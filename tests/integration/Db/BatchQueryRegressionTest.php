<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Db;

use OCA\ChoreStrike\Db\CompletedTaskMapper;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\RoomMapper;
use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * Real-DB regression suite for the CS-PLAN-036 batch query methods.
 *
 * Mock-based mapper tests pin the query shape, but only a real database
 * proves the semantics the N+1 elimination relies on: skip-on-missing
 * IN-clause lookups, house-scoped isolation (especially the same NC user
 * in two houses), bounded ordering for the pending-sync feed, and
 * row-for-row equivalence of the grouped medal stats with the per-user
 * aggregates they replace.
 */
class BatchQueryRegressionTest extends IntegrationTestCase {
	private TaskMapper $taskMapper;
	private RoomMapper $roomMapper;
	private HouseUserMapper $houseUserMapper;
	private CompletedTaskMapper $completedTaskMapper;

	/** @var array<int> Completed-task rows created by this test (not covered by the base cleanup). */
	private array $createdCompletedTaskIds = [];

	protected function setUp(): void {
		parent::setUp();
		$this->taskMapper = \OC::$server->get(TaskMapper::class);
		$this->roomMapper = \OC::$server->get(RoomMapper::class);
		$this->houseUserMapper = \OC::$server->get(HouseUserMapper::class);
		$this->completedTaskMapper = \OC::$server->get(CompletedTaskMapper::class);
	}

	protected function tearDown(): void {
		if (!empty($this->createdCompletedTaskIds)) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('chorestrike_completed_tasks')
				->where($qb->expr()->in('id', $qb->createNamedParameter($this->createdCompletedTaskIds, IQueryBuilder::PARAM_INT_ARRAY)));
			$qb->executeStatement();
		}

		parent::tearDown();
	}

	/**
	 * Insert a completed_tasks row with explicit values.
	 */
	private function insertCompletion(
		int $roomTaskId,
		int $houseUserId,
		string $completedAt,
		int $coinsEarned = 10,
		bool $wasCombo = false,
		?int $nextcloudTaskId = null,
		bool $syncedToNextcloud = false,
	): int {
		$qb = $this->db->getQueryBuilder();
		$qb->insert('chorestrike_completed_tasks')
			->values([
				'room_task_id' => $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT),
				'user_id' => $qb->createNamedParameter($houseUserId, IQueryBuilder::PARAM_INT),
				'completed_at' => $qb->createNamedParameter($completedAt, IQueryBuilder::PARAM_STR),
				'coins_earned' => $qb->createNamedParameter($coinsEarned, IQueryBuilder::PARAM_INT),
				'was_combo' => $qb->createNamedParameter($wasCombo, IQueryBuilder::PARAM_BOOL),
				'nextcloud_task_id' => $qb->createNamedParameter($nextcloudTaskId, IQueryBuilder::PARAM_INT),
				'synced_to_nextcloud' => $qb->createNamedParameter($syncedToNextcloud, IQueryBuilder::PARAM_BOOL),
			]);
		$qb->executeStatement();

		$id = (int)$this->db->lastInsertId('chorestrike_completed_tasks');
		$this->createdCompletedTaskIds[] = $id;
		return $id;
	}

	/**
	 * findByIds tolerates missing ids: the returned map simply lacks the
	 * key (skip-on-missing proof for the N+1 consumers).
	 */
	public function testFindByIds_MissingIdIsAbsentFromMap(): void {
		$houseId = $this->createHouse('batch_owner', 'Batch House');
		$floorId = $this->createFloor($houseId);
		$roomId = $this->createRoom($houseId, $floorId, 'Kitchen');

		$taskIdA = $this->createTask($houseId, 'Dishes');
		$taskIdB = $this->createTask($houseId, 'Laundry');

		$tasksById = $this->taskMapper->findByIds([$taskIdA, $taskIdB, 999999999]);
		self::assertSame([$taskIdA, $taskIdB], array_keys($tasksById));
		self::assertSame('Dishes', $tasksById[$taskIdA]->getName());

		$roomsById = $this->roomMapper->findByIds([$roomId, 999999999]);
		self::assertSame([$roomId], array_keys($roomsById));
		self::assertSame('Kitchen', $roomsById[$roomId]->getName());
	}

	/**
	 * findByHouseIdAndNextcloudUserIds resolves a multi-member house,
	 * omits absent users, and isolates houses: the same NC user id is a
	 * member of two houses and the map must return only this house's row.
	 */
	public function testFindByHouseIdAndNextcloudUserIds_ScopesToHouseAndOmitsAbsent(): void {
		$houseA = $this->createHouse('batch_alice', 'House A');
		$this->addHouseMember($houseA, 'batch_bob', 'Bob A');

		$houseB = $this->createHouse('batch_alice', 'House B');
		$this->addHouseMember($houseB, 'batch_carol', 'Carol B');

		$map = $this->houseUserMapper->findByHouseIdAndNextcloudUserIds(
			$houseA,
			['batch_alice', 'batch_bob', 'batch_nobody']
		);

		self::assertSame(['batch_alice', 'batch_bob'], array_keys($map));
		// Both houses have a row for batch_alice — house A's row must win
		// (the query is house-scoped, not global per user id).
		self::assertSame($houseA, $map['batch_alice']->getHouseId());
		self::assertSame('Bob A', $map['batch_bob']->getDisplayName());
		self::assertArrayNotHasKey('batch_nobody', $map);

		self::assertSame([], $this->houseUserMapper->findByHouseIdAndNextcloudUserIds($houseA, []));
	}

	/**
	 * findPendingSyncsForHouse scopes to the house (other houses' older
	 * rows never leak in), orders completed_at ASC, and honors the limit.
	 */
	public function testFindPendingSyncsForHouse_ScopesOrdersAndLimits(): void {
		$houseA = $this->createHouse('sync_owner_a', 'Sync House A');
		$floorA = $this->createFloor($houseA);
		$roomA = $this->createRoom($houseA, $floorA, 'Kitchen A');
		$roomTaskA1 = $this->assignTaskToRoom($this->createTask($houseA, 'Dishes A'), $roomA);
		$roomTaskA2 = $this->assignTaskToRoom($this->createTask($houseA, 'Laundry A'), $roomA);
		$roomTaskA3 = $this->assignTaskToRoom($this->createTask($houseA, 'Trash A'), $roomA);

		$houseB = $this->createHouse('sync_owner_b', 'Sync House B');
		$floorB = $this->createFloor($houseB);
		$roomB = $this->createRoom($houseB, $floorB, 'Kitchen B');
		$roomTaskB1 = $this->assignTaskToRoom($this->createTask($houseB, 'Dishes B'), $roomB);

		// completed_tasks.user_id is NOT NULL — seed real house-user ids.
		$houseUserA = $this->houseUserMapper
			->findByHouseIdAndNextcloudUserId($houseA, 'sync_owner_a')->getId();
		$houseUserB = $this->houseUserMapper
			->findByHouseIdAndNextcloudUserId($houseB, 'sync_owner_b')->getId();

		// House B's pending row is OLDEST — must never surface for house A.
		$this->insertCompletion($roomTaskB1, $houseUserB, '2026-08-15 08:00:00', 10, false, 555001);
		$oldestA = $this->insertCompletion($roomTaskA1, $houseUserA, '2026-08-15 10:00:00', 10, false, 555002);
		$middleA = $this->insertCompletion($roomTaskA2, $houseUserA, '2026-08-15 10:05:00', 10, false, 555003);
		$newestA = $this->insertCompletion($roomTaskA3, $houseUserA, '2026-08-15 10:10:00', 10, false, 555004);
		// Same house, but already synced / unlinked → excluded.
		$this->insertCompletion($roomTaskA1, $houseUserA, '2026-08-15 09:00:00', 10, false, null);
		$this->insertCompletion($roomTaskA1, $houseUserA, '2026-08-15 09:30:00', 10, false, 555005, true);

		$all = $this->completedTaskMapper->findPendingSyncsForHouse($houseA, 10);
		self::assertSame(
			[$oldestA, $middleA, $newestA],
			array_map(static fn ($ct) => $ct->getId(), $all),
			'Only house A pending rows, completed_at ASC'
		);

		$limited = $this->completedTaskMapper->findPendingSyncsForHouse($houseA, 2);
		self::assertSame(
			[$oldestA, $middleA],
			array_map(static fn ($ct) => $ct->getId(), $limited),
			'The limit must cut house A rows, not admit house B rows'
		);
	}

	/**
	 * The grouped medal stats (GROUP BY over the house's users) must equal
	 * the per-user methods row for row — the regression guard that the
	 * batch conversion did not change medal semantics.
	 */
	public function testGroupedStats_EqualPerUserAggregates(): void {
		$houseId = $this->createHouse('stats_owner', 'Stats House');
		$floorId = $this->createFloor($houseId);
		$roomKitchen = $this->createRoom($houseId, $floorId, 'Kitchen');
		$roomBath = $this->createRoom($houseId, $floorId, 'Bathroom');
		$this->addHouseMember($houseId, 'stats_user1', 'User One');
		$this->addHouseMember($houseId, 'stats_user2', 'User Two');
		$this->addHouseMember($houseId, 'stats_user3', 'User Three');

		// Resolve the created house-user ids for seeding completions.
		$members = $this->houseUserMapper->findByHouseId($houseId);
		$idsByNcId = [];
		foreach ($members as $member) {
			$idsByNcId[$member->getNextcloudUserId()] = $member->getId();
		}
		$user1 = $idsByNcId['stats_user1'];
		$user2 = $idsByNcId['stats_user2'];
		$user3 = $idsByNcId['stats_user3'];

		$rtKitchen = $this->assignTaskToRoom($this->createTask($houseId, 'Dishes'), $roomKitchen);
		$rtBath = $this->assignTaskToRoom($this->createTask($houseId, 'Scrub'), $roomBath);

		// user1: 3 completions across 2 days, 2 rooms (kitchen twice), 1 combo.
		$this->insertCompletion($rtKitchen, $user1, '2026-08-11 09:00:00', 20, false);
		$this->insertCompletion($rtKitchen, $user1, '2026-08-11 09:03:00', 40, true);
		$this->insertCompletion($rtBath, $user1, '2026-08-12 18:00:00', 15, false);
		// user2: 1 completion.
		$this->insertCompletion($rtBath, $user2, '2026-08-11 12:00:00', 15, false);
		// user3: none in range (this completion is OUTSIDE the window).
		$this->insertCompletion($rtKitchen, $user3, '2026-08-01 09:00:00', 10, false);

		$start = new \DateTime('2026-08-10 00:00:00');
		$end = new \DateTime('2026-08-17 00:00:00');

		$statsByUser = $this->completedTaskMapper->getUserStatsByHouse($houseId, $start, $end);
		$maxByUser = $this->completedTaskMapper->getMaxRoomCompletionCountByHouse($houseId, $start, $end);

		foreach ([$user1, $user2, $user3] as $houseUserId) {
			$expectedStats = $this->completedTaskMapper->getUserStats($houseUserId, $start, $end);
			$expectedMax = $this->completedTaskMapper->getMaxRoomCompletionCountByUser($houseUserId, $start, $end);

			if ($expectedStats['taskCount'] === 0) {
				// Empty-range users are absent from the grouped maps.
				self::assertArrayNotHasKey($houseUserId, $statsByUser);
				self::assertArrayNotHasKey($houseUserId, $maxByUser);
				self::assertSame(0, $expectedMax);
			} else {
				self::assertSame($expectedStats, $statsByUser[$houseUserId]);
				self::assertSame($expectedMax, $maxByUser[$houseUserId]);
			}
		}

		// Sanity: the seeded data itself (user1 kitchen concentration = 2).
		self::assertSame(3, $statsByUser[$user1]['taskCount']);
		self::assertSame(2, $statsByUser[$user1]['activeDays']);
		self::assertSame(1, $statsByUser[$user1]['comboCount']);
		self::assertSame(2, $maxByUser[$user1]);
		self::assertSame(1, $statsByUser[$user2]['taskCount']);
	}
}
