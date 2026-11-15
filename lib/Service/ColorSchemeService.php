<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\HouseUser;
use OCP\Config\IUserConfig;
use OCP\IL10N;

/**
 * A person's colour scheme (CS-PLAN-090 WP-K, FEAT-UX-057, D-090-61/64/67).
 *
 * The scheme changes the accent colours inside the app for that person on
 * every device; it is a setting, not a fact. It lives in the person's
 * per-user app config (no schema, Nextcloud deletes it with the account).
 * *Standard* keeps the colour of the Nextcloud instance and is stored as no
 * value, so an instance that never stored one reads it without a migration.
 * The keys match the palettes of `src/styles/schemes.css`.
 */
class ColorSchemeService {
	public const KEY = 'color_scheme';
	public const DEFAULT = 'standard';
	/** @var list<string> */
	public const SCHEMES = ['standard', 'ocean', 'forest', 'sunset', 'berry', 'slate'];

	private const APP_NAME = 'chorestrike';

	public function __construct(
		private IUserConfig $userConfig,
		private IL10N $l,
	) {
	}

	/**
	 * The stored scheme of a person; an unknown stored value reads as
	 * *Standard*.
	 */
	public function get(string $userId): string {
		$value = $this->userConfig->getValueString($userId, self::APP_NAME, self::KEY, self::DEFAULT);
		return in_array($value, self::SCHEMES, true) ? $value : self::DEFAULT;
	}

	/**
	 * Set the caller's own scheme. The actor comes from the membership
	 * gate, so no route can name another person.
	 *
	 * @param HouseUser $actor The calling member
	 * @param mixed $scheme Raw client value
	 * @return string The stored scheme
	 * @throws \InvalidArgumentException for anything but a listed scheme, before any write
	 */
	public function setOwn(HouseUser $actor, mixed $scheme): string {
		if (!is_string($scheme) || !in_array($scheme, self::SCHEMES, true)) {
			throw new \InvalidArgumentException($this->l->t('Unknown colour scheme'));
		}
		$userId = (string)$actor->getNextcloudUserId();
		if ($scheme === self::DEFAULT) {
			$this->userConfig->deleteUserConfig($userId, self::APP_NAME, self::KEY);
		} else {
			$this->userConfig->setValueString($userId, self::APP_NAME, self::KEY, $scheme);
		}
		return $scheme;
	}

	/** Delete one person's scheme (account erasure). */
	public function forget(string $userId): void {
		$this->userConfig->deleteUserConfig($userId, self::APP_NAME, self::KEY);
	}

	/** Delete every person's scheme (test-mode reset only). */
	public function forgetAll(): void {
		$this->userConfig->deleteKey(self::APP_NAME, self::KEY);
	}
}
