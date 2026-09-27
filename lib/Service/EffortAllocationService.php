<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\OccurrenceMember;

/**
 * The ONE pure share-validation/allocation implementation (CS-PLAN-070
 * WP3 §3.2) — moved (not copied) from the WP2 planner arithmetic and now
 * shared by planned shares (SchedulePlanService) and actual shares
 * (CompletionService).
 *
 * Exact integer hundredths: for total energy `E` (1-5) and percentage `P`,
 * `energyMinor = E * P` and `coinMinor = 10 * E * P`; totals are exactly
 * `100 * E` and `1000 * E`. All arithmetic stays inside both PHP integer
 * bounds and the signed JavaScript safe-integer range (JSON boundary).
 *
 * Equal proposals use the immutable WP0 wallet order for the remainders
 * (energy 1 shared by three people is 34/33/33%) — never role, name or
 * request order. This class performs no I/O.
 */
class EffortAllocationService {
	/** Coin minor units per whole energy unit (frozen rate: 10 Coins). */
	public const COIN_MINOR_PER_ENERGY = 1000;

	/** Energy minor units per whole energy unit. */
	public const ENERGY_MINOR_PER_UNIT = 100;

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
		if ($count < 1 || $count > DailyCapacityService::MAX_PARTICIPANTS) {
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
	 * minor and `10 * E * P` coin minor.
	 *
	 * @return array{energyMinor: int, coinMinor: int}
	 * @throws \InvalidArgumentException on an out-of-range energy or share
	 */
	public function allocate(int $totalEnergy, int $sharePct): array {
		if ($totalEnergy < 1 || $totalEnergy > 5) {
			throw new \InvalidArgumentException('Total energy must be between 1 and 5');
		}
		if ($sharePct < 1 || $sharePct > 100) {
			throw new \InvalidArgumentException('Every share must be a whole percent between 1 and 100');
		}
		return [
			'energyMinor' => $totalEnergy * $sharePct,
			'coinMinor' => $totalEnergy * $sharePct * (self::COIN_MINOR_PER_ENERGY / 100),
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

	/**
	 * The effective concrete agreement shares (WP3 §3.2) as ONE shared
	 * derivation: ACTIVE occurrence member rows with a resolvable wallet
	 * map to their share (clamped to at least 1 so a legacy zero row can
	 * not silently drop a participant). Used by the completion writer AND
	 * the read-side proposal block — the preview can never drift from
	 * what a participant-less completion would credit.
	 *
	 * @param OccurrenceMember[] $memberRows
	 * @param array<int, string> $userIdByWallet walletId => userId
	 * @return array<string, int> userId => sharePct (empty = no agreement)
	 */
	public function agreementShares(array $memberRows, array $userIdByWallet): array {
		$agreed = [];
		foreach ($memberRows as $memberRow) {
			if ($memberRow->getDisposition() !== OccurrenceMember::DISPOSITION_ACTIVE) {
				continue;
			}
			$uid = $userIdByWallet[(int)$memberRow->getWalletId()] ?? null;
			if ($uid !== null) {
				$agreed[$uid] = max(1, $memberRow->getSharePct());
			}
		}
		return $agreed;
	}
}
