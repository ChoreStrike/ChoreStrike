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
 * CS-PLAN-060 WP1 §3.5).
 *
 * This class is the ONLY place in the app allowed to call
 * `IDBConnection::beginTransaction()/commit()/rollBack()`. It carries no
 * business logic and exposes no query-builder access — services use it to
 * scope an aggregate of mapper calls into one atomic unit, while mappers
 * keep their own lock primitives (`findForUpdate`/`lockForUpdate`).
 *
 * Deterministic lock order for code running inside `run()` — always acquire
 * in this order, never reverse or mix:
 *
 *   house → room_task → task/assignee/reward rows
 *
 * See docs/engineering/development.md ("Locking and transactions") and
 * ADR-007's completion ordering; the completion-ledger lookup/inversion
 * primitives built on this order arrive with WP5 / ADR-011.
 */
class TransactionExecutor {
	private IDBConnection $db;

	public function __construct(IDBConnection $db) {
		$this->db = $db;
	}

	/**
	 * Begin a transaction. Prefer {@see run()}.
	 */
	public function begin(): void {
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
	 * any Throwable and re-throw. The callable's return value is passed
	 * through.
	 *
	 * @template T
	 * @param callable(): T $fn
	 * @return T
	 * @throws Throwable re-thrown after rolling back
	 */
	public function run(callable $fn): mixed {
		$this->db->beginTransaction();
		try {
			$result = $fn();
			$this->db->commit();
			return $result;
		} catch (Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}
}
