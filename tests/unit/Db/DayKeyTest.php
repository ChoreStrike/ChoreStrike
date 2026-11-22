<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Db;

use OCA\ChoreStrike\Db\DayKey;
use PHPUnit\Framework\TestCase;

/**
 * The exclusive upper bound the mappers compare DATE columns with.
 *
 * @covers \OCA\ChoreStrike\Db\DayKey
 */
class DayKeyTest extends TestCase {
	/** @dataProvider days */
	public function testAfter_IsTheNextCivilDay(string $day, string $after): void {
		self::assertSame($after, DayKey::after($day));
	}

	/** @return array<string, array{string, string}> */
	public static function days(): array {
		return [
			'inside a month' => ['2026-11-22', '2026-11-23'],
			'across a month' => ['2026-11-30', '2026-12-01'],
			'across a year' => ['2026-12-31', '2027-01-01'],
			'a leap day' => ['2028-02-28', '2028-02-29'],
			// A civil day, never an instant: the day a zone changes its clock is one day long.
			'the day the clocks change' => ['2026-10-25', '2026-10-26'],
		];
	}

	public function testAfter_DoesNotDependOnTheHostZone(): void {
		$zone = date_default_timezone_get();
		try {
			date_default_timezone_set('Pacific/Kiritimati');
			self::assertSame('2026-11-23', DayKey::after('2026-11-22'));
		} finally {
			date_default_timezone_set($zone);
		}
	}
}
