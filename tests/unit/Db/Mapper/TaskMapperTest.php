<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Db\Mapper;

use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Db\TaskMapper;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for TaskMapper
 */
class TaskMapperTest extends TestCase {
	private TaskMapper $mapper;
	private IDBConnection $db;
	private IQueryBuilder $qb;
	private IExpressionBuilder $expr;

	protected function setUp(): void {
		$this->db = $this->createMock(IDBConnection::class);
		$this->qb = $this->createMock(IQueryBuilder::class);
		$this->expr = $this->createMock(IExpressionBuilder::class);

		$this->db->method('getQueryBuilder')
			->willReturn($this->qb);
		$this->qb->method('expr')
			->willReturn($this->expr);

		$this->mapper = new TaskMapper($this->db);
	}

	/**
	 * Find_WithExistingId_ReturnsTask
	 */
	public function testFind_WithExistingId_ReturnsTask(): void {
		$task = new Task();
		$task->setId(1);
		$task->setName('Test Task');

		// Mock parent find behavior through database query
		$this->qb->expects($this->once())
			->method('select')
			->with('*')
			->willReturn($this->qb);
		$this->qb->expects($this->once())
			->method('from')
			->with('chorestrike_tasks')
			->willReturn($this->qb);

		$mockResult = $this->createMock(IResult::class);
		$mockResult->method('fetch')
			->willReturn([
				'id' => 1,
				'name' => 'Test Task',
				'energy_cost' => 2,
				'priority' => 2,
				'frequency_type' => 'weekly',
				'frequency_value' => 1,
				'estimated_minutes' => 30,
				'category' => 'cleaning',
				'scope_type' => 'room',
				'recurrence_mode' => 'recurring',
				'is_voluntary' => false
			]);

		$this->qb->expects($this->once())
			->method('executeQuery')
			->willReturn($mockResult);

		$result = $this->mapper->find(1);

		$this->assertInstanceOf(Task::class, $result);
		$this->assertEquals('Test Task', $result->getName());
	}

	/**
	 * FindByIds_EmptyInput_ReturnsEmptyMapWithoutQuery
	 *
	 * CS-PLAN-036: the empty-array early return must not build an IN ().
	 */
	public function testFindByIds_EmptyInput_ReturnsEmptyMapWithoutQuery(): void {
		$this->qb->expects($this->never())->method('select');

		$this->assertSame([], $this->mapper->findByIds([]));
	}

	/**
	 * FindByIds_BuildsInQueryAndMapsById
	 */
	public function testFindByIds_BuildsInQueryAndMapsById(): void {
		$capturedTypes = [];
		$this->qb->method('createNamedParameter')->willReturnCallback(
			function ($value, $type) use (&$capturedTypes) {
				$capturedTypes[] = $type;
				return 'param_' . count($capturedTypes);
			}
		);
		$this->qb->method('select')->willReturn($this->qb);
		$this->qb->method('from')->willReturn($this->qb);
		$this->qb->method('where')->willReturn($this->qb);

		$mockResult = $this->createMock(IResult::class);
		$mockResult->method('fetch')->willReturnOnConsecutiveCalls(
			['id' => 1, 'name' => 'Dishes', 'energy_cost' => 2],
			['id' => 3, 'name' => 'Laundry', 'energy_cost' => 3],
			false
		);
		$this->qb->method('executeQuery')->willReturn($mockResult);

		$map = $this->mapper->findByIds([1, 2, 3]);

		// id 2 is missing from the result → tolerated, absent from the map.
		$this->assertSame([1, 3], array_keys($map));
		$this->assertInstanceOf(Task::class, $map[1]);
		$this->assertSame('Dishes', $map[1]->getName());
		$this->assertSame('Laundry', $map[3]->getName());
		$this->assertContains(IQueryBuilder::PARAM_INT_ARRAY, $capturedTypes);
	}

	/**
	 * ValidateTask_WithValidData_PassesValidation
	 */
	public function testValidateTask_WithValidData_PassesValidation(): void {
		$task = new Task();
		$task->setName('Valid Task');
		$task->setEnergyCost(2);
		$task->setPriority(2);
		$task->setEstimatedMinutes(30);
		$task->setFrequencyType('weekly');
		$task->setFrequencyValue(1);
		$task->setCategory('cleaning');
		$task->setScopeType('room');
		$task->setRecurrenceMode('recurring');

		$errors = $task->validate();

		$this->assertEmpty($errors);
	}

	/**
	 * ValidateTask_WithInvalidEnergyCost_FailsValidation
	 */
	public function testValidateTask_WithInvalidEnergyCost_FailsValidation(): void {
		$task = new Task();
		$task->setName('Test Task');
		$task->setEnergyCost(10);

		$errors = $task->validate();

		$this->assertContains('Energy cost must be between 1 and 5', $errors);
	}

	/**
	 * ValidateTask_WithEmptyName_FailsValidation
	 */
	public function testValidateTask_WithEmptyName_FailsValidation(): void {
		$task = new Task();
		$task->setName('');

		$errors = $task->validate();

		$this->assertContains('Task name is required', $errors);
	}

	/**
	 * GetPriorityLabel_ReturnsCorrectLabel
	 */
	public function testGetPriorityLabel_ReturnsCorrectLabel(): void {
		$task = new Task();
		$task->setPriority(3);

		$this->assertEquals('high', $task->getPriorityLabel());
	}

	/**
	 * GetFrequencyLabel_WithWeeklyValue_ReturnsCorrectLabel
	 */
	public function testGetFrequencyLabel_WithWeeklyValue_ReturnsCorrectLabel(): void {
		$task = new Task();
		$task->setFrequencyType('weekly');
		$task->setFrequencyValue(2);

		$this->assertEquals('Every 2 weeks', $task->getFrequencyLabel());
	}

	/**
	 * GetFrequencyLabel_WithMonthlyValue1_ReturnsMonthly
	 */
	public function testGetFrequencyLabel_WithMonthlyValue1_ReturnsMonthly(): void {
		$task = new Task();
		$task->setFrequencyType('monthly');
		$task->setFrequencyValue(1);

		$this->assertEquals('Monthly', $task->getFrequencyLabel());
	}
}
