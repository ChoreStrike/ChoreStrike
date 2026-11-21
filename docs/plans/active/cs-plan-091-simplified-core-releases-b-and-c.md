# CS-PLAN-091: 0.8.0-alpha.5 — Simplified Core, Releases B and C (Derived Windows, Trust, Live Suggestion)

> **Status:** Active (Standard A `PASS-WITH-FIXES` 2026-11-16; WP-0 written 2026-11-16 and WP-B1 implemented 2026-11-18 on `feat/simplified-core-bc`; interim Full review of WP-0 and WP-B1 `PASS-WITH-FIXES` 2026-11-18 with the review commit, D-091-41 to D-091-45 decided; WP-B2 and WP-B3 implemented 2026-11-18 with RF-091-02 to RF-091-05; their interim Full review `PASS-WITH-FIXES` 2026-11-18 with the review commit, D-091-46 to D-091-58 accepted and D-091-59 to D-091-63 decided; WP-B4 and WP-B5 implemented 2026-11-18 with RF-091-07 and RF-091-08; their interim Full review `PASS-WITH-FIXES` 2026-11-18 with the review commit, D-091-64 to D-091-77 accepted and D-091-78 to D-091-84 decided; D-091-85 to D-091-88 decided by the operator 2026-11-18; WP-B6 implemented 2026-11-19 with RF-091-09 and RF-091-10; the operator's GUI review of 2026-11-21 worked in (D-091-103 to D-091-107); its interim Full review `PASS-WITH-FIXES` 2026-11-21 with the review commit, D-091-89 to D-091-107 accepted and D-091-108 to D-091-112 decided; WP-B7 implemented 2026-11-21 with RF-091-06; its interim Full review `PASS-WITH-FIXES` 2026-11-21 with the review commit, D-091-113 to D-091-119 accepted and D-091-120 to D-091-122 decided; RF-091-11 decided by the operator and implemented 2026-11-21 (D-091-123 to D-091-125), its interim Full review `PASS-WITH-FIXES` 2026-11-21 with the review commit; RF-091-12 and RF-091-13 open for WP-C; next: WP-C)
> **Effort:** XL — about 40–55 engineering days, plus the operator's GUI review and the release steps, each with its own approval
> **Priority:** P1
> **Depends on:** the confirmed [simplified-core target picture](../../concepts/simplified-core.md) (operator, 2026-11-10) and its mockups (reviewed with the family, kept outside the repository); Release A on `master` ([CS-PLAN-090](../done/cs-plan-090-simplified-core-release-a.md), closed 2026-11-16, not deployed, D-090-81); `0.8.0-alpha.4` on the retained baseline in production
> **Related backlog items:** WP-B1 FEAT-UX-068 (new) · WP-B2 FEAT-UX-041, FEAT-UX-051 · WP-B3 FEAT-UX-050, FEAT-UX-052 · WP-B4 FEAT-UX-069 (new) · WP-B5 FEAT-UX-070 (new) · WP-B6 FEAT-UX-040, FEAT-UX-043 (form part) · WP-C FEAT-UX-059 · WP-G RELEASE-002 (`0.8.0-alpha.5` slice) · closes BUG-UX-015 · retires the route of the archived FEAT-UX-045 (D-091-19) · umbrella CONCEPT-002 · **not in scope:** the catalog content of FEAT-UX-043 (new sets, Putzteufel as a superset), Round 3 items, TECH-TEST-005 and CONCEPT-001 (CS-PLAN-080)

## 1. Problem Summary

Release A changed the screens. The model underneath is still the one the family trial found too
strict ([why](../../concepts/simplified-core.md#why)):

- **Stored appointments.** Every recurrence is materialized as occurrence rows a week ahead
  (`OccurrenceReconcileService`, `HORIZON_DAYS = 7`), each with its own window, members and
  energy. Changing a date is an owner agreement with consent (`AgreementService::amend`); a
  missed occurrence becomes a catch-up appointment (`work_date`). Work can only be completed
  inside its window (`OccurrenceSelectionService::selectFromAgreed`).
- **Requests and refusals.** Date moves and assignments after creation are refused at a
  person's energy budget or a child's limit (`DailyCapacityService::assertCommitmentsFit`,
  `AgreementService::assert*Capacity`, `RoomTaskService::assertChildCapacityForDate`).
- **An applied plan.** The weekly planner previews and applies a seven-day plan as owner
  agreements (`SchedulePlanService`, ADR-012). Nothing suggests work on *Today* unless the owner
  ran it.
- **Several ways to edit a task.** `TaskEditForm` (create, edit), `RoomTaskEditModal` (due date,
  assignees) and `TaskListDetails` (amend, hand over, restart) overlap; templates share one
  definition across rooms (FEAT-UX-043).
- **One reward rate.** Children with less time collect less; there is no way to make a day
  special.

The concept replaces this with derived windows, trust instead of requests and a live suggestion
([rules](../../concepts/simplified-core.md#rules)). Release B changes the model, Release C the
scheduler. They reach the family instance together with Release A as one version, `0.8.0-alpha.5`
(D-090-81).

## 2. Strategy

Contracts first, then the model, then the screens, then the scheduler, then one release:

1. **WP-0:** two new ADRs and the concept updates before code — ADR-021 *Derived due windows*
   supersedes ADR-013 (the pause part stays with ADR-020) and amends ADR-014; ADR-022 *Live
   suggestion* supersedes ADR-012. The API reference states every changed route first.
2. **WP-B1:** the window core. The current window of a task is computed on read from its rhythm,
   its last valid completion (or its first date) and its postponement. Closed cycles become
   history rows; future cycles are never stored. Everything that writes or checks stored future
   rows goes in the same package: the agreement, catch-up, restart, due-date and last-done
   writers, preview and apply, and the capacity refusals.
3. **WP-B2 to WP-B5:** what members do with windows — early completion and recording for others;
   postpone and history corrections; sleeping and bonus tasks; coin factors and bonus days.
4. **WP-B6:** one form and one inline row, which replace the three editors and the details view.
5. **WP-B7:** what is left of the old surface goes: the cleanliness route, the agreement digest
   lines and the help texts.
6. **WP-C:** the live suggestion on *Today* and in the calendar with preferred days; capacity
   shapes it and shows overload.
7. **WP-G:** one joint candidate `0.8.0-alpha.5` for A, B and C, qualified anew, exported and
   deployed through the private exchange, each step on the operator's approval.

All of it lives on one implementation branch, `feat/simplified-core-bc`, cut after Standard A.
Each work package ends with its interim review and a review commit, and every review commit
passes the build gate. `master` is merged into the branch whenever it moves, and the branch
reaches `master` once, after the final Full review (D-091-13). `master` keeps Release A
releasable meanwhile.

### Decisions recorded (operator)

- **D-091-01 One version, one plan (2026-11-15):** A, B and C reach the family instance as one
  version; B and C share this plan and one Standard A review; each superseded or amended contract
  still gets its own ADR before code (D-090-81,
  [delivery](../../concepts/simplified-core.md#delivery)).
- **D-091-02 On time is decided at completion (2026-11-15):** a completion is classified once and
  frozen with it. Early, or inside the window including a postponement, is on time; after the
  window is late. A cycle counts in the house-local week its window ends. A fixed-day cycle that
  was never done counts as missed once the next window opens. Settles open point 1.
- **D-091-03 Absence is neutral, the red entry stays (2026-11-15):** a window that lies entirely
  inside the absence of a person is neither on time nor missed for that person's streak, weekly
  goal and ranking. When the window ends, the task turns red as usual. Settles open point 2.
- **D-091-04 Cutover keeps what was earned (2026-11-15):** current and longest streaks and every
  claimed weekly goal stay as stored. The new rules apply from the first house-local day after
  the update and continue the stored streak; no past week is evaluated again. *Amended by
  D-091-41: the derived model starts on the house-local day of the update itself.*
- **D-091-05 Scope (2026-11-15):** FEAT-UX-051 (recording for someone else, sub-tasks on
  completion) and the form part of FEAT-UX-043 (a template only fills the form; a definition
  shared by several rooms splits on edit) join; the catalog content of FEAT-UX-043 stays in the
  backlog.
- **D-091-06 Version (2026-11-16):** the joint deployment keeps `0.8.0-alpha.5`. The
  qualification of 2026-11-15 stays history; the joint candidate is qualified anew.
- **D-091-17 Closed cycles in their own table (2026-11-16, Standard A):** closed cycles of the
  new model live in a new table, not in `chorestrike_occurrences`, whose revision and ordinal
  columns are not null under a unique key. Stored occurrence rows stay untouched; the
  recognitions read them up to the update and the new table after it. Refines D-091-07.
- **D-091-18 A daily snapshot keeps today's list stable (2026-11-16, Standard A):** the first
  read of a house-local day stores that day's suggestion per house, as a plain cache without
  history value that the timeline job deletes after a few days. Refines D-091-11.
- **D-091-19 The first date replaces "last done on" (2026-11-16, Standard A):** until a task's
  first completion the form's first date sets its window; afterwards the date correction of the
  latest entry moves it. The `last-done` route (FEAT-UX-045, D-088-20) and its service go;
  retained `last_done` journal events keep replaying.
- **D-091-20 Recording for others keeps ADR-010 visibility (2026-11-16, Standard A):** only
  members who may see a task can be credited on it, so a private task credits only its creator
  and the room task's assignees (ADR-010's matrix; WP-0 corrected "only its owner"); the server
  refuses anyone else. ADR-010 stays unchanged.
- **D-091-21 Postpone only before the window ends (2026-11-16, WP-0):** postponing works
  before and inside the window; a red task cannot be postponed, so a miss stays a miss.
- **D-091-22 Completing a sleeping task wakes it (2026-11-16, WP-0):** the completion is
  recorded, the task wakes and its rhythm continues from that day.
- **D-091-23 Own entries as the concept table (2026-11-16, WP-0):** members change the date of,
  or delete, only the latest entry of a task that they recorded; changing the person becomes
  owner-only. This narrows the completer rights of ADR-014.

### Implementation decisions (WP-B1, for the interim review)

Taken while implementing; each is open to the interim review of WP-0 and WP-B1. The interim
Full review of 2026-11-18 accepted them, with D-091-28 refined by D-091-44, D-091-31 by
D-091-42 and D-091-38 extended by RF-091-06.

- **D-091-24 Two steps for the window core:** `Version1035` adds the schema (room-task cycle
  state, `tasks.fixed_month_day`, the completion's cycle facts, `houses.cycles_from`, the
  streak bases, `chorestrike_cycles`, `chorestrike_cycle_progress`), `Version1036` is the
  data-only cutover of ADR-021 decision 12. The coin-factor and bonus-period columns and
  table of decision 11 come with WP-B5 in a step of their own.
- **D-091-25 The completion targets the current cycle from WP-B1 on:** without stored
  occurrences there is no "inside the window" target left, so the command takes
  `{commandId, cycleKey, expectedVersion}` and the any-time and same-day rules of ADR-021
  decision 4 in WP-B1. WP-B2 keeps recording for others, the picker and the sub-tasks
  inside the command.
- **D-091-26 The latest valid completion is the one with the highest id:** a date correction
  may never move an entry past its neighbours (WP-B3 enforces it), so one grouped query
  finds the anchor of every room task.
- **D-091-27 No stored anchor where a completion anchors:** the cutover leaves `anchor_on`
  empty for an after-completion or one-time task that has a valid completion; if every
  completion is undone later, the window falls back to the day the room task was created.
- **D-091-28 A fixed rule never starts before its anchor:** rule days are counted from
  `anchor_on`, which also is the floor for cycles, so a rule-mode edit or a wake day sets
  a new anchor on a rule day and keeps the parity.
- **D-091-29 The capacity service goes whole:** every reader of `DailyCapacityService` read
  stored future rows or refused at a budget, so the service, its selected-share window read
  and their tests go in WP-B1; WP-C rebuilds the load view on the live suggestion. Until then
  the scheduling view shows the planned energy of the current cycles against the budgets.
- **D-091-30 Ticks of an appointment that was open at the cutover are not carried over:**
  they stay in `chorestrike_chk_progress` as history; the first derived cycle starts without
  ticks. Copying them would need a cycle key for a window that the old model never named.
- **D-091-31 A bonus task completes without a cycle from WP-B1 on:** `cycleKey` is absent,
  the class is `bonus` and no cycle row is written. The once-per-person-and-day rule and the
  bonus coins come with WP-B4 and WP-B5.
- **D-091-32 Stored occurrences are never rewritten:** an undo of a completion from before
  the cutover invalidates the completion only; the readers ignore an undone completion, so
  its stored appointment reads as missed, as it would have in the old model.
- **D-091-33 Neutrality is asked for the members a window counted for:** a closed cycle keeps
  its neutrality when the absent member later leaves the house (the old model had stored
  it on the appointment). Future effects stop at a departure because the member is
  responsible for nothing that is still open.
- **D-091-34 Who is asked "who took part" follows the assignees:** without agreements there
  is no server `proposal`. The dialog opens for a task of several people or of someone else;
  the default split is equal shares of the assignees. WP-B2 replaces the dialog.
- **D-091-35 Payload names:** creation takes `firstDate` (a `nextDue` key is ignored), the
  range read lists `cycles` (`date`, `cycleKey` for the current one, the window, `current`,
  `late`) in place of `occurrences`, `occurrenceDetails` and `flexWindow`, and the definition
  accepts `fixedMonthDay`.
- **D-091-36 No date edit between WP-B1 and WP-B6:** the date dialog went with its two
  writers. An existing task's window moves by its completion, an edit of its rule or flex,
  and from WP-B6 on by the form's first date (D-091-19).
- **D-091-37 The job pass is per house:** `CycleService::advanceHouse()` records the missed
  fixed-day cycles of one house and repairs its projection; the job hands it the one
  household of the instance. Tests call it for their own fixture house.
- **D-091-38 What WP-B1 leaves for WP-B7:** code that lost its last caller but belongs to
  the retained read model of old rows or to a surface WP-B7 retires — the writer methods of
  `AgreementMapper`, `OccurrenceMapper`, `OccurrenceMemberMapper`, `ScheduleRevisionMapper`
  and `RoomTaskChecklistProgressMapper`, `RecurrenceCalculator::enumerate()`, the ideal-date
  helpers of `RoomTask`, `RoomReplayService::recordLastDone()`, and the help, glossary and
  digest wording about agreements and catch-up.
- **D-091-39 The planner screenshot goes with the planner:** the documentation scene
  `weekly-planner.png` has nothing left to capture, and the drift test allows no shown image
  without a scene. The README shows *Today* and the reward catalog, the user guide's
  *Planning the week* describes availability, budgets and the capacity overview. The other
  planner wording of the README (WP-G) and of help and glossary (WP-B7) stays with those
  packages.
- **D-091-40 Proof P4 covers the cutover:** the upgrade fixture compares every column the
  source build had and checks the cutover values of the new ones against the stored rows
  (`tests/recovery/upgrade-cycles.php`). Its source is populated with a fixed weekly, a fixed
  monthly and a one-time room task through the source build's creation service, because the
  family seed holds after-completion rules only.

### Review decisions (interim Full review of WP-0 and WP-B1, 2026-11-18)

The operator chose the recommended option of each question.

- **D-091-41 The derived model starts on the update day:** `Version1036` sets
  `houses.cycles_from` to the house-local day of the update. Stored occurrences can no longer be
  fulfilled once the update runs, so work done that day after the update would otherwise count
  as missed for windows ending that day. Streaks and claimed weeks stay as stored and no past
  week is evaluated again; amends D-091-04.
- **D-091-42 Bonus tasks keep the day rule until WP-B4:** a bonus task takes one completion per
  house-local day (`409 SAME_DAY`), so the branch never pays bonus work without a limit; WP-B4
  replaces it with the once-per-credited-person rule of ADR-021 decision 8. Refines D-091-31.
- **D-091-43 A miss is recorded by the command that would lose it:** a completion first records
  the fixed cycles that stopped being current before its own and that the timeline job had not
  reached; an undo of an older fixed completion records its cycle as missed when the cycle does
  not become current again; no window that ended before its room task was created counts as
  missed (a past first date).
- **D-091-44 A rule edit anchors on a day of the rule it replaces:** a fixed rule whose days
  change is anchored on the centre of the replaced rule's current cycle (the edit day when the
  replaced rule was not fixed), so a fortnightly rule keeps its parity and a monthly rule its
  day; a change between recurring and bounded alone keeps the anchor. The policy lives in
  `CycleService::ruleChangedUnderLock()`. Refines D-091-28.
- **D-091-45 A first date is a real day within a year:** `firstDate` must be a calendar day at
  most 366 days from today, refused with a translated `400`.

### Implementation decisions (WP-B2 and WP-B3, for the interim review)

Taken while implementing; each is open to the interim review of WP-B2 and WP-B3. The
interim Full review of 2026-11-18 accepted them, with D-091-51 extended by D-091-61,
D-091-54 reworded and D-091-57 refined by D-091-62.
Gates of the implementation round (2026-11-18, uncommitted tree on `335cb162`): Jest 181 suites / 2497 tests, ESLint, stylelint, build, l10n (1255
keys); PHPUnit unit 1695 and integration 648 on PostgreSQL (canonical), MariaDB, SQLite and
the AIO row (matrix `r91b23`); proofs P1 (PostgreSQL, MySQL, SQLite), P2 and P3; focused
browser specs 81 passed on `chromium-serial-db`, the phone core set 11 passed each on
`mobile-chromium` and `mobile-webkit`. No schema step, so proof P4 was not rerun.

- **D-091-46 A takeover is late work that none of the assignees is credited for:** recording
  for others made "someone else completed it" the wrong test. The red entry of an assigned
  task is taken over when no assignee is among the people credited; the claim names the
  recorder when credited, otherwise the first person credited. Late work recorded *for* its
  assignee is no takeover.
- **D-091-47 Hold completes, a short tap opens the picker:** holding sends no split, the
  server credits the assignees (the recorder when the task has none) in equal shares. The
  picker lists the household with those people ticked; its sub-task ticks are saved at once
  per cycle (D-089-02), and *Done* completes with every sub-task marked inside the command.
  A pointer tap acts on the click that follows the release, so the opening dialog never
  receives that click; the keyboard taps at once.
- **D-091-48 The claim button of a red entry stays until WP-B6:** the picker already records
  a takeover; the separate button goes with the row it sits on.
- **D-091-49 A date correction moves the work instant, not only the day:** `completed_at`
  takes the corrected day at the same clock time in the house zone (never ahead of now),
  `work_date` and `work_zone` follow, so every reader that orders or filters by the instant
  agrees with the day. Shares, coins and the ledger stay as recorded.
- **D-091-50 The corrected day lies strictly between its neighbours:** not in the future, not
  the day it has, and after the previous and before the next valid entry of the room task.
  This keeps D-091-26 and the same-day rule; refused with a translated `400`.
- **D-091-51 What a date correction re-derives:** the entry's class against its frozen window,
  its closed-cycle row, the room event and the room replay, the recognitions, and — under an
  after-completion rule — the frozen window and class of the entry that followed, moved by
  the difference in days. The follower gets no audit row of its own; the `date` revision of
  the corrected entry is the record. An entry from before the cutover keeps its stored
  occurrence, which is read against the corrected instant (D-091-32).
- **D-091-52 Recent activity stores nothing:** postponements are read from the command
  receipts, date changes and deletions from the completion audit chain, absences (entered and
  removed) from the pause revisions. Titles of tasks the viewer may not see are withheld.
- **D-091-53 `canCorrect` and `canAttribute`:** the read models answer the date change and the
  deletion (`canCorrect`) apart from the change of people (`canAttribute`, owner only,
  D-091-23), so no client decides by role.
- **D-091-54 The history has its own read:** `GET /room-tasks/{id}/history` (ten entries, at
  most fifty); the next window comes with the row's `cycle` block and with the answer of
  every command that moves it. `RoomTaskCycleController` holds the read and the postpone
  command, because `RoomTaskController` is above the size guideline.
- **D-091-55 Postpone and history sit in the opened row of the task list until WP-B6:** the
  inline row of WP-B6 brings both to *Today*. The date is changed in the task's history
  only; the Insights feed keeps deletion and, for the owner, the change of people.
- **D-091-56 Whoever may not see a task is credited by nobody:** the visibility rule of
  D-091-20 holds for the owner's attribution correction too (`403`).
- **D-091-57 A postponement is refused once the window ended:** a red entry cannot be
  postponed (`409 WINDOW_ENDED`, D-091-21); without flex days or after three presses the
  answer is `409 NOT_POSTPONABLE`. The count belongs to the cycle key and is frozen with the
  window on the completion.
- **D-091-58 The anchor query stays, pinned by a budget:** RF-091-04 named the aggregate over
  the whole valid history per room task. It is one grouped query on an indexed column; the
  list read with its cycle derivation is now pinned in `WorkflowQueryBudgetTest`, which is
  the trigger D-091-09 sets for a cache.

### Review decisions (interim Full review of WP-B2 and WP-B3, 2026-11-18)

The operator chose the recommended option of each question.

- **D-091-59 Fix the verified defects now, the security ones included:** the absence events
  of requests that were never acknowledged, the two-part right of the date correction
  (D-091-63), its translated refusals, visibility per room task in the activity feed and its
  events (ADR-010), and the history of a deleted room task (404) are fixed in the review,
  each with a regression test; so are the correctness defects. Structure, performance and
  accessibility polish go to RF-091-07 to RF-091-09.
- **D-091-60 An undo keeps the sub-task ticks:** the ticks a completion set stay with the
  reopened cycle, like a member's own saved progress (D-089-02), since nothing tells them
  apart; the next completion marks every sub-task anyway.
- **D-091-61 A date correction keeps the takeover facts:** the claim and the digest receipt
  describe the moment the work was recorded; a later correction of the day re-derives the
  classification only (extends D-091-51).
- **D-091-62 The postponement stays with its cycle key:** closing a cycle no longer clears
  `cycle_key`, `postpone_count` and `postpone_days`. The next cycle has another key and
  starts without a postponement; an undo reopens the cycle postponed as it was, its count of
  presses included. Refines D-091-57; ADR-021 §4 amended.
- **D-091-63 The right of a date correction has two parts:** the identity part (the owner,
  or the member who recorded the entry) is checked before the command and, under the house
  lock, on execution and replay; whether the entry is still the latest is a state the
  aggregate decides under the lock. A replay therefore answers the committed result, and a
  role or visibility change before the lock is refused on replay too (CommandService guard
  contract).

### Implementation decisions (WP-B4 and WP-B5, for the interim review)

Taken while implementing; each is open to the interim review of WP-B4 and WP-B5.
The interim Full review of 2026-11-18 accepted them, with D-091-66 extended by
D-091-82, D-091-67 by D-091-83, D-091-68 by D-091-84 and D-091-75 reworded.
Gates of the implementation round (2026-11-18, uncommitted tree on `d3b91359`): Jest 184 suites / 2562 tests, ESLint, stylelint, build, l10n (1302 keys), php-cs-fixer; PHPUnit unit 1817 and integration 679 on PostgreSQL (canonical), MariaDB, SQLite and the AIO row (matrix `r91b45`), no skips; proof P4 with `Version1037` applied on PostgreSQL, MariaDB, SQLite and the AIO row, proofs P1 (PostgreSQL, MariaDB, SQLite), P2 and P3; focused browser specs 117 passed on `chromium-serial-db`, the bonus day on *Today* among them, and the phone core set 11 passed each on `mobile-chromium` and `mobile-webkit`.

- **D-091-64 One schema step for the multipliers:** `Version1037` adds
  `house_users.coin_factor` (default 1), `completed_tasks.bonus_percent` and `coin_factors`
  (both nullable) and `chorestrike_bonus_periods` (`house_id`, `starts_on`, `ends_on`,
  `created_at`, `cancelled_at`; index on `(house_id, ends_on)`). No percent and no actor
  column: a period is always × 1.5 and the command receipt names the owner. No data step:
  a stored completion keeps both columns empty and reads as × 1 at 100 percent, which is
  what it was paid. Completes D-091-24.
- **D-091-65 The asleep block is the receipt of waking:** a sleeping task's `cycle` block is
  `{state: "asleep", expectedVersion, wakeOn}`, and asleep wins over bonus. The sleep
  command and the completion of a sleeping task name the version only, never a cycle key.
- **D-091-66 Going to sleep keeps what happened:** the fixed cycles that had stopped being
  current are recorded as missed first, the current cycle ends without an outcome, and a
  repeated `sleeping: true` only moves the wake date. The wake date is a real house-local
  day after today, at most 366 days ahead (translated `400`).
- **D-091-67 The wake anchor:** an after-completion or one-time task hangs on the wake day;
  a fixed rule continues on its first rule day on or after it, counted from its stored
  anchor, so it keeps its parity and its day of the month, and the time asleep counts no
  miss. A bounded fixed rule with no rule day left is anchored on the wake day itself, so
  it ends instead of bringing its last cycle back as overdue. The timeline job wakes a task
  on its `wake_on` and anchors it on that date also when the job runs later. Refines
  D-091-22 and D-091-28.
- **D-091-68 The bonus rule is per person and holds for corrections:** a bonus task takes
  any number of completions a day, each person once (`409 BONUS_ALREADY_TODAY`), checked
  under the house lock against the valid shares of the work day. The owner's change of
  people is refused the same way for a member it would newly credit; the corrected entry
  does not count against itself. A date correction of a bonus entry keeps the neighbour
  rule of D-091-50, so it can never put two credits of one person on one day. Replaces
  D-091-42.
- **D-091-69 Bonus rows have their own read:** `GET /room-tasks/bonus` lists the visible
  bonus tasks that are not asleep with `creditedToday`, the members they counted for
  today. A child reads its own and the unassigned ones; the daily limit and the
  availability gate do not apply to work that is never planned. *Today* shows them in a
  section below the day's work, under the same *mine / everyone* filter. A hold credits
  the assignees (the viewer when there are none); once they are counted the row shows a
  check, whom it counted for and *Someone else*, whose picker disables the counted people.
- **D-091-70 The multipliers are frozen once:** the percent is the one of the work day at
  the moment of recording. A later date correction keeps it, as it keeps shares and coins
  (D-091-49); cancelling a period keeps what its days paid; a change of people is priced
  with the frozen map, and a member missing from it counts × 1.
- **D-091-71 Every member reads the multipliers:** the member list carries `coinFactor`, the
  house payload `bonusPeriod` (the running period, else the next planned one) and
  `cyclesFrom`. Only the owner writes them: both commands check the role before the house
  lock and again under it, are receipt-protected and listed in the command audit.
- **D-091-72 Period limits:** a period starts today or at most 366 days ahead and overlaps
  no other period that is not cancelled (`409`). Cancelling a cancelled period changes
  nothing; an ended one answers `409`. The command id of the `DELETE` is a query parameter.
- **D-091-73 Erasure reaches the frozen factors:** erasing an account removes its wallet id
  from `coin_factors` of the house's completions, with the rest of its ledger identity.
- **D-091-74 Interim homes until WP-B6:** the sleep control sits in the opened row of the
  task list (adults and the owner), the bonus switch in the edit form only — creation takes
  the flag with the one form of WP-B6 — and `RewardSettings` hosts the coin factors and the
  bonus days above the catalog under *Settings → Household → Rewards*. `TodayView.vue` grew
  from 551 to 567 non-blank lines (banner and bonus section) and `TaskEditForm.vue` from
  953 to 978; WP-B6 rebuilds the form, and its GUI review decides on the length of *Today*
  (§8, the criterion on view length).
- **D-091-75 Contract details that came with RF-091-07:** `recordedBy` of a recorder who
  left the house is the placeholder the completion read model already used (`userId: null`,
  *Removed member*; the feed's allocation rows say *Former member*, RF-091-10); postponing a deleted room task answers `404` like its history, through
  the one visible-room-task resolver; `expectedVersion` is a whole number or its canonical
  decimal string in the complete, postpone and sleep commands; `isVoluntary` must be a
  boolean.
- **D-091-76 No index on the audit rows:** the activity read with its events stays at 13
  statements for 3 and for 30 rows, so the budget shows no need (D-091-09). The limit of
  three presses sent by the server stays with RF-091-09.
- **D-091-77 Proof P4 covers the multipliers:** the upgrade fixture expects the new table
  and columns, checks that every member starts at × 1 and that no stored completion got a
  frozen multiplier, and on the upgraded household sets a factor and a bonus day and
  compares every wallet with what the frozen multipliers of one completion say
  (`tests/recovery/upgrade-cycles.php`). Extends D-091-40.

### Review decisions (interim Full review of WP-B4 and WP-B5, 2026-11-18)

The operator chose the recommended option of each question.

- **D-091-78 Fix the verified defects now, the privacy one included:** the erasure of
  an account that had left the house reaches the frozen coin factors too (D-091-73),
  and the correctness defects below are fixed in the review, each with a regression
  test; so are a check icon shown before the viewer is known, a missing test for a
  member the frozen map does not know (D-091-70), a browser budget that the bonus read
  had broken, and the stable log key of ADR-011 §5.
- **D-091-79 Structure and performance leftovers are routed:** the duplicates that
  RF-091-07 meant to remove, the narrower reads of the new paths and the placeholder
  of departed members go to RF-091-10, the accessibility findings of the new
  surfaces fold into RF-091-09; both are due with WP-B6, which rebuilds these
  surfaces. Documentation and behaviour-neutral nits are fixed in the review.
- **D-091-80 Budgets now, no index:** the WP-B4/WP-B5 paths are pinned in
  `CycleCommandQueryBudgetTest` (bonus rows 11, constant for 3 and 30 tasks; sleep 22;
  wake 20; a bonus completion 74; a sleeping task's completion 88; the bonus period of
  the house payload 1), and the activity budget is measured with as many absences as
  rows: 14, not the 13 RF-091-08 recorded. No index on
  `completed_tasks(room_task_id, work_date)`: the per-person rule filters by the
  indexed room task, and a bonus task has a few rows per day.

How the approved fixes work:

- **D-091-81 A bonus task that becomes a regular task starts on the edit day:** the
  anchor its bonus time left behind would have recorded a miss for every rule day it
  spent as a bonus task, or turned an after-completion task red at once. Refines
  D-091-28 and D-091-44.
- **D-091-82 Going to sleep ends the postponement:** the current cycle ends without an
  outcome and its `cycle_key`, `postpone_count` and `postpone_days` with it, so the
  fresh cycle of the wake day starts unmoved also when it gets the same key. Extends
  D-091-66.
- **D-091-83 A sleeping task whose bounded rule ended cannot be completed:** waking it
  leaves no cycle to fulfil, so its completion is refused with `409 TASK_ENDED` and a
  translated toast; waking it closes it. The refusal used to read `STALE_TARGET`, which
  a reload never cured. Extends D-091-67.
- **D-091-84 A bonus completion leaves the version as it is:** a bonus task has no
  cycle, so its completions, their undo and their date corrections only move its
  last-completed mark; several members complete it on one day with the version their
  rows showed. Edits, sleeping and waking still move it. Extends D-091-68; ADR-021 §3
  and §8 amended.

### Decisions for WP-B6 (operator, 2026-11-18)

Asked before the first line of WP-B6; the operator chose the recommended option except
for bulk assignment.

- **D-091-85 Further fields fold under *More*:** the form shows what the mockup shows;
  description, instructions, priority, category and visibility sit in a collapsed *More*
  section at its end. The row drops the priority badge, and the description moves into the
  opened row.
- **D-091-86 A task is deleted from the form:** *Delete task* at the end of the edit form,
  with a confirmation, for adults and the owner; the row's delete button goes.
- **D-091-87 Bulk assignment goes:** assignees change in the form only (FEAT-UX-040); the
  row checkbox, the bulk bar and `POST /room-tasks/bulk-assign` go.
- **D-091-88 The setup wizard creates one definition per room:** new households start
  without shared definitions; existing shared definitions split on edit (FEAT-UX-043, form
  part).

### Implementation decisions (WP-B6, for the interim review)

Taken while implementing; each is open to the operator's GUI review and the interim review
of WP-B6.
Gates of the implementation round (2026-11-19, uncommitted tree on `1c962cf8`): Jest 185 suites /
2584 tests, ESLint, stylelint, build, l10n (1287 keys), php-cs-fixer, docs encoding, backlog
and adapter checks; PHPUnit unit 1845 and integration 680 on PostgreSQL (canonical), no skips;
the browser gate's self-test and its regenerated scenario manifest; focused browser specs 32
passed on `chromium` and 81 on `chromium-serial-db`, and the phone core set 11 passed each on
`mobile-chromium` and `mobile-webkit`. Not run in this round: the MariaDB, SQLite and AIO rows
and the proofs P1 to P3 (no schema step; the recovery fixture now writes sub-tasks through the
form's save), which the interim Full review runs.

- **D-091-89 One save, one command:** `PUT /room-tasks/{id}` takes `commandId`,
  `expectedVersion` from the row's `cycle` block, the form's fields (`task`, `roomId`,
  `assigneeUserIds`, `checklistItems`, `firstDate`, `sleeping`, `wakeOn`) and `scope`; every
  field but the receipt is optional and an absent one stays. The receipt `room_task.edit`
  keys the whole body, so a repeated command replays and a changed body under the same id
  conflicts. Only definition values that differ from the stored ones count as a change, so a
  save that changes nothing of a shared definition needs no scope. The answer is
  `{ task, roomTask, assignees, checklistItems, cycle, replay }`.
- **D-091-90 A move takes the room task's history along:** completions, closed cycles and
  assignees belong to the room task and move with it; the room journal keeps every entry in
  the room where it happened. A room that already holds the definition answers `409`, a
  foreign room `404`. Settles FEAT-UX-040's open question of what moves.
- **D-091-91 A split keeps every fact:** *this room* copies the definition and its sub-tasks
  for this room task; the ticks of the current cycle follow their sub-tasks, and completions,
  closed cycles and the journal stay as recorded. Without a scope the save answers
  `409 SCOPE_REQUIRED` and writes nothing; the form asks and saves again.
- **D-091-92 One window change per save:** a changed first date wins over a changed rule,
  which wins over changed assignees; a split or a move alone only updates the row; the sleep
  switch comes last. The first date changes only before the first completion (`409`
  afterwards, D-091-19); a bonus task has no window and ignores it.
- **D-091-93 Sub-tasks are part of the form:** the form sends the whole list; an entry with
  the `id` of one of the task's sub-tasks keeps it, one without adds a sub-task, a missing
  one goes, and its ticks stay as facts without counting. At most 50 entries of at most 255
  characters. Adults and the owner edit them, as they edit everything else in the form (the
  checklist editor of CS-PLAN-042 was the owner's). The four item write routes go, and with
  them their audit log lines: the command receipt of the save names who changed what.
- **D-091-94 What goes with the one form:** `PUT /tasks/{id}`, `POST /room-tasks/from-template`,
  the mount variant of `POST /room-tasks`, `POST /room-tasks/bulk-assign` (D-091-87),
  `GET|PUT /room-tasks/{id}/assignees` and the four checklist item writes (96 routes, pinned
  by `RouteMatrixTest`). The creation takes `checklistItems`, `sleeping`, `wakeOn` and
  `isVoluntary` in its one closed envelope. The overdue-claim dialog goes: recording for
  someone else (WP-B2) covers it.
- **D-091-95 The owner of a private task is claimed, not sent:** `ownerUserId` is no longer a
  form field. A task that becomes private, or a private task without an owner, is claimed by
  the member who saves it; an assignee's edit of a private task leaves its owner. The
  transfer to another member through the update went.
- **D-091-96 The template fill uses the default level:** *From template* lists the templates
  of a room type, the chosen room's type first and the household's under every type (as the
  per-room picker had them), and fills name, description, instructions,
  energy, priority, category and the rhythm of the setup wizard's default level. The
  Putzmuffel ↔ Putzteufel switch of the picker sketch stays with the catalog content of
  FEAT-UX-043, which this plan does not take on.
- **D-091-97 The energy field stays a select:** 1 to 5 and *Longer task* up to 20, as
  before; the form only moves it next to the name.
- **D-091-98 RF-091-10 shapes the structure:** postpone, sleep and the save share
  `RoomTaskCommand` (lock, receipt, the guard with the member as they are now, the fresh
  answer); `CycleService` hands the projection, the misses and sleep to `CycleProjection`,
  `CycleMisses` and `CycleSleep`; both bonus-period commands answer the period the house
  shows now; one placeholder, *Former member*, for a departed member; the server sends
  `postponeLimit` in the `cycle` block and the activity event; the room task payload carries
  `anchorOn`, the form's first date.
- **D-091-99 One row everywhere:** `TaskRow` (variants due, overdue, possible and list) opens
  in place to `TaskRowDetails` with rhythm, window, last done, assignees, description and
  instructions, the sub-tasks, postpone, *For someone else*, *Edit*, sleep and the history;
  the priority badge (D-091-85) and the row checkbox (D-091-87) are gone. `TodayView.vue`
  stays at 567 non-blank lines; the GUI review decides on its length (D-091-74).
- **D-091-100 A save changes only what changed:** an empty description, instructions or tip
  is no text (stored as null, and a stored empty string counts the same); the form sends the
  rhythm only when it changed, and reads a legacy `custom` type as every week, as the
  windows derive it. The browser layer found it: a members-only save of a shared task
  without instructions asked for the scope.
- **D-091-101 The row on a phone:** *Done*, the text and the arrow share one line, and the
  text takes everything the two controls leave. The guard of RF-087-08 (the text never
  shrinks to a sliver) is re-pinned from 70 percent of the row to that rule; the GUI review
  decides on the layout.
- **D-091-102 One look and one refusal:** an overdue row of the list has the red border and
  badge of *Today*, without a tinted background; the people of an entry name everyone it
  credits (`entryPeople`) in the history and the feed; a refusal shows the server's
  translated message (`showRefusal`), without a code.

### GUI review of WP-B6 (operator, 2026-11-21)

The operator reviewed the form and the row on the development stack ("so far everything
works") and asked for the changes below. D-091-89 to D-091-102 drew no objection and stand
for the interim review.
Gates after the GUI review (2026-11-21, uncommitted tree on `1c962cf8`, frontend and tests
only): Jest 186 suites / 2635 tests, ESLint, stylelint, build, l10n (1284 keys), docs
encoding, backlog and adapter checks, the browser gate's self-test; focused browser specs
26 and 17 passed on `chromium`, 81 on `chromium-serial-db`; the phone core set on
`mobile-chromium` and `mobile-webkit` passed after its text-width guard followed the card
(the text fills the space between *Done* and the arrow, D-091-101).

- **D-091-103 A task is put to sleep in the form only:** the opened row loses *Put to
  sleep* and its date form. A sleeping task keeps *Wake up* in its row; it also wakes by
  its wake date, by a completion and through the form.
- **D-091-104 Every task is a card:** each row has its own frame, on *Today* and in the
  task list, so a section reads as a list. *Also possible today* stays the collapsed line
  it was.
- **D-091-105 One room button:** the form's two selects become one button that opens the
  floors in place; a floor opens its rooms, a room is the choice.
- **D-091-106 Quick filters in the task list (the operator extends WP-B6):** *People* next
  to the scope buttons ticks members and unassigned work, with *All* and *None*; the three
  scope buttons are its presets, so both always agree. Below them the room button of
  D-091-105 filters by a floor or one of its rooms. Person, floor and room leave the
  *Filters* panel, which keeps the detail filters. `?scope=` links work as before; a custom
  choice of people is not mirrored in the address.
- **D-091-107 Cleanliness from the windows is its own work:** the operator's proposal (a
  task's value falls from 100 % after its window to 0 % past the next one; room, floor and
  house as means) and a chart over time are routed to the backlog (FEAT-UX-071, a fold-in
  to FEAT-UX-058). They need a concept and an ADR next to ADR-014 and are not part of this
  plan.

### Review decisions (interim Full review of WP-B6, 2026-11-21)

Asked in one batch; the operator chose the recommended option each time. D-091-89 to
D-091-107 accepted, with D-091-92, D-091-93 and D-091-100 amended as below.

- **D-091-108 A save never takes the member's own sight:** a save after which the acting
  member could no longer see the task — leaving the assignees of a private task that is
  not theirs — is refused with `403` before anything is written. The owner changes who is
  assigned. Before, the write committed and the answer said *not found*.
- **D-091-109 Only its owner changes a private task's visibility:** an assignee edits
  everything else. Before, an assignee could make the task public and private again and
  owned it afterwards. Narrows D-091-95.
- **D-091-110 A member who cannot see every room of a shared task splits silently:** the
  save changes this room's copy whatever the scope says, asks nothing and leaves the room
  tasks the member may not see untouched and unmentioned. The conflict of a move into a
  room that already holds the definition stays, also where that instance is hidden: a
  known, accepted disclosure inside one house.
- **D-091-111 Length limits:** a name takes at most 255 characters, a description is text of
  at most 2000; longer input is a translated `400` instead of a database error.
- **D-091-112 What the review changed in the contract:** waking comes before the window
  change of the same save, so a first date set with it holds (amends D-091-92); an unchanged
  sleep state and an unchanged first date are not checked again, so a task on its wake day
  and a never-completed task with an old anchor save; the form sends the version it was
  opened with and only the fields the user changed (completes D-091-100), so a second click
  after a refusal cannot overwrite someone else's change; the save is an audited command
  again (amends D-091-93: the receipt does not replace the operational log line); a created
  task stores an empty text as no text, like an edited one.

### Implementation decisions (WP-B7, for the interim review)

- **D-091-113 What counts as left without a caller:** a method goes when it has no
  production caller on the branch and had one on `master` before this plan
  (`2b9efaf2`), or when only a retired route reached it. That covers D-091-38 and
  RF-091-06 in full and three methods beyond them (`TaskAccessPolicy::assertCanViewTask()`,
  `CompletedTaskMapper::countDistinctCompletedRoomTasksInSet()`,
  `RecurrenceCalculator::dayBefore()`). `Room::updateCleanliness()` had no caller on `master`
  either and went by choice, with the cleanliness delta it belonged to. Methods that had no
  caller before the plan either stay and are listed in CR-SVC-67.
- **D-091-114 The history mappers keep what still runs:** `OccurrenceMapper` keeps the
  one read of the recognitions (`findByHousePlannedBetween()`), `OccurrenceMemberMapper`
  the member read next to it and the erasure by wallet, `ScheduleRevisionMapper`,
  `AgreementMapper` and `RoomTaskChecklistProgressMapper` the anonymization of an erased
  account and the clean-up with a deleted room task. Their entities keep every field and
  constant: they describe the retained rows.
- **D-091-115 Retained journal entries are seeded as stored rows:** the writers of
  `manual_delta` and `last_done` went with their commands, so the replay tests seed such an
  entry through `StoredTimelineFixtures::insertStoredManualDelta()`, as the occurrence
  rows are seeded since WP-B1. The reset and replay scenarios of
  `RoomReplayConcurrencyTest` stay, with a retained delta or a completion as the later
  work; the four tests of the manual-delta command (receipt replay, changed payload, stale
  version, clamp of a new request) went with the command.
- **D-091-116 The frozen dates of the fixed rules moved to the lookup:**
  `RecurrenceCalculatorTest` pinned the fixed sequences through `enumerate()` and compared
  `firstFixedDayOnOrAfter()` with it. The same date lists now pin the lookup directly, each
  shape of the data provider carries its first days, and the from-any-day property stays.
  The cases of the stored model only (ordinals, a one-time lifetime, a zero interval, a
  reversed query window, interval revisions) went with the enumeration.
- **D-091-117 Two pins moved with their code:** the savepoint rule of a first tick
  (an unexpected insert failure propagates without further SQL) to the new
  `CycleProgressMapperTest`, and the day bounds of the stored occurrences to the one read
  that is left. The tests of the two retired routes went with them: the cross-house and
  visibility refusals of the room statistics, their counts, and the controller and service
  tests of the cleanliness delta.
- **D-091-118 Help and settings wording:** the glossary explains *Due window* and *Overdue*
  where it explained *Agreement* and *Catch-up*; the context topic *Agreements and shares*
  is *Work shares* (`shares`); the streak is *days in a row on which you did your due
  tasks on time*. The progress topic and the user guide no longer call free days neutral:
  only an absence is (D-091-03). The hints of the two digests say what is delivered, the
  reset texts no longer list agreements, the ranking text says *public tasks*, and the
  refusal of an unconfirmed split reads *The shares differ from the suggested split and
  must be confirmed*. The wider help for postponing, sleeping and bonus tasks stays with
  WP-G.
- **D-091-119 Retained agreement rows of the digest queue:** the `agreement` case of the
  relevance check went, so such a row is never counted and never consumed; it stays
  `queued` until its slot expires with it, as before. BUG-UX-015 closes with that
  (D-091-12).

Routed while implementing: CR-SVC-67 (methods without a caller from before the plan),
BUG-UX-017 (the organization digest is called weekly) and TECH-DEV-002 (a rebuilt bundle is
not fetched on the development stack, operator report of 2026-11-21).

### Review decisions (interim Full review of WP-B7, 2026-11-21)

No question needed the operator; D-091-113 to D-091-119 accepted with these amendments.
RF-091-11 stays open for the operator.

- **D-091-120 Nothing serializes a retained row** (narrows D-091-114): the five history
  entities keep their fields and the constants that name stored values. Their
  `jsonSerialize()`, the key helpers (`Occurrence::toPublicKey()`,
  `RoomTaskChecklistProgress::occurrenceKey()`), `ScheduleRevision::weekdayList()` and
  `Agreement::DEFINITION_KINDS` lost their last caller in this plan and went, with
  `OccurrenceTest` and `RoomTaskChecklistProgressTest`.
- **D-091-121 The help says what the rules say** (amends D-091-118): an overdue task is one
  red entry until it is done and, on a fixed day, until the next window opens; a task whose
  whole window lies inside an absence counts neither for nor against the person, instead of
  *nothing counts for or against anyone*; the streak counts the days on which every task
  whose window ended was done on time, and a day without one is neutral; the organization
  digest names what is queued (overdue tasks someone else did, absences, reward requests);
  *Work shares* names coin factors and bonus days; the energy hint of the form no longer
  speaks of a planner that places tasks. German uses *Zeitraum* for the window everywhere,
  as the labels of the task row do, and *öffentliche Aufgaben* in the ranking text.
- **D-091-122 What the review added to the tests:** the fixed rules are compared through
  March 2028 with a per-day reference that shares no code with the calculator; the check
  that a room-scoped list hides a private task moved from the retired statistics to
  `GET /room-tasks?roomId=` on a real database; every help topic is checked for the
  retired words; the three retired-route guards of the route matrix share one body.

### Follow-up of WP-B7: the two other routes without a client (RF-091-11, 2026-11-21)

- **D-091-123 Both routes go (operator, 2026-11-21):** `PUT /pauses/{id}` with
  `PauseController::update()` and the `pause.shorten` command, and `GET /house/health`
  with `HouseController::health()`. An absence is ended by deleting it (ADR-020). Stored
  shortened revisions keep governing; a pending one is still decided and a declined one
  still leaves its absence deletable. The right to end an absence keeps its one check, now
  named `assertCanEndPause()`.
- **D-091-124 The statistics compute the house health:** `GET /house/stats` reported the
  stored `house_health`, and only `GET /house/health` refreshed it. Its client went with
  the Dashboard in Release A, so *Insights* has shown the last stored value since then
  (100 % on the family seed, whose rooms average 63 %; on the retained baseline the last
  mean the Dashboard refreshed). The statistics now take the mean
  cleanliness of the rooms they read, 100 without rooms, with no further query;
  `HouseService::calculateHouseHealth()` and `HouseMapper::updateHealth()` went. The stored
  column keeps its last written value, a reset still writes 100, and its removal from the
  house payload is folded into FEAT-UX-071.
- **D-091-125 Tests that used the shortening as their vehicle:** the two races of
  `PauseConcurrencyTest` (two connections on one revision, one receipt replayed on two
  connections) run on the deletion; the projection of a shortened absence is proven on a
  revision stored as the command left it (acknowledged, decided by its subject), next to
  the pending one from before ADR-020 that the owner acknowledges; the lost-authority
  cases of `OwnerCommandAuthorizationTest` keep the commands that are left and gain the
  deletion of a household absence, which only the shortening had covered. The tests of the command's own rules (clamp to today, no earlier end, a child's
  direct acknowledgment, the role refusals) went with it.

### Positions for the Standard A review

These settle the remaining open points of the concept. They bind once Standard A passes.

- **D-091-07 Cycle storage (open point 3):** the open window is derived, never stored. Per room
  task, only the inputs are stored: the first date until the first completion, and for the
  current cycle its key, the postponement count and shift; plus the sleep state. Closed cycles
  live in their own table (D-091-17), one row per room task and cycle key under a unique key,
  with the credited members. The completion writes the cycle it fulfilled in its own
  transaction (window start and end, classification), frozen like the rate; an undo reopens it.
  The timeline job records a fixed-day cycle that was never done as missed once the next window
  opens (D-091-02); after-completion and one-time cycles are never missed, they stay the red
  entry until done late. Sub-task progress is keyed by the cycle instead of the occurrence. Rows
  written before the update stay read-only history and are never rewritten.
- **D-091-08 Exactly-once (open point 3):** the client sends the cycle key and the room-task
  version it showed with the command UUID. Under the existing house-first lock (ADR-011) the
  server refuses a second valid completion of the same cycle (409 with a stable code) and
  replays a repeated command UUID; the unique cycle key of the closed-cycle table backs it.
  Completions keep no unique index, because an undone completion keeps its row.
- **D-091-09 Calendar reads (open point 3):** windows are computed on read for the visible
  range. No derived cache unless the request budgets of `WorkflowQueryBudgetTest` and the browser
  budgets fail; then a cache is its own reviewed step.
- **D-091-10 No single-cycle handover, no restart (open point 4):** assignees change in the form,
  and anyone may record a completion for someone else (D-091-05). *Restart* has no successor:
  sleeping replaces pausing a task, and absences are neutral.
- **D-091-11 Scheduler rules (open point 5):** a horizon of 14 house-local days. Earliest window
  end first; ties by higher energy, then room-task id. A cycle goes to the responsible
  assignee's preferred day inside the window with the most remaining energy, else to any
  available day inside it with the most remaining energy, else to the window's last day as
  overload. Unassigned tasks go to the eligible member with the most remaining energy, ties by
  house-user id. Team tasks use the responsible person's budget only. *Stability:* the first
  read of a house-local day stores that day's suggestion (D-091-18); during the day work only
  leaves it (done, postponed, asleep) or joins it (created today and due); everything else
  moves only from tomorrow on.
- **D-091-12 Daily summary (open point 6):** the agreement lines go; the summary counts the
  member's suggested work for today plus their overdue tasks. BUG-UX-015 closes with the
  agreement queue. The pause-request lines went with ADR-020 in Release A; ADR-016, which names
  agreement receipts in the queue, gets a status note.
- **D-091-13 Integration (open point 7):** one implementation branch for B and C, merged into
  `master` once after the final Full review (see the strategy above). `master` takes only
  unrelated fixes meanwhile, each merged into the branch.
- **D-091-14 Where the new state lives:** sleeping and the first date belong to the room task;
  *bonus* is a property of the definition and builds on `tasks.is_voluntary`; the coin factor
  belongs to the house member; bonus days are a house period. Preferred days join the existing
  `schedulingPreferences` of the member. Exact columns, the closed-cycle table and the snapshot
  table included, are fixed in ADR-021 and ADR-022 before code.
- **D-091-15 Reward math:** coins = frozen rate × energy × member factor × bonus-day factor, in
  exact minor units (`× 1.5` as `× 3 / 2`, rounding down to one minor unit after the product);
  both factors are frozen on the completion, so undo and attribution reverse exactly what was
  paid. Energy, weekly goal, rhythm, ranking and arcade unlocks never see the factors.
- **D-091-16 Rights:** as the concept's table
  ([people and rights](../../concepts/simplified-core.md#people-and-rights)): children postpone,
  record for others and change or delete their own latest entry; adults also edit tasks; only
  the owner corrects any entry, sets factors and starts bonus days. Recording for others
  credits only members who may see the task (D-091-20). Every check runs in the service.

## 3. Backend / Frontend Changes

### WP-0 Contracts (before code)

- **ADR-021 Derived due windows:** window rules per mode (after completion, fixed weekday,
  fixed day of month clamped to the month's end, one-time), flex and postponement, early
  completion except on the same local day, the single red entry, sleeping, bonus tasks, cycle
  keys and exactly-once (D-091-07, D-091-08), the classification (D-091-02), absences
  (D-091-03), the cutover (D-091-04) including the first window of every existing room task
  and existing voluntary definitions becoming bonus tasks, the closed-cycle table and the cycle
  key of sub-task progress (D-091-17), the first date replacing *last done on* (D-091-19),
  corrections by role (D-091-16), recording for others under ADR-010 visibility (D-091-20) and
  the coin factors (D-091-15). Supersedes ADR-013 except its pause part (ADR-020); amends
  ADR-014 (date correction, recording for others, factors, recognition inputs), ADR-011
  (completion target) and ADR-016 (agreement receipts leave the digest queue).
- **ADR-022 Live suggestion:** D-091-11, the daily snapshot (D-091-18), capacity that shows
  overload and never refuses, child limits on *Today*, the retirement of preview and apply.
  Supersedes ADR-012.
- Concepts: `task-model-and-sync.md` (C1 and C2 replaced), `gamification-and-rewards.md`,
  `energy-and-balancing.md`, `calm-core-ux.md`, `simplified-core.md` (open points settled).
  `docs/api/README.md` and `docs/api/notifications.md` describe the new and removed routes.

### WP-B1 Window core (FEAT-UX-068)

- `lib/Service/WindowService.php` (new): the current cycle of a room task from its rule, last
  valid completion or first date, postponement and sleep state, in the house time zone
  (ADR-009). One read path for lists, *Today*, the calendar, completion and the job.
- Schema steps `Version1035…` (new, ordered, populated-upgrade tests): room-task cycle state,
  the closed-cycle table (D-091-17), the cycle key of sub-task progress, the completion's cycle
  facts, the definition's monthly fixed day where the existing fields cannot carry it.
- `OccurrenceReconcileService`, `TimelineJob`: materialization stops; the job records missed
  fixed-day cycles (D-091-07). Every command that changes a window updates the projection
  `room_tasks.next_due` in its own transaction, so the list queries that filter by it stay
  current; the job only repairs what the passing of time changes.
- Retired together with materialization, so the branch keeps building green: `occurrence#amend`,
  `occurrence#catchUp`, `occurrence#restart` (`OccurrenceController`, `AgreementService`,
  `OccurrenceAgreementService`), `room_task#updateDueDate`, `room_task_last_done#update`
  (D-091-19), `scheduling#preview` and `scheduling#apply` (`SchedulePlanService`,
  `SchedulePlanner.vue`), and the capacity refusals that read stored future rows
  (`DailyCapacityService::assertCommitmentsFit` and its callers,
  `RoomTaskService::assertChildCapacityForDate`). Their store actions, UI entry points and tests
  go with them; journal replay and the read paths of old rows stay.
- `ChecklistService`: sub-task progress per cycle; partly ticked work stays saved (D-089-02).
- `RhythmService`, `WeeklyGoalService`, `RankingService`: read the frozen classification, stored
  occurrences up to the update and the closed-cycle table after it; absences neutral
  (D-091-03); cutover baseline (D-091-04).
- Overdue: one red entry per task (`RoomTaskReadService`, `TodayView`, task list).

### WP-B2 Early completion and recording for others (FEAT-UX-041, FEAT-UX-051)

- `CompletionService`, `OccurrenceCompletionService`: the target is the current cycle at any
  time, except on the same local day as the previous completion of that task; on a fixed day an
  early completion fulfils the upcoming cycle and the day stays.
- Every member may record for any other member who may see the task (reverses Q-074-02;
  D-091-20); `completed_tasks.user_id` keeps who recorded; the history shows it when it differs
  from the people credited. Taking over someone else's red entry keeps its digest receipt.
- `HoldButton`: a short tap opens the picker for who did it and, with sub-tasks, which are
  done; holding completes everything. Marking sub-tasks runs inside the completion command.

### WP-B3 Postpone and history (FEAT-UX-050, FEAT-UX-052)

- Postpone command (new route): any member, up to three times per cycle, each by half the flex
  days rounded up; not for tasks without flex days; the next cycle is unaffected.
- History per task from the inline row: date change and deletion of one's own latest entry for
  every member; owner corrections of any entry (date, person through the existing attribution,
  delete through the existing undo). The date change is a new audited correction kind that
  re-derives classification, the closed cycles after the corrected entry, weekly goal and room
  journal (ADR-014 amendment).
- Recent activity (`ActivityService`, Insights): postponements, date changes, deletions and
  absences next to completions.

### WP-B4 Sleeping and bonus tasks (FEAT-UX-069)

- Sleep with an optional wake date per room task: no window, never overdue, no capacity, no
  suggestion. Waking starts a fresh cycle from the wake date.
- Bonus tasks: never due, never overdue, never suggested; at most one completion per person,
  task and house-local day; energy and coins paid; outside weekly goal, rhythm and ranking.
  *Today* lists them in their own section.

### WP-B5 Coin factor and bonus days (FEAT-UX-070)

- Owner settings: a factor of × 1, × 2 or × 3 per member; a bonus period of one to three days
  with × 1.5. Both on coins only, frozen on the completion (D-091-15). *Today* shows a running
  bonus period.

### WP-B6 One form and one row (FEAT-UX-040, FEAT-UX-043 form part)

- One form for new and edit in both entry points: room (floor, then room) with a move command
  under the room-task lock, assignees, sub-tasks, rhythm with fixed weekday or day of month,
  flex, energy, first date (until the first completion; it replaces *last done on*, D-091-19),
  the read-only next window, sleeping and bonus. One save is one atomic write. Adults and the
  owner edit; children do not.
- Templates only fill the form. Editing a definition that several rooms share asks *this room*
  or *all rooms*; *this room* splits off a copy for that room task, and every completion,
  closed cycle and journal row stays where it was.
- The inline expansion of the task row holds details, sub-tasks, postpone, record for someone
  else, edit, sleep and the history. `TaskListDetails`, `RoomTaskEditModal` and the separate
  details view go.

### WP-B7 Retiring the rest of the old surface

- Routes go: `PUT /rooms/{id}/cleanliness` (`RoomController`, D-089-09), whose retained
  journal entries keep replaying, and `room_task#roomStats`, which has had no client since
  D-090-10. The two other routes of those decisions went in the follow-up (RF-091-11,
  D-091-123): `PUT /pauses/{id}` and `GET /house/health`.
- Digest: the agreement lines and their relevance check go; their queue writes went with the
  writers in WP-B1, and retained agreement rows are skipped (D-091-12, BUG-UX-015).
- Help, glossary and user guide drop catch-up, agreements, *last done on* and the applied plan.
- Code left without a caller after WP-B1, proven by the route matrix and the build gate.

### WP-C Live suggestion and preferred days (FEAT-UX-059)

- `lib/Service/SuggestionService.php` (new): computed on read per D-091-11 from the open
  windows, energy per weekday, preferred days and absences; nothing applied as an agreement.
- The daily snapshot (D-091-18): written by the first read of a house-local day under the house
  lock, then only left and joined by the rules of D-091-11; the timeline job deletes old days.
- Preferred days: a per-member setting next to energy per day under *Settings → Planning*.
- Capacity shapes the suggestion and shows overload (the refusals went in WP-B1). A child's
  daily limit keeps limiting what *Today* offers that child.
- *Today* and the calendar show the suggestion; the remaining planner parts of
  `store/scheduling.js` and `SchedulingView.vue` go.
- The daily summary counts the member's suggested work for today plus their overdue tasks
  (D-091-12, RF-091-12), and the *Work shares* help finds its place (RF-091-13).

### WP-G Release `0.8.0-alpha.5` for A, B and C (RELEASE-002)

The version stays (D-091-06). Changelog sections for B and C next to A's; README, user guide,
help and screenshots; the operator's GUI review on the development stack; final Full review;
the qualification pipeline on the joint candidate; export of one selected smoke run; operator
deployment; verified record in the deployment guide, including `tests/migrations/applied-migrations.json`
with `Version1034` and every new step; the dated changelog heading, roadmap and documentation
map. These carry the three criteria CS-PLAN-090 handed over (D-090-81). Each step needs its own
approval.

## 4. Tests

- **WP-B1:** `WindowServiceTest` (each mode, flex, month-end clamp, first date, sleep, one-time,
  time zones, DST); integration on all providers for the closed-cycle writers (exactly once per
  cycle, concurrent job runs), the `next_due` projection matching `WindowService` after every
  window-changing command, sub-task progress per cycle with partly ticked work kept, the
  cutover (each existing room task's first window, an existing voluntary definition becoming a
  bonus task, the stored baseline) and the recognitions; the retired routes answer 404 and the
  route matrix pins it, old rows still read; populated-upgrade tests from the `0.8.0-alpha.4`
  shape for every new step on PostgreSQL, MySQL and SQLite, and proof P4 on both runtime rows.
- **WP-B2:** early completion on every mode, the same-day refusal, a fixed day keeping its day;
  recording for others and undo, a private task crediting only who may see it (D-091-20), a takeover
  keeping its digest receipt; concurrent completions of one cycle (`RecordingForOthersTest`,
  next to the races of `CompletionConcurrencyTest`); Jest for tap and hold with keyboard
  parity; browser scenario.
- **WP-B3:** postpone limits, rights and the next cycle; each correction across coins, rhythm,
  weekly goal and room journal, a date correction re-deriving the closed cycles after it;
  activity entries; browser scenario.
- **WP-B4, WP-B5:** sleep and wake, bonus once per day, outside the recognitions; factor math in
  minor units, frozen with undo and attribution; owner-only settings; browser scenario for a
  bonus day on *Today*.
- **WP-B6:** form specs (atomic save, split on edit, move between rooms keeping history,
  read-only next window), inline row specs, the affected browser specs with their pinned
  scenarios moved, none dropped without a successor or a recorded reason.
- **WP-B7:** the cleanliness and the room-statistics route answer 404 and the route matrix
  pins both; retained journal entries and agreement queue rows still read or are skipped.
  Follow-up (RF-091-11): the shortening and the health route have no route (the health
  one answers 404, the shortening 405 because DELETE keeps its path) and are pinned;
  stored shortened revisions keep governing; the statistics compute the health.
- **WP-C:** `SuggestionServiceTest` (D-091-11 rules, preferred days, absences, team tasks,
  overload, the child limit); the daily snapshot (one per house and day under concurrent first
  reads, the leave and join rules, deletion of old days); request budgets for *Today* and the
  calendar; browser scenarios for *Today* and the calendar.
- **WP-G:** the complete gates of the qualification: PHPUnit on PostgreSQL, MySQL, SQLite and the
  AIO row, both browser gates, package build and smoke, the preservation proofs.

## 5. File Manifest

```text
new:      docs/adr/ADR-021-derived-due-windows.md, docs/adr/ADR-022-live-suggestion.md
new:      lib/Service/WindowService.php, lib/Service/SuggestionService.php, the closed-cycle and
          snapshot entities and mappers in lib/Db/ (names fixed in ADR-021 and ADR-022),
          lib/Migration/Version1035… (one or more ordered steps) with their upgrade tests
new:      lib/Service/CompletionAuthority.php, CompletionDateCorrection.php, PostponeService.php,
          CycleAdvanceService.php, TaskResponsibility.php, ActivityEventService.php,
          RecognitionPass.php, lib/Controller/RoomTaskCycleController.php (WP-B2, WP-B3)
new:      src/components/Tasks/CompletionPicker.vue, PostponeButton.vue, TaskHistory.vue,
          src/store/taskCycle.js, src/utils/commandId.js (WP-B2, WP-B3)
new:      lib/Migration/Version1037Date20261118000000.php, lib/Db/BonusPeriod.php,
          BonusPeriodMapper.php, lib/Service/SleepService.php, CoinMultiplierService.php,
          CreditPolicy.php, lib/Controller/CoinMultiplierController.php (WP-B4, WP-B5)
new:      lib/Service/CompletionAccess.php, CorrectionTarget.php, CompletionLocks.php,
          CompletionEvidence.php, CompletionRecognition.php, CompletionReadModel.php,
          CompletionDateCommand.php, ParticipantAllocation.php, RoomTaskAccess.php,
          RoomTaskList.php, CycleReceipt.php (RF-091-07)
new:      src/components/Today/TodayBonusSection.vue, TodayBonusRow.vue, BonusDaysBanner.vue,
          src/components/Tasks/SleepControl.vue, src/components/Settings/RewardSettings.vue,
          CoinFactorSettings.vue, BonusDaysSettings.vue (WP-B4, WP-B5)
new:      lib/Service/RoomTaskEditService.php, RoomTaskCommand.php, TaskEdit.php,
          TaskFormPayload.php, SubTaskList.php, SubTaskWriter.php, CycleProjection.php,
          CycleMisses.php, CycleSleep.php, lib/Controller/RoomTaskEditController.php
          (WP-B6, RF-091-10)
new:      src/components/Tasks/TaskForm.vue, TaskRhythmField.vue, TaskWindowNote.vue,
          SubTaskEditor.vue, TaskFormMore.vue, TaskTemplateFill.vue, TaskScopeDialog.vue,
          TaskRow.vue, TaskRowDetails.vue, src/composables/useTaskFormState.js,
          src/store/taskForm.js, src/utils/taskFormModel.js (WP-B6)
modified: lib/Service/OccurrenceReconcileService.php, OccurrenceService.php,
          OccurrenceSelectionService.php, OccurrenceCompletionService.php, CompletionService.php,
          EffortAllocationService.php, RhythmService.php, WeeklyGoalService.php, RankingService.php,
          DailyCapacityService.php, DigestService.php, ActivityService.php, TaskService.php,
          TaskCreationService.php, RoomTaskService.php, RoomTaskReadService.php,
          ChecklistService.php, SchedulingService.php, PauseProjectionService.php,
          TestDataService.php, lib/BackgroundJob/TimelineJob.php
modified: lib/Db/RoomTask.php, Task.php, CompletedTask.php, Occurrence.php, HouseUser.php,
          RoomTaskChecklistProgress.php and their mappers; lib/Controller/RoomTaskController.php,
          CompletionController.php, ChecklistController.php, SchedulingController.php,
          HouseController.php, RoomController.php; appinfo/routes.php
removed:  lib/Service/AgreementService.php, OccurrenceAgreementService.php,
          SchedulePlanService.php, RoomTaskLastDoneService.php,
          lib/Controller/OccurrenceController.php, RoomTaskLastDoneController.php (in WP-B1, once
          no caller is left), with their unit tests
modified: src/views/TodayView.vue, TaskManager.vue, CalendarView.vue, SchedulingView.vue,
          src/components/Tasks/TaskEditForm.vue, TaskCreateDialog.vue, TaskList.vue,
          TaskListItem.vue, TaskGroupList.vue, src/components/Common/HoldButton.vue,
          src/components/Settings/PlanningSettings.vue, NotificationPreferences.vue,
          src/components/Scheduling/OvercapacityWarning.vue, src/components/Help/HelpGlossary.vue,
          HelpEntry.vue, src/store/roomTasks.js, rooms.js, scheduling.js, house.js,
          src/utils/taskDisplay.js, src/services/api.js
removed:  src/components/Tasks/TaskListDetails.vue, RoomTaskEditModal.vue,
          src/components/Scheduling/SchedulePlanner.vue (and their specs)
new:      src/components/Tasks/TaskPeopleFilter.vue (GUI review of WP-B6),
          lib/Service/SleepSwitch.php (interim Full review of WP-B6)
modified: lib/Controller/TaskController.php, lib/Service/HouseSetupService.php,
          src/components/Tasks/RoomPicker.vue, TaskFilterBar.vue, TaskChecklist.vue,
          src/utils/taskFilters.js, src/store/tasks.js, checklist.js (WP-B6 and its GUI review)
removed:  src/components/Tasks/TaskEditForm.vue, TaskCreateDialog.vue, TaskListItem.vue,
          TaskListBulkBar.vue, TaskTemplatePicker.vue, OverdueClaimDialog.vue,
          RecurrencePresetSelector.vue, index.js, src/components/Today/TodayTaskRow.vue
          (WP-B6, with their specs)
new:      tests/unit/Db/Mapper/CycleProgressMapperTest.php (WP-B7)
modified: lib/Db/AgreementMapper.php, OccurrenceMapper.php, OccurrenceMemberMapper.php,
          ScheduleRevisionMapper.php, RoomTaskChecklistProgressMapper.php, Occurrence.php,
          Room.php, RoomEvent.php, DigestChange.php, lib/Service/RecurrenceCalculator.php,
          RoomReplayService.php, TaskAccessPolicy.php, NotificationService.php,
          src/components/Settings/DangerZoneSettings.vue, src/components/Insights/RankingPanel.vue,
          tests/integration/Support/StoredTimelineFixtures.php, lib/Db/Agreement.php,
          OccurrenceMember.php, ScheduleRevision.php, RoomTaskChecklistProgress.php,
          src/components/Tasks/TaskEnergyField.vue, docs/engineering/notifications.md,
          development.md, docs/concepts/notifications.md, ui-use-cases.md (WP-B7 and its review)
removed:  tests/unit/Db/OccurrenceTest.php, tests/unit/Db/Entity/RoomTaskChecklistProgressTest.php
          (interim Full review of WP-B7)
modified: lib/Controller/HouseController.php, PauseController.php, lib/Service/HouseService.php,
          PauseService.php, lib/Db/HouseMapper.php, docs/adr/ADR-020 (status note),
          docs/concepts/task-model-and-sync.md (RF-091-11)
modified: l10n/de.json, l10n/en.json, l10n/templates/chorestrike.pot
modified: tests named in section 4, tests/e2e/required-scenarios.json, scripts/run-e2e-gate.js
modified: docs/concepts/*.md named in WP-0, docs/api/README.md, docs/api/notifications.md,
          docs/user-guide.md, docs/engineering/architecture.md, deployment.md, testing.md,
          docs/adr/README.md, ADR-011, ADR-012, ADR-013, ADR-014, ADR-016 (status notes only)
modified: appinfo/info.xml (unchanged version), CHANGELOG.md, README.md, docs/screenshots/*,
          docs/release/roadmap.md, docs/README.md, docs/backlog/*
```

## 6. Implementation Order

1. Standard A of this plan; the operator's answers recorded; the implementation branch cut.
2. WP-0: ADR-021, ADR-022, concepts and API reference; reviewed with WP-B1.
3. WP-B1 with its schema steps, upgrade tests and the retired writers; interim review.
4. WP-B2 and WP-B3; interim review.
5. WP-B4 and WP-B5; interim review.
6. WP-B6; the operator's GUI review against the mockups; interim review.
7. WP-B7; interim review.
8. WP-C; the operator's GUI review of *Today* and the calendar; interim review.
9. WP-G candidate; the operator's GUI review of the whole release; final Full review.
10. WP-G release steps, each with its own approval.

Each interim review is a Full review where the review skill requires one (migrations, routes,
authorization, reward facts), otherwise Standard B. Every review commit passes the build gate
and re-measures the size inventory of the files it touched.

## 7. Risks & Mitigations

| Risk | Likelihood | Mitigation |
|------|-----------|------------|
| History before the update reads differently from history after it | High | D-091-04: stored streaks and claimed weeks stay; old rows stay read-only; cutover tests on populated data |
| A completion fulfils the wrong cycle or two at once | Medium | Cycle key plus room-task version under the house-first lock (D-091-08); concurrency tests on all providers |
| Computed windows make lists and the calendar slow | Medium | One read path (`WindowService`), batched inputs, request budgets in PHPUnit and the browser (D-091-09) |
| Removing agreement code breaks a reader of old rows | Medium | The writers go in WP-B1, their read paths stay; old rows read in integration tests from a populated baseline |
| A package leaves the branch red because a removed premise still has callers | Medium | Everything that writes or checks stored future rows goes in WP-B1 with materialization; every review commit passes the build gate |
| The already oversized aggregates grow further (`CompletionService` 1,451, `TaskEditForm.vue` 953, `RoomTaskService` 888 lines) | High | New behaviour goes into new collaborators (`WindowService`, cycle targeting, the factor math); the retirements shrink the rest; the size inventory is re-measured at every review commit |
| Coin factors drift from what was paid | Low | Factors frozen on the completion; exact minor-unit math; undo and attribution tests (D-091-15) |
| The suggestion jumps during the day | Medium | The daily snapshot with fixed leave and join rules (D-091-11, D-091-18); snapshot tests under concurrent first reads |
| A schema step cannot be undone on production | Medium | New ordered steps only, populated-upgrade tests, fresh/upgrade parity, P4 on both rows; backup before the deployment |
| The long-lived branch drifts from `master` | Medium | Merge `master` whenever it moves; interim review commits per package (D-091-13) |
| Scope grows during the GUI reviews | Medium | Findings route scope-first; new wishes go to the backlog unless the operator extends the plan |

## 8. Acceptance Criteria

- [ ] ADR-021 accepted before WP-B1 code and ADR-022 before WP-C code; the superseded and amended ADRs carry status notes; concepts and API reference match the code
- [ ] Every task shows one derived window per its mode and flex; the next window is never edited directly; a new task's first date stays editable until its first completion
- [ ] Open work is not frozen: a changed rhythm, flex or energy applies at once to the current window; completed work keeps what it recorded
- [ ] Early completion works at any time except on the same local day as the previous one; a fixed day keeps its day
- [ ] Overdue shows one red entry per task; no catch-up entries; a fixed-day red entry ends when the next window opens
- [ ] Every member postpones up to three times per cycle by half the flex days; tasks without flex days cannot be postponed
- [ ] Completions are classified at completion and frozen (D-091-02); absences are neutral (D-091-03); stored streaks and claimed weeks survive the update (D-091-04)
- [ ] A completion fulfils exactly one cycle under concurrency and replay
- [ ] Every member records for others, on a private task only for who may see it (D-091-20); tap and hold work with keyboard parity; sub-tasks are marked inside the completion, and partly ticked progress stays saved per cycle
- [ ] Members change or delete their own latest entry; the owner corrects any entry; recent activity shows postponements, date changes, deletions and absences
- [ ] Sleeping tasks have no window and take no capacity; bonus tasks pay once per person, task and day and stay outside the recognitions
- [ ] Coin factors and bonus days apply to coins only, are frozen with the completion and reverse exactly on undo and attribution
- [ ] One form creates and edits tasks, moves them between rooms and splits shared definitions on edit; one inline row replaces the details view and the extra editors
- [ ] The agreement, catch-up, restart, due-date, last-done, preview, apply and cleanliness routes are gone; old rows still read; the first date replaces *last done on* (D-091-19)
- [ ] *Today* and the calendar show the live suggestion with preferred days; today's list stays stable through the daily snapshot (D-091-18); capacity never refuses; a child's limit holds on *Today*; the preview-and-apply planner is gone
- [ ] Every user-visible string goes through `t()`; `l10n:check-keys` is green with real German copy
- [ ] Every new schema step is an ordered step with populated-upgrade tests on all providers; fresh and upgraded installs match; the 26 applied migrations and `Version1034` stay unchanged
- [ ] No view touched by this plan is longer than before, unless the GUI review accepts it; the size inventory names every file above the guideline
- [ ] Operator GUI reviews recorded; their findings resolved or routed
- [ ] `0.8.0-alpha.5` (A, B and C) qualified: full browser gates and PHPUnit suites on both runtime rows, SQLite and MySQL provider suites, reproducible package build and smoke
- [ ] Deployed through the private exchange; the operator's sanitized report verified and imported into the deployment guide; every applied step appended to `tests/migrations/applied-migrations.json` (taken over from CS-PLAN-090)
- [ ] `CHANGELOG.md` with its dated heading, README, roadmap and documentation map name the deployed version; screenshots recaptured (taken over from CS-PLAN-090)
- [ ] Build gate green (`npm test` + lint + backend tests)
- [ ] Required tests ran without new or unapproved skips
- [ ] All implementation decisions and review questions resolved and recorded
- [ ] All plan-owned review findings (§9) resolved or user-rescoped to `backlog.md`
- [ ] Owning backlog items updated or archived according to their actual completion state
- [ ] Durable concepts/decisions integrated into the permanent docs (`docs/concepts/`, `docs/adr/`, `docs/engineering/`, `docs/api/`, `docs/standards/`) — plans are temporary
- [ ] Final Full review is `PASS` or `PASS-WITH-FIXES` (the plan changes the model, authorization and migrations)
- [ ] `docs/backlog/ACTIVE_TASKS.md` updated

## 9. Review Findings (plan-owned)

Findings **within this plan's scope** from implementation rounds or reviews
(scope-first routing per `project-rules.md` §4). Resolve before `done/`;
rescoping to `backlog.md` needs explicit user approval. Out-of-scope findings
go directly to `backlog.md` (or fold into an existing item) instead.

- [x] **RF-091-01 New steps are not applied on kept test volumes** (WP-B1, test
  environment): `docker/test-entrypoint.sh` ran `occ upgrade` only for an app version bump
  (TECH-TEST-006). The version stays `0.8.0-alpha.5` (D-091-06), so `Version1035` and
  `Version1036` stayed pending on the kept canonical volumes and the integration suite
  failed on the missing tables. **Fixed 2026-11-18:** ordinary (seeded) provisioning applies
  the app's pending steps through `OC\DB\MigrationService::migrate()` once the app is
  enabled and before the seed; preservation mode never migrates outside the upgrade path
  (`TestEnvironmentProvisioningTest`: order, failure and preservation cases).

Routed by the interim Full review of WP-0 and WP-B1 (2026-11-18); every verified defect of
that review was fixed in it, these are the deferred work it found (operator decision: fix
the defects now, route refactors, performance and the recovery tooling to WP-B2).

- [x] **RF-091-02 The recovery fixture still seeds the retired model** (WP-B1, tests):
  `tests/recovery/fixture.php` imports `AgreementService` and `OccurrenceService`, calls the
  occurrence receipt of `CompletionService::complete()` and `ChecklistService::toggleItem()`,
  and `snapshot()` requires rows in `occurrences`, `occ_members`, `agreements`,
  `schedule_revs` and `chk_progress` that the derived model no longer writes, so proofs P1 to
  P3 fail at the seed (P4 seeds on the source build and is green). Rewrite the seed against
  the `cycle` block and require the cycle tables; run P1 to P3 once. Due before the WP-G
  qualification, best in the WP-B2 round. **Fixed 2026-11-18:** the seed completes, ticks,
  postpones and corrects through the derived model and requires `cycles` and
  `cycle_progress`; P1 on PostgreSQL, MySQL and SQLite, P2 and P3 are green.
- [x] **RF-091-03 The recognitions rebuild the open cycles per week** (WP-B1, performance):
  `RecognitionFactsService::factsForWeek()` loads every active room task, its definition,
  the completion anchors, members and assignees on each call, and `MedalService`
  reconciles every week since `cycles_from` on each 5-minute job run (plus three full
  evaluations in `GameController::state`). Load the open facts and absences once per pass
  and filter them per week; take the week key from `CalendarService::weekStart()`.
  **Fixed 2026-11-18:** `RecognitionPass` holds the open inputs and the absences of one
  evaluation; the medal scan, the closed-week reconciliation and `GET /game/state` begin one
  pass each, and `WeeklyGoalService::weekKey()` replaces the evaluation that only named the
  week (`WorkflowQueryBudgetTest`: a further week of a pass costs at most three statements).
- [x] **RF-091-04 Read-path redundancies of the window core** (WP-B1, performance):
  `RoomTaskReadService::cycleFacts()` re-reads the house and the definitions the list
  already holds; the checklist reads run `findByCycle()` twice and re-read task and house;
  a definition edit runs the history aggregate once per mounted room task;
  `findCycleAnchorsByRoomTasks()` aggregates the whole valid history per room task;
  `WindowService::cyclesThrough()` walks from the current cycle for a far range and a
  bounded fixed rule past its end walks its whole sequence; `WorkflowQueryBudgetTest` does
  not pin the list read with its cycle derivation (D-091-09 makes these budgets the trigger
  for a cache). **Fixed 2026-11-18:** the list hands its house zone and definitions to
  `cycleFacts()`; the checklist reads share one cycle read and one progress read; a
  definition edit reads the histories of its room tasks once; `cyclesThrough()` skips to a
  far range and a bounded rule is read from its bound; the list read with its cycles is
  pinned at five statements. The anchor aggregate stays as it is (D-091-58).
- [x] **RF-091-05 Structure of the window core** (WP-B1, architecture): `CycleService`
  (441 non-blank lines) moves its job pass into its own collaborator; the three resolutions
  of the responsible wallets (`CycleService`, `RecognitionFactsService`,
  `CompletionService`) become one; the day-key and weekday helpers of `WindowService` and
  `CycleRule` reuse `CalendarService` and `TaskService`'s weekday list and get unit
  constants of their own instead of `ScheduleRevision::UNIT_*`;
  `CompletionService::cycleBlock()` uses `RoomTaskReadService`; the creation-day rule of
  `cycles_from` moves from `HouseMapper` into the house service. **Fixed 2026-11-18:**
  `CycleAdvanceService` (job pass), `TaskResponsibility` (the one resolution),
  `CycleRule::UNIT_*` with `TaskService::WEEKDAYS` and `CalendarService::dayKeyOf()`, the
  block through `RoomTaskReadService::cycleFacts()`, the creation day in `HouseService`.
  `CycleService` is at 412 non-blank lines with the postponement and the date seam added.
- [x] **RF-091-06 Code without a caller beyond D-091-38** (WP-B1, cleanup; extends D-091-38
  for WP-B7): `RoomTaskMapper::findActiveDueUpToByHouse()` and `findDueWithinDaysByHouse()`,
  `CompletedTaskMapper::findUndoStateByRoomTasks()`, `RoomTaskReadService::rowsForRoomTasks()`
  and `timeZoneForRoom()`, `Occurrence::parsePublicKey()` and `suspensionFacts()`, and the
  readers of the history mappers that lost every production caller (`OccurrenceMapper`,
  `ScheduleRevisionMapper`, `RoomTaskChecklistProgressMapper`, `OccurrenceMemberMapper`,
  `AgreementMapper::hasExplicitAgreementFor()`), each kept only where `RecognitionFactsService`
  or `DigestService` still reads old rows. **Fixed 2026-11-21 (WP-B7):** everything named
  here and in D-091-38 is gone, with the private helpers and constants only they used
  (D-091-113, D-091-114); `rowsForRoomTasks()` and `timeZoneForRoom()` had already gone with
  RF-091-10. `DigestService` reads no stored occurrence any more; the recognitions keep
  their one read.

Found while implementing WP-B7 (2026-11-21).

- [x] **RF-091-11 Two more routes without a client are not named by WP-B7** (scope, operator
  decision): D-090-10 kept `PUT /pauses/{id}` (shortening an absence) and D-090-38
  `GET /house/health` (which recalculates and stores the house health on a read) *until
  Release B reworks both surfaces*; the API reference repeats that. WP-B7 names only the
  cleanliness and the statistics route, and Release B does not rework absences or house
  health. Neither route has a client (`src/services/api.js`, the stores). Recommended:
  retire both in this package's follow-up, with `PauseController::update()`, the shortening
  command of `PauseService` and `HouseController::health()` and their tests, keeping the
  health calculation that the reset and the statistics use. Alternative: keep them and say
  so in the API reference without *until Release B*. **Fixed 2026-11-21:** the operator
  chose to retire both (D-091-123); 92 routes. The statistics did not use the calculation:
  they reported the stored value, which only the retired route refreshed (D-091-124).

Routed by the interim Full review of WP-B7 (2026-11-21); every other finding of that review
was fixed in it.

- [ ] **RF-091-12 The count of the daily summary has no package** (WP-C, behaviour):
  D-091-12 and ADR-022 decision 7 make the summary count the member's suggested work for
  today plus their overdue tasks. `DigestService::countRelevantTasks()` counts the member's
  own open windows planned for today and nothing overdue; WP-B7 took the agreement lines out
  and no package named the rest. It needs the suggestion of WP-C. Due with WP-C: the count,
  the class description, the hint of the task digest in the settings and
  [`notifications.md`](../../engineering/notifications.md).
- [ ] **RF-091-13 The *Work shares* help sits on the capacity page** (WP-C, interface): the
  topic explains how people who did a task together split its coins, and its trigger is in
  the header of `SchedulingView.vue`, which shows availability and load. It fitted
  *Agreements and shares* next to the planner. WP-C rebuilds that page; the topic moves next
  to *Who did the work?* or goes into the glossary.

Routed by the interim Full review of WP-B2 and WP-B3 (2026-11-18); every verified defect of
that review was fixed in it (D-091-59), these are the structure, performance and
accessibility findings it deferred.

- [x] **RF-091-07 Structure of the correction commands** (WP-B2/WP-B3, architecture; due in
  the WP-B4/WP-B5 round, which touches `CompletionService` for the coin factors):
  `CompletionService` grew to 1,546 non-blank lines with the date correction, against the
  §7 mitigation — move `changeDate`/`dateAggregate` into a command collaborator of their
  own; one correction skeleton (target lock, re-read, authorization, version check) and one
  recognition-recompute tail for undo, attribution and date (rule of three); the
  follower's completion write of `CycleService::moveClosedWindow()` belongs to the
  completion side; one classifier (`CompletedTask::classOn()` and `CurrentCycle::classify()`),
  one postponability rule (`WindowService::block()` and `CycleService::postponeUnderLock()`)
  with one stale-target check, one cycle-receipt validator for complete and postpone (their
  `expectedVersion` shapes differ), one rule for *recorded for others* (`ActivityService`,
  `CompletionService::showCompletion()`), a factory for the `c<id>` cycle key, one
  visible-room-task resolver (`ActivityService::getRoomTaskHistory()`,
  `PostponeService::visibleRoomTask()`), the recognition pass of `GET /game/state` in a
  service, the post-commit seam named for corrections (`runForUndo`), and a result object
  instead of the by-reference `$tasksById` of the `RoomTaskReadService` list reads.
  **Fixed 2026-11-18:** `CompletionService` keeps the three ledger aggregates (complete,
  undo, attribution) at 677 non-blank lines, down from 1,546. `CompletionDateCommand` holds
  the date correction; `CompletionAccess::lockTarget()` is the one correction skeleton and
  `CompletionRecognition::recompute()` the one tail; `CompletionLocks`, `CompletionEvidence`,
  `CompletionReadModel` and `ParticipantAllocation` take the member and wallet locks, the
  share and audit writes, the read models and the split. The follower's completion write is
  `CompletionDateCorrection::moveFollower()`, and `CycleService::redatedUnderLock()` only
  projects. One classifier (`CompletedTask::classify()`), one postponability rule
  (`WindowService::postponeRefusal()`) with one stale-target check
  (`CycleService::assertTarget()`), one receipt validator (`CycleReceipt`, also for the
  sleep command), one rule for *recorded for others*
  (`CompletionReadModel::recordedForOthers()`), the key factories `CurrentCycle::keyAfter()`
  and `keyOn()`, one visible-room-task resolver (`RoomTaskAccess`),
  `GameLogicService::recognitionsFor()` for `GET /game/state`,
  `CompletionPostCommitService::runForCorrection()` and the `RoomTaskList` result object.
  `CompletionService` is still above the size guideline with its three aggregates; the
  size inventory keeps it.
- [x] **RF-091-08 Performance and budgets of the WP-B2/WP-B3 paths** (WP-B2/WP-B3,
  performance; due with RF-091-07): request budgets for the history read (10 against 50
  entries), the postpone command, the date correction and the activity read with events at
  two sizes, and the completion budget with sub-tasks (`CycleProgressMapper::checkAll()`
  writes per item); the absence read bounded to groups with a revision in the period; the
  redundant reads of `rowsInState`/`withCycleBlocks` (definitions and zone), of
  `postponeUnderLock` (histories twice), of `PostponeService` (visibility three times), of
  `dateAggregate` (members one by one, house, room task, task), of the history read, of
  `GET /game/state` (the week's facts twice) and of `MedalService` (a second pass, the house
  per week), the picker's progress read for a task without sub-tasks, the second visibility
  and member pass of `ActivityEventService`; an index on the revisions' `(action,
  created_at)` if a budget shows the need (D-091-09).
  **Fixed 2026-11-18:** `CycleCommandQueryBudgetTest` pins the history read at 8 statements
  for 10 and 50 entries, the postpone command at 20, the date correction at 82, the activity
  read with events at 13 for 3 and 30 rows, and one tick write per sub-task of a completion;
  `complete()` fell from 91 to 87 and `undo()` from 83 to 72 (`WorkflowQueryBudgetTest`),
  with the provider overhead pinned apart (`QueryBudgets`). The absence read takes the
  groups with a revision in the period (`PauseMapper::findGroupsRevisedSince()`); the list
  states resolve the zone and the definitions once; the postpone command reads the
  histories once and decides visibility in its guard; the date correction loads the members
  in one read and its target once; `GET /game/state` and the medal scan run one recognition
  pass each and read the house once; the picker reads the ticks only for a task with
  sub-tasks; the activity events share the visibility and member pass of the completions.
  No index is needed (D-091-76). *Corrected by the interim review (D-091-80):* the
  activity budget had been measured without an absence; with as many absences as rows
  it is 14.
- [x] **RF-091-09 Client structure and accessibility of the cycle surfaces** (WP-B2/WP-B3,
  frontend; due with WP-B6, which rebuilds these surfaces in the inline row): a public
  completion-target getter of `useRoomTasksStore` for `useTaskCycleStore`; one refusal
  strategy in `errorToast.js` (`showHistoryError`, `showPostponeError` and the delete path,
  which hides the server's 403 reason); the limit of three presses sent by the server
  instead of copied (`PostponeButton`, `ActivityFeed`); one formatter for the people of an
  entry; the history's rights note from the server flags instead of the role; the reason of
  a disabled postpone reachable without a pointer; focus kept through a date edit in
  `TaskHistory`; the year in history dates outside the current year.
  *Folded in by the interim review of WP-B4 and WP-B5 (D-091-79):* focus that falls to
  the page and no live region when `SleepControl` and `BonusDaysSettings` swap their
  controls; a *Someone else* button of `TodayBonusRow` whose name lacks the task, and
  focus lost when its hold turns into the check; `showServerRefusal` as a fourth
  refusal strategy (with `showHistoryError`); the sleep command of `taskCycle.js` as a
  copy of the postpone skeleton and a second caller of the private completion-target
  read; the people of a bonus row named by another inline formatter.
  *Fixed 2026-11-19 with WP-B6:* `completionTargetOf` is the store's public getter;
  `showRefusal` is the one refusal strategy (completion and postpone toasts build on it);
  `postponeLimit` comes from the server; `entryPeople` and `memberNames` format people,
  `asleepLabel` the asleep state; the history's note follows `canCorrect`; the disabled
  postpone names its reason through `aria-describedby`; focus returns to the edited entry
  and to the control that replaces the pressed one in `SleepControl`, `BonusDaysSettings`
  and `TodayBonusRow`, with a live region for the swapped state; past dates carry their
  year (`formatPastDayKey`); the sleep command runs through the store's one command
  skeleton.

Routed by the interim Full review of WP-B4 and WP-B5 (2026-11-18); every verified
defect of that review was fixed in it (D-091-78), this is the structure and
performance work it deferred (D-091-79).

- [x] **RF-091-10 What RF-091-07 left duplicated, and the reads of the new paths**
  (WP-B4/WP-B5, architecture and performance; due with WP-B6): the stale-target
  check of `SleepService` beside `CycleService::assertTarget()`; the live member and
  the visibility of a resolved room task in `CompletionAccess::authorize()`,
  `CompletionService::complete()` and `CompletionReadModel::show()` beside
  `RoomTaskAccess`, and `CompletionAccess::roomTask()` without the deleted check;
  three rules for `expectedVersion` and three copies of the closed-body loop
  (`CycleReceipt`, `CommandService::requireExpectedVersion()`,
  `CompletionController::validateCorrectionPayload()`, `CoinMultiplierController`);
  the child rule of `getBonusTaskRows()` beside `isChildEligible()`; the "credited on
  a day" predicate twice in `WorkShareMapper`; the real-day parser in `SleepService`,
  `CoinMultiplierService` and `TaskCreationService`; the command skeleton and the
  fresh-answer tail of `SleepService`, `PostponeService` and `CompletionReadModel`;
  the post-commit merge and the member locks of the date command beside the undo, and
  its logger identity; the cycle-key format outside `CurrentCycle` (`p` keys,
  `TestDataService`); the "has a cycle" predicate twice and two bonus tests in
  complete and attribute; `CommandService` naming `CoinMultiplierService` constants;
  `cancelPeriod` orchestrating in the controller and the two period commands
  answering different things (so `startBonusPeriod` reads the house again); the
  allocation projection of `ActivityService` beside `CompletionReadModel`, with two
  placeholders for a departed member (*Former member*, *Removed member*);
  `SleepControl` repeating the asleep label of `taskDisplay.js`. Performance: the
  bonus read derives the cycles of every room task of the house before it keeps the
  bonus ones; `currentFor()` reads the histories of a sleeping or bonus task it does
  not need (twice when a completion wakes a task); the sleep answer reads the house
  zone and the histories again. The sleep and wake rules are the extraction
  candidate of `CycleService` (511 non-blank lines).
  *Fixed 2026-11-19 with WP-B6:* `RoomTaskCommand` is the one skeleton of postpone,
  sleep and the save, with the stale-target check of `CycleService::assertTarget()`;
  `CompletionAccess` is the one resolution of the room task, the live member and
  the visible definition for the completion, its corrections and its read, the
  deleted check included; `CycleReceipt` holds the one `expectedVersion`
  rule and the closed-body loop; `isChildEligible()` and one credited-on-a-day
  predicate serve the bonus rows; `CalendarService::isRealDay()` is the one real-day
  parser; the date command shares the undo's post-commit merge and member locks;
  cycle keys come from `CurrentCycle`; one "has a cycle" predicate; the period
  constants live with their service and `cancelPeriod` in it, and both period
  commands answer the shown period; `CompletionReadModel` owns the allocation
  projection and the placeholder is *Former member* everywhere; `SleepControl` uses
  `asleepLabel`. The bonus read derives cycles only for bonus rows, `currentFor()`
  skips the histories of a task without cycles, and the sleep answer comes from
  `RoomTaskCommand`'s one fresh read. `CycleService` fell to 316 non-blank lines with
  `CycleProjection`, `CycleMisses` and `CycleSleep`.

## Review Outcome

**Standard A, 2026-11-16: `PASS-WITH-FIXES`.** Scope: this plan, its backlog items and its
coordination entries on `docs/release-bc-plan`, base `723a94f6` with the draft uncommitted
(fingerprint `6254c02c`). The plan's claims were checked against the code: routes, services,
the occurrence and checklist-progress schema, the `next_due` readers, the digest queue and the
size inventory. Record: [CODE_REVIEW_2026-11-16](../../backlog/archive/CODE_REVIEW_2026-11-16.md#standard-a---cs-plan-091-simplified-core-releases-b-and-c).

Fifteen findings, all fixed in this plan or its backlog items:

- **High:** D-091-07 put closed cycles into `chorestrike_occurrences`, whose `schedule_rev_id`
  and `ordinal` are not null under a unique key, and sub-task progress is keyed by them →
  D-091-17, D-091-07 rewritten, schema steps and tests extended.
- **High:** WP-B1 stopped materialization while the agreement, due-date and last-done writers,
  preview and apply, and the capacity refusals kept reading stored future rows until WP-B7 and
  WP-C, so the branch would have stayed red in between → they go in WP-B1; WP-B7 and WP-C
  trimmed; every review commit passes the build gate.
- **Medium:** the start-of-day stability of D-091-11 needs stored state → D-091-18.
- **Medium:** `room_task_last_done#update` (FEAT-UX-045) re-anchors through the agreement
  writer and was not covered → D-091-19.
- **Medium:** recording for others on private tasks (ADR-010) was unspecified → D-091-20.
- **Medium:** "a window closes unfulfilled" contradicted D-091-02 → only fixed-day cycles are
  missed; after-completion and one-time cycles stay red until done late.
- **Medium:** the `next_due` projection, which the list queries filter by, was kept only by the
  job → every window-changing command updates it in its own transaction.
- **Medium:** partly ticked sub-tasks (D-089-02) had no home without occurrence rows → progress
  keyed by the cycle.
- **Medium:** a date correction can invalidate closed cycles after the corrected entry → it
  re-derives them.
- **Low:** the first window of existing room tasks and existing voluntary definitions at the
  cutover; ADR-016 names agreement receipts (status note added; the pause-request lines went
  with ADR-020); the interim review tier now follows the review skill; a risk row for the
  oversized aggregates; the file manifest completed; FEAT-UX-050, FEAT-UX-059 and RELEASE-002
  carried stale text.

Decisions: D-091-17 to D-091-20 (operator, the recommended options). Positions D-091-07 to
D-091-16 bind as amended. Gates for this docs-only change: `backlog:check` (including its link
check of the scoped files), `docs:encoding`, `git diff --check` and LF endings. Status set to
active, the owning items to *In Progress*, and the plan moved `new/ → active/`.

**Interim Full review of WP-0 and WP-B1, 2026-11-18: `PASS-WITH-FIXES`.** Scope: the whole
uncommitted package on `feat/simplified-core-bc` (base `2b9efaf2`, 253 paths, fingerprint
`67c30a11…a991e`), four reviewer tracks plus the main agent. Record:
[CODE_REVIEW_2026-11-18](../../backlog/archive/CODE_REVIEW_2026-11-18.md#full-interim---cs-plan-091-wp-0-and-wp-b1-derived-due-windows).

Fixed in the review: a lock-out after two early completions before a future first date
(block); update-day work counted as missed (D-091-41); fixed misses lost behind a later
completion or an undo, and misses before a room task's creation (D-091-43); rule edits that
broke parity and the monthly day (D-091-44); unlimited bonus completions (D-091-42); the
deleted pause races (restored as `PauseConcurrencyTest`); erasure of the tick authors and of
swept wallets in closed cycles; `firstDate` validation (D-091-45); active bounds west of UTC;
restart successors at the cutover; `cycle: null` responses; the refusal toasts; the missing
index and the undo's table scan; stale API, ADR and guide passages. Routed: RF-091-02 to
RF-091-06, RF-080-18, BUG-SEC-003, CR-SVC-66 (folded in). Decisions D-091-41 to D-091-45
(operator, the recommended options); D-091-24 to D-091-40 accepted.

Gates: Jest 177 suites / 2438 tests, ESLint, stylelint; PHPUnit unit 1616 and integration 629 on
PostgreSQL (canonical), MariaDB, SQLite and the AIO row (matrix `r91irv`); proof P4 on all four;
focused browser specs (completion, Insights, Today, rhythm) 38 passed; l10n, php-cs-fixer,
adapter, docs and backlog checks. Interim review: the plan stays in `active/`.

**Interim Full review of WP-B2 and WP-B3, 2026-11-18: `PASS-WITH-FIXES`.** Scope: the whole
uncommitted package on `feat/simplified-core-bc` (base `335cb162`, 121 paths, fingerprint
`30fa2b68…505ef3`), four reviewer tracks plus the main agent. Record:
[CODE_REVIEW_2026-11-18](../../backlog/archive/CODE_REVIEW_2026-11-18.md#full-interim---cs-plan-091-wp-b2-and-wp-b3-recording-postpone-and-history).

Fixed in the review (D-091-59): absence requests that were never acknowledged leaked into the
feed as removals; an undo lost the postponement of the cycle it reopened (D-091-62); the date
correction's replay right (D-091-63) and its translated refusals; visibility per room task in
the feed and its events (ADR-010); the history of a deleted task; a bounded task ended for
good by a date correction; the member filter after the limit; the absent person missing from
an absence line; a stale history after a completion or undo; an empty picker before the
members load; new command ids on an uncertain retry; the untested owner attribution of
D-091-56; a departed recorder's warning; logging, docs, l10n and a flaky fixture. Decisions
D-091-60 (an undo keeps the ticks) and D-091-61 (a date correction keeps takeover facts),
operator, the recommended options; D-091-46 to D-091-58 accepted. Routed: RF-091-07 to
RF-091-09.

Gates: Jest 181 suites / 2503 tests, ESLint, stylelint, build; PHPUnit unit 1712 and
integration 653 on the canonical stack; php-cs-fixer, l10n (1256 keys), adapter, docs and
backlog checks; focused browser specs 48 passed on `chromium-serial-db` and the phone set 19 passed on
`mobile-chromium` and `mobile-webkit`. No schema step: matrix and P4 not repeated. Interim review:
the plan stays in `active/`.

**Interim Full review of WP-B4 and WP-B5, 2026-11-18: `PASS-WITH-FIXES`.** Scope: the whole
uncommitted package on `feat/simplified-core-bc` (base `d3b91359`, 147 paths, fingerprint
`2063ebb6…ac48c0`), four reviewer tracks plus the main agent. Record:
[CODE_REVIEW_2026-11-18](../../backlog/archive/CODE_REVIEW_2026-11-18.md#full-interim---cs-plan-091-wp-b4-and-wp-b5-sleeping-bonus-tasks-and-coin-multipliers).

Fixed in the review (D-091-78): a required browser budget the bonus read had turned red;
the erasure of an account that had left the house kept its wallet in the frozen coin
factors; a bonus task turned regular recorded a miss for its bonus days (D-091-81); a
postponement survived sleep and wake (D-091-82); a sleeping task whose bounded rule ended
could not be completed and said *stale* (D-091-83); a bonus completion moved the version
and refused the next member (D-091-84); stale bonus rows after a correction; the retry of
bonus days; an untested unknown member of a frozen map; the renamed log key of ADR-011;
the stale size inventory, service sections, source tree and store boundaries of the
architecture document. Budgets added for the new paths, the activity budget re-measured
with absences (D-091-80). D-091-64 to D-091-77 accepted, D-091-75 reworded. Routed:
RF-091-10, a fold-in to RF-091-09.

Gates: Jest 184 suites / 2567 tests, ESLint, stylelint, build; PHPUnit unit 1817 and
integration 690 on the canonical stack, no skips; php-cs-fixer, l10n (1303 keys), adapter,
docs and backlog checks; focused browser specs 6 passed on `chromium` and 20 on
`chromium-serial-db`, the phone set 19 passed on `mobile-chromium` and `mobile-webkit`.
`Version1037` unchanged: matrix and P4 of the implementation round stand. Interim review: the
plan stays in `active/`.

**Interim Full review of WP-B6, 2026-11-21: `PASS-WITH-FIXES`.** Scope: the whole
uncommitted package on `feat/simplified-core-bc` (base `1c962cf8`, 227 paths, fingerprint
`21d12189…45ec9a`), four reviewer tracks plus the main agent. Record:
[CODE_REVIEW_2026-11-21](../../backlog/archive/CODE_REVIEW_2026-11-21.md#full-interim---cs-plan-091-wp-b6-one-form-and-one-row).

Fixed in the review: after a save the lists were never read again (two refresh calls, the
second one met the guard of the first); a second click after a stale refusal could
overwrite someone else's change; a sleeping task on its wake day and a never-completed task
with an old anchor could not be saved; a wake lost the first date of the same save; a
deleted category made a members-only save of a shared task ask for the scope; the ticks of
a split row showed unticked; a bounded rule lost its mode; a created task stored an empty
text as `''`. The operator decided four authorization and validation questions (D-091-108
to D-091-111); the contract changes are D-091-112. Also: the save is audited again, one
start, one sleep switch, one member check and one cycle-block read for the room-task
commands, fewer reads and requests per save and per opened row, focus kept through the
form, the history, the template fill, the sub-task editor and a dialog whose opener is
gone, dead code of the removals, German wording, the stale parts of the architecture,
development and logging documents. Tests added for the replay guard, the gates of the
decisions, combined saves and the refresh after a save. D-091-89 to D-091-107 accepted,
D-091-92, D-091-93, D-091-95 and D-091-100 amended. Nothing routed; RF-091-06 stays open
for WP-B7.

Gates: Jest 186 suites / 2657 tests, ESLint, stylelint, build; PHPUnit unit 1872 and
integration 707 on the canonical stack and on PostgreSQL, MariaDB, SQLite and the AIO row
(matrix `r91b6b`), no skips; php-cs-fixer, l10n (1286 keys), adapter, docs and backlog
checks; focused browser specs 35 passed on `chromium` (the request budgets among them), 93
on `chromium-serial-db` and the phone set 11 each on `mobile-chromium` and
`mobile-webkit`; the preservation proofs passed on the final tree: P1 on PostgreSQL, MariaDB and SQLite, P2, P3 and P4 on PostgreSQL, each with a clean teardown. No schema step. Interim review: the plan stays in `active/`.

**Interim Full review of WP-B7, 2026-11-21: `PASS-WITH-FIXES`.** Scope: the uncommitted WP-B7 package on
`feat/simplified-core-bc` (base HEAD `f1d9d606`, 66 paths, fingerprint
`03727d98…599f77`), four reviewer tracks plus the main agent. Record:
[CODE_REVIEW_2026-11-21](../../backlog/archive/CODE_REVIEW_2026-11-21.md#full-interim---cs-plan-091-wp-b7-retiring-the-rest-of-the-old-surface).

Fixed in the review: the help called an overdue task red until it is done, also on a fixed
day; the user guide still let owners *plan and apply the week*; the two notification
documents still described agreed catch-up and agreement recordings; the fixed rules were
compared with the lookup's own output; the real-database check of a room-scoped list went
with the statistics route; the retained entities kept serialization nobody calls
(D-091-120); the hints of the organization digest and of the energy field, the absence and
streak texts and the German window term (D-091-121). Also stale comments and documents,
dead test helpers, one body for the retired-route guards. No question needed the operator.
D-091-113 to D-091-119 accepted, D-091-120 to D-091-122 decided. Routed: RF-091-12 and
RF-091-13 to WP-C; RF-091-11 stays open for the operator; CR-SVC-67, BUG-UX-017 and
TECH-DEV-002 went to the backlog while implementing.

Gates: Jest 186 suites / 2658 tests, ESLint, stylelint, build; PHPUnit unit 1846 and
integration 698 on the canonical stack and on PostgreSQL, MariaDB, SQLite and the AIO row
(matrix `r91b7c`), no skips; php-cs-fixer, l10n (1283 keys), adapter, docs and backlog
checks; focused browser specs 126 passed on `chromium-serial-db`, 23 on `chromium`, 30 on
`chromium-scheduling-owner` and the phone set 11 each on `mobile-chromium` and
`mobile-webkit`; the preservation proofs passed on the final tree: P1 on PostgreSQL,
MariaDB and SQLite, P2, P3 and P4 on PostgreSQL, each with a clean teardown. No schema
step. Interim review: the plan stays in `active/`.

**Interim Full review of the WP-B7 follow-up (RF-091-11), 2026-11-21: `PASS-WITH-FIXES`.**
Scope: the uncommitted follow-up on `feat/simplified-core-bc` (base HEAD `b35ab2fd`, 28
paths, fingerprint `839c05c4…7d2de6a2`), four reviewer tracks plus the main agent. Record:
[CODE_REVIEW_2026-11-21](../../backlog/archive/CODE_REVIEW_2026-11-21.md#full-interim---cs-plan-091-wp-b7-follow-up-the-two-other-routes-without-a-client).

The operator chose to retire both routes (D-091-123): `PUT /pauses/{id}` with the shortening
command and `GET /house/health`; 92 routes. Found with it and fixed: the statistics reported
a stored house health that nothing refreshed since Release A, and now compute it
(D-091-124). Fixed in the review: the projection test stores the shortening as the command
left it; the lost-authority cases gained the deletion of a household absence; ADR-020, the
API reference and the architecture no longer let pending shortenings govern or call the
stored health a reset value; the release test plan and the UI use cases dropped the
shortening and the health route; a retired route answers 404, or 405 where another verb
keeps its path. D-091-124 and D-091-125 accepted. Nothing routed; RF-091-11 is closed,
RF-091-12 and RF-091-13 stay open for WP-C.

Gates: Jest 186 suites / 2658 tests, ESLint; PHPUnit unit 1837 and integration 693 on the
canonical stack and on PostgreSQL, MariaDB, SQLite and the AIO row (matrix `r91b7e`), no
skips; php-cs-fixer, l10n (1280 keys), adapter, docs and backlog checks; focused browser
specs 85 passed on `chromium-serial-db`, 10 on `chromium` and the phone set 11 each on
`mobile-chromium` and `mobile-webkit`; the preservation proofs passed on the final tree: P1
on PostgreSQL, MariaDB and SQLite, P2, P3 and P4 on PostgreSQL, each with a clean teardown.
No schema step. Interim review: the plan stays in `active/`.
