<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Db;

use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Service\CompletionService;
use OCA\ChoreStrike\Service\RoomTaskReadService;
use OCA\ChoreStrike\Service\RoomTaskService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;
use OCA\ChoreStrike\Tests\Integration\Support\QueryCounter;

/**
 * CS-PLAN-067 §3.5 / §4.1 — workflow query budgets, measured with the
 * executed-query counter ({@see QueryCounter}) against the real MySQL
 * profile of the canonical Docker stack.
 *
 * Budgets are LITERAL frozen integers, not relative assertions, so a
 * regression cannot silently re-baseline. Every budget also asserts the
 * exact result identity/state so a lower count can never be bought with
 * wrong data.
 *
 * Budget reasoning:
 * - House-wide instance read: 3 bounded queries — the house-scoped mapper
 *   fetch, the batched task-definition fetch for the visibility matrix and
 *   the batched assignee fetch it performs. CONSTANT in the row count —
 *   Q(100) = Q(10) within one chunk (no N+1, D2).
 * - Empty set: exactly 1 query — the mapper fetch; NO enrichment query.
 * - updateDueDate: 8 observed statements — gate reads (room task,
 *   definition, assignees), BEGIN, FOR UPDATE, UPDATE, the locked fresh
 *   read that becomes the response, COMMIT. The former after-commit
 *   re-fetch is GONE (§3.3) — proven statement-order-wise: nothing runs
 *   after COMMIT.
 * - complete(): ≤ 27 observed statements for the whole aggregate —
 *   occurrence gates, the three locked entity reads (room_task → room →
 *   house_user, ADR-011) plus the reward phase findForUpdate, the
 *   checklist reads, the reward window fetch, transaction control, and
 *   the single ledger INSERT, plus the guarded post-commit weekly-goal
 *   cluster. Per-class rule: at most two enrichment reads, exactly one
 *   ledger insert with a complete effect record (D1).
 * - undo(): ≤ 32 observed statements — same gate/lock structure plus the
 *   effect-record restore (ledger read, checklist rewrite), transaction
 *   control and the post-commit weekly-goal read.
 */
class WorkflowQueryBudgetTest extends IntegrationTestCase {
	private const HOUSE_READ_BUDGET = 3;
	private const UPDATE_DUE_DATE_BUDGET = 8;
	private const COMPLETE_BUDGET = 27;
	private const UNDO_BUDGET = 32;

	/**
	 * HouseWideRead_ConstantQueryCount_10vs100Rows
	 *
	 * Q(100) = Q(10) within one chunk — the house-wide read stays at two
	 * executed queries regardless of the instance count (CS-PLAN-067 D2).
	 */
	public function testHouseWideRead_ConstantQueryCount_10vs100Rows(): void {
		$ownerId = 'wqb_owner';

		// --- Small house: 2 rooms, 10 instances ---
		$this->actingAs($ownerId);
		$smallHouse = $this->createHouse($ownerId, 'WQB Small');
		$smallRoomTasks = $this->seedRoomsAndTasks($smallHouse, $ownerId, 2, 5);

		// --- Big house: 10 rooms, 100 instances ---
		$bigHouse = $this->createHouse($ownerId, 'WQB Big');
		$bigRoomTasks = $this->seedRoomsAndTasks($bigHouse, $ownerId, 10, 10);

		$this->actingAs($ownerId);
		$readService = \OC::$server->get(RoomTaskReadService::class);
		$viewer = \OC::$server->get(HouseUserMapper::class)->findByNextcloudUserId($smallHouse, $ownerId);

		$counter = QueryCounter::attach($this->db);
		try {
			$counter->mark();
			$smallResult = $readService->getHouseRoomTasks($smallHouse, $viewer);
			$smallCount = $counter->count();

			$counter->mark();
			$bigResult = $readService->getHouseRoomTasks($bigHouse, $viewer);
			$bigCount = $counter->count();
		} finally {
			$counter->detach();
		}

		// Exact result identity: every seeded instance id is present.
		$smallIds = array_map(static fn ($rt) => $rt->getId(), $smallResult);
		sort($smallIds);
		self::assertSame($smallRoomTasks, $smallIds, 'the small-house read must return exactly the seeded ids');

		$bigIds = array_map(static fn ($rt) => $rt->getId(), $bigResult);
		sort($bigIds);
		self::assertSame($bigRoomTasks, $bigIds, 'the big-house read must return exactly the seeded ids');

		// The frozen constant-size budget — 10x the rows, SAME query count.
		self::assertSame(
			$smallCount,
			$bigCount,
			'Q(100) must equal Q(10) within one chunk — an N+1 reintroduction fails here'
		);
		self::assertLessThanOrEqual(self::HOUSE_READ_BUDGET, $bigCount, implode("\n", $counter->sqlStatements()));
	}

	/**
	 * HouseWideRead_EmptySet_IssuesNoEnrichmentQueries
	 */
	public function testHouseWideRead_EmptySet_IssuesNoEnrichmentQueries(): void {
		$ownerId = 'wqb_empty_owner';
		$this->actingAs($ownerId);
		$houseId = $this->createHouse($ownerId, 'WQB Empty');
		$viewer = \OC::$server->get(HouseUserMapper::class)->findByNextcloudUserId($houseId, $ownerId);

		$readService = \OC::$server->get(RoomTaskReadService::class);

		$counter = QueryCounter::attach($this->db);
		try {
			$counter->mark();
			$result = $readService->getHouseRoomTasks($houseId, $viewer);
			$count = $counter->count();
		} finally {
			$counter->detach();
		}

		self::assertSame([], $result);
		self::assertSame(
			1,
			$count,
			'an empty house read is exactly ONE mapper query — no enrichment'
		);
	}

	/**
	 * UpdateDueDate_WritesUnderLock_NoPostCommitRefetch
	 *
	 * CS-PLAN-067 §3.3: the response entity is produced INSIDE the
	 * transaction (locked fresh read); the after-commit re-fetch is gone.
	 */
	public function testUpdateDueDate_WritesUnderLock_NoPostCommitRefetch(): void {
		$ownerId = 'wqb_due_owner';
		$this->actingAs($ownerId);
		$houseId = $this->createHouse($ownerId, 'WQB Due');
		$floorId = $this->createFloor($houseId);
		$roomId = $this->createRoom($houseId, $floorId, 'Kitchen');
		$taskId = $this->createTask($houseId, 'WQB due task');
		$roomTaskId = $this->assignTaskToRoom($taskId, $roomId);
		$viewer = \OC::$server->get(HouseUserMapper::class)->findByNextcloudUserId($houseId, $ownerId);

		$service = \OC::$server->get(RoomTaskService::class);
		$newDue = new \DateTime('+3 days');

		$counter = QueryCounter::attach($this->db);
		try {
			$counter->mark();
			$response = $service->updateDueDate($roomTaskId, $newDue, $viewer);
			$count = $counter->count();
			$lockSelects = $counter->countLockSelects();
		} finally {
			$counter->detach();
		}

		// Response state equality: the returned entity carries the new date.
		self::assertSame(
			$newDue->format('Y-m-d H:i:s'),
			$response->getNextDue()?->format('Y-m-d H:i:s'),
			'the in-transaction response must carry the new due date'
		);

		// And the DB row agrees (no write/read divergence).
		$mapper = \OC::$server->get(RoomTaskMapper::class);
		self::assertSame(
			$response->getNextDue()?->format('Y-m-d H:i:s'),
			$mapper->find($roomTaskId)->getNextDue()?->format('Y-m-d H:i:s')
		);

		// The frozen budget: gate reads (room task, definition, assignees) +
		// BEGIN + FOR UPDATE + UPDATE + locked fresh read + COMMIT. The
		// counter observes transaction-control statements too, hence 8.
		self::assertLessThanOrEqual(self::UPDATE_DUE_DATE_BUDGET, $count, implode("\n", $counter->sqlStatements()));

		// The after-commit re-fetch is GONE: the transaction ends with the
		// lock read, the UPDATE and the fresh locked read that becomes the
		// response — and NOTHING follows COMMIT.
		$statements = $counter->sqlStatements();
		$commitIndex = array_search('"COMMIT"', $statements, true);
		self::assertNotFalse($commitIndex, 'the transaction committed');
		$transactionTail = array_slice($statements, $commitIndex - 3, 3);
		self::assertSame(
			[
				'SELECT * FROM oc_chorestrike_room_tasks WHERE id = :id FOR UPDATE',
				'UPDATE `oc_chorestrike_room_tasks` SET `next_due` = :dcValue1 WHERE `id` = :dcValue2',
				'SELECT * FROM `oc_chorestrike_room_tasks` WHERE `id` = :dcValue1',
			],
			$transactionTail,
			'the transaction ends lock → update → fresh read; the response entity is read under the lock'
		);
		self::assertCount($commitIndex + 1, $statements, 'no query runs after commit — the former re-fetch is removed');
		self::assertSame(1, $lockSelects, 'exactly one FOR UPDATE lock select');
	}

	/**
	 * Completion_TransactionalPhaseWithinBudget_LedgerExactlyOnce
	 *
	 * Per-class rule: at most two enrichment reads, exactly ONE ledger
	 * insert with a complete effect record (occurrence_due, before_state,
	 * deltas — D1).
	 */
	public function testCompletion_TransactionalPhaseWithinBudget_LedgerExactlyOnce(): void {
		$ownerId = 'wqb_complete_owner';
		$this->actingAs($ownerId);
		$houseId = $this->createHouse($ownerId, 'WQB Complete');
		$floorId = $this->createFloor($houseId);
		$roomId = $this->createRoom($houseId, $floorId, 'Kitchen');
		$taskId = $this->createTask($houseId, 'WQB complete task');
		$roomTaskId = $this->assignTaskToRoom($taskId, $roomId, $ownerId);
		// The completion reaches the weekly goal (due today + completed =
		// 100% ≥ any configured target). Pin the house's notification week
		// to the CURRENT week so the claim deterministically LOSES — the
		// branch is exercised, but the order-dependent notify INSERT stays
		// out of the measured workload (full-suite runs leaked a global
		// appconfig override into this branch).
		$this->pinWeeklyGoalClaimAsDone($houseId);

		$completion = \OC::$server->get(CompletionService::class);

		$counter = QueryCounter::attach($this->db);
		try {
			$counter->mark();
			$result = $completion->complete($houseId, $ownerId, $roomTaskId);
			$count = $counter->count();
			$lockSelects = $counter->countLockSelects();
		} finally {
			$counter->detach();
		}

		// Exact result identity/state.
		self::assertSame($roomTaskId, $result['roomTask']->getId());
		self::assertGreaterThan(0, $result['rewards']['coins']);

		// Exactly ONE ledger row with a COMPLETE effect record.
		$ledgerRows = $this->ledgerRows($roomTaskId);
		self::assertCount(1, $ledgerRows, 'exactly one ledger row per completion');
		self::assertNotNull($ledgerRows[0]['occurrence_due'], 'the effect record carries occurrence_due');
		self::assertNotSame('{}', (string)$ledgerRows[0]['before_state'], 'before_state is a real snapshot');
		self::assertArrayHasKey('coinsAwarded', json_decode((string)$ledgerRows[0]['deltas'], true));

		// Four locked reads: room_task → room → house_user (ADR-011) plus
		// the reward phase's findForUpdate inside the same locked section.
		self::assertSame(4, $lockSelects);

		self::assertLessThanOrEqual(self::COMPLETE_BUDGET, $count, implode("\n", $counter->sqlStatements()));
	}

	/**
	 * Undo_RestoresStateWithinBudget
	 */
	public function testUndo_RestoresStateWithinBudget(): void {
		$ownerId = 'wqb_undo_owner';
		$this->actingAs($ownerId);
		$houseId = $this->createHouse($ownerId, 'WQB Undo');
		$floorId = $this->createFloor($houseId);
		$roomId = $this->createRoom($houseId, $floorId, 'Kitchen');
		$taskId = $this->createTask($houseId, 'WQB undo task');
		$roomTaskId = $this->assignTaskToRoom($taskId, $roomId, $ownerId);
		// Same determinism pin as the completion budget test.
		$this->pinWeeklyGoalClaimAsDone($houseId);

		$completion = \OC::$server->get(CompletionService::class);
		$completion->complete($houseId, $ownerId, $roomTaskId);

		$actor = \OC::$server->get(HouseUserMapper::class)->findByNextcloudUserId($houseId, $ownerId);
		$coinsAfterCompletion = $actor->getCoins();

		$counter = QueryCounter::attach($this->db);
		try {
			$counter->mark();
			$result = $completion->undo($houseId, $actor, $roomTaskId);
			$count = $counter->count();
			$lockSelects = $counter->countLockSelects();
		} finally {
			$counter->detach();
		}

		// Exact result identity/state.
		self::assertTrue($result['undone']);
		// Reconciliation: the post-undo totalCoins IS the pre-completion
		// total; reverted coins + pre-total = the completion-time total.
		self::assertSame(
			$coinsAfterCompletion,
			$result['rewards']['totalCoins'] - $result['rewards']['coins'],
			'the reverted coins reconcile the pre-completion total'
		);

		// The ledger row is marked undone, not deleted.
		$ledgerRows = $this->ledgerRows($roomTaskId);
		self::assertCount(1, $ledgerRows);
		self::assertNotNull($ledgerRows[0]['undone_at'], 'undo marks the row undone');

		// Three locked reads: room_task → room → house_user (ADR-011); the
		// undo path rebuilds rewards from the effect record instead of the
		// reward phase, so no fourth lock occurs.
		self::assertSame(3, $lockSelects);
		self::assertLessThanOrEqual(self::UNDO_BUDGET, $count, implode("\n", $counter->sqlStatements()));
	}

	// ========== Helpers ==========

	/**
	 * Set the house's weekly-goal notification week to the CURRENT week so
	 * the post-commit claim in a completion deterministically loses the
	 * week-change comparison (the branch runs, the order-dependent
	 * notification INSERT does not).
	 */
	private function pinWeeklyGoalClaimAsDone(int $houseId): void {
		$weekKey = (new \DateTime('monday this week'))->format('Y-m-d');
		$qb = $this->db->getQueryBuilder();
		$qb->update('chorestrike_houses')
			->set('weekly_goal_notified_week', $qb->createNamedParameter($weekKey))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($houseId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	/**
	 * Seed `$rooms` rooms with `$tasksPerRoom` tasks each; returns the
	 * created room task ids (sorted).
	 *
	 * @return list<int>
	 */
	private function seedRoomsAndTasks(int $houseId, string $ownerId, int $rooms, int $tasksPerRoom): array {
		$ids = [];
		for ($r = 0; $r < $rooms; $r++) {
			$floorId = $this->createFloor($houseId, "Floor $r", $r + 1);
			$roomId = $this->createRoom($houseId, $floorId, "Room $r");
			for ($t = 0; $t < $tasksPerRoom; $t++) {
				$taskId = $this->createTask($houseId, "WQB task $r-$t");
				$ids[] = $this->assignTaskToRoom($taskId, $roomId);
			}
		}
		sort($ids);
		return $ids;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function ledgerRows(int $roomTaskId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('chorestrike_completed_tasks')
			->where($qb->expr()->eq('room_task_id', $qb->createNamedParameter($roomTaskId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
		$rows = $qb->executeQuery()->fetchAll();
		return is_array($rows) ? $rows : [];
	}
}
