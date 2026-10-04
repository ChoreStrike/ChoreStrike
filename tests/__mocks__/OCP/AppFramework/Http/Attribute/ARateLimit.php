<?php

/**
 * Mock ARateLimit for testing (mirrors the NC27+ public contract).
 */

declare(strict_types=1);

namespace OCP\AppFramework\Http\Attribute;

abstract class ARateLimit {
	public function __construct(
		protected int $limit,
		protected int $period,
	) {
	}

	public function getLimit(): int {
		return $this->limit;
	}

	public function getPeriod(): int {
		return $this->period;
	}
}
