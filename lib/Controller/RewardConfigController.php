<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Controller;

use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\RewardConfigService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/**
 * Controller for reward configuration management
 */
class RewardConfigController extends Controller {
	use ControllerLoggingTrait;

	private RewardConfigService $configService;

	public function __construct(
		string $appName,
		IRequest $request,
		RewardConfigService $configService,
		AppLogger $appLogger,
	) {
		parent::__construct($appName, $request);
		$this->configService = $configService;
		$this->initializeControllerLogger($appLogger);
	}

	/**
	 * Get all reward configuration values
	 *
	 * @NoAdminRequired
	 */
	public function index(): DataResponse {
		$this->logDebug('Getting reward config');
		return new DataResponse($this->configService->getAllConfig());
	}

	/**
	 * Update reward configuration values
	 *
	 * @AdminRequired
	 */
	public function update(array $values): DataResponse {
		$this->logDebug('Updating reward config', ['keys' => array_keys($values)]);
		$this->configService->updateConfig($values);
		return new DataResponse($this->configService->getAllConfig());
	}
}
