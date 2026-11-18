# Code Review Record — 2026-11-18

## Full (interim) - CS-PLAN-091 WP-0 and WP-B1, derived due windows

**Outcome:** `PASS-WITH-FIXES`. Interim review; the plan stays in `active/` and continues
with WP-B2.

### Scope and capture

- Tier: Full, four read-only `reviewer` tracks (correctness, architecture, security and
  data flow, performance and code quality) plus the main agent's own reading of the window
  core, the cutover and the completion path. Required: the package adds schema steps,
  retires routes and changes authorization-relevant completion and reward facts.
- Owning plan: [CS-PLAN-091](../../plans/active/cs-plan-091-simplified-core-releases-b-and-c.md)
  (WP-0 contracts, WP-B1 window core). The second active plan, CS-PLAN-080, is not
  reviewed; one finding is routed to it.
- Branch `feat/simplified-core-bc` in its pinned worktree, base HEAD `2b9efaf2` (equal to
  `master`), the whole package uncommitted. Captured before the review: 253 paths (197
  modified, 34 deleted and 1 added in the index, 21 untracked; 232 tracked files with
  +5,985/−27,339 lines), combined scope fingerprint
  `67c30a1167f6c26638b5516eeba30b0cd97a8c74dc98884398525dfa424a991e` (SHA-256 over
  branch, HEAD, the staged and unstaged binary diffs, the staged path list and every
  untracked path with its content hash).

### Decisions (operator, recommended option each)

| Question | Decision |
|---|---|
| Work done on the update day after the update counted as missed (`cycles_from` = the day after) | **D-091-41:** the derived model starts on the house-local day of the update; amends D-091-04 |
| Fix the verified correctness defects now or route them | Fix all now; route refactors, performance work and the recovery fixture to the plan |
| Bonus tasks could be completed any number of times a day | **D-091-42:** the day rule holds for bonus tasks until WP-B4 brings the per-person rule |
| Security and privacy fixes | Authorized: erasure of the new tables, `firstDate` validation (**D-091-45**), no miss before a room task's creation (part of **D-091-43**) |

D-091-43 (misses recorded by the command that would lose them) and D-091-44 (the anchor
policy of rule edits) record how the approved fixes work. The implementation decisions
D-091-24 to D-091-40 are accepted; D-091-28, D-091-31 and D-091-38 are refined or extended.

### Findings fixed in the review

| Severity | Area | Issue | Fix |
|---|---|---|---|
| block | `WindowService::anchoredCycle()` | Two early completions before a future first date brought the fulfilled first cycle back; every later completion answered `ALREADY_COMPLETED` (also cutover tasks anchored on a future appointment) | The anchor counts as used once any valid completion fulfilled it; `WindowServiceTest` regression |
| major | `Version1036`, recognitions | Windows ending on the update day belonged to stored occurrences that can no longer be fulfilled: on-time work after the update counted as missed in that week | D-091-41; `CycleCutoverStepTest`, `RecognitionSourcesTest` and the P4 verifier |
| major | `CycleService` | A fixed cycle was lost when the next one was completed before the job recorded the miss, or when an older fixed completion was undone while a later one stayed valid | D-091-43: the completion records the misses first, the undo records its cycle; `CycleTimelineTest` regressions |
| major | `TaskService` rule edit | A rule edit anchored a fixed rule on the edit day: fortnightly parity flipped, a monthly rule moved to the edit day, bounding a rule moved it | D-091-44 in `CycleService::ruleChangedUnderLock()`; `CycleTimelineTest` regression |
| major (security) | `CycleService::resolveTargetUnderLock()` | Bonus tasks paid coins and arcade progress without any limit per day | D-091-42; `CycleTimelineTest` regression |
| major | tests | `PauseAgreementConcurrencyTest` was deleted whole, with three races of the retained `PauseService` (shortening race, two-connection replay, injected rollback) | Restored as `PauseConcurrencyTest` |
| minor (privacy) | `AccountErasureService` | `checked_by_user_id` of the per-cycle ticks (and of the retained ticks) survived erasure; the orphan-wallet sweep left the wallet in `chorestrike_cycles.responsible` | Anonymized and swept; `AccountErasureTest` regressions |
| minor (validation) | `TaskCreationService` | An impossible `firstDate` (`2026-02-31`) surfaced an untranslated message with the input; no range; a past first date wrote misses that broke an assignee's streak | D-091-45 and the creation-day bound of D-091-43; unit and integration regressions |
| minor | `CycleRule` | Active bounds shifted one day in houses west of UTC | Read as calendar days; `WindowServiceTest` regression |
| minor | `Version1036` | The anchor of a never-done task took an appointment a restart had replaced | Restart predecessors excluded; mirrored in `upgrade-cycles.php`; cutover regression |
| minor | completion and attribution responses | A replay answered `cycle: null` and an attribution sent `cycle: null`, which wiped the client's target | The replay carries the fresh block, the attribution none; controller test updated |
| minor | `errorToast.js` | `SAME_DAY` and `ALREADY_COMPLETED` fell through to the generic toast; the dead `NOT_OPEN_YET` branch stayed | Own translated messages (DE/EN), dead branch and key removed |
| minor (performance) | `Version1035`, `CycleMapper` | No index for the weekly-goal week scan; the undo's delete scanned the table | `idx_cycle_house_week` (guarded, so a database with an earlier draft converges); the delete is scoped by room task; the extra reads run for fixed rules only |
| minor | API reference, ADRs, guides | Unshipped routes and fields read as delivered; stale writer lists, budgets, evidence rows, status lines and wording | Marked as planned or corrected (API reference, ADR-009/011/014/021/022, ADR index, notifications, testing, release test plan, architecture) |
| nit | stores, PHPDoc, comments | `logger.warn` called with three arguments (context lost), comments and a log message naming the retired planner, missing PHPDoc | Fixed |

Not changed: unlimited early completion of fixed cycles, one per day, follows the
concept's rule ("early completion is always possible, except on the same local day");
the reviewer's note that such work counts in the rhythm before its day is recorded here
as an observation only.

### Findings routed

- Plan-owned, open: RF-091-02 (the recovery fixture of P1 to P3 still seeds the retired
  model), RF-091-03 (the recognitions rebuild the open cycles per week), RF-091-04 (read-path
  redundancies and the missing list budget), RF-091-05 (structure: `CycleService` job pass,
  the three responsibility resolutions, duplicated helpers), RF-091-06 (code without a
  caller beyond D-091-38, for WP-B7).
- CS-PLAN-080: RF-080-18 (its required matrix names suites WP-B1 retired).
- `backlog.md`: BUG-SEC-003 (the orphan-wallet sweep leaves stored appointment links,
  older than this plan); CR-SVC-66 folded in (the same regrouping cost per window day).

### Verification

- Canonical stack (PostgreSQL, this worktree): PHPUnit unit 1616 and integration 629, no
  skips. The canonical database had applied an earlier draft of the unapplied
  `Version1035`; the step's schema part was executed again there so the new index exists
  (development volume only, no reseed).
- Proof P4 (app upgrade from the retained baseline): SQLite, MariaDB, PostgreSQL and the
  AIO row green after the last step change; the three new steps applied, 30 row sets
  identical, the cutover values verified, a second upgrade without work.
- Provider matrix: MariaDB, SQLite and the AIO row (see Gates).
- Jest 177 suites / 2438 tests, ESLint, stylelint, `l10n:check-keys` (1223 keys),
  php-cs-fixer (450 files clean), `agents:check`, `docs:encoding`, `backlog:check`,
  `git diff --check`.
- Browser: the focused specs of the changed paths (see Gates).

### Gates

- Build gate: Jest 177 suites / 2438 tests, ESLint and stylelint green.
- PHPUnit, both suites without skips, unit 1616 and integration 629 on every row: the
  canonical stack (PostgreSQL 18.6, Nextcloud 35.0.3) and the matrix run `r91irv` on owned
  fresh installations — MariaDB 11.8.6 and SQLite on `cs082-runtime-35.0.3-local`
  (`sha256:0e199781861c`), PostgreSQL with Redis on the AIO row
  `cs082-runtime-35.0.2-aio-local` (`sha256:79b4767be1ba`). The rows were removed after the
  run (no container, volume or network left).
- Proof P4 on SQLite, MariaDB, PostgreSQL and the AIO row, rerun after the last change of
  `Version1035`; evidence under the gitignored `private/`.
- Browser, focused on the changed paths (`chromium-serial-db`, no retries):
  `task-completion-full-flow` 8 passed, `insights-flow`, `today-view` and `family-rhythm`
  30 passed. The full five-project gate of the implementation (353 tests) was not repeated:
  the review's client changes are a toast mapping, logger arguments and comments, all
  covered by Jest.
- Checks: `l10n:check-keys`, php-cs-fixer, `agents:check`, `docs:encoding`,
  `backlog:check`, `git diff --check`, LF endings of the new files.

## Full (interim) - CS-PLAN-091 WP-B2 and WP-B3, recording, postpone and history

**Outcome:** `PASS-WITH-FIXES`. Interim review; the plan stays in `active/` and continues
with WP-B4 and WP-B5.

### Scope and capture

- Tier: Full, four read-only `reviewer` tracks (correctness, architecture, security and
  data flow, performance and code quality) plus the main agent's own reading of the
  authorization paths, the date correction and the completion command. Required: the
  package adds routes, changes completion rights and reward facts, and widens who may be
  credited.
- Owning plan: [CS-PLAN-091](../../plans/active/cs-plan-091-simplified-core-releases-b-and-c.md)
  (WP-B2 recording for others, WP-B3 postpone and history, with RF-091-02 to RF-091-05 of
  the previous review). CS-PLAN-080 is not touched.
- Branch `feat/simplified-core-bc` in its pinned worktree, base HEAD `335cb162` (the
  committed WP-0 and WP-B1, 1 ahead of `master`, 0 behind), the whole package
  uncommitted. Captured before the review: 121 paths (98 modified, 23 untracked; +3,196/
  −1,349 lines in the tracked files), nothing staged, combined scope fingerprint
  `30fa2b6892aa5630b7445c960a3e21f37d7eb9709a5827c495a19b5f12505ef3` (SHA-256 over
  branch, HEAD, the staged and unstaged binary diffs and every untracked path with its
  content hash).

### Decisions (operator, recommended option each)

| Question | Decision |
|---|---|
| Security and privacy fixes (absence requests in the feed, the date correction's replay right, its translated refusals, visibility per room task, the history of a deleted task) | **D-091-59:** fix all now, each with a regression test |
| Correctness defects and the structure findings | **D-091-59:** fix the defects now; route the `CompletionService` extraction, duplicated helpers, budgets and accessibility polish (RF-091-07 to RF-091-09) |
| The sub-task ticks a completion set, after its undo | **D-091-60:** they stay; recorded in ADR-021 and pinned by a test |
| Takeover facts after a date correction across late and on time | **D-091-61:** they stay as recorded; ADR-021 §9 and the API reference say so |

D-091-62 (the postponement stays with its cycle key) and D-091-63 (the date correction's
right in two parts) record how the approved fixes work. The implementation decisions
D-091-46 to D-091-58 are accepted; D-091-54 is reworded to the implemented payload.

### Findings fixed in the review

| Severity | Area | Issue | Fix |
|---|---|---|---|
| major (privacy) | `ActivityEventService` absences | A withdrawn request that was never acknowledged showed as "absence removed" to every member, with the person and the dates (pending requests are the requester's and the owner's alone); a request acknowledged later never showed | Tracked by the flag, not by key existence; `ActivityEventServiceTest` |
| major | `CycleService::closeUnderLock()` | An undo lost the postponement of the cycle it reopened: the task could turn red at once, a redo could count as late and the limit of three presses reset | D-091-62; `PostponeAndHistoryTest` (undo reopens the postponed cycle with its count; the next window of an after-completion rule hangs on the completion) |
| minor (security) | `CompletionService::changeDate()` | The latest-entry right ran before the receipt lookup, so a replay answered 403 once a newer entry existed; a replay checked no right under the lock | D-091-63: identity before the command and as the under-lock guard, the latest entry inside; the date path joins the unit correction matrix (role, visibility, membership, each under lock and on replay) with replay and latest-entry regressions |
| minor (security) | `ActivityService`, `ActivityEventService` | Titles, flags and the class of feed rows and events were decided by the assignees of the whole definition, not of the room task (ADR-010) | `TaskAccessPolicy::filterVisibleRoomTasks()` in both; unit regressions |
| minor | `CompletionController::changeDate()` | The translated `400` refusals reached the client as the generic fallback | `ControllerErrorFormatter::validation()`; the controller tests assert the message |
| minor (security) | `ActivityService::getRoomTaskHistory()` | The history of a deleted room task stayed readable; the API reference promised `404` | A tombstone answers `404`; unit regression |
| minor | `CycleService::redatedUnderLock()` | A date correction could end a bounded task for good, and moving its last entry earlier never reopened it | Reactivated as the undo does, the derivation decides; integration regression |
| minor | `ActivityEventService::eventsSince()` | The per-member filter ran after the house-wide limit, so a member's older events dropped out | The actor filter in both reads, before the limit; unit regression |
| minor | `ActivityFeed.vue` | An absence line named who entered it, not the absent person; the line was composed of fragments | Four whole-sentence keys with the person (DE/EN); spec |
| minor | `TaskHistory.vue` | Stale after a completion or undo elsewhere; a stale *Delete* then got `403` | Reads again on the completion and undo events of its task; spec |
| minor | `CompletionPicker.vue` | Opened empty when the members were not read yet; the recorder's preselection was read once | Reads the people before the dialog builds its rows; spec |
| minor | `taskCycle.js` | A retry after an uncertain postpone or date change sent a new command id | Pending command ids as in `roomTasks.js` (RF-072-WP1-07); spec |
| minor | tests | D-091-56 (the owner's attribution credits nobody who may not see the task) had no test | Unit and integration tests |
| minor | `ActivityService` | An undefined-key warning for every entry of a departed recorder | `?? null`; unit regression |
| minor (logging) | `CompletionPicker.vue`, `PostponeButton.vue`, `TaskHistory.vue` | A raw `ApiError` inside a log context; failures logged twice | Status and code only; the stores log once |
| nit | docs, l10n, code, tests | Stale undo docblock; D-091-54 promised the next window in the history answer; the source tree and store boundaries lacked the new units; "Münzen" against the product term "Coins"; a raw `crypto.randomUUID()` left in `house.js`; a fixture of `InsightsView.spec.js` read the clock twice and failed when the feed sorted the rows a millisecond apart | Fixed |

### Findings routed

- Plan-owned, open: RF-091-07 (structure of the correction commands: the date correction
  out of `CompletionService`, one correction skeleton, duplicated rules and helpers),
  RF-091-08 (request budgets of the new reads and commands, redundant reads, the unbounded
  absence read), RF-091-09 (client structure and accessibility of the cycle surfaces, with
  WP-B6). RF-091-06 stays open for WP-B7.
- Nothing went to `backlog.md`; no finding was rejected.

### Verification

- Canonical stack (PostgreSQL 18.6, Nextcloud 35.0.3, this worktree): PHPUnit unit 1712
  and integration 653, no skips. No schema step, so the provider matrix and proof P4 were
  not repeated; the review's SQL adds two optional equality filters with named parameters.
- Jest 181 suites / 2503 tests, ESLint, stylelint, the webpack build, php-cs-fixer (465
  files clean), `l10n:check-keys` (1256 keys), `agents:check`, `docs:encoding`,
  `backlog:check`, `git diff --check`, LF endings.
- Browser, focused on the changed surfaces (no retries, built from the reviewed tree):
  `family-core-ux`, `insights-flow`, `task-completion-full-flow`, `today-view` and
  `family-rhythm` 48 passed on `chromium-serial-db`; `mobile-core` (the picker, postpone,
  history and recent activity on a phone) 19 passed on `mobile-chromium` and
  `mobile-webkit`. No skips, no flaky results. The full five-project gate belongs to the
  WP-G qualification.

### Gates

- Build gate: Jest 181 suites / 2503 tests, ESLint, stylelint and the build green.
- PHPUnit, both suites without skips, unit 1712 and integration 653 on the canonical stack.
- Focused browser specs green (see Verification).
- Checks: php-cs-fixer, `l10n:check-keys`, `agents:check`, `docs:encoding`,
  `backlog:check`, `git diff --check`, LF endings.
