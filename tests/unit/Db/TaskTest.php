<?php

declare(strict_types=1);

namespace OCA\ChoreWars\Tests\Unit\Db;

use OCA\ChoreWars\Db\Task;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Task entity
 */
class TaskTest extends TestCase {

	/**
	 * Validate_WithValidTask_ReturnsEmptyErrors
	 */
	public function testValidate_WithValidTask_ReturnsEmptyErrors(): void {
		$task = new Task();
		$task->setName('Clean Kitchen');
		$task->setEnergyCost(2);
		$task->setPriority(2);
		$task->setEstimatedMinutes(30);
		$task->setFrequencyType('weekly');
		$task->setFrequencyValue(1);
		$task->setCategory('cleaning');
		$task->setScopeType('room');
		$task->setRecurrenceMode('recurring');

		$errors = $task->validate();

		self::assertEmpty($errors);
	}

	/**
	 * Validate_WithEmptyName_ReturnsError
	 */
	public function testValidate_WithEmptyName_ReturnsError(): void {
		$task = new Task();
		$task->setName('');
		$task->setEnergyCost(2);

		$errors = $task->validate();

		self::assertContains('Task name is required', $errors);
	}

	/**
	 * Validate_WithWhitespaceName_ReturnsError
	 */
	public function testValidate_WithWhitespaceName_ReturnsError(): void {
		$task = new Task();
		$task->setName('   ');
		$task->setEnergyCost(2);

		$errors = $task->validate();

		self::assertContains('Task name is required', $errors);
	}

	/**
	 * Validate_WithInvalidEnergyCost_ReturnsError
	 */
	public function testValidate_WithInvalidEnergyCost_ReturnsError(): void {
		$task = new Task();
		$task->setName('Test Task');
		$task->setEnergyCost(10);

		$errors = $task->validate();

		self::assertContains('Energy cost must be between 1 and 5', $errors);
	}

	/**
	 * Validate_WithZeroEnergyCost_ReturnsError
	 */
	public function testValidate_WithZeroEnergyCost_ReturnsError(): void {
		$task = new Task();
		$task->setName('Test Task');
		$task->setEnergyCost(0);

		$errors = $task->validate();

		self::assertContains('Energy cost must be between 1 and 5', $errors);
	}

	/**
	 * Validate_WithInvalidPriority_ReturnsError
	 */
	public function testValidate_WithInvalidPriority_ReturnsError(): void {
		$task = new Task();
		$task->setName('Test Task');
		$task->setEnergyCost(2);
		$task->setPriority(5);

		$errors = $task->validate();

		self::assertContains('Priority must be between 1 and 3', $errors);
	}

	/**
	 * Validate_WithInvalidEstimatedMinutes_ReturnsError
	 */
	public function testValidate_WithInvalidEstimatedMinutes_ReturnsError(): void {
		$task = new Task();
		$task->setName('Test Task');
		$task->setEnergyCost(2);
		$task->setEstimatedMinutes(600);

		$errors = $task->validate();

		self::assertContains('Estimated time must be between 1 and 480 minutes', $errors);
	}

	/**
	 * Validate_WithInvalidFrequencyType_ReturnsError
	 */
	public function testValidate_WithInvalidFrequencyType_ReturnsError(): void {
		$task = new Task();
		$task->setName('Test Task');
		$task->setEnergyCost(2);
		$task->setFrequencyType('invalid');

		$errors = $task->validate();

		self::assertContains('Invalid frequency type', $errors);
	}

	/**
	 * Validate_WithInvalidScopeType_ReturnsError
	 */
	public function testValidate_WithInvalidScopeType_ReturnsError(): void {
		$task = new Task();
		$task->setName('Test Task');
		$task->setEnergyCost(2);
		$task->setScopeType('invalid');

		$errors = $task->validate();

		self::assertContains('Invalid scope type', $errors);
	}

	/**
	 * Validate_WithInvalidRecurrenceMode_ReturnsError
	 */
	public function testValidate_WithInvalidRecurrenceMode_ReturnsError(): void {
		$task = new Task();
		$task->setName('Test Task');
		$task->setEnergyCost(2);
		$task->setRecurrenceMode('invalid');

		$errors = $task->validate();

		self::assertContains('Invalid recurrence mode', $errors);
	}

	/**
	 * Validate_WithZeroFrequencyValue_ReturnsError
	 */
	public function testValidate_WithZeroFrequencyValue_ReturnsError(): void {
		$task = new Task();
		$task->setName('Test Task');
		$task->setEnergyCost(2);
		$task->setFrequencyValue(0);

		$errors = $task->validate();

		self::assertContains('Frequency value must be at least 1', $errors);
	}

	/**
	 * Validate_WithInvalidDateRange_ReturnsError
	 */
	public function testValidate_WithInvalidDateRange_ReturnsError(): void {
		$task = new Task();
		$task->setName('Test Task');
		$task->setEnergyCost(2);
		$task->setActiveFrom(new \DateTime('2026-02-25'));
		$task->setActiveUntil(new \DateTime('2026-02-20'));

		$errors = $task->validate();

		self::assertContains('Active until must be equal or after active from', $errors);
	}

	/**
	 * GetPriorityLabel_WithPriority1_ReturnsLow
	 */
	public function testGetPriorityLabel_WithPriority1_ReturnsLow(): void {
		$task = new Task();
		$task->setPriority(1);

		self::assertEquals('low', $task->getPriorityLabel());
	}

	/**
	 * GetPriorityLabel_WithPriority2_ReturnsMedium
	 */
	public function testGetPriorityLabel_WithPriority2_ReturnsMedium(): void {
		$task = new Task();
		$task->setPriority(2);

		self::assertEquals('medium', $task->getPriorityLabel());
	}

	/**
	 * GetPriorityLabel_WithPriority3_ReturnsHigh
	 */
	public function testGetPriorityLabel_WithPriority3_ReturnsHigh(): void {
		$task = new Task();
		$task->setPriority(3);

		self::assertEquals('high', $task->getPriorityLabel());
	}

	/**
	 * GetFrequencyLabel_WithDaily_ReturnsDaily
	 */
	public function testGetFrequencyLabel_WithDaily_ReturnsDaily(): void {
		$task = new Task();
		$task->setFrequencyType('daily');
		$task->setFrequencyValue(1);

		self::assertEquals('Daily', $task->getFrequencyLabel());
	}

	/**
	 * GetFrequencyLabel_WithWeeklyValue1_ReturnsWeekly
	 */
	public function testGetFrequencyLabel_WithWeeklyValue1_ReturnsWeekly(): void {
		$task = new Task();
		$task->setFrequencyType('weekly');
		$task->setFrequencyValue(1);

		self::assertEquals('Weekly', $task->getFrequencyLabel());
	}

	/**
	 * GetFrequencyLabel_WithWeeklyValue2_ReturnsEvery2Weeks
	 */
	public function testGetFrequencyLabel_WithWeeklyValue2_ReturnsEvery2Weeks(): void {
		$task = new Task();
		$task->setFrequencyType('weekly');
		$task->setFrequencyValue(2);

		self::assertEquals('Every 2 weeks', $task->getFrequencyLabel());
	}

	/**
	 * GetFrequencyLabel_WithMonthlyValue1_ReturnsMonthly
	 */
	public function testGetFrequencyLabel_WithMonthlyValue1_ReturnsMonthly(): void {
		$task = new Task();
		$task->setFrequencyType('monthly');
		$task->setFrequencyValue(1);

		self::assertEquals('Monthly', $task->getFrequencyLabel());
	}

	/**
	 * GetFrequencyLabel_WithCustom_ReturnsCustom
	 */
	public function testGetFrequencyLabel_WithCustom_ReturnsCustom(): void {
		$task = new Task();
		$task->setFrequencyType('custom');

		self::assertEquals('Custom', $task->getFrequencyLabel());
	}

	/**
	 * JsonSerialize_ReturnsCorrectArray
	 */
	public function testJsonSerialize_ReturnsCorrectArray(): void {
		$task = new Task();
		$task->setId(1);
		$task->setName('Test Task');
		$task->setDescription('Test Description');
		$task->setEnergyCost(2);
		$task->setPriority(2);
		$task->setFrequencyType('weekly');
		$task->setFrequencyValue(1);
		$task->setEstimatedMinutes(30);
		$task->setCategory('cleaning');
		$task->setScopeType('room');
		$task->setScopeLabel('Kitchen');
		$task->setRecurrenceMode('recurring');
		$task->setIsVoluntary(false);
		$task->setIsTemplate(false);
		$createdAt = new \DateTime('2026-02-24 12:00:00');
		$task->setCreatedAt($createdAt);

		$result = $task->jsonSerialize();

		self::assertEquals(1, $result['id']);
		self::assertEquals('Test Task', $result['name']);
		self::assertEquals('Test Description', $result['description']);
		self::assertEquals(2, $result['energyCost']);
		self::assertEquals('weekly', $result['frequencyType']);
		self::assertEquals($createdAt->format('c'), $result['createdAt']);
	}
}
