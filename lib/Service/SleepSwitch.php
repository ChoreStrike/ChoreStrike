<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCP\IL10N;

/**
 * The sleep switch as a client sends it (ADR-021 decision 7): `sleeping`
 * and, only with it, an optional wake day. One boundary validation for
 * the sleep command and the one form ({@see TaskFormPayload}); how far
 * ahead a wake date may lie is {@see SleepService}'s rule.
 */
final class SleepSwitch {
	/**
	 * @param bool|null $sleeping Null: the form leaves the sleep state as it is
	 * @param string|null $wakeOn A real day (`Y-m-d`); only with `$sleeping`
	 */
	private function __construct(
		public readonly ?bool $sleeping,
		public readonly ?string $wakeOn,
	) {
	}

	/**
	 * @param array<string, mixed> $payload The body with `sleeping` and `wakeOn`
	 * @param bool $required Whether `sleeping` must be sent (the sleep command)
	 * @throws \InvalidArgumentException with a translated message (400)
	 */
	public static function fromPayload(array $payload, CalendarService $calendar, IL10N $l10n, bool $required): self {
		$sleeping = $payload['sleeping'] ?? null;
		$wakeOn = $payload['wakeOn'] ?? null;
		if (!is_bool($sleeping) && ($required || $sleeping !== null)) {
			throw new \InvalidArgumentException($l10n->t('sleeping must be a boolean'));
		}
		if ($wakeOn !== null && !$calendar->isRealDay($wakeOn)) {
			throw new \InvalidArgumentException($l10n->t('A valid date (Y-m-d) is required'));
		}
		if ($wakeOn !== null && $sleeping !== true) {
			throw new \InvalidArgumentException($l10n->t('A wake date needs a sleeping task'));
		}
		return new self($sleeping, $wakeOn);
	}
}
