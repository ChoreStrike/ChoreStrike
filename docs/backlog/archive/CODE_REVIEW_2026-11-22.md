# Code Review Record — 2026-11-22

## Full (interim) - CS-PLAN-091 WP-C, the live suggestion and preferred days

**Outcome:** `PASS-WITH-FIXES`. Interim review; the plan stays in `active/` and continues
with RF-091-14, then the WP-G candidate.

### Scope and capture

- Tier: Full, four read-only `reviewer` tracks (correctness, architecture, security and
  data flow, performance and code quality) plus the main agent's own reading of the routes,
  the schema step and the authorization of the suggestion read, `filtered-daily`, the
  daily summary and the preference writes. Required: the package adds a route and a schema
  step and changes what each member is shown. The operator asked for the weight on routes,
  the migration and authorization, and accepted the GUI as it is.
- Owning plan: [CS-PLAN-091](../../plans/active/cs-plan-091-simplified-core-releases-b-and-c.md)
  (WP-C, the live suggestion and preferred days, with RF-091-12 and RF-091-13 of the WP-B7
  review). CS-PLAN-080 is not touched.
- Branch `feat/simplified-core-bc` in its pinned worktree, base HEAD `53946b1d` (the
  committed RF-091-11 follow-up, 6 ahead of `master`, 0 behind), the whole package
  uncommitted. Captured before the review: 121 paths (93 modified, 6 deletions staged,
  22 untracked), combined scope fingerprint
  `cc170a799ca713191a9afcd03851a7e6625a6c18dfa45e89a884018a9121d59a` (SHA-256 over the
  staged and unstaged binary diffs and every untracked path with its content hash).
- Before any change the gates of the implementation round stood on the captured tree:
  Jest 186 suites / 2651 tests, ESLint, PHPUnit unit 1864 and integration 717 on the
  canonical stack.

### Decisions

| Question | Decision |
|---|---|
| GUI review of *Today* and the calendar | **D-091-136 (operator):** accepted as it is; the month keeps its grid |
| The placement counts private work: loads, the owner's read of every member's load and the *does not fit* mark of someone else's entry can reflect work the viewer may not see (ADR-010: private even from the owner) | **D-091-140 (operator):** keep the loads and marks complete and document the aggregate in ADR-022 §6 and ADR-010; no single private task is ever named |
| `security.md` forbids state changes on GET; the day's first read stores the suggestion cache (ADR-022 §3, D-091-18) | **D-091-141 (operator):** the guideline names this cache as its one exception, with the conditions that keep it acceptable |
| Nine verified logic defects (below) | **Operator:** fix all now, with regression tests (D-091-137 to D-091-139) |
| Structure and performance findings without wrong behaviour, apart from the overload line of *Today* | **D-091-142 (operator):** RF-091-14, a follow-up round before the WP-G candidate with its own interim review |

D-091-126 to D-091-135 are accepted. D-091-143 records what the review added and what it
left as it is.

### Findings fixed in the review

| Severity | Where | Finding | Fix |
|---|---|---|---|
| major | `SuggestionPlanner::choose()` | When a cycle fit nobody, a member with the day off (0 left) beat an overloaded available member: unassigned and team work went to someone who has the day free, and a child's free day hid it from everyone (ADR-022 §2 rule 2) | Overload ranks fits, then available, then below the limit, then remaining energy (D-091-137); planner test |
| major (test) | `RoomTaskReadService::openRowsOf()` | *Today*'s visibility filter moved to a new path; no test read it with a task that is not public (the unit test stubs `canView`) | Integration test with private, admin-only and child cases for every role |
| minor | `SuggestionPlanner::choose()` | A child at its daily limit with energy left beat overloaded adults; the unassigned work then stood on nobody's *Suggested* list (ADR-022 §2 rule 5) | Same order (D-091-137); planner test |
| minor | `SuggestionService::view()` | The day's first read returned the plan made with today open; every later read plans from the stored list, so a reload could show later work on another person | Every read places from the stored list (D-091-138); integration test |
| minor | `SuggestionService::todaysList()` | A stored entry whose window an edit moved off today stayed pinned: *Today* dropped it, the calendar and the daily summary kept it | The entry leaves when its window no longer contains today (D-091-138); integration test |
| minor | `SuggestionPlanner` joiners | A task created after the row that nobody could take today (absent, done today) was placed on no day | It keeps the rest of its window from tomorrow on (D-091-138); unit and integration tests |
| minor | `GET /scheduling/suggestion`, `CalendarView` | A tab left open past house-local midnight sent yesterday as `from`; the `400` replaced the whole week with the error state. The check also ran after the day's row was stored | The range is cut to the horizon before anything is read or stored; only a reversed range is refused (D-091-139); integration tests |
| minor | `SuggestionService::view()` | A stored row that decodes to no list broke *Today* with a `TypeError` for the rest of the day | Read as an empty list (D-091-138); integration test |
| minor | `AvailabilityEditModal.vue` | A failed read after a committed save showed *Failed to save* and kept the dialog open | The save and the read after it are handled apart (D-091-139); specs |
| minor (logging) | `SuggestionService`, `SuggestionPlanner` | The lost first-read race and the step bound (5,000) dropped work silently (logging.md: expected-race swallows at warning) | `suggestion_day_race_lost`, `suggestion_plan_truncated` with `SuggestionPlan::$truncated`; tests |
| minor (test) | `LiveSuggestionTest` | No test of an admin-only team task with an assignee who may not see it, of a carrier who left the house, of the lost race through `view()`, or of the empty overdue list of a later range | Added |
| minor (test) | `WorkflowQueryBudgetTest` | The day's first read ran uncounted | Pinned at 14 statements |
| minor | `CalendarAgenda.vue` | Today's bar compared only the placed energy with the day's, next to entries the server marked *does not fit* because of work already done | Today's bar counts `doneMinor` too, with its own label |
| minor (i18n) | `TaskEnergyField.vue`, `AvailabilitySettings.vue`, `SelfAvailabilitySettings.vue` | The energy hint still pointed at the weekly plan; *days available* was concatenated to its number | Copy names *Today* and the calendar; one placeholder key; German copy |
| minor (docs) | `energy-and-balancing.md`, `security.md`, API reference, ADR-022, ADR-010, architecture | Plan capacity and the capacity gates read as current; the leave and join rules lacked the handover and the moved window; the API promised an empty overdue list for absent assignees; the store contract named members nothing reads | Superseded notes, the rules, the decisions above, the store contract and the size inventory |
| nit | store, comments, PHPDoc | `maxDailyTasks`, `error` and `clearError()` of the scheduling store had no reader; stale *SchedulingView*, *weekly plan* and `'shares'` mentions; public members without PHPDoc; an incomplete `@return` shape; a second weekday list; mixed emit styles | Removed or corrected; `SchedulingService::WEEKDAYS` is `TaskService::WEEKDAYS` |
| nit | plan §3 | The snapshot was said to be written under the house lock | Insert-or-ignore, as D-091-129 says |
| nit | `RouteMatrixTest` | The new route was pinned only through the count | Named assertion |

### Findings routed

- **RF-091-14** (plan-owned, open, operator D-091-142): the daily summary computing the
  suggestion per member slot under the house lock with a second absence rule; the *Today*
  block of `RoomTaskReadService` and its second resolution of a child's availability and
  limit; *Today*'s own load from the client, with an overload line that counts team tasks
  and unassigned overdue work in full; the horizon copied in the client; rule-of-three
  helpers; the read contract and a placement value object; the planner's CPU cost and
  budgets; long methods.
- Left as it is (D-091-143): `SuggestionDayMapper::storeOnce()` reads the row back after
  its insert, one statement once per house and day.
- Nothing went to `backlog.md`.

### Verification

- Canonical stack (PostgreSQL 18.6, Nextcloud 35.0.3, this worktree): PHPUnit unit 1869 and
  integration 726, no skips. The same suites on PostgreSQL, MariaDB, SQLite and the AIO row
  (matrix `r91wc`): every suite passed.
- Each new regression test failed against the old behaviour before it passed: the overload
  order (two planner tests), the first read against a later one and an edit that moves a
  window off today.
- The preservation proofs passed on the final tree: P1 on PostgreSQL, MariaDB and SQLite,
  P2, P3 and P4 on PostgreSQL, each with a clean teardown. `Version1038` is unchanged since
  the implementation round, whose P4 ran on all four rows.
- Full browser gate, five projects in sequence without retries: the first run `run-DRAuF0`
  failed. `scheduling.spec.ts` still expected the `400` that D-091-139 replaced by the cut
  range (spec and required-scenario title corrected). `family-reward-api` and
  `setup-wizard-journey` each lost one request to `socket hang up` before any assertion,
  and one test was skipped behind it. The POST `/setup/apply` never reached the access log,
  no foreign container ran and no WP-C code is on either path; this is the TECH-TRANSPORT-001
  symptom and was folded into that item. The complete rerun `run-ULfOXm` passed: `chromium`
  104, `chromium-serial-db` 193, `chromium-scheduling-owner` 33, `mobile-chromium` 16 and
  `mobile-webkit` 16, no skipped, failed or flaky test.
- Jest 186 suites / 2652 tests, ESLint, stylelint, build, php-cs-fixer (523 files clean),
  `l10n:check-keys` (1296 keys), `agents:check`, `docs:encoding`, `backlog:check`,
  `git diff --check`, LF endings.

### Gates

- Build gate: Jest 186 suites / 2652 tests, ESLint, stylelint and the build green.
- PHPUnit, both suites without skips: unit 1869 and integration 726 on the canonical stack
  and on all four rows of the matrix.
- Preservation proofs P1 to P4 green; the full browser gate green (`run-ULfOXm`).
- Checks: php-cs-fixer, `l10n:check-keys`, `agents:check`, `docs:encoding`,
  `backlog:check`, `git diff --check`, LF endings.

## Full (interim) - CS-PLAN-091 RF-091-14, structure and performance of the live suggestion

**Outcome:** `PASS-WITH-FIXES`. Interim review; the plan stays in `active/` and continues
with the WP-G candidate.

### Scope and capture

- Tier: Full, four read-only `reviewer` tracks (correctness, architecture, security and
  data flow, performance and code quality) plus the main agent's own trace of the
  visibility decision once per room task, the authorization of `filtered-daily` through
  `TodayRows` and the digest's per-pass cache. Required: the round changes the answer of a
  route, the read path of every member's *Today* and the daily summary.
- Owning plan: [CS-PLAN-091](../../plans/active/cs-plan-091-simplified-core-releases-b-and-c.md)
  (RF-091-14, D-091-144 to D-091-152).
- Branch `feat/simplified-core-bc` in its pinned worktree, base HEAD `fe47009c` (the
  committed WP-C review, 7 ahead of `master`, 0 behind), the whole round uncommitted.
  Captured before the review: 81 paths (75 modified, one staged rename, 5 untracked),
  combined scope fingerprint
  `5de325926de3f014cb993ff7a7b80d87d5acdc47266b3bd196b39c4cf06ce15d` (SHA-256 over HEAD,
  the staged and unstaged binary diffs and every untracked path with its content hash).
- Before any change the gates of the implementation round stood on the captured tree:
  Jest 186 suites / 2659 tests, ESLint, stylelint, build, the zero-noise gate,
  php-cs-fixer, l10n, adapter, docs and backlog checks, PHPUnit unit 1885 and
  integration 729 on the canonical stack.

### Decisions

| Question | Decision |
|---|---|
| The digest's pass cache: a view built in a transaction that rolls back or retries was reused; later summaries count facts read under the first summary's lock | **D-091-153 (operator):** share a view only once the transaction that built it committed; accept that later summaries of the pass count facts read seconds earlier, and document it |
| Four client defects of the load of *Today* and the calendar | **D-091-154 (operator):** fix all four now, with Jest regression tests |
| Structure leftovers without wrong behaviour | **D-091-155 (operator):** fix now |
| A WP-B6 defect the first browser gate found: a form saved before its own sub-task read answered dropped the sub-task just added | **D-091-157 (operator):** keep the fix in this review and rerun the gate |

D-091-144 to D-091-152 are accepted, with the amendments the decisions above name.
D-091-156 records what the review added without a question.

### Findings fixed in the review

| Severity | Where | Finding | Fix |
|---|---|---|---|
| major | `useTaskFormState`, `TaskForm.vue` (WP-B6) | Saved before its own read of the sub-tasks answered, the form sent `checklistItems: null` (unknown, leave as is) and dropped the sub-task the user had just added; the read also started only after the members were loaded. Found by the review's first browser gate (`family-core-ux`) | A save waits for a read still under way, the read starts when the form opens, a list cached by another reader counts as known (D-091-157); two Jest tests, each failing on the old code |
| minor | `DigestService::viewsOfPass()` | The pass cached the view inside a slot's transaction. A deadlock retry, or a failure after the view was built, rolled back the day's stored list, yet the retry and later slots counted that plan; the next reader stored another list | The pass takes over a view only after the commit; a retried attempt starts afresh (D-091-153); unit tests for a retry and a failed attempt, each failing on the old code |
| minor (security-adjacent) | `DigestService` | Later summaries of a pass count absences, free days, assignees and takeover visibility read under the first summary's lock: a write that lands mid-pass is missed for the rest of the pass | Accepted as bounded staleness and documented in ADR-022 §7, the notification document and the class description (D-091-153) |
| minor | `store/roomTasks.js` `refreshDailyLoad()` | It reused the full read's sequence: an older full daily read that answered after it overwrote the fresher load | The load has its own generation (D-091-154); Jest |
| minor | `store/roomTasks.js` `refreshDerivedProjections()` | A row refresh on a shown *Today* without rows fetched the daily read and dropped its rows, so a task edited onto today stayed missing | The full read runs whenever *Today* holds its load (D-091-154); Jest |
| minor (perf) | `store/roomTasks.js`, `TodayView.vue` | Once *Today* was opened, every write in any view ran the whole daily read for a load nobody showed | *Today* forgets the load when it is left (`forgetDailyLoad()`) (D-091-154); Jest for the store and the view |
| minor | `CalendarView.vue` | A week wholly beyond the horizon now asks for the suggestion; a failure of that read alone replaced the week's due windows with the error | The error shows only for a week that reaches into the last known horizon (D-091-154); Jest |
| minor | `SuggestionService` | It depended on the 12-dependency `RoomTaskReadService` only to read rooms, which reversed the dependency and pulled the read cluster into the digest and the job | `RoomMapper::findByIds()` again, `roomsByIdOf()` private (D-091-155) |
| minor | `TodayRows::childRows()` | It reached into `view->plan->ledger` for the day gate and limit that `SuggestionView::countFor()` also checks | `SuggestionView::isAvailableToday()` and `limitOf()`; a child's free day returns before any room is read (D-091-155) |
| minor (test) | `TodayRowsTest` | The fixture built the plan's budgets from the stored preferences with the production rule, so the plan-versus-preferences claim of D-091-148 and the adult case could not fail | The plan's budgets differ from the stored ones; the plan wins, an adult is never cut |
| minor (test) | `DigestDeliveryTest` | The pass budget queued no takeover, so the visibility path never ran in the counted pass | A queued takeover, consumed in the pass, still one read of the room tasks |
| minor | `CalendarDayCell.vue` | A third copy of the short day label | `formatShortDayKey()` |
| minor (docs) | architecture | The service catalogue still gave `filtered-daily` to `RoomTaskReadService` and had no `TodayRows` entry | Corrected, with the client's load generation and the size inventory |
| nit | `DayKey::after()`, `RecurrenceCalculator::dayAfter()` | Two day-after helpers | The service helper delegates to the Db one |
| nit | `calendarAgenda.js` | `buildAgendaDays()` about 50 lines | Three entry mappers |
| nit (test) | `SuggestionService` | Nothing pinned that the rule of a room task is read once | `SuggestionServiceTest`, failing without the memo |
| nit (test) | `WindowServiceTest` | `successorOf()` had no clamped month day | Two cases |
| nit | PHPDoc, comments | `SuggestionPlacement` documented half its members; the takeover closure's type; `PauseProjectionService` still named the summary and the timeliness gate; a dead counter mark and a misplaced docblock in `WorkflowQueryBudgetTest`; `TodayRows::rowsOf()` public without a caller | Corrected; `rowsOf()` private |
| nit | visibility wrappers | Two public `visibleRoomTasks()` | Each says which input it takes |

### Findings routed

- None. RF-091-14 is complete; nothing went to `backlog.md`.

### Verification

- Canonical stack (PostgreSQL 18.6, Nextcloud 35.0.3, this worktree): PHPUnit unit 1891 and
  integration 729, no skips. The first integration run after the fix failed in
  `DigestConcurrencyTest` and `DigestDeliveryTest`: their reflective call of the removed
  `viewsOfPass()`; both now hand the slot an empty pass by reference.
- The same suites on PostgreSQL, MariaDB, SQLite and the AIO row (matrix `r91rv14`): every
  suite passed; the owned rows were removed afterwards.
- Each new regression test of the digest, the memo and the form failed against the old code
  before it passed.
- The preservation proofs passed on the final tree: P1 on PostgreSQL, MariaDB and SQLite,
  P2, P3 and P4 on PostgreSQL, each with a clean teardown. No schema step.
- Full browser gate, five projects in sequence without retries: the first run `run-5oP0R0`
  failed one required scenario of `family-core-ux` (`chromium-serial-db` 192 passed and 1
  failed, the other four projects green). Its trace showed the save sending
  `checklistItems: null` 36 ms before the form's own read of the sub-tasks answered: the
  WP-B6 defect of D-091-157, no transport failure. After the fix the complete rerun
  `run-LPQb6u` passed: `chromium` 104, `chromium-serial-db` 193,
  `chromium-scheduling-owner` 33, `mobile-chromium` 16 and `mobile-webkit` 16, no skipped,
  failed or flaky test.
- Jest 186 suites / 2666 tests, the zero-noise gate, ESLint, stylelint, build,
  php-cs-fixer (529 files clean after a scoped fix of two docblock alignments),
  `l10n:check-keys`, `agents:check`, `docs:encoding`, `backlog:check`,
  `git diff --check`, LF endings.

### Gates

- Build gate: Jest 186 suites / 2666 tests, ESLint, stylelint and the build green.
- PHPUnit, both suites without skips: unit 1891 and integration 729 on the canonical stack
  and on all four rows of the matrix.
- Preservation proofs P1 to P4 green; the full browser gate green (`run-LPQb6u`).
- Checks: php-cs-fixer, `l10n:check-keys`, `agents:check`, `docs:encoding`,
  `backlog:check`, `git diff --check`, LF endings.
