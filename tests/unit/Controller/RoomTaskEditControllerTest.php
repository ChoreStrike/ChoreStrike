<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Controller;

use OCA\ChoreStrike\Controller\RoomTaskEditController;
use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Db\TaskChecklistItem;
use OCA\ChoreStrike\Exception\ConflictException;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Exception\NotFoundException;
use OCA\ChoreStrike\Exception\ValidationException;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\CalendarService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\RoomTaskEditService;
use OCA\ChoreStrike\Service\RoomTaskReadService;
use OCA\ChoreStrike\Service\TaskEdit;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * The one form's save on an existing task (CS-PLAN-091 WP-B6): the closed
 * body, the delegation to the edit aggregate, the answer and the mapping
 * of every refusal to its status and stable code.
 */
class RoomTaskEditControllerTest extends TestCase {
	private const UUID = '11111111-2222-4333-8444-555555555555';

	private RoomTaskEditService $editService;
	private RoomTaskReadService $roomTaskReadService;
	private AppLogger $appLogger;

	protected function setUp(): void {
		parent::setUp();
		$this->editService = $this->createMock(RoomTaskEditService::class);
		$this->roomTaskReadService = $this->createMock(RoomTaskReadService::class);
		$this->appLogger = $this->createMock(AppLogger::class);
	}

	private function controller(array $body): RoomTaskEditController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn(['id' => '7', '_route' => 'chorestrike.room_task_edit.update', ...$body]);
		$house = new House();
		$house->setId(3);
		$houseService = $this->createMock(HouseService::class);
		$houseService->method('getInstanceId')->willReturn('instance-123');
		$houseService->method('getOrCreateHouse')->willReturn($house);
		$adult = new HouseUser();
		$adult->setId(11);
		$adult->setRole('member');
		$authService = $this->createMock(AuthorizationService::class);
		$authService->method('assertHouseMember')->willReturn($adult);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []) => vsprintf($text, $params));
		return new RoomTaskEditController(
			$request, $houseService, $authService, $this->editService, $this->roomTaskReadService, new CalendarService(), $l10n, $this->appLogger
		);
	}

	public function testUpdate_DelegatesTheSave_AndAnswersTheFreshAggregate(): void {
		$roomTask = new RoomTask();
		$task = new Task();
		$task->setId(9);
		$task->setName('Vacuum');
		$item = new TaskChecklistItem();
		$item->setLabel('Carpet');
		$cycle = ['cycleKey' => 'c88', 'expectedVersion' => 5];
		$zone = new \DateTimeZone('Europe/Berlin');
		$this->editService->expects($this->once())->method('edit')
			->with(3, $this->isInstanceOf(HouseUser::class), 7, $this->callback(static fn (TaskEdit $edit): bool => $edit->commandId === self::UUID
				&& $edit->expectedVersion === 4 && $edit->scope === TaskEdit::SCOPE_ALL_ROOMS && $edit->form->definition === ['name' => 'Vacuum']))
			->willReturn([
				'roomTask' => $roomTask, 'task' => $task, 'assignees' => ['kim'], 'checklistItems' => [$item],
				'cycle' => $cycle, 'timeZone' => $zone, 'replay' => false,
			]);
		$this->roomTaskReadService->expects($this->once())->method('roomTaskPayload')
			->with($roomTask, $zone, $cycle)->willReturn(['id' => 7]);

		$response = $this->controller([
			'commandId' => strtoupper(self::UUID), 'expectedVersion' => 4, 'task' => ['name' => 'Vacuum'], 'scope' => 'allRooms',
		])->update(7);

		$this->assertSame(200, $response->getStatus());
		$data = $response->getData()['data'];
		$this->assertSame(['id' => 7, 'assignees' => ['kim'], 'cycle' => $cycle], $data['roomTask']);
		$this->assertSame('Vacuum', $data['task']['name']);
		$this->assertSame(['kim'], $data['assignees']);
		$this->assertSame(['Carpet'], array_column($data['checklistItems'], 'label'));
		$this->assertSame([$cycle, false], [$data['cycle'], $data['replay']]);
	}

	public function testUpdate_AnUnknownField_Is400_BeforeTheService(): void {
		$this->editService->expects($this->never())->method('edit');

		$response = $this->controller(['commandId' => self::UUID, 'expectedVersion' => 4, 'taskId' => 9])->update(7);

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['code' => 'VALIDATION_ERROR', 'message' => 'Unknown field: taskId'], $response->getData()['error']);
	}

	/** @return array<string, array{\Throwable, int, string}> */
	public static function serviceOutcomes(): array {
		return [
			'an invisible task' => [new NotFoundException('Room task not found'), 404, 'RESOURCE_NOT_FOUND'],
			'a child' => [new ForbiddenException('Children may view and complete tasks, but not manage them'), 403, 'FORBIDDEN'],
			'a stale form' => [new ConflictException('The task changed since it was displayed. Reload and try again.', ConflictException::REASON_STALE_TARGET), 409, 'STALE_TARGET'],
			'a shared definition without a scope' => [new ConflictException('Several rooms share this task.', ConflictException::REASON_SCOPE_REQUIRED), 409, 'SCOPE_REQUIRED'],
			'a rule of the service' => [new \InvalidArgumentException('User is not a member of this house: x'), 400, 'VALIDATION_ERROR'],
			'anything unexpected' => [new \RuntimeException('connection lost'), 500, 'ROOM_TASK_UPDATE_ERROR'],
		];
	}

	/** @dataProvider serviceOutcomes */
	public function testUpdate_MapsEveryRefusal(\Throwable $outcome, int $status, string $code): void {
		$this->editService->method('edit')->willThrowException($outcome);
		if ($status === 500) {
			$this->appLogger->expects($this->once())->method('logException');
		}

		$response = $this->controller(['commandId' => self::UUID, 'expectedVersion' => 4])->update(7);

		$this->assertSame($status, $response->getStatus());
		$this->assertSame($code, $response->getData()['error']['code']);
		if ($status === 500) {
			$this->assertStringNotContainsString('connection lost', $response->getData()['error']['message'], 'no internal detail leaks');
		}
	}

	public function testUpdate_ADefinitionOutsideTheClosedSet_Is400WithEveryMessage(): void {
		$this->editService->method('edit')->willThrowException(
			new ValidationException('Energy cost must be between 1 and 20', ['Energy cost must be between 1 and 20', 'Invalid scheduling mode'])
		);

		$response = $this->controller(['commandId' => self::UUID, 'expectedVersion' => 4, 'task' => ['energyCost' => 99]])->update(7);

		$this->assertSame(400, $response->getStatus());
		$this->assertSame([
			'code' => 'VALIDATION_ERROR', 'message' => 'Energy cost must be between 1 and 20',
			'details' => ['Energy cost must be between 1 and 20', 'Invalid scheduling mode'],
		], $response->getData()['error']);
	}
}
