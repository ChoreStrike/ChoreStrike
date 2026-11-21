<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Exception;

/**
 * Thrown when a mutation conflicts with the current resource state (HTTP 409),
 * e.g. demoting the last remaining house owner (BUG-SEC-002).
 *
 * A refusal a client has to tell apart from others carries a stable
 * reason, answered as the error code (ADR-021 decisions 4 and 6); every
 * other conflict stays `CONFLICT`.
 */
class ConflictException extends \Exception {
	public const REASON_CONFLICT = 'CONFLICT';
	/** The shown target is no longer the current one: reload and retry. */
	public const REASON_STALE_TARGET = 'STALE_TARGET';
	/** The cycle already has a valid completion. */
	public const REASON_ALREADY_COMPLETED = 'ALREADY_COMPLETED';
	/** The room task was already completed on this house-local day. */
	public const REASON_SAME_DAY = 'SAME_DAY';
	/** The window of the cycle has ended: a red task is not postponed. */
	public const REASON_WINDOW_ENDED = 'WINDOW_ENDED';
	/** The task has no flex days, or the cycle was postponed three times. */
	public const REASON_NOT_POSTPONABLE = 'NOT_POSTPONABLE';
	/** A bonus task already paid one of the people to credit on this house-local day. */
	public const REASON_BONUS_ALREADY_TODAY = 'BONUS_ALREADY_TODAY';
	/** A sleeping task whose bounded rule ended while it slept: waking closes it, nothing is left to complete. */
	public const REASON_TASK_ENDED = 'TASK_ENDED';
	/** An edit of a definition that several rooms share names no scope: this room or all rooms. */
	public const REASON_SCOPE_REQUIRED = 'SCOPE_REQUIRED';

	public function __construct(
		string $message = '',
		private string $reason = self::REASON_CONFLICT,
	) {
		parent::__construct($message);
	}

	/** The stable error code of the 409 response. */
	public function getReason(): string {
		return $this->reason;
	}
}
