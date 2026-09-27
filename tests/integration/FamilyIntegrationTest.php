<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration;

use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Exception\ConflictException;
use OCA\ChoreStrike\Service\CompletionService;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * Family-scenario integration suite (CS-PLAN-068 §3.1).
 *
 * The historical eight skips (mocked GameLogicService scenarios around the
 * removed FamilyTestData dataset with pre-CS-PLAN-002 point expectations)
 * are replaced by behavior-bearing real-DB tests through the
 * CompletionService aggregate and real persisted rows. Reward FORMULAS stay
 * owned by the frozen-rule unit tests
 * (tests/unit/Service/GameLogicServiceTest.php); these scenarios pin the
 * exact credited deltas against independently captured before/after totals,
 * the occurrence gates, streak progression, recurrence activation/
 * deactivation and the cleanliness impact.
 *
 * Two-connection race behavior (two users completing the same task type)
 * keeps its dedicated engine-guarded owners: CompletionConcurrencyTest,
 * ConcurrentCompletionTest and MinigameConcurrencyTest — linked, not
 * duplicated here.
 */
class FamilyIntegrationTest extends IntegrationTestCase {
	private CompletionService $completionService;
	/** @var array<int> Ledger rows created through the completion service. */
	private array $ledgerRoomTaskIds = [];

	protected function setUp(): void {
		parent::setUp();
		$this->completionService = \OC::$server->get(CompletionService::class);
	}

	protected function tearDown(): void {
		if ($this->ledgerRoomTaskIds !== []) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('chorestrike_completed_tasks')
				->where($qb->expr()->in('room_task_id', $qb->createNamedParameter($this->ledgerRoomTaskIds, IQueryBuilder::PARAM_INT_ARRAY)))
				->executeStatement();
			$this->ledgerRoomTaskIds = [];
		}
		parent::tearDown();
	}

	/**
	 * FamilyTaskCompletionWorkflow_CompletesAssignedDailyTask_WithExactCreditedDeltas
	 *
	 * The canonical family workflow: an assigned member completes their
	 * daily task; the receipt and the independently captured before/after
	 * totals must agree on every credited delta (WP3 §3.1: contribution
	 * and wallet derive from the selected work shares in exact minor
	 * units; cleanliness = 10% per energy point).
	 */
	public function testFamilyTaskCompletionWorkflow(): void {
		$ids = $this->familyHouseFixture();
		$before = $this->memberRow($ids['houseId'], 'fam_parent');

		$result = $this->completeOccurrence($this->completionService, $ids['houseId'], 'fam_parent', $ids['dailyRoomTaskId']);
		$this->ledgerRoomTaskIds[] = $ids['dailyRoomTaskId'];

		$rewards = $result['rewards'];
		$after = $this->memberRow($ids['houseId'], 'fam_parent');

		self::assertSame(2, $rewards['energyCost']);
		self::assertSame(2, $rewards['completion']['totalEnergy'], 'the frozen work evidence equals the task energy');
		// WP3 §3.1: 2 energy solo = 200 energy minor contribution and a
		// 2000 coin minor wallet credit (rate 10 Coins per energy).
		self::assertSame((int)$before['contribution_minor'] + 200, (int)$after['contribution_minor']);
		self::assertNotNull($after['wallet_id']);
		self::assertSame(2000, $this->walletBookedMinor((int)$after['wallet_id']), 'the wallet ledger credits the frozen rate');
		self::assertSame(2000, $rewards['wallet']['bookedMinor'], 'the receipt wallet block matches the persisted ledger');
		self::assertSame(20, $rewards['cleanlinessChange'], 'cleanliness delta is 10% per energy point');
		// C1f rhythm semantics: the parent has FOUR same-day obligations and
		// fulfilled ONE — the day is held PENDING (neither step nor break)
		// until every planned obligation of the day is fulfilled.
		self::assertSame(0, (int)$after['current_streak'], 'a partially fulfilled planned day does not step the streak');
		self::assertSame((int)$before['contribution_minor'] + 200, $rewards['contributionMinor']);
	}

	/**
	 * ParentDailyRoutine_MultipleCompletions_AccumulateExactMinorTotals
	 *
	 * A parent working through the daily routine is credited per task with
	 * exact running totals (WP3: the combo flag retired — the evidence
	 * rows accumulate; each share books its frozen rate).
	 */
	public function testParentDailyRoutine(): void {
		$ids = $this->familyHouseFixture();

		$this->completeOccurrence($this->completionService, $ids['houseId'], 'fam_parent', $ids['dailyRoomTaskId']);
		$this->ledgerRoomTaskIds[] = $ids['dailyRoomTaskId'];
		$this->completeOccurrence($this->completionService, $ids['houseId'], 'fam_parent', $ids['secondRoomTaskId']);
		$this->ledgerRoomTaskIds[] = $ids['secondRoomTaskId'];
		$this->completeOccurrence($this->completionService, $ids['houseId'], 'fam_parent', $ids['thirdRoomTaskId']);
		$this->ledgerRoomTaskIds[] = $ids['thirdRoomTaskId'];

		// (2 + 3 + 2) energy → 700 minor contribution, 7000 booked coins.
		$expectedEnergy = 2 + 3 + 2;
		$after = $this->memberRow($ids['houseId'], 'fam_parent');
		self::assertSame($expectedEnergy * 100, (int)$after['contribution_minor']);
		self::assertSame($expectedEnergy * 1000, $this->walletBookedMinor((int)$after['wallet_id']));
		// Same-day routine completions never inflate the streak: with other
		// same-day obligations still open the day stays PENDING (C1f —
		// neither step nor break until all planned obligations are met).
		self::assertSame(0, (int)$after['current_streak']);
		// Cleanliness accumulates and clamps: 50 + 20 + 30 = 100, the third
		// delta (+20) must not overshoot the cap.
		self::assertSame(100, (int)$this->roomRow($ids['roomId'])['cleanliness']);
	}

	/**
	 * TeenCompletesOwnAssignedTask_ChildRoleEarnsRewards_WithoutOwnerControls
	 *
	 * Weekend/weekday independence: a child with an explicit assignment can
	 * complete it on ANY run day — the reward path is role-and-assignment
	 * based, never weekday-based. The credited deltas land on the child's
	 * own row.
	 */
	public function testTeenWeekendTasks(): void {
		$ids = $this->familyHouseFixture();

		$before = $this->memberRow($ids['houseId'], 'fam_teen');
		$result = $this->completeOccurrence($this->completionService, $ids['houseId'], 'fam_teen', $ids['teenRoomTaskId']);
		$this->ledgerRoomTaskIds[] = $ids['teenRoomTaskId'];

		self::assertSame(3, $result['rewards']['completion']['totalEnergy']);
		$after = $this->memberRow($ids['houseId'], 'fam_teen');
		self::assertSame((int)$before['contribution_minor'] + 300, (int)$after['contribution_minor']);
		self::assertSame(1, (int)$after['current_streak']);
		// The parent's row is untouched by the teen's completion.
		self::assertSame(0, (int)$this->memberRow($ids['houseId'], 'fam_parent')['contribution_minor']);
	}

	/**
	 * DailyStreakCalculation_DerivesFromValidHistory_OverridingTheCache
	 *
	 * WP3 (Q-074-01A): the confirmed streak is ALWAYS re-derived from the
	 * valid occurrence history — a seeded cache value never survives a
	 * completion. With the day PENDING (other same-day obligations open,
	 * C1f) and no earlier history, both the current run AND the record
	 * re-derive to the history truth (a corrected history that never
	 * reached the cached record shrinks it).
	 */
	public function testDailyStreakCalculation(): void {
		$ids = $this->familyHouseFixture();
		$qb = $this->db->getQueryBuilder();
		$qb->update('chorestrike_house_users')
			->set('current_streak', $qb->createNamedParameter(3, IQueryBuilder::PARAM_INT))
			->set('longest_streak', $qb->createNamedParameter(3, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$this->memberRow($ids['houseId'], 'fam_parent')['id'], IQueryBuilder::PARAM_INT)))
			->executeStatement();

		$result = $this->completeOccurrence($this->completionService, $ids['houseId'], 'fam_parent', $ids['dailyRoomTaskId']);
		$this->ledgerRoomTaskIds[] = $ids['dailyRoomTaskId'];

		$after = $this->memberRow($ids['houseId'], 'fam_parent');
		self::assertSame(0, (int)$after['current_streak'], 'the run derives from the valid history, not the seeded cache');
		self::assertSame(0, (int)$after['longest_streak'], 'the record shrinks when the corrected history never reached the cached value');
		self::assertGreaterThan(0, $result['rewards']['completion']['totalEnergy']);
	}

	/**
	 * ConcurrentTaskCompletion_SecondOccurrenceIsRejected_NoDoubleReward
	 *
	 * In-process occurrence-gate proof (CS-PLAN-072 WP1: occurrence
	 * validity replaces the day-duplicate guard): the same SHOWN target
	 * cannot be completed twice — the replay of the same command returns
	 * the original identity, while a NEW command on the fulfilled target
	 * conflicts — and a deactivated one-time occurrence answers 409. The
	 * real two-connection races keep their dedicated, engine-guarded
	 * owners (CompletionConcurrencyTest, ConcurrentCompletionTest) — this
	 * scenario links them instead of duplicating the lock choreography.
	 */
	public function testConcurrentTaskCompletion(): void {
		$ids = $this->familyHouseFixture();

		[$commandId, $occurrenceId, $expectedVersion, $selectionToken] = $this->completionReceiptFor($ids['dailyRoomTaskId']);
		$this->completionService->complete($ids['houseId'], 'fam_parent', $ids['dailyRoomTaskId'], $commandId, $occurrenceId, $expectedVersion, $selectionToken);
		$this->ledgerRoomTaskIds[] = $ids['dailyRoomTaskId'];

		// Replaying the SAME command is the idempotent receipt hit: the
		// original completion identity, no second reward.
		$replay = $this->completionService->complete($ids['houseId'], 'fam_parent', $ids['dailyRoomTaskId'], $commandId, $occurrenceId, $expectedVersion, $selectionToken);
		self::assertTrue($replay['replay']);

		// A NEW command on the fulfilled target conflicts.
		try {
			$this->completionService->complete($ids['houseId'], 'fam_parent', $ids['dailyRoomTaskId'], self::uuid(), $occurrenceId, $expectedVersion, $selectionToken);
			self::fail('a second completion of the same target must be rejected');
		} catch (ConflictException $e) {
			self::assertSame('This occurrence is already completed', $e->getMessage());
		}

		// The one-time occurrence is inactive after its completion → 409.
		[$onceCommand, $onceOccurrence, $onceVersion, $onceToken] = $this->completionReceiptFor($ids['onceRoomTaskId']);
		$this->completionService->complete($ids['houseId'], 'fam_parent', $ids['onceRoomTaskId'], $onceCommand, $onceOccurrence, $onceVersion, $onceToken);
		$this->ledgerRoomTaskIds[] = $ids['onceRoomTaskId'];
		try {
			$this->completionService->complete($ids['houseId'], 'fam_parent', $ids['onceRoomTaskId'], self::uuid(), $onceOccurrence, $onceVersion, $onceToken);
			self::fail('a deactivated occurrence must be rejected');
		} catch (ConflictException $e) {
			self::assertSame('Task is not active', $e->getMessage());
		}

		self::assertCount(2, $this->ledgerRows('revertible'), 'exactly the two fresh completions are revertible');
	}

	private static function uuid(): string {
		return sprintf('%08x-%04x-4%03x-8%03x-%012x', mt_rand(), mt_rand(0, 0xffff), mt_rand(0, 0xfff), mt_rand(0, 0xfff), mt_rand());
	}

	/**
	 * RecurringTaskActivation_CompletionAdvancesTheOccurrence_KeepsRowActive
	 *
	 * A weekly recurring task stays active after completion, records its
	 * completion and advances the occurrence; the exact advance math is
	 * owned by tests/unit/Db/RoomTaskTest.php (linked, not recomputed).
	 */
	public function testRecurringTaskActivation(): void {
		$ids = $this->familyHouseFixture();
		$before = $this->roomTaskRow($ids['weeklyRoomTaskId']);

		$result = $this->completeOccurrence($this->completionService, $ids['houseId'], 'fam_parent', $ids['weeklyRoomTaskId']);
		$this->ledgerRoomTaskIds[] = $ids['weeklyRoomTaskId'];

		$after = $this->roomTaskRow($ids['weeklyRoomTaskId']);
		self::assertSame(1, (int)$after['is_active'], 'a recurring occurrence re-arms after completion');
		self::assertNotNull($after['last_completed']);
		self::assertNull($before['last_completed']);
		self::assertGreaterThan((string)$before['next_due'], (string)$after['next_due'], 'the recurrence must advance');
		self::assertSame($before['room_id'], $after['room_id']);
		self::assertSame($before['task_id'], $after['task_id']);
		self::assertGreaterThan(0, $result['rewards']['completion']['totalEnergy']);
	}

	/**
	 * OneTimeTaskDeactivation_CompletionDeactivates_WithRevertibleLedgerRow
	 *
	 * A once-mode completion deactivates the row (unit-owned row logic)
	 * while the aggregate records exactly one revertible ledger row.
	 */
	public function testOneTimeTaskDeactivation(): void {
		$ids = $this->familyHouseFixture();

		$this->completeOccurrence($this->completionService, $ids['houseId'], 'fam_parent', $ids['onceRoomTaskId']);
		$this->ledgerRoomTaskIds[] = $ids['onceRoomTaskId'];

		$row = $this->roomTaskRow($ids['onceRoomTaskId']);
		self::assertSame(0, (int)$row['is_active'], 'a one-time task deactivates on completion');
		self::assertNotNull($row['last_completed']);

		$ledger = $this->ledgerRows('revertible');
		self::assertCount(1, $ledger);
		self::assertSame($ids['onceRoomTaskId'], (int)$ledger[0]['room_task_id']);
		// WP3 §3.1: the evidence row carries the frozen work facts, not a
		// serialized effect blob.
		self::assertGreaterThan(0, (int)$ledger[0]['total_energy']);
		self::assertSame(1000, (int)$ledger[0]['rate_minor']);
		self::assertArrayNotHasKey('before_state', $ledger[0]);
		self::assertArrayNotHasKey('deltas', $ledger[0]);
	}

	/**
	 * RoomCleanlinessImpact_CompletionRaisesCleanliness_ExactlyAndCapped
	 *
	 * A non-saturated room gains exactly 10% per energy point; a nearly
	 * clean room is capped at 100 instead of overshooting.
	 */
	public function testRoomCleanlinessImpact(): void {
		$ids = $this->familyHouseFixture();

		$this->completeOccurrence($this->completionService, $ids['houseId'], 'fam_parent', $ids['dailyRoomTaskId']);
		$this->ledgerRoomTaskIds[] = $ids['dailyRoomTaskId'];
		self::assertSame(70, (int)$this->roomRow($ids['roomId'])['cleanliness'], '50 + 2 energy × 10% = 70');
		self::assertMatchesRegularExpression(
			'/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
			(string)$this->roomRow($ids['roomId'])['last_cleaned']
		);

		// Exact second step and the saturation cap: +30 reaches 100 and the
		// final +20 delta must stay at the cap.
		$this->completeOccurrence($this->completionService, $ids['houseId'], 'fam_parent', $ids['secondRoomTaskId']);
		$this->ledgerRoomTaskIds[] = $ids['secondRoomTaskId'];
		self::assertSame(100, (int)$this->roomRow($ids['roomId'])['cleanliness'], '70 + 3 energy × 10% = 100');

		$this->completeOccurrence($this->completionService, $ids['houseId'], 'fam_parent', $ids['thirdRoomTaskId']);
		$this->ledgerRoomTaskIds[] = $ids['thirdRoomTaskId'];
		self::assertSame(100, (int)$this->roomRow($ids['roomId'])['cleanliness']);
	}

	// -----------------------------------------------------------------
	// Fixture and helpers
	// -----------------------------------------------------------------

	/**
	 * A family house (parent owner, teen child) with assigned daily/weekly/
	 * once room tasks and one unassigned weekly task, mirroring the shape of
	 * the historical FamilyTestData scenario set on REAL rows.
	 *
	 * @return array{houseId: int, roomId: int, dailyRoomTaskId: int, secondRoomTaskId: int, thirdRoomTaskId: int, teenRoomTaskId: int, weeklyRoomTaskId: int, onceRoomTaskId: int}
	 */
	private function familyHouseFixture(): array {
		$houseId = $this->createHouse('fam_parent', 'Family Home');
		$this->addHouseMember($houseId, 'fam_teen', 'Teen', 'child');
		$floorId = $this->createFloor($houseId, 'Ground Floor', 1);
		$roomId = $this->createRoom($houseId, $floorId, 'Kitchen');
		$this->setRoomCleanliness($roomId, 50);

		$dailyTask = $this->createTask($houseId, 'Do the dishes', '', 5, 2);
		$qb = $this->db->getQueryBuilder();
		$qb->update('chorestrike_tasks')
			->set('frequency_type', $qb->createNamedParameter('daily'))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($dailyTask, IQueryBuilder::PARAM_INT)))
			->executeStatement();
		$secondTask = $this->createTask($houseId, 'Wipe kitchen counters', '', 5, 3);
		$thirdTask = $this->createTask($houseId, 'Take out trash', '', 5, 2);
		$teenTask = $this->createTask($houseId, 'Tidy up toys', '', 5, 3);
		$weeklyTask = $this->createTask($houseId, 'Do laundry', '', 5, 4);
		$onceTask = $this->createTask($houseId, 'Assemble shelf', '', 5, 2);

		return [
			'houseId' => $houseId,
			'roomId' => $roomId,
			'dailyRoomTaskId' => $this->assignTaskToRoom($dailyTask, $roomId, 'fam_parent'),
			'secondRoomTaskId' => $this->assignTaskToRoom($secondTask, $roomId, 'fam_parent'),
			'thirdRoomTaskId' => $this->assignTaskToRoom($thirdTask, $roomId, 'fam_parent'),
			'teenRoomTaskId' => $this->assignTaskToRoom($teenTask, $roomId, 'fam_teen'),
			'weeklyRoomTaskId' => $this->assignTaskToRoom($weeklyTask, $roomId, null),
			'onceRoomTaskId' => $this->assignOnceTaskToRoom($onceTask, $roomId, 'fam_parent'),
		];
	}

	private function assignOnceTaskToRoom(int $taskId, int $roomId, string $assignee): int {
		// CS-PLAN-072 WP1: the schedule freezes the task's generation
		// inputs at assignment time — a 'once' lifetime must be set BEFORE
		// the initial schedule materializes.
		$qb = $this->db->getQueryBuilder();
		$qb->update('chorestrike_tasks')
			->set('recurrence_mode', $qb->createNamedParameter('once'))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($taskId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
		return $this->assignTaskToRoom($taskId, $roomId, $assignee);
	}

	private function setRoomCleanliness(int $roomId, int $cleanliness): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('chorestrike_rooms')
			->set('cleanliness', $qb->createNamedParameter($cleanliness, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($roomId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	/**
	 * @return array<string, mixed>
	 */
	private function memberRow(int $houseId, string $userId): array {
		$row = \OC::$server->get(HouseUserMapper::class)->findByHouseAndUser($houseId, $userId);
		self::assertInstanceOf(HouseUser::class, $row);
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('chorestrike_house_users')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($row->getId(), IQueryBuilder::PARAM_INT)));
		$data = $qb->executeQuery()->fetch();
		self::assertIsArray($data);
		return $data;
	}

	/**
	 * The wallet's booked minor total (WP3 §3.1 exact ledger read).
	 */
	private function walletBookedMinor(int $walletId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select('booked_minor')
			->from('chorestrike_wallets')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($walletId, IQueryBuilder::PARAM_INT)));
		return (int)$qb->executeQuery()->fetchOne();
	}

	/**
	 * @return array<string, mixed>
	 */
	private function roomRow(int $roomId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('chorestrike_rooms')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($roomId, IQueryBuilder::PARAM_INT)));
		$row = $qb->executeQuery()->fetch();
		self::assertIsArray($row);
		return $row;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function roomTaskRow(int $roomTaskId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('chorestrike_room_tasks')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)));
		$row = $qb->executeQuery()->fetch();
		self::assertIsArray($row);
		return $row;
	}

	/**
	 * Ledger rows for this suite's room tasks.
	 *
	 * @param 'revertible' $filter
	 * @return array<int, array<string, mixed>>
	 */
	private function ledgerRows(string $filter): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('chorestrike_completed_tasks')
			->where($qb->expr()->in('room_task_id', $qb->createNamedParameter($this->ledgerRoomTaskIds, IQueryBuilder::PARAM_INT_ARRAY)));
		if ($filter === 'revertible') {
			$qb->andWhere($qb->expr()->isNull('undone_at'));
		}
		$rows = $qb->executeQuery()->fetchAll();
		return is_array($rows) ? $rows : [];
	}
}
