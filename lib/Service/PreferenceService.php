<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\MemberPreference;
use OCA\ChoreStrike\Db\MemberPreferenceMapper;
use OCA\ChoreStrike\Exception\ConflictException;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCP\IL10N;

/**
 * Self-only personal preferences (CS-PLAN-076 §3.1, C5h).
 *
 * One shared `member_prefs` row per active membership carries the
 * delivery block (task/org digest opt-ins, digest time, quiet range,
 * sound/animation/optional-area flags) and the ranking-consent block.
 * Reads and writes are SELF-ONLY — there is deliberately no route or
 * service path for an owner to read or write another member's
 * preferences (C5h); children included.
 *
 * Every write runs as a receipt-protected command under the house-first
 * lock, validates the whole payload BEFORE any side effect (unknown
 * fields rejected, `HH:mm` shapes, cross-midnight quiet ranges, digest
 * time required when a category is enabled) and bumps the shared
 * `version`; a stale `expectedVersion` answers 409 and preserves the
 * client draft. A first save creates the row WITHOUT granting ranking
 * consent (RF-076-01); consent/re-consent/withdraw/disable mutate the
 * same row in place (Q-076-03).
 */
class PreferenceService {
	/** The whitelisted, writable delivery fields. */
	private const WRITABLE_FIELDS = [
		'taskDigestEnabled',
		'orgDigestEnabled',
		'digestTime',
		'quietStart',
		'quietEnd',
		'soundEnabled',
		'animationEnabled',
		'arcadeVisible',
		'catalogVisible',
	];

	public function __construct(
		private MemberPreferenceMapper $prefMapper,
		private CommandService $commandService,
		private IL10N $l10n,
	) {
	}

	/**
	 * The caller's own preference payload (versioned own settings incl.
	 * the effective ranking start, parent §3.4). Never creates a row —
	 * an unsaved member reads the neutral defaults at version 0.
	 *
	 * @return array<string, mixed>
	 */
	public function getOwnPreferences(int $houseId, HouseUser $actor, ?\DateTime $now = null): array {
		$now ??= new \DateTime();
		$pref = $this->prefMapper->findByHouseAndUser($houseId, (int)$actor->getId());
		if ($pref === null) {
			return $this->payload(null, $now);
		}
		return $this->payload($pref, $now);
	}

	/**
	 * Update the caller's own delivery preferences (self-only, children
	 * included). Idempotent per commandId; a stale expectedVersion throws
	 * ConflictException (409) without any write.
	 *
	 * @param array<string, mixed> $input Raw client fields (whitelisted
	 *                                    keys only; unknown keys are a 400
	 *                                    before any side effect)
	 * @return array<string, mixed> The fresh preference payload
	 * @throws ConflictException on a stale expectedVersion or a reused
	 *                           command key with different input
	 */
	public function updateOwnPreferences(
		int $houseId,
		HouseUser $actor,
		array $input,
		string $commandId,
		?\DateTime $now = null,
	): array {
		$now ??= new \DateTime();
		$fields = $this->validatePayload($input);

		$this->commandService->runCommand(
			$houseId,
			(int)$actor->getId(),
			$commandId,
			'preferences.update',
			['expectedVersion' => $fields['expectedVersion']] + $fields['values'],
			function () use ($houseId, $actor, $fields, $now): array {
				$pref = $this->prefMapper->findByHouseAndUser($houseId, (int)$actor->getId());
				$currentVersion = $pref === null ? 0 : $pref->getVersion();
				if ($currentVersion !== $fields['expectedVersion']) {
					// Stale draft: nothing written, the client keeps its
					// draft and reconciles against the current version.
					throw new ConflictException($this->l10n->t('The preferences changed since they were displayed'));
				}
				$this->validateSettings($fields['values'] + $this->payload($pref, $now));

				if ($pref === null) {
					// First save (RF-076-01): creates the row WITHOUT any
					// ranking consent — a delivery-only, ranking-inactive
					// row.
					$pref = new MemberPreference();
					$pref->setHouseId($houseId);
					$pref->setHouseUserId((int)$actor->getId());
					$pref->setCreatedAt($now);
					$pref->setVersion(1);
					foreach ($fields['values'] as $name => $value) {
						$this->applyField($pref, $name, $value);
					}
					$pref->setUpdatedAt($now);
					$pref = $this->prefMapper->insertOrGet($pref);
				} else {
					$pref->setVersion($pref->getVersion() + 1);
					foreach ($fields['values'] as $name => $value) {
						$this->applyField($pref, $name, $value);
					}
					$pref->setUpdatedAt($now);
					$pref = $this->prefMapper->update($pref);
				}

				return ['version' => $pref->getVersion()];
			},
		);

		$fresh = $this->prefMapper->findByHouseAndUser($houseId, (int)$actor->getId());
		if ($fresh === null) {
			// Unreachable in practice (the command wrote the row); keeps
			// the return type total.
			throw new ForbiddenException($this->l10n->t('Preference row missing after update'));
		}
		return $this->payload($fresh, $now);
	}

	/**
	 * Whether the member is currently inside their personal quiet range
	 * at the given house-local wall time (CS-PLAN-076 §3.4). A range may
	 * cross midnight; `start === end` is a full-day quiet range. Quiet
	 * hours never shift a slot — they only gate dispatch.
	 */
	public static function isWithinQuietHours(?string $quietStart, ?string $quietEnd, string $localTime): bool {
		if ($quietStart === null || $quietEnd === null) {
			return false;
		}
		if ($quietStart === $quietEnd) {
			return true;
		}
		if ($quietStart < $quietEnd) {
			return $localTime >= $quietStart && $localTime < $quietEnd;
		}
		// Cross-midnight range (e.g. 20:00 → 08:00).
		return $localTime >= $quietStart || $localTime < $quietEnd;
	}

	/**
	 * Validate the raw client payload BEFORE any side effect (the house
	 * lock is not even held yet). Messages are localized, client-safe and
	 * static — they never echo client-controlled field names or values
	 * (the TaskService unknown-field lesson).
	 *
	 * @return array{expectedVersion: int, values: array<string, mixed>}
	 */
	private function validatePayload(array $input): array {
		foreach (array_keys($input) as $key) {
			if ($key === 'expectedVersion') {
				continue;
			}
			if (!in_array($key, self::WRITABLE_FIELDS, true)) {
				throw new \InvalidArgumentException($this->l10n->t('Unknown preference field'));
			}
		}

		$expectedVersion = $input['expectedVersion'] ?? null;
		if (!is_int($expectedVersion) || $expectedVersion < 0) {
			throw new \InvalidArgumentException($this->l10n->t('A valid expectedVersion is required'));
		}

		$values = [];
		foreach (self::WRITABLE_FIELDS as $field) {
			if (!array_key_exists($field, $input)) {
				continue;
			}
			$value = $input[$field];
			switch ($field) {
				case 'digestTime':
				case 'quietStart':
				case 'quietEnd':
					if ($value !== null && !is_string($value)) {
						throw new \InvalidArgumentException($this->l10n->t('Times must be HH:mm strings'));
					}
					if ($value !== null && !CalendarService::isLocalTime($value)) {
						throw new \InvalidArgumentException($this->l10n->t('Times must be valid HH:mm values'));
					}
					$values[$field] = $value;
					break;
				default:
					if (!is_bool($value)) {
						throw new \InvalidArgumentException($this->l10n->t('Toggles must be true or false'));
					}
					$values[$field] = $value;
			}
		}

		return ['expectedVersion' => $expectedVersion, 'values' => $values];
	}

	private function validateSettings(array $values): void {
		// Validate the resulting settings, not just the partial update.
		$hasStart = $values['quietStart'] !== null;
		$hasEnd = $values['quietEnd'] !== null;
		if ($hasStart !== $hasEnd) {
			throw new \InvalidArgumentException($this->l10n->t('A quiet range needs both a start and an end'));
		}

		// Digest time required when a category is enabled (C5c: the slot
		// schedule needs a wall time to resolve).
		$taskEnabled = $values['taskDigestEnabled'] ?? false;
		$orgEnabled = $values['orgDigestEnabled'] ?? false;
		if (($taskEnabled || $orgEnabled) && $values['digestTime'] === null) {
			throw new \InvalidArgumentException($this->l10n->t('A summary time is required while a summary is enabled'));
		}

	}

	private function applyField(MemberPreference $pref, string $name, mixed $value): void {
		switch ($name) {
			case 'taskDigestEnabled':
				$pref->setTaskDigestEnabled($value);
				break;
			case 'orgDigestEnabled':
				$pref->setOrgDigestEnabled($value);
				break;
			case 'digestTime':
				$pref->setDigestTime((string)($value ?? '18:00'));
				break;
			case 'quietStart':
				$pref->setQuietStart($value);
				break;
			case 'quietEnd':
				$pref->setQuietEnd($value);
				break;
			case 'soundEnabled':
				$pref->setSoundEnabled($value);
				break;
			case 'animationEnabled':
				$pref->setAnimationEnabled($value);
				break;
			case 'arcadeVisible':
				$pref->setArcadeVisible($value);
				break;
			case 'catalogVisible':
				$pref->setCatalogVisible($value);
				break;
		}
	}

	/**
	 * The versioned self-only payload (no foreign, consent-computation or
	 * digest-queue data).
	 */
	private function payload(?MemberPreference $pref, \DateTime $now): array {
		if ($pref === null) {
			return [
				'version' => 0,
				'taskDigestEnabled' => false,
				'orgDigestEnabled' => false,
				'digestTime' => '18:00',
				'quietStart' => null,
				'quietEnd' => null,
				'soundEnabled' => false,
				'animationEnabled' => false,
				'arcadeVisible' => true,
				'catalogVisible' => true,
				'ranking' => ['state' => 'none', 'generation' => null, 'effectiveMonday' => null],
			];
		}

		$state = 'none';
		if ($pref->getRankingConsentGeneration() > 0) {
			$state = $pref->isEffectiveAt($now) ? 'effective' : 'pending';
		}

		return [
			'version' => $pref->getVersion(),
			'taskDigestEnabled' => $pref->getTaskDigestEnabled(),
			'orgDigestEnabled' => $pref->getOrgDigestEnabled(),
			'digestTime' => $pref->getDigestTime(),
			'quietStart' => $pref->getQuietStart(),
			'quietEnd' => $pref->getQuietEnd(),
			'soundEnabled' => $pref->getSoundEnabled(),
			'animationEnabled' => $pref->getAnimationEnabled(),
			'arcadeVisible' => $pref->getArcadeVisible(),
			'catalogVisible' => $pref->getCatalogVisible(),
			'ranking' => [
				'state' => $state,
				'generation' => $state === 'none' ? null : $pref->getRankingConsentGeneration(),
				'effectiveMonday' => $state === 'none' ? null : $pref->getRankingEffectiveMonday()?->format('Y-m-d'),
			],
		];
	}
}
