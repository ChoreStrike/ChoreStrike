<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Controller;

use OCA\ChoreStrike\Controller\FloorController;
use OCA\ChoreStrike\Db\FloorMapper;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Entity\Floor;
use OCA\ChoreStrike\Entity\House;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for FloorController
 */
class FloorControllerTest extends TestCase
{
    private FloorController $controller;
    private $floorMapper;
    private $houseService;
    private $authService;
    private $request;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->floorMapper = $this->createMock(FloorMapper::class);
        $this->houseService = $this->createMock(HouseService::class);
        $this->authService = $this->createMock(AuthorizationService::class);

        $this->controller = new FloorController(
            $this->request,
            $this->floorMapper,
            $this->houseService,
            $this->authService
        );
    }

    /**
     * Test GET /floors returns list successfully
     */
    public function testIndex_ReturnsFloorsSuccessfully(): void
    {
        $house = new House();
        $house->setId(1);

        $floor1 = new Floor();
        $floor1->setId(1);
        $floor1->setName('Ground Floor');
        $floor1->setHouseId(1);

        $floor2 = new Floor();
        $floor2->setId(2);
        $floor2->setName('First Floor');
        $floor2->setHouseId(1);

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

        $this->floorMapper->expects($this->once())
            ->method('findByHouseId')
            ->with(1)
            ->willReturn([$floor1, $floor2]);

        $response = $this->controller->index();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());

        $data = $response->getData();
        $this->assertTrue($data['success']);
        $this->assertCount(2, $data['data']);
    }

    /**
     * Test POST /floors validates empty name
     */
    public function testCreate_ValidatesEmptyName(): void
    {
        $response = $this->controller->create('');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(400, $response->getStatus());

        $data = $response->getData();
        $this->assertFalse($data['success']);
        $this->assertEquals('VALIDATION_ERROR', $data['error']['code']);
    }

    /**
     * Test DELETE /floors/{id} deletes successfully
     */
    public function testDelete_DeletesSuccessfully(): void
    {
        $house = new House();
        $house->setId(1);

        $this->houseService->expects($this->once())
            ->method('getInstanceId')
            ->willReturn('instance-123');

        $this->houseService->expects($this->once())
            ->method('getOrCreateHouse')
            ->willReturn($house);

        $this->authService->expects($this->once())
            ->method('requireHouseOwner')
            ->with(1);

        $this->floorMapper->expects($this->once())
            ->method('delete')
            ->with(1);

        $response = $this->controller->delete(1);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());

        $data = $response->getData();
        $this->assertTrue($data['success']);
    }
}
