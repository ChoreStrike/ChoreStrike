<?php

/**
 * Mock AppFramework Controller for testing
 */

namespace OCP\AppFramework;

use OCP\IRequest;

class Controller {

	protected IRequest $request;

	public function __construct(IRequest $request) {
		$this->request = $request;
	}
}
