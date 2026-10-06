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
		];
	}

	/** @dataProvider provisioningOutcomes */
	public function testProvisioning_RequiredStages_ControlReadiness(string $scenario, int $expectedExit, string $message): void {
		$directory = sys_get_temp_dir() . '/cs-provision-' . bin2hex(random_bytes(8));
		self::assertTrue(mkdir($directory));
		$commands = ['php', 'su', 'composer', 'apache2-foreground', 'curl', 'sleep', 'ln', 'chown', 'rm', 'touch', 'bash'];
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
				self::assertLessThan(strpos($output, 'READY_MARKER'), strpos($output, 'SEED_SUCCEEDED'));
			} else {
				self::assertStringNotContainsString('READY_MARKER', $output);
				self::assertStringNotContainsString('Setup Complete', $output);
				if (in_array($scenario, ['wrong-code-runtime', 'old-volume'], true)) {
					self::assertStringNotContainsString('CONFIG_MUTATION', $output);
					self::assertStringNotContainsString('SEED_SUCCEEDED', $output);
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
		];
	}

	private function validate(array $settings): array {
		return $this->runEntrypoint($settings, ['--validate-only']);
	}

	private function runEntrypoint(array $settings, array $arguments): array {
		$environment = array_merge(getenv(), [
			'TEST_DB_TYPE' => 'mysql', 'TEST_DB_PROFILE' => 'family',
			'DB_HOST' => 'unused', 'DB_PORT' => '3306', 'DB_NAME' => 'nextcloud',
			'DB_USER' => 'nextcloud', 'DB_PASS' => 'synthetic-test-only',
			'TEST_RUNTIME_INTERNAL_VERSION' => '', 'TEST_RUNTIME_PHP_VERSION' => '',
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
