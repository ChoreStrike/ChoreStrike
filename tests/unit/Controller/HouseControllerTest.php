<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Controller;

use OCA\ChoreStrike\Controller\HouseController;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Entity\House;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for HouseController
 */
class HouseControllerTest extends TestCase
{
    private HouseController $controller;
    private $houseService;
    private $authService;
    private $request;

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
     * Test GET /house returns house successfully
     */
    public function testGet_ReturnsHouseSuccessfully(): void
    {
        $house = new House();
        $house->setId(1);
        $house->setName('Test House');

        $this->houseService->expects($this->once())
            ->method('getInstanceId')
            ->willReturn('instance-123');

        $this->houseService->expects($this->once())
            ->method('getOrCreateHouse')
            ->with('instance-123')
            ->willReturn($house);

        $this->authService->expects($this->once())
            ->method('requireHouseMember')
            ->with(1);

        $response = $this->controller->get();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());

        $data = $response->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals('Test House', $data['data']['name']);
    }

    /**
     * Test GET /house handles exceptions
     */
    public function testGet_HandlesException(): void
    {
        $this->houseService->expects($this->once())
            ->method('getInstanceId')
            ->willThrowException(new \Exception('Database error'));

        $response = $this->controller->get();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(500, $response->getStatus());

        $data = $response->getData();
        $this->assertFalse($data['success']);
        $this->assertEquals('HOUSE_ERROR', $data['error']['code']);
    }

    /**
     * Test UPDATE /house validates empty name
     */
    public function testUpdate_ValidatesEmptyName(): void
    {
        $response = $this->controller->update('');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(400, $response->getStatus());

        $data = $response->getData();
        $this->assertFalse($data['success']);
        $this->assertEquals('VALIDATION_ERROR', $data['error']['code']);
    }

    /**
     * Test UPDATE /house validates whitespace only name
     */
    public function testUpdate_ValidatesWhitespaceName(): void
    {
        $response = $this->controller->update('   ');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(400, $response->getStatus());

        $data = $response->getData();
        $this->assertFalse($data['success']);
        $this->assertEquals('VALIDATION_ERROR', $data['error']['code']);
    }

    /**
     * Test UPDATE /house updates successfully
     */
    public function testUpdate_UpdatesSuccessfully(): void
    {
        $house = new House();
        $house->setId(1);
        $house->setName('Updated House');

        $this->houseService->expects($this->once())
            ->method('getInstanceId')
            ->willReturn('instance-123');

        $this->houseService->expects($this->once())
            ->method('getOrCreateHouse')
            ->with('instance-123')
            ->willReturn($house);

        $this->authService->expects($this->once())
            ->method('requireHouseOwner')
            ->with(1);

        $this->houseService->expects($this->once())
            ->method('updateHouseName')
            ->with(1, 'New House Name')
            ->willReturn($house);

        $response = $this->controller->update('New House Name');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());

        $data = $response->getData();
        $this->assertTrue($data['success']);
    }

    /**
     * Test UPDATE /house handles exceptions
     */
    public function testUpdate_HandlesException(): void
    {
        $house = new House();
        $house->setId(1);

        $this->houseService->expects($this->once())
            ->method('getInstanceId')
            ->willReturn('instance-123');

        $this->houseService->expects($this->once())
            ->method('getOrCreateHouse')
            ->with('instance-123')
            ->willReturn($house);

        $this->authService->expects($this->once())
            ->method('requireHouseOwner')
            ->willThrowException(new \Exception('Not authorized'));

        $response = $this->controller->update('New Name');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(500, $response->getStatus());

        $data = $response->getData();
        $this->assertFalse($data['success']);
        $this->assertEquals('HOUSE_UPDATE_ERROR', $data['error']['code']);
    }
}
