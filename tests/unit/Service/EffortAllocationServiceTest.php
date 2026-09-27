<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Service\EffortAllocationService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the ONE pure share implementation (CS-PLAN-070 WP3 §3.2,
 * T3 conservation): exact minor amounts, equal wallet-order remainders,
 * share-set validation invariants and the money-boundary guard.
 */
class EffortAllocationServiceTest extends TestCase {
	private EffortAllocationService $service;

	protected function setUp(): void {
		$this->service = new EffortAllocationService();
	}

	public function testAllocateExactAmountsForWholeShares(): void {
		// E=3, P=100 → 300 energy minor, 3000 coin minor.
		$this->assertSame(['energyMinor' => 300, 'coinMinor' => 3000], $this->service->allocate(3, 100));
		// E=1, P=34 → 34 energy minor, 340 coin minor.
		$this->assertSame(['energyMinor' => 34, 'coinMinor' => 340], $this->service->allocate(1, 34));
	}

	public function testSelectedSharesConserveExactly(): void {
		// The WP3 core invariant: a valid completion's selected shares total
		// exactly 100*E energy minor and 1000*E coin minor — never a rounding
		// residue. Wallet-order remainders (34/33/33) must conserve.
		$totalEnergy = 1;
		$energyMinor = 0;
		$coinMinor = 0;
		foreach ([34, 33, 33] as $sharePct) {
			$amounts = $this->service->allocate($totalEnergy, $sharePct);
			$energyMinor += $amounts['energyMinor'];
			$coinMinor += $amounts['coinMinor'];
		}
		$this->assertSame(100 * $totalEnergy, $energyMinor);
		$this->assertSame(EffortAllocationService::COIN_MINOR_PER_ENERGY * $totalEnergy, $coinMinor);
	}

	public function testEqualSharesUseWalletOrderForRemainders(): void {
		// Wallet ids fix the remainder order (never role/name/request order):
		// the lowest wallet id gets the extra percent.
		$shares = $this->service->equalShares([
			'kid' => 7,
			'mom' => 3,
			'dad' => 5,
		]);
		$this->assertSame(34, $shares['mom']);
		$this->assertSame(33, $shares['dad']);
		$this->assertSame(33, $shares['kid']);
		$this->assertSame(100, array_sum($shares));
	}

	public function testEqualSharesNullWalletSortsLast(): void {
		$shares = $this->service->equalShares(['a' => null, 'b' => 9]);
		$this->assertSame(50, $shares['a']);
		$this->assertSame(50, $shares['b']);
	}

	public function testValidateSharesAcceptsExactHundred(): void {
		$this->service->validateShares(['a' => 70, 'b' => 30]);
		$this->service->validateShares(['a' => 100]);
		$this->service->validateShares(['a' => 34, 'b' => 33, 'c' => 33]);
		$this->assertTrue(true);
	}

	public function testValidateSharesRejectsWrongSum(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->service->validateShares(['a' => 70, 'b' => 31]);
	}

	public function testValidateSharesRejectsZeroOrNegativeShare(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->service->validateShares(['a' => 100, 'b' => 0]);
	}

	public function testValidateSharesRejectsEmptySet(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->service->validateShares([]);
	}

	public function testAllocateRejectsOutOfRangeEnergy(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->service->allocate(0, 100);
	}

	public function testAllocateRejectsOutOfRangeEnergyHigh(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->service->allocate(6, 100);
	}

	public function testAssertSafeAmountAcceptsJsSafeBound(): void {
		$this->service->assertSafeAmount(EffortAllocationService::JS_SAFE_INTEGER_MAX, 'x');
		$this->service->assertSafeAmount(-EffortAllocationService::JS_SAFE_INTEGER_MAX, 'x');
		$this->assertTrue(true);
	}

	public function testAssertSafeAmountRejectsOverflow(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->service->assertSafeAmount(EffortAllocationService::JS_SAFE_INTEGER_MAX + 1, 'x');
	}
}
