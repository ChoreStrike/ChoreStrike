# Simplified Core — Target Picture

> **Status:** Target picture, confirmed by the operator on 2026-11-10 after the family reviewed
> the mockups. It records the decisions of 2026-11-09 and 2026-11-10. Nothing here is
> implemented. The shipped contracts stay binding until a reviewed plan and a new ADR replace
> them ([what this replaces](#what-this-replaces)).
> Owning item: [CONCEPT-002](../backlog/backlog.md#p1-concept-002--simplified-core-target-picture).

## Why

The family trial on `0.8.0-alpha.1` to `0.8.0-alpha.4` showed three things. The same task is
shown and edited in several different places. The rules are stricter than a family needs:
requests, reasons, consent ticks and recorded agreements guard against problems this kind of
household does not have. Both cost screen space and clicks. A household is a family, not an
employer.

## Principles

1. **Rolling rhythm instead of agreed appointments.** What is due follows from when a task was
   last done and how often it repeats. Future appointments are not stored and not negotiated.
2. **Trust instead of requests.** Every member may act. Everything is visible in the recent
   activity, and the owner can take it back.
3. **The scheduler suggests, it never commits.** It spreads the open work over the days and
   recalculates whenever something changes.
4. **One way to show and edit a task.** One row, one inline expansion, one form, everywhere.
5. **Calm by default, red may nag.** The interface is compact and quiet. Overdue work is the
   exception and is allowed to be loud.

## Rules

### Due windows

Every task has a window, not a due day: a centre day plus and minus its flex days. Work done
inside the window is on time.

| Mode | Centre of the next window | Example |
|------|---------------------------|---------|
| After completion | last completion + interval | Vacuuming every 7 days ± 1, last done Mon 2 Nov: window Sun 8 to Tue 10 Nov |
| Fixed weekday | the chosen weekday, whatever day the work was done | Laundry every Saturday ± 1: Fri to Sun, every week |
| Fixed day of month | the chosen day, clamped to the month's last day | Filter change on the 15th ± 2: 13th to 17th |

- **Flex days** are symmetric. Default ± 1 from a weekly rhythm on, 0 below that. The existing
  upper limit stays: half the interval, rounded up.
- **The next window is never edited directly.** It follows from the last date and the rhythm. To
  move a task, correct the last date or use *Postpone*.
- **A new task** starts with a first date. It stays editable until the task is done for the
  first time; from then on the last completion takes its place.
- **Open work is not frozen.** A changed rhythm, flex or energy applies at once to the current
  window. Completed work keeps what it recorded.
- **Early completion is always possible**, except on the same local day as the previous
  completion of that task. After completion, the next window moves with it. On a fixed day, the
  early completion fulfils the upcoming cycle and the day stays.
- **Overdue** means the window has ended. There is one red entry per task until it is done once.
  Missed cycles do not pile up and there are no catch-up appointments. On a fixed day, the red
  entry ends when the next cycle's window opens.
- **Postpone** is open to every member, children included: up to three times per cycle, each
  press shifting the current window by half the flex days, rounded up. A postponed window still
  counts as on time. Tasks without flex days cannot be postponed. The next cycle is
  not affected.
- **Sleeping tasks.** A task can be put to sleep, optionally until a wake date (lawn mowing in
  winter). While asleep it has no window, is never overdue and takes no capacity.
- **Bonus tasks** are voluntary and always available (cooking, emptying the dishwasher). They are
  never due, never overdue and never scheduled. They pay energy and coins, at most once per
  person, task and day, and count neither for nor against the weekly goal, the rhythm or the
  ranking.
- **One-time tasks** keep their single window and end when done.

### People and rights

| Action | Child | Adult | Owner |
|--------|:-----:|:-----:|:-----:|
| Complete a task, also early and also for someone else | yes | yes | yes |
| Postpone | yes | yes | yes |
| Enter or delete an own absence | yes | yes | yes |
| Change the date of, or delete, one's own latest entry of a task | yes | yes | yes |
| Create and edit tasks: rhythm, energy, assignees, room, sleeping, bonus | no | yes | yes |
| Correct any history entry: date, person, delete | no | no | yes |
| Delete anyone's absence; enter a whole-household absence | no | no | yes |
| Set coin factors, start bonus days | no | no | yes |

- **Absence** is a period, nothing more: no reason, no request, no approval. Everyone sees the
  entries. While absent, a person gets nothing suggested.
- **Recent activity** shows postponements, date changes, deletions and absences next to
  completions. This visibility replaces approval.

### Scheduler

- The suggestion is computed on read from the open windows, each member's energy per weekday,
  their preferred days and the absences. Nothing is applied or stored as an agreement.
- *Today* and the calendar show the suggestion. It places work on a good day inside the window,
  not on the centre day by default.
- **Preferred days** are a per-member setting next to the energy per day. The suggestion fills
  them first.
- It recalculates after every completion, postponement, edit and absence. What it already
  suggested for today stays on today's list, so the list does not jump during the day.
- **Team tasks** are placed by the responsible person alone. The others see the task on the same
  day; their budget does not block it.
- **Capacity shapes the suggestion and shows overload. It never refuses** an assignment or a new
  task (this settles D-089-13). A child's daily limit keeps limiting what *Today*
  offers that child.

### Rewards

- **Coin factor per member.** The owner sets a factor per member (× 1, × 2 or × 3), so
  that children with less time can collect as much as adults.
- **Bonus days.** The owner starts a period of one to three days in which everyone's coins count
  × 1.5. *Today* shows it.
- Both apply to **coins only** and multiply with each other. Energy stays the honest measure of
  work; the weekly goal, the rhythm, the ranking and the arcade unlocks do not change. The factor
  is frozen with the completion, like the coin rate today, so an undo or a correction reverses
  exactly what was paid.
- These are the only two multipliers. Combos, tiers and streak freezes stay retired.

### Feedback

- The completion animation is on by default; sound stays a personal opt-in.
  `prefers-reduced-motion` always wins.
- Completing a task should feel like the *Done* button does today, with more visible and, where
  enabled, audible feedback.
- Overdue work sits first on *Today* in a strong red block, and its room carries a red badge.

### Screens

- **Navigation:** Today, Tasks, Calendar, Insights, Absence, Rewards, Arcade, Settings.
- **Today** merges My Day and the Dashboard: one status line (energy, weekly goal, streak,
  coins), the overdue block, the suggestion, what is already possible, and the bonus tasks.
- **Tasks** is the one list, grouped by due date, by room or by person. Grouped by room, floors
  and rooms are collapsible headings with their open and overdue counts. The separate room page
  and the room cards go.
- **One task row** everywhere. A tap expands it in place: details, sub-tasks, postpone, record
  for someone else, edit, sleep, and the history. There is no separate details view.
- **One form** for new and edit. It shows the next window read-only, derived from the fields
  above it.
- **Insights** becomes dense: a few key figures and a single-line activity list. The ranking
  moves here, and the planning settings move into Settings.

The family reviewed mockups of these screens, and the operator confirmed them on 2026-11-10.
They are kept outside the repository.

## What this replaces

Accepted ADRs are immutable, so each row needs a new ADR that supersedes or amends the old one,
written with the owning plan.

| Shipped contract | Source | Becomes |
|------------------|--------|---------|
| Agreed occurrences are the recurrence authority; future instances are stored rows | [ADR-013](../adr/ADR-013-occurrence-agreements-and-corrections.md), [timeline contract C1](task-model-and-sync.md#approved-timeline-contract-c1-2026-09-17) | Windows derived from the last completion or the fixed day; completions stay the durable history |
| Work can be completed only inside its window | ADR-013 | Early completion at any time |
| Date changes are owner-recorded agreements with consent, before the deadline | [agreement contract C2](task-model-and-sync.md#approved-agreement-contract-c2-2026-09-17) | Postpone by any member; otherwise correct the last date |
| A missed occurrence stays as its own catch-up entry | C1, [calm core UX](calm-core-ux.md) | One red entry per task; no catch-up |
| Children request pauses, owners decide; reasons; retrospective illness | C2, calm core UX | Absence as a plain period for everyone |
| The owner previews and applies a seven-day plan; later assignments and date moves are refused at a budget | [ADR-012](../adr/ADR-012-actionable-household-scheduling.md), [energy and balancing](energy-and-balancing.md) | A live suggestion; capacity never refuses |
| All multipliers are retired | [ADR-014](../adr/ADR-014-work-accounting-ledger-and-replay.md) | Exactly two coin multipliers, frozen at completion |
| Sound and animation are off by default | calm core UX | Animation on by default, sound opt-in |
| My Day is the landing page, the Dashboard a second overview | calm core UX | One *Today* |
| History corrections are undo and attribution, by completer or owner | ADR-014 | Plus a date correction; own latest entry for members, everything for the owner |
| Rhythm, weekly goal and ranking count agreed occurrences | ADR-014, [ADR-015](../adr/ADR-015-ranking-consent-and-privacy.md) | The same recognitions over windows (definition open) |

## Data retention

The [retained baseline](../engineering/deployment.md#first-retained-baseline) holds. Completions,
work shares, the ledger, the room journal, memberships and preferences are untouched. The stored
occurrence, agreement, schedule-revision and pause rows stay as history; nothing is deleted or
reset. Existing pauses stay valid as absences. Schema changes come as new ordered steps with
populated-upgrade tests, and no applied migration is edited.

## Open points

To be settled in the owning plans, before code:

1. What "on time" means for the rhythm, the weekly goal and the ranking once windows can be
   postponed and fixed cycles can be skipped.
2. Whether a window that falls entirely into an absence is neutral for those recognitions.
3. What is stored for the current window (postpone count and shift), whether calendar reads need
   a derived cache, and how a completion names its window so that concurrent and repeated
   requests stay exactly-once (ADR-007, ADR-011).
4. Handing a single cycle to someone else: keep it, or leave it to the assignees in the form.
5. The scheduler's horizon, its tie-breaks and the exact stability rule.
6. The digest notifications that refer to agreements and pause requests today.
7. How Release B is integrated while C is not built: `master` stays releasable, so B either keeps
   the planner working or waits with C on a shared branch.

## Delivery

Three planned releases, each with its own plan and review, in two deployments. The order keeps
the model change away from the first visible improvements.

| Release | Content | Model change | Deployed |
|---------|---------|--------------|----------|
| A | Navigation, *Today*, tasks grouped by room, denser lists and Insights, absence without request, feedback defaults | none; one data-only migration step switches the animation on for everyone | on its own, as `0.8.0-alpha.5` |
| B | Windows as derived state, first date and history editing, early completion, postpone, sleeping and bonus tasks, coin multipliers, the one form and the inline expansion | yes | together with C, as one version |
| C | Live suggestion on *Today* and in the calendar, preferred days | scheduler | together with B |

B and C keep their names, plans and reviews and are built in this order, but reach the family
instance as one version (operator, 2026-11-14). A deployment takes the operator time, and no
deployed version then has to run the preview-and-apply planner on derived windows.

All of them stay alpha versions on the family instance. The `v0.9` beta starts only after they are
built and tested there, so that outside testers meet the simplified model and no second
installation has to carry the model change
([release strategy](../release/release-strategy.md#stage-2-v09-beta)).

## Decision record (operator, 2026-11-09)

- The family trial closes, with this list as its product result. The evidence gates of
  [CS-PLAN-080](../plans/active/cs-plan-080-wp9-cross-db-and-family-evidence.md) are a separate
  matter.
- Fixed days stay next to the rolling rhythm: a weekday whose centre does not move, and a day of
  the month.
- Everyone enters absences without reason or request; the owner sees and may delete them.
- The first date of a new task stays editable. Members change the date of, or delete, only their
  own latest entry; the owner may change everything.
- Current and future work is always derived from the last date and the current rhythm. The next
  date is not edited directly.
- Postpone for all members, up to three times, by half the flex days.
- Early completion is always possible, except on the same day.
- Flex days are standard, at least ± 1 from weekly on.
- Catch-up appointments go. The red entry is allowed to nag.
- Tasks can sleep. Bonus tasks are voluntary, pay energy and coins, at most once per day, and stay
  outside the weekly goal and the rhythm.
- A coin factor per member and bonus days of × 1.5 for one to three days, started by the owner,
  on coins only.
- Adults and the owner edit task definitions; children postpone and change their own latest
  entries.
- The scheduler suggests an optimized distribution on *Today* and in the calendar, recalculates
  on changes, honours preferred days, and places team tasks by one person.
- My Day and the Dashboard merge; floors and rooms move to the tasks; Insights becomes compact.
- Animation on by default, sound as an opt-in.
- GUI concepts come before implementation and are reviewed with the family.

### Confirmation (operator, 2026-11-10)

- The mockups fit as reviewed with the family.
- The five rules this document had proposed are confirmed: on a fixed day the red entry ends
  when the next cycle's window opens; tasks without flex days cannot be postponed; capacity
  never refuses; the ranking moves into Insights and the planning settings into Settings; the
  coin factor is × 1, × 2 or × 3.
- The releases A, B and C stay alpha versions. The beta follows once they are built and tested,
  possibly with a larger circle than first planned.

### Two deployments (operator, 2026-11-14)

- Release A is deployed on its own as `0.8.0-alpha.5`, as planned.
- Releases B and C are deployed together as one version, because every deployment takes the
  operator time. Each keeps its own plan, Standard A review and superseding ADR.
