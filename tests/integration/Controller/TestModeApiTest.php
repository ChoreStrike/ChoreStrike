<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Controller;

use OCA\ChoreStrike\Controller\TestController;
use OCA\ChoreStrike\Service\TestDataService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;

/**
 * Real service/DB route behavior of the test-tooling API across test-mode
 * states (CS-PLAN-068 §3.5, decision D5 of the Standard A review).
 *
 * Evidence layers, labeled honestly:
 * - HERE (in-process): the controller/service against the REAL database
 *   with a test-owned process environment. Refusal before mutation in
 *   disabled/unset/invalid mode, enabled-mode seed/reset/profile effects,
 *   and the service-level backstop exception.
 * - NOT here: the already-running web server's environment cannot be
 *   changed by PHPUnit. The live HTTP proofs (authenticated dispatch,
 *   403-when-disabled probes, CSRF behavior) stay with the Playwright
 *   fixtures (auth.setup.ts / database-fixtures.ts) which run against the
 *   Docker container whose server process carries NEXTCLOUD_TEST_MODE.
 *
 * The response/error mock layer remains in TestControllerTest; this suite
 * adds the real persistence behavior separately, as ratified.
 */
class TestModeApiTest extends IntegrationTestCase {
	private string $previousEnv = '';
	private bool $hadPreviousEnv = false;

	protected function setUp(): void {
		parent::setUp();
		$this->hadPreviousEnv = getenv('NEXTCLOUD_TEST_MODE') !== false;
		if ($this->hadPreviousEnv) {
			$this->previousEnv = (string)getenv('NEXTCLOUD_TEST_MODE');
		}
	}

	protected function tearDown(): void {
		// Restore the process environment owned by the PHPUnit process.
		if ($this->hadPreviousEnv) {
			putenv('NEXTCLOUD_TEST_MODE=' . $this->previousEnv);
			$_ENV['NEXTCLOUD_TEST_MODE'] = $this->previousEnv;
		} else {
			putenv('NEXTCLOUD_TEST_MODE');
			unset($_ENV['NEXTCLOUD_TEST_MODE']);
		}
		parent::tearDown();
	}

	/**
	 * @param string|null $value null unsets the variable entirely
	 */
	private function setTestMode(?string $value): void {
		if ($value === null) {
			putenv('NEXTCLOUD_TEST_MODE');
			unset($_ENV['NEXTCLOUD_TEST_MODE']);
			return;
		}
		putenv('NEXTCLOUD_TEST_MODE=' . $value);
		$_ENV['NEXTCLOUD_TEST_MODE'] = $value;
	}

	private function controller(): TestController {
		$this->actingAs('admin');
		return \OC::$server->get(TestController::class);
	}

	/**
	 * @return int Number of ChoreStrike houses (any other app state is not
	 *             touched by the test tooling and is not asserted here).
	 */
	private function houseCount(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))->from('chorestrike_houses');
		return (int)$qb->executeQuery()->fetchOne();
	}

	/**
	 * DisabledMode_Reset_IsRefusedBeforeAnyMutation
	 *
	 * @dataProvider disabledModeValues
	 */
	public function testDisabledMode_Reset_IsRefusedBeforeAnyMutation(?string $envValue): void {
		$this->setTestMode($envValue);

		// Pre-existing app data that the refused request must NOT touch.
		$this->createBareHouse('Survivor House');
		$housesBefore = $this->houseCount();

		foreach (['reset', 'seed', 'profiles'] as $method) {
			$response = $this->controller()->$method();
			self::assertSame(403, $response->getStatus());
			self::assertFalse($response->getData()['success']);
			self::assertSame('NOT_TEST_MODE', $response->getData()['error']['code']);
			self::assertSame($housesBefore, $this->houseCount(), 'the refused route must not mutate');
		}
	}

	/**
	 * @return array<string, array<int, string|null>>
	 */
	public static function disabledModeValues(): array {
		return [
			'unset variable' => [null],
			'explicit false' => ['false'],
			'invalid value' => ['yes'],
		];
	}

	/**
	 * DisabledMode_ServiceBackstop_ThrowsBeforeMutation
	 */
	public function testDisabledMode_ServiceBackstop_ThrowsBeforeMutation(): void {
		$this->setTestMode('false');
		$service = \OC::$server->get(TestDataService::class);

		$housesBefore = $this->houseCount();
		try {
			$service->seed('empty');
			self::fail('the service must refuse destructive work outside test mode');
		} catch (\RuntimeException $e) {
			self::assertStringContainsString('test mode', $e->getMessage());
		}
		self::assertSame($housesBefore, $this->houseCount(), 'the refused seed must not mutate');
	}

	/**
	 * EnabledMode_SeedResetCycle_PersistsDeclaredStructure_AndClearsIt
	 */
	public function testEnabledMode_SeedResetCycle_PersistsDeclaredStructure_AndClearsIt(): void {
		$this->setTestMode('true');

		// enabled dispatch: seed a real profile with real rows.
		$seedResponse = $this->controller()->seed('minimal');
		self::assertSame(200, $seedResponse->getStatus());
		$seeded = $seedResponse->getData()['data'];
		self::assertSame('minimal', $seeded['profile']);
		self::assertSame('Test House', $seeded['data']['house']['name']);
		self::assertCount(1, $seeded['data']['floors']);
		self::assertCount(1, $seeded['data']['rooms']);
		self::assertCount(2, $seeded['data']['users']);
		self::assertSame(1, $this->houseCount());

		// The reset endpoint seeds 'empty' — the app state is gone again.
		$resetResponse = $this->controller()->reset();
		self::assertSame(200, $resetResponse->getStatus());
		self::assertSame(0, $this->houseCount());

		// profiles: the enabled-mode catalog (the live probe signal used by
		// the Playwright fixtures).
		$profilesResponse = $this->controller()->profiles();
		self::assertSame(200, $profilesResponse->getStatus());
		$names = array_map(
			static fn (array $profile): string => $profile['name'],
			$profilesResponse->getData()['data']['profiles']
		);
		self::assertSame(['empty', 'emptyhouse', 'minimal', 'family'], $names);
	}

	/**
	 * EnabledMode_UnknownProfile_Get400Envelope_WithoutWrites
	 */
	public function testEnabledMode_UnknownProfile_Get400Envelope_WithoutWrites(): void {
		$this->setTestMode('true');

		$response = $this->controller()->seed('galaxy');

		self::assertSame(400, $response->getStatus());
		self::assertSame('INVALID_PROFILE', $response->getData()['error']['code']);
		self::assertSame(0, $this->houseCount());
	}

	/**
	 * TestRoutes_CarryTheAuthenticationAnnotations
	 *
	 * The static half of the authentication proof: the three registered
	 * test routes resolve to @NoAdminRequired actions (never @PublicPage).
	 * The live half (a real session required, 403 when disabled) is proven
	 * over HTTP by the Playwright fixtures against the test container.
	 */
	public function testTestRoutes_CarryTheAuthenticationAnnotations(): void {
		$routes = require dirname(__DIR__, 3) . '/appinfo/routes.php';
		$testRoutes = array_values(array_filter(
			$routes['routes'],
			static fn (array $route): bool => str_starts_with($route['url'], '/api/v1/test/')
		));
		self::assertCount(3, $testRoutes, 'the test-tooling route surface is exactly the three registered routes');

		foreach ($testRoutes as $route) {
			[$controllerName, $method] = explode('#', $route['name'], 2);
			$class = new \ReflectionClass('\\OCA\\ChoreStrike\\Controller\\' . ucfirst($controllerName) . 'Controller');
			$reflection = new \ReflectionMethod($class->getName(), $method);
			$docComment = (string)$reflection->getDocComment();

			self::assertStringContainsString('@NoAdminRequired', $docComment, "{$route['name']} must require an authenticated user");
			self::assertStringNotContainsString('@PublicPage', $docComment, "{$route['name']} must never be public");
		}
	}

	/**
	 * Reset_LeavesForeignAppData_Intact
	 *
	 * The documented boundary: a reset clears ChoreStrike app tables only —
	 * other apps' config survives.
	 */
	public function testReset_LeavesForeignAppData_Intact(): void {
		$this->setTestMode('true');

		$config = \OC::$server->get(\OCP\IConfig::class);
		$config->setAppValue('someotherapp', 'probe_key', 'probe-value');

		$response = $this->controller()->reset();
		self::assertSame(200, $response->getStatus());
		self::assertSame('probe-value', $config->getAppValue('someotherapp', 'probe_key', ''));

		$config->deleteAppValue('someotherapp', 'probe_key');
		$qb = $this->db->getQueryBuilder();
		$qb->delete('appconfig')
			->where($qb->expr()->eq('appid', $qb->createNamedParameter('someotherapp')))
			->andWhere($qb->expr()->eq('configkey', $qb->createNamedParameter('probe_key')))
			->executeStatement();
	}
}
