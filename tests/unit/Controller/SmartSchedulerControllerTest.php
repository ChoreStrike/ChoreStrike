<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Controller;

use OCA\ChoreStrike\Controller\SmartSchedulerController;
use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\SchedulingService;
use OCA\ChoreStrike\Service\SmartSchedulerService;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Behavior unit tests for SmartSchedulerController
 */
class SmartSchedulerControllerTest extends TestCase
{
    private SmartSchedulerController $controller;
    private SmartSchedulerService $smartSchedulerService;
    private SchedulingService $schedulingService;
    private HouseService $houseService;
    private IUserSession $userSession;
    private IL10N $l10n;
    private IRequest $request;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->smartSchedulerService = $this->createMock(SmartSchedulerService::class);
        $this->schedulingService = $this->createMock(SchedulingService::class);
        $this->houseService = $this->createMock(HouseService::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->l10n = $this->createMock(IL10N::class);
        $this->l10n->method('t')->willReturnCallback(fn ($text, $params = []) => $text);

        $this->controller = new SmartSchedulerController(
            $this->request,
            $this->smartSchedulerService,
            $this->schedulingService,
            $this->houseService,
            $this->userSession,
            $this->l10n
        );
    }

    private function expectHouse(int $houseId = 1): void
    {
        $house = $this->createMock(House::class);
        $house->method('getId')->willReturn($houseId);
        $this->houseService->method('getInstanceId')->willReturn('inst-1');
        $this->houseService->method('getOrCreateHouse')->willReturn($house);
    }

    private function expectUser(string $uid = 'user1'): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $this->userSession->method('getUser')->willReturn($user);
    }

    public function testGetSchedulePreview_ReturnsPreview(): void
    {
        $this->expectHouse();
        $preview = ['days' => [['date' => '2026-07-30', 'tasks' => []]]];

        $this->smartSchedulerService->expects($this->once())
            ->method('getSchedulePreview')
            ->with(1, 7)
            ->willReturn($preview);

        $response = $this->controller->getSchedulePreview();

        $this->assertEquals(Http::STATUS_OK, $response->getStatus());
        $data = $response->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals($preview, $data['data']);
    }

    public function testGetSchedulePreview_CustomDays(): void
    {
        $this->expectHouse();

        $this->smartSchedulerService->expects($this->once())
            ->method('getSchedulePreview')
            ->with(1, 14)
            ->willReturn([]);

        $response = $this->controller->getSchedulePreview(14);

        $this->assertEquals(Http::STATUS_OK, $response->getStatus());
    }

    public function testGetSchedulePreview_NoHouse_Returns404(): void
    {
        $this->houseService->method('getInstanceId')->willReturn('inst-1');
        $this->houseService->method('getOrCreateHouse')
            ->willThrowException(new \Exception('No house'));

        $response = $this->controller->getSchedulePreview();

        $this->assertEquals(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
    }

    public function testGenerateSchedule_ValidRange_ReturnsSchedule(): void
    {
        $this->expectHouse();
        $schedule = ['assignments' => []];

        $this->smartSchedulerService->expects($this->once())
            ->method('generateSchedule')
            ->willReturn($schedule);

        $response = $this->controller->generateSchedule('2026-07-30', '2026-08-05');

        $this->assertEquals(Http::STATUS_OK, $response->getStatus());
        $this->assertTrue($response->getData()['success']);
    }

    public function testGenerateSchedule_StartAfterEnd_Returns400(): void
    {
        $this->expectHouse();

        $response = $this->controller->generateSchedule('2026-08-05', '2026-07-30');

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }

    public function testGenerateSchedule_RangeExceeds30Days_Returns400(): void
    {
        $this->expectHouse();

        $response = $this->controller->generateSchedule('2026-07-01', '2026-08-15');

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }

    public function testGetMemberAvailability_ReturnsAvailability(): void
    {
        $this->expectHouse();
        $availability = [['userId' => 'user1', 'monday' => true]];

        $this->schedulingService->expects($this->once())
            ->method('getMemberAvailability')
            ->with(1)
            ->willReturn($availability);

        $response = $this->controller->getMemberAvailability();

        $this->assertEquals(Http::STATUS_OK, $response->getStatus());
        $this->assertEquals($availability, $response->getData()['data']);
    }

    public function testUpdateMyAvailability_ValidData_Updates(): void
    {
        $this->expectHouse();
        $this->expectUser();

        $availability = ['monday' => true, 'tuesday' => false];

        $this->schedulingService->expects($this->exactly(2))
            ->method('updateAvailabilityByNextcloudUserId');

        $response = $this->controller->updateMyAvailability($availability);

        $this->assertEquals(Http::STATUS_OK, $response->getStatus());
        $this->assertTrue($response->getData()['success']);
    }

    public function testUpdateMyAvailability_InvalidDay_Returns400(): void
    {
        $this->expectHouse();
        $this->expectUser();

        $response = $this->controller->updateMyAvailability(['funday' => true]);

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }

    public function testUpdateMyAvailability_NonBooleanValue_Returns400(): void
    {
        $this->expectHouse();
        $this->expectUser();

        $response = $this->controller->updateMyAvailability(['monday' => 'yes']);

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }

    public function testUpdateMyAvailability_AllFalse_Returns400(): void
    {
        $this->expectHouse();
        $this->expectUser();

        $response = $this->controller->updateMyAvailability(['monday' => false, 'tuesday' => false]);

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }

    public function testUpdateMyEnergyBudget_ValidData_Updates(): void
    {
        $this->expectHouse();
        $this->expectUser();

        $this->schedulingService->expects($this->once())
            ->method('updateEnergyBudgetByNextcloudUserId')
            ->with(1, 'user1', 'monday', 10);

        $response = $this->controller->updateMyEnergyBudget(['monday' => 10]);

        $this->assertEquals(Http::STATUS_OK, $response->getStatus());
    }

    public function testUpdateMyEnergyBudget_OutOfRange_Returns400(): void
    {
        $this->expectHouse();
        $this->expectUser();

        $response = $this->controller->updateMyEnergyBudget(['monday' => 25]);

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }

    public function testUpdateMyEnergyBudget_ZeroValue_Returns400(): void
    {
        $this->expectHouse();
        $this->expectUser();

        $response = $this->controller->updateMyEnergyBudget(['monday' => 0]);

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }

    public function testUpdateMyEnergyBudget_InvalidDay_Returns400(): void
    {
        $this->expectHouse();
        $this->expectUser();

        $response = $this->controller->updateMyEnergyBudget(['notaday' => 5]);

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }

    public function testApplySuggestion_Success_ReturnsTaskData(): void
    {
        $this->expectHouse();

        $task = new RoomTask();
        $task->setId(42);
        $task->setAssignedToUserId('user2');
        $task->setNextDue(new \DateTime('2026-08-01'));

        $this->smartSchedulerService->expects($this->once())
            ->method('applySuggestion')
            ->with(1, 42, 'user2', '2026-08-01')
            ->willReturn($task);

        $response = $this->controller->applySuggestion(42, 'user2', '2026-08-01');

        $this->assertEquals(Http::STATUS_OK, $response->getStatus());
        $data = $response->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals(42, $data['data']['taskId']);
        $this->assertEquals('user2', $data['data']['assignedTo']);
    }

    public function testApplySuggestion_InvalidArgument_Returns400(): void
    {
        $this->expectHouse();

        $this->smartSchedulerService->method('applySuggestion')
            ->willThrowException(new \InvalidArgumentException('Task not in house'));

        $response = $this->controller->applySuggestion(999, 'user2', '2026-08-01');

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertFalse($response->getData()['success']);
    }

    public function testApplySuggestion_ServiceException_Returns500(): void
    {
        $this->expectHouse();

        $this->smartSchedulerService->method('applySuggestion')
            ->willThrowException(new \RuntimeException('DB error'));

        $response = $this->controller->applySuggestion(42, 'user2', '2026-08-01');

        $this->assertEquals(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
    }

    public function testGetSchedulingSuggestions_ReturnsSuggestions(): void
    {
        $this->expectHouse();

        $schedule = ['assignments' => [['taskId' => 1]]];
        $this->smartSchedulerService->method('generateSchedule')->willReturn($schedule);

        $suggestions = [['taskId' => 1, 'suggestedUser' => 'user1']];
        $this->schedulingService->method('getSchedulingSuggestions')
            ->willReturn($suggestions);

        $response = $this->controller->getSchedulingSuggestions();

        $this->assertEquals(Http::STATUS_OK, $response->getStatus());
        $this->assertTrue($response->getData()['success']);
    }
}
