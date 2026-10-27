<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

/**
 * Unique room names within a house (FEAT-UX-044, CS-PLAN-088 D-088-19): a
 * name already used by another live room, compared trimmed and
 * case-insensitively, gets the next free " 2", " 3", … appended. A taken
 * name whose number continues a taken base keeps counting from that base
 * ("Bath 2" → "Bath 3" when "Bath" exists). Existing duplicates are never
 * rewritten; the rule applies to new and renamed rooms only.
 */
final class RoomNamePolicy {
	/** Length of the `rooms.name` column. */
	public const MAX_LENGTH = 255;

	/**
	 * The name to store for `$wanted` next to the `$taken` names.
	 *
	 * @param list<string> $taken Names of the house's other live rooms
	 */
	public static function unique(string $wanted, array $taken): string {
		$name = trim($wanted);
		$keys = array_fill_keys(array_map(self::key(...), $taken), true);
		if (!isset($keys[self::key($name)])) {
			return $name;
		}
		$base = preg_replace('/\s+\d+$/u', '', $name) ?? $name;
		if ($base === '' || !isset($keys[self::key($base)])) {
			$base = $name;
		}
		for ($number = 2; ; $number++) {
			$suffix = ' ' . $number;
			$candidate = mb_substr($base, 0, self::MAX_LENGTH - mb_strlen($suffix)) . $suffix;
			if (!isset($keys[self::key($candidate)])) {
				return $candidate;
			}
		}
	}

	private static function key(string $name): string {
		return mb_strtolower(trim($name));
	}
}
