<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Service;

use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\Occurrence;
use OCA\ChoreStrike\Db\OccurrenceMapper;
use OCA\ChoreStrike\Service\AgreementService;
use OCA\ChoreStrike\Service\CompletionService;
use OCA\ChoreStrike\Service\OccurrenceService;
use OCA\ChoreStrike\Service\RoomTaskReadService;
use OCA\ChoreStrike\Service\RoomTaskService;
use OCA\ChoreStrike\Service\WalletService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * Successor ownership across the three ways work changes hands
 * (CS-PLAN-084 WP-G, TECH-TEST-005): an overdue takeover and a
 * one-occurrence handover never change who owns the NEXT occurrence — it
 * returns to the default responsible — while a reassignment moves the open
 * and every following occurrence to the new assignee and removes the task
 * from the former assignee's day. Asserted on the persisted occurrence
 * members and on the same daily read the My Day view renders.
 */
class SuccessorOwnershipTest extends IntegrationTestCase {
	private const OWNER = 'so_owner';
	private const KID = 'so_kid';
	private const OTHER_KID = 'so_other_kid';

	private CompletionService $completionService;
	private OccurrenceMapper $occurrenceMapper;
	private RoomTaskReadService $readService;
	private HouseUserMapper $houseUserMapper;

	protected function setUp(): void {
		parent::setUp();
		$this->completionService = \OC::$server->get(CompletionService::class);
		$this->occurrenceMapper = \OC::$server->get(OccurrenceMapper::class);
		$this->readService = \OC::$server->get(RoomTaskReadService::class);
		$this->houseUserMapper = \OC::$server->get(HouseUserMapper::class);
	}

	public function testOverdueTakeover_SuccessorReturnsToOriginalAssignee(): void {
		$f = $this->fixture((new \DateTime('-1 day'))->format('Y-m-d') . ' 00:00:00');

		$this->completeOccurrenceWithParticipants($this->completionService, $f['houseId'], self::OTHER_KID, $f['roomTaskId'],
			[['userId' => self::OTHER_KID, 'sharePct' => 100]]);

		$claim = $this->roomTaskRow($f['roomTaskId']);
		self::assertSame(1, (int)$claim['was_claimed'], 'the overdue takeover is recorded');
		self::assertSame(self::OTHER_KID, $claim['claimed_by_user']);
		self::assertSame(self::KID, $claim['original_assignee']);
		self::assertSame([$this->walletIdOf($f['houseId'], self::KID)], $this->obligatedWallets($this->openSuccessor($f['roomTaskId'])),
			'the next occurrence belongs to the original assignee again');
		$next = $this->successorDate($f['roomTaskId']);
		self::assertTrue($this->visibleTo($f, self::KID, $next));
		self::assertFalse($this->visibleTo($f, self::OTHER_KID, $next), 'the taker does not inherit the following occurrence');
	}

	public function testHandoverThenCompletion_SuccessorReturnsToDefaultResponsible(): void {
		$f = $this->fixture((new \DateTime())->format('Y-m-d') . ' 00:00:00');
		$this->handOver($f, self::OTHER_KID);
		self::assertFalse($this->visibleTo($f, self::KID, new \DateTime()), 'the handed-over occurrence leaves the default assignee\'s day');
		self::assertTrue($this->visibleTo($f, self::OTHER_KID, new \DateTime()));

		$this->completeOccurrence($this->completionService, $f['houseId'], self::OTHER_KID, $f['roomTaskId']);

		self::assertSame([$this->walletIdOf($f['houseId'], self::KID)], $this->obligatedWallets($this->openSuccessor($f['roomTaskId'])),
			'a one-occurrence handover never changes the following occurrence');
		$next = $this->successorDate($f['roomTaskId']);
		self::assertTrue($this->visibleTo($f, self::KID, $next));
		self::assertFalse($this->visibleTo($f, self::OTHER_KID, $next));
		self::assertSame(0, (int)$this->roomTaskRow($f['roomTaskId'])['was_claimed'], 'an agreed handover is not a takeover');
	}

	public function testReassignment_MovesOpenAndFollowingOccurrencesToNewAssignee(): void {
		$f = $this->fixture((new \DateTime())->format('Y-m-d') . ' 00:00:00');
		$actor = $this->houseUserMapper->findByHouseAndUser($f['houseId'], self::OWNER);
		\OC::$server->get(RoomTaskService::class)->setAssignees($f['roomTaskId'], [self::OTHER_KID], $f['houseId'], $actor);

		self::assertFalse($this->visibleTo($f, self::KID, new \DateTime()), 'the former assignee no longer sees the task');
		self::assertTrue($this->visibleTo($f, self::OTHER_KID, new \DateTime()));

		$this->completeOccurrence($this->completionService, $f['houseId'], self::OTHER_KID, $f['roomTaskId']);

		self::assertSame([$this->walletIdOf($f['houseId'], self::OTHER_KID)], $this->obligatedWallets($this->openSuccessor($f['roomTaskId'])),
			'a reassignment owns every following occurrence');
		$next = $this->successorDate($f['roomTaskId']);
		self::assertTrue($this->visibleTo($f, self::OTHER_KID, $next));
		self::assertFalse($this->visibleTo($f, self::KID, $next));
	}

	/**
	 * Adult owner (the acting parent) plus two children with bound wallets
	 * and one daily interval task (energy 2, within a child's daily budget)
	 * assigned to the first child from the given anchor. Children see only
	 * the tasks they are obligated to, which makes "who sees it" observable.
	 *
	 * @return array{houseId: int, roomTaskId: int}
	 */
	private function fixture(string $anchor): array {
		$houseId = $this->createHouse(self::OWNER, 'Successor House');
		$this->addHouseMember($houseId, self::KID, 'Kid', 'child');
		$this->addHouseMember($houseId, self::OTHER_KID, 'Other Kid', 'child');
		$walletService = \OC::$server->get(WalletService::class);
		foreach ([self::OWNER, self::KID, self::OTHER_KID] as $userId) {
			$walletService->ensureBinding($houseId, $this->houseUserMapper->findByNextcloudUserId($houseId, $userId));
		}
		$floorId = $this->createFloor($houseId, 'Ground', 1);
		$roomId = $this->createRoom($houseId, $floorId, 'Kitchen');
		$taskId = $this->createTask($houseId, 'Dishes', '', 5, 2);
		$qb = $this->db->getQueryBuilder();
		foreach (['frequency_type' => 'daily', 'frequency_value' => 1, 'scheduling_mode' => 'dynamic', 'recurrence_mode' => 'recurring'] as $column => $value) {
			$qb->update('chorestrike_tasks')
				->set($column, $qb->createNamedParameter($value))
				->where($qb->expr()->eq('id', $qb->createNamedParameter($taskId, IQueryBuilder::PARAM_INT)))
				->executeStatement();
		}
		return ['houseId' => $houseId, 'roomTaskId' => $this->assignTaskToRoom($taskId, $roomId, self::KID, $anchor)];
	}

	/** One-occurrence handover through the public agreement command. */
	private function handOver(array $f, string $userId): void {
		$occurrenceService = \OC::$server->get(OccurrenceService::class);
		$roomTask = \OC::$server->get(\OCA\ChoreStrike\Db\RoomTaskMapper::class)->find($f['roomTaskId']);
		$selection = $occurrenceService->selectionFor($roomTask, new \DateTime(), $f['houseId']);
		self::assertNotNull($selection);
		\OC::$server->get(AgreementService::class)->amend($f['houseId'], self::OWNER, $f['roomTaskId'], [
			'commandId' => sprintf('%08x-%04x-4%03x-8%03x-%012x', mt_rand(), mt_rand(0, 0xffff), mt_rand(0, 0xfff), mt_rand(0, 0xfff), mt_rand()),
			'occurrenceId' => $selection['occurrence']->toPublicKey(),
			'expectedVersion' => $selection['occurrence']->getVersion(),
			'selectionToken' => $occurrenceService->selectionToken($selection['occurrence'], $selection['revision'],
				$selection['classification'], $f['houseId'], null, $selection['workAppointment']),
			'consent' => true,
			'participants' => [['userId' => $userId, 'role' => 'responsible', 'sharePct' => 100]],
		]);
	}

	/** The single open (unfulfilled, uncovered) occurrence after the last completion. */
	private function openSuccessor(int $roomTaskId): Occurrence {
		$open = array_values(array_filter(
			$this->occurrenceMapper->findByRoomTask($roomTaskId),
			static fn (Occurrence $o): bool => $o->getCompletionId() === null && $o->getCoveredByCompletionId() === null
		));
		self::assertCount(1, $open, 'an interval task owns exactly one open successor');
		return $open[0];
	}

	private function successorDate(int $roomTaskId): \DateTime {
		return new \DateTime($this->openSuccessor($roomTaskId)->getPlannedDate()->format('Y-m-d') . ' 12:00:00');
	}

	/** @return list<int> sorted wallet ids actively obligated on the occurrence */
	private function obligatedWallets(Occurrence $occurrence): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('wallet_id')
			->from('chorestrike_occ_members')
			->where($qb->expr()->eq('occurrence_id', $qb->createNamedParameter($occurrence->getId(), IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('disposition', $qb->createNamedParameter('active')))
			->andWhere($qb->expr()->eq('obligated', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$wallets = array_map('intval', $result->fetchAll(\PDO::FETCH_COLUMN));
		$result->closeCursor();
		sort($wallets);
		return $wallets;
	}

	/** Whether the child's daily read (the My Day list) contains the task on the date. */
	private function visibleTo(array $f, string $viewerId, \DateTime $date): bool {
		$viewer = $this->houseUserMapper->findByNextcloudUserId($f['houseId'], $viewerId);
		self::assertTrue($viewer->isChild(), 'visibility is asserted from a child, adults see every house task');
		foreach ($this->readService->getFilteredDailyTasksForUser($f['houseId'], $viewer, $date) as $row) {
			if ((int)$row['roomTask']->getId() === $f['roomTaskId']) {
				return true;
			}
		}
		return false;
	}

	private function walletIdOf(int $houseId, string $userId): int {
		$member = $this->houseUserMapper->findByHouseAndUser($houseId, $userId);
		self::assertNotNull($member);
		self::assertNotNull($member->getWalletId());
		return (int)$member->getWalletId();
	}

	private function roomTaskRow(int $roomTaskId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('chorestrike_room_tasks')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		self::assertIsArray($row);
		return $row;
	}
}
