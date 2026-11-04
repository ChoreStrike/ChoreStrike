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
 * What remains: the title system flag and the arcade unlock gate
 * (CS-PLAN-086 D-086-02).
 */
class RewardConfigService {
	private const APP_NAME = 'chorestrike';

	// Config keys with defaults
	public const KEY_TITLES_ENABLED = 'titles_enabled';
	public const KEY_ARCADE_GATE_ENABLED = 'arcade_gate_enabled';

	/**
	 * Single source of truth for every config flag (CR-SVC-16): IAppConfig
	 * key => spec with `default` (stored string) and the camelCase
	 * `apiField` used by getAllConfig()/updateConfig().
	 *
	 * Insertion order is the public getAllConfig() field order.
	 */
	private const REGISTRY = [
		self::KEY_TITLES_ENABLED => ['default' => '1', 'apiField' => 'titlesEnabled'],
		self::KEY_ARCADE_GATE_ENABLED => ['default' => '1', 'apiField' => 'arcadeGateEnabled'],
	];

	private IAppConfig $config;

	public function __construct(IAppConfig $config) {
		$this->config = $config;
	}

	/**
	 * Read a flag: the stored string, or the registry default when unset.
	 */
	private function readSpec(string $key): bool {
		return $this->config->getValueString(self::APP_NAME, $key, self::REGISTRY[$key]['default']) === '1';
	}

	/**
	 * Write a flag as a stored string.
	 *
	 * @param mixed $value Raw input value (client payload or typed setter arg)
	 */
	private function writeSpec(string $key, $value): void {
		$this->config->setValueString(self::APP_NAME, $key, $value ? '1' : '0');
	}

	/**
	 * Whether the role-title system is enabled (CS-PLAN-006 feature flag).
	 *
	 * Wraps the retained recognition unlock/reconcile path so an aggressive
	 * celebration cadence can be disabled without a redeploy. Default true.
	 */
	public function getTitlesEnabled(): bool {
		return $this->readSpec(self::KEY_TITLES_ENABLED);
	}

	/**
	 * Whether arcade games unlock through finished chores (CS-PLAN-086
	 * D-086-02). Default true; an owner switches it off house-wide — there
	 * is one house per instance, so an app-config flag needs no schema.
	 */
	public function getArcadeGateEnabled(): bool {
		return $this->readSpec(self::KEY_ARCADE_GATE_ENABLED);
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
