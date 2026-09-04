<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\CompletedTaskMapper;
use OCA\ChoreStrike\Db\FloorMapper;
use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\Room;
use OCA\ChoreStrike\Db\RoomMapper;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Db\TaskAssigneeMapper;
use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\CalendarService;
use OCA\ChoreStrike\Service\RoomService;
use OCA\ChoreStrike\Service\RoomTaskService;
use OCA\ChoreStrike\Service\TaskAccessPolicy;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class RoomTaskServiceDailyTest extends TestCase {
	private RoomTaskService $service;
	private RoomTaskMapper|MockObject $roomTaskMapper;
	private TaskMapper|MockObject $taskMapper;
	private RoomMapper|MockObject $roomMapper;
	private FloorMapper|MockObject $floorMapper;
	private RoomService|MockObject $roomService;
	private TaskAssigneeMapper|MockObject $taskAssigneeMapper;
	private HouseUserMapper|MockObject $houseUserMapper;
	private CompletedTaskMapper|MockObject $completedTaskMapper;
	private HouseMapper|MockObject $houseMapper;
	private TaskAccessPolicy|MockObject $taskAccessPolicy;
	private AuthorizationService|MockObject $authorization;

	protected function setUp(): void {
		$this->roomTaskMapper = $this->createMock(RoomTaskMapper::class);
		$this->taskMapper = $this->createMock(TaskMapper::class);
		$this->roomMapper = $this->createMock(RoomMapper::class);
		$this->floorMapper = $this->createMock(FloorMapper::class);
		$this->roomService = $this->createMock(RoomService::class);
		$this->taskAssigneeMapper = $this->createMock(TaskAssigneeMapper::class);
		$this->houseUserMapper = $this->createMock(HouseUserMapper::class);
		$this->completedTaskMapper = $this->createMock(CompletedTaskMapper::class);
		$this->houseMapper = $this->createMock(HouseMapper::class);
		$this->taskAccessPolicy = $this->createMock(TaskAccessPolicy::class);
		$this->authorization = $this->createMock(AuthorizationService::class);
		// CS-PLAN-057: day windows resolve the house timezone (UTC here, so
		// the [start, end) bounds match the injected date's UTC calendar day).
		$utcHouse = new House();
		$utcHouse->setTimezone('UTC');
		$this->houseMapper->method('find')->willReturn($utcHouse);

		// Daily read paths filter through the visibility matrix — default to
		// "everything visible" here; denials are covered in RoomTaskServiceTest.
		$this->taskAccessPolicy->method('filterVisibleRoomTasks')
			->willReturnCallback(static fn (array $roomTasks): array => $roomTasks);

		$this->service = new RoomTaskService(
			$this->roomTaskMapper,
			$this->taskMapper,
			$this->roomMapper,
			$this->floorMapper,
			$this->roomService,
			$this->taskAssigneeMapper,
			$this->houseUserMapper,
			$this->completedTaskMapper,
			$this->createMock(\OCA\ChoreStrike\Service\TemplateService::class),
			new \OCA\ChoreStrike\Db\TransactionExecutor($this->createMock(\OCP\IDBConnection::class)),
			$this->createIdentityL10n(),
			$this->createMock(AppLogger::class),
			$this->houseMapper,
			new CalendarService(),
			$this->taskAccessPolicy,
			$this->authorization
		);
	}

	private function createIdentityL10n(): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text) => $text);
		return $l10n;
	}

	public function testGetDailyTasksReturnsTasks(): void {
		$houseId = 1;
		$date = new \DateTime('2026-03-02');

		$roomTask = $this->createMock(RoomTask::class);
		$roomTask->method('getId')->willReturn(1);

		$this->roomTaskMapper->expects($this->once())
			->method('findDailyTasks')
			->with($houseId, $this->isInstanceOf(\DateTimeInterface::class), $this->isInstanceOf(\DateTimeInterface::class))
			->willReturn([$roomTask]);

		$result = $this->service->getDailyTasks($houseId, $date);

		$this->assertCount(1, $result);
		$this->assertSame($roomTask, $result[0]);
	}

	public function testUncompleteTaskThrowsExceptionWhenTaskNotFound(): void {
		$roomTaskId = 999;

		$this->roomTaskMapper->expects($this->once())
			->method('find')
			->with($roomTaskId)
			->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Task not found'));

		// CS-PLAN-061 §3.5: the mapper miss is wrapped into the app
		// NotFoundException — a raw DoesNotExistException would surface as 500.
		$this->expectException(\OCA\ChoreStrike\Exception\NotFoundException::class);
		$this->expectExceptionMessage('Room task not found');
		$this->service->uncompleteTask($roomTaskId, $this->createHouseUser());
	}

	private function createHouseUser(string $userId = 'user123', string $role = 'member'): HouseUser {
		$houseUser = new HouseUser();
		$houseUser->setNextcloudUserId($userId);
		$houseUser->setRole($role);
		return $houseUser;
	}

	public function testGetFilteredDailyTasksForUserReturnsAllTasksForNonChildUser(): void {
		$houseId = 1;
		$date = new \DateTime('2026-03-07'); // Saturday

		// Create room tasks
		$roomTask1 = new RoomTask();
		$roomTask1->setId(1);
		$roomTask1->setTaskId(1);
		$roomTask1->setRoomId(1);

		$roomTask2 = new RoomTask();
		$roomTask2->setId(2);
		$roomTask2->setTaskId(2);
		$roomTask2->setRoomId(1);

		$task = new Task();
		$task->setId(1);
		$task->setName('Test Task');

		$room = new Room();
		$room->setId(1);
		$room->setName('Kitchen');

		// Mock daily tasks
		$this->roomTaskMapper->expects($this->once())
			->method('findDailyTasks')
			->with($houseId, $this->isInstanceOf(\DateTimeInterface::class), $this->isInstanceOf(\DateTimeInterface::class))
			->willReturn([$roomTask1, $roomTask2]);

		$this->taskMapper->expects($this->any())
			->method('findByIds')
			->willReturnCallback(fn (array $ids) => array_fill_keys($ids, $task));

		$this->roomMapper->expects($this->any())
			->method('findByIds')
			->willReturnCallback(fn (array $ids) => array_fill_keys($ids, $room));

		// Real HouseUser entity (non-child) — passed directly as the viewer
		// since CS-PLAN-061; the HouseUserMapper param was removed.
		$viewer = new \OCA\ChoreStrike\Db\HouseUser();
		$viewer->setId(1);
		$viewer->setNextcloudUserId('parent');
		$viewer->setRole('owner');

		$result = $this->service->getFilteredDailyTasksForUser($houseId, $viewer, $date);

		$this->assertCount(2, $result);
	}

	public function testGetFilteredDailyTasksForUserFiltersByWeekdayAvailabilityForChild(): void {
		$houseId = 1;
		$date = new \DateTime('2026-03-06'); // Friday (weekday)

		// Create room tasks
		$roomTask1 = new RoomTask();
		$roomTask1->setId(1);
		$roomTask1->setTaskId(1);
		$roomTask1->setRoomId(1);

		$task = new Task();
		$task->setId(1);
		$task->setName('Test Task');

		$room = new Room();
		$room->setId(1);
		$room->setName('Kitchen');

		// Mock daily tasks
		$this->roomTaskMapper->expects($this->once())
			->method('findDailyTasks')
			->with($houseId, $this->isInstanceOf(\DateTimeInterface::class), $this->isInstanceOf(\DateTimeInterface::class))
			->willReturn([$roomTask1]);

		// Real HouseUser entity (child with weekend-only availability)
		$viewer = new \OCA\ChoreStrike\Db\HouseUser();
		$viewer->setId(2);
		$viewer->setNextcloudUserId('teen');
		$viewer->setRole('child');
		$viewer->setSchedulingPreferences([
			'weekdayAvailability' => [
				'monday' => false,
				'tuesday' => false,
				'wednesday' => false,
				'thursday' => false,
				'friday' => false,
				'saturday' => true,
				'sunday' => true,
			],
			'maxDailyTasks' => 3,
		]);

		$result = $this->service->getFilteredDailyTasksForUser($houseId, $viewer, $date);

		// Friday is not available, so result should be empty
		$this->assertCount(0, $result);
	}

	public function testGetFilteredDailyTasksForUserLimitsByMaxDailyTasksForChild(): void {
		$houseId = 1;
		$date = new \DateTime('2026-03-07'); // Saturday (available day)

		// Create 5 room tasks
		$roomTasks = [];
		for ($i = 1; $i <= 5; $i++) {
			$roomTask = new RoomTask();
			$roomTask->setId($i);
			$roomTask->setTaskId($i);
			$roomTask->setRoomId(1);
			$roomTasks[] = $roomTask;
		}

		$task = new Task();
		$task->setId(1);
		$task->setName('Test Task');

		$room = new Room();
		$room->setId(1);
		$room->setName('Kitchen');

		// Mock daily tasks
		$this->roomTaskMapper->expects($this->once())
			->method('findDailyTasks')
			->with($houseId, $this->isInstanceOf(\DateTimeInterface::class), $this->isInstanceOf(\DateTimeInterface::class))
			->willReturn($roomTasks);

		$this->taskMapper->expects($this->any())
			->method('findByIds')
			->willReturnCallback(fn (array $ids) => array_fill_keys($ids, $task));

		$this->roomMapper->expects($this->any())
			->method('findByIds')
			->willReturnCallback(fn (array $ids) => array_fill_keys($ids, $room));

		// Real HouseUser entity (child with maxDailyTasks=2)
		$viewer = new \OCA\ChoreStrike\Db\HouseUser();
		$viewer->setId(3);
		$viewer->setNextcloudUserId('kid');
		$viewer->setRole('child');
		$viewer->setSchedulingPreferences([
			'weekdayAvailability' => [
				'monday' => false,
				'tuesday' => false,
				'wednesday' => false,
				'thursday' => false,
				'friday' => false,
				'saturday' => true,
				'sunday' => true,
			],
			'maxDailyTasks' => 2,
		]);

		$result = $this->service->getFilteredDailyTasksForUser($houseId, $viewer, $date);

		// Saturday is available, but maxDailyTasks=2 limits to 2 tasks
		$this->assertCount(2, $result);
	}

	public function testGetFilteredDailyTasksForChildReturnsTasksOnAvailableDay(): void {
		$houseId = 1;
		$date = new \DateTime('2026-03-07'); // Saturday (available day)

		// Create room tasks
		$roomTask1 = new RoomTask();
		$roomTask1->setId(1);
		$roomTask1->setTaskId(1);
		$roomTask1->setRoomId(1);

		$roomTask2 = new RoomTask();
		$roomTask2->setId(2);
		$roomTask2->setTaskId(2);
		$roomTask2->setRoomId(1);

		$task = new Task();
		$task->setId(1);
		$task->setName('Test Task');

		$room = new Room();
		$room->setId(1);
		$room->setName('Kitchen');

		// Mock daily tasks
		$this->roomTaskMapper->expects($this->once())
			->method('findDailyTasks')
			->with($houseId, $this->isInstanceOf(\DateTimeInterface::class), $this->isInstanceOf(\DateTimeInterface::class))
			->willReturn([$roomTask1, $roomTask2]);

		$this->taskMapper->expects($this->any())
			->method('findByIds')
			->willReturnCallback(fn (array $ids) => array_fill_keys($ids, $task));

		$this->roomMapper->expects($this->any())
			->method('findByIds')
			->willReturnCallback(fn (array $ids) => array_fill_keys($ids, $room));

		// Real HouseUser entity (child with weekend-only availability and maxDailyTasks=3)
		$viewer = new \OCA\ChoreStrike\Db\HouseUser();
		$viewer->setId(4);
		$viewer->setNextcloudUserId('teen');
		$viewer->setRole('child');
		$viewer->setSchedulingPreferences([
			'weekdayAvailability' => [
				'monday' => false,
				'tuesday' => false,
				'wednesday' => false,
				'thursday' => false,
				'friday' => false,
				'saturday' => true,
				'sunday' => true,
			],
			'maxDailyTasks' => 3,
		]);

		$result = $this->service->getFilteredDailyTasksForUser($houseId, $viewer, $date);

		// Saturday is available and maxDailyTasks=3 allows all 2 tasks
		$this->assertCount(2, $result);
	}
}
