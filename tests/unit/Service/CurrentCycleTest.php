<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Service\CurrentCycle;
use PHPUnit\Framework\TestCase;

/**
 * The cycle keys are built and read in one place (ADR-021 decision 2,
 * RF-091-10): a key of a day, of a completion, and of a projected cycle
 * that is never a fact.
 */
class CurrentCycleTest extends TestCase {
	public function testKeys_AreReadWhereTheyAreBuilt(): void {
		$this->assertSame('2026-11-21', CurrentCycle::dayOfKey(CurrentCycle::keyOn('2026-11-21')));
		$this->assertNull(CurrentCycle::dayOfKey(CurrentCycle::keyAfter(88)), 'a cycle after a completion hangs on no day');
		$this->assertNull(CurrentCycle::dayOfKey(CurrentCycle::keyProjected('2026-11-21')));
	}

	public function testProjectedCycle_IsToldFromACurrentOne(): void {
		$projected = new CurrentCycle(CurrentCycle::keyProjected('2026-11-28'), '2026-11-28', '2026-11-27', '2026-11-29');
		$current = new CurrentCycle(CurrentCycle::keyOn('2026-11-21'), '2026-11-21', '2026-11-20', '2026-11-22');

		$this->assertTrue($projected->isProjected());
		$this->assertFalse($current->isProjected());
		$this->assertFalse(CurrentCycle::isKey($projected->key), 'a projected cycle is never a command target');
	}
}
