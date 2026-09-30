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

	/**
	 * Whether the value is a bare `Y-m-d` day-key label (no time/offset).
	 *
	 * Day-key labels denote house-local dates (ADR-009): writers that
	 * accept one must resolve it through {@see dayStartInstant()} instead
	 * of tagging it at 00:00 UTC/server-TZ, which would land one local
	 * day early for houses west of UTC (RF-058-01).
	 */
	public function isDayKey(string $value): bool {
		return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;
	}

	/**
	 * The UTC-normalized instant of house-local midnight for a `Y-m-d` day
	 * key — the write-side inverse of {@see dayKey()}.
	 *
	 * Instant-based readers (dayBounds range comparisons, occurrence
	 * projection) then bucket the persisted value on the intended local
	 * day for every house timezone, including negative UTC offsets.
	 *
	 * @param string $dayKey House-local `Y-m-d` label
	 * @throws \InvalidArgumentException When $dayKey is not a valid `Y-m-d` date
	 */
	public function dayStartInstant(string $dayKey, \DateTimeZone $timeZone): \DateTime {
		$local = \DateTime::createFromFormat('!Y-m-d', $dayKey, $timeZone);
		if ($local === false || $local->format('Y-m-d') !== $dayKey) {
			throw new \InvalidArgumentException('Invalid day key: ' . $dayKey . ' (expected Y-m-d)');
		}
		return $local->setTimezone(new \DateTimeZone(self::DEFAULT_TIMEZONE));
	}

	/**
	 * Whether `$value` is a bare `HH:mm` local-time label (00-23 hours,
	 * 00-59 minutes).
	 */
	public static function isLocalTime(?string $value): bool {
		if ($value === null || preg_match('/^(\d{2}):(\d{2})$/', $value, $m) !== 1) {
			return false;
		}
		return (int)$m[1] <= 23 && (int)$m[2] <= 59;
	}

	/**
	 * Resolve a house-local wall time on a given day to its UTC instant
	 * with the C5b DST rules (CS-PLAN-076 §3.2):
	 *
	 *  - a nonexistent time (spring gap) resolves to the FIRST VALID
	 *    LATER instant — the transition instant itself (Europe/Berlin
	 *    02:30 on the gap day → 03:00 CEST);
	 *  - a repeated time (autumn fold) resolves to its FIRST occurrence
	 *    (02:30 on the fold day → the CEST one, not the CET repeat);
	 *  - an ordinary time resolves exactly.
	 *
	 * All arithmetic uses ABSOLUTE timestamps — relative minute modify()
	 * is wall-clock based across transitions and must not be used here.
	 * The returned DateTime is UTC-normalized (class convention).
	 *
	 * @param string $dayKey House-local `Y-m-d` label
	 * @param string $localTime `HH:mm` wall time
	 * @throws \InvalidArgumentException on a malformed day key or time
	 */
	public function localTimeToInstant(string $dayKey, string $localTime, \DateTimeZone $timeZone): \DateTime {
		if (!$this->isDayKey($dayKey) || !self::isLocalTime($localTime)) {
			throw new \InvalidArgumentException('Invalid day key or local time (expected Y-m-d / HH:mm)');
		}

		// Local midnight as an absolute instant (dayStartInstant is
		// UTC-normalized; the timestamp is what matters here).
		$dayStart = $this->dayStartInstant($dayKey, $timeZone);
		$baseTs = (int)$dayStart->getTimestamp();
		[$hours, $minutes] = sscanf($localTime, '%d:%d') ?? [0, 0];
		$seconds = ((int)$hours) * 3600 + ((int)$minutes) * 60;

		// Offsets before and after the day's (single) transition — equal
		// offsets mean an ordinary day.
		$offsetStart = $timeZone->getOffset($dayStart);
		$dayEndProbe = (clone $dayStart)->setTimestamp($baseTs + 86399);
		$offsetEnd = $timeZone->getOffset($dayEndProbe);

		// Interpret the wall time under BOTH candidate offsets. baseTs +
		// seconds already encodes offsetStart, so re-interpreting at
		// offsetEnd shifts by their difference.
		$candidateTimestamps = $offsetStart === $offsetEnd
			? [$baseTs + $seconds]
			: [$baseTs + $seconds, $baseTs + $seconds + ($offsetStart - $offsetEnd)];

		$renderValid = static function (int $ts) use ($timeZone, $localTime): bool {
			return (new \DateTime('@' . $ts))->setTimezone($timeZone)->format('H:i') === $localTime;
		};
		$valid = array_values(array_filter($candidateTimestamps, $renderValid));
		if ($valid !== []) {
			// Ordinary day: the single exact hit. Fold: the smaller
			// timestamp IS the first occurrence (C5b).
			$instant = min($valid);
			return (new \DateTime('@' . $instant))->setTimezone(new \DateTimeZone(self::DEFAULT_TIMEZONE));
		}

		// Gap: the wall time does not exist — the first valid LATER
		// instant is the transition itself. Walk forward with absolute
		// timestamps from the lower candidate (bounded: real gaps are at
		// most one hour).
		$ts = min($candidateTimestamps);
		for ($i = 0; $i < 120; $i++) {
			$rendered = (new \DateTime('@' . $ts))->setTimezone($timeZone)->format('H:i');
			if ($rendered >= $localTime) {
				break;
			}
			$ts += 60;
		}
		return (new \DateTime('@' . $ts))->setTimezone(new \DateTimeZone(self::DEFAULT_TIMEZONE));
	}

	/**
	 * The house-local `HH:mm` wall time of the given instant.
	 */
	public function localTimeKey(\DateTimeInterface $now, \DateTimeZone $timeZone): string {
		return \DateTime::createFromInterface($now)->setTimezone($timeZone)->format('H:i');
	}
}
