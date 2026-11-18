<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\BonusPeriod;

/**
 * The ONE pure share-validation/allocation implementation (CS-PLAN-070
 * WP3 §3.2) for the actual shares of a completion
 * (CompletionService).
 *
 * Exact integer hundredths: for total energy `E` and percentage `P`,
 * `energyMinor = E * P` and `coinMinor = 10 * E * P`; totals are exactly
 * `100 * E` and `1000 * E`. The two coin multipliers of ADR-021
 * decision 11 reach the coins only: `10 * E * P * factor * bonusPercent /
 * 100`, rounded down once after the product (D-091-15). All arithmetic
 * stays inside both PHP integer bounds and the signed JavaScript
 * safe-integer range (JSON boundary).
 *
 * Equal proposals use the immutable WP0 wallet order for the remainders
 * (energy 1 shared by three people is 34/33/33%) — never role, name or
 * request order. This class performs no I/O.
 */
class EffortAllocationService {
	/** Inclusive lower bound of a task's energy. */
	public const ENERGY_MIN = 1;

	/**
	 * Inclusive upper bound of a task's energy (D-089-07). It equals the largest
	 * daily budget by decision, not by reference: allocate() also re-reads
	 * frozen completions, so this bound may rise and must never fall.
	 */
	public const ENERGY_MAX = 20;

	/** Coin minor units per whole energy unit (frozen rate: 10 Coins). */
	public const COIN_MINOR_PER_ENERGY = 1000;

	/** Energy minor units per whole energy unit. */
	public const ENERGY_MINOR_PER_UNIT = 100;

	/** The coin factors an owner can give a member (ADR-021 decision 11). */
	public const COIN_FACTORS = [1, 2, 3];

	/** What a share's coins count in percent: an ordinary day, a day of a bonus period. */
	public const BONUS_PERCENTS = [BonusPeriod::PERCENT_NONE, BonusPeriod::PERCENT];

	/** Maximum positive whole-percent participants of one completion. */
	public const MAX_PARTICIPANTS = 100;

	/** The JSON money boundary: every minor-unit value must stay within. */
	public const JS_SAFE_INTEGER_MAX = 9007199254740991;

	/**
	 * Validate an explicit participant share set: 1-100 unique subjects,
	 * every share a positive whole percent, total exactly 100.
	 *
	 * @param array<int|string, int> $shares walletId|userId => sharePct
	 * @throws \InvalidArgumentException on any violation
	 */
	public function validateShares(array $shares): void {
		$count = count($shares);
		if ($count < 1 || $count > self::MAX_PARTICIPANTS) {
			throw new \InvalidArgumentException('A completion requires between 1 and 100 participants');
		}
		$total = 0;
		foreach ($shares as $subject => $pct) {
			if (!is_int($pct) || $pct < 1 || $pct > 100) {
				throw new \InvalidArgumentException('Every share must be a whole percent between 1 and 100');
			}
			$total += $pct;
		}
		if ($total !== 100) {
			throw new \InvalidArgumentException('Shares must total exactly 100 percent');
		}
	}

	/**
	 * Equal shares over the given subjects with the immutable wallet order
	 * for the remainders (34/33/33). `$subjects` maps the stable subject
	 * key to its wallet id; a NULL wallet (only possible for pre-binding
	 * reads) sorts last deterministically.
	 *
	 * @param array<int|string, int|null> $subjects subject key => walletId
	 * @return array<int|string, int> subject key => sharePct
	 */
	public function equalShares(array $subjects): array {
		$entries = [];
		foreach ($subjects as $key => $walletId) {
			$entries[] = ['key' => $key, 'walletId' => $walletId !== null ? (int)$walletId : PHP_INT_MAX];
		}
		usort($entries, static fn (array $a, array $b): int => $a['walletId'] <=> $b['walletId']);

		$count = count($entries);
		if ($count === 0) {
			return [];
		}
		$base = intdiv(100, $count);
		$remainder = 100 - $base * $count;

		$shares = [];
		foreach ($entries as $index => $entry) {
			$shares[$entry['key']] = $base + ($index < $remainder ? 1 : 0);
		}
		return $shares;
	}

	/**
	 * The exact minor amounts of one participant share: `E * P` energy
	 * minor and `10 * E * P * factor * bonusPercent / 100` coin minor. The
	 * multipliers never reach the energy.
	 *
	 * @param int $coinFactor The member's frozen coin factor (self::COIN_FACTORS)
	 * @param int $bonusPercent The frozen percent of the work day (self::BONUS_PERCENTS)
	 * @return array{energyMinor: int, coinMinor: int}
	 * @throws \InvalidArgumentException on an out-of-range energy, share or multiplier
	 */
	public function allocate(int $totalEnergy, int $sharePct, int $coinFactor = 1, int $bonusPercent = 100): array {
		if ($totalEnergy < self::ENERGY_MIN || $totalEnergy > self::ENERGY_MAX) {
			throw new \InvalidArgumentException('Total energy must be between ' . self::ENERGY_MIN . ' and ' . self::ENERGY_MAX);
		}
		if ($sharePct < 1 || $sharePct > 100) {
			throw new \InvalidArgumentException('Every share must be a whole percent between 1 and 100');
		}
		if (!in_array($coinFactor, self::COIN_FACTORS, true) || !in_array($bonusPercent, self::BONUS_PERCENTS, true)) {
			throw new \InvalidArgumentException('Unknown coin multiplier');
		}
		$baseCoinMinor = $totalEnergy * $sharePct * intdiv(self::COIN_MINOR_PER_ENERGY, 100);
		return [
			'energyMinor' => $totalEnergy * $sharePct,
			// One rounding, after the whole product.
			'coinMinor' => intdiv($baseCoinMinor * $coinFactor * $bonusPercent, 100),
		];
	}

	/**
	 * Guard a monetary accumulator against integer overflow at the PHP and
	 * JSON boundaries (WP3 §3.1): overflow rejects the entire transaction.
	 *
	 * @throws \InvalidArgumentException on overflow
	 */
	public function assertSafeAmount(int $value, string $field): void {
		if ($value > self::JS_SAFE_INTEGER_MAX || $value < -self::JS_SAFE_INTEGER_MAX) {
			throw new \InvalidArgumentException($field . ' exceeds the safe integer range');
		}
	}
}
