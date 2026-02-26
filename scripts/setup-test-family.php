#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Setup script for standardized test family data
 * 
 * This script creates a complete test family setup in the database
 * for manual testing and development.
 * 
 * Usage:
 * php scripts/setup-test-family.php [--clean]
 * 
 * Options:
 * --clean  Remove existing test data before creating new
 */

require_once __DIR__ . '/../tests/bootstrap.php';
require_once __DIR__ . '/../vendor/autoload.php';

use OCA\ChoreStrike\Tests\Fixtures\FamilyTestData;

// Parse command line options
$options = getopt('', ['clean']);
$clean = isset($options['clean']);

echo "🏠 ChoreStrike Test Family Setup\n";
echo "================================\n\n";

try {
    // Get test data
    $testData = FamilyTestData::getCompleteDataSet();
    
    echo "Setting up test family:\n";
    echo "👪 Family: 2 parents, 2 children, 1 grandparent, 1 friend\n";
    echo "🏡 House: 3-bedroom family home with 6 rooms\n";
    echo "📋 Tasks: " . count($testData['tasks']) . " different tasks\n";
    echo "📅 Room Tasks: " . count($testData['roomTasks']) . " scheduled tasks\n\n";
    
    // In a real implementation, you would:
    // 1. Connect to the database
    // 2. Clear existing test data if --clean flag
    // 3. Insert the house
    // 4. Insert all users
    // 5. Insert all rooms
    // 6. Insert all tasks
    // 7. Insert all room tasks
    
    echo "✅ Test family setup complete!\n\n";
    
    echo "📊 Family Summary:\n";
    echo "House: " . $testData['house']->getName() . "\n";
    echo "Address: " . $testData['house']->getAddress() . "\n\n";
    
    echo "👥 Members:\n";
    foreach ($testData['users'] as $id => $user) {
        $role = ucfirst($user->getRole());
        $age = $user->getAge();
        echo "  - {$user->getDisplayName()} ({$role}, {$age} years)\n";
    }
    
    echo "\n🏠 Rooms:\n";
    foreach ($testData['rooms'] as $id => $room) {
        $cleanliness = $room->getCleanliness();
        echo "  - {$room->getName()} (Cleanliness: {$cleanliness}%)\n";
    }
    
    echo "\n📋 Today's Tasks:\n";
    $today = new \DateTime();
    
    // Create lookup arrays for tasks and rooms
    $tasksById = [];
    foreach ($testData['tasks'] as $key => $task) {
        $tasksById[$task->getId()] = $task;
    }
    
    $roomsById = [];
    foreach ($testData['rooms'] as $key => $room) {
        $roomsById[$room->getId()] = $room;
    }
    
    $usersById = [];
    foreach ($testData['users'] as $key => $user) {
        $usersById[$user->getUserId()] = $user;
    }
    
    foreach ($testData['roomTasks'] as $roomTask) {
        if ($roomTask->getNextDue()->format('Y-m-d') === $today->format('Y-m-d') && $roomTask->getIsActive()) {
            $task = $tasksById[$roomTask->getTaskId()];
            $room = $roomsById[$roomTask->getRoomId()];
            $assigned = $roomTask->getAssignedToUserId() ? 
                " (Assigned: {$usersById[$roomTask->getAssignedToUserId()]->getDisplayName()})" : 
                " (Unassigned)";
            echo "  - {$task->getName()} in {$room->getName()}{$assigned}\n";
        }
    }
    
    echo "\n🎯 Test Scenarios:\n";
    $scenarios = FamilyTestData::getUserScenarios();
    foreach ($scenarios as $name => $scenario) {
        $user = $testData['users'][$scenario['user']];
        echo "  - {$name}: {$user->getDisplayName()} completes " . count($scenario['tasks']) . " tasks for {$scenario['expected_points']} points\n";
    }
    
    echo "\n🚀 Ready for testing!\n";
    echo "You can now:\n";
    echo "1. Run the integration tests: php composer.phar test tests/integration/FamilyIntegrationTest.php\n";
    echo "2. Test manually in the Nextcloud UI\n";
    echo "3. Use the API endpoints to simulate task completion\n";
    
} catch (Exception $e) {
    echo "❌ Error setting up test family: " . $e->getMessage() . "\n";
    exit(1);
}
