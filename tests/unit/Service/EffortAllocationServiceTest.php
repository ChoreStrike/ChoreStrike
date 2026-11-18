<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Service\EffortAllocationService;
use OCA\ChoreStrike\Service\SchedulingService;
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
		$this->service->allocate(EffortAllocationService::ENERGY_MAX + 1, 100);
	}

	/** D-089-07: no task outgrows the largest daily budget. */
	public function testTaskLimitEqualsTheLargestDailyBudget(): void {
		$this->assertSame(SchedulingService::ENERGY_BUDGET_MAX, EffortAllocationService::ENERGY_MAX);
	}

	/** D-089-07: the whole range pays at the frozen rate. */
	public function testAllocatePaysTheLargestTaskAtTheFrozenRate(): void {
		$this->assertSame(['energyMinor' => 2000, 'coinMinor' => 20000], $this->service->allocate(20, 100));
		$this->assertSame(['energyMinor' => 500, 'coinMinor' => 5000], $this->service->allocate(20, 25));
	}

	/**
	 * ADR-021 decision 11, D-091-15: `10 × E × P × factor × bonusPercent /
	 * 100` coin minor; the energy never sees a multiplier.
	 *
	 * @dataProvider multipliedShares
	 */
	public function testAllocate_MultipliesCoinsOnly(int $energy, int $sharePct, int $factor, int $bonusPercent, int $coinMinor): void {
		$this->assertSame(
			['energyMinor' => $energy * $sharePct, 'coinMinor' => $coinMinor],
			$this->service->allocate($energy, $sharePct, $factor, $bonusPercent)
		);
	}

	public static function multipliedShares(): array {
		return [
			'no multiplier is the frozen rate' => [3, 100, 1, 100, 3000],
			'factor 2' => [3, 100, 2, 100, 6000],
			'factor 3 on a third' => [1, 34, 3, 100, 1020],
			'a bonus day alone' => [1, 33, 1, 150, 495],
			'both multiply' => [2, 34, 3, 150, 3060],
			'the largest task at the largest multipliers' => [20, 100, 3, 150, 90000],
		];
	}

	public function testAllocate_SharesOfOneCompletionEachUseTheirOwnFactor(): void {
		// Energy 1 by three people at × 1, × 2 and × 3 on a bonus day.
		$coins = [];
		foreach ([[34, 1], [33, 2], [33, 3]] as [$sharePct, $factor]) {
			$coins[] = $this->service->allocate(1, $sharePct, $factor, 150)['coinMinor'];
		}
		$this->assertSame([510, 990, 1485], $coins);
	}

	/** @dataProvider unknownMultipliers */
	public function testAllocate_RejectsAnUnknownMultiplier(int $factor, int $bonusPercent): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->service->allocate(1, 100, $factor, $bonusPercent);
	}

	public static function unknownMultipliers(): array {
		return ['factor 0' => [0, 100], 'factor 4' => [4, 100], 'percent 0' => [1, 0], 'percent 200' => [1, 200], 'percent 125' => [1, 125]];
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
