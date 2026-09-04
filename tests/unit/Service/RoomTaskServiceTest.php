<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\CompletedTaskMapper;
use OCA\ChoreStrike\Db\Floor;
use OCA\ChoreStrike\Db\FloorMapper;
use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\Room;
use OCA\ChoreStrike\Db\RoomMapper;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Db\TaskAssignee;
use OCA\ChoreStrike\Db\TaskAssigneeMapper;
use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Db\TransactionExecutor;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Exception\NotFoundException;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\CalendarService;
use OCA\ChoreStrike\Service\RoomService;
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
	private HouseUserMapper $houseUserMapper;
	private CompletedTaskMapper $completedTaskMapper;
	private IDBConnection $db;
	private HouseMapper $houseMapper;
	private TaskAccessPolicy $taskAccessPolicy;
	private AuthorizationService $authorization;

	protected function setUp(): void {
		$this->roomTaskMapper = $this->createMock(RoomTaskMapper::class);
		$this->taskMapper = $this->createMock(TaskMapper::class);
		$this->roomMapper = $this->createMock(RoomMapper::class);
		$this->floorMapper = $this->createMock(FloorMapper::class);
		$this->roomService = $this->createMock(RoomService::class);
		$this->taskAssigneeMapper = $this->createMock(TaskAssigneeMapper::class);
		$this->houseUserMapper = $this->createMock(HouseUserMapper::class);
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
		// CS-PLAN-057: day-window methods resolve the house timezone.
		$utcHouse = new House();
		$utcHouse->setTimezone('UTC');
		$this->houseMapper->method('find')->willReturn($utcHouse);

		$this->service = new RoomTaskService(
			$this->roomTaskMapper,
			$this->taskMapper,
			$this->roomMapper,
			$this->floorMapper,
			$this->roomService,
			$this->taskAssigneeMapper,
			$this->houseUserMapper,
			$this->completedTaskMapper,
			$this->createMock(\OCA\ChoreStrike\Service\TemplateService::class),
			new TransactionExecutor($this->db),
			$this->createIdentityL10n(),
			$this->createMock(AppLogger::class),
			$this->houseMapper,
			new CalendarService(),
			$this->taskAccessPolicy,
			$this->authorization
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
	 * GetRoomTasks_WithActiveFilter_ReturnsActiveTasks
	 */
	public function testGetRoomTasks_WithActiveFilter_ReturnsActiveTasks(): void {
		$roomId = 1;

		$roomTask1 = new RoomTask();
		$roomTask1->setId(1);
		$roomTask1->setTaskId(1);
		$roomTask1->setIsActive(true);

		$roomTask2 = new RoomTask();
		$roomTask2->setId(2);
		$roomTask2->setTaskId(1);
		$roomTask2->setIsActive(false);

		$this->roomTaskMapper
			->expects($this->once())
			->method('findByRoomId')
			->with($roomId, true)
			->willReturn([$roomTask1]);

		$this->taskMapper->method('findByIds')->willReturn([]);
		$this->stubPolicyPassthrough();

		$result = $this->service->getRoomTasks($roomId, $this->createHouseUser(), true);

		$this->assertCount(1, $result);
		$this->assertTrue($result[0]->getIsActive());
	}

	/**
	 * GetRoomTasks_AppliesVisibilityMatrix
	 *
	 * CS-PLAN-061 §3.3: the room-task read path filters through
	 * TaskAccessPolicy::filterVisibleRoomTasks — an invisible room task is
	 * absent from the result.
	 */
	public function testGetRoomTasks_AppliesVisibilityMatrix(): void {
		$roomId = 1;

		$visible = new RoomTask();
		$visible->setId(1);
		$visible->setTaskId(1);
		$visible->setIsActive(true);

		$invisible = new RoomTask();
		$invisible->setId(2);
		$invisible->setTaskId(2);
		$invisible->setIsActive(true);

		$this->roomTaskMapper->method('findByRoomId')->willReturn([$visible, $invisible]);
		$this->taskMapper->method('findByIds')->willReturn([]);

		$this->taskAccessPolicy->method('filterVisibleRoomTasks')->willReturn([$visible]);

		$result = $this->service->getRoomTasks($roomId, $this->createHouseUser());

		$this->assertSame([$visible], $result);
	}

	/**
	 * GetScopeTasksScopedToHouse_FiltersCrossHouseEntries
	 *
	 * CR-053-02: skip-on-missing intersection — only scope tasks whose ids
	 * survive the house-scoped batch fetch are returned, in the original
	 * fetch order.
	 */
	public function testGetScopeTasksScopedToHouse_FiltersCrossHouseEntries(): void {
		$scopeId = 5;
		$houseId = 1;

		$own = new RoomTask();
		$own->setId(1);
		$own->setTaskId(1);
		$foreign = new RoomTask();
		$foreign->setId(2);
		$foreign->setTaskId(1);

		$this->roomTaskMapper
			->expects($this->once())
			->method('findByScopeId')
			->with($scopeId, true)
			->willReturn([$own, $foreign]);

		$this->roomTaskMapper
			->expects($this->once())
			->method('findByIdsScopedToHouse')
			->with([1, 2], $houseId)
			->willReturn([$own]);

		$this->taskMapper->method('findByIds')->willReturn([]);
		$this->stubPolicyPassthrough();

		$result = $this->service->getScopeTasksScopedToHouse($scopeId, $houseId, $this->createHouseUser());

		$this->assertSame([$own], $result);
	}

	/**
	 * GetScopeTasksScopedToHouse_EmptyScope_SkipsBatchFetch
	 */
	public function testGetScopeTasksScopedToHouse_EmptyScope_SkipsBatchFetch(): void {
		$this->roomTaskMapper
			->expects($this->once())
			->method('findByScopeId')
			->willReturn([]);
		$this->roomTaskMapper
			->expects($this->never())
			->method('findByIdsScopedToHouse');

		$this->assertSame([], $this->service->getScopeTasksScopedToHouse(9, 1, $this->createHouseUser()));
	}

	/**
	 * GetOverdueTasks_ReturnsOverdueTasks
	 */
	public function testGetOverdueTasks_ReturnsOverdueTasks(): void {
		$houseId = 1;
		$roomId = 2;

		$roomTask = new RoomTask();
		$roomTask->setId(1);
		$roomTask->setTaskId(1);
		$roomTask->setNextDue(new \DateTime('-1 day'));

		$this->roomTaskMapper
			->expects($this->once())
			->method('findOverdueByHouse')
			->with($houseId, $roomId)
			->willReturn([$roomTask]);

		$this->taskMapper->method('findByIds')->willReturn([]);
		$this->stubPolicyPassthrough();

		$result = $this->service->getOverdueTasks($houseId, $this->createHouseUser(), $roomId);

		$this->assertCount(1, $result);
	}

	/**
	 * GetDueTodayTasks_ReturnsDueTodayTasks
	 */
	public function testGetDueTodayTasks_ReturnsDueTodayTasks(): void {
		$houseId = 1;
		$roomId = 2;

		$roomTask = new RoomTask();
		$roomTask->setId(1);
		$roomTask->setTaskId(1);
		$roomTask->setNextDue(new \DateTime());

		$this->roomTaskMapper
			->expects($this->once())
			->method('findDueTodayByHouse')
			->with(
				$houseId,
				$this->isInstanceOf(\DateTimeInterface::class),
				$this->isInstanceOf(\DateTimeInterface::class),
				$roomId
			)
			->willReturn([$roomTask]);

		$this->taskMapper->method('findByIds')->willReturn([]);
		$this->stubPolicyPassthrough();

		$result = $this->service->getDueTodayTasks($houseId, $this->createHouseUser(), $roomId);

		$this->assertCount(1, $result);
	}

	/**
	 * GetDueWithinDaysTasks_ReturnsTasksWithinRange
	 */
	public function testGetDueWithinDaysTasks_ReturnsTasksWithinRange(): void {
		$houseId = 1;
		$days = 7;
		$roomId = 2;

		$roomTask = new RoomTask();
		$roomTask->setId(1);
		$roomTask->setTaskId(1);
		$roomTask->setNextDue(new \DateTime('+3 days'));

		$this->roomTaskMapper
			->expects($this->once())
			->method('findDueWithinDaysByHouse')
			->with($houseId, $days, $roomId)
			->willReturn([$roomTask]);

		$this->taskMapper->method('findByIds')->willReturn([]);
		$this->stubPolicyPassthrough();

		$result = $this->service->getDueWithinDaysTasks($houseId, $days, $this->createHouseUser(), $roomId);

		$this->assertCount(1, $result);
	}

	/**
	 * CreateRoomTask_NewTask_CreatesRoomTask
	 *
	 * The room resolves through the floor chain to the caller's house, so a
	 * same-house create succeeds.
	 */
	public function testCreateRoomTask_NewTask_CreatesRoomTask(): void {
		$houseId = 10;
		$roomId = 1;
		$taskId = 1;
		$floorId = 7;

		$room = new Room();
		$room->setId($roomId);
		$room->setFloorId($floorId);

		$floor = new Floor();
		$floor->setId($floorId);
		$floor->setHouseId($houseId);

		$task = new Task();
		$task->setId($taskId);

		$roomTask = new RoomTask();
		$roomTask->setId(1);

		$this->roomMapper
			->expects($this->once())
			->method('find')
			->with($roomId)
			->willReturn($room);

		$this->floorMapper
			->expects($this->once())
			->method('find')
			->with($floorId)
			->willReturn($floor);

		$this->taskMapper
			->expects($this->once())
			->method('find')
			->with($taskId)
			->willReturn($task);

		$this->roomTaskMapper
			->expects($this->once())
			->method('exists')
			->with($roomId, $taskId)
			->willReturn(false);

		$this->roomTaskMapper
			->expects($this->once())
			->method('create')
			->willReturn($roomTask);

		$result = $this->service->createRoomTask($houseId, $roomId, $taskId, $this->createHouseUser('creator', 'owner'));

		$this->assertSame($roomTask, $result);
	}

	/**
	 * CreateRoomTask_DuplicateTask_ThrowsException
	 */
	public function testCreateRoomTask_DuplicateTask_ThrowsException(): void {
		$houseId = 10;
		$roomId = 1;
		$taskId = 1;
		$floorId = 7;

		$room = new Room();
		$room->setId($roomId);
		$room->setFloorId($floorId);

		$floor = new Floor();
		$floor->setId($floorId);
		$floor->setHouseId($houseId);

		$task = new Task();
		$task->setId($taskId);

		$this->roomMapper->method('find')->willReturn($room);
		$this->floorMapper->method('find')->willReturn($floor);
		$this->taskMapper->method('find')->willReturn($task);

		$this->roomTaskMapper
			->expects($this->once())
			->method('exists')
			->willReturn(true);

		$this->expectException(\InvalidArgumentException::class);

		$this->service->createRoomTask($houseId, $roomId, $taskId, $this->createHouseUser('creator', 'owner'));
	}

	/**
	 * CreateRoomTask_CrossHouseRoom_ThrowsNotFound
	 *
	 * CR-SVC-01 regression: a room that resolves to another house must be
	 * rejected (NotFoundException) before any persistence. The room/floor
	 * existence boundary must not be leaked.
	 */
	public function testCreateRoomTask_CrossHouseRoom_ThrowsNotFound(): void {
		$houseId = 10;
		$roomId = 1;
		$floorId = 7;

		$room = new Room();
		$room->setId($roomId);
		$room->setFloorId($floorId);

		$floor = new Floor();
		$floor->setId($floorId);
		$floor->setHouseId(99); // a different house

		$this->roomMapper->method('find')->with($roomId)->willReturn($room);
		$this->floorMapper->method('find')->with($floorId)->willReturn($floor);
		$this->taskMapper->expects($this->never())->method('find');
		$this->roomTaskMapper->expects($this->never())->method('create');

		$this->expectException(NotFoundException::class);

		$this->service->createRoomTask($houseId, $roomId, 1, $this->createHouseUser('creator', 'owner'));
	}

	/**
	 * CreateRoomTask_RoomNotFound_ThrowsNotFound
	 *
	 * A missing room (or floor) maps to NotFoundException, not a raw
	 * DoesNotExistException leak.
	 */
	public function testCreateRoomTask_RoomNotFound_ThrowsNotFound(): void {
		$this->roomMapper->method('find')
			->willThrowException(new DoesNotExistException('room gone'));

		$this->taskMapper->expects($this->never())->method('find');
		$this->roomTaskMapper->expects($this->never())->method('create');

		$this->expectException(NotFoundException::class);

		$this->service->createRoomTask(10, 1, 1, $this->createHouseUser('creator', 'owner'));
	}

	/**
	 * CreateRoomTask_ChildActor_ThrowsForbidden
	 *
	 * CS-PLAN-061 §3.4 (D5): children execute tasks, they do not create
	 * them — the actor policy gate runs before any persistence.
	 */
	public function testCreateRoomTask_ChildActor_ThrowsForbidden(): void {
		$this->authorization->method('assertCanManageTasks')
			->willThrowException(new ForbiddenException('Children may view and complete tasks, but not manage them'));

		$this->roomMapper->expects($this->never())->method('find');
		$this->roomTaskMapper->expects($this->never())->method('create');

		$this->expectException(ForbiddenException::class);

		$this->service->createRoomTask(10, 1, 1, $this->createHouseUser('kid', 'child'));
	}

	/**
	 * CreateRoomTask_NonMemberAssignee_Throws
	 *
	 * CR-SVC-01 regression: an assignee stamped by the client who is not a
	 * member of the caller's house must be rejected before persistence.
	 */
	public function testCreateRoomTask_NonMemberAssignee_Throws(): void {
		$houseId = 10;
		$roomId = 1;
		$floorId = 7;

		$room = new Room();
		$room->setId($roomId);
		$room->setFloorId($floorId);

		$floor = new Floor();
		$floor->setId($floorId);
		$floor->setHouseId($houseId);

		$this->roomMapper->method('find')->willReturn($room);
		$this->floorMapper->method('find')->willReturn($floor);
		$this->houseUserMapper->expects($this->once())
			->method('findByHouseAndUser')
			->with($houseId, 'intruder')
			->willReturn(null);
		$this->taskMapper->expects($this->never())->method('find');
		$this->roomTaskMapper->expects($this->never())->method('create');

		$this->expectException(\InvalidArgumentException::class);

		$this->service->createRoomTask($houseId, $roomId, 1, $this->createHouseUser('creator', 'owner'), null, null, 'intruder');
	}

	/**
	 * CreateRoomTask_ValidMemberAssignee_CreatesRoomTask
	 *
	 * CR-SVC-01 happy path: an assignee who IS a house member is accepted.
	 */
	public function testCreateRoomTask_ValidMemberAssignee_CreatesRoomTask(): void {
		$houseId = 10;
		$roomId = 1;
		$taskId = 1;
		$floorId = 7;

		$room = new Room();
		$room->setId($roomId);
		$room->setFloorId($floorId);

		$floor = new Floor();
		$floor->setId($floorId);
		$floor->setHouseId($houseId);

		$task = new Task();
		$task->setId($taskId);

		$roomTask = new RoomTask();
		$roomTask->setId(1);

		$this->roomMapper->method('find')->willReturn($room);
		$this->floorMapper->method('find')->willReturn($floor);
		$this->taskMapper->method('find')->willReturn($task);
		$this->houseUserMapper->expects($this->once())
			->method('findByHouseAndUser')
			->with($houseId, 'member-user')
			->willReturn(new HouseUser());
		$this->roomTaskMapper->method('exists')->willReturn(false);
		$this->roomTaskMapper->expects($this->once())->method('create')->willReturn($roomTask);

		$result = $this->service->createRoomTask($houseId, $roomId, $taskId, $this->createHouseUser('creator', 'owner'), null, null, 'member-user');

		$this->assertSame($roomTask, $result);
	}

	/**
	 * ReactivateTask_ExistingTask_Reactivates
	 */
	public function testReactivateTask_ExistingTask_Reactivates(): void {
		$roomTaskId = 1;

		$roomTask = new RoomTask();
		$roomTask->setId($roomTaskId);
		$roomTask->setTaskId(1);
		$roomTask->setIsActive(true);

		$this->stubTaskDefinition($this->createTask());
		$this->roomTaskMapper
			->expects($this->exactly(2))
			->method('find')
			->with($roomTaskId)
			->willReturn($roomTask);

		$this->roomTaskMapper
			->expects($this->once())
			->method('reactivate')
			->with($roomTaskId);

		$this->taskAssigneeMapper->method('findUserIdsByRoomTask')->willReturn([]);

		$result = $this->service->reactivateTask($roomTaskId, $this->createHouseUser());

		$this->assertTrue($result->getIsActive());
	}

	/**
	 * ReactivateTask_NonViewerTask_ThrowsNotFound
	 *
	 * CS-PLAN-061 (D2/D3): the lifecycle visibility matrix denies a
	 * non-viewer with the app NotFoundException (404 anti-enumeration) —
	 * previously this was a ForbiddenException.
	 */
	public function testReactivateTask_NonViewerTask_ThrowsNotFound(): void {
		$roomTask = new RoomTask();
		$roomTask->setId(1);
		$roomTask->setTaskId(1);

		$this->stubTaskDefinition($this->createTask(Task::VISIBILITY_PRIVATE));
		$this->roomTaskMapper->method('find')->willReturn($roomTask);
		$this->taskAssigneeMapper->method('findUserIdsByRoomTask')->willReturn([]);
		$this->taskAccessPolicy->method('assertCanView')
			->willThrowException(new NotFoundException('Task not found'));

		$this->roomTaskMapper->expects($this->never())->method('reactivate');

		$this->expectException(NotFoundException::class);
		$this->service->reactivateTask(1, $this->createHouseUser('memberUser'));
	}

	/**
	 * UpdateDueDate_ExistingTask_UpdatesDueDate
	 */
	public function testUpdateDueDate_ExistingTask_UpdatesDueDate(): void {
		$roomTaskId = 1;
		$newDue = new \DateTime('+3 days');

		$roomTask = new RoomTask();
		$roomTask->setId($roomTaskId);
		$roomTask->setTaskId(1);
		$roomTask->setNextDue($newDue);

		$this->stubTaskDefinition($this->createTask());
		$this->roomTaskMapper
			->expects($this->exactly(2))
			->method('find')
			->with($roomTaskId)
			->willReturn($roomTask);

		$this->roomTaskMapper
			->expects($this->once())
			->method('updateNextDue')
			->with($roomTaskId, $newDue);

		$this->taskAssigneeMapper->method('findUserIdsByRoomTask')->willReturn([]);

		$result = $this->service->updateDueDate($roomTaskId, $newDue, $this->createHouseUser());

		$this->assertSame($roomTask, $result);
	}

	// CS-PLAN-056 (CR-053-06): lifecycle mutations enforce the same visibility
	// matrix as the completion path. Wired through uncompleteTask /
	// updateDueDate / reactivateTask so the guard cannot be bypassed. Since
	// CS-PLAN-061 the matrix runs through TaskAccessPolicy and a denial is
	// the 404 anti-enumeration NotFoundException (D2/D3).

	/**
	 * UncompleteTask_PrivateTask_NonAssigneeMember_ThrowsNotFound
	 */
	public function testUncompleteTask_PrivateTask_NonAssigneeMember_ThrowsNotFound(): void {
		$roomTask = new RoomTask();
		$roomTask->setId(1);
		$roomTask->setTaskId(1);
		$roomTask->setAssignedToUserId('ownerUser');

		$this->stubTaskDefinition($this->createTask(Task::VISIBILITY_PRIVATE));
		$this->roomTaskMapper->method('find')->willReturn($roomTask);
		$this->taskAssigneeMapper->method('findUserIdsByRoomTask')->willReturn([]);
		$this->taskAccessPolicy->method('assertCanView')
			->willThrowException(new NotFoundException('Task not found'));

		$this->roomTaskMapper->expects($this->never())->method('update');

		$this->expectException(NotFoundException::class);
		$this->service->uncompleteTask(1, $this->createHouseUser('memberUser'));
	}

	/**
	 * UncompleteTask_PrivateTask_Assignee_Succeeds
	 */
	public function testUncompleteTask_PrivateTask_Assignee_Succeeds(): void {
		$roomTask = new RoomTask();
		$roomTask->setId(1);
		$roomTask->setTaskId(1);
		$roomTask->setAssignedToUserId('assigneeUser');

		$this->stubTaskDefinition($this->createTask(Task::VISIBILITY_PRIVATE));
		$this->roomTaskMapper->method('find')->willReturn($roomTask);
		$this->taskAssigneeMapper->method('findUserIdsByRoomTask')->willReturn(['assigneeUser']);

		$updatedRoomTask = new RoomTask();
		$updatedRoomTask->setId(1);
		$updatedRoomTask->setTaskId(1);
		$updatedRoomTask->setAssignedToUserId('assigneeUser');
		$updatedRoomTask->setLastCompleted(null);
		$this->roomTaskMapper->method('update')->willReturn($updatedRoomTask);

		$result = $this->service->uncompleteTask(1, $this->createHouseUser('assigneeUser'));

		$this->assertNull($result->getLastCompleted());
	}

	/**
	 * UncompleteTask_PublicTask_AnyMember_Succeeds
	 */
	public function testUncompleteTask_PublicTask_AnyMember_Succeeds(): void {
		$roomTask = new RoomTask();
		$roomTask->setId(1);
		$roomTask->setTaskId(1);

		$this->stubTaskDefinition($this->createTask(Task::VISIBILITY_PUBLIC));
		$this->roomTaskMapper->method('find')->willReturn($roomTask);
		$this->taskAssigneeMapper->method('findUserIdsByRoomTask')->willReturn([]);

		$updatedRoomTask = new RoomTask();
		$updatedRoomTask->setId(1);
		$updatedRoomTask->setTaskId(1);
		$updatedRoomTask->setLastCompleted(null);
		$this->roomTaskMapper->method('update')->willReturn($updatedRoomTask);

		$result = $this->service->uncompleteTask(1, $this->createHouseUser('anyMember'));

		$this->assertNull($result->getLastCompleted());
	}

	/**
	 * UpdateDueDate_AdminOnlyTask_NonOwnerMember_ThrowsNotFound
	 */
	public function testUpdateDueDate_AdminOnlyTask_NonOwnerMember_ThrowsNotFound(): void {
		$roomTask = new RoomTask();
		$roomTask->setId(1);
		$roomTask->setTaskId(1);

		$this->stubTaskDefinition($this->createTask(Task::VISIBILITY_ADMIN_ONLY));
		$this->roomTaskMapper->method('find')->willReturn($roomTask);
		$this->taskAssigneeMapper->method('findUserIdsByRoomTask')->willReturn([]);
		$this->taskAccessPolicy->method('assertCanView')
			->willThrowException(new NotFoundException('Task not found'));

		$this->roomTaskMapper->expects($this->never())->method('updateNextDue');

		$this->expectException(NotFoundException::class);
		$this->service->updateDueDate(1, new \DateTime('+1 day'), $this->createHouseUser('memberUser'));
	}

	/**
	 * ReactivateTask_AdminOnlyTask_Owner_Succeeds
	 */
	public function testReactivateTask_AdminOnlyTask_Owner_Succeeds(): void {
		$roomTask = new RoomTask();
		$roomTask->setId(1);
		$roomTask->setTaskId(1);
		$roomTask->setIsActive(true);

		$this->stubTaskDefinition($this->createTask(Task::VISIBILITY_ADMIN_ONLY));
		$this->roomTaskMapper->method('find')->willReturn($roomTask);
		$this->taskAssigneeMapper->method('findUserIdsByRoomTask')->willReturn([]);

		$this->roomTaskMapper->expects($this->once())->method('reactivate');

		$this->taskAssigneeMapper->method('findUserIdsByRoomTask')->willReturn([]);

		$result = $this->service->reactivateTask(1, $this->createHouseUser('ownerUser', 'owner'));

		$this->assertTrue($result->getIsActive());
	}

	/**
	 * CompleteTask_DelegatesToRoomService
	 */
	public function testCompleteTask_DelegatesToRoomService(): void {
		$roomTaskId = 1;
		$userId = 'user123';

		$completionData = [
			'roomTask' => new RoomTask(),
			'rewards' => ['cleanlinessChange' => 20]
		];

		$this->roomService
			->expects($this->once())
			->method('completeRoomTask')
			->with($roomTaskId, $userId)
			->willReturn($completionData);

		$result = $this->service->completeTask($roomTaskId, $userId);

		$this->assertSame($completionData, $result);
	}

	/**
	 * DeleteRoomTask_ExistingTask_CascadesDependentsInOneTransaction
	 *
	 * CS-PLAN-037 (D1/D2): single-task deletion is orphan-free — the
	 * room task's dependents (checklist progress, assignees,
	 * completions) are removed with it via the shared cascade helper.
	 */
	public function testDeleteRoomTask_ExistingTask_CascadesDependentsInOneTransaction(): void {
		$roomTaskId = 1;

		$roomTask = new RoomTask();
		$roomTask->setId($roomTaskId);
		$roomTask->setTaskId(1);

		$this->stubTaskDefinition($this->createTask());
		// The visibility gate and the deletion both resolve the room task.
		$this->roomTaskMapper
			->expects($this->exactly(2))
			->method('find')
			->with($roomTaskId)
			->willReturn($roomTask);

		$this->taskAssigneeMapper->method('findUserIdsByRoomTask')->willReturn([]);

		$this->roomService
			->expects($this->once())
			->method('deleteRoomTaskDependents')
			->with([$roomTaskId]);

		$this->roomTaskMapper
			->expects($this->once())
			->method('delete')
			->with($roomTask);

		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('commit');
		$this->db->expects($this->never())->method('rollBack');

		$this->service->deleteRoomTask($roomTaskId, $this->createHouseUser('deleter', 'owner'));
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

		$this->roomService->expects($this->never())->method('deleteRoomTaskDependents');
		$this->roomTaskMapper->expects($this->never())->method('delete');

		$this->expectException(ForbiddenException::class);

		$this->service->deleteRoomTask(1, $this->createHouseUser('kid', 'child'));
	}

	/**
	 * DeleteRoomTask_DependentFailure_RollsBackAndReThrows
	 */
	public function testDeleteRoomTask_DependentFailure_RollsBackAndReThrows(): void {
		$roomTaskId = 1;

		$roomTask = new RoomTask();
		$roomTask->setId($roomTaskId);
		$roomTask->setTaskId(1);

		$this->stubTaskDefinition($this->createTask());
		$this->roomTaskMapper->method('find')->willReturn($roomTask);
		$this->taskAssigneeMapper->method('findUserIdsByRoomTask')->willReturn([]);
		$this->roomService->method('deleteRoomTaskDependents')
			->willThrowException(new \RuntimeException('db down'));

		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('rollBack');
		$this->db->expects($this->never())->method('commit');
		$this->roomTaskMapper->expects($this->never())->method('delete');

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('db down');

		$this->service->deleteRoomTask($roomTaskId, $this->createHouseUser('deleter', 'owner'));
	}

	/**
	 * GetRoomTaskWithDetails_WithData_ReturnsCompleteDetails
	 */
	public function testGetRoomTaskWithDetails_WithData_ReturnsCompleteDetails(): void {
		$roomTaskId = 1;

		$task = new Task();
		$task->setId(1);
		$task->setName('Test Task');

		$room = new Room();
		$room->setId(1);
		$room->setName('Test Room');
		$room->setFloorId(7);

		$floor = new Floor();
		$floor->setId(7);
		$floor->setHouseId(99);
		$this->floorMapper->method('find')->willReturn($floor);

		$house = new House();
		$house->setTimezone('UTC');
		$this->houseMapper->method('find')->willReturn($house);

		$roomTask = new RoomTask();
		$roomTask->setId($roomTaskId);
		$roomTask->setTaskId(1);
		$roomTask->setRoomId(1);
		$roomTask->setNextDue(new \DateTime('+1 day'));

		$this->roomTaskMapper
			->expects($this->once())
			->method('find')
			->with($roomTaskId)
			->willReturn($roomTask);

		$this->taskMapper
			->expects($this->once())
			->method('find')
			->with(1)
			->willReturn($task);

		$this->roomMapper
			->expects($this->once())
			->method('find')
			->with(1)
			->willReturn($room);

		$result = $this->service->getRoomTaskWithDetails($roomTaskId);

		$this->assertSame($roomTask, $result['roomTask']);
		$this->assertSame($task, $result['task']);
		$this->assertSame($room, $result['room']);
		$this->assertEquals('due_soon', $result['status']);
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

		$activeTask = new RoomTask();
		$activeTask->setId(1);
		$activeTask->setIsActive(true);
		$activeTask->setNextDue(new \DateTime('-1 day'));

		$inactiveTask = new RoomTask();
		$inactiveTask->setId(2);
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
	 * BulkAssignTasks_MultipleTasks_AssignsAllWithBatchPreValidation
	 *
	 * CS-PLAN-037: ONE house-scoped fetch + ONE membership validation
	 * replace the per-item ownership chain (≈7N → 3 + N); the return
	 * payload comes from one re-fetch of the assigned ids, in input order.
	 */
	public function testBulkAssignTasks_MultipleTasks_AssignsAllWithBatchPreValidation(): void {
		$houseId = 1;
		$roomTaskIds = [1, 2, 3];
		$userId = 'user123';

		$scopedTask1 = new RoomTask();
		$scopedTask1->setId(1);
		$scopedTask1->setTaskId(1);

		$scopedTask2 = new RoomTask();
		$scopedTask2->setId(2);
		$scopedTask2->setTaskId(1);

		$scopedTask3 = new RoomTask();
		$scopedTask3->setId(3);
		$scopedTask3->setTaskId(1);

		$freshTask1 = new RoomTask();
		$freshTask1->setId(1);

		$freshTask2 = new RoomTask();
		$freshTask2->setId(2);

		$freshTask3 = new RoomTask();
		$freshTask3->setId(3);

		$member = new HouseUser();
		$member->setNextcloudUserId($userId);

		// One batch fetch up front, one re-fetch for the payload.
		$this->roomTaskMapper
			->expects($this->exactly(2))
			->method('findByIdsScopedToHouse')
			->willReturnOnConsecutiveCalls(
				[$scopedTask1, $scopedTask2, $scopedTask3],
				[$freshTask1, $freshTask2, $freshTask3]
			);

		$this->houseUserMapper
			->expects($this->once())
			->method('findByHouseId')
			->with($houseId)
			->willReturn([$member]);

		$this->roomTaskMapper
			->method('find')
			->willReturnCallback(static fn (int $id) => new RoomTask());

		// Visibility pre-check for the house-scoped ids: visible to the actor.
		$this->taskMapper->method('findByIds')
			->willReturnCallback(fn (array $ids) => array_fill_keys($ids, $this->createTask()));
		$this->taskAssigneeMapper->method('findUserIdsByRoomTasks')->willReturn([]);
		$this->taskAccessPolicy->method('canView')->willReturn(true);

		$this->taskAssigneeMapper
			->expects($this->exactly(3))
			->method('replaceAssignees');

		// The bulk path never runs the per-item ownership chain.
		$this->roomMapper->expects($this->never())->method('find');
		$this->floorMapper->expects($this->never())->method('find');

		$result = $this->service->bulkAssignTasks($roomTaskIds, $userId, $houseId, $this->createHouseUser('assigner', 'owner'));

		$this->assertCount(3, $result);
		$this->assertSame([1, 2, 3], array_map(fn ($rt) => $rt->getId(), $result));
	}

	/**
	 * BulkAssignTasks_ForeignOrMissingIdsSkipped_ReturnsOnlyAssigned
	 *
	 * Isolation regression (CR-SVC-09/CS-PLAN-037): ids absent from the
	 * house-scoped fetch (foreign house or missing) are skipped without
	 * aborting the batch — the write only ever targets owned ids.
	 */
	public function testBulkAssignTasks_ForeignOrMissingIdsSkipped_ReturnsOnlyAssigned(): void {
		$houseId = 1;
		$userId = 'user123';

		$ownedScoped = new RoomTask();
		$ownedScoped->setId(2);
		$ownedScoped->setTaskId(1);

		$freshOwned = new RoomTask();
		$freshOwned->setId(2);

		$member = new HouseUser();
		$member->setNextcloudUserId($userId);

		// id 1 (foreign house) and id 99 (missing) never come back from
		// the scoped fetch — only the owned id 2 does.
		$this->roomTaskMapper
			->expects($this->exactly(2))
			->method('findByIdsScopedToHouse')
			->willReturnOnConsecutiveCalls([$ownedScoped], [$freshOwned]);

		$this->houseUserMapper
			->method('findByHouseId')
			->with($houseId)
			->willReturn([$member]);

		$this->roomTaskMapper
			->method('find')
			->willReturnCallback(static fn (int $id) => new RoomTask());

		// The owned id is visible to the actor.
		$this->taskMapper->method('findByIds')
			->willReturnCallback(fn (array $ids) => array_fill_keys($ids, $this->createTask()));
		$this->taskAssigneeMapper->method('findUserIdsByRoomTasks')->willReturn([]);
		$this->taskAccessPolicy->method('canView')->willReturn(true);

		$this->taskAssigneeMapper
			->expects($this->once())
			->method('replaceAssignees')
			->with(2, [$userId]);

		$result = $this->service->bulkAssignTasks([1, 2, 99], $userId, $houseId, $this->createHouseUser('assigner', 'owner'));

		$this->assertCount(1, $result);
		$this->assertSame(2, $result[0]->getId());
	}

	/**
	 * BulkAssignTasks_NonMemberTarget_ThrowsUpFront
	 *
	 * The target user is a request-level parameter: a non-member fails
	 * the whole call (clean InvalidArgumentException) before any write
	 * instead of silently skipping every item.
	 */
	public function testBulkAssignTasks_NonMemberTarget_ThrowsUpFront(): void {
		$houseId = 1;
		$userId = 'stranger';

		$member = new HouseUser();
		$member->setNextcloudUserId('someone-else');

		$this->houseUserMapper
			->method('findByHouseId')
			->with($houseId)
			->willReturn([$member]);

		$this->roomTaskMapper->expects($this->never())->method('findByIdsScopedToHouse');
		$this->taskAssigneeMapper->expects($this->never())->method('replaceAssignees');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('User is not a member of this house: %1$s');

		$this->service->bulkAssignTasks([1, 2], $userId, $houseId, $this->createHouseUser('assigner', 'owner'));
	}

	/**
	 * BulkAssignTasks_WriteFailureSkipped_DoesNotAbortBatch
	 *
	 * A failing assignment write is isolated per item: the batch
	 * continues and the failed id is absent from the payload.
	 */
	public function testBulkAssignTasks_WriteFailureSkipped_DoesNotAbortBatch(): void {
		$houseId = 1;
		$userId = 'user123';

		$scoped1 = new RoomTask();
		$scoped1->setId(1);
		$scoped1->setTaskId(1);

		$scoped2 = new RoomTask();
		$scoped2->setId(2);
		$scoped2->setTaskId(1);

		$fresh2 = new RoomTask();
		$fresh2->setId(2);

		$member = new HouseUser();
		$member->setNextcloudUserId($userId);

		$this->roomTaskMapper
			->expects($this->exactly(2))
			->method('findByIdsScopedToHouse')
			->willReturnOnConsecutiveCalls([$scoped1, $scoped2], [$fresh2]);

		$this->houseUserMapper
			->method('findByHouseId')
			->with($houseId)
			->willReturn([$member]);

		$this->roomTaskMapper
			->method('find')
			->willReturnCallback(static fn (int $id) => new RoomTask());

		// Both scoped ids are visible to the actor.
		$this->taskMapper->method('findByIds')
			->willReturnCallback(fn (array $ids) => array_fill_keys($ids, $this->createTask()));
		$this->taskAssigneeMapper->method('findUserIdsByRoomTasks')->willReturn([]);
		$this->taskAccessPolicy->method('canView')->willReturn(true);

		$this->taskAssigneeMapper
			->method('replaceAssignees')
			->willReturnCallback(function (int $roomTaskId) {
				if ($roomTaskId === 1) {
					throw new \RuntimeException('write failed');
				}
			});

		$result = $this->service->bulkAssignTasks([1, 2], $userId, $houseId, $this->createHouseUser('assigner', 'owner'));

		$this->assertCount(1, $result);
		$this->assertSame(2, $result[0]->getId());
	}

	/**
	 * BulkAssignTasks_InvisibleScopedTask_ThrowsNotFound
	 *
	 * CS-PLAN-061 §3.4: a house-scoped id whose definition is invisible to
	 * the actor fails the whole request up front with the 404
	 * anti-enumeration denial — before any write.
	 */
	public function testBulkAssignTasks_InvisibleScopedTask_ThrowsNotFound(): void {
		$houseId = 1;

		$scoped = new RoomTask();
		$scoped->setId(1);
		$scoped->setTaskId(1);

		$member = new HouseUser();
		$member->setNextcloudUserId('user123');

		$this->roomTaskMapper->method('findByIdsScopedToHouse')->willReturn([$scoped]);
		// The TARGET user is a member — the denial must come from the
		// visibility pre-check, not from membership validation.
		$this->houseUserMapper->method('findByHouseId')->willReturn([$member]);
		$this->taskMapper->method('findByIds')
			->willReturnCallback(fn (array $ids) => array_fill_keys($ids, $this->createTask(Task::VISIBILITY_PRIVATE)));
		$this->taskAssigneeMapper->method('findUserIdsByRoomTasks')->willReturn([]);
		$this->taskAccessPolicy->method('canView')->willReturn(false);

		$this->taskAssigneeMapper->expects($this->never())->method('replaceAssignees');

		$this->expectException(NotFoundException::class);
		$this->expectExceptionMessage('Room task not found');

		$this->service->bulkAssignTasks([1], 'user123', $houseId, $this->createHouseUser('viewer'));
	}

	/**
	 * BulkAssignTasks_EmptyInput_ReturnsEmptyWithoutQueries
	 */
	public function testBulkAssignTasks_EmptyInput_ReturnsEmptyWithoutQueries(): void {
		$houseId = 1;
		$userId = 'user123';

		$member = new HouseUser();
		$member->setNextcloudUserId($userId);

		$this->houseUserMapper
			->method('findByHouseId')
			->with($houseId)
			->willReturn([$member]);

		// The mapper's empty-input early return means no query runs;
		// nothing is ever assigned.
		$this->roomTaskMapper
			->method('findByIdsScopedToHouse')
			->with([], $houseId)
			->willReturn([]);
		$this->taskAssigneeMapper->expects($this->never())->method('replaceAssignees');

		$this->assertSame([], $this->service->bulkAssignTasks([], $userId, $houseId, $this->createHouseUser('assigner', 'owner')));
	}

	/**
	 * SetAssignees_ValidMembers_ReplacesAssignees
	 *
	 * The 4th parameter is the acting HouseUser: the gates run in order
	 * (ownership → visibility → actor policy → membership) and the write is
	 * attributed to the actor's Nextcloud user id.
	 */
	public function testSetAssignees_ValidMembers_ReplacesAssignees(): void {
		$roomTaskId = 1;
		$houseId = 1;
		$roomId = 5;
		$floorId = 7;
		$taskId = 9;
		$actor = $this->createHouseUser('assigner');

		$roomTask = new RoomTask();
		$roomTask->setId($roomTaskId);
		$roomTask->setRoomId($roomId);
		$roomTask->setTaskId($taskId);

		$room = new Room();
		$room->setId($roomId);
		$room->setFloorId($floorId);

		$floor = new Floor();
		$floor->setId($floorId);
		$floor->setHouseId($houseId);

		$member1 = new HouseUser();
		$member1->setNextcloudUserId('user-a');
		$member2 = new HouseUser();
		$member2->setNextcloudUserId('user-b');

		$assignee = new TaskAssignee();
		$assignee->setRoomTaskId($roomTaskId);
		$assignee->setUserId('user-a');

		$this->roomTaskMapper->method('find')->with($roomTaskId)->willReturn($roomTask);
		$this->roomMapper->method('find')->with($roomId)->willReturn($room);
		$this->floorMapper->method('find')->with($floorId)->willReturn($floor);
		$this->stubTaskDefinition($this->createTask());
		$this->taskAssigneeMapper->method('findUserIdsByRoomTask')->willReturn([]);
		$this->authorization->expects($this->once())->method('assertCanManageTasks')->with($actor);
		$this->houseUserMapper->method('findByHouseId')->with($houseId)->willReturn([$member1, $member2]);
		$this->taskAssigneeMapper->expects($this->once())->method('replaceAssignees')->with($roomTaskId, ['user-a', 'user-b'], 'assigner');
		$this->taskAssigneeMapper->method('findByRoomTask')->with($roomTaskId)->willReturn([$assignee]);

		$result = $this->service->setAssignees($roomTaskId, ['user-a', 'user-b'], $houseId, $actor);

		$this->assertCount(1, $result);
		$this->assertSame('user-a', $result[0]->getUserId());
	}

	/**
	 * SetAssignees_NonMember_Throws
	 *
	 * Client-supplied assignee lists are never trusted: a user who is not a
	 * house member must be rejected before any persistence.
	 */
	public function testSetAssignees_NonMember_Throws(): void {
		$roomTask = new RoomTask();
		$roomTask->setId(1);
		$roomTask->setRoomId(5);
		$roomTask->setTaskId(9);

		$room = new Room();
		$room->setId(5);
		$room->setFloorId(7);

		$floor = new Floor();
		$floor->setId(7);
		$floor->setHouseId(1);

		$member = new HouseUser();
		$member->setNextcloudUserId('user-a');

		$this->roomTaskMapper->method('find')->willReturn($roomTask);
		$this->roomMapper->method('find')->willReturn($room);
		$this->floorMapper->method('find')->willReturn($floor);
		$this->stubTaskDefinition($this->createTask());
		$this->taskAssigneeMapper->method('findUserIdsByRoomTask')->willReturn([]);
		$this->houseUserMapper->method('findByHouseId')->willReturn([$member]);
		$this->taskAssigneeMapper->expects($this->never())->method('replaceAssignees');

		$this->expectException(\InvalidArgumentException::class);
		$this->service->setAssignees(1, ['user-a', 'intruder'], 1, $this->createHouseUser('assigner'));
	}

	/**
	 * SetAssignees_ChildActor_ThrowsForbidden
	 *
	 * CS-PLAN-061 §3.4 (D5): children may not assign — the role gate fires
	 * after ownership + visibility, before any write. (Covers the surface of
	 * the removed assignTask/addAssignee tests.)
	 */
	public function testSetAssignees_ChildActor_ThrowsForbidden(): void {
		$roomTask = new RoomTask();
		$roomTask->setId(1);
		$roomTask->setRoomId(5);
		$roomTask->setTaskId(9);

		$room = new Room();
		$room->setId(5);
		$room->setFloorId(7);

		$floor = new Floor();
		$floor->setId(7);
		$floor->setHouseId(1);

		$this->roomTaskMapper->method('find')->willReturn($roomTask);
		$this->roomMapper->method('find')->willReturn($room);
		$this->floorMapper->method('find')->willReturn($floor);
		$this->stubTaskDefinition($this->createTask());
		$this->taskAssigneeMapper->method('findUserIdsByRoomTask')->willReturn([]);
		$this->authorization->method('assertCanManageTasks')
			->willThrowException(new ForbiddenException('Children may view and complete tasks, but not manage them'));
		$this->houseUserMapper->expects($this->never())->method('findByHouseId');
		$this->taskAssigneeMapper->expects($this->never())->method('replaceAssignees');

		$this->expectException(ForbiddenException::class);
		$this->service->setAssignees(1, ['user-a'], 1, $this->createHouseUser('kid', 'child'));
	}

	/**
	 * SetAssignees_ForeignHouseRoomTask_ThrowsNotFound
	 *
	 * CR-SVC-09 (kept for the removed assignTask coverage): a room task
	 * belonging to another house is rejected with NotFoundException (no
	 * existence leak) before any write.
	 */
	public function testSetAssignees_ForeignHouseRoomTask_ThrowsNotFound(): void {
		$roomTask = new RoomTask();
		$roomTask->setId(1);
		$roomTask->setRoomId(5);
		$roomTask->setTaskId(9);

		$room = new Room();
		$room->setId(5);
		$room->setFloorId(7);

		$floor = new Floor();
		$floor->setId(7);
		$floor->setHouseId(99); // different house

		$this->roomTaskMapper->method('find')->willReturn($roomTask);
		$this->roomMapper->method('find')->willReturn($room);
		$this->floorMapper->method('find')->willReturn($floor);

		$this->authorization->expects($this->never())->method('assertCanManageTasks');
		$this->taskAssigneeMapper->expects($this->never())->method('replaceAssignees');

		$this->expectException(NotFoundException::class);
		$this->expectExceptionMessage('Room task not found');

		$this->service->setAssignees(1, ['user-a'], 1, $this->createHouseUser('assigner'));
	}

	/**
	 * GetAssigneeUserIds_ReadsCanonicalJoinTable
	 *
	 * The assignee set is the join-table rows only — no legacy column exists
	 * anymore (CS-PLAN-060 WP1, D2).
	 */
	public function testGetAssigneeUserIds_ReadsCanonicalJoinTable(): void {
		$this->stubVisibleRoomTask();
		$this->taskAssigneeMapper->method('findUserIdsByRoomTask')->willReturn(['join-user']);

		$result = $this->service->getAssigneeUserIds(1, $this->createHouseUser());

		$this->assertSame(['join-user'], $result);
	}

	/**
	 * GetAssigneeUserIds_InvisibleTask_ThrowsNotFound
	 *
	 * CS-PLAN-061 review fix: the assignee list of a task the actor cannot
	 * see is a 404 — reading it would leak the assignment graph of an
	 * invisible private task.
	 */
	public function testGetAssigneeUserIds_InvisibleTask_ThrowsNotFound(): void {
		$roomTask = new RoomTask();
		$roomTask->setId(1);
		$roomTask->setTaskId(9);
		$this->roomTaskMapper->method('find')->willReturn($roomTask);
		$this->taskMapper->method('find')->willReturn($this->createTask(Task::VISIBILITY_PRIVATE));

		$this->taskAssigneeMapper->method('findUserIdsByRoomTask')->willReturn([]);
		$this->taskAccessPolicy
			->method('assertCanView')
			->willThrowException(new NotFoundException('Task not found'));

		$this->expectException(NotFoundException::class);
		$this->service->getAssigneeUserIds(1, $this->createHouseUser());
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
