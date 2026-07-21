<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Controller;

use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Exception\NotFoundException;

/**
 * Formats controller error responses so internal exception details (SQL
 * errors, stack traces, framework internals) never leak to the client.
 *
 * Domain exceptions that carry an intentional, user-facing message are
 * surfaced verbatim. Any other throwable is replaced by a generic message;
 * the original detail must be logged separately at the call site.
 */
final class ControllerErrorFormatter
{
    /**
     * Return a safe, client-facing message for the given throwable.
     *
     * @param \Throwable $e The caught exception
     * @param string $fallback Generic message for non-domain exceptions
     */
    public static function safeMessage(\Throwable $e, string $fallback = 'An unexpected error occurred'): string
    {
        if ($e instanceof \InvalidArgumentException
            || $e instanceof NotFoundException
            || $e instanceof ForbiddenException
        ) {
            return $e->getMessage();
        }

        return $fallback;
    }
}
