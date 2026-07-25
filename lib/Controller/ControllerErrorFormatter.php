<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Controller;

use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Exception\NotFoundException;
use OCP\AppFramework\Http\JSONResponse;

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
        // Only NotFoundException / ForbiddenException carry intentional,
        // user-facing messages. InvalidArgumentException is intentionally
        // excluded: services use it to reject bad input (e.g. an unknown
        // mass-assignment field in TaskService::updateTask) and its message
        // echoes client-controlled values — surfacing it would leak internal
        // entity schema. Callers that want to expose a validation message
        // must catch it explicitly and return a curated 400 response.
        if ($e instanceof NotFoundException
            || $e instanceof ForbiddenException
        ) {
            return $e->getMessage();
        }

        return $fallback;
    }

    /**
     * Build the standard 403 response for a {@see ForbiddenException}.
     *
     * Centralises the auth-denial payload so every controller returns an
     * identical shape (status 403, code `FORBIDDEN`, safe message) instead of
     * the generic 500 the catch-all would otherwise produce.
     */
    public static function forbiddenResponse(ForbiddenException $e): JSONResponse
    {
        return new JSONResponse([
            'success' => false,
            'error' => [
                'code' => 'FORBIDDEN',
                'message' => self::safeMessage($e),
            ],
        ], 403);
    }
}
