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
 * (CS-PLAN-065 §3.3, decision D11; CS-PLAN-073 WP2 §3.2 frozen energy).
 *
 * The batched query is the preview's authoritative completed-effort input:
 * non-undone completions only, house-scoped, carrying the completer and the
 * FROZEN energy of the SAVED occurrence joined by the frozen
 * (schedule_rev_id, occurrence_ordinal) key. RF-073-WP2-15: the join is
 * INNER — a completion row without its occurrence link never silently
 * falls back to the mutable `tasks.energy_cost` definition; it is excluded
 * as the invariant violation it is. The former per-user day query
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

	/** @var array<int> Occurrence rows created by this test's fixtures. */
	private array $createdOccurrenceIds = [];

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
		if (!empty($this->createdOccurrenceIds)) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('chorestrike_occurrences')
				->where($qb->expr()->in('id', $qb->createNamedParameter($this->createdOccurrenceIds, IQueryBuilder::PARAM_INT_ARRAY)));
			$qb->executeStatement();
			$this->createdOccurrenceIds = [];
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
	 * Insert a SAVED occurrence with an explicit frozen energy and link a
	 * completion row to it through the frozen (rev, ordinal) key — the
	 * production completion shape (CompletionService records the timeline
	 * evidence on every completion).
	 */
	private function linkCompletionToFrozenOccurrence(int $houseId, int $roomTaskId, int $completionId, int $frozenEnergy, string $plannedDate = '2026-06-15'): void {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from('chorestrike_schedule_revs')
			->where($qb->expr()->eq('room_task_id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('closed_at'))
			->orderBy('id', 'DESC')
			->setMaxResults(1);
		$revId = (int)$qb->executeQuery()->fetchOne();
		self::assertGreaterThan(0, $revId, 'the room task must own a live schedule revision');

		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->max('ordinal'))
			->from('chorestrike_occurrences')
			->where($qb->expr()->eq('schedule_rev_id', $qb->createNamedParameter($revId, IQueryBuilder::PARAM_INT)));
		$ordinal = ((int)$qb->executeQuery()->fetchOne()) + 1;

		$qb = $this->db->getQueryBuilder();
		$qb->insert('chorestrike_occurrences')
			->values([
				'house_id' => $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT),
				'room_task_id' => $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT),
				'schedule_rev_id' => $qb->createNamedParameter($revId, IQueryBuilder::PARAM_INT),
				'ordinal' => $qb->createNamedParameter($ordinal, IQueryBuilder::PARAM_INT),
				'kind' => $qb->createNamedParameter('scheduled', IQueryBuilder::PARAM_STR),
				'planned_date' => $qb->createNamedParameter($plannedDate, IQueryBuilder::PARAM_STR),
				'week_start' => $qb->createNamedParameter($plannedDate, IQueryBuilder::PARAM_STR),
				'timezone' => $qb->createNamedParameter('UTC', IQueryBuilder::PARAM_STR),
				'day_start' => $qb->createNamedParameter($plannedDate . ' 00:00:00', IQueryBuilder::PARAM_STR),
				'window_start' => $qb->createNamedParameter($plannedDate . ' 00:00:00', IQueryBuilder::PARAM_STR),
				'window_end' => $qb->createNamedParameter($plannedDate . ' 23:59:59', IQueryBuilder::PARAM_STR),
				'energy' => $qb->createNamedParameter($frozenEnergy, IQueryBuilder::PARAM_INT),
				'is_public' => $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL),
				'version' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
				'created_at' => $qb->createNamedParameter('2026-06-15 00:00:00', IQueryBuilder::PARAM_STR),
			]);
		$qb->executeStatement();
		$this->createdOccurrenceIds[] = (int)$this->db->lastInsertId('chorestrike_occurrences');

		$qb = $this->db->getQueryBuilder();
		$qb->update('chorestrike_completed_tasks')
			->set('schedule_rev_id', $qb->createNamedParameter($revId, IQueryBuilder::PARAM_INT))
			->set('occurrence_ordinal', $qb->createNamedParameter($ordinal, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($completionId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	/**
	 * Insert a completion WITH its frozen occurrence link (the production
	 * shape) and return the completion row id.
	 */
	private function insertLinkedCompletion(int $houseId, int $roomTaskId, int $houseUserId, string $completedAt, int $frozenEnergy, bool $undone = false): int {
		$completionId = $this->insertCompletion($roomTaskId, $houseUserId, $completedAt, $undone);
		$this->linkCompletionToFrozenOccurrence($houseId, $roomTaskId, $completionId, $frozenEnergy);
		return $completionId;
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
		$this->insertLinkedCompletion($houseId, $rtDishes, $houseUserId, '2026-06-15 08:30:00', 3);
		$this->insertLinkedCompletion($houseId, $rtLaundry, $houseUserId, '2026-06-15 19:45:00', 5);

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
		$this->insertLinkedCompletion($houseId, $rtKept, $houseUserId, '2026-06-15 09:00:00', 3);
		$this->insertLinkedCompletion($houseId, $rtUndone, $houseUserId, '2026-06-15 10:00:00', 9, true);

		$rows = $this->usageFor($houseId);

		self::assertCount(1, $rows);
		self::assertSame(3, $rows[0]['energy']);
	}

	/**
	 * FindEnergyUsage_FrozenOccurrenceEnergy_SurvivesDefinitionEdit
	 * (RF-073-WP2-15): the energy comes from the SAVED occurrence — a
	 * later definition edit never re-prices recorded work.
	 */
	public function testFindEnergyUsage_FrozenOccurrenceEnergy_SurvivesDefinitionEdit(): void {
		$houseId = $this->createHouse('energy_frozen_owner', 'Energy Frozen House');
		$floorId = $this->createFloor($houseId, 'Ground Floor', 1);
		$roomId = $this->createRoom($houseId, $floorId, 'Kitchen');
		$roomTaskId = $this->assignTaskToRoom($this->createTask($houseId, 'Dishes', '', 5, 3), $roomId);

		$houseUserId = $this->houseUserId($houseId, 'energy_frozen_owner');
		$this->insertLinkedCompletion($houseId, $roomTaskId, $houseUserId, '2026-06-15 09:00:00', 3);

		// The definition is edited to a DIFFERENT energy after the work was
		// recorded — the frozen occurrence energy must win.
		$qb = $this->db->getQueryBuilder();
		$qb->update('chorestrike_tasks')
			->set('energy_cost', $qb->createNamedParameter(9, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($this->taskIdOfRoomTask($roomTaskId), IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();

		$rows = $this->usageFor($houseId);
		self::assertCount(1, $rows);
		self::assertSame(3, $rows[0]['energy'], 'the saved occurrence energy is frozen against definition edits');
	}

	/**
	 * FindEnergyUsage_UnlinkedCompletion_NoSilentDefinitionFallback
	 * (RF-073-WP2-15): a completion row without its occurrence link is an
	 * invariant violation — it is excluded instead of silently re-priced
	 * from the mutable definition.
	 */
	public function testFindEnergyUsage_UnlinkedCompletion_NoSilentDefinitionFallback(): void {
		$houseId = $this->createHouse('energy_unlinked_owner', 'Energy Unlinked House');
		$floorId = $this->createFloor($houseId, 'Ground Floor', 1);
		$roomId = $this->createRoom($houseId, $floorId, 'Kitchen');
		$roomTaskId = $this->assignTaskToRoom($this->createTask($houseId, 'Dishes', '', 5, 3), $roomId);

		$houseUserId = $this->houseUserId($houseId, 'energy_unlinked_owner');
		$this->insertCompletion($roomTaskId, $houseUserId, '2026-06-15 09:00:00');

		self::assertSame([], $this->usageFor($houseId), 'an unlinked row never falls back to the definition energy');
	}

	private function taskIdOfRoomTask(int $roomTaskId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select('task_id')
			->from('chorestrike_room_tasks')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)));
		return (int)$qb->executeQuery()->fetchOne();
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
		$this->insertLinkedCompletion($houseId, $rtPreviousDay, $houseUserId, '2026-06-14 23:59:59', 6); // before window
		$this->insertLinkedCompletion($houseId, $rtStartEdge, $houseUserId, '2026-06-15 00:00:00', 2);   // inclusive start
		$this->insertLinkedCompletion($houseId, $rtLateEvening, $houseUserId, '2026-06-15 23:59:59', 4); // last in-window second
		$this->insertLinkedCompletion($houseId, $rtNextDay, $houseUserId, '2026-06-16 00:00:00', 7);     // exclusive end

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
		$this->insertLinkedCompletion($houseId, $rtMine, $userA, '2026-06-15 09:00:00', 3);
		$this->insertLinkedCompletion($houseId, $rtTheirs, $userB, '2026-06-15 10:00:00', 9);

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
