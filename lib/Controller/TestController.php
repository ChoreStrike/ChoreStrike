<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Controller;

use OCA\ChoreStrike\Service\TestDataService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * API controller for test data management
 *
 * Only accessible in test mode (NEXTCLOUD_TEST_MODE=true) and with an
 * authenticated Nextcloud session. Never annotate with @PublicPage.
 */
class TestController extends Controller {
	private TestDataService $testDataService;

	public function __construct(
		IRequest $request,
		TestDataService $testDataService,
	) {
		parent::__construct('chorestrike', $request);
		$this->testDataService = $testDataService;
	}

	/**
	 * Seed test data with a specific profile
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function seed(string $profile = 'minimal'): JSONResponse {
		$guard = $this->requireTestMode();
		if ($guard !== null) {
			return $guard;
		}

		// Workaround for Apache/Nextcloud POST-to-GET rewrite issue:
		// Extract profile from query params if the route parameter is empty or default
		$queryProfile = $this->request->getParam('profile');
		if ($queryProfile !== null && $queryProfile !== '') {
			$profile = $queryProfile;
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
					'message' => ControllerErrorFormatter::safeMessage($e)
				]
			], 500);
		}
	}

	/**
	 * Reset database (equivalent to seed empty)
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function reset(): JSONResponse {
		$guard = $this->requireTestMode();
		if ($guard !== null) {
			return $guard;
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
					'message' => ControllerErrorFormatter::safeMessage($e)
				]
			], 500);
		}
	}

	/**
	 * Get available profiles
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function profiles(): JSONResponse {
		$guard = $this->requireTestMode();
		if ($guard !== null) {
			return $guard;
		}

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
			]
		]);
	}

	/**
	 * Return a 403 response when test mode is not active, null otherwise
	 */
	private function requireTestMode(): ?JSONResponse {
		if ($this->testDataService->isTestMode()) {
			return null;
		}

		return new JSONResponse([
			'success' => false,
			'error' => [
				'code' => 'NOT_TEST_MODE',
				'message' => 'Test data API only available in test mode'
			]
		], 403);
	}
}
