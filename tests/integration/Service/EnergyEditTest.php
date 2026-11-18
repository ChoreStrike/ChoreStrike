<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Service;

use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Exception\ConflictException;
use OCA\ChoreStrike\Service\CompletionService;
use OCA\ChoreStrike\Service\CycleAdvanceService;
use OCA\ChoreStrike\Service\RecurrenceCalculator;
use OCA\ChoreStrike\Service\RoomTaskReadService;
use OCA\ChoreStrike\Service\TaskService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * BUG-UX-014: an energy edit applies to all open work at once. Energy is
 * read from the definition when the work is completed (ADR-021 decision
 * 1), so the payout uses the new value and completed work keeps its own —
 * against the real database with the real services.
 */
class EnergyEditTest extends IntegrationTestCase {
	private const OWNER = 'ee_owner';

	private CompletionService $completionService;
	private TaskService $taskService;

	protected function setUp(): void {
		parent::setUp();
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

	/** The `cycle` block a row shows for the room task; null when it has no current cycle. */
	private function block(int $roomTaskId): ?array {
		$roomTask = \OC::$server->get(\OCA\ChoreStrike\Db\RoomTaskMapper::class)->find($roomTaskId);
		$readService = \OC::$server->get(RoomTaskReadService::class);
		return $readService->cycleFacts($readService->houseIdForRoom($roomTask->getRoomId()), [$roomTask])[$roomTaskId]['block'];
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

	private function isActive(int $roomTaskId): bool {
		return (bool)$this->rows('chorestrike_room_tasks', 'id', $roomTaskId)[0]['is_active'];
	}

	private function paidEnergy(int $completionId): int {
		return (int)$this->rows('chorestrike_completed_tasks', 'id', $completionId)[0]['total_energy'];
	}

	private static function uuid(): string {
		return sprintf('%08x-%04x-4%03x-8%03x-%012x', mt_rand(), mt_rand(0, 0xffff), mt_rand(0, 0xfff), mt_rand(0, 0xfff), mt_rand());
	}

	public function testUpdateTask_OpenWorkPaysTheNewEnergy(): void {
		$f = $this->fixture();
		$before = $this->block($f['roomTaskId']);

		$this->edit($f, ['energyCost' => 7]);

		$after = $this->block($f['roomTaskId']);
		self::assertSame($before['cycleKey'], $after['cycleKey'], 'the cycle keeps its identity');
		self::assertGreaterThan($before['expectedVersion'], $after['expectedVersion'], 'a row shown before the edit is stale');

		$completionId = $this->complete($f);
		self::assertSame(7, $this->paidEnergy($completionId));
		self::assertSame(7, (int)$this->rows('chorestrike_cycles', 'completion_id', $completionId)[0]['energy'], 'the closed cycle records what was paid');
	}

	public function testUpdateTask_OneTimeTask_PaysTheLatestEdit(): void {
		$f = $this->fixture(['recurrence_mode' => 'once']);

		$this->edit($f, ['energyCost' => 7]);
		$this->edit($f, ['energyCost' => 9]);

		self::assertSame(9, $this->paidEnergy($this->complete($f)));
	}

	/**
	 * @dataProvider schedulingModes
	 */
	public function testComplete_OneTimeTaskEditedBefore_StaysDone(string $mode): void {
		$f = $this->fixture(['recurrence_mode' => 'once', 'scheduling_mode' => $mode]);
		$this->edit($f, ['energyCost' => 7]);

		$this->complete($f);
		self::assertFalse($this->isActive($f['roomTaskId']), 'closing its one cycle ends the task');

		// A later edit and a job pass find nothing to bring back.
		$this->edit($f, ['energyCost' => 8]);
		\OC::$server->get(CycleAdvanceService::class)->advanceHouse(\OC::$server->get(\OCA\ChoreStrike\Db\HouseMapper::class)->find($f['houseId']), new \DateTime('+2 days'));
		self::assertFalse($this->isActive($f['roomTaskId']));
		self::assertNull($this->block($f['roomTaskId']), 'the task never comes back');
		self::assertCount(1, $this->rows('chorestrike_cycles', 'room_task_id', $f['roomTaskId']));
	}

	/** @return array<string, array{string}> */
	public static function schedulingModes(): array {
		return ['interval' => ['dynamic'], 'fixed' => ['static']];
	}

	public function testUpdateTask_ReachesEveryRoomOfTheDefinition_ButNoFinishedOne(): void {
		$f = $this->fixture(['recurrence_mode' => 'once']);
		$otherRoom = $this->createRoom($f['houseId'], $f['floorId'], 'Bathroom');
		$other = ['roomTaskId' => $this->assignTaskToRoom($f['taskId'], $otherRoom, self::OWNER)] + $f;
		$finished = $this->complete($f);

		$this->edit($f, ['energyCost' => 7]);

		self::assertNull($this->block($f['roomTaskId']), 'the finished room gets no new work');
		self::assertSame(2, $this->paidEnergy($finished));
		self::assertSame(7, $this->paidEnergy($this->complete($other)));
	}

	public function testUpdateTask_OtherFields_LeaveOpenWorkAlone(): void {
		$f = $this->fixture();
		$before = $this->block($f['roomTaskId']);

		$this->edit($f, ['name' => 'Wash up', 'energyCost' => 2]);

		self::assertSame($before, $this->block($f['roomTaskId']), 'a row shown before the edit stays valid');
	}

	public function testUpdateTask_CompletedWork_KeepsItsEnergy(): void {
		$yesterday = (new \DateTime('-1 day'))->format('Y-m-d');
		$f = $this->fixture(['scheduling_mode' => 'static'], $yesterday . ' 00:00:00');
		$completionId = $this->complete($f);
		$wallet = $this->rows('chorestrike_wallet_entries', 'source_id', $completionId);

		$this->edit($f, ['energyCost' => 7]);

		self::assertSame(2, $this->paidEnergy($completionId));
		self::assertSame(2, (int)$this->rows('chorestrike_cycles', 'completion_id', $completionId)[0]['energy']);
		self::assertSame($wallet, $this->rows('chorestrike_wallet_entries', 'source_id', $completionId));
	}

	public function testComplete_ShownBeforeTheEdit_ConflictsInsteadOfPayingTheOldValue(): void {
		$f = $this->fixture();
		[$commandId, $key, $version] = $this->completionReceiptFor($f['roomTaskId']);

		$this->edit($f, ['energyCost' => 7]);

		try {
			$this->completionService->complete($f['houseId'], self::OWNER, $f['roomTaskId'], $commandId, $key, $version);
			self::fail('a state shown before the edit must conflict');
		} catch (ConflictException $e) {
			self::assertSame(ConflictException::REASON_STALE_TARGET, $e->getReason());
			self::assertSame([], $this->rows('chorestrike_completed_tasks', 'room_task_id', $f['roomTaskId']));
		}
	}

	public function testUndo_AfterAnEnergyEdit_ReopenedWorkPaysTheCurrentEnergy(): void {
		$f = $this->fixture();
		$target = $this->block($f['roomTaskId']);
		$completionId = $this->complete($f);

		$this->edit($f, ['energyCost' => 7]);
		$this->undo($f, $completionId);

		self::assertSame($target['cycleKey'], $this->block($f['roomTaskId'])['cycleKey'], 'the undo reopens the cycle it closed');
		self::assertSame(2, $this->paidEnergy($completionId), 'the undone work keeps what it recorded');
		self::assertSame(7, $this->paidEnergy($this->complete($f)));
	}

	public function testUndo_AfterAWindowEdit_ReopensTheSameCycleInTheNewWindow(): void {
		$f = $this->fixture(['frequency_type' => 'weekly']);
		$target = $this->block($f['roomTaskId']);
		$completionId = $this->complete($f);

		$this->edit($f, ['flexDays' => 2]);
		$this->undo($f, $completionId);

		$reopened = $this->block($f['roomTaskId']);
		self::assertSame($target['cycleKey'], $reopened['cycleKey']);
		self::assertSame($target['centreOn'], $reopened['centreOn']);
		self::assertSame(RecurrenceCalculator::offsetDays($target['centreOn'], 2), $reopened['windowEndOn'], 'the window follows the current rule');
	}
}
