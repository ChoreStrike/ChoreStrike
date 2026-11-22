<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Service;

use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\TaskAssigneeMapper;
use OCA\ChoreStrike\Service\CompletionService;
use OCA\ChoreStrike\Service\RoomTaskReadService;
use OCA\ChoreStrike\Service\RoomTaskService;
use OCA\ChoreStrike\Service\WalletService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;
use OCA\ChoreStrike\Tests\Integration\Support\TaskEditing;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * Who owns the next cycle when work changes hands (CS-PLAN-084 WP-G,
 * TECH-TEST-005): an overdue takeover never changes who the task belongs
 * to — the next cycle is the assignee's again — while a reassignment moves
 * the open and every following cycle to the new assignee and removes the
 * task from the former assignee's day. The one-occurrence handover went
 * with the agreement commands (ADR-021 decision 13). Asserted on the
 * assignment and on the same daily read the My Day view renders.
 */
class SuccessorOwnershipTest extends IntegrationTestCase {
	use TaskEditing;

	private const OWNER = 'so_owner';
	private const KID = 'so_kid';
	private const OTHER_KID = 'so_other_kid';

	private CompletionService $completionService;
	private RoomTaskReadService $readService;
	private HouseUserMapper $houseUserMapper;

	protected function setUp(): void {
		parent::setUp();
		$this->completionService = \OC::$server->get(CompletionService::class);
		$this->readService = \OC::$server->get(RoomTaskReadService::class);
		$this->houseUserMapper = \OC::$server->get(HouseUserMapper::class);
	}

	public function testOverdueTakeover_NextCycleStaysWithTheAssignee(): void {
		$f = $this->fixture((new \DateTime('-1 day'))->format('Y-m-d') . ' 00:00:00');

		$this->completeOccurrenceWithParticipants($this->completionService, $f['houseId'], self::OTHER_KID, $f['roomTaskId'],
			[['userId' => self::OTHER_KID, 'sharePct' => 100]]);

		$claim = $this->roomTaskRow($f['roomTaskId']);
		self::assertSame(1, (int)$claim['was_claimed'], 'the overdue takeover is recorded');
		self::assertSame(self::OTHER_KID, $claim['claimed_by_user']);
		self::assertSame(self::KID, $claim['original_assignee']);
		self::assertSame([self::KID], $this->assignees($f['roomTaskId']), 'the task still belongs to the original assignee');
		$next = $this->nextCycleDate($f);
		self::assertTrue($this->visibleTo($f, self::KID, $next));
		self::assertFalse($this->visibleTo($f, self::OTHER_KID, $next), 'the taker does not inherit the following cycle');
	}

	public function testReassignment_MovesOpenAndFollowingCyclesToNewAssignee(): void {
		$f = $this->fixture((new \DateTime())->format('Y-m-d') . ' 00:00:00');
		$actor = $this->houseUserMapper->findByHouseAndUser($f['houseId'], self::OWNER);
		$this->assignTo($f['houseId'], $actor, $f['roomTaskId'], [self::OTHER_KID]);

		self::assertFalse($this->visibleTo($f, self::KID, new \DateTime()), 'the former assignee no longer sees the task');
		self::assertTrue($this->visibleTo($f, self::OTHER_KID, new \DateTime()));

		$this->completeOccurrence($this->completionService, $f['houseId'], self::OTHER_KID, $f['roomTaskId']);

		self::assertSame([self::OTHER_KID], $this->assignees($f['roomTaskId']), 'a reassignment owns every following cycle');
		$next = $this->nextCycleDate($f);
		self::assertTrue($this->visibleTo($f, self::OTHER_KID, $next));
		self::assertFalse($this->visibleTo($f, self::KID, $next));
	}

	/**
	 * RF-084-01 / D-084-08: work due TODAY is never overdue — a sibling who
	 * does it the same day takes nothing over (no claim, no digest notice).
	 */
	public function testSameDayCompletionByAnotherChild_IsNoTakeover(): void {
		$f = $this->fixture((new \DateTime())->format('Y-m-d') . ' 00:00:00');

		$this->completeOccurrenceWithParticipants($this->completionService, $f['houseId'], self::OTHER_KID, $f['roomTaskId'],
			[['userId' => self::OTHER_KID, 'sharePct' => 100]]);

		self::assertSame(0, (int)$this->roomTaskRow($f['roomTaskId'])['was_claimed'], 'same-day help is no takeover');
		self::assertSame(0, $this->takeoverNoticeCount($f['houseId']), 'no "taken over" digest notice for the default assignee');
	}

	/**
	 * The list status the TaskManager renders (controller serializer path:
	 * batched cycle blocks + statusFor) follows the end of the window.
	 */
	public function testListStatus_FollowsHouseLocalWindowBoundary(): void {
		$today = $this->fixture((new \DateTime())->format('Y-m-d') . ' 00:00:00');
		self::assertSame(['due_today', 'open'], $this->listStatus($today));

		$missed = $this->fixture((new \DateTime('-1 day'))->format('Y-m-d') . ' 00:00:00');
		self::assertSame(['overdue', 'overdue'], $this->listStatus($missed));

		$flex = $this->fixture((new \DateTime('-1 day'))->format('Y-m-d') . ' 00:00:00', 2);
		self::assertSame(['due_today', 'open'], $this->listStatus($flex), 'an open flex window is still due, not overdue');
	}

	/**
	 * CR-084-03: single-row payloads (create, completion, execution rows)
	 * report the list status — an open flex window after the planned day is
	 * due.
	 */
	public function testRoomTaskPayload_ReportsTheListStatus(): void {
		$cases = [
			'missed' => [$this->fixture((new \DateTime('-1 day'))->format('Y-m-d') . ' 00:00:00'), 'overdue'],
			'open flex window' => [$this->fixture((new \DateTime('-1 day'))->format('Y-m-d') . ' 00:00:00', 2), 'due_today'],
		];
		foreach ($cases as $label => [$f, $expected]) {
			$roomTask = \OC::$server->get(\OCA\ChoreStrike\Db\RoomTaskMapper::class)->find($f['roomTaskId']);
			$tz = $this->readService->timeZoneForHouse($f['houseId']);
			$status = $this->readService->selectedRoomTaskPayload($f['houseId'], $roomTask, $tz)['status'];
			self::assertSame($expected, $status, $label);
			self::assertSame($this->listStatus($f)[0], $status, $label . ': equals the list row');
		}
	}

	/**
	 * CR-084-01: the calendar marks a day entry overdue only when its
	 * cycle's window has ended — a past planned day inside an open flex
	 * window is not late.
	 */
	public function testRangeRead_MarksOnlyEndedWindowsLate(): void {
		$cases = [
			'missed' => [$this->fixture((new \DateTime('-1 day'))->format('Y-m-d') . ' 00:00:00'), true],
			'open flex window' => [$this->fixture((new \DateTime('-1 day'))->format('Y-m-d') . ' 00:00:00', 2), false],
		];
		$yesterday = (new \DateTime('-1 day'))->format('Y-m-d');
		foreach ($cases as $label => [$f, $late]) {
			$owner = $this->houseUserMapper->findByNextcloudUserId($f['houseId'], self::OWNER);
			$range = \OC::$server->get(RoomTaskService::class)->getRangeTasks(
				$f['houseId'], new \DateTime('-3 days'), new \DateTime('+1 day'), $owner);
			$rows = array_values(array_filter($range['scheduled'], static fn (array $row): bool => $row['roomTask']['id'] === $f['roomTaskId']));
			self::assertCount(1, $rows, $label);
			$days = array_values(array_filter($rows[0]['cycles'], static fn (array $cycle): bool => $cycle['date'] === $yesterday));
			self::assertCount(1, $days, $label . ': the planned day is in the range');
			self::assertTrue($days[0]['current'], $label);
			self::assertSame($late, $days[0]['late'], $label);
		}
	}

	/** @return array{0: string, 1: ?string} [status, cycle state] as the list endpoint serializes them */
	private function listStatus(array $f): array {
		$roomTask = \OC::$server->get(\OCA\ChoreStrike\Db\RoomTaskMapper::class)->find($f['roomTaskId']);
		$block = $this->readService->cycleFacts($f['houseId'], [$roomTask])[$f['roomTaskId']]['block'];
		return [$this->readService->statusFor($roomTask, $block), $block['state'] ?? null];
	}

	private function takeoverNoticeCount(int $houseId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))
			->from('chorestrike_digest_changes')
			->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('object_type', $qb->createNamedParameter('takeover')));
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();
		return $count;
	}

	/**
	 * Adult owner (the acting parent) plus two children with bound wallets
	 * and one daily interval task (energy 2, within a child's daily budget)
	 * assigned to the first child from the given first date. Children see
	 * only the tasks they are assigned, which makes "who sees it" observable.
	 *
	 * @return array{houseId: int, roomTaskId: int}
	 */
	private function fixture(string $anchor, int $flexDays = 0): array {
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
		foreach (['frequency_type' => 'daily', 'frequency_value' => 1, 'scheduling_mode' => 'dynamic', 'recurrence_mode' => 'recurring', 'flex_days' => $flexDays] as $column => $value) {
			$qb->update('chorestrike_tasks')
				->set($column, $qb->createNamedParameter($value))
				->where($qb->expr()->eq('id', $qb->createNamedParameter($taskId, IQueryBuilder::PARAM_INT)))
				->executeStatement();
		}
		return ['houseId' => $houseId, 'roomTaskId' => $this->assignTaskToRoom($taskId, $roomId, self::KID, $anchor)];
	}

	/** The planned day of the cycle after the completion, at noon. */
	private function nextCycleDate(array $f): \DateTime {
		$roomTask = \OC::$server->get(\OCA\ChoreStrike\Db\RoomTaskMapper::class)->find($f['roomTaskId']);
		$block = $this->readService->cycleFacts($f['houseId'], [$roomTask])[$f['roomTaskId']]['block'];
		self::assertStringStartsWith('c', (string)$block['cycleKey'], 'the next cycle follows the completion');
		return new \DateTime($block['centreOn'] . ' 12:00:00');
	}

	/** @return list<string> */
	private function assignees(int $roomTaskId): array {
		return \OC::$server->get(TaskAssigneeMapper::class)->findUserIdsByRoomTask($roomTaskId);
	}

	/** Whether the child's daily read (the My Day list) contains the task on the date. */
	private function visibleTo(array $f, string $viewerId, \DateTime $date): bool {
		$viewer = $this->houseUserMapper->findByNextcloudUserId($f['houseId'], $viewerId);
		self::assertTrue($viewer->isChild(), 'visibility is asserted from a child, adults see every house task');
		foreach ($this->todayRowsOf($f['houseId'], $viewer, $date) as $row) {
			if ((int)$row['roomTask']->getId() === $f['roomTaskId']) {
				return true;
			}
		}
		return false;
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
