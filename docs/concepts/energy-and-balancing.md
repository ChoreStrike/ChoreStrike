# Energy Model and Workload Balancing

## Goal

Replace abstract difficulty stars with a practical effort model that improves fairness and weekly planning.

## Energy Unit

- Each task costs 1-5 energy.
- 1 energy corresponds to roughly 5 minutes of effort.
- The conversion is intentionally approximate and should not be treated as exact time tracking.

## Estimated Duration Table

The ranges below are rough household examples, not a second exact conversion
formula. The current stored/validated scale is 1-5; the household should agree
what each level means rather than turn children's effort into timed competition.

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

- Target: do not place work on a member's unavailable days. The owner planner
  checks availability, but recurrence advancement is separate: zero flexibility
  or no available slot in the flex window can retain an unavailable base date.
- Weekly fairness comparisons should normalize by each member's available energy capacity, not by raw task count.

**Implemented (CS-PLAN-040):** the Insights `MemberFairnessTable` normalizes
load per member as `completed energy / weekly capacity`, where completed
energy is the Σ `energyCost` of the member's completions in the activity
window (`GET /house/activity`) and weekly capacity is Σ `energyBudget` over
the member's available weekdays from their serialized
`schedulingPreferences` (fallback budgets 10 weekday / 15 weekend).
Contribution score and coins are always shown as separate columns — they are
never merged into one metric.

## Balancing Strategy (Original Intent)

This is the original heuristic, not an autonomous rescheduling guarantee.
The current confirmed-preview workflow and hard constraints are described in
*Plan Capacity* below and [ADR-012](../adr/ADR-012-actionable-household-scheduling.md).

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
(`maxDailyTasks`, 1-100, decision D5) are the **documented hard placement
constraints**. Existing unavoidable overload is reported
(`EXISTING_OVERLOAD` on unchanged/unplaced rows), never silently fixed.

**Review limitations (2026-09-16):** current placement can accept an override
outside `legalDates`, and preserved/shared assignee sets can pass after checking
only one member. Therefore the implementation does not yet uphold these
constraints for every override and participant. The
[family-rhythm plan](../plans/new/cs-plan-070-family-rhythm-and-reward-clarification.md)
tracks both correctness gaps separately from the product decisions.

The former `todayConsumedEnergy` client counter (incremented per completion,
read by nobody) was removed with CS-PLAN-065 (decision D7, closes
CR-055-01); the E2E daily/range projections in this document remain the
canonical display sources.

## Approved Target (2026-09-17; Not Yet Implemented)

- Task energy represents the **total** effort, split visibly between participants
  in both planning and contribution/coin credit. More names do not multiply the
  budget or produce a second full reward. This deliberately replaces the current
  full-effort-per-assignee model described above.
- Normal chores have one responsible member with optional helpers. Explicit joint
  chores require each participant's availability and their agreed share of capacity;
  an available adult cannot make an unavailable child's assignment valid.
- Personal streak credit requires actual participation. A pre-deadline agreed
  handover releases the former responsible person neutrally, not as an earned
  success. Unplanned substitution alone does not rescue their obligation.
- Ordinary date/window amendments are agreed before the existing deadline;
  after it expires, catching up does not rewrite the missed history. Recognized
  retrospective illness is a separate traceable owner decision.

### Approved Whole-Day Capacity C2 (2026-09-17)

Target behavior only; these rules are not enforced by the current daily filter
or all existing completion paths. (Planning/amendment capacity IS enforced
since CS-PLAN-073 WP2 — see *Delivered planning capacity* below.)

- New planning, takeovers and offered optional work must account jointly for work
  already completed and outstanding daily commitments: regular, catch-up, early
  work and voluntary help. Use the child's own energy share and count each
  participated occurrence once toward the task limit. Never count an occurrence
  twice merely because it moved from planned to completed.
- At either daily limit, add no extra commitments or offers. Owners must replan
  within the limits instead of bypassing them with a per-item override. Existing
  overload remains visible; silently hiding excess obligations is not recovery.
- Actual work remains immediately recordable, with normal effort credit, even
  if it exceeded the plan's limit. Show overload neutrally, stop offering extra
  work and have the owner clarify remaining commitments. Do not erase reality,
  create an extra unplanned-day streak step or repair a historical miss.
- A recognized pause never automatically increases someone else's effort share.
  A remaining joint-work plan needs an explicit owner-recorded agreement before
  the deadline and must fit every participant's capacity; see the
  [agreement contract](task-model-and-sync.md#approved-agreement-contract-c2-2026-09-17).

### Approved Effort Allocation C3 (2026-09-17)

Target rules only; current per-assignee accounting is not changed by this document.

- Propose equal shares among planned participants unless otherwise agreed; a solo
  responsible person starts at 100%. The authorized completer confirms/corrects
  actual participants and shares, explicitly reviewing deviations from the plan,
  without a new owner approval queue.
- Each actual participant has a positive whole-percent share; shares total 100%,
  so at most 100 actual participants are supported per completion. Split an equal
  proposal using a stable immutable-member order for remainders, independent of
  input order, display names and role. Show the resulting shares before confirmation.
- For energy 1 and three equal participants, use 34/33/33%: 0.34/0.33/0.33 energy
  and 3.4/3.3/3.3 Coins at the fixed rate of 10. Preserve total effort and total
  Coins exactly; do not round each participant independently into a larger reward.
- Energy and Coins support up to two decimal places. Use exact integer hundredths:
  for integer task energy E and whole percentage P, energy hundredths = E * P and
  Coin hundredths = 10 * E * P. No floating-point conservation errors.
- After a real completion, correcting only attribution preserves the occurrence,
  actual time, total effort and room effect. Correct each affected person's
  allocation/credit and rhythm contribution, including removed participants;
  see the [correction contract](task-model-and-sync.md#approved-correction-contract-c3-2026-09-17).

### Delivered planning capacity and frozen energy (CS-PLAN-073 WP2, implemented 2026-09-24)

The whole-day C2 rules are **enforced for the planning/amendment surfaces** by
`DailyCapacityService` (ADR-013 §WP2): exact integer-hundredths ("minor units",
E × P) shares per participant; occurrence-identity dedupe against the
completion ledger (a fulfilled obligation is never planned AND completed);
the saved work-date/zone time basis (Q-073-02 frozen vectors); **frozen
completion energies joined from the SAVED occurrence** — a definition edit
never re-prices recorded work and an unlinked ledger row is excluded instead
of silently re-priced; suspended plans consume zero while retaining their
frozen share snapshot; concretely agreed **helper** commitments are checked
like every affected participant; a replacement removes exactly the row's old
allocation before its new share vector is tested; and the gates answer stable
conflicts (pause / availability / energy budget / child limit) without
leaking third-party facts. Over-limit REAL work stays recordable — completion
never consults the evaluator — and existing overload remains visible in the
planner capacity grid (`{planned}/{budget} — over capacity`, `Paused`,
`Away`). C3 actual-share reconciliation after completions remains WP3.

## Open Details

- Whether energy costs can be personalized per member based on age/ability.
- Exact persistence/concurrency enforcement for C3 actual-share reconciliation
  after completions (WP3 accounting); the owner-recorded agreement and
  aggregate-capacity enforcement itself is delivered (CS-PLAN-073 WP2, above).
- C5 local-day/UI integration of the approved pause restart and complete workload
  model. These are not permission to
  weaken the limits or block legitimate recording of already completed work.

Availability is a capacity constraint, not a daily obligation. A rhythm streak
must derive from agreed due occurrences, not from every weekday marked available.

## See Also

- Implemented reward tokens driven by energy (coins, tier/early multipliers, streak
  freezes, teamwork bonus, weekly goal) are documented in
  [`gamification-and-rewards.md`](gamification-and-rewards.md) § Implemented Reward
  Mechanics. Energy budgets are configurable per weekday and persisted in the
  `scheduling_preferences` JSON column of `house_users` (the orphan
  `AvailabilitySettings` entity/table cluster was pruned in CS-PLAN-055; see
  [`../engineering/architecture.md`](../engineering/architecture.md)).
