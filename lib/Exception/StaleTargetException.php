<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Exception;

/**
 * Thrown when the shown target drifted between the read that displayed it
 * and the command: the cycle key or cycle version a completion names is
 * no longer current (ADR-021 decision 4). Answered as 409 `STALE_TARGET`,
 * so clients tell it apart from domain refusals (`CONFLICT`) without
 * matching messages (BUG-UX-002).
 */
class StaleTargetException extends ConflictException {
	public function __construct(string $message = '') {
		parent::__construct($message, self::REASON_STALE_TARGET);
	}
}
