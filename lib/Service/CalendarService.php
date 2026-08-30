<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\House;

/**
 * Per-house calendar-day semantics (CS-PLAN-057).
 *
 * Single source of truth for every day/week boundary: the household's own
 * timezone instead of the server clock (a Nextcloud server runs UTC, so
 * server-TZ buckets flip at the wrong local midnight for any non-UTC
 * household). Pure and stateless — all methods take the instant and the
 * target zone explicitly, so the service is trivially unit-testable around
 * DST transitions.
 *
 * Conventions:
 *  - Day/week windows use the `[start, end)` half-open interval.
 *  - Returned boundary DateTimes are normalized to UTC because the DB layer
 *    formats bound parameters in the value's own timezone and all stored
 *    timestamps are UTC wall-clock — a UTC-normalized boundary therefore
 *    represents the correct absolute instant in every SQL comparison.
 *  - Day/week *keys* (`dayKey`) are computed in the house timezone; they are
 *    stable local labels, never compared against stored timestamps.
 */
class CalendarService {
	public const DEFAULT_TIMEZONE = 'UTC';

	/** @var array<string, \DateTimeZone> Runtime cache of constructed zones (per-process). */
	private array $zoneCache = [];

	/**
	 * Resolve a house's timezone. Missing/unknown stored ids fall back to
	 * UTC (defensive: the column is created with 'UTC' and owner edits are
	 * closed-set validated).
	 */
	public function getTimeZone(House $house): \DateTimeZone {
		$id = $house->getTimezone();
		if (is_string($id) && $id !== '' && $this->isValidTimeZone($id)) {
			return $this->zoneCache[$id] ??= new \DateTimeZone($id);
		}
		return $this->zoneCache[self::DEFAULT_TIMEZONE] ??= new \DateTimeZone(self::DEFAULT_TIMEZONE);
	}

	/**
	 * Whether the given id is a valid IANA timezone identifier.
	 */
	public function isValidTimeZone(?string $timeZoneId): bool {
		if ($timeZoneId === null || $timeZoneId === '') {
			return false;
		}
		try {
			new \DateTimeZone($timeZoneId);
			return true;
		} catch (\Exception $e) {
			return false;
		}
	}

	/**
	 * `[start, end)` bounds of the house-local calendar day containing
	 * $now, as UTC-normalized DateTimes (see class conventions).
	 *
	 * DST-correct: a 23-hour spring-forward day yields a 23-hour window, a
	 * 25-hour fall-back day a 25-hour window — the window always spans
	 * exactly one local calendar day.
	 *
	 * @return array{\DateTime, \DateTime} UTC DateTimes, start inclusive, end exclusive
	 */
	public function dayBounds(\DateTimeInterface $now, \DateTimeZone $timeZone): array {
		$local = \DateTime::createFromInterface($now)->setTimezone($timeZone);
		$local->setTime(0, 0, 0);
		$start = \DateTime::createFromInterface($local)->setTimezone(new \DateTimeZone(self::DEFAULT_TIMEZONE));
		$localEnd = \DateTime::createFromInterface($local)->modify('+1 day');
		$end = $localEnd->setTimezone(new \DateTimeZone(self::DEFAULT_TIMEZONE));
		return [$start, $end];
	}

	/**
	 * Monday 00:00 of the house-local calendar week containing $now, as a
	 * UTC-normalized DateTime (the week *start*; callers derive the end).
	 *
	 * Correct "monday this week" semantics (CR-P2-11): on a Sunday the
	 * week starts the *previous* Monday, unlike PHP's `'monday this week'`
	 * relative format which returns the next day on Sundays.
	 */
	public function weekStart(\DateTimeInterface $now, \DateTimeZone $timeZone): \DateTime {
		$local = \DateTime::createFromInterface($now)->setTimezone($timeZone);
		$local->setTime(0, 0, 0);
		$dayOfWeek = (int)$local->format('N'); // 1 (Mon) .. 7 (Sun)
		$local->modify('-' . ($dayOfWeek - 1) . ' days');
		return $local->setTimezone(new \DateTimeZone(self::DEFAULT_TIMEZONE));
	}

	/**
	 * The house-local `Y-m-d` day key of the given instant.
	 */
	public function dayKey(\DateTimeInterface $now, \DateTimeZone $timeZone): string {
		return \DateTime::createFromInterface($now)->setTimezone($timeZone)->format('Y-m-d');
	}
}
