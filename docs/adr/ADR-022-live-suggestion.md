# ADR-022: Live Suggestion

- **Status:** Accepted (2026-11-16); refined while implementing (CS-PLAN-091 WP-C, D-091-126 to D-091-135): team tasks, projected cycles, the stored list, the read contract; refined by the interim Full review of WP-C (D-091-137 to D-091-140): who takes overload, the first read, entries moved off today, joiners, the range, the loads
- **Deciders:** Operator decisions of 2026-11-09 to 2026-11-16 ([simplified core](../concepts/simplified-core.md#scheduler), CS-PLAN-091 D-091-11, D-091-12 and D-091-18)
- **Supersedes:** [ADR-012](ADR-012-actionable-household-scheduling.md) (the owner's preview and apply)
- **Builds on:** [ADR-021](ADR-021-derived-due-windows.md) (cycles and windows)

## Context

ADR-012 let the owner preview a seven-day plan and apply it as agreements.
Nothing suggested work on *Today* unless the owner ran it, and a later
assignment or date move was refused at a person's energy budget or a child's
limit. With derived windows (ADR-021) there are no stored appointments to apply
a plan to. The confirmed target picture asks for a suggestion that is computed
on read, honours preferred days, recalculates on changes, places team tasks by
one person, and shows overload instead of refusing
([scheduler](../concepts/simplified-core.md#scheduler)).

## Decision

### 1. Computed on read

`SuggestionService` places the open cycles of a house over a **horizon of 14
house-local days** from today (ADR-009). Nothing is applied and nothing becomes
an agreement. Input: the current cycles of ADR-021 (sleeping and bonus tasks
have none), each member's energy per weekday and availability
(`scheduling_preferences.energyBudget` and `weekdayAvailability`), their
preferred days, their
absences (`PauseProjectionService`) and today's valid completions.

- **Overdue cycles** are not placed: they sit in the red block. They count
  against today's remaining energy of the member who would do them, so the
  suggestion does not pile more onto that day.
- **Remaining energy** of a member on a day is that weekday's energy minus what
  is placed there; today also subtracts the energy of the member's valid
  completions of today.
- **The cycles after the current one** are placed too, so the later days of
  the horizon carry their recurring work: after a cycle comes the one its rule
  yields next, as if the work were done on the suggested day (an overdue cycle:
  today). A fixed rule continues on its next rule day. Such a cycle is a
  projection: it is no completion target and is never stored.
- A task that was done today is not suggested again for today (ADR-021
  decision 4: at most once a local day).

### 2. Placement rules (D-091-11)

1. **Order:** earliest window end first; ties by higher energy, then by
   room-task id.
2. **Who:** a task with one assignee goes to that assignee. A task with several
   assignees, or an unassigned one, goes to the eligible member whose day has
   the most remaining energy inside the window, ties by house-user id; a day
   the task fits comes before an overloaded one. *Eligible* means allowed to
   see the task (ADR-010), available that weekday and not absent. When the task
   fits nobody, a member available on a day of the window comes before one who
   has those days off, and one below a child's daily limit before one at it;
   then the most remaining energy decides. An unassigned task is suggested to
   that one member; for everyone else who may do it, it is only possible.
3. **Which day** inside the window and the horizon, never before today: the
   member's preferred day with the most remaining energy, else any available
   day with the most remaining energy (the earliest on a tie), else the last
   day of the window the member is available on as **overload**. A day of an
   absence is never suggested; a member who is absent for the whole window gets
   nothing suggested.
4. **Team tasks** are the tasks with several assignees. One of them carries the
   task with their budget alone; the other assignees see it on the same day,
   and their budgets neither block nor count it. (The `allow_teamwork` column
   has no writer and plays no part.)
5. **A child's daily limit** (`maxDailyTasks`, owner-set) is a second budget:
   at most that many cycles are placed on the child's day, so unassigned work
   beyond it goes to someone else. *Today* never suggests a child more than the
   limit: the child's own work above it stays visible as *also possible*, never
   hidden, and unassigned work that the day suggests to someone else is not
   offered to the child.

### 3. A stable list for today (D-091-18)

- The first read of a house-local day stores that day's placements for the
  house in `chorestrike_suggestion_days` (`house_id`, `day_key`, `placements`,
  `created_at`; unique `(house_id, day_key)`; `Version1038`). `placements` is
  bounded JSON, one `{r, k, u, p, o}` per cycle: the room task, the key of the
  cycle, the house-user ids it is placed on (the one who carries it first),
  the cycle's postponement count and whether it was placed as overload. No
  name and no Nextcloud user id is stored. Concurrent first reads converge on
  one row (insert-or-ignore, then read). The row is a cache, not a fact: no
  actor, no effect, no history value. The timeline job deletes rows older than
  seven days. A house time-zone change starts the new local day's row. The
  daily summary can be the day's first read. Every read, the first one
  included, then places from the stored list, so a reload never shows another
  plan for the same inputs. The write on a read is the one exception that
  [`security.md`](../standards/guidelines/security.md#no-mutations-on-get)
  names.
- During the day an entry **leaves** when its cycle is no longer current (done,
  undone into another key, re-anchored), was postponed, fell asleep, was
  deleted, was handed to someone else (the member who carries it is no longer
  an assignee or left the house), no longer contains today because an edit
  moved its window, or is no longer visible to the viewer. An undo brings an entry
  back. An entry **joins** when its room task was created after the day was
  stored and its window contains today, and when the list does not hold it
  and its window ends today: work that was handed over or edited onto today
  has no later day to move to, and without this rule nobody would be told
  about it before it is overdue. A joiner is placed on today, as overload
  when today has no room for it; when nobody can take it today (absent, or
  done today), it keeps the rest of its window from tomorrow on. A stored row
  that holds no list reads as an empty list. Every other change — an edit, a new absence,
  a changed budget — moves work only from tomorrow on.
- Days after today are always computed live.

### 4. Capacity shows, it never refuses

Assignment changes, task creation, edits and postponements are never refused at
a budget or a child's limit (this settles D-089-13). Overload — placed energy
above a member's budget on a day, or a cycle without a fitting day — is shown on
*Today* and in the calendar, whose week lists the placed energy against the
day's energy. `DailyCapacityService` went whole with its refusals in WP-B1
(D-091-29); the weekly plan's page and its client-side load view went with
WP-C.

### 5. Preferred days

`scheduling_preferences.preferredWeekdays` holds a member's preferred weekdays
(the `TaskService::WEEKDAYS` names). It is edited next to the energy per
weekday under *Settings → Planning*, with the same rights.

### 6. Read contract

`GET /api/v1/scheduling/suggestion?from=&to=` (house-local days) answers, for
the part of the range from today to the end of the 14-day horizon, per day the viewer-visible entries
`{roomTaskId, cycleKey, userIds, energy, overload}` with what a calendar row
shows (the window, the task and room names), the overdue cycles that count
against today, and the placed energy against the budget: the viewer's own,
and every member's for the owner, who manages the budgets. A projected cycle
carries `cycleKey: null`. The calendar reads the range. *Today* needs no
second request: its daily read (`GET /room-tasks/filtered-daily`) marks each
row the suggestion placed on today with `suggestedFor` and `overload`.
Visibility filtering follows the task reads (ADR-010); a member's load is a
sum of energy and names no task. The range is cut to the horizon before
anything is read or stored, so a client whose day ran over midnight still
gets the remaining days; a range wholly outside yields no days, and a last
day before the first is refused (`400`).

**What the loads and the overload marks reveal (operator, D-091-140).** The
placement counts all work, private tasks included. A load, the owner's read
of every member's load and the *does not fit* mark of an entry someone else
carries can therefore reflect work the viewer may not see: the owner can tell
that a member's day holds energy beyond the tasks the owner sees, and a member
can tell that someone else's day is full. No name, room, energy or existence of
a single private task is shown. This is accepted inside one house; hiding the
number alone would not hide the full day, which the marks show anyway.

### 7. Daily summary (D-091-12)

The digest counts the member's suggested work for today plus their overdue
tasks. The agreement lines and their relevance check go (BUG-UX-015); retained
agreement rows in the queue are skipped. The pause-request lines went with
ADR-020.

### 8. Retired surface

`scheduling#preview` (`POST /scheduling/suggestions`), `scheduling#apply`,
`SchedulePlanService`, `SchedulePlanner.vue`, the weekly plan's page
(`SchedulingView.vue`, `ScheduleCalendar.vue`, `OvercapacityWarning.vue`; its
address redirects to the calendar) with the load getters of
`store/scheduling.js`, and every capacity refusal after
creation (`DailyCapacityService::assertCommitmentsFit` and its callers,
`RoomTaskService::assertChildCapacityForDate`). ADR-012's server-side *Today*
eligibility (D4: assigned to the viewer or unassigned, before the child limit)
and the owner-only `maxDailyTasks` setting (D5) stay.

## Consequences

### Positive

- *Today* always has a suggestion, for every member, without an owner action.
- No write path can be blocked by a budget; overload becomes visible instead.
- The planner aggregate with its fingerprint and lock set disappears.

### Negative

- Every *Today* and calendar read runs the placement over 14 days. The request
  budgets of `WorkflowQueryBudgetTest` and the browser gates guard the cost.
- A GET writes the day's cache row once per house and day.

### Neutral

- The placement is deterministic for the same inputs; tests pin it.
- The suggestion is advice: anyone may do any visible task on any day.

## Alternatives Considered

- **Always live, nothing stored.** Rejected (D-091-18): an edit or an absence
  during the day would move work in or out of today's list, against the
  concept's rule that the list does not jump.
- **Applying the suggestion as agreements (ADR-012).** Rejected: it brings back
  the stored appointments ADR-021 removes.
- **A snapshot per member.** Rejected: one row per house and day serves every
  member and keeps team tasks consistent.

## Related

- Plan: [CS-PLAN-091](../plans/active/cs-plan-091-simplified-core-releases-b-and-c.md)
- Concepts: [simplified core](../concepts/simplified-core.md#scheduler), [energy and balancing](../concepts/energy-and-balancing.md)
- Digest: [ADR-016](ADR-016-opt-in-digest-notifications.md)
- API: [`docs/api/README.md`](../api/README.md) (Scheduling)
