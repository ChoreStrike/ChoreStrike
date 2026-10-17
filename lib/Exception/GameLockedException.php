<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Exception;

/**
 * A score was submitted for an arcade game the member has not unlocked yet
 * (CS-PLAN-086 §3.2, ADR-019). Controllers map this to HTTP 403 with the
 * MINIGAME_LOCKED code; the message is curated and translated. As a
 * {@see ForbiddenException} it never reaches a generic rollback log.
 */
class GameLockedException extends ForbiddenException {
}
