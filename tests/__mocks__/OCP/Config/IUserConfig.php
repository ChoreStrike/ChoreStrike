<?php

/**
 * Mock for OCP\Config\IUserConfig (Nextcloud 32+), limited to the methods
 * ChoreStrike calls.
 */

namespace OCP\Config;

interface IUserConfig {
	public function getValueArray(string $userId, string $app, string $key, array $default = [], bool $lazy = false): array;
	public function setValueArray(string $userId, string $app, string $key, array $value, bool $lazy = false, int $flags = 0): bool;
	public function getValuesByUsers(string $app, string $key, ?ValueType $typedAs = null, ?array $userIds = null): array;
	public function deleteUserConfig(string $userId, string $app, string $key): void;
	public function deleteKey(string $app, string $key): void;
	public function clearCache(string $userId, bool $reload = false): void;
}
