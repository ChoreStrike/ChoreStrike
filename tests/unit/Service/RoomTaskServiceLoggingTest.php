<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\CompletedTaskMapper;
use OCA\ChoreStrike\Db\Floor;
use OCA\ChoreStrike\Db\FloorMapper;
use OCA\ChoreStrike\Db\Room;
use OCA\ChoreStrike\Db\RoomMapper;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\TaskAssigneeMapper;
use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Exception\NotFoundException;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\RoomService;
use OCA\ChoreStrike\Service\RoomTaskService;
use OCA\ChoreStrike\Service\TaskAccessPolicy;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Logging-focused tests for RoomTaskService.
 */
class RoomTaskServiceLoggingTest extends TestCase {
	/**
	 * Build RoomTaskService with the CS-PLAN-067 constructor (the read
	 * cluster is a real RoomTaskReadService sharing the same mocks).
	 */
	private function createService(
		RoomTaskMapper $roomTaskMapper,
		TaskMapper $taskMapper,
		RoomMapper $roomMapper,
		FloorMapper $floorMapper,
		RoomService $roomService,
		TaskAssigneeMapper $taskAssigneeMapper,
		AppLogger $appLogger,
	): RoomTaskService {
		return new RoomTaskService(
			$roomTaskMapper,
			$taskMapper,
			$roomMapper,
			$this->createMock(\OCA\ChoreStrike\Db\HouseMapper::class),
			new \OCA\ChoreStrike\Service\RoomTaskReadService(
				$roomTaskMapper,
				$taskMapper,
				$roomMapper,
				$floorMapper,
				$this->createMock(\OCA\ChoreStrike\Db\HouseMapper::class),
				$taskAssigneeMapper,
				$this->createMock(TaskAccessPolicy::class),
				new \OCA\ChoreStrike\Service\CalendarService(),
				new \OCA\ChoreStrike\Service\WindowService(new \OCA\ChoreStrike\Service\RecurrenceCalculator(), $this->createMock(\OCA\ChoreStrike\Db\CompletedTaskMapper::class)),
				$this->createMock(\OCA\ChoreStrike\Service\AppLogger::class),
				$this->createMock(\OCA\ChoreStrike\Db\TaskChecklistItemMapper::class),
				$this->createMock(\OCA\ChoreStrike\Service\CreditPolicy::class)
			),
			$roomService,
			$taskAssigneeMapper,
			$this->createMock(CompletedTaskMapper::class),
			new \OCA\ChoreStrike\Db\TransactionExecutor($this->createMock(\OCP\IDBConnection::class)),
			$this->createIdentityL10n(),
			$appLogger,
			$this->createMock(TaskAccessPolicy::class),
			$this->createMock(AuthorizationService::class),
			new \OCA\ChoreStrike\Service\WindowService(new \OCA\ChoreStrike\Service\RecurrenceCalculator(), $this->createMock(\OCA\ChoreStrike\Db\CompletedTaskMapper::class))
		);
	}

	/**
	 * ValidateRoomTaskOwnership_ForeignHouse_LogsSecurityWarning.
	 *
	 * CS-PLAN-063 §3.4: the room→floor→house walk is delegated to
	 * RoomService; the wrapper logs the boundary violation (without the
	 * foreign house id) and translates the miss into the room-task 404.
	 */
	public function testValidateRoomTaskOwnership_ForeignHouse_LogsSecurityWarning(): void {
		$roomTaskMapper = $this->createMock(RoomTaskMapper::class);
		$taskMapper = $this->createMock(TaskMapper::class);
		$roomMapper = $this->createMock(RoomMapper::class);
		$floorMapper = $this->createMock(FloorMapper::class);
		$roomService = $this->createMock(RoomService::class);
		$taskAssigneeMapper = $this->createMock(TaskAssigneeMapper::class);
		$appLogger = $this->createMock(AppLogger::class);

		$roomTask = new RoomTask();
		$roomTask->setId(10);
		$roomTask->setRoomId(5);

		$roomTaskMapper->method('find')->with(10)->willReturn($roomTask);
		$roomService->method('validateRoomOwnership')
			->willThrowException(new NotFoundException('Room not found'));

		$appLogger->expects($this->once())
			->method('securityWarning')
			->with(
				'Room task ownership validation failed',
				$this->callback(function (array $context): bool {
					return $context['service'] === 'RoomTaskService'
						&& $context['roomTaskId'] === 10
						&& $context['houseId'] === 1;
				})
			);

		$service = $this->createService(
			$roomTaskMapper,
			$taskMapper,
			$roomMapper,
			$floorMapper,
			$roomService,
			$taskAssigneeMapper,
			$appLogger
		);

		$this->expectException(NotFoundException::class);
		$this->expectExceptionMessage('Room task not found');

		$service->validateRoomTaskOwnership(10, 1);
	}

	private function createIdentityL10n(): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text) => $text);
		return $l10n;
	}
}
