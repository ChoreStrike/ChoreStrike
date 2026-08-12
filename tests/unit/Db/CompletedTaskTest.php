<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Db;

use OCA\ChoreStrike\Db\CompletedTask;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CompletedTask entity
 */
class CompletedTaskTest extends TestCase {
	/**
	 * JsonSerialize_ReturnsCorrectArray
	 */
	public function testJsonSerialize_ReturnsCorrectArray(): void {
		$completedTask = new CompletedTask();
		$completedTask->setId(1);
		$completedTask->setUserId(2);
		$completedTask->setRoomTaskId(3);
		$completedTask->setCoinsEarned(15);
		$completedTask->setWasCombo(true);
		$completedAt = new \DateTime('2026-02-24 15:30:00');
		$completedTask->setCompletedAt($completedAt);

		$result = $completedTask->jsonSerialize();

		self::assertEquals(1, $result['id']);
		self::assertEquals(2, $result['userId']);
		self::assertEquals(3, $result['roomTaskId']);
		self::assertEquals(15, $result['coinsEarned']);
		self::assertTrue($result['wasCombo']);
		self::assertEquals($completedAt->format('c'), $result['completedAt']);
	}

	/**
	 * SetAndGetProperties_WorksCorrectly
	 */
	public function testSetAndGetProperties_WorksCorrectly(): void {
		$completedTask = new CompletedTask();
		$completedTask->setUserId(5);
		$completedTask->setRoomTaskId(10);
		$completedTask->setCoinsEarned(25);
		$completedTask->setWasCombo(false);
		$completedAt = new \DateTime('2026-02-24 10:00:00');
		$completedTask->setCompletedAt($completedAt);

		self::assertEquals(5, $completedTask->getUserId());
		self::assertEquals(10, $completedTask->getRoomTaskId());
		self::assertEquals(25, $completedTask->getCoinsEarned());
		self::assertFalse($completedTask->getWasCombo());
		self::assertSame($completedAt, $completedTask->getCompletedAt());
	}

	/**
	 * DefaultValues_AreCorrect
	 */
	public function testDefaultValues_AreCorrect(): void {
		$completedTask = new CompletedTask();

		self::assertEquals(0, $completedTask->getCoinsEarned());
		self::assertFalse($completedTask->getWasCombo());
	}
}
