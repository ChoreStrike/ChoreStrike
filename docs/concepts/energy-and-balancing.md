# Energy Model and Workload Balancing

## Goal

Replace abstract difficulty stars with a practical effort model that improves fairness and weekly planning.

## Energy Unit

- Each task costs 1-5 energy.
- 1 energy corresponds to roughly 5 minutes of effort.
- The conversion is intentionally approximate and should not be treated as exact time tracking.

## Estimated Duration Table

| Energy | Approximate effort |
|---|---|
| 1 | 5-10 minutes |
| 2 | 10-15 minutes |
| 3 | 15-20 minutes |
| 4 | 20-25 minutes |
| 5 | 25-30 minutes |

## Weekly Capacity Planning

Users can define personal energy budgets by weekday.

Example profile:

- Monday-Friday: 5 energy per day
- Saturday-Sunday: 15 energy per day

The scheduler should distribute due tasks against these daily limits.

## Presence Days and Fairness

Each member can define availability by weekday.

Examples:

- children unavailable Sunday-Wednesday
- parent unavailable on weekends

Balancing must exclude unavailable days from assignment calculations.

### Fairness constraints

- Never assign tasks to members on unavailable days.
- Weekly fairness comparisons should normalize by each member's available energy capacity, not by raw task count.

**Implemented (CS-PLAN-040):** the Insights `MemberFairnessTable` normalizes
load per member as `completed energy / weekly capacity`, where completed
energy is the Σ `energyCost` of the member's completions in the activity
window (`GET /house/activity`) and weekly capacity is Σ `energyBudget` over
the member's available weekdays from their serialized
`schedulingPreferences` (fallback budgets 10 weekday / 15 weekend).
Contribution score and coins are always shown as separate columns — they are
never merged into one metric.

## Balancing Strategy (MVP)

1. Filter tasks by due window.
2. Exclude members unavailable on each day.
3. Sort tasks by urgency and priority.
4. Assign tasks to members with the highest remaining energy capacity for that day.
5. Roll excess tasks to nearest valid day within configurable tolerance.

## Overcapacity Policy (Locked)

If total due energy exceeds available capacity:

- defer lower-priority tasks first
- keep higher-priority tasks in the nearest available slots
- show a scheduling warning that explains which tasks were deferred and why

**Implemented (CS-PLAN-040):** the scheduling-store getter
`overcapacityDays()` computes planned energy per day (Σ `energyCost` of
active room tasks due that day — the canonical planned-energy source) against
`getCapacityForDay` (member totals for owners; own preferences with the 10/15
fallback for non-owners). `SchedulingView` renders `OvercapacityWarning`
listing each overloaded day with its would-be-deferred tasks sorted lowest
priority first; `ScheduleCalendar` marks overloaded day headers inline.

## Plan Capacity (CS-PLAN-065, ADR-012)

The owner plan preview derives per-person/day capacity from three
authoritative server-side sources — never from client-side counters:

1. **Preferences** — canonical `energyBudget` / `weekdayAvailability`
   (fallbacks: 10 weekday / 15 weekend; a missing weekday flag means
   available).
2. **Planned occurrences** — projected occurrences of every active house
   task inside the seven-day planning window, including tasks outside the
   selection. Full task effort is counted **per assignee**, not an invented
   teamwork split; a selected row's current in-window occurrence is
   replaced, not added again.
3. **Completion effort** — the Σ `energyCost` of non-undone completions on
   that day. `undone_at` rows are excluded (decision D11): an undone
   completion is no longer planned work.

Availability, energy capacity and the owner-set child daily-task limit
(`maxDailyTasks`, 1-100, decision D5) are **hard placement constraints**:
the plan never creates a new overload, an unavailable assignment or a
child-limit violation. Existing unavoidable overload is reported
(`EXISTING_OVERLOAD` on unchanged/unplaced rows), never silently fixed.

The former `todayConsumedEnergy` client counter (incremented per completion,
read by nobody) was removed with CS-PLAN-065 (decision D7, closes
CR-055-01); the E2E daily/range projections in this document remain the
canonical display sources.

## Open Decisions

- Whether energy costs can be personalized per member based on age/ability.

## See Also

- Implemented reward tokens driven by energy (coins, tier/early multipliers, streak
  freezes, teamwork bonus, weekly goal) are documented in
  [`gamification-and-rewards.md`](gamification-and-rewards.md) § Implemented Reward
  Mechanics. Energy budgets are configurable per weekday and persisted in the
  `scheduling_preferences` JSON column of `house_users` (the orphan
  `AvailabilitySettings` entity/table cluster was pruned in CS-PLAN-055; see
  [`../engineering/architecture.md`](../engineering/architecture.md)).
