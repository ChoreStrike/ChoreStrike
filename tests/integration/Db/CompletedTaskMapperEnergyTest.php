<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Db;

use OCA\ChoreStrike\Db\CompletedTaskMapper;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * Real-DB regression test for CompletedTaskMapper::findEnergyUsageBetweenByHouse
 * (CS-PLAN-065 §3.3, decision D11).
 *
 * The batched query is the preview's authoritative completed-effort input:
 * non-undone completions only, house-scoped, carrying the completer and the
 * task's energy_cost per row. The former per-user day query
 * (getConsumedEnergyForUserOnDate) was removed with the reader-less client
 * energy counter (decision D7); its boundary semantics survive here in the
 * window shape. A mock-based test cannot catch SQL shape errors, so this
 * spec runs against the real Docker DB.
 */
class CompletedTaskMapperEnergyTest extends IntegrationTestCase {
	private CompletedTaskMapper $mapper;
	private HouseUserMapper $houseUserMapper;

	/** @var array<int> Completed-task rows created by this test (not covered by the base cleanup). */
	private array $createdCompletedTaskIds = [];

	protected function setUp(): void {
		parent::setUp();
		$this->mapper = \OC::$server->get(CompletedTaskMapper::class);
		$this->houseUserMapper = \OC::$server->get(HouseUserMapper::class);
	}

	protected function tearDown(): void {
		if (!empty($this->createdCompletedTaskIds)) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('chorestrike_completed_tasks')
				->where($qb->expr()->in('id', $qb->createNamedParameter($this->createdCompletedTaskIds, IQueryBuilder::PARAM_INT_ARRAY)));
			$qb->executeStatement();
			$this->createdCompletedTaskIds = [];
		}

		parent::tearDown();
	}

	private function houseUserId(int $houseId, string $ncUserId): int {
		return $this->houseUserMapper->findByNextcloudUserId($houseId, $ncUserId)->getId();
	}

	/**
	 * Insert a completed_tasks row with an explicit completed_at timestamp.
	 */
	private function insertCompletion(int $roomTaskId, int $houseUserId, string $completedAt, bool $undone = false): int {
		$qb = $this->db->getQueryBuilder();
		$qb->insert('chorestrike_completed_tasks')
			->values([
				'room_task_id' => $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT),
				'user_id' => $qb->createNamedParameter($houseUserId, IQueryBuilder::PARAM_INT),
				'completed_at' => $qb->createNamedParameter($completedAt, IQueryBuilder::PARAM_STR),
				'coins_earned' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
				'was_combo' => $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL),
				// WP5 effect-record columns are NOT NULL (CS-PLAN-064); the
				// energy fixtures carry no undo semantics → empty JSON.
				'before_state' => $qb->createNamedParameter('{}', IQueryBuilder::PARAM_STR),
				'deltas' => $qb->createNamedParameter('{}', IQueryBuilder::PARAM_STR),
				'undone_at' => $undone
					? $qb->createNamedParameter('2026-06-16 08:00:00', IQueryBuilder::PARAM_STR)
					: $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
			]);
		$qb->executeStatement();

		$id = (int)$this->db->lastInsertId('chorestrike_completed_tasks');
		$this->createdCompletedTaskIds[] = $id;
		return $id;
	}

	/**
	 * @return array<int, array{houseUserId: int, energy: int}> Rows reduced to the asserted fields.
	 */
	private function usageFor(int $houseId): array {
		$rows = $this->mapper->findEnergyUsageBetweenByHouse(
			$houseId,
			new \DateTimeImmutable('2026-06-15 00:00:00'),
			new \DateTimeImmutable('2026-06-16 00:00:00')
		);
		return array_map(static fn (array $row): array => [
			'houseUserId' => $row['houseUserId'],
			'energy' => $row['energy'],
		], $rows);
	}

	/**
	 * FindEnergyUsage_NoCompletions_ReturnsEmpty
	 */
	public function testFindEnergyUsage_NoCompletions_ReturnsEmpty(): void {
		$houseId = $this->createHouse('energy_owner', 'Energy House');
		$floorId = $this->createFloor($houseId, 'Ground Floor', 1);
		$roomId = $this->createRoom($houseId, $floorId, 'Kitchen');
		$this->assignTaskToRoom($this->createTask($houseId, 'Dishes', '', 5, 3), $roomId);

		self::assertSame([], $this->usageFor($houseId));
	}

	/**
	 * FindEnergyUsage_MultipleCompletions_SumsPerRowEnergyCost
	 */
	public function testFindEnergyUsage_MultipleCompletions_SumsPerRowEnergyCost(): void {
		$houseId = $this->createHouse('energy_sum_owner', 'Energy Sum House');
		$floorId = $this->createFloor($houseId, 'Ground Floor', 1);
		$roomId = $this->createRoom($houseId, $floorId, 'Kitchen');

		$rtDishes = $this->assignTaskToRoom($this->createTask($houseId, 'Dishes', '', 5, 3), $roomId);
		$rtLaundry = $this->assignTaskToRoom($this->createTask($houseId, 'Laundry', '', 5, 5), $roomId);

		$houseUserId = $this->houseUserId($houseId, 'energy_sum_owner');
		$this->insertCompletion($rtDishes, $houseUserId, '2026-06-15 08:30:00');
		$this->insertCompletion($rtLaundry, $houseUserId, '2026-06-15 19:45:00');

		self::assertSame(
			[3, 5],
			array_map(static fn (array $row): int => $row['energy'], $this->usageFor($houseId))
		);
	}

	/**
	 * FindEnergyUsage_UndoneCompletions_Excluded (decision D11): an undone
	 * row is no longer planned work and must not feed the preview capacity.
	 */
	public function testFindEnergyUsage_UndoneCompletions_Excluded(): void {
		$houseId = $this->createHouse('energy_undo_owner', 'Energy Undo House');
		$floorId = $this->createFloor($houseId, 'Ground Floor', 1);
		$roomId = $this->createRoom($houseId, $floorId, 'Kitchen');

		$rtKept = $this->assignTaskToRoom($this->createTask($houseId, 'Dishes', '', 5, 3), $roomId);
		$rtUndone = $this->assignTaskToRoom($this->createTask($houseId, 'Laundry', '', 5, 9), $roomId);

		$houseUserId = $this->houseUserId($houseId, 'energy_undo_owner');
		$this->insertCompletion($rtKept, $houseUserId, '2026-06-15 09:00:00');
		$this->insertCompletion($rtUndone, $houseUserId, '2026-06-15 10:00:00', true);

		$rows = $this->usageFor($houseId);

		self::assertCount(1, $rows);
		self::assertSame(3, $rows[0]['energy']);
	}

	/**
	 * FindEnergyUsage_DayBoundary_UsesHalfOpenInterval
	 */
	public function testFindEnergyUsage_DayBoundary_UsesHalfOpenInterval(): void {
		$houseId = $this->createHouse('energy_edge_owner', 'Energy Edge House');
		$floorId = $this->createFloor($houseId, 'Ground Floor', 1);
		$roomId = $this->createRoom($houseId, $floorId, 'Kitchen');

		$rtStartEdge = $this->assignTaskToRoom($this->createTask($houseId, 'Windows', '', 5, 2), $roomId);
		$rtLateEvening = $this->assignTaskToRoom($this->createTask($houseId, 'Trash', '', 5, 4), $roomId);
		$rtPreviousDay = $this->assignTaskToRoom($this->createTask($houseId, 'Mop', '', 5, 6), $roomId);
		$rtNextDay = $this->assignTaskToRoom($this->createTask($houseId, 'Vacuum', '', 5, 7), $roomId);

		$houseUserId = $this->houseUserId($houseId, 'energy_edge_owner');
		$this->insertCompletion($rtPreviousDay, $houseUserId, '2026-06-14 23:59:59'); // before window
		$this->insertCompletion($rtStartEdge, $houseUserId, '2026-06-15 00:00:00');   // inclusive start
		$this->insertCompletion($rtLateEvening, $houseUserId, '2026-06-15 23:59:59'); // last in-window second
		$this->insertCompletion($rtNextDay, $houseUserId, '2026-06-16 00:00:00');     // exclusive end

		self::assertSame(
			[2, 4],
			array_map(static fn (array $row): int => $row['energy'], $this->usageFor($houseId))
		);
	}

	/**
	 * FindEnergyUsage_CrossHouseAndUser_Isolation
	 */
	public function testFindEnergyUsage_CrossHouseAndUser_Isolation(): void {
		$houseId = $this->createHouse('energy_iso_a', 'Energy Isolation House');
		$this->addHouseMember($houseId, 'energy_iso_b', 'Member B');
		$floorId = $this->createFloor($houseId, 'Ground Floor', 1);
		$roomId = $this->createRoom($houseId, $floorId, 'Kitchen');

		$rtMine = $this->assignTaskToRoom($this->createTask($houseId, 'Dishes', '', 5, 3), $roomId);
		$rtTheirs = $this->assignTaskToRoom($this->createTask($houseId, 'Laundry', '', 5, 9), $roomId);

		$userA = $this->houseUserId($houseId, 'energy_iso_a');
		$userB = $this->houseUserId($houseId, 'energy_iso_b');
		$this->insertCompletion($rtMine, $userA, '2026-06-15 09:00:00');
		$this->insertCompletion($rtTheirs, $userB, '2026-06-15 10:00:00');

		$rows = $this->usageFor($houseId);

		// Both house members' rows are returned in one query, tagged with
		// their completer; another house's rows never appear.
		self::assertCount(2, $rows);
		self::assertSame(
			[$userA, $userB],
			array_map(static fn (array $row): int => $row['houseUserId'], $rows)
		);
	}
}
