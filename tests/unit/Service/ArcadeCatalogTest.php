<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Service\ArcadeCatalog;
use PHPUnit\Framework\TestCase;

/**
 * CS-PLAN-086 §3.5: the server catalog is the runtime source of game ids.
 * This list and the client registry spec each pin the expected ids, so a
 * new game fails both until both sides are updated.
 */
class ArcadeCatalogTest extends TestCase {
	public function testIds_PinTheCatalog(): void {
		$this->assertSame(['spots', 'socks', 'snake'], ArcadeCatalog::ids());
	}

	public function testEveryGame_HasCompletePositiveRules(): void {
		foreach (ArcadeCatalog::ids() as $gameId) {
			$rules = ArcadeCatalog::rules($gameId);
			$this->assertSame(['maxScore', 'maxDurationMs', 'pointsPerSecond', 'burstPoints', 'unlockThreshold'], array_keys($rules), $gameId);
			foreach ($rules as $key => $value) {
				if ($key === 'unlockThreshold') {
					$this->assertGreaterThanOrEqual(0, $value, "$gameId.$key");
					continue;
				}
				$this->assertGreaterThan(0, $value, "$gameId.$key");
			}
			// The ceiling must be reachable within the longest round, or the
			// plausibility rule would silently lower it.
			$this->assertTrue(
				ArcadeCatalog::isPlausible($gameId, $rules['maxScore'], $rules['maxDurationMs']),
				"$gameId: maxScore unreachable within maxDurationMs"
			);
		}
	}

	/**
	 * UnlockThresholds_PinD08606: finished chores per game (spots 0,
	 * socks 3, snake 10, tiles 25, dust 50 as the games arrive). Every
	 * threshold above 0 is at least 3, so the progress copy needs no
	 * count-dependent plural (CS-PLAN-086 §3.2).
	 */
	public function testUnlockThresholds_PinD08606(): void {
		$thresholds = array_map(static fn (array $rules): int => $rules['unlockThreshold'], ArcadeCatalog::GAMES);
		$this->assertSame(['spots' => 0, 'socks' => 3, 'snake' => 10], $thresholds);
		foreach ($thresholds as $gameId => $threshold) {
			$this->assertTrue($threshold === 0 || $threshold >= 3, $gameId);
		}
	}

	public function testHas_KnownAndUnknownIds(): void {
		$this->assertTrue(ArcadeCatalog::has('spots'));
		$this->assertTrue(ArcadeCatalog::has('socks'));
		$this->assertTrue(ArcadeCatalog::has('snake'));
		$this->assertFalse(ArcadeCatalog::has('2048'));
		$this->assertFalse(ArcadeCatalog::has(''));
	}

	public function testRules_UnknownId_Throws(): void {
		$this->expectException(\OutOfBoundsException::class);
		ArcadeCatalog::rules('pacman');
	}

	/**
	 * IsPlausible_BurstPlusRate_InclusiveLimit (D-086-04)
	 */
	public function testIsPlausible_BurstPlusRate_InclusiveLimit(): void {
		// Snake: 100 burst points + 30 points per second.
		$this->assertTrue(ArcadeCatalog::isPlausible('snake', 100, 1));
		$this->assertFalse(ArcadeCatalog::isPlausible('snake', 101, 1));
		$this->assertTrue(ArcadeCatalog::isPlausible('snake', 400, 10000));
		$this->assertFalse(ArcadeCatalog::isPlausible('snake', 401, 10000));
		// Partial seconds count proportionally (floor of the reachable points).
		$this->assertTrue(ArcadeCatalog::isPlausible('snake', 115, 500));
		$this->assertFalse(ArcadeCatalog::isPlausible('snake', 116, 500));
		// Spot Sprint: 100 burst points + 50 per second; a full 60-second
		// round reaches its ceiling of 3000 (the client logic spec proves a
		// perfect player stays below the rate at every step).
		$this->assertTrue(ArcadeCatalog::isPlausible('spots', 3000, 60000));
		$this->assertFalse(ArcadeCatalog::isPlausible('spots', 601, 10000));
		// Sock Match never rewards speed: a lucky perfect round (800, the
		// exact maximum) is plausible however short; the ceiling binds.
		$this->assertTrue(ArcadeCatalog::isPlausible('socks', 800, 1));
		$this->assertFalse(ArcadeCatalog::isPlausible('socks', 801, 1));
		$this->assertSame(800, ArcadeCatalog::rules('socks')['maxScore']);
		// Untimed: a round left running for hours still saves (R086D-02).
		$this->assertSame(86400000, ArcadeCatalog::rules('socks')['maxDurationMs']);
	}
}
