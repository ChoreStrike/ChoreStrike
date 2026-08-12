<?php

namespace OCP\DB;

/**
 * Mock class for OCP\DB\Exception.
 *
 * Wraps driver-level errors with a stable reason code. Used by mappers that
 * need to detect specific failure modes (e.g. unique-constraint violation
 * on insert) without depending on driver-specific exceptions.
 *
 * Note: does not `use \Exception` as an alias because this class is itself
 * named `Exception` — declaring `class Exception` while `Exception` is
 * already imported would collide. The parent is referenced via the
 * fully-qualified `\Exception` instead.
 */
class Exception extends \Exception {
	/** Reason: a unique constraint was violated. */
	public const REASON_UNIQUE_CONSTRAINT_VIOLATION = 1;
	/** Reason: a foreign-key constraint was violated. */
	public const REASON_FOREIGN_KEY_CONSTRAINT_VIOLATION = 2;
	/** Reason: a NOT NULL constraint was violated. */
	public const REASON_NOT_NULL_CONSTRAINT_VIOLATION = 3;
	/** Reason: another kind of integrity constraint was violated. */
	public const REASON_INTEGRITY_CONSTRAINT_VIOLATION = 4;
	/** Reason: a generic database error not covered by the specific codes. */
	public const REASON_OTHER = 0;

	public function __construct(
		string $message = '',
		int $code = 0,
		?\Throwable $previous = null,
		private int $reason = self::REASON_OTHER,
	) {
		parent::__construct($message, $code, $previous);
	}

	/**
	 * Get the stable reason code for this exception.
	 */
	public function getReason(): int {
		return $this->reason;
	}
}
