# Task Model and Sync Status

## Goal

Define how chores are planned and completed in ChoreStrike. The app runs inside
Nextcloud; synchronization with Nextcloud Tasks or other CalDAV clients is not
implemented. The future-sync sketches below are not current requirements or a
commitment to restore the removed API.

## Task Definition

Each task includes:

- title
- description (optional)
- room/floor/house scope metadata; every executable instance belongs to a room
- recurrence settings
- definition start date; agreed occurrence due dates live on the durable
  occurrence timeline, with `RoomTask.next_due` as a derived projection
  (CS-PLAN-072 WP1 / ADR-013)
- priority
- energy cost (1-5)
- reminder settings (**planned — never implemented**: the column-less
  `reminderType`/`reminderMinutesBefore` entity fields and the whole
  `task_sync` HTTP surface were pruned in CS-PLAN-055; see *Reminder
  Configuration* below)
- multi-assignment on the room-task join rows, not a definition-owned user scalar

## Creation Model (CS-PLAN-038, atomic since CS-PLAN-063)

Every user-facing task creation path materializes a **room task** — a task
definition alone is never user-visible output. Since CS-PLAN-063 the whole
aggregate (definition, room task, initial assignees) commits or rolls back
together in **exactly one request** — `POST /room-tasks` (atomic
`TaskCreationService` transaction; no orphan definitions on failure):

1. the definition form (cascading floor→room picker — required, locked when
   created from a room's context; member-chip assignees; optional first due
   date; flex window; optional instructions; concise preview) submits
   `{roomId, task, assigneeUserIds, nextDue}` once,
2. template creation via the per-room picker (`POST
   /room-tasks/from-template`) runs the same atomic body and returns the
   same `{task, roomTask, assignees}` aggregate shape,
3. mounting an existing definition (`{roomId, taskId}`) is the third variant
   of the same endpoint.

Success means everything persisted; failure means nothing did and the form
stays open with the input preserved (no partial-success toasts). Both create
paths share one closed validation authority (service-owned), children are
gated out of the creation surface (`canManageTasks`), and catalog-template
prefill fills the form from `GET /setup/catalog` filtered by the selected
room's type. The former definition-only `POST /tasks` and the three-request
create chain were pruned (CS-PLAN-063; guarded by `RouteMatrixTest`).

Scope model: **custom tasks are always room-scoped** (the old room/floor/house
scope selector was removed — decision D1). Whole-floor/house materialization is
a template/wizard capability, not a manual-form capability. Editing a room task
from its room shows a slim modal (due date + assignees) that persists exactly
what it displays; the full definition form is for editing the definition only.
Assignment is multi-assign via member chips — there is no free-text assignee
input anywhere.

## Today View (CS-PLAN-038)

`/today` ("My Day") is the single check-things-off surface: an *Overdue*
section and a *Due Today* section with ≥44 px complete buttons. It composes
`GET /room-tasks/filtered-daily` (child-aware: weekday availability +
max-daily-tasks limits) with the overdue list, kept in a dedicated store slice
so Today items never bleed into the shared room-task lists. The default filter
is **My tasks** (assigned to me or unassigned/claimable); "Everyone" is one
tap away, and the Dashboard *Due Today*/*Overdue* cards link into the view.
Completing a recurring task moves its next due date out of today — the row
leaves the list (one-time tasks keep their row with a completed state).
Celebration feedback (confetti, sounds, toasts) fires globally via the
`chorestrike:task-completed` event.

The child availability/daily-limit filter applies to the daily query, not to
the whole overdue list or all completion paths. "My tasks" also includes
unassigned, claimable work. It is therefore not yet a definition of a child's
agreed obligations or a guaranteed maximum catch-up workload.

## Recurrence and Rhythm

- Static mode is intended for calendar schedules and uses configured weekdays
  for weekly chores; dynamic mode advances from actual completion. Current static
  daily/monthly advancement also starts from completion, so a preserved calendar
  anchor is not a general implemented guarantee. The two intended modes need
  explicit examples, not interchangeable "automatic planning" labels.
- Completion fulfills the shown occurrence on the durable timeline
  (CS-PLAN-072 WP1 / ADR-013); the next occurrence and the `next_due`
  projection are re-derived from the timeline, and a `once` task deactivates
  when its single occurrence is fulfilled. This is not an open scheduler-job
  decision.
- The owner planner previews and applies a seven-day proposal over existing
  tasks with availability, energy and child-limit checks; the override/shared-task
  limitations are documented in [balancing](energy-and-balancing.md). It does not
  provide a complete dated vacation/illness or recurring-exception workflow.
- Due dates, flex-window recommendations, and the deadline used to judge
  "on time" need an explicit shared contract before introducing rhythm streaks.
  The current streak calculation does not consult those task obligations.

The family-rhythm [clarification plan](../plans/new/cs-plan-070-family-rhythm-and-reward-clarification.md)
covers strict weekdays, rescheduling, missed occurrences, shared assignments,
and non-punitive recovery. Approved target rules below do not change the current API.

### Approved Target Rules (2026-09-17; Not Yet Implemented)

- Each task supports an explicit choice between fixed calendar cadence and an
  interval after actual completion. Early completion does not move a fixed
  calendar cadence; it does move a completion-relative interval.
- By default a chore is on time on its planned day through day-end. The household
  can instead agree an earlier/later window for that task, such as Friday-Sunday
  for a Saturday chore. One completion fulfills that occurrence exactly once.
- Repeated misses produce one visible catch-up item per recurring chore, not an
  accumulating list of identical cards. Missed occurrences remain historical
  misses; completing once does not turn every missed occurrence into a success.
- Normal chores have one responsible person plus optional helpers; tasks needing
  several people are explicitly joint work. Voluntary help is not an additional
  obligation. A pre-deadline agreed handover releases the former responsible
  person neutrally; unplanned substitution does not grant them personal success.
- Ordinary date/window amendments must be agreed before the existing deadline
  expires. Later replanning does not turn a historical miss into on-time work.
- Adults manage their own dated pauses; children request pauses for owner
  confirmation. Owners manage house-wide pauses and may explicitly recognize
  retrospective illness. This exception must be traceable, not hidden repair.
- Timeline attribution, calendar boundaries and selected agreement/pause rules
  are specified below. Remaining lifecycle and cross-contract details are not
  implied. Approval does not make the current implementation compliant.

### Approved Timeline Contract C1 (2026-09-17)

> **Delivery status (2026-09-20, CS-PLAN-072 WP1):** C1 is **implemented** and
> is now the single recurrence authority - see
> [ADR-013](../adr/ADR-013-occurrence-agreements-and-corrections.md). Durable
> `schedule_revs`/`occurrences` rows carry the immutable generation inputs, the
> calendar-date enumeration (monthly clamp, fortnightly Monday anchor,
> query-independent ordinals), the canonical selection (earliest open window ->
> latest uncovered miss -> voluntary), coverage-not-fulfillment catch-up, and
> the completion receipt contract. `room_tasks.next_due`/`ideal_due_date`/
> `last_completed` are derived projections. The C2 date/participant **agreement
> writers** shipped with WP1 (Q-072-01 Option A); C2 pause/restart and the C5
> bounded digest delivery remain pending later packages. The six C1 decisions
> below are the delivered behavior.

Six explicit C1 decisions supplement the approved core rules.

- Fixed monthly recurrence clamps a missing day to that month's last day without
  changing the original anchor: January 31 -> February 28/29 -> March 31. Apply
  the same rule to selected days 29 and 30.
- Every-second-Saturday recurrence follows a chosen first Saturday at 14-day
  intervals, not odd/even ISO weeks. Year boundaries, early/late completion and
  individual missed occurrences do not shift the fixed sequence.
- If several unfulfilled occurrence windows are open, completion selects the
  earliest end deadline, then the earliest planned date for a tie. Show that
  planned date before completion. Fulfill one occurrence only; others stay open.
- An open window takes precedence over expired occurrences. Completing current
  work removes the stale catch-up card as covered, but neither fulfills an old
  occurrence nor changes its historical missed status. Award work only once.
- With no open window, catch-up fulfills the most recent missed occurrence late.
  Older misses stay historical; fixed future dates remain unchanged. This does
  not turn catch-up into on-time progress. Completion-relative intervals still
  advance from actual completion under the approved recurrence-mode rule.
- Week attribution and chronological streak confirmation follow the
  [approved timeline evaluation](gamification-and-rewards.md#approved-timeline-evaluation-c1-2026-09-17).

The selected C2 agreement rules follow below; correction/undo (C3) and
local-time/DST boundaries (C5) still require specification.

### Approved Agreement Contract C2 (2026-09-17)

Target rules only; completion, assignment and pause APIs are not changed here.

- The actor already authorized to complete records themselves and actual coworkers
  at completion. Completion and credits apply immediately, without individual
  confirmations. Assignment alone proves no participation. Participants see the
  attribution; mistakes receive traceable correction. C3 now defines
  [exact shares](energy-and-balancing.md#approved-effort-allocation-c3-2026-09-17).
- An owner records a handover agreed with both responsible people before the
  existing deadline. It takes effect without extra confirmation clicks, for
  adults and children alike: former responsibility becomes neutral, the recipient
  takes the obligation, and authorized viewers can trace the change.
- For an unfulfilled occurrence, an owner records an agreed date/window amendment
  with affected responsible people before the old deadline, preserving old/new
  state. It applies immediately. This route cannot move fulfilled or expired
  occurrences retrospectively. These workflows do not override private-task rights.
- A recognized pause excuses an unfulfilled personal obligation when the planned
  date lies within the pause. A Saturday task with a Friday-Monday window is
  excused by a Saturday pause, not automatically by a Monday-only pause. Already
  correctly completed work remains; retrospective illness recognition is explicit.
- A child's undecided pause request stays visibly pending and changes no
  obligation, deadline or goal. It neither auto-extends the window nor auto-rejects.
  After expiry, excusal requires explicit owner recognition of retrospective illness.
- A pause does not automatically transfer work or enlarge anyone else's share.
  With the sole responsible person paused, neutrally suspend the occurrence while
  keeping necessary work visible to authorized household/owner viewers for
  resolution. For joint work, suspension lasts unless an owner confirms a feasible
  plan agreed with the remaining people before the deadline. New work must fit
  their capacity; visibility never bypasses private-task protection.
- [Goal amendments](gamification-and-rewards.md#approved-goal-amendments-c2-2026-09-17)
  preserve occurrence identity and visible exceptions. The
  [whole-day capacity contract](energy-and-balancing.md#approved-whole-day-capacity-c2-2026-09-17)
  protects new commitments without suppressing actual completed work.
- Shortening/revoking an acknowledged pause takes effect no earlier than the next
  local day, under existing pause roles. Already excused obligations for today or
  earlier stay neutral, without retrospective misses. Voluntary actual work is
  recordable today without creating a retrospective duty; future commitments must
  still fit capacity.
- If a pause suspends completion-relative work without completion, returning does
  not automatically make it overdue. Keep necessary work visible for clarification;
  an owner records an agreed capacity-fitting restart date. The suspended occurrence
  stays historically neutral, and only the next actual completion anchors the
  interval again. Fixed calendar sequences retain their original anchor.

The technical manifest must integrate these rules with the approved C3 corrections and
C5 local-day/UI rules before activation; unasked product behavior is not an
implementation default.

### Delivered agreement & pause surface (CS-PLAN-073 WP2, implemented 2026-09-24)

The C1/C2/C3 contracts above are **implemented** by the WP2 package
(ADR-013 §WP2, corrected in the 2026-09-25 fix round): owner-recorded
agreement commands (`amend`, `catch-up`, `restart`) under receipt identity
(action + all normalized semantic inputs), command-boundary staleness gates
(version + recomputed selection token + effective suspension stamp),
persisted neutral handover releases with consent evidence, follow-up
date/catch-up validation against the **concrete** current participants
(never the replaced revision defaults), catch-up appointments (date + zone)
that survive participant changes and project independently of pauses,
revisioned pause groups (personal/house; child requests pending;
owner-recognized illness; acknowledged-only shortening whose superseded
future coverage is actually removed while recognized today/past survives —
per-group revision precedence from the persisted decision fact;
union provenance across groups), recognition-time eligibility (an ordinary
late acknowledgment never erases an expired miss; only the explicit
retrospective-illness marker does), neutral suspension semantics (ANY
paused obligated participant suspends the occurrence — including one
partner of a joint arrangement until an explicit feasible agreement resumes
it; helper-only release, frozen shares, zero outstanding capacity),
virtual/materialized projection parity, a viewer-safe pause list as an
explicit field whitelist (personal reason and illness marker are
self/owner data in every state), interval restart with exactly one linked
successor, and departure/erasure handling per Q-073-03/§3.6. The API surface
is `/api/v1/occurrences/{key}/{amend,catch-up,restart}` and
`/api/v1/pauses*`; a dedicated pause-management UI remains WP6.

## Reminder Configuration

> **Status (verified 2026-08-29, CS-PLAN-055):** not implemented. What existed
> was an API-only stub: the `task_sync` routes ×6 + `TaskSyncController`/
> `TaskSyncService` (completion mirror, reminder read endpoint, pending-sync
> queue, mark-synced, link) had no UI consumer and were **pruned**. The
> `Task` entity carried `reminderType`/`reminderMinutesBefore` without any
> backing columns (the only reader was `TaskSyncService`) — deleted, too.
> Re-introducing this surface needs a real CalDAV/Task interaction design and
> a consumer (FEAT-UX-021 closed as moot by the prune).

Per-task reminder settings remain a proposal. Current background jobs send
individual due-today/tomorrow and streak-at-risk notifications; there is no
ChoreStrike digest, quiet-hours setting or global per-recipient notification
budget. House-local date selection does not guarantee house-local delivery time:
delivery depends on Nextcloud running the eligible background job.

The earlier per-task reminder ideas below are not the selected first delivery
scope; repeated overdue reminders are not an approved default.

**Approved target (2026-09-17; not implemented):** task reminders are off by
default. Members can enable at most one summary per person/day at their chosen
time, only for relevant planned work, never on free/paused days or with streak
threats. Respect quiet hours. C5 below defines delivery and category policy. The per-task
ideas below are historical alternatives, not the approved first reminder scope.

### Approved Delivery Contract C5 (2026-09-17)

Target behavior only; the existing individual notification jobs do not implement
this digest. Use household-local time, never device/travel timezone, for the
selected time, quiet hours and daily budget; visibly identify that timezone.

- Resolve a nonexistent local time to the first valid later instant (e.g. 02:30
  to 03:00); a repeated time uses its first occurrence only. Delivery eligibility
  lasts at most 60 elapsed minutes from that resolved instant, only on the same
  local day and outside personal quiet hours. A later eligible instant within
  that window is allowed; afterward skip, with no next-day catch-up. Suggest
  personally editable quiet hours 20:00-08:00 when enabling.
- Task content is freshly evaluated at delivery: unfinished personal agreements
  for today that can meaningfully be done now, including explicitly scheduled
  catch-up. Exclude unsolicited old backlog, future work and optional offers.
  Personally free or acknowledged-paused days receive no task reminders.
- Authorized organizational state remains available in-app. Nextcloud notices
  default off, with separate personal opt-ins for task and organizational content.
  Both share one daily summary at the chosen time and the same quiet/late rules,
  not separate daily allowances. Organizational updates may appear on free/paused
  days; task reminders may not. Awards stay quietly in-app. Nextcloud's own security
  notices are outside this application policy.
- Organizational changes after a regular delivery time can enter the next planned
  slot. Include only the latest relevant state per object, never repeat unchanged
  pending requests. If that slot's window is missed, expire its queued notices
  rather than delivering them on later days; in-app state remains. Only new changes
  can qualify again. Recheck visibility, membership and preferences before sending.
- Per-person/house preferences are server-side and follow the person across devices.
  Children control their own opt-ins; an owner cannot enable delivery for someone
  else. Opt-out stops unsent notices immediately. Departure invalidates opt-ins;
  rejoining starts off. Never inherit another account's shared-device settings.
- On a household timezone change, historical day/week attribution and already
  concretely agreed occurrences retain their saved time basis and deadlines.
  The new zone applies to newly agreed occurrences; existing dates change only
  through the approved explicit amendment workflow. Clearly label an old basis.
  Digest and quiet timing move to the new zone, but transition delivery must stay
  at least 24 elapsed hours after the last digest and must not bypass the daily cap.

Implementation must define versioned occurrence/zone facts, slot/queue cutoffs,
atomic daily claims and dispatch failure/retry semantics. Opt-in toggling or
concurrent workers must not create another daily allowance. These are exact-design
obligations, not proof of shipped delivery guarantees. The
[calm UI and evidence contract](ui-use-cases.md#approved-calm-ux-and-evidence-c5-2026-09-17)
defines presentation, consent and the future family trial.

### Earlier Per-Task Reminder Ideas (Not Selected for the First Slice)

- no reminder
- at due time
- before due time (for example 10m, 30m, 1h, 1d)
- repeated reminder while overdue (optional)

### Sync rule (design intent — unimplemented)

Reminder fields in ChoreStrike must stay synchronized with Nextcloud Tasks-compatible reminder fields so reminders are visible on mobile task clients.

## Completion Rules

A task instance can be completed from:

1. ChoreStrike UI (dashboard, room detail, task list)
2. *(planned)* Connected task clients through Nextcloud task completion
   status — see the prune note above; there is no external-completion path
   today.

### Completion and undo model (CS-PLAN-064 WP5)

Completion and undo use one authoritative effect record and idempotent
transitions. The recorded-state design is described in
[ADR-011](../adr/ADR-011-transactional-completion-and-undo.md); this section
records the domain model.

**Known correctness limitation (2026-09-16):** undo restores shared wallet,
contribution and room values from the earlier snapshot. It does not preserve
independent later changes to those values. The operation is not an exact inverse
of only that completion in an interleaved history. Define and test the
cross-event reversal contract before attaching real-world value to coins;
tracked in the family-rhythm clarification plan.

- **One occurrence = one ledger row.** `POST /room-tasks/{id}/complete`
  validates the occurrence *before* any mutation (inactive → 409, same-user
  day duplicate → 403, visibility → 404/403) and writes one immutable effect
  record: the house-local `occurrence_due` plus the full pre-mutation
  `before_state` (room task, room cleanliness, completer rewards, checklist
  progress) and every applied `delta` (coins, contribution, streak, freezes,
  titles, cleanliness, takeover). The occurrence-scoped checklist progress is
  reset in the same transaction, so checked items never leak into the next
  occurrence.
- **Undo restores, never recalculates.** `POST /room-tasks/{id}/undo` reverts
  the *newest revertible* occurrence of that room task by assigning the
  recorded before-state values back — recurrence dates, active flag, claim
  fields, room cleanliness, completer coins/contribution/streaks/freezes, and
  the snapshotted checklist rows. Only titles unlocked by the undone
  completion (and not re-earned later) are removed; medals, the weekly-goal
  notification claim, and dispatched notifications are documented
  non-retractions. The actor must be the completer or a house owner; with no
  revertible occurrence undo is a zero-write no-op (`undone: false`).
- **An undone occurrence is not a completion anywhere.** Undone ledger rows
  are excluded from the day-duplicate guard, the combo window, and the
  weekly-goal count — undo-then-re-complete the same day is the supported
  misclick-recovery flow.
- **The client reconciles from the one response.** The completion/undo payload
  carries authoritative totals (`totalCoins`, `totalContribution`,
  `longestStreak`, room state; undo adds `completerUserId`); every owning
  frontend store (room tasks, daily list, rooms, game, user, coin wallet) is
  updated from that payload **before** the first celebration event fires —
  celebration effects cannot run against stale caches. The global undo
  snackbar is a queued list: each completion gets its own entry and countdown
  (UX-015), so a second completion never destroys the first undo action.

### Structural deletion and account erasure (CS-PLAN-071 WP0, implemented)

Domain behavior of the WP0 substrate (mechanism detail in
[architecture.md](../engineering/architecture.md) and
[development.md](../engineering/development.md)):

- **Deleting structure keeps the history.** Deleting a room task, a room or a
  floor tombstones the rows (`deleted_at`, room tasks also `deleted_token` +
  `is_active = 0`) instead of destroying them: the rows vanish from every
  active-planning view, the `completed_tasks` evidence is retained with its
  existing visibility, and earned effects (coins/streaks/medals) stay valid.
  Undo of a completion on a tombstoned room task stays authorized for the
  completer or an owner. Re-adding the same task to the same room creates a
  fresh live link — the tombstone never blocks it.
- **Account deletion erases the person, not the shared history.** When the
  Nextcloud account is deleted, ChoreStrike removes every personal reference
  (memberships, assignments, medals, minigame scores, the wallet with its
  ledger) and nulls actor references on receipts and completions. The
  anonymous shared work facts (when, what, room effects) remain visible in
  the activity feed rendered as "Former member". An ordinary departure keeps
  the wallet as dormant financial identity; a re-invitation reattaches it; a
  recreated same-name account starts with a fresh identity and cannot claim
  prior data.

### Approved Correction Contract C3 (2026-09-17)

Target behavior, not the shipped ADR-011 restore implementation described above.

- Target a specific completion by stable identity, including older completions of
  the same task. Its completer or an authorized owner may correct it under existing
  membership and visibility/private-task rights. Being merely a recorded helper
  does not grant new correction authority.
- Full undo removes only that completion's effects once. Preserve valid later
  completions, their actual timestamps and independent earnings/spending. Re-derive
  dependent chronological streaks/goals and still-open future dates from corrected
  history. Do not replace a wallet, room, task or checklist with its old snapshot.
- If the work really happened but coworkers/shares were wrong, correct attribution
  separately: preserve real completion time, occurrence, total effort and room
  effect. Apply only allocation differences and recalculate affected personal
  rhythm/recognition. This route cannot edit time, total effort or planned date.
- Additive wallet/contribution corrections use the historical allocated amounts;
  they never reprice unrelated entries or discard later debits. A genuine
  correction may make the booked or available balance negative. The
  [reservation policy](gamification-and-rewards.md#approved-accounting-contract-c3-2026-09-17)
  governs further requests and settlement, without reclaiming delivered rewards.
- Room correction is an ordered replay from a known baseline using valid events
  and their historical requested effects/clamps, not subtraction of a clipped
  delta. At room state 90, false +20 then valid +20 originally gives 100; undoing
  the first must still give 100 because the later event remains. A later explicit
  absolute state, if supported, remains authoritative. Rebuild timestamps from
  retained events rather than touching them at correction time.
- The current manual room endpoint is a delta operation, not an absolute set.
  Completion/manual mutations need one serialized event order and replay coverage;
  CR-SVC-63 already owns the manual-adjust locking gap. The reset/bulk-writer
  inventory and history boundaries must be specified too; this is not permission
  to introduce an absolute-setting UI or redefine destructive reset behavior.
- Record correction lineage, stable versions and frozen participant effects.
  Validate authority and versions under locks, and commit affected wallets,
  reservations and projections atomically. Repeated/concurrent commands must not
  duplicate corrections or celebrations. Preserve unrelated later checklist work.

These requirements guide the technical manifest, not a ratified schema or API.
Reuse the existing transaction/effect-record infrastructure; exact persistence,
lock order, reset boundaries and regression tests require the later Standard A
review. No general event-sourcing rewrite or runtime change occurs in this round.

### Completion synchronization (design intent — unimplemented)

- Completing in ChoreStrike marks the linked task as completed in Nextcloud tasks.
- Completing in a synced task client marks the task as completed in ChoreStrike.
- Completion timestamps should be normalized to server time and stored with timezone-aware values.

## Conflict Handling (design intent — unimplemented)

If two clients update the same task close in time:

- prefer latest update timestamp
- keep an event log entry for audit/debug purposes
- do not silently drop completion state changes

## Mobile Behavior (design intent — unimplemented)

- Reminder and completion status must work in standard mobile task workflows.
- ChoreStrike-specific visuals are optional on mobile clients; task state consistency is mandatory.

## Open Decisions

- Exact mapping strategy to Nextcloud Tasks fields where multiple reminder formats exist.
- Technical persistence/transaction and C5 local-time integration of the approved
  C1/C2/C3 rules, including
  pause lifecycle and interval restart; basic overlap, week attribution,
  chronological streaks and the selected agreement rules are no longer open.
- Whether the pruned Nextcloud-sync surface is revived at all, and with which
  transport (the removal recorded in `docs/api/README.md` and the
  `RouteMatrixTest` CS-055 guard stands until a consumer exists).
