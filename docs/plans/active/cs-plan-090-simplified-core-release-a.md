# CS-PLAN-090: 0.8.0-alpha.5 — Simplified Core, Release A (Screens, Absence, Feedback)

> **Status:** Active - Standard A `PASS-WITH-FIXES` 2026-11-10; WP-A implemented, interim Standard B `PASS-WITH-FIXES` 2026-11-10; WP-F implemented, interim Standard B `PASS-WITH-FIXES` 2026-11-11; WP-B implemented, interim Standard B `PASS-WITH-FIXES` 2026-11-11; WP-C implemented 2026-11-11, WP-D and WP-E 2026-11-12, one interim Standard B `PASS-WITH-FIXES` for the three 2026-11-12 (operator's choice); the operator's GUI review without findings 2026-11-12; runtime update (RF-090-12) requalified 2026-11-13; scope extended by WP-H to WP-K 2026-11-13 (D-090-60), Standard A of the extension `PASS-WITH-FIXES` the same day; WP-H and WP-I implemented 2026-11-13, one interim Standard B `PASS-WITH-FIXES` for the two 2026-11-13 (operator's choice); WP-J implemented, interim Standard B `PASS-WITH-FIXES` 2026-11-14; WP-K implemented 2026-11-14, interim Standard B `PASS` 2026-11-15; WP-G: candidate committed 2026-11-15 (metadata, changelog, README, screenshots); the operator's second GUI review without findings 2026-11-15; final Full review `PASS-WITH-FIXES` 2026-11-15; qualification and deployment open
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

Positions taken while implementing WP-B (2026-11-11). They bind once its interim review passes.

- **D-090-33 Floor list and room cards go with the Dashboard:** `FloorList.vue` and
  `RoomCard.vue` had no other host, so they leave in WP-B, not in WP-C. Until WP-C groups the
  tasks by room, the interface has no link to a room page; it opens by its address
  (`/room/:id`). The browser specs reach a room through one helper
  (`tests/e2e/fixtures/rooms.ts`), which WP-C retargets (RF-090-14).
- **D-090-34 The status line is about the viewer:** it counts the viewer's own open work, due
  today or overdue, unassigned work included, against the viewer's own energy for the day
  (new store getter `getOwnCapacityForDay`), whatever the list filter shows. The overload note
  uses the same two numbers. Before, it added up the listed rows, everyone's under *Everyone*,
  and compared them with the household's capacity once an owner had opened the planner. A
  value no read has delivered shows a dash.
- **D-090-35 The overdue block:** heading *Overdue · n*; the sentence that completing covers
  the miss stands once in the block, not on every row; a row keeps its planned date and the
  end of its window and adds the days since that window ended. The glossary keeps *Catch-up*
  until Release B removes catch-up.
- **D-090-36 Holding a completed row (refines D-090-30):** a row cannot keep itself in a list
  its parent renders. The view remembers where a row stood when its completion started and
  keeps it there, and nowhere else, while the store marks it in flight or just completed;
  `TodayTaskRow` shows the check mark for that moment. The section rules are pure functions
  (`src/utils/todaySections.js`).
- **D-090-37 The Done button on Today:** the round check icon of the mockups, a `HoldButton`
  without a label whose accessible name names the task. Task lists keep the labelled button.
- **D-090-38 What loses its client:** `GET /house/health` joins the routes of D-090-10. In the
  client, the health refresh and getter of the house store, the floor-expansion state and the
  average-cleanliness getter of the rooms store, the name-based room icon and the unused
  `/today?scope=all` handling go with their last reader. Settings keeps its own expansion
  state; WP-C keeps a room's in the view and the URL.
- **D-090-39 The way into the setup wizard:** one small component, `SetupWizardEntry`, on Today
  (it opens the wizard by itself for a house that was never set up) and in the empty state of
  *Floors & Rooms* in Settings. It shows only after the floors were read, so a failed read
  never offers a setup.
- **D-090-40 README and screenshots:** the Dashboard screenshot goes with its scene and its
  README cell, and the README and the user guide name Today. The remaining screenshots show
  the screens of `0.8.0-alpha.4` until WP-G recaptures them.

Positions taken while implementing WP-C (2026-11-11). They bind once its interim review passes.

- **D-090-41 A grouped list shows everyone:** *By room* and *By person* start with everyone's
  tasks, by the switch and by a link alike, unless the link names a `scope`; the old room page
  showed every task of its room. The filters stay changeable afterwards and apply to every
  grouping. One group is open at a time. The open room is part of the address (`?room=`), the
  open person is not.
- **D-090-42 By due date:** it stays the one paged list. Its order is now the due class first
  (missed, due today, later, finished) and the due date within a class, with a heading per
  class that counts all pages. Before, a task planned earlier whose window was still open stood
  above missed work.
- **D-090-43 Which groups exist:** every room is a group, also one without tasks, so *New*
  reaches it; the floor and room filters narrow the rooms shown. A person is a group only with
  tasks, the unassigned tasks come last, and a shared task stands under each of its assignees.
- **D-090-44 What goes with the room page:** its statistics card (the route stays without a
  client, D-090-10), the large cleanliness ring, the back link, and rename and delete in its
  header, which Settings offers (FEAT-UX-049). The store's statistics read goes with its last
  reader. A room's rows are the compact rows of the task list: the description shows in the
  expanded row.
- **D-090-45 Creating and dating a task:** one dialog, `TaskCreateDialog`, serves *New Task* of
  the page (the form, prefilled from the filters) and *New* of a room heading (that room's
  templates, the custom form one step behind them). *Date* in the expanded row is offered to
  those who may manage tasks, on open work. After that dialog saved, the room facts are read
  again, because *Last done* sets the *Last cleaned* of the heading.
- **D-090-46 Units beyond the manifest:** the filter rules (`src/utils/taskFilters.js`), the
  create dialog and the pager (`TaskPager.vue`) left the view, and its switch is the shared
  `SegmentedSwitch.vue` (D-090-56). The view ends at 665 lines (660 before WP-C, 743 before
  this plan); the category filter of WP-I takes it to 672.

Positions taken while implementing WP-D and WP-E (2026-11-11 and 2026-11-12). They bind once their interim
review passes. The operator chose to build both on the uncommitted WP-C and to review the three
together.

- **D-090-47 Structure of Settings (operator, 2026-11-11):** a switch *Personal | Household*
  and below it categories that all start closed; several may be open at once. Shown to the
  operator as a text sketch before building, which is the "mockup first" FEAT-UX-054 asks for.
  Personal: *Your Title*, *Notifications & feedback*, *Planning*, and the link *My Requests*.
  Household: *House*, *Members*, *Floors & Rooms*, *Rewards*, *Arcade*, *Danger Zone*.
- **D-090-48 Categories and addresses:** a category mounts when it opens and reads its own
  data then, so a closed page reads nothing. `/settings?category=<id>` opens a category in its
  area, and the address names the category opened last, so a reload or a link comes back to
  it. A member has no household area, also by a link. The help has no links into Settings
  today, so nothing else needed a target.
- **D-090-49 Planning:** the own days and the own energy move to *Settings → Planning*; they
  are no longer on the weekly plan's page. `PlanningSettings` reads the preferences once and
  offers a retry in place; a failed save is reported at its form with the entries kept, not
  by a toast and never by replacing the page. The weekly plan's page keeps the overload
  warning, the owner's member grid, the planner and the plan calendar; every role reaches it
  from the calendar header (*Weekly plan*) and gets a link back.
- **D-090-50 My requests:** no navigation entry. Rewards keeps its link, and the personal
  settings always show one, for every role and with either catalog gate closed.
- **D-090-51 Navigation order:** Today, Tasks, Calendar, Insights, Absence, Rewards, Arcade,
  Settings, then Help, as in the mockup.
- **D-090-52 Key figures:** house health, clean rooms of all rooms, dirty rooms and rooms
  that need cleaning, from the one house read Insights already makes; house health stays in
  Insights (D-090-11). The mockup shows task figures (done, on time, open, overdue); a
  household read for the on-time share does not exist in this release, so these four wait for
  the GUI review to confirm or for Release B to supply the data. The operator's second GUI
  review kept the house figures (2026-11-15).
- **D-090-53 Activity line:** time and day are the household's. The coins are what the
  completion paid to everyone together; an undone completion, one that paid nothing and work
  the viewer may not see show none. Every line opens on a tap, one at a time: room, frozen
  effort, who did how much, and for the completer or an owner *Correct split* and *Undo*. A
  refresh keeps the list and the open line in place.
- **D-090-54 Ranking:** a section of Insights with the anchor `#ranking`; `/leaderboard`
  redirects to it, and the view brings it into view, because hash routing leaves the anchor
  to the page (RF-090-24). `RankingPanel` has a loading and an error state of its own: a failed read
  showed "ranking is off" on the old page.
- **D-090-55 Fairness bars:** one line per member with the bar of completed energy against
  the weekly capacity; contribution and streak stay as small facts on the line.
- **D-090-56 Units beyond the manifest:** `Common/SegmentedSwitch.vue` (the switch of Tasks
  and of Settings), `Settings/SettingsCategory.vue`, `TitleSettings.vue`, `HouseSettings.vue`,
  `StructureSettings.vue`, `DangerZoneSettings.vue` and `PlanningSettings.vue`, their shared
  form styles `src/styles/settings.css` (scoped by `.cs-settings-form`, so they reach no
  component with styles of its own), `Tasks/CalendarControls.vue` and
  `Insights/CorrectionReview.vue`. With them `Settings.vue` has 307 lines (1502 before this
  plan), `InsightsView.vue` 545 (615; 528 before the ranking anchor of the interim review) and `CalendarView.vue` 282 (354).

- **WP-H and WP-I (2026-11-13), implemented; interim Standard B `PASS-WITH-FIXES` the same day
  (see the Review Outcome):** the Rewards list
  waits for the wallet read; test autoload works on a case-sensitive file system (control and
  fix run on a Linux copy: integration 698 and unit 1600 pass, the old map exits 0 without a
  report), and the CI jobs check their JUnit reports; the dependency slice
  (`fast-xml-parser` 5.12.1 resolves its advisory; `npm audit` keeps only the four bounded
  advisories of TECH-DEPS-001). Task categories: `TaskCategoryService` and the thin
  `TaskCategoryController`, the form, the filter, the household settings category, the in-app
  glossary, the API reference, the task-model concept and the user guide. A running stack
  answers 404 for a new route until Apache restarts (APCu route cache); the `docker-dev` skill
  names the step. Gates on that tree: Jest 171 suites / 2391 tests, lint, stylelint, build,
  `composer cs:check`, `l10n:check-keys`; PHPUnit unit 1636 / integration 700 on the canonical
  PostgreSQL stack; the new browser scenario and the Settings category scenario on the
  canonical stack.

- **Operator GUI review (2026-11-12):** the operator checked the screens of this release on the
  development stack (family profile, the tree of the WP-C to WP-E review commit) and reported no
  findings.

Decisions and positions of the runtime update before the freeze (2026-11-12, RF-090-12).

- **D-090-57 Runtime of the release (operator, 2026-11-12):** `0.8.0-alpha.5` is installed on
  AIO 15.0.2 with Nextcloud 35.0.2. By the operator's statement the production already runs
  that AIO with `0.8.0-alpha.4`, so the major update from Nextcloud 34.0.5 has happened there
  and needs no proof of its own; the sanitized deployment report confirms the installed
  identity as usual. The inventory follows: the primary row moves to Nextcloud 35.0.3, the AIO
  row to 35.0.2 with 35.0.1 as its update source and Redis 8.10.3.
- **D-090-58 Lower bound (operator, 2026-11-12):** no row tests Nextcloud 34 any more, so
  `0.8.0-alpha.5` declares Nextcloud 35 only. `min-version` in `appinfo/info.xml`, the README
  badge and requirement, the prerequisites of the deployment guide and the development guide
  change in the metadata step of WP-G.
- **D-090-59 Two rows on one patch:** the AIO row's release is now the primary row's update
  source, so an image name built from the version alone would stand for two identities. The
  local image of the AIO row carries `-aio` in its name (`runtimeImageName()`, printed by
  `test-runtime.js selection`), and the target check picks the row by the image label, not by
  the first row with that version.

Decisions and positions of the scope extension (2026-11-13). The deployment takes the operator
time, so the release carries as much as can be built without the model change of Release B.

- **D-090-60 Scope extension (operator, 2026-11-13):** four packages join this release. WP-H
  takes three small items (TECH-TEST-013, TECH-TEST-012 and the in-range slice of
  TECH-DEPS-001). WP-I takes FEAT-UX-053, WP-J FEAT-UX-056 and WP-K FEAT-UX-057. FEAT-UX-055
  stays out because it needs a schema step, FEAT-UX-058 because it depends on FEAT-UX-053 and
  on the occurrence history that Release B changes, and every Release B topic stays out. The
  extension gets a Standard A of its own, limited to these packages, before their code. WP-H
  started before it, because its items carry no design question.
- **D-090-61 Colour scheme per person (operator, 2026-11-13):** each person picks a scheme
  under *Personal*, and the choice follows them to every device.
- **D-090-62 Accent only (operator, 2026-11-13):** a scheme changes the accent colours inside the
  app: buttons, highlights, progress, energy and celebration. Backgrounds and text stay with
  Nextcloud's light or dark theme. *Standard* keeps the colour of the Nextcloud instance.
- **D-090-63 Arcade reset (operator, 2026-11-13):** an owner resets one person's progress for all
  games at once, after a confirmation. From then on only chores finished after the reset count,
  so locked games unlock anew. Highscores stay (D-089-05).
- **D-090-64 No schema step for the extension:** the three features store settings, not facts.
  The household's task categories and arcade thresholds live in typed app configuration (one
  house per instance, the ADR-019 pattern). A person's colour scheme and arcade start point live
  in Nextcloud's per-user app configuration (`IUserConfig`). Nextcloud deletes those values with
  the account. Account erasure also deletes them explicitly, and the test-data reset clears every
  value. An instance that has never stored a value reads the defaults, so retained installs need
  no migration. The acceptance criterion "no table or column definition changes" holds.
- **D-090-65 Task categories:** the list starts as the six values the form offers today
  (`cleaning`, `kitchen`, `bathroom`, `organizing`, `maintenance`, `other`), so retained tasks
  keep their meaning without a write. Keys are stable. The labels of the six are translated
  until an owner renames one. An owner adds, renames, reorders and deletes categories, with at
  most 20 and labels of 1 to 40 characters, unique regardless of case. The server generates the
  key of a new category, short enough for the 50 characters of `tasks.category`. *Other* stays
  last and can be neither deleted nor renamed. The list carries a revision, and a save against
  an older one answers 409. The comparison and the write run under the house-row lock against
  a value read past the request cache of `IAppConfig`, so two concurrent saves cannot both
  pass. New tasks default to *Other*; a template or the wizard keeps its `cleaning`
  while that key exists. Task writes accept only keys of the current list. A task whose stored
  key was deleted reads and filters as *Other*, and nothing rewrites it until someone saves that
  task. The filter is one more select in the panel of the task list (`?category=`), and the row
  shows no category.
- **D-090-66 Arcade thresholds and reset:** the constant map of `ArcadeCatalog` stays the default.
  An owner overrides it per game with a whole number from 0 to 500. The start point of a reset
  is the highest committed completion id at the moment of the reset. The reset holds the
  house-row lock like the score lock, so a score save and a reset never interleave. A chore
  whose transaction overlaps the reset may land on either side of the start point, and none
  counts twice. The count then takes only completions above the start point, so an undo or a
  correction of earlier work changes nothing. The count keeps one grouped query for several
  wallets, with each wallet's own lower bound, so the owner's progress list stays one read. The owner sees each member's count since their
  last reset, which the activity list already shows in another form. ADR-019 is amended: the
  thresholds become a setting, an unlock lasts until an owner resets the person, and the
  storage statement changes. It also corrects the name of the count method
  (`countValidSelectedByWallet()`).
- **D-090-67 Colour schemes:** *Standard* plus five schemes (working names Ocean, Forest, Sunset,
  Berry, Slate), each with a light and a dark variant keyed to Nextcloud's theme attributes like
  `tokens.css`. The attribute sits on `body`, because the app's dialogs are teleported there
  (`Modal.vue`, `SetupWizardEntry.vue`). The overrides of Nextcloud's primary-element variables
  and the app's accent tokens are scoped to the app content and those teleported roots, so the
  Nextcloud header keeps its colour. Under Nextcloud's high-contrast themes every scheme yields
  to *Standard*, because a person chose those themes for contrast. The page delivers the scheme
  as initial state, read with `@nextcloud/initial-state`, a new dependency from Nextcloud's own
  libraries, so the first paint already has it.
  Colour literals outside `tokens.css` move to tokens first, in 18 files. The arcade boards and
  `confetti.js` keep their own game palette. Contrast is proven in two ways. Jest computes
  WCAG ratios for every scheme, theme and token pair: 4.5:1 for text on the accent, 3:1 for the
  accent against the background. The browser contrast spec samples two views per scheme and
  theme, and the full view set on *Standard*.

Positions taken while implementing WP-K (2026-11-14). They bind once its interim review passes.

- **D-090-68 Contrast target:** the accent reaches 4.5:1 against Nextcloud's main, hover and
  dark background in both themes, not only 3:1, because the app paints text with
  `--color-primary-element` (links, active labels, the selected filter). The tint text on the
  tint and the energy value reach 4.5:1 too; text on the accent and on `--color-primary` as
  planned. `tests/unit/css/colorSchemes.spec.js` computes all of it from `schemes.css`.
- **D-090-69 The high-contrast rule lives in the client:** `applyColorScheme()` sets no
  attribute under `data-theme-light-highcontrast` (Nextcloud's id of the light high-contrast
  theme), `data-theme-dark-highcontrast`, or `data-theme-default` while the system asks for
  more contrast, where Nextcloud applies its high-contrast variables by media query. A change
  of that system preference applies at once. The stored choice stays and returns with another
  theme.
- **D-090-70 Storage and saving:** *Standard* is stored as no value and an unknown stored value
  reads as *Standard*; the key is not lazy, because every page load reads it. The scheme is no
  field of the versioned preference row: no command id and no version, the last save wins. The
  store recolours at once, restores the previous scheme when a save fails, lets the later of
  two quick saves win and drops the scheme of a read that started before a save
  (client-state contract).
- **D-090-71 What a scheme sets:** Nextcloud's thirteen primary variables, the two invert
  filters and the primary gradient (Nextcloud resolves its aliases at `:root`, so all of them
  are set again), `--cs-energy`, `--cs-celebrate-primary` and `--cs-celebrate-accent`. The
  confetti reads its palette on the app root. `--color-primary` stays theme-independent with
  white text, as Nextcloud has it.
- **D-090-72 The literals:** fourteen component files; the plan's 18 counted the two arcade
  boards, `board.js` and `confetti.js`, which keep the game palette. Status and accent tints
  became `color-mix()` of Nextcloud's element colours, the dialog shadow and backdrop and the
  medal gradients became tokens, and white text on `--color-primary` became
  `--color-primary-text`. The ranking row's highlight used `--color-primary-rgb`, which
  Nextcloud 35 does not define, so it showed no highlight; it now uses
  `--color-primary-element-light`. The guard covers hex, `rgb()`/`hsl()` and the names
  `white`/`black` in style declarations.
- **D-090-73 Placement and swatches:** *Appearance* sits between *Notifications & feedback*
  and *Planning*. Each swatch carries `data-cs-swatch`, so it shows its scheme's accent in the
  current theme whatever scheme is active; *Standard* shows the instance accent, saved on
  `<body>` where no scheme reaches. The German working names (Ozean, Wald, Abendrot, Beere,
  Schiefer) went to the second GUI review, which kept them (2026-11-15).
- **D-090-74 Units beyond the manifest:** `src/utils/colorSchemes.js` (the list, the initial
  state, the high-contrast rule, the attribute), `tests/unit/css/colorSchemes.spec.js`,
  `tests/e2e/color-schemes.spec.ts` and `tests/e2e/fixtures/color-scheme.ts`. The Jest mapping
  of `@nextcloud/initial-state` was a stub with a default export only; it re-exports the real
  `loadState` now. `Settings.vue` grows from 314 to 323 lines.

Positions taken while preparing the candidate (WP-G, 2026-11-15). They bind once the final review
passes.

- **D-090-75 What names the version when:** the candidate step changes the app metadata, the
  README (badges, caption, requirement, status), the changelog and the plan index. The roadmap,
  the documentation map, the installed build in the deployment guide and the release test plan
  state what is installed, so they change with the verified deployment record, as in
  CS-PLAN-089.
- **D-090-76 Shape of the changelog:** one candidate section under *Unreleased*. The three
  sections *after 0.8.0-alpha.4* stay below it and ship with it (D-090-06). All of it gets the
  dated version heading with the deployment record.
- **D-090-77 Screenshots:** the seven scenes stay and are recaptured from the candidate tree.
  New scenes for the list by room, Absence or Insights are not part of this release.
- **D-090-78 Dependabot PR #12:** `follow-redirects` 1.16.3 joins the lockfile as an in-range
  patch of WP-H's kind. It carries no advisory and is not in the browser bundle; taking it lets
  the push close the PR instead of carrying it into the next round.

Decisions of the qualification (operator, 2026-11-15).

- **D-090-79 Build toolchain minimum Node 24.15.0 (RF-090-58):** the package builder and CI
  stay on the exact minimum, which rises from 24.14.0 to 24.15.0 (npm 11.12.1) together with
  `engines.node`, because the locked test tooling needs it. Not the newest patch, and no
  downgrade of `@vue/test-utils`.
- **D-090-80 Requalification after RF-090-58:** that commit changes no app code (`lib/`,
  `src/`, `js/` byte-identical), so on it only the static gates and the package build and
  smoke run again; the matrix and both browser gates count from `3031d686`.

The four questions of the draft were answered by the operator on 2026-11-10 (D-090-16 to
D-090-19), and the two of the Standard A review the same day (D-090-21, D-090-22). The three
of the runtime update were answered on 2026-11-12 (D-090-57, D-090-58), and the five of the
the six of the scope extension on 2026-11-13 (D-090-60 to D-090-63). No question is open.

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
  view) and the section rules (`todaySections.js`). A just-completed row keeps its place for
  the length of the completion feedback, so a recurring row shows it before it leaves the
  list (D-090-30, D-090-36).
- `Dashboard.vue`, `QuickStats.vue`, `HouseHealthBar.vue` and `HouseholdGoalBar.vue` go, and
  with them `FloorList.vue` and `RoomCard.vue` (D-090-33); `/dashboard` redirects to `today`.
  `HouseStatsCards` keeps showing house health in Insights.
- The setup wizard opens from Today for an owner with an empty house, with the existing
  dismissal rule, and from *Floors & Rooms* in Settings.

### WP-C Tasks by due date, room or person (FEAT-UX-063)

- New `src/utils/taskGrouping.js`: pure functions that group the filtered rows by due class, by
  floor and room, or by person, with open and overdue counts per group.
- `TaskManager.vue` gains the switch *By due date · By room · By person*, mirrored in the URL as
  `?group=`. Grouped by room, floors are headings and rooms are collapsible rows with their
  counts, a small cleanliness percentage (D-090-19) and *Last cleaned*; `?room=` opens one room. The pager stays for *By due date* only,
  because grouped views render a room's rows when it opens.
- `/room/:id` redirects to `/tasks?group=room&room=:id`. `RoomDetail.vue` goes (`FloorList.vue`
  and `RoomCard.vue` went with the Dashboard, D-090-33); rename and delete stay in Settings,
  where they already are. The browser helper `fixtures/rooms.ts` opens a room in the grouped
  list from then on (RF-090-14).
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

### WP-H Release hardening (TECH-TEST-013, TECH-TEST-012, TECH-DEPS-001 slice)

- **Rewards:** the catalog list renders only after the wallet read has ended, whether it
  succeeded or failed. Before, the wallet banner could arrive after the list and push the
  *Request* buttons under a tap. The request buttons also appear only once it is known whether
  a funding hold applies.
- **Case-sensitive file systems:** `composer.json` maps the lower-case test directories
  (`tests/unit`, `tests/integration`, `tests/fixtures`) to their namespaces, and the one file
  with a lower-case namespace is corrected. The `tests/Integration` link of proof P4 goes with
  its `HACK` marker. `run-tests.js --check-junit` judges a report by the matrix rule: a missing
  report, zero tests, a failure, an error or a skip fails it. The PHPUnit jobs of
  `release-gate.yml` and `test.yml` write a report and run that check, so a suite that loads no
  test can no longer pass.
- **Dependencies:** updates inside the declared ranges (Vue, Sass, the Babel patches) and the
  `fast-xml-parser` advisory, which `npm audit fix` resolves without a major. `lucide-vue-next`
  and `ajv` have no importer and are removed after a peer check. `dependabot.yml` limits the
  `npm-all` group to minor and patch updates. The rows of TECH-DEPS-001 that move a major or the
  gate itself stay there: `@nextcloud/vue` 9.14 with dialogs, Playwright, Pinia 4, Vue Router 5,
  Jest 30 and the rest.

### WP-I Task categories (FEAT-UX-053)

- `TaskCategoryService` reads and saves the list (D-090-65) in typed app configuration, owner-only
  with the revision check, and with an audit entry. `HouseController` adds `taskCategories` to
  the house payload. `PUT /api/v1/house/task-categories` lives in a thin
  `TaskCategoryController`, because `HouseController` already exceeds the file-size guideline.
- `TaskService` and `TaskCreationService` accept only keys of the current list. The server
  default becomes `other`, and a template or the wizard keeps its key while it exists.
- Frontend: a household settings category *Task categories* (`TaskCategorySettings.vue`), the
  select of `TaskEditForm` from the list, the category select in `TaskFilterBar` with
  `?category=` in `taskFilters.js`, and one resolver that reads unknown keys as *Other*.

### WP-J Arcade owner controls (FEAT-UX-056)

- `ArcadeAccessService` reads the thresholds from typed app configuration over the defaults of
  `ArcadeCatalog`, and counts only completions above a member's start point (D-090-66).
  `WorkShareMapper::countValidSelectedByWallet()` takes a lower bound per wallet in its grouped
  query.
- Owner-only commands, each audited: `PUT /api/v1/house/arcade-thresholds` (every game, 0 to
  500), `POST /api/v1/house/members/{userId}/arcade-reset` (under the house-row lock), and the
  read `GET /api/v1/house/arcade-progress` (each member's count and the time of their last
  reset). The score lock keeps its order and uses the same count.
- `AccountErasureService` deletes the start point, and `TestDataService` clears thresholds and
  start points.
- Frontend: the *Arcade* settings category gets `ArcadeThresholdSettings.vue` and
  `ArcadeResetList.vue` beside the gate switch, with the confirmation dialog. The catalog
  already shows the threshold the server sends.
- ADR-019 amended (D-090-66) before code.
- **Implemented 2026-11-14.** The three routes live in a thin `ArcadeSettingsController`,
  because `MinigameController` would pass the file-size guideline. `ArcadeStartPointStore` owns
  the per-user key (`arcade_reset`, lazy, the start point with the time of the reset) for the
  access service, account erasure and the test reset. It reads past the config cache with
  `getValuesByUsers()`, so the score lock under the house-row lock sees a reset that committed
  before it. The threshold payload names every game. The progress list is sorted by name, never
  by contribution (ADR-019 decision 7). The category is one component, `ArcadeSettings.vue`, so
  `Settings.vue` shrinks from 316 to 314 lines. A threshold of 1 reads in the singular on the
  card. A catalog that arrives during an edit moves only the untouched fields, and the store
  drops a catalog or progress read that a newer read or write overtook (client-state contract).
  The in-app help, the user guide, the API reference, `ui-use-cases.md` and
  `gamification-and-rewards.md` name the controls.
  **Scenario record (WP-J):** 330 pinned scenarios before, 331 after; the minimum of
  `arcade-games.spec.ts` rises from 8 to 9, and no minimum is lowered. `RouteMatrixTest` pins
  102 routes (103 once WP-K adds its route).
  **Test record (implementation, before the interim review):** PHPUnit unit 1682 and
  integration 709 on the canonical stack, Jest 2419, ESLint, stylelint, `composer cs:check`,
  `l10n:check-keys`, `backlog:check`, `docs:encoding`, `agents:check`, `git diff --check` and
  `npm run build` green; `arcade-games.spec.ts` on the primary row (9 scenarios) green. The first
  integration run after that browser run failed twice: `MinigameControllerIntegrationTest` read
  the threshold the browser scenario had left on the shared stack (its `setUp` now clears the
  thresholds, as it already set the gate), and `PauseAgreementConcurrencyTest` got no answer
  from its helper process once. The full rerun and three isolated runs of the latter passed;
  the cause of that one failure is not established. The interim review added MariaDB, SQLite,
  the AIO row and the browser gate on both rows (see the Review Outcome).

### WP-K Colour schemes (FEAT-UX-057)

- First, colour literals outside `tokens.css` become tokens, with the game palettes excepted
  (D-090-67), and `theme-consistency.spec.js` holds that line.
- `src/styles/schemes.css` defines *Standard* and five schemes for light and dark. Each overrides
  Nextcloud's primary-element variables and the app's accent tokens under
  `body[data-cs-scheme="…"]`, scoped to the app content and its teleported dialogs, and none
  applies under a high-contrast theme (D-090-67).
- `ColorSchemeService` stores the choice in per-user app configuration and checks it against the
  list. `PUT /api/v1/preferences/me/color-scheme` sets the caller's own scheme, and
  `PageController` provides it as initial state. `GET /preferences/me` reports it too.
- Frontend: a personal settings category *Appearance* (`AppearanceSettings.vue`) with swatches,
  and `useColorScheme` setting the attribute at mount and after a change.
- **Implemented 2026-11-14** (D-090-68 to D-090-74). The route lives in `PreferenceController`
  beside the preference pair; `GET /preferences/me` adds `colorScheme` in the controller, so
  `PreferenceService` (301 lines) does not grow. The scheme is checked against the list in
  `ColorSchemeService::setOwn()` with the member the gate resolved, so no route names another
  person; account erasure and the test reset delete it. `schemes.css` has three layers: one
  palette per scheme with a light and a dark variant, the theme selection on `<body>` keyed to
  Nextcloud's theme attributes like `tokens.css`, and the application to
  `#app-chorestrike-vue`, `.modal-overlay` and `.wizard-overlay`. A choice in Settings recolours
  the app at once. The in-app glossary, the user guide, the API reference, both design
  documents, `ui-use-cases.md`, `testing.md` and `architecture.md` describe it. RF-090-40 fixed
  while implementing.
  **Scenario record (WP-K):** 331 pinned scenarios before, 337 after.
  `color-contrast.spec.ts` joins the manifest with its two scenarios of every main view and two
  new ones, every scheme on Today and in Settings per theme (minimum 4);
  `color-schemes.spec.ts` is new with two (minimum 2); `settings.spec.ts` also checks the new
  category closed. No minimum is lowered. `RouteMatrixTest` pins 103 routes and the self-only
  preference surface.
  **Test record (implementation, before the interim review):** PHPUnit unit 1699 and
  integration 715 on the canonical stack, Jest 178 suites / 2494 tests, ESLint, stylelint,
  `composer cs:check`, `l10n:check-keys`, `backlog:check`, `docs:encoding`, `agents:check`,
  `git diff --check` and `npm run build` green. On the primary row (canonical stack, Nextcloud
  35.0.3): `color-schemes.spec.ts` (2), `color-contrast.spec.ts` (4, with the full view set on
  *Standard* after the literal move), `settings.spec.ts` (20) and `layout-clearance.spec.ts`
  (8) pass. The complete browser gate on both rows and the other providers belong to the
  interim review, which ran them (see the Review Outcome).

### WP-G Release `0.8.0-alpha.5` (RELEASE-002)

App metadata in `appinfo/info.xml` and `package.json`, with Nextcloud 35 as the lower bound
(D-090-58); changelog section, dated after the
verified deployment; README, roadmap and documentation map; screenshots recaptured, because
every view moves; the operator's GUI review on the development stack; final review; the
[qualification pipeline](../../standards/ai/skills/release/SKILL.md#qualification-pipeline);
export of one selected smoke run; operator deployment; verified record in the deployment guide.
Each step needs its own approval.

- **Candidate prepared and committed 2026-11-15** (D-090-75 to D-090-78). `appinfo/info.xml`,
  `package.json` and the lockfile name `0.8.0-alpha.5`. `min-version` is 35 (D-090-58), and with
  it the README badge and requirement, the prerequisites of the deployment and development
  guides, the stack line of `AGENTS.md` and the Jest pin of the metadata bounds
  (`testRuntime.spec.js`). The changelog has its candidate section. The README names the
  version, the absence, the grouped task list, the categories, the arcade controls and the
  schemes, says *absences* where it said *pauses*, and corrects the energy range to 1–20, which
  `0.8.0-alpha.4` had changed. The seven screenshots are recaptured on an owned staged row
  (primary runtime, SQLite, `family` seed) and were compared by eye with their predecessors.
  **Pre-freeze checks:** `test:runtime:check` reports `current`. `npm audit --omit=dev` lists
  only what the two bounded advisories of TECH-DEPS-001 reach in the production tree
  (`minimatch` through `webdav` under dialogs, `elliptic` through
  `node-polyfill-webpack-plugin`). The notes of the `0.8.0-alpha.4` handoff hold no promise
  that this round has not kept. Of the open Dependabot PRs, #5 to #7 close with the push, #8 and
  #11 stay with TECH-DEPS-001, and #12 is taken (D-090-78).
  **GUI review stack:** the canonical stack serves this tree with the `family` profile; its
  kept volumes went from `0.8.0-alpha.4` to `0.8.0-alpha.5` through `occ upgrade`.
  **Second operator GUI review (2026-11-15, implementation order step 10):** on that stack the
  operator checked the task categories, the arcade controls and the colour schemes and
  reported no findings; the German scheme names (D-090-73) and the key figures of Insights
  (D-090-52) stay as built.
  **Test record (candidate preparation):** Jest 178 suites / 2494 tests, ESLint, stylelint,
  `l10n:check-keys`, `backlog:check`, `docs:encoding`, `agents:check`, `git diff --check`, the
  preservation runner's `-SelfTest`, `test:runtime:canonical` and `npm run build` green. No PHP
  changed, so PHPUnit and the browser gate did not run for this step; both run in full with
  the qualification.

### Durable documentation

- New **ADR-020: Absences without requests**, amending the pause section of ADR-013: direct
  acknowledgement for every role, deletion as a cancelled revision, the boundary rule, one
  reading for every cancelled revision (D-090-21) and what can be deleted (D-090-22).
- `calm-core-ux.md`: Today as the one landing page, the feedback default, absences.
  `task-model-and-sync.md` and `gamification-and-rewards.md`: the pause paragraphs.
  `ui-use-cases.md`: the use cases of the removed pages, and the stale landing rule found in the
  inventory. `docs/api/README.md` and `docs/api/notifications.md`. `user-guide.md` and the in-app
  help. `architecture.md`: the component map and its large-file list.
- Scope extension: ADR-019 amended (thresholds, reset, storage). `task-model-and-sync.md` gets the
  categories, and `docs/standards/guidelines/design.md` with `docs/concepts/design.md` the
  schemes and their contrast rule. `docs/api/README.md` gains the five new routes,
  `user-guide.md` and the in-app help the three settings, and `testing.md` the report check and
  the scheme dimension of the contrast spec.

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
- **WP-B:** specs for `useTaskCompletion`, `TodayStatusLine`, `TodayTaskRow`, `SetupWizardEntry`
  and `todaySections`; `TodayView.spec.js` for the section order, the red block, the held row
  and the wizard entry; `router.spec.js` for the redirect.
  Browser: `today-view.spec.ts`, `family-core-ux.spec.ts`, `workflow-state-reconciliation.spec.ts`,
  `setup-wizard-journey.spec.ts`, and the specs that crossed the Dashboard (below).
  **Scenario record (WP-B):** 335 pinned scenarios before, 327 after; no minimum count is
  lowered. `dashboard.spec.ts` becomes `task-deep-links.spec.ts` (the private link, plus the
  overdue link from `dashboard-navigation.spec.ts`), and `dashboard-navigation.spec.ts` goes.
  New: the landing page with its status and the redirect (`today-view`), the status line's row
  on desktop and phone (`layout-clearance`), Today's request budget
  (`workflow-request-budgets`). Dropped with their surface (D-090-11), eleven scenarios: the
  Dashboard's view, quick stats, house name and health bar, quick-stats section, floor list
  (five); a room card opened by click and by keyboard with the due-today tile (two); the
  read-only floor list (`room-management`); the health bar's layout (`layout-clearance`,
  succeeded by the status-line check); and two duplicates of "navigate to all main views",
  which `core-functionality` and `fast-core-tests` keep (the latter now with the
  leaderboard). The mobile set keeps seven scenarios: the Dashboard-tiles scenario becomes
  "today shows the status line and the overdue block, and a room opens".
- **WP-C:** `taskGrouping.spec.js` for the three groupings, the counts and empty groups;
  `taskFilters.spec.js` for the presets and the filter rules; `TaskGroupList.spec.js` (with the
  switch) and `TaskCreateDialog.spec.js`; `TaskManager.spec.js` for the switch, the URL, the
  room deep link, the paging rule and the two dialogs; `TaskList.spec.js` and
  `TaskListDetails.spec.js` for the headings, the order and *Date*; `router.spec.js` for the
  redirect; `requestCounts.spec.js` for one house-wide read, and the browser budget of
  `workflow-request-budgets.spec.ts` for the groupings and an opened group (RF-090-42).
  **Scenario record (WP-C):** 327 pinned scenarios before, 327 after; no minimum count is
  lowered. `room-detail.spec.ts` becomes `task-grouping.spec.ts` with four scenarios: the
  switch with its address (new); a room heading's counts and cleanliness (successor of the
  room page's information and cleanliness scenarios, two into one); *New* with the template
  picker (successor of the add-task scenario); *Date* with *Last done* and *Last cleaned*
  (new, the first browser proof of that dialog). Dropped with its surface: the back link of
  the room page. `room-management`: rename and delete now run in Settings and check the list
  by room. `core-functionality`: the room-address scenario proves the redirect. The Dashboard
  specs named in the draft moved with WP-B. `color-contrast` and `layout-clearance` measure
  the list by room in place of the room page. The helper `fixtures/rooms.ts` opens a room in
  the grouped list and creates a spec's own task (RF-090-17).
- **WP-D:** `App.spec.js` and `navigationIcons.spec.js` for the eight entries; `Settings.spec.js`
  for the areas, the closed categories, mounting on open, the links and the non-owner view;
  one spec per category (`SettingsCategory`, `HouseSettings`, `StructureSettings`,
  `DangerZoneSettings`, `PlanningSettings`; the tests of the old page moved with their
  category); `SelfAvailabilitySettings` and `EnergyBudgetSettings` for the error at the form;
  `SchedulingView.spec.js`, `CalendarView.spec.js`, `SegmentedSwitch.spec.js`,
  `router.spec.js`. Browser: `fixtures/settings.ts` opens a category and `fixtures/planning.ts`
  the weekly plan and the planning settings; `settings.spec.ts`, `room-management`,
  `members-owner`, `self-availability-member`, `availability-settings-flow`, `scheduling`,
  `flex-window-scheduling`, `scheduling-apply-flow`, `family-rewards` and the specs that only
  pass through Settings; `tests/package/owner-smoke.cjs` and the screenshot action.
- **WP-E:** `ActivityServiceTest` and `HouseActivityIntegrationTest` for `coinMinor` and its
  redaction; `RankingPanel` (moved from `Leaderboard.spec.js`, with the loading and error
  states), `ActivityFeed`, `HouseStatsCards` and `MemberFairnessTable` specs;
  `InsightsView.spec.js`. Browser: `fixtures/insights.ts` opens the ranking;
  `insights-flow.spec.ts`, `leaderboard.spec.ts`, `ranking-consent.spec.ts`,
  `core-functionality` (the old Leaderboard address), `multi-user-interactions`.
  **Scenario record (WP-D and WP-E):** 327 pinned scenarios before, 329 after;
  no minimum count is lowered and no scenario goes without a successor. New in
  `settings.spec.ts`: the page starts with the personal area and every category closed, and
  the own requests open from the personal settings. Retitled with their surface: the
  navigation scenarios of `scheduling` and `flex-window-scheduling` now reach the weekly plan
  through the calendar (the latter also proves the way back), `core-functionality` proves
  the redirect of the old Leaderboard address, and the Insights navigation scenario lost the
  neighbours from its name.
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
- **WP-H:** `Rewards.spec.js` holds the list back until the wallet read ends and shows it
  without a banner after a failed read. `family-rewards.spec.ts` sees the banner before it taps.
  `runTestsMatrix.spec.js` covers `--check-junit` (zero tests, a skip, an error, a missing
  report). The integration suite runs once from a copy on a case-sensitive file system: with the
  old map as the control, then with the new one; the result is recorded. P4 runs without the
  link. Dependencies: the complete gates of the qualification, plus `npm audit` before and
  after.
- **WP-I:** `TaskCategoryServiceTest` (default list, add, rename, reorder, delete, *Other* fixed,
  limits, unique labels, owner-only, revision conflict, audit); `TaskServiceTest` and
  `TaskCreationServiceTest` (unknown key refused, default `other`, template key kept or
  replaced); `HouseControllerTest`; `RouteMatrixTest`; an integration test on all providers that
  a deleted key leaves the task row untouched. Jest for the settings component, the house store,
  `taskFilters.spec.js` (`?category=`, unknown as *Other*), `TaskFilterBar` and `TaskEditForm`.
  Browser: an owner adds a category, a task gets it, and the filter finds it.
- **WP-J:** `ArcadeAccessServiceTest` (thresholds over the defaults, range and unknown games
  refused, a reset excludes earlier chores, an undo on either side of the start point,
  owner-only, audit); `MinigameServiceTest` (the score lock with a lowered threshold and after a
  reset); `ArcadeUnlockTest` on all providers (a reset relocks, new chores unlock, highscores
  stay); controller tests; `RouteMatrixTest`; `AccountErasureServiceTest`;
  `TestDataServiceTest`. Jest for both settings components and the store. Browser
  (`arcade-games.spec.ts`): a lowered threshold opens Sock for a child, and a reset locks it
  again while the highscore stays.
- **WP-K:** `ColorSchemeServiceTest` and controller tests (unknown scheme refused, only the
  caller's own); `RouteMatrixTest`; Jest: a contrast spec over every scheme, theme and token
  pair, `theme-consistency.spec.js` without the moved literals, `useColorScheme`,
  `AppearanceSettings`. Browser: a scheme chosen in Settings holds after a reload and colours an
  open dialog too, a high-contrast theme shows *Standard*, and `color-contrast.spec.ts` gains
  the scheme dimension (D-090-67).
- **Extension, shared:** `RouteMatrixTest` at 103 routes. Each new browser scenario joins
  `required-scenarios.json` with a scenario record per package, and no minimum is lowered.
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
modified: docker/test-runtime.json, docker/Dockerfile.test, docker/compose.test.yml,
          docker/compose.test-matrix.yml, docker/compose.package-smoke.yml, docker/README.md,
          scripts/test-runtime.js, scripts/test-runtime-stage.js, scripts/test-target.js,
          scripts/run-package-smoke.js, .github/workflows/release-gate.yml, full-matrix.yml,
          tests/unit/scripts/testRuntime.spec.js, testRuntimeStage.spec.js (runtime update,
          RF-090-12)
new:      tests/recovery/upgrade-fixture.php, src/styles/feedback.css
new:      src/views/AbsenceView.vue, src/components/Insights/RankingPanel.vue,
          src/components/Today/TodayStatusLine.vue, src/components/Today/TodayTaskRow.vue,
          src/components/Setup/SetupWizardEntry.vue, src/utils/todaySections.js,
          src/composables/useTaskCompletion.js, src/utils/taskGrouping.js,
          src/utils/taskFilters.js, src/components/Tasks/TaskGroupList.vue,
          TaskCreateDialog.vue, TaskPager.vue, CalendarControls.vue,
          src/components/Common/SegmentedSwitch.vue, src/components/Insights/CorrectionReview.vue,
          src/components/Settings/ (SettingsCategory.vue and one file per category),
          src/styles/settings.css
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
          src/store/rooms.js, src/store/roomTasks.js, src/store/scheduling.js, src/utils/dateKeys.js,
          src/utils/taskDisplay.js, src/utils/roomIcons.js,
          src/components/Common/HoldButton.vue,
          src/composables/useCelebration.js, src/composables/useToasts.js
removed:  src/views/Dashboard.vue, src/views/RoomDetail.vue, src/views/Leaderboard.vue,
          src/components/Dashboard/QuickStats.vue, HouseHealthBar.vue, HouseholdGoalBar.vue,
          FloorList.vue, RoomCard.vue, src/components/Settings/PauseSettings.vue, with their specs
modified: l10n/de.json, l10n/en.json, l10n/templates/chorestrike.pot
modified: tests named in section 4, tests/e2e/fixtures/*, tests/e2e/required-scenarios.json,
          scripts/run-e2e-gate.js, playwright.config.ts, tests/package/owner-smoke.cjs,
          tests/docs-screenshots/scenes.js
new:      tests/e2e/fixtures/settings.ts, planning.ts, insights.ts,
          tests/e2e/fixtures/rooms.ts (replaces dashboard-floors.ts),
          tests/e2e/task-deep-links.spec.ts (was dashboard.spec.ts),
          tests/e2e/task-grouping.spec.ts (was room-detail.spec.ts)
removed:  tests/e2e/dashboard-navigation.spec.ts, docs/screenshots/dashboard.png
new:      docs/adr/ADR-020-absences-without-requests.md
modified: docs/adr/README.md, docs/concepts/calm-core-ux.md, task-model-and-sync.md,
          gamification-and-rewards.md, ui-use-cases.md, simplified-core.md, docs/api/README.md,
          docs/api/notifications.md, docs/user-guide.md, docs/engineering/architecture.md,
          docs/engineering/deployment.md, docs/engineering/testing.md,
          docs/testing/release-test-plan.md
modified: appinfo/info.xml, package.json, package-lock.json, CHANGELOG.md, README.md,
          docs/screenshots/*, docs/release/roadmap.md, docs/README.md, docs/backlog/*
modified: src/views/Rewards.vue, tests/unit/views/Rewards.spec.js, tests/e2e/family-rewards.spec.ts,
          composer.json, tests/integration/Service/RoomTaskServiceExtendedFeaturesTest.php,
          scripts/run-tests.js, tests/unit/scripts/runTestsMatrix.spec.js,
          .github/workflows/release-gate.yml, test.yml, .github/dependabot.yml, eslint.config.js
          (WP-H; eslint.config.js loses the exemptions of two components WP-B removed)
new:      lib/Controller/TaskCategoryController.php, tests/unit/Controller/TaskCategoryControllerTest.php,
          tests/integration/Service/TaskCategoryTest.php (WP-I);
          tests/__mocks__/OCP/IAppConfig.php gains the typed array methods
new:      lib/Controller/ArcadeSettingsController.php, lib/Service/ArcadeStartPointStore.php,
          src/components/Settings/ArcadeSettings.vue, tests/__mocks__/OCP/Config/IUserConfig.php,
          ValueType.php (loaded by tests/bootstrap-entity.php), with their unit and
          integration tests (WP-J; the routes leave MinigameController.php unchanged)
modified: lib/Db/CompletedTaskMapper.php (maxId), src/components/Minigames/ArcadeCatalog.vue,
          src/components/Help/HelpGlossary.vue, docs/concepts/ui-use-cases.md (WP-J)
new:      lib/Service/TaskCategoryService.php, ColorSchemeService.php,
          src/components/Settings/TaskCategorySettings.vue, AppearanceSettings.vue,
          src/components/Minigames/ArcadeThresholdSettings.vue, ArcadeResetList.vue,
          src/composables/useColorScheme.js, src/styles/schemes.css, src/utils/taskCategories.js,
          with their tests; dependency @nextcloud/initial-state (package.json, package-lock.json)
modified: lib/Controller/HouseController.php, PreferenceController.php, PageController.php,
          appinfo/routes.php (five routes),
          lib/Service/TaskService.php, TaskCreationService.php, ArcadeAccessService.php,
          RewardConfigService.php, AccountErasureService.php, TestDataService.php,
          lib/Db/WorkShareMapper.php, src/components/Tasks/TaskEditForm.vue, TaskFilterBar.vue,
          src/utils/taskFilters.js, src/views/Settings.vue, src/store/house.js,
          src/store/preferences.js, src/store/minigame.js, src/styles/tokens.css and the 18 files
          with colour literals (WP-I to WP-K)
modified: docs/adr/ADR-019-arcade-unlocks-from-completion-history.md,
          docs/standards/guidelines/design.md, docs/concepts/design.md
new:      src/utils/colorSchemes.js, tests/unit/css/colorSchemes.spec.js,
          tests/e2e/color-schemes.spec.ts, tests/e2e/fixtures/color-scheme.ts (WP-K, D-090-74)
modified: the fourteen component files with colour literals (D-090-72), src/App.vue,
          src/main.js, src/services/api.js, src/utils/confetti.js,
          src/components/Tasks/TaskListItem.vue (RF-090-40), tests/unit/__mocks__/
          nextcloud-initial-state.js, tests/e2e/color-contrast.spec.ts, fixtures/theme.ts,
          fixtures/settings.ts, scripts/run-e2e-gate.js, tests/e2e/required-scenarios.json,
          docs/api/README.md, docs/user-guide.md, docs/concepts/ui-use-cases.md,
          docs/engineering/testing.md, docs/engineering/architecture.md (WP-K)
new:      src/components/Rewards/RewardWalletBanner.vue, tests/unit/components/
          RewardWalletBanner.spec.js (final Full review, RF-090-41)
modified: lib/Service/TaskCategoryService.php, PauseService.php,
          lib/Notification/ChoreStrikeNotifier.php, src/views/Rewards.vue, TodayView.vue,
          TaskManager.vue, InsightsView.vue, SchedulingView.vue, src/components/Tasks/TaskList.vue,
          Insights/RankingPanel.vue, Minigames/ArcadeThresholdSettings.vue, ArcadeResetList.vue,
          Scheduling/SchedulePlanner.vue, AvailabilityEditModal.vue, src/utils/taskGrouping.js,
          icons.js, src/styles/mobile.css, src/router.spec.js, comments in api.js, game.js and
          house.js, their tests, tests/e2e/private-tasks.spec.ts, workflow-request-budgets.spec.ts,
          required-scenarios.json, l10n/*, appinfo/info.xml, README.md, CHANGELOG.md,
          docs/engineering/architecture.md, deployment.md, docs/api/README.md, docs/adr/README.md,
          ADR-013, docs/concepts/task-model-and-sync.md, ui-use-cases.md,
          docs/standards/guidelines/e2e-testid-conventions.md (final Full review, RF-090-41..55)
modified: lib/Db/OccurrenceMapper.php, CHANGELOG.md, docs/engineering/testing.md;
new:      tests/integration/Db/OccurrenceDayBoundsTest.php (qualification, RF-090-57)
modified: docker/package-build.json, docker/Dockerfile.package, .github/workflows/checks.yml,
          full-matrix.yml, release-gate.yml, runtime-check.yml, test.yml, package.json,
          package-lock.json (root engines), README.md, CHANGELOG.md,
          docs/engineering/development.md, deployment.md, testing.md (qualification, RF-090-58)
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
8. WP-H (D-090-60), then a Standard A limited to WP-I to WP-K.
9. WP-I, WP-J and WP-K, each with an interim Standard B and a browser gate on both rows. WP-H
   joins the first of them. WP-J amends ADR-019 before its code; WP-K moves the literals to
   tokens before the schemes.
10. A second operator GUI review for the three settings and the schemes.
11. WP-G, each step with its own approval.

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
| Grouped lists render slowly in a large household | Low | One house-wide read; a room's rows render when it opens; request budgets in Jest and the browser |
| Bookmarks and the digest link break | Low | Redirects for the four old addresses; router specs |
| The runtime check moves during the round | Medium | Rerun before the freeze; promotion is routine |
| Docker Desktop is shared with another project | Medium | Check for its runs first; rerun the complete gate, never explain a timeout away |
| The extension delays the release | Certain | Operator decision D-090-60; each package is reviewed on its own and could leave the release without touching the others |
| A deleted category hides retained tasks | Low | Unknown keys read as *Other*; nothing rewrites stored rows; an integration test pins it |
| A reset loses or double-counts chores at its boundary | Low | The start point is a completion id taken under the house-row lock; undo cases on both sides |
| A scheme fails contrast in one theme | Medium | Accent only (D-090-62); Jest ratios for every pair; the browser contrast spec per scheme and theme |
| Per-user values outlive an account | Low | Nextcloud removes them with the account; account erasure deletes them explicitly, with a test |

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
- [x] No view touched by this plan is longer than before; the participation flow exists once
- [ ] Every user-visible string goes through `t()`; `l10n:check-keys` is green with real German copy
- [ ] The Rewards list does not move when the wallet arrives; PHPUnit suites load and run on a case-sensitive file system, and a CI suite that runs no test fails (WP-H)
- [ ] Owners manage task categories with *Other* fixed; tasks accept only listed keys; a deleted key reads as *Other* without a rewrite; the task list filters by category (WP-I)
- [ ] Owners set each game's threshold and reset one person's progress after a confirmation; highscores stay; ADR-019 amended (WP-J)
- [ ] Each person picks one of six colour schemes, kept across devices and applied at first paint; every scheme passes contrast in both themes (WP-K)
- [ ] The extension stores no fact and changes no table; account erasure removes the per-user values
- [ ] `npm run test:runtime:check` reports `current` at the freeze
- [x] Operator GUI review recorded; its findings resolved or routed
- [ ] `0.8.0-alpha.5` qualified: full browser gates and PHPUnit suites on both runtime rows, SQLite and MySQL provider suites, reproducible package build and smoke
- [ ] Deployed through the private exchange; the operator's sanitized report verified and imported into the deployment guide
- [ ] `CHANGELOG.md`, README, roadmap and documentation map name the new version; screenshots recaptured
- [ ] Build gate green (`npm test` + lint + backend tests)
- [ ] Required tests ran without new or unapproved skips
- [ ] All implementation decisions and review questions resolved and recorded
- [ ] All plan-owned review findings (§9) resolved or user-rescoped to `backlog.md`
- [ ] Owning backlog items updated or archived according to their actual completion state
- [ ] Durable concepts/decisions integrated into the permanent docs (`docs/concepts/`, `docs/adr/`, `docs/engineering/`, `docs/api/`, `docs/standards/`) — plans are temporary
- [x] Final Full review is `PASS` or `PASS-WITH-FIXES` (the round adds a command and changes authorization for children)
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
- [x] RF-090-12 (minor) - WP-G, runtime currency: `npm run test:runtime:check` reports `update-needed` on 2026-11-11, and the scheduled check on `master` has been red since 2026-11-09. Upstream AIO is 15.0.2 and ships Nextcloud 35.0.2 and Redis 8.10.3; the pinned PHP and PostgreSQL image tags moved too. The inventory names AIO 14.4.3 with Nextcloud 34.0.5 and Redis 8.10.2. Found while implementing WP-F. The reviewed inventory update and the requalification belong before the freeze (§7 and the acceptance criterion); which AIO version production runs at the deployment is the operator's to state. **Resolved 2026-11-13:** the operator named AIO 15.0.2 (D-090-57). The inventory holds Nextcloud 35.0.3 (update source 35.0.2) on the primary row and AIO 15.0.2 with Nextcloud 35.0.2 (update source 35.0.1) and Redis 8.10.3 on the AIO row, with the moved PHP and PostgreSQL images; the check reports `current`. Two rows on one patch needed row-aware image names and a target check that reads the image label (D-090-59). Upstream conditions changed and are pinned: the `lcobucci/clock` exception left with Nextcloud 34, and 35.0.3 ships a current Notifications lock. Evidence: all four images verified; PHPUnit unit 1600 / integration 698 with skips forbidden on PostgreSQL, MariaDB, SQLite and the AIO row (matrix run `cs090-rt-r1`); P2 `c0901211` (35.0.2 to 35.0.3) and `c0901212` (AIO row, 35.0.1 to 35.0.2); P4 `c0901203`..`c0901206` on the four targets; P3 `c0901221`. The complete browser gate passes on both rows without retries, skips or flaky scenarios: `run-zBcjiF` on the AIO row and `run-RwKd3N` on the primary row (canonical stack on 35.0.3), each started after the shared Docker host had been quiet for three minutes; Jest 169 suites / 2367 tests and lint pass on the same tree. Two earlier complete browser gates on the AIO row are not passes: `run-F2Vfj0` lost one scenario of `workflow-state-reconciliation.spec.ts` and `run-6OZBYh` four of `mobile-core.spec.ts` on `mobile-webkit`, the other four projects passing each time. Three of the five are seed requests that ended with "other side closed" before any assertion and never reached the server's access log (the pattern of TECH-TRANSPORT-001); the other two are timeouts in the same five minutes. Test runs of another project on the shared Docker host started in the first window and in the later part of the second; the two closures at its start have no such record. Both failed runs are folded into TECH-TRANSPORT-001 and stay unattributed. The package smoke on both rows and the check at the freeze belong to the WP-G qualification (acceptance criteria), not to this finding.
- [x] RF-090-13 (minor) - WP-F, `src/utils/effectGates.js`, `src/composables/useGameEffects.js`: the effect gates read the store's initial `animationEnabled: true` before `/preferences/me` had answered, so a member who had switched the animation off saw confetti when completing a task while the read was pending or after it failed; before WP-F an unknown state meant off. Fixed in the interim review (operator's choice, D-090-32): `animationAllowed()` opens the animation only after an authoritative read, used by the celebration gate and the arcade's pulses, with Jest cases for the unread state; `calm-core-ux.md` and the gamification skill say so.
- [x] RF-090-14 (minor) - WP-C, `src/router.js`, `tests/e2e/fixtures/rooms.ts`: since WP-B removed the Dashboard with its room cards (D-090-33), nothing in the interface links to a room page; it opens by its address only, and the template picker and the date dialog are reachable only that way. Not a state to release. Closed by WP-C: the task list groups by room, `/room/:id` redirects to that room, *New* in a room heading opens the template picker and *Date* in the expanded row the date dialog, and the browser helper opens a room in the grouped list.
- [x] RF-090-15 (minor) - WP-B, `src/views/RoomDetail.vue`: the first browser gate of WP-B (`run-v47nfT`) lost a task created right after a room page opened by its address: *Add Task* was enabled while the room still loaded, and a read that had started before the creation replaced the list afterwards. Fixed while implementing: the button is disabled while the room loads, as in the task manager, with a Jest case. The store cause is older than this plan and out of its scope: BUG-UX-016.
- [x] RF-090-16 (nit) - WP-B, `src/views/TodayView.vue`: the count next to *Due today* said "1 tasks" for a single row; the new label lacked the singular branch `Settings.vue` already uses. Fixed in the interim review with the existing `1 task` key and a Jest case.
- [x] RF-090-17 (minor) - WP-C, `tests/e2e/task-completion.spec.ts`: two pinned scenarios ("User can complete a task and earn points", "Completion offers Undo which restores the task") proved nothing. They read the number of Done buttons right after the room opened, before its rows had rendered, and returned at zero; the undo scenario also waited for `POST /room-tasks/{id}/undo`, a route that `POST /completions/{id}/undo` replaced with ADR-014. The retargeted helper waits for the rows, so both ran for the first time and failed: the first seeded row is assigned to someone else and asks who did the work. Fixed while implementing: each scenario creates a one-time task of its own through `createRoomTask()` and has no early return, and the undo scenario expects the current route. The exact receipt and undo proofs stay in `task-completion-full-flow.spec.ts`.
- [x] RF-090-18 (minor) - WP-D, `src/components/Scheduling/OvercapacityWarning.vue`: the muted text of a deferred task (priority and energy) reached 4.27:1 on the warning background in the light theme, below the 4.5:1 the contrast check requires. Older than this plan and unseen by the gate, because the rows exist only while a day is over capacity, which the fresh seed is not; a focused run on a database that earlier runs had filled showed it. Fixed while implementing: the main text colour, with a Jest case that pins it.
- [x] RF-090-19 (minor) - WP-E, `src/components/Insights/HouseStatsCards.vue`: the denser key figures made the house-health value small bold text in the primary colour, 4.38:1 in the dark theme. Found by `color-contrast.spec.ts` in the first browser run of WP-E. Fixed while implementing: the value keeps the main text colour.
- [x] RF-090-20 (minor) - WP-C to WP-E, gates: the first full browser gate of WP-C on the primary row (`run-B7GTOA`) is not a pass. Four of five projects passed; in `chromium-serial-db` the seed request of `validation.spec.ts` ended with "other side closed" before any assertion, and its eight following tests were skipped (the pattern of TECH-TRANSPORT-001). With WP-D and WP-E on the tree, `run-9WVZYP` failed on one test (RF-090-21) and `run-Nqxc1g` passed on 2026-11-12: five projects, 355 tests, none skipped, failed or flaky. Closed in the interim review: on the final tree the complete gate passes on both rows, `run-nZTwVa` (primary) and `run-heRaa0` (AIO), 355 tests each in five projects without skipped, failed or flaky results. The AIO run before it (`run-blNRHT`) lost one scenario of `family-rewards.spec.ts` to a layout shift of the Rewards view, outside this plan: TECH-TEST-013.
- [x] RF-090-21 (nit) - WP-D, `tests/e2e/layout-clearance.spec.ts`: the reachability probe of the long Settings view failed once on the phone in the full gate `run-9WVZYP` (844.375 against 844). It now aligns the link that ends the personal settings; the scroll position is a whole pixel and the boxes above the link are not. Fixed while implementing: the probe compares whole pixels, which a clipped frame (BUG-UX-009) still fails by its whole margin; 27 repeated runs and the gate `run-Nqxc1g` pass.
- [x] RF-090-22 (minor) - WP-D, `src/components/Settings/StructureSettings.vue`: a failed read of the floors left an endless spinner without an error or a retry. The old page showed its error state for that read, and the house and planning categories have one. Fixed in the interim review (operator's choice): an error state with a retry in place, with a Jest case.
- [x] RF-090-23 (minor) - WP-D, `src/components/Settings/DangerZoneSettings.vue`: when only the read after a successful reset failed, the category reported "Failed to reset house" after the success toast; the old page caught that read on its own. Fixed in the interim review (operator's choice): the reads run after the reset's own failure path and a failed read leaves the reset reported as done, with Jest cases for both paths.
- [x] RF-090-24 (minor) - WP-E, `src/views/InsightsView.vue`: `/leaderboard` redirected to `#ranking`, but with hash routing the browser cannot follow that anchor and the router has no scroll behaviour, so an old bookmark landed at the top of Insights, on a phone above the fold of the ranking (D-090-54). Fixed in the interim review (operator's choice): the view brings the ranking into view once its content has rendered when the address names it, with Jest cases and a viewport check in the browser scenario of the old address.
- [x] RF-090-25 (minor) - WP-K, D-090-67: a scheme set on the app container would not reach the app's dialogs, which `Modal.vue` and `SetupWizardEntry.vue` teleport to `body`. Resolved in the Standard A of the extension: the attribute sits on `body`, the overrides are scoped to the app content and its teleported roots, and a browser check opens a dialog.
- [x] RF-090-26 (minor) - WP-J: `countValidSelectedByWallet()` counts several wallets in one grouped query, so one start point per person would have turned the owner's progress list into one query per member. Resolved at plan level: a lower bound per wallet inside the same query.
- [x] RF-090-27 (minor) - WP-I, D-090-65: the revision check would compare against `IAppConfig`'s request cache, so two concurrent saves could both pass. Resolved at plan level: compare and write under the house-row lock against a value read past the cache.
- [x] RF-090-28 (minor) - WP-K: initial state needs `@nextcloud/initial-state`, which is not a dependency. Resolved at plan level: named as a new dependency from Nextcloud's own libraries, in D-090-67 and the manifest.
- [x] RF-090-29 (minor) - WP-K: a scheme could lower the contrast of Nextcloud's high-contrast themes, which a person chose for contrast. Resolved at plan level: schemes yield to *Standard* there, with a browser check.
- [x] RF-090-30 (nit) - WP-I, D-090-65: `tasks.category` holds 50 characters, and the plan left open where the key of a new category comes from. Resolved at plan level: the server generates a short key.
- [x] RF-090-31 (minor) - WP-J, D-090-66: "the start point is taken under the house-row lock" claimed a serialization with completions that the code does not give; a completion locks wallets and only later the house row. Resolved at plan level: the wording says that a reset never interleaves with a score save, and that an overlapping chore may land on either side of the start point without counting twice.
- [x] RF-090-32 (minor) - Tests: the new browser scenarios and routes named no registration. Resolved at plan level: `RouteMatrixTest` at 103 routes, and every new scenario joins `required-scenarios.json` with a scenario record.
- [x] RF-090-33 (nit) - Implementation order: WP-H had no review step. Resolved at plan level: it joins the first interim Standard B of the extension.
- [x] RF-090-34 (minor) - WP-I, `src/components/Tasks/TaskEditForm.vue`: until the house read landed (slow or failed), the form read every stored custom key as *Other*, and a save then rewrote the task's category, a silent change D-090-65 rules out. Fixed in the interim review (operator's choice): the list decides only once the house carries it; until then the stored key stays selectable, and the form reads it against the list when the list arrives; Jest case.
- [x] RF-090-35 (minor) - WP-I, `lib/Service/TaskCategoryService.php`: the size limit was checked after the whole list had been walked, and the key generator compares against a growing list, so an oversized owner payload cost quadratic time before its refusal. Fixed in the interim review (operator's choice): more than 20 items are refused before the walk; unit case.
- [x] RF-090-36 (nit) - WP-I, `src/components/Settings/TaskCategorySettings.vue`: the move and delete buttons of a row said "Move up" or "Delete category" without the row, ambiguous for a screen reader. Fixed in the interim review: each names its category, with German copy and a Jest assertion.
- [x] RF-090-37 (major) - WP-D, `src/store/scheduling.js`: Today and the planning settings both read the scheduling preferences, and the store applied every answer in arrival order. When Today's older read landed after the settings' own read, it replaced the stored budget, and the watcher of `EnergyBudgetSettings` (and of `SelfAvailabilitySettings`) reset the form, so a fresh edit was lost and *Save* stayed disabled. Found by the AIO browser gate of this review (`run-cyYpmo`, `scheduling.spec.ts`; the trace shows the two reads finishing out of order). Fixed in the interim review: the store keeps a read sequence and the read in flight, as `fetchHouse` does and the client-state contract of [`architecture.md`](../../engineering/architecture.md#client-state-contract-cs-plan-066-3233) requires; an overtaken answer is dropped and its caller waits for the winning read; an accepted save and `reset()` invalidate older reads. Three Jest cases, red against the old store.
- [x] RF-090-38 (minor) - WP-J, `src/components/Settings/ArcadeSettings.vue`: the category root carried `cs-settings-form`, so the shared rules of `settings.css` reached the gate switch, the threshold form and the reset list, which bring their own styles; that sheet keeps its rules away from such components, and rules of equal weight then win by load order. Fixed in the interim review: the root drops the class and styles its cards itself, as `Settings.vue` does; the Jest case pins it.
- [x] RF-090-39 (minor) - Tests, `tests/e2e/settings.spec.ts` (from `b545c97d`, 2026-10-10): "House drafts wait for the winning read on a direct Settings load" routes the house reads and never waits for the overtaken first one. On the AIO row of this review (`run-qrPuOs`) that read was still in `route.fetch()` when the test ended, so the closing page failed the test after every check had passed; all three reads had answered 200 in the access log. "Cold structure hydration survives room reconciliation" has the same gap for its second read. Fixed in the interim review: both wait for their handlers (`page.unrouteAll({ behavior: 'wait' })`) before the page closes. A temporary hanging first fetch reproduced the failure against the old cleanup and passed with the new one.
- [x] RF-090-40 (minor) - WP-K, `src/components/Tasks/TaskListItem.vue`: an overdue task row paints its background with `--color-error-hover`, and the energy value on it reached about 3.9:1 in the light theme: the hue of *Standard* (`#A34A00`) and of every scheme meets 4.5:1 on the ordinary backgrounds only. Older than this plan and unseen by the browser contrast spec, which passes, so the views it measures show no such row; found while computing the scheme pairs. Fixed while implementing: on an overdue row the energy takes the main text colour, as the description already does, with a Jest pin in `TaskMeta.spec.js`.
- [x] RF-090-41 (major) - WP-H, `src/views/Rewards.vue`: the wallet wait (TECH-TEST-013) took the view from 599 to 610 lines, against "No view grows" (§2) and its acceptance criterion; no exception was recorded. Found by the final Full review. Fixed in the review (operator's choice): the wallet banner is `Rewards/RewardWalletBanner.vue` with its Jest spec, and the view has 565 lines.
- [x] RF-090-42 (minor) - WP-C, tests: the plan names `requestCounts.spec.js` as the guard for "one house-wide read feeds every grouping", but that spec calls store actions directly and never groups or opens a room; no test looked at requests after switching the grouping. Fixed in the review (operator's choice): the browser budget in `workflow-request-budgets.spec.ts` groups by room and by person, opens a group each time and still expects the one house-wide read; the scenario is renamed in `required-scenarios.json`.
- [x] RF-090-43 (minor) - WP-B, `src/views/TodayView.vue`: Today still read `/room-tasks/due-today`, which only the Dashboard had shown. The loaded count made every completion on Today read it again, and a failure of that read showed "Some views could not be refreshed" for data Today never displays. Fixed in the review (operator's choice): Today reads the day and the overdue work only; Jest case and the browser budget (two projections).
- [x] RF-090-44 (minor) - WP-C, `src/views/TaskManager.vue`, `src/components/Tasks/TaskList.vue`: the list by due date sorted with a comparator that worked out both rows' due class on every comparison, about four date formats per comparison and O(n log n) per keystroke in the search, and each heading was computed four times per row. Fixed in the review (operator's choice): `sortByDueGroup()` works out each row's class once, and the headings are one computed list; Jest case.
- [x] RF-090-45 (minor) - WP-E, `src/components/Insights/RankingPanel.vue`: as a section of Insights the ranking ran its ranking read, the medal evaluation and the medal read one after another on every visit, although the ranking does not depend on the medals. Fixed in the review (operator's choice): the ranking read and the evaluation-then-read run side by side; Jest case.
- [x] RF-090-46 (minor) - WP-I, `lib/Service/TaskCategoryService.php`: the duplicate check compared only owner-given names, so an owner could add a second "Other" or "Kitchen" beside the built-in one that keeps its translated name, against D-090-65 and the API reference. Fixed in the review (operator's choice): a kept built-in counts with its name in the saving owner's language; unit cases, an integration case on the real l10n, API reference.
- [x] RF-090-47 (minor) - WP-B, copy: three user-visible strings still named the retired *My Day*: the daily digest notification, the success banner of the weekly plan and the hint of the owner's daily task limit. Fixed in the review: they name *Today* (German *Heute*), catalogues regenerated; the use-case table and a browser comment follow.
- [x] RF-090-48 (minor) - WP-B, `tests/e2e/private-tasks.spec.ts`: the pinned scenario "my-private filter shows only own private tasks" looked for the Dashboard's private chip and did nothing once WP-B removed it, so it passed without a check. Fixed in the review: it creates a private and a public probe, opens `#/tasks?visibility=private` and expects the filter, the private row with its lock and no public row, without a conditional branch.
- [x] RF-090-49 (minor) - WP-G, `docs/engineering/deployment.md`, `CHANGELOG.md`: the expected data change of the update that carries BUG-UX-014 said that no migration runs and the maintenance-window comparison stays identical, while the next paragraph names step `1034` for the same update. Fixed in the review: the BUG-UX-014 paragraph defers to the `0.8.0-alpha.5` paragraph, and the changelog says the fix needs no migration of its own.
- [x] RF-090-50 (minor) - `TaskList.vue`, `InsightsView.vue`, `SchedulingView.vue`: the loading skeletons used `var(--color-background)`, which Nextcloud 35 does not define (checked against the theming app of the canonical container), so they showed an empty area. Older than this plan, in files it changed. Fixed in the review: `--color-main-background`.
- [x] RF-090-51 (minor) - Durable documentation, `docs/engineering/architecture.md`: the size inventory was stale and mixed counting methods (the rows this plan wrote used total lines), the new `AbsenceView.vue` and several older files above the guideline had no row, the component map left out most units of this release and the first step on the retained baseline, and the house store section described a health refresh WP-B had removed. Fixed in the review: the inventory is re-measured in non-blank lines with the missing rows, `CalendarView.vue` leaves it, the map and the store section follow the code.
- [x] RF-090-52 (nit) - Documentation and comments: README step 4 sent readers to a *Scheduling* entry that left the navigation; the app description said *recognized pauses*; the testid standard named `nav-leaderboard`; `GET /house/health` had no API entry; the ADR-013 index and file did not point to ADR-020, and the task-model concept still said child requests pend; comments in `api.js`, `game.js`, `house.js` and a browser spec named removed views; the manifest listed `TaskGroupSwitch.vue` and a changed `MinigameController.php`, and D-090-46 an old line count. Fixed in the review.
- [x] RF-090-53 (nit) - Orphans: `.room-card` and `.floor-section` in `mobile.css` and five icon imports (Trophy, CalendarWorkWeek, TicketDiagonal, LayoutGrid, ArrowLeft) lost their last consumers with the removed pages, and `src/router.spec.js` repeated the room redirect `tests/unit/router.spec.js` pins. Fixed in the review; the shared fallback shapes of three icons stay.
- [x] RF-090-54 (nit) - WP-J, `ArcadeThresholdSettings.vue`, `ArcadeResetList.vue`: the saved note's timer was never cleared, so a second save lost its note early and the timer outlived the component, and both components logged failures the store had already logged. Fixed in the review; Jest case for the timer.
- [x] RF-090-55 (nit) - WP-A, `lib/Service/PauseService.php`: `listForViewer()` had grown to about 71 lines with D-090-27. Fixed in the review: the viewer's entry is `viewerEntry()`, behaviour unchanged (unit and integration suites).
- [x] RF-090-56 (minor) - WP-J, `appinfo/routes.php`: the reset route restricts the user ID to `[a-zA-Z0-9_.@-]+`, copied from the older scheduling route, so a member whose Nextcloud ID holds a space or an apostrophe cannot be reset. User-rescoped to `backlog.md` (operator, 2026-11-15): [BUG-API-002](../../backlog/backlog.md#p3-bug-api-002--member-routes-refuse-nextcloud-user-ids-with-a-space-or-an-apostrophe) fixes both routes together.
- [x] RF-090-57 (major) - WP-G qualification, matrix `a5q1` on `f426d75c` (Sunday 2026-11-15): the SQLite integration suite failed two tests, `RankingConsentTest::testOnTimeRatio_CountsOnlyPublicAgreedOccurrences` and `RhythmProjectionTest::testWeeklyGoal_PercentAndAchievedFollowTheValidCompletionSet`, because the week's last day was missing. Cause, present since the occurrence model of the retained baseline: Nextcloud binds an entity's date field as `Y-m-d 00:00:00`, SQLite keeps that text, and `OccurrenceMapper` compared it with bare day keys, so `<=` and `>` put a day after itself and `=` never matched. On SQLite the daily summary missed the day's planned and agreed work, every inclusive window lost its last day, the history paging could reread its cursor day, and the interval cleanup could delete an open row of its exclusive day. PostgreSQL and MySQL store the day and were not affected. Fixed in the qualification: every day bound is half-open (`>=` the day, `<` the next day), which holds for either stored form, so no data changes. `OccurrenceDayBoundsTest` writes through the entity on fixed days and failed 4 of 4 on SQLite without the fix (control run); the testing guide names the rule.
- [x] RF-090-58 (major) - WP-G qualification, `test:package:build --verify-reproducible` on `3031d686`: `npm ci --engine-strict` in the pinned builder (Node 24.14.0) refused `abbrev@5.0.0`, which needs Node `^24.15.0`. It arrived on `master` after `0.8.0-alpha.4` with `8dcafab6` (`@vue/test-utils` 2.5.1 → `js-beautify` 2 → `nopt` 10 → `abbrev` 5, all development tooling); no package had been built since, and every CI workflow installs the same way on 24.14.0, so the next push would have failed the remote checks too. Fixed in the qualification (D-090-79): builder inventory, `Dockerfile.package`, the five workflows, `engines.node`, README, the development, deployment and testing guides and the changelog name Node 24.15.0; the development guide says that a dependency raising a locked engine raises the minimum with it. Every locked engine range holds at 24.15.0 / npm 11.12.1; at 24.14.0 exactly these two packages fail.

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

**Standard B, interim (WP-B), 2026-11-11: PASS-WITH-FIXES.** Scope: the uncommitted WP-B change
set of this worktree (`/review-chorestrike` without arguments; the main checkout was clean). No
subagents.

- Base HEAD `c058b52cdd8348734f693d10214f6b987bd42a39`, branch `docs/release-a-plan`, pinned,
  0 behind `master`. Capture: 16 staged deletions and one staged rename, 104 modified tracked
  files, 11 untracked paths; scope fingerprint (staged and unstaged binary diffs, sorted untracked
  paths with SHA-256) `4c9d8bf87201215fa1bc1ab3b77dd288be9e9bd69b26ce3ab1bb81837c0b840a`.
- RF-090-16 fixed in the review (§9). Safe fixes: two stale Dashboard mentions in the release
  test plan and the task model, one size in `architecture.md`. D-090-33 to D-090-40 bind with
  this review; no question was asked. FEAT-UX-067's row-feedback criterion is done (D-090-36).
- Gates on the final tree: Jest 160 suites / 2304 tests, `npm run lint`, stylelint,
  `l10n:check-keys`, `backlog:check`, `docs:encoding`, `agents:check`, `git diff --check`,
  `npm run build`; browser gate `run-H7fdXI` on the primary row and `run-k4TsjY` on the AIO
  row, 353 tests each without skipped, failed or flaky results. No PHP changed, so PHPUnit did
  not run; the package smoke runs in the WP-G qualification.
- No transition: WP-C to WP-E and WP-G are open, and so are RF-090-12 and RF-090-14. Record:
  [2026-11-11 review](../../backlog/archive/CODE_REVIEW_2026-11-11.md#standard-b---cs-plan-090-wp-b-today-interim).
  One scoped review commit; no merge, push, tag or deployment.

**Standard B, interim (WP-C, WP-D and WP-E), 2026-11-12: PASS-WITH-FIXES.** Scope: the
uncommitted WP-C to WP-E change set of this worktree, reviewed together as the operator chose
(`/review-chorestrike` without arguments; the main checkout was clean). No subagents.

- Base HEAD `34a318913c5e16c758f34a38928ccdc9bda2f23f`, branch `docs/release-a-plan`, pinned,
  never pushed, 2 behind `master` (two agent-tooling commits). Capture: three staged deletions
  and two staged renames, 96 modified tracked files, 29 untracked paths; scope fingerprint
  (staged and unstaged binary diffs, sorted untracked paths with SHA-256)
  `bc40c8f38b783abcec15be3fd8efb02feda544b3d8d13e44f17f381ed90c93d1`.
- RF-090-22..24 fixed in the review (§9). The operator chose the recommended option on all three
  questions: fix both Settings regressions now, bring the ranking into view (D-090-54 amended),
  and rebase the branch onto `master` after this commit. D-090-41 to D-090-56 bind with this
  review. Safe fixes: stale sizes in `architecture.md`, a Leaderboard comment in `MedalBadge.vue`,
  and section 11 of `release-test-plan.md` (Scheduling no longer in the navigation, *Absent*,
  absences per ADR-020).
- Gates on the final tree: Jest 169 suites / 2366 tests, `npm run lint`, stylelint,
  `l10n:check-keys`, `backlog:check`, `docs:encoding`, `agents:check`, `git diff --check`, the gate
  runner's self-test, `npm run build`, `composer cs:check`; PHPUnit unit 1600 / integration 698
  on the canonical PostgreSQL stack and, with `--fail-on-skipped`, on the AIO row; browser gate `run-nZTwVa` on the primary row and `run-heRaa0` on the AIO row, 355 tests each without skipped, failed or flaky results (RF-090-20 closed). The AIO run before it, `run-blNRHT`, lost one `family-rewards` scenario to a layout shift of the Rewards view that this scope does not touch; routed to TECH-TEST-013 with its trace evidence, then the complete gate was rerun.
- No transition: WP-G is open with RF-090-12, and so are the operator's GUI review and the final
  Full review. Record:
  [2026-11-12 review](../../backlog/archive/CODE_REVIEW_2026-11-12.md#standard-b---cs-plan-090-wp-c-to-wp-e-tasks-navigation-and-settings-insights-interim).
  One scoped review commit, then the rebase; no merge, push, tag or deployment.

**Standard A, scope extension (WP-H to WP-K), 2026-11-13: PASS-WITH-FIXES.** Scope: the parts of
this plan that D-090-60 added (D-090-60 to D-090-67, WP-H to WP-K, their tests, manifest, order,
risks and criteria), as the operator chose. No subagents.

- Base HEAD `97c917be`, branch `docs/release-a-plan`, pinned, 6 ahead and 0 behind `master`, empty
  index. Capture: the plan file with SHA-256 prefix `c64bbbf3235a148e`, and the uncommitted WP-H
  implementation beside it (binary diff hash `1e25be53`), which this review does not judge.
- RF-090-25..33 resolved at plan level (§9); none claims delivered code. The rest checked out
  against the code and the Nextcloud 35 runtime: the six category keys of the form and the 48
  `cleaning` templates, the free `tasks.category` column, the threshold map and the derived
  count, the gate's app-config key, `IAppConfig::getValueArray`/`clearCache` and `IUserConfig`,
  the absence of per-user storage and initial state, the token file and the 18 files with
  literals, the two-theme contrast spec, and the task rows that already carry their definition.
  The extension changes no table, and every new route is `@NoAdminRequired` with membership and
  owner checks in the service.
- Questions: the operator's six answers of 2026-11-13 (D-090-60 to D-090-63); D-090-64 to
  D-090-67 bind with this review. No question is open.
- Gates: Jest 169 suites / 2374 tests, `npm run lint`, stylelint, `npm run build`,
  `backlog:check`, `docs:encoding`, `agents:check`, `git diff --check`. Documentation only for
  this review, so no Docker backend or browser gate was required for it.
- No transition. Record:
  [2026-11-13 review](../../backlog/archive/CODE_REVIEW_2026-11-13.md#standard-a---cs-plan-090-scope-extension-wp-h-to-wp-k).
  One scoped review commit of the plan, the record and the tracking files; the WP-H code stays
  out of it. No merge, push, tag or deployment.

**Standard B, interim (WP-H and WP-I), 2026-11-13: PASS-WITH-FIXES.** Scope: the uncommitted WP-H
and WP-I change set of this worktree, reviewed together as the operator chose. No subagents.

- Base HEAD `87d6254932a243da1797113a021a57430344a7f9`, branch `docs/release-a-plan`, pinned,
  never pushed, 7 ahead and 0 behind `master`. Capture: empty index, 56 modified tracked files,
  9 untracked paths; scope fingerprint (unstaged binary diff, sorted untracked paths with
  SHA-256) `34130e1fd7b60f1c8f5e3dcc2a023971bcb1f8318d4d936f240b7741e70c9611`.
- RF-090-34..37 fixed in the review (§9). The operator chose to fix RF-090-34 and RF-090-35 now;
  RF-090-36 and RF-090-37 fell under the fix-now rule. RF-090-37 belongs to WP-D: the AIO browser
  gate of this review found it. TECH-TEST-012 and TECH-TEST-013 are archived.
- Gates on the final tree: Jest 171 suites / 2395 tests, `npm run lint`, stylelint,
  `l10n:check-keys`, `backlog:check`, `docs:encoding`, `agents:check`, `git diff --check`,
  `npm run build`, `composer cs:check`; PHPUnit unit 1637 / integration 700 on the canonical
  PostgreSQL stack and, with `--fail-on-skipped`, on the AIO row (matrix run `cs090-rv-r1`,
  Nextcloud 35.0.2 with Redis). Browser gate: before RF-090-37 the primary row passed
  (`run-yV6aya`) and the AIO row lost two scenarios (`run-cyYpmo`): the race fixed as RF-090-37,
  and one test GET that never reached the server, folded into TECH-TRANSPORT-001. After the fix
  the AIO row passed (`run-8xxQBQ`); on the primary row `run-mvRorq` lost one `arcade-games`
  scenario to the same transport symptom (folded in too), and the complete rerun `run-5vA2t2`
  passed. Each pass: 356 tests without skipped, failed or flaky results. The AIO target is torn
  down.
- No transition: WP-J, WP-K and WP-G are open, and so are the second GUI review and the final
  Full review. Record:
  [2026-11-13 review](../../backlog/archive/CODE_REVIEW_2026-11-13.md#standard-b---cs-plan-090-wp-h-and-wp-i-release-hardening-and-task-categories-interim).
  One scoped review commit; no merge, push, tag or deployment.

**Standard B, interim (WP-J), 2026-11-14: PASS-WITH-FIXES.** Scope: the uncommitted WP-J change
set of this worktree. No subagents.

- Base HEAD `13097788907092c146fe9895aa843755da8d3990`, branch `docs/release-a-plan`, pinned,
  never pushed, 9 ahead and 0 behind `master`. Capture: empty index, 40 modified tracked files,
  12 untracked paths; scope fingerprint (staged and unstaged binary diff, sorted untracked paths
  with SHA-256) `2735ff2ce78dd917792ca31c6424289105dcf2464e0df68cd541984fdd651bbf`.
- RF-090-38 and RF-090-39 fixed in the review (§9), under the fix-now rule. RF-090-39 lies outside
  WP-J, in a settings browser test of 2026-10-10; the AIO browser gate of this review found it. No
  question was open.
- Gates on the final tree: Jest 174 suites / 2419 tests, `npm run lint`, stylelint,
  `l10n:check-keys`, `backlog:check`, `docs:encoding`, `agents:check`, `git diff --check`,
  `npm run build`, `composer cs:check`; PHPUnit unit 1682 / integration 709 on the canonical
  PostgreSQL stack and, with `--fail-on-skipped`, on MariaDB, SQLite (Nextcloud 35.0.3) and the
  AIO row (Nextcloud 35.0.2 with Redis), matrix run `cs090-wpj-r1`, because the grouped count
  changed its query. RF-090-38 and RF-090-39 changed only the client and a browser test, so
  these stand. Browser gate: the primary row passed (`run-yXLnZu`); the AIO row lost one
  scenario (`run-qrPuOs`), the test defect fixed as RF-090-39, and the complete rerun on the
  final tree passed (`run-BRaDiN`). Each pass: 357 tests without skipped, failed or flaky
  results. RF-090-39 changed only `settings.spec.ts`, which passed in full on the primary row
  afterwards (20 tests). The staged targets are torn down with their volumes.
- No transition: WP-K and WP-G are open, and so are the second GUI review and the final Full
  review. Record:
  [2026-11-14 review](../../backlog/archive/CODE_REVIEW_2026-11-14.md#standard-b---cs-plan-090-wp-j-arcade-owner-controls-interim).
  One scoped review commit; no merge, push, tag or deployment.

**Standard B, interim (WP-K), 2026-11-15: PASS.** Scope: the uncommitted WP-K change set of this
worktree, chosen by the operator because the main checkout had nothing to review. No subagents.

- Base HEAD `5c10481fc405ab93a989be1591af0ec12c9e66af`, branch `docs/release-a-plan`, pinned,
  never pushed, 10 ahead and 0 behind `master`. Capture: empty index, 62 modified tracked files,
  13 untracked paths; scope fingerprint (staged and unstaged binary diff, sorted untracked paths
  with SHA-256) `8ba636907cb84dbe61c78cf79acac56abd1ce70d4096d3286fbf026d561e5947`, unchanged
  after the gates.
- No finding in WP-K, no fix, no new §9 entry; D-090-68 to D-090-74 bind from now on. Two
  browser failures lie outside WP-K and went to the backlog: a tap in `mobile-webkit` that
  opened a second page (new TECH-TEST-014) and a 14.7 s fsync of the staged PostgreSQL (folded
  into TECH-TRANSPORT-001).
- Gates on the reviewed tree: Jest 178 suites / 2494 tests, `npm run lint`, stylelint,
  `l10n:check-keys`, `backlog:check`, `docs:encoding`, `agents:check`, `git diff --check`,
  `npm run build`, `composer cs:check`, `composer validate --strict`; PHPUnit unit 1699 /
  integration 715 on the canonical PostgreSQL stack and, with `--fail-on-skipped`, on MariaDB,
  SQLite (Nextcloud 35.0.3) and the AIO row (Nextcloud 35.0.2 with Redis), matrix run
  `cs090-wpk-r1`. Browser gate: the first runs lost one scenario each, both outside WP-K
  (`run-flsDoF`, `run-O6eNGi`); the complete reruns on the same tree passed on the primary row
  (`run-SXfzOH`) and the AIO row (`run-x6gdyU`), each 361 tests without skipped, failed or
  flaky results. The staged targets are torn down with their volumes.
- No transition: WP-G is open, and so are the second GUI review and the final Full review.
  Record:
  [2026-11-15 review](../../backlog/archive/CODE_REVIEW_2026-11-15.md#standard-b---cs-plan-090-wp-k-colour-schemes-interim).
  One scoped review commit; no merge, push, tag or deployment.

**Full, final, 2026-11-15: PASS-WITH-FIXES.** Scope: the whole plan, `master..022bfd55`
(twelve commits, 404 files) with its decisions, manifest, criteria and durable docs, after the
candidate commit and the second GUI review. Four read-only reviewers in parallel.

- Base HEAD `022bfd559d325a046811c9447f25d67588d7363e`, branch `docs/release-a-plan`, pinned,
  never pushed, 12 ahead and 0 behind `master`; clean tree and empty index at capture.
- RF-090-41..55 fixed in the review (§9); RF-090-56 user-rescoped to BUG-API-002. Two older,
  out-of-scope findings went to the backlog (BUG-I18N-002, CR-SVC-66); two nits were accepted
  with reasons in the record. The operator answered four questions with the recommended
  option each.
- Gates on the final tree: Jest 179 suites / 2499 tests, `npm run lint`, stylelint,
  `l10n:check-keys`, `backlog:check`, `docs:encoding`, `agents:check`, `git diff --check`,
  `npm run build`, `composer cs:check`; PHPUnit unit 1703 / integration 716 with
  `--fail-on-skipped` on the canonical PostgreSQL stack; focused browser runs of the affected
  specs on `chromium` (14) and `chromium-serial-db` (39) without retries. The provider suites,
  the AIO row, the complete browser gates and the package build and smoke are the qualification.
- No transition: the qualification, the export, the deployment and its verified record are
  open. Record:
  [2026-11-15 review](../../backlog/archive/CODE_REVIEW_2026-11-15.md#full-review-final---cs-plan-090-simplified-core-release-a-candidate).
  One scoped review commit; no merge, push, tag or deployment.

**Qualification, 2026-11-15 (WP-G), first attempt on `f426d75c`: failed, kept as evidence.**

- `test:runtime:check` current, no drift: upstream 35.0.3, AIO 15.0.2 with Nextcloud 35.0.2
  (PHP 8.4.27, APCu 5.1.28, phpredis 6.3.0, Redis 8.10.3, PostgreSQL 18.6).
- Jest 179 suites / 2499 tests, ESLint, stylelint and `composer cs:check` green.
- Staged rows `cs082-a5q1-{mysql,pgsql,sqlite}` (primary 35.0.3) and `cs082-a5q1-aio`
  (35.0.2 with Redis sessions, cache and locking), each verified. Matrix `a5q1`: unit 1703 on
  all four rows, integration 716 on MySQL, PostgreSQL and the AIO row; the SQLite integration
  suite failed 2 of 716 (RF-090-57). Not rerun unchanged; the fix of RF-090-57 makes the next
  candidate, and the browser gates and the package did not run on this one.

**Qualification, second attempt on `3031d686`: backend and browser green, package failed.**

- `test:runtime:check` current, no drift; Jest 179 suites / 2499 tests, ESLint, stylelint and
  `composer cs:check` green. The `a5q1` rows were removed and fresh rows staged.
- Matrix `a5q2` on `cs082-a5q2-{mysql,pgsql,sqlite}` (primary 35.0.3, inventory
  `121b9151…`) and `cs082-a5q2-aio` (35.0.2 with Redis, inventory `9fba5eca…`): unit 1703 and
  integration 720 on all four rows, no failure, error or skip.
- Browser gates, five projects each, `workers=1`, no retries: primary on the canonical stack
  `run-f60Ggq`, AIO on `cs082-a5q2-aio` `run-XJlr3a`; 361 tests each (chromium 104,
  chromium-serial-db 188, chromium-scheduling-owner 39, mobile-chromium 15, mobile-webkit 15),
  none failed, skipped or flaky.
- `test:package:build --verify-reproducible` failed in the builder's strict install
  (RF-090-58). Its fix makes the next candidate; by D-090-80 the matrix and both browser gates
  above count for it.
