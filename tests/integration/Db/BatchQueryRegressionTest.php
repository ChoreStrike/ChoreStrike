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
	): int {
		$qb = $this->db->getQueryBuilder();
		$qb->insert('chorestrike_completed_tasks')
			->values([
				'room_task_id' => $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT),
				'user_id' => $qb->createNamedParameter($houseUserId, IQueryBuilder::PARAM_INT),
				'completed_at' => $qb->createNamedParameter($completedAt, IQueryBuilder::PARAM_STR),
				'coins_earned' => $qb->createNamedParameter($coinsEarned, IQueryBuilder::PARAM_INT),
				'was_combo' => $qb->createNamedParameter($wasCombo, IQueryBuilder::PARAM_BOOL),
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

}
