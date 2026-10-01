<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\CommandReceipt;
use OCA\ChoreStrike\Db\CommandReceiptMapper;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\TransactionExecutor;
use OCA\ChoreStrike\Exception\ConflictException;
use OCP\IL10N;

/**
 * Command receipt foundation (CS-PLAN-071 WP0; parent CS-PLAN-070 §3.2/§3.5).
 *
 * `runCommand()` executes a house-scoped mutating command exactly once per
 * (house, actor, commandUuid) key:
 *
 *  - the house lock is taken FIRST (every WP0 writer enters house-first);
 *  - an existing receipt with an identical normalized-payload hash replays:
 *    the original result identity comes back with `replay: true`, while the
 *    caller re-reads authorized current projections fresh (a replay must
 *    never overwrite newer client state with stale totals);
 *  - an existing receipt with a different payload hash is a
 *    {@see ConflictException} (409);
 *  - otherwise the receipt and the callable's domain facts commit in ONE
 *    transaction — a receipt never exists without its effects and vice
 *    versa. A reused key against an already terminal target therefore never
 *    repeats financial effects.
 *
 * The normalized payload is canonical-JSON hashed (stable key order,
 * recursive); callers pass only the whitelisted command fields — no tokens,
 * no raw request bodies are ever stored. `$resultRefs` (the stored result
 * identity) must be a small, JSON-serializable id/version map.
 *
 * No production endpoint adopts `commandId` in WP0 (decision D6): T0
 * exercises the service directly; first consumers are WP2 (scheduling
 * apply) and WP3 (completion) per parent §3.4.
 *
 * Actor keys: a receipt ALWAYS belongs to a live actor at creation —
 * `actorHouseUserId` must not be null. Receipts of erased members keep
 * their row with a NULL actor (decision D3), so a NULL-actor lookup
 * namespace exists that new commands must never enter: a future system
 * command needs an explicit actor discriminator (sentinel/status column)
 * before it may run under this service.
 */
class CommandService {
	private const MAX_RESULT_REFS_BYTES = 8192;

	private const MAX_COMMAND_UUID_LENGTH = 36;

	private const MAX_ACTION_LENGTH = 64;

	private TransactionExecutor $transactions;
	private HouseMapper $houseMapper;
	/**
	 * The shared WP7 command-payload validators (RF-078 dedup): one home
	 * for the receipt-key and optimistic-version rules every command
	 * surface enforces.
	 *
	 * @param array<string, mixed> $payload
	 */
	public static function requireCommandUuid(array $payload, IL10N $l10n): string {
		$commandId = $payload['commandId'] ?? null;
		if (!is_string($commandId)
			|| preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $commandId) !== 1) {
			throw new \InvalidArgumentException($l10n->t('A valid commandId (UUID) is required'));
		}
		return $commandId;
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	public static function requireExpectedVersion(array $payload, IL10N $l10n): int {
		$version = $payload['expectedVersion'] ?? null;
		if (!is_int($version) || $version < 0) {
			throw new \InvalidArgumentException($l10n->t('A valid expectedVersion is required'));
		}
		return $version;
	}

	private CommandReceiptMapper $receiptMapper;
	private AppLogger $appLogger;

	public function __construct(
		TransactionExecutor $transactions,
		HouseMapper $houseMapper,
		CommandReceiptMapper $receiptMapper,
		AppLogger $appLogger,
	) {
		$this->transactions = $transactions;
		$this->houseMapper = $houseMapper;
		$this->receiptMapper = $receiptMapper;
		$this->appLogger = $appLogger;
	}

	/**
	 * Run a house-scoped command under its receipt key.
	 *
	 * The house lock is acquired inside this method; `$fn` must NOT
	 * re-acquire it (house-first: the lock is already held for the whole
	 * aggregate) and must be DB-only (no network/notification calls inside
	 * transactions).
	 *
	 * @param int $houseId The house ID (locks the aggregate)
	 * @param int $actorHouseUserId The acting membership's ID (never null — system commands need an actor discriminator first)
	 * @param string $commandUuid Client-supplied idempotency UUID (max 36 chars)
	 * @param string $action Stable action identifier (e.g. 'scheduling.apply', max 64 chars)
	 * @param array<string, mixed> $payload The whitelisted normalized command fields
	 * @param callable(): array<string, mixed> $fn The domain aggregate; returns the result identity to store
	 * @param callable(int, int): void|null $authorizeUnderLock Optional under-lock authorization guard (CS-PLAN-070 WP7 §3.4),
	 *                                                          invoked after the house lock and BEFORE the receipt lookup on BOTH execution and replay. It checks fresh
	 *                                                          actor identity, visibility and current action authority — never business version/funding/state
	 *                                                          preconditions, which the committed receipt already answers. All WP7 commands supply it so authorization
	 *                                                          never lives only in the aggregate that replay skips.
	 * @return array{result: array<string, mixed>, receipt: CommandReceipt, replay: bool}
	 * @throws ConflictException on a reused key with a changed payload (409)
	 * @throws \InvalidArgumentException on an empty/oversized UUID/action, a null actor or an unencodable result identity
	 */
	public function runCommand(
		int $houseId,
		int $actorHouseUserId,
		string $commandUuid,
		string $action,
		array $payload,
		callable $fn,
		?callable $authorizeUnderLock = null,
	): array {
		if (trim($commandUuid) === '') {
			throw new \InvalidArgumentException('commandUuid is required');
		}
		if (strlen($commandUuid) > self::MAX_COMMAND_UUID_LENGTH) {
			throw new \InvalidArgumentException('commandUuid exceeds ' . self::MAX_COMMAND_UUID_LENGTH . ' characters');
		}
		if (trim($action) === '') {
			throw new \InvalidArgumentException('action is required');
		}
		if (strlen($action) > self::MAX_ACTION_LENGTH) {
			throw new \InvalidArgumentException('action exceeds ' . self::MAX_ACTION_LENGTH . ' characters');
		}

		// RF-073-WP2-19: the ACTION IDENTITY is part of the receipt hash —
		// one UUID reused across two different commands (even with an
		// identical payload shape) must conflict, never replay the other
		// command's result.
		$payloadHash = $this->hashPayload(['action' => $action, 'payload' => $payload]);

		return $this->transactions->run(function () use ($houseId, $actorHouseUserId, $commandUuid, $action, $payloadHash, $fn, $authorizeUnderLock): array {
			// FIRST lock of the documented order: the house row serializes
			// every same-house command (receipt lookup + unique-key insert).
			$this->houseMapper->findForUpdate($houseId);

			// WP7 under-lock guard (§3.4): fresh actor identity, visibility
			// and current action authority — on execution AND replay, before
			// any receipt answer. Demotions/departures therefore deny even
			// a replayed command.
			if ($authorizeUnderLock !== null) {
				$authorizeUnderLock($houseId, $actorHouseUserId);
			}

			$existing = $this->receiptMapper->findByHouseActorUuid($houseId, $actorHouseUserId, $commandUuid);
			if ($existing !== null) {
				if (!hash_equals($existing->getPayloadHash(), $payloadHash)) {
					throw new ConflictException('This command was already executed with different input');
				}

				$this->appLogger->info('Command replayed from receipt', [
					'event' => 'command_replay',
					'service' => 'CommandService',
					'houseId' => $houseId,
					'action' => $action,
				]);

				return [
					// Freshly read by the caller from authorized projections
					// when it needs current totals; the stored identity is
					// the ORIGINAL result of the first execution.
					'result' => $existing->getResultRefs() ?? [],
					'receipt' => $existing,
					'replay' => true,
				];
			}

			$result = $fn();
			if (!is_array($result)) {
				throw new \InvalidArgumentException('Command aggregate must return its result identity array');
			}

			$receipt = new CommandReceipt();
			$receipt->setHouseId($houseId);
			$receipt->setActorHouseUserId($actorHouseUserId);
			$receipt->setCommandUuid($commandUuid);
			$receipt->setAction($action);
			$receipt->setPayloadHash($payloadHash);
			$receipt->setResultRefs($this->boundResultRefs($result));
			$receipt->setStatus(CommandReceipt::STATUS_COMMITTED);
			$receipt->setCreatedAt(new \DateTime());

			try {
				$inserted = $this->receiptMapper->insert($receipt);
			} catch (\OCP\DB\Exception $e) {
				// Lost a unique-key race (only possible for a caller that
				// bypassed the house lock): map it to the same conflict
				// semantics. Every OTHER DB failure (transient lock error,
				// data-length, driver failure) propagates unchanged — both
				// for honest error reporting and so the executor's
				// transient-lock retry still recognizes it.
				if ($e->getReason() !== \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
					throw $e;
				}
				throw new ConflictException('Command key conflict');
			}

			return [
				'result' => $result,
				'receipt' => $inserted,
				'replay' => false,
			];
		});
	}

	/**
	 * Canonical payload hash: recursive key sort + compact JSON + SHA-256.
	 * Stable across PHP versions and request orderings.
	 *
	 * @param array<string, mixed> $payload
	 */
	public function hashPayload(array $payload): string {
		$canonical = $payload;
		self::canonicalize($canonical);
		$encoded = json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if ($encoded === false) {
			// An unencodable payload must never collapse onto the shared
			// sha256('') hash — two different malformed payloads would
			// otherwise replay each other's result.
			throw new \InvalidArgumentException('Command payload is not JSON-serializable');
		}
		return hash('sha256', $encoded);
	}

	/**
	 * Bound the stored result identity (defensive size cap; the receipt
	 * never carries full payloads or response bodies).
	 *
	 * @param array<string, mixed> $result
	 * @return array<string, mixed>
	 */
	private function boundResultRefs(array $result): array {
		$encoded = json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if ($encoded === false) {
			throw new \InvalidArgumentException('Command result identity is not JSON-serializable');
		}
		if (strlen($encoded) > self::MAX_RESULT_REFS_BYTES) {
			throw new \InvalidArgumentException('Command result identity exceeds the receipt size bound');
		}
		return $result;
	}

	/**
	 * Recursively sort string-keyed arrays by key so identical content
	 * always serializes identically.
	 */
	private static function canonicalize(array &$array): void {
		foreach ($array as &$value) {
			if (is_array($value)) {
				self::canonicalize($value);
			}
		}
		unset($value);

		$keys = array_keys($array);
		$hasStringKeys = array_reduce($keys, static fn (bool $carry, $key): bool => $carry || is_string($key), false);
		if ($hasStringKeys) {
			ksort($array, SORT_STRING);
		}
	}
}
