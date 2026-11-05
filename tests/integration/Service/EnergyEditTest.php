<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Service;

use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\Occurrence;
use OCA\ChoreStrike\Db\OccurrenceMapper;
use OCA\ChoreStrike\Exception\ConflictException;
use OCA\ChoreStrike\Service\CompletionService;
use OCA\ChoreStrike\Service\OccurrenceReconcileService;
use OCA\ChoreStrike\Service\TaskService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * BUG-UX-014: an energy edit applies to all open work at once. The row, the
 * planner and the payout use the new value, completed work keeps its own,
 * against the real database with the real services.
 */
class EnergyEditTest extends IntegrationTestCase {
	private const OWNER = 'ee_owner';

	private OccurrenceMapper $occurrenceMapper;
	private CompletionService $completionService;
	private TaskService $taskService;

	protected function setUp(): void {
		parent::setUp();
		$this->occurrenceMapper = \OC::$server->get(OccurrenceMapper::class);
		$this->completionService = \OC::$server->get(CompletionService::class);
		$this->taskService = \OC::$server->get(TaskService::class);
	}

	/**
	 * A house with one task of energy 2 in a kitchen: by default an interval
	 * rule, due today.
	 *
	 * @return array{houseId: int, floorId: int, taskId: int, roomTaskId: int}
	 */
	private function fixture(array $taskOverrides = [], ?string $anchor = null): array {
		$houseId = $this->createHouse(self::OWNER, 'Energy House');
		$floorId = $this->createFloor($houseId, 'Ground', 1);
		$roomId = $this->createRoom($houseId, $floorId, 'Kitchen');
		$taskId = $this->createTask($houseId, 'Dishes', '', 3, 2);
		$taskOverrides += [
			'frequency_type' => 'daily',
			'frequency_value' => 1,
			'scheduling_mode' => 'dynamic',
			'recurrence_mode' => 'recurring',
		];
		foreach ($taskOverrides as $column => $value) {
			$qb = $this->db->getQueryBuilder();
			$qb->update('chorestrike_tasks')
				->set($column, $qb->createNamedParameter($value))
				->where($qb->expr()->eq('id', $qb->createNamedParameter($taskId, IQueryBuilder::PARAM_INT)))
				->executeStatement();
		}
		return [
			'houseId' => $houseId,
			'floorId' => $floorId,
			'taskId' => $taskId,
			'roomTaskId' => $this->assignTaskToRoom($taskId, $roomId, self::OWNER, $anchor),
		];
	}

	private function owner(int $houseId): HouseUser {
		return \OC::$server->get(HouseUserMapper::class)->findByHouseAndUser($houseId, self::OWNER);
	}

	private function edit(array $f, array $updates): void {
		$this->actingAs(self::OWNER);
		$this->taskService->updateTask($f['taskId'], $updates, $this->owner($f['houseId']));
	}

	private function complete(array $f): int {
		return (int)$this->completeOccurrence($this->completionService, $f['houseId'], self::OWNER, $f['roomTaskId'])['completionId'];
	}

	private function undo(array $f, int $completionId): void {
		$version = (int)$this->rows('chorestrike_completed_tasks', 'id', $completionId)[0]['completion_version'];
		$this->completionService->undo($f['houseId'], $this->owner($f['houseId']), $completionId, self::uuid(), $version, 'not_done');
	}

	/** @return list<Occurrence> */
	private function openRows(int $roomTaskId): array {
		return $this->occurrenceMapper->findOpenByRoomTasks([$roomTaskId]);
	}

	/** @return list<array<string, mixed>> */
	private function rows(string $table, string $column, int $value): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($table)
			->where($qb->expr()->eq($column, $qb->createNamedParameter($value, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC');
		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();
		return $rows;
	}

	/** @return list<array<string, mixed>> */
	private function energyEvidence(int $occurrenceId): array {
		return array_values(array_filter(
			$this->rows('chorestrike_agreements', 'occurrence_id', $occurrenceId),
			static fn (array $row): bool => $row['kind'] === 'energy'
		));
	}

	private function isActive(int $roomTaskId): bool {
		return (bool)$this->rows('chorestrike_room_tasks', 'id', $roomTaskId)[0]['is_active'];
	}

	private function setRoomTask(int $roomTaskId, string $column, mixed $value): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('chorestrike_room_tasks')
			->set($column, $qb->createNamedParameter($value))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	private function reconcile(array $f, ?\DateTime $now = null): void {
		\OC::$server->get(OccurrenceReconcileService::class)->reconcileRoomTask($f['houseId'], $f['roomTaskId'], $now);
	}

	private function paidEnergy(int $completionId): int {
		return (int)$this->rows('chorestrike_completed_tasks', 'id', $completionId)[0]['total_energy'];
	}

	private static function uuid(): string {
		return sprintf('%08x-%04x-4%03x-8%03x-%012x', mt_rand(), mt_rand(0, 0xffff), mt_rand(0, 0xfff), mt_rand(0, 0xfff), mt_rand());
	}

	public function testUpdateTask_IntervalRule_OpenWorkPaysTheNewEnergy(): void {
		$f = $this->fixture();
		$before = $this->openRows($f['roomTaskId'])[0];

		$this->edit($f, ['energyCost' => 7]);

		$open = $this->openRows($f['roomTaskId']);
		self::assertCount(1, $open);
		self::assertSame($before->toPublicKey(), $open[0]->toPublicKey(), 'the occurrence keeps its identity');
		self::assertSame(7, $open[0]->getEnergy());
		self::assertSame($before->getVersion() + 1, $open[0]->getVersion());

		$completionId = $this->complete($f);
		self::assertSame(7, $this->paidEnergy($completionId));
		self::assertSame(7, $this->openRows($f['roomTaskId'])[0]->getEnergy(), 'the next occurrence has it too');
	}

	public function testUpdateTask_OneTimeTask_HasNoNextOccurrenceToWaitFor(): void {
		$f = $this->fixture(['recurrence_mode' => 'once']);

		$this->edit($f, ['energyCost' => 7]);
		$this->edit($f, ['energyCost' => 9]);

		self::assertSame(9, $this->openRows($f['roomTaskId'])[0]->getEnergy());
		self::assertSame(9, $this->paidEnergy($this->complete($f)));
	}

	/**
	 * An edit appends a revision without moving the row, so the end of a
	 * one-time task must not depend on the current revision's rows.
	 *
	 * @dataProvider schedulingModes
	 */
	public function testComplete_OneTimeTaskEditedBefore_StaysDone(string $mode): void {
		$f = $this->fixture(['recurrence_mode' => 'once', 'scheduling_mode' => $mode]);
		$this->edit($f, ['energyCost' => 7]);

		$this->complete($f);
		self::assertFalse($this->isActive($f['roomTaskId']), 'fulfilling the one occurrence ends the task');

		$this->setRoomTask($f['roomTaskId'], 'is_active', 1);
		$this->reconcile($f, new \DateTime('+5 minutes'));
		self::assertFalse($this->isActive($f['roomTaskId']), 'a job pass ends one an earlier version left active');
		self::assertSame([], $this->openRows($f['roomTaskId']), 'the task never comes back');
		self::assertCount(1, $this->occurrenceMapper->findByRoomTask($f['roomTaskId']));
	}

	/** @return array<string, array{string}> */
	public static function schedulingModes(): array {
		return ['interval' => ['dynamic'], 'fixed' => ['static']];
	}

	public function testUpdateTask_FinishedRoomOfASharedDefinition_GetsNoNewWork(): void {
		$f = $this->fixture(['recurrence_mode' => 'once']);
		$otherRoom = $this->createRoom($f['houseId'], $f['floorId'], 'Bathroom');
		$otherRoomTask = $this->assignTaskToRoom($f['taskId'], $otherRoom, self::OWNER);
		$this->complete($f);

		$this->edit($f, ['energyCost' => 7]);

		self::assertSame([], $this->openRows($f['roomTaskId']));
		self::assertCount(1, $this->occurrenceMapper->findByRoomTask($f['roomTaskId']));
		self::assertSame(7, $this->openRows($otherRoomTask)[0]->getEnergy());
	}

	public function testUpdateTask_ReachesAnInactiveRoomTask_SkipsADeletedOne(): void {
		$f = $this->fixture();
		$inactive = $this->assignTaskToRoom($f['taskId'], $this->createRoom($f['houseId'], $f['floorId'], 'Bathroom'), self::OWNER);
		$deleted = $this->assignTaskToRoom($f['taskId'], $this->createRoom($f['houseId'], $f['floorId'], 'Hall'), self::OWNER);
		$this->setRoomTask($inactive, 'is_active', 0);
		$this->setRoomTask($deleted, 'deleted_at', (new \DateTime())->format('Y-m-d H:i:s'));

		$this->edit($f, ['energyCost' => 7]);

		self::assertSame(7, $this->openRows($inactive)[0]->getEnergy());
		self::assertSame(2, $this->openRows($deleted)[0]->getEnergy());
	}

	public function testUpdateTask_FixedRule_RepricesEveryOpenOccurrenceWithEvidence(): void {
		$f = $this->fixture(['scheduling_mode' => 'static']);
		$before = $this->openRows($f['roomTaskId']);
		self::assertGreaterThan(1, count($before), 'precondition: the horizon is agreed ahead');

		$this->edit($f, ['energyCost' => 7]);

		$owner = (int)$this->owner($f['houseId'])->getId();
		foreach ($this->openRows($f['roomTaskId']) as $occurrence) {
			self::assertSame(7, $occurrence->getEnergy());
			$evidence = $this->energyEvidence($occurrence->getId());
			self::assertCount(1, $evidence);
			self::assertSame($owner, (int)$evidence[0]['actor_house_user_id']);
			self::assertSame(['energy' => 2], json_decode($evidence[0]['old_state'], true));
			self::assertSame(['energy' => 7], json_decode($evidence[0]['new_state'], true));
		}
		self::assertCount(count($before), $this->openRows($f['roomTaskId']), 'no occurrence is added or lost');
	}

	public function testUpdateTask_ReachesEveryRoomOfTheDefinition(): void {
		$f = $this->fixture();
		$otherRoom = $this->createRoom($f['houseId'], $f['floorId'], 'Bathroom');
		$otherRoomTask = $this->assignTaskToRoom($f['taskId'], $otherRoom, self::OWNER);

		$this->edit($f, ['energyCost' => 7]);

		self::assertSame(7, $this->openRows($f['roomTaskId'])[0]->getEnergy());
		self::assertSame(7, $this->openRows($otherRoomTask)[0]->getEnergy());
	}

	public function testUpdateTask_OtherFields_LeaveOpenWorkAlone(): void {
		$f = $this->fixture();
		$before = $this->openRows($f['roomTaskId'])[0];

		$this->edit($f, ['name' => 'Wash up', 'energyCost' => 2]);

		$after = $this->openRows($f['roomTaskId'])[0];
		self::assertSame($before->getVersion(), $after->getVersion());
		self::assertSame([], $this->energyEvidence($after->getId()));
	}

	public function testUpdateTask_CompletedAndCoveredWork_KeepsItsEnergy(): void {
		$yesterday = (new \DateTime('-1 day'))->format('Y-m-d');
		$f = $this->fixture(['scheduling_mode' => 'static'], $yesterday . ' 00:00:00');
		$completionId = $this->complete($f);
		$wallet = $this->rows('chorestrike_wallet_entries', 'source_id', $completionId);

		$this->edit($f, ['energyCost' => 7]);

		$rows = $this->occurrenceMapper->findByRoomTask($f['roomTaskId']);
		$settled = array_values(array_filter($rows, static fn (Occurrence $o): bool => $o->getCompletionId() !== null || $o->getCoveredByCompletionId() !== null));
		self::assertCount(2, $settled, 'precondition: today is completed and covers yesterday');
		foreach ($settled as $occurrence) {
			self::assertSame(2, $occurrence->getEnergy());
			self::assertSame([], $this->energyEvidence($occurrence->getId()));
		}
		self::assertSame(2, $this->paidEnergy($completionId));
		self::assertSame($wallet, $this->rows('chorestrike_wallet_entries', 'source_id', $completionId));
	}

	public function testComplete_ShownBeforeTheEdit_ConflictsInsteadOfPayingTheOldValue(): void {
		$f = $this->fixture();
		[$commandId, $key, $version, $token] = $this->completionReceiptFor($f['roomTaskId']);

		$this->edit($f, ['energyCost' => 7]);

		try {
			$this->completionService->complete($f['houseId'], self::OWNER, $f['roomTaskId'], $commandId, $key, $version, $token);
			self::fail('a state shown before the edit must conflict');
		} catch (ConflictException $e) {
			self::assertSame([], $this->rows('chorestrike_completed_tasks', 'room_task_id', $f['roomTaskId']));
		}
	}

	public function testUndo_AfterAnEnergyEdit_ReopenedWorkCarriesTheCurrentEnergy(): void {
		$yesterday = (new \DateTime('-1 day'))->format('Y-m-d');
		$f = $this->fixture(['scheduling_mode' => 'static'], $yesterday . ' 00:00:00');
		$completionId = $this->complete($f);

		$this->edit($f, ['energyCost' => 7]);
		$this->undo($f, $completionId);

		$today = (new \DateTime($yesterday . ' +1 day'))->format('Y-m-d');
		$open = $this->openRows($f['roomTaskId']);
		$reopened = array_values(array_filter(
			$open,
			static fn (Occurrence $o): bool => $o->getPlannedDate()->format('Y-m-d') <= $today
		));
		self::assertCount(2, $reopened, 'today and the covered miss are open again');
		foreach ($open as $occurrence) {
			self::assertSame(7, $occurrence->getEnergy());
		}
		foreach ($reopened as $occurrence) {
			$evidence = $this->energyEvidence($occurrence->getId());
			self::assertCount(1, $evidence);
			self::assertNull($evidence[0]['actor_house_user_id'], 'an undo is no decision about the energy');
		}
	}

	public function testUndo_EnergyEvidenceDoesNotPinTheDerivedSuccessor(): void {
		$f = $this->fixture();
		$target = $this->openRows($f['roomTaskId'])[0];
		$completionId = $this->complete($f);
		$successor = $this->openRows($f['roomTaskId'])[0];
		self::assertNotSame($target->toPublicKey(), $successor->toPublicKey());

		$this->edit($f, ['energyCost' => 7]);
		self::assertCount(1, $this->energyEvidence($successor->getId()));
		$this->undo($f, $completionId);

		$open = $this->openRows($f['roomTaskId']);
		self::assertCount(1, $open, 'the undo leaves exactly one open interval occurrence');
		self::assertSame($target->toPublicKey(), $open[0]->toPublicKey());
		self::assertSame(7, $open[0]->getEnergy());
		self::assertCount(1, $this->energyEvidence($successor->getId()), 'the evidence of who changed the energy stays');
		self::assertSame(7, $this->paidEnergy($this->complete($f)));
	}

	/**
	 * What an earlier version left behind: the definition and the current
	 * revision moved on, the open rows did not.
	 */
	private function leaveOpenWorkAtTheOldEnergy(array $f, int $energy): void {
		foreach ($this->openRows($f['roomTaskId']) as $occurrence) {
			$qb = $this->db->getQueryBuilder();
			$qb->update('chorestrike_occurrences')
				->set('energy', $qb->createNamedParameter($energy, IQueryBuilder::PARAM_INT))
				->where($qb->expr()->eq('id', $qb->createNamedParameter($occurrence->getId(), IQueryBuilder::PARAM_INT)))
				->executeStatement();
			$qb = $this->db->getQueryBuilder();
			$qb->delete('chorestrike_agreements')
				->where($qb->expr()->eq('occurrence_id', $qb->createNamedParameter($occurrence->getId(), IQueryBuilder::PARAM_INT)))
				->executeStatement();
		}
	}

	public function testReconcile_AlignsOpenWorkAnEarlierVersionLeftBehind_Once(): void {
		$yesterday = (new \DateTime('-1 day'))->format('Y-m-d');
		$f = $this->fixture(['scheduling_mode' => 'static'], $yesterday . ' 00:00:00');
		$completionId = $this->complete($f);
		$this->edit($f, ['energyCost' => 7]);
		$this->leaveOpenWorkAtTheOldEnergy($f, 2);
		$stale = $this->openRows($f['roomTaskId']);
		self::assertGreaterThan(1, count($stale));

		$this->reconcile($f);

		$versions = [];
		foreach ($stale as $occurrence) {
			$aligned = $this->occurrenceMapper->find($occurrence->getId());
			self::assertSame(7, $aligned->getEnergy());
			self::assertSame($occurrence->getVersion() + 1, $aligned->getVersion());
			$evidence = $this->energyEvidence($occurrence->getId());
			self::assertCount(1, $evidence);
			self::assertNull($evidence[0]['actor_house_user_id'], 'no person decided this');
			$versions[$occurrence->getId()] = $aligned->getVersion();
		}
		self::assertSame(2, $this->paidEnergy($completionId), 'completed work keeps its energy');

		$this->reconcile($f);
		foreach ($versions as $id => $version) {
			self::assertSame($version, $this->occurrenceMapper->find($id)->getVersion(), 'a later pass finds nothing to do');
			self::assertCount(1, $this->energyEvidence($id));
		}
	}

	public function testReconcile_AlignsAOneTimeTaskThatHasNoNextOccurrence(): void {
		$f = $this->fixture(['recurrence_mode' => 'once']);
		$this->edit($f, ['energyCost' => 7]);
		$this->leaveOpenWorkAtTheOldEnergy($f, 2);

		$this->reconcile($f);

		self::assertSame(7, $this->paidEnergy($this->complete($f)));
	}

	public function testUndo_WindowEvidenceDoesNotPinTheDerivedSuccessor(): void {
		$f = $this->fixture(['frequency_type' => 'weekly']);
		$target = $this->openRows($f['roomTaskId'])[0];
		$completionId = $this->complete($f);
		$successor = $this->openRows($f['roomTaskId'])[0];

		$this->edit($f, ['flexDays' => 2]);
		self::assertNotSame([], $this->rows('chorestrike_agreements', 'occurrence_id', $successor->getId()), 'precondition: the widening left evidence');
		$this->undo($f, $completionId);

		$open = $this->openRows($f['roomTaskId']);
		self::assertCount(1, $open, 'the undo leaves exactly one open interval occurrence');
		self::assertSame($target->toPublicKey(), $open[0]->toPublicKey());
	}
}
