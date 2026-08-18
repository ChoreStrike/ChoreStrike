# ADR-007: Concurrency-Safe Task Completion

**Status**: Accepted  
**Date**: 2026-08-12  
**Deciders**: Development Team  
**Source**: [Full Review of `lib/Service`](../backlog/archive/CODE_REVIEW_2026-08-04.md) (CR-SVC-04, CR-SVC-12) · landed by [CS-PLAN-025](../plans/done/cs-plan-025-service-security-concurrency-cleanup.md)

## Context

Task completion is the hot path of the app and the place where currency (coins) and
streaks mutate. Two data-integrity defects were found in `RoomService::completeRoomTask`
/ `GameLogicService::handleTaskCompletion`:

- **CR-SVC-04 (P0 lost-update race).** `house_user` coins/streak were read, mutated in
  PHP, and written back with **no row lock**. Two concurrent completions by the same
  user (e.g. a minigame boost + a real task, or two devices) could both read the same
  pre-increment value and clobber each other's increment — silently losing coins/streak.
- **CR-SVC-12 (P1 lock held across an external call).** `handleTaskCompletion`
  dispatched the title-unlock notification **inside** the transaction/lock. The
  `house_user` row lock was therefore held across a notification dispatch, widening the
  critical section and coupling lock duration to an external system.

ChoreStrike runs on three DB engines via the Nextcloud abstraction: MySQL/MariaDB and
PostgreSQL (locking, row-level) in production, and SQLite in dev/test (database-wide
write lock, no `SELECT … FOR UPDATE`).

## Decision

The completion path is **lock-then-read-modify-write inside one transaction, with no
external call inside the critical section.**

1. **Lock the `house_user` row before reading/mutating coins/streak.**
   `HouseUserMapper::findForUpdate(int $houseId, string $userId): ?HouseUser` acquires
   the lock and returns the freshest committed row in one step:
   - **MySQL / MariaDB / PostgreSQL:** a true `SELECT … FOR UPDATE` (raw prepared
     statement). The exclusive row lock and the freshest committed row come from the
     same statement, so the increment always builds on the winner state.
   - **SQLite (no `FOR UPDATE`):** a no-op **self-assigning** UPDATE
     (`SET contribution_score = contribution_score`) acquires the database-wide write
     lock, followed by a fresh re-read.

   The lock **never writes pre-read data back.** (An earlier attempt wrote the
   pre-lock `contribution_score` / `is_active` value back to acquire the lock — that
   reintroduced the clobber when a concurrent winner committed while this transaction
   waited. `RoomTaskMapper::lockForUpdate` was hardened with the same self-assigning
   pattern.)

2. **Defer side-effecting notifications past `commit()`.** `handleTaskCompletion`
    *returns* the title-unlock payload(s) instead of dispatching them;
    `RoomService::completeRoomTask` dispatches `notifyTitleUnlocked` **after** the
    transaction commits. No external call holds the lock. This mirrors how the
    takeover and weekly-goal notifications were already deferred.

3. **Refresh the locked entity; never keep the pre-lock snapshot.**
   `RoomTaskMapper::lockForUpdate(int $roomTaskId): RoomTask` now returns the
   freshest committed entity alongside the lock (same platform split as
   `findForUpdate`: `SELECT … FOR UPDATE` on locking engines, self-assigning
   UPDATE + re-read on SQLite), and `completeRoomTask` replaces its pre-lock
   `$roomTask` with it. A lock alone is not enough: every post-lock decision
   (overdue takeover, claim state, recurrence from `next_due`) must run on the
   entity the lock handed back, or a concurrent winner's commit between the
   pre-read and the lock grant is silently ignored (spurious takeover
   notification, recurrence from a stale `next_due`). Landed by
   [CS-PLAN-028](../plans/done/cs-plan-028-top-priority-fixes.md) (CR-SVC-53);
   proven by a two-connection integration test in
   `tests/integration/Service/ConcurrentCompletionTest.php`.

4. **All coin mutations on `house_users` follow the same lock, not just task
   completion** (extended by [CS-PLAN-031](../plans/done/cs-plan-031-concurrency-integrity-quick-wins.md),
   CR-SVC-54/23). `MinigameService::saveScore` (score insert + coin award) and
   `MinigameService::purchaseBoost` (lock-check-deduct) wrap their whole
   mutation in `beginTransaction()` → `findForUpdate()` → mutate → `commit()`
   with `rollBack()`-and-rethrow on failure. The sufficiency check in
   `purchaseBoost` runs **on the locked row** — the pre-read balance is only
   used to resolve the row id and may be stale. Proven by the same
   two-connection pattern in
   `tests/integration/Service/MinigameConcurrencyTest.php` (verified to fail
   on the lock-less code). `TaskService`/`HouseService` do not own coin
   mutations outside this pattern; any future `house_users` writer must
   reuse `findForUpdate` inside a transaction rather than reintroducing a
   lock-less read-modify-write.

## Consequences

### Positive
- No lost coin/streak increments under concurrent same-user completion.
- Short, well-bounded critical section (no notification dispatch inside the lock).
- Engine divergence is encapsulated in one mapper method, not spread across callers.

### Negative
- SQLite's lock is database-wide (coarser than row-level). Accepted: SQLite is dev/test
  only; production uses MySQL/MariaDB, where the lock is row-scoped to one user.
- Callers thread post-commit payloads (small contract change: `handleTaskCompletion`
  returns a richer array including `newTitle`).

### Neutral
- Correctness is proven by a real-DB two-process integration test
  (`tests/integration/Service/ConcurrentCompletionTest.php`): a second PHP process holds
  the `house_user` lock and commits a winner increment (+5/+10) while the test's
  `findForUpdate` blocks; the test asserts both increments land. It was verified to
  **fail** against the old stale-write implementation and pass with the fix.

## References

- [`docs/engineering/architecture.md`](../engineering/architecture.md) — service/mapper layering.
- [`docs/plans/done/cs-plan-028-top-priority-fixes.md`](../plans/done/cs-plan-028-top-priority-fixes.md) — CR-SVC-53 fresh-entity-after-lock fix.
- [`docs/plans/done/cs-plan-025-service-security-concurrency-cleanup.md`](../plans/done/cs-plan-025-service-security-concurrency-cleanup.md) — implementation record (Phase 3).
- [`docs/backlog/backlog.md`](../backlog/backlog.md) — CR-SVC-04/12/53/54/23 (resolved), CR-SVC-50 (open follow-up).
