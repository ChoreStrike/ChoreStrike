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
use OCA\ChoreStrike\Service\TodayRows;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for RoomTaskController
 */
class RoomTaskControllerTest extends TestCase {
	private RoomTaskController $controller;
	private AppLogger $appLogger;
	private $roomTaskService;
	private $roomTaskReadService;
	private $completionService;
	private $taskCreationService;
	private $todayRows;
	private $houseService;
	private $authService;
	private $request;

	protected function setUp(): void {
		$this->appLogger = $this->createMock(AppLogger::class);
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->roomTaskService = $this->createMock(RoomTaskService::class);
		$this->roomTaskReadService = $this->createMock(\OCA\ChoreStrike\Service\RoomTaskReadService::class);
		$this->roomTaskReadService->method('committedRoomTaskWithCycle')
			->willReturn(['roomTask' => ['id' => 3], 'cycle' => ['state' => 'upcoming', 'cycleKey' => 'c:2026-11-24', 'expectedVersion' => 1]]);
		$this->completionService = $this->createMock(CompletionService::class);
		$this->taskCreationService = $this->createMock(TaskCreationService::class);
		$this->houseService = $this->createMock(HouseService::class);
		$this->authService = $this->createMock(AuthorizationService::class);
		// Identity mock: message assertions match the wrapped source text.
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text) => $text);

		$this->todayRows = $this->createMock(TodayRows::class);
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
			$this->appLogger,
			$this->todayRows,
		);
	}

	/**
	 * Stub the request params read by the creation endpoint.
	 */
	private function stubCreateParams(array $params): void {
		$this->request->method('getParams')->willReturn([...$params, '_route' => 'chorestrike.room_task.create']);
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
			->willReturn(new \OCA\ChoreStrike\Service\RoomTaskList([$roomTask1, $roomTask2], []));

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
	 * the missing room-ownership validation (CR-053-02): membership alone
	 * let any member read foreign houses' room tasks incl. assignee user
	 * ids.
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
			->willReturn(new \OCA\ChoreStrike\Service\RoomTaskList([$roomTask], []));

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
			'cycleKey' => 'c41',
			'expectedVersion' => 0,
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
				'c41',
				0
			)
			->willReturn([
				'roomTask' => $roomTask,
				'room' => $room,
				'task' => $task,
				'rewards' => [],
				'completionId' => 5,
				'cycle' => ['cycleKey' => 'c42', 'state' => 'open'],
				'replay' => false,
			]);
		// CR-084-03: the row status follows the fresh cycle block.
		$this->roomTaskReadService->expects($this->once())->method('roomTaskPayload')
			->with($roomTask, $this->isInstanceOf(\DateTimeZone::class), ['cycleKey' => 'c42', 'state' => 'open'])
			->willReturn(['id' => 1, 'status' => 'due_today']);

		$response = $this->controller->complete(1);

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(200, $response->getStatus());

		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertSame(['id' => 1, 'status' => 'due_today'], $data['data']['roomTask']);
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
	 * Unknown receipt fields are REJECTED with 400, never silently
	 * ignored (parent §3.4; `participants`/`allocationReviewed` are part
	 * of the WP3 closed set now).
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
			'cycleKey' => 'c41',
			'expectedVersion' => 0,
			'boosts' => ['double_points'],
		]);

		$this->completionService->expects($this->never())->method('complete');

		$response = $this->controller->complete(1);

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(400, $response->getStatus());
		$this->assertStringContainsString('Unknown field', $response->getData()['error']['message']);
	}

	/**
	 * A cycle key that is not one never reaches the service (ADR-021
	 * decision 2): the retired occurrence identity, a wrong date shape and
	 * a non-string are all validation errors.
	 *
	 * @dataProvider malformedCycleKeys
	 */
	public function testComplete_MalformedCycleKey_Answers400(mixed $cycleKey): void {
		$house = new House();
		$house->setId(1);

		$this->houseService->method('getInstanceId')->willReturn('instance-123');
		$this->houseService->method('getOrCreateHouse')->willReturn($house);
		$this->authService->method('assertHouseMember');
		$this->roomTaskService->method('validateRoomTaskOwnership');
		$this->request->method('getParams')->willReturn([
			'commandId' => '11111111-2222-4333-8444-555555555555',
			'cycleKey' => $cycleKey,
			'expectedVersion' => 0,
		]);

		$this->completionService->expects($this->never())->method('complete');

		$response = $this->controller->complete(1);

		$this->assertEquals(400, $response->getStatus());
		$this->assertSame('VALIDATION_ERROR', $response->getData()['error']['code']);
		$this->assertSame('Invalid cycle key', $response->getData()['error']['message']);
	}

	/** @return array<string, array{mixed}> */
	public static function malformedCycleKeys(): array {
		return [
			'the retired occurrence identity' => ['12:3'],
			'a projected cycle' => ['p2026-11-21'],
			'a date without its day' => ['d2026-11'],
			'a completion id of zero' => ['c0'],
			'trailing input' => ["c41\n"],
			'an empty string' => [''],
			'a number' => [41],
			'an array' => [['c41']],
		];
	}

	/**
	 * A bonus task has no cycle (ADR-021 decision 8): the receipt carries
	 * no cycle key and the service receives none.
	 */
	public function testComplete_WithoutCycleKey_PassesNoneToTheService(): void {
		$house = new House();
		$house->setId(1);
		$house->setTimezone('UTC');

		$this->houseService->method('getInstanceId')->willReturn('instance-123');
		$this->houseService->method('getOrCreateHouse')->willReturn($house);
		$this->authService->method('assertHouseMember');
		$this->roomTaskService->method('validateRoomTaskOwnership');
		$this->authService->method('requireCurrentUserId')->willReturn('user1');
		$this->request->method('getParams')->willReturn([
			'commandId' => '11111111-2222-4333-8444-555555555555',
			'expectedVersion' => 3,
		]);

		$roomTask = new RoomTask();
		$roomTask->setId(1);
		$this->completionService->expects($this->once())
			->method('complete')
			->with(1, 'user1', 1, '11111111-2222-4333-8444-555555555555', null, 3)
			->willReturn([
				'roomTask' => $roomTask,
				'room' => new Room(),
				'task' => new Task(),
				'rewards' => [],
				'completionId' => 5,
				'cycle' => ['state' => 'bonus', 'expectedVersion' => 3],
				'replay' => false,
			]);
		$this->roomTaskReadService->method('roomTaskPayload')->willReturn(['id' => 1, 'status' => 'pending']);

		$response = $this->controller->complete(1);

		$this->assertEquals(200, $response->getStatus());
		$this->assertTrue($response->getData()['success']);
	}

	/**
	 * Complete_MalformedParticipant_Answers400 (WP3 §3.6)
	 *
	 * The closed participant set requires {userId, sharePct} entries —
	 * a legacy boost-style `{userId, share}` entry is rejected.
	 */
	public function testComplete_MalformedParticipant_Answers400(): void {
		$house = new House();
		$house->setId(1);

		$this->houseService->method('getInstanceId')->willReturn('instance-123');
		$this->houseService->method('getOrCreateHouse')->willReturn($house);
		$this->authService->method('assertHouseMember');
		$this->roomTaskService->method('validateRoomTaskOwnership');
		$this->request->method('getParams')->willReturn([
			'commandId' => '11111111-2222-4333-8444-555555555555',
			'cycleKey' => 'c41',
			'expectedVersion' => 0,
			'participants' => [['userId' => 'mom', 'share' => 50]],
		]);

		$this->completionService->expects($this->never())->method('complete');

		$response = $this->controller->complete(1);

		$this->assertEquals(400, $response->getStatus());
		$this->assertSame('VALIDATION_ERROR', $response->getData()['error']['code']);
		$this->assertSame(
			'Every participant needs a user id and a whole percent share',
			$response->getData()['error']['message']
		);
	}

	/**
	 * Undo_SurfaceMovedToCompletionController (WP3 §3.4)
	 *
	 * The latest-by-room-task undo route is retired: correction targets a
	 * specific numeric completion through the CompletionController.
	 */
	public function testUndo_SurfaceMovedToCompletionController(): void {
		$this->assertFalse(
			method_exists($this->controller, 'undo'),
			'the latest-by-room-task undo endpoint must stay removed'
		);
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
	 * Create_ForwardsTheFormBody
	 *
	 * CS-PLAN-063 §3.3, CS-PLAN-091 WP-B6: the creation endpoint routes the
	 * form's body (without the route internals) through the atomic
	 * TaskCreationService with the caller's house id and the acting
	 * HouseUser, and returns the unified aggregate shape.
	 */
	public function testCreate_ForwardsTheFormBody(): void {
		$house = new House();
		$house->setId(42);
		$houseUser = $this->createHouseUser();

		$this->stubCreateParams(['roomId' => 1, 'task' => ['name' => 'Vacuum'], 'checklistItems' => [['label' => 'Carpet']]]);
		$this->houseService->method('getInstanceId')->willReturn('instance-123');
		$this->houseService->method('getOrCreateHouse')->willReturn($house);
		$this->authService->method('assertHouseMember')->willReturn($houseUser);

		$this->taskCreationService->expects($this->once())
			->method('create')
			->with(42, $this->identicalTo($houseUser), $this->callback(
				fn (array $payload) => $payload === ['roomId' => 1, 'task' => ['name' => 'Vacuum'], 'checklistItems' => [['label' => 'Carpet']]]
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
		$this->assertSame(1, $response->getData()['data']['cycle']['expectedVersion'], 'the new row is a command target at once');
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
	 * A definition outside the closed set answers 400 with every message.
	 */
	public function testCreate_ValidationRefusal_Is400WithTheDetails(): void {
		$house = new House();
		$house->setId(42);

		$this->stubCreateParams(['roomId' => 1, 'task' => ['energyCost' => 99]]);
		$this->houseService->method('getInstanceId')->willReturn('instance-123');
		$this->houseService->method('getOrCreateHouse')->willReturn($house);
		$this->authService->method('assertHouseMember');
		$this->taskCreationService->method('create')->willThrowException(
			new \OCA\ChoreStrike\Exception\ValidationException('Energy cost must be between 1 and 20', ['Energy cost must be between 1 and 20', 'Task name is required'])
		);

		$response = $this->controller->create();

		$this->assertEquals(400, $response->getStatus());
		$this->assertSame(['Energy cost must be between 1 and 20', 'Task name is required'], $response->getData()['error']['details']);
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
			->willReturn([['roomTask' => $roomTask, 'task' => $task, 'room' => $room, 'checklistItemCount' => 2]]);
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
		// BUG-UX-003: the service's checklist count reaches the client.
		$this->assertSame(2, $row['checklistItemCount']);
	}

	/**
	 * BUG-UX-003 regression: the My Day read keeps the service's checklist
	 * count and open-window flag in its explicit row shape (the browser gate
	 * caught the count missing from the HTTP payload).
	 */
	public function testFilteredDaily_RowsCarryTheChecklistCountAndOpenWindow(): void {
		$house = new House();
		$house->setId(1);
		$roomTask = new RoomTask();
		$roomTask->setId(11);
		$task = new Task();
		$task->setId(4);
		$this->houseService->method('getOrCreateHouse')->willReturn($house);
		$this->authService->method('assertHouseMember')->willReturn($this->createHouseUser());
		$this->todayRows->method('forViewer')->willReturn(['load' => null, 'rows' => [
			['roomTask' => $roomTask, 'task' => $task, 'room' => new Room(), 'assignees' => [], 'checklistItemCount' => 3, 'openWindow' => true],
			['roomTask' => $roomTask, 'task' => $task, 'room' => new Room(), 'assignees' => []],
		]]);

		$rows = $this->controller->filteredDaily()->getData()['data']['rows'];

		$this->assertSame([3, 0], array_column($rows, 'checklistItemCount'));
		$this->assertSame([true, false], array_column($rows, 'openWindow'));
	}

	/**
	 * WP-B4 (ADR-021 decision 8): the bonus rows have the row shape of Today
	 * and say whom each task already counted for on this day.
	 */
	public function testBonus_RowsCarryTheirCycleAndWhoIsCreditedToday(): void {
		$house = new House();
		$house->setId(1);
		$roomTask = new RoomTask();
		$roomTask->setId(40);
		$task = new Task();
		$task->setId(9);
		$viewer = $this->createHouseUser();
		$cycle = ['state' => 'bonus', 'expectedVersion' => 2];
		$this->houseService->method('getOrCreateHouse')->willReturn($house);
		$this->authService->method('assertHouseMember')->willReturn($viewer);
		$this->roomTaskReadService->expects($this->once())->method('getBonusTaskRows')->with(1, $viewer)->willReturn([
			['roomTask' => $roomTask, 'task' => $task, 'room' => new Room(), 'assignees' => ['kid'], 'cycle' => $cycle, 'creditedToday' => ['kid']],
			['roomTask' => $roomTask, 'task' => $task, 'room' => new Room(), 'assignees' => [], 'cycle' => $cycle, 'creditedToday' => []],
		]);
		$this->roomTaskReadService->method('roomTaskPayload')->willReturn(['id' => 40]);

		$response = $this->controller->bonus();

		$this->assertSame(200, $response->getStatus());
		$rows = $response->getData()['data'];
		$this->assertSame(
			['roomTask', 'task', 'room', 'assignees', 'cycle', 'openWindow', 'checklistItemCount', 'creditedToday'],
			array_keys($rows[0])
		);
		$this->assertSame([['kid'], []], array_column($rows, 'creditedToday'));
		$this->assertSame([$cycle, $cycle], array_column($rows, 'cycle'));
		$this->assertSame([['kid'], []], array_column($rows, 'assignees'));
	}

	public function testBonus_ANonMemberIsRefused_AndAFailureHidesItsDetail(): void {
		$house = new House();
		$house->setId(1);
		$this->houseService->method('getOrCreateHouse')->willReturn($house);
		$this->authService->method('assertHouseMember')->willReturnOnConsecutiveCalls(
			$this->throwException(new \OCA\ChoreStrike\Exception\ForbiddenException('Not a member of this house')),
			$this->createHouseUser()
		);
		$this->roomTaskReadService->method('getBonusTaskRows')->willThrowException(new \RuntimeException('connection lost'));

		$refused = $this->controller->bonus();
		$failed = $this->controller->bonus();

		$this->assertSame([403, 'FORBIDDEN'], [$refused->getStatus(), $refused->getData()['error']['code']]);
		$this->assertSame(500, $failed->getStatus());
		$this->assertSame(['code' => 'BONUS_TASKS_ERROR', 'message' => 'An unexpected error occurred'], $failed->getData()['error']);
	}

	/** The daily rows keep their shape: who is credited today is a fact of bonus rows only. */
	public function testFilteredDaily_RowsHaveNoCreditedToday(): void {
		$house = new House();
		$house->setId(1);
		$this->houseService->method('getOrCreateHouse')->willReturn($house);
		$this->authService->method('assertHouseMember')->willReturn($this->createHouseUser());
		$this->todayRows->method('forViewer')->willReturn(['load' => null, 'rows' => [
			['roomTask' => new RoomTask(), 'task' => new Task(), 'room' => new Room(), 'assignees' => []],
		]]);

		$this->assertArrayNotHasKey('creditedToday', $this->controller->filteredDaily()->getData()['data']['rows'][0]);
	}

	/**
	 * RF-091-14: the daily read hands the House it resolved to the rows
	 * and answers the viewer's load of today next to them.
	 */
	public function testFilteredDaily_AnswersTheRowsAndTheViewersLoad(): void {
		$house = new House();
		$house->setId(1);
		$viewer = $this->createHouseUser();
		$load = ['userId' => 'mom', 'day' => '2026-03-07', 'budget' => 6, 'placed' => 4, 'doneMinor' => 150, 'preferred' => false, 'absent' => false];
		$this->houseService->method('getOrCreateHouse')->willReturn($house);
		$this->authService->method('assertHouseMember')->willReturn($viewer);
		$this->todayRows->expects($this->once())->method('forViewer')->with($house, $viewer)->willReturn(['rows' => [], 'load' => $load]);

		$this->assertSame(['rows' => [], 'load' => $load], $this->controller->filteredDaily()->getData()['data']);
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
			->willReturn([['roomTask' => $roomTask, 'task' => $task, 'room' => $room, 'cycle' => ['cycleKey' => 'c9', 'state' => 'overdue']]]);
		$this->roomTaskReadService->method('assigneesByRoomTasks')
			->willReturn([12 => []]);
		// CR-084-03: an execution row's status follows its own cycle block.
		$this->roomTaskReadService->expects($this->once())->method('roomTaskPayload')
			->with($roomTask, $this->isInstanceOf(\DateTimeZone::class), ['cycleKey' => 'c9', 'state' => 'overdue'])
			->willReturn(['id' => 12, 'status' => 'overdue']);

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

	private function wireRoomTaskAction(): void {
		$house = new House();
		$house->setId(1);
		$this->houseService->method('getInstanceId')->willReturn('instance-123');
		$this->houseService->method('getOrCreateHouse')->willReturn($house);
		$this->authService->method('assertHouseMember')->willReturn($this->createHouseUser());
	}

	/**
	 * CR-084-03 and the CS-PLAN-091 WP-B1 interim review: a replayed
	 * completion carries the fresh cycle block like the first run, so the
	 * client never keeps the closed cycle as its target and the row status
	 * follows the block.
	 */
	public function testComplete_ReplayCarriesTheFreshCycleBlock(): void {
		$house = new House();
		$house->setId(1);
		$house->setTimezone('UTC');
		$this->houseService->method('getInstanceId')->willReturn('instance-123');
		$this->houseService->method('getOrCreateHouse')->willReturn($house);
		$this->authService->method('requireCurrentUserId')->willReturn('user1');
		$this->request->method('getParams')->willReturn([
			'commandId' => '11111111-2222-4333-8444-555555555555',
			'cycleKey' => 'c41',
			'expectedVersion' => 0,
		]);
		$roomTask = new RoomTask();
		$roomTask->setId(1);
		$block = ['cycleKey' => 'c42', 'expectedVersion' => 3, 'state' => 'upcoming'];
		$this->completionService->method('complete')->willReturn([
			'roomTask' => $roomTask, 'room' => new Room(), 'task' => new Task(),
			'rewards' => [], 'completionId' => 5, 'replay' => true, 'cycle' => $block,
		]);
		$this->roomTaskReadService->expects($this->never())->method('committedRoomTaskPayload');
		$this->roomTaskReadService->expects($this->once())->method('roomTaskPayload')
			->with($roomTask, $this->isInstanceOf(\DateTimeZone::class), $block)
			->willReturn(['id' => 1, 'status' => 'upcoming']);

		$response = $this->controller->complete(1);

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['id' => 1, 'status' => 'upcoming'], $response->getData()['data']['roomTask']);
		$this->assertSame($block, $response->getData()['data']['cycle']);
	}
}
