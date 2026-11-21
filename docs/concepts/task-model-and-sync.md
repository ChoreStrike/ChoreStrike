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
  (CS-PLAN-072 WP1 / ADR-013). With derived windows (ADR-021) the room task
  carries a first date and the current window is computed on read.
- priority
- energy cost (1-20)
- reminder settings (**planned — never implemented**: the column-less
  `reminderType`/`reminderMinutesBefore` entity fields and the whole
  `task_sync` HTTP surface were pruned in CS-PLAN-055; see *Reminder
  Configuration* below)
- multi-assignment on the room-task join rows, not a definition-owned user scalar
- a category key from the household's list (CS-PLAN-090 WP-I, FEAT-UX-053)

### Categories

The household keeps one ordered list of task categories, which an owner
edits in the household settings. It lives in typed app configuration, since
there is one house per instance, and needs no schema. Until an owner saves a
list, it holds the six keys the task form always offered, so tasks stored
before the list existed keep their meaning without a write. Keys are stable;
a built-in key keeps its translated name until an owner renames it. *Other*
is always last, keeps its name and cannot be deleted.

A task stores only the key. Deleting a category rewrites no task: a task whose
key is no longer listed counts as *Other* in lists and filters, and saving
that task stores a listed key. Task writes accept only listed keys; a new task
starts as *Other*, and a template keeps its key while the list holds it. The
task list filters by category. The list carries a revision, so two owners
saving at once cannot overwrite each other (`409`). API:
[`PUT /house/task-categories`](../api/README.md#put-housetask-categories).

## Creation Model (CS-PLAN-038, atomic since CS-PLAN-063)

Every user-facing task creation path materializes a **room task** — a task
definition alone is never user-visible output. Since CS-PLAN-063 the whole
aggregate (definition, room task, initial assignees) commits or rolls back
together in **exactly one request** — `POST /room-tasks` (atomic
`TaskCreationService` transaction; no orphan definitions on failure):

The one task form (CS-PLAN-091 WP-B6, FEAT-UX-040: one room button whose
floors open their rooms, preset when opened from a room; member-chip assignees; rhythm with its
flex days; energy; sub-tasks; the first date; the sleep switch; description,
instructions, priority, category and visibility under a collapsed *More*)
submits `{roomId, task, assigneeUserIds, checklistItems, firstDate, sleeping,
wakeOn}` once and gets the `{task, roomTask, assignees}` aggregate back.
*From template* only fills that form from `GET /setup/catalog`, the chosen
room's type first; whatever is saved is a task of its own (FEAT-UX-043).

Success means everything persisted; failure means nothing did and the form
stays open with the input preserved (no partial-success toasts). One closed
validation authority (service-owned) checks the definition, and children are
gated out of the form (`canManageTasks`, and the server's `403`). The former
definition-only `POST /tasks`, the three-request create chain (CS-PLAN-063),
the per-room template endpoint and the mount variant `{roomId, taskId}`
(CS-PLAN-091 WP-B6) are pruned; `RouteMatrixTest` guards them.

Scope model: **custom tasks are always room-scoped** (the old room/floor/house
scope selector was removed — decision D1). Whole-floor/house materialization is
a template/wizard capability, not a manual-form capability, and the setup
wizard gives every room a definition of its own (D-091-88).

**Editing (CS-PLAN-091 WP-B6).** The same form edits an existing task: *Edit*
in the opened row opens it, and one save is one atomic `PUT /room-tasks/{id}`
of the definition, the sub-tasks, the room, the assignees, the first date and
the sleep switch (BUG-UX-011) — a refusal never leaves it half applied. A
definition several rooms share asks first whether the change applies to *only
this room*, which splits off a copy for this room task, or to *all rooms*
(FEAT-UX-043). A move keeps the history with the task: completions and closed
cycles belong to the room task, and the room journal keeps every entry in the
room where it happened. The first date moves the first window only until the
first completion; afterwards the form shows the next window read-only and the
date of the latest entry moves it (ADR-021). The form deletes the task after a
confirmation (D-091-86). Assignment is multi-assign via member chips in the
form — there is no free-text assignee input and no bulk assignment
(D-091-87).

## Today View (CS-PLAN-038)

`/today` (*Today*, the landing page of every role since CS-PLAN-090 WP-B;
[calm core UX](calm-core-ux.md#today-is-the-one-landing-page-for-every-role))
is the single check-things-off surface: an *Overdue* block first, a *Due Today*
section and what is already possible today, with ≥44 px complete buttons. It composes
`GET /room-tasks/filtered-daily` (child-aware: weekday availability +
max-daily-tasks limits) with the overdue list, kept in a dedicated store slice
so Today items never bleed into the shared room-task lists. The default filter
is **My tasks** (assigned to me or unassigned/claimable); "Everyone" is one
tap away.
Completing a recurring task moves its next due date out of today — the row
shows its completion for a moment and then leaves the list (one-time tasks
keep their row with a completed state).
Under *Also possible today* the section also offers work whose flex window is
open although it is planned for another day (BUG-UX-005): Done credits that
occurrence on time, and it never counts toward a child's daily limit.
Celebration feedback (confetti, sounds, toasts) fires globally via the
`chorestrike:task-completed` event.

**Overdue boundary (CS-PLAN-084 D-084-08):** work is overdue only after its
occurrence window has ended in the house timezone — for a task without flex
days, after its due day is over. Work due today is never overdue, never shows a
claim button to others, and a sibling completing it the same day takes nothing
over. A takeover is recorded only when a non-assignee who is not obligated by
the occurrence's agreement completes a missed occurrence; an agreed handover
(amendment or catch-up with participants) is never a takeover. The
overdue-claim dialog is gone (CS-PLAN-091 WP-B6): a short tap on Done opens the
picker, where anyone records who did the work (ADR-021 §13).

The child availability/daily-limit filter applies to the daily query, not to
the whole overdue list or all completion paths. "My tasks" also includes
unassigned, claimable work. It is therefore not yet a definition of a child's
agreed obligations or a guaranteed maximum catch-up workload. The daily limit
caps only unassigned offers: a child's own tasks stay on Today even above it
(BUG-UX-013).

## Recurrence and Rhythm

### Derived due windows (ADR-021, CS-PLAN-091)

> Replaces the timeline contract C1, the agreement contract C2 and the delivered
> agreement surface below, which stay as the record of the history rows they
> wrote. Built on `feat/simplified-core-bc`; the shipped contracts hold until it
> merges ([ADR-021](../adr/ADR-021-derived-due-windows.md),
> [simplified core](simplified-core.md#due-windows)).

- **One current window per task, derived on read.** After completion: the last
  valid completion's work date plus the interval (without one, the first date).
  Fixed weekday or fixed day of the month: the rule's next day. One-time: the
  first date. The window is that centre day ± the flex days; nothing in the
  future is stored.
- **Completion at any time, except on the day of the previous one.** Early work
  is on time; on a fixed rule it fulfils the upcoming cycle and the day stays.
  The client names the cycle by its key and `cycle_version`; a second
  completion of one cycle is a conflict.
- **One red entry per task.** An after-completion or one-time cycle stays red
  until done late; a fixed cycle is missed once its window has ended and the
  next window has opened. There are no catch-up entries.
- **Postpone** (every member): up to three times per cycle by half the flex
  days, rounded up, only before the window ends; not for tasks without flex
  days.
- **Sleeping tasks** have no window; completing one wakes it. **Bonus tasks**
  are never due and pay at most once per person, task and day.
- **Corrections:** a member changes the date of, or deletes, the latest entry of
  a task that they recorded; the owner corrects any entry, including the
  person. The first date replaces *last done on*.
- **History:** closed cycles live in `chorestrike_cycles`; a completion is
  classified `on_time`, `late` or `bonus` once, at completion. The recognitions
  read stored occurrences for windows that ended before the house's
  `cycles_from` day and cycles from that day on.

### Before derived windows (history)

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
  tasks with availability, energy and child-limit checks for every participant
  ([balancing](energy-and-balancing.md)). Dated member/house pauses are delivered
  by WP2 (*Delivered agreement & pause surface* below).
- Due dates, windows and the deadline used to judge "on time" share one
  occurrence contract (WP1, ADR-013); the personal rhythm streak evaluates
  exactly those agreed occurrences (WP4, ADR-015).

The family-rhythm [clarification plan](../plans/done/cs-plan-070-family-rhythm-and-reward-clarification.md)
covers strict weekdays, rescheduling, missed occurrences, shared assignments,
and non-punitive recovery. WP1-WP4 implement the approved rules below.

### Approved Target Rules (2026-09-17; implemented by WP1-WP4)

- Each task supports an explicit choice between fixed calendar cadence and an
  interval after actual completion. Early completion does not move a fixed
  calendar cadence; it does move a completion-relative interval.
- By default a chore is on time on its planned day through day-end. The household
  can instead agree an earlier/later window for that task, such as Friday-Sunday
  for a Saturday chore. One completion fulfills that occurrence exactly once.
  The window (flex days, symmetric) is at most half the recurrence period,
  rounded up — every 6 months → 90 days, weekly → 4, daily → 1 (FEAT-UX-039,
  [API](../api/README.md#tasks)); neighbouring windows may then share a day, and
  the earliest deadline is the target there. Occurrences of a fixed calendar
  cadence are agreed seven days ahead, so they open at most seven days early.
  Widening the flex days applies at once to occurrences still open; narrowing
  applies from the next one, so an agreed window never shrinks (D-088-17).
  A changed energy applies at once to every occurrence still open; completed
  work keeps its energy (BUG-UX-014).
- Recording when a task was last done (FEAT-UX-045) is retired with ADR-021
  (D-091-19): a new task's first date sets its window until the first
  completion. Retained "last done" entries keep showing as "Last cleaned" in
  the room journal.
- Repeated misses produce one visible catch-up item per recurring chore, not an
  accumulating list of identical cards. Missed occurrences remain historical
  misses; completing once does not turn every missed occurrence into a success.
- Normal chores have one responsible person plus optional helpers; tasks needing
  several people are explicitly joint work. Voluntary help is not an additional
  obligation. A pre-deadline agreed handover releases the former responsible
  person neutrally; unplanned substitution does not grant them personal success.
- Ordinary date/window amendments must be agreed before the existing deadline
  expires. Later replanning does not turn a historical miss into on-time work.
- Everyone manages their own dated absences without a request; this replaced
  the child request with owner confirmation
  ([ADR-020](../adr/ADR-020-absences-without-requests.md), 2026-11-10). Owners
  manage house-wide absences and may explicitly enter one for someone else
  afterwards. This exception must be traceable, not hidden repair.
- Timeline attribution, calendar boundaries and the agreement/pause rules are
  specified below and implemented by WP1-WP2 (ADR-013).

### Approved Timeline Contract C1 (2026-09-17)

> **Replaced by [derived due windows](#derived-due-windows-adr-021-cs-plan-091)
> (ADR-021).** Its rows stay as history.

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

> **Replaced by [derived due windows](#derived-due-windows-adr-021-cs-plan-091)
> (ADR-021):** postponement and date corrections instead of agreements; the
> pause part was already replaced by ADR-020.

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
- An undecided pause request (only rows stored before ADR-020; no command
  creates one any more) stays visibly pending and changes no obligation,
  deadline or goal. It neither auto-extends the window nor auto-rejects.
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
- Shortening or deleting an acknowledged pause takes effect no earlier than the next
  local day: the subject for an own one, the owner for any (ADR-020). A deleted
  absence that has not started leaves no coverage at all. Already excused obligations for today or
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

> **Agreement part retired by ADR-021** (amend, catch-up, restart and the
> capacity refusals go); the pause part lives on as absences (ADR-020).

The C1/C2/C3 contracts above are **implemented** by the WP2 package
(ADR-013 §WP2, corrected in the 2026-09-25 fix round): owner-recorded
agreement commands (`amend`, `catch-up`, `restart`) under receipt identity
(action + all normalized semantic inputs), command-boundary staleness gates
(version + recomputed selection token + effective suspension stamp),
persisted neutral handover releases with consent evidence, follow-up
date/catch-up validation against the **concrete** current participants
(never the replaced revision defaults), catch-up appointments (date + zone)
that survive participant changes and project independently of pauses,
revisioned pause groups (personal/house; child requests pending until
[ADR-020](../adr/ADR-020-absences-without-requests.md), which acknowledges
every role directly and adds deletion;
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
was `/api/v1/occurrences/{key}/{amend,catch-up,restart}` (retired with
ADR-021, CS-PLAN-091 WP-B1) and `/api/v1/pauses*`.
ADR-020 (CS-PLAN-090, 2026-11-10) amends the pause part: every role is
acknowledged directly, `DELETE /api/v1/pauses/{id}` appends a `cancelled`
revision that governs from the next local day, and the surface is the
Absence view.

## Reminder Configuration

> **Status (verified 2026-08-29, CS-PLAN-055):** not implemented. What existed
> was an API-only stub: the `task_sync` routes ×6 + `TaskSyncController`/
> `TaskSyncService` (completion mirror, reminder read endpoint, pending-sync
> queue, mark-synced, link) had no UI consumer and were **pruned**. The
> `Task` entity carried `reminderType`/`reminderMinutesBefore` without any
> backing columns (the only reader was `TaskSyncService`) — deleted, too.
> Re-introducing this surface needs a real CalDAV/Task interaction design and
> a consumer (FEAT-UX-021 closed as moot by the prune).

Per-task reminder settings remain a historical proposal. WP5 replaces
individual due/streak/award notifications with the optional, quiet-by-default
combined daily digest and self-only Settings preferences. The house-local
schedule, quiet range and shared per-recipient budget are described in
[Notifications](notifications.md); timely delivery still requires Nextcloud
cron to run within the bounded same-day window.

The earlier per-task reminder ideas below are not the selected first delivery
scope; repeated overdue reminders are not an approved default.

**Approved target (2026-09-17; WP5 delivery implemented):** task reminders are off by
default. Members can enable at most one summary per person/day at their chosen
time, only for relevant planned work, never on free/paused days or with streak
threats. Respect quiet hours. C5 below defines delivery and category policy. The per-task
ideas below are historical alternatives, not the approved first reminder scope.

### Approved Delivery Contract C5 (2026-09-17)

The WP5 backend and minimal Settings surface implement the delivery slice;
the complete calm-UX redesign and sound/animation consumers remain WP6.
Use household-local time, never device/travel timezone, for the
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

1. ChoreStrike UI (Today, task list)
2. *(planned)* Connected task clients through Nextcloud task completion
   status — see the prune note above; there is no external-completion path
   today.

### Completion and undo model (WP3, ADR-014 — implemented 2026-09-27)

> **Amended by ADR-021 (CS-PLAN-091):** the completion targets the current
> cycle; anyone may record it for other members who may see the task; a date
> correction joins undo and attribution. Members correct or delete only the
> latest entry of a task that they recorded; attribution is owner-only.

> **Supersedes the CS-PLAN-064 WP5 snapshot-reversal model** (kept in
> [ADR-011](../adr/ADR-011-transactional-completion-and-undo.md) as the
> historical record). The 2026-09-16 "snapshot restore is not an exact inverse"
> limitation is closed: corrections now re-derive from the valid history
> instead of restoring snapshots.

Completion, undo and attribution share one transactional aggregate with one
lock order and one command-receipt seam (every mutation carries a client
`commandId` UUID; an identical retry replays the stored outcome, a changed
payload under the same UUID conflicts). The domain model:

- **Work is the immutable fact.** A completion freezes the attributed work
  evidence — the occurrence identity (`schedule_rev_id`, `occurrence_ordinal`),
  house-local `work_date`/`work_zone`, `occurrence_due`, `total_energy` and
  the frozen `rate_minor` — and records the actual participants as the
  selected allocation revision (`work_shares`: wallet, exact share, minor
  units, actor, review confirmation). The recorder need not take part
  (ADR-021 §10): anyone records work for anyone who may see the task, and the
  completion keeps who recorded it. Every sub-task of the cycle is done with
  the completion.
- **Rewards are exact derivations, never minted integers.** `energyMinor =
  totalEnergy × sharePct`, `coinMinor = 10 × totalEnergy × sharePct` at the
  frozen rate; wallet postings are immutable `wallet_entries` rows keyed by
  `(wallet, source-kind, source-id, source-revision)` so every command commits
  exactly once. Totals are conserved by construction.
- **Undo reverses the latest selected allocation exactly once.**
  `POST /completions/{id}/undo` (the owner for any entry; another member only
  for the latest valid entry of a task that they recorded, ADR-021 §9)
  reopens the cycle the completion closed, posts the exact negative wallet-union deltas, replays
  the room journal (the undone requested effect drops out; the correction
  execution time never becomes `last_cleaned`) and recomputes the personal
  recognition of everyone whose credit changed. No revertible target → a
  zero-write `undone: false` success. The receipt/version staleness stays a
  `409`.
- **Attribution corrects who actually did the work.**
  `PUT /completions/{id}/attribution` (the owner alone, ADR-021 §9;
  participation and having recorded it are no authority) rewrites the
  selected allocation with the explicit deviation confirmation. The reversal union is keyed by **wallet id from the
  share rows**: a departed participant's retained wallet is always reversed,
  so a completion pays out exactly the frozen total and never more. The
  frozen work facts and the room journal stay immutable; the audit chain
  (`completion_revs`) is append-only and a departed-not-erased actor renders
  as the localized 'Former member' placeholder.
- **A date correction moves when the work was done.**
  `PUT /completions/{id}/date` (the rights of the undo) moves the work day
  between the days of the room task's neighbouring valid entries, never into
  the future. The classification is decided again against the frozen window
  of the entry's cycle; the room journal folds the work at the corrected
  instant; under an after-completion rule the cycle that followed and the
  current window move with the day. Shares, energy and coins stay. The audit
  chain records it as a `date` revision.
- **An undone completion is not a completion anywhere.** Undone rows are
  excluded from the weekly-goal count and the rhythm evaluation; the
  supported misclick flow is undo-then-re-complete (a fresh command identity).
- **The client reconciles from the one response.** Every owning store
  (room tasks, rooms, game, user) updates from the authoritative payload
  **before** the first UI event fires (CS-PLAN-066 D10); the payload carries
  frozen work facts, the selected allocation and the caller's own wallet
  totals in minor units. The global undo snackbar stays a queued list
  (UX-015), and the activity feed exposes the authorized older-completion
  correction through the viewer-scoped `canCorrect` flag (WP3 §3.6).

### Structural deletion and account erasure (CS-PLAN-071 WP0, implemented)

Domain behavior of the WP0 substrate (mechanism detail in
[architecture.md](../engineering/architecture.md) and
[development.md](../engineering/development.md)):

- **Deleting structure keeps the history.** Deleting a room task, a room or a
  floor tombstones the rows (`deleted_at`, room tasks also `deleted_token` +
  `is_active = 0`) instead of destroying them: the rows vanish from every
  active-planning view, the `completed_tasks` evidence is retained with its
  existing visibility, and earned effects (coins/streaks/medals) stay valid.
  Undo of a completion on a tombstoned room task stays authorized under the
  rights above. Re-adding the same task to the same room creates a
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

> **Amended by ADR-021 (CS-PLAN-091):** see the note on the completion and undo
> model above.

Implemented by WP3 (CS-PLAN-074, ADR-014); it supersedes the ADR-011 snapshot
restore — see *Completion and undo model* above.

- Target a specific completion by stable identity, including older completions of
  the same task. An owner may correct any of them; another member only the latest
  valid entry of a task that they recorded themselves (ADR-021 §9, D-091-23), under
  existing membership and visibility/private-task rights. Being merely a recorded
  helper does not grant correction authority.
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
