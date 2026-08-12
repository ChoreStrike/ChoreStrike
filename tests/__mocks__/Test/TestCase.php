<?php

/**
 * Mock Test\TestCase for integration tests
 * Provides basic test case functionality without Nextcloud dependencies
 */

namespace Test;

abstract class TestCase {
	protected function setUp(): void {
		// Basic setup for tests
	}

	protected function tearDown(): void {
		// Basic cleanup for tests
	}

	/**
	 * Assert that two values are equal
	 */
	protected function assertEquals($expected, $actual, string $message = ''): void {
		if ($expected !== $actual) {
			$msg = $message ?: 'Expected ' . var_export($expected, true) . ' but got ' . var_export($actual, true);
			throw new \Exception($msg);
		}
	}

	/**
	 * Assert that a condition is true
	 */
	protected function assertTrue($condition, string $message = ''): void {
		if (!$condition) {
			$msg = $message ?: 'Assertion failed';
			throw new \Exception($msg);
		}
	}

	/**
	 * Assert that a condition is false
	 */
	protected function assertFalse($condition, string $message = ''): void {
		if ($condition) {
			$msg = $message ?: 'Assertion failed';
			throw new \Exception($msg);
		}
	}

	/**
	 * Assert that a value is not null
	 */
	protected function assertNotNull($value, string $message = ''): void {
		if ($value === null) {
			$msg = $message ?: 'Value should not be null';
			throw new \Exception($msg);
		}
	}

	/**
	 * Create a mock object
	 */
	protected function createMock(string $className): object {
		return new class($className) {
			private $className;
			private $methods = [];

			public function __construct(string $className) {
				$this->className = $className;
			}

			public function __call($method, $args) {
				return null;
			}

			public function getClass(): string {
				return $this->className;
			}
		};
	}
}
