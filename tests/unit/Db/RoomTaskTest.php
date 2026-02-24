<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Db;

use OCA\ChoreStrike\Db\RoomTask;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for room task recurrence lifecycle behavior.
 */
class RoomTaskTest extends TestCase {

	/**
	 * Ensure one-time tasks are deactivated after completion.
	 */
	public function testMarkCompleted_WithOneTimeRecurrence_DeactivatesTask(): void {
		$roomTask = new RoomTask();
		$roomTask->setIsActive(true);
		$roomTask->setNextDue(new \DateTime('2026-02-24 12:00:00'));

		$completedAt = new \DateTime('2026-02-24 15:00:00');
		$roomTask->markCompleted($completedAt, 'weekly', 1, 'one_time');

		self::assertFalse($roomTask->getIsActive());
		self::assertSame($completedAt->format('c'), $roomTask->getNextDue()->format('c'));
	}

	/**
	 * Ensure recurring tasks stop when the next due date exceeds the active window.
	 */
	public function testMarkCompleted_WithRecurringPastActiveUntil_DeactivatesTask(): void {
		$roomTask = new RoomTask();
		$roomTask->setIsActive(true);
		$roomTask->setNextDue(new \DateTime('2026-02-24 12:00:00'));

		$completedAt = new \DateTime('2026-02-24 15:00:00');
		$activeUntil = new \DateTime('2026-02-25 00:00:00');
		$roomTask->markCompleted($completedAt, 'weekly', 1, 'recurring', $activeUntil);

		self::assertFalse($roomTask->getIsActive());
		self::assertSame($activeUntil->format('c'), $roomTask->getNextDue()->format('c'));
	}
}
