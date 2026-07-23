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

## Open Decisions

- Whether energy costs can be personalized per member based on age/ability.
