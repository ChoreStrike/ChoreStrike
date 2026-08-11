# Code Review — Active Plans Full Review (2026-08-11)

> **Scope:** All 4 active plans — CS-017 (Manual Release Test Plan), CS-022 (Test
> Coverage Expansion), CS-023 (Time-Travel Testing), CS-025 (`lib/Service` Security,
> Concurrency & Dead-Code Cleanup). Focus on the **uncommitted CS-025 reopened-items
> changeset** (CR-SVC-04/45/46/47) + a spot review of the committed CS-022/023
> implementations, with the explicit user ask to verify **which tests were actually
> run**.
> **Tier:** Full (4 parallel `reviewer` subagents: correctness / architecture /
> security-auth / perf-quality) + gate re-verification in Docker.
> **Tree reviewed:** uncommitted working tree on `master` (8 commits ahead of
> `origin/master`), i.e. the CS-025 reopened-items changeset on top of the committed
> CS-022/023/025 phases.

---

## 1. Gate Re-Verification (this turn, on the uncommitted tree + 1 auto-fix)

| Gate | Result | Plan claim | Match |
|------|--------|-----------|-------|
| Jest (`npm test`) | **677 passed / 677** (66 suites) | 677 | ✅ |
| PHPUnit unit (`phpunit.unit.xml`, Docker) | **690 tests, 1842 assertions, OK** | 690 | ✅ |
| PHPUnit integration (`phpunit.integration.xml`, Docker MariaDB) | **150 tests, 24 skipped, OK** | 150 (24 skips) | ✅ |
| `ConcurrentCompletionTest` (the CR-SVC-04 real-DB proof) | **2/2 ✔** incl. "Find for update builds on committed winner state" **2149 ms** (real lock-block) | new, must pass | ✅ |
| ESLint (`npm run lint`) | **clean** | clean | ✅ |
| E2E (Playwright) | **not re-run this turn** | 160 passed (4 baseline failures, proven pre-existing via stash) | ⚠️ not re-verified |

**Which tests actually ran (the user's core question):**
- The full **unit** + **integration** + **Jest** suites ran green in Docker on the
  uncommitted tree. Counts match the plans' claims exactly (690 / 150 / 677).
- The **new** CR-SVC-04 concurrency integration test is **not** among the 24 skips —
  it executed on MariaDB and both methods passed, including the 2.1 s two-process
  lock-block proof. (The 24 skips are the pre-existing set, e.g.
  `FamilyIntegrationTest::testConcurrentTaskCompletion`.)
- The **new** CS-022/023/025 test files all exist on disk and are wired into the
  suites (verified by inventory): `TriggerJobsTest`, `Streak{Freeze,AtRisk,Restock}ServiceTest`,
  the 7 CS-022 mapper tests, 6 component specs, `checklist` store spec, 4 util specs,
  `RewardConfig/Template/TaskSyncControllerTest`, `TemplateServiceTest`, plus the
  reopened-items integration tests (`ConcurrentCompletionTest`, `TaskSyncCompletionTest`,
  the bootstrap + call-order additions).
- **CS-022's 4 "missing" files** (`SmartSchedulerControllerTest`, `UserStatsMapperTest`,
  `ComboTest`, `UserStatsTest`) are **explained, not defects**: CS-025 Phase 2 deleted
  the SmartScheduler/Combo/UserStats dead cluster (incl. their tests). Consistent
  across the two plans.
- **E2E was not re-run** this turn (slow full matrix); the plans record it green at
  implementation time. Flagged as the one gate leg not re-verified here.

---

## 2. Findings

### Verified clean (no action)

CR-SVC-01 (IDOR airtight: service-internal floor-chain + assignee membership, single
live caller passes caller `houseId`); CR-SVC-02 (all 5 destructive methods self-gate on
`assertTestMode()`, strict `NEXTCLOUD_TEST_MODE === 'true'`, no CI fallback);
CR-SVC-05 (both score bounds before insert + coin award, `InvalidArgumentException`→400);
CR-SVC-06 (`requireHouseOwner`/`isHouseOwner` → read-only `assertHouseMember`, no row
created); CR-SVC-03 (idempotency guard before the per-user query, stamps both consume
and reset paths, TZ-stable Y-m-d key); CR-SVC-12 (title dispatch after `commit`);
CR-SVC-45 (dead `completed_tasks` columns removed, zero `lib/` references); CR-SVC-46
(`nextcloudTaskId` threaded fully through `completeRoomTask`→`handleTaskCompletion`,
non-sync completions stay null); CR-SVC-47 (bootstrap ordering correct at all 5
endpoints, `requireHouseOwner` stays read-only); Phase-2 dead-code deletions (no
dangling references in `lib/`/`tests/`/`appinfo/`/migrations/`RESET_TABLES`);
`HouseUserMapper::findForUpdate` (true `SELECT … FOR UPDATE` on locking engines,
returns the freshest committed row, injection-safe); `ConcurrentCompletionTest`
(deterministic two-process orchestration, strict assertions, clean tearDown).

### New findings (→ `backlog.md` CR-SVC-53..58)

| # | Sev | File:line | Issue | Status |
|---|-----|-----------|-------|--------|
| **CR-SVC-53** | **P1** | `lib/Service/RoomService.php:276,295,331,354` | `completeRoomTask` operates on a **stale `RoomTask`** after `lockForUpdate()` (returns `void`); the entity fetched before `beginTransaction()` is never refreshed → overdue-takeover check, `markClaimed`, and `markCompleted` recurrence all run on the pre-lock snapshot. Same freshness asymmetry `findForUpdate` was hardened to avoid; pre-existing, surfaced by this concurrency slice. | logged (decision point for `done/`) |
| CR-SVC-54 | P3 | `lib/Service/MinigameService.php:75-86` | `saveScore` coin award has no row lock/transaction (lost-update class, mirror of CR-SVC-04). | logged |
| CR-SVC-55 | P3 | `lib/Service/StreakFreezeService.php:81` | per-user N+1 `countByUserOnDate` in loop; sibling `StreakAtRiskService` already uses a GROUP BY. | logged |
| CR-SVC-56 | P3 | `lib/Service/MinigameService.php:96,122` | `getLeaderboard` N+1 display-name enrichment (per-row `findByHouseIdAndNextcloudUserId`). | logged |
| CR-SVC-57 | P3 | `lib/Service/GameLogicService.php:184` | `calculateContributionScore` ignores its `$roomCleanliness` parameter (dead param). | logged |
| CR-SVC-58 | P3 | `phpunit.unit.xml:15-41` | Entity/Mapper suites double-run the 5 newer mapper tests (in Mapper `<file>` list but not Entity `<exclude>` list); fragile split that already drifted once. | logged |

### Confirmed-open (already in `backlog.md`, re-verified real)

CR-SVC-48 (reverse-sync `$completedAt` unvalidated — confirmed it also throws uncaught
on malformed input → 500); CR-SVC-50 (extra queries inside the `house_user` lock —
confirmed: `roomTaskMapper->find` + 2× `findRecentByUserId`); CR-SVC-51
(MinigameController recomputes the coin formula, diverges when house-user missing);
CR-SVC-49 / CR-SVC-52.

### Auto-fix applied this review (minor, gate re-run green)

- `lib/Service/TaskSyncService.php:172` — dead `$completedTask->setSparksEarned(0)`
  (the only `sparksEarned` reference in the codebase; `completed_tasks.sparks_earned`
  was renamed to `coins_earned` by `Version1017`; the entity declares `coinsEarned`)
  → `setCoinsEarned(0)`. Gate re-verified green after the fix.

### Deferred nits (recorded here, not separate backlog items)

- `deriveYesterday()` duplicated verbatim in `DailyNotificationJob` + `StreakFreezeJob`
  (rule-of-three not met — only 2 jobs; the restock job is time-independent).
- The 3 CS-023 streak services inject `Psr\Log\LoggerInterface` directly instead of
  `AppLogger` (codebase-wide normalization; relates to CR-SVC-13; nothing sensitive
  logged).
- `TriggerJobs`: `--user` option declared but not honored (runs the full pass with a
  notice); error message mentions "CI environment" but `isTestMode()` has no CI
  fallback (safe direction — message is stricter than the gate).
- i18n: `HouseController`/`MinigameController`/`RoomTaskController` emit raw English
  response strings without `t()`/`IL10N` (project-rules §2; pre-existing).
- PHPDoc `@return` gaps on ~15 touched public methods (`HouseUserMapper::find/findForUpdate/findByNextcloudUserId/createOrGet`,
  `GameLogicService::calculateContributionScore/calculateCoins/getGameState/getLeaderboard/checkAndAwardMedals`,
  `RoomService::completeRoomTask`, `MinigameController` action methods). The docblocks
  the prior review added (`handleTaskCompletion`, `MinigameService::saveScore`,
  `AuthorizationService::isHouseOwner`) are complete and correct.
- `ConcurrentCompletionTest::buildLockHolderScript` hardcodes
  `/var/www/html/lib/base.php` (Docker-only; MySQL-only skip is graceful; tearDown is
  leak-free). Minor portability fragility.
- `tests/unit/Db/TaskMapperVisibilityTest.php` — misleading name (entity-only behavior,
  no mapper/DB).
- File/method-size guideline breaches (pre-existing, grown by these plans):
  `RoomTaskController` 1002 lines, `RoomTaskService` 870, `RoomService::completeRoomTask`
  ~222 lines, `GameLogicService::handleTaskCompletion` ~113 lines, `UserController` 524,
  `TaskSyncService` 397, `MinigameController` 363, `SchedulingController` 324,
  `GameLogicService` 528. Split candidates noted in the architecture subagent's output.

### Separate finding (not part of the 4 plans' scope, surfaced during review)

- **`docs/plans/done/` is completely empty** in the working tree — 24 tracked
  done-plan files deleted, unstaged (last touched 2026-07-30; the deletions are a
  working-tree change, not committed). 7 dangling links in `ACTIVE_TASKS.md`
  (Recently Completed → `../plans/done/cs-plan-*`) + 4 doc references
  (`docs/backlog/README.md`, `docs/backlog/archive/2026-07.md`, `ADR-004`,
  `archive/README.md`). Looks like an accidental or half-finished archival. Reported
  to the user; no action taken in this review.

---

## 3. Verdict

- **CS-017** (docs-only): delivery verified (`docs/testing/release-test-plan.md`
  exists, stage-mapped; gate references wired; Standard-B PASS stood). Ready for
  `active/ → done/`.
- **CS-022** (Phases 1–4, 6 done; Phase 5 E2E deferred): tooling + tests verified
  present and wired (PCOV, `test:coverage` scripts, Jest coverage config + thresholds,
  mapper-gate repair, the new suites). The 4 "missing" files are explained by CS-025's
  dead-code deletion. Ready for `active/ → done/` (Phase 5 remains a tracked deferral).
- **CS-023**: time-travel harness verified (3 services + thin jobs + `TriggerJobs`
  command + `occ-runner` + 2 E2E specs + `RouteMatrixTest` wired). Ready for
  `active/ → done/`.
- **CS-025**: all 4 phases + the reopened items landed and the gate is green. **One
  new [P1] concurrency finding (CR-SVC-53)** is a decision point for `active/ → done/`:
  it is pre-existing (not a CS-025 regression) and narrow, but it is the sibling gap to
  the CR-SVC-04 fix on the same completion path. Recommend the user either reopen the
  CR-SVC-04 class to cover `lockForUpdate`'s caller, or accept CR-SVC-53 as a P1
  backlog follow-up and proceed with `done/`.

**Quality gate:** green (unit 690 / integration 150 / Jest 677 / lint clean /
`ConcurrentCompletionTest` 2/2). One auto-fix applied and re-verified. No security or
data-integrity regression introduced by the 4 plans; the one data-integrity note
(CR-SVC-53) is pre-existing.
