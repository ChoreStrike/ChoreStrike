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
 * - updateDueDate: 19 observed statements — gate reads (room task,
 *   definition, assignees) plus the owning-house resolution for the
 *   house-first lock (room → floor → house, CS-PLAN-071), BEGIN, the house
 *   FOR UPDATE, the room-task FOR UPDATE, the AGREEMENT-writer tail
 *   (CS-PLAN-072 WP1: timeline read, occurrence amendment, agreement
 *   evidence insert, projection update), COMMIT and exactly ONE
 *   authoritative after-commit re-read that becomes the response.
 * - complete(): ≤ 45 observed statements for the whole aggregate —
 *   the receipt resolution (timeline reads + selection), the command
 *   receipt read/insert, occurrence gates, the house-first locked entity
 *   reads (house → room_task → room → house_user, CS-PLAN-071 —
 *   supersedes the ADR-011 entry order) plus the reward phase
 *   findForUpdate, the checklist reads, the reward window fetch,
 *   transaction control, the single ledger INSERT, the timeline effects
 *   (fulfill, catch-up cover, successor anchor, projection) and the
 *   guarded post-commit weekly-goal cluster + response selection block.
 *   Per-class rule: at most two enrichment reads, exactly one ledger
 *   insert with a complete effect record (D1).
 * - undo(): ≤ 36 observed statements — same house-first gate/lock structure
 *   plus the effect-record restore (ledger read), the timeline release
 *   (occurrence reopen, successor deletion, projection re-derivation),
 *   transaction control and the post-commit weekly-goal read.
 */
class WorkflowQueryBudgetTest extends IntegrationTestCase {
	private const HOUSE_READ_BUDGET = 3;
	private const UPDATE_DUE_DATE_BUDGET = 19;
	// CS-PLAN-073 WP2: the completion re-validation re-resolves the canonical
	// selection WITH the pause-projection stamp (pauses, current wallets,
	// member snapshots) and re-fingerprints the pause inputs inside the token
	// — a bounded +13 batched queries (measured 58 in the two-member house).
	// RF-073-WP2-20 fix round: the stamping pass derives the agreed work
	// appointment INDEPENDENTLY of pause presence, so each of the three
	// stamp passes in this flow now always runs its ONE batched member-
	// snapshot read (58 → 61; still constant-size, no N+1).
	private const COMPLETE_BUDGET = 61;
	// CS-PLAN-073 WP2: the undo response's detail row now fingerprints the
	// pause inputs and re-resolves the selection with the projection stamp
	// (pause fingerprint + batched pause/wallet/member reads) → +4.
	private const UNDO_BUDGET = 40;

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
	 * UpdateDueDate_WritesUnderLock_SingleAuthoritativeReread
	 *
	 * CS-PLAN-067 §3.3 + CS-PLAN-072 WP1: the due-date edit writes under
	 * the house-first lock through the agreement writer (occurrence
	 * amendment + evidence + projection); the response is ONE authoritative
	 * after-commit re-read of the persisted row — no other query follows.
	 */
	public function testUpdateDueDate_WritesUnderLock_SingleAuthoritativeReread(): void {
		$ownerId = 'wqb_due_owner';
		$this->actingAs($ownerId);
		$houseId = $this->createHouse($ownerId, 'WQB Due');
		$floorId = $this->createFloor($houseId);
		$roomId = $this->createRoom($houseId, $floorId, 'Kitchen');
		$taskId = $this->createTask($houseId, 'WQB due task');
		$roomTaskId = $this->assignTaskToRoom($taskId, $roomId);
		$viewer = \OC::$server->get(HouseUserMapper::class)->findByNextcloudUserId($houseId, $ownerId);

		$service = \OC::$server->get(RoomTaskService::class);
		$newDue = '+3 days';

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
		// CS-PLAN-072 WP1: next_due is the derived projection of the amended
		// occurrence — the house-local day start, not the parsed instant.
		$expectedDue = (new \DateTime('+3 days'))->format('Y-m-d') . ' 00:00:00';
		self::assertSame(
			$expectedDue,
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
		// the owning-house resolution (room → floor → house, CS-PLAN-071) +
		// BEGIN + house FOR UPDATE + room-task FOR UPDATE + the agreement
		// writer (timeline read, occurrence amendment, agreement evidence,
		// projection refresh) + COMMIT + the single authoritative re-read.
		// The counter observes transaction-control statements too, hence 19.
		self::assertLessThanOrEqual(self::UPDATE_DUE_DATE_BUDGET, $count, implode("\n", $counter->sqlStatements()));

		// CS-PLAN-072 WP1: the transaction ends with the occurrence reload
		// and the derived-projection update; exactly ONE authoritative
		// re-read follows COMMIT (the response entity) — nothing else.
		// RF-072-WP1-09: the projection refresh follows ONE indexed row
		// query (the earliest open scheduled occurrence), not a full
		// occurrence-history load.
		$statements = $counter->sqlStatements();
		$commitIndex = array_search('"COMMIT"', $statements, true);
		self::assertNotFalse($commitIndex, 'the transaction committed');
		$transactionTail = array_slice($statements, $commitIndex - 2, 2);
		self::assertSame(
			[
				'SELECT * FROM `oc_chorestrike_occurrences` WHERE (`room_task_id` = :dcValue1) AND (`completion_id` IS NULL) AND (`covered_by_completion_id` IS NULL) AND (`kind` = :dcValue2) ORDER BY `day_start` ASC, `schedule_rev_id` ASC, `ordinal` ASC LIMIT 1',
				'UPDATE `oc_chorestrike_room_tasks` SET `next_due` = :dcValue1, `ideal_due_date` = :dcValue2 WHERE `id` = :dcValue3',
			],
			$transactionTail,
			'the transaction ends with the timeline reload and the projection update'
		);
		self::assertCount($commitIndex + 2, $statements, 'exactly one authoritative re-read runs after commit');
		// CS-PLAN-071: house lock first, then the room-task lock.
		self::assertSame(2, $lockSelects, 'exactly two FOR UPDATE lock selects (house → room task)');
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
			$result = $this->completeOccurrence($completion, $houseId, $ownerId, $roomTaskId);
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

		// Five locked reads: house → room_task → room → house_user (the
		// single house-first order, CS-PLAN-071 — supersedes the ADR-011
		// entry order) plus the reward phase's findForUpdate inside the same
		// locked section.
		self::assertSame(5, $lockSelects);

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
		$this->completeOccurrence($completion, $houseId, $ownerId, $roomTaskId);

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

		// Four locked reads: house → room_task → room → house_user (the
		// single house-first order, CS-PLAN-071); the undo path rebuilds
		// rewards from the effect record instead of the reward phase, so no
		// fifth lock occurs.
		self::assertSame(4, $lockSelects);
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
