<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\CompletedTaskMapper;
use OCA\ChoreStrike\Db\Floor;
use OCA\ChoreStrike\Db\FloorMapper;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\Room;
use OCA\ChoreStrike\Db\RoomMapper;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\TaskAssigneeMapper;
use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Exception\NotFoundException;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\RoomService;
use OCA\ChoreStrike\Service\RoomTaskService;
use PHPUnit\Framework\TestCase;

/**
 * Logging-focused tests for RoomTaskService.
 */
class RoomTaskServiceLoggingTest extends TestCase {
	/**
	 * ValidateRoomTaskOwnership_ForeignHouse_LogsSecurityWarning.
	 */
	public function testValidateRoomTaskOwnership_ForeignHouse_LogsSecurityWarning(): void {
		$roomTaskMapper = $this->createMock(RoomTaskMapper::class);
		$taskMapper = $this->createMock(TaskMapper::class);
		$roomMapper = $this->createMock(RoomMapper::class);
		$floorMapper = $this->createMock(FloorMapper::class);
		$roomService = $this->createMock(RoomService::class);
		$taskAssigneeMapper = $this->createMock(TaskAssigneeMapper::class);
		$houseUserMapper = $this->createMock(HouseUserMapper::class);
		$appLogger = $this->createMock(AppLogger::class);

		$roomTask = new RoomTask();
		$roomTask->setId(10);
		$roomTask->setRoomId(5);

		$room = new Room();
		$room->setId(5);
		$room->setFloorId(7);

		$floor = new Floor();
		$floor->setId(7);
		$floor->setHouseId(99);

		$roomTaskMapper->method('find')->with(10)->willReturn($roomTask);
		$roomMapper->method('find')->with(5)->willReturn($room);
		$floorMapper->method('find')->with(7)->willReturn($floor);

		$appLogger->expects($this->once())
			->method('securityWarning')
			->with(
				'Room task ownership validation failed',
				$this->callback(function (array $context): bool {
					return $context['service'] === 'RoomTaskService'
						&& $context['roomTaskId'] === 10
						&& $context['houseId'] === 1
						&& $context['actualHouseId'] === 99;
				})
			);

		$service = new RoomTaskService(
			$roomTaskMapper,
			$taskMapper,
			$roomMapper,
			$floorMapper,
			$roomService,
			$taskAssigneeMapper,
			$houseUserMapper,
			$this->createMock(CompletedTaskMapper::class),
			$this->createMock(\OCA\ChoreStrike\Service\TemplateService::class),
			$appLogger
		);

		$this->expectException(NotFoundException::class);
		$this->expectExceptionMessage('Room task not found');

		$service->validateRoomTaskOwnership(10, 1);
	}

	/**
	 * BulkAssignTasks_SkippedItem_LogsWithoutUserId.
	 *
	 * CR-SVC-09: the warning context must not contain the raw target
	 * userId (privacy) — houseId and roomTaskId are sufficient context.
	 */
	public function testBulkAssignTasks_SkippedItem_LogsWithoutUserId(): void {
		$roomTaskMapper = $this->createMock(RoomTaskMapper::class);
		$taskMapper = $this->createMock(TaskMapper::class);
		$roomMapper = $this->createMock(RoomMapper::class);
		$floorMapper = $this->createMock(FloorMapper::class);
		$roomService = $this->createMock(RoomService::class);
		$taskAssigneeMapper = $this->createMock(TaskAssigneeMapper::class);
		$houseUserMapper = $this->createMock(HouseUserMapper::class);
		$appLogger = $this->createMock(AppLogger::class);

		// The room task belongs to another house -> ownership fails -> skip.
		$roomTask = new RoomTask();
		$roomTask->setId(20);
		$roomTask->setRoomId(5);

		$room = new Room();
		$room->setId(5);
		$room->setFloorId(7);

		$floor = new Floor();
		$floor->setId(7);
		$floor->setHouseId(99);

		$roomTaskMapper->method('find')->with(20)->willReturn($roomTask);
		$roomMapper->method('find')->with(5)->willReturn($room);
		$floorMapper->method('find')->with(7)->willReturn($floor);

		$appLogger->expects($this->once())
			->method('warning')
			->with(
				'Bulk room task assignment skipped failed item',
				$this->callback(function (array $context): bool {
					return !array_key_exists('userId', $context)
						&& $context['service'] === 'RoomTaskService'
						&& $context['roomTaskId'] === 20
						&& $context['houseId'] === 1
						&& $context['exception'] === NotFoundException::class;
				})
			);

		$service = new RoomTaskService(
			$roomTaskMapper,
			$taskMapper,
			$roomMapper,
			$floorMapper,
			$roomService,
			$taskAssigneeMapper,
			$houseUserMapper,
			$this->createMock(CompletedTaskMapper::class),
			$this->createMock(\OCA\ChoreStrike\Service\TemplateService::class),
			$appLogger
		);

		$result = $service->bulkAssignTasks([20], 'target-user', 1);

		$this->assertSame([], $result);
	}
}
