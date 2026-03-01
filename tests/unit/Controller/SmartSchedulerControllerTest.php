<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Controller;

use OCA\ChoreStrike\Controller\SmartSchedulerController;
use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\SchedulingService;
use OCA\ChoreStrike\Service\SmartSchedulerService;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SmartSchedulerController
 */
class SmartSchedulerControllerTest extends TestCase {

	private SmartSchedulerController $controller;
	private SmartSchedulerService $smartSchedulerService;
	private SchedulingService $schedulingService;
	private HouseService $houseService;
	private IUserSession $userSession;
	private IRequest $request;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->smartSchedulerService = $this->createMock(SmartSchedulerService::class);
		$this->schedulingService = $this->createMock(SchedulingService::class);
		$this->houseService = $this->createMock(HouseService::class);
		$this->userSession = $this->createMock(IUserSession::class);

		$this->controller = new SmartSchedulerController(
			$this->request,
			$this->smartSchedulerService,
			$this->schedulingService,
			$this->houseService,
			$this->userSession
		);
	}

	/**
	 * Test member availability uses resolved house ID from HouseService.
	 */
	public function testGetMemberAvailability_UsesResolvedHouseIdFromHouseService(): void {
		$house = new House();
		$house->setId(42);
		$availability = [['userId' => 7, 'userName' => 'Alice']];

		$this->houseService->expects($this->once())
			->method('getInstanceId')
			->willReturn('instance-123');

		$this->houseService->expects($this->once())
			->method('getOrCreateHouse')
			->with('instance-123')
			->willReturn($house);

		$this->schedulingService->expects($this->once())
			->method('getMemberAvailability')
			->with(42)
			->willReturn($availability);

		$response = $this->controller->getMemberAvailability();

		$this->assertInstanceOf(DataResponse::class, $response);
		$this->assertSame(200, $response->getStatus());
		$this->assertSame([
			'success' => true,
			'data' => $availability,
		], $response->getData());
	}
}
