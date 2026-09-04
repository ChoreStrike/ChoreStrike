<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Exception;

/**
 * A caller hit a rate limit (e.g. the per-user minigame saveScore daily cap,
 * CS-PLAN-061 §3.7 decision D7). Controllers map this to HTTP 429 with the
 * MINIGAME_RATE_LIMITED code.
 */
class RateLimitException extends \Exception {
}
