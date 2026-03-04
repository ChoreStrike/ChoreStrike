<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Controller;

use OCA\ChoreStrike\Controller\SchedulingController;
use OCA\ChoreStrike\Db\SchedulingPreferencesMapper;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Entity\SchedulingPreferences;
use OCA\ChoreStrike\Entity\House;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SchedulingController
 */
class SchedulingControllerTest extends TestCase
{
    private SchedulingController $controller;
    private $preferencesMapper;
    private $houseService;
    private $authService;
    private $request;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->preferencesMapper = $this->createMock(SchedulingPreferencesMapper::class);
        $this->houseService = $this->createMock(HouseService::class);
        $this->authService = $this->createMock(AuthorizationService::class);

        $this->controller = new SchedulingController(
            $this->request,
            $this->preferencesMapper,
            $this->houseService,
            $this->authService
        );
    }

    /**
     * Test GET /scheduling/preferences returns preferences
     */
    public function testGetPreferences_ReturnsPreferences(): void
    {
        $house = new House();
        $house->setId(1);

        $preferences = new SchedulingPreferences();
        $preferences->setId(1);
        $preferences->setUserId('user1');
        $preferences->setHouseId(1);
        $preferences->setWeekdayAvailability(json_encode(['monday' => true, 'tuesday' => false]));
        $preferences->setEnergyBudget(json_encode(['monday' => 10, 'tuesday' => 15]));

        $this->houseService->expects($this->once())
            ->method('getInstanceId')
            ->willReturn('instance-123');

        $this->houseService->expects($this->once())
            ->method('getOrCreateHouse')
            ->willReturn($house);

        $this->authService->expects($this->once())
            ->method('requireHouseMember')
            ->with(1);

        $this->preferencesMapper->expects($this->once())
            ->method('findByUserAndHouse')
            ->with('user1', 1)
            ->willReturn($preferences);

        $response = $this->controller->getPreferences('user1');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());

        $data = $response->getData();
        $this->assertTrue($data['success']);
    }

    /**
     * Test POST /scheduling/preferences validates empty userId
     */
    public function testUpdatePreferences_ValidatesEmptyUserId(): void
    {
        $response = $this->controller->updatePreferences('', []);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(400, $response->getStatus());

        $data = $response->getData();
        $this->assertFalse($data['success']);
        $this->assertEquals('VALIDATION_ERROR', $data['error']['code']);
    }

    /**
     * Test GET /scheduling/members returns member availability
     */
    public function testGetMemberAvailability_ReturnsAvailability(): void
    {
        $house = new House();
        $house->setId(1);

        $pref1 = new SchedulingPreferences();
        $pref1->setUserId('user1');
        $pref1->setHouseId(1);

        $pref2 = new SchedulingPreferences();
        $pref2->setUserId('user2');
        $pref2->setHouseId(1);

        $this->houseService->expects($this->once())
            ->method('getInstanceId')
            ->willReturn('instance-123');

        $this->houseService->expects($this->once())
            ->method('getOrCreateHouse')
            ->willReturn($house);

        $this->authService->expects($this->once())
            ->method('requireHouseOwner')
            ->with(1);

        $this->preferencesMapper->expects($this->once())
            ->method('findByHouseId')
            ->with(1)
            ->willReturn([$pref1, $pref2]);

        $response = $this->controller->getMemberAvailability();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());

        $data = $response->getData();
        $this->assertTrue($data['success']);
        $this->assertCount(2, $data['data']);
    }
}
