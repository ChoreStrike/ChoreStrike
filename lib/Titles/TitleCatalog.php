<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Titles;

use OCA\ChoreStrike\Db\HouseUser;

/**
 * Code-defined catalog of the RETAINED role-title series (CS-PLAN-070
 * WP4 cutover, C3d/D7c).
 *
 * Pure constants — no DB, no DI. One series × three tiers = 3 titles:
 *
 *  - Family Rhythm → longestStreak (the valid-history maximum maintained
 *    by RhythmService, C1f/C3g)
 *
 * The former contribution-driven Home Helpers and streak-driven Team
 * Energy packs are retired with the WP4 single-series cutover (C3d: no
 * separate effort/wallet title series). Qualifying on the valid-history
 * MAXIMUM (not the current streak) keeps an earned title alive through
 * ordinary later streak breaks (D7c); only a genuine correction that
 * lowers the valid-history maximum can shrink it (C3g). Thresholds are
 * FIXED policy (10/80/3-6-12 first slice) — no config override exists.
 *
 * Thresholds MUST be strictly ascending (tier 1 < tier 2 < tier 3)
 * — {@see self::isValidCatalog()} enforces this for the test suite.
 */
class TitleCatalog {
	/** Series key: retained rhythm recognition (C3d) — driven by longestStreak. */
	public const PACK_FAMILY_RHYTHM = 'family_rhythm';

	/**
	 * Progression signal identifiers. Each maps to a HouseUser getter.
	 */
	public const SIGNAL_LONGEST_STREAK = 'longestStreak';

	/**
	 * The full series definition.
	 *
	 * Structure:
	 *   - labelKey:   i18n key for the series name.
	 *   - signal:     one of the SIGNAL_* constants.
	 *   - tiers:      ordered list (ascending threshold) of:
	 *       - key:        stable tier identifier (snake_case).
	 *       - labelKey:   i18n key for the tier label.
	 *       - threshold:  fixed unlock threshold.
	 *
	 * Tier index (0-based) is the canonical tier rank used by TitleService.
	 */
	public const PACKS = [
		self::PACK_FAMILY_RHYTHM => [
			'labelKey' => 'titles.pack.family_rhythm',
			'signal' => self::SIGNAL_LONGEST_STREAK,
			'tiers' => [
				// C3d: retained personal rhythm awards at 3/6/12 consecutive
				// successful planned task days (valid-history maximum).
				['key' => 'rhythm_3', 'labelKey' => 'titles.family_rhythm.rhythm_3', 'threshold' => 3],
				['key' => 'rhythm_6', 'labelKey' => 'titles.family_rhythm.rhythm_6', 'threshold' => 6],
				['key' => 'rhythm_12', 'labelKey' => 'titles.family_rhythm.rhythm_12', 'threshold' => 12],
			],
		],
	];

	/**
	 * The single series key (kept for structural symmetry with consumers).
	 *
	 * @return string[]
	 */
	public static function getPackKeys(): array {
		return array_keys(self::PACKS);
	}

	/**
	 * Whether `$packKey` is the known retained series.
	 */
	public static function isValidPack(string $packKey): bool {
		return isset(self::PACKS[$packKey]);
	}

	/**
	 * Get the series definition by key. Throws if unknown — callers
	 * validate first via {@see isValidPack()}.
	 *
	 * @return array{labelKey: string, signal: string, tiers: array<int, array{key: string, labelKey: string, threshold: int}>}
	 */
	public static function getPack(string $packKey): array {
		if (!isset(self::PACKS[$packKey])) {
			throw new \InvalidArgumentException('Unknown title pack: ' . $packKey);
		}
		return self::PACKS[$packKey];
	}

	/**
	 * Get the tiers of a series (ordered ascending).
	 *
	 * @return array<int, array{key: string, labelKey: string, threshold: int}>
	 */
	public static function getTiers(string $packKey): array {
		return self::getPack($packKey)['tiers'];
	}

	/**
	 * Build the stable `{pack}.{tier}` identifier for a tier.
	 *
	 * This is the canonical string stored in the `unlocked_titles` marker
	 * and exchanged with the frontend.
	 */
	public static function tierId(string $packKey, int $tierIndex): string {
		return $packKey . '.' . self::getTiers($packKey)[$tierIndex]['key'];
	}

	/**
	 * Read the raw progression-signal value off a HouseUser.
	 *
	 * @return int The current signal value (0 when the signal is unknown).
	 */
	public static function getSignalValue(HouseUser $user, string $signal): int {
		return match ($signal) {
			self::SIGNAL_LONGEST_STREAK => $user->getLongestStreak(),
			default => 0,
		};
	}

	/**
	 * Validate the catalog invariants (used by the test suite).
	 *
	 * Returns true when the series has 3 tiers, unique tier keys, a known
	 * signal, non-zero thresholds, and strictly ascending thresholds.
	 */
	public static function isValidCatalog(): bool {
		if (count(self::PACKS) !== 1) {
			return false;
		}

		foreach (self::PACKS as $pack) {
			if ($pack['signal'] !== self::SIGNAL_LONGEST_STREAK) {
				return false;
			}
			$tiers = $pack['tiers'];
			if (count($tiers) !== 3) {
				return false;
			}
			$keys = [];
			$previous = -1;
			foreach ($tiers as $tier) {
				$keys[] = $tier['key'];
				if ($tier['threshold'] <= 0) {
					return false;
				}
				if ($tier['threshold'] <= $previous) {
					return false;
				}
				$previous = $tier['threshold'];
			}
			if (count(array_unique($keys)) !== 3) {
				return false;
			}
		}

		return true;
	}
}
