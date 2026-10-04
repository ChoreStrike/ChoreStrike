<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Exception;

/**
 * The membership removal target is the house owner (CS-PLAN-081 H3).
 *
 * Distinct from the generic owner-authority ForbiddenException so the API
 * layer can map the endpoint-specific CANNOT_REMOVE_OWNER 403 without
 * confusing it with a racing-demotion denial.
 */
class CannotRemoveOwnerException extends ForbiddenException {
}
