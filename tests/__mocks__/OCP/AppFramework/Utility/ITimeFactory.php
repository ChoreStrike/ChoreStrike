<?php

namespace OCP\AppFramework\Utility;

/**
 * Mock ITimeFactory for testing TimedJob subclasses.
 *
 * Mirrors the real OCP\AppFramework\Utility\ITimeFactory surface used by
 * ChoreStrike's StreakFreezeJob, StreakRestockJob and DailyNotificationJob.
 */
interface ITimeFactory
{
    /**
     * Get current Unix timestamp.
     *
     * @return int
     */
    public function getTime(): int;

    /**
     * Get a DateTime for the current instant.
     *
     * @return \DateTime
     */
    public function getDateTime(): \DateTime;

    /**
     * Get a DateTimeImmutable for the current instant.
     *
     * @return \DateTimeImmutable
     */
    public function getDateTimeImmutable(): \DateTimeImmutable;

    /**
     * Get a DateTime for a relative time string.
     *
     * @param string $time A date/time string understood by strtotime()
     * @return \DateTime
     */
    public function getDateTimeFromTimestamp(int $timestamp): \DateTime;
}
