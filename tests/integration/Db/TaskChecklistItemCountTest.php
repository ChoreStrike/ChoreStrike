<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Db;

use OCA\ChoreStrike\Db\TaskChecklistItemMapper;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;

/**
 * BUG-UX-003 (CS-PLAN-088 D-088-19): the grouped checklist item count
 * behind the My Day toggle.
 */
class TaskChecklistItemCountTest extends IntegrationTestCase {
	private TaskChecklistItemMapper $mapper;
	/** @var int[] */
	private array $taskIds = [];

	protected function setUp(): void {
		parent::setUp();
		$this->mapper = \OC::$server->get(TaskChecklistItemMapper::class);
	}

	protected function tearDown(): void {
		foreach ($this->taskIds as $taskId) {
			$this->mapper->deleteByTaskId($taskId);
		}
		parent::tearDown();
	}

	public function testCountByTaskIds_GroupsPerTaskAndOmitsTasksWithoutItems(): void {
		$houseId = $this->createHouse('checklist_count_owner', 'Checklist Count House');
		$this->taskIds = [
			$this->createTask($houseId, 'Kitchen'),
			$this->createTask($houseId, 'Bath'),
			$this->createTask($houseId, 'Hall'),
		];
		[$kitchen, $bath, $hall] = $this->taskIds;
		foreach (['Counter', 'Sink', 'Floor'] as $order => $label) {
			$this->mapper->create($kitchen, $label, $order);
		}
		$this->mapper->create($bath, 'Mirror', 0);

		$counts = $this->mapper->countByTaskIds([$kitchen, $bath, $hall, $kitchen]);

		ksort($counts);
		$this->assertSame([$kitchen => 3, $bath => 1], $counts);
		$this->assertSame([], $this->mapper->countByTaskIds([]));
	}
}
