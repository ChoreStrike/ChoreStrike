<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Controller;

use OCA\ChoreStrike\Controller\SmartSchedulerController;
use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\RoomTask;
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
	 * Test scheduling suggestions use generated assignments as input.
	 */
	public function testGetSchedulingSuggestions_UsesGeneratedAssignments(): void {
		$house = new House();
		$house->setId(42);

		$generatedAssignments = [
			[
				'taskId' => 11,
				'taskTitle' => 'Kitchen wipe',
				'assignedTo' => 'alice',
			],
			[
				'taskId' => 12,
				'taskTitle' => 'Bathroom clean',
				'assignedTo' => 'bob',
			],
		];

		$this->houseService->expects($this->once())
			->method('getInstanceId')
			->willReturn('instance-123');

		$this->houseService->expects($this->once())
			->method('getOrCreateHouse')
			->with('instance-123')
			->willReturn($house);

		$this->smartSchedulerService->expects($this->once())
			->method('generateSchedule')
			->with(
				42,
				$this->isInstanceOf(\DateTime::class),
				$this->isInstanceOf(\DateTime::class)
			)
			->willReturn([
				'assignments' => $generatedAssignments,
			]);

		$this->schedulingService->expects($this->once())
			->method('getSchedulingSuggestions')
			->with($generatedAssignments, 42)
			->willReturn(['monday' => ['recommendedTasks' => 2]]);

		$response = $this->controller->getSchedulingSuggestions();

		$this->assertInstanceOf(DataResponse::class, $response);
		$this->assertSame(200, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertSame(2, $data['data']['monday']['recommendedTasks']);
	}

	/**
	 * Test apply suggestion persists task assignment successfully.
	 */
	public function testApplySuggestion_SuccessfullyAssignsTask(): void {
		$house = new House();
		$house->setId(42);

		$task = new RoomTask();
		$task->setId(7);
		$task->setAssignedToUserId('user1');
		$task->setNextDue(new \DateTime('2026-03-15'));

		$this->houseService->expects($this->once())
			->method('getInstanceId')
			->willReturn('instance-123');

		$this->houseService->expects($this->once())
			->method('getOrCreateHouse')
			->with('instance-123')
			->willReturn($house);

		$this->smartSchedulerService->expects($this->once())
			->method('applySuggestion')
			->with(42, 7, 'user1', '2026-03-15')
			->willReturn($task);

		$response = $this->controller->applySuggestion(7, 'user1', '2026-03-15');

		$this->assertInstanceOf(DataResponse::class, $response);
		$this->assertSame(200, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertSame(7, $data['data']['taskId']);
		$this->assertSame('user1', $data['data']['assignedTo']);
		$this->assertSame('2026-03-15', $data['data']['scheduledFor']);
	}

	/**
	 * Test apply suggestion returns 400 for validation error.
	 */
	public function testApplySuggestion_Returns400ForValidationError(): void {
		$house = new House();
		$house->setId(42);

		$this->houseService->expects($this->once())
			->method('getInstanceId')
			->willReturn('instance-123');

		$this->houseService->expects($this->once())
			->method('getOrCreateHouse')
			->with('instance-123')
			->willReturn($house);

		$this->smartSchedulerService->expects($this->once())
			->method('applySuggestion')
			->with(42, 7, 'user1', 'invalid-date')
			->willThrowException(new \InvalidArgumentException('Invalid date format. Use Y-m-d'));

		$response = $this->controller->applySuggestion(7, 'user1', 'invalid-date');

		$this->assertInstanceOf(DataResponse::class, $response);
		$this->assertSame(400, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertSame('Invalid date format. Use Y-m-d', $data['error']);
	}

	/**
	 * Test apply suggestion returns 500 for server error.
	 */
	public function testApplySuggestion_Returns500ForServerError(): void {
		$house = new House();
		$house->setId(42);

		$this->houseService->expects($this->once())
			->method('getInstanceId')
			->willReturn('instance-123');

		$this->houseService->expects($this->once())
			->method('getOrCreateHouse')
			->with('instance-123')
			->willReturn($house);

		$this->smartSchedulerService->expects($this->once())
			->method('applySuggestion')
			->with(42, 999, 'user1', '2026-03-15')
			->willThrowException(new \Exception('Task not found'));

		$response = $this->controller->applySuggestion(999, 'user1', '2026-03-15');

		$this->assertInstanceOf(DataResponse::class, $response);
		$this->assertSame(500, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertSame('Task not found', $data['error']);
	}
}
