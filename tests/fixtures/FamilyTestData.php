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
        $house->setNextcloudInstanceId('test-instance');
        $house->setName('Test Family Home');
        $house->setHouseHealth(100);
        $house->setCreatedAt(new \DateTime('2024-01-01'));
        return $house;
    }

    public static function createFamilyMembers(): array
    {
        return [
            // Parents (Owners) - realistic scores and streaks from ongoing household
            'mom' => self::createHouseUser(1, 'mom', 'Mom', 'owner', 35, [
                'weekdayAvailability' => ['monday' => true, 'tuesday' => true, 'wednesday' => true, 'thursday' => true, 'friday' => true, 'saturday' => true, 'sunday' => true],
                'preferredTasks' => ['kitchen', 'laundry'],
                'maxDailyTasks' => 5
            ], 1250, 7, 14, 85),
            'dad' => self::createHouseUser(2, 'dad', 'Dad', 'owner', 37, [
                'weekdayAvailability' => ['monday' => true, 'tuesday' => true, 'wednesday' => true, 'thursday' => true, 'friday' => true, 'saturday' => true, 'sunday' => true],
                'preferredTasks' => ['garage', 'yard'],
                'maxDailyTasks' => 4
            ], 980, 4, 12, 60),

            // Children - age-appropriate scores
            'teen' => self::createHouseUser(3, 'teen', 'Teen', 'child', 15, [
                'weekdayAvailability' => ['monday' => false, 'tuesday' => false, 'wednesday' => false, 'thursday' => false, 'friday' => false, 'saturday' => true, 'sunday' => true],
                'preferredTasks' => ['bedroom', 'bathroom'],
                'maxDailyTasks' => 3
            ], 450, 0, 5, 25),
            'kid' => self::createHouseUser(4, 'kid', 'Kid', 'child', 8, [
                'weekdayAvailability' => ['monday' => false, 'tuesday' => false, 'wednesday' => false, 'thursday' => false, 'friday' => false, 'saturday' => true, 'sunday' => true],
                'preferredTasks' => ['toys', 'pets'],
                'maxDailyTasks' => 2
            ], 230, 2, 3, 15),

            // Extended Family/Friends (Guests) - occasional helpers
            'grandma' => self::createHouseUser(5, 'grandma', 'Grandma', 'guest', 65, [
                'weekdayAvailability' => ['monday' => true, 'tuesday' => true, 'wednesday' => true, 'thursday' => true, 'friday' => false, 'saturday' => true, 'sunday' => true],
                'preferredTasks' => ['kitchen', 'living_room'],
                'maxDailyTasks' => 2
            ], 180, 3, 6, 12),
            'friend' => self::createHouseUser(6, 'friend', 'Friend', 'guest', 30, [
                'weekdayAvailability' => ['monday' => false, 'tuesday' => false, 'wednesday' => false, 'thursday' => false, 'friday' => false, 'saturday' => true, 'sunday' => false],
                'preferredTasks' => ['living_room'],
                'maxDailyTasks' => 1
            ], 50, 0, 2, 5),
        ];
    }

    private static function createHouseUser(int $id, string $nextcloudUserId, string $displayName, string $role, int $age, array $preferences, int $score = 0, int $currentStreak = 0, int $longestStreak = 0, int $teamSparks = 0): HouseUser
    {
        $user = new HouseUser();
        $user->setId($id);
        $user->setNextcloudUserId($nextcloudUserId);
        $user->setDisplayName($displayName);
        $user->setRole($role);
        $user->setContributionScore($score);
        $user->setTeamSparks($teamSparks);
        $user->setCurrentStreak($currentStreak);
        $user->setLongestStreak($longestStreak);
        $user->setSchedulingPreferences($preferences);
        $user->setJoinedAt(new \DateTime('2024-01-01'));
        $user->setLastActivity(new \DateTime());
        return $user;
    }

    public static function createRooms(): array
    {
        return [
            // Ground floor - cleanliness reflects overdue tasks
            'kitchen' => self::createRoom(1, 'kitchen', 'Kitchen', 1, 65), // Lower due to overdue dishes
            'living_room' => self::createRoom(2, 'living_room', 'Living Room', 1, 60),
            'master_bathroom' => self::createRoom(5, 'master_bathroom', 'Master Bathroom', 1, 70),
            
            // First floor - realistic family usage
            'master_bedroom' => self::createRoom(3, 'master_bedroom', 'Master Bedroom', 2, 85), // Recently cleaned
            'kids_bedroom' => self::createRoom(4, 'kids_bedroom', 'Kids Bedroom', 2, 30), // Very low - 2 days overdue cleaning
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
        // Realistic last cleaned dates based on cleanliness
        $lastCleanedDays = (int) ((100 - $cleanliness) / 10);
        $lastCleaned = (new \DateTime())->modify("-{$lastCleanedDays} days");
        $room->setLastCleaned($lastCleaned);
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
        $task->setScopeType('house'); // Default scope type
        $task->setCategory('general');
        $task->setIsTemplate(false);
        $task->setRecurrenceMode('standard');
        $task->setIsVoluntary(false);
        $task->setSchedulingMode('flexible');
        $task->setAllowTeamwork(false);
        $task->setTeamSparkMultiplier(1.0);
        $task->setCreatedAt(new \DateTime('2024-01-01'));
        return $task;
    }

    public static function createRoomTasks(): array
    {
        $roomTasks = [];
        $today = new \DateTime();
        $yesterday = (clone $today)->modify('-1 day');
        $twoDaysAgo = (clone $today)->modify('-2 days');
        $tomorrow = (clone $today)->modify('+1 day');

        // === Completed Tasks (History) - realistic household snapshot ===
        // Mom completed dishes yesterday
        $roomTasks[] = self::createRoomTask(1, 1, 1, $yesterday, false, 'mom');
        
        // Teen completed make bed this morning (same day but completed)
        $roomTasks[] = self::createRoomTask(2, 7, 4, $today, false, 'teen');
        
        // Dad completed trash 2 days ago
        $roomTasks[] = self::createRoomTask(3, 3, 1, $twoDaysAgo, false, 'dad');

        // === Overdue Tasks (Critical - claimable by others) ===
        // Dishes overdue (assigned to mom but not done today) - dad could claim this
        $roomTasks[] = self::createRoomTask(4, 1, 1, $yesterday, true, 'mom');
        
        // Kids bedroom cleaning overdue (assigned to kid, 2 days overdue)
        $roomTasks[] = self::createRoomTask(5, 8, 4, $twoDaysAgo, true, 'kid');

        // === Active Today (Assigned) ===
        // Wipe counters assigned to teen (due today)
        $roomTasks[] = self::createRoomTask(6, 2, 1, $today, true, 'teen');
        
        // Tidy toys assigned to kid (due today)
        $roomTasks[] = self::createRoomTask(7, 6, 2, $today, true, 'kid');
        
        // Clean sink assigned to grandma (due today)
        $roomTasks[] = self::createRoomTask(8, 11, 6, $today, true, 'grandma');
        
        // Make bed assigned to kid (due today)
        $roomTasks[] = self::createRoomTask(9, 7, 3, $today, true, 'kid');

        // === Active Today (Unassigned - available to claim) ===
        // Vacuum living room (no one assigned yet, anyone can claim)
        $roomTasks[] = self::createRoomTask(10, 4, 2, $today, true, null);
        
        // Dust furniture (unassigned, claimable)
        $roomTasks[] = self::createRoomTask(11, 5, 2, $today, true, null);

        // === Future Tasks ===
        // Laundry scheduled for tomorrow (assigned to mom)
        $roomTasks[] = self::createRoomTask(12, 9, 3, $tomorrow, true, 'mom');
        
        // Clean toilet scheduled for tomorrow (unassigned)
        $roomTasks[] = self::createRoomTask(13, 10, 6, $tomorrow, true, null);

        // Restock supplies (future, assigned to dad)
        $roomTasks[] = self::createRoomTask(14, 12, 6, $tomorrow, true, 'dad');

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
