<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Db\Entity;

use OCA\ChoreStrike\Db\Task;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Task entity
 */
class TaskTest extends TestCase {
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
