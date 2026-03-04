<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Controller;

use OCA\ChoreStrike\Controller\PageController;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Entity\House;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PageController
 */
class PageControllerTest extends TestCase
{
    private PageController $controller;
    private $houseService;
    private $request;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->houseService = $this->createMock(HouseService::class);

        $this->controller = new PageController(
            $this->request,
            $this->houseService
        );
    }

    /**
     * Test index returns template response
     */
    public function testIndex_ReturnsTemplateResponse(): void
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

        $response = $this->controller->index();

        $this->assertInstanceOf(TemplateResponse::class, $response);
    }

    /**
     * Test index handles exception gracefully
     */
    public function testIndex_HandlesException(): void
    {
        $this->houseService->expects($this->once())
            ->method('getInstanceId')
            ->willThrowException(new \Exception('Database error'));

        $response = $this->controller->index();

        $this->assertInstanceOf(TemplateResponse::class, $response);
    }
}
