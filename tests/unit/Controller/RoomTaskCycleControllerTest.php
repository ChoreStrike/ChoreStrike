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
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for RoomTaskCycleController (CS-PLAN-091 WP-B3): the closed
 * receipt of the postpone command, its stable refusal codes and the task
 * history read.
 */
class RoomTaskCycleControllerTest extends TestCase {
	private const UUID = '11111111-2222-4333-8444-555555555555';

	private RoomTaskCycleController $controller;
	private PostponeService $postponeService;
	private ActivityService $activityService;
	private RoomTaskReadService $roomTaskReadService;
	private AuthorizationService $authService;
	private IRequest $request;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->postponeService = $this->createMock(PostponeService::class);
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
