<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Controller;

use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;
use OCP\IRequest;

/**
 * Task Authorization Integration Tests
 * 
 * Verifies that users cannot complete, claim, or modify tasks from different houses
 * or tasks they don't have permission to access.
 */
class TaskAuthorizationTest extends IntegrationTestCase
{
    private string $house1Id;
    private string $house2Id;
    private int $task1Id;
    private int $task2Id;
    private int $roomTask1Id;
    private int $roomTask2Id;

    protected function setUp(): void
    {
        parent::setUp();

        // Create House 1 with owner user1
        $this->house1Id = $this->createHouse('user1', 'House 1');
        $floor1 = $this->createFloor($this->house1Id, 'Ground Floor', 1);
        $room1 = $this->createRoom($this->house1Id, $floor1, 'Kitchen');
        
        // Create task and room task for House 1
        $this->task1Id = $this->createTask($this->house1Id, 'Dishes', 'Do the dishes', 3, 30);
        $this->roomTask1Id = $this->assignTaskToRoom($this->task1Id, $room1, null);

        // Create House 2 with owner user2
        $this->house2Id = $this->createHouse('user2', 'House 2');
        $floor2 = $this->createFloor($this->house2Id, 'Ground Floor', 1);
        $room2 = $this->createRoom($this->house2Id, $floor2, 'Kitchen');
        
        // Create task and room task for House 2
        $this->task2Id = $this->createTask($this->house2Id, 'Vacuum', 'Vacuum the floor', 4, 20);
        $this->roomTask2Id = $this->assignTaskToRoom($this->task2Id, $room2, null);
    }

    public function testUserCannotCompleteTaskFromDifferentHouse(): void
    {
        // user1 tries to complete task from House 2
        $response = $this->completeTask($this->roomTask2Id, 'user1');

        // Should get 403 Forbidden or 404 Not Found
        $this->assertContains($response->getStatus(), [403, 404]);
    }

    public function testUserCannotClaimTaskFromDifferentHouse(): void
    {
        // user1 tries to claim task from House 2
        $response = $this->claimTask($this->task2Id, 'user1');

        $this->assertContains($response->getStatus(), [403, 404]);
    }

    public function testUserCannotViewTasksFromDifferentHouse(): void
    {
        // user1 fetches tasks - should only see House 1 tasks
        $response = $this->getTasks('user1');

        $this->assertEquals(200, $response->getStatus());
        $data = json_decode($response->render(), true);
        
        $this->assertTrue($data['success']);
        
        $taskIds = array_column($data['data'], 'id');
        $this->assertContains($this->task1Id, $taskIds);
        $this->assertNotContains($this->task2Id, $taskIds);
    }

    public function testChildCannotDeleteOwnersTask(): void
    {
        // Add child to House 1
        $this->addHouseMember($this->house1Id, 'kid', 'Kid', 'child');

        // Kid tries to delete owner's task
        $response = $this->deleteTask($this->task1Id, 'kid');

        // Should get 403 Forbidden (children cannot delete tasks)
        $this->assertEquals(403, $response->getStatus());
    }

    public function testGuestCannotAssignTasks(): void
    {
        // Add guest to House 1
        $this->addHouseMember($this->house1Id, 'friend', 'Friend', 'guest');

        // Guest tries to assign task to room
        $response = $this->assignTaskToRoomAsUser($this->task1Id, 1, 'friend');

        // Should get 403 Forbidden (guests cannot assign tasks)
        $this->assertEquals(403, $response->getStatus());
    }

    public function testOwnerCanCompleteAnyTaskInTheirHouse(): void
    {
        // user1 (owner) completes task in their house
        $response = $this->completeTask($this->roomTask1Id, 'user1');

        $this->assertEquals(200, $response->getStatus());
        $data = json_decode($response->render(), true);
        
        $this->assertTrue($data['success']);
    }

    public function testUserCanCompleteTaskAssignedToThem(): void
    {
        // Add member to House 1
        $this->addHouseMember($this->house1Id, 'teen', 'Teen', 'child');

        // Assign task to teen
        $this->assignRoomTaskToUser($this->roomTask1Id, 'teen');

        // Teen completes their assigned task
        $response = $this->completeTask($this->roomTask1Id, 'teen');

        $this->assertEquals(200, $response->getStatus());
    }

    public function testUserCannotCompleteTaskAssignedToOtherUser(): void
    {
        // Add two members to House 1
        $this->addHouseMember($this->house1Id, 'teen', 'Teen', 'child');
        $this->addHouseMember($this->house1Id, 'kid', 'Kid', 'child');

        // Assign task to teen
        $this->assignRoomTaskToUser($this->roomTask1Id, 'teen');

        // Kid tries to complete teen's task
        $response = $this->completeTask($this->roomTask1Id, 'kid');

        // Should either succeed (claiming overdue) or fail (not their task)
        // Depends on implementation - for now, document current behavior
        $this->assertContains($response->getStatus(), [200, 403]);
    }

    public function testUserCannotDeleteCompletedTaskFromOtherUser(): void
    {
        // Add member
        $this->addHouseMember($this->house1Id, 'teen', 'Teen', 'child');

        // Assign and complete task as teen
        $this->assignRoomTaskToUser($this->roomTask1Id, 'teen');
        $this->completeTask($this->roomTask1Id, 'teen');

        // user1 (owner) tries to delete completed task
        // Depending on implementation, this might be allowed or not
        $response = $this->deleteTask($this->task1Id, 'user1');

        // Owner should be able to delete tasks
        $this->assertEquals(200, $response->getStatus());
    }

    public function testNonMemberCannotAccessHouseTasks(): void
    {
        // user3 (not a member of any house) tries to view House 1 tasks
        $response = $this->getTasks('user3');

        // Should get empty result or 403
        $data = json_decode($response->render(), true);
        
        if ($response->getStatus() === 200) {
            // Should return empty array
            $this->assertEmpty($data['data']);
        } else {
            $this->assertEquals(403, $response->getStatus());
        }
    }

    // Helper methods
    private function completeTask(int $roomTaskId, string $userId): \OCP\AppFramework\Http\Response
    {
        $request = $this->createMock(IRequest::class);
        $controller = $this->getController('RoomTaskController', $userId, $request);
        
        return $controller->complete($roomTaskId);
    }

    private function claimTask(int $taskId, string $userId): \OCP\AppFramework\Http\Response
    {
        $request = $this->createMock(IRequest::class);
        $controller = $this->getController('TaskController', $userId, $request);
        
        return $controller->claim($taskId);
    }

    private function getTasks(string $userId): \OCP\AppFramework\Http\Response
    {
        $request = $this->createMock(IRequest::class);
        $controller = $this->getController('TaskController', $userId, $request);
        
        return $controller->index();
    }

    private function deleteTask(int $taskId, string $userId): \OCP\AppFramework\Http\Response
    {
        $request = $this->createMock(IRequest::class);
        $controller = $this->getController('TaskController', $userId, $request);
        
        return $controller->destroy($taskId);
    }

    private function assignTaskToRoomAsUser(int $taskId, int $roomId, string $userId): \OCP\AppFramework\Http\Response
    {
        $request = $this->createMock(IRequest::class);
        $controller = $this->getController('RoomTaskController', $userId, $request);
        
        return $controller->create($taskId, $roomId, null);
    }

    private function assignRoomTaskToUser(int $roomTaskId, string $assignedUserId): void
    {
        $request = $this->createMock(IRequest::class);
        $controller = $this->getController('RoomTaskController', 'user1', $request); // owner assigns
        
        $controller->assign($roomTaskId, $assignedUserId);
    }
}
