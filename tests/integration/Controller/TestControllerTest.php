<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Controller;

use OCA\ChoreStrike\Controller\TestController;
use OCA\ChoreStrike\Service\TestDataService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for TestController
 */
class TestControllerTest extends TestCase {
	private TestController $controller;
	private TestDataService $testDataService;

	protected function setUp(): void {
		parent::setUp();

		$request = $this->createMock(IRequest::class);
		$this->testDataService = $this->createMock(TestDataService::class);

		$this->controller = new TestController(
			$request,
			$this->testDataService,
			\OC::$server->get(\OCA\ChoreStrike\Service\AppLogger::class)
		);
	}

	/**
	 * Test seed endpoint returns success when in test mode
	 */
	public function testSeedReturnsSuccessInTestMode(): void {
		$this->testDataService->method('isTestMode')->willReturn(true);
		$this->testDataService->method('seed')
			->with('minimal')
			->willReturn([
				'profile' => 'minimal',
				'data' => ['house' => ['id' => 1]]
			]);

		$response = $this->controller->seed('minimal');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$data = json_decode(json_encode($response->getData()), true);
		$this->assertTrue($data['success']);
		$this->assertEquals('minimal', $data['data']['profile']);
	}

	/**
	 * Test seed endpoint returns 403 when not in test mode
	 */
	public function testSeedReturns403WhenNotInTestMode(): void {
		$this->testDataService->method('isTestMode')->willReturn(false);

		$response = $this->controller->seed('minimal');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(403, $response->getStatus());
		$data = json_decode(json_encode($response->getData()), true);
		$this->assertFalse($data['success']);
		$this->assertEquals('NOT_TEST_MODE', $data['error']['code']);
	}

	/**
	 * Test seed endpoint validates profile parameter
	 */
	public function testSeedValidatesProfileParameter(): void {
		$this->testDataService->method('isTestMode')->willReturn(true);

		$response = $this->controller->seed('invalid_profile');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(400, $response->getStatus());
		$data = json_decode(json_encode($response->getData()), true);
		$this->assertFalse($data['success']);
		$this->assertEquals('INVALID_PROFILE', $data['error']['code']);
	}

	/**
	 * Test reset endpoint returns success in test mode
	 */
	public function testResetReturnsSuccessInTestMode(): void {
		$this->testDataService->method('isTestMode')->willReturn(true);
		$this->testDataService->method('seed')
			->with('empty')
			->willReturn(['profile' => 'empty', 'data' => []]);

		$response = $this->controller->reset();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$data = json_decode(json_encode($response->getData()), true);
		$this->assertTrue($data['success']);
		$this->assertEquals('Database reset complete', $data['message']);
	}

	/**
	 * Test reset endpoint returns 403 when not in test mode
	 */
	public function testResetReturns403WhenNotInTestMode(): void {
		$this->testDataService->method('isTestMode')->willReturn(false);

		$response = $this->controller->reset();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(403, $response->getStatus());
		$data = json_decode(json_encode($response->getData()), true);
		$this->assertFalse($data['success']);
	}

	/**
	 * Test profiles endpoint returns available profiles
	 */
	public function testProfilesReturnsAvailableProfiles(): void {
		$this->testDataService->method('isTestMode')->willReturn(true);

		$response = $this->controller->profiles();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$data = json_decode(json_encode($response->getData()), true);
		$this->assertTrue($data['success']);
		$this->assertCount(3, $data['data']['profiles']);
		$this->assertArrayNotHasKey('testMode', $data['data']);

		$profileNames = array_column($data['data']['profiles'], 'name');
		$this->assertContains('empty', $profileNames);
		$this->assertContains('minimal', $profileNames);
		$this->assertContains('family', $profileNames);
	}

	/**
	 * Test profiles endpoint includes testMode status
	 */
	public function testProfilesReturns403WhenNotInTestMode(): void {
		$this->testDataService->method('isTestMode')->willReturn(false);

		$response = $this->controller->profiles();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(403, $response->getStatus());
		$data = json_decode(json_encode($response->getData()), true);
		$this->assertFalse($data['success']);
		$this->assertEquals('NOT_TEST_MODE', $data['error']['code']);
	}

	/**
	 * Test seed handles service exceptions
	 */
	public function testSeedHandlesServiceException(): void {
		$this->testDataService->method('isTestMode')->willReturn(true);
		$this->testDataService->method('seed')
			->willThrowException(new \Exception('Database error'));

		$response = $this->controller->seed('minimal');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(500, $response->getStatus());
		$data = json_decode(json_encode($response->getData()), true);
		$this->assertFalse($data['success']);
		$this->assertEquals('SEED_ERROR', $data['error']['code']);
	}

	/**
	 * Test all valid profiles can be seeded
	 */
	public function testAllValidProfilesCanBeSeeded(): void {
		$this->testDataService->method('isTestMode')->willReturn(true);

		$profiles = ['empty', 'minimal', 'family'];

		foreach ($profiles as $profile) {
			$this->testDataService->method('seed')
				->willReturn(['profile' => $profile, 'data' => []]);

			$response = $this->controller->seed($profile);

			$this->assertEquals(200, $response->getStatus());
		}
	}
}
