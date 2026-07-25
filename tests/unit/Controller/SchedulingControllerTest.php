<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Controller;

use OCA\ChoreStrike\Controller\SchedulingController;
use OCA\ChoreStrike\Service\SchedulingService;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\HouseService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SchedulingController
 */
class SchedulingControllerTest extends TestCase
{
    private SchedulingController $controller;
    private $schedulingService;
    private $houseService;
    private $authService;
    private $request;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->schedulingService = $this->createMock(SchedulingService::class);
        $this->houseService = $this->createMock(HouseService::class);
        $this->authService = $this->createMock(AuthorizationService::class);

        $this->controller = new SchedulingController(
            $this->request,
            $this->schedulingService,
            $this->houseService,
            $this->authService
        );
    }

    /**
     * Test GET /scheduling/preferences returns preferences
     */
    public function testGetPreferences_ReturnsPreferences(): void
    {
        $preferences = [
            'userId' => 'user1',
            'houseId' => 1,
            'weekdayAvailability' => ['monday' => true, 'tuesday' => false],
            'energyBudget' => ['monday' => 10, 'tuesday' => 15]
        ];

        $this->houseService->method('getInstanceId')->willReturn('instance-123');
        $house = new \OCA\ChoreStrike\Db\House();
        $house->setId(1);
        $this->houseService->method('getOrCreateHouse')->willReturn($house);
        $this->authService->method('getCurrentUserId')->willReturn('user1');
        $this->authService->method('ensureHouseMember');

        $this->schedulingService->expects($this->once())
            ->method('getUserPreferencesByNextcloudUserId')
            ->with(1, 'user1')
            ->willReturn($preferences);

        $response = $this->controller->getPreferences();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());

        $data = $response->getData();
        $this->assertTrue($data['success']);
    }

    /**
     * Test GET /scheduling/members returns member availability
     */
    public function testGetMemberAvailability_ReturnsAvailability(): void
    {
        $preferences = [
            ['userId' => 'user1', 'houseId' => 1],
            ['userId' => 'user2', 'houseId' => 1]
        ];

        $this->houseService->method('getInstanceId')->willReturn('instance-123');
        $house = new \OCA\ChoreStrike\Db\House();
        $house->setId(1);
        $this->houseService->method('getOrCreateHouse')->willReturn($house);
        $this->authService->method('requireHouseOwner');

        $this->schedulingService->expects($this->once())
            ->method('getHousePreferences')
            ->with(1)
            ->willReturn($preferences);

        $response = $this->controller->getMemberAvailability();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());

        $data = $response->getData();
        $this->assertTrue($data['success']);
        $this->assertCount(2, $data['data']);
    }
}
