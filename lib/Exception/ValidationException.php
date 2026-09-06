<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Exception;

/**
 * Thrown when domain validation rejects a client payload (CS-PLAN-063 §3.1).
 *
 * Extends \InvalidArgumentException so every existing curated
 * `ControllerErrorFormatter::validation()` catch site keeps working; the
 * optional per-field error list lets controllers preserve the
 * `details[]` envelope shape the creation/update 400 responses carry.
 *
 * Messages carried by this exception are intentional, user-facing
 * (localized at the throw site) and client-safe.
 */
class ValidationException extends \InvalidArgumentException {
	/** @var string[] Localized, client-safe validation error messages */
	private array $errors;

	/**
	 * @param string $message The primary (first) validation error
	 * @param string[] $errors All validation errors (defaults to [$message])
	 */
	public function __construct(string $message, array $errors = []) {
		parent::__construct($message);
		$this->errors = $errors === [] ? [$message] : $errors;
	}

	/** @return string[] Localized, client-safe validation error messages */
	public function getErrors(): array {
		return $this->errors;
	}
}
