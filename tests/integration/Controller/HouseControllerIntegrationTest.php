<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Controller;

use OCA\ChoreStrike\Controller\HouseController;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\HouseService;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for HouseController API endpoints
 */
class HouseControllerIntegrationTest extends TestCase
{
    private HouseController $controller;
    private HouseService $houseService;
    private AuthorizationService $authService;
    private IRequest $request;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->houseService = $this->createMock(HouseService::class);
        $this->authService = $this->createMock(AuthorizationService::class);

        $this->controller = new HouseController(
            $this->request,
            $this->houseService,
            $this->authService
        );
    }

    /**
     * Get_ReturnsHouseData_SuccessResponse
     */
    public function testGet_ReturnsHouseData_SuccessResponse(): void
    {
        $instanceId = 'test-instance-123';
        $houseId = 1;
        $houseData = [
            'id' => $houseId,
            'name' => 'Test House',
            'houseHealth' => 85,
            'nextcloudInstanceId' => $instanceId,
        ];

        $house = $this->createMock(\OCA\ChoreStrike\Db\House::class);
        $house->method('getId')->willReturn($houseId);
        $house->method('jsonSerialize')->willReturn($houseData);

        $this->houseService->method('getInstanceId')->willReturn($instanceId);
        $this->houseService->method('getOrCreateHouse')
            ->with($instanceId)
            ->willReturn($house);

        $this->authService->method('requireHouseMember')
            ->with($houseId)
            ->willReturn($this->createMock(\OCA\ChoreStrike\Db\HouseUser::class));

        $response = $this->controller->get();

        $this->assertEquals(200, $response->getStatus());
        $data = $response->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals($houseData, $data['data']);
    }

    /**
     * Get_ServiceThrowsException_ReturnsErrorResponse
     */
    public function testGet_ServiceThrowsException_ReturnsErrorResponse(): void
    {
        $this->houseService->method('getInstanceId')
            ->willThrowException(new \Exception('Database error'));

        $response = $this->controller->get();

        $this->assertEquals(500, $response->getStatus());
        $data = $response->getData();
        $this->assertFalse($data['success']);
        $this->assertEquals('HOUSE_ERROR', $data['error']['code']);
    }

    /**
     * Update_ValidName_ReturnsUpdatedHouse
     */
    public function testUpdate_ValidName_ReturnsUpdatedHouse(): void
    {
        $newName = 'Updated House Name';
        $instanceId = 'test-instance-123';
        $houseId = 1;

        $existingHouse = $this->createMock(\OCA\ChoreStrike\Db\House::class);
        $existingHouse->method('getId')->willReturn($houseId);

        $updatedHouseData = [
            'id' => $houseId,
            'name' => $newName,
            'houseHealth' => 85,
        ];
        $updatedHouse = $this->createMock(\OCA\ChoreStrike\Db\House::class);
        $updatedHouse->method('jsonSerialize')->willReturn($updatedHouseData);

        $this->houseService->method('getInstanceId')->willReturn($instanceId);
        $this->houseService->method('getOrCreateHouse')
            ->with($instanceId)
            ->willReturn($existingHouse);
        $this->houseService->method('updateHouseName')
            ->with($houseId, $newName)
            ->willReturn($updatedHouse);

        $this->authService->method('requireHouseOwner')
            ->with($houseId)
            ->willReturn($this->createMock(\OCA\ChoreStrike\Db\HouseUser::class));

        $response = $this->controller->update($newName);

        $this->assertEquals(200, $response->getStatus());
        $data = $response->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals($newName, $data['data']['name']);
    }

    /**
     * Update_EmptyName_ReturnsValidationError
     */
    public function testUpdate_EmptyName_ReturnsValidationError(): void
    {
        $response = $this->controller->update('');

        $this->assertEquals(400, $response->getStatus());
        $data = $response->getData();
        $this->assertFalse($data['success']);
        $this->assertEquals('VALIDATION_ERROR', $data['error']['code']);
    }

    /**
     * Update_WhitespaceName_ReturnsValidationError
     */
    public function testUpdate_WhitespaceName_ReturnsValidationError(): void
    {
        $response = $this->controller->update('   ');

        $this->assertEquals(400, $response->getStatus());
        $data = $response->getData();
        $this->assertFalse($data['success']);
        $this->assertEquals('VALIDATION_ERROR', $data['error']['code']);
    }

    /**
     * GetStats_ReturnsHouseStats_SuccessResponse
     */
    public function testGetStats_ReturnsHouseStats_SuccessResponse(): void
    {
        $instanceId = 'test-instance-123';
        $houseId = 1;
        $stats = [
            'totalRooms' => 5,
            'totalFloors' => 2,
            'averageCleanliness' => 78.5,
            'totalTasks' => 12,
            'completedTasksThisWeek' => 8,
        ];

        $house = $this->createMock(\OCA\ChoreStrike\Db\House::class);
        $house->method('getId')->willReturn($houseId);

        $this->houseService->method('getInstanceId')->willReturn($instanceId);
        $this->houseService->method('getOrCreateHouse')->willReturn($house);
        $this->houseService->method('getHouseStats')
            ->with($houseId)
            ->willReturn(['house' => $house, 'stats' => $stats]);

        $this->authService->method('requireHouseMember')
            ->with($houseId)
            ->willReturn($this->createMock(\OCA\ChoreStrike\Db\HouseUser::class));

        $response = $this->controller->stats();

        $this->assertEquals(200, $response->getStatus());
        $data = $response->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals($houseId, $data['data']['house']['id']);
        $this->assertEquals($stats, $data['data']['stats']);
    }

    /**
     * GetHealth_ReturnsHouseHealth_SuccessResponse
     */
    public function testGetHealth_ReturnsHouseHealth_SuccessResponse(): void
    {
        $instanceId = 'test-instance-123';
        $houseId = 1;
        $health = [
            'health' => 82,
            'status' => 'good',
            'details' => [
                'cleanliness' => 85,
                'taskCompletion' => 90,
                'freshness' => 70,
            ],
        ];

        $house = $this->createMock(\OCA\ChoreStrike\Db\House::class);
        $house->method('getId')->willReturn($houseId);

        $this->houseService->method('getInstanceId')->willReturn($instanceId);
        $this->houseService->method('getOrCreateHouse')->willReturn($house);
        $this->houseService->method('calculateHouseHealth')
            ->with($houseId)
            ->willReturn($health);

        $this->authService->method('requireHouseMember')
            ->with($houseId)
            ->willReturn($this->createMock(\OCA\ChoreStrike\Db\HouseUser::class));

        $response = $this->controller->health();

        $this->assertEquals(200, $response->getStatus());
        $data = $response->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals($health, $data['data']);
    }
}
