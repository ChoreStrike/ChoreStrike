<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCP\Config\IUserConfig;
use OCP\Config\ValueType;

/**
 * Per-member arcade start points (CS-PLAN-090 WP-J, D-090-64/66, ADR-019
 * decision 8).
 *
 * An owner's reset stores the highest committed completion id at that
 * moment (`after`) and the time of the reset (`at`, Unix seconds) in the
 * member's per-user app config; the arcade count then takes only
 * completions above `after`. It is a setting, not a fact: no schema, and
 * Nextcloud deletes it with the account. Reads go past the config cache
 * (`getValuesByUsers()`), so a score save under the house-row lock sees a
 * reset that committed just before it.
 */
class ArcadeStartPointStore {
	public const KEY = 'arcade_reset';

	private const APP_NAME = 'chorestrike';

	public function __construct(
		private IUserConfig $userConfig,
	) {
	}

	/**
	 * The stored start points of the given members, read without cache.
	 *
	 * @param list<string> $userIds Nextcloud user ids
	 * @return array<string, array{after: int, at: int}> Only members with a valid stored value
	 */
	public function forUsers(array $userIds): array {
		$userIds = array_values(array_unique(array_filter($userIds, static fn (string $id): bool => $id !== '')));
		if ($userIds === []) {
			return [];
		}

		$points = [];
		foreach ($this->userConfig->getValuesByUsers(self::APP_NAME, self::KEY, ValueType::ARRAY, $userIds) as $userId => $value) {
			$after = is_array($value) ? ($value['after'] ?? null) : null;
			$at = is_array($value) ? ($value['at'] ?? null) : null;
			if (is_int($after) && $after >= 0 && is_int($at)) {
				$points[(string)$userId] = ['after' => $after, 'at' => $at];
			}
		}
		return $points;
	}

	/**
	 * Store a member's start point (lazy, so it never loads with the
	 * member's other settings).
	 */
	public function set(string $userId, int $afterCompletionId, int $at): void {
		$this->userConfig->setValueArray($userId, self::APP_NAME, self::KEY, ['after' => $afterCompletionId, 'at' => $at], true);
	}

	/** Delete one member's start point (account erasure). */
	public function forget(string $userId): void {
		$this->userConfig->deleteUserConfig($userId, self::APP_NAME, self::KEY);
	}

	/** Delete every member's start point (test-mode reset only). */
	public function forgetAll(): void {
		$this->userConfig->deleteKey(self::APP_NAME, self::KEY);
	}
}
