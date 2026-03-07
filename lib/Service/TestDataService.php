<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\Floor;
use OCA\ChoreStrike\Db\FloorMapper;
use OCA\ChoreStrike\Db\Room;
use OCA\ChoreStrike\Db\RoomMapper;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\AvailabilitySettings;
use OCA\ChoreStrike\Db\AvailabilitySettingsMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Service for managing test data profiles
 *
 * Provides three profiles:
 * - empty: Clean database (no data)
 * - minimal: 1 House, 1 Floor, 1 Room, 2 Users
 * - family: Complete family (6 Users, 3 Floors, 6 Rooms, 12 Tasks)
 */
class TestDataService
{
	private IDBConnection $db;
	private HouseMapper $houseMapper;
	private HouseUserMapper $houseUserMapper;
	private FloorMapper $floorMapper;
	private RoomMapper $roomMapper;
	private TaskMapper $taskMapper;
	private RoomTaskMapper $roomTaskMapper;
	private AvailabilitySettingsMapper $availabilityMapper;

	public function __construct(
		IDBConnection $db,
		HouseMapper $houseMapper,
		HouseUserMapper $houseUserMapper,
		FloorMapper $floorMapper,
		RoomMapper $roomMapper,
		TaskMapper $taskMapper,
		RoomTaskMapper $roomTaskMapper,
		AvailabilitySettingsMapper $availabilityMapper
	) {
		$this->db = $db;
		$this->houseMapper = $houseMapper;
		$this->houseUserMapper = $houseUserMapper;
		$this->floorMapper = $floorMapper;
		$this->roomMapper = $roomMapper;
		$this->taskMapper = $taskMapper;
		$this->roomTaskMapper = $roomTaskMapper;
		$this->availabilityMapper = $availabilityMapper;
	}

	/**
	 * Reset all tables (truncate, preserve schema)
	 */
	public function reset(): void
	{
		$tables = [
			'chorestrike_availability',
			'chorestrike_chk_progress',
			'chorestrike_task_parts',
			'chorestrike_claims',
			'chorestrike_completed_tasks',
			'chorestrike_room_tasks',
			'chorestrike_chk_items',
			'chorestrike_tasks',
			'chorestrike_rooms',
			'chorestrike_floors',
			'chorestrike_house_users',
			'chorestrike_houses',
			'chorestrike_task_scopes',
			'chorestrike_medals',
			'chorestrike_combos',
			'chorestrike_user_stats',
		];

		foreach ($tables as $table) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete($table);
			$qb->executeStatement();
		}
	}

	/**
	 * Seed empty profile (just reset)
	 *
	 * @return array Empty result
	 */
	public function seedEmpty(): array
	{
		$this->reset();
		return ['profile' => 'empty', 'data' => []];
	}

	/**
	 * Seed minimal profile
	 *
	 * Creates:
	 * - 1 House
	 * - 1 Floor
	 * - 1 Room (Kitchen)
	 * - 2 Users (adult, kid)
	 *
	 * @return array Seeded data with IDs
	 */
	public function seedMinimal(): array
	{
		$this->reset();

		// Create house
		$house = new House();
		$house->setNextcloudInstanceId('test-minimal');
		$house->setName('Test House');
		$house->setHouseHealth(100);
		$house->setCreatedAt(new \DateTime());
		$house->setUpdatedAt(new \DateTime());
		$house = $this->houseMapper->insert($house);

		// Create floor
		$floor = $this->floorMapper->create($house->getId(), 'Ground Floor', 0);

		// Create room
		$room = $this->roomMapper->create($floor->getId(), 'Kitchen');

		// Create users
		$users = [];
		$userData = [
			['userId' => 'adult', 'displayName' => 'Adult User', 'role' => 'owner'],
			['userId' => 'kid', 'displayName' => 'Kid User', 'role' => 'child'],
		];

		foreach ($userData as $data) {
			$houseUser = new HouseUser();
			$houseUser->setHouseId($house->getId());
			$houseUser->setNextcloudUserId($data['userId']);
			$houseUser->setDisplayName($data['displayName']);
			$houseUser->setRole($data['role']);
			$houseUser->setJoinedAt(new \DateTime());
			$houseUser = $this->houseUserMapper->insert($houseUser);
			$users[$data['userId']] = $houseUser;
		}

		return [
			'profile' => 'minimal',
			'data' => [
				'house' => $house->jsonSerialize(),
				'floors' => [$floor->jsonSerialize()],
				'rooms' => [$room->jsonSerialize()],
				'users' => array_values(array_map(fn ($u) => $u->jsonSerialize(), $users)),
			],
		];
	}

	/**
	 * Seed family profile
	 *
	 * Creates complete family structure:
	 * - 6 Users: mom, dad, teen, kid, grandma, friend
	 * - 3 Floors: Ground, First, Second
	 * - 6 Rooms: Kitchen, Living Room, Master Bedroom, Kids Bedroom, Master Bathroom, Kids Bathroom
	 * - 12 Tasks with various categories
	 * - 12 RoomTasks with assignments
	 *
	 * @return array Seeded data with IDs
	 */
	public function seedFamily(): array
	{
		$this->reset();

		// Create house
		$house = new House();
		$house->setNextcloudInstanceId('test-family');
		$house->setName('Test Family Home');
		$house->setHouseHealth(100);
		$house->setCreatedAt(new \DateTime());
		$house->setUpdatedAt(new \DateTime());
		$house = $this->houseMapper->insert($house);

		// Create floors
		$floors = [];
		$floorData = [
			['name' => 'Ground Floor', 'level' => 0],
			['name' => 'First Floor', 'level' => 1],
			['name' => 'Second Floor', 'level' => 2],
		];
		foreach ($floorData as $i => $data) {
			$floors[] = $this->floorMapper->create($house->getId(), $data['name'], $data['level']);
		}

		// Create rooms
		$rooms = [];
		$roomData = [
			['floorIndex' => 0, 'name' => 'Kitchen', 'cleanliness' => 75],
			['floorIndex' => 0, 'name' => 'Living Room', 'cleanliness' => 60],
			['floorIndex' => 1, 'name' => 'Master Bedroom', 'cleanliness' => 80],
			['floorIndex' => 1, 'name' => 'Kids Bedroom', 'cleanliness' => 40],
			['floorIndex' => 0, 'name' => 'Master Bathroom', 'cleanliness' => 70],
			['floorIndex' => 1, 'name' => 'Kids Bathroom', 'cleanliness' => 50],
		];
		foreach ($roomData as $data) {
			$room = new Room();
			$room->setFloorId($floors[$data['floorIndex']]->getId());
			$room->setName($data['name']);
			$room->setCleanliness($data['cleanliness']);
			$room->setCreatedAt(new \DateTime());
			$room = $this->roomMapper->insert($room);
			$rooms[$data['name']] = $room;
		}

		// Create users
		$users = [];
		$userData = [
			['userId' => 'mom', 'displayName' => 'Mom', 'role' => 'owner', 'preferences' => [
				'weekdayAvailability' => ['monday' => true, 'tuesday' => true, 'wednesday' => true, 'thursday' => true, 'friday' => true, 'saturday' => true, 'sunday' => true],
				'preferredTasks' => ['kitchen', 'laundry'],
				'maxDailyTasks' => 5,
			]],
			['userId' => 'dad', 'displayName' => 'Dad', 'role' => 'owner', 'preferences' => [
				'weekdayAvailability' => ['monday' => true, 'tuesday' => true, 'wednesday' => true, 'thursday' => true, 'friday' => true, 'saturday' => true, 'sunday' => true],
				'preferredTasks' => ['garage', 'yard'],
				'maxDailyTasks' => 4,
			]],
			['userId' => 'teen', 'displayName' => 'Teen', 'role' => 'child', 'preferences' => [
				'weekdayAvailability' => ['monday' => false, 'tuesday' => false, 'wednesday' => false, 'thursday' => false, 'friday' => false, 'saturday' => true, 'sunday' => true],
				'preferredTasks' => ['bedroom', 'bathroom'],
				'maxDailyTasks' => 3,
			]],
			['userId' => 'kid', 'displayName' => 'Kid', 'role' => 'child', 'preferences' => [
				'weekdayAvailability' => ['monday' => false, 'tuesday' => false, 'wednesday' => false, 'thursday' => false, 'friday' => false, 'saturday' => true, 'sunday' => true],
				'preferredTasks' => ['toys', 'pets'],
				'maxDailyTasks' => 2,
			]],
			['userId' => 'grandma', 'displayName' => 'Grandma', 'role' => 'member', 'preferences' => [
				'weekdayAvailability' => ['monday' => true, 'tuesday' => true, 'wednesday' => true, 'thursday' => true, 'friday' => false, 'saturday' => true, 'sunday' => true],
				'preferredTasks' => ['kitchen', 'living_room'],
				'maxDailyTasks' => 2,
			]],
			['userId' => 'friend', 'displayName' => 'Friend', 'role' => 'member', 'preferences' => [
				'weekdayAvailability' => ['monday' => false, 'tuesday' => false, 'wednesday' => false, 'thursday' => false, 'friday' => false, 'saturday' => true, 'sunday' => false],
				'preferredTasks' => ['living_room'],
				'maxDailyTasks' => 1,
			]],
		];

		foreach ($userData as $data) {
			$houseUser = new HouseUser();
			$houseUser->setHouseId($house->getId());
			$houseUser->setNextcloudUserId($data['userId']);
			$houseUser->setDisplayName($data['displayName']);
			$houseUser->setRole($data['role']);
			$houseUser->setSchedulingPreferences($data['preferences']);
			$houseUser->setJoinedAt(new \DateTime());
			$houseUser = $this->houseUserMapper->insert($houseUser);
			$users[$data['userId']] = $houseUser;
		}

		// Create tasks
		$tasks = [];
		$taskData = [
			['name' => 'Do the dishes', 'description' => 'Clean all dishes and load/unload dishwasher', 'energyCost' => 3, 'priority' => 2, 'frequencyType' => 'daily', 'estimatedMinutes' => 30, 'category' => 'cleaning'],
			['name' => 'Wipe kitchen counters', 'description' => 'Clean and disinfect all kitchen surfaces', 'energyCost' => 2, 'priority' => 2, 'frequencyType' => 'daily', 'estimatedMinutes' => 15, 'category' => 'cleaning'],
			['name' => 'Take out trash', 'description' => 'Empty kitchen trash and take to outside bin', 'energyCost' => 2, 'priority' => 3, 'frequencyType' => 'daily', 'estimatedMinutes' => 10, 'category' => 'maintenance'],
			['name' => 'Vacuum living room', 'description' => 'Vacuum all carpets and floors', 'energyCost' => 4, 'priority' => 2, 'frequencyType' => 'weekly', 'estimatedMinutes' => 20, 'category' => 'cleaning'],
			['name' => 'Dust furniture', 'description' => 'Dust all surfaces in living room', 'energyCost' => 2, 'priority' => 1, 'frequencyType' => 'weekly', 'estimatedMinutes' => 15, 'category' => 'cleaning'],
			['name' => 'Tidy up toys', 'description' => 'Put away all toys and games', 'energyCost' => 1, 'priority' => 1, 'frequencyType' => 'daily', 'estimatedMinutes' => 10, 'category' => 'organizing'],
			['name' => 'Make bed', 'description' => 'Make bed and arrange pillows', 'energyCost' => 1, 'priority' => 1, 'frequencyType' => 'daily', 'estimatedMinutes' => 5, 'category' => 'organizing'],
			['name' => 'Clean bedroom', 'description' => 'Vacuum, dust, and organize bedroom', 'energyCost' => 3, 'priority' => 2, 'frequencyType' => 'weekly', 'estimatedMinutes' => 30, 'category' => 'cleaning'],
			['name' => 'Do laundry', 'description' => 'Wash, dry, and fold clothes', 'energyCost' => 4, 'priority' => 2, 'frequencyType' => 'weekly', 'estimatedMinutes' => 60, 'category' => 'cleaning'],
			['name' => 'Clean toilet', 'description' => 'Clean and disinfect toilet', 'energyCost' => 3, 'priority' => 3, 'frequencyType' => 'weekly', 'estimatedMinutes' => 15, 'category' => 'cleaning'],
			['name' => 'Clean bathroom sink', 'description' => 'Clean sink and mirror', 'energyCost' => 2, 'priority' => 2, 'frequencyType' => 'weekly', 'estimatedMinutes' => 10, 'category' => 'cleaning'],
			['name' => 'Restock bathroom supplies', 'description' => 'Refill toilet paper, soap, etc.', 'energyCost' => 1, 'priority' => 1, 'frequencyType' => 'weekly', 'estimatedMinutes' => 5, 'category' => 'maintenance'],
		];

		foreach ($taskData as $data) {
			$task = $this->taskMapper->create(
				$data['name'],
				$data['description'],
				$data['energyCost'],
				$data['priority'],
				$data['frequencyType'],
				1, // frequencyValue
				$data['estimatedMinutes'],
				$data['category']
			);
			$tasks[$data['name']] = $task;
		}

		// Create room tasks with assignments
		$roomTasks = [];
		$today = new \DateTime();
		$tomorrow = (clone $today)->modify('+1 day');

		$roomTaskData = [
			['room' => 'Kitchen', 'task' => 'Do the dishes', 'due' => $today, 'assignedTo' => 'mom'],
			['room' => 'Kitchen', 'task' => 'Wipe kitchen counters', 'due' => $today, 'assignedTo' => 'teen'],
			['room' => 'Kitchen', 'task' => 'Take out trash', 'due' => $today, 'assignedTo' => 'dad'],
			['room' => 'Living Room', 'task' => 'Tidy up toys', 'due' => $today, 'assignedTo' => 'kid'],
			['room' => 'Living Room', 'task' => 'Vacuum living room', 'due' => $tomorrow, 'assignedTo' => null],
			['room' => 'Living Room', 'task' => 'Dust furniture', 'due' => $tomorrow, 'assignedTo' => 'grandma'],
			['room' => 'Kids Bedroom', 'task' => 'Make bed', 'due' => $today, 'assignedTo' => 'kid'],
			['room' => 'Master Bedroom', 'task' => 'Make bed', 'due' => $today, 'assignedTo' => 'teen'],
			['room' => 'Master Bedroom', 'task' => 'Clean bedroom', 'due' => $tomorrow, 'assignedTo' => null],
			['room' => 'Master Bedroom', 'task' => 'Do laundry', 'due' => $tomorrow, 'assignedTo' => 'mom'],
			['room' => 'Kids Bathroom', 'task' => 'Clean bathroom sink', 'due' => $today, 'assignedTo' => 'teen'],
			['room' => 'Master Bathroom', 'task' => 'Clean toilet', 'due' => $tomorrow, 'assignedTo' => null],
		];

		foreach ($roomTaskData as $data) {
			$roomTask = $this->roomTaskMapper->create(
				$rooms[$data['room']]->getId(),
				$tasks[$data['task']]->getId(),
				$data['due'],
				$data['assignedTo']
			);
			$roomTasks[] = $roomTask;
		}

		return [
			'profile' => 'family',
			'data' => [
				'house' => $house->jsonSerialize(),
				'floors' => array_values(array_map(fn ($f) => $f->jsonSerialize(), $floors)),
				'rooms' => array_values(array_map(fn ($r) => $r->jsonSerialize(), $rooms)),
				'users' => array_values(array_map(fn ($u) => $u->jsonSerialize(), $users)),
				'tasks' => array_values(array_map(fn ($t) => $t->jsonSerialize(), $tasks)),
				'roomTasks' => array_values(array_map(fn ($rt) => $rt->jsonSerialize(), $roomTasks)),
			],
		];
	}

	/**
	 * Seed by profile name
	 *
	 * @param string $profile Profile name (empty, minimal, family)
	 * @return array Seeded data
	 */
	public function seed(string $profile): array
	{
		return match ($profile) {
			'empty' => $this->seedEmpty(),
			'minimal' => $this->seedMinimal(),
			'family' => $this->seedFamily(),
			default => throw new \InvalidArgumentException("Unknown profile: {$profile}. Valid profiles: empty, minimal, family"),
		};
	}

	/**
	 * Check if test mode is enabled
	 *
	 * @return bool
	 */
	public function isTestMode(): bool
	{
		// Check for test environment indicator
		return getenv('NEXTCLOUD_TEST_MODE') === 'true'
			|| getenv('CI') !== false
			|| isset($_ENV['NEXTCLOUD_TEST_MODE']);
	}
}
