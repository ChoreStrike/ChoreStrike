# CS-PLAN-090: 0.8.0-alpha.5 — Simplified Core, Release A (Screens, Absence, Feedback)

> **Status:** Active - Standard A `PASS-WITH-FIXES` 2026-11-10; WP-A implemented, interim Standard B `PASS-WITH-FIXES` 2026-11-10; WP-F implemented, interim Standard B `PASS-WITH-FIXES` 2026-11-11; WP-B to WP-E and WP-G not started
> **Effort:** XL — about 24–30 engineering days, plus the operator's GUI review and the release steps, each with its own approval
> **Priority:** P1
> **Depends on:** the confirmed [simplified-core target picture](../../concepts/simplified-core.md) (operator, 2026-11-10); `0.8.0-alpha.4` on the retained baseline; one data-only migration step, the first on that baseline (D-090-16)
> **Related backlog items:** WP-A FEAT-UX-065 · WP-B FEAT-UX-062 · WP-C FEAT-UX-063 · WP-D FEAT-UX-064, FEAT-UX-054 · WP-E FEAT-UX-066 · WP-F FEAT-UX-067 · WP-G RELEASE-002 (alpha.5 slice) · umbrella CONCEPT-002 · **not in scope:** Releases B and C of the concept (derived windows, postpone, the one form, sleeping and bonus tasks, coin multipliers, the live suggestion); FEAT-UX-040, FEAT-UX-041, FEAT-UX-050, FEAT-UX-051, FEAT-UX-052, FEAT-UX-059; TECH-TEST-005 and CONCEPT-001 (owned by [CS-PLAN-080](../active/cs-plan-080-wp9-cross-db-and-family-evidence.md))

## 1. Problem Summary

The family trial ended with one main complaint: the same things are shown in several places,
and the rules ask for more than a family needs. The confirmed target picture answers it in three
releases. This plan owns **Release A**: what improves the screens without changing how due
dates work.

The code was inventoried at `9872cbf`. Four facts shape the plan:

- **Eleven navigation entries, four task surfaces.** My Day, the Dashboard, Tasks and the room
  page each render tasks with their own loading and filtering code. `TodayView.vue` has 1201
  lines, `RoomDetail.vue` 956, `TaskManager.vue` 743 and `Settings.vue` 1502, against a
  guideline of about 300. The participation logic exists three times.
- **Some functions exist only on the pages that go.** The template picker and the dialog for
  the due date and *Last done* are reachable only from the room page; the setup wizard opens
  only from the Dashboard; *Last cleaned* is shown only on a room card.
- **"Absence without request" is not a screen change.** No endpoint deletes or revokes a pause.
  Shortening clamps to today and cannot remove an absence that has not started. The projection
  reads acknowledged revisions only, so a later revision in any other state never removes
  coverage. The interface shows a member only their own entries.
- **Nearly every browser spec crosses the Dashboard.** About forty specs, three shared fixtures,
  the gate manifest and the package smoke navigate through `#/dashboard`, room cards or the
  room page. Moving them is the largest single part of this plan.

## 2. Strategy

One release, `0.8.0-alpha.5`, on the family instance, built in six work packages that can each
be reviewed on their own. The occurrence model, the agreements and the planner stay exactly as
shipped; Release B replaces them.

- **No table or column changes; one data-only migration step.** The 26 applied migrations stay
  pinned, and the `pauses` table already holds everything an absence needs. By operator decision
  (D-090-16) one new ordered step switches the completion animation on for every member once. It
  is the first step on the retained baseline, so it follows the
  [rules for schema changes after adoption](../../engineering/deployment.md#schema-changes-after-adoption):
  populated-baseline upgrade tests and fresh/upgrade parity on every supported provider.
- **Old addresses keep working.** `/dashboard`, `/room/:id`, `/leaderboard` and
  `/reward-requests` stay as routes or redirects, so bookmarks and the digest link do not break.
- **Extract before rearranging.** Each large view first loses its shared logic to a composable
  or a small component, with its tests, and is then recomposed. No view grows.
- **Tests move with their surface.** Each work package migrates the specs, fixtures and gate
  entries of the surface it changes, so the gate stays green between packages.
- **Mockups are the reference** for layout and wording. They live outside the repository; the
  concept's *Screens* section is the tracked summary.

### Decisions recorded (operator)

- **D-090-01 Target picture (2026-11-10):** mockups and rules are confirmed as reviewed with the
  family, including the five rules the concept had marked as proposed.
- **D-090-02 Stage (2026-11-10):** this release and Releases B and C stay alpha versions on the
  family instance. The `v0.9` beta follows after them, possibly with a larger circle.
- **D-090-03 Absence (2026-11-09):** everyone enters an absence for themselves without a reason
  or a request; the owner sees every entry and may delete it.
- **D-090-04 Screens (2026-11-09):** My Day and the Dashboard merge; floors and rooms move to the
  tasks; Insights becomes compact; the ranking moves into Insights and the planning settings
  into Settings.
- **D-090-05 Feedback (2026-11-09):** the animation is on by default, sound stays an opt-in.
- **D-090-16 Animation for existing members (2026-11-10):** the update switches the animation on
  for everyone once, also where "off" is stored, because a stored "off" cannot be told apart from
  a value nobody chose. The operator chose this over leaving stored values alone, knowing that it
  overrides explicit choices and is the first migration step on the retained baseline. Members
  can switch it off again afterwards. Sound is not touched.
- **D-090-17 The owner's entry afterwards (2026-11-10):** it stays until Release B removes
  misses, as an owner action in the Absence view under neutral wording. Until then it is the
  only way to excuse a miss that has already expired.
- **D-090-18 Settings structure (2026-11-10):** FEAT-UX-054 joins this release: personal and
  household settings with collapsible categories, so the page is rebuilt once.
- **D-090-19 Room cleanliness (2026-11-10):** each room heading keeps a small percentage next to
  its counts, beyond what the mockups show.
- **D-090-21 Cancelled rows already stored (2026-11-10, Standard A):** one rule for every
  `cancelled` revision. The pending requests that a departure cancelled in place (Q-073-03,
  `PauseMapper::cancelPendingPersonalForSubject()`) also end their group's coverage from the
  local day after the departure. This shows only when the member rejoins with the retained
  wallet, and it matches Q-073-03 ("rejoin requires a new pause"). A group that had no pending
  row at the departure keeps today's behaviour. No marker and no second rule.
- **D-090-22 What can be deleted (2026-11-10, Standard A):** pending, upcoming and running
  absences. Deleting a request still pending from before the update withdraws it. An absence
  that has ended has nothing left to free: it stays in the shared list with no delete control,
  and the server answers 409.

### Decisions left to the source project

Positions of this plan. They bind once Standard A passes.

- **D-090-06 Version and content:** `0.8.0-alpha.5`. It also carries what `master` holds since
  `0.8.0-alpha.4` (BUG-UX-014 and the dependency updates), with the read-only check that item
  asks for before and after the update.
- **D-090-07 Deleting an absence:** a new command appends a `cancelled` revision, and the
  projection lets a cancelled revision govern like a shortening does: from the local day after
  the deletion. So an absence that has not started disappears, a running one ends today, and
  what an absence already excused stays excused. This reuses the boundary rule the shortening
  already has instead of adding a second mechanism.
- **D-090-08 Reasons:** the interface no longer asks for or shows a reason. The server keeps
  accepting the optional field and stored reasons stay; nothing is rewritten.
- **D-090-09 Requests already pending:** no new request can arise. The owner's decision buttons
  stay, shown only while a pending row exists, so a request created before the update can still
  be closed.
- **D-090-10 Routes without a client:** `PUT /pauses/{id}` (shorten) and
  `GET /room-tasks/room/{id}/stats` lose their client and stay until Release B reworks both
  surfaces, as D-089-09 did for the cleanliness and catch-up routes.
- **D-090-11 What leaves the screens:** the Dashboard tiles, the room cards, the room's task
  statistics and *Next appointments* on Today, as in the confirmed mockups. Upcoming work is in
  the calendar; house health stays in Insights.
- **D-090-12 What needs a new home:** the template picker opens from *New* inside a room heading;
  the dialog for due date and *Last done* opens from the expanded task row; the setup wizard
  opens from Today for an owner with an empty house and from Settings; *Last cleaned* shows in
  the room heading.
- **D-090-13 Activity list:** it stays a list of completions in this release, single-line and
  with the coins a completion paid. Absences, date changes and postponements join it in
  Release B, when those events exist; until then the absence list itself is the shared view.
- **D-090-14 Weekly plan:** the owner's preview-and-apply planner keeps its page until Release C
  replaces it. It leaves the navigation and is reached from the calendar.
- **D-090-15 Medal check:** it stays with the ranking component and so moves to Insights with it.
- **D-090-20 Shape of the migration step:** data only. It sets `animation_enabled` on every
  existing preference row and bumps the row version, so a page that was open during the update
  cannot write the old value back unnoticed. The column default stays as it is: every path that
  creates a row writes the delivery fields explicitly, which makes the column default
  irrelevant and avoids a definition change on three database providers.

Positions taken while implementing WP-A (2026-11-10). They bind once its interim review passes.

- **D-090-23 Delete parameters:** the client sends `commandId` and `expectedRevision` of
  `DELETE /pauses/{id}` as query parameters, because a DELETE body does not survive every
  proxy. The server reads both places.
- **D-090-24 Pending requests in the list:** a request stored before the update appears as a
  row of the shared list, for its subject and the owner, with the owner's decision buttons in
  the row instead of a separate block in the owner section. Declined requests are not listed.
- **D-090-25 What "ended" means:** the revision that governs today decides, so a pending
  shortening does not make an ended absence deletable. A pending first request never covered
  anything and can be withdrawn whatever its dates.
- **D-090-26 Wording in the planner:** the capacity grid says *Absent* where it said *Paused*;
  *Away* stays the label of a free day.
- **D-090-27 The revision a group stands for (operator, 2026-11-10, interim review of WP-A):**
  a group is its latest revision, except that a request declined on top of an absence (a child's
  shortening from before ADR-020) leaves the absence as it stood. The list then shows the latest
  acknowledged or cancelled revision with the group's latest revision number, and deletion
  follows the same rule. Each list entry carries `deletable` for its viewer, by the rule the
  delete command applies, and the view uses it instead of deciding on its own (RF-090-08).

Positions taken while implementing WP-F (2026-11-11). They bind once its interim review passes.

- **D-090-28 The app-upgrade proof and its source:** proof P4 of the preservation runner
  installs the release commit of `0.8.0-alpha.4` from Git, the backend parts only, and checks
  that it ships exactly the pinned migrations. The candidate still carries that version until
  WP-G, and Nextcloud updates an app only to a higher one. Until then the proof runs with
  `-StampVersion 0.8.0-alpha.5`, which changes the version in the owned copy alone and is
  recorded in the summary; the release qualification runs unstamped. On the AIO row the proof
  uses that row's Nextcloud and PHP image with PostgreSQL, without the Redis peer.
- **D-090-29 Shape of the step in code:** it gets the database connection through its
  constructor and only defines `postSchemaChange()`. A fresh install applies schema steps
  only, so the step does not run there; new rows get the default from
  `MemberPreference::createFor()`, which marks every delivery field for the insert because the
  entity setters skip a value equal to the property default. The two tests that walk the
  migration chain skip a step that inherits `changeSchema()`.
- **D-090-30 Feedback on the row:** the store marks a room task as just completed for a moment
  and a shared stylesheet highlights the row and pops its check mark. It is always on, like
  the check mark of the snackbar, and follows reduced motion but not the animation switch. A
  row shows it where it stays in its list. A recurring row that leaves *Today* on completion
  shows it from WP-B on, where `TodayTaskRow` holds a just-completed row for the length of the
  effect.
- **D-090-31 Coins in the snackbar:** the viewer's own share of this completion. A completion
  that paid the viewer nothing keeps the plain text.
- **D-090-32 Unread preferences (operator, 2026-11-11, interim review of WP-F):** the animation
  runs only after an authoritative preference read. While `/preferences/me` is pending or has
  failed it stays off, like the other optional surfaces, so the store's initial default never
  overrides a stored "off" (RF-090-13).

The four questions of the draft were answered by the operator on 2026-11-10 (D-090-16 to
D-090-19), and the two of the Standard A review the same day (D-090-21, D-090-22). No question
is open.

## 3. Backend / Frontend Changes

### WP-A Absence (FEAT-UX-065)

**Backend**

- `PauseService::requestForSelf()` acknowledges for every role; the child branch that created a
  `requested` row goes, in `shorten()` too.
- New `PauseService::delete()` as the command `pause.delete`: the subject for an own personal
  absence, the owner for any absence including a household one. It runs under the house lock
  with `commandId` and `expectedRevision`, appends a `cancelled` revision and re-applies the
  effects. A stale revision answers 409, and so does an absence that has ended (D-090-22); a
  pending request is withdrawn. Deleting someone else's absence queues one digest change for
  the subject; deleting a household absence queues it for every other member, as any pause
  decision does.
- `PauseProjectionService`: a cancelled revision takes part in the governing rule with the same
  next-local-day boundary and means no coverage (D-090-07). The rule reads every cancelled
  revision alike, also a pending request that a departure cancelled in place (D-090-21). The
  fingerprint already covers state, so selection tokens and plan previews turn stale as for any
  pause change.
- `PauseService::listForViewer()` leaves out groups whose current revision is cancelled.
- `PauseController::destroy()` and the route `DELETE /api/v1/pauses/{id}`: thin, validated,
  `@NoAdminRequired`, conflicts through `ControllerErrorFormatter::conflictResponse()`.
- `CommandService` lists the new action for the audit log.

**Frontend**

- New `AbsenceView.vue` at `/absence` with its navigation entry: from, until, *Enter*; the list
  of everyone's entries; a delete control on one's own entries and, for the owner, on all, as
  long as an entry has not ended (D-090-22).
- The owner section holds the household absence without a reason, the entry for someone else
  afterwards (D-090-17) and the decision buttons while a request is pending (D-090-09).
- `PauseSettings.vue` and the three pause blocks of `Settings.vue` go; the pauses store gains
  `deletePause` and loses the unused getters.
- Wording: *Absence* replaces *Pause* in the interface and the help.

### WP-B Today (FEAT-UX-062)

- `TodayView.vue` becomes the one landing page. Top to bottom: a status line (energy planned
  against today's capacity, weekly goal, streak, own coins), the overdue block, *Due today*,
  *Also possible today* collapsed behind one line, the free-day state.
- The overdue block is today's catch-up list in a strong red block, always first.
- Extracted first, with specs: `useTaskCompletion` (the participation flow that exists three
  times), `TodayStatusLine.vue`, `TodayTaskRow.vue` (the row markup that exists twice in the
  view). `TodayTaskRow` holds a just-completed row for the length of the completion feedback,
  so a recurring row shows it before it leaves the list (D-090-30).
- `Dashboard.vue`, `QuickStats.vue`, `HouseHealthBar.vue` and `HouseholdGoalBar.vue` go;
  `/dashboard` redirects to `today`. `HouseStatsCards` keeps showing house health in Insights.
- The setup wizard opens from Today for an owner with an empty house, with the existing
  dismissal rule, and from *Floors & Rooms* in Settings.

### WP-C Tasks by due date, room or person (FEAT-UX-063)

- New `src/utils/taskGrouping.js`: pure functions that group the filtered rows by due class, by
  floor and room, or by person, with open and overdue counts per group.
- `TaskManager.vue` gains the switch *By due date · By room · By person*, mirrored in the URL as
  `?group=`. Grouped by room, floors are headings and rooms are collapsible rows with their
  counts, a small cleanliness percentage (D-090-19) and *Last cleaned*; `?room=` opens one room. The pager stays for *By due date* only,
  because grouped views render a room's rows when it opens.
- `/room/:id` redirects to `/tasks?group=room&room=:id`. `RoomDetail.vue`, `FloorList.vue` and
  `RoomCard.vue` go; rename and delete stay in Settings, where they already are.
- *New* inside a room heading opens the template picker for that room, with the custom form one
  step behind it, as the room page did. The expanded row gains *Date* for the dialog of
  `RoomTaskEditModal` (D-090-12).
- One house-wide read feeds every grouping; no per-room request.

### WP-D Navigation and Settings (FEAT-UX-064, FEAT-UX-054)

- `App.vue`: Today, Tasks, Calendar, Insights, Absence, Rewards, Arcade, Settings, Help. The
  visibility rules of Arcade and Rewards stay.
- *My requests* is reached from Rewards and, always, from the personal settings, so it stays
  reachable with either catalog gate closed; its routes stay.
- The weekly plan leaves the navigation and gets a link in the calendar header (D-090-14).
- `Settings.vue` is split per category behind a *Personal | Household* switch (D-090-18):
  personal holds title, notifications and feedback, planning (own days and energy) and the link
  to the requests; household holds house, members, floors and rooms, rewards, arcade and the
  danger zone. Non-owners see only *Personal*. The planning components load their own data and
  report a failed save inline instead of replacing the page.

### WP-E Insights (FEAT-UX-066)

- `InsightsView.vue`: four key figures in one row, the fairness table as compact bars, the
  ranking as a section, and the activity list.
- `Leaderboard.vue` becomes `RankingPanel.vue` without its page chrome, with an error state of
  its own; `/leaderboard` redirects to Insights.
- `ActivityFeed.vue`: one line per entry (time, person, task, coins), grouped by day; *Correct
  split* and *Undo* move behind a tap on the row.
- `ActivityService` adds `coinMinor` to each allocation entry, under the redaction that already
  applies to energy. The API reference already shows the field and gains `occurrenceId`.

### WP-F Feedback (FEAT-UX-067)

- Default: animation on, sound off. The computed default changes in `PreferenceService` and in
  the store's initial state. Every path that creates a preference row
  (`PreferenceService::updateOwnPreferences()`, `RankingService` on consent) writes the delivery
  fields explicitly, so a first save of one field cannot flip the others to the column default.
  `prefers-reduced-motion` keeps winning.
- One new migration step, the 27th, switches the animation on in every existing preference row
  and bumps its version (D-090-16, D-090-20). It changes no table definition, touches no other
  column and is safe to meet an empty table. The handoff names it, so the operator's
  before-and-after comparison expects exactly this change in the preference table and none
  elsewhere; the deployment guide gains an *expected data change* paragraph for it, as it has
  for BUG-UX-014, with the counts the operator compares. After the verified deployment the step
  joins the pinned inventory (`tests/migrations/applied-migrations.json`) with its SHA-256,
  reconciled against the installed migration list of the sanitized report, and is immutable
  from then on.
- More visible completion: the row's check mark pops in and the row highlights briefly, the
  snackbar names the coins earned, and a new title gets the toast it lacks today. All of it
  respects reduced motion; none of it needs the sound opt-in.
- The settings text that says both effects are off by default changes with it.

### WP-G Release `0.8.0-alpha.5` (RELEASE-002)

App metadata in `appinfo/info.xml` and `package.json`; changelog section, dated after the
verified deployment; README, roadmap and documentation map; screenshots recaptured, because
every view moves; the operator's GUI review on the development stack; final review; the
[qualification pipeline](../../standards/ai/skills/release/SKILL.md#qualification-pipeline);
export of one selected smoke run; operator deployment; verified record in the deployment guide.
Each step needs its own approval.

### Durable documentation

- New **ADR-020: Absences without requests**, amending the pause section of ADR-013: direct
  acknowledgement for every role, deletion as a cancelled revision, the boundary rule, one
  reading for every cancelled revision (D-090-21) and what can be deleted (D-090-22).
- `calm-core-ux.md`: Today as the one landing page, the feedback default, absences.
  `task-model-and-sync.md` and `gamification-and-rewards.md`: the pause paragraphs.
  `ui-use-cases.md`: the use cases of the removed pages, and the stale landing rule found in the
  inventory. `docs/api/README.md` and `docs/api/notifications.md`. `user-guide.md` and the in-app
  help. `architecture.md`: the component map and its large-file list.

## 4. Tests

- **WP-A:** `PauseServiceTest` (every role acknowledged; delete by subject and owner, forbidden
  for others, stale revision, receipt replay; a pending request is withdrawn; an ended absence
  answers 409); `PauseProjectionServiceTest` (a cancelled revision frees later days, keeps today
  and earlier, removes a future absence; a departure-cancelled first request covers nothing and a
  departure-cancelled pending shortening ends its group's coverage from the next local day,
  D-090-21); `PauseAgreementTest` on all providers (a child's absence suspends at once; deletion
  lifts the suspension from the next day; a deleted future absence leaves no trace in capacity,
  weekly goal or rhythm; a member who rejoins after a departure with a pending shortening is not
  covered again);
  `PauseAgreementConcurrencyTest` for delete against plan apply; `RoleGuardApiTest` and
  `OwnerCommandAuthorizationTest`; `RouteMatrixTest` at 98 routes; `CommandAuditTest`;
  `PauseControllerTest`. Jest for `AbsenceView` and the store. Browser: the pause scenario of
  `calm-core-ui.spec.ts` becomes "a child enters an absence, everyone sees it, the owner deletes
  it"; `family-rhythm.spec.ts` and `minimal-smoke-tests.spec.ts` follow.
- **WP-B:** specs for `useTaskCompletion`, `TodayStatusLine` and `TodayTaskRow`; `TodayView.spec.js`
  for the section order, the red block and the wizard entry; `router.spec.js` for the redirect.
  Browser: `today-view.spec.ts`, `family-core-ux.spec.ts`, `workflow-state-reconciliation.spec.ts`,
  `setup-wizard-journey.spec.ts`.
- **WP-C:** `taskGrouping.spec.js` for the three groupings, the counts and empty groups;
  `TaskManager.spec.js` for the switch, the URL, the room deep link and the paging rule;
  `requestCounts.spec.js` for one house-wide read. Browser: `task-manager.spec.ts`; the scenarios
  of `room-detail.spec.ts`, `room-management.spec.ts`, `dashboard.spec.ts` and
  `dashboard-navigation.spec.ts` move to the new surfaces or go with a recorded reason.
- **WP-D:** `App.spec.js` and `navigationIcons.spec.js` for the eight entries; Settings specs per
  category and for the non-owner view; the scheduling component specs for own loading and
  inline errors. Browser: `settings.spec.ts` through one helper that opens a category;
  `self-availability-member.spec.ts`, `availability-settings-flow.spec.ts`, `family-rewards.spec.ts`.
- **WP-E:** `ActivityServiceTest` and `HouseActivityIntegrationTest` for `coinMinor` and its
  redaction; `RankingPanel` and `ActivityFeed` specs; `InsightsView.spec.js`. Browser:
  `insights-flow.spec.ts`, `leaderboard.spec.ts`, `ranking-consent.spec.ts`.
- **WP-F:** `PreferenceServiceTest` for the computed default and for row creation by a one-field
  save and by ranking consent; an integration test on all providers that an explicit "off" in a
  first save is stored as off; `SupportedDatabaseSchemaTest`; Jest for the store, the effect
  gates, the celebration composable and the settings text. Browser: `calm-core-ui.spec.ts` now
  starts with the animation on and still shows no canvas under reduced motion.
- **Migration step (WP-F):** a real app upgrade on PostgreSQL, MySQL and SQLite. A new
  app-upgrade proof in the preservation runner (`scripts/test-runtime-preservation.ps1`)
  installs the `0.8.0-alpha.4` build (the 26 baseline migrations) on an owned synthetic target,
  populates it and runs `occ upgrade` to the candidate without reseeding; the P1–P3 proofs and
  their fixtures stay separate. The populated state holds preference rows in every state
  (animation off, on, sound on, row created by ranking consent, member without a row) and pause
  rows in every state, departure-cancelled requests included. Afterwards the animation is on in
  every row, each version is one higher, sound and every other column and table are unchanged,
  the pause rows are identical, and a member without a row still has none. A PHPUnit
  integration test runs the step on each provider and shows that it runs once and leaves an
  empty table alone. Fresh install and upgrade end in the same schema and the same computed
  defaults. The recovery fixture keeps restoring. `appliedMigrations.spec.js` stays green: the
  26 pinned files are untouched and a new step is allowed.
- **Shared:** the fixtures `browser-aware-navigation.ts`, `test-house.ts` and `dashboard-floors.ts`;
  `tests/e2e/required-scenarios.json` and `scripts/run-e2e-gate.js` with `browserGate.spec.js`;
  `tests/package/owner-smoke.cjs`; `tests/docs-screenshots/scenes.js` with `docsDrift.spec.js`;
  the source-scanning specs that list view files (`coreWorkflowLocalization`, `ExpandChevron`,
  `theme-consistency`, `view-inline-start`). At `9872cbf`, 40 browser specs and 4 fixtures
  reference the Dashboard, a room page or the leaderboard, and further specs reach the weekly
  plan through the navigation. Besides those named per package they include `mobile-core`
  (TECH-TEST-005's seven mobile scenarios), `scheduling` and `scheduling-apply-flow` (the weekly
  plan leaves the navigation), `3-floor-house`, `core-household-journey`, `layout-clearance`,
  `color-contrast` and `multi-user-interactions`. Each spec moves with the work package that
  removes its page. No scenario is dropped without a successor or a recorded reason, and no
  minimum count is lowered.
- **Gates:** Jest, ESLint, stylelint, `composer cs:check`, `npm run l10n:check-keys`,
  `backlog:check`, `docs:encoding`, `agents:check`; PHPUnit unit and integration on the canonical
  stack, SQLite, MySQL and the AIO row; browser gates on both rows; package build and smoke.

## 5. File Manifest

```text
modified: lib/Controller/PauseController.php (destroy), appinfo/routes.php (one route)
modified: lib/Service/PauseService.php, lib/Service/PauseProjectionService.php,
          lib/Service/CommandService.php, lib/Service/ActivityService.php,
          lib/Service/PreferenceService.php, lib/Service/RankingService.php
modified: lib/Db/MemberPreference.php (delivery defaults, createFor)
new:      lib/Migration/Version1034Date20261111000000.php (data only, D-090-20) with its
          upgrade tests; after the verified deployment: tests/migrations/applied-migrations.json
modified: scripts/test-runtime-preservation.ps1 (proof P4: app upgrade from 0.8.0-alpha.4,
          with its self-test), tests/recovery/owned-target.php
new:      tests/recovery/upgrade-fixture.php, src/styles/feedback.css
new:      src/views/AbsenceView.vue, src/components/Insights/RankingPanel.vue,
          src/components/Today/TodayStatusLine.vue, src/components/Today/TodayTaskRow.vue,
          src/composables/useTaskCompletion.js, src/utils/taskGrouping.js,
          src/components/Tasks/TaskGroupList.vue, src/components/Settings/ (one file per category)
modified: src/App.vue, src/router.js, src/views/TodayView.vue, src/views/TaskManager.vue,
          src/views/InsightsView.vue, src/views/Settings.vue, src/views/CalendarView.vue,
          src/views/Rewards.vue, src/views/SchedulingView.vue
modified: src/components/Tasks/TaskList.vue, TaskListItem.vue, TaskListDetails.vue,
          TaskFilterBar.vue, src/components/Insights/ActivityFeed.vue, MemberFairnessTable.vue,
          HouseStatsCards.vue, src/components/Scheduling/SelfAvailabilitySettings.vue,
          EnergyBudgetSettings.vue, src/components/Settings/NotificationPreferences.vue,
          src/components/Common/UndoSnackbar.vue, src/components/Help/HelpGlossary.vue,
          HelpEntry.vue
modified: src/store/pauses.js, src/store/preferences.js, src/store/house.js, src/services/api.js,
          src/composables/useCelebration.js, src/composables/useToasts.js
removed:  src/views/Dashboard.vue, src/views/RoomDetail.vue, src/views/Leaderboard.vue,
          src/components/Dashboard/QuickStats.vue, HouseHealthBar.vue, HouseholdGoalBar.vue,
          FloorList.vue, RoomCard.vue, src/components/Settings/PauseSettings.vue, with their specs
modified: l10n/de.json, l10n/en.json, l10n/templates/chorestrike.pot
modified: tests named in section 4, tests/e2e/fixtures/*, tests/e2e/required-scenarios.json,
          scripts/run-e2e-gate.js, tests/package/owner-smoke.cjs, tests/docs-screenshots/scenes.js
new:      docs/adr/ADR-020-absences-without-requests.md
modified: docs/adr/README.md, docs/concepts/calm-core-ux.md, task-model-and-sync.md,
          gamification-and-rewards.md, ui-use-cases.md, simplified-core.md, docs/api/README.md,
          docs/api/notifications.md, docs/user-guide.md, docs/engineering/architecture.md,
          docs/engineering/deployment.md, docs/engineering/testing.md,
          docs/testing/release-test-plan.md
modified: appinfo/info.xml, package.json, package-lock.json, CHANGELOG.md, README.md,
          docs/screenshots/*, docs/release/roadmap.md, docs/README.md, docs/backlog/*
```

## 6. Implementation Order

1. Standard A review of this plan; the backlog items get this plan as owner and turn
   `In Progress`; move to `active/`.
2. WP-A backend with ADR-020, then its view. It changes server rules, so it gets an interim
   review of its own.
3. WP-F, independent of the screens. Its migration step gets the same interim review, with the
   populated upgrade on all providers, before anything else builds on it.
4. WP-B: extract, then recompose Today; the Dashboard goes with its specs and fixtures.
5. WP-C: grouping, then the room page goes.
6. WP-D, then WP-E, which builds on the new navigation.
7. Operator GUI review on the development stack; findings go to §9.
8. WP-G, each step with its own approval.

## 7. Risks & Mitigations

| Risk | Likelihood | Mitigation |
|------|-----------|------------|
| The projection change frees or keeps days it should not | Medium | One rule shared with shortening; unit cases per boundary and time zone; integration on all providers; interim review after WP-A |
| A child's absence takes effect at once, where a request did nothing | Low | Operator decision D-090-03; the absence list is visible to everyone and the owner can delete |
| The browser migration loses coverage | High | Scenario-by-scenario mapping in `required-scenarios.json`; no minimum lowered; every dropped scenario carries a recorded reason |
| A function of a removed page has no new home | Medium | The inventory lists them (D-090-12); a browser scenario per relocated function |
| The plan is too large for one review | Medium | Work packages with interim Standard B reviews; the gate stays green between them |
| A first save flips the animation to the column default | Medium | Explicit writes on row creation; integration test on all providers before the default changes |
| The first migration step on the retained baseline goes wrong in production | Low | Data only, one column, one table; populated upgrade on all three providers; current whole-instance backup before the update; the operator's before-and-after comparison names the one expected change; recovery is a whole-instance restore, never a downgrade |
| The step overrides a member's deliberate "off" | Certain | Operator decision D-090-16; the changelog and the handoff say so; one switch in the personal settings turns it off again |
| A departure cancellation now ends its group's coverage | Low | Operator decision D-090-21; visible only after a rejoin with the retained wallet; ADR-020 records it; projection and integration tests pin both row shapes; the upgrade proof shows the stored pause rows unchanged |
| CS-PLAN-080's open real-device checklist (`release-test-plan.md` §14.1, rows D1, D6, D8) and `mobile-core.spec.ts` name screens this release removes | Certain | This plan updates the checklist rows and the mobile scenarios with their surfaces, keeping the seven-scenario count; the run, its record and how CS-PLAN-080 closes stay with that plan; a record names the version it ran on |
| Grouped lists render slowly in a large household | Low | One house-wide read; a room's rows render when it opens; request-count spec |
| Bookmarks and the digest link break | Low | Redirects for the four old addresses; router specs |
| The runtime check moves during the round | Medium | Rerun before the freeze; promotion is routine |
| Docker Desktop is shared with another project | Medium | Check for its runs first; rerun the complete gate, never explain a timeout away |

## 8. Acceptance Criteria

- [ ] Every member, children included, enters an absence for themselves that takes effect at once; everyone sees all entries; a member deletes their own and the owner any, as long as it has not ended; an ended absence stays listed
- [ ] Deleting frees the days from the next local day, keeps today and earlier, and removes an absence that has not started; no reason is asked or shown
- [ ] Every cancelled revision, including a pending request a departure cancelled, ends coverage from its next local day (D-090-21), recorded in ADR-020
- [ ] No table or column definition changes; the 26 applied migrations stay pinned; stored pause rows are unchanged by the update
- [ ] The one new migration step switches the animation on in every existing preference row, bumps its version and changes nothing else; real app upgrades from `0.8.0-alpha.4` pass on PostgreSQL, MySQL and SQLite, and fresh and upgraded installs match
- [ ] After the verified deployment the step is appended to `tests/migrations/applied-migrations.json` with its SHA-256, reconciled against the installed migration list of the sanitized report
- [ ] Today is the landing page for every role and shows status line, overdue block, due today and what is already possible; `/dashboard` redirects
- [ ] Tasks groups by due date, by room and by person; a room deep link opens its room; `/room/:id` redirects
- [ ] Template picker, due date and *Last done*, setup wizard and *Last cleaned* are reachable from their new places
- [ ] The navigation has eight entries plus Help; ranking, planning settings and requests are reachable for every role that could reach them before
- [ ] Settings shows personal and household categories; a non-owner sees only personal ones
- [ ] Insights shows key figures, fairness, ranking and a single-line activity list with coins
- [ ] Every member gets the completion animation after the update, with or without stored preferences; sound stays as it was; reduced motion suppresses the animation; switching it off afterwards holds, also as a first save
- [ ] Each room heading shows its open and overdue counts, a small cleanliness percentage and *Last cleaned*
- [ ] No view touched by this plan is longer than before; the participation flow exists once
- [ ] Every user-visible string goes through `t()`; `l10n:check-keys` is green with real German copy
- [ ] `npm run test:runtime:check` reports `current` at the freeze
- [ ] Operator GUI review recorded; its findings resolved or routed
- [ ] `0.8.0-alpha.5` qualified: full browser gates and PHPUnit suites on both runtime rows, SQLite and MySQL provider suites, reproducible package build and smoke
- [ ] Deployed through the private exchange; the operator's sanitized report verified and imported into the deployment guide
- [ ] `CHANGELOG.md`, README, roadmap and documentation map name the new version; screenshots recaptured
- [ ] Build gate green (`npm test` + lint + backend tests)
- [ ] Required tests ran without new or unapproved skips
- [ ] All implementation decisions and review questions resolved and recorded
- [ ] All plan-owned review findings (§9) resolved or user-rescoped to `backlog.md`
- [ ] Owning backlog items updated or archived according to their actual completion state
- [ ] Durable concepts/decisions integrated into the permanent docs (`docs/concepts/`, `docs/adr/`, `docs/engineering/`, `docs/api/`, `docs/standards/`) — plans are temporary
- [ ] Final Full review is `PASS` or `PASS-WITH-FIXES` (the round adds a command and changes authorization for children)
- [ ] `docs/backlog/ACTIVE_TASKS.md` updated

## 9. Review Findings (plan-owned)

Findings **within this plan's scope** from implementation rounds or reviews
(scope-first routing per `project-rules.md` §4). Resolve before `done/`;
rescoping to `backlog.md` needs explicit user approval. Out-of-scope findings
go directly to `backlog.md` (or fold into an existing item) instead.

- [x] RF-090-01 (major) - WP-A, D-090-07: the retained baseline already holds `cancelled` rows with another meaning (pending requests a departure cancelled in place), and the new governing rule would reread them without the plan saying so or testing it. Resolved by the operator's D-090-21 (one rule for all), with projection, integration and upgrade tests, a risk row and an acceptance criterion.
- [x] RF-090-02 (minor) - WP-A: deleting a pending request or an absence that has ended was undefined, and deleting an ended one would have hidden the record of an excuse that stays. Resolved by D-090-22 (only absences that have not ended; a pending request is withdrawn), with service tests and an acceptance criterion.
- [x] RF-090-03 (major) - WP-F, tests: "populated upgrade" named no harness. The preservation runner proves same-build recreation (P1) and Nextcloud runtime updates (P2), not an app update that runs a new step, which the deployment guide requires. The plan now names a real app-upgrade proof from `0.8.0-alpha.4` on all three providers, with pause rows in the populated state, plus a PHPUnit test of the step; manifest and `testing.md` added.
- [x] RF-090-04 (minor) - WP-F, acceptance criteria: pinning the 27th step after the deployment and the guide's *expected data change* paragraph were missing from the criteria and the WP. Added.
- [x] RF-090-05 (minor) - Coordination with CS-PLAN-080: its open real-device checklist (rows D1, D6, D8) and `mobile-core.spec.ts` name screens this release removes. A risk row now keeps the run and the closure with CS-PLAN-080 and the updated rows with this plan.
- [x] RF-090-06 (minor) - Tests: several affected browser specs were not named (`mobile-core`, `scheduling`, `scheduling-apply-flow`, `3-floor-house`, `core-household-journey`, `layout-clearance`, `color-contrast`, `multi-user-interactions`). The shared inventory now names them and routes each to the package that removes its page.
- [x] RF-090-07 (nit) - D-090-10 and WP-A: D-089-09 also covered the cleanliness route, and the digest for a deleted household absence was unstated. Corrected.
- [x] RF-090-08 (minor) - WP-A, `PauseService::listForViewer()`/`delete()`, `AbsenceView.vue`: a child's shortening declined before the update (`acknowledged` r1, `rejected` r2) left the absence in force, but the list returned r2, the view hid it from everyone and deletion answered 409, against D-090-22. Separately, the view offered deletion on a pending shortening of an ended absence, which the server refuses (D-090-25). Fixed in the interim review by D-090-27: the standing revision and the server's `deletable`, with unit, integration and Jest cases, ADR-020 and the API reference.
- [x] RF-090-09 (nit) - WP-A, `AbsenceView.vue`: a third copy of the day-key label (with `TodayView.vue` and `SchedulePlanner.vue`). Fixed in the interim review (operator's choice): one `formatDayKey()` in `src/utils/dateKeys.js` with its spec, used by all three.
- [x] RF-090-10 (nit) - WP-A, `src/store/pauses.js`: the `pendingRequests` getter had no reader left; the plan says the store loses its unused getters. Removed with its spec cases.
- [x] RF-090-11 (minor) - WP-A, `tests/e2e/layout-clearance.spec.ts`: the browser gate failed twice (desktop, phone). Its reachability probe scrolled the last Settings section with `scrollIntoViewIfNeeded()`, which worked only while a member's Settings ended with the short pause section; the notification preferences are taller than the frame. The routed-view list also lacked `#/absence`. Fixed in the interim review: the probe aligns the section's end with the scroller, which a clipped frame (BUG-UX-009) would still fail, and the Absence view joins the heading, gutter and sideways-scroll checks.
- [ ] RF-090-12 (minor) - WP-G, runtime currency: `npm run test:runtime:check` reports `update-needed` on 2026-11-11, and the scheduled check on `master` has been red since 2026-11-09. Upstream AIO is 15.0.2 and ships Nextcloud 35.0.2 and Redis 8.10.3; the pinned PHP and PostgreSQL image tags moved too. The inventory names AIO 14.4.3 with Nextcloud 34.0.5 and Redis 8.10.2. Found while implementing WP-F. The reviewed inventory update and the requalification belong before the freeze (§7 and the acceptance criterion); which AIO version production runs at the deployment is the operator's to state.
- [x] RF-090-13 (minor) - WP-F, `src/utils/effectGates.js`, `src/composables/useGameEffects.js`: the effect gates read the store's initial `animationEnabled: true` before `/preferences/me` had answered, so a member who had switched the animation off saw confetti when completing a task while the read was pending or after it failed; before WP-F an unknown state meant off. Fixed in the interim review (operator's choice, D-090-32): `animationAllowed()` opens the animation only after an authoritative read, used by the celebration gate and the arcade's pulses, with Jest cases for the unread state; `calm-core-ux.md` and the gamification skill say so.

## Review Outcome

**Standard A, 2026-11-10: PASS-WITH-FIXES.** Scope: this plan, checked against the owning
backlog items, the active CS-PLAN-080, the pause, preference, activity and routing sources, every
test file the plan names, the browser files that cross a moved surface, and the deployment,
testing and retention rules. No subagents and no implementation edits.

- Base HEAD `9872cbf9af1bcfc677290a93499df69b6719d50d`, branch `docs/release-a-plan`, pinned
  worktree. Capture: empty index; unstaged drafts in seven files (binary diff SHA-256 prefix
  `7672362ef4cccd86`); one untracked path, this plan, SHA-256
  `40d9b46a894b3e98edcea09aadf04abf54c4f4ca61a78e0f0bcb33e2c4fcb296`, a regular file. The
  destination was absent. No overlap with CS-PLAN-080: it keeps the human evidence, the
  real-device run and its closure (RF-090-05).
- RF-090-01..07 resolved at plan level (§9); these are plan corrections, not delivered fixes.
  Every work package has an owning backlog ID.
- Decisions: the operator answered both review questions with the recommended option on
  2026-11-10 (D-090-21, D-090-22). No question is open.
- Gates: Jest 162 suites / 2302 tests, `npm run lint`, `backlog:check`, `docs:encoding`,
  `agents:check`, `git diff --check`. Documentation only, so Docker backend, browser, build
  and package gates were not required and did not run; they stay binding for the implementation.
- Transition: `new/` to `active/`; FEAT-UX-062..067 and FEAT-UX-054 are `In Progress`,
  RELEASE-002 assigns this plan its alpha.5 slice. Record:
  [2026-11-10 review](../../backlog/archive/CODE_REVIEW_2026-11-10.md#standard-a---cs-plan-090-simplified-core-release-a).
- Finalization is one scoped review commit; no merge, push, tag or deployment.

**Standard B, interim (WP-A), 2026-11-10: PASS-WITH-FIXES.** Scope: the uncommitted WP-A change
set of this worktree (`/review-chorestrike` without arguments; the main checkout was clean). No
subagents.

- Base HEAD `c34f6e3349a8200427da7b15447e79ebaa39dcf4`, branch `docs/release-a-plan`, pinned,
  0 behind `master`. Capture: two staged deletions, 57 modified tracked files, five untracked
  paths; scope fingerprint (staged and unstaged binary diffs, sorted untracked paths with SHA-256)
  `4debce0ecf331e27545697e7523749a80471d9d5906038d905bbeb4f2d851e9c`.
- RF-090-08..11 fixed in the review (§9). The operator chose the recommended option on both
  questions: fix RF-090-08 now (D-090-27), extract the day label now (RF-090-09). D-090-23 to
  D-090-26 bind with this review. One unattributed `mobile-webkit` timing failure of the first
  browser run is out of scope and folded into TECH-TRANSPORT-001.
- Gates on the final tree: Jest 162 suites / 2319 tests, `npm run lint`, stylelint,
  `l10n:check-keys`, `backlog:check`, `docs:encoding`, `agents:check`, `git diff --check`,
  `composer cs:check`, `npm run build`; PHPUnit unit 1590 / integration 691 on the canonical
  PostgreSQL stack and, in matrix run `cs090-wpa-r1` with `--fail-on-skipped`, on MySQL, SQLite
  and the AIO row; browser gate `run-G53HP8` on the primary row (361 tests, five projects) and
  `run-BjeJTR` on the AIO row (the same 361), both without skipped, failed or flaky results.
- No transition: WP-B to WP-G are open. Record:
  [2026-11-10 review](../../backlog/archive/CODE_REVIEW_2026-11-10.md#standard-b---cs-plan-090-wp-a-absence-interim).
  One scoped review commit; no merge, push, tag or deployment.

**Standard B, interim (WP-F), 2026-11-11: PASS-WITH-FIXES.** Scope: the uncommitted WP-F change
set of this worktree (`/review-chorestrike` without arguments; the main checkout was clean). No
subagents.

- Base HEAD `3ed38cf6ff41f3b874dfc97d7982f94b302f4dcf`, branch `docs/release-a-plan`, pinned,
  0 behind `master`. Capture: empty index, 50 modified tracked files, five untracked paths;
  scope fingerprint (staged and unstaged binary diffs, sorted untracked paths with their Git
  object hashes) `8fca7b237af0195bfe93b7c8cb7347ff2083ef3c5461ed3fe101e067726e007d`.
- RF-090-13 fixed in the review (§9); the operator chose the recommended option (D-090-32).
  D-090-28 to D-090-31 bind with this review. Safe fixes: the README, the gamification skill and
  the user guide on the new defaults, the `HACK` marker of the P4 link (TECH-TEST-012), and the
  stale `css/` entry of the P3 archive list.
- Gates on the final tree: Jest 162 suites / 2335 tests, `npm run lint`, stylelint,
  `l10n:check-keys`, `backlog:check`, `docs:encoding`, `agents:check`, `git diff --check`,
  `composer cs:check`, `npm run build`, the runner's `-SelfTest`; PHPUnit unit 1600 /
  integration 698 with `--fail-on-skipped` on the canonical PostgreSQL stack and, in matrix run
  `cs090-wpf-r1`, on MariaDB, SQLite and the AIO row; P4 `e090f001`..`e090f004` on PostgreSQL,
  MariaDB, SQLite and the AIO row; P3 `e090f007`; browser gate `run-1GWdf0` on the primary row
  and `run-b3hlTW` on the AIO row, 361 tests each without skipped, failed or flaky results.
- No transition: WP-B to WP-E and WP-G are open, and so is RF-090-12. Record:
  [2026-11-11 review](../../backlog/archive/CODE_REVIEW_2026-11-11.md#standard-b---cs-plan-090-wp-f-feedback-interim).
  One scoped review commit; no merge, push, tag or deployment.
