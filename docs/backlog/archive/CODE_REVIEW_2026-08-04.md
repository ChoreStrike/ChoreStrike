# Code Review: `lib/Service` (Full Review)

**Review Date**: 2026-08-04
**Reviewer**: Kilo (main agent) + 4 parallel `reviewer` subagents + 1 `explore` subagent
**Mode**: Full Review (Deep Slice) — whole module / domain, auth- & data-integrity relevant
**Scope**: `lib/Service/` (24 services) + `lib/Service/Scheduling/` (5 algorithm classes) = 29 files
**Base Commit**: working tree on `master` (uncommitted manual-test changeset pre-existing)
**Duration Target**: 90 min (Deep)

---

## Executive Summary

### Outcome
- **Needs follow-up** — two P0 authorization/data-safety findings plus several P1
  correctness/concurrency issues must be addressed before the next release cut.
  The module is architecturally sound (no circular deps, consistent thin-controller
  layering) but carries meaningful security, concurrency, and dead-code debt.

### What Was Reviewed
- All 29 files under `lib/Service` (services + scheduling algorithms).
- Reachability traced into `lib/Controller/`, `appinfo/routes.php`, and background
  jobs/commands to confirm exploitability of authorization findings.

### What Was Fixed Immediately (auto-fix cluster, gate green)
1. `FloorService::deleteFloor` — removed dead `if (!empty($rooms))` block + redundant
   `findByFloorId` query. (`lib/Service/FloorService.php`)
2. `RewardConfigService::applyTitleThresholds` — iterate original keys instead of
   `array_values()` so a sparse tier payload (`[2 => 1000]`) maps to the correct tier
   index, not position 0. (`lib/Service/RewardConfigService.php`)
3. `RoomTaskService::skipTask` — null-guard `getNextDue()` before `->modify()` to
   prevent a `TypeError` on a task with no due date. (`lib/Service/RoomTaskService.php`)

   Each fix has a regression test (`FloorServiceTest`, `RewardConfigServiceTest`,
   `RoomTaskServiceTest`).

### Main Risks Remaining
1. **Cross-house IDOR** in `RoomTaskService::createRoomTask` (P0) — a member can write
   room tasks into any house's rooms and stamp an arbitrary assignee.
2. **Non-idempotent daily streak-freeze job** (P1) — re-runs silently consume extra
   freezes / reset preserved streaks (data loss).
3. **Lost-update race** on `house_user` coins/streak under concurrent same-user
   completions (P1).
4. **Transaction held across notification dispatch** on the hottest mutation path
   (`completeRoomTask`) — lock contention + stale-notification-on-rollback (P1).
5. **Large dead cluster** (~11 files) unreachable since the smart-scheduler routes were
   removed — pre-release rules say delete; decision pending (extends CR-016-07).

---

## Scope and Risk Framing

### Modules
- `lib/Service/*` — 24 service classes.
- `lib/Service/Scheduling/*` — 5 scheduling-algorithm classes.

### Documents
- `AGENTS.md`, `project-rules.md`, `security.md`, `review`/`docker-dev` skills.
- `appinfo/routes.php`, `lib/Controller/*` (reachability traces).

### Initial Risk Hypotheses (confirmed)
- Services trust callers for membership; resource-scoping helpers are per-service and
  inconsistently applied → IDOR risk on mutators that accept entity/user ids.
- Streak/notification services run from background jobs (no HTTP authz) → idempotency
  and scoping matter.
- A route-removed scheduler chain → dead code.

---

## Methodology

Per the `review` skill §4 (Full Review): one `explore` subagent produced the module
inventory; **four `reviewer` subagents ran in parallel** — (1) Correctness &
Functionality, (2) Architecture & Ownership, (3) Security/Auth/Data-Flow, (4)
Performance & Code Quality. The main agent consolidated/deduplicated findings,
verified the highest-severity live-code findings by direct read, applied the safe
auto-fix cluster, and ran the full quality gate.

---

## Findings — Consolidated Table

Severity: **B**=block · **Maj**=major · **Min**=minor · **N**=nit.
Status: **Fixed** (this review) · **Open** (→ backlog) · **Retired** (vanishes when
the dead cluster in CR-SVC-08 is deleted).

| ID | Sev | Category | File:Line | Issue | Status |
|----|-----|----------|-----------|-------|--------|
| CR-SVC-01 | B | Security | `RoomTaskService.php:173` / `RoomTaskController.php:157` | `createRoomTask` reachable without `roomId` house-ownership or `assignedToUserId` membership check → cross-house write + arbitrary assignee (IDOR). | Open |
| CR-SVC-02 | B | Security | `TestDataService.php:114` | `reset()`/`seed*()` have no internal `isTestMode()` self-gate; only callers enforce it → any future caller wipes prod DB. (CS-PLAN-019 hardened endpoints, not the service.) | Open |
| CR-SVC-03 | Maj | Correctness | `StreakFreezeService.php:78` | `process($yesterday)` not idempotent; job retry / `occ trigger-jobs` double-consumes freezes or resets preserved streaks. | Open |
| CR-SVC-04 | Maj | Correctness | `GameLogicService.php:69` | Lost-update race on `house_user` coins/streak: transaction locks `room_task` but not `house_user`; concurrent same-user completions clobber. | Open |
| CR-SVC-05 | Maj | Security | `MinigameService.php:52` | `saveScore` accepts unbounded client `score` → `floor(score/10)` coin farming (negative drain / unlimited mint). | Open |
| CR-SVC-06 | Maj | Security | `AuthorizationService.php:192,206` | `isHouseOwner`/`requireHouseOwner` delegate to `ensureHouseMember`, which auto-creates a membership on a miss — a read-looking/owner check mutates state. | Open |
| CR-SVC-07 | Maj | Correctness/Arch | `TaskSyncService.php:58,379` | Parallel completion path: reward-less, lock-less, no duplicate guard, divergent recurrence (`calculateNextDueDateFromTask` ignores flex scheduling). Should route through `RoomService::completeRoomTask`. | Open |
| CR-SVC-12 | Maj | Perf/Concurrency | `RoomService.php:290-394` | DB transaction + `lockForUpdate` held across `NotificationService::notifyTitleUnlocked` (external dispatch) inside the lock → contention + stale notification on rollback. | Open |
| CR-SVC-08 | Maj | Architecture | `SmartSchedulerService.php` + chain | Dead cluster unreachable after route removal: `SmartSchedulerService`+`TaskSchedulerService`+5 `Scheduling/*` + orphans `ComboService`/`HouseUserService`/`UserStatsService` (~11 files). Pre-release: delete. Extends CR-016-07 / CR-016-13. | Open (decision) |
| CR-SVC-09 | Maj | Security | `RoomTaskService.php:228,502` | `assignTask`/`bulkAssignTasks` unguarded internally (HTTP path mitigated today); `bulkAssignTasks` logs userId plaintext. | Open |
| CR-SVC-10 | Maj | Security | `RoomService.php:280` | `completeRoomTask` has no membership re-check for ordinary tasks; null `$houseUser` → 500 not 403. | Open |
| CR-SVC-11 | Maj | Performance | multiple | N+1 cluster: `HouseService` health/stats/reset, `GameLogicService` medals, `TaskSchedulerService` 3-level, `TaskPriorityScorer`, `FloorService`, `RoomService`, `RoomTaskService` daily/range/bulk, `MinigameService` leaderboards, `TaskSyncService`, `StreakFreezeService`. Needs batch/`findByIds` mapper queries. | Open |
| CR-SVC-13 | Maj | Code Quality | `NotificationService.php:49` + 3 streak svcs | Raw `Psr\Log\LoggerInterface` instead of `AppLogger` (project-rules §2); ~16 services have no logger. | Open |
| CR-SVC-14 | Maj | Code Quality | `TemplateService.php:303` | `bulkImport` silently swallows exceptions. **Already tracked as BUG-TEMPLATE-001.** | Dup |
| CR-SVC-15 | Maj | Architecture | `RoomService.php:272` etc. | God-method/service size: `completeRoomTask` (~200 lines), `GameLogicService` (517, 7 concerns), `handleTaskCompletion`/`checkAndAwardMedals`, `RoomTaskService` (833), `RewardConfigService` (607), `TaskService::createTask` (24 params). | Open |
| CR-SVC-16 | Maj | Architecture | `RewardConfigService.php` | Each config key touched in 4-5 places; collapse `get`/`set`/`update`/`all` to a registry. | Open |
| CR-SVC-17 | Min | Security | `TaskService.php:174` | `updateTask` missing task-visibility matrix (asymmetric with `deleteTask`); `ownerUserId` whitelisted → silent private-task reassignment. | Open |
| CR-SVC-19 | Min | Security | `RewardConfigService.php:385+` | Unclamped setters/getters (negative multiplier/coin base invert rewards). Admin-gated. | Open |
| CR-SVC-21 | Min | Security/Arch | `TaskSyncService.php:65,58,314` | Inconsistent house-scoping (`room->getHouseId()` vs floor chain) + unscoped public mutators; make base methods private, expose only `*ForHouse`. | Open |
| CR-SVC-22 | Min | Security | `RoomTaskService.php:359` | `removeAssignee` unguarded (inconsistent with `addAssignee`). | Open |
| CR-SVC-23 | Min | Correctness | `MinigameService.php:197` | `purchaseBoost` read-check-deduct with no lock → lost update on concurrent purchase. | Open |
| CR-SVC-26 | Min | Correctness | `HouseUserService.php:44` | `removeUserFromHouse` doesn't null `assigned_to_user_id` / delete `task_assignees` (root cause of the defensive re-check at `RoomService.php:419`). | Open |
| CR-SVC-27 | Min | Correctness | `NotificationService.php:212` | `notifyStreakAtRisk` objectId = streak number (not day-qualified) → cross-day reminders deduped/dropped. | Open |
| CR-SVC-28 | Min | Correctness | `SchedulingService.php:47` | `getUserPreferences` hardcodes `todayConsumedEnergy=0` (stale). | Open |
| CR-SVC-30 | Min | Correctness | `StreakRestockService.php:49` | Whole-loop try/catch aborts the weekly restock on one user failure; inconsistent with `StreakFreezeService` per-user handling. | Open |
| CR-SVC-31 | Min | Correctness | `RoomTaskService.php:471,492` | `completionRate` conflates inactive with completed. | Open |
| CR-SVC-32 | Min | Correctness | `GameLogicService.php:230` | `calculateDaysEarly` divides by 86400 (DST/timezone edge cases). | Open |
| CR-SVC-33 | Min | Correctness/Arch | `TaskService.php:481` | Task-level `completeTask` reward-less path parallel to `RoomTaskService`; risk of calling the wrong "complete". | Open |
| CR-SVC-36 | Min | Code Quality | `GameLogicService.php:188` | `calculateContributionScore` unused `$roomCleanliness` param (public API). | Open |
| CR-SVC-37 | Min | i18n | `TaskService.php:330`, `MinigameService.php:175` | Hardcoded English exception/validation strings returned to clients (need `IL10N->t()`). | Open |
| CR-SVC-39 | Min | Code Quality | `Scheduling/AvailabilityCalculator.php` etc. | Missing `@param`/`@return` PHPDoc on public members. | Open |
| CR-SVC-40 | Min | Architecture | `RoomService.php:51` | Service injects `IDBConnection` for transaction/`lockForUpdate` (persistence concern below the service layer). | Open |
| CR-SVC-41 | Min | Architecture | `RoomTaskService.php`, `*Service.php` | DI inconsistencies: nullable `?AppLogger` in 3 services; `getFilteredDailyTasksForUser` takes `HouseUserMapper` as a param despite ctor injection; duplicated stat helpers; duplicated `validate*Ownership`. | Open |
| CR-SVC-42 | Min | Architecture | `TestDataService.php:47` | `RESET_TABLES` legacy table aliases (pre-release compat shim — project-rules §1). | Open |
| CR-SVC-44 | Min | Performance | multiple | Minor perf: `getAllTasks` unbounded; `createDefaultTemplates` 15 inserts; `StreakFreeze`/`Restock` per-user UPDATEs; `assignTask` triple-fetch; `ScheduleOptimizer` O(n²) recompute; `extractHouseIdFromSchedule` 3 queries. | Open |
| — (fixed) | Min | Correctness | `FloorService.php:136` | Dead no-op `if`-block in `deleteFloor`. | **Fixed** |
| — (fixed) | Min | Correctness | `RewardConfigService.php:565` | `array_values()` reindexed sparse tier payloads → wrong tier. | **Fixed** |
| — (fixed) | Min | Correctness | `RoomTaskService.php:762` | `skipTask` null-deref crash on `getNextDue()`. | **Fixed** |
| — (non-issue) | — | Correctness | `RoomTaskService.php:540` | `getUpcomingTasksForUser` usort null-comparator — **not reachable** (`isDueWithinDays` filters nulls first). | Rejected |
| — (non-issue) | — | Correctness | `HouseService.php:252` | `getWeeklyGoalProgress` completedCount dedup — **documented design choice**; mapper dedupes via GROUP BY (covered by existing tests). | Rejected |

### Findings retired by CR-SVC-08 (dead-cluster deletion — not separately backlogged)
These live **only** in unreachable code and vanish once the dead cluster is deleted:
`TaskReassignmentSuggester` `rand()` workload balancing; DI `new`-violations in
`ScheduleOptimizer`/`TaskAssignmentAlgorithm`/`TaskReassignmentSuggester`; the scheduler
N+1 in `TaskSchedulerService::getActiveRoomTasks`; `TaskPriorityScorer::daysUntilDue`
overdue reporting; `ScheduleOptimizer::countChanges`/`groupRelatedTasks` correctness;
`ComboService::getTotalComboBonus` 100-cap; `UserStatsService::getOrCreateStats`
duplicate-on-multiple; `HouseUserService::isMember` role gate; duplicate catch in
`TaskReassignmentSuggester`.

---

## Tests and Validation

| Command | Result |
|---------|--------|
| `npm run lint:fix` | clean (browserslist notice only) |
| `npm test` (Jest) | **66 suites / 677 tests pass** |
| `vendor/bin/phpunit --configuration phpunit.unit.xml` (Docker) | **788 tests / 2172 assertions pass** |
| Affected files (FloorServiceTest / RoomTaskServiceTest / RewardConfigServiceTest) | 11 + 27 + 38 pass, incl. 3 new/updated tests |

Gate is **green**. Docker env (`docker-compose.test.yml`) used for backend tests per
the `docker-dev` skill.

---

## Documentation and Backlog Changes

- `docs/backlog/backlog.md` — new themed section **"Review Follow-ups — Full Review of
  `lib/Service` (2026-08-04)"** with the open P0–P3 items (CR-SVC-01 … CR-SVC-44).
- `docs/backlog/ACTIVE_TASKS.md` — one-line *Recently Completed* entry for this review.
- `docs/backlog/archive/CODE_REVIEW_2026-08-04.md` — this record.
- No public-API / workflow / rules changed by the auto-fixes → no doc rewrites required.
- No active plan owns "review of lib/Service" → no plan `## Review Outcome` note added.

---

## Batched Questions

1. **Dead scheduler cluster (CR-SVC-08):** delete now (pre-release, no compat needed)
   or keep staged for a future revival?
   - Option A: **Delete** the chain + 3 orphan services now (recommended; retires the
     dead-code bugs above and shrinks the module).
   - Option B: Keep but mark `@internal`/disabled and gate behind a feature flag.
   - Option C: Leave as-is (defer the decision).

---

## Recommendation

- **Final status:** Needs follow-up (2 P0 + 6 P1 must land before the next release cut).
- **Next recommended package:** a focused security hardening plan (CS-PLAN-NNN) covering
  CR-SVC-01/02/05/06/09/10 + the concurrency items CR-SVC-03/04/12 — these are small,
  localized, and have clear project patterns (route through `validate*Ownership` /
  `assertUserInHouse`, `SELECT … FOR UPDATE`, `lockForUpdate` discipline, idempotency
  guard). Then a separate cleanup plan for the dead cluster (CR-SVC-08) and the
  perf/logger refactors.
- **Suggested owner:** main agent (next session), starting with the P0 IDOR.

---

**Review Completed**: 2026-08-04
**Next Review**: after the P0/P1 security+concurrency follow-up lands.
