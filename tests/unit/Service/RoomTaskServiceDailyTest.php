<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\Room;
use OCA\ChoreStrike\Db\RoomMapper;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Db\TaskAssigneeMapper;
use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Service\CalendarService;
use OCA\ChoreStrike\Service\RoomTaskReadService;
use OCA\ChoreStrike\Service\TaskAccessPolicy;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Daily/filtered-daily read behavior of RoomTaskReadService (CS-PLAN-067
 * §3.1: the read cluster moved here from RoomTaskService; the tests follow
 * the moved code, CS-PLAN-066 §3.1 D1 semantics unchanged).
 */
class RoomTaskServiceDailyTest extends TestCase {
	private RoomTaskReadService $service;
	private RoomTaskMapper|MockObject $roomTaskMapper;
	private TaskMapper|MockObject $taskMapper;
	private RoomMapper|MockObject $roomMapper;
	private HouseMapper|MockObject $houseMapper;
	private TaskAssigneeMapper|MockObject $taskAssigneeMapper;
	private TaskAccessPolicy|MockObject $taskAccessPolicy;
	private \OCA\ChoreStrike\Service\OccurrenceService|MockObject $occurrenceService;

	protected function setUp(): void {
		$this->roomTaskMapper = $this->createMock(RoomTaskMapper::class);
		$this->taskMapper = $this->createMock(TaskMapper::class);
		$this->roomMapper = $this->createMock(\OCA\ChoreStrike\Db\RoomMapper::class);
		$this->houseMapper = $this->createMock(HouseMapper::class);
		$this->taskAssigneeMapper = $this->createMock(TaskAssigneeMapper::class);
		$this->taskAccessPolicy = $this->createMock(TaskAccessPolicy::class);
		$this->occurrenceService = $this->createMock(\OCA\ChoreStrike\Service\OccurrenceService::class);
		// CS-PLAN-057: day windows resolve the house timezone (UTC here, so
		// the [start, end) bounds match the injected date's UTC calendar day).
		$utcHouse = new House();
		$utcHouse->setTimezone('UTC');
		$this->houseMapper->method('find')->willReturn($utcHouse);

		// Daily read paths filter through the visibility matrix — default to
		// "everything visible" here; denials are covered in RoomTaskServiceTest.
		$this->taskAccessPolicy->method('filterVisibleRoomTasks')
			->willReturnCallback(static fn (array $roomTasks): array => $roomTasks);

		// CS-PLAN-072 WP1: daily reads derive from the occurrence
		// authority — the derivation mock answers with one agreed entry per
		// room task unless a test narrows it.
		$this->occurrenceService->method('agreedOccurrencesForHouseTasks')
			->willReturnCallback(static function (int $houseId, array $roomTasks, ?string $fromKey, ?string $toKey): array {
				$result = [];
				foreach ($roomTasks as $roomTask) {
					$result[$roomTask->getId()] = [['occurrence' => null, 'revision' => null]];
				}
				return $result;
			});

		$this->service = new RoomTaskReadService(
			$this->roomTaskMapper,
			$this->taskMapper,
			$this->roomMapper,
			$this->createMock(\OCA\ChoreStrike\Db\FloorMapper::class),
			$this->houseMapper,
			$this->taskAssigneeMapper,
			$this->taskAccessPolicy,
			new CalendarService(),
			$this->occurrenceService,
			$this->createMock(\OCA\ChoreStrike\Service\PauseProjectionService::class),
			$this->createMock(\OCA\ChoreStrike\Db\HouseUserMapper::class),
			$this->createMock(\OCA\ChoreStrike\Db\OccurrenceMemberMapper::class),
			new \OCA\ChoreStrike\Service\EffortAllocationService(),
			$this->createIdentityL10n(),
			$this->createMock(\OCA\ChoreStrike\Service\AppLogger::class)
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
			->method('findByHouse')
			->with($houseId, true)
			->willReturn([$roomTask]);

		$result = $this->service->getDailyTasks($houseId, $date);

		$this->assertCount(1, $result);
		$this->assertSame($roomTask, $result[0]);
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
			->method('findByHouse')
			->with($houseId, true)
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
			->method('findByHouse')
			->with($houseId, true)
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
			->method('findByHouse')
			->with($houseId, true)
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
			->method('findByHouse')
			->with($houseId, true)
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

	/**
	 * CS-PLAN-065 (decision D4): a task assigned to ANOTHER member is
	 * ineligible for the child's My Day and must not consume a limit slot —
	 * even when it sorts earlier than the child's own tasks.
	 */
	public function testGetFilteredDailyTasksForChild_AssignedToOtherEarlier_SkippedBeforeLimit(): void {
		$houseId = 1;
		$date = new \DateTime('2026-03-07'); // Saturday (available day)

		// id 1 sorts first but belongs to another member; ids 2-3 are the
		// child's own; id 4 is unassigned.
		$roomTasks = [];
		for ($i = 1; $i <= 4; $i++) {
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

		$this->roomTaskMapper->expects($this->once())
			->method('findByHouse')
			->willReturn($roomTasks);

		$this->taskMapper->expects($this->any())
			->method('findByIds')
			->willReturnCallback(fn (array $ids) => array_fill_keys($ids, $task));

		$this->roomMapper->expects($this->any())
			->method('findByIds')
			->willReturnCallback(fn (array $ids) => array_fill_keys($ids, $room));

		$this->taskAssigneeMapper->expects($this->once())
			->method('findUserIdsByRoomTasks')
			->with([1, 2, 3, 4])
			->willReturn([1 => ['teen-sibling'], 2 => ['teen'], 3 => ['teen'], 4 => []]);

		$viewer = new \OCA\ChoreStrike\Db\HouseUser();
		$viewer->setId(9);
		$viewer->setNextcloudUserId('teen');
		$viewer->setRole('child');
		$viewer->setSchedulingPreferences([
			'weekdayAvailability' => [
				'monday' => true,
				'tuesday' => true,
				'wednesday' => true,
				'thursday' => true,
				'friday' => true,
				'saturday' => true,
				'sunday' => true,
			],
			'maxDailyTasks' => 2,
		]);

		$result = $this->service->getFilteredDailyTasksForUser($houseId, $viewer, $date);

		// The ineligible id 1 must not eat one of the two slots.
		$this->assertSame([2, 3], array_map(static fn (array $item): int => $item['roomTask']->getId(), $result));
	}

	/**
	 * CS-PLAN-065 (decision D4): unassigned tasks keep the "mine" semantics
	 * — they pass child eligibility and consume limit slots like own tasks.
	 */
	public function testGetFilteredDailyTasksForChild_UnassignedTasks_AreEligible(): void {
		$houseId = 1;
		$date = new \DateTime('2026-03-07');

		$roomTask1 = new RoomTask();
		$roomTask1->setId(1);
		$roomTask1->setTaskId(1);
		$roomTask1->setRoomId(1);

		$task = new Task();
		$task->setId(1);
		$task->setName('Unassigned Task');

		$room = new Room();
		$room->setId(1);
		$room->setName('Kitchen');

		$this->roomTaskMapper->expects($this->once())
			->method('findByHouse')
			->willReturn([$roomTask1]);

		$this->taskMapper->expects($this->any())
			->method('findByIds')
			->willReturnCallback(fn (array $ids) => array_fill_keys($ids, $task));

		$this->roomMapper->expects($this->any())
			->method('findByIds')
			->willReturnCallback(fn (array $ids) => array_fill_keys($ids, $room));

		$this->taskAssigneeMapper->expects($this->once())
			->method('findUserIdsByRoomTasks')
			->willReturn([1 => []]);

		$viewer = new \OCA\ChoreStrike\Db\HouseUser();
		$viewer->setId(10);
		$viewer->setNextcloudUserId('teen');
		$viewer->setRole('child');
		$viewer->setSchedulingPreferences([
			'weekdayAvailability' => [
				'monday' => true,
				'tuesday' => true,
				'wednesday' => true,
				'thursday' => true,
				'friday' => true,
				'saturday' => true,
				'sunday' => true,
			],
			'maxDailyTasks' => 1,
		]);

		$result = $this->service->getFilteredDailyTasksForUser($houseId, $viewer, $date);

		$this->assertCount(1, $result);
	}

	/**
	 * CS-PLAN-065 (decision D5): a non-finite stored limit (e.g. a stale
	 * PHP_INT_MAX from before D5) means unlimited, not zero.
	 */
	public function testGetFilteredDailyTasksForChild_StaleIntMaxLimit_IsUnlimited(): void {
		$houseId = 1;
		$date = new \DateTime('2026-03-07');

		$roomTasks = [];
		for ($i = 1; $i <= 3; $i++) {
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

		$this->roomTaskMapper->expects($this->once())
			->method('findByHouse')
			->willReturn($roomTasks);

		$this->taskMapper->expects($this->any())
			->method('findByIds')
			->willReturnCallback(fn (array $ids) => array_fill_keys($ids, $task));

		$this->roomMapper->expects($this->any())
			->method('findByIds')
			->willReturnCallback(fn (array $ids) => array_fill_keys($ids, $room));

		$this->taskAssigneeMapper->expects($this->any())
			->method('findUserIdsByRoomTasks')
			->willReturn([]);

		$viewer = new \OCA\ChoreStrike\Db\HouseUser();
		$viewer->setId(11);
		$viewer->setNextcloudUserId('teen');
		$viewer->setRole('child');
		$viewer->setSchedulingPreferences([
			'weekdayAvailability' => [
				'monday' => true,
				'tuesday' => true,
				'wednesday' => true,
				'thursday' => true,
				'friday' => true,
				'saturday' => true,
				'sunday' => true,
			],
			'maxDailyTasks' => PHP_INT_MAX,
		]);

		$result = $this->service->getFilteredDailyTasksForUser($houseId, $viewer, $date);

		$this->assertCount(3, $result);
	}
}
