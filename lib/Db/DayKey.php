<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Db;

/**
 * Day keys (`Y-m-d`) as the mappers compare them with DATE columns.
 *
 * Nextcloud binds an entity's date field as 'Y-m-d 00:00:00'; PostgreSQL
 * and MySQL store the day, SQLite keeps the text and compares strings, so
 * `<=`, `=` and `>` against a bare day key put that day after itself
 * there. `>=` a day and `<` the day after hold for either form on every
 * provider. Bare keys, because Doctrine's PARAM_DATE conversion rejects
 * plain 'Y-m-d' strings.
 */
final class DayKey {
	/** The day after a day: the exclusive upper bound of "on or before the day". */
	public static function after(string $dayKey): string {
		return (new \DateTimeImmutable($dayKey, new \DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d');
	}
}
