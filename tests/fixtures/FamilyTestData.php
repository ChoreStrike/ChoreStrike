<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Fixtures;

use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\Room;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Db\RoomTask;

/**
 * Standardized test family setup for ChoreStrike testing
 *
 * Family Structure:
 * - 2 Parents (Mom, Dad) - Owners
 * - 2 Children (Teen, Kid) - Children
 * - 1 Grandparent - Guest
 * - 1 Friend - Guest
 *
 * House: 3-bedroom family home
 * - Kitchen, Living Room, 2 Bedrooms, 2 Bathrooms
 */
class FamilyTestData
{
    public static function createHouse(): House
    {
        $house = new House();
        $house->setName('Test Family Home');
        $house->setAddress('123 Test Street');
        $house->setCity('Test City');
        $house->setActive(true);
        $house->setCreatedAt(new \DateTime('2024-01-01'));
        return $house;
    }

    public static function createFamilyMembers(): array
    {
        return [
            // Parents (Owners)
            'mom' => self::createHouseUser(1, 'mom', 'Mom', 'owner', 35, [
                'weekdayAvailability' => ['monday' => true, 'tuesday' => true, 'wednesday' => true, 'thursday' => true, 'friday' => true, 'saturday' => true, 'sunday' => true],
                'preferredTasks' => ['kitchen', 'laundry'],
                'maxDailyTasks' => 5
            ]),
            'dad' => self::createHouseUser(2, 'dad', 'Dad', 'owner', 37, [
                'weekdayAvailability' => ['monday' => true, 'tuesday' => true, 'wednesday' => true, 'thursday' => true, 'friday' => true, 'saturday' => true, 'sunday' => true],
                'preferredTasks' => ['garage', 'yard'],
                'maxDailyTasks' => 4
            ]),

            // Children
            'teen' => self::createHouseUser(3, 'teen', 'Teen', 'child', 15, [
                'weekdayAvailability' => ['monday' => false, 'tuesday' => false, 'wednesday' => false, 'thursday' => false, 'friday' => false, 'saturday' => true, 'sunday' => true],
                'preferredTasks' => ['bedroom', 'bathroom'],
                'maxDailyTasks' => 3
            ]),
            'kid' => self::createHouseUser(4, 'kid', 'Kid', 'child', 8, [
                'weekdayAvailability' => ['monday' => false, 'tuesday' => false, 'wednesday' => false, 'thursday' => false, 'friday' => false, 'saturday' => true, 'sunday' => true],
                'preferredTasks' => ['toys', 'pets'],
                'maxDailyTasks' => 2
            ]),

            // Extended Family/Friends (Guests)
            'grandma' => self::createHouseUser(5, 'grandma', 'Grandma', 'guest', 65, [
                'weekdayAvailability' => ['monday' => true, 'tuesday' => true, 'wednesday' => true, 'thursday' => true, 'friday' => false, 'saturday' => true, 'sunday' => true],
                'preferredTasks' => ['kitchen', 'living_room'],
                'maxDailyTasks' => 2
            ]),
            'friend' => self::createHouseUser(6, 'friend', 'Friend', 'guest', 30, [
                'weekdayAvailability' => ['monday' => false, 'tuesday' => false, 'wednesday' => false, 'thursday' => false, 'friday' => false, 'saturday' => true, 'sunday' => false],
                'preferredTasks' => ['living_room'],
                'maxDailyTasks' => 1
            ]),
        ];
    }

    private static function createHouseUser(int $id, string $userId, string $displayName, string $role, int $age, array $preferences): HouseUser
    {
        $user = new HouseUser();
        $user->setId($id);
        $user->setUserId($userId);
        $user->setDisplayName($displayName);
        $user->setRole($role);
        $user->setAge($age);
        $user->setContributionScore(0);
        $user->setTeamSparks(0);
        $user->setCurrentStreak(0);
        $user->setLongestStreak(0);
        $user->setSchedulingPreferences(json_encode($preferences));
        $user->setJoinedAt(new \DateTime('2024-01-01'));
        $user->setLastActivity(new \DateTime('2024-01-01'));
        return $user;
    }

    public static function createRooms(): array
    {
        return [
            'kitchen' => self::createRoom(1, 'kitchen', 'Kitchen', 1, 75),
            'living_room' => self::createRoom(2, 'living_room', 'Living Room', 1, 60),
            'master_bedroom' => self::createRoom(3, 'master_bedroom', 'Master Bedroom', 2, 80),
            'kids_bedroom' => self::createRoom(4, 'kids_bedroom', 'Kids Bedroom', 2, 40),
            'master_bathroom' => self::createRoom(5, 'master_bathroom', 'Master Bathroom', 1, 70),
            'kids_bathroom' => self::createRoom(6, 'kids_bathroom', 'Kids Bathroom', 1, 50),
        ];
    }

    private static function createRoom(int $id, string $key, string $name, int $floorId, int $cleanliness): Room
    {
        $room = new Room();
        $room->setId($id);
        $room->setName($name);
        $room->setFloorId($floorId);
        $room->setCleanliness($cleanliness);
        $room->setLastCleaned(new \DateTime('2024-01-01'));
        $room->setCreatedAt(new \DateTime('2024-01-01'));
        return $room;
    }

    public static function createTasks(): array
    {
        return [
            // Kitchen Tasks
            'dishes' => self::createTask(1, 'dishes', 'Do the dishes', 'Clean all dishes and load/unload dishwasher', 3, 30, 2, 'daily', 1),
            'wipe_counters' => self::createTask(2, 'wipe_counters', 'Wipe kitchen counters', 'Clean and disinfect all kitchen surfaces', 2, 15, 2, 'daily', 1),
            'take_out_trash' => self::createTask(3, 'take_out_trash', 'Take out kitchen trash', 'Empty kitchen trash and take to outside bin', 2, 10, 3, 'daily', 1),

            // Living Room Tasks
            'vacuum_living' => self::createTask(4, 'vacuum_living', 'Vacuum living room', 'Vacuum all carpets and floors', 4, 20, 2, 'weekly', 1),
            'dust_furniture' => self::createTask(5, 'dust_furniture', 'Dust furniture', 'Dust all surfaces in living room', 2, 15, 1, 'weekly', 1),
            'tidy_toys' => self::createTask(6, 'tidy_toys', 'Tidy up toys', 'Put away all toys and games', 1, 10, 1, 'daily', 1),

            // Bedroom Tasks
            'make_bed' => self::createTask(7, 'make_bed', 'Make bed', 'Make bed and arrange pillows', 1, 5, 1, 'daily', 1),
            'clean_bedroom' => self::createTask(8, 'clean_bedroom', 'Clean bedroom', 'Vacuum, dust, and organize bedroom', 3, 30, 2, 'weekly', 1),
            'laundry' => self::createTask(9, 'laundry', 'Do laundry', 'Wash, dry, and fold clothes', 4, 60, 2, 'weekly', 1),

            // Bathroom Tasks
            'clean_toilet' => self::createTask(10, 'clean_toilet', 'Clean toilet', 'Clean and disinfect toilet', 3, 15, 3, 'weekly', 1),
            'clean_sink' => self::createTask(11, 'clean_sink', 'Clean bathroom sink', 'Clean sink and mirror', 2, 10, 2, 'weekly', 1),
            'restock_supplies' => self::createTask(12, 'restock_supplies', 'Restock bathroom supplies', 'Refill toilet paper, soap, etc.', 1, 5, 1, 'weekly', 1),
        ];
    }

    private static function createTask(int $id, string $key, string $name, string $description, int $energyCost, int $estimatedMinutes, int $priority, string $frequencyType, int $frequencyValue): Task
    {
        $task = new Task();
        $task->setId($id);
        $task->setName($name);
        $task->setDescription($description);
        $task->setEnergyCost($energyCost);
        $task->setEstimatedMinutes($estimatedMinutes);
        $task->setPriority($priority);
        $task->setFrequencyType($frequencyType);
        $task->setFrequencyValue($frequencyValue);
        $task->setScopeId(1); // Default scope
        $task->setCreatedAt(new \DateTime('2024-01-01'));
        return $task;
    }

    public static function createRoomTasks(): array
    {
        $roomTasks = [];
        $today = new \DateTime();
        $tomorrow = (clone $today)->modify('+1 day');

        // Kitchen tasks for today
        $roomTasks[] = self::createRoomTask(1, 1, 1, $today, true, 'mom');
        $roomTasks[] = self::createRoomTask(2, 2, 1, $today, true, 'teen');
        $roomTasks[] = self::createRoomTask(3, 3, 1, $today, true, 'dad');

        // Living room tasks for today
        $roomTasks[] = self::createRoomTask(4, 6, 2, $today, true, 'kid');
        $roomTasks[] = self::createRoomTask(5, 4, 2, $tomorrow, true, null);

        // Bedroom tasks for today
        $roomTasks[] = self::createRoomTask(6, 7, 4, $today, true, 'teen');
        $roomTasks[] = self::createRoomTask(7, 7, 3, $today, true, 'kid');
        $roomTasks[] = self::createRoomTask(8, 8, 3, $tomorrow, true, null);

        // Bathroom tasks for today
        $roomTasks[] = self::createRoomTask(9, 11, 6, $today, true, 'teen');
        $roomTasks[] = self::createRoomTask(10, 10, 6, $tomorrow, true, null);

        // Weekly tasks
        $roomTasks[] = self::createRoomTask(11, 9, 3, $tomorrow, true, 'mom');
        $roomTasks[] = self::createRoomTask(12, 5, 2, $tomorrow, true, 'grandma');

        return $roomTasks;
    }

    private static function createRoomTask(int $id, int $taskId, int $roomId, \DateTime $nextDue, bool $isActive, ?string $assignedToUserId): RoomTask
    {
        $roomTask = new RoomTask();
        $roomTask->setId($id);
        $roomTask->setTaskId($taskId);
        $roomTask->setRoomId($roomId);
        $roomTask->setNextDue($nextDue);
        $roomTask->setAssignedToUserId($assignedToUserId);
        $roomTask->setIsActive($isActive);
        $roomTask->setCreatedAt(new \DateTime('2024-01-01'));
        return $roomTask;
    }

    /**
     * Get complete test data set
     */
    public static function getCompleteDataSet(): array
    {
        return [
            'house' => self::createHouse(),
            'users' => self::createFamilyMembers(),
            'rooms' => self::createRooms(),
            'tasks' => self::createTasks(),
            'roomTasks' => self::createRoomTasks(),
        ];
    }

    /**
     * Get user scenarios for testing
     */
    public static function getUserScenarios(): array
    {
        return [
            'parent_daily_routine' => [
                'user' => 'mom',
                'tasks' => [1, 2, 11], // dishes_today, wipe_counters_today, laundry_weekly
                'expected_points' => 108, // 36 + 24 + 48 with priority multipliers
            ],
            'teen_weekend_tasks' => [
                'user' => 'teen',
                'tasks' => [6, 9], // make_bed_teen, clean_sink_today
                'expected_points' => 34, // 10 + 24 with priority multipliers
            ],
            'kid_simple_tasks' => [
                'user' => 'kid',
                'tasks' => [7, 4], // make_bed_kid, tidy_toys_today
                'expected_points' => 20, // 1 + 1 energy costs * 10
            ],
            'guest_helping' => [
                'user' => 'grandma',
                'tasks' => [12], // dust_furniture_weekly
                'expected_points' => 20, // 2 energy cost * 10
            ],
        ];
    }
}
