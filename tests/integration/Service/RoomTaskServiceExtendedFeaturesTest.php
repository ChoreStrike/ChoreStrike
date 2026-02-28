<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\tests\integration\Service;

use OCA\ChoreStrike\Db\Floor;
use OCA\ChoreStrike\Db\FloorMapper;
use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\Room;
use OCA\ChoreStrike\Db\RoomMapper;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Service\RoomTaskService;
use OCP\AppFramework\Http\NotFoundException;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for RoomTaskService with Group Assignment, Teamwork, and Claiming
 *
 * @covers \OCA\ChoreStrike\Service\RoomTaskService
 */
class RoomTaskServiceExtendedFeaturesTest extends TestCase {

	private RoomTaskService $service;
	private RoomTaskMapper $roomTaskMapper;
	private TaskMapper $taskMapper;
	private RoomMapper $roomMapper;
	private FloorMapper $floorMapper;
	private HouseMapper $houseMapper;
	private int $testHouseId;
	private int $testFloorId;
	private int $testRoomId;
	private int $testTaskId;

	protected function setUp(): void {
		parent::setUp();

		// Initialize mappers with mocked database connection
		// In a real test environment, these would use the actual database
		$this->roomTaskMapper = $this->createMock(RoomTaskMapper::class);
		$this->taskMapper = $this->createMock(TaskMapper::class);
		$this->roomMapper = $this->createMock(RoomMapper::class);
		$this->floorMapper = $this->createMock(FloorMapper::class);
		$this->houseMapper = $this->createMock(HouseMapper::class);

		$this->service = new RoomTaskService(
			$this->roomTaskMapper,
			$this->taskMapper,
			$this->roomMapper,
			$this->floorMapper,
			$this->getMockBuilder(\OCA\ChoreStrike\Service\RoomService::class)
				->disableOriginalConstructor()
				->getMock()
		);

		// Set up test IDs
		$this->testHouseId = 1;
		$this->testFloorId = 1;
		$this->testRoomId = 1;
		$this->testTaskId = 1;
	}

	/**
	 * Test assigning a task to a group
	 *
	 * @test
	 */
	public function testAssignToGroup(): void {
		$roomTaskId = 1;
		$groupId = 'test-group-123';

		$roomTask = new RoomTask();
		$roomTask->setId($roomTaskId);
		$roomTask->setRoomId($this->testRoomId);
		$roomTask->setTaskId($this->testTaskId);
		$roomTask->setAssignmentType('user');

		$updatedRoomTask = new RoomTask();
		$updatedRoomTask->setId($roomTaskId);
		$updatedRoomTask->setRoomId($this->testRoomId);
		$updatedRoomTask->setTaskId($this->testTaskId);
		$updatedRoomTask->setAssignmentType('group');
		$updatedRoomTask->setAssignedToGroup($groupId);

		$this->roomTaskMapper
			->expects($this->exactly(2))
			->method('find')
			->with($roomTaskId)
			->willReturnOnConsecutiveCalls($roomTask, $updatedRoomTask);

		$this->roomTaskMapper
			->expects($this->once())
			->method('assignToGroup')
			->with($roomTaskId, $groupId);

		$result = $this->service->assignToGroup($roomTaskId, $groupId);

		$this->assertInstanceOf(RoomTask::class, $result);
		$this->assertEquals('group', $result->getAssignmentType());
		$this->assertEquals($groupId, $result->getAssignedToGroup());
	}

	/**
	 * Test claiming a group-assigned task for a user
	 *
	 * @test
	 */
	public function testClaimForUser(): void {
		$roomTaskId = 1;
		$userId = 'user-456';
		$groupId = 'test-group-123';

		$roomTask = new RoomTask();
		$roomTask->setId($roomTaskId);
		$roomTask->setRoomId($this->testRoomId);
		$roomTask->setAssignmentType('group');
		$roomTask->setAssignedToGroup($groupId);

		$claimedRoomTask = new RoomTask();
		$claimedRoomTask->setId($roomTaskId);
		$claimedRoomTask->setRoomId($this->testRoomId);
		$claimedRoomTask->setAssignmentType('group');
		$claimedRoomTask->setAssignedToGroup($groupId);
		$claimedRoomTask->setClaimedByUser($userId);

		$this->roomTaskMapper
			->expects($this->exactly(2))
			->method('find')
			->with($roomTaskId)
			->willReturnOnConsecutiveCalls($roomTask, $claimedRoomTask);

		$this->roomTaskMapper
			->expects($this->once())
			->method('claimForUser')
			->with($roomTaskId, $userId);

		$result = $this->service->claimForUser($roomTaskId, $userId);

		$this->assertInstanceOf(RoomTask::class, $result);
		$this->assertEquals($userId, $result->getClaimedByUser());
	}

	/**
	 * Test claiming a task not assigned to a group throws exception
	 *
	 * @test
	 */
	public function testClaimForUserThrowsExceptionWhenNotGroupAssigned(): void {
		$roomTaskId = 1;
		$userId = 'user-456';

		$roomTask = new RoomTask();
		$roomTask->setId($roomTaskId);
		$roomTask->setAssignmentType('user');
		$roomTask->setAssignedToUserId('other-user');

		$this->roomTaskMapper
			->expects($this->once())
			->method('find')
			->with($roomTaskId)
			->willReturn($roomTask);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Task is not assigned to a group');

		$this->service->claimForUser($roomTaskId, $userId);
	}

	/**
	 * Test unclaiming a task
	 *
	 * @test
	 */
	public function testUnclaim(): void {
		$roomTaskId = 1;

		$roomTask = new RoomTask();
		$roomTask->setId($roomTaskId);
		$roomTask->setClaimedByUser('user-456');

		$unclaimedRoomTask = new RoomTask();
		$unclaimedRoomTask->setId($roomTaskId);
		$unclaimedRoomTask->setClaimedByUser(null);

		$this->roomTaskMapper
			->expects($this->exactly(2))
			->method('find')
			->with($roomTaskId)
			->willReturnOnConsecutiveCalls($roomTask, $unclaimedRoomTask);

		$this->roomTaskMapper
			->expects($this->once())
			->method('unclaim')
			->with($roomTaskId);

		$result = $this->service->unclaim($roomTaskId);

		$this->assertInstanceOf(RoomTask::class, $result);
		$this->assertNull($result->getClaimedByUser());
	}

	/**
	 * Test getting tasks assigned to a group
	 *
	 * @test
	 */
	public function testGetGroupTasks(): void {
		$groupId = 'test-group-123';

		$task1 = new RoomTask();
		$task1->setId(1);
		$task1->setAssignedToGroup($groupId);

		$task2 = new RoomTask();
		$task2->setId(2);
		$task2->setAssignedToGroup($groupId);

		$expectedTasks = [$task1, $task2];

		$this->roomTaskMapper
			->expects($this->once())
			->method('findByGroupId')
			->with($groupId, true)
			->willReturn($expectedTasks);

		$result = $this->service->getGroupTasks($groupId);

		$this->assertCount(2, $result);
		$this->assertEquals($groupId, $result[0]->getAssignedToGroup());
	}

	/**
	 * Test getting tasks visible to a user
	 *
	 * @test
	 */
	public function testGetTasksVisibleToUser(): void {
		$userId = 'user-456';
		$groupIds = ['group-1', 'group-2'];

		$userTask = new RoomTask();
		$userTask->setId(1);
		$userTask->setAssignedToUserId($userId);

		$groupTask = new RoomTask();
		$groupTask->setId(2);
		$groupTask->setAssignedToGroup('group-1');
		$groupTask->setAssignmentType('group');

		$expectedTasks = [$userTask, $groupTask];

		$this->roomTaskMapper
			->expects($this->once())
			->method('findVisibleToUser')
			->with($userId, $groupIds, true)
			->willReturn($expectedTasks);

		$result = $this->service->getTasksVisibleToUser($userId, $groupIds);

		$this->assertCount(2, $result);
	}

	/**
	 * Test claiming an overdue task
	 *
	 * @test
	 */
	public function testClaimOverdueTask(): void {
		$roomTaskId = 1;
		$newUserId = 'user-789';
		$originalAssignee = 'user-456';

		// Create overdue task (nextDue in the past)
		$roomTask = new RoomTask();
		$roomTask->setId($roomTaskId);
		$roomTask->setRoomId($this->testRoomId);
		$roomTask->setTaskId($this->testTaskId);
		$roomTask->setAssignedToUserId($originalAssignee);
		$roomTask->setNextDue(new \DateTime('-3 days'));
		$roomTask->setIsActive(true);

		$this->roomTaskMapper
			->expects($this->once())
			->method('find')
			->with($roomTaskId)
			->willReturn($roomTask);

		$this->roomTaskMapper
			->expects($this->once())
			->method('assignToUser')
			->with($roomTaskId, $newUserId);

		$result = $this->service->claimOverdueTask($roomTaskId, $newUserId);

		$this->assertTrue($result['success']);
		$this->assertEquals(3, $result['daysOverdue']);
		$this->assertEquals($originalAssignee, $result['originalAssignee']);
		$this->assertEquals($newUserId, $result['claimedBy']);
		$this->assertEquals(15, $result['bonusSparks']); // 5 sparks per day, max 50
	}

	/**
	 * Test claiming a non-overdue task throws exception
	 *
	 * @test
	 */
	public function testClaimOverdueTaskThrowsExceptionWhenNotOverdue(): void {
		$roomTaskId = 1;
		$userId = 'user-789';

		$roomTask = new RoomTask();
		$roomTask->setId($roomTaskId);
		$roomTask->setNextDue(new \DateTime('+3 days')); // Future date
		$roomTask->setIsActive(true);

		$this->roomTaskMapper
			->expects($this->once())
			->method('find')
			->with($roomTaskId)
			->willReturn($roomTask);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Task is not overdue and cannot be claimed');

		$this->service->claimOverdueTask($roomTaskId, $userId);
	}

	/**
	 * Test bonus sparks calculation with max cap
	 *
	 * @test
	 */
	public function testClaimOverdueTaskCapsBonusSparksAt50(): void {
		$roomTaskId = 1;
		$newUserId = 'user-789';
		$originalAssignee = 'user-456';

		// Create task overdue by 20 days
		$roomTask = new RoomTask();
		$roomTask->setId($roomTaskId);
		$roomTask->setRoomId($this->testRoomId);
		$roomTask->setTaskId($this->testTaskId);
		$roomTask->setAssignedToUserId($originalAssignee);
		$roomTask->setNextDue(new \DateTime('-20 days'));
		$roomTask->setIsActive(true);

		$this->roomTaskMapper
			->expects($this->once())
			->method('find')
			->with($roomTaskId)
			->willReturn($roomTask);

		$this->roomTaskMapper
			->expects($this->once())
			->method('assignToUser')
			->with($roomTaskId, $newUserId);

		$result = $this->service->claimOverdueTask($roomTaskId, $newUserId);

		$this->assertEquals(50, $result['bonusSparks']); // Capped at 50, not 100
	}

	/**
	 * Test joining a task that allows teamwork
	 *
	 * @test
	 */
	public function testJoinTaskWithTeamworkAllowed(): void {
		$roomTaskId = 1;
		$userId = 'user-456';

		$roomTask = new RoomTask();
		$roomTask->setId($roomTaskId);
		$roomTask->setTaskId($this->testTaskId);

		$task = new Task();
		$task->setId($this->testTaskId);
		$task->setAllowTeamwork(true);

		$this->roomTaskMapper
			->expects($this->once())
			->method('find')
			->with($roomTaskId)
			->willReturn($roomTask);

		$this->taskMapper
			->expects($this->once())
			->method('find')
			->with($this->testTaskId)
			->willReturn($task);

		$result = $this->service->joinTask($roomTaskId, $userId);

		$this->assertTrue($result['success']);
		$this->assertEquals($roomTaskId, $result['roomTaskId']);
		$this->assertEquals($userId, $result['userId']);
		$this->assertNotNull($result['joinedAt']);
	}

	/**
	 * Test joining a task that doesn't allow teamwork throws exception
	 *
	 * @test
	 */
	public function testJoinTaskThrowsExceptionWhenTeamworkNotAllowed(): void {
		$roomTaskId = 1;
		$userId = 'user-456';

		$roomTask = new RoomTask();
		$roomTask->setId($roomTaskId);
		$roomTask->setTaskId($this->testTaskId);

		$task = new Task();
		$task->setId($this->testTaskId);
		$task->setAllowTeamwork(false);

		$this->roomTaskMapper
			->expects($this->once())
			->method('find')
			->with($roomTaskId)
			->willReturn($roomTask);

		$this->taskMapper
			->expects($this->once())
			->method('find')
			->with($this->testTaskId)
			->willReturn($task);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('This task does not allow teamwork');

		$this->service->joinTask($roomTaskId, $userId);
	}

	/**
	 * Test leaving a task
	 *
	 * @test
	 */
	public function testLeaveTask(): void {
		$roomTaskId = 1;
		$userId = 'user-456';

		$roomTask = new RoomTask();
		$roomTask->setId($roomTaskId);

		$this->roomTaskMapper
			->expects($this->once())
			->method('find')
			->with($roomTaskId)
			->willReturn($roomTask);

		$result = $this->service->leaveTask($roomTaskId, $userId);

		$this->assertTrue($result['success']);
		$this->assertEquals($roomTaskId, $result['roomTaskId']);
		$this->assertEquals($userId, $result['userId']);
		$this->assertNotNull($result['leftAt']);
	}

	/**
	 * Test getting claimable overdue tasks
	 *
	 * @test
	 */
	public function testGetClaimableOverdueTasks(): void {
		$houseId = 1;

		$overdueTask1 = new RoomTask();
		$overdueTask1->setId(1);
		$overdueTask1->setAssignedToUserId('user-1');
		$overdueTask1->setNextDue(new \DateTime('-2 days'));
		$overdueTask1->setIsActive(true);

		$overdueTask2 = new RoomTask();
		$overdueTask2->setId(2);
		$overdueTask2->setAssignedToUserId('user-2');
		$overdueTask2->setNextDue(new \DateTime('-5 days'));
		$overdueTask2->setIsActive(true);

		// Unassigned task should be filtered out
		$unassignedTask = new RoomTask();
		$unassignedTask->setId(3);
		$unassignedTask->setAssignedToUserId(null);
		$unassignedTask->setNextDue(new \DateTime('-1 day'));
		$unassignedTask->setIsActive(true);

		$allOverdueTasks = [$overdueTask1, $overdueTask2, $unassignedTask];

		$this->roomTaskMapper
			->expects($this->once())
			->method('findOverdue')
			->willReturn($allOverdueTasks);

		$result = $this->service->getClaimableOverdueTasks($houseId);

		$this->assertCount(2, $result); // Unassigned task filtered out
		$this->assertEquals(1, $result[0]->getId());
		$this->assertEquals(2, $result[1]->getId());
	}
}
