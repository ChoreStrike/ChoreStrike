<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Db;

use OCP\IDBConnection;
use Throwable;

/**
 * Single owner of database transaction plumbing (CR-SVC-40 foundation,
 * CS-PLAN-060 WP1; house-first contract CS-PLAN-070 §3.5 / CS-PLAN-071 WP0).
 *
 * This class is the ONLY place in the app allowed to call
 * `IDBConnection::beginTransaction()/commit()/rollBack()`. It carries no
 * business logic and exposes no query-builder access — services use it to
 * scope an aggregate of mapper calls into one atomic unit, while mappers
 * keep their own lock primitives (`findForUpdate`/`lockForUpdate`).
 *
 * Deterministic lock order (frozen, CS-PLAN-070 §3.5) — every writer
 * acquires locks in this order or a subsequence (a subset preserving the
 * relative order); no writer may acquire the house lock after a child lock:
 *
 *   house → room tasks (by ID) → task definitions (by ID) → rooms (by ID)
 *         → active membership rows (by ID) → wallets (by ID)
 *         → occurrence/completion rows (by ID)
 *         → request/preference/digest rows (by ID)
 *
 * This single order supersedes the former two-family split (setup/creation
 * `house → room_task → …` versus the ADR-011/012 completion-only entry
 * order `room_task → room → house_user`); CS-PLAN-071 WP0 converted every
 * audited writer — including `CompletionService` — to the house-first
 * entry. Bulk writes iterate in ascending-id order within their lock class.
 * Reads and different houses stay independent. Never hold a DB transaction
 * while sending a Nextcloud notification (post-commit boundaries only).
 *
 * SQLite write acquisition (CS-PLAN-071 decision D5): SQLite has no
 * row-level `FOR UPDATE` and Doctrine/`IDBConnection` cannot express
 * `BEGIN IMMEDIATE`. The executor therefore applies a bounded
 * `busy_timeout` on SQLite, and the contractually FIRST statement of every
 * writer — the house-lock primitive, whose SQLite implementation is a no-op
 * self-assignment `UPDATE` — acquires the database-wide write lock at
 * transaction start (deferred BEGIN takes no lock; the first statement is a
 * write), which is BEGIN-IMMEDIATE-equivalent at the application level.
 *
 * Transient failures: recognized DB lock-wait/deadlock failures are retried
 * inside `run()` with a small bounded retry policy (same command key — the
 * transaction aborted, so nothing committed and a clean re-run is safe).
 * Authorization, validation and conflict failures are never retried.
 *
 * See docs/engineering/development.md ("Locking and transactions") and the
 * ADR-011/012 supersession notes.
 */
class TransactionExecutor {
	/** Total attempts per run() (1 initial + bounded retries). */
	private const MAX_ATTEMPTS = 3;

	/** Bounded SQLite busy timeout in milliseconds (decision D5). */
	private const SQLITE_BUSY_TIMEOUT_MS = 5000;

	private IDBConnection $db;
	private bool $sqliteBusyTimeoutApplied = false;

	public function __construct(IDBConnection $db) {
		$this->db = $db;
	}

	/**
	 * Begin a transaction. Prefer {@see run()}.
	 */
	public function begin(): void {
		$this->applySqliteBusyTimeout();
		$this->db->beginTransaction();
	}

	/**
	 * Commit the open transaction. Prefer {@see run()}.
	 */
	public function commit(): void {
		$this->db->commit();
	}

	/**
	 * Roll back the open transaction. Prefer {@see run()}.
	 */
	public function rollBack(): void {
		$this->db->rollBack();
	}

	/**
	 * Run `$fn` inside one transaction: begin → $fn → commit, roll back on
	 * any Throwable and re-throw — with a bounded retry for recognized
	 * transient lock/deadlock failures (the aborted transaction committed
	 * nothing; `$fn` must therefore be a DB-only callable, which the
	 * no-notifications-in-transactions rule already guarantees). The
	 * callable's return value is passed through.
	 *
	 * @template T
	 * @param callable(): T $fn
	 * @return T
	 * @throws Throwable re-thrown after rolling back
	 */
	public function run(callable $fn): mixed {
		$attempt = 0;
		while (true) {
			$attempt++;
			$this->begin();
			try {
				$result = $fn();
				$this->commit();
				return $result;
			} catch (Throwable $e) {
				$this->rollBack();
				if ($attempt < self::MAX_ATTEMPTS && self::isTransientLockFailure($e)) {
					continue;
				}
				throw $e;
			}
		}
	}

	/**
	 * Whether the failure is a recognized transient DB lock/deadlock
	 * (parent §3.5): MariaDB/MySQL deadlock (1213) and lock-wait timeout
	 * (1205), PostgreSQL serialization/deadlock (40001/40P01), SQLite
	 * busy/locked (5/6). Only message/SQLSTATE probing — never business
	 * exceptions.
	 */
	private static function isTransientLockFailure(Throwable $e): bool {
		$message = strtolower($e->getMessage());
		return str_contains($message, 'deadlock')
			|| str_contains($message, 'lock wait timeout')
			|| str_contains($message, 'database is locked')
			|| str_contains($message, 'database table is locked')
			|| str_contains($message, 'serialization failure')
			|| str_contains($message, 'sqlstate[40001]')
			|| str_contains($message, 'sqlstate[40p01]');
	}

	/**
	 * Apply the bounded busy timeout to the SQLite connection once per
	 * executor lifetime (decision D5). Other engines keep their server-side
	 * lock-wait configuration.
	 */
	private function applySqliteBusyTimeout(): void {
		if ($this->sqliteBusyTimeoutApplied) {
			return;
		}
		$this->sqliteBusyTimeoutApplied = true;
		if ($this->db->getDatabaseProvider() === IDBConnection::PLATFORM_SQLITE) {
			$this->db->executeStatement('PRAGMA busy_timeout = ' . self::SQLITE_BUSY_TIMEOUT_MS);
		}
	}
}
