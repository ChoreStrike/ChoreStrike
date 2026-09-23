# ADR-013: Occurrence Agreements and Corrections (Durable Recurrence Timeline)

**Status**: Accepted
**Date**: 2026-09-20
**Deciders**: ChoreStrike maintainers (ratified in CS-PLAN-070 / CS-PLAN-072 WP1)

## Context

Until CS-PLAN-072 WP1 the recurrence authority was the mutable
`room_tasks.next_due` column plus the recurrence fields on the task
definition. Completion advanced `next_due` in place; undo restored it from a
JSON effect snapshot; the daily/overdue/due-today reads re-derived "what is
due" from `next_due` and the definition on every request. This had structural
defects:

- **No durable history.** A definition edit (frequency, energy, lifetime,
  active-end, timezone) rewrote the single mutable `next_due`, so the past
  could only be reconstructed from today's definition. Cron gaps and edits
  silently changed what "was due" last week.
- **Ambiguous catch-up.** "Overdue" was `next_due < now` on one row; a missed
  fortnightly or monthly obligation had no identity, so late work could not be
  attributed to the specific missed instance, and coverage of an older miss was
  indistinguishable from fulfilling the current one.
- **Person/day duplicate guard.** Completion was blocked by a per-person,
  per-day duplicate check. That is wrong once two legitimate occurrences of
  different tasks (or a catch-up plus a current target) can fall on one day.
- **Checklist and undo snapshot authority.** Checklist progress was keyed by
  `(room_task_id, checklist_item_id)` and reset on completion; undo restored
  recurrence and checklist from the effect snapshot, re-introducing stale
  timeline state over newer facts.
- **Client races.** A stale UI click could complete "whatever is due now"
  rather than the occurrence the user was shown, silently crediting a different
  target.

The parent contract (CS-PLAN-070 sections 3.2/3.3) requires calendar-date
enumeration with fortnightly anchors on the chosen first anchor's Monday,
monthly clamps that preserve the original day, query-independent ordinals,
immutable revisions, and coverage-not-fulfillment catch-up semantics.

## Decision

Introduce a single **durable occurrence timeline** as the only recurrence
authority. `next_due`, `ideal_due_date` and `last_completed` on `room_tasks`
become **derived projections** of the timeline, never the source of truth.

### Schema (Version1031; Version1005/Version1029 amended)

- `schedule_revs` - immutable generation-input revisions per room task. A
  revision freezes cadence (fixed/interval), unit, interval, anchor date,
  weekdays, lifetime (once/bounded/recurring), active bounds, early/late
  grace, timezone, energy, visibility, voluntary flag and the default-member
  snapshot. Unique `(room_task_id, revision)`. Edits **close** the live
  revision (`closed_at`) and **append** a new one; saved occurrences keep their
  identity, zone and deadlines.
- `occurrences` - one row per agreed instance. Unique
  `(schedule_rev_id, ordinal)`; ordinals are anchored-sequence positions,
  independent of any query window. Carries `planned_date`, `week_start`,
  `timezone`, `day_start`, half-open `window_start`/`window_end`, `energy`,
  `kind` (scheduled/voluntary), `completion_id`, `covered_by_completion_id`,
  `source_completion_id` and a `version` for optimistic concurrency.
- `occ_members` - per-occurrence participant shares. Unique
  `(occurrence_id, wallet_id)`.
- `agreements` - append-only evidence of every correction (date amendment,
  participant change), carrying `kind`, actor, `old_state`, `new_state` and a
  per-occurrence `revision`.
- `chk_progress` is re-keyed to `(schedule_rev_id, ordinal, checklist_item_id)`
  with a progress `revision` column (Version1005). `completed_tasks` gains
  frozen occurrence evidence: `schedule_rev_id`, `occurrence_ordinal`,
  `occurrence_due`, `work_date`, `work_zone`, `completion_class` (Version1029).

### Public occurrence identity

An occurrence is addressed publicly as `<scheduleRevisionId>:<ordinal>`
(`Occurrence::toPublicKey`/`parsePublicKey`). The identity is stable across
reads and never leaks the internal row id.

### Enumeration (RecurrenceCalculator)

A pure calculator enumerates calendar dates for a revision between an anchor
day key and a target day key: fixed cadence walks weekdays; interval cadence
steps by unit; fortnightly anchors on the chosen first anchor's Monday;
monthly clamps to the shorter month while preserving the original day-of-month
across the anchor. Ordinals are positions in the anchored sequence, so they are
query-window independent.

### Generation and horizon

`OccurrenceService` (the stable facade; implementation split into the
`Occurrence{RowFactory,ReconcileService,SelectionService,RevisionService,CompletionService,AgreementService}`
collaborators, RF-072-WP1-18) initializes revision 1 at creation/seed/setup,
anchored on
the seeded `next_due`. Fixed-cadence revisions materialize occurrences out to a
horizon and track an explicit `agreed_through` frontier. Interval revisions own
exactly **one** pending occurrence, anchored on the real completion **work
date** (not the planned date), so early/late work never moves the next fixed
anchor. A 5-minute `TimelineJob` (and `chorestrike:trigger-jobs timeline
--as-of`) extends the frontier for the house in indexed cursor batches. **No
GET and no job ever awards coins, credits work, or emits notifications** -
materialization is write-of-rows only and fabricates no command actor.

### Selection and completion receipts

`selectionFor` resolves the single canonical target for a room task: the
open window with the smallest end, else the latest uncovered miss
(classification `late` - catch-up), else a voluntary occurrence. **Coverage is
not fulfillment**: completing the current target covers the latest older miss
but does not fulfill it. `once` produces exactly one occurrence; fulfilling it
applies end-of-life deactivation.

Completion is a **closed-set receipt contract** through `CommandService`:
`{commandId (UUID), occurrenceId, expectedVersion, selectionToken}`. Unknown
fields are a 400. The server resolves the shown target under the house-first
lock, materializes a virtual agreed key at version 0, and verifies the expected
version, the canonical selection and the server-computed `selectionToken`
(a hash over key/version/classification/window/revision/frontier/members) with
`hash_equals`. A stale version/token/target is a 409; a fulfilled target is a
409 ("already completed"). Replaying the same `commandId` returns the original
completion identity plus freshly authorized projections with `replay: true` and
**no repeated post-commit effects**. The person/day duplicate guard is removed:
two legitimate occurrences on one day are permitted, two completions of one
target are not.

### Corrections (agreement writers)

Due-date edits, participant changes and the scheduling planner's `apply`
persist **through the common agreement writers**
(`amendOccurrenceDateUnderLock`, `amendParticipantsUnderLock`), never by a raw
`next_due` write. An amendment keeps the occurrence identity, enforces the C2
child day-limit and the amendment deadline (an occurrence past its window cannot
move), records an `agreements` evidence row and bumps the occurrence version. A
house timezone edit appends a revision (`reviseForZoneChangeUnderLock`): saved
occurrences keep their zone/deadlines, only new agreements resolve in the new
zone.

### Checklist and undo seam

Checklist progress is occurrence-scoped: a fulfilled occurrence keeps its
checked evidence, the next occurrence derives fresh state, and a stale toggle
(expectedRevision mismatch) is a 409. Undo (`releaseCompletionUnderLock`) marks
the ledger row undone **before** releasing the occurrence so `last_completed`
re-derives correctly, reopens the target (version bump), deletes the anchored
successor, and reactivates a once/bounded row via `hasOpenObligation`. The
effect-record restore no longer touches `next_due`, `ideal_due_date`,
`last_completed`, `is_active` or the checklist - the timeline re-derives them.

## Consequences

### Positive
- One authority for fixed/interval/lifetime recurrence; durable, immutable
  history survives edits and cron gaps.
- Catch-up is explicit (latest uncovered miss) and coverage is distinct from
  fulfillment; late work is attributable to a real occurrence.
- Completion is idempotent per command and race-safe per shown target; a stale
  click can never silently credit a different occurrence.
- Checklist and undo no longer re-introduce stale recurrence state.

### Negative
- Reads derive selections from the timeline (batched, but more queries than a
  single `next_due` column); write budgets grew accordingly
  (`WorkflowQueryBudgetTest`).
- Two new write seams (agreement writers, command receipts) that every caller
  must use; a raw `next_due` write would diverge from the timeline.

### Neutral
- `next_due`/`ideal_due_date`/`last_completed` remain as projections for
  existing read surfaces and ordering, but are recomputed from the timeline.
- WP2 (pause/restart and the remaining full agreement-writer surface), WP3
  (monetary/room replay cutover) and WP4 (family rhythm) build on this
  foundation and remain pending; this ADR documents only the WP1 timeline.

## Alternatives Considered

- **Keep `next_due` authoritative, add a history log.** Rejected: a log beside
  a mutable authority still cannot answer "what was due last Tuesday" without
  replaying edits, and catch-up stays ambiguous.
- **Derive occurrences purely on read (no stored rows).** Rejected: ordinals
  and identity would shift with the query window, breaking stable public keys,
  checklist scoping and completion receipts.

## References

- Parent contract: `docs/plans/active/cs-plan-070-family-rhythm-and-reward-clarification.md` (sections 3.2/3.3, T1)
- Plan: `docs/plans/active/cs-plan-072-wp1-occurrence-timeline.md`
- ADR-007 (concurrency-safe completion), ADR-009 (per-house timezone),
  ADR-011 (transactional completion and undo), ADR-012 (scheduling preview/apply)
- Concept: `docs/concepts/task-model-and-sync.md`
- Code: `lib/Service/{RecurrenceCalculator,OccurrenceService,OccurrenceRowFactory,OccurrenceReconcileService,OccurrenceSelectionService,OccurrenceRevisionService,OccurrenceCompletionService,OccurrenceAgreementService}.php`,
  `lib/Db/{ScheduleRevision,Occurrence,OccurrenceMember,Agreement}.php`,
  `lib/Migration/Version1031Date20260918000000.php`,
  `lib/BackgroundJob/TimelineJob.php`
