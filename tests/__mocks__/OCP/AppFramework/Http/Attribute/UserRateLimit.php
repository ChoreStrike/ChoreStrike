<?php

/**
 * Mock UserRateLimit for testing (mirrors the NC27+ public contract).
 */

declare(strict_types=1);

namespace OCP\AppFramework\Http\Attribute;

#[\Attribute(\Attribute::TARGET_METHOD)]
class UserRateLimit extends ARateLimit {
}
