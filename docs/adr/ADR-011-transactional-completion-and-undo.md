# ADR-011: Transactional Completion and Full Undo (Effect Record)

**Status**: Accepted — snapshot reversal superseded by ADR-014 (2026-09-27)
**Date**: 2026-09-06  
**Deciders**: ChoreStrike maintainers (ratified in the CS-PLAN-064 Standard A review, decisions D1–D12)  
**Source**: [CS-PLAN-058](../plans/done/cs-plan-058-core-workflow-bottom-up-masterplan.md) WP5 · [CS-PLAN-064](../plans/done/cs-plan-064-wp5-completion-ledger-full-undo.md) · refines [ADR-007](ADR-007-concurrency-safe-task-completion.md)

> **WP3 cutover note (2026-09-27, ADR-014):** the receipt seam introduced here
> (command UUID + optimistic version, CR-SVC-64) is retained and generalized —
> every WP3 mutation is a `CommandService` command. The JSON **effect
> snapshot** reversal this ADR describes is superseded: undo now reverses the
> latest selected allocation revision through exact wallet-union deltas on the
> immutable `wallet_entries` ledger, room state replays from `room_events`,
> and attribution (`PUT /completions/{id}/attribution`) corrects any live
> completion by completer/owner authority. The body below remains the
> historical record of the seam and the targeted-undo target contract.

## Context

> **Target clarification (2026-09-17):** this accepted ADR records the current
> implementation and its original scope, not the newly approved family-rhythm
> target. Snapshot restore does not preserve independent interleaved changes.
> [CS-PLAN-070 C3](../plans/done/cs-plan-070-family-rhythm-and-reward-clarification.md#c3-accounting-decision-log-2026-09-17)
> selects targeted undo, separate attribution correction and room-event replay;
> the [durable correction contract](../concepts/task-model-and-sync.md#approved-correction-contract-c3-2026-09-17)
> distinguishes the target from this history. Its exact schema/lock/API design
> awaits review and implementation; this note does not claim those changes ship.

> **Occurrence-timeline amendment (2026-09-20, CS-PLAN-072 WP1 / ADR-013):**
> completion and undo now run on the durable occurrence timeline. Completion is
> a closed-set receipt command (`commandId`, `occurrenceId`, `expectedVersion`,
> `selectionToken`) that fulfills exactly the shown occurrence; the person/day
> duplicate guard is removed (occurrence validity replaces it) and a replayed
> `commandId` returns the original identity with `replay: true` and no repeated
> post-commit effects. Undo marks the ledger row undone, then releases the
> occurrence (reopen + successor deletion + reactivation via open obligation).
> The effect-record restore **no longer** writes `next_due`, `ideal_due_date`,
> `last_completed`, `is_active` or the checklist - the timeline re-derives those
> projections, so undo can no longer re-introduce stale recurrence state over
> newer facts. The room/house_user reward restore and the D1 effect record are
> unchanged.

The masterplan mandates that "completion and undo become exact, idempotent state
transitions" (CS-PLAN-058 §3/WP5). Before WP5 the live tree could not satisfy
this:

- The completion ledger `chorestrike_completed_tasks` stored reward **outputs**
  only — nothing recorded the pre-mutation state, so an exact inverse was not
  computable (CR-WF-02).
- Undo was a split, partial, non-idempotent client chain
  (`PUT /tasks/{id}/uncomplete` + `POST /room-tasks/{id}/reactivate`) that
  cleared `lastCompleted` and reactivated, leaving the recurrence advance, the
  ledger row, all coin/streak/freeze/title rewards, and the room cleanliness
  delta in place. The undo actor gate was visibility-only: any member who could
  see the task could uncomplete it.
- Room cleanliness was an unlocked read-modify-write (concurrent same-room
  completions clobbered each other), the teamwork bonus was a second unlocked
  RMW outside the locked reward write, an inactive room task could still be
  completed and earn rewards, and checklist progress leaked from one
  occurrence into the next (`resetProgress` had zero callers).
- Post-commit effects (weekly-goal read, notification claims) were not
  failure-isolated: an exception after `COMMIT` answered a 500 on an already
  committed completion, and the client's retry then hit the day-duplicate 403.

## Decision

`CompletionService` (decided: D2 — extracted out of `RoomService`/`RoomTaskService`)
owns both transitions as single-transaction aggregates with one documented
lock order and one effect record per occurrence.

### 1. The effect record lives on the ledger row (D1)

One occurrence = one `chorestrike_completed_tasks` row = one undo unit. The
fresh-install schema (migration `Version1029`) gained four columns; the seven
never-written legacy columns (`combo_with_user_id`, `is_team_completion`,
`participant_count`, `team_spark_bonus`, `was_claimed_completion`,
`claim_bonus_sparks`, `days_overdue_when_claimed`) were dropped:

| Column | Meaning |
|--------|---------|
| `occurrence_due` | House-local due date (day start, ADR-009 semantics) of the completed occurrence — captured BEFORE the recurrence advance |
| `before_state` | Authoritative pre-mutation JSON snapshot (contract below) |
| `deltas` | Every applied domain delta (JSON) |
| `undone_at` | `NULL` = revertible occurrence; set by undo |

#### `before_state` JSON contract

```jsonc
{
  "roomTask": {
    "nextDue": "...", "idealDueDate": "...", "isActive": true,
    "lastCompleted": "...", "wasClaimed": false,
    "originalAssignee": null, "claimedByUser": null, "claimedAt": null
  },
  "room":      { "cleanliness": 42, "lastCleaned": "..." },
  "houseUser": {
    "coins": 150, "contributionScore": 320, "currentStreak": 3,
    "longestStreak": 9, "streakFreezes": 1, "lastFreezeDate": null,
    "unlockedTitles": ["pack.tier"], "lastActivity": "..."
  },
  "checklist": [ { "checklistItemId": 4, "userId": "mom", "checked": true, "checkedAt": "..." } ]
}
```

All datetimes are ISO-8601 (`c`) strings or `null`. `room.lastCleaned` and
`houseUser.lastActivity` are part of the contract because completion mutates
them and exact before/after state equality (the WP5 exit gate) is impossible
without restoring them — `lastActivity` additionally drives the streak-absence
calculation. The contract is pinned by a serialization unit test; drift breaks
that test, and undo reads only through these typed keys.

#### `deltas`

```jsonc
{
  "coinsAwarded": 54,          // base coins + teamwork bonus (total credited)
  "coins": 50, "teamworkBonus": 4,
  "contributionAwarded": 3,
  "streakBefore": 2, "streakAfter": 3,
  "freezeUsed": false, "freezeAwarded": false,
  "titlesAdded": ["pack.tier"], // tier ids unlocked BY this completion only
  "cleanlinessChange": 30,
  "takeoverClaimed": false
}
```

### 2. Deterministic lock order: `room_task → room → house_user`

> **SUPERSEDED (CS-PLAN-071 WP0, parent CS-PLAN-070 §3.5):** the two-family
> split (setup/creation `house → room_task → …` vs this completion-only
> entry order `room_task → room → house_user`) is collapsed into ONE
> house-first order — `house → room tasks → task definitions → rooms →
> active membership rows → wallets → occurrence/completion rows →
> request/preference/digest rows` (or a subsequence). `CompletionService`
> now acquires the HOUSE lock first, then `room_task → room → house_user`
> exactly as below; the relative order of the completion locks is unchanged,
> only the house lock was prepended. This section is preserved as the
> historical rationale; the authoritative order lives in
> [development.md](../engineering/development.md) and
> [architecture.md](../engineering/architecture.md).

Both aggregates acquire in exactly this order, never reversing or mixing:

```
room_task → room → house_user        (completion and undo alike)
```

1. `RoomTaskMapper::lockForUpdate($roomTaskId)` — FIRST lock; the pre-lock
   entity is replaced by the fresh locked one (ADR-007 #3).
2. `RoomMapper::lockForUpdate($roomId)` — SECOND lock (new WP5 primitive, same
   MySQL/PG `SELECT … FOR UPDATE` vs. SQLite self-assigning-UPDATE split).
   Two concurrent completions of different tasks in the same room serialize
   here, closing the unlocked cleanliness read-modify-write.
3. `HouseUserMapper::findForUpdate(...)` — THIRD lock: the completer's reward
   row (the same row the frozen reward write locks). Undo locks the
   **completer's** row — the undo actor may be a house owner.

Checklist progress rows are mutated inside the transaction after the locks
(occurrence-scoped reset on completion, snapshot-restore on undo).

**Deadlock argument against the existing orders.** Setup (`HouseSetupService`)
and creation (`TaskCreationService`) take `house` first and then only fresh
inserts or revalidated rows; assignment takes `room_task` as first and only
lock (`TaskAssigneeMapper` rows after it). Completion/undo **never take the
house row lock**, so the two families (`house → …` vs `room_task → room →
house_user`) share no inverted pair; within the `room_task`-first family the
relative order of `room` before `house_user` is fixed by this ADR. No cycle
is constructible. Proven by the two-connection race tests
(`CompletionConcurrencyTest`, `ConcurrentCompletionTest` same-room pair).

**Ratified deviation from the masterplan lock wording (D12):** the task
definition is deliberately **not** locked. It is stable read-only input for
completion, read once before any mutation; locking it would contend with the
`PUT /tasks/{id}` edit path for no integrity gain. Accepted in the Standard A
review (2026-09-06).

### 3. Occurrence gates before any write (D4)

Inside the transaction, before any reward, cleanliness, checklist, or
recurrence write:

| Condition | Answer |
|-----------|--------|
| Room task unknown / other house / invisible | `404 RESOURCE_NOT_FOUND` (ADR-010 anti-enumeration) |
| Non-member actor | `403 FORBIDDEN` |
| `is_active = false` (stale/inactive occurrence) | `409 CONFLICT` — "Task is not active" |
| Same user already completed this room task today (non-undone rows only, D11) | `403 FORBIDDEN` — existing message |

### 4. Undone rows are not completions (D11)

The three ledger reads gained the same `undone_at IS NULL` predicate:

- `countByRoomTaskAndUserOnDate` — day-duplicate guard: undo → same-day
  re-completion is a legal, non-duplicate occurrence (the core misclick-recovery
  flow);
- `findRecentByUserId` — combo window: an undone completion neither keeps nor
  starts a combo;
- `findCompletedRoomTaskIdsBetweenByHouse` — weekly-goal count: undo deflates
  the weekly goal.

### 5. Post-commit degradation boundary (D7)

Everything after `COMMIT` — weekly-goal read, takeover/title dispatch, the
weekly-goal notification claim — runs inside one log-and-degrade guard. A
committed completion **always** answers 200; on any post-commit failure the
response carries `rewards.weeklyGoal = null` and `rewards.degraded = true` and
an `AppLogger` warning with stable keys (`completion_post_commit_degraded` /
`undo_post_commit_degraded`) is emitted. A committed success can therefore
never surface as an ambiguous API failure, and the degradation stays
observable (never a silent swallow). Undo re-reads the weekly goal in the same
boundary and reports it in its reverted `rewards` payload.

### 6. Undo: newest-revertible, completer-or-owner, restore-not-recalculate (D3/D8)

`CompletionService::undo(houseId, actor, roomTaskId)`:

1. Resolve + `TaskAccessPolicy::assertCanView` first (404 anti-enumeration).
2. **Actor gate:** the completer themself **or** a house owner (masterplan D3) —
   a child may undo their own completion, never anyone else's. A completer
   whose membership row was deleted after the completion is a clean 403, not a
   500 (RF-05).
3. `findNewestRevertibleByRoomTask` selects the undo unit (LIFO: completions
   undo newest-first). No revertible row → `200 {undone: false}` with **zero
   writes** — undo is idempotent.
4. Inside one transaction on the same lock order, an under-lock re-check makes
   a concurrent undo loser take the same zero-write no-op path. Restore
   **assigns every recorded before-state value** (recurrence dates, claim
   fields, active flag, room cleanliness/lastCleaned, completer
   coins/contribution/streaks/freezes/lastActivity); `titlesAdded` removal only
   removes tier ids **still present** in `unlockedTitles`, so titles re-earned
   by later completions survive. Snapshotted checklist rows are re-inserted.
   `markUndone(id, now)` closes the occurrence. The undo never recalculates
   policy (masterplan D2).
5. The reverted `rewards` payload carries the same reconciliation inputs as
   completion (`totalCoins`, `totalContribution`, `longestStreak`, post-undo
   `weeklyGoal`) plus `completerUserId` — telling the client whose user state
   the totals describe (the actor may differ from the completer).

**Documented non-retractions (D8):** medals already awarded, the house-level
`weekly_goal_notified_week` claim, and dispatched notifications are **not**
reverted — undo reverses recorded domain deltas, not derived side-channel
history. This is the superseded effect-record behavior: ADR-014 uses per-week
receipts for closed-week notification claims and re-evaluates medal support.

### 7. Frozen contracts

The reward formulas are frozen: `GameLogicService::handleTaskCompletion` keeps
its calculations verbatim. The teamwork bonus was **folded into** the locked
reward write (D9) — the same computed amount, one locked write instead of a
second unlocked read-modify-write after the reward phase — so the bonus is part
of the effect record. Event names (`chorestrike:task-completed`,
`streak-freeze-used`, `title-unlocked`, `task-uncompleted`), title/medal/
notification vocabulary, and the client-side single-payload reconciliation
(D10: stores reconcile from the one response **before** any celebration
event fires) stay unchanged. The undo snackbar queue (UX-015) is presentation
over the same events.

## Consequences

### Positive
- Completion and undo are exact inverses — proven by real-DB before/after
  row-equality tests over the recurrence permutation matrix (takeover included)
  and a complete → undo → same-day re-complete → undo roundtrip.
- No lost cleanliness/teamwork updates under concurrency (two-connection proofs).
- A committed completion can never answer an ambiguous failure; a retry after
  a degraded post-commit cannot produce a second ledger row.
- One undo endpoint replaces a client-side two-request chain that could half
  fail; the legacy `uncomplete`/`reactivate` routes are gone (D5).

### Negative
- The room row lock serializes same-room completions. Accepted at household
  scale — the critical section is short (ADR-007 doctrine).
- The JSON effect-record contract must be kept in sync with the restore logic
  (mitigated by the serialization pin test; ADR-011 owns the schema).
- The manual cleanliness endpoint (`PUT /rooms/{id}/cleanliness`) remains an
  unlocked RMW that can race the new room lock — tracked as CR-SVC-63.

### Neutral
- `undone_at`-marked rows stay in the ledger as immutable history; every
  domain read that could count an undone occurrence as a completion must carry
  the D11 filter (three today, pinned by tests).

## Alternatives Considered

- **Separate effect-record table** — rejected (D1): one ledger row already
  identifies one occurrence; a join and a second insert in the hot path buy
  nothing pre-release.
- **Recalculate on undo instead of restoring** — rejected (masterplan D2): any
  formula, config, or streak-rule evolution since the completion would turn
  "undo" into a silently different mutation.
- **Keep the split undo chain client-side** — rejected (D5): two requests
  cannot commit as one; the chain was the CR-WF-02 defect's visible half.
- **Lock the task definition** — rejected (D12): deviation from the literal
  masterplan wording, ratified above.

## References

- [ADR-007](ADR-007-concurrency-safe-task-completion.md) — lock doctrine this
  ADR refines (fresh-locked entities, deferred notifications, shared wallet lock).
- [ADR-009](ADR-009-per-house-timezone.md) — house-local day semantics of
  `occurrence_due` and the day-duplicate gate.
- [ADR-010](ADR-010-invite-only-membership-visibility-matrix.md) — 403/404
  denial standard on the occurrence gates.
- [`docs/engineering/development.md`](../engineering/development.md) — "Locking and transactions".
- [`docs/api/README.md`](../api/README.md) — `POST /room-tasks/{id}/complete` / `undo` payloads.
- [`lib/Db/TransactionExecutor.php`](../../lib/Db/TransactionExecutor.php) —
  the lock-order docblock this ADR documents.
