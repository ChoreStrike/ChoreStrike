<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Exception;

/**
 * Thrown when the authenticated user lacks permission for the requested action.
 */
class ForbiddenException extends \Exception {
}
