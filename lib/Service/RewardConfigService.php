<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Titles\TitleCatalog;
use OCP\IAppConfig;

/**
 * Service for managing reward system configuration
 */
class RewardConfigService {
	private const APP_NAME = 'chorestrike';

	// Config keys with defaults
	public const KEY_TIER_BRONZE_MULTIPLIER = 'tier_bronze_multiplier';
	public const KEY_TIER_SILVER_MULTIPLIER = 'tier_silver_multiplier';
	public const KEY_TIER_GOLD_MULTIPLIER = 'tier_gold_multiplier';
	public const KEY_EARLY_BONUS_THRESHOLD_DAYS = 'early_bonus_threshold_days';
	public const KEY_EARLY_BONUS_MULTIPLIER = 'early_bonus_multiplier';
	public const KEY_ENERGY_MAX_PER_TASK = 'energy_max_per_task';
	public const KEY_COIN_BASE_PER_ENERGY = 'coin_base_per_energy';
	public const KEY_STREAK_FREEZES_PER_WEEK = 'streak_freezes_per_week';
	public const KEY_STREAK_FREEZE_CAP = 'streak_freeze_cap';
	public const KEY_COMBO_BONUS_FREEZE_THRESHOLD = 'combo_bonus_freeze_threshold';
	public const KEY_COMBO_MULTIPLIER = 'combo_multiplier';
	public const KEY_WEEKLY_GOAL_TARGET_PERCENT = 'weekly_goal_target_percent';
	public const KEY_TEAMWORK_BONUS_ENABLED = 'teamwork_bonus_enabled';
	public const KEY_TEAMWORK_BONUS_MAX_PCT = 'teamwork_bonus_max_pct';
	public const KEY_TITLES_ENABLED = 'titles_enabled';
	/** Prefix for per-tier title thresholds; full key is `{prefix}_{pack}_{tierIndex}`. */
	public const KEY_TITLE_THRESHOLD_PREFIX = 'title_threshold';

	private IAppConfig $config;
	private ?AppLogger $appLogger;

	public function __construct(IAppConfig $config, ?AppLogger $appLogger = null) {
		$this->config = $config;
		$this->appLogger = $appLogger;
	}

	/**
	 * Clamp an int config value into [min, max] and log when clamping fires.
	 *
	 * CR-SVC-19: applied on BOTH the write and the read path so previously
	 * persisted out-of-range values are never applied raw.
	 */
	private function clampInt(string $key, int $value, int $min, ?int $max = null): int {
		$clamped = max($min, $value);
		if ($max !== null) {
			$clamped = min($max, $clamped);
		}
		$this->logClamp($key, $value, $clamped);
		return $clamped;
	}

	/**
	 * Clamp a float config value into [min, max] and log when clamping fires.
	 *
	 * CR-SVC-19: applied on BOTH the write and the read path so previously
	 * persisted out-of-range values are never applied raw.
	 */
	private function clampFloat(string $key, float $value, float $min, ?float $max = null): float {
		$clamped = max($min, $value);
		if ($max !== null) {
			$clamped = min($max, $clamped);
		}
		$this->logClamp($key, $value, $clamped);
		return $clamped;
	}

	/**
	 * Log a clamp event once (only when the value actually changed).
	 *
	 * @param int|float $value The original value
	 * @param int|float $clamped The clamped value
	 */
	private function logClamp(string $key, $value, $clamped): void {
		if ($value === $clamped) {
			return;
		}

		$this->appLogger?->warning('Reward config value clamped', [
			'service' => 'RewardConfigService',
			'key' => $key,
			'value' => $value,
			'clampedTo' => $clamped,
		]);
	}

	/**
	 * Get tier multiplier for a given tier
	 *
	 * Clamped to >= 0 (CR-SVC-19): a negative multiplier would invert rewards.
	 *
	 * @param string $tier The tier (bronze, silver, gold)
	 */
	public function getTierMultiplier(string $tier): float {
		$value = match ($tier) {
			'bronze' => (float)$this->config->getValueString(
				self::APP_NAME,
				self::KEY_TIER_BRONZE_MULTIPLIER,
				'1.0'
			),
			'silver' => (float)$this->config->getValueString(
				self::APP_NAME,
				self::KEY_TIER_SILVER_MULTIPLIER,
				'1.5'
			),
			'gold' => (float)$this->config->getValueString(
				self::APP_NAME,
				self::KEY_TIER_GOLD_MULTIPLIER,
				'2.0'
			),
			default => null,
		};

		if ($value === null) {
			return 1.0;
		}

		return $this->clampFloat('tier_' . $tier . '_multiplier', $value, 0.0);
	}

	/**
	 * Get early completion bonus threshold in days
	 *
	 * Clamped to >= 0 (CR-SVC-19).
	 */
	public function getEarlyBonusThresholdDays(): int {
		return $this->clampInt(
			self::KEY_EARLY_BONUS_THRESHOLD_DAYS,
			(int)$this->config->getValueString(
				self::APP_NAME,
				self::KEY_EARLY_BONUS_THRESHOLD_DAYS,
				'21'
			),
			0
		);
	}

	/**
	 * Get early completion bonus multiplier
	 *
	 * Clamped to >= 1.0 (CR-SVC-19): below 1.0 the "bonus" would penalize
	 * early completion.
	 */
	public function getEarlyBonusMultiplier(): float {
		return $this->clampFloat(
			self::KEY_EARLY_BONUS_MULTIPLIER,
			(float)$this->config->getValueString(
				self::APP_NAME,
				self::KEY_EARLY_BONUS_MULTIPLIER,
				'1.5'
			),
			1.0
		);
	}

	/**
	 * Get maximum energy per task
	 *
	 * Clamped to >= 0 (CR-SVC-19).
	 */
	public function getEnergyMaxPerTask(): int {
		return $this->clampInt(
			self::KEY_ENERGY_MAX_PER_TASK,
			(int)$this->config->getValueString(
				self::APP_NAME,
				self::KEY_ENERGY_MAX_PER_TASK,
				'9'
			),
			0
		);
	}

	/**
	 * Get base coins per energy point
	 *
	 * Clamped to >= 0 (CR-SVC-19): a negative base would invert rewards.
	 */
	public function getCoinBasePerEnergy(): int {
		return $this->clampInt(
			self::KEY_COIN_BASE_PER_ENERGY,
			(int)$this->config->getValueString(
				self::APP_NAME,
				self::KEY_COIN_BASE_PER_ENERGY,
				'10'
			),
			0
		);
	}

	/**
	 * Get number of streak freezes restocked per week
	 *
	 * Clamped to >= 0 (CR-SVC-19).
	 */
	public function getStreakFreezesPerWeek(): int {
		return $this->clampInt(
			self::KEY_STREAK_FREEZES_PER_WEEK,
			(int)$this->config->getValueString(
				self::APP_NAME,
				self::KEY_STREAK_FREEZES_PER_WEEK,
				'1'
			),
			0
		);
	}

	/**
	 * Get maximum number of streak freezes a user can hold
	 *
	 * Clamped to >= 0 (CR-SVC-19).
	 */
	public function getStreakFreezeCap(): int {
		return $this->clampInt(
			self::KEY_STREAK_FREEZE_CAP,
			(int)$this->config->getValueString(
				self::APP_NAME,
				self::KEY_STREAK_FREEZE_CAP,
				'2'
			),
			0
		);
	}

	/**
	 * Get combo count threshold for earning a bonus freeze
	 *
	 * Clamped to >= 0 (CR-SVC-19).
	 */
	public function getComboBonusFreezeThreshold(): int {
		return $this->clampInt(
			self::KEY_COMBO_BONUS_FREEZE_THRESHOLD,
			(int)$this->config->getValueString(
				self::APP_NAME,
				self::KEY_COMBO_BONUS_FREEZE_THRESHOLD,
				'5'
			),
			0
		);
	}

	/**
	 * Get the weekly household goal completion target as a percent (0-100).
	 *
	 * The household "reaches" its weekly goal when the share of due tasks
	 * completed this week is at least this percent. Default 80 (matches the
	 * Phase 3 plan recommendation: forgiving enough for family life).
	 */
	public function getWeeklyGoalTargetPercent(): int {
		$value = (int)$this->config->getValueString(
			self::APP_NAME,
			self::KEY_WEEKLY_GOAL_TARGET_PERCENT,
			'80'
		);
		// Clamp to a sane range; an admin could store anything via IAppConfig.
		return $this->clampInt(self::KEY_WEEKLY_GOAL_TARGET_PERCENT, $value, 1, 100);
	}

	/**
	 * Whether the teamwork bonus is enabled.
	 */
	public function getTeamworkBonusEnabled(): bool {
		return $this->config->getValueString(
			self::APP_NAME,
			self::KEY_TEAMWORK_BONUS_ENABLED,
			'1'
		) === '1';
	}

	/**
	 * Get the teamwork bonus cap as a percentage of the base reward (0-100).
	 */
	public function getTeamworkBonusMaxPct(): int {
		$value = (int)$this->config->getValueString(
			self::APP_NAME,
			self::KEY_TEAMWORK_BONUS_MAX_PCT,
			'50'
		);
		return $this->clampInt(self::KEY_TEAMWORK_BONUS_MAX_PCT, $value, 0, 100);
	}

	/**
	 * Whether the role-title system is enabled (CS-PLAN-006 feature flag).
	 *
	 * Wraps the title unlock-detection in GameLogicService so an aggressive
	 * celebration cadence can be disabled without a redeploy. Default true.
	 */
	public function getTitlesEnabled(): bool {
		return $this->config->getValueString(
			self::APP_NAME,
			self::KEY_TITLES_ENABLED,
			'1'
		) === '1';
	}

	/**
	 * Set whether the role-title system is enabled.
	 */
	public function setTitlesEnabled(bool $enabled): void {
		$this->config->setValueString(
			self::APP_NAME,
			self::KEY_TITLES_ENABLED,
			$enabled ? '1' : '0'
		);
	}

	/**
	 * Get the unlock threshold for a specific title tier, honouring any
	 * per-house override stored via IAppConfig. Falls back to the catalog
	 * default. Clamped to >= 1 so a misconfigured threshold can never make
	 * a tier unreachable.
	 *
	 * @param string $packKey Pack key (validated by the catalog lookup)
	 * @param int $tierIndex 0-based tier index within the pack
	 */
	public function getTitleThreshold(string $packKey, int $tierIndex): int {
		$tiers = TitleCatalog::getTiers($packKey);
		$default = $tiers[$tierIndex]['threshold'] ?? 1;
		$key = $this->thresholdKey($packKey, $tierIndex);
		$value = (int)$this->config->getValueString(
			self::APP_NAME,
			$key,
			(string)$default
		);
		return $this->clampInt($key, $value, 1);
	}

	/**
	 * Set the unlock threshold for a specific title tier.
	 *
	 * @param string $packKey Pack key
	 * @param int $tierIndex 0-based tier index within the pack
	 * @param int $value Threshold (clamped to >= 1)
	 */
	public function setTitleThreshold(string $packKey, int $tierIndex, int $value): void {
		$key = $this->thresholdKey($packKey, $tierIndex);
		$this->config->setValueString(
			self::APP_NAME,
			$key,
			(string)$this->clampInt($key, $value, 1)
		);
	}

	/**
	 * Build the IAppConfig key for a tier threshold.
	 */
	private function thresholdKey(string $packKey, int $tierIndex): string {
		return self::KEY_TITLE_THRESHOLD_PREFIX . '_' . $packKey . '_' . $tierIndex;
	}

	/**
	 * Set streak freezes per week
	 *
	 * @param int $count Clamped to >= 0 (CR-SVC-19)
	 */
	public function setStreakFreezesPerWeek(int $count): void {
		$this->config->setValueString(
			self::APP_NAME,
			self::KEY_STREAK_FREEZES_PER_WEEK,
			(string)$this->clampInt(self::KEY_STREAK_FREEZES_PER_WEEK, $count, 0)
		);
	}

	/**
	 * Set streak freeze cap
	 *
	 * @param int $cap Clamped to >= 0 (CR-SVC-19)
	 */
	public function setStreakFreezeCap(int $cap): void {
		$this->config->setValueString(
			self::APP_NAME,
			self::KEY_STREAK_FREEZE_CAP,
			(string)$this->clampInt(self::KEY_STREAK_FREEZE_CAP, $cap, 0)
		);
	}

	/**
	 * Set combo bonus freeze threshold
	 *
	 * @param int $threshold Clamped to >= 0 (CR-SVC-19)
	 */
	public function setComboBonusFreezeThreshold(int $threshold): void {
		$this->config->setValueString(
			self::APP_NAME,
			self::KEY_COMBO_BONUS_FREEZE_THRESHOLD,
			(string)$this->clampInt(self::KEY_COMBO_BONUS_FREEZE_THRESHOLD, $threshold, 0)
		);
	}

	/**
	 * Get the combo multiplier applied when a user has an active combo.
	 *
	 * Default 2.0 (doubles coins). Clamped to >= 0 to forbid a nonsensical
	 * negative value that would invert combo rewards.
	 */
	public function getComboMultiplier(): float {
		$value = (float)$this->config->getValueString(
			self::APP_NAME,
			self::KEY_COMBO_MULTIPLIER,
			'2.0'
		);
		return $this->clampFloat(self::KEY_COMBO_MULTIPLIER, $value, 0.0);
	}

	/**
	 * Set the combo multiplier.
	 *
	 * @param float $multiplier Clamped to >= 0
	 */
	public function setComboMultiplier(float $multiplier): void {
		$this->config->setValueString(
			self::APP_NAME,
			self::KEY_COMBO_MULTIPLIER,
			(string)$this->clampFloat(self::KEY_COMBO_MULTIPLIER, $multiplier, 0.0)
		);
	}

	/**
	 * Set weekly household goal target percent
	 *
	 * @param int $percent Target percent (clamped to 1-100)
	 */
	public function setWeeklyGoalTargetPercent(int $percent): void {
		$this->config->setValueString(
			self::APP_NAME,
			self::KEY_WEEKLY_GOAL_TARGET_PERCENT,
			(string)$this->clampInt(self::KEY_WEEKLY_GOAL_TARGET_PERCENT, $percent, 1, 100)
		);
	}

	/**
	 * Set whether the teamwork bonus is enabled.
	 */
	public function setTeamworkBonusEnabled(bool $enabled): void {
		$this->config->setValueString(
			self::APP_NAME,
			self::KEY_TEAMWORK_BONUS_ENABLED,
			$enabled ? '1' : '0'
		);
	}

	/**
	 * Set the teamwork bonus cap as a percentage of the base reward.
	 *
	 * @param int $pct Cap percent (clamped to 0-100)
	 */
	public function setTeamworkBonusMaxPct(int $pct): void {
		$this->config->setValueString(
			self::APP_NAME,
			self::KEY_TEAMWORK_BONUS_MAX_PCT,
			(string)$this->clampInt(self::KEY_TEAMWORK_BONUS_MAX_PCT, $pct, 0, 100)
		);
	}

	/**
	 * Set tier multiplier
	 *
	 * @param string $tier The tier (bronze, silver, gold)
	 * @param float $value The multiplier value (clamped to >= 0, CR-SVC-19)
	 */
	public function setTierMultiplier(string $tier, float $value): void {
		$key = match ($tier) {
			'bronze' => self::KEY_TIER_BRONZE_MULTIPLIER,
			'silver' => self::KEY_TIER_SILVER_MULTIPLIER,
			'gold' => self::KEY_TIER_GOLD_MULTIPLIER,
			default => null,
		};

		if ($key !== null) {
			$this->config->setValueString(self::APP_NAME, $key, (string)$this->clampFloat($key, $value, 0.0));
		}
	}

	/**
	 * Set early bonus threshold days
	 *
	 * @param int $days Clamped to >= 0 (CR-SVC-19)
	 */
	public function setEarlyBonusThresholdDays(int $days): void {
		$this->config->setValueString(
			self::APP_NAME,
			self::KEY_EARLY_BONUS_THRESHOLD_DAYS,
			(string)$this->clampInt(self::KEY_EARLY_BONUS_THRESHOLD_DAYS, $days, 0)
		);
	}

	/**
	 * Set early bonus multiplier
	 *
	 * @param float $multiplier Clamped to >= 1.0 (CR-SVC-19)
	 */
	public function setEarlyBonusMultiplier(float $multiplier): void {
		$this->config->setValueString(
			self::APP_NAME,
			self::KEY_EARLY_BONUS_MULTIPLIER,
			(string)$this->clampFloat(self::KEY_EARLY_BONUS_MULTIPLIER, $multiplier, 1.0)
		);
	}

	/**
	 * Set max energy per task
	 *
	 * @param int $max Clamped to >= 0 (CR-SVC-19)
	 */
	public function setEnergyMaxPerTask(int $max): void {
		$this->config->setValueString(
			self::APP_NAME,
			self::KEY_ENERGY_MAX_PER_TASK,
			(string)$this->clampInt(self::KEY_ENERGY_MAX_PER_TASK, $max, 0)
		);
	}

	/**
	 * Set base coins per energy
	 *
	 * @param int $base Clamped to >= 0 (CR-SVC-19)
	 */
	public function setCoinBasePerEnergy(int $base): void {
		$this->config->setValueString(
			self::APP_NAME,
			self::KEY_COIN_BASE_PER_ENERGY,
			(string)$this->clampInt(self::KEY_COIN_BASE_PER_ENERGY, $base, 0)
		);
	}

	/**
	 * Get all config values as array
	 */
	public function getAllConfig(): array {
		return [
			'tierBronzeMultiplier' => $this->getTierMultiplier('bronze'),
			'tierSilverMultiplier' => $this->getTierMultiplier('silver'),
			'tierGoldMultiplier' => $this->getTierMultiplier('gold'),
			'earlyBonusThresholdDays' => $this->getEarlyBonusThresholdDays(),
			'earlyBonusMultiplier' => $this->getEarlyBonusMultiplier(),
			'energyMaxPerTask' => $this->getEnergyMaxPerTask(),
			'coinBasePerEnergy' => $this->getCoinBasePerEnergy(),
			'streakFreezesPerWeek' => $this->getStreakFreezesPerWeek(),
			'streakFreezeCap' => $this->getStreakFreezeCap(),
			'comboBonusFreezeThreshold' => $this->getComboBonusFreezeThreshold(),
			'comboMultiplier' => $this->getComboMultiplier(),
			'weeklyGoalTargetPercent' => $this->getWeeklyGoalTargetPercent(),
			'teamworkBonusEnabled' => $this->getTeamworkBonusEnabled(),
			'teamworkBonusMaxPct' => $this->getTeamworkBonusMaxPct(),
			'titlesEnabled' => $this->getTitlesEnabled(),
			'titleThresholds' => $this->getAllTitleThresholds(),
		];
	}

	/**
	 * Update multiple config values at once
	 *
	 * @param array $values Key-value pairs to update
	 */
	public function updateConfig(array $values): void {
		if (isset($values['tierBronzeMultiplier'])) {
			$this->setTierMultiplier('bronze', (float)$values['tierBronzeMultiplier']);
		}
		if (isset($values['tierSilverMultiplier'])) {
			$this->setTierMultiplier('silver', (float)$values['tierSilverMultiplier']);
		}
		if (isset($values['tierGoldMultiplier'])) {
			$this->setTierMultiplier('gold', (float)$values['tierGoldMultiplier']);
		}
		if (isset($values['earlyBonusThresholdDays'])) {
			$this->setEarlyBonusThresholdDays((int)$values['earlyBonusThresholdDays']);
		}
		if (isset($values['earlyBonusMultiplier'])) {
			$this->setEarlyBonusMultiplier((float)$values['earlyBonusMultiplier']);
		}
		if (isset($values['energyMaxPerTask'])) {
			$this->setEnergyMaxPerTask((int)$values['energyMaxPerTask']);
		}
		if (isset($values['coinBasePerEnergy'])) {
			$this->setCoinBasePerEnergy((int)$values['coinBasePerEnergy']);
		}
		if (isset($values['streakFreezesPerWeek'])) {
			$this->setStreakFreezesPerWeek((int)$values['streakFreezesPerWeek']);
		}
		if (isset($values['streakFreezeCap'])) {
			$this->setStreakFreezeCap((int)$values['streakFreezeCap']);
		}
		if (isset($values['comboBonusFreezeThreshold'])) {
			$this->setComboBonusFreezeThreshold((int)$values['comboBonusFreezeThreshold']);
		}
		if (isset($values['comboMultiplier'])) {
			$this->setComboMultiplier((float)$values['comboMultiplier']);
		}
		if (isset($values['weeklyGoalTargetPercent'])) {
			$this->setWeeklyGoalTargetPercent((int)$values['weeklyGoalTargetPercent']);
		}
		if (isset($values['teamworkBonusEnabled'])) {
			$this->setTeamworkBonusEnabled((bool)$values['teamworkBonusEnabled']);
		}
		if (isset($values['teamworkBonusMaxPct'])) {
			$this->setTeamworkBonusMaxPct((int)$values['teamworkBonusMaxPct']);
		}
		if (isset($values['titlesEnabled'])) {
			$this->setTitlesEnabled((bool)$values['titlesEnabled']);
		}
		if (isset($values['titleThresholds']) && is_array($values['titleThresholds'])) {
			$this->applyTitleThresholds($values['titleThresholds']);
		}
	}

	/**
	 * Collect every tier threshold as a nested map: pack → [tier0, tier1, tier2].
	 *
	 * @return array<string, int[]>
	 */
	private function getAllTitleThresholds(): array {
		$result = [];
		foreach (TitleCatalog::getPackKeys() as $packKey) {
			$result[$packKey] = [];
			foreach (TitleCatalog::getTiers($packKey) as $index => $tier) {
				$result[$packKey][] = $this->getTitleThreshold($packKey, $index);
			}
		}
		return $result;
	}

	/**
	 * Apply a nested `titleThresholds` payload (pack → tier-index → value).
	 * Unknown packs / tier indices are skipped so a malformed payload can't
	 * poison the config. Thresholds within a pack MUST stay strictly
	 * ascending — a non-ascending set is rejected before anything is written
	 * so getCurrentTitle()'s "last qualifying tier = highest" assumption
	 * can never break silently.
	 *
	 * @param array<string, array<int|string, int>> $thresholds
	 *
	 * @throws \InvalidArgumentException When a pack's provided thresholds are not strictly ascending.
	 */
	private function applyTitleThresholds(array $thresholds): void {
		foreach ($thresholds as $packKey => $tierValues) {
			if (!is_array($tierValues) || !TitleCatalog::isValidPack((string)$packKey)) {
				continue;
			}
			$byIndex = [];
			foreach ($tierValues as $index => $value) {
				if (!is_numeric($value) || !is_numeric($index)) {
					continue;
				}
				$byIndex[(int)$index] = max(1, (int)$value);
			}
			$this->assertAscendingThresholds((string)$packKey, $byIndex);
			foreach ($byIndex as $index => $value) {
				$this->setTitleThreshold((string)$packKey, $index, $value);
			}
		}
	}

	/**
	 * Reject a non-strictly-ascending set of tier thresholds for a pack.
	 * Only the tiers present in `$byIndex` are compared (gaps are allowed),
	 * so a partial payload that is ascending among itself still validates.
	 *
	 * @param string $packKey Pack key
	 * @param array<int, int> $byIndex tier index → clamped value
	 *
	 * @throws \InvalidArgumentException When two provided tiers are not strictly ascending.
	 */
	private function assertAscendingThresholds(string $packKey, array $byIndex): void {
		$tierCount = count(TitleCatalog::getTiers($packKey));
		$previous = null;
		for ($i = 0; $i < $tierCount; $i++) {
			if (!isset($byIndex[$i])) {
				continue;
			}
			$value = $byIndex[$i];
			if ($previous !== null && $value <= $previous) {
				throw new \InvalidArgumentException(
					'Title thresholds must be strictly ascending within a pack; '
					. $packKey . ' tier ' . $i . ' (' . $value . ') is not greater than tier '
					. ($i - 1) . ' (' . $previous . ')'
				);
			}
			$previous = $value;
		}
	}
}
