<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Exception;

/**
 * Thrown when a requested resource does not exist or the caller
 * does not have visibility (IDOR-safe: same message for both cases).
 */
class NotFoundException extends \Exception {
}
