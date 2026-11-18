# ADR-022: Live Suggestion

- **Status:** Accepted (2026-11-16)
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

### 2. Placement rules (D-091-11)

1. **Order:** earliest window end first; ties by higher energy, then by
   room-task id.
2. **Who:** a task with one assignee goes to that assignee. A task with several
   assignees, or an unassigned one, goes to the eligible member with the most
   remaining energy inside the window, ties by house-user id. *Eligible* means
   allowed to see the task (ADR-010), available that weekday and not absent.
3. **Which day** inside the window and the horizon, never before today: the
   member's preferred day with the most remaining energy, else any available
   day with the most remaining energy, else the window's last day as
   **overload**.
4. **Team tasks** (`allow_teamwork` with several assignees) are placed by the
   chosen person's budget alone; the other assignees see the task on the same
   day, and their budgets neither block nor count it.
5. **A child's daily limit** (`maxDailyTasks`, owner-set) is a second budget:
   at most that many cycles are placed on the child's day. *Today* never offers
   a child more than the limit; a cycle that fits nowhere waits as overload in
   the task list.

### 3. A stable list for today (D-091-18)

- The first read of a house-local day stores that day's placements for the
  house in `chorestrike_suggestion_days` (`house_id`, `day_key`, bounded JSON
  of `{roomTaskId, cycleKey, userIds}`, `created_at`; unique
  `(house_id, day_key)`). Concurrent first reads converge on one row
  (insert-or-ignore, then read). The row is a cache, not a fact: no actor, no
  effect, no history value. The timeline job deletes rows older than seven
  days. A house time-zone change starts the new local day's row.
- During the day an entry **leaves** when its cycle is no longer current (done,
  undone into another key, re-anchored), was postponed, fell asleep, was
  deleted or is no longer visible to the viewer. An entry **joins** when its
  room task was created today and its window contains today; it is placed live.
  Every other change — an edit, a new absence, a changed budget — moves work
  only from tomorrow on.
- Days after today are always computed live.

### 4. Capacity shows, it never refuses

Assignment changes, task creation, edits and postponements are never refused at
a budget or a child's limit (this settles D-089-13). Overload — placed energy
above a member's budget on a day, or a cycle without a fitting day — is shown on
*Today*, in the calendar and in the budget views. `DailyCapacityService` went
whole with its refusals in WP-B1 (D-091-29); WP-C rebuilds the load view on the
suggestion.

### 5. Preferred days

`scheduling_preferences.preferredWeekdays` holds a member's preferred weekdays
(the `TaskService::WEEKDAYS` names). It is edited next to the energy per
weekday under *Settings → Planning*, with the same rights.

### 6. Read contract

`GET /api/v1/scheduling/suggestion?from=&to=` (house-local days, from today, at
most the 14-day horizon) answers per day the viewer-visible entries
`{roomTaskId, cycleKey, userIds, energy, overload}` and per member the placed
energy against the budget. *Today* reads today's entries for the viewer; the
calendar reads the range. Visibility filtering follows the task reads (ADR-010).

### 7. Daily summary (D-091-12)

The digest counts the member's suggested work for today plus their overdue
tasks. The agreement lines and their relevance check go (BUG-UX-015); retained
agreement rows in the queue are skipped. The pause-request lines went with
ADR-020.

### 8. Retired surface

`scheduling#preview` (`POST /scheduling/suggestions`), `scheduling#apply`,
`SchedulePlanService`, `SchedulePlanner.vue`, the planner parts of
`store/scheduling.js` and `SchedulingView.vue`, and every capacity refusal after
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
