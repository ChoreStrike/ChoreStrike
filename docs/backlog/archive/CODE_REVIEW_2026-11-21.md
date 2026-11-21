# Code Review Record — 2026-11-21

## Full (interim) - CS-PLAN-091 WP-B6, one form and one row

**Outcome:** `PASS-WITH-FIXES`. Interim review; the plan stays in `active/` and continues
with WP-B7.

### Scope and capture

- Tier: Full, four read-only `reviewer` tracks (correctness, architecture, security and
  data flow, performance and code quality) plus the main agent's own reading of the
  command skeleton, the edit aggregate, the store's refresh after a save and the four
  authorization decisions as implemented. Required: the package adds and removes routes
  and changes who may write what.
- Owning plan: [CS-PLAN-091](../../plans/active/cs-plan-091-simplified-core-releases-b-and-c.md)
  (WP-B6 one form and one row, with RF-091-09 and RF-091-10 of the earlier reviews and the
  operator's GUI review of 2026-11-21). CS-PLAN-080 is not touched.
- Branch `feat/simplified-core-bc` in its pinned worktree, base HEAD `1c962cf8` (the
  committed WP-B4 and WP-B5, 3 ahead of `master`, 0 behind), the whole package
  uncommitted. Captured before the review: 227 paths (166 modified, 21 deletions staged,
  40 untracked), combined scope fingerprint
  `21d121890a1aad9cda7e159788c4a45ec6c25d7d75481f1abf1f3a723b45ec9a` (SHA-256 over the
  staged and unstaged binary diffs and every untracked path with its content hash).
- Before any change the gates of the GUI-review round stood on the captured tree, and the
  provider matrix ran on it: PHPUnit unit 1845 and integration 680 on PostgreSQL, MariaDB,
  SQLite and the AIO row (`r91b6`), all green.

### Decisions (operator, recommended option each)

| Question | Decision |
|---|---|
| An assignee of a private task who removes themselves: the save committed and answered *not found* | **D-091-108:** refused before anything is written (`403`) |
| An assignee could make a private task public and private again and owned it afterwards | **D-091-109:** only the owner of a private task changes its visibility |
| A shared private definition with room tasks the member may not see: the scope question told of them and *all rooms* changed them | **D-091-110:** the save splits off for the member's room without a question; the conflict of a move stays, documented |
| No length check for the name and the description (a long name ended in a server error) | **D-091-111:** name at most 255 characters, description text of at most 2000, translated `400` |

The implementation decisions D-091-89 to D-091-102 and the GUI-review decisions D-091-103
to D-091-107 are accepted; D-091-92 (a wake comes first), D-091-93 (the save is audited
again), D-091-95 (narrowed by D-091-109) and D-091-100 (only changed fields are sent) are
amended, recorded as D-091-112.

### Findings fixed in the review

| Severity | Area | Issue | Fix |
|---|---|---|---|
| major | `store/roomTasks.js` `applySavedRoomTask` | Two refresh calls in a row; the second, which rereads the lists, returned at the in-flight guard of the first. After a save *Today* kept the old definition and room, and the other rows of an all-rooms edit kept stale versions. The unit test mocked the guard (three tracks found it) | One refresh with the rows; a store test that runs the real guard |
| major (test) | replay guard of `room_task.edit` | No test replayed a committed save after the member left, became a child or lost sight of the task | `RoomTaskEditGuardsTest` |
| minor (security) | `RoomTaskCommand`, `TaskService`, `RoomTaskEditService` | The three authorization findings of the decisions above | D-091-108 to D-091-110, each with integration tests |
| minor | `TaskService::validateTaskData` | Name and description without limits | D-091-111 |
| minor | `TaskForm.vue` | The save sent the store's current version, so a second click after `STALE_TARGET` overwrote another adult's change | The version the form was opened with; spec |
| minor | `SleepService::applyUnderLock` | The wake date was checked before the unchanged state: no save on a task's wake day | Unchanged sleep returns first; test |
| minor | `TaskFormPayload`, form | The stored first date was range-checked on every save: a never-completed task with an anchor older than a year could not be saved | The range holds for a changed date; the form sends the date only when it changed; tests |
| minor | `RoomTaskEditService::aggregate` | A save that woke a task and set a first date lost the date | Waking comes before the window change; test |
| minor | form model | A deleted category (shown as *Other*) counted as a change and asked a members-only save of a shared task for the scope; a bounded rule lost its mode on a rhythm change | Only changed fields are sent; `bounded` kept; specs |
| minor | `store/taskForm.js` | After a *this room* split the open row kept ticks under the old sub-task ids | The ticks are read again after a save; spec |
| minor | `TaskCreationService` | A created task stored `''` for an empty text, an edited one null | One value normalizer for create and edit; test |
| minor (logging) | `room_task.edit` | The save wrote sub-task definitions without the audit event `logging.md` requires | Audited command; test for the commit and the replay |
| minor (architecture) | room-task commands | The start of the aggregates, the sleep-switch validation, the member check and the single cycle-block read each existed three times; key parsing outside `CurrentCycle`; the closed-body loop copied | `RoomTaskCommand::lockTarget`, `SleepSwitch`, `AuthorizationService::assertMembersUnderLock`, `RoomTaskReadService::cycleBlockOf`, `CurrentCycle::dayOfKey`, `CycleProjection::isAwakeBonus` |
| minor (performance) | save, setup, opened row | Completion inputs and sub-tasks read several times per save; one lock statement per room of a shared definition; a history read per freshly created room task; three requests per opened row, a second history read after a delete, a catalog read by the filter, a linear definition lookup per row | Read once; one set lock; empty histories for new rows; budgets tightened (`EDIT_BUDGET` 28, `SPLIT_BUDGET` 29) and a budget for an all-rooms save; fewer requests, an id map of the definitions |
| minor (accessibility) | form, history, template fill, sub-task editor, postpone, dialogs | Focus fell to the page after a pick, a reorder, a removal, a press, a delete and a refused save; weekday buttons overlapped on narrow phones | Focus kept or handed on (`Modal` hands lost focus to the main region); the buttons wrap; specs |
| minor (dead code, docs) | removals of WP-B6 | `assertLifecycleVisibility`, two checklist mapper methods, unused exports and bindings; the store sketch, the visibility note, the lock table, the source tree and the size inventory of the durable documents | Removed; rewritten and re-measured |
| nit | texts | *Jede 2 Wochen*, *Zimmer* beside *Raum*, two names for the first date, two keys for *Sub-tasks* and *Not done yet*, the checklist wording of a sub-task message | Corrected; `Floor and room` as the caption |

### Findings routed

- Nothing went to the plan's findings or to `backlog.md`; no security finding stays open.
  RF-091-06 stays open for WP-B7.
- Not changed: the People panel keeps its place after its toggle in the tab order; a
  move into a room that already holds the definition answers the conflict also where that
  instance is hidden from the member (D-091-110).

### Verification

- Canonical stack (PostgreSQL 18.6, Nextcloud 35.0.3, this worktree): PHPUnit unit 1872
  and integration 707, no skips. The same suites on PostgreSQL, MariaDB 11.8.6, SQLite and
  the AIO row (matrix `r91b6b`, reports under `build/test-reports/`): every suite passed.
- The preservation proofs passed on the final tree: P1 on PostgreSQL, MariaDB and SQLite, P2, P3 and P4 on PostgreSQL, each with a clean teardown.
- Jest 186 suites / 2657 tests, ESLint, stylelint, the webpack build, php-cs-fixer (508
  files clean), `l10n:check-keys` (1286 keys), `agents:check`, `docs:encoding`,
  `backlog:check`, `git diff --check`, LF endings, the browser gate's self-test.
- Browser, focused on the changed surfaces (no retries, built from the reviewed tree):
  35 passed on `chromium` (`workflow-request-budgets` among them), 93 on
  `chromium-serial-db` (`core-household-journey`, `family-core-ux`, `family-rhythm`,
  `task-checklist-and-features`, `release-task-templates`, `settings`, `today-view`,
  `3-floor-house`, `task-deep-links`, `task-completion-full-flow`, `insights-flow`),
  `mobile-core` 11 each on `mobile-chromium` and `mobile-webkit`. No skips, no flaky
  results. The full five-project gate belongs to the WP-G qualification.

### Gates

- Build gate: Jest 186 suites / 2657 tests, ESLint, stylelint and the build green.
- PHPUnit, both suites without skips, unit 1872 and integration 707 on the canonical stack
  and on all four rows of the matrix.
- Focused browser specs green (see Verification).
- Checks: php-cs-fixer, `l10n:check-keys`, `agents:check`, `docs:encoding`,
  `backlog:check`, `git diff --check`, LF endings.

## Full (interim) - CS-PLAN-091 WP-B7, retiring the rest of the old surface

**Outcome:** `PASS-WITH-FIXES`. Interim review; the plan stays in `active/` and continues
with WP-C.

### Scope and capture

- Tier: Full, four read-only `reviewer` tracks (correctness, architecture, security and
  data flow, performance and code quality with the weight on wording) plus the main agent's
  own reading of the digest relevance, the replay of retained journal entries and the
  rewritten tests. Required: the package removes routes.
- Owning plan: [CS-PLAN-091](../../plans/active/cs-plan-091-simplified-core-releases-b-and-c.md)
  (WP-B7 retiring the rest of the old surface, with RF-091-06). CS-PLAN-080 is not touched.
- Branch `feat/simplified-core-bc` in its pinned worktree, base HEAD `f1d9d606` (the
  committed WP-B6, 4 ahead of `master`, 0 behind), the whole package uncommitted. Captured
  before the review: 66 paths (65 modified, 1 untracked, nothing staged), combined scope
  fingerprint `03727d98a1625c7eeb575508325a2d18a8c3f057b05ac1c63b610b3e43599f77` (SHA-256
  over the staged and unstaged binary diffs and every untracked path with its content hash).
- Before any change the build gate stood on the captured tree, and the provider matrix ran
  on it: PHPUnit unit 1847 and integration 697 on PostgreSQL, MariaDB, SQLite and the AIO
  row (`r91b7`), all green.

### Decisions

No question needed the operator. D-091-113 to D-091-119 accepted; D-091-120 (nothing
serializes a retained row), D-091-121 (the help says what the rules say) and D-091-122
(what the review added to the tests) recorded in the plan. RF-091-11 (two more routes
without a client, `PUT /pauses/{id}` and `GET /house/health`) was found while
implementing and waits for the operator.

### Findings fixed in the review

| Severity | Where | Finding | Fix |
|---|---|---|---|
| major | `HelpGlossary.vue`, user guide | *Overdue* said one red entry until the task is done; on a fixed day the entry ends when the next window opens | Both say so; spec pin |
| major | `docs/user-guide.md` | The roles table still gave owners *Plan and apply the week* | Row corrected |
| major | `docs/engineering/notifications.md`, `docs/concepts/notifications.md` | The digest documents still described occurrence reads, agreed catch-up and agreement recordings | Rewritten to the open cycles of today and the three queued kinds; retained agreement rows named |
| minor | `RecurrenceCalculatorTest` | The lookup of the fixed rules was compared with its own output beyond the first days | A per-day reference from the rule's definition, compared through March 2028 |
| minor | `TaskAuthorizationTest` | The only real-database check that a room-scoped read hides a private task went with the statistics route | The check moved to `GET /room-tasks?roomId=` |
| minor | history entities | `jsonSerialize()`, the key helpers, `weekdayList()` and `Agreement::DEFINITION_KINDS` had lost their last caller but stayed, while `Occurrence::MAX_ORDINAL` went | Removed with their two tests (D-091-120) |
| minor | `NotificationPreferences.vue` | The hint of the organization digest promised every work recorded for one's tasks; only an overdue task someone else did is queued | Hint corrected |
| minor | `HelpGlossary.vue` | *Absence*: nothing counts for or against anyone; only a window entirely inside the absence is neutral, for that person. *Streak*: read as if early work or a day without tasks broke it | Both reworded (D-091-121) |
| minor | `TaskEnergyField.vue` | *The planner only places a task on a day…*: there is no planner and nothing is refused | Hint says what the weekly plan shows |
| minor | `l10n/de.json` | The glossary said *Fenster*, the task row *Zeitraum*; the ranking text said *gemeinsame* for public tasks | *Zeitraum* and *öffentliche Aufgaben* |
| minor | `architecture.md`, `testing.md`, `development.md` | The notification catalog, three test descriptions and the list of lock primitives named removed code | Corrected |
| nit | comments, tests | Stale comments in `RoomTaskService`, `ScheduleRevision`, `Occurrence`, `Agreement`, `RoomTaskChecklistProgressMapper` and six tests; dead helpers and a dead stub in `RoomServiceTest` and `RoomTaskServiceTest`; an unused import; the third copy of the retired-route guard; *today's agreements* in the UI use cases; two backlog status lines; D-091-113, the manifest and the test list of the plan | Corrected; `assertRouteStaysRetired()`; the help topics are checked for the retired words |

### Findings routed

- To the plan, for WP-C: RF-091-12 (the daily summary counts neither suggested nor overdue
  work yet, D-091-12) and RF-091-13 (the *Work shares* help sits on the capacity page). The
  WP-C scope names both.
- Open for the operator: RF-091-11.
- Routed while implementing, before the review: CR-SVC-67 (methods without a caller from
  before the plan), BUG-UX-017 (the organization digest is called weekly) and TECH-DEV-002
  (a rebuilt bundle is not fetched on the development stack).
- Not changed: the test ids `today-section-catchup` and `catchup-planned` keep their names;
  they are not user-visible. No security finding stays open; BUG-SEC-003 is older and not
  made worse.

### Verification

- Canonical stack (PostgreSQL 18.6, Nextcloud 35.0.3, this worktree): PHPUnit unit 1846
  and integration 698, no skips. The same suites on PostgreSQL, MariaDB 11.8.6, SQLite and
  the AIO row (matrix `r91b7c`, reports under `build/test-reports/`): every suite passed.
- The new digest test fails on the previous `DigestService` (`uncertain` instead of
  `skipped`) and passes on the changed one.
- The preservation proofs passed on the final tree: P1 on PostgreSQL, MariaDB and SQLite,
  P2, P3 and P4 on PostgreSQL, each with a clean teardown.
- Jest 186 suites / 2658 tests, ESLint, stylelint, the webpack build, php-cs-fixer (507
  files clean), `l10n:check-keys` (1283 keys), `agents:check`, `docs:encoding`,
  `backlog:check`, `git diff --check`, LF endings.
- Browser, focused on the changed surfaces (no retries, built from the reviewed tree): 126
  passed on `chromium-serial-db` (`quiet-digest`, `family-rewards`, `calm-core-ui`,
  `settings`, `today-view`, `task-completion-full-flow`, `release-task-lifecycle`,
  `core-household-journey`, `insights-flow` among them), 23 on `chromium` (the request
  budgets and the contrast among them), 30 on `chromium-scheduling-owner`, `mobile-core`
  11 each on `mobile-chromium` and `mobile-webkit`. No skips, no flaky results. The full
  five-project gate belongs to the WP-G qualification.

### Gates

- Build gate: Jest 186 suites / 2658 tests, ESLint, stylelint and the build green.
- PHPUnit, both suites without skips, unit 1846 and integration 698 on the canonical stack
  and on all four rows of the matrix.
- Focused browser specs green (see Verification).
- Checks: php-cs-fixer, `l10n:check-keys`, `agents:check`, `docs:encoding`,
  `backlog:check`, `git diff --check`, LF endings.

## Full (interim) - CS-PLAN-091 WP-B7 follow-up, the two other routes without a client

**Outcome:** `PASS-WITH-FIXES`. Interim review; the plan stays in `active/` and continues
with WP-C.

### Scope and capture

- Tier: Full, four read-only `reviewer` tracks (correctness, architecture, security and
  data flow, performance and code quality) plus the main agent's own reading of the
  renamed guard and the statistics. Required: the package removes routes and a command.
- Owning plan: [CS-PLAN-091](../../plans/active/cs-plan-091-simplified-core-releases-b-and-c.md),
  finding RF-091-11 of WP-B7, decided by the operator on 2026-11-21: retire both routes
  (D-091-123).
- Branch `feat/simplified-core-bc` in its pinned worktree, base HEAD `b35ab2fd` (the
  committed WP-B7, 5 ahead of `master`, 0 behind), the package uncommitted. Captured
  before the review: 28 modified paths, nothing staged, combined scope fingerprint
  `839c05c470b549a7e0485576979a078ee1848b9cb12cb4b80a1bf81b7d2de6a2`.
- Before any change of the review the build gate stood on the captured tree and the
  provider matrix ran on it: PHPUnit unit 1837 and integration 689 on PostgreSQL, MariaDB,
  SQLite and the AIO row (`r91b7d`), all green.

### Decisions

- Operator, before the implementation: both routes go (D-091-123).
- Implementation, accepted by the review: the statistics compute the house health
  (D-091-124); the tests that used the shortening as their vehicle (D-091-125). No further
  question needed the operator.

### Findings fixed in the review

| Severity | Where | Finding | Fix |
|---|---|---|---|
| minor | `PauseAbsenceTest` | The converted projection test seeded a pending shortening for an adult and had the owner approve it: a shape that never existed, and the same path as the test next to it | The revision is stored as the command left it (acknowledged, decided by its subject) |
| minor | `OwnerCommandAuthorizationTest` | A demoted or departed owner ending a household absence was only covered through the shortening | The deletion of a household absence joined the lost-authority cases |
| minor | ADR-020, API reference, architecture, `HouseService` | The status note let pending and declined shortenings govern; the stored house health was called the value of the last reset, while a retained installation holds the last refreshed mean | Reworded in all places |
| minor | `ui-use-cases.md`, `release-test-plan.md` | The absence row, the pause lifecycle check and the route surface still named the shortening and `house#health` | Corrected |
| nit | `RoomReplayConcurrencyTest` | The health assertion of the reset replay could no longer fail on its own | A stored value the replay would overwrite, next to the computed one |
| nit | `RouteMatrixTest`, plan | A retired route was said to answer 404; `PUT /pauses/{id}` answers 405, because DELETE keeps the path | Both say so |
| nit | comments, imports | An unused import, two ragged comment blocks, a store comment, *two routes* for four | Corrected |

### Findings routed

- Nothing new went to the plan or to `backlog.md`. The stored `house_health` column and
  the `houseHealth` field of the house payload were folded into FEAT-UX-071 while
  implementing. RF-091-12 and RF-091-13 stay open for WP-C.
- Observed on the development stack: after a route is removed, the running web server keeps
  the cached route and answers 500 until it is restarted; a release changes the app version
  and rebuilds the cache. The `docker-dev` skill names the restart for removed routes too.

### Verification

- Canonical stack (PostgreSQL 18.6, Nextcloud 35.0.3, this worktree): PHPUnit unit 1837
  and integration 693, no skips. The same suites on PostgreSQL, MariaDB 11.8.6, SQLite and
  the AIO row (matrix `r91b7e`): every suite passed.
- On the running stack after a web server restart: `GET /house/health` 404,
  `PUT /pauses/{id}` 405, the two routes of WP-B7 404; `GET /house/stats` reports 63 on the
  family seed, where it reported the stored 100 before.
- The preservation proofs passed on the final tree: P1 on PostgreSQL, MariaDB and SQLite,
  P2, P3 and P4 on PostgreSQL, each with a clean teardown.
- Jest 186 suites / 2658 tests, ESLint, php-cs-fixer (507 files clean), `l10n:check-keys`
  (1280 keys), `agents:check`, `docs:encoding`, `backlog:check`, `git diff --check`, LF
  endings. No file under `src/` changed.
- Browser, focused on absences, Insights and the settings (no retries): 85 passed on
  `chromium-serial-db` (`calm-core-ui`, `family-rhythm`, `family-core-ux`,
  `insights-flow`, `settings`, `today-view`, `multi-user-interactions`, `quiet-digest`,
  `core-household-journey`), 10 on `chromium`, `mobile-core` 11 each on
  `mobile-chromium` and `mobile-webkit`. No skips, no flaky results.

### Gates

- Build gate: Jest 186 suites / 2658 tests and ESLint green; no frontend source changed.
- PHPUnit, both suites without skips, unit 1837 and integration 693 on the canonical stack
  and on all four rows of the matrix.
- Focused browser specs green (see Verification).
- Checks: php-cs-fixer, `l10n:check-keys`, `agents:check`, `docs:encoding`,
  `backlog:check`, `git diff --check`, LF endings.
