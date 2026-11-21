<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\CompletedTaskMapper;
use OCA\ChoreStrike\Db\Floor;
use OCA\ChoreStrike\Db\FloorMapper;
use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\Room;
use OCA\ChoreStrike\Db\RoomMapper;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Db\TaskAssigneeMapper;
use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Db\TransactionExecutor;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Exception\NotFoundException;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\CalendarService;
use OCA\ChoreStrike\Service\RoomService;
use OCA\ChoreStrike\Service\RoomTaskReadService;
use OCA\ChoreStrike\Service\RoomTaskService;
use OCA\ChoreStrike\Service\TaskAccessPolicy;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for RoomTaskService
 */
class RoomTaskServiceTest extends TestCase {
	private RoomTaskService $service;
	private RoomTaskMapper $roomTaskMapper;
	private TaskMapper $taskMapper;
	private RoomMapper $roomMapper;
	private FloorMapper $floorMapper;
	private RoomService $roomService;
	private TaskAssigneeMapper $taskAssigneeMapper;
	private CompletedTaskMapper $completedTaskMapper;
	private IDBConnection $db;
	private HouseMapper $houseMapper;
	private TaskAccessPolicy $taskAccessPolicy;
	private AuthorizationService $authorization;
	private \OCA\ChoreStrike\Service\WindowService $windows;
	/** House returned by houseMapper->find (tz-resolution, swappable per test). */
	private House $houseForTz;

	protected function setUp(): void {
		$this->roomTaskMapper = $this->createMock(RoomTaskMapper::class);
		$this->taskMapper = $this->createMock(TaskMapper::class);
		$this->roomMapper = $this->createMock(RoomMapper::class);
		$this->floorMapper = $this->createMock(FloorMapper::class);
		$this->roomService = $this->createMock(RoomService::class);
		$this->taskAssigneeMapper = $this->createMock(TaskAssigneeMapper::class);
		$this->completedTaskMapper = $this->createMock(CompletedTaskMapper::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->houseMapper = $this->createMock(HouseMapper::class);
		$this->taskAccessPolicy = $this->getMockBuilder(TaskAccessPolicy::class)
			->onlyMethods(['canView', 'canViewTask', 'assertCanView', 'assertCanViewTask', 'filterVisibleRoomTasks'])
			->disableOriginalConstructor()
			->disableOriginalClone()
			->disableArgumentCloning()
			->getMock();
		$this->authorization = $this->createMock(AuthorizationService::class);
		$this->windows = new \OCA\ChoreStrike\Service\WindowService(new \OCA\ChoreStrike\Service\RecurrenceCalculator(), $this->createMock(\OCA\ChoreStrike\Db\CompletedTaskMapper::class));
		// CS-PLAN-057: day-window methods resolve the house timezone.
		$utcHouse = new House();
		$utcHouse->setTimezone('UTC');
		$this->houseForTz = $utcHouse;
		$this->houseMapper->method('find')->willReturnCallback(fn (): House => $this->houseForTz);

		$this->service = new RoomTaskService(
			$this->roomTaskMapper,
			$this->taskMapper,
			$this->roomMapper,
			$this->houseMapper,
			// CS-PLAN-067 §3.1: the read cluster lives in RoomTaskReadService;
			// the real instance shares this test's mapper/policy mocks so the
			// delegated enrichment (stats/bulk visibility/tz) stays observable.
			new RoomTaskReadService(
				$this->roomTaskMapper,
				$this->taskMapper,
				$this->roomMapper,
				$this->floorMapper,
				$this->houseMapper,
				$this->taskAssigneeMapper,
				$this->taskAccessPolicy,
				new CalendarService(),
				$this->windows,
				$this->createMock(\OCA\ChoreStrike\Service\AppLogger::class),
				$this->createMock(\OCA\ChoreStrike\Db\TaskChecklistItemMapper::class),
				$this->createMock(\OCA\ChoreStrike\Service\CreditPolicy::class)
			),
			$this->roomService,
			$this->taskAssigneeMapper,
			$this->completedTaskMapper,
			new TransactionExecutor($this->db),
			$this->createIdentityL10n(),
			$this->createMock(AppLogger::class),
			$this->taskAccessPolicy,
			$this->authorization,
			$this->windows
		);
	}

	private function createIdentityL10n(): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text) => $text);
		return $l10n;
	}

	/**
	 * HouseUser actor for lifecycle-visibility wiring (CS-PLAN-056).
	 */
	private function createHouseUser(string $userId = 'user123', string $role = 'member'): HouseUser {
		$houseUser = new HouseUser();
		$houseUser->setNextcloudUserId($userId);
		$houseUser->setRole($role);
		return $houseUser;
	}

	/**
	 * Public task definition — passes the lifecycle gate for any member.
	 */
	private function createTask(string $visibilityType = Task::VISIBILITY_PUBLIC): Task {
		$task = new Task();
		$task->setVisibilityType($visibilityType);
		return $task;
	}

	/**
	 * Stub the task lookup behind the lifecycle guard.
	 */
	private function stubTaskDefinition(Task $task): void {
		$this->taskMapper
			->method('find')
			->willReturn($task);
	}

	/**
	 * Passthrough policy: every fetched room task stays visible, every
	 * assert* gate admits the actor. Tests override single methods when
	 * they exercise a denial.
	 */
	private function stubPolicyPassthrough(): void {
		$this->taskAccessPolicy->method('filterVisibleRoomTasks')
			->willReturnCallback(static fn (array $roomTasks): array => $roomTasks);
		$this->taskAccessPolicy->method('canView')->willReturn(true);
		$this->taskAccessPolicy->method('canViewTask')->willReturn(true);
	}

	/**
	 * GetRoomTask_ExistingId_ReturnsRoomTask
	 */
	public function testGetRoomTask_ExistingId_ReturnsRoomTask(): void {
		$roomTaskId = 1;

		$roomTask = new RoomTask();
		$roomTask->setId($roomTaskId);

		$this->roomTaskMapper
			->expects($this->once())
			->method('find')
			->with($roomTaskId)
			->willReturn($roomTask);

		$result = $this->service->getRoomTask($roomTaskId);

		$this->assertSame($roomTask, $result);
	}

	/**
	 * GetRoomTask_NonExistingId_ThrowsNotFound
	 *
	 * CS-PLAN-061 §3.5: the mapper's DoesNotExistException is wrapped into
	 * the app NotFoundException — a raw framework exception would 500.
	 */
	public function testGetRoomTask_NonExistingId_ThrowsNotFound(): void {
		$this->roomTaskMapper
			->method('find')
			->willThrowException(new DoesNotExistException('room task gone'));

		$this->expectException(NotFoundException::class);
		$this->expectExceptionMessage('Room task not found');

		$this->service->getRoomTask(999);
	}

	/**
	 * ValidateRoomTaskOwnership_TombstonedRoomTask_Yields404
	 *
	 * Q-70-01 (CS-PLAN-071): a tombstoned room task is gone from active
	 * planning — validation surfaces treat it exactly like a missing id.
	 */
	public function testValidateRoomTaskOwnership_TombstonedRoomTask_Yields404(): void {
		$roomTask = new RoomTask();
		$roomTask->setId(1);
		$roomTask->setRoomId(5);
		$roomTask->setDeletedAt(new \DateTime());

		$this->roomTaskMapper->method('find')->willReturn($roomTask);

		$this->expectException(NotFoundException::class);
		$this->expectExceptionMessage('Room task not found');

		$this->service->validateRoomTaskOwnership(1, 1);
	}

	// CS-PLAN-056 (CR-053-06): lifecycle mutations enforce the same visibility
	// matrix as the completion path (updateDueDate since CS-PLAN-064 WP5 —
	// uncompleteTask/reactivateTask/completeTask moved to the
	// CompletionService undo/complete aggregates). Since CS-PLAN-061 the
	// matrix runs through TaskAccessPolicy and a denial is the 404
	// anti-enumeration NotFoundException (D2/D3).

	/**
	 * DeleteRoomTask_ExistingTask_TombstonesAndCleansPlanningState
	 *
	 * CS-PLAN-071 WP0 (Q-70-01): single-task deletion TOMBSTONES the row
	 * (deleted_at + deleted_token + is_active off) and deletes only the
	 * active-planning state via the shared cascade helper — the
	 * completed_tasks evidence is retained and nothing is hard-deleted.
	 */
	public function testDeleteRoomTask_ExistingTask_CascadesDependentsInOneTransaction(): void {
		$roomTaskId = 1;

		$roomTask = new RoomTask();
		$roomTask->setId($roomTaskId);
		$roomTask->setTaskId(1);
		$roomTask->setRoomId(5);

		$this->stubTaskDefinition($this->createTask());
		$this->stubOwningHouseChain(5, 9, 1);

		// The visibility gate and the tombstone re-read resolve the room task.
		$this->roomTaskMapper
			->expects($this->exactly(2))
			->method('find')
			->with($roomTaskId)
			->willReturn($roomTask);

		// Under the locks the row is re-acquired for the tombstone write.
		$this->roomTaskMapper
			->method('lockForUpdate')
			->with($roomTaskId)
			->willReturn($roomTask);

		$this->taskAssigneeMapper->method('findUserIdsByRoomTask')->willReturn([]);

		$this->roomService
			->expects($this->once())
			->method('deleteRoomTaskPlanningState')
			->with([$roomTaskId]);

		$this->roomTaskMapper
			->expects($this->once())
			->method('update')
			->with($this->callback(static function (RoomTask $rt): bool {
				return $rt->getDeletedAt() !== null
					&& $rt->getDeletedToken() === $rt->getId()
					&& $rt->getIsActive() === false;
			}));

		// No hard delete anywhere (evidence retained, Q-70-01).
		$this->roomTaskMapper->expects($this->never())->method('delete');

		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('commit');
		$this->db->expects($this->never())->method('rollBack');

		$this->service->deleteRoomTask($roomTaskId, $this->createHouseUser('deleter', 'owner'));
	}

	/**
	 * Stub the room → floor → house chain the house-first writers resolve
	 * (CS-PLAN-071): the shared RoomTaskReadService reads the same mocks.
	 */
	private function stubOwningHouseChain(int $roomId, int $floorId, int $houseId): void {
		$room = new Room();
		$room->setId($roomId);
		$room->setFloorId($floorId);
		$this->roomMapper->method('find')->with($roomId)->willReturn($room);

		$floor = new Floor();
		$floor->setId($floorId);
		$floor->setHouseId($houseId);
		$this->floorMapper->method('find')->with($floorId)->willReturn($floor);
	}

	/**
	 * DeleteRoomTask_ChildActor_ThrowsForbidden
	 *
	 * CS-PLAN-061 §3.4: children may not delete room tasks — the actor
	 * policy gate runs after the visibility gate, before any persistence.
	 */
	public function testDeleteRoomTask_ChildActor_ThrowsForbidden(): void {
		$roomTask = new RoomTask();
		$roomTask->setId(1);
		$roomTask->setTaskId(1);

		$this->stubTaskDefinition($this->createTask());
		$this->roomTaskMapper->method('find')->willReturn($roomTask);
		$this->taskAssigneeMapper->method('findUserIdsByRoomTask')->willReturn([]);

		$this->authorization->method('assertCanManageTasks')
			->willThrowException(new ForbiddenException('Children may view and complete tasks, but not manage them'));

		$this->roomService->expects($this->never())->method('deleteRoomTaskPlanningState');
		$this->roomTaskMapper->expects($this->never())->method('update');
		$this->roomTaskMapper->expects($this->never())->method('delete');

		$this->expectException(ForbiddenException::class);

		$this->service->deleteRoomTask(1, $this->createHouseUser('kid', 'child'));
	}

	/**
	 * DeleteRoomTask_PlanningStateFailure_RollsBackAndReThrows
	 */
	public function testDeleteRoomTask_DependentFailure_RollsBackAndReThrows(): void {
		$roomTaskId = 1;

		$roomTask = new RoomTask();
		$roomTask->setId($roomTaskId);
		$roomTask->setTaskId(1);
		$roomTask->setRoomId(5);

		$this->stubTaskDefinition($this->createTask());
		$this->stubOwningHouseChain(5, 9, 1);
		$this->roomTaskMapper->method('find')->willReturn($roomTask);
		$this->taskAssigneeMapper->method('findUserIdsByRoomTask')->willReturn([]);
		$this->roomService->method('deleteRoomTaskPlanningState')
			->willThrowException(new \RuntimeException('db down'));

		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('rollBack');
		$this->db->expects($this->never())->method('commit');
		$this->roomTaskMapper->expects($this->never())->method('update');

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('db down');

		$this->service->deleteRoomTask($roomTaskId, $this->createHouseUser('deleter', 'owner'));
	}

	/**
	 * DeleteRoomTask_TombstonedBehindPreRead_Yields404ZeroWrites
	 *
	 * CS-PLAN-071 review fix (TOCTOU): a concurrent delete winner makes
	 * the second delete a clean 404 under the locks — the original
	 * tombstone evidence (deleted_at/deleted_token) is never re-stamped.
	 */
	public function testDeleteRoomTask_TombstonedBehindPreRead_Yields404ZeroWrites(): void {
		$roomTaskId = 1;

		$liveRoomTask = new RoomTask();
		$liveRoomTask->setId($roomTaskId);
		$liveRoomTask->setTaskId(1);
		$liveRoomTask->setRoomId(5);

		$tombstonedRoomTask = new RoomTask();
		$tombstonedRoomTask->setId($roomTaskId);
		$tombstonedRoomTask->setTaskId(1);
		$tombstonedRoomTask->setRoomId(5);
		$tombstonedRoomTask->setDeletedAt(new \DateTime('2026-09-18T10:00:00Z'));
		$tombstonedRoomTask->setDeletedToken($roomTaskId);

		$this->stubTaskDefinition($this->createTask());
		$this->stubOwningHouseChain(5, 9, 1);
		$this->roomTaskMapper->method('find')->willReturn($liveRoomTask);
		$this->taskAssigneeMapper->method('findUserIdsByRoomTask')->willReturn([]);

		// The authoritative gate is the FRESH row under the locks.
		$this->roomTaskMapper
			->method('lockForUpdate')
			->with($roomTaskId)
			->willReturn($tombstonedRoomTask);

		$this->roomService->expects($this->never())->method('deleteRoomTaskPlanningState');
		$this->roomTaskMapper->expects($this->never())->method('update');
		$this->roomTaskMapper->expects($this->never())->method('delete');
		$this->db->expects($this->once())->method('rollBack');
		$this->db->expects($this->never())->method('commit');

		$this->expectException(NotFoundException::class);
		$this->expectExceptionMessage('Room task not found');

		$this->service->deleteRoomTask($roomTaskId, $this->createHouseUser('deleter', 'owner'));
	}

	/**
	 * GetRoomTaskStats_WithTasks_ReturnsStats
	 *
	 * CR-SVC-31 (CS-PLAN-040): completionRate counts REAL completions —
	 * distinct completed room tasks from CompletedTaskMapper — not
	 * `(total − active)/total`, which counted deactivated tasks as done.
	 */
	public function testGetRoomTaskStats_WithTasks_ReturnsStats(): void {
		$roomId = 1;

		// CS-PLAN-057: isDueToday resolves the room → floor → house tz chain.
		$room = new Room();
		$room->setId($roomId);
		$room->setFloorId(7);
		$this->roomMapper->method('find')->willReturn($room);
		$floor = new Floor();
		$floor->setId(7);
		$floor->setHouseId(99);
		$this->floorMapper->method('find')->willReturn($floor);
		$house = new House();
		$house->setTimezone('UTC');
		$this->houseMapper->method('find')->willReturn($house);

		// A weekly task whose window ended three days ago: overdue (ADR-021).
		$definition = new Task();
		$definition->setId(9);
		$this->taskMapper->method('findByIds')->willReturn([9 => $definition]);
		$activeTask = new RoomTask();
		$activeTask->setId(1);
		$activeTask->setTaskId(9);
		$activeTask->setIsActive(true);
		$activeTask->setAnchorOn(new \DateTime('-3 days'));
		$activeTask->setNextDue(new \DateTime('-3 days'));

		$inactiveTask = new RoomTask();
		$inactiveTask->setId(2);
		$inactiveTask->setTaskId(9);
		$inactiveTask->setIsActive(false);

		$this->roomTaskMapper
			->expects($this->once())
			->method('findByRoomId')
			->with($roomId, false)
			->willReturn([$activeTask, $inactiveTask]);

		// Every room task is visible to the viewer (pass-through filter).
		$this->taskAccessPolicy
			->method('filterVisibleRoomTasks')
			->willReturnCallback(static fn (array $roomTasks) => $roomTasks);

		// Only one of the two room tasks has a real completion row.
		$this->completedTaskMapper
			->expects($this->once())
			->method('countDistinctCompletedRoomTasksInSet')
			->with([1, 2])
			->willReturn(1);

		$result = $this->service->getRoomTaskStats($roomId, $this->createHouseUser());

		$this->assertEquals(2, $result['totalTasks']);
		$this->assertEquals(1, $result['activeTasks']);
		$this->assertEquals(1, $result['overdueTasks']);
		$this->assertEquals(50, $result['completionRate']);
	}

	/**
	 * GetRoomTaskStats_InvisibleTasksExcludedFromCounts
	 *
	 * CS-PLAN-061 review fix: the stats are computed over the visibility-
	 * filtered room-task set — invisible private tasks neither appear in
	 * the counts nor confirm their existence.
	 */
	public function testGetRoomTaskStats_InvisibleTasksExcludedFromCounts(): void {
		$roomId = 1;

		$room = new Room();
		$room->setId($roomId);
		$room->setFloorId(7);
		$this->roomMapper->method('find')->willReturn($room);
		$floor = new Floor();
		$floor->setId(7);
		$floor->setHouseId(99);
		$this->floorMapper->method('find')->willReturn($floor);
		$house = new House();
		$house->setTimezone('UTC');
		$this->houseMapper->method('find')->willReturn($house);

		$visibleTask = new RoomTask();
		$visibleTask->setId(1);
		$visibleTask->setIsActive(true);
		$visibleTask->setNextDue(new \DateTime('+1 day'));

		$invisibleTask = new RoomTask();
		$invisibleTask->setId(2);
		$invisibleTask->setIsActive(true);
		$invisibleTask->setNextDue(new \DateTime('-1 day'));

		$this->roomTaskMapper
			->method('findByRoomId')
			->willReturn([$visibleTask, $invisibleTask]);

		// The policy filters the invisible room task out.
		$this->taskAccessPolicy
			->method('filterVisibleRoomTasks')
			->willReturn([$visibleTask]);

		$this->completedTaskMapper
			->method('countDistinctCompletedRoomTasksInSet')
			->with([1])
			->willReturn(1);

		$result = $this->service->getRoomTaskStats($roomId, $this->createHouseUser());

		$this->assertEquals(1, $result['totalTasks']);
		$this->assertEquals(1, $result['activeTasks']);
		$this->assertEquals(0, $result['overdueTasks'], 'the invisible overdue task must not be counted');
		$this->assertEquals(100, $result['completionRate']);
	}

	/**
	 * GetRoomTaskStats_EmptyRoom_CompletionRateIsZero
	 *
	 * CR-SVC-31 regression: the old formula returned 100 for an empty set;
	 * with real completions an empty room has completed nothing → 0.
	 */
	public function testGetRoomTaskStats_EmptyRoom_CompletionRateIsZero(): void {
		$roomId = 1;

		// CS-PLAN-057: isDueToday resolves the room → floor → house tz chain.
		$room = new Room();
		$room->setId($roomId);
		$room->setFloorId(7);
		$this->roomMapper->method('find')->willReturn($room);
		$floor = new Floor();
		$floor->setId(7);
		$floor->setHouseId(99);
		$this->floorMapper->method('find')->willReturn($floor);
		$house = new House();
		$house->setTimezone('UTC');
		$this->houseMapper->method('find')->willReturn($house);

		$this->roomTaskMapper
			->method('findByRoomId')
			->willReturn([]);

		$this->taskAccessPolicy
			->method('filterVisibleRoomTasks')
			->willReturnCallback(static fn (array $roomTasks) => $roomTasks);

		$this->completedTaskMapper
			->method('countDistinctCompletedRoomTasksInSet')
			->willReturn(0);

		$result = $this->service->getRoomTaskStats($roomId, $this->createHouseUser());

		$this->assertEquals(0, $result['totalTasks']);
		$this->assertEquals(0, $result['completionRate']);
	}

	/**
	 * GetRoomTaskStats_DeactivatedTasksDoNotCountAsCompleted
	 *
	 * CR-SVC-31 regression: deactivating every task must NOT push the rate
	 * to 100 — only actual completion rows move it.
	 */
	public function testGetRoomTaskStats_DeactivatedTasksDoNotCountAsCompleted(): void {
		$roomId = 1;

		// CS-PLAN-057: isDueToday resolves the room → floor → house tz chain.
		$room = new Room();
		$room->setId($roomId);
		$room->setFloorId(7);
		$this->roomMapper->method('find')->willReturn($room);
		$floor = new Floor();
		$floor->setId(7);
		$floor->setHouseId(99);
		$this->floorMapper->method('find')->willReturn($floor);
		$house = new House();
		$house->setTimezone('UTC');
		$this->houseMapper->method('find')->willReturn($house);

		$inactiveTaskA = new RoomTask();
		$inactiveTaskA->setId(1);
		$inactiveTaskA->setIsActive(false);

		$inactiveTaskB = new RoomTask();
		$inactiveTaskB->setId(2);
		$inactiveTaskB->setIsActive(false);

		$this->roomTaskMapper
			->method('findByRoomId')
			->willReturn([$inactiveTaskA, $inactiveTaskB]);

		$this->taskAccessPolicy
			->method('filterVisibleRoomTasks')
			->willReturnCallback(static fn (array $roomTasks) => $roomTasks);

		$this->completedTaskMapper
			->method('countDistinctCompletedRoomTasksInSet')
			->willReturn(0);

		$result = $this->service->getRoomTaskStats($roomId, $this->createHouseUser());

		$this->assertEquals(2, $result['totalTasks']);
		$this->assertEquals(0, $result['activeTasks']);
		$this->assertEquals(0, $result['completionRate']);
	}

	/**
	 * Stub a room task whose definition passes the visibility gate.
	 */
	private function stubVisibleRoomTask(): void {
		$roomTask = new RoomTask();
		$roomTask->setId(1);
		$roomTask->setTaskId(9);
		$this->roomTaskMapper->method('find')->willReturn($roomTask);
		$this->taskMapper->method('find')->willReturn($this->createTask(Task::VISIBILITY_PUBLIC));
	}
}
