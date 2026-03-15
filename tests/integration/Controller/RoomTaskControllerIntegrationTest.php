<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Controller;

use OCA\ChoreStrike\Controller\RoomTaskController;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\RoomTaskService;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for RoomTaskController API endpoints
 */
class RoomTaskControllerIntegrationTest extends TestCase
{
    private RoomTaskController $controller;
    private RoomTaskService $roomTaskService;
    private HouseService $houseService;
    private AuthorizationService $authService;
    private IRequest $request;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->roomTaskService = $this->createMock(RoomTaskService::class);
        $this->houseService = $this->createMock(HouseService::class);
        $this->authService = $this->createMock(AuthorizationService::class);

        $this->controller = new RoomTaskController(
            $this->request,
            $this->roomTaskService,
            $this->houseService,
            $this->authService
        );
    }

    /**
     * Index_ReturnsRoomTasksList_SuccessResponse
     */
    public function testIndex_ReturnsRoomTasksList_SuccessResponse(): void
    {
        $roomId = 1;
        $roomTasks = [
            $this->createMockRoomTask(1, 1, $roomId),
            $this->createMockRoomTask(2, 2, $roomId),
        ];

        $this->houseService->method('getInstanceId')->willReturn('test-instance');
        $this->houseService->method('getOrCreateHouse')->willReturn($this->createMockHouse());
        $this->authService->method('requireHouseMember')->willReturn($this->createMockHouseUser());
        $this->roomTaskService->method('getRoomTasks')
            ->with($roomId, true)
            ->willReturn($roomTasks);

        $response = $this->controller->index($roomId);

        $this->assertEquals(200, $response->getStatus());
        $data = $response->getData();
        $this->assertTrue($data['success']);
    }

    private function createMockRoomTask(int $id, int $taskId, int $roomId): \OCA\ChoreStrike\Db\RoomTask
    {
        $roomTask = new \OCA\ChoreStrike\Db\RoomTask();
        $roomTask->setId($id);
        $roomTask->setTaskId($taskId);
        $roomTask->setRoomId($roomId);
        $roomTask->setIsActive(true);
        $roomTask->setNextDue(new \DateTime());
        $roomTask->setCreatedAt(new \DateTime());
        return $roomTask;
    }

    private function createMockHouse(): \OCA\ChoreStrike\Db\House
    {
        $house = new \OCA\ChoreStrike\Db\House();
        $house->setId(1);
        $house->setNextcloudInstanceId('test-instance');
        $house->setName('Test House');
        return $house;
    }

    private function createMockHouseUser(): \OCA\ChoreStrike\Db\HouseUser
    {
        $user = new \OCA\ChoreStrike\Db\HouseUser();
        $user->setId(1);
        $user->setHouseId(1);
        $user->setNextcloudUserId('test-user');
        $user->setDisplayName('Test User');
        $user->setRole('member');
        return $user;
    }

    /**
     * Create_ValidData_ReturnsCreatedRoomTask
     */
    public function testCreate_ValidData_ReturnsCreatedRoomTask(): void
    {
        $roomId = 1;
        $taskId = 5;
        $createdRoomTask = $this->createMockRoomTask(3, $taskId, $roomId);

        $this->houseService->method('getInstanceId')->willReturn('test-instance');
        $this->houseService->method('getOrCreateHouse')->willReturn($this->createMockHouse());
        $this->authService->method('requireHouseMember')->willReturn($this->createMockHouseUser());
        $this->roomTaskService->method('createRoomTask')
            ->willReturn($createdRoomTask);

        $response = $this->controller->create($roomId, $taskId, '2026-03-15T10:00:00Z');

        $this->assertEquals(200, $response->getStatus());
        $data = $response->getData();
        $this->assertTrue($data['success']);
    }

    /**
     * Complete_ExistingRoomTask_ReturnsSuccessWithCompletion
     */
    public function testComplete_ExistingRoomTask_ReturnsSuccessWithCompletion(): void
    {
        $roomTaskId = 1;
        $completedRoomTask = $this->createMockRoomTask($roomTaskId, 1, 1);
        $completedRoomTask->setLastCompleted(new \DateTime());

        $this->houseService->method('getInstanceId')->willReturn('test-instance');
        $this->houseService->method('getOrCreateHouse')->willReturn($this->createMockHouse());
        $this->authService->method('requireHouseMember')->willReturn($this->createMockHouseUser());
        $this->roomTaskService->method('completeTask')
            ->willReturn($completedRoomTask);

        $response = $this->controller->complete($roomTaskId);

        $this->assertEquals(200, $response->getStatus());
        $data = $response->getData();
        $this->assertTrue($data['success']);
    }

    /**
     * Assign_ValidUser_ReturnsAssignedRoomTask
     */
    public function testAssign_ValidUser_ReturnsAssignedRoomTask(): void
    {
        $roomTaskId = 1;
        $userId = 'user456';
        $assignedRoomTask = $this->createMockRoomTask($roomTaskId, 1, 1);
        $assignedRoomTask->setAssignedToUserId($userId);

        $this->houseService->method('getInstanceId')->willReturn('test-instance');
        $this->houseService->method('getOrCreateHouse')->willReturn($this->createMockHouse());
        $this->authService->method('requireHouseMember')->willReturn($this->createMockHouseUser());
        $this->roomTaskService->method('assignTask')
            ->willReturn($assignedRoomTask);

        $response = $this->controller->assign($roomTaskId, $userId);

        $this->assertEquals(200, $response->getStatus());
        $data = $response->getData();
        $this->assertTrue($data['success']);
    }

    /**
     * Unassign_ExistingAssignment_ReturnsUnassignedRoomTask
     */
    public function testUnassign_ExistingAssignment_ReturnsUnassignedRoomTask(): void
    {
        $roomTaskId = 1;
        $unassignedRoomTask = $this->createMockRoomTask($roomTaskId, 1, 1);

        $this->houseService->method('getInstanceId')->willReturn('test-instance');
        $this->houseService->method('getOrCreateHouse')->willReturn($this->createMockHouse());
        $this->authService->method('requireHouseMember')->willReturn($this->createMockHouseUser());
        $this->roomTaskService->method('unassignTask')
            ->willReturn($unassignedRoomTask);

        $response = $this->controller->unassign($roomTaskId);

        $this->assertEquals(200, $response->getStatus());
        $data = $response->getData();
        $this->assertTrue($data['success']);
    }

    /**
     * Stats_ReturnsRoomTaskStatistics_SuccessResponse
     */
    public function testStats_ReturnsRoomTaskStatistics_SuccessResponse(): void
    {
        // stats() method doesn't exist in RoomTaskController - skip test
        $this->markTestSkipped('stats() method not implemented in RoomTaskController');
    }

    /**
     * Overdue_ReturnsOverdueTasks_SuccessResponse
     */
    public function testOverdue_ReturnsOverdueTasks_SuccessResponse(): void
    {
        $overdueTasks = [
            $this->createMockRoomTask(1, 1, 1),
            $this->createMockRoomTask(2, 2, 2),
        ];

        $this->houseService->method('getInstanceId')->willReturn('test-instance');
        $this->houseService->method('getOrCreateHouse')->willReturn($this->createMockHouse());
        $this->authService->method('requireHouseMember')->willReturn($this->createMockHouseUser());
        $this->roomTaskService->method('getOverdueTasks')
            ->willReturn($overdueTasks);

        $response = $this->controller->overdue();

        $this->assertEquals(200, $response->getStatus());
        $data = $response->getData();
        $this->assertTrue($data['success']);
    }
}
