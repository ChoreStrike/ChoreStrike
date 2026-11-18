<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Controller;

use OCA\ChoreStrike\Controller\RoomTaskCycleController;
use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Exception\ConflictException;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Exception\NotFoundException;
use OCA\ChoreStrike\Exception\StaleTargetException;
use OCA\ChoreStrike\Service\ActivityService;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\PostponeService;
use OCA\ChoreStrike\Service\RoomTaskReadService;
use OCA\ChoreStrike\Service\SleepService;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for RoomTaskCycleController (CS-PLAN-091 WP-B3 and WP-B4): the
 * closed receipts of the postpone and sleep commands, their stable refusal
 * codes and the task history read.
 */
class RoomTaskCycleControllerTest extends TestCase {
	private const UUID = '11111111-2222-4333-8444-555555555555';

	private RoomTaskCycleController $controller;
	private PostponeService $postponeService;
	private SleepService $sleepService;
	private ActivityService $activityService;
	private RoomTaskReadService $roomTaskReadService;
	private AuthorizationService $authService;
	private IRequest $request;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->postponeService = $this->createMock(PostponeService::class);
		$this->sleepService = $this->createMock(SleepService::class);
		$this->activityService = $this->createMock(ActivityService::class);
		$this->roomTaskReadService = $this->createMock(RoomTaskReadService::class);
		$this->roomTaskReadService->method('timeZoneForHouse')->willReturn(new \DateTimeZone('UTC'));
		$this->authService = $this->createMock(AuthorizationService::class);

		$house = new House();
		$house->setId(1);
		$houseService = $this->createMock(HouseService::class);
		$houseService->method('getInstanceId')->willReturn('instance-123');
		$houseService->method('getOrCreateHouse')->willReturn($house);
		$child = new HouseUser();
		$child->setId(11);
		$child->setRole('child');
		$this->authService->method('assertHouseMember')->willReturn($child);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []) => vsprintf($text, $params));

		$this->controller = new RoomTaskCycleController(
			$this->request,
			$houseService,
			$this->authService,
			$this->postponeService,
			$this->sleepService,
			$this->activityService,
			$this->roomTaskReadService,
			$l10n,
			$this->createMock(AppLogger::class)
		);
	}

	/**
	 * Postpone_DelegatesTheShownTarget_AndAnswersTheFreshCycle
	 */
	public function testPostpone_DelegatesTheShownTarget_AndAnswersTheFreshCycle(): void {
		$this->request->method('getParams')->willReturn([
			'id' => '7', '_route' => 'chorestrike.room_task_cycle.postpone',
			'commandId' => strtoupper(self::UUID), 'cycleKey' => 'd2026-11-18', 'expectedVersion' => 4,
		]);
		$roomTask = new RoomTask();
		$cycle = ['cycleKey' => 'd2026-11-18', 'expectedVersion' => 5, 'postponeCount' => 1];
		// Every member may postpone: no role gate ahead of the service.
		$this->postponeService->expects($this->once())->method('postpone')
			->with(1, $this->isInstanceOf(HouseUser::class), 7, self::UUID, 'd2026-11-18', 4)
			->willReturn(['roomTask' => $roomTask, 'cycle' => $cycle, 'replay' => false]);
		$this->roomTaskReadService->expects($this->once())->method('roomTaskPayload')
			->with($roomTask, $this->isInstanceOf(\DateTimeZone::class), $cycle)
			->willReturn(['id' => 7, 'status' => 'due_today']);

		$response = $this->controller->postpone(7);

		$this->assertEquals(200, $response->getStatus());
		$this->assertSame(
			['roomTask' => ['id' => 7, 'status' => 'due_today'], 'cycle' => $cycle, 'replay' => false],
			$response->getData()['data']
		);
	}

	/** @return array<string, array{array<string, mixed>}> */
	public static function invalidReceipts(): array {
		$valid = ['commandId' => self::UUID, 'cycleKey' => 'c88', 'expectedVersion' => 4];
		return [
			'an unknown field' => [$valid + ['days' => 3]],
			'no command id' => [['commandId' => 'nope'] + $valid],
			'no cycle key' => [array_diff_key($valid, ['cycleKey' => true])],
			'a projected cycle key' => [['cycleKey' => 'p2026-11-25'] + $valid],
			'a negative version' => [['expectedVersion' => -1] + $valid],
		];
	}

	/**
	 * Postpone_InvalidReceipt_Answers400
	 *
	 * @dataProvider invalidReceipts
	 */
	public function testPostpone_InvalidReceipt_Answers400(array $payload): void {
		$this->request->method('getParams')->willReturn($payload);
		$this->postponeService->expects($this->never())->method('postpone');

		$response = $this->controller->postpone(7);

		$this->assertEquals(400, $response->getStatus());
		$this->assertSame('VALIDATION_ERROR', $response->getData()['error']['code']);
	}

	/** @return array<string, array{\Exception, int, string}> */
	public static function refusals(): array {
		return [
			'no flex days or three presses' => [new ConflictException('no', ConflictException::REASON_NOT_POSTPONABLE), 409, 'NOT_POSTPONABLE'],
			'a red task' => [new ConflictException('no', ConflictException::REASON_WINDOW_ENDED), 409, 'WINDOW_ENDED'],
			'a stale target' => [new StaleTargetException('stale'), 409, 'STALE_TARGET'],
			'an invisible task' => [new NotFoundException('Room task not found'), 404, 'RESOURCE_NOT_FOUND'],
			'a member who left' => [new ForbiddenException('Not a member of this house'), 403, 'FORBIDDEN'],
		];
	}

	/**
	 * Postpone_Refusals_CarryTheirStableCode
	 *
	 * @dataProvider refusals
	 */
	public function testPostpone_Refusals_CarryTheirStableCode(\Exception $refusal, int $status, string $code): void {
		$this->request->method('getParams')->willReturn(['commandId' => self::UUID, 'cycleKey' => 'c88', 'expectedVersion' => 4]);
		$this->postponeService->method('postpone')->willThrowException($refusal);

		$response = $this->controller->postpone(7);

		$this->assertEquals($status, $response->getStatus());
		$this->assertSame($code, $response->getData()['error']['code']);
	}

	/** @return array<string, array{array<string, mixed>, bool, ?string}> */
	public static function sleepBodies(): array {
		return [
			'to sleep without a wake date' => [['sleeping' => true], true, null],
			'to sleep until a day' => [['sleeping' => true, 'wakeOn' => '2026-12-01'], true, '2026-12-01'],
			'an explicit null wake date' => [['sleeping' => true, 'wakeOn' => null], true, null],
			'wake' => [['sleeping' => false], false, null],
		];
	}

	/**
	 * WP-B4 (ADR-021 decision 7): the sleep command names the room task and
	 * the version it showed, never a cycle.
	 *
	 * @dataProvider sleepBodies
	 */
	public function testSleep_DelegatesTheShownVersion_AndAnswersTheFreshCycle(array $body, bool $sleeping, ?string $wakeOn): void {
		$this->request->method('getParams')->willReturn([
			'id' => '7', '_route' => 'chorestrike.room_task_cycle.sleep',
			'commandId' => strtoupper(self::UUID), 'expectedVersion' => 4,
		] + $body);
		$roomTask = new RoomTask();
		$cycle = $sleeping ? ['state' => 'asleep', 'expectedVersion' => 5, 'wakeOn' => $wakeOn] : ['cycleKey' => 'd2026-11-18', 'expectedVersion' => 5];
		// The role is the service's decision: no gate ahead of it.
		$this->sleepService->expects($this->once())->method('setSleeping')
			->with(1, $this->isInstanceOf(HouseUser::class), 7, self::UUID, 4, $sleeping, $wakeOn)
			->willReturn(['roomTask' => $roomTask, 'cycle' => $cycle, 'replay' => false]);
		$this->roomTaskReadService->expects($this->once())->method('roomTaskPayload')
			->with($roomTask, $this->isInstanceOf(\DateTimeZone::class), $cycle)
			->willReturn(['id' => 7]);

		$response = $this->controller->sleep(7);

		$this->assertEquals(200, $response->getStatus());
		$this->assertSame(['roomTask' => ['id' => 7], 'cycle' => $cycle, 'replay' => false], $response->getData()['data']);
	}

	/** @return array<string, array{array<string, mixed>}> */
	public static function invalidSleepBodies(): array {
		$valid = ['commandId' => self::UUID, 'expectedVersion' => 4, 'sleeping' => true];
		return [
			'no sleeping flag' => [array_diff_key($valid, ['sleeping' => true])],
			'a sleeping flag as text' => [['sleeping' => 'true'] + $valid],
			'a wake date that is no text' => [$valid + ['wakeOn' => 20261201]],
			'a cycle key: the command names no cycle' => [$valid + ['cycleKey' => 'd2026-11-18']],
			'an unknown field' => [$valid + ['days' => 3]],
			'no version' => [array_diff_key($valid, ['expectedVersion' => true])],
			'no command id' => [array_diff_key($valid, ['commandId' => true])],
		];
	}

	/** @dataProvider invalidSleepBodies */
	public function testSleep_InvalidBody_Answers400WithoutAWrite(array $payload): void {
		$this->request->method('getParams')->willReturn($payload);
		$this->sleepService->expects($this->never())->method('setSleeping');

		$response = $this->controller->sleep(7);

		$this->assertEquals(400, $response->getStatus());
		$this->assertSame('VALIDATION_ERROR', $response->getData()['error']['code']);
	}

	/** @return array<string, array{\Exception, int, string, string}> */
	public static function sleepRefusals(): array {
		return [
			'an inactive task' => [new ConflictException('This task is not active'), 409, 'CONFLICT', 'This task is not active'],
			'a stale version' => [new StaleTargetException('stale'), 409, 'STALE_TARGET', 'stale'],
			'an invisible task' => [new NotFoundException('Room task not found'), 404, 'RESOURCE_NOT_FOUND', 'Room task not found'],
			'a child' => [new ForbiddenException('Children cannot manage tasks'), 403, 'FORBIDDEN', 'Children cannot manage tasks'],
			// The wake date rule is the service's, with its curated message.
			'a wake date that has passed' => [new \InvalidArgumentException('The wake date must be after today'), 400, 'VALIDATION_ERROR', 'The wake date must be after today'],
			'anything unexpected' => [new \RuntimeException('connection lost'), 500, 'SLEEP_TASK_ERROR', 'An unexpected error occurred'],
		];
	}

	/** @dataProvider sleepRefusals */
	public function testSleep_Refusals_CarryTheirStableCode(\Exception $refusal, int $status, string $code, string $message): void {
		$this->request->method('getParams')->willReturn(['commandId' => self::UUID, 'expectedVersion' => 4, 'sleeping' => true]);
		$this->sleepService->method('setSleeping')->willThrowException($refusal);

		$response = $this->controller->sleep(7);

		$this->assertEquals($status, $response->getStatus());
		$this->assertSame(['code' => $code, 'message' => $message], $response->getData()['error']);
	}

	/**
	 * History_ReturnsTheEntriesOfTheTask
	 */
	public function testHistory_ReturnsTheEntriesOfTheTask(): void {
		$this->activityService->expects($this->exactly(2))->method('getRoomTaskHistory')
			->withConsecutive(
				[1, 7, ActivityService::HISTORY_LIMIT, $this->isInstanceOf(HouseUser::class)],
				[1, 7, 25, $this->isInstanceOf(HouseUser::class)],
			)
			->willReturn(['completions' => [['id' => 42]]]);

		$response = $this->controller->history(7);
		$this->controller->history(7, 25);

		$this->assertEquals(200, $response->getStatus());
		$this->assertSame(['completions' => [['id' => 42]]], $response->getData()['data']);
	}

	/**
	 * History_InvisibleTask_Maps404AntiEnumeration
	 */
	public function testHistory_InvisibleTask_Maps404AntiEnumeration(): void {
		$this->activityService->method('getRoomTaskHistory')->willThrowException(new NotFoundException('Room task not found'));

		$response = $this->controller->history(7);

		$this->assertEquals(404, $response->getStatus());
		$this->assertSame('RESOURCE_NOT_FOUND', $response->getData()['error']['code']);
	}
}
