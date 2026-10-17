<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

/**
 * The one server-side source of arcade games and their score rules
 * (CS-PLAN-086 §3.3, D-086-04). Client scores are untrusted; every rule
 * here bounds what a crafted request can store:
 *
 * - `maxScore`: inclusive per-game ceiling, above the game's reachable
 *   maximum.
 * - `maxDurationMs`: longest accepted active play time of one round.
 * - `pointsPerSecond` + `burstPoints`: a score must not exceed
 *   `burstPoints + pointsPerSecond * durationMs / 1000`; the burst covers a
 *   lucky streak early in a short round.
 *
 * The client registry (`src/components/Minigames/gameRegistry.js`) pins the
 * same id list; adding a game updates both sides (ArcadeCatalogTest and the
 * registry spec fail until they agree).
 */
final class ArcadeCatalog {
	/**
	 * @var array<string, array{maxScore: int, maxDurationMs: int, pointsPerSecond: int, burstPoints: int}>
	 */
	public const GAMES = [
		// Crumb Snake: 10 per crumb, 30 per golden crumb, a 20x20 board and
		// at least 60 ms per move. The `snake` id predates the re-theme and
		// stays, so existing scores remain valid.
		'snake' => [
			'maxScore' => 20000,
			'maxDurationMs' => 3600000,
			'pointsPerSecond' => 30,
			'burstPoints' => 100,
		],
	];

	/**
	 * @return string[] Known game ids in catalog order.
	 */
	public static function ids(): array {
		return array_keys(self::GAMES);
	}

	public static function has(string $gameId): bool {
		return isset(self::GAMES[$gameId]);
	}

	/**
	 * @return array{maxScore: int, maxDurationMs: int, pointsPerSecond: int, burstPoints: int}
	 * @throws \OutOfBoundsException for an unknown id (callers check has() first)
	 */
	public static function rules(string $gameId): array {
		if (!self::has($gameId)) {
			throw new \OutOfBoundsException('Unknown arcade game');
		}
		return self::GAMES[$gameId];
	}

	/**
	 * Whether a score is reachable in the given active play time.
	 */
	public static function isPlausible(string $gameId, int $score, int $durationMs): bool {
		$rules = self::rules($gameId);
		$reachable = $rules['burstPoints'] + intdiv($rules['pointsPerSecond'] * $durationMs, 1000);
		return $score <= $reachable;
	}
}
