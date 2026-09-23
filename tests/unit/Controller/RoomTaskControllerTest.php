<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Controller;

use OCA\ChoreStrike\Controller\RoomTaskController;
use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\Room;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\CalendarService;
use OCA\ChoreStrike\Service\CompletionService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\RoomTaskService;
use OCA\ChoreStrike\Service\TaskCreationService;
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
	private $roomTaskReadService;
	private $completionService;
	private $taskCreationService;
	private $houseService;
	private $authService;
	private $request;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->roomTaskService = $this->createMock(RoomTaskService::class);
		$this->roomTaskReadService = $this->createMock(\OCA\ChoreStrike\Service\RoomTaskReadService::class);
		$this->completionService = $this->createMock(CompletionService::class);
		$this->taskCreationService = $this->createMock(TaskCreationService::class);
		$this->houseService = $this->createMock(HouseService::class);
		$this->authService = $this->createMock(AuthorizationService::class);
		// Identity mock: message assertions match the wrapped source text.
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text) => $text);

		$this->controller = new RoomTaskController(
			$this->request,
			$this->roomTaskService,
			$this->roomTaskReadService,
			$this->completionService,
			$this->taskCreationService,
			$this->houseService,
			$this->authService,
			$l10n,
			new CalendarService(),
			$this->createMock(AppLogger::class)
		);
	}

	/**
	 * Stub the request params read by the creation endpoint.
	 */
	private function stubCreateParams(array $params): void {
		$this->request->method('getParam')->willReturnCallback(
			static fn (string $key) => $params[$key] ?? null
		);
	}

	private function createHouseUser(): HouseUser {
		// HouseUser accessors are magic (Entity __call), so use a real entity.
		$houseUser = new HouseUser();
		$houseUser->setNextcloudUserId('caller');
		$houseUser->setRole('member');
		return $houseUser;
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
		$houseUser = $this->createHouseUser();
		$this->authService->method('assertHouseMember')->willReturn($houseUser);
		$this->roomTaskService->method('validateRoomOwnership');

		$this->roomTaskReadService->expects($this->once())
			->method('getRoomTasks')
			->with(1, $this->identicalTo($houseUser), true)
			->willReturn([$roomTask1, $roomTask2]);

		$this->roomTaskReadService->method('assigneesByRoomTasks')->willReturn([]);

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
		$this->authService->method('assertHouseMember');
		$this->roomTaskService->expects($this->once())
			->method('validateRoomOwnership')
			->with(9, 1)
			->willThrowException(new \OCA\ChoreStrike\Exception\NotFoundException('Room not found'));

		$this->roomTaskReadService->expects($this->never())->method('getRoomTasks');

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
		$houseUser = $this->createHouseUser();
		$this->authService->method('assertHouseMember')->willReturn($houseUser);

		$this->roomTaskReadService->expects($this->once())
			->method('getScopeTasksScopedToHouse')
			->with(5, 1, $this->identicalTo($houseUser), true)
			->willReturn([$roomTask]);

		$this->roomTaskReadService->method('assigneesByRoomTasks')->willReturn([7 => ['user1']]);

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
	 * The controller resolves the actor via requireCurrentUserId(),
	 * validates the occurrence receipt (closed input set, CS-PLAN-072 WP1)
	 * and delegates to the CompletionService aggregate (CS-PLAN-064, D2),
	 * returning the {roomTask, room, task, rewards, occurrence, replay}
	 * payload.
	 */
	public function testComplete_CompletesSuccessfully(): void {
		$house = new House();
		$house->setId(1);
		$house->setTimezone('UTC');

		$this->houseService->method('getInstanceId')->willReturn('instance-123');
		$this->houseService->method('getOrCreateHouse')->willReturn($house);
		$this->authService->method('assertHouseMember');
		$this->roomTaskService->method('validateRoomTaskOwnership');
		$this->authService->expects($this->once())
			->method('requireCurrentUserId')
			->willReturn('user1');
		// The receipt rides the request params (route/framework keys are
		// filtered by the controller).
		$this->request->method('getParams')->willReturn([
			'commandId' => '11111111-2222-4333-8444-555555555555',
			'occurrenceId' => '1:1',
			'expectedVersion' => 0,
			'selectionToken' => 'token',
			'id' => 1,
		]);

		$roomTask = new RoomTask();
		$roomTask->setId(1);
		$room = new Room();
		$task = new Task();

		$this->completionService->expects($this->once())
			->method('complete')
			->with(
				1,
				'user1',
				1,
				'11111111-2222-4333-8444-555555555555',
				'1:1',
				0,
				'token'
			)
			->willReturn([
				'roomTask' => $roomTask,
				'room' => $room,
				'task' => $task,
				'rewards' => [],
				'completionId' => 5,
				'occurrence' => null,
				'replay' => false,
			]);

		$response = $this->controller->complete(1);

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(200, $response->getStatus());

		$data = $response->getData();
		$this->assertTrue($data['success']);
	}

	/**
	 * Test POST /room-tasks/{id}/complete without a receipt answers 400
	 * (closed input set, CS-PLAN-072 WP1) — never a silently degraded
	 * completion.
	 */
	public function testComplete_MissingReceiptAnswers400(): void {
		$house = new House();
		$house->setId(1);

		$this->houseService->method('getInstanceId')->willReturn('instance-123');
		$this->houseService->method('getOrCreateHouse')->willReturn($house);
		$this->authService->method('assertHouseMember');
		$this->roomTaskService->method('validateRoomTaskOwnership');

		$this->completionService->expects($this->never())->method('complete');

		$response = $this->controller->complete(1);

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(400, $response->getStatus());
		$this->assertSame('VALIDATION_ERROR', $response->getData()['error']['code']);
	}

	/**
	 * Unknown receipt fields (future WP3 participants) are REJECTED with
	 * 400, never silently ignored (parent §3.4).
	 */
	public function testComplete_UnknownReceiptFieldAnswers400(): void {
		$house = new House();
		$house->setId(1);

		$this->houseService->method('getInstanceId')->willReturn('instance-123');
		$this->houseService->method('getOrCreateHouse')->willReturn($house);
		$this->authService->method('assertHouseMember');
		$this->roomTaskService->method('validateRoomTaskOwnership');
		$this->request->method('getParams')->willReturn([
			'commandId' => '11111111-2222-4333-8444-555555555555',
			'occurrenceId' => '1:1',
			'expectedVersion' => 0,
			'selectionToken' => 'token',
			'participants' => [['userId' => 'mom', 'share' => 50]],
		]);

		$this->completionService->expects($this->never())->method('complete');

		$response = $this->controller->complete(1);

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(400, $response->getStatus());
		$this->assertStringContainsString('Unknown field', $response->getData()['error']['message']);
	}

	/**
	 * Test POST /room-tasks/{id}/undo (CS-PLAN-064 WP5, decision D5): the
	 * single undo operation delegates to the CompletionService undo
	 * aggregate and carries the `undone` flag in the response.
	 */
	public function testUndo_DelegatesToCompletionService(): void {
		$house = new House();
		$house->setId(1);

		$this->houseService->method('getInstanceId')->willReturn('instance-123');
		$this->houseService->method('getOrCreateHouse')->willReturn($house);
		$this->authService->method('assertHouseMember')
			->willReturn($this->createHouseUser());
		$this->roomTaskService->method('validateRoomTaskOwnership');

		$this->completionService->expects($this->once())
			->method('undo')
			->with(1, $this->isInstanceOf(HouseUser::class), 1)
			->willReturn([
				'roomTask' => new RoomTask(),
				'room' => new Room(),
				'task' => new Task(),
				'rewards' => ['coins' => -50],
				'undone' => true,
			]);

		$response = $this->controller->undo(1);

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(200, $response->getStatus());

		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertTrue($data['data']['undone']);
		$this->assertSame(-50, $data['data']['rewards']['coins']);
	}

	/**
	 * The undo endpoint maps the 404 anti-enumeration denial from the
	 * service to the standard envelope.
	 */
	public function testUndo_Returns404ForUnknownRoomTask(): void {
		$house = new House();
		$house->setId(1);

		$this->houseService->method('getInstanceId')->willReturn('instance-123');
		$this->houseService->method('getOrCreateHouse')->willReturn($house);
		$this->authService->method('assertHouseMember')
			->willReturn($this->createHouseUser());
		$this->roomTaskService->method('validateRoomTaskOwnership');

		$this->completionService->method('undo')
			->willThrowException(new \OCA\ChoreStrike\Exception\NotFoundException('Room task not found'));

		$response = $this->controller->undo(99);

		$this->assertEquals(404, $response->getStatus());
		$this->assertEquals('RESOURCE_NOT_FOUND', $response->getData()['error']['code']);
	}

	/**
	 * The undo endpoint maps the actor-gate denial to 403.
	 */
	public function testUndo_Returns403ForActorGateDenial(): void {
		$house = new House();
		$house->setId(1);

		$this->houseService->method('getInstanceId')->willReturn('instance-123');
		$this->houseService->method('getOrCreateHouse')->willReturn($house);
		$this->authService->method('assertHouseMember')
			->willReturn($this->createHouseUser());
		$this->roomTaskService->method('validateRoomTaskOwnership');

		$this->completionService->method('undo')
			->willThrowException(new \OCA\ChoreStrike\Exception\ForbiddenException('Only the completer or a house owner can undo a completion'));

		$response = $this->controller->undo(1);

		$this->assertEquals(403, $response->getStatus());
		$this->assertEquals('FORBIDDEN', $response->getData()['error']['code']);
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
		$this->authService->method('assertHouseMember');
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
		$houseUser = $this->createHouseUser();
		$this->authService->method('assertHouseMember')->willReturn($houseUser);
		$this->roomTaskService->method('validateRoomOwnership');

		$stats = ['totalTasks' => 2, 'completionRate' => 50];
		$this->roomTaskService->expects($this->once())
			->method('getRoomTaskStats')
			->with(3, $this->identicalTo($houseUser))
			->willReturn($stats);

		$response = $this->controller->roomStats(3);

		$this->assertEquals(200, $response->getStatus());
		$this->assertTrue($response->getData()['success']);
		$this->assertEquals($stats, $response->getData()['data']);
	}

	public function testRange_ReturnsScheduledAndCompleted(): void {
		$house = new House();
		$house->setId(1);

		$this->houseService->method('getInstanceId')->willReturn('instance-123');
		$this->houseService->method('getOrCreateHouse')->willReturn($house);
		$this->authService->method('assertHouseMember')->willReturn($this->createHouseUser());

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
		$this->authService->method('assertHouseMember')
			->willThrowException(new \OCA\ChoreStrike\Exception\ForbiddenException('Not a member'));

		$response = $this->controller->range('2026-07-28', '2026-08-03');

		$this->assertEquals(403, $response->getStatus());
		$this->assertEquals('FORBIDDEN', $response->getData()['error']['code']);
	}

	/**
	 * Create_MountVariant_ForwardsAggregatePayload
	 *
	 * CS-PLAN-063 §3.3: the creation endpoint routes through the atomic
	 * TaskCreationService with the caller's house id and the acting
	 * HouseUser, and returns the unified aggregate shape.
	 */
	public function testCreate_MountVariant_ForwardsAggregatePayload(): void {
		$house = new House();
		$house->setId(42);
		$houseUser = $this->createHouseUser();

		$this->stubCreateParams(['roomId' => 1, 'taskId' => 5]);
		$this->houseService->method('getInstanceId')->willReturn('instance-123');
		$this->houseService->method('getOrCreateHouse')->willReturn($house);
		$this->authService->method('assertHouseMember')->willReturn($houseUser);

		$this->taskCreationService->expects($this->once())
			->method('create')
			->with(42, $this->identicalTo($houseUser), $this->callback(
				fn (array $payload) => $payload === ['roomId' => 1, 'taskId' => 5]
			))
			->willReturn([
				'task' => new Task(),
				'roomTask' => new RoomTask(),
				'assignees' => [],
			]);

		$response = $this->controller->create();

		$this->assertEquals(200, $response->getStatus());
		$this->assertTrue($response->getData()['success']);
		$this->assertArrayHasKey('task', $response->getData()['data']);
		$this->assertArrayHasKey('roomTask', $response->getData()['data']);
		$this->assertArrayHasKey('assignees', $response->getData()['data']);
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

		$this->stubCreateParams(['roomId' => 1, 'taskId' => 5]);
		$this->houseService->method('getInstanceId')->willReturn('instance-123');
		$this->houseService->method('getOrCreateHouse')->willReturn($house);
		$this->authService->method('assertHouseMember');

		$this->taskCreationService->method('create')
			->willThrowException(new \OCA\ChoreStrike\Exception\NotFoundException('Room not found'));

		$response = $this->controller->create();

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

		$this->stubCreateParams(['roomId' => 1, 'taskId' => 5, 'assigneeUserIds' => ['intruder']]);
		$this->houseService->method('getInstanceId')->willReturn('instance-123');
		$this->houseService->method('getOrCreateHouse')->willReturn($house);
		$this->authService->method('assertHouseMember');

		$this->taskCreationService->method('create')
			->willThrowException(new \InvalidArgumentException('not a member'));

		$response = $this->controller->create();

		$this->assertEquals(400, $response->getStatus());
		$this->assertEquals('VALIDATION_ERROR', $response->getData()['error']['code']);
	}

	/**
	 * Create_Returns400WithoutTaskOrTaskId
	 *
	 * CS-PLAN-063 §3.3: exactly one of `task` / `taskId` is required.
	 */
	public function testCreate_Returns400WithoutTaskOrTaskId(): void {
		$house = new House();
		$house->setId(42);

		$this->stubCreateParams(['roomId' => 1]);
		$this->houseService->method('getInstanceId')->willReturn('instance-123');
		$this->houseService->method('getOrCreateHouse')->willReturn($house);
		$this->authService->method('assertHouseMember');

		$this->taskCreationService->expects($this->never())->method('create');

		$response = $this->controller->create();

		$this->assertEquals(400, $response->getStatus());
		$this->assertEquals('VALIDATION_ERROR', $response->getData()['error']['code']);
	}

	/**
	 * Create_Returns400WithBothTaskAndTaskId
	 *
	 * The payload variants are mutually exclusive.
	 */
	public function testCreate_Returns400WithBothTaskAndTaskId(): void {
		$house = new House();
		$house->setId(42);

		$this->stubCreateParams(['roomId' => 1, 'taskId' => 5, 'task' => ['name' => 'X']]);
		$this->houseService->method('getInstanceId')->willReturn('instance-123');
		$this->houseService->method('getOrCreateHouse')->willReturn($house);
		$this->authService->method('assertHouseMember');

		$this->taskCreationService->expects($this->never())->method('create');

		$response = $this->controller->create();

		$this->assertEquals(400, $response->getStatus());
		$this->assertEquals('VALIDATION_ERROR', $response->getData()['error']['code']);
	}

	/**
	 * Overdue_ReturnsCanonicalEnrichedRows
	 *
	 * CS-PLAN-066 §3.1 (D1): overdue and due-today return the canonical
	 * `{roomTask, task, room, assignees}` execution-row shape with the same
	 * visibility/ordering as before — enrichment without broadened access.
	 */
	public function testOverdue_ReturnsCanonicalEnrichedRows(): void {
		$house = new House();
		$house->setId(1);
		$houseUser = $this->createHouseUser();

		$roomTask = new RoomTask();
		$roomTask->setId(11);
		$roomTask->setRoomId(3);
		$roomTask->setTaskId(4);
		$task = new Task();
		$task->setId(4);
		$room = new Room();
		$room->setId(3);

		$this->houseService->method('getInstanceId')->willReturn('instance-123');
		$this->houseService->method('getOrCreateHouse')->willReturn($house);
		$this->authService->method('assertHouseMember')->willReturn($houseUser);

		$this->roomTaskReadService->expects($this->once())
			->method('getOverdueTaskRows')
			->with(1, $this->identicalTo($houseUser), null)
			->willReturn([['roomTask' => $roomTask, 'task' => $task, 'room' => $room]]);
		$this->roomTaskReadService->method('assigneesByRoomTasks')
			->willReturn([11 => ['alice', 'bob']]);

		$response = $this->controller->overdue();

		$this->assertEquals(200, $response->getStatus());
		$row = $response->getData()['data'][0];
		$this->assertArrayHasKey('roomTask', $row);
		$this->assertArrayHasKey('task', $row);
		$this->assertArrayHasKey('room', $row);
		// Canonical user-ID array (D1): unique, deterministically ordered.
		$this->assertSame(['alice', 'bob'], $row['assignees']);
	}

	/**
	 * DueToday_ReturnsCanonicalEnrichedRows
	 *
	 * CS-PLAN-066 §3.1 (D1): due-today mirrors the enriched overdue rows.
	 */
	public function testDueToday_ReturnsCanonicalEnrichedRows(): void {
		$house = new House();
		$house->setId(1);
		$houseUser = $this->createHouseUser();

		$roomTask = new RoomTask();
		$roomTask->setId(12);
		$roomTask->setRoomId(3);
		$roomTask->setTaskId(4);
		$task = new Task();
		$task->setId(4);
		$room = new Room();
		$room->setId(3);

		$this->houseService->method('getInstanceId')->willReturn('instance-123');
		$this->houseService->method('getOrCreateHouse')->willReturn($house);
		$this->authService->method('assertHouseMember')->willReturn($houseUser);

		$this->roomTaskReadService->expects($this->once())
			->method('getDueTodayTaskRows')
			->with(1, $this->identicalTo($houseUser), 3)
			->willReturn([['roomTask' => $roomTask, 'task' => $task, 'room' => $room]]);
		$this->roomTaskReadService->method('assigneesByRoomTasks')
			->willReturn([12 => []]);

		$response = $this->controller->dueToday(3);

		$this->assertEquals(200, $response->getStatus());
		$row = $response->getData()['data'][0];
		$this->assertArrayHasKey('roomTask', $row);
		$this->assertArrayHasKey('task', $row);
		$this->assertArrayHasKey('room', $row);
		$this->assertSame([], $row['assignees']);
	}

	/**
	 * Create_ReturnsCanonicalUserIdAssignees
	 *
	 * CS-PLAN-066 (D1): the aggregate's `assignees` are a user-ID string
	 * array (unique, deterministically ordered), not relation objects.
	 */
	public function testCreate_ReturnsCanonicalUserIdAssignees(): void {
		$house = new House();
		$house->setId(42);
		$houseUser = $this->createHouseUser();

		$assigneeA = new \OCA\ChoreStrike\Db\TaskAssignee();
		$assigneeA->setUserId('mom');
		$assigneeB = new \OCA\ChoreStrike\Db\TaskAssignee();
		$assigneeB->setUserId('dad');

		$this->stubCreateParams(['roomId' => 1, 'taskId' => 5]);
		$this->houseService->method('getInstanceId')->willReturn('instance-123');
		$this->houseService->method('getOrCreateHouse')->willReturn($house);
		$this->authService->method('assertHouseMember')->willReturn($houseUser);

		$this->taskCreationService->method('create')->willReturn([
			'task' => new Task(),
			'roomTask' => new RoomTask(),
			'assignees' => [$assigneeA, $assigneeB],
		]);

		$response = $this->controller->create();

		$this->assertEquals(200, $response->getStatus());
		$this->assertSame(['mom', 'dad'], $response->getData()['data']['assignees']);
	}

	/**
	 * SetAssignees_ReturnsCanonicalUserIdArray
	 *
	 * CS-PLAN-066 (D1): the PUT assignees response IS the persisted
	 * user-ID set — no client-side union on relation objects.
	 */
	public function testSetAssignees_ReturnsCanonicalUserIdArray(): void {
		$house = new House();
		$house->setId(1);
		$houseUser = $this->createHouseUser();

		$assignee = new \OCA\ChoreStrike\Db\TaskAssignee();
		$assignee->setUserId('mom');

		$this->houseService->method('getInstanceId')->willReturn('instance-123');
		$this->houseService->method('getOrCreateHouse')->willReturn($house);
		$this->authService->method('assertHouseMember')->willReturn($houseUser);
		$this->roomTaskService->method('validateRoomTaskOwnership');

		$this->roomTaskService->expects($this->once())
			->method('setAssignees')
			->with(7, ['mom'], 1, $this->identicalTo($houseUser))
			->willReturn([$assignee]);

		$response = $this->controller->setAssignees(7, ['mom']);

		$this->assertEquals(200, $response->getStatus());
		$this->assertSame(['mom'], $response->getData()['data']);
	}

	/**
	 * BulkAssign_CarriesCanonicalAssigneeSetsOnReturnedSuccesses
	 *
	 * CS-PLAN-066 (D1): the bulk response embeds each returned success's
	 * persisted assignee set — the client reconciles exactly the server
	 * result, never the requested set.
	 */
	public function testBulkAssign_CarriesCanonicalAssigneeSetsOnReturnedSuccesses(): void {
		$house = new House();
		$house->setId(1);
		$houseUser = $this->createHouseUser();

		$roomTask = new RoomTask();
		$roomTask->setId(3);
		$roomTask->setRoomId(1);
		$roomTask->setTaskId(2);

		$this->request->method('getParam')->willReturnCallback(
			static fn (string $key) => ['roomTaskIds' => [3, 4], 'userId' => 'mom'][$key] ?? null
		);
		$this->houseService->method('getInstanceId')->willReturn('instance-123');
		$this->houseService->method('getOrCreateHouse')->willReturn($house);
		$this->authService->method('assertHouseMember')->willReturn($houseUser);

		$this->roomTaskService->expects($this->once())
			->method('bulkAssignTasks')
			->with([3, 4], 'mom', 1, $this->identicalTo($houseUser))
			->willReturn([$roomTask]);
		$this->roomTaskReadService->method('assigneesByRoomTasks')
			->willReturn([3 => ['mom']]);

		$response = $this->controller->bulkAssign();

		$this->assertEquals(200, $response->getStatus());
		$data = $response->getData()['data'];
		$this->assertCount(1, $data);
		$this->assertSame(['mom'], $data[0]['assignees']);
	}
}
