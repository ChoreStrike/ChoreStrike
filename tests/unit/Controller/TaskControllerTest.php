<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Controller;

use OCA\ChoreStrike\Controller\TaskController;
use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Entity\Task;
use OCA\ChoreStrike\Entity\House;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for TaskController
 */
class TaskControllerTest extends TestCase {

	private TaskController $controller;
	private $taskMapper;
	private $houseService;
	private $authService;
	private $request;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->taskMapper = $this->createMock(TaskMapper::class);
		$this->houseService = $this->createMock(HouseService::class);
		$this->authService = $this->createMock(AuthorizationService::class);

		$this->controller = new TaskController(
			$this->request,
			$this->taskMapper,
			$this->houseService,
			$this->authService
		);
	}

	/**
	 * Test GET /tasks returns list successfully
	 */
	public function testIndex_ReturnsTasksSuccessfully(): void {
		$house = new House();
		$house->setId(1);

		$task1 = new Task();
		$task1->setId(1);
		$task1->setName('Clean Kitchen');
		$task1->setHouseId(1);

		$task2 = new Task();
		$task2->setId(2);
		$task2->setName('Vacuum Living Room');
		$task2->setHouseId(1);

		$this->houseService->expects($this->once())
			->method('getInstanceId')
			->willReturn('instance-123');

		$this->houseService->expects($this->once())
			->method('getOrCreateHouse')
			->willReturn($house);

		$this->authService->expects($this->once())
			->method('requireHouseMember')
			->with(1);

		$this->taskMapper->expects($this->once())
			->method('findByHouseId')
			->with(1)
			->willReturn([$task1, $task2]);

		$response = $this->controller->index();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(200, $response->getStatus());

		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertCount(2, $data['data']);
	}

	/**
	 * Test POST /tasks validates empty name
	 */
	public function testCreate_ValidatesEmptyName(): void {
		$response = $this->controller->create('');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(400, $response->getStatus());

		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertEquals('VALIDATION_ERROR', $data['error']['code']);
		$this->assertEquals('Task name is required', $data['error']['message']);
	}

	/**
	 * Test POST /tasks creates successfully
	 */
	public function testCreate_CreatesSuccessfully(): void {
		$house = new House();
		$house->setId(1);

		$task = new Task();
		$task->setId(1);
		$task->setName('Clean Kitchen');
		$task->setHouseId(1);

		$this->houseService->expects($this->once())
			->method('getInstanceId')
			->willReturn('instance-123');

		$this->houseService->expects($this->once())
			->method('getOrCreateHouse')
			->willReturn($house);

		$this->authService->expects($this->once())
			->method('requireHouseMember')
			->with(1);

		$this->taskMapper->expects($this->once())
			->method('insert')
			->willReturn($task);

		$response = $this->controller->create('Clean Kitchen');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(201, $response->getStatus());

		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertEquals('Clean Kitchen', $data['data']['name']);
	}

	/**
	 * Test GET /tasks/{id} returns task successfully
	 */
	public function testShow_ReturnsTaskSuccessfully(): void {
		$house = new House();
		$house->setId(1);

		$task = new Task();
		$task->setId(1);
		$task->setName('Clean Kitchen');
		$task->setHouseId(1);

		$this->houseService->expects($this->once())
			->method('getInstanceId')
			->willReturn('instance-123');

		$this->houseService->expects($this->once())
			->method('getOrCreateHouse')
			->willReturn($house);

		$this->authService->expects($this->once())
			->method('requireHouseMember')
			->with(1);

		$this->taskMapper->expects($this->once())
			->method('find')
			->with(1)
			->willReturn($task);

		$response = $this->controller->show(1);

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(200, $response->getStatus());

		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertEquals('Clean Kitchen', $data['data']['name']);
	}

	/**
	 * Test DELETE /tasks/{id} deletes successfully
	 */
	public function testDelete_DeletesSuccessfully(): void {
		$house = new House();
		$house->setId(1);

		$task = new Task();
		$task->setId(1);
		$task->setHouseId(1);

		$this->houseService->expects($this->once())
			->method('getInstanceId')
			->willReturn('instance-123');

		$this->houseService->expects($this->once())
			->method('getOrCreateHouse')
			->willReturn($house);

		$this->authService->expects($this->once())
			->method('requireHouseOwner')
			->with(1);

		$this->taskMapper->expects($this->once())
			->method('find')
			->with(1)
			->willReturn($task);

		$this->taskMapper->expects($this->once())
			->method('delete')
			->with($task);

		$response = $this->controller->delete(1);

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(200, $response->getStatus());

		$data = $response->getData();
		$this->assertTrue($data['success']);
	}
}
