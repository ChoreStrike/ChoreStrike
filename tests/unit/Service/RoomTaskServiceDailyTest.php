<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Service\RoomTaskService;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Db\RoomMapper;
use OCA\ChoreStrike\Db\FloorMapper;
use OCA\ChoreStrike\Service\RoomService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class RoomTaskServiceDailyTest extends TestCase {

	private RoomTaskService $service;
	private RoomTaskMapper|MockObject $roomTaskMapper;
	private TaskMapper|MockObject $taskMapper;
	private RoomMapper|MockObject $roomMapper;
	private FloorMapper|MockObject $floorMapper;
	private RoomService|MockObject $roomService;

	protected function setUp(): void {
		$this->roomTaskMapper = $this->createMock(RoomTaskMapper::class);
		$this->taskMapper = $this->createMock(TaskMapper::class);
		$this->roomMapper = $this->createMock(RoomMapper::class);
		$this->floorMapper = $this->createMock(FloorMapper::class);
		$this->roomService = $this->createMock(RoomService::class);

		$this->service = new RoomTaskService(
			$this->roomTaskMapper,
			$this->taskMapper,
			$this->roomMapper,
			$this->floorMapper,
			$this->roomService
		);
	}

	public function testGetDailyTasksReturnsTasks(): void {
		$houseId = 1;
		$date = new \DateTime('2026-03-02');

		$roomTask = $this->createMock(RoomTask::class);
		$roomTask->method('getId')->willReturn(1);

		$this->roomTaskMapper->expects($this->once())
			->method('findDailyTasks')
			->with($houseId, $date)
			->willReturn([$roomTask]);

		$result = $this->service->getDailyTasks($houseId, $date);

		$this->assertCount(1, $result);
		$this->assertSame($roomTask, $result[0]);
	}

	public function testCompleteTaskSimpleUpdatesTask(): void {
		$roomTaskId = 1;
		$roomTask = $this->createMock(RoomTask::class);
		$roomTask->expects($this->once())->method('setLastCompleted');
		$roomTask->expects($this->once())->method('getId')->willReturn($roomTaskId);

		$this->roomTaskMapper->expects($this->once())
			->method('find')
			->with($roomTaskId)
			->willReturn($roomTask);

		$this->roomTaskMapper->expects($this->once())
			->method('update')
			->with($roomTask)
			->willReturn($roomTask);

		$result = $this->service->completeTaskSimple($roomTaskId);

		$this->assertSame($roomTask, $result);
	}

	public function testUncompleteTaskRemovesCompletion(): void {
		$roomTaskId = 1;
		$roomTask = $this->createMock(RoomTask::class);
		$roomTask->expects($this->once())->method('setLastCompleted')->with(null);
		$roomTask->expects($this->once())->method('getId')->willReturn($roomTaskId);

		$this->roomTaskMapper->expects($this->once())
			->method('find')
			->with($roomTaskId)
			->willReturn($roomTask);

		$this->roomTaskMapper->expects($this->once())
			->method('update')
			->with($roomTask)
			->willReturn($roomTask);

		$result = $this->service->uncompleteTask($roomTaskId);

		$this->assertSame($roomTask, $result);
	}

	public function testSkipTaskMovesDueDateForward(): void {
		$roomTaskId = 1;
		$originalDue = new \DateTime('2026-03-02');
		$expectedDue = new \DateTime('2026-03-03');

		$roomTask = $this->createMock(RoomTask::class);
		$roomTask->expects($this->once())->method('getNextDue')->willReturn($originalDue);
		$roomTask->expects($this->once())->method('setNextDue')->with($expectedDue);
		$roomTask->expects($this->once())->method('getId')->willReturn($roomTaskId);

		$this->roomTaskMapper->expects($this->once())
			->method('find')
			->with($roomTaskId)
			->willReturn($roomTask);

		$this->roomTaskMapper->expects($this->once())
			->method('update')
			->with($roomTask)
			->willReturn($roomTask);

		$result = $this->service->skipTask($roomTaskId);

		$this->assertSame($roomTask, $result);
	}

	public function testRescheduleTaskUpdatesDueDate(): void {
		$roomTaskId = 1;
		$newDate = new \DateTime('2026-03-05');

		$roomTask = $this->createMock(RoomTask::class);
		$roomTask->expects($this->once())->method('setNextDue')->with($newDate);
		$roomTask->expects($this->once())->method('getId')->willReturn($roomTaskId);

		$this->roomTaskMapper->expects($this->once())
			->method('find')
			->with($roomTaskId)
			->willReturn($roomTask);

		$this->roomTaskMapper->expects($this->once())
			->method('update')
			->with($roomTask)
			->willReturn($roomTask);

		$result = $this->service->rescheduleTask($roomTaskId, $newDate);

		$this->assertSame($roomTask, $result);
	}

	public function testCompleteTaskSimpleThrowsExceptionWhenTaskNotFound(): void {
		$roomTaskId = 999;

		$this->roomTaskMapper->expects($this->once())
			->method('find')
			->with($roomTaskId)
			->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Task not found'));

		$this->expectException(\OCP\AppFramework\Db\DoesNotExistException::class);
		$this->service->completeTaskSimple($roomTaskId);
	}

	public function testUncompleteTaskThrowsExceptionWhenTaskNotFound(): void {
		$roomTaskId = 999;

		$this->roomTaskMapper->expects($this->once())
			->method('find')
			->with($roomTaskId)
			->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Task not found'));

		$this->expectException(\OCP\AppFramework\Db\DoesNotExistException::class);
		$this->service->uncompleteTask($roomTaskId);
	}

	public function testSkipTaskThrowsExceptionWhenTaskNotFound(): void {
		$roomTaskId = 999;

		$this->roomTaskMapper->expects($this->once())
			->method('find')
			->with($roomTaskId)
			->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Task not found'));

		$this->expectException(\OCP\AppFramework\Db\DoesNotExistException::class);
		$this->service->skipTask($roomTaskId);
	}

	public function testRescheduleTaskThrowsExceptionWhenTaskNotFound(): void {
		$roomTaskId = 999;
		$newDate = new \DateTime('2026-03-05');

		$this->roomTaskMapper->expects($this->once())
			->method('find')
			->with($roomTaskId)
			->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Task not found'));

		$this->expectException(\OCP\AppFramework\Db\DoesNotExistException::class);
		$this->service->rescheduleTask($roomTaskId, $newDate);
	}
}
