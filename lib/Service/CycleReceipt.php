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
 * complete, postpone and sleep commands and the one form's save
 * ({@see TaskEdit}).
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
		CommandService::assertClosedBody($payload, ['commandId', 'expectedVersion', ...($key === self::KEY_NONE ? [] : ['cycleKey']), ...$furtherFields], $l10n);
		$commandId = CommandService::requireCommandUuid($payload, $l10n);

		$cycleKey = $payload['cycleKey'] ?? null;
		if (($cycleKey === null && $key === self::KEY_REQUIRED) || ($cycleKey !== null && !CurrentCycle::isKey($cycleKey))) {
			throw new \InvalidArgumentException($l10n->t('Invalid cycle key'));
		}

		return new self(strtolower($commandId), $cycleKey, CommandService::requireExpectedVersion($payload, $l10n));
	}
}
