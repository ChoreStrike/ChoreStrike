# ADR-012: Actionable Household Scheduling — Preview/Apply Aggregate

**Status**: Accepted
**Date**: 2026-09-10
**Deciders**: ChoreStrike maintainers (ratified in CS-PLAN-065 Standard A review)

## Context

The scheduling surface could not produce an owner-confirmed plan over real
room tasks. The `POST /scheduling/suggestions` endpoint accepted only a
`taskCount` and returned weekday aggregates (counts and energy totals) — no
task IDs, no dates, no people. The client could therefore neither preview
nor apply a household schedule. Capacity was computed from an invented
`weekDays` client constant and a reader-less `todayConsumedEnergy` counter
that every completion incremented but nothing read. The child daily-task
limit (`maxDailyTasks`) was read and seeded but had no production setting,
and My Day applied assignment eligibility (the "mine" semantics) only
client-side — after the server-side child limit had already truncated the
list, so an ineligible task could consume a child's daily slot.

Concurrency was the hard constraint: ADR-011 defines the completion/undo
order (`room_task → room → house_user`), and creation/setup writers take
the house lock first. A scheduling apply must coordinate with both families
without introducing an ordering cycle, and a confirmed plan must not be
applied against drifted state (assignments, dates, definitions, membership,
preferences, timezone, completions, undo state).

## Decision

Introduce a single owner-only **preview/apply aggregate** in
`SchedulePlanService` with an opaque **content fingerprint**
(`previewVersion`), documented in this ADR and implemented exactly once.

### Contract

- `POST /api/v1/scheduling/suggestions` — **preview** (owner-only,
  zero writes): request carries `startDate`, unique positive
  `roomTaskIds` (bounded at 100), and optional per-row `overrides`
  (`assigneeUserIds`, `date`). The response carries deterministic
  `changes` (before/proposed assignees and house-local due day, warning
  codes, legal dates), `unchanged`/`unplaced` rows with explicit reason
  codes, a per-member/day `capacity` report, global `warnings`, and the
  `previewVersion`.
- `POST /api/v1/scheduling/apply` — **apply** (owner-only): the exact
  normalized preview input plus `previewVersion`. One transaction; success
  returns the applied room tasks (with canonical assignees) for client
  reconciliation; a stale confirmation is a `409`; a confirmed no-change
  plan performs zero writes. The retired taskCount contract has no
  compatibility fallback (a legacy payload is a 400).

### Planning rules

- One next occurrence per real room task over seven consecutive
  house-local days (per-house timezone, ADR-009).
- Task recurrence/active bounds/static weekdays/flex tolerance are
  authoritative: static weekly definitions may only move onto their
  configured weekdays; everything else may move within its flex window
  around the stored ideal (or next) due day. A zero-flex or static task
  without a legal slot stays `unchanged`/`unplaced` with a reason — the
  recurrence is never rewritten to make a suggestion fit. An overdue
  dynamic task may move only within its existing legal date constraints.
- Eligibility precedes capacity: admin-only definitions are assignable to
  owners only; public and private definitions accept any member (an
  explicit assignee gains visibility under ADR-010).
- Availability, energy capacity (preferences with canonical fallback) and
  child limits are hard placement constraints: the plan never creates a new
  overload, an unavailable assignment, or a child-limit violation. Existing
  unavoidable overload is reported (`EXISTING_OVERLOAD`), not silently
  fixed. Full task effort is counted per assignee, not an invented teamwork
  split.
- Capacity sources: projected occurrences of all active house tasks inside
  the window (including tasks outside the selection) plus non-undone
  completion effort (D11 — `undone_at` rows are excluded). A selected row's
  current in-window occurrence is replaced, not added again.
- Placement preference: nearest legal date, then the eligible member with
  most remaining capacity; stable tie-breakers are the date key and the
  member's house-user id. Unassigned tasks get one eligible person
  proposed; unclaimed rows only — claimed tasks are never rescheduled (D8),
  so a later undo (ADR-011) always restores its recorded state.

### Fingerprint (previewVersion)

`previewVersion` is a SHA-1 over a canonically sorted serialization of: the
normalized request, the selected rows' authoritative state, the task
scheduling inputs, all member roles and preferences, the house timezone,
the capacity-source rows (planned effort and task counts) and the proposal
result. It is content-derived, order-independent and opaque to clients.

### Lock order (refines, never inverts ADR-011)

> **SUPERSEDED IN PART (CS-PLAN-071 WP0):** the single house-first order now
> covers every writer — completion/undo enter through the house lock first
> (`house → room_task → room → house_user`), so the "two families, never
> mixed" framing below is historical. Apply's own subsequence is unchanged
> and remains a valid subsequence of the frozen order; see
> [development.md](../engineering/development.md).

```
house → room_task (ascending IDs) → task definition (ascending IDs)
      → house_user (ascending IDs) → assignee writes
```

- Creation/setup writers take the house lock first → serialize.
- Completion/undo lock `room_task → room → house_user`; apply takes no room
  lock, and both orders agree that room_task precedes house_user →
  compatible.
- Due-date edits, assignment replacements, definition edits, membership/
  role/preference writes and member removal all block on row locks apply
  already holds (`room_task`, task definition, or `house_user`) → a serial
  order without cycles.

Apply recomputes the identical plan from the freshly locked rows and
rejects on fingerprint drift, so every staleness class (assignment, date,
definition, membership, preference, timezone, completion, undo) is a clean
`409` with zero writes. Two applies of one preview — in any input order —
serialize into one commit and one clean conflict.

### Consequential payload/state decisions

- D4: My Day eligibility ("assigned to viewer OR unassigned") moved
  server-side into the child path, evaluated **before** the availability
  gate and the child limit; the daily read order is the deterministic
  `nextDue, createdAt, id` and the `filtered-daily` rows embed their
  canonical assignee sets.
- D5: `maxDailyTasks` is an owner-only member setting (omitted leaves
  unchanged, `null` clears to unlimited, integer 1–100 is a finite bound;
  `0` is a validation error — unavailable weekdays already express a day
  off). Personal preference endpoints cannot change it. It is stored as a
  JSON sibling key in `scheduling_preferences`, not a new column.
- D7: the reader-less `todayConsumedEnergy` cluster is removed (store
  state, the completion increment, the preferences payload field, and the
  per-user energy query it fed). Preview capacity derives scheduled work
  and non-undone completion effort server-side.

## Consequences

### Positive
- Owners can plan a real week for a real household in one reviewable,
  atomic confirmation; nothing changes until Apply.
- Stale confirmations are impossible to commit; every rejection is a clean,
  actionable 409 with zero writes.
- The lock order composes with both existing writer families (ADR-007 /
  ADR-011 completion-undo and house-first creation) without deadlock.
- Child limits are enforceable server-side and fairness (eligibility before
  limits) is guaranteed rather than best-effort client behavior.

### Negative
- Apply locks every capacity-relevant room task of the house for the
  duration of one transaction — acceptable for household-sized data, but
  the aggregate must never be generalized to unbounded batch sizes
  (enforced by the 100-task bound).
- The fingerprint is deliberately strict: any relevant mutation between
  preview and apply (including unrelated completions) forces a re-preview.
  This is the cost of "never apply against drifted state".

### Neutral
- `idealDueDate` is intentionally not rewritten by an applied move; the
  recurrence keeps its own ideal and the scheduled move lives inside the
  legal window.
- The `scheduling/suggestions` URL keeps serving the preview; its contract
  changed without a fallback because the app is pre-release (project-rules
  §1).
