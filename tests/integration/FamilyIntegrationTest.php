<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration;

use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\Room;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\CompletedTask;
use OCA\ChoreStrike\Service\GameLogicService;
use OCA\ChoreStrike\Tests\Fixtures\FamilyTestData;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests using the standardized family test data
 * 
 * This test suite simulates real family scenarios and tests the complete
 * workflow from task assignment to completion and gamification.
 */
class FamilyIntegrationTest extends TestCase {

    private GameLogicService $gameLogicService;
    private array $testData;

    protected function setUp(): void {
        // In a real integration test, you would set up actual database connections
        // For now, we'll use the test data fixtures
        $this->testData = FamilyTestData::getCompleteDataSet();
        
        // Mock the GameLogicService dependencies for integration testing
        $houseUserMapper = $this->createMock(\OCA\ChoreStrike\Db\HouseUserMapper::class);
        $completedTaskMapper = $this->createMock(\OCA\ChoreStrike\Db\CompletedTaskMapper::class);
        $medalMapper = $this->createMock(\OCA\ChoreStrike\Db\MedalMapper::class);
        $roomMapper = $this->createMock(\OCA\ChoreStrike\Db\RoomMapper::class);

        $this->gameLogicService = new GameLogicService(
            $houseUserMapper,
            $completedTaskMapper,
            $medalMapper,
            $roomMapper
        );
    }

    /**
     * Test complete family task completion workflow
     */
    public function testFamilyTaskCompletionWorkflow(): void {
        $scenarios = FamilyTestData::getUserScenarios();
        
        foreach ($scenarios as $scenarioName => $scenario) {
            $this->simulateUserScenario($scenarioName, $scenario);
        }
    }

    /**
     * Test parent daily routine scenario
     */
    public function testParentDailyRoutine(): void {
        $scenario = FamilyTestData::getUserScenarios()['parent_daily_routine'];
        $user = $this->testData['users'][$scenario['user']];
        
        // Simulate completing tasks
        $totalPoints = 0;
        foreach ($scenario['tasks'] as $roomTaskId) {
            $roomTask = $this->findRoomTask($roomTaskId);
            $task = $this->findTask($roomTask->getTaskId());
            
            // Mock the mappers for this test
            $this->mockMappersForUser($user);
            
            $result = $this->gameLogicService->handleTaskCompletion(
                $roomTask->getId(),
                $user->getId(),
                $task,
                75 // Room cleanliness
            );
            
            $totalPoints += $result['contributionScore'];
        }
        
        // Verify points calculation
        $this->assertEquals($scenario['expected_points'], $totalPoints, 
            "Parent daily routine should earn {$scenario['expected_points']} points");
    }

    /**
     * Test teen weekend task scenario
     */
    public function testTeenWeekendTasks(): void {
        $scenario = FamilyTestData::getUserScenarios()['teen_weekend_tasks'];
        $user = $this->testData['users'][$scenario['user']];
        
        $totalPoints = 0;
        foreach ($scenario['tasks'] as $roomTaskId) {
            $roomTask = $this->findRoomTask($roomTaskId);
            $task = $this->findTask($roomTask->getTaskId());
            
            $this->mockMappersForUser($user);
            
            $result = $this->gameLogicService->handleTaskCompletion(
                $roomTask->getId(),
                $user->getId(),
                $task,
                50 // Room cleanliness
            );
            
            $totalPoints += $result['contributionScore'];
        }
        
        $this->assertEquals($scenario['expected_points'], $totalPoints,
            "Teen weekend tasks should earn {$scenario['expected_points']} points");
    }

    /**
     * Test streak calculation for daily tasks
     */
    public function testDailyStreakCalculation(): void {
        $user = $this->testData['users']['mom'];
        
        // Set initial streak
        $user->setCurrentStreak(5);
        $user->setLastActivity(new \DateTime('yesterday'));
        
        // Simulate completing today's task
        $roomTask = $this->findRoomTask('dishes_today');
        $task = $this->findTask('dishes');
        
        $this->mockMappersForUser($user);
        
        $result = $this->gameLogicService->handleTaskCompletion(
            $roomTask->getId(),
            $user->getId(),
            $task,
            70
        );
        
        // Streak should increase from 5 to 6
        $this->assertEquals(6, $result['currentStreak'],
            "Daily task completion should increase streak");
    }

    /**
     * Test concurrent task completion (two users completing same task type)
     */
    public function testConcurrentTaskCompletion(): void {
        $mom = $this->testData['users']['mom'];
        $dad = $this->testData['users']['dad'];
        
        // Both complete kitchen tasks
        $momTask = $this->findRoomTask('dishes_today');
        $dadTask = $this->findRoomTask('wipe_counters_today');
        
        $dishesTask = $this->findTask('dishes');
        $countersTask = $this->findTask('wipe_counters');
        
        $this->mockMappersForUser($mom);
        $this->mockMappersForUser($dad);
        
        $momResult = $this->gameLogicService->handleTaskCompletion(
            $momTask->getId(),
            $mom->getId(),
            $dishesTask,
            70
        );
        
        $dadResult = $this->gameLogicService->handleTaskCompletion(
            $dadTask->getId(),
            $dad->getId(),
            $countersTask,
            70
        );
        
        // Both should earn points independently
        $this->assertGreaterThan(0, $momResult['contributionScore']);
        $this->assertGreaterThan(0, $dadResult['contributionScore']);
    }

    /**
     * Test recurring task activation
     */
    public function testRecurringTaskActivation(): void {
        $roomTask = $this->findRoomTask('dishes_today');
        $task = $this->findTask('dishes');
        
        // Mark as completed
        $roomTask->markCompleted(
            new \DateTime(),
            $task->getFrequencyType(),
            $task->getFrequencyValue(),
            'recurring'
        );
        
        // Task should remain active for recurring tasks
        $this->assertTrue($roomTask->getIsActive(),
            "Recurring task should remain active after completion");
        
        // Next due date should be tomorrow
        $tomorrow = (new \DateTime())->modify('+1 day')->format('Y-m-d');
        $nextDue = $roomTask->getNextDue()->format('Y-m-d');
        $this->assertEquals($tomorrow, $nextDue,
            "Recurring task should be scheduled for tomorrow");
    }

    /**
     * Test one-time task deactivation
     */
    public function testOneTimeTaskDeactivation(): void {
        $roomTask = $this->findRoomTask('dishes_today');
        $task = $this->findTask('dishes');
        
        // Mark as completed with one_time mode
        $roomTask->markCompleted(
            new \DateTime(),
            $task->getFrequencyType(),
            $task->getFrequencyValue(),
            'one_time'
        );
        
        // Task should be deactivated
        $this->assertFalse($roomTask->getIsActive(),
            "One-time task should be deactivated after completion");
    }

    /**
     * Test room cleanliness impact on points
     */
    public function testRoomCleanlinessImpact(): void {
        $user = $this->testData['users']['teen'];
        $roomTask = $this->findRoomTask('make_bed_teen');
        $task = $this->findTask('make_bed');
        
        $this->mockMappersForUser($user);
        
        // Test with clean room (high cleanliness)
        $cleanResult = $this->gameLogicService->handleTaskCompletion(
            $roomTask->getId(),
            $user->getId(),
            $task,
            90 // Clean room
        );
        
        // Test with dirty room (low cleanliness)
        $dirtyResult = $this->gameLogicService->handleTaskCompletion(
            $roomTask->getId(),
            $user->getId(),
            $task,
            20 // Dirty room
        );
        
        // Dirty room should give more points
        $this->assertGreaterThan($cleanResult['contributionScore'], $dirtyResult['contributionScore'],
            "Dirty room should give more points");
    }

    // Helper methods

    private function simulateUserScenario(string $scenarioName, array $scenario): void {
        $user = $this->testData['users'][$scenario['user']];
        
        $totalPoints = 0;
        foreach ($scenario['tasks'] as $roomTaskId) {
            $roomTask = $this->findRoomTask($roomTaskId);
            $task = $this->findTask($roomTask->getTaskId());
            
            $this->mockMappersForUser($user);
            
            $result = $this->gameLogicService->handleTaskCompletion(
                $roomTask->getId(),
                $user->getId(),
                $task,
                75
            );
            
            $totalPoints += $result['contributionScore'];
        }
        
        $this->assertEquals($scenario['expected_points'], $totalPoints,
            "Scenario '{$scenarioName}' should earn {$scenario['expected_points']} points");
    }

    private function findRoomTask(int $id): RoomTask {
        foreach ($this->testData['roomTasks'] as $roomTask) {
            if ($roomTask->getId() === $id) {
                return $roomTask;
            }
        }
        throw new \Exception("Room task '{$id}' not found");
    }

    private function findTask(int $id): Task {
        foreach ($this->testData['tasks'] as $task) {
            if ($task->getId() === $id) {
                return $task;
            }
        }
        throw new \Exception("Task '{$id}' not found");
    }

    private function mockMappersForUser(HouseUser $user): void {
        // Mock houseUserMapper->find
        $this->gameLogicService = new class($this->gameLogicService) {
            private GameLogicService $inner;
            private HouseUser $user;

            public function __construct(GameLogicService $inner) {
                $this->inner = $inner;
            }

            public function handleTaskCompletion(int $roomTaskId, int $userId, Task $task, int $roomCleanliness): array {
                // Mock the user lookup
                $this->user = $this->mockUser;
                return $this->inner->handleTaskCompletion($roomTaskId, $userId, $task, $roomCleanliness);
            }

            public function setUser(HouseUser $user): void {
                $this->mockUser = $user;
            }
        };
        
        // This is a simplified mock - in real integration tests you'd use proper mocking
        $this->gameLogicService->setUser($user);
    }
}
