<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Titles;

use OCA\ChoreStrike\Db\HouseUser;

/**
 * Code-defined catalog of role-title packs and tiers (CS-PLAN-006).
 *
 * Pure constants — no DB, no DI. Three packs × three tiers = 9 titles.
 * Each pack is themed around a different progression signal so players with
 * different playstyles find a track that matches what they actually do:
 *
 *  - Home Helpers  → contributionScore (total energy spent)
 *  - Team Energy   → longestStreak     (dependability)
 *  - Cozy Home     → coins             (household contribution)
 *
 * Thresholds here are *defaults*. `RewardConfigService` overrides them per
 * house via the `title_threshold_{pack}_{tier}` keys (one per tier, 9 total)
 * so owners can tune difficulty without a code change. The
 * `RewardConfigService::getTitleThreshold()` getter falls back to the
 * catalog default when no override is stored.
 *
 * Thresholds in a pack MUST be strictly ascending (tier 1 < tier 2 < tier 3)
 * — {@see self::isValidCatalog()} enforces this for the test suite.
 */
class TitleCatalog {
	/** Pack key: consistency / breadth — driven by contributionScore. */
	public const PACK_HOME_HELPERS = 'home_helpers';
	/** Pack key: streak / dependability — driven by longestStreak. */
	public const PACK_TEAM_ENERGY = 'team_energy';
	/** Pack key: coins / household contribution — driven by `coins`. */
	public const PACK_COZY_HOME = 'cozy_home';

	/**
	 * Progression signal identifiers. Each maps to a HouseUser getter.
	 */
	public const SIGNAL_CONTRIBUTION_SCORE = 'contributionScore';
	public const SIGNAL_LONGEST_STREAK = 'longestStreak';
	public const SIGNAL_COINS = 'coins';

	/**
	 * The full pack/tier definition.
	 *
	 * Structure per pack:
	 *   - labelKey:   i18n key for the pack name.
	 *   - signal:     one of the SIGNAL_* constants.
	 *   - tiers:      ordered list (ascending threshold) of:
	 *       - key:        stable tier identifier (snake_case).
	 *       - labelKey:   i18n key for the tier label.
	 *       - threshold:  default unlock threshold (overridable via config).
	 *
	 * Tier index (0-based) is the canonical tier rank used by
	 * RewardConfigService config keys and the TitleService.
	 */
	public const PACKS = [
		self::PACK_HOME_HELPERS => [
			'labelKey' => 'titles.pack.home_helpers',
			'signal' => self::SIGNAL_CONTRIBUTION_SCORE,
			'tiers' => [
				['key' => 'helper', 'labelKey' => 'titles.home_helpers.helper', 'threshold' => 50],
				['key' => 'organizer', 'labelKey' => 'titles.home_helpers.organizer', 'threshold' => 250],
				['key' => 'care_captain', 'labelKey' => 'titles.home_helpers.care_captain', 'threshold' => 1000],
			],
		],
		self::PACK_TEAM_ENERGY => [
			'labelKey' => 'titles.pack.team_energy',
			'signal' => self::SIGNAL_LONGEST_STREAK,
			'tiers' => [
				['key' => 'spark_starter', 'labelKey' => 'titles.team_energy.spark_starter', 'threshold' => 7],
				['key' => 'flow_keeper', 'labelKey' => 'titles.team_energy.flow_keeper', 'threshold' => 30],
				['key' => 'team_anchor', 'labelKey' => 'titles.team_energy.team_anchor', 'threshold' => 90],
			],
		],
		self::PACK_COZY_HOME => [
			'labelKey' => 'titles.pack.cozy_home',
			'signal' => self::SIGNAL_COINS,
			'tiers' => [
				['key' => 'cozy_buddy', 'labelKey' => 'titles.cozy_home.cozy_buddy', 'threshold' => 500],
				['key' => 'room_ranger', 'labelKey' => 'titles.cozy_home.room_ranger', 'threshold' => 2500],
				['key' => 'home_guardian', 'labelKey' => 'titles.cozy_home.home_guardian', 'threshold' => 10000],
			],
		],
	];

	/**
	 * All pack keys in catalog order.
	 *
	 * @return string[]
	 */
	public static function getPackKeys(): array {
		return array_keys(self::PACKS);
	}

	/**
	 * Whether `$packKey` is a known pack.
	 */
	public static function isValidPack(string $packKey): bool {
		return isset(self::PACKS[$packKey]);
	}

	/**
	 * Get a pack definition by key. Throws if unknown — callers validate
	 * first via {@see isValidPack()}.
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
	 * Get the tiers for a pack (ordered ascending).
	 *
	 * @return array<int, array{key: string, labelKey: string, threshold: int}>
	 */
	public static function getTiers(string $packKey): array {
		return self::getPack($packKey)['tiers'];
	}

	/**
	 * Build the stable `{pack}.{tier}` identifier for a tier.
	 *
	 * This is the canonical string stored in the `unlocked_titles` cache and
	 * exchanged with the frontend.
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
			self::SIGNAL_CONTRIBUTION_SCORE => $user->getContributionScore(),
			self::SIGNAL_LONGEST_STREAK => $user->getLongestStreak(),
			self::SIGNAL_COINS => $user->getCoins(),
			default => 0,
		};
	}

	/**
	 * Validate the catalog invariants (used by the test suite).
	 *
	 * Returns true when every pack has 3 tiers, unique tier keys, a known
	 * signal, non-zero thresholds, and strictly ascending thresholds.
	 */
	public static function isValidCatalog(): bool {
		$validSignals = [
			self::SIGNAL_CONTRIBUTION_SCORE,
			self::SIGNAL_LONGEST_STREAK,
			self::SIGNAL_COINS,
		];

		if (count(self::PACKS) !== 3) {
			return false;
		}

		foreach (self::PACKS as $pack) {
			if (!in_array($pack['signal'], $validSignals, true)) {
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
