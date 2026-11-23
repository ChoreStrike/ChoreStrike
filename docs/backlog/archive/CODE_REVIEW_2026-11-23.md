# Code Review Record — 2026-11-23

## Full Review (final) - CS-PLAN-091 Simplified Core Releases B and C

**Outcome:** `PASS-WITH-FIXES`. The final Full review the plan requires before the
qualification; the plan stays in `active/`, because the qualification, the export and the
deployment of `0.8.0-alpha.5` are open.

### Scope and capture

- Requested by the operator on 2026-11-23 as the next step of the plan, after accepting the
  GUI of the whole release on the canonical stack (D-091-172).
- The whole plan: `master..8b8230c9` on branch `feat/simplified-core-bc`, ten commits (WP-0
  to WP-C, RF-091-11, RF-091-14, the round of D-091-158 and the WP-G candidate), 582 files
  (160 added, 358 modified, 64 removed), plus the plan's decisions, manifest, acceptance
  criteria and durable-doc integration.
- Merge base `2b9efaf2` (`master`), HEAD `8b8230c9`, pinned worktree, 10 ahead and 0 behind
  `master`, never pushed. Clean working tree and empty index at capture; diff fingerprint
  `ce23b5da24436dbc8475aaa69317f0d8bf7a30388acaeead67f7c5eb68534fd1` (SHA-256 of
  `git diff master...8b8230c9`).
- Owning plan [CS-PLAN-091](../../plans/active/cs-plan-091-simplified-core-releases-b-and-c.md);
  the other active plan, CS-PLAN-080, owns none of these files.
- Four read-only reviewers in parallel (correctness, architecture, security and data flow,
  performance and quality), each told what the eight interim reviews had settled and asked
  to weigh the seams between the packages, the candidate's documents against the code, and
  the release as a whole; every finding re-checked against the code before routing. A static
  sweep found no `console.*`, `v-html`, `TODO`/`FIXME`/`HACK`, `@PublicPage`, focused or
  skipped test in the changed files. Only the steps 1035 to 1038 are new under
  `lib/Migration`; the 26 applied migrations and `Version1034` are unchanged. All sixteen
  plan-owned findings (§9) were already resolved.

### Decisions

| Question | Decision |
|---|---|
| The ticks of one room task followed the definition's visibility, and refusals reached the toast unescaped | **D-091-173 (operator):** fix both now |
| Eight verified defects (the create answer, a correction after a move, a postponed row, a child's count, a reopened miss, the German plural, the untranslated conflict, the UTC year) | **D-091-174 (operator):** fix all now, each with a regression test |
| Two rare corrections of a fixed rhythm lose a cycle or its postponement; a fix reaches deep into the derivation | **D-091-175 (operator):** known limits in ADR-021 and [BUG-UX-018](../backlog.md#p3-bug-ux-018--two-rare-corrections-of-a-fixed-rhythm-lose-a-cycle-or-its-postponement) |
| Repeated reads, the erasure's batching, method sizes, and receipt hashes of erased members | **D-091-176 (operator):** to the backlog, the receipt hashes as a sanitized public entry |

D-091-177 records what the review fixed without a question.

### Findings fixed in the review

| Severity | Track | Where | Finding | Fix |
|---|---|---|---|---|
| major (security, authorization) | security | `ChecklistService::assertRoomTaskAccessible()` | The progress read and the toggle of one room task checked the definition's visibility, which counts the assignees of every room: a member assigned to one room's copy of a shared private definition could read another room's window and ticks and tick there | Visibility per room task through `RoomTaskAccess::visible()`, as every other room-task command; ADR-010 and the API reference say so (D-091-173); an integration test fails on the old code |
| minor (security) | security | `errorToast.js` `showRefusal()` | The library's "plain text" decodes its input once through `innerHTML`, so markup in a server's refusal would run; today only the caller's own input reaches it | The reason is escaped before the toast (D-091-173); a test decodes it as the library does and finds no element |
| major | performance | create answer, `addCreatedRoomTask()` | A new row had no `cycle` block: its first edit answered `409 STALE_TARGET`, and postpone, sleep and completion had no target until a reload | The create answer carries the row's `cycle` block (`committedRoomTaskWithCycle()`), and the store keeps it (D-091-174) |
| major | correctness | `CompletionAccess::lockTarget()` | After a move an undo or a date correction rebuilt the task's new room; the room where the work was done kept the undone gain and its *Last cleaned* | The room of the entry's journal event is locked and rebuilt (D-091-174); an integration test fails on the old code; the undo budget rises by one statement to 73 |
| major (i18n) | performance | `check-l10n-keys.js`, `l10n/de.json` | The gate collected only the singular of a plural, so the German digest read *3 planned tasks* (older than this plan) | Both keys collected, `%n` known as a placeholder, *%n geplante Aufgaben* and *%n Haushaltsupdates* added |
| minor | correctness | `CycleService::reopenUnderLock()` | A cycle an earlier undo had recorded as missed became current again after a second undo: its completion answered `409`, and the recognitions counted it twice | The miss of a cycle that is current again goes (D-091-174); an integration test fails on the old code |
| minor | correctness | `taskCycle.js` `postpone()` | A postponed task stayed under *Suggested for today* until a reload | The rows read again after a postponement |
| minor | correctness | `SuggestionView::countFor()` | The daily summary counted all of a child's suggested work, *Today* only up to the limit | The count stops at the limit (D-091-131) |
| minor (i18n) | performance | `CommandService` | The two receipt conflicts reached German members in English | Translated |
| minor | architecture | `TaskFormPayload::assertFirstDateInRange()` | The year of a first date counted from today in UTC | It counts from the house-local today |
| minor (docs) | correctness | changelog, glossary, ADR-021 §5 | *Counts neither for nor against you* — but work done during an absence counts | *Never counts against you; work you do during it still counts* (D-091-121 corrected) |
| minor (docs) | architecture | concepts, API reference, notifications reference | Notes said B and C were *built on* the branch with the shipped contracts binding until the merge; passages kept the planner, agreements, catch-up and claim | Delivered state with `0.8.0-alpha.5`; the stale passages rewritten or marked as history |
| minor (docs) | architecture | `architecture.md` | Source tree with retired jobs and services, stale store boundaries, size inventory off for five files, `Settings.vue` above the guideline without a row | Rewritten and re-measured |
| minor (docs) | architecture | `ui-use-cases.md`, API reference | The route ledger ended at 96 routes and kept the assignee routes; the route groups and the WP-B1 prune were missing; undo still pointed at the removed route, and its actor gate contradicted ADR-021 §9 | 93 routes with every delta; the groups and the prune named; undo and its gate corrected |
| minor (docs) | architecture | `RankingService`, `store/ranking.js`, `appinfo/info.xml` | Docblocks and the job comment described agreed occurrences | They describe the recognition facts and the timeline job of ADR-021 |
| minor (docs) | main agent | plan §5 | About forty changed files were missing from the manifest, five removed services stood as modified | Completed |
| minor | architecture | `house.js`, `roomTasks.js` | Two more copies of the rule *keep the UUID across an uncertain failure* beside `commandId.js` | Both use `rememberCommand` and `settleCommand`, which now treats a missing status as no answer |
| minor | performance | `run-e2e-gate.js` | A run that failed before its report left only the exit code | Playwright's stderr is kept beside the evidence and named |
| nit | architecture | `CompletionController::changeDate()` | A second implementation of the day check | `CalendarService::isRealDay()` |
| nit | performance | `test-runtime.js` | A failed probe did not say which; both rows' tags were probed twice | The probe and its ending are named; each tag is probed once |
| nit | performance | `CompletionPicker.vue` | A failed toggle was logged twice | The store's line only |
| nit (docs) | correctness | user guide | *A fair plan for the week* | *A fair suggestion of who does what on which day* |

Accepted without change: the test hooks `catchup-planned` and `today-section-catchup` keep
their names (no behaviour, pinned by the browser gates). The second log line of a failed
completion in `useTaskCompletion` stays: the refusal of a second split in flight is logged
only there.

### Findings routed

- **Known limits (D-091-175):** [BUG-UX-018](../backlog.md#p3-bug-ux-018--two-rare-corrections-of-a-fixed-rhythm-lose-a-cycle-or-its-postponement)
  — deleting the older of two cycles done early, and undoing an entry after the next cycle
  was postponed; ADR-021 names both.
- **Backlog (D-091-176):** [CR-SVC-68](../backlog.md#p3-cr-svc-68--reads-repeated-on-the-paths-of-today-the-lists-and-the-cycle-commands)
  (repeated reads), [CR-SVC-69](../backlog.md#p3-cr-svc-69--erasure-rewrites-the-frozen-coin-factors-row-by-row-under-the-house-lock)
  (the erasure's batching), [CR-SVC-70](../backlog.md#p3-cr-svc-70--three-methods-above-the-method-size-guideline)
  (method sizes) and, sanitized by the operator's decision,
  [BUG-SEC-004](../backlog.md#p3-bug-sec-004--command-receipts-keep-a-hash-derived-from-an-erased-members-user-id)
  (receipt hashes; data minimisation, needs database access).

Checked without findings (security track): every route the branch added or changed is
`@NoAdminRequired`, with membership in controller and service and the owner checks of coin
factors, bonus days, corrections and the planning of others; client ids resolve inside the
caller's house; the suggestion, the calendar, the digest, the history, the activity events,
the bonus rows and recording for others keep private tasks private (the decided exceptions
D-091-52, D-091-59 and D-091-140 aside); steps 1035 to 1038 bind every parameter and delete
no fact; no `v-html` with user content; logs carry ids only.

### Verification

- Each new integration test failed on the code before the review and passes now: the
  shared private definition (`ChecklistApiTest`), the undo after a move
  (`RoomTaskEditTest`), both fixed entries undone (`CycleTimelineTest`).
- Canonical stack (PostgreSQL 18.6, Nextcloud 35.0.3, PHP 8.4.28, this worktree): PHPUnit
  unit 1900 and integration 734 with `--fail-on-skipped`. The first integration run found
  the undo budget one statement over (76 of 75) and a test that pinned the untranslated
  conflict text; both were corrected and the suites rerun.
- Focused browser runs on the reseeded canonical stack (`family` profile), without retries:
  `chromium` 24 passed (request budgets, private tasks, sub-tasks, the task list and its
  groups), `chromium-serial-db` 36 passed (rhythm, completion, the task lifecycle, *Today*,
  deep links); no failed, skipped or flaky result.
- Jest 186 suites / 2673 tests, the zero-noise gate, ESLint, stylelint, build,
  php-cs-fixer (529 files clean), `l10n:check-keys` (1306 keys), `agents:check`,
  `docs:encoding`, `backlog:check`, `git diff --check`.

### Gates

- Build gate: Jest, ESLint, stylelint and the build green; PHPUnit unit 1900 and
  integration 734 on the canonical stack without skips.
- Not run here: the provider suites (MariaDB, SQLite), the AIO row, the preservation proofs
  and the complete browser gates on both rows, the package build and smoke. They are the
  qualification of WP-G on the committed tree.

### Transition

None: the final Full review passed, and the plan stays in `active/` until the qualification,
the export, the deployment and its verified record. One scoped review commit on
`feat/simplified-core-bc`; no merge, push, tag or deployment.
