<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Db;

use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Service\CompletionService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;

/**
 * CR-053-09 regression: a validation-accepted 'once' room task must
 * deactivate on completion (the former silent infinite recurrence), while
 * 'recurring' projects the next occurrence.
 */
class RecurrenceOnceDeactivationTest extends IntegrationTestCase {
	private TaskMapper $taskMapper;
	private RoomTaskMapper $roomTaskMapper;

	protected function setUp(): void {
		parent::setUp();
		$this->taskMapper = \OC::$server->get(TaskMapper::class);
		$this->roomTaskMapper = \OC::$server->get(RoomTaskMapper::class);
	}

	/**
	 * CompleteRoomTask_OnceRecurrence_DeactivatesTask
	 */
	public function testCompleteRoomTask_OnceRecurrence_DeactivatesTask(): void {
		$houseId = $this->createHouse('once_owner', 'Once House');
		$floorId = $this->createFloor($houseId, 'Ground Floor', 1);
		$roomId = $this->createRoom($houseId, $floorId, 'Kitchen');

		$taskId = $this->createTaskWithRecurrence($houseId, 'One-time chore', Task::RECURRENCE_ONCE);
		$roomTaskId = $this->assignTaskToRoom($taskId, $roomId, 'once_owner');

		$this->actingAs('once_owner');
		$completionService = \OC::$server->get(CompletionService::class);
		$result = $this->completeOccurrence($completionService, $houseId, 'once_owner', $roomTaskId);

		/** @var RoomTask $completed */
		$completed = $result['roomTask'];
		self::assertFalse($completed->getIsActive(), "a 'once' task must deactivate on completion");
		self::assertNotNull($completed->getLastCompleted());
	}

	/**
	 * CompleteRoomTask_RecurringRecurrence_ProjectsNextOccurrence
	 */
	public function testCompleteRoomTask_RecurringRecurrence_ProjectsNextOccurrence(): void {
		$houseId = $this->createHouse('rec_owner', 'Recurring House');
		$floorId = $this->createFloor($houseId, 'Ground Floor', 1);
		$roomId = $this->createRoom($houseId, $floorId, 'Kitchen');

		$taskId = $this->createTaskWithRecurrence($houseId, 'Weekly chore', Task::RECURRENCE_RECURRING);
		$roomTaskId = $this->assignTaskToRoom($taskId, $roomId, 'rec_owner');

		$this->actingAs('rec_owner');
		$completionService = \OC::$server->get(CompletionService::class);
		$result = $this->completeOccurrence($completionService, $houseId, 'rec_owner', $roomTaskId);

		/** @var RoomTask $completed */
		$completed = $result['roomTask'];
		self::assertTrue($completed->getIsActive(), "a 'recurring' task stays active after completion");
		self::assertGreaterThan(
			(new \DateTime())->getTimestamp(),
			$completed->getNextDue()->getTimestamp(),
			'the next occurrence must be projected into the future'
		);
	}

	/**
	 * The validation vocabulary accepts exactly the canonical constants and
	 * TaskService rejects a foreign mode.
	 */
	public function testValidateTaskData_AcceptsOnlyCanonicalRecurrenceModes(): void {
		$taskService = \OC::$server->get(\OCA\ChoreStrike\Service\TaskService::class);

		self::assertSame([], $taskService->validateTaskData([
			'name' => 'x',
			'recurrenceMode' => Task::RECURRENCE_ONCE,
		]));
		self::assertSame([], $taskService->validateTaskData([
			'name' => 'x',
			'recurrenceMode' => Task::RECURRENCE_BOUNDED,
		]));
		$errors = $taskService->validateTaskData([
			'name' => 'x',
			'recurrenceMode' => 'one_time',
		]);
		self::assertContains('Invalid recurrence mode', $errors, "the legacy 'one_time' spelling must be rejected");
	}

	/**
	 * Insert a task definition with the given recurrence mode via the mapper.
	 */
	private function createTaskWithRecurrence(int $houseId, string $name, string $recurrenceMode): int {
		$task = $this->taskMapper->create(
			name: $name,
			description: 'recurrence regression',
			energyCost: 3,
			priority: 2,
			frequencyType: 'weekly',
			frequencyValue: 1,
			estimatedMinutes: 15,
			category: 'cleaning',
			recurrenceMode: $recurrenceMode,
		);
		$this->createdTaskIds[] = $task->getId();
		return $task->getId();
	}
}
