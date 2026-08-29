<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Controller;

use OCA\ChoreStrike\Controller\RoomTaskController;
use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\Room;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\RoomTaskService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for RoomTaskController
 */
class RoomTaskControllerTest extends TestCase {
	private RoomTaskController $controller;
	private $roomTaskService;
	private $houseService;
	private $authService;
	private $request;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->roomTaskService = $this->createMock(RoomTaskService::class);
		$this->houseService = $this->createMock(HouseService::class);
		$this->authService = $this->createMock(AuthorizationService::class);
		// Identity mock: message assertions match the wrapped source text.
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text) => $text);

		$this->controller = new RoomTaskController(
			$this->request,
			$this->roomTaskService,
			$this->houseService,
			$this->authService,
			$l10n
		);
	}

	/**
	 * Test GET /room-tasks returns list successfully
	 */
	public function testIndex_ReturnsRoomTasksSuccessfully(): void {
		$house = new House();
		$house->setId(1);

		$roomTask1 = new RoomTask();
		$roomTask1->setId(1);
		$roomTask1->setRoomId(1);
		$roomTask1->setTaskId(1);

		$roomTask2 = new RoomTask();
		$roomTask2->setId(2);
		$roomTask2->setRoomId(2);
		$roomTask2->setTaskId(2);

		$this->houseService->method('getInstanceId')->willReturn('instance-123');
		$this->houseService->method('getOrCreateHouse')->willReturn($house);
		$this->authService->method('ensureHouseMember');
		$this->roomTaskService->method('validateRoomOwnership');

		$this->roomTaskService->expects($this->once())
			->method('getRoomTasks')
			->willReturn([$roomTask1, $roomTask2]);

		$this->roomTaskService->method('getAssigneeUserIdsByRoomTasks')->willReturn([]);

		$response = $this->controller->index(1);

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(200, $response->getStatus());

		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertCount(2, $data['data']);
	}

	/**
	 * index must reject a roomId from another house (404) — regression for
	 * the missing room-ownership validation (CR-053-02, same fix as
	 * roomStats): membership alone let any member read foreign houses'
	 * room tasks incl. assignee user ids.
	 */
	public function testIndex_Returns404ForForeignHouseRoom(): void {
		$house = new House();
		$house->setId(1);

		$this->houseService->method('getInstanceId')->willReturn('instance-123');
		$this->houseService->method('getOrCreateHouse')->willReturn($house);
		$this->authService->method('ensureHouseMember');
		$this->roomTaskService->expects($this->once())
			->method('validateRoomOwnership')
			->with(9, 1)
			->willThrowException(new \OCA\ChoreStrike\Exception\NotFoundException('Room not found'));

		$this->roomTaskService->expects($this->never())->method('getRoomTasks');

		$response = $this->controller->index(9);

		$this->assertEquals(404, $response->getStatus());
		$this->assertEquals('RESOURCE_NOT_FOUND', $response->getData()['error']['code']);
	}

	/**
	 * index with a scopeId must use the house-scoped scope fetch
	 * (CR-053-02): cross-house entries are filtered server-side.
	 */
	public function testIndex_ScopeBranch_UsesHouseScopedScopeFetch(): void {
		$house = new House();
		$house->setId(1);

		$roomTask = new RoomTask();
		$roomTask->setId(7);
		$roomTask->setRoomId(3);
		$roomTask->setTaskId(4);

		$this->houseService->method('getInstanceId')->willReturn('instance-123');
		$this->houseService->method('getOrCreateHouse')->willReturn($house);
		$this->authService->method('ensureHouseMember');

		$this->roomTaskService->expects($this->once())
			->method('getScopeTasksScopedToHouse')
			->with(5, 1, true)
			->willReturn([$roomTask]);
		$this->roomTaskService->expects($this->never())->method('getScopeTasks');

		$this->roomTaskService->method('getAssigneeUserIdsByRoomTasks')->willReturn([7 => ['user1']]);

		$response = $this->controller->index(null, 5);

		$this->assertEquals(200, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertCount(1, $data['data']);
		$this->assertEquals(['user1'], $data['data'][0]['assignees']);
	}

	/**
	 * Test POST /room-tasks/{id}/complete completes successfully.
	 *
	 * The current RoomTaskController::complete() resolves the actor via
	 * requireCurrentUserId() and delegates to RoomTaskService::completeTask(),
	 * returning the {roomTask, room, task, rewards} payload.
	 */
	public function testComplete_CompletesSuccessfully(): void {
		$house = new House();
		$house->setId(1);

		$this->houseService->method('getInstanceId')->willReturn('instance-123');
		$this->houseService->method('getOrCreateHouse')->willReturn($house);
		$this->authService->method('ensureHouseMember');
		$this->roomTaskService->method('validateRoomTaskOwnership');
		$this->authService->expects($this->once())
			->method('requireCurrentUserId')
			->willReturn('user1');

		$roomTask = new RoomTask();
		$roomTask->setId(1);
		$room = new Room();
		$task = new Task();

		$this->roomTaskService->expects($this->once())
			->method('completeTask')
			->with(1, 'user1')
			->willReturn([
				'roomTask' => $roomTask,
				'room' => $room,
				'task' => $task,
				'rewards' => [],
			]);

		$response = $this->controller->complete(1);

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(200, $response->getStatus());

		$data = $response->getData();
		$this->assertTrue($data['success']);
	}

	/**
	 * roomStats must reject a roomId from another house (404) — regression
	 * for the missing room-ownership validation (CS-PLAN-040): membership
	 * alone let any member read foreign houses' room stat counts.
	 */
	public function testRoomStats_Returns404ForForeignHouseRoom(): void {
		$house = new House();
		$house->setId(1);

		$this->houseService->method('getInstanceId')->willReturn('instance-123');
		$this->houseService->method('getOrCreateHouse')->willReturn($house);
		$this->authService->method('ensureHouseMember');
		$this->roomTaskService->expects($this->once())
			->method('validateRoomOwnership')
			->with(9, 1)
			->willThrowException(new \OCA\ChoreStrike\Exception\NotFoundException('Room not found'));

		$this->roomTaskService->expects($this->never())->method('getRoomTaskStats');

		$response = $this->controller->roomStats(9);

		$this->assertEquals(404, $response->getStatus());
		$this->assertEquals('RESOURCE_NOT_FOUND', $response->getData()['error']['code']);
	}

	/**
	 * roomStats returns stats for a same-house room.
	 */
	public function testRoomStats_ReturnsStatsForSameHouseRoom(): void {
		$house = new House();
		$house->setId(1);

		$this->houseService->method('getInstanceId')->willReturn('instance-123');
		$this->houseService->method('getOrCreateHouse')->willReturn($house);
		$this->authService->method('ensureHouseMember');
		$this->roomTaskService->method('validateRoomOwnership');

		$stats = ['totalTasks' => 2, 'completionRate' => 50];
		$this->roomTaskService->expects($this->once())
			->method('getRoomTaskStats')
			->with(3)
			->willReturn($stats);

		$response = $this->controller->roomStats(3);

		$this->assertEquals(200, $response->getStatus());
		$this->assertTrue($response->getData()['success']);
		$this->assertEquals($stats, $response->getData()['data']);
	}

	/**
	 * assign must reject a target userId that is not a member of the caller's
	 * house (cross-house BOLA → 403). Regression for the missing membership
	 * check on the legacy assign() endpoint.
	 */
	public function testAssign_Returns403ForCrossHouseUser(): void {
		$house = new House();
		$house->setId(1);

		$this->houseService->method('getInstanceId')->willReturn('instance-123');
		$this->houseService->method('getOrCreateHouse')->willReturn($house);
		$this->authService->method('ensureHouseMember');
		$this->roomTaskService->method('validateRoomTaskOwnership');
		$this->authService->expects($this->once())
			->method('assertUserInHouse')
			->with(1, 'outsider')
			->willThrowException(new \OCA\ChoreStrike\Exception\ForbiddenException('User is not a member of this house'));

		$this->roomTaskService->expects($this->never())->method('assignTask');

		$response = $this->controller->assign(7, 'outsider');

		$this->assertEquals(403, $response->getStatus());
		$this->assertEquals('FORBIDDEN', $response->getData()['error']['code']);
	}

	/**
	 * assign surfaces the service's internal membership rejection
	 * (CR-SVC-09) as a 400 VALIDATION_ERROR with the curated message —
	 * not the generic 500 fallback.
	 */
	public function testAssign_Returns400WithCuratedMessageForServiceRejection(): void {
		$house = new House();
		$house->setId(1);

		$this->houseService->method('getInstanceId')->willReturn('instance-123');
		$this->houseService->method('getOrCreateHouse')->willReturn($house);
		$this->authService->method('ensureHouseMember');
		$this->roomTaskService->method('validateRoomTaskOwnership');
		$this->authService->method('assertUserInHouse');

		$this->roomTaskService->expects($this->once())
			->method('assignTask')
			->with(7, 'intruder', 1)
			->willThrowException(new \InvalidArgumentException('User is not a member of this house: intruder'));

		$response = $this->controller->assign(7, 'intruder');

		$this->assertEquals(400, $response->getStatus());
		$this->assertEquals('VALIDATION_ERROR', $response->getData()['error']['code']);
		$this->assertEquals(
			'User is not a member of this house: intruder',
			$response->getData()['error']['message']
		);
	}

	public function testRange_ReturnsScheduledAndCompleted(): void {
		$house = new House();
		$house->setId(1);

		$this->houseService->method('getInstanceId')->willReturn('instance-123');
		$this->houseService->method('getOrCreateHouse')->willReturn($house);
		$this->authService->method('ensureHouseMember');

		$mockData = [
			'scheduled' => [['roomTask' => ['id' => 1], 'task' => ['name' => 'T'], 'room' => ['name' => 'R'], 'flexWindow' => []]],
			'completed' => [['roomTaskId' => 2, 'taskName' => 'Done']],
		];
		$this->roomTaskService->expects($this->once())
			->method('getRangeTasks')
			->willReturn($mockData);

		$response = $this->controller->range('2026-07-28', '2026-08-03');

		$this->assertEquals(200, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertCount(1, $data['data']['scheduled']);
		$this->assertCount(1, $data['data']['completed']);
	}

	public function testRange_Returns400ForInvalidDate(): void {
		$response = $this->controller->range('not-a-date', '2026-08-03');

		$this->assertEquals(400, $response->getStatus());
		$this->assertEquals('VALIDATION_ERROR', $response->getData()['error']['code']);
	}

	public function testRange_Returns400WhenFromAfterTo(): void {
		$response = $this->controller->range('2026-08-03', '2026-07-28');

		$this->assertEquals(400, $response->getStatus());
		$this->assertEquals('VALIDATION_ERROR', $response->getData()['error']['code']);
	}

	public function testRange_Returns400WhenSpanExceeds60Days(): void {
		$response = $this->controller->range('2026-01-01', '2026-06-01');

		$this->assertEquals(400, $response->getStatus());
		$this->assertEquals('VALIDATION_ERROR', $response->getData()['error']['code']);
	}

	public function testRange_Returns403ForNonMember(): void {
		$house = new House();
		$house->setId(1);

		$this->houseService->method('getInstanceId')->willReturn('instance-123');
		$this->houseService->method('getOrCreateHouse')->willReturn($house);
		$this->authService->method('ensureHouseMember')
			->willThrowException(new \OCA\ChoreStrike\Exception\ForbiddenException('Not a member'));

		$response = $this->controller->range('2026-07-28', '2026-08-03');

		$this->assertEquals(403, $response->getStatus());
		$this->assertEquals('FORBIDDEN', $response->getData()['error']['code']);
	}

	/**
	 * Create_PassesHouseIdToService
	 *
	 * CR-SVC-01: the controller must forward the caller's house id as the
	 * first argument so the service can scope the target room itself.
	 */
	public function testCreate_PassesHouseIdToService(): void {
		$house = new House();
		$house->setId(42);

		$roomTask = new RoomTask();
		$roomTask->setId(9);

		$this->houseService->method('getInstanceId')->willReturn('instance-123');
		$this->houseService->method('getOrCreateHouse')->willReturn($house);
		$this->authService->method('ensureHouseMember');

		$this->roomTaskService->expects($this->once())
			->method('createRoomTask')
			->with(42, 1, 5, null, null, null)
			->willReturn($roomTask);

		$response = $this->controller->create(1, 5);

		$this->assertEquals(200, $response->getStatus());
		$this->assertTrue($response->getData()['success']);
	}

	/**
	 * Create_Returns404ForCrossHouseRoom
	 *
	 * CR-SVC-01: a cross-house room (NotFoundException from the service) maps
	 * to 404, not a 500.
	 */
	public function testCreate_Returns404ForCrossHouseRoom(): void {
		$house = new House();
		$house->setId(42);

		$this->houseService->method('getInstanceId')->willReturn('instance-123');
		$this->houseService->method('getOrCreateHouse')->willReturn($house);
		$this->authService->method('ensureHouseMember');

		$this->roomTaskService->method('createRoomTask')
			->willThrowException(new \OCA\ChoreStrike\Exception\NotFoundException('Room not found'));

		$response = $this->controller->create(1, 5);

		$this->assertEquals(404, $response->getStatus());
		$this->assertEquals('RESOURCE_NOT_FOUND', $response->getData()['error']['code']);
	}

	/**
	 * Create_Returns400ForNonMemberAssignee
	 *
	 * CR-SVC-01: a non-member assignee (InvalidArgumentException from the
	 * service) maps to a 400 validation error, not a 500.
	 */
	public function testCreate_Returns400ForNonMemberAssignee(): void {
		$house = new House();
		$house->setId(42);

		$this->houseService->method('getInstanceId')->willReturn('instance-123');
		$this->houseService->method('getOrCreateHouse')->willReturn($house);
		$this->authService->method('ensureHouseMember');

		$this->roomTaskService->method('createRoomTask')
			->willThrowException(new \InvalidArgumentException('not a member'));

		$response = $this->controller->create(1, 5, null, null, 'intruder');

		$this->assertEquals(400, $response->getStatus());
		$this->assertEquals('VALIDATION_ERROR', $response->getData()['error']['code']);
	}
}
