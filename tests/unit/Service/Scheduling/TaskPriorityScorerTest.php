<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service\Scheduling;

use OCA\ChoreStrike\Service\Scheduling\TaskPriorityScorer;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Db\TaskMapper;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

class TaskPriorityScorerTest extends TestCase {

	private TaskPriorityScorer $scorer;
	private TaskMapper|MockObject $taskMapper;

	protected function setUp(): void {
		$this->taskMapper = $this->createMock(TaskMapper::class);
		$this->scorer = new TaskPriorityScorer($this->taskMapper);
	}

	public function testCalculateScores(): void {
		$roomTask1 = $this->createMockRoomTask(1, new \DateTime('2026-03-05'));
		$roomTask2 = $this->createMockRoomTask(2, new \DateTime('2026-03-01'));
		$roomTask3 = $this->createMockRoomTask(3, null); // No due date

		$task1 = $this->createMockTask(1, 5, 8);
		$task2 = $this->createMockTask(2, 3, 5);

		$this->taskMapper->method('find')
			->willReturnMap([
				[1, $task1],
				[2, $task2],
			]);

		$roomTasks = [$roomTask1, $roomTask2, $roomTask3];
		$result = $this->scorer->calculateScores($roomTasks);

		$this->assertCount(2, $result); // Only tasks with due dates
		$this->assertEquals(1, $result[0]['room_task']->getId());
		$this->assertEquals(2, $result[1]['room_task']->getId());
	}

	public function testCalculatePriorityScore(): void {
		// Test normal task
		$score = $this->scorer->calculatePriorityScore(5, 3, 0, 8);
		$expected = (5 * 10) + (max(0, 7 - 3) * 5) + (0 * 20) + (8 * 2);
		$this->assertEquals($expected, $score);

		// Test overdue task
		$score = $this->scorer->calculatePriorityScore(3, -2, 2, 5);
		$expected = (3 * 10) + (max(0, 7 - (-2)) * 5) + (2 * 20) + (5 * 2);
		$this->assertEquals($expected, $score);
	}

	public function testSortByPriority(): void {
		$scoredTasks = [
			['priority_score' => 30, 'room_task' => $this->createMockRoomTask(1)],
			['priority_score' => 80, 'room_task' => $this->createMockRoomTask(2)],
			['priority_score' => 50, 'room_task' => $this->createMockRoomTask(3)],
		];

		$result = $this->scorer->sortByPriority($scoredTasks);

		$this->assertEquals(80, $result[0]['priority_score']);
		$this->assertEquals(50, $result[1]['priority_score']);
		$this->assertEquals(30, $result[2]['priority_score']);
	}

	public function testGetOverdueTasks(): void {
		$scoredTasks = [
			['is_overdue' => false, 'room_task' => $this->createMockRoomTask(1)],
			['is_overdue' => true, 'room_task' => $this->createMockRoomTask(2)],
			['is_overdue' => false, 'room_task' => $this->createMockRoomTask(3)],
			['is_overdue' => true, 'room_task' => $this->createMockRoomTask(4)],
		];

		$result = $this->scorer->getOverdueTasks($scoredTasks);

		// array_filter preserves keys, so reindex
		$result = array_values($result);
		$this->assertCount(2, $result);
		$this->assertEquals(2, $result[0]['room_task']->getId());
		$this->assertEquals(4, $result[1]['room_task']->getId());
	}

	public function testGetTasksDueSoon(): void {
		$scoredTasks = [
			['is_overdue' => false, 'days_until_due' => 5, 'room_task' => $this->createMockRoomTask(1)],
			['is_overdue' => false, 'days_until_due' => 2, 'room_task' => $this->createMockRoomTask(2)],
			['is_overdue' => false, 'days_until_due' => 3, 'room_task' => $this->createMockRoomTask(3)],
			['is_overdue' => true, 'days_until_due' => 1, 'room_task' => $this->createMockRoomTask(4)], // Overdue, should not be included
		];

		$result = $this->scorer->getTasksDueSoon($scoredTasks);

		// array_filter preserves keys, so reindex
		$result = array_values($result);
		$this->assertCount(2, $result);
		$this->assertEquals(2, $result[0]['room_task']->getId());
		$this->assertEquals(3, $result[1]['room_task']->getId());
	}

	public function testGetHighPriorityTasks(): void {
		$scoredTasks = [
			['priority_score' => 30, 'room_task' => $this->createMockRoomTask(1)],
			['priority_score' => 60, 'room_task' => $this->createMockRoomTask(2)],
			['priority_score' => 45, 'room_task' => $this->createMockRoomTask(3)],
			['priority_score' => 80, 'room_task' => $this->createMockRoomTask(4)],
		];

		$result = $this->scorer->getHighPriorityTasks($scoredTasks, 50);

		// array_filter preserves keys, so reindex
		$result = array_values($result);
		$this->assertCount(2, $result);
		$this->assertEquals(2, $result[0]['room_task']->getId());
		$this->assertEquals(4, $result[1]['room_task']->getId());
	}

	public function testGetHighPriorityTasksWithCustomThreshold(): void {
		$scoredTasks = [
			['priority_score' => 30, 'room_task' => $this->createMockRoomTask(1)],
			['priority_score' => 40, 'room_task' => $this->createMockRoomTask(2)],
			['priority_score' => 45, 'room_task' => $this->createMockRoomTask(3)],
		];

		$result = $this->scorer->getHighPriorityTasks($scoredTasks, 40);

		// array_filter preserves keys, so reindex
		$result = array_values($result);
		$this->assertCount(2, $result);
		$this->assertEquals(2, $result[0]['room_task']->getId());
		$this->assertEquals(3, $result[1]['room_task']->getId());
	}

	private function createMockRoomTask(int $id, ?\DateTime $nextDue = null): RoomTask {
		$roomTask = new RoomTask();
		$roomTask->setId($id);
		$roomTask->setTaskId($id);
		$roomTask->setNextDue($nextDue);
		return $roomTask;
	}

	private function createMockTask(int $id, int $priority, int $energyCost): Task {
		$task = new Task();
		$task->setId($id);
		$task->setPriority($priority);
		$task->setEnergyCost($energyCost);
		return $task;
	}
}
