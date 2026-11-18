# ADR-021: Derived Due Windows

- **Status:** Accepted (2026-11-16)
- **Deciders:** Operator decisions of 2026-11-09 to 2026-11-16 ([simplified core](../concepts/simplified-core.md), CS-PLAN-091 D-091-01 to D-091-23)
- **Supersedes:** [ADR-013](ADR-013-occurrence-agreements-and-corrections.md), except its pause part, which [ADR-020](ADR-020-absences-without-requests.md) already amended
- **Amends:** [ADR-009](ADR-009-per-house-timezone.md) (a zone edit moves open windows), [ADR-011](ADR-011-transactional-completion-and-undo.md) (the completion target), [ADR-014](ADR-014-work-accounting-ledger-and-replay.md) (corrections, recording for others, coin factors, recognition inputs), [ADR-015](ADR-015-ranking-consent-and-privacy.md) (the ranking reads cycles) and [ADR-016](ADR-016-opt-in-digest-notifications.md) (agreement receipts leave the digest queue)

## Context

ADR-013 made stored occurrences the recurrence authority. Every rule was
materialized a week ahead; a date change was an owner agreement with consent; a
missed occurrence stayed as its own catch-up entry; work could be completed only
inside its window. The family trial on `0.8.0-alpha.1` to `0.8.0-alpha.4` found
this stricter than a family needs ([why](../concepts/simplified-core.md#why)).
The operator confirmed the target picture on 2026-11-10: what is due follows from
when a task was last done and how often it repeats, every member may act, and
the owner can take anything back.

ADR-013 rejected deriving occurrences on read because ordinals and identity would
shift with the query window, which breaks public keys, checklist scoping and
completion receipts. This ADR answers that objection with a cycle key that does
not depend on any query window (decision 2).

The installation is a retained baseline (`0.8.0-alpha.1`, 26 applied migrations,
`Version1034` since). Stored occurrence, agreement, schedule-revision, pause and
checklist-progress rows stay as history and are never rewritten. Schema changes
are new ordered steps.

## Decision

### 1. One current window per task, derived on read

A room task has at most one **current cycle**. Its window is a centre day plus
and minus the task's flex days, in house-local days (ADR-009); as instants it is
half-open, from the start day's local midnight to the local midnight after the
end day. `WindowService` computes it from the rule, the stored inputs of
decision 3 and the valid completions. Lists, *Today*, the calendar, the
completion command and the timeline job use this one read path.

| Mode (definition) | Centre of the current cycle |
|---|---|
| After completion (`scheduling_mode = dynamic`) | the work date of the last valid completion plus the interval; without one, the first date |
| Fixed weekday (`static`, weekly, `fixed_schedule_days`) | the next rule day from the anchor, as `RecurrenceCalculator` enumerates it today (fortnightly on the anchor's Monday) |
| Fixed day of month (`static`, monthly, `fixed_month_day`) | that day of the month, clamped to the month's last day |
| One-time (`recurrence_mode = once`) | the first date |

- **Flex days** are symmetric (`tasks.flex_days`, the existing `FlexDaysPolicy`
  cap of half the period, rounded up). New definitions default to ± 1 from a
  weekly period on and to 0 below it.
- **Open work is not frozen.** A changed rule, flex or energy applies to the
  current cycle at once; completed work keeps what it recorded.
- **Which fixed cycle is current.** Windows of a fixed rule may overlap (the cap
  allows it). A fixed cycle stays current until it is done, or until its window
  has ended *and* the next cycle's window has opened; then the next cycle is
  current and the old one is missed (decision 5). After a completion the next
  rule day becomes current, so an early completion fulfils the upcoming cycle
  and the day stays.
- **Bounded rules** (`active_from`, `active_until`) only have cycles whose
  centre lies inside the bounds. A one-time task, or a bounded one after its
  last cycle, ends when done, as today.
- **Overdue** means the current window has ended. It is one red entry per task,
  whatever the number of cycles that passed. After-completion and one-time
  cycles stay current until done; a fixed cycle stops being red when the next
  window opens.
- **The next window is never edited directly.** It moves through the first date
  (until the first completion), a date correction (decision 9) or a postponement
  (decision 6).

### 2. Cycle identity

A cycle has a **cycle key** that depends only on its anchor, never on a query:

- `c<completionId>` for an after-completion cycle anchored on a valid completion;
- `d<Y-m-d>` for a cycle anchored on a day: the first date or a wake date of an
  after-completion or one-time task, or the centre day of a fixed cycle.

The key changes only when the anchor changes (a completion, its undo, a date
correction, an edit of the first date, the rule mode or a fixed rule's days).
Clients send the key they showed; a key that is no longer current is a stale
target.

### 3. What is stored

No future cycle is stored. New ordered steps add (exact names are binding for
the implementation and the populated-upgrade tests):

- `room_tasks`: `anchor_on` (date, nullable: the first date of an
  after-completion or one-time task until its first completion, and the parity
  anchor of a fixed rule, which stays; an edit that changes a fixed rule's days
  moves it to the centre of the replaced rule's current cycle, D-091-44),
  `cycle_key` (string 40, nullable),
  `postpone_count` (small int, default 0), `postpone_days` (int, default 0) for
  the postponement of the cycle named by `cycle_key`, `cycle_version` (bigint,
  default 0), `sleeping_since` (datetime, nullable) and `wake_on` (date,
  nullable).
- `tasks`: `fixed_month_day` (small int 1–31, nullable). *Bonus* is the existing
  `is_voluntary` flag (decision 8).
- `completed_tasks`: `cycle_key` (string 40, nullable), `window_start_on` and
  `window_end_on` (dates, nullable), `bonus_percent` (small int, nullable) and
  `coin_factors` (bounded JSON, nullable). The classification uses the existing
  `completion_class` with the new values of decision 5.
- `house_users`: `coin_factor` (small int, default 1), `streak_base` and
  `longest_streak_base` (ints, nullable).
- `houses`: `cycles_from` (date, nullable): the first house-local day of the
  derived model.
- New table `chorestrike_cycles`: one row per **closed** cycle, unique
  `(room_task_id, cycle_key)`, with `house_id`, `window_start_on`,
  `window_end_on`, `week_start` (the house-local Monday of the window's end),
  `timezone`, `outcome` (`done` | `missed`), `completion_id`,
  `completion_class`, `energy`, `responsible` (bounded JSON of wallet ids) and
  `created_at`; indexed on `(house_id, window_end_on)` (D-091-17).
- New table `chorestrike_cycle_progress`: sub-task ticks per cycle, unique
  `(room_task_id, cycle_key, checklist_item_id)`, with `revision`, `is_checked`,
  `checked_at` and `checked_by_user_id`. The occurrence-keyed
  `chorestrike_chk_progress` stays as history; its unique key cannot take rows
  without an occurrence.
- New table `chorestrike_bonus_periods` (decision 11).

`room_tasks.next_due` stays as a projection of the current window's end day for
the list queries that filter and sort by it. Every command that changes a window
updates it in its own transaction; the timeline job repairs only what the
passing of time changes. `cycle_version` bumps with every change of the current
cycle: a completion, an undo or date correction that moves it, a postponement,
sleeping and waking, and every edit of the rule, flex, energy, assignees or
first date.

### 4. Completion

`POST /room-tasks/{id}/complete` takes a closed body:
`{commandId, cycleKey, expectedVersion, participants?, allocationReviewed?}`
(`cycleKey` is absent for a bonus task). Under the house-first lock (ADR-011)
the server derives the current cycle, compares the key and `cycle_version`
(`409 STALE_TARGET` otherwise) and refuses a second valid completion of the same
cycle (`409 ALREADY_COMPLETED`). A repeated `commandId` replays the stored result
without a second effect. The unique key of `chorestrike_cycles` backs the rule in
the database; completions keep no unique index, because an undone completion
keeps its row (D-091-08).

- **Any time, except the same day.** A task can be completed before its window
  opens, inside it or late. It cannot be completed on the house-local day of its
  latest valid completion (`409 SAME_DAY`); bonus tasks have their own rule
  (decision 8; until it ships with WP-B4 the day rule holds for them too,
  D-091-42).
- **The completion closes the cycle** in its transaction: it writes the
  `chorestrike_cycles` row (`outcome = done`), freezes the cycle facts on the
  completion and resets the postponement. Every sub-task of the cycle is marked
  done inside the same command. Ticking only some sub-tasks goes through the
  checklist route and leaves the task open (D-089-02).
- **Undo** marks the completion undone as today and deletes its cycle row, which
  only projects the completion's frozen cycle facts; the cycle reopens and the
  next current cycle derives again. A fixed cycle that does not become current
  again because a later one is done, and whose window has ended, is recorded as
  missed instead (D-091-43). The audit chain keeps the undo. Work facts,
  shares and ledger rows follow ADR-014.
- **Sleeping tasks** have no cycle. Completing one wakes it: the completion
  fulfils a fresh cycle anchored on its day, and the rhythm continues from it
  (D-091-22).

### 5. Classification and recognitions

A completion is classified once, at completion, and frozen with it (D-091-02):
`on_time` when it is done before or inside its window, a postponement included;
`late` after the window; `bonus` for a bonus task. The values `agreed`,
`late` and `voluntary` of earlier rows keep their meaning.

- **Missed cycles.** Only a fixed cycle can be missed: the timeline job writes
  its row (`outcome = missed`) once the cycle stopped being current
  (decision 1), exactly once under the unique key, also under concurrent job
  runs. A completion records the misses before its own cycle that the job had
  not reached yet, in its transaction (D-091-43). No window that ended before
  its room task was created counts as missed. An after-completion or one-time
  cycle is never missed; it stays red until it is done late.
- **The week of a cycle** is the house-local week its final window ends
  (D-091-02). An open red cycle counts as not kept in that week while it is
  open, and stays not kept when done late.
- **Responsibility.** A cycle counts for the room task's assignees when it
  closes (`responsible`). An unassigned cycle counts for the household only, as
  an unassigned occurrence does today.
- **Absence is neutral** (D-091-03). A window that lies entirely inside an
  absence of a member counts neither as kept nor as missed for that member.
  The recognitions decide this on read through `PauseProjectionService`, so an
  absence entered or deleted later is honoured. The red entry still shows.
- **The recognitions keep their definitions** and read cycles where they read
  agreed occurrences: the rhythm and its streaks, the weekly goal and its claim
  receipts, and the consent-filtered ranking (ADR-015). Bonus completions count
  for none of them. Arcade unlocks count valid work shares as before (ADR-019).
  The coin factors of decision 11 reach none of them.

### 6. Postponement

`POST /room-tasks/{id}/postpone` with `{commandId, cycleKey, expectedVersion}`.
Every member who may see the task, children included, may postpone. Each press
moves the current window by half the flex days, rounded up, up to three times
per cycle. A task without flex days cannot be postponed (`409 NOT_POSTPONABLE`),
nor can a cycle whose window has ended (`409 WINDOW_ENDED`; a miss stays a
miss, D-091-21). A postponed window still counts as on time. The next cycle is not
affected: a fixed rule's next window keeps its days, and an after-completion
rule derives the next window from the completion as usual.

### 7. Sleeping tasks

`PUT /room-tasks/{id}/sleep` with `{commandId, expectedVersion, sleeping,
wakeOn?}` (adults and the owner). A sleeping task has no cycle: no window, never
overdue, no capacity, no suggestion. On `wake_on` (the timeline job) or when it
is woken, it starts a fresh cycle: an after-completion or one-time task is
anchored on the wake day, a fixed rule continues with its next rule day.

### 8. Bonus tasks

A definition with `is_voluntary` is a bonus task. It is never due, never
overdue and never suggested, and *Today* lists it in its own section. It pays
energy and coins. Each credited member may be credited at most once per bonus
task and house-local day (`409 BONUS_ALREADY_TODAY`). Its completions carry
`bonus` and count for no recognition. Existing voluntary definitions become
bonus tasks at the cutover.

### 9. Corrections and rights

The [rights table](../concepts/simplified-core.md#people-and-rights) applies,
enforced in the services:

| Action | Child | Adult | Owner |
|---|:-:|:-:|:-:|
| Complete, also early and for someone else; postpone | yes | yes | yes |
| Change the date of, or delete, the latest entry of a task that one recorded | yes | yes | yes |
| Create and edit tasks: rule, energy, assignees, room, first date, sleeping, bonus | no | yes | yes |
| Correct any entry: date, person, delete | no | no | yes |
| Set coin factors, start bonus periods | no | no | yes |

- **An entry one recorded** is a completion whose `completed_tasks.user_id` is
  the actor. *Latest* is the latest valid completion of that room task.
- **Date correction** (`PUT /completions/{id}/date` with `{commandId,
  expectedVersion, date}`, a house-local day, never in the future and never on
  or before the day of the room task's previous valid completion) is a new
  audited correction kind in `completion_revs`. It re-derives the completion's
  classification against its cycle's window, the cycle row, the room journal
  replay and the weekly goal. For an after-completion rule it also re-derives
  the window and the classification of the cycle that followed it.
- **Delete** is the existing undo (`POST /completions/{id}/undo`).
- **Person** is the existing attribution (`PUT /completions/{id}/attribution`),
  now owner-only.

This narrows two ADR-014 rights (D-091-23): a completer who is not the owner
may delete and correct only the latest entry of a task, and no longer
re-attributes.

### 10. Recording for others

`participants` may leave the actor out; this reverses Q-074-02. Every credited
member must be allowed to see the task under ADR-010's matrix (public: every
member; private: its creator and the room task's assignees; admin-only: the
owner), otherwise `403` (D-091-20). `completed_tasks.user_id` keeps the
recorder; the history shows it when it differs from the people credited. Taking
over someone else's red entry keeps its digest receipt.

### 11. Coin factors and bonus periods

- The owner sets a **coin factor** of 1, 2 or 3 per member
  (`PUT /house/members/{userId}/coin-factor`).
- The owner starts a **bonus period** of one to three house-local days with
  × 1.5 (`POST /house/bonus-periods`) and may cancel one that has not ended
  (`DELETE /house/bonus-periods/{id}`). Periods do not overlap. `GET /house`
  carries the running or next period.
- A completion freezes the factors of every member of the house
  (`coin_factors`, wallet id to factor) and the bonus percent of its work day
  (`bonus_percent`, 100 or 150). Each share's coins are
  `10 × E × P × factor × bonus_percent / 100` coin minor, rounded down once
  after the product (D-091-15). Attribution uses the frozen map (a member
  missing from it counts × 1); undo reverses the stored shares exactly.
- Energy, the weekly goal, the rhythm, the ranking and the arcade unlocks never
  see a factor.

### 12. Cutover

The schema step `Version1035` adds `houses.cycles_from`; the data-only step
`Version1036` sets it to the house-local day of the update for every existing
house (D-091-41): stored occurrences can no longer be fulfilled once the update
runs, so a window that ends on that day already belongs to the derived model.
A new house uses its creation day.

- **Recognitions** read stored occurrences whose window ends before
  `cycles_from`, and `chorestrike_cycles` plus open cycles from that day on, so
  no cycle counts twice and no past week is evaluated again (D-091-04). A
  stored occurrence that a completion from before the cutover fulfilled is
  read as stored even when its window reaches past that day; one that was
  still open then is left to the derived cycle of its room task. The job
  records no missed cycle whose window ended before `cycles_from`, nor one whose
  window ended before its room task was created (a past first date).
- **Stored streaks and claimed weeks stay.** The step copies each member's
  current and longest streak into `streak_base` and `longest_streak_base`; the
  rhythm continues from them.
- **First windows.** An after-completion task with a valid completion anchors
  on its latest one and gets no stored anchor (D-091-27). A task without a valid completion gets `anchor_on` from
  the planned date of its earliest open occurrence, else from its current
  revision's anchor. A fixed rule takes `anchor_on` from its current revision's
  anchor and, for a monthly rule, `fixed_month_day` from that anchor's day.
- Voluntary definitions become bonus tasks. Existing pauses stay valid as
  absences.
- Sub-task ticks of an appointment that was open at the cutover stay in
  `chorestrike_chk_progress` as history; the first derived cycle starts
  without ticks (D-091-30).
- The data step fills empty columns only and is safe to repeat; on a fresh
  installation it finds no rows.

### 13. Retired surface

`occurrence#amend`, `occurrence#catchUp`, `occurrence#restart`,
`room_task#updateDueDate` and `room_task_last_done#update` go with their
services (`AgreementService`, `OccurrenceAgreementService`,
`RoomTaskLastDoneService`) and their digest queue writes. There is no
single-cycle handover and no restart (D-091-10): assignees change in the form
and anyone may record for someone else; sleeping replaces pausing a task. The
first date replaces *last done on* (D-091-19); retained `last_done` journal
events keep replaying. Old rows of every retired table keep their read paths
for history.

## Consequences

### Positive

- One rule decides what is due, and nobody has to negotiate it. The family's
  three complaints (strict windows, requests, catch-up piles) disappear.
- Writes shrink: no materialization, no agreements, no consent evidence.
- History stays honest: closed cycles and completions are frozen facts, and
  every change to them is an audited correction.

### Negative

- Reads compute windows. The request budgets of `WorkflowQueryBudgetTest` and
  the browser gates guard the cost; a cache is its own reviewed step (D-091-09).
- The recognitions read two sources split at `cycles_from`.
- `CompletionService` is already the largest named aggregate. Window logic,
  cycle targeting and the factor math go into new collaborators.

### Neutral

- Every stored occurrence, agreement, schedule-revision, pause and
  checklist-progress row stays; nothing is deleted or reset.
- Old completions keep `schedule_rev_id`, `occurrence_ordinal` and their
  classification; new ones carry the cycle facts instead.

## Alternatives Considered

- **Closed cycles in `chorestrike_occurrences` with a model marker.** Rejected
  (D-091-17): `schedule_rev_id` and `ordinal` are not null under a unique key,
  so the step would rebuild a populated table on SQLite or invent revisions.
- **No cycle rows, misses derived on read.** Rejected: a rule edit would rewrite
  which past cycles were missed.
- **A sequence number as the cycle key.** Rejected: undoing an older completion
  would renumber or leave gaps that clients cannot tell from a stale key.
- **Keeping *last done on* as a separate input.** Rejected (D-091-19): two inputs
  for one anchor; the first date and the date correction cover it.

## Related

- Plan: [CS-PLAN-091](../plans/active/cs-plan-091-simplified-core-releases-b-and-c.md)
- Concept: [simplified core](../concepts/simplified-core.md), [task model](../concepts/task-model-and-sync.md)
- Scheduler: [ADR-022](ADR-022-live-suggestion.md)
- Absences: [ADR-020](ADR-020-absences-without-requests.md); visibility: [ADR-010](ADR-010-invite-only-membership-visibility-matrix.md); time zone: [ADR-009](ADR-009-per-house-timezone.md)
- API: [`docs/api/README.md`](../api/README.md) (Room Tasks, Completions, House)
