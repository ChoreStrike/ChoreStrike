<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Service\CalendarService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CalendarService (CS-PLAN-057).
 *
 * Pins the per-house day/week bucketing primitives around DST transitions
 * in three zones: UTC, Europe/Berlin (CET/CEST) and Pacific/Auckland
 * (southern hemisphere, opposite DST phase).
 *
 * Conventions under test:
 *  - dayBounds/weekStart return UTC-normalized DateTimes (the DB stores UTC
 *    wall clock and Doctrine formats bound DateTimes in their own timezone).
 *  - Windows are [start, end) half-open; a DST-shifted day yields a 23/25h
 *    window spanning exactly one local calendar day.
 *  - weekStart is Monday 00:00 house-local, with correct Sunday semantics
 *    (CR-P2-11: unlike PHP's 'monday this week', a Sunday belongs to the
 *    week that started the PREVIOUS Monday).
 */
class CalendarServiceTest extends TestCase {
	private CalendarService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->service = new CalendarService();
	}

	private function houseIn(string $timezone): House {
		$house = new House();
		$house->setTimezone($timezone);
		return $house;
	}

	// ------------------------------------------------------------------
	// getTimeZone
	// ------------------------------------------------------------------

	public function testGetTimeZone_UTC_HouseReturnsUTCZone(): void {
		$tz = $this->service->getTimeZone($this->houseIn('UTC'));
		self::assertSame('UTC', $tz->getName());
	}

	public function testGetTimeZone_BerlinHouseReturnsBerlinZone(): void {
		$tz = $this->service->getTimeZone($this->houseIn('Europe/Berlin'));
		self::assertSame('Europe/Berlin', $tz->getName());
	}

	public function testGetTimeZone_UnknownStoredId_FallsBackToUTC(): void {
		$tz = $this->service->getTimeZone($this->houseIn('Mars/Olympus_Mons'));
		self::assertSame('UTC', $tz->getName());
	}

	public function testGetTimeZone_EmptyStoredId_FallsBackToUTC(): void {
		$tz = $this->service->getTimeZone($this->houseIn(''));
		self::assertSame('UTC', $tz->getName());
	}

	// ------------------------------------------------------------------
	// isValidTimeZone
	// ------------------------------------------------------------------

	public function testIsValidTimeZone_ValidIdentifiers_Accepted(): void {
		self::assertTrue($this->service->isValidTimeZone('UTC'));
		self::assertTrue($this->service->isValidTimeZone('Europe/Berlin'));
		self::assertTrue($this->service->isValidTimeZone('Pacific/Auckland'));
	}

	public function testIsValidTimeZone_UnknownOrEmpty_Rejected(): void {
		self::assertFalse($this->service->isValidTimeZone('Not/AZone'));
		self::assertFalse($this->service->isValidTimeZone(''));
		self::assertFalse($this->service->isValidTimeZone(null));
	}

	// ------------------------------------------------------------------
	// dayBounds — UTC baseline
	// ------------------------------------------------------------------

	public function testDayBounds_UTC_MatchesCalendarDay(): void {
		$tz = new \DateTimeZone('UTC');
		$now = new \DateTime('2026-08-30 17:33:00', $tz);

		[$start, $end] = $this->service->dayBounds($now, $tz);

		self::assertSame('2026-08-30 00:00:00', $start->format('Y-m-d H:i:s'));
		self::assertSame('UTC', $start->getTimezone()->getName());
		self::assertSame('2026-08-31 00:00:00', $end->format('Y-m-d H:i:s'));
		self::assertSame('UTC', $end->getTimezone()->getName());
	}

	// ------------------------------------------------------------------
	// dayBounds — Europe/Berlin (UTC+1/+2)
	// ------------------------------------------------------------------

	public function testDayBounds_Berlin_SummerStartsAtPreviousUTCEvening(): void {
		$tz = new \DateTimeZone('Europe/Berlin');
		// 00:30 Berlin on 2026-07-15 is 22:30 UTC on 2026-07-14.
		$now = new \DateTime('2026-07-15 00:30:00', $tz);

		[$start, $end] = $this->service->dayBounds($now, $tz);

		self::assertSame('2026-07-14 22:00:00', $start->format('Y-m-d H:i:s'));
		self::assertSame('UTC', $start->getTimezone()->getName());
		self::assertSame('2026-07-15 22:00:00', $end->format('Y-m-d H:i:s'));
		self::assertSame('2026-07-15', $this->service->dayKey($now, $tz));
	}

	public function testDayBounds_Berlin_CompletionJustAfterLocalMidnight_IsNextBerlinDay(): void {
		$tz = new \DateTimeZone('Europe/Berlin');
		// 22:30 UTC = 00:30 Berlin NEXT day — the CS-PLAN-057 integration pin.
		$now = new \DateTime('2026-07-14 22:30:00', new \DateTimeZone('UTC'));

		[$start, $end] = $this->service->dayBounds($now, $tz);

		self::assertSame('2026-07-14 22:00:00', $start->format('Y-m-d H:i:s'));
		self::assertSame('2026-07-15 22:00:00', $end->format('Y-m-d H:i:s'));
		self::assertSame('2026-07-15', $this->service->dayKey($now, $tz));
	}

	// ------------------------------------------------------------------
	// dayBounds — DST transitions
	// ------------------------------------------------------------------

	public function testDayBounds_Berlin_SpringForwardGap_WindowIs23Hours(): void {
		$tz = new \DateTimeZone('Europe/Berlin');
		// 2026-03-29 is the spring-forward day in Berlin (02:00 → 03:00 CET→CEST).
		$now = new \DateTime('2026-03-29 12:00:00', $tz);

		[$start, $end] = $this->service->dayBounds($now, $tz);

		// Local-midnight view of the UTC-normalized bounds.
		self::assertSame('2026-03-29 00:00:00', $start->setTimezone($tz)->format('Y-m-d H:i:s'));
		self::assertSame('2026-03-30 00:00:00', $end->setTimezone($tz)->format('Y-m-d H:i:s'));
		// 23-hour absolute window spanning exactly one local calendar day.
		self::assertSame(23 * 3600, $end->getTimestamp() - $start->getTimestamp());
	}

	public function testDayBounds_Berlin_FallBackOverlap_WindowIs25Hours(): void {
		$tz = new \DateTimeZone('Europe/Berlin');
		// 2026-10-25 is the fall-back day in Berlin (03:00 → 02:00 CEST→CET).
		$now = new \DateTime('2026-10-25 12:00:00', $tz);

		[$start, $end] = $this->service->dayBounds($now, $tz);

		// Local-midnight view of the UTC-normalized bounds.
		self::assertSame('2026-10-25 00:00:00', $start->setTimezone($tz)->format('Y-m-d H:i:s'));
		self::assertSame('2026-10-26 00:00:00', $end->setTimezone($tz)->format('Y-m-d H:i:s'));
		self::assertSame(25 * 3600, $end->getTimestamp() - $start->getTimestamp());
	}

	public function testDayBounds_Auckland_SouthernSpringForward_WindowIs23Hours(): void {
		$tz = new \DateTimeZone('Pacific/Auckland');
		// 2026-09-27 is the spring-forward day in Auckland (02:00 → 03:00 NZST→NZDT).
		$now = new \DateTime('2026-09-27 12:00:00', $tz);

		[$start, $end] = $this->service->dayBounds($now, $tz);

		// Local-midnight view of the UTC-normalized bounds.
		self::assertSame('2026-09-27 00:00:00', $start->setTimezone($tz)->format('Y-m-d H:i:s'));
		self::assertSame('2026-09-28 00:00:00', $end->setTimezone($tz)->format('Y-m-d H:i:s'));
		// 23-hour absolute window spanning exactly one local calendar day.
		self::assertSame(23 * 3600, $end->getTimestamp() - $start->getTimestamp());
	}

	// ------------------------------------------------------------------
	// dayKey
	// ------------------------------------------------------------------

	public function testDayKey_HouseLocalKeyDiffersFromUTCKeyAroundMidnight(): void {
		$tz = new \DateTimeZone('Pacific/Auckland');
		// 12:00 UTC on 2026-08-30 is 2026-08-31 00:00 (+12h) in Auckland.
		$now = new \DateTime('2026-08-30 12:00:00', new \DateTimeZone('UTC'));

		self::assertSame('2026-08-31', $this->service->dayKey($now, $tz));
		self::assertSame('2026-08-30', $this->service->dayKey($now, new \DateTimeZone('UTC')));
	}

	public function testDayKey_StableAcrossHouseLocalMidnight(): void {
		$tz = new \DateTimeZone('Europe/Berlin');
		// Just before and just after Berlin midnight on the same local date.
		$before = new \DateTime('2026-07-14 23:59:59', $tz);
		$after = new \DateTime('2026-07-15 00:00:00', $tz);

		self::assertSame('2026-07-14', $this->service->dayKey($before, $tz));
		self::assertSame('2026-07-15', $this->service->dayKey($after, $tz));
	}

	// ------------------------------------------------------------------
	// weekStart
	// ------------------------------------------------------------------

	public function testWeekStart_Monday_IsSameDay(): void {
		$tz = new \DateTimeZone('UTC');
		$now = new \DateTime('2026-08-24 15:00:00', $tz); // Monday

		$weekStart = $this->service->weekStart($now, $tz);

		self::assertSame('2026-08-24 00:00:00', $weekStart->format('Y-m-d H:i:s'));
	}

	public function testWeekStart_Sunday_BelongsToPreviousMonday(): void {
		$tz = new \DateTimeZone('UTC');
		$now = new \DateTime('2026-08-30 10:00:00', $tz); // Sunday

		$weekStart = $this->service->weekStart($now, $tz);

		// CR-P2-11 / CR-053-11(a): a Sunday belongs to the week that started
		// the previous Monday — NOT the next day ('monday this week' bug).
		self::assertSame('2026-08-24 00:00:00', $weekStart->format('Y-m-d H:i:s'));
	}

	public function testWeekStart_Berlin_LocalMondayMidnightAsUTCInstant(): void {
		$tz = new \DateTimeZone('Europe/Berlin');
		// Wednesday 2026-07-15, 10:00 Berlin (CEST, UTC+2).
		$now = new \DateTime('2026-07-15 10:00:00', $tz);

		$weekStart = $this->service->weekStart($now, $tz);

		// The returned instant is UTC-normalized: local Monday 00:00 CEST
		// == Sunday 22:00 UTC.
		self::assertSame('UTC', $weekStart->getTimezone()->getName());
		self::assertSame('2026-07-12 22:00:00', $weekStart->format('Y-m-d H:i:s'));
		// Viewed in the house zone it is local Monday midnight.
		self::assertSame('2026-07-13 00:00:00', $weekStart->setTimezone($tz)->format('Y-m-d H:i:s'));
	}

	public function testWeekStart_Berlin_SundayLateEvening_PreviousMonday(): void {
		$tz = new \DateTimeZone('Europe/Berlin');
		// Sunday 2026-08-30, 23:30 Berlin — still the same local week.
		$now = new \DateTime('2026-08-30 23:30:00', $tz);

		$weekStart = $this->service->weekStart($now, $tz);

		self::assertSame('2026-08-24', $this->service->dayKey($weekStart, $tz));
	}

	public function testWeekStart_Auckland_LocalMondayMidnightAsUTCInstant(): void {
		$tz = new \DateTimeZone('Pacific/Auckland');
		// Friday 2026-08-28, 06:00 NZST (UTC+12, winter).
		$now = new \DateTime('2026-08-28 06:00:00', $tz);

		$weekStart = $this->service->weekStart($now, $tz);

		// Local Monday 2026-08-24 00:00 NZST == Sunday 2026-08-23 12:00 UTC.
		self::assertSame('2026-08-23 12:00:00', $weekStart->format('Y-m-d H:i:s'));
		self::assertSame('2026-08-24', $this->service->dayKey($weekStart, $tz));
	}
}
