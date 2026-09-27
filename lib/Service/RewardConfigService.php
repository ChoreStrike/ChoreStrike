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
 * Service for managing reward system configuration.
 *
 * CS-PLAN-070 WP3 cutover: every earning multiplier, Coin-base, freeze and
 * combo key is retired — money is wallet minor units at the FROZEN rate
 * (EffortAllocationService::COIN_MINOR_PER_ENERGY), recognition is the
 * valid-history rhythm. What remains: task-energy validation, the weekly
 * goal target and the title system flag/thresholds.
 */
class RewardConfigService {
	private const APP_NAME = 'chorestrike';

	// Config keys with defaults
	public const KEY_ENERGY_MAX_PER_TASK = 'energy_max_per_task';
	public const KEY_WEEKLY_GOAL_TARGET_PERCENT = 'weekly_goal_target_percent';
	public const KEY_TITLES_ENABLED = 'titles_enabled';
	/** Prefix for per-tier title thresholds; full key is `{prefix}_{pack}_{tierIndex}`. */
	public const KEY_TITLE_THRESHOLD_PREFIX = 'title_threshold';

	/**
	 * Single source of truth for every scalar config key (CR-SVC-16):
	 * IAppConfig key => spec with `type` ('int'|'float'|'bool'), `default`
	 * (stored string), `min`/`max` clamp bounds (null = unbounded), and the
	 * camelCase `apiField` used by getAllConfig()/updateConfig().
	 *
	 * Insertion order is the public getAllConfig() field order.
	 *
	 * Per-tier title thresholds are intentionally NOT registered here — they
	 * are dynamic per-pack/tier keys with a TitleCatalog fallback (see the
	 * *TitleThreshold methods).
	 */
	private const REGISTRY = [
		self::KEY_ENERGY_MAX_PER_TASK => ['type' => 'int', 'default' => '9', 'min' => 0, 'max' => null, 'apiField' => 'energyMaxPerTask'],
		self::KEY_WEEKLY_GOAL_TARGET_PERCENT => ['type' => 'int', 'default' => '80', 'min' => 1, 'max' => 100, 'apiField' => 'weeklyGoalTargetPercent'],
		self::KEY_TITLES_ENABLED => ['type' => 'bool', 'default' => '1', 'min' => null, 'max' => null, 'apiField' => 'titlesEnabled'],
	];

	private IAppConfig $config;
	private AppLogger $appLogger;

	public function __construct(IAppConfig $config, AppLogger $appLogger) {
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

		$this->appLogger->warning('Reward config value clamped', [
			'service' => 'RewardConfigService',
			'key' => $key,
			'value' => $value,
			'clampedTo' => $clamped,
		]);
	}

	/**
	 * Read a registry key: fetch the stored string (registry default when
	 * unset), cast it to the spec type, and clamp numeric values into the
	 * spec bounds (CR-SVC-19).
	 *
	 * @return int|float|bool The typed and clamped value
	 */
	private function readSpec(string $key): int|float|bool {
		$spec = self::REGISTRY[$key];
		$raw = $this->config->getValueString(self::APP_NAME, $key, $spec['default']);
		switch ($spec['type']) {
			case 'int':
				return $this->clampInt($key, (int)$raw, $spec['min'], $spec['max']);
			case 'float':
				return $this->clampFloat($key, (float)$raw, $spec['min'], $spec['max']);
			default:
				return $raw === '1';
		}
	}

	/**
	 * Write a registry key: cast the value to the spec type, clamp numeric
	 * values into the spec bounds (CR-SVC-19), and persist as a string.
	 *
	 * @param mixed $value Raw input value (client payload or typed setter arg)
	 */
	private function writeSpec(string $key, $value): void {
		$spec = self::REGISTRY[$key];
		switch ($spec['type']) {
			case 'int':
				$this->config->setValueString(
					self::APP_NAME,
					$key,
					(string)$this->clampInt($key, (int)$value, $spec['min'], $spec['max'])
				);
				return;
			case 'float':
				$this->config->setValueString(
					self::APP_NAME,
					$key,
					(string)$this->clampFloat($key, (float)$value, $spec['min'], $spec['max'])
				);
				return;
			default:
				$this->config->setValueString(self::APP_NAME, $key, $value ? '1' : '0');
				return;
		}
	}

	/**
	 * Get maximum energy per task (clamped to >= 0, CR-SVC-19).
	 */
	public function getEnergyMaxPerTask(): int {
		return (int)$this->readSpec(self::KEY_ENERGY_MAX_PER_TASK);
	}

	/**
	 * Get the weekly household goal completion target as a percent (1-100).
	 *
	 * The household "reaches" its weekly goal when the share of due tasks
	 * completed this week is at least this percent. Default 80 (matches the
	 * Phase 3 plan recommendation: forgiving enough for family life).
	 */
	public function getWeeklyGoalTargetPercent(): int {
		return (int)$this->readSpec(self::KEY_WEEKLY_GOAL_TARGET_PERCENT);
	}

	/**
	 * Whether the role-title system is enabled (CS-PLAN-006 feature flag).
	 *
	 * Wraps the retained recognition unlock/reconcile path so an aggressive
	 * celebration cadence can be disabled without a redeploy. Default true.
	 */
	public function getTitlesEnabled(): bool {
		return $this->readSpec(self::KEY_TITLES_ENABLED) === true;
	}

	/**
	 * Set max energy per task (clamped to >= 0, CR-SVC-19).
	 */
	public function setEnergyMaxPerTask(int $max): void {
		$this->writeSpec(self::KEY_ENERGY_MAX_PER_TASK, $max);
	}

	/**
	 * Set weekly household goal target percent (clamped to 1-100).
	 */
	public function setWeeklyGoalTargetPercent(int $percent): void {
		$this->writeSpec(self::KEY_WEEKLY_GOAL_TARGET_PERCENT, $percent);
	}

	/**
	 * Set whether the role-title system is enabled.
	 */
	public function setTitlesEnabled(bool $enabled): void {
		$this->writeSpec(self::KEY_TITLES_ENABLED, $enabled);
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
	 * Get all config values as array (registry order + nested titleThresholds).
	 */
	public function getAllConfig(): array {
		$result = [];
		foreach (self::REGISTRY as $key => $spec) {
			$result[$spec['apiField']] = $this->readSpec($key);
		}
		$result['titleThresholds'] = $this->getAllTitleThresholds();
		return $result;
	}

	/**
	 * Update multiple config values at once.
	 *
	 * Explicit `null` values are skipped (isset semantics) and unknown fields
	 * are ignored; `titleThresholds` keeps its dedicated nested handling.
	 *
	 * @param array $values Key-value pairs to update
	 */
	public function updateConfig(array $values): void {
		foreach (self::REGISTRY as $key => $spec) {
			if (isset($values[$spec['apiField']])) {
				$this->writeSpec($key, $values[$spec['apiField']]);
			}
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
