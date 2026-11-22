<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Db;

use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Service\CompletionService;
use OCA\ChoreStrike\Service\RoomTaskReadService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;
use OCA\ChoreStrike\Tests\Integration\Support\QueryBudgets;
use OCA\ChoreStrike\Tests\Integration\Support\QueryCounter;

/**
 * CS-PLAN-067 §3.5 / §4.1 — workflow query budgets, measured with the
 * executed-query counter ({@see QueryCounter}) against every supported DB.
 * The MariaDB budgets stay frozen. Provider overhead is separately pinned:
 * PostgreSQL identity reads and SQLite's write-lock-plus-read primitive.
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
 * - complete(): see the measured budget below for the whole aggregate —
 *   the receipt resolution (the fixture reads the current cycle), the
 *   command receipt read/insert, the house-first locked entity reads
 *   (house → room_task → room → house_user, CS-PLAN-071), the cycle target
 *   check under the lock (ADR-021 decision 4), the reward phase,
 *   transaction control, the single ledger INSERT, the closed-cycle row
 *   and the `next_due` projection, and the guarded post-commit weekly-goal
 *   cluster + response block. Per-class rule: at most two enrichment
 *   reads, exactly one ledger insert with a complete effect record (D1).
 * - undo(): see the measured budget below — same house-first gate/lock
 *   structure plus the effect-record restore (ledger read), the cycle
 *   reopen (closed row deleted, projection re-derived), transaction
 *   control and the post-commit weekly-goal read.
 */
class WorkflowQueryBudgetTest extends IntegrationTestCase {
	use QueryBudgets;

	private const HOUSE_READ_BUDGET = 3;
	// The aggregate writes the work evidence/shares/audit trio, posts wallet
	// entries with lazily ensured bindings, recalcs the derived contribution
	// cache, replays the room journal, closes the cycle (a savepoint-guarded
	// insert-if-absent, ADR-021 decision 5) and re-derives the rhythm on
	// re-loaded members. Correction safety adds under-lock
	// membership/obligation reads; the compact receipt requires an
	// after-commit viewer-safe read. Measured 87 on the fresh family profile
	// (91 before CS-PLAN-091 RF-091-08 removed repeated reads of the
	// definition, the members and the completion; the two coin multipliers
	// of ADR-021 decision 11 add the bonus-period read); the share and cycle
	// lookups are batched rather than per participant.
	private const COMPLETE_BUDGET = 87;
	// The targeted undo is a full correction aggregate — under-lock
	// completion re-read, wallet/member locking of the selected allocation,
	// negative ledger entries, work-share-driven contribution recache,
	// journal replay, the cycle reopen and the rhythm re-derivation over
	// both recognition sources. Under-lock re-authorization, actor-wallet
	// provenance, reset-bounded journal replay and compact-response
	// hydration bring the fresh family-profile measurement to 72 (83 before
	// CS-PLAN-091 RF-091-08: one correction skeleton, one batched member
	// reload). These are one batched read per concern, not one query per
	// historical event or work share.
	private const UNDO_BUDGET = 72;
	// The list read (3) plus the two completion-input reads of the cycle
	// derivation; the definitions and the house zone are handed on.
	private const LIST_WITH_CYCLES_BUDGET = 5;
	// One week of an evaluation that holds a pass: its stored occurrences,
	// its closed cycles and their work shares.
	private const WEEK_WITH_PASS_BUDGET = 3;
	// The daily read of *Today* with the live suggestion (ADR-022): the
	// house, the inputs of the plan (members, room tasks, definitions,
	// assignees, the two completion inputs, absences, today's work), the
	// stored day, then the rooms and the sub-task counts of the viewer's
	// rows, which are built from those inputs. Measured 12 once the day is
	// stored.
	private const TODAY_WITH_SUGGESTION_BUDGET = 12;
	// The day's first read of *Today*: the same, plus the insert and the
	// re-read of the snapshot. Measured 14.
	private const FIRST_TODAY_WITH_SUGGESTION_BUDGET = 14;
	// The range read of the calendar: the same inputs and the stored day,
	// plus the rooms of the entries. Measured 10.
	private const SUGGESTION_RANGE_BUDGET = 10;

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
			$smallResult = $readService->getHouseRoomTasks($smallHouse, $viewer)->roomTasks;
			$smallCount = $counter->count();

			$counter->mark();
			$bigResult = $readService->getHouseRoomTasks($bigHouse, $viewer)->roomTasks;
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
	 * HouseList_WithItsCycles_ConstantQueryCount_10vs100Rows
	 *
	 * The list a client reads: the visible room tasks and the derived cycle
	 * of each (ADR-021, D-091-09). The derivation takes the definitions the
	 * visibility decision read and the zone its caller holds, so it adds
	 * only the completion inputs — whatever the row count (RF-091-04).
	 */
	public function testHouseList_WithItsCycles_ConstantQueryCount_10vs100Rows(): void {
		$ownerId = 'wqb_cycle_owner';
		$this->actingAs($ownerId);
		$smallHouse = $this->createHouse($ownerId, 'WQB Cycles Small');
		$smallRoomTasks = $this->seedRoomsAndTasks($smallHouse, $ownerId, 2, 5);
		$bigHouse = $this->createHouse($ownerId, 'WQB Cycles Big');
		$bigRoomTasks = $this->seedRoomsAndTasks($bigHouse, $ownerId, 10, 10);
		// One completion each: the derivation then reads both completion inputs.
		$completions = \OC::$server->get(CompletionService::class);
		$this->completeOccurrence($completions, $smallHouse, $ownerId, $smallRoomTasks[0]);
		$this->completeOccurrence($completions, $bigHouse, $ownerId, $bigRoomTasks[0]);

		$readService = \OC::$server->get(RoomTaskReadService::class);
		$viewer = \OC::$server->get(HouseUserMapper::class)->findByNextcloudUserId($smallHouse, $ownerId);
		$zone = new \DateTimeZone('UTC');
		$read = static function (int $houseId) use ($readService, $viewer, $zone): array {
			$list = $readService->getHouseRoomTasks($houseId, $viewer);
			return $readService->cycleFacts($houseId, $list->roomTasks, $list->tasksById, null, $zone);
		};

		$counter = QueryCounter::attach($this->db);
		try {
			$counter->mark();
			$smallFacts = $read($smallHouse);
			$smallCount = $counter->count();

			$counter->mark();
			$bigFacts = $read($bigHouse);
			$bigCount = $counter->count();
		} finally {
			$counter->detach();
		}

		self::assertCount(10, $smallFacts);
		self::assertCount(100, $bigFacts);
		foreach ($bigFacts as $fact) {
			self::assertNotEmpty($fact['block']['cycleKey'] ?? null, 'every row carries its completion target');
		}
		self::assertStringStartsWith('c', $bigFacts[$bigRoomTasks[0]]['block']['cycleKey'], 'the completed task hangs on its completion');
		self::assertSame($smallCount, $bigCount, 'the cycle derivation is batched: Q(100) must equal Q(10)');
		self::assertLessThanOrEqual(self::LIST_WITH_CYCLES_BUDGET, $bigCount, implode("\n", $counter->sqlStatements()));
	}

	/**
	 * TodayAndCalendar_WithTheLiveSuggestion_ConstantQueryCount_10vs100Rows
	 *
	 * *Today* and the calendar place every open cycle of the house on
	 * each read (ADR-022 decision 1). The plan is computed from a bounded
	 * batch per source, so ten times the tasks cost the same statements.
	 */
	public function testTodayAndCalendar_WithTheLiveSuggestion_ConstantQueryCount_10vs100Rows(): void {
		$ownerId = 'wqb_suggest_owner';
		$this->actingAs($ownerId);
		$smallHouse = $this->createHouse($ownerId, 'WQB Suggest Small');
		$this->addHouseMember($smallHouse, 'wqb_suggest_kid', 'Kid', 'child');
		$smallRoomTasks = $this->seedRoomsAndTasks($smallHouse, $ownerId, 2, 5);
		$bigHouse = $this->createHouse($ownerId, 'WQB Suggest Big');
		$this->addHouseMember($bigHouse, 'wqb_suggest_kid', 'Kid', 'child');
		$bigRoomTasks = $this->seedRoomsAndTasks($bigHouse, $ownerId, 10, 10);
		// One completion each: the plan then reads both completion inputs and today's work.
		$completions = \OC::$server->get(CompletionService::class);
		$this->completeOccurrence($completions, $smallHouse, $ownerId, $smallRoomTasks[0]);
		$this->completeOccurrence($completions, $bigHouse, $ownerId, $bigRoomTasks[0]);

		$readService = \OC::$server->get(RoomTaskReadService::class);
		$suggestions = \OC::$server->get(\OCA\ChoreStrike\Service\SuggestionService::class);
		$houses = \OC::$server->get(\OCA\ChoreStrike\Db\HouseMapper::class);
		$members = \OC::$server->get(HouseUserMapper::class);
		$today = (new \DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d');
		$last = (new \DateTime($today))->modify('+13 days')->format('Y-m-d');
		$counts = [];
		$rows = [];
		$entries = [];

		$counter = QueryCounter::attach($this->db);
		try {
			foreach (['small' => $smallHouse, 'big' => $bigHouse] as $size => $houseId) {
				$viewer = $members->findByNextcloudUserId($houseId, $ownerId);
				// The day's first read stores today's list; the reads after it find it.
				$counter->mark();
				$readService->getFilteredDailyTasksForUser($houseId, $viewer, new \DateTime());
				$counts[$size]['first'] = $counter->count();
				$counter->mark();
				$rows[$size] = $readService->getFilteredDailyTasksForUser($houseId, $viewer, new \DateTime());
				$counts[$size]['today'] = $counter->count();

				$house = $houses->find($houseId);
				$counter->mark();
				$range = $suggestions->range($house, $viewer, $today, $last);
				$counts[$size]['range'] = $counter->count();
				$entries[$size] = array_sum(array_map(static fn (array $day): int => count($day['entries']), $range['days']));
			}
		} finally {
			$counter->detach();
		}

		// Every seeded task is weekly and due today: the open ones are rows, and the horizon holds
		// two cycles of each; the completed one has its next cycle a week from now.
		self::assertCount(9, $rows['small']);
		self::assertCount(99, $rows['big']);
		self::assertSame([19, 199], [$entries['small'], $entries['big']]);
		self::assertSame($counts['small'], $counts['big'], 'the suggestion is batched: Q(100) must equal Q(10)');
		self::assertLessThanOrEqual(self::TODAY_WITH_SUGGESTION_BUDGET, $counts['big']['today'], implode("\n", $counter->sqlStatements()));
		self::assertLessThanOrEqual(self::SUGGESTION_RANGE_BUDGET, $counts['big']['range'], implode("\n", $counter->sqlStatements()));
		self::assertSame(
			[self::FIRST_TODAY_WITH_SUGGESTION_BUDGET, self::TODAY_WITH_SUGGESTION_BUDGET, self::SUGGESTION_RANGE_BUDGET],
			[$counts['big']['first'], $counts['big']['today'], $counts['big']['range']],
			'a lower count is welcome: lower the pin with it'
		);
	}

	/**
	 * RecognitionWeeks_WithOnePass_ReadTheOpenWorkOnce
	 *
	 * The closed-week scan of the timeline job and the game state evaluate
	 * several weeks or slices at once. With one {@see RecognitionPass} the
	 * open work and the absences are read once; each further week costs only
	 * its own closed facts (RF-091-03).
	 */
	public function testRecognitionWeeks_WithOnePass_ReadTheOpenWorkOnce(): void {
		$ownerId = 'wqb_pass_owner';
		$this->actingAs($ownerId);
		$houseId = $this->createHouse($ownerId, 'WQB Pass');
		$this->seedRoomsAndTasks($houseId, $ownerId, 10, 10);
		$house = \OC::$server->get(\OCA\ChoreStrike\Db\HouseMapper::class)->find($houseId);
		$facts = \OC::$server->get(\OCA\ChoreStrike\Service\RecognitionFactsService::class);
		$now = new \DateTime('now', new \DateTimeZone('UTC'));
		$thisWeek = (clone $now)->modify('monday this week')->format('Y-m-d');
		$lastWeek = (clone $now)->modify('monday this week')->modify('-7 days')->format('Y-m-d');

		$counter = QueryCounter::attach($this->db);
		try {
			$counter->mark();
			$pass = $facts->beginPass($house);
			$passCount = $counter->count();

			$counter->mark();
			$withPass = $facts->factsForWeek($house, $thisWeek, $now, $pass);
			$weekCount = $counter->count();

			$counter->mark();
			$facts->factsForWeek($house, $lastWeek, $now, $pass);
			$secondWeekCount = $counter->count();

			$counter->mark();
			$withoutPass = $facts->factsForWeek($house, $thisWeek, $now);
			$freshCount = $counter->count();
		} finally {
			$counter->detach();
		}

		self::assertCount(100, $withPass, 'the open cycle of every seeded task ends this week');
		self::assertEquals($withoutPass, $withPass, 'a pass changes the cost, never the facts');
		self::assertLessThanOrEqual(self::WEEK_WITH_PASS_BUDGET, $weekCount, implode("\n", $counter->sqlStatements()));
		self::assertLessThanOrEqual(self::WEEK_WITH_PASS_BUDGET, $secondWeekCount);
		self::assertSame($passCount + $weekCount, $freshCount, 'without a pass every week pays for the open inputs again');
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
			$result = $readService->getHouseRoomTasks($houseId, $viewer)->roomTasks;
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
	 * Completion_TransactionalPhaseWithinBudget_LedgerExactlyOnce
	 *
	 * Per-class rule: at most two enrichment reads, exactly ONE ledger
	 * insert with a complete effect record (cycle facts, before_state,
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
		$completion = \OC::$server->get(CompletionService::class);

		$counter = QueryCounter::attach($this->db);
		try {
			$counter->mark();
			$result = $this->completeOccurrence($completion, $houseId, $ownerId, $roomTaskId);
		} finally {
			$counter->detach();
		}

		// Exact result identity/state.
		self::assertSame($roomTaskId, $result['roomTask']->getId());
		self::assertGreaterThan(0, $result['rewards']['wallet']['bookedMinor']);

		// Exactly ONE work-evidence row (WP3 §3.1) with the frozen work
		// facts, ONE selected work share and ONE audit revision.
		$ledgerRows = $this->ledgerRows($roomTaskId);
		self::assertCount(1, $ledgerRows, 'exactly one evidence row per completion');
		self::assertMatchesRegularExpression('/^d\d{4}-\d{2}-\d{2}$/', (string)$ledgerRows[0]['cycle_key'], 'the evidence row carries the cycle it closed');
		self::assertNotNull($ledgerRows[0]['window_end_on'], 'and the final window, frozen');
		self::assertGreaterThan(0, (int)$ledgerRows[0]['total_energy']);
		$shares = $this->rowsBy('chorestrike_work_shares', 'completion_id', (int)$ledgerRows[0]['id']);
		self::assertCount(1, $shares, 'exactly one selected work share (solo completion)');
		$revs = $this->rowsBy('chorestrike_completion_revs', 'completion_id', (int)$ledgerRows[0]['id']);
		self::assertCount(1, $revs, 'the append-only audit records the completion');

		// Locked reads in the single house-first order (CS-PLAN-071):
		// house → room_task → room, then the member + wallet locks of the
		// reward phase (including a lazily ensured wallet binding) inside
		// the same locked section.
		$this->assertPortableWriteBudget($counter, self::COMPLETE_BUDGET, 7, 7);
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
		$completion = \OC::$server->get(CompletionService::class);
		$completed = $this->completeOccurrence($completion, $houseId, $ownerId, $roomTaskId);

		$actor = \OC::$server->get(HouseUserMapper::class)->findByNextcloudUserId($houseId, $ownerId);
		$walletMapper = \OC::$server->get(\OCA\ChoreStrike\Db\WalletMapper::class);
		$wallet = $walletMapper->findByHouseAndUser($houseId, $ownerId);
		$bookedAfterCompletion = $wallet->getBookedMinor();

		$counter = QueryCounter::attach($this->db);
		try {
			$counter->mark();
			$result = $completion->undo($houseId, $actor, (int)$completed['completionId'], sprintf('d1c2e3f4-a5b6-4c7d-8e9f-%012d', random_int(0, 999999999999)), 0, null);
		} finally {
			$counter->detach();
		}

		// Exact result identity/state.
		self::assertTrue($result['undone']);
		// Reconciliation: the undo posts the exact negative wallet entries —
		// the booked total returns to the pre-completion level (fresh
		// fixture: completion credit = totalEnergy * 1000 minor).
		$walletAfter = $walletMapper->findByHouseAndUser($houseId, $ownerId);
		self::assertSame(
			$completed['rewards']['wallet']['bookedMinor'] - $completed['rewards']['completion']['totalEnergy'] * 1000,
			$walletAfter->getBookedMinor(),
			'the reverted credit reconciles the pre-completion wallet total'
		);

		// The evidence row is marked undone, not deleted.
		$ledgerRows = $this->ledgerRows($roomTaskId);
		self::assertCount(1, $ledgerRows);
		self::assertNotNull($ledgerRows[0]['undone_at'], 'undo marks the row undone');

		$this->assertPortableWriteBudget($counter, self::UNDO_BUDGET, 7, 3);
	}

	// ========== Helpers ==========

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function rowsBy(string $table, string $column, int $value): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($table)
			->where($qb->expr()->eq($column, $qb->createNamedParameter($value, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();
		return is_array($rows) ? $rows : [];
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
