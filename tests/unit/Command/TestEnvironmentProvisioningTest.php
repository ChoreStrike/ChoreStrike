<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Command;

use PHPUnit\Framework\TestCase;

class TestEnvironmentProvisioningTest extends TestCase {
	/** @dataProvider providerSettings */
	public function testValidateOnly_ProviderSettings_AcceptsAllInstalledDrivers(string $provider, array $settings): void {
		[$exit, $output] = $this->validate(['TEST_DB_TYPE' => $provider] + $settings);
		self::assertSame(0, $exit, $output);
		self::assertStringContainsString('driver validated: ' . $provider, $output);
	}

	public static function providerSettings(): array {
		$network = ['DB_HOST' => 'unused', 'DB_PORT' => '5432', 'DB_NAME' => 'nextcloud', 'DB_USER' => 'nextcloud', 'DB_PASS' => 'synthetic-test-only'];
		return [['mysql', $network], ['pgsql', $network], ['sqlite', ['DB_HOST' => '', 'DB_PORT' => '']]];
	}

	/** @dataProvider invalidSettings */
	public function testValidateOnly_InvalidSettings_RefusesBeforeInstallation(array $settings, string $message): void {
		[$exit, $output] = $this->validate($settings);
		self::assertSame(1, $exit, $output);
		self::assertStringContainsString($message, $output);
		self::assertStringNotContainsString('Installing Composer', $output);
		self::assertStringNotContainsString('Running initial Nextcloud', $output);
	}

	public static function invalidSettings(): array {
		return [
			[['TEST_DB_TYPE' => 'postgres'], 'Unsupported TEST_DB_TYPE'],
			[['TEST_DB_TYPE' => 'mysql;echo unsafe'], 'Unsupported TEST_DB_TYPE'],
			[['TEST_DB_PROFILE' => 'unknown'], 'Unsupported TEST_DB_PROFILE'],
			[['DB_HOST' => ''], 'Required database setting is missing: DB_HOST'],
			[['DB_PASS' => ''], 'Required database setting is missing: DB_PASS'],
			[['DB_PORT' => 'abc'], 'DB_PORT must be an integer'],
			[['DB_PORT' => '0'], 'DB_PORT must be an integer'],
			[['DB_PORT' => '65536'], 'DB_PORT must be an integer'],
			[['TEST_RUNTIME_INTERNAL_VERSION' => '35.0.1.1'], 'Both frozen runtime versions are required'],
			[['TEST_RUNTIME_PHP_VERSION' => '8.4.25'], 'Both frozen runtime versions are required'],
			[['TEST_RUNTIME_INTERNAL_VERSION' => 'stable35', 'TEST_RUNTIME_PHP_VERSION' => '8.4.25'], 'Both frozen runtime versions are required'],
			[['REDIS_HOST' => 'redis;echo unsafe'], 'REDIS_HOST must be a plain host name'],
			[['REDIS_HOST' => 'tcp://redis'], 'REDIS_HOST must be a plain host name'],
			[['REDIS_HOST' => 'redis', 'REDIS_PORT' => '0'], 'REDIS_PORT an integer'],
			[['REDIS_HOST' => 'redis', 'REDIS_PORT' => 'abc'], 'REDIS_PORT an integer'],
		];
	}

	/** @dataProvider redisOutcomes */
	public function testProvisioning_RedisRow_ConfiguresSessionsCacheAndLocking(string $scenario, int $expectedExit, string $message): void {
		$directory = sys_get_temp_dir() . '/cs-provision-' . bin2hex(random_bytes(8));
		self::assertTrue(mkdir($directory . '/conf.d', 0700, true));
		$commands = ['php', 'su', 'composer', 'apache2-foreground', 'curl', 'sleep', 'rm', 'touch', 'bash'];
		$ini = $directory . '/conf.d/zz-chorestrike-redis-session.ini';
		try {
			foreach ($commands as $command) {
				self::assertTrue(copy(__DIR__ . '/../../fixtures/provision-command.sh', $directory . '/' . $command));
				self::assertTrue(chmod($directory . '/' . $command, 0700));
			}
			[$exit, $output] = $this->runEntrypoint([
				'PATH' => $directory . ':' . getenv('PATH'),
				'PHP_INI_DIR' => $directory,
				'PROVISION_SCENARIO' => $scenario,
				'REDIS_HOST' => 'redis',
				'TEST_RUNTIME_INTERNAL_VERSION' => '35.0.1.1',
				'TEST_RUNTIME_PHP_VERSION' => '8.4.25',
				'TEST_SEED_SCRIPT' => __DIR__ . '/../../fixtures/provision-command.sh',
			], []);
			self::assertSame($expectedExit, $exit, $output);
			self::assertStringContainsString($message, $output);
			self::assertStringContainsString('session.save_handler = redis', (string)file_get_contents($ini));
			self::assertStringContainsString('session.save_path = "tcp://redis:6379?', (string)file_get_contents($ini));
			self::assertStringContainsString('redis.session.locking_enabled = 1', (string)file_get_contents($ini));
			if ($expectedExit === 0) {
				self::assertStringContainsString('REDIS_CONFIG_IMPORTED', $output);
				self::assertLessThan(strpos($output, 'SEED_SUCCEEDED'), strpos($output, 'REDIS_CONFIG_IMPORTED'));
			} else {
				self::assertStringNotContainsString('READY_MARKER', $output);
				self::assertStringNotContainsString('SEED_SUCCEEDED', $output);
			}
		} finally {
			foreach ($commands as $command) {
				unlink($directory . '/' . $command);
			}
			if (file_exists($ini)) {
				unlink($ini);
			}
			rmdir($directory . '/conf.d');
			rmdir($directory);
		}
	}

	public static function redisOutcomes(): array {
		return [
			['success', 0, 'Configuring Redis distributed cache and file locking'],
			['redis-unreachable', 1, 'Redis is unreachable after 60 attempts'],
			['redis-config-failure', 1, 'Redis cache/locking verification failed'],
		];
	}

	/** @dataProvider provisioningOutcomes */
	public function testProvisioning_RequiredStages_ControlReadiness(string $scenario, int $expectedExit, string $message): void {
		$directory = sys_get_temp_dir() . '/cs-provision-' . bin2hex(random_bytes(8));
		self::assertTrue(mkdir($directory));
		$commands = ['php', 'su', 'composer', 'apache2-foreground', 'curl', 'sleep', 'rm', 'touch', 'bash'];
		try {
			foreach ($commands as $command) {
				self::assertTrue(copy(__DIR__ . '/../../fixtures/provision-command.sh', $directory . '/' . $command));
				self::assertTrue(chmod($directory . '/' . $command, 0700));
			}
			[$exit, $output] = $this->runEntrypoint([
				'PATH' => $directory . ':' . getenv('PATH'),
				'PROVISION_SCENARIO' => $scenario,
				'TEST_RUNTIME_INTERNAL_VERSION' => '35.0.1.1',
				'TEST_RUNTIME_PHP_VERSION' => '8.4.25',
				'TEST_SEED_SCRIPT' => $scenario === 'missing-seed' ? $directory . '/missing-seed.sh' : __DIR__ . '/../../fixtures/provision-command.sh',
			], []);
			self::assertSame($expectedExit, $exit, $output);
			self::assertStringContainsString($message, $output);
			if ($expectedExit === 0) {
				self::assertStringContainsString('READY_MARKER', $output);
				self::assertStringContainsString('App discovery: /apps (read-only) + /custom_apps (writable), no alias.', $output);
				// The complete apps_paths array must be configured before any
				// app-dependent config mutation and before seeding.
				self::assertLessThan(strpos($output, 'CONFIG_MUTATION'), strpos($output, 'App discovery:'));
				self::assertLessThan(strpos($output, 'SEED_SUCCEEDED'), strpos($output, 'App discovery:'));
				self::assertLessThan(strpos($output, 'READY_MARKER'), strpos($output, 'SEED_SUCCEEDED'));
			} else {
				self::assertStringNotContainsString('READY_MARKER', $output);
				self::assertStringNotContainsString('Setup Complete', $output);
				if (in_array($scenario, ['wrong-code-runtime', 'old-volume', 'unexpected-apps-paths', 'apps-paths-import-failure', 'apps-paths-read-failure'], true)) {
					self::assertStringNotContainsString('CONFIG_MUTATION', $output);
					self::assertStringNotContainsString('SEED_SUCCEEDED', $output);
				}
				if ($scenario === 'apps-paths-read-failure') {
					self::assertStringNotContainsString('APPS_PATHS_IMPORTED', $output);
				}
			}
		} finally {
			foreach ($commands as $command) {
				unlink($directory . '/' . $command);
			}
			rmdir($directory);
		}
	}

	public static function provisioningOutcomes(): array {
		return [
			['success', 0, 'Setup Complete'],
			['existing-apps-paths', 0, 'Keeping existing alias-free apps_paths configuration.'],
			['install-failure', 1, 'INSTALL_FAILED'],
			['wrong-provider', 1, 'Installed database provider does not match'],
			['wrong-code-runtime', 1, 'RUNTIME_FAILED'],
			['old-volume', 1, 'Installed Nextcloud version does not match the frozen runtime'],
			['enable-failure', 1, 'ENABLE_FAILED'],
			['disabled-app', 1, 'ChoreStrike app is not enabled'],
			['http-failure', 1, 'Nextcloud HTTP readiness failed'],
			['http-runtime-mismatch', 1, 'Nextcloud HTTP readiness failed'],
			['seed-failure', 1, 'SEED_FAILED'],
			['missing-seed', 1, 'Required seed script is missing'],
			['unexpected-apps-paths', 1, 'unexpected existing apps_paths configuration'],
			['apps-paths-import-failure', 1, 'APPS_PATHS_IMPORT_FAILED'],
			['apps-paths-read-failure', 1, 'apps_paths configuration probe failed'],
		];
	}

	/** @dataProvider preservationOutcomes */
	public function testProvisioning_PreservationMode_NeverReseedsOrInstalls(string $scenario, array $settings, int $expectedExit, string $message): void {
		$directory = sys_get_temp_dir() . '/cs-provision-' . bin2hex(random_bytes(8));
		self::assertTrue(mkdir($directory));
		$commands = ['php', 'su', 'composer', 'apache2-foreground', 'curl', 'sleep', 'rm', 'touch', 'bash'];
		try {
			foreach ($commands as $command) {
				self::assertTrue(copy(__DIR__ . '/../../fixtures/provision-command.sh', $directory . '/' . $command));
				self::assertTrue(chmod($directory . '/' . $command, 0700));
			}
			[$exit, $output] = $this->runEntrypoint([
				'PATH' => $directory . ':' . getenv('PATH'),
				'PROVISION_SCENARIO' => $scenario,
				'PROVISION_STATE' => $directory,
				'TEST_RUNTIME_INTERNAL_VERSION' => '35.0.1.1',
				'TEST_RUNTIME_PHP_VERSION' => '8.4.25',
				'TEST_SEED_SCRIPT' => __DIR__ . '/../../fixtures/provision-command.sh',
			] + $settings, []);
			self::assertSame($expectedExit, $exit, $output);
			self::assertStringContainsString($message, $output);
			if ($expectedExit === 0) {
				self::assertStringContainsString('READY_MARKER', $output);
				self::assertStringContainsString('Preservation mode', $output);
				// Populated re-creation keeps the previously imported alias-free
				// apps_paths instead of mutating configuration again.
				self::assertStringContainsString('Keeping existing alias-free apps_paths configuration.', $output);
			} else {
				self::assertStringNotContainsString('READY_MARKER', $output);
			}
			if ($scenario === 'preserve-upgrade') {
				self::assertStringContainsString('UPGRADE_RAN', $output);
				// Alias-free app discovery must be verified before the upgrade runs.
				self::assertLessThan(strpos($output, 'UPGRADE_RAN'), strpos($output, 'alias-free apps_paths'));
			} else {
				self::assertStringNotContainsString('UPGRADE_RAN', $output);
			}
			self::assertStringNotContainsString('SEED_SUCCEEDED', $output);
			self::assertStringNotContainsString('Creating family users', $output);
			self::assertStringNotContainsString('Installing Composer dependencies', $output);
			self::assertStringNotContainsString('Running initial Nextcloud installation', $output);
			if ($scenario !== 'preserve-upgrade') {
				self::assertStringNotContainsString('UPGRADE_RAN', $output);
			}
		} finally {
			foreach ($commands as $command) {
				unlink($directory . '/' . $command);
			}
			if (file_exists($directory . '/upgraded')) {
				unlink($directory . '/upgraded');
			}
			rmdir($directory);
		}
	}

	public static function preservationOutcomes(): array {
		return [
			['preserve-recreate', ['TEST_RUNTIME_PRESERVATION' => 'true'], 0, 'keeping the installed vendor tree'],
			['preserve-upgrade', ['TEST_RUNTIME_PRESERVATION' => 'true', 'TEST_RUNTIME_UPGRADE_FROM' => '35.0.0.10'], 0,
				'official Nextcloud upgrade path (35.0.0.10 -> 35.0.1.1)'],
			['preserve-upgrade-failure', ['TEST_RUNTIME_PRESERVATION' => 'true', 'TEST_RUNTIME_UPGRADE_FROM' => '35.0.0.10'], 1, 'UPGRADE_FAILED'],
			['preserve-wrong-source', ['TEST_RUNTIME_PRESERVATION' => 'true', 'TEST_RUNTIME_UPGRADE_FROM' => '35.0.0.10'], 1,
				'Populated source version does not match the pinned update source'],
			['preserve-same-build', ['TEST_RUNTIME_PRESERVATION' => 'true', 'TEST_RUNTIME_UPGRADE_FROM' => '35.0.1.1'], 1,
				'a same-build reinstall is not a runtime update'],
			['preserve-uninstalled', ['TEST_RUNTIME_PRESERVATION' => 'true'], 1, 'Preservation mode requires an already installed Nextcloud'],
		];
	}

	/** @dataProvider preservationSettings */
	public function testValidateOnly_PreservationSettings_RefuseBeforeInstallation(array $settings, string $message): void {
		[$exit, $output] = $this->validate($settings);
		self::assertSame(1, $exit, $output);
		self::assertStringContainsString($message, $output);
		self::assertStringNotContainsString('Installing Composer', $output);
	}

	public static function preservationSettings(): array {
		return [
			[['TEST_RUNTIME_PRESERVATION' => 'yes'], 'TEST_RUNTIME_PRESERVATION must be exactly true or false'],
			[['TEST_RUNTIME_UPGRADE_FROM' => '35.0.0.10'], 'TEST_RUNTIME_UPGRADE_FROM requires preservation mode'],
			[['TEST_RUNTIME_PRESERVATION' => 'true', 'TEST_RUNTIME_UPGRADE_FROM' => 'stable35'],
				'TEST_RUNTIME_UPGRADE_FROM requires preservation mode and an exact internal version'],
			[['TEST_RUNTIME_PRESERVATION' => 'true'], 'Preservation mode requires both frozen runtime versions'],
		];
	}

	private function validate(array $settings): array {
		return $this->runEntrypoint($settings, ['--validate-only']);
	}

	public function testValidateOnly_InheritedPreservationFlags_DoesNotChangeFixtureMode(): void {
		$keys = ['TEST_RUNTIME_PRESERVATION', 'TEST_RUNTIME_UPGRADE_FROM'];
		$original = array_combine($keys, array_map('getenv', $keys));
		try {
			putenv('TEST_RUNTIME_PRESERVATION=true');
			putenv('TEST_RUNTIME_UPGRADE_FROM=35.0.0.10');
			[$exit, $output] = $this->validate([]);
			self::assertSame(0, $exit, $output);
			self::assertStringContainsString('driver validated: mysql', $output);
		} finally {
			foreach ($original as $key => $value) {
				putenv($value === false ? $key : $key . '=' . $value);
			}
		}
	}

	private function runEntrypoint(array $settings, array $arguments): array {
		$environment = array_merge(getenv(), [
			'TEST_DB_TYPE' => 'mysql', 'TEST_DB_PROFILE' => 'family',
			'DB_HOST' => 'unused', 'DB_PORT' => '3306', 'DB_NAME' => 'nextcloud',
			'DB_USER' => 'nextcloud', 'DB_PASS' => 'synthetic-test-only',
			// The AIO-near row's own container exports REDIS_HOST; never let the
			// fixture inherit it and rewrite the running container's PHP config.
			'REDIS_HOST' => '', 'REDIS_PORT' => '',
			'TEST_RUNTIME_INTERNAL_VERSION' => '', 'TEST_RUNTIME_PHP_VERSION' => '',
			'TEST_RUNTIME_PRESERVATION' => 'false', 'TEST_RUNTIME_UPGRADE_FROM' => '',
		], $settings);
		$process = proc_open(['/bin/bash', dirname(__DIR__, 3) . '/docker/test-entrypoint.sh', ...$arguments],
			[0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
		self::assertIsResource($process);
		fclose($pipes[0]);
		$output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		return [proc_close($process), $output];
	}
}
