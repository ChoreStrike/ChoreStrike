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
 * Real-DB regression test for CompletedTaskMapper::getConsumedEnergyForUserOnDate
 * (CR-016-10).
 *
 * The method originally shipped with a mangled select('COALESCE(SUM(...)) AS …')
 * that failed at runtime with SQLSTATE[1054] — the raw aggregate string was
 * quoted as a column name instead of going through createFunction()/selectAlias().
 * The only coverage was mock-based (SchedulingServiceTest stubs the mapper), which
 * by construction cannot catch SQL shape errors — the exact defect class of the
 * original bug. This spec runs against the real Docker DB and pins the
 * [00:00, +1d) day-boundary semantics plus per-user isolation.
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
	private function insertCompletion(int $roomTaskId, int $houseUserId, string $completedAt): int {
		$qb = $this->db->getQueryBuilder();
		$qb->insert('chorestrike_completed_tasks')
			->values([
				'room_task_id' => $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT),
				'user_id' => $qb->createNamedParameter($houseUserId, IQueryBuilder::PARAM_INT),
				'completed_at' => $qb->createNamedParameter($completedAt, IQueryBuilder::PARAM_STR),
				'coins_earned' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
				'was_combo' => $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL),
			]);
		$qb->executeStatement();

		$id = (int)$this->db->lastInsertId('chorestrike_completed_tasks');
		$this->createdCompletedTaskIds[] = $id;
		return $id;
	}

	/**
	 * GetConsumedEnergy_NoCompletions_ReturnsZero
	 *
	 * A user without any completions on the requested date yields 0 (the
	 * COALESCE guard), not null/false.
	 */
	public function testGetConsumedEnergy_NoCompletions_ReturnsZero(): void {
		$houseId = $this->createHouse('energy_owner', 'Energy House');
		$floorId = $this->createFloor($houseId, 'Ground Floor', 1);
		$roomId = $this->createRoom($houseId, $floorId, 'Kitchen');
		$this->assignTaskToRoom($this->createTask($houseId, 'Dishes', '', 5, 3), $roomId);
		$houseUserId = $this->houseUserId($houseId, 'energy_owner');

		self::assertSame(
			0,
			$this->mapper->getConsumedEnergyForUserOnDate($houseUserId, new \DateTimeImmutable('2026-06-15 00:00:00'), new \DateTimeImmutable('2026-06-16 00:00:00'))
		);
	}

	/**
	 * GetConsumedEnergy_MultipleCompletions_SumsEnergyCost
	 *
	 * Completions of different room tasks on one date are summed by their
	 * task's energy_cost.
	 */
	public function testGetConsumedEnergy_MultipleCompletions_SumsEnergyCost(): void {
		$houseId = $this->createHouse('energy_sum_owner', 'Energy Sum House');
		$floorId = $this->createFloor($houseId, 'Ground Floor', 1);
		$roomId = $this->createRoom($houseId, $floorId, 'Kitchen');

		$rtDishes = $this->assignTaskToRoom($this->createTask($houseId, 'Dishes', '', 5, 3), $roomId);
		$rtLaundry = $this->assignTaskToRoom($this->createTask($houseId, 'Laundry', '', 5, 5), $roomId);

		$houseUserId = $this->houseUserId($houseId, 'energy_sum_owner');
		$this->insertCompletion($rtDishes, $houseUserId, '2026-06-15 08:30:00');
		$this->insertCompletion($rtLaundry, $houseUserId, '2026-06-15 19:45:00');

		self::assertSame(
			8,
			$this->mapper->getConsumedEnergyForUserOnDate($houseUserId, new \DateTimeImmutable('2026-06-15 00:00:00'), new \DateTimeImmutable('2026-06-16 00:00:00'))
		);
	}

	/**
	 * GetConsumedEnergy_DayBoundary_UsesHalfOpenInterval
	 *
	 * The day is [00:00:00, next-day 00:00:00): midnight-start is counted,
	 * the second before midnight is counted, both neighboring midnights are
	 * excluded.
	 */
	public function testGetConsumedEnergy_DayBoundary_UsesHalfOpenInterval(): void {
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
			6,
			$this->mapper->getConsumedEnergyForUserOnDate($houseUserId, new \DateTimeImmutable('2026-06-15 00:00:00'), new \DateTimeImmutable('2026-06-16 00:00:00'))
		);
	}

	/**
	 * GetConsumedEnergy_OtherUsersCompletions_Ignored
	 *
	 * Another member's completions on the same date do not leak into the
	 * queried user's consumed energy (cross-user isolation).
	 */
	public function testGetConsumedEnergy_OtherUsersCompletions_Ignored(): void {
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

		self::assertSame(3, $this->mapper->getConsumedEnergyForUserOnDate($userA, new \DateTimeImmutable('2026-06-15 00:00:00'), new \DateTimeImmutable('2026-06-16 00:00:00')));
		self::assertSame(9, $this->mapper->getConsumedEnergyForUserOnDate($userB, new \DateTimeImmutable('2026-06-15 00:00:00'), new \DateTimeImmutable('2026-06-16 00:00:00')));
	}
}
