<?php

/**
 * Mock DataResponse for testing
 */

namespace OCP\AppFramework\Http;

class DataResponse {
	/** @var mixed */
	private $data;
	private int $status;

	/**
	 * @param mixed $data
	 */
	public function __construct($data = [], int $status = 200) {
		$this->data = $data;
		$this->status = $status;
	}

	public function getStatus(): int {
		return $this->status;
	}

	/**
	 * @return mixed
	 */
	public function getData() {
		return $this->data;
	}
}
