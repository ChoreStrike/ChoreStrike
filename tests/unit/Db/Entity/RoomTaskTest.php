<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Db\Entity;

use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\Task;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for room task entity behavior. The legacy recurrence engine
 * (markCompleted / projectOccurrences) was retired with CS-PLAN-072 WP1
 * (RF-072-WP1-12/-16) — recurrence enumeration coverage lives in
 * RecurrenceCalculatorTest and the OccurrenceTimelineTest integration
 * suite; the flex-window display helpers stay.
 */
class RoomTaskTest extends TestCase {
	/**
	 * The removed 'one_time' spelling must no longer be part of the
	 * canonical vocabulary (CR-053-09).
	 */
	public function testLegacyOneTimeSpelling_IsNotTheCanonicalVocabulary(): void {
		self::assertNotContains('one_time', Task::RECURRENCE_MODES);
		self::assertNotSame('one_time', Task::RECURRENCE_ONCE);
	}

	// ========== Flex-Window Scheduling Tests ==========

	/**
	 * Test calculateIdealDueDate returns base date when flexDays is 0.
	 */
	public function testCalculateIdealDueDate_WithNoFlexDays_ReturnsBaseDate(): void {
		$roomTask = new RoomTask();
		$baseDate = new \DateTime('2026-03-15');
		$idealDue = $roomTask->calculateIdealDueDate($baseDate, 0);

		self::assertSame('2026-03-15', $idealDue->format('Y-m-d'));
	}

	/**
	 * Test calculateIdealDueDate returns base date when flexDays is negative.
	 */
	public function testCalculateIdealDueDate_WithNegativeFlexDays_ReturnsBaseDate(): void {
		$roomTask = new RoomTask();
		$baseDate = new \DateTime('2026-03-15');
		$idealDue = $roomTask->calculateIdealDueDate($baseDate, -5);

		self::assertSame('2026-03-15', $idealDue->format('Y-m-d'));
	}

	/**
	 * Test calculateIdealDueDate picks a day within the flex window.
	 */
	public function testCalculateIdealDueDate_WithFlexDays_ReturnsDayWithinWindow(): void {
		$roomTask = new RoomTask();
		$baseDate = new \DateTime('2026-03-15'); // Saturday
		$flexDays = 3;

		// Window should be 2026-03-12 to 2026-03-18
		$idealDue = $roomTask->calculateIdealDueDate($baseDate, $flexDays);

		$windowStart = new \DateTime('2026-03-12');
		$windowEnd = new \DateTime('2026-03-18');

		self::assertGreaterThanOrEqual($windowStart->format('Y-m-d'), $idealDue->format('Y-m-d'));
		self::assertLessThanOrEqual($windowEnd->format('Y-m-d'), $idealDue->format('Y-m-d'));
	}

	/**
	 * Test calculateIdealDueDate respects weekday availability.
	 */
	public function testCalculateIdealDueDate_WithAvailability_RespectsUnavailableDays(): void {
		$roomTask = new RoomTask();
		$baseDate = new \DateTime('2026-03-16'); // Monday
		$flexDays = 2;

		// Window: 2026-03-14 (Sat) to 2026-03-18 (Wed)
		// Mark Saturday and Sunday as unavailable
		$weekdayAvailability = [
			'saturday' => false,
			'sunday' => false,
			'monday' => true,
			'tuesday' => true,
			'wednesday' => true,
			'thursday' => true,
			'friday' => true,
		];

		$idealDue = $roomTask->calculateIdealDueDate($baseDate, $flexDays, $weekdayAvailability);

		// Should not be Saturday (14) or Sunday (15)
		$dayOfWeek = (int)$idealDue->format('N'); // 1=Mon, 7=Sun
		self::assertLessThan(6, $dayOfWeek); // Monday-Friday only
	}

	/**
	 * Test calculateIdealDueDate prefers days with higher energy budget.
	 */
	public function testCalculateIdealDueDate_WithEnergyBudget_PrefersHighEnergyDays(): void {
		$roomTask = new RoomTask();
		$baseDate = new \DateTime('2026-03-16'); // Monday
		$flexDays = 1;

		// Window: 2026-03-15 (Sun) to 2026-03-17 (Tue)
		// Give Tuesday higher energy budget
		$energyBudget = [
			'sunday' => 5,
			'monday' => 5,
			'tuesday' => 15, // Higher budget
		];

		$idealDue = $roomTask->calculateIdealDueDate($baseDate, $flexDays, [], $energyBudget);

		// Should prefer Tuesday due to higher energy budget
		self::assertSame('2026-03-17', $idealDue->format('Y-m-d'));
	}

	/**
	 * Test calculateIdealDueDate falls back to base date when no days are available.
	 */
	public function testCalculateIdealDueDate_WithNoAvailableDays_ReturnsBaseDate(): void {
		$roomTask = new RoomTask();
		$baseDate = new \DateTime('2026-03-16'); // Monday
		$flexDays = 1;

		// Mark all days as unavailable
		$weekdayAvailability = [
			'sunday' => false,
			'monday' => false,
			'tuesday' => false,
		];

		$idealDue = $roomTask->calculateIdealDueDate($baseDate, $flexDays, $weekdayAvailability);

		// Falls back to base date
		self::assertSame('2026-03-16', $idealDue->format('Y-m-d'));
	}

	/**
	 * Test getFlexWindowBounds returns correct bounds.
	 */
	public function testGetFlexWindowBounds_WithFlexDays_ReturnsCorrectBounds(): void {
		$roomTask = new RoomTask();
		$roomTask->setIdealDueDate(new \DateTime('2026-03-15'));

		$bounds = $roomTask->getFlexWindowBounds(3);

		self::assertSame('2026-03-12', $bounds['earliest']->format('Y-m-d'));
		self::assertSame('2026-03-15', $bounds['ideal']->format('Y-m-d'));
		self::assertSame('2026-03-18', $bounds['latest']->format('Y-m-d'));
	}

	/**
	 * Test getFlexWindowBounds returns null bounds when flexDays is 0.
	 */
	public function testGetFlexWindowBounds_WithNoFlexDays_ReturnsNullBounds(): void {
		$roomTask = new RoomTask();
		$roomTask->setIdealDueDate(new \DateTime('2026-03-15'));

		$bounds = $roomTask->getFlexWindowBounds(0);

		self::assertNull($bounds['earliest']);
		self::assertNull($bounds['latest']);
		self::assertSame('2026-03-15', $bounds['ideal']->format('Y-m-d'));
	}

	// ========== Null-Safety Regression Tests (CS-PLAN-004 Phase 1) ==========

	/**
	 * getDaysUntilDue must return null (not crash) when nextDue is null.
	 */
	public function testGetDaysUntilDueReturnsNullWhenNextDueIsNull(): void {
		$roomTask = new RoomTask();
		$roomTask->setIsActive(true);

		self::assertNull($roomTask->getDaysUntilDue());
	}

	/**
	 * jsonSerialize must emit daysUntilDue => null when nextDue is null.
	 */
	public function testJsonSerializeEmitsNullDaysUntilDueWhenNextDueIsNull(): void {
		$roomTask = new RoomTask();
		$roomTask->setIsActive(true);
		$roomTask->setRoomId(1);
		$roomTask->setTaskId(1);

		$json = $roomTask->jsonSerialize();

		self::assertNull($json['daysUntilDue']);
		self::assertNull($json['nextDue']);
	}

	/**
	 * The payload contract carries no removed legacy keys (CS-PLAN-060 WP1,
	 * D2/D4/D9): assignees live in the task_assignees join table, the sync
	 * and group-assignment remnants were folded out of the schema.
	 */
	public function testJsonSerialize_DoesNotEmitRemovedLegacyKeys(): void {
		$roomTask = new RoomTask();
		$roomTask->setRoomId(1);
		$roomTask->setTaskId(1);
		$roomTask->setNextDue(new \DateTime('2026-03-10'));

		$json = $roomTask->jsonSerialize();

		foreach (['assignedToUserId', 'nextcloudTaskId', 'assignmentType', 'assignedToGroup', 'assignedByUserId', 'assignedAt'] as $removedKey) {
			self::assertArrayNotHasKey($removedKey, $json, "payload key '$removedKey' must be gone");
		}
		// The claim-feature snapshot surface stays (decision D9).
		self::assertArrayHasKey('claimedByUser', $json);
		self::assertArrayHasKey('wasClaimed', $json);
		self::assertArrayHasKey('originalAssignee', $json);
		self::assertArrayHasKey('claimedAt', $json);
	}
}
