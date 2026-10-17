<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Exception;

/**
 * A minigame score is not reachable in the reported play time
 * (CS-PLAN-086 D-086-04). Controllers map this to HTTP 400 with the
 * MINIGAME_IMPLAUSIBLE_SCORE code; the message is curated and translated.
 */
class ImplausibleScoreException extends \Exception {
}
