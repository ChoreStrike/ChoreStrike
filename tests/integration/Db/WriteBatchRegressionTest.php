<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Db;

use OCA\ChoreStrike\Db\CompletedTaskMapper;
use OCA\ChoreStrike\Db\FloorMapper;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\RoomMapper;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\TaskAssigneeMapper;
use OCA\ChoreStrike\Service\FloorService;
use OCA\ChoreStrike\Service\RoomService;
use OCA\ChoreStrike\Service\RoomTaskService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * Real-DB regression suite for the CS-PLAN-037 write-path batch methods.
 *
 * Mock-based tests pin the query shape, but only a real database proves
 * the semantics the set-based writes rely on: house isolation of the
 * subquery UPDATE, the orphan-free explicit delete cascade (D1/D2 proof
 * — the schema has no FK constraints), CASE-WHEN reorder persistence,
 * and the house-scoped skip of the bulk assign pre-validation.
 */
class WriteBatchRegressionTest extends IntegrationTestCase {
	private RoomMapper $roomMapper;
	private RoomTaskMapper $roomTaskMapper;
	private FloorMapper $floorMapper;
	private HouseUserMapper $houseUserMapper;
	private CompletedTaskMapper $completedTaskMapper;
	private TaskAssigneeMapper $taskAssigneeMapper;
	private RoomService $roomService;
	private RoomTaskService $roomTaskService;
	private FloorService $floorService;

	/** @var array<int> Room task IDs whose dependent rows this test created directly. */
	private array $dependentRowRoomTaskIds = [];

	protected function setUp(): void {
		parent::setUp();
		$this->roomMapper = \OC::$server->get(RoomMapper::class);
		$this->roomTaskMapper = \OC::$server->get(RoomTaskMapper::class);
		$this->floorMapper = \OC::$server->get(FloorMapper::class);
		$this->houseUserMapper = \OC::$server->get(HouseUserMapper::class);
		$this->completedTaskMapper = \OC::$server->get(CompletedTaskMapper::class);
		$this->taskAssigneeMapper = \OC::$server->get(TaskAssigneeMapper::class);
		$this->roomService = \OC::$server->get(RoomService::class);
		$this->roomTaskService = \OC::$server->get(RoomTaskService::class);
		$this->floorService = \OC::$server->get(FloorService::class);
	}

	protected function tearDown(): void {
		if (!empty($this->dependentRowRoomTaskIds)) {
			foreach ([
				'chorestrike_chk_progress',
				'chorestrike_task_assignees',
				'chorestrike_completed_tasks',
			] as $tableName) {
				$qb = $this->db->getQueryBuilder();
				$qb->delete($tableName)
					->where($qb->expr()->in('room_task_id', $qb->createNamedParameter($this->dependentRowRoomTaskIds, IQueryBuilder::PARAM_INT_ARRAY)));
				$qb->executeStatement();
			}
		}

		parent::tearDown();
	}

	private function trackRoomTaskId(int $roomTaskId): int {
		$this->dependentRowRoomTaskIds[] = $roomTaskId;
		return $roomTaskId;
	}

	private function countRows(string $tableName, string $column, array $ids): int {
		if ($ids === []) {
			return 0;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))
			->from($tableName)
			->where($qb->expr()->in($column, $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));

		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();
		return $count;
	}

	/**
	 * Count the given rows that are TOMBSTONED (deleted_at set — the
	 * CS-PLAN-071 structural-deletion marker).
	 */
	private function countTombstoned(string $tableName, array $ids): int {
		if ($ids === []) {
			return 0;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))
			->from($tableName)
			->where($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->isNotNull('deleted_at'));

		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();
		return $count;
	}

	private function insertCompletion(int $roomTaskId, int $houseUserId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->insert('chorestrike_completed_tasks')
			->values([
				'room_task_id' => $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT),
				'user_id' => $qb->createNamedParameter($houseUserId, IQueryBuilder::PARAM_INT),
				'completed_at' => $qb->createNamedParameter('2026-08-20 10:00:00', IQueryBuilder::PARAM_STR),
				'coins_earned' => $qb->createNamedParameter(10, IQueryBuilder::PARAM_INT),
				'was_combo' => $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL),
				// WP5 effect-record columns are NOT NULL (CS-PLAN-064); the
				// cascade fixtures carry no undo semantics → empty JSON.
				'before_state' => $qb->createNamedParameter('{}', IQueryBuilder::PARAM_STR),
				'deltas' => $qb->createNamedParameter('{}', IQueryBuilder::PARAM_STR),
			]);
		$qb->executeStatement();
	}

	private function insertAssignee(int $roomTaskId, string $ncUserId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->insert('chorestrike_task_assignees')
			->values([
				'room_task_id' => $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT),
				'user_id' => $qb->createNamedParameter($ncUserId, IQueryBuilder::PARAM_STR),
				'assigned_at' => $qb->createNamedParameter('2026-08-20 10:00:00', IQueryBuilder::PARAM_STR),
			]);
		$qb->executeStatement();
	}

	private function insertChecklistProgress(int $roomTaskId, int $checklistItemId): void {
		// CS-PLAN-072 WP1: progress rows key on the occurrence identity —
		// resolve the room task's current revision and first ordinal.
		$revision = \OC::$server->get(\OCA\ChoreStrike\Db\ScheduleRevisionMapper::class)
			->findCurrentByRoomTask($roomTaskId);
		$qb = $this->db->getQueryBuilder();
		$qb->insert('chorestrike_chk_progress')
			->values([
				'room_task_id' => $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT),
				'schedule_rev_id' => $qb->createNamedParameter($revision->getId(), IQueryBuilder::PARAM_INT),
				'ordinal' => $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT),
				'revision' => $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT),
				'checklist_item_id' => $qb->createNamedParameter($checklistItemId, IQueryBuilder::PARAM_INT),
				'is_checked' => $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL),
			]);
		$qb->executeStatement();
	}

	/**
	 * Set a room's columns directly (dirty state for the reset proof).
	 */
	private function makeRoomDirty(int $roomId, int $cleanliness, int $monsters): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('chorestrike_rooms')
			->set('cleanliness', $qb->createNamedParameter($cleanliness, IQueryBuilder::PARAM_INT))
			->set('monster_count', $qb->createNamedParameter($monsters, IQueryBuilder::PARAM_INT))
			->set('last_cleaned', $qb->createNamedParameter('2026-08-19 08:00:00', IQueryBuilder::PARAM_STR))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($roomId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	/**
	 * resetByHouseId only resets the target house's rooms — a sibling
	 * house with identical dirty state must survive untouched.
	 */
	public function testResetByHouseId_TwoHouseIsolation(): void {
		$houseA = $this->createHouse('wbatch_reset_a', 'Reset House A');
		$floorA = $this->createFloor($houseA);
		$roomA1 = $this->createRoom($houseA, $floorA, 'Kitchen A');
		$roomA2 = $this->createRoom($houseA, $floorA, 'Bath A');

		$houseB = $this->createHouse('wbatch_reset_b', 'Reset House B');
		$floorB = $this->createFloor($houseB);
		$roomB1 = $this->createRoom($houseB, $floorB, 'Kitchen B');

		$this->makeRoomDirty($roomA1, 10, 3);
		$this->makeRoomDirty($roomA2, 20, 1);
		$this->makeRoomDirty($roomB1, 10, 2);

		$affected = $this->roomMapper->resetByHouseId($houseA);

		self::assertSame(2, $affected, 'exactly house A\'s two rooms are reset');

		foreach ($this->roomMapper->findByIds([$roomA1, $roomA2, $roomB1]) as $roomId => $room) {
			if ($roomId === $roomB1) {
				self::assertSame(10, $room->getCleanliness(), 'sibling house untouched');
				self::assertSame(2, $room->getMonsterCount());
				self::assertNotNull($room->getLastCleaned());
			} else {
				self::assertSame(100, $room->getCleanliness(), "room {$roomId} reset to clean");
				self::assertSame(0, $room->getMonsterCount());
				self::assertNull($room->getLastCleaned(), 'last_cleaned cleared');
			}
		}
	}

	/**
	 * The Q-70-01 tombstone contract: deleting a room TOMBSTONES the room
	 * and its room tasks (rows survive with deleted_at, invisible to the
	 * active-planning reads), deletes the active-planning dependents
	 * (checklist progress, assignees) and RETAINS the completed_tasks
	 * evidence, while a sibling house's identical structure survives.
	 */
	public function testDeleteRoom_CascadesAllFiveTablesOrphanFree(): void {
		$houseA = $this->createHouse('wbatch_del_a', 'Delete House A');
		$floorA = $this->createFloor($houseA);
		$roomA = $this->createRoom($houseA, $floorA, 'Kitchen A');
		$houseUserA = $this->houseUserMapper
			->findByHouseIdAndNextcloudUserId($houseA, 'wbatch_del_a')->getId();

		$rtA1 = $this->trackRoomTaskId($this->assignTaskToRoom($this->createTask($houseA, 'Dishes A'), $roomA));
		$rtA2 = $this->trackRoomTaskId($this->assignTaskToRoom($this->createTask($houseA, 'Laundry A'), $roomA));
		$this->insertCompletion($rtA1, $houseUserA);
		$this->insertCompletion($rtA2, $houseUserA);
		$this->insertAssignee($rtA1, 'wbatch_del_a');
		$this->insertChecklistProgress($rtA1, 1);
		$this->insertChecklistProgress($rtA1, 2);

		$houseB = $this->createHouse('wbatch_del_b', 'Delete House B');
		$floorB = $this->createFloor($houseB);
		$roomB = $this->createRoom($houseB, $floorB, 'Kitchen B');
		$houseUserB = $this->houseUserMapper
			->findByHouseIdAndNextcloudUserId($houseB, 'wbatch_del_b')->getId();
		$rtB1 = $this->trackRoomTaskId($this->assignTaskToRoom($this->createTask($houseB, 'Dishes B'), $roomB));
		$this->insertCompletion($rtB1, $houseUserB);
		$this->insertAssignee($rtB1, 'wbatch_del_b');
		$this->insertChecklistProgress($rtB1, 1);

		// Precondition: dependents exist in all three tables.
		self::assertSame(2, $this->countRows('chorestrike_completed_tasks', 'room_task_id', [$rtA1, $rtA2]));
		self::assertSame(1, $this->countRows('chorestrike_task_assignees', 'room_task_id', [$rtA1]));
		self::assertSame(2, $this->countRows('chorestrike_chk_progress', 'room_task_id', [$rtA1]));

		// Owner-only service gate (CS-PLAN-061 D4): bind the owner's session
		// right before the call (lesson (b): the session is process-global).
		$this->actingAs('wbatch_del_a');
		$this->roomService->deleteRoom($roomA);

		// CS-PLAN-071 (Q-70-01): the structural rows are TOMBSTONED (present
		// with deleted_at, excluded from the active reads) — never deleted.
		self::assertSame(2, $this->countRows('chorestrike_room_tasks', 'id', [$rtA1, $rtA2]));
		self::assertSame(2, $this->countTombstoned('chorestrike_room_tasks', [$rtA1, $rtA2]), 'both room tasks are tombstoned');
		self::assertSame(2, $this->countRows('chorestrike_completed_tasks', 'room_task_id', [$rtA1, $rtA2]), 'the completed_tasks evidence is retained');
		self::assertSame(0, $this->countRows('chorestrike_task_assignees', 'room_task_id', [$rtA1, $rtA2]), 'assignees (active-planning state) are deleted');
		self::assertSame(0, $this->countRows('chorestrike_chk_progress', 'room_task_id', [$rtA1, $rtA2]), 'checklist progress (active-planning state) is deleted');
		self::assertSame(1, $this->countRows('chorestrike_rooms', 'id', [$roomA]), 'the room row survives as a tombstone');
		self::assertSame(1, $this->countTombstoned('chorestrike_rooms', [$roomA]), 'the room is tombstoned');

		// The tombstoned structure vanished from the active-planning reads.
		$activeRoomIds = array_map(static fn ($r) => $r->getId(), $this->roomMapper->findByHouseId($houseA));
		self::assertNotContains($roomA, $activeRoomIds);

		// The sibling house is untouched (live rows, no tombstones).
		self::assertSame(1, $this->countRows('chorestrike_room_tasks', 'id', [$rtB1]));
		self::assertSame(0, $this->countTombstoned('chorestrike_room_tasks', [$rtB1]));
		self::assertSame(1, $this->countRows('chorestrike_completed_tasks', 'room_task_id', [$rtB1]));
		self::assertSame(1, $this->countRows('chorestrike_task_assignees', 'room_task_id', [$rtB1]));
		self::assertSame(1, $this->countRows('chorestrike_chk_progress', 'room_task_id', [$rtB1]));
	}

	/**
	 * Single-task deletion tombstones the row, deletes only the
	 * active-planning state and retains the completion evidence
	 * (deleteRoomTask; before CS-PLAN-037 it left the three dependent
	 * tables orphaned, before CS-PLAN-071 it deleted the evidence).
	 */
	public function testDeleteRoomTask_RemovesDependentsOrphanFree(): void {
		$houseId = $this->createHouse('wbatch_deltask', 'Delete Task House');
		$floorId = $this->createFloor($houseId);
		$roomId = $this->createRoom($houseId, $floorId, 'Kitchen');
		$houseUserId = $this->houseUserMapper
			->findByHouseIdAndNextcloudUserId($houseId, 'wbatch_deltask')->getId();

		$rtKept = $this->trackRoomTaskId($this->assignTaskToRoom($this->createTask($houseId, 'Kept'), $roomId));
		$rtDeleted = $this->trackRoomTaskId($this->assignTaskToRoom($this->createTask($houseId, 'Deleted'), $roomId));
		$this->insertCompletion($rtDeleted, $houseUserId);
		$this->insertAssignee($rtDeleted, 'wbatch_deltask');
		$this->insertChecklistProgress($rtDeleted, 1);

		// Re-bind the owner's session (lesson (b)) and pass the explicit
		// HouseUser actor the service now requires.
		$this->actingAs('wbatch_deltask');
		$actor = $this->houseUserMapper->findByHouseIdAndNextcloudUserId($houseId, 'wbatch_deltask');
		$this->roomTaskService->deleteRoomTask($rtDeleted, $actor);

		// CS-PLAN-071 (Q-70-01): the row survives as a tombstone, the
		// planning state is deleted, the evidence is retained.
		self::assertSame(1, $this->countRows('chorestrike_room_tasks', 'id', [$rtDeleted]));
		self::assertSame(1, $this->countTombstoned('chorestrike_room_tasks', [$rtDeleted]), 'the room task is tombstoned (deleted_at + deleted_token)');
		self::assertSame(1, $this->countRows('chorestrike_completed_tasks', 'room_task_id', [$rtDeleted]), 'the completed_tasks evidence is retained');
		self::assertSame(0, $this->countRows('chorestrike_task_assignees', 'room_task_id', [$rtDeleted]), 'assignees are deleted');
		self::assertSame(0, $this->countRows('chorestrike_chk_progress', 'room_task_id', [$rtDeleted]), 'checklist progress is deleted');

		// The sibling task in the same room survives, live.
		self::assertSame(1, $this->countRows('chorestrike_room_tasks', 'id', [$rtKept]));
		self::assertSame(0, $this->countTombstoned('chorestrike_room_tasks', [$rtKept]));
	}

	/**
	 * deleteFloor cascades tombstones through rooms and room tasks: the
	 * floor's structure is tombstoned, the planning state deleted, the
	 * evidence retained; the sibling floor of the same house stays live.
	 */
	public function testDeleteFloor_CascadesRoomsAndDependents(): void {
		$houseId = $this->createHouse('wbatch_delfloor', 'Delete Floor House');
		$floorGone = $this->createFloor($houseId, 'Gone Floor', 1);
		$floorKept = $this->createFloor($houseId, 'Kept Floor', 2);

		$roomGone1 = $this->createRoom($houseId, $floorGone, 'Kitchen');
		$roomGone2 = $this->createRoom($houseId, $floorGone, 'Bath');
		$roomKept = $this->createRoom($houseId, $floorKept, 'Attic');

		$houseUserId = $this->houseUserMapper
			->findByHouseIdAndNextcloudUserId($houseId, 'wbatch_delfloor')->getId();

		$rtGone = $this->trackRoomTaskId($this->assignTaskToRoom($this->createTask($houseId, 'Gone Task'), $roomGone1));
		$rtKept = $this->trackRoomTaskId($this->assignTaskToRoom($this->createTask($houseId, 'Kept Task'), $roomKept));
		$this->insertCompletion($rtGone, $houseUserId);
		$this->insertAssignee($rtGone, 'wbatch_delfloor');
		$this->insertChecklistProgress($rtGone, 1);

		$this->floorService->deleteFloor($floorGone);

		// CS-PLAN-071 (Q-70-01): tombstones instead of deletes.
		self::assertSame(1, $this->countRows('chorestrike_floors', 'id', [$floorGone]));
		self::assertSame(1, $this->countTombstoned('chorestrike_floors', [$floorGone]), 'the floor is tombstoned');
		self::assertSame(2, $this->countRows('chorestrike_rooms', 'id', [$roomGone1, $roomGone2]));
		self::assertSame(2, $this->countTombstoned('chorestrike_rooms', [$roomGone1, $roomGone2]), 'both rooms are tombstoned');
		self::assertSame(1, $this->countRows('chorestrike_room_tasks', 'id', [$rtGone]));
		self::assertSame(1, $this->countTombstoned('chorestrike_room_tasks', [$rtGone]), 'the room task is tombstoned');
		self::assertSame(1, $this->countRows('chorestrike_completed_tasks', 'room_task_id', [$rtGone]), 'the completed_tasks evidence is retained');
		self::assertSame(0, $this->countRows('chorestrike_task_assignees', 'room_task_id', [$rtGone]), 'assignees are deleted');
		self::assertSame(0, $this->countRows('chorestrike_chk_progress', 'room_task_id', [$rtGone]), 'checklist progress is deleted');

		// Sibling floor with its room and task survives, live.
		self::assertSame(1, $this->countRows('chorestrike_floors', 'id', [$floorKept]));
		self::assertSame(0, $this->countTombstoned('chorestrike_floors', [$floorKept]));
		self::assertSame(1, $this->countRows('chorestrike_rooms', 'id', [$roomKept]));
		self::assertSame(0, $this->countTombstoned('chorestrike_rooms', [$roomKept]));
		self::assertSame(1, $this->countRows('chorestrike_room_tasks', 'id', [$rtKept]));
		self::assertSame(0, $this->countTombstoned('chorestrike_room_tasks', [$rtKept]));
	}

	/**
	 * reorderFloors persists the new order (survives a fresh re-fetch)
	 * and still rejects a foreign floor id with InvalidArgumentException.
	 */
	public function testReorderFloors_PersistsOrderAndRejectsForeignFloor(): void {
		$houseId = $this->createHouse('wbatch_reorder', 'Reorder House');
		$floorA = $this->createFloor($houseId, 'A', 0);
		$floorB = $this->createFloor($houseId, 'B', 1);
		$floorC = $this->createFloor($houseId, 'C', 2);

		$houseOther = $this->createHouse('wbatch_reorder2', 'Other House');
		$floorOther = $this->createFloor($houseOther, 'X', 0);

		// Owner-only service gate (CS-PLAN-061 D4): bind the owner's session
		// right before the call (lesson (b)).
		$this->actingAs('wbatch_reorder');
		// New order: C, A, B.
		$updated = $this->floorService->reorderFloors($houseId, [$floorC, $floorA, $floorB]);

		self::assertSame(
			[$floorC, $floorA, $floorB],
			array_map(static fn ($f) => $f->getId(), $updated),
			'payload is in the new order'
		);
		self::assertSame([0, 1, 2], array_map(static fn ($f) => $f->getSortOrder(), $updated));

		// The persisted order survives a fresh re-fetch.
		$fresh = $this->floorMapper->findByHouseId($houseId);
		self::assertSame(
			[$floorC, $floorA, $floorB],
			array_map(static fn ($f) => $f->getId(), $fresh),
			'findByHouseId (ORDER BY sort_order) reflects the CASE-WHEN update'
		);

		// A floor of another house is rejected, unchanged contract.
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage("Floor ID {$floorOther} does not belong to house {$houseId}");

		$this->floorService->reorderFloors($houseId, [$floorOther]);
	}

	/**
	 * bulkAssignTasks skips a real cross-house room task id and a
	 * missing id, assigning only the caller's own tasks.
	 */
	public function testBulkAssignTasks_HouseScopedSkipOnRealCrossHouseId(): void {
		$houseA = $this->createHouse('wbatch_bulk_a', 'Bulk House A');
		$this->addHouseMember($houseA, 'wbatch_bulk_member', 'Member');
		$floorA = $this->createFloor($houseA);
		$roomA = $this->createRoom($houseA, $floorA, 'Kitchen A');
		$rtA1 = $this->trackRoomTaskId($this->assignTaskToRoom($this->createTask($houseA, 'Dishes A'), $roomA));
		$rtA2 = $this->trackRoomTaskId($this->assignTaskToRoom($this->createTask($houseA, 'Laundry A'), $roomA));

		$houseB = $this->createHouse('wbatch_bulk_b', 'Bulk House B');
		$floorB = $this->createFloor($houseB);
		$roomB = $this->createRoom($houseB, $floorB, 'Kitchen B');
		$rtB1 = $this->trackRoomTaskId($this->assignTaskToRoom($this->createTask($houseB, 'Dishes B'), $roomB));

		$this->actingAs('wbatch_bulk_a');
		$actor = $this->houseUserMapper->findByHouseIdAndNextcloudUserId($houseA, 'wbatch_bulk_a');
		$result = $this->roomTaskService->bulkAssignTasks(
			[$rtA1, $rtB1, 999999999, $rtA2],
			'wbatch_bulk_member',
			$houseA,
			$actor
		);

		self::assertSame(
			[$rtA1, $rtA2],
			array_map(static fn ($rt) => $rt->getId(), $result),
			'only the caller\'s own tasks come back, in input order'
		);
		self::assertSame(
			['wbatch_bulk_member'],
			$this->taskAssigneeMapper->findUserIdsByRoomTask($rtA1),
			'the persisted assignment lives in the canonical join table'
		);

		// The foreign task was never written.
		self::assertSame(
			[],
			$this->taskAssigneeMapper->findUserIdsByRoomTask($rtB1),
			'the foreign task stays unassigned'
		);
	}
}
