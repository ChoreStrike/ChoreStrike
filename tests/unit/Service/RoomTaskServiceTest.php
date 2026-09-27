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
	private HouseUserMapper $houseUserMapper;
	private CompletedTaskMapper $completedTaskMapper;
	private IDBConnection $db;
	private HouseMapper $houseMapper;
	private TaskAccessPolicy $taskAccessPolicy;
	private AuthorizationService $authorization;
	/** Occurrence agreement writer (CS-PLAN-072 WP1). */
	private \OCA\ChoreStrike\Service\OccurrenceService $occurrenceService;
	/** House returned by houseMapper->find (tz-resolution, swappable per test). */
	private House $houseForTz;

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
		$this->occurrenceService = $this->createMock(\OCA\ChoreStrike\Service\OccurrenceService::class);
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
				$this->occurrenceService,
				$this->createMock(\OCA\ChoreStrike\Service\PauseProjectionService::class),
				$this->createMock(\OCA\ChoreStrike\Db\HouseUserMapper::class),
				$this->createMock(\OCA\ChoreStrike\Db\OccurrenceMemberMapper::class),
				new \OCA\ChoreStrike\Service\EffortAllocationService(),
				$this->createIdentityL10n()
			),
			$this->roomService,
			$this->taskAssigneeMapper,
			$this->houseUserMapper,
			$this->completedTaskMapper,
			new TransactionExecutor($this->db),
			$this->createIdentityL10n(),
			$this->createMock(AppLogger::class),
			$this->taskAccessPolicy,
			$this->authorization,
			new CalendarService(),
			$this->occurrenceService
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

	/**
	 * UpdateDueDate_TombstonedRoomTask_Yields404
	 *
	 * Q-70-01: lifecycle mutations never land on deleted planning state.
	 */
	public function testUpdateDueDate_TombstonedRoomTask_Yields404(): void {
		$roomTask = new RoomTask();
		$roomTask->setId(1);
		$roomTask->setTaskId(1);
		$roomTask->setRoomId(5);
		$roomTask->setDeletedAt(new \DateTime());

		$this->stubTaskDefinition($this->createTask());
		$this->roomTaskMapper->method('find')->willReturn($roomTask);
		$this->taskAssigneeMapper->method('findUserIdsByRoomTask')->willReturn([]);
		$this->occurrenceService->expects($this->never())->method('amendOccurrenceDateUnderLock');

		$this->expectException(NotFoundException::class);
		$this->expectExceptionMessage('Room task not found');

		$this->service->updateDueDate(1, '+1 day', $this->createHouseUser());
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
		$roomTask->setRoomId(5);
		$roomTask->setNextDue($newDue);

		$this->stubTaskDefinition($this->createTask());
		$this->roomTaskMapper
			->expects($this->exactly(2))
			->method('find')
			->with($roomTaskId)
			->willReturn($roomTask);

		// CS-PLAN-071: the owning house resolves via room → floor → house.
		$utcRoom = new \OCA\ChoreStrike\Db\Room();
		$utcRoom->setId(5);
		$utcRoom->setFloorId(9);
		$utcFloor = new \OCA\ChoreStrike\Db\Floor();
		$utcFloor->setId(9);
		$utcFloor->setHouseId(1);
		$this->roomMapper->method('find')->with(5)->willReturn($utcRoom);
		$this->floorMapper->method('find')->with(9)->willReturn($utcFloor);

		// CS-PLAN-072 WP1: due-date edits are AGREED amendments — the
		// agreement writer moves the target occurrence (identity retained).
		$this->roomTaskMapper->method('lockForUpdate')->willReturn($roomTask);
		$this->occurrenceService
			->expects($this->once())
			->method('amendOccurrenceDateUnderLock')
			->with(
				$this->callback(static fn (RoomTask $rt): bool => $rt->getId() === $roomTaskId),
				null,
				null,
				$this->callback(static fn (string $dayKey): bool => $dayKey === (new \DateTime('+3 days'))->format('Y-m-d')),
				$this->isInstanceOf(\DateTime::class)
			)
			->willReturn(new \OCA\ChoreStrike\Db\Occurrence());

		$this->taskAssigneeMapper->method('findUserIdsByRoomTask')->willReturn([]);

		$result = $this->service->updateDueDate($roomTaskId, '+3 days', $this->createHouseUser());

		$this->assertSame($roomTask, $result);
	}

	/**
	 * UpdateDueDate_DayKeyLabel_ResolvesHouseLocalMidnight (RF-058-01)
	 *
	 * A bare `Y-m-d` label denotes house-local midnight (ADR-009): for a
	 * UTC house '2030-05-10' persists as 2030-05-10T00:00:00Z; tagging the
	 * label at server-TZ midnight would land one local day early for
	 * houses west of UTC.
	 */
	public function testUpdateDueDate_DayKeyLabel_ResolvesHouseLocalMidnight(): void {
		$roomTask = new RoomTask();
		$roomTask->setId(1);
		$roomTask->setTaskId(1);
		$roomTask->setRoomId(5);

		$this->stubTaskDefinition($this->createTask());
		$this->roomTaskMapper->method('find')->willReturn($roomTask);
		$this->taskAssigneeMapper->method('findUserIdsByRoomTask')->willReturn([]);

		// Room 5 belongs to a Pacific/Honolulu (UTC-10) house: house-local
		// midnight of 2030-05-10 is 2030-05-10T10:00:00Z — NOT 00:00Z.
		$honoluluHouse = new House();
		$honoluluHouse->setTimezone('Pacific/Honolulu');
		$this->houseForTz = $honoluluHouse;
		$honoluluRoom = new \OCA\ChoreStrike\Db\Room();
		$honoluluRoom->setId(5);
		$honoluluRoom->setFloorId(9);
		$honoluluFloor = new \OCA\ChoreStrike\Db\Floor();
		$honoluluFloor->setId(9);
		$honoluluFloor->setHouseId(3);
		$this->roomMapper->method('find')->with(5)->willReturn($honoluluRoom);
		$this->floorMapper->method('find')->with(9)->willReturn($honoluluFloor);

		$captured = null;
		$this->roomTaskMapper->method('lockForUpdate')->willReturn($roomTask);
		$this->occurrenceService
			->expects($this->once())
			->method('amendOccurrenceDateUnderLock')
			->willReturnCallback(static function ($rt, $actor, $wallets, string $dayKey, $now) use (&$captured): \OCA\ChoreStrike\Db\Occurrence {
				$captured = $dayKey;
				return new \OCA\ChoreStrike\Db\Occurrence();
			});

		$this->service->updateDueDate(1, '2030-05-10', $this->createHouseUser());

		// The HOUSE-LOCAL day label is what the agreement moves (the saved
		// zone resolves the window boundaries server-side).
		$this->assertSame('2030-05-10', $captured);
	}

	/**
	 * UpdateDueDate_UnparseableInput_ThrowsValidation (RF-058-01)
	 */
	public function testUpdateDueDate_UnparseableInput_ThrowsValidation(): void {
		$roomTask = new RoomTask();
		$roomTask->setId(1);
		$roomTask->setTaskId(1);

		$this->stubTaskDefinition($this->createTask());
		$this->roomTaskMapper->method('find')->willReturn($roomTask);
		$this->taskAssigneeMapper->method('findUserIdsByRoomTask')->willReturn([]);

		$this->occurrenceService->expects($this->never())->method('amendOccurrenceDateUnderLock');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Invalid nextDue date format');
		$this->service->updateDueDate(1, 'not-a-date', $this->createHouseUser());
	}

	// CS-PLAN-056 (CR-053-06): lifecycle mutations enforce the same visibility
	// matrix as the completion path (updateDueDate since CS-PLAN-064 WP5 —
	// uncompleteTask/reactivateTask/completeTask moved to the
	// CompletionService undo/complete aggregates). Since CS-PLAN-061 the
	// matrix runs through TaskAccessPolicy and a denial is the 404
	// anti-enumeration NotFoundException (D2/D3).

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

		$this->occurrenceService->expects($this->never())->method('amendOccurrenceDateUnderLock');

		$this->expectException(NotFoundException::class);
		$this->service->updateDueDate(1, '+1 day', $this->createHouseUser('memberUser'));
	}

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
			->method('deleteByRoomTask');

		$this->taskAssigneeMapper
			->expects($this->exactly(3))
			->method('addAssignee')
			->willReturnCallback(static fn (int $roomTaskId) => new TaskAssignee());

		$this->roomTaskMapper
			->method('lockForUpdate')
			->willReturnCallback(static function (int $id): RoomTask {
				$rt = new RoomTask();
				$rt->setId($id);
				return $rt;
			});

		$this->houseUserMapper
			->method('findByHouseAndUser')
			->willReturn($member);

		$this->taskAssigneeMapper->method('findByRoomTask')->willReturn([]);

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

		$this->roomTaskMapper
			->method('lockForUpdate')
			->willReturnCallback(static function (int $id): RoomTask {
				$rt = new RoomTask();
				$rt->setId($id);
				return $rt;
			});

		$this->houseUserMapper->method('findByHouseAndUser')->willReturn($member);

		$this->taskAssigneeMapper
			->expects($this->once())
			->method('deleteByRoomTask')
			->with(2);

		$this->taskAssigneeMapper
			->expects($this->once())
			->method('addAssignee')
			->with(2, $userId, 'assigner')
			->willReturn(new TaskAssignee());

		$this->taskAssigneeMapper->method('findByRoomTask')->willReturn([]);

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
		$this->taskAssigneeMapper->expects($this->never())->method('deleteByRoomTask');

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

		$this->roomTaskMapper
			->method('lockForUpdate')
			->willReturnCallback(static function (int $id): RoomTask {
				$rt = new RoomTask();
				$rt->setId($id);
				return $rt;
			});

		$this->houseUserMapper->method('findByHouseAndUser')->willReturn($member);

		$this->taskAssigneeMapper
			->method('deleteByRoomTask')
			->willReturnCallback(function (int $roomTaskId) {
				if ($roomTaskId === 1) {
					throw new \RuntimeException('write failed');
				}
			});

		$this->taskAssigneeMapper->method('addAssignee')->willReturn(new TaskAssignee());
		$this->taskAssigneeMapper->method('findByRoomTask')->willReturn([]);

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

		$this->taskAssigneeMapper->expects($this->never())->method('deleteByRoomTask');

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
		$this->taskAssigneeMapper->expects($this->never())->method('deleteByRoomTask');

		$this->assertSame([], $this->service->bulkAssignTasks([], $userId, $houseId, $this->createHouseUser('assigner', 'owner')));
	}

	/**
	 * SetAssignees_ValidMembers_ReplacesAssignees
	 *
	 * The 4th parameter is the acting HouseUser: the gates run in order
	 * (ownership → visibility → actor policy → membership) and the write
	 * runs under the room-task row lock (CS-PLAN-063 §3.4), attributed to
	 * the actor's Nextcloud user id.
	 */
	public function testSetAssignees_ValidMembers_ReplacesAssignees(): void {
		$roomTaskId = 1;
		$houseId = 1;
		$taskId = 9;
		$actor = $this->createHouseUser('assigner');

		$roomTask = new RoomTask();
		$roomTask->setId($roomTaskId);
		$roomTask->setRoomId(5);
		$roomTask->setTaskId($taskId);

		$member1 = new HouseUser();
		$member1->setNextcloudUserId('user-a');
		$member2 = new HouseUser();
		$member2->setNextcloudUserId('user-b');

		$assignee = new TaskAssignee();
		$assignee->setRoomTaskId($roomTaskId);
		$assignee->setUserId('user-a');

		$this->roomTaskMapper->method('find')->with($roomTaskId)->willReturn($roomTask);
		$this->stubTaskDefinition($this->createTask());
		$this->taskAssigneeMapper->method('findUserIdsByRoomTask')->willReturn([]);
		$this->authorization->expects($this->once())->method('assertCanManageTasks')->with($actor);
		$this->houseUserMapper->method('findByHouseId')->with($houseId)->willReturn([$member1, $member2]);

		// Locked write path (CS-PLAN-063 §3.4): lock → under-lock membership
		// revalidation → atomic delete-then-insert.
		$this->roomTaskMapper
			->expects($this->once())
			->method('lockForUpdate')
			->with($roomTaskId)
			->willReturn($roomTask);
		$this->houseUserMapper
			->expects($this->atMost(3))
			->method('findByHouseAndUser')
			->willReturnCallback(static fn (int $houseId, string $userId): HouseUser => ($userId === 'user-a' ? $member1 : $member2));
		$this->taskAssigneeMapper->expects($this->once())->method('deleteByRoomTask')->with($roomTaskId);
		$this->taskAssigneeMapper
			->expects($this->exactly(2))
			->method('addAssignee')
			->willReturnCallback(static function (int $id, string $userId): TaskAssignee {
				$assignee = new TaskAssignee();
				$assignee->setUserId($userId);
				return $assignee;
			});
		$this->taskAssigneeMapper->method('findByRoomTask')->with($roomTaskId)->willReturn([$assignee]);

		$result = $this->service->setAssignees($roomTaskId, ['user-a', 'user-b'], $houseId, $actor);

		$this->assertCount(1, $result);
		$this->assertSame('user-a', $result[0]->getUserId());
	}

	/**
	 * SetAssignees_UnderLockMembershipMiss_ThrowsWithZeroWrites
	 *
	 * CS-PLAN-063 §3.4: a membership removed between the pre-check and the
	 * locked write revalidates under the room-task row lock — the miss
	 * throws (→ 400) before any assignee write happens.
	 */
	public function testSetAssignees_UnderLockMembershipMiss_ThrowsWithZeroWrites(): void {
		$roomTask = new RoomTask();
		$roomTask->setId(1);
		$roomTask->setRoomId(5);
		$roomTask->setTaskId(9);

		$member = new HouseUser();
		$member->setNextcloudUserId('user-a');

		$this->roomTaskMapper->method('find')->willReturn($roomTask);
		$this->stubTaskDefinition($this->createTask());
		$this->taskAssigneeMapper->method('findUserIdsByRoomTask')->willReturn([]);
		$this->houseUserMapper->method('findByHouseId')->willReturn([$member]);
		$this->roomTaskMapper->method('lockForUpdate')->willReturn($roomTask);

		// The target was a member at pre-check time but the under-lock
		// revalidation no longer finds the membership.
		$this->houseUserMapper->method('findByHouseAndUser')->willReturn(null);

		$this->taskAssigneeMapper->expects($this->never())->method('deleteByRoomTask');
		$this->taskAssigneeMapper->expects($this->never())->method('addAssignee');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('User is not a member of this house: %1$s');

		$this->service->setAssignees(1, ['user-a'], 1, $this->createHouseUser('assigner'));
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
		$this->taskAssigneeMapper->expects($this->never())->method('deleteByRoomTask');

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
		$this->roomTaskMapper->expects($this->never())->method('lockForUpdate');
		$this->taskAssigneeMapper->expects($this->never())->method('deleteByRoomTask');

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

		$this->roomTaskMapper->method('find')->willReturn($roomTask);

		// CS-PLAN-063 §3.4: the room→floor→house walk is owned by
		// RoomService; the wrapper translates its miss into the room-task 404.
		$this->roomService->method('validateRoomOwnership')
			->willThrowException(new NotFoundException('Room not found'));

		$this->authorization->expects($this->never())->method('assertCanManageTasks');
		$this->taskAssigneeMapper->expects($this->never())->method('deleteByRoomTask');

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
