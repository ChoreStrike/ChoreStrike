<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Exception;

/**
 * Thrown when the shown occurrence target drifted between the read that
 * displayed it and the command (version, selection token or virtual
 * state). Answered as 409 `STALE_TARGET`, so clients tell it apart from
 * domain refusals (`CONFLICT`) without matching messages (BUG-UX-002).
 */
class StaleTargetException extends ConflictException {
}
