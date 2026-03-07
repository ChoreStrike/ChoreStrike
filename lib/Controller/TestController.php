<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Controller;

use OCA\ChoreStrike\Service\TestDataService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/**
 * API controller for test data management
 *
 * Only accessible in test mode (CI or NEXTCLOUD_TEST_MODE=true)
 */
class TestController extends OCSController
{
	private TestDataService $testDataService;

	public function __construct(
		IRequest $request,
		TestDataService $testDataService
	) {
		parent::__construct('chorestrike', $request);
		$this->testDataService = $testDataService;
	}

	/**
	 * Seed test data with a specific profile
	 *
	 * @NoAdminRequired
	 * @PublicPage
	 */
	public function seed(string $profile = 'minimal'): JSONResponse
	{
		// Safety check: only allow in test mode
		if (!$this->testDataService->isTestMode()) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'NOT_TEST_MODE',
					'message' => 'Test data API only available in test mode'
				]
			], 403);
		}

		$validProfiles = ['empty', 'minimal', 'family'];
		if (!in_array($profile, $validProfiles)) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'INVALID_PROFILE',
					'message' => "Invalid profile: {$profile}. Valid profiles: " . implode(', ', $validProfiles)
				]
			], 400);
		}

		try {
			$result = $this->testDataService->seed($profile);

			return new JSONResponse([
				'success' => true,
				'data' => $result
			]);
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'SEED_ERROR',
					'message' => $e->getMessage()
				]
			], 500);
		}
	}

	/**
	 * Reset database (equivalent to seed empty)
	 *
	 * @NoAdminRequired
	 * @PublicPage
	 */
	public function reset(): JSONResponse
	{
		// Safety check: only allow in test mode
		if (!$this->testDataService->isTestMode()) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'NOT_TEST_MODE',
					'message' => 'Test data API only available in test mode'
				]
			], 403);
		}

		try {
			$result = $this->testDataService->seed('empty');

			return new JSONResponse([
				'success' => true,
				'message' => 'Database reset complete',
				'data' => $result
			]);
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'RESET_ERROR',
					'message' => $e->getMessage()
				]
			], 500);
		}
	}

	/**
	 * Get available profiles
	 *
	 * @NoAdminRequired
	 * @PublicPage
	 */
	public function profiles(): JSONResponse
	{
		return new JSONResponse([
			'success' => true,
			'data' => [
				'profiles' => [
					[
						'name' => 'empty',
						'description' => 'Clean database (no data)',
						'users' => 0,
						'rooms' => 0,
						'tasks' => 0,
					],
					[
						'name' => 'minimal',
						'description' => '1 House, 1 Floor, 1 Room, 2 Users',
						'users' => 2,
						'rooms' => 1,
						'tasks' => 0,
					],
					[
						'name' => 'family',
						'description' => 'Complete family: 6 Users, 3 Floors, 6 Rooms, 12 Tasks',
						'users' => 6,
						'rooms' => 6,
						'tasks' => 12,
					],
				],
				'testMode' => $this->testDataService->isTestMode(),
			]
		]);
	}
}
