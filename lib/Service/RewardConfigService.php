<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCP\IAppConfig;

/**
 * Service for managing reward system configuration.
 *
 * CS-PLAN-070 WP3 cutover: every earning multiplier, Coin-base, freeze and
 * combo key is retired — money is wallet minor units at the FROZEN rate
 * (EffortAllocationService::COIN_MINOR_PER_ENERGY), recognition is the
 * valid-history rhythm. CS-PLAN-070 WP4 cutover: the weekly-goal target
 * (fixed 80%) and the per-tier title thresholds (fixed 3/6/12) are fixed
 * policy too — no owner override exists for the first-slice rewards.
 * What remains: task-energy validation, the title system flag and the
 * arcade unlock gate (CS-PLAN-086 D-086-02).
 */
class RewardConfigService {
	private const APP_NAME = 'chorestrike';

	// Config keys with defaults
	public const KEY_ENERGY_MAX_PER_TASK = 'energy_max_per_task';
	public const KEY_TITLES_ENABLED = 'titles_enabled';
	public const KEY_ARCADE_GATE_ENABLED = 'arcade_gate_enabled';

	/**
	 * Single source of truth for every scalar config key (CR-SVC-16):
	 * IAppConfig key => spec with `type` ('int'|'float'|'bool'), `default`
	 * (stored string), `min`/`max` clamp bounds (null = unbounded), and the
	 * camelCase `apiField` used by getAllConfig()/updateConfig().
	 *
	 * Insertion order is the public getAllConfig() field order.
	 */
	private const REGISTRY = [
		self::KEY_ENERGY_MAX_PER_TASK => ['type' => 'int', 'default' => '9', 'min' => 0, 'max' => null, 'apiField' => 'energyMaxPerTask'],
		self::KEY_TITLES_ENABLED => ['type' => 'bool', 'default' => '1', 'min' => null, 'max' => null, 'apiField' => 'titlesEnabled'],
		self::KEY_ARCADE_GATE_ENABLED => ['type' => 'bool', 'default' => '1', 'min' => null, 'max' => null, 'apiField' => 'arcadeGateEnabled'],
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
	 * Whether the role-title system is enabled (CS-PLAN-006 feature flag).
	 *
	 * Wraps the retained recognition unlock/reconcile path so an aggressive
	 * celebration cadence can be disabled without a redeploy. Default true.
	 */
	public function getTitlesEnabled(): bool {
		return $this->readSpec(self::KEY_TITLES_ENABLED) === true;
	}

	/**
	 * Whether arcade games unlock through finished chores (CS-PLAN-086
	 * D-086-02). Default true; an owner switches it off house-wide — there
	 * is one house per instance, so an app-config flag needs no schema.
	 */
	public function getArcadeGateEnabled(): bool {
		return $this->readSpec(self::KEY_ARCADE_GATE_ENABLED) === true;
	}

	/**
	 * Set max energy per task (clamped to >= 0, CR-SVC-19).
	 */
	public function setEnergyMaxPerTask(int $max): void {
		$this->writeSpec(self::KEY_ENERGY_MAX_PER_TASK, $max);
	}

	/**
	 * Set whether the role-title system is enabled.
	 */
	public function setTitlesEnabled(bool $enabled): void {
		$this->writeSpec(self::KEY_TITLES_ENABLED, $enabled);
	}

	/**
	 * Set the arcade unlock gate. The only caller is the owner-checked
	 * {@see ArcadeAccessService::setGateEnabled()}.
	 */
	public function setArcadeGateEnabled(bool $enabled): void {
		$this->writeSpec(self::KEY_ARCADE_GATE_ENABLED, $enabled);
	}

	/**
	 * Drop the stored arcade gate, so it reads its default again. The only
	 * caller is the test-mode reset ({@see TestDataService::reset()}).
	 */
	public function resetArcadeGateEnabled(): void {
		$this->config->deleteKey(self::APP_NAME, self::KEY_ARCADE_GATE_ENABLED);
	}

	/**
	 * Get all config values as array (registry order).
	 */
	public function getAllConfig(): array {
		$result = [];
		foreach (self::REGISTRY as $key => $spec) {
			$result[$spec['apiField']] = $this->readSpec($key);
		}
		return $result;
	}

	/**
	 * Update multiple config values at once.
	 *
	 * Explicit `null` values are skipped (isset semantics) and unknown fields
	 * are ignored.
	 *
	 * @param array $values Key-value pairs to update
	 */
	public function updateConfig(array $values): void {
		foreach (self::REGISTRY as $key => $spec) {
			if (isset($values[$spec['apiField']])) {
				$this->writeSpec($key, $values[$spec['apiField']]);
			}
		}
	}
}
