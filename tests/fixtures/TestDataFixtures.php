<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Fixtures;

use OCA\ChoreStrike\Db\AvailabilitySettings;
use OCA\ChoreStrike\Db\Floor;
use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\Room;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\Task;
use OCP\IDBConnection;

/**
 * Test data fixtures for setting up test scenarios
 */
class TestDataFixtures {
	private IDBConnection $db;

	public function __construct(IDBConnection $db) {
		$this->db = $db;
	}

	/**
	 * Create a complete test house with floors, rooms, tasks, and availability
	 */
	public function createTestHouse(): array {
		$house = $this->createHouse();
		$floors = $this->createFloors($house->getId());
		$rooms = $this->createRooms($floors);
		$tasks = $this->createTasks();
		$roomTasks = $this->createRoomTasks($rooms, $tasks);
		$availability = $this->createAvailabilitySettings($house->getId());

		return [
			'house' => $house,
			'floors' => $floors,
			'rooms' => $rooms,
			'tasks' => $tasks,
			'roomTasks' => $roomTasks,
			'availability' => $availability
		];
	}

	/**
	 * Create test house
	 */
	public function createHouse(): House {
		$house = new House();
		$house->setInstanceId('test-instance');
		$house->setName('Test House');

		// Insert into database
		$qb = $this->db->getQueryBuilder();
		$qb->insert('chorestrike_houses')
			->values([
				'instance_id' => $qb->createNamedParameter($house->getInstanceId()),
				'name' => $qb->createNamedParameter($house->getName()),
				'created_at' => $qb->createNamedParameter(new \DateTime(), IQueryBuilder::PARAM_DATE),
			]);

		$qb->executeStatement();
		$house->setId((int)$this->db->lastInsertId());

		return $house;
	}

	/**
	 * Create test floors
	 */
	public function createFloors(int $houseId): array {
		$floors = [];

		$floorData = [
			['name' => 'Ground Floor', 'level' => 0],
			['name' => 'First Floor', 'level' => 1],
		];

		foreach ($floorData as $data) {
			$floor = new Floor();
			$floor->setHouseId($houseId);
			$floor->setName($data['name']);
			$floor->setLevel($data['level']);

			$qb = $this->db->getQueryBuilder();
			$qb->insert('chorestrike_floors')
				->values([
					'house_id' => $qb->createNamedParameter($floor->getHouseId(), IQueryBuilder::PARAM_INT),
					'name' => $qb->createNamedParameter($floor->getName()),
					'level' => $qb->createNamedParameter($floor->getLevel(), IQueryBuilder::PARAM_INT),
					'created_at' => $qb->createNamedParameter(new \DateTime(), IQueryBuilder::PARAM_DATE),
				]);

			$qb->executeStatement();
			$floor->setId((int)$this->db->lastInsertId());
			$floors[] = $floor;
		}

		return $floors;
	}

	/**
	 * Create test rooms
	 */
	public function createRooms(array $floors): array {
		$rooms = [];

		$roomData = [
			['floor_index' => 0, 'name' => 'Kitchen', 'type' => 'kitchen'],
			['floor_index' => 0, 'name' => 'Living Room', 'type' => 'living_room'],
			['floor_index' => 1, 'name' => 'Master Bedroom', 'type' => 'bedroom'],
			['floor_index' => 1, 'name' => 'Bathroom', 'type' => 'bathroom'],
		];

		foreach ($roomData as $data) {
			$floor = $floors[$data['floor_index']];

			$room = new Room();
			$room->setFloorId($floor->getId());
			$room->setName($data['name']);
			$room->setType($data['type']);

			$qb = $this->db->getQueryBuilder();
			$qb->insert('chorestrike_rooms')
				->values([
					'floor_id' => $qb->createNamedParameter($room->getFloorId(), IQueryBuilder::PARAM_INT),
					'name' => $qb->createNamedParameter($room->getName()),
					'type' => $qb->createNamedParameter($room->getType()),
					'created_at' => $qb->createNamedParameter(new \DateTime(), IQueryBuilder::PARAM_DATE),
				]);

			$qb->executeStatement();
			$room->setId((int)$this->db->lastInsertId());
			$rooms[] = $room;
		}

		return $rooms;
	}

	/**
	 * Create test tasks
	 */
	public function createTasks(): array {
		$tasks = [];

		$taskData = [
			['name' => 'Clean Kitchen', 'category' => 'cleaning', 'frequency' => 1, 'mode' => 'weekly'],
			['name' => 'Vacuum Living Room', 'category' => 'cleaning', 'frequency' => 2, 'mode' => 'weekly'],
			['name' => 'Clean Bathroom', 'category' => 'cleaning', 'frequency' => 1, 'mode' => 'weekly'],
			['name' => 'Take Out Trash', 'category' => 'maintenance', 'frequency' => 2, 'mode' => 'weekly'],
			['name' => 'Water Plants', 'category' => 'maintenance', 'frequency' => 7, 'mode' => 'daily'],
		];

		foreach ($taskData as $data) {
			$task = new Task();
			$task->setName($data['name']);
			$task->setCategoryId($this->getCategoryIdByName($data['category']));
			$task->setFrequency($data['frequency']);
			$task->setSchedulingMode($data['mode']);
			$task->setFixedScheduleDays(['monday', 'wednesday', 'friday']);
			$task->setFixedScheduleTime('10:00');

			$qb = $this->db->getQueryBuilder();
			$qb->insert('chorestrike_tasks')
				->values([
					'name' => $qb->createNamedParameter($task->getName()),
					'category_id' => $qb->createNamedParameter($task->getCategoryId(), IQueryBuilder::PARAM_INT),
					'frequency' => $qb->createNamedParameter($task->getFrequency(), IQueryBuilder::PARAM_INT),
					'scheduling_mode' => $qb->createNamedParameter($task->getSchedulingMode()),
					'fixed_schedule_days' => $qb->createNamedParameter(json_encode($task->getFixedScheduleDays())),
					'fixed_schedule_time' => $qb->createNamedParameter($task->getFixedScheduleTime()),
					'created_at' => $qb->createNamedParameter(new \DateTime(), IQueryBuilder::PARAM_DATE),
				]);

			$qb->executeStatement();
			$task->setId((int)$this->db->lastInsertId());
			$tasks[] = $task;
		}

		return $tasks;
	}

	/**
	 * Create room tasks
	 */
	public function createRoomTasks(array $rooms, array $tasks): array {
		$roomTasks = [];

		$assignments = [
			['room_index' => 0, 'task_index' => 0, 'due_date' => '2026-03-02'],
			['room_index' => 1, 'task_index' => 1, 'due_date' => '2026-03-02'],
			['room_index' => 3, 'task_index' => 2, 'due_date' => '2026-03-03'],
			['room_index' => 0, 'task_index' => 3, 'due_date' => '2026-03-04'],
			['room_index' => 2, 'task_index' => 4, 'due_date' => '2026-03-02'],
		];

		foreach ($assignments as $assignment) {
			$room = $rooms[$assignment['room_index']];
			$task = $tasks[$assignment['task_index']];

			$roomTask = new RoomTask();
			$roomTask->setRoomId($room->getId());
			$roomTask->setTaskId($task->getId());
			$roomTask->setNextDue(new \DateTime($assignment['due_date']));
			$roomTask->setIsActive(true);
			$roomTask->setAssignedToUserId('test-user');

			$qb = $this->db->getQueryBuilder();
			$qb->insert('chorestrike_room_tasks')
				->values([
					'room_id' => $qb->createNamedParameter($roomTask->getRoomId(), IQueryBuilder::PARAM_INT),
					'task_id' => $qb->createNamedParameter($roomTask->getTaskId(), IQueryBuilder::PARAM_INT),
					'next_due' => $qb->createNamedParameter($roomTask->getNextDue(), IQueryBuilder::PARAM_DATE),
					'is_active' => $qb->createNamedParameter($roomTask->getIsActive(), IQueryBuilder::PARAM_BOOL),
					'assigned_to_user_id' => $qb->createNamedParameter($roomTask->getAssignedToUserId()),
					'created_at' => $qb->createNamedParameter(new \DateTime(), IQueryBuilder::PARAM_DATE),
				]);

			$qb->executeStatement();
			$roomTask->setId((int)$this->db->lastInsertId());
			$roomTasks[] = $roomTask;
		}

		return $roomTasks;
	}

	/**
	 * Create availability settings
	 */
	public function createAvailabilitySettings(int $houseId): array {
		$availabilitySettings = [];

		$users = [
			['user_id' => 'test-user', 'name' => 'Test User'],
			['user_id' => 'other-user', 'name' => 'Other User'],
		];

		foreach ($users as $userData) {
			$availability = new AvailabilitySettings();
			$availability->setHouseId($houseId);
			$availability->setUserId($userData['user_id']);
			$availability->setWeekdayAvailability([
				'monday' => true,
				'tuesday' => true,
				'wednesday' => false,
				'thursday' => true,
				'friday' => true,
				'saturday' => false,
				'sunday' => false,
			]);
			$availability->setEnergyBudget([
				'monday' => 10,
				'tuesday' => 8,
				'wednesday' => 5,
				'thursday' => 12,
				'friday' => 15,
				'saturday' => 0,
				'sunday' => 0,
			]);
			$availability->setPlanningMode('auto_balance');
			$availability->setMaxTasksPerDay(5);
			$availability->setTimeRanges(['start' => '09:00', 'end' => '18:00']);

			$qb = $this->db->getQueryBuilder();
			$qb->insert('chorestrike_availability_settings')
				->values([
					'house_id' => $qb->createNamedParameter($availability->getHouseId(), IQueryBuilder::PARAM_INT),
					'user_id' => $qb->createNamedParameter($availability->getUserId()),
					'weekday_availability' => $qb->createNamedParameter(json_encode($availability->getWeekdayAvailability())),
					'energy_budget' => $qb->createNamedParameter(json_encode($availability->getEnergyBudget())),
					'planning_mode' => $qb->createNamedParameter($availability->getPlanningMode()),
					'max_tasks_per_day' => $qb->createNamedParameter($availability->getMaxTasksPerDay(), IQueryBuilder::PARAM_INT),
					'time_ranges' => $qb->createNamedParameter(json_encode($availability->getTimeRanges())),
					'updated_at' => $qb->createNamedParameter(new \DateTime(), IQueryBuilder::PARAM_DATE),
				]);

			$qb->executeStatement();
			$availability->setId((int)$this->db->lastInsertId());
			$availabilitySettings[] = $availability;
		}

		return $availabilitySettings;
	}

	/**
	 * Clean up all test data
	 */
	public function cleanup(): void {
		$tables = [
			'chorestrike_availability_settings',
			'chorestrike_room_tasks',
			'chorestrike_tasks',
			'chorestrike_rooms',
			'chorestrike_floors',
			'chorestrike_houses',
		];

		foreach ($tables as $table) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete($table)
				->where($qb->expr()->like('instance_id', $qb->createNamedParameter('test-%')));
			$qb->executeStatement();
		}
	}

	/**
	 * Get category ID by name (simplified for tests)
	 */
	private function getCategoryIdByName(string $categoryName): int {
		// For testing purposes, we'll use simple numeric IDs
		$categories = [
			'cleaning' => 1,
			'maintenance' => 2,
			'organizing' => 3,
		];

		return $categories[$categoryName] ?? 1;
	}

	/**
	 * Create daily tasks for today
	 */
	public function createDailyTasksForToday(): array {
		$testData = $this->createTestHouse();
		$today = new \DateTime('2026-03-02');

		$dailyTasks = array_filter($testData['roomTasks'], function ($roomTask) use ($today) {
			return $roomTask->getNextDue()->format('Y-m-d') === $today->format('Y-m-d');
		});

		return array_values($dailyTasks);
	}

	/**
	 * Create completed tasks
	 */
	public function createCompletedTasks(): array {
		$testData = $this->createTestHouse();
		$completedTasks = [];

		// Mark some tasks as completed
		foreach (array_slice($testData['roomTasks'], 0, 2) as $roomTask) {
			$roomTask->setLastCompleted(new \DateTime('2026-03-01'));

			$qb = $this->db->getQueryBuilder();
			$qb->update('chorestrike_room_tasks')
				->set('last_completed', $qb->createNamedParameter($roomTask->getLastCompleted(), IQueryBuilder::PARAM_DATE))
				->where($qb->expr()->eq('id', $qb->createNamedParameter($roomTask->getId(), IQueryBuilder::PARAM_INT)));

			$qb->executeStatement();
			$completedTasks[] = $roomTask;
		}

		return $completedTasks;
	}
}
