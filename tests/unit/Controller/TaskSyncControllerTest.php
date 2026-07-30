<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Controller;

use OCA\ChoreStrike\Controller\TaskSyncController;
use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\TaskSyncService;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for TaskSyncController
 */
class TaskSyncControllerTest extends TestCase
{
    private TaskSyncController $controller;
    private TaskSyncService $taskSyncService;
    private HouseService $houseService;
    private AuthorizationService $authService;
    private IRequest $request;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->taskSyncService = $this->createMock(TaskSyncService::class);
        $this->houseService = $this->createMock(HouseService::class);
        $this->authService = $this->createMock(AuthorizationService::class);

        $this->controller = new TaskSyncController(
            $this->request,
            $this->taskSyncService,
            $this->houseService,
            $this->authService
        );
    }

    private function expectHouseContext(int $houseId = 1): void
    {
        $house = $this->createMock(House::class);
        $house->method('getId')->willReturn($houseId);

        $houseUser = new HouseUser();
        $houseUser->setNextcloudUserId('caller');
        $houseUser->setRole('member');

        $this->houseService->method('getInstanceId')->willReturn('inst-1');
        $this->houseService->method('getOrCreateHouse')->willReturn($house);
        $this->authService->method('ensureHouseMember')->willReturn($houseUser);
    }

    public function testComplete_Success_ReturnsResult(): void
    {
        $this->expectHouseContext();
        $this->authService->method('getCurrentUserId')->willReturn('user1');

        $result = ['completedTaskId' => 5, 'synced' => true];
        $this->taskSyncService->expects($this->once())
            ->method('completeTaskForHouse')
            ->with(10, 'user1', 1, null)
            ->willReturn($result);

        $response = $this->controller->complete(10);

        $data = $response->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals($result, $data['data']);
    }

    public function testComplete_WithNextcloudTaskId_PassesIt(): void
    {
        $this->expectHouseContext();
        $this->authService->method('getCurrentUserId')->willReturn('user1');

        $this->taskSyncService->expects($this->once())
            ->method('completeTaskForHouse')
            ->with(10, 'user1', 1, 99)
            ->willReturn(['ok' => true]);

        $response = $this->controller->complete(10, 99);

        $this->assertTrue($response->getData()['success']);
    }

    public function testComplete_NotAuthenticated_Returns500(): void
    {
        $this->expectHouseContext();
        $this->authService->method('getCurrentUserId')->willReturn(null);

        $response = $this->controller->complete(10);

        $this->assertEquals(500, $response->getStatus());
        $this->assertFalse($response->getData()['success']);
    }

    public function testComplete_Forbidden_Returns403(): void
    {
        $house = $this->createMock(House::class);
        $house->method('getId')->willReturn(1);
        $this->houseService->method('getInstanceId')->willReturn('inst-1');
        $this->houseService->method('getOrCreateHouse')->willReturn($house);
        $this->authService->method('getCurrentUserId')->willReturn('user1');
        $this->authService->method('ensureHouseMember')
            ->willThrowException(new ForbiddenException('Not a member'));

        $response = $this->controller->complete(10);

        $this->assertEquals(403, $response->getStatus());
        $this->assertEquals('FORBIDDEN', $response->getData()['error']['code']);
    }

    public function testSyncFromNextcloud_Success_ReturnsResult(): void
    {
        $this->expectHouseContext();

        $result = ['synced' => true];
        $this->taskSyncService->method('syncCompletionFromNextcloudForHouse')
            ->with(50, '2026-07-30T10:00:00', 1, null)
            ->willReturn($result);

        $response = $this->controller->syncFromNextcloud(50, '2026-07-30T10:00:00');

        $this->assertTrue($response->getData()['success']);
    }

    public function testSyncFromNextcloud_NullResult_Returns404(): void
    {
        $this->expectHouseContext();

        $this->taskSyncService->method('syncCompletionFromNextcloudForHouse')
            ->willReturn(null);

        $response = $this->controller->syncFromNextcloud(50, '2026-07-30T10:00:00');

        $this->assertEquals(404, $response->getStatus());
        $this->assertEquals('TASK_NOT_FOUND', $response->getData()['error']['code']);
    }

    public function testGetReminders_ReturnsMappedReminders(): void
    {
        $this->expectHouseContext();

        $roomTask = new RoomTask();
        $roomTask->setId(10);
        $this->taskSyncService->method('getRoomTaskForHouse')
            ->with(10, 1)
            ->willReturn($roomTask);

        $reminders = [['type' => 'email', 'minutes' => 30]];
        $this->taskSyncService->method('mapRemindersToNextcloud')
            ->with($roomTask)
            ->willReturn($reminders);

        $response = $this->controller->getReminders(10);

        $this->assertTrue($response->getData()['success']);
        $this->assertEquals($reminders, $response->getData()['data']);
    }

    public function testGetPendingSyncs_ReturnsPendingList(): void
    {
        $this->expectHouseContext();

        $pending = [['id' => 1], ['id' => 2]];
        $this->taskSyncService->method('getPendingSyncsForHouse')
            ->with(1, 50)
            ->willReturn($pending);

        $response = $this->controller->getPendingSyncs();

        $this->assertTrue($response->getData()['success']);
        $this->assertCount(2, $response->getData()['data']);
    }

    public function testGetPendingSyncs_CustomLimit(): void
    {
        $this->expectHouseContext();

        $this->taskSyncService->expects($this->once())
            ->method('getPendingSyncsForHouse')
            ->with(1, 10)
            ->willReturn([]);

        $response = $this->controller->getPendingSyncs(10);

        $this->assertTrue($response->getData()['success']);
    }

    public function testMarkAsSynced_Success(): void
    {
        $this->expectHouseContext();

        $this->taskSyncService->expects($this->once())
            ->method('markAsSyncedForHouse')
            ->with(5, 1);

        $response = $this->controller->markAsSynced(5);

        $this->assertTrue($response->getData()['success']);
    }

    public function testLinkTask_Success(): void
    {
        $this->expectHouseContext();

        $this->taskSyncService->expects($this->once())
            ->method('linkTaskForHouse')
            ->with(10, 99, 1);

        $response = $this->controller->linkTask(10, 99);

        $this->assertTrue($response->getData()['success']);
    }

    public function testLinkTask_Forbidden_Returns403(): void
    {
        $house = $this->createMock(House::class);
        $house->method('getId')->willReturn(1);
        $this->houseService->method('getInstanceId')->willReturn('inst-1');
        $this->houseService->method('getOrCreateHouse')->willReturn($house);
        $this->authService->method('ensureHouseMember')
            ->willThrowException(new ForbiddenException('Denied'));

        $response = $this->controller->linkTask(10, 99);

        $this->assertEquals(403, $response->getStatus());
    }

    public function testComplete_ServiceException_Returns500SafeMessage(): void
    {
        $this->expectHouseContext();
        $this->authService->method('getCurrentUserId')->willReturn('user1');
        $this->taskSyncService->method('completeTaskForHouse')
            ->willThrowException(new \RuntimeException('SQL error: table missing'));

        $response = $this->controller->complete(10);

        $this->assertEquals(500, $response->getStatus());
        $data = $response->getData();
        $this->assertStringNotContainsString('SQL error', $data['error']['message']);
    }
}
