<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCP\IL10N;

/**
 * The receipt a client sends with a command on a room task's cycle
 * (ADR-021 decisions 4, 6 and 7): its command id, the cycle key it showed
 * and the cycle version it showed. One boundary validation for the
 * complete, postpone and sleep commands.
 */
final class CycleReceipt {
	/** No `cycleKey` field at all: the command names the room task alone. */
	public const KEY_NONE = 'none';
	/** Absent for a task without a cycle to name (bonus, asleep). */
	public const KEY_OPTIONAL = 'optional';
	public const KEY_REQUIRED = 'required';

	private function __construct(
		public readonly string $commandId,
		public readonly ?string $cycleKey,
		public readonly int $expectedVersion,
	) {
	}

	/**
	 * Validate the closed body of a cycle command.
	 *
	 * @param array<string, mixed> $payload The request body without framework keys
	 * @param string $key self::KEY_* — whether the command names a cycle
	 * @param list<string> $furtherFields Fields of the command besides the receipt
	 * @throws \InvalidArgumentException with a translated message (400)
	 */
	public static function fromPayload(array $payload, IL10N $l10n, string $key, array $furtherFields = []): self {
		$allowed = ['commandId', 'expectedVersion', ...($key === self::KEY_NONE ? [] : ['cycleKey']), ...$furtherFields];
		foreach (array_keys($payload) as $field) {
			if (!in_array($field, $allowed, true)) {
				throw new \InvalidArgumentException($l10n->t('Unknown field: %1$s', [$field]));
			}
		}
		$commandId = CommandService::requireCommandUuid($payload, $l10n);

		$cycleKey = $payload['cycleKey'] ?? null;
		if (($cycleKey === null && $key === self::KEY_REQUIRED) || ($cycleKey !== null && !CurrentCycle::isKey($cycleKey))) {
			throw new \InvalidArgumentException($l10n->t('Invalid cycle key'));
		}

		// A whole number, also as the canonical decimal string of a form body.
		$version = $payload['expectedVersion'] ?? null;
		if ((!is_int($version) && !is_string($version)) || (int)$version < 0 || (string)(int)$version !== (string)$version) {
			throw new \InvalidArgumentException($l10n->t('A valid expectedVersion is required'));
		}
		return new self(strtolower($commandId), $cycleKey, (int)$version);
	}
}
