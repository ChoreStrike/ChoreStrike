<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service\Scheduling;

use OCA\ChoreStrike\Service\Scheduling\TaskReassignmentSuggester;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Db\Room;
use OCA\ChoreStrike\Db\Floor;
use OCA\ChoreStrike\Db\AvailabilitySettings;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

class TaskReassignmentSuggesterTest extends TestCase {

	private TaskReassignmentSuggester $suggester;
	private MockObject $taskMapper;
	private MockObject $roomTaskMapper;
	private MockObject $availabilityMapper;
	private MockObject $roomMapper;
	private MockObject $floorMapper;

	protected function setUp(): void {
		$this->taskMapper = $this->createMock(\OCA\ChoreStrike\Db\TaskMapper::class);
		$this->roomTaskMapper = $this->createMock(\OCA\ChoreStrike\Db\RoomTaskMapper::class);
		$this->availabilityMapper = $this->createMock(\OCA\ChoreStrike\Db\AvailabilitySettingsMapper::class);
		$this->roomMapper = $this->createMock(\OCA\ChoreStrike\Db\RoomMapper::class);
		$this->floorMapper = $this->createMock(\OCA\ChoreStrike\Db\FloorMapper::class);

		$this->suggester = new TaskReassignmentSuggester(
			$this->taskMapper,
			$this->roomTaskMapper,
			$this->availabilityMapper,
			$this->roomMapper,
			$this->floorMapper
		);
	}

	public function testSuggestReassignmentWithValidTask(): void {
		$roomTaskId = 1;
		$roomTask = $this->createMockRoomTask($roomTaskId, 1);
		$task = $this->createMockTask(1, 'Clean Kitchen', 5, 8);
		$room = $this->createMockRoom(1, 'Kitchen');
		$floor = $this->createMockFloor(1, 123);
		$userSettings = $this->createUserSettings();

		// Setup mocks
		$this->roomTaskMapper->method('find')->with($roomTaskId)->willReturn($roomTask);
		$this->taskMapper->method('find')->with(1)->willReturn($task);
		$this->roomMapper->method('find')->with(1)->willReturn($room);
		$this->floorMapper->method('find')->with(1)->willReturn($floor);
		$this->availabilityMapper->method('findByHouse')->with(123)->willReturn($userSettings);

		$result = $this->suggester->suggestReassignment($roomTaskId);

		$this->assertTrue($result['success']);
		$this->assertArrayHasKey('task', $result);
		$this->assertArrayHasKey('suggestions', $result);
		$this->assertArrayHasKey('metadata', $result);
		
		// Check task details
		$this->assertEquals($roomTaskId, $result['task']['id']);
		$this->assertEquals('Clean Kitchen', $result['task']['name']);
		$this->assertEquals('Kitchen', $result['task']['room']);
		
		// Check suggestions structure
		$this->assertIsArray($result['suggestions']);
		foreach ($result['suggestions'] as $suggestion) {
			$this->assertArrayHasKey('user_id', $suggestion);
			$this->assertArrayHasKey('day', $suggestion);
			$this->assertArrayHasKey('score', $suggestion);
			$this->assertArrayHasKey('reason', $suggestion);
			$this->assertArrayHasKey('benefits', $suggestion);
			$this->assertArrayHasKey('constraints', $suggestion);
		}
	}

	public function testSuggestReassignmentWithInvalidTaskId(): void {
		$roomTaskId = 999;

		$this->roomTaskMapper->method('find')->with($roomTaskId)->willThrowException(new \Exception('Room task not found'));

		$result = $this->suggester->suggestReassignment($roomTaskId);

		$this->assertFalse($result['success']);
		$this->assertEquals('Room task not found', $result['error']);
		$this->assertEmpty($result['suggestions']);
	}

	public function testSuggestReassignmentWithInvalidTask(): void {
		$roomTaskId = 1;
		$roomTask = $this->createMockRoomTask($roomTaskId, 1);

		$this->roomTaskMapper->method('find')->with($roomTaskId)->willReturn($roomTask);
		$this->taskMapper->method('find')->with(1)->willThrowException(new \Exception('Task not found'));

		$result = $this->suggester->suggestReassignment($roomTaskId);

		$this->assertFalse($result['success']);
		$this->assertEquals('Task not found', $result['error']);
		$this->assertEmpty($result['suggestions']);
	}

	public function testSuggestReassignmentWithOverdueTask(): void {
		$roomTaskId = 1;
		$roomTask = $this->createMockRoomTask($roomTaskId, 1);
		$task = $this->createMockTask(1, 'Clean Kitchen', 5, 8, true); // Overdue
		$room = $this->createMockRoom(1, 'Kitchen');
		$floor = $this->createMockFloor(1, 123);
		$userSettings = $this->createUserSettings();

		$this->roomTaskMapper->method('find')->with($roomTaskId)->willReturn($roomTask);
		$this->taskMapper->method('find')->with(1)->willReturn($task);
		$this->roomMapper->method('find')->with(1)->willReturn($room);
		$this->floorMapper->method('find')->with(1)->willReturn($floor);
		$this->availabilityMapper->method('findByHouse')->with(123)->willReturn($userSettings);

		$result = $this->suggester->suggestReassignment($roomTaskId);

		$this->assertTrue($result['success']);
		$this->assertTrue($result['task']['is_overdue']);
		
		// Should have urgent reassignment suggestions
		$urgentSuggestions = array_filter($result['suggestions'], fn($s) => 
			isset($s['urgency_level']) && $s['urgency_level'] === 'high'
		);
		$this->assertNotEmpty($urgentSuggestions);
	}

	public function testSuggestReassignmentFiltersAndRanksSuggestions(): void {
		$roomTaskId = 1;
		$roomTask = $this->createMockRoomTask($roomTaskId, 1);
		$task = $this->createMockTask(1, 'Clean Kitchen', 5, 8);
		$room = $this->createMockRoom(1, 'Kitchen');
		$floor = $this->createMockFloor(1, 123);
		$userSettings = $this->createUserSettings();

		$this->roomTaskMapper->method('find')->with($roomTaskId)->willReturn($roomTask);
		$this->taskMapper->method('find')->with(1)->willReturn($task);
		$this->roomMapper->method('find')->with(1)->willReturn($room);
		$this->floorMapper->method('find')->with(1)->willReturn($floor);
		$this->availabilityMapper->method('findByHouse')->with(123)->willReturn($userSettings);

		$result = $this->suggester->suggestReassignment($roomTaskId);

		$this->assertTrue($result['success']);
		
		// Should have limited number of suggestions (top 5)
		$this->assertLessThanOrEqual(5, count($result['suggestions']));
		
		// Suggestions should be sorted by score (highest first)
		$scores = array_column($result['suggestions'], 'score');
		$sortedScores = $scores;
		rsort($sortedScores);
		$this->assertEquals($sortedScores, $scores);
	}

	public function testSuggestReassignmentIncludesMetadata(): void {
		$roomTaskId = 1;
		$roomTask = $this->createMockRoomTask($roomTaskId, 1);
		$task = $this->createMockTask(1, 'Clean Kitchen', 5, 8);
		$room = $this->createMockRoom(1, 'Kitchen');
		$floor = $this->createMockFloor(1, 123);
		$userSettings = $this->createUserSettings();

		$this->roomTaskMapper->method('find')->with($roomTaskId)->willReturn($roomTask);
		$this->taskMapper->method('find')->with(1)->willReturn($task);
		$this->roomMapper->method('find')->with(1)->willReturn($room);
		$this->floorMapper->method('find')->with(1)->willReturn($floor);
		$this->availabilityMapper->method('findByHouse')->with(123)->willReturn($userSettings);

		$result = $this->suggester->suggestReassignment($roomTaskId);

		$this->assertTrue($result['success']);
		$this->assertArrayHasKey('metadata', $result);
		
		$metadata = $result['metadata'];
		$this->assertArrayHasKey('total_users', $metadata);
		$this->assertArrayHasKey('available_users', $metadata);
		$this->assertArrayHasKey('analysis_timestamp', $metadata);
		
		$this->assertEquals(count($userSettings), $metadata['total_users']);
		$this->assertGreaterThan(0, $metadata['available_users']);
		$this->assertMatchesRegularExpression('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', $metadata['analysis_timestamp']);
	}

	public function testSuggestReassignmentWithNoAvailableUsers(): void {
		$roomTaskId = 1;
		$roomTask = $this->createMockRoomTask($roomTaskId, 1);
		$task = $this->createMockTask(1, 'Clean Kitchen', 5, 8);
		$room = $this->createMockRoom(1, 'Kitchen');
		$floor = $this->createMockFloor(1, 123);
		
		// Create user settings with no availability
		$userSettings = [];
		for ($i = 1; $i <= 3; $i++) {
			$user = new AvailabilitySettings();
			$user->setUserId("user{$i}");
			$user->setWeekdayAvailability([
				'monday' => false, 'tuesday' => false, 'wednesday' => false,
				'thursday' => false, 'friday' => false, 'saturday' => false, 'sunday' => false
			]);
			$userSettings[] = $user;
		}

		$this->roomTaskMapper->method('find')->with($roomTaskId)->willReturn($roomTask);
		$this->taskMapper->method('find')->with(1)->willReturn($task);
		$this->roomMapper->method('find')->with(1)->willReturn($room);
		$this->floorMapper->method('find')->with(1)->willReturn($floor);
		$this->availabilityMapper->method('findByHouse')->with(123)->willReturn($userSettings);

		$result = $this->suggester->suggestReassignment($roomTaskId);

		$this->assertTrue($result['success']);
		$this->assertEquals(0, $result['metadata']['available_users']);
		$this->assertEmpty($result['suggestions']);
	}

	private function createMockRoomTask(int $id, int $roomId): RoomTask {
		$roomTask = new RoomTask();
		$roomTask->setId($id);
		$roomTask->setRoomId($roomId);
		$roomTask->setTaskId($id);
		return $roomTask;
	}

	private function createMockTask(int $id, string $name, int $priority, int $energyCost, bool $overdue = false): Task {
		$task = new Task();
		$task->setId($id);
		$task->setName($name);
		$task->setPriority($priority);
		$task->setEnergyCost($energyCost);
		return $task;
	}

	private function createMockRoom(int $id, string $name): Room {
		$room = new Room();
		$room->setId($id);
		$room->setName($name);
		$room->setFloorId($id);
		return $room;
	}

	private function createMockFloor(int $id, int $houseId): Floor {
		$floor = new Floor();
		$floor->setId($id);
		$floor->setHouseId($houseId);
		return $floor;
	}

	private function createUserSettings(): array {
		$users = [];
		
		for ($i = 1; $i <= 3; $i++) {
			$user = new AvailabilitySettings();
			$user->setUserId("user{$i}");
			$user->setWeekdayAvailability([
				'monday' => true, 'tuesday' => true, 'wednesday' => true,
				'thursday' => true, 'friday' => true, 'saturday' => true, 'sunday' => true
			]);
			$user->setMaxTasksPerDay(3);
			$user->setEnergyBudget([
				'monday' => 10, 'tuesday' => 10, 'wednesday' => 10,
				'thursday' => 10, 'friday' => 10, 'saturday' => 10, 'sunday' => 10
			]);
			$users[] = $user;
		}
		
		return $users;
	}
}
