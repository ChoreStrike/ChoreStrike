<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Db;

use OCA\ChoreStrike\Db\RoomTask;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for room task recurrence lifecycle behavior.
 */
class RoomTaskTest extends TestCase
{
    /**
     * Ensure one-time tasks are deactivated after completion.
     */
    public function testMarkCompleted_WithOneTimeRecurrence_DeactivatesTask(): void
    {
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
    public function testMarkCompleted_WithRecurringPastActiveUntil_DeactivatesTask(): void
    {
        $roomTask = new RoomTask();
        $roomTask->setIsActive(true);
        $roomTask->setNextDue(new \DateTime('2026-02-24 12:00:00'));

        $completedAt = new \DateTime('2026-02-24 15:00:00');
        $activeUntil = new \DateTime('2026-02-25 00:00:00');
        $roomTask->markCompleted($completedAt, 'weekly', 1, 'recurring', 'dynamic', $activeUntil);

        self::assertFalse($roomTask->getIsActive());
        self::assertSame($activeUntil->format('c'), $roomTask->getNextDue()->format('c'));
    }

    // ========== Flex-Window Scheduling Tests ==========

    /**
     * Test calculateIdealDueDate returns base date when flexDays is 0.
     */
    public function testCalculateIdealDueDate_WithNoFlexDays_ReturnsBaseDate(): void
    {
        $roomTask = new RoomTask();
        $baseDate = new \DateTime('2026-03-15');
        $idealDue = $roomTask->calculateIdealDueDate($baseDate, 0);

        self::assertSame('2026-03-15', $idealDue->format('Y-m-d'));
    }

    /**
     * Test calculateIdealDueDate returns base date when flexDays is negative.
     */
    public function testCalculateIdealDueDate_WithNegativeFlexDays_ReturnsBaseDate(): void
    {
        $roomTask = new RoomTask();
        $baseDate = new \DateTime('2026-03-15');
        $idealDue = $roomTask->calculateIdealDueDate($baseDate, -5);

        self::assertSame('2026-03-15', $idealDue->format('Y-m-d'));
    }

    /**
     * Test calculateIdealDueDate picks a day within the flex window.
     */
    public function testCalculateIdealDueDate_WithFlexDays_ReturnsDayWithinWindow(): void
    {
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
    public function testCalculateIdealDueDate_WithAvailability_RespectsUnavailableDays(): void
    {
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
        $dayOfWeek = (int) $idealDue->format('N'); // 1=Mon, 7=Sun
        self::assertLessThan(6, $dayOfWeek); // Monday-Friday only
    }

    /**
     * Test calculateIdealDueDate prefers days with higher energy budget.
     */
    public function testCalculateIdealDueDate_WithEnergyBudget_PrefersHighEnergyDays(): void
    {
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
    public function testCalculateIdealDueDate_WithNoAvailableDays_ReturnsBaseDate(): void
    {
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
    public function testGetFlexWindowBounds_WithFlexDays_ReturnsCorrectBounds(): void
    {
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
    public function testGetFlexWindowBounds_WithNoFlexDays_ReturnsNullBounds(): void
    {
        $roomTask = new RoomTask();
        $roomTask->setIdealDueDate(new \DateTime('2026-03-15'));

        $bounds = $roomTask->getFlexWindowBounds(0);

        self::assertNull($bounds['earliest']);
        self::assertNull($bounds['latest']);
        self::assertSame('2026-03-15', $bounds['ideal']->format('Y-m-d'));
    }

    /**
     * Test markCompleted sets idealDueDate when flexDays is provided.
     */
    public function testMarkCompleted_WithFlexDays_SetsIdealDueDate(): void
    {
        $roomTask = new RoomTask();
        $roomTask->setIsActive(true);
        $roomTask->setNextDue(new \DateTime('2026-03-10'));

        $completedAt = new \DateTime('2026-03-10 15:00:00');
        $flexDays = 3;
        $weekdayAvailability = ['monday' => true, 'tuesday' => true, 'wednesday' => true];

        $roomTask->markCompleted(
            $completedAt,
            'weekly',
            1,
            'recurring',
            'dynamic',
            null,
            null,
            null,
            $flexDays,
            $weekdayAvailability
        );

        self::assertNotNull($roomTask->getIdealDueDate());
        // nextDue should equal idealDueDate
        self::assertSame($roomTask->getIdealDueDate()->format('Y-m-d'), $roomTask->getNextDue()->format('Y-m-d'));
    }

    /**
     * Test markCompleted clears idealDueDate for one-time tasks.
     */
    public function testMarkCompleted_OneTimeTask_ClearsIdealDueDate(): void
    {
        $roomTask = new RoomTask();
        $roomTask->setIsActive(true);
        $roomTask->setIdealDueDate(new \DateTime('2026-03-15'));
        $roomTask->setNextDue(new \DateTime('2026-03-10'));

        $completedAt = new \DateTime('2026-03-10 15:00:00');

        $roomTask->markCompleted(
            $completedAt,
            'weekly',
            1,
            'one_time',
            'dynamic',
            null,
            null,
            null,
            3
        );

        self::assertFalse($roomTask->getIsActive());
        self::assertNull($roomTask->getIdealDueDate());
    }
}
