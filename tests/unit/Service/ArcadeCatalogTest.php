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
		$this->assertSame(['snake'], ArcadeCatalog::ids());
	}

	public function testEveryGame_HasCompletePositiveRules(): void {
		foreach (ArcadeCatalog::ids() as $gameId) {
			$rules = ArcadeCatalog::rules($gameId);
			$this->assertSame(['maxScore', 'maxDurationMs', 'pointsPerSecond', 'burstPoints'], array_keys($rules), $gameId);
			foreach ($rules as $key => $value) {
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

	public function testHas_KnownAndUnknownIds(): void {
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
	}
}
