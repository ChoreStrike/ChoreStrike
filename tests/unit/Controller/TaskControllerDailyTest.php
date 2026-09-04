<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Controller;

use OCA\ChoreStrike\Controller\TaskController;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\RoomTaskService;
use OCA\ChoreStrike\Service\TaskService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class TaskControllerDailyTest extends TestCase {
	private TaskController $controller;
	private RoomTaskService|MockObject $roomTaskService;
	private HouseService|MockObject $houseService;
	private AuthorizationService|MockObject $authService;
	private TaskService|MockObject $taskService;
	private IRequest|MockObject $request;

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->taskService = $this->createMock(TaskService::class);
		$this->roomTaskService = $this->createMock(RoomTaskService::class);
		$this->houseService = $this->createMock(HouseService::class);
		$this->authService = $this->createMock(AuthorizationService::class);
		// Identity mock: message assertions match the wrapped source text.
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text) => $text);

		$this->controller = new TaskController(
			$this->request,
			$this->taskService,
			$this->roomTaskService,
			$this->houseService,
			$this->authService,
			$l10n,
			$this->createMock(AppLogger::class)
		);
	}

	public function testUncompleteReturnsSuccess(): void {
		$house = $this->createMock(\OCA\ChoreStrike\Db\House::class);
		$house->method('getId')->willReturn(1);

		$this->houseService->method('getInstanceId')->willReturn('test-instance');
		$this->houseService->method('getOrCreateHouse')->willReturn($house);
		$houseUser = new \OCA\ChoreStrike\Db\HouseUser();
		$houseUser->setNextcloudUserId('user123');
		$this->authService->expects($this->once())->method('assertHouseMember')->with(1)->willReturn($houseUser);
		$this->roomTaskService->expects($this->once())->method('validateRoomTaskOwnership')->with(1, 1);

		$roomTask = $this->createMock(RoomTask::class);
		$roomTask->method('jsonSerialize')->willReturn(['id' => 1, 'completed' => false]);

		$this->roomTaskService->expects($this->once())
			->method('uncompleteTask')
			->with(1, $houseUser)
			->willReturn($roomTask);

		$response = $this->controller->uncomplete(1);

		$this->assertInstanceOf(JSONResponse::class, $response);
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertEquals(['id' => 1, 'completed' => false], $data['data']);
	}

}
