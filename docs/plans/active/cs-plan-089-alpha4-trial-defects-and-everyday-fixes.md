# CS-PLAN-089: 0.8.0-alpha.4 — Trial Defects and Everyday Fixes (Round 1)

> **Status:** Active - Standard A `PASS-WITH-FIXES` 2026-11-04; drafted 2026-11-04; implementation started 2026-11-04 on `fix/alpha4-round1`
> **Effort:** M — about 8–10 engineering days, plus the operator's GUI review and the release steps, each with its own approval
> **Priority:** P2
> **Depends on:** `0.8.0-alpha.3` deployed on the retained baseline (done 2026-10-29); the operator's sanitized trial report of 2026-11-03; no schema change intended
> **Related backlog items:** WP-0 TECH-RUNTIME-003 · WP-A BUG-UX-008, BUG-UX-009, BUG-UX-010, BUG-UX-011, BUG-UX-013, TECH-LOG-001 · WP-B FEAT-UX-046 · WP-C FEAT-UX-047 · WP-D FEAT-UX-048 · WP-E FEAT-UX-049, BUG-UX-012 · WP-F RELEASE-002 (alpha.4 slice) · **not in scope:** Round 2, Round 3 and later items of the [register](#feedback-register-sanitized); TECH-TEST-005 and CONCEPT-001 (owned by [CS-PLAN-080](cs-plan-080-wp9-cross-db-and-family-evidence.md))

## 1. Problem Summary

`0.8.0-alpha.3` has run on the family instance since 2026-10-29. The operator's
sanitized trial report of 2026-11-03 and the
[deployment report](../../engineering/deployment.md#update-to-080-alpha3-2026-10-29)
bring 34 items: five defects and 29 change requests. They are requests, not
specifications: the operator leaves scope, order, data model and design to the
source project. Every claim below was checked against the code at `65f9725`.

All five defects are real. None is a P0/P1 under the
[release strategy](../../release/release-strategy.md), so no hotfix is needed.
Two are larger than reported:

- **D-2 (frame):** [`templates/main.php`](../../../templates/main.php) renders a
  second `#content` inside the one from Nextcloud's `layout.user.php`. The core
  rule for `#content` (`position: fixed`, its own margins, width and height) then
  applies twice, and `NcContent`, itself `position: fixed` without a margin, sits at
  the resulting offset. At 1280×800 the frame starts at x=16, y=88 and reaches the
  right window edge, as the tracked
  [settings screenshot](../../screenshots/guide-settings.png) shows; by the same
  rules it ends at y=836. Beyond the reported margins, 44 px of height stay unused
  under the header, and the last 36 px of every scroll area never come into view (44
  px on a phone). The browser confirmed this: right margin 0 against 16 on the left on
  the desktop, and the frame ending 44 px below the window on a phone.
- **D-4 (HTTP 500):** `RoomTaskController::updateDueDate()` does not catch
  `ConflictException`. That turns every refusal of the date writer into a 500: the
  passed amendment deadline, the child day limit and a stale target.
  `setAssignees()` has the same gap. The two questions the
  report left open can be answered from the code. The reported dialog saved only
  partly, because `RoomTaskEditModal.save()` runs "Last done on" and the due-date
  change as two transactions. It must have been a fixed-cadence task: an overdue
  interval is refused in the first step, and the due-date call would never have
  followed. The date writer and the dialog both use the oldest open occurrence, here
  the missed one, so ADR-013 correctly refuses; only the status, the message and the
  partial save are wrong. The same trace shows a contract gap: D-088-20, the API
  reference and the service docblock say "Last done" is refused while the appointment
  is overdue, but the code checks that only for an interval.

A sixth defect arrived on 2026-11-04, after the report: tasks created the day before
did not appear on a child's My Day. **D-6** reproduces with a daily limit.
`childDailyRows()` cut the due rows at the limit in the order `nextDue, createdAt, id`, so
the newest tasks fell off first, including tasks the child owes, and an unassigned row that
sorted earlier could take their place. The creation path accepts a task for a child on a
full day, while a later assignment is refused, and the overload banner counts visible rows
only, so nothing showed. This contradicts contract C2 ("silently hiding excess obligations
is not recovery"). Without a limit the task appears in all eleven tested variants: assigned
at creation or later, shared, fixed, flexible, one-time and private. The operator confirmed
that the reported child has a limit, and older open tasks had filled it. The owner can raise
a limit at once, so this is a P2 without a hotfix.

Many change requests touch a recorded contract (ADR-013, ADR-014, ADR-015,
ADR-019) or need the first schema step on the retained baseline, so they cannot
ship with the defects. Separately, the weekly runtime check has failed since
2026-11-02: AIO v14.4.3 and a moved `php:8.4.27-apache` digest are not in the
inventory, and the project rules forbid deploying before that is promoted.

## 2. Strategy

The operator chose three rounds (D-089-01). This plan owns **Round 1**: what needs
neither a schema step nor a changed core contract. Later rounds get their own
plans from the backlog.

| Round | Content | Migration |
|-------|---------|-----------|
| 1: `0.8.0-alpha.4` (this plan) | D-1..D-6, the log `app` key, runtime promotion, T-1, T-2, G-7, C-3, removal of Quick clean, S-7, S-8, S-10 | none |
| 2: task flow | G-1..G-6 (mockups first), T-3, T-5, T-6, C-1, C-2, S-1..S-5; starts after the family trial closes (D-089-06) | none expected |
| 3: appearance and evaluation | S-9, S-6, L-1, statistics | first step on the retained baseline |
| Later | T-7 with the scheduler discussion, ranking over chosen periods, I-1 | open |

- **WP-0 Runtime:** promoted first, because every later gate runs on it.
- **WP-A Defects:** one commit per item, each with a regression test. D-3 starts
  with a reproduction, because its cause is only probable so far.
- **WP-B Energy, WP-C Task row, WP-D Removals, WP-E Floors & Rooms and wizard:**
  the everyday improvements that do not need the task-flow redesign.
- **WP-F Release:** freeze, version bump, changelog, review, qualification, export,
  operator deployment, verified record import. Plan approval is not authorization
  to branch, publish, tag, deploy or import: each operation needs its own explicit
  approval.
- **Hotfix rule:** a P0/P1 finding does not wait for this round; it follows the
  rule of CS-PLAN-088 (own `fix/` branch, requalification, private exchange).
- **No schema or migration change.** The 26 applied migrations stay pinned. The
  `estimated_minutes` column stays, so no persisted definition fact is lost.

### Decisions recorded (operator 2026-11-04)

- **D-089-00 Drafting:** the record import and this draft use branch
  `docs/alpha3-closure-alpha4-plan` in its own pinned worktree
  (`~/worktrees/chorestrike-alpha3-closure`). Implementation gets its own branch.
- **D-089-01 Rounds:** the three rounds above. They keep the first schema step and
  the contract changes out of the defect round.
- **D-089-02 Partly ticked sub-tasks (C-2, Round 2):** the task stays open and the
  ticks stay saved; holding counts everything as done.
- **D-089-03 Rights (T-6, G-5, Round 2):** assignees and adults may postpone without
  the consent tick, because the change stays inside the agreed window. Handover
  stays owner-only with the consent tick, as a button with a small dialog.
- **D-089-04 Categories (T-3, Round 2):** categories serve a filter in the Tasks list
  and an evaluation in the statistics. Today they appear only in the form.
- **D-089-05 Arcade reset (S-6, Round 3):** highscores stay when a person's unlock
  progress is reset.
- **D-089-06 Family trial:** it closes before Round 2 starts, because Round 2 changes
  the flows it evaluates. Round 1 may ship while it runs (recorded in
  [CS-PLAN-080](cs-plan-080-wp9-cross-db-and-family-evidence.md#deployment-080-alpha3-2026-10-29)).

- **D-089-10 Sixth defect (operator 2026-11-04):** D-6 joins Round 1 and comes first. The
  child limit caps only what My Day offers: own tasks always stay visible, and unassigned
  rows fill the slots that remain.
- **D-089-11 Overbooked days (operator 2026-11-04):** the operator confirmed that the reported
  child has a daily limit. Creating a task stays possible on a full day: refusals only
  irritate, and a day may be overbooked in principle.
- **D-089-12 Next appointments (operator 2026-11-04):** the list on My Day follows the filter,
  so *My tasks* no longer shows other people's appointments.

### Decisions left to the source project

The report leaves these open. The operator accepted them with Standard A on
2026-11-04.

- **D-089-07 Energy range (T-1):** 1–20. The form offers 1–5 from a list plus a
  custom value. Both variants of the report raise the limit, so they differ only in
  the UI. 20 is also the upper bound of a daily energy budget (`SchedulingService`).
  With 1 energy ≈ 10 minutes the limit is 3 h 20 min; larger work should be split.
- **D-089-08 "Last done" while overdue:** the refusal applies to every cadence, as
  D-088-20 and the API reference already state. Today's early return for a fixed
  cadence is the defect. The check looks at every open occurrence, as the interval
  check does, so it also covers an older miss next to a current window. The report
  cannot tell those two cases apart, and this closes the partial save for both. The
  reason text has to fit both.
- **D-089-09 Orphaned endpoints:** `PUT /rooms/{id}/cleanliness` and the
  `occurrence#catchUp` route lose their client in Round 1 but stay until Round 2 reworks
  that surface (FEAT-UX-050). Removing them touches `RoomService`, `AgreementService` and
  their suites, which would widen a round of small changes. Retained `manual_delta` and
  catch-up facts keep replaying and reading either way.

### Answers to the operator's four questions (2026-11-03)

Positions of the source project. The operator raised no objection on 2026-11-04;
they bind only once the owning round's plan passes its review, and they are recorded
in the owning backlog items.

- **T-7 separate scheduled date:** no second date on the task; the occurrence already
  separates obligation (window) and plan (`planned_date`). FEAT-UX-059.
- **G-6 drop the details view:** yes, once *Restart the linked interval work on* has a
  home. FEAT-UX-050.
- **C-1 show who recorded an entry:** yes, when the recorder differs from the people
  credited; the data exists. FEAT-UX-051, FEAT-UX-052.
- **S-2 collapsible sections:** yes, with two levels instead of three. FEAT-UX-054.

## 3. Backend / Frontend Changes

### WP-0 Runtime promotion (TECH-RUNTIME-003)

`npm run test:runtime:check` exits 2 with `aio-bundle` and `moved-php-image`:
AIO v14.4.3 with unchanged Nextcloud 34.0.5 and components, and a new digest behind
`php:8.4.27-apache`. Same procedure as RF-088-11: reviewed update of
`docker/test-runtime.json`, the image and compose pins, the spec hashes and the
current-runtime docs; rebuild; requalify both rows. The check runs again before the
freeze, and any newer drift is promoted the same way.

Delivered 2026-11-04: `test:runtime:check` reports `current`. Only the multi-arch index of
`php:8.4.27-apache` moved; its `linux/amd64` digest is unchanged, so the tested runtime stays
identical. The canonical stack was rebuilt, PHPUnit is green on it, and the AIO row and the
browser gates follow with the candidate.

### WP-A Defects

- **BUG-UX-008 (D-1):** `frequencyLabel()` uses the existing `Every {n} days` for
  n > 1 instead of ignoring `frequencyValue` in the `daily` branch.
- **BUG-UX-009 (D-2):** `templates/main.php` keeps only the mount element
  `#app-chorestrike-vue`. `NcContent` positions itself, so the mount element needs no
  sizing; by the core rules the frame then spans x=8..1272 and y=44..792 at 1280×800,
  which is 8 px on every side. The app keeps its own mount element because core's
  `#content` also holds the hidden page heading, which a mount on `#content` would
  replace. Every view moves up by 44 px, and the removed `#app-content` wrapper also
  carried a background and a z-index. Delivered on the primary row: equal margins on both
  viewports, reachable end of a long view, screenshots reviewed by eye; the navigation now
  shows the usual translucent Nextcloud background. The AIO row follows with the candidate.
- **BUG-UX-010 (D-3):** reproduced in the browser: three selects in Settings had 11.5 px
  for a 17.1 px line, because core fixes the height of a `<select>` and `.text-input` adds
  12 px block padding. The select in the task dialog was short too, and dialogs are
  teleported out of the app content. So one rule on the body lets every select take its
  natural height, instead of a fix per component. Eight components also lost core's
  dropdown arrow to a `background` shorthand; they set `background-color` now.
- **BUG-UX-011 (D-4):**
  - `RoomTaskController`: `updateDueDate()` and `setAssignees()` catch `ConflictException`
    through `ControllerErrorFormatter::conflictResponse()`. The audit of the other mutations
    corrected the plan and added one: `bulkAssign()` has no gap, because its service counts a
    refused item as skipped, while `TaskController::update()` can reach the same 500.
  - `RoomTaskLastDoneService`: the overdue refusal runs for every cadence (D-089-08).
  - `RoomTaskEditModal.vue`: due-date and assignee refusals appear inline, as the
    last-done refusal already does. When a later step is refused after "Last done" was
    saved, the dialog stays open, says what was saved and clears the field so a retry
    does not record it again.
  - `TaskList.vue`: the assignee chips rolled back silently; they now show the reason at the
    chips, as BUG-UX-002 established for refusals.
  - Both refusals of a missed appointment share one reason a person can act on: "This task
    has a missed appointment. Complete it first."
  - Known limit until FEAT-UX-050: while an older miss stands, the dialog cannot move a
    later occurrence, because it edits the oldest open one. The refusal says so; the
    explicit-target path of FEAT-UX-050 ends the limit. Atomic saving of the whole dialog
    comes with the one form in FEAT-UX-040.
- **BUG-UX-013 (D-6):** `childDailyRows()` no longer cuts own tasks. An own task is the agreed
  obligation when the row has one, otherwise the assignment. Own tasks all stay, and unassigned
  rows fill the slots that remain in canonical order. Creating a task on a full day stays
  possible (D-089-11).
- **TECH-LOG-001 (D-5):** `initializeScheduling()` fetches the member availability only
  for owners. The role is known by then, because `App.vue` renders the router view only
  after the membership resolved, and the section that uses the data is already
  owner-gated. `AppLogger` adds `app => chorestrike` to its context, so entries stop
  showing `no app in context`.

### WP-B Energy as the only effort measure (FEAT-UX-046: T-1, T-2)

- **Scale:** 1 energy ≈ 10 minutes. The catalog already fits it, with medians of about
  10, 15, 25 and 45 minutes for energy 1–4 and no template at 5. Existing tasks and
  templates keep their values, so only
  [`energy-and-balancing.md`](../../concepts/energy-and-balancing.md) (which says 5
  minutes) and the user-facing help change.
- **Limit:** 1–20 through one constant in `TaskService` (D-089-07). The unused
  `energy_max_per_task` key in `RewardConfigService` (default 9, enforced nowhere) goes,
  so no second limit remains. No frontend reads it.
- **Form:** `TaskEditForm.vue` offers 1–5 as bolts with their approximate time, plus a
  custom value of 6–20, shown as a bolt with a number. `data-testid="task-energy-select"`
  stays. A hint explains that a task above a person's daily budget (default 10 on
  weekdays, 15 at weekends) cannot be placed for that person by the planner.
  `TaskTemplatePicker.vue` follows.
- **Estimated time:** gone from `TaskEditForm`, `TaskTemplatePicker` and `TaskListItem`.
  It feeds no calculation. The column and the API field stay.
- **Coins:** stay linear at 10 per energy. Saved occurrences keep their frozen energy, so
  a changed definition applies from its next revision (ADR-013, ADR-014).

### WP-C One task row (FEAT-UX-047: G-7)

- One presentational meta component for `TaskListItem` (Tasks, rooms) and the My Day row
  groups in `TodayView`: energy in colour through a new token, recurrence with its flex
  days in one label (`Every 3 days ±2`), floor and room below the title. My Day does not
  load floors yet, so it calls `fetchFloorsWithRooms()` once per view, never per row.
- No clock (WP-B) and no separate flex display: `FlexWindowDisplay.vue` loses its only
  consumer and goes with its spec, its `flexWindow` helper and its exemption in
  TECH-LINT-009.
- The *Teamwork bonus* label goes. ADR-014 retired multipliers, and shared work splits the
  reward, so the label promises something that does not exist.

### WP-D Removals (FEAT-UX-048: C-3, Quick clean of T-6)

- **Catch-up action:** the form *Agree a catch-up appointment on* / *Record catch-up* goes
  from `TaskListDetails.vue`. The catch-up status stays: a missed chore keeps its original
  date and can still be completed, and already agreed appointments keep showing. Refusal
  texts, help and docs stop offering the action.
- **Quick clean:** the control and `updateRoomCleanliness` go from `RoomCard.vue` and the
  rooms store. Retained `manual_delta` journal entries keep replaying. The hold-button
  coverage in `family-core-ux.spec.ts` moves to the *Done* control.
- The two routes stay for now (D-089-09).

### WP-E Floors & Rooms and the setup wizard (FEAT-UX-049: S-7, S-8; BUG-UX-012: S-10)

- **Rename:** each room in the Settings list gets *Rename room*, reusing the dialog of
  `RoomDetail.vue`.
- **Task count:** per room, from `fetchHouseRoomTasks()` in the owner-only section; no new
  API field, so non-owners learn nothing new.
- **Expand control:** one small component, a right chevron turning 90° when open, in
  Settings, `FloorList`, `TaskFilterBar` and `TaskListItem`; it respects reduced motion.
  Reorder controls use up and down arrows, so the two no longer look alike.
- **Wizard:** today it creates "Ground Floor, 2. Floor, 3. Floor", which is wrong under
  either convention. Numbered floors become the default, with a choice whether the first
  floor above ground counts as 1st or 2nd, through translatable source strings. It affects
  new setups only; existing floors stay and can be renamed.

### WP-F Release `0.8.0-alpha.4` (RELEASE-002)

App metadata in `appinfo/info.xml` and `package.json`; `CHANGELOG.md` section, dated after
the verified deployment; README badge, caption and status; screenshots recaptured, because
D-2 moves every view; final review; the
[qualification pipeline](../../standards/ai/skills/release/SKILL.md#qualification-pipeline);
export of one selected smoke run; operator deployment; verified record under
[*Updates on the Retained Baseline*](../../engineering/deployment.md#updates-on-the-retained-baseline).

## 4. Tests

- **WP-0:** `npm run test:runtime:check` reports `current`; `testRuntime.spec.js`,
  `testRuntimeStage.spec.js` and `testTarget.spec.js`; PHPUnit and browser gates on both rows.
- **BUG-UX-008:** `taskDisplay.spec.js` for n = 1, n > 1 and the other frequencies.
- **BUG-UX-009:** `layout-clearance.spec.ts` asserts on both viewports that the frame's right
  and bottom margins equal the left one (0 on a phone), that the top starts at the header
  height, and that the last element of a long view scrolls into view. The specs that select
  `#app-content-vue`, `tests/package/owner-smoke.cjs` and the contrast checks that select
  `#content` (`color-contrast.spec.ts`, `family-core-ux.spec.ts`,
  `fixtures/non-text-contrast.ts`) stay green; `#content` then matches core's element alone.
- **BUG-UX-010:** a browser assertion in `settings.spec.ts` that every select in Settings and
  in the task dialog fits one text line; the measurement does not depend on the theme.
- **BUG-UX-011:** `RoomTaskControllerTest` for 409, stable code and no error-level log on all
  actions, for `TaskController::update()` too; `RoomTaskLastDoneIntegrationTest` for a refused fixed cadence, both with a miss
  alone and with a miss next to a current window, without any write;
  `RoomTaskEditModal.spec.js` for inline refusals, the message after a partial save and no
  repeated last-done call on retry; the `TaskList` and `TaskListDetails` specs for the reason at
  the chips.
- **BUG-UX-013:** `RoomTaskServiceDailyTest` for own tasks above the limit and free slots at four
  limits; `ChildDailyLimitTest` on the real creation path, where a later assignment stays refused.
- **TECH-LOG-001:** a scheduling store spec for owner and non-owner; `AppLoggerTest` for the
  `app` key next to the sanitized context, for `audit()` too.
- **FEAT-UX-046:** `TaskServiceTest` and `TaskCreationServiceTest` for 0, 1, 20 and 21 on
  create and update, with the changed range message; `RewardConfigServiceTest` without the
  removed key; `TaskEditForm.spec.js`, `TaskTemplatePicker.spec.js`, `TaskListItem.spec.js`; the
  four browser specs that use `task-energy-select`; an integration case with an energy above 5
  through completion, work shares and Coins. The template-level assertion in
  `core-household-journey.spec.ts` stays valid, because the API field stays.
- **FEAT-UX-047:** a spec for the meta component; `TaskListItem.spec.js`, `TodayView` specs,
  `theme-consistency.spec.js`; a contrast check of the energy colour in both themes; one
  browser scenario for row content.
- **FEAT-UX-048:** `TaskListDetails.spec.js`, `RoomCard.spec.js`, `family-core-ux.spec.ts` and
  its entry in `tests/e2e/required-scenarios.json`, whose scenario name mentions Quick clean; the
  existing catch-up card scenarios stay green unchanged.
- **FEAT-UX-049, BUG-UX-012:** a spec for the expand control; Settings specs for rename and
  count; `settings.spec.ts`; `SetupWizard.spec.js` for both numbering choices.
- **Gates:** Jest, ESLint, stylelint, `composer cs:check`, `npm run l10n:check-keys`,
  `backlog:check`, `docs:encoding`, `agents:check`; PHPUnit unit and integration on the
  canonical stack, SQLite, MySQL and the AIO row; browser gates on both rows; package build and
  smoke.

## 5. File Manifest

```text
modified: docker/test-runtime.json, docker/Dockerfile.test, docker/compose*.yml (WP-0)
modified: scripts/test-runtime*.js as needed, tests/unit/scripts/testRuntime*.spec.js,
          tests/unit/scripts/testTarget.spec.js
modified: templates/main.php
modified: src/utils/taskDisplay.js
modified: src/views/Settings.vue, src/views/TodayView.vue, src/views/RoomDetail.vue,
          src/views/TaskManager.vue
modified: src/components/Rewards/RewardCatalogEditor.vue, RewardRequestDetails.vue,
          RewardRequestList.vue, src/components/Members/MemberList.vue (select audit)
new:      tests/integration/Service/ChildDailyLimitTest.php
modified: lib/Service/RoomTaskReadService.php, tests/unit/Service/RoomTaskServiceDailyTest.php
modified: lib/Controller/RoomTaskController.php, lib/Service/RoomTaskLastDoneService.php,
          lib/Service/AppLogger.php, lib/Service/TaskService.php,
          lib/Service/RewardConfigService.php, lib/Service/OccurrenceAgreementService.php,
          lib/Service/AgreementService.php
modified: src/components/Tasks/RoomTaskEditModal.vue, TaskEditForm.vue, TaskTemplatePicker.vue,
          TaskList.vue, TaskListItem.vue, TaskListDetails.vue, TaskFilterBar.vue
modified: src/components/Dashboard/RoomCard.vue, FloorList.vue
modified: src/components/Setup/SetupWizard.vue, src/components/Help/HelpGlossary.vue,
          src/components/Help/HelpEntry.vue
modified: src/store/scheduling.js, src/store/rooms.js, src/styles/tokens.css, eslint.config.js
modified: l10n/de.json, l10n/en.json and the l10n templates
new:      a task meta component and an expand control under src/components/ with specs
new:      a shared room rename dialog, if RoomDetail's cannot be reused in place
removed:  src/components/Tasks/FlexWindowDisplay.vue, tests/unit/components/FlexWindowDisplay.spec.js
modified: tests named in section 4
modified: docs/concepts/energy-and-balancing.md, gamification-and-rewards.md, design.md,
          calm-core-ux.md, ui-use-cases.md, task-model-and-sync.md, docs/api/README.md,
          docs/user-guide.md, docs/testing/release-test-plan.md,
          docs/engineering/deployment.md, docs/standards/guidelines/logging.md,
          docs/standards/guidelines/design.md, docs/standards/guidelines/testing.md
modified: appinfo/info.xml, package.json, package-lock.json, CHANGELOG.md, README.md,
          docs/screenshots/*, docs/release/roadmap.md, docs/README.md, docs/backlog/*
```

## 6. Implementation Order

1. Standard A review of this plan; backlog items `In Progress`; move to `active/`.
2. BUG-UX-013, reported during the round and the only defect that hides work from a child.
   Then WP-0, so that every later gate runs on the promoted inventory.
3. WP-A, starting with BUG-UX-009 and BUG-UX-011, the two that change the most behavior.
4. WP-B, then WP-C, which builds on the removed clock and the corrected label.
5. WP-D and WP-E, independent of each other.
6. Operator GUI review on the development stack; findings go to §9 or the register.
7. WP-F, each step with its own approval.

## 7. Risks & Mitigations

| Risk | Likelihood | Mitigation |
|------|-----------|------------|
| Removing the nested `#content` shifts every view and could affect sidebars, dialogs or the navigation toggle | Medium | Measure first; `layout-clearance.spec.ts` on both viewports and both rows; full browser gates; recaptured screenshots reviewed by eye |
| D-3's cause is only probable | Medium | Reproduce before fixing; on a different cause the item is updated, not forced |
| A task above a person's daily budget cannot be placed by the planner | Medium | Form hint; documented in the concept; budgets stay editable up to 20; real work stays recordable |
| Large energy values skew fairness and Coins against the small-chore scale | Low | Linear and documented; retained tasks unchanged; no reward price depends on the task limit |
| Removing the catch-up action hides a retained capability | Low | Operator decision; agreed appointments keep showing; the route stays until Round 2 (D-089-09) |
| The refusal for an overdue fixed cadence changes behavior that production users may have seen | Low | It is the documented contract (D-089-08); the reason is shown inline; no persisted fact changes |
| The row change arrives during the family trial | Medium | D-089-06 allows it; CS-PLAN-080 records the version in use; the operator's GUI review precedes the export |
| The runtime check moves again during the round | Medium | Rerun before the freeze; promotion is routine since RF-088-11 |
| Docker Desktop is shared with another project and its load can time out browser gates | Medium | Check for its runs first; a timeout is not a failure to explain away, rerun the complete gate |

## 8. Acceptance Criteria

- [ ] Every item of Round 1 delivered with its tests, or explicitly user-rescoped with recorded approval and backlog state updated; no still-required deferred test
- [ ] Feedback register: every entry routed (Round 1 delivered, backlog ID, or closed with a reason); no open P0 or P1
- [ ] No schema or migration change; the 26 applied migrations stay pinned and `estimated_minutes` keeps its column
- [ ] `npm run test:runtime:check` reports `current` at the freeze
- [ ] The app frame keeps equal margins on the desktop viewport, and the end of a long view is reachable, on both runtime rows
- [ ] A child's own tasks stay on My Day above the daily limit; the limit caps only unassigned rows
- [ ] Refused due-date and assignee changes answer 409 with their reason, shown in the dialog, without an error-level log entry; a partly applied save says what was saved
- [ ] Energy accepts 1–20 on every path; existing tasks, frozen occurrences and earlier payouts are unchanged; the concept states 1 energy ≈ 10 minutes
- [ ] One task row on My Day, Tasks and room views, in both themes and on both viewports
- [ ] The catch-up status and already agreed appointments still display; retained `manual_delta` entries still replay
- [ ] Operator GUI review recorded; its findings resolved or routed
- [ ] `0.8.0-alpha.4` qualified: full browser gates and PHPUnit suites on both runtime rows, SQLite and MySQL provider suites, reproducible package build and smoke
- [ ] Deployed through the private exchange; the operator's sanitized report verified and imported into the deployment guide
- [ ] `CHANGELOG.md`, README status/badge/caption, roadmap snapshot and documentation map name the new version
- [ ] Build gate green (`npm test` + lint + backend tests)
- [ ] Required tests ran without new or unapproved skips
- [ ] All implementation decisions and review questions resolved and recorded
- [ ] All plan-owned review findings (§9) resolved or user-rescoped to `backlog.md`
- [ ] Owning backlog items updated or archived according to their actual completion state
- [ ] Durable concepts/decisions integrated into the permanent docs (`docs/concepts/`, `docs/adr/`, `docs/engineering/`, `docs/api/`, `docs/standards/`) — plans are temporary
- [ ] Final Full review is `PASS` or `PASS-WITH-FIXES` (the round touches controllers and refusal paths)
- [ ] `docs/backlog/ACTIVE_TASKS.md` updated

## 9. Review Findings (plan-owned)

Findings **within this plan's scope** from implementation rounds or reviews
(scope-first routing per `project-rules.md` §4). Resolve before `done/`;
rescoping to `backlog.md` needs explicit user approval. Out-of-scope findings
go directly to `backlog.md` (or fold into an existing item) instead.

- [x] RF-089-01 (major) - BUG-UX-011 / D-089-08: the draft did not define "overdue" for a fixed cadence with an older miss next to a current window. The check now looks at every open occurrence, both cases get a regression test and the reason text fits both.
- [x] RF-089-02 (major) - BUG-UX-011: the draft called every refusal correct but left unstated that the dialog cannot move a later occurrence while an older miss stands. Recorded as a known limit until the explicit target of FEAT-UX-050; the refusal says so.
- [x] RF-089-03 (minor) - BUG-UX-009: the mechanism missed `NcContent` and required the mount element to fill the container. Corrected with the computed target frame; the stylesheet leaves the manifest, and the measuring step names the removed wrapper's background and z-index.
- [x] RF-089-04 (minor) - BUG-UX-011: `TaskList.vue` (assignee chips, bulk assignment) and the message shared with `AgreementService` were missing from scope, tests and manifest.
- [x] RF-089-05 (minor) - FEAT-UX-047: My Day does not load floors; one `fetchFloorsWithRooms()` per view, never per row.
- [x] RF-089-06 (minor) - Tests and manifest: the `#content` contrast selectors, `TaskCreationServiceTest`, the scenario name that mentions Quick clean and the docs naming the removed actions or the 1–5 scale were missing.

## Feedback Register (sanitized)

Role-level, sanitized entries only (adult / child / owner); no names, ages, accounts,
quotations or household details. Raw notes stay with the operator. All entries date from
2026-11-03 and were reported by the owner role; the IDs follow the operator's report. D-4 and
D-5 come from the server log. D-6 and D-7 date from 2026-11-04. Owning items are in [`backlog.md`](../../backlog/backlog.md).

| ID | Observation | Type / priority | Routing | State |
|----|-------------|-----------------|---------|-------|
| D-1 | A task repeating every n days shows "Daily" in the list | Bug / P2 | BUG-UX-008 | Round 1; fixed on `fix/alpha4-round1` |
| D-2 | The main frame has no right or bottom margin | Bug / P2 | BUG-UX-009 | Round 1; fixed on `fix/alpha4-round1`, wider than reported |
| D-3 | A dark area partly covers the selected timezone | Bug / P2 | BUG-UX-010 | Round 1; reproduced and fixed on `fix/alpha4-round1` |
| D-4 | HTTP 500 on a refused due-date change | Bug / P2 | BUG-UX-011 | Round 1; fixed on `fix/alpha4-round1`, wider than reported |
| D-5 | An owner-only endpoint is called for every member and fills the log | Bug / P3 | TECH-LOG-001 | Round 1; fixed on `fix/alpha4-round1` |
| D-6 | Tasks created the day before do not appear on a child's My Day | Bug / P2 | BUG-UX-013 | Round 1; fixed on `fix/alpha4-round1`, cause confirmed by the operator |
| D-7 | *Next appointments* shows other people's tasks under *My tasks* | Improvement / P3 | FEAT-UX-047 | Round 1; found in the D-2 check, D-089-12; fixed on `fix/alpha4-round1` |
| L-1 | About 4–6 colour schemes that work in the light and the dark theme | Feature / P3 | FEAT-UX-057 | Round 3 |
| T-1 | Energy as the only effort measure at about 10 minutes each, with room for longer tasks | Improvement / P2 | FEAT-UX-046 | Round 1 (D-089-07) |
| T-2 | Energy shown as bolts in the task dialog, scaling above 5 | Improvement / P3 | FEAT-UX-046 | Round 1 |
| T-3 | Owner-managed categories with a permanent *Other* as default and fallback | Feature / P3 | FEAT-UX-053 | Round 2 (D-089-04) |
| T-4 | Flex days for a fixed schedule, with later dates staying on the grid | Feature / P3 | FEAT-UX-040 (mode naming only) | Closed: already delivered; the form offers the field in both modes and the backend applies it to both |
| T-5 | Per-task history with who did it, any period, statistics, owner edits | Feature / P2 | FEAT-UX-052, FEAT-UX-058, FEAT-UX-060 | Split: Round 2 (history, corrections), Round 3 (statistics), later (ranking periods) |
| T-6 | Postpone by one day per press within the flex days; remove Quick clean | Feature / P2 | FEAT-UX-050, FEAT-UX-048 | Postpone Round 2 (D-089-03); Quick clean Round 1 |
| T-7 | A separate scheduled date set by the scheduler | Feature / P3 | FEAT-UX-059 | Later; scheduler discussion first |
| G-1 | One form for Add and Edit; room picked by floor, then room; the room can change | Improvement / P2 | FEAT-UX-040, FEAT-UX-043 | Round 2; mockups first |
| G-2 | Sub-tasks are edited in Edit Task | Improvement / P2 | FEAT-UX-040 | Round 2 |
| G-3 | Assigned members change only in Edit Task | Improvement / P2 | FEAT-UX-040 | Round 2 |
| G-4 | Postpone replaces moving a single occurrence | Improvement / P2 | FEAT-UX-050 | Round 2 |
| G-5 | Handover as a direct button | Improvement / P3 | FEAT-UX-050 | Round 2 (D-089-03) |
| G-6 | Drop the details view if nothing remains in it | Improvement / P3 | FEAT-UX-050 | Round 2; *Restart* needs a home |
| G-7 | The same task entry everywhere: energy in colour, no clock, flex after the recurrence, floor and room under the title | Improvement / P2 | FEAT-UX-047 | Round 1 |
| C-1 | A short tap picks who did the task; every member may record for any other | Feature / P2 | FEAT-UX-051 | Round 2; reverses Q-074-02 by operator decision |
| C-2 | A tap picks finished sub-tasks and people; holding marks all done | Feature / P2 | FEAT-UX-051 | Round 2 (D-089-02) |
| C-3 | Remove the catch-up agreement action; the status stays | Improvement / P3 | FEAT-UX-048 | Round 1 |
| S-1 | Separate personal from household settings | Improvement / P3 | FEAT-UX-054 | Round 2 |
| S-2 | Group settings into collapsible categories, starting collapsed | Improvement / P3 | FEAT-UX-054 | Round 2; two levels |
| S-3 | Notifications become one category | Improvement / P3 | FEAT-UX-054 | Round 2 |
| S-4 | Task categories are managed in the household settings | Feature / P3 | FEAT-UX-053 | Round 2 |
| S-5 | House name and timezone join Floors & Rooms in a household category | Improvement / P3 | FEAT-UX-054 | Round 2 |
| S-6 | Owners set each game's unlock threshold and reset a person's progress after a confirmation | Feature / P3 | FEAT-UX-056 | Round 3 (D-089-05); amends ADR-019 |
| S-7 | Rename a room in the Floors & Rooms list; show its task count | Improvement / P3 | FEAT-UX-049 | Round 1 |
| S-8 | Nicer expand and reorder controls that look different; one expand style everywhere | Improvement / P3 | FEAT-UX-049 | Round 1 |
| S-9 | Icons and colours for floors and rooms, with defaults and a manual choice | Feature / P3 | FEAT-UX-055 | Round 3; first schema step on the retained baseline |
| S-10 | Numbered floors by default, with a choice whether the first floor above ground counts as 1st or 2nd | Improvement / P3 | BUG-UX-012 | Round 1; the wizard's numbering is also wrong today |
| I-1 | Support the more widely used European languages | Feature / P3 | FEAT-UX-061 | Later, once the texts settle |

## Review Outcome

**Standard A, 2026-11-04: PASS-WITH-FIXES.** Scope: this plan, checked against the owning
backlog items, the active CS-PLAN-080 and CS-PLAN-088, the source and test entry points, the
Nextcloud 35.0.2 core CSS of the test image, the `NcContent` CSS and the release and retention
rules. No subagents and no implementation edits.

- Base HEAD `8b5248ab9727c1117d50182d6840efa1f73f0d25`, branch
  `docs/alpha3-closure-alpha4-plan`, pinned worktree. Capture: empty index; unstaged drafts
  `docs/backlog/backlog.md` and `docs/plans/README.md` (binary diff SHA-256 prefix
  `543a3cd27606d769`); one untracked path, this plan, SHA-256
  `6e65157c0cb093db35b69782a04a7b67557613fd7524fc1103194dcb40ce3042`, a regular file. The
  destination was absent. No overlap with the active plans: CS-PLAN-080 keeps the human
  evidence, CS-PLAN-088 the alpha.3 closure.
- RF-089-01..06 resolved at plan level (§9); these are plan corrections, not delivered fixes.
  All 34 register entries are routed, and every work item has an owning backlog ID.
- Decisions: D-089-00..06 recorded from the operator; the operator accepted D-089-07..09 and
  the review's recommendation on 2026-11-04. RF-089-01 sharpens D-089-08. No question is open.
- Gates: Jest 159 suites / 2241 tests, `npm run lint`, `backlog:check`, `docs:encoding`,
  `agents:check`, `git diff --check`. Documentation only, so Docker backend, browser, build
  and package gates were not required and did not run; they stay binding for the implementation.
- Transition: `new/` to `active/`; the 11 owning backlog items are `In Progress`, RELEASE-002
  assigns this plan its alpha.4 slice. Record:
  [2026-11-04 review](../../backlog/archive/CODE_REVIEW_2026-11-04.md#standard-a---cs-plan-089-alpha4-trial-defects-and-everyday-fixes).
- Finalization is one scoped review commit; no merge, push, tag or deployment.
