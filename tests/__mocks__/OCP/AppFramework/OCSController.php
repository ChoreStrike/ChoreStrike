<?php

/**
 * Mock OCSController for testing
 */

declare(strict_types=1);

namespace OCP\AppFramework;

use OCP\IRequest;

class OCSController extends Controller {
	protected string $appName;

	public function __construct(string $appName, IRequest $request) {
		parent::__construct($request);
		$this->appName = $appName;
	}
}
