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
 * - `unlockThreshold`: lifetime finished chores a member needs before the
 *   game opens while the owner gate is on (D-086-06; 0 = always open).
 *   The count is derived from valid history ({@see ArcadeAccessService}).
 *
 * The client registry (`src/components/Minigames/gameRegistry.js`) pins the
 * same id list; adding a game updates both sides (ArcadeCatalogTest and the
 * registry spec fail until they agree).
 */
final class ArcadeCatalog {
	/**
	 * @var array<string, array{maxScore: int, maxDurationMs: int, pointsPerSecond: int, burstPoints: int, unlockThreshold: int}>
	 */
	public const GAMES = [
		// Spot Sprint: 60-second rounds on a 4x4 floor, 10 per stain (20
		// while the golden sponge works), at most one item per 450 ms after
		// the opening stain — at most 20 + 44 points per second. The
		// always-open entry game.
		'spots' => [
			'maxScore' => 3000,
			'maxDurationMs' => 65000,
			'pointsPerSecond' => 50,
			'burstPoints' => 100,
			'unlockThreshold' => 0,
		],
		// Sock Match: eight pairs on a 4x4 board, untimed and without a
		// fail state; a pair scores 100 minus 5 per wrong guess so far (at
		// least 20), so 800 is the exact maximum. The game never rewards
		// speed and a lucky perfect round lasts only seconds, so the burst
		// covers the whole maximum: the ceiling binds, the rate is nominal.
		// A round left running stays saveable for a day (R086D-02).
		'socks' => [
			'maxScore' => 800,
			'maxDurationMs' => 86400000,
			'pointsPerSecond' => 1,
			'burstPoints' => 800,
			'unlockThreshold' => 3,
		],
		// Crumb Snake: 10 per crumb, 30 per golden crumb, a 20x20 board and
		// at least 60 ms per move, so at most 387 crumbs (11610 points). A
		// shortest-path autopilot (client logic spec, seeds 1-1000) averages
		// at most about 17 points per second over a round of ten seconds or
		// more and breaks the burst plus 12 per second only in its luckiest
		// rounds; 20 per second keeps real play clear of the rule (WP-H).
		// The `snake` id predates the re-theme and stays, so existing
		// scores remain valid.
		'snake' => [
			'maxScore' => 20000,
			'maxDurationMs' => 3600000,
			'pointsPerSecond' => 20,
			'burstPoints' => 100,
			'unlockThreshold' => 10,
		],
		// Tile Breaker: 10 points per tile hit, endless walls of 8x5 tiles,
		// bubbles at most 15 cells per second and at most three in play. An
		// autopilot that never misses (client logic spec) scores about 7
		// points per second over an hour and at most about 15 per second
		// over any minute; rate and burst stay well above it, the ceiling is
		// about twice its hour.
		'tiles' => [
			'maxScore' => 50000,
			'maxDurationMs' => 3600000,
			'pointsPerSecond' => 20,
			'burstPoints' => 300,
			'unlockThreshold' => 25,
		],
		// Dust Runner: 1 point per dust patch, at most one per robot move
		// (150 ms), and 10 per caught dust bunny, at most three per charge;
		// a charge needs the dock, which recharges for 20 seconds and is
		// ready again in each new room (at least 119 dust patches, so at
		// least about 18 seconds per room). That bounds a round at about
		// 30 + 9.9 points per second (about 35500 in an hour); an autopilot
		// that docks and catches at every chance (client logic spec) scores
		// about 19000 in an hour.
		'dust' => [
			'maxScore' => 36000,
			'maxDurationMs' => 3600000,
			'pointsPerSecond' => 10,
			'burstPoints' => 100,
			'unlockThreshold' => 50,
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
	 * @return array{maxScore: int, maxDurationMs: int, pointsPerSecond: int, burstPoints: int, unlockThreshold: int}
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
