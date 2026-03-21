<?php
/**
 * Mock for OCP\IAppConfig
 */
namespace OCP;

interface IAppConfig
{
    public function getValueString(string $app, string $key, string $default = ''): string;
    public function setValueString(string $app, string $key, string $value): void;
    public function getValueInt(string $app, string $key, int $default = 0): int;
    public function setValueInt(string $app, string $key, int $value): void;
    public function getValueFloat(string $app, string $key, float $default = 0): float;
    public function setValueFloat(string $app, string $key, float $value): void;
    public function getValueBool(string $app, string $key, bool $default = false): bool;
    public function setValueBool(string $app, string $key, bool $value): void;
    public function getAppKeys(string $app): array;
    public function deleteKey(string $app, string $key): void;
    public function deleteApp(string $app): void;
}
