<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Exception;

/**
 * Thrown when a mutation conflicts with the current resource state (HTTP 409),
 * e.g. demoting the last remaining house owner (BUG-SEC-002).
 */
class ConflictException extends \Exception {
}
