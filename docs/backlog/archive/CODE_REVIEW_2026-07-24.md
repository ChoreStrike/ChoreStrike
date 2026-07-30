# Code Review — 2026-07-24 (Project-wide)

> **Tier:** Full Review (whole project). **Scope:** entire `lib/` + `src/` + migrations.
> **Method:** 4 parallel `reviewer` subagents (auth-core, gamification/concurrency,
> persistence/migrations, frontend) against the ChoreStrike checklist, then
> **orchestrator verification** of every block/major finding (caller analysis +
> source reads). Working tree was clean, branch 1 commit ahead of `origin/master`.
> **Source:** Review of the full codebase.

## Summary

| Severity | Count | Status |
|----------|-------|--------|
| Block | 0 | — |
| Major | 13 | Logged to backlog (security/behavior — never auto-fixed) |
| Minor | 9 | 3 auto-fixed inline; 6 logged |
| Nit | 4 | Logged |
| **Discarded (false positives)** | 3 | Verified non-issues |

**No shipping blocker** was found, but several **security/authorization** and
**cross-house data-flow** issues were confirmed and routed to `bugs.md` /
`phase-9-code-quality.md`. The transaction model on the completion path is sound
(verified — see "Discarded" below). SQL injection audit: **clean** — every
parameter uses `createNamedParameter()` / prepared statements; zero string
interpolation in query building.

## Auto-fixes applied this review (mechanical, zero behavior risk)

1. `src/store/game.js:51` — `topContributors` getter sorted `state.leaderboard`
   in place (mutated store state on every read). Now sorts a copy.
2. `src/store/user.js:44` — `getTopContributors` getter sorted `state.houseUsers`
   in place. Now sorts a copy.
3. `lib/Db/TaskMapper.php:252` — `getCategories()` did not `closeCursor()` after
   its fetch loop (every sibling method does). Cursor now closed.

**Quality gate after fixes:** Jest 59/59 (affected suites), ESLint 0 errors
(69 pre-existing JSDoc warnings unchanged), `php -l` clean.

## Verified findings

### Security / Authorization (→ `bugs.md` [P1], never auto-fixed)

| # | File:line | Issue | Routing |
|---|-----------|-------|---------|
| S1 | `lib/Controller/RoomTaskController.php:822` | `claimForUser()` lacks `validateRoomTaskOwnership()` (unlike sibling `assignToGroup()` at :798). Cross-house task claim by ID-guessing. | bugs.md |
| S2 | `lib/Controller/RoomTaskController.php:852` | `unclaim()` lacks `validateRoomTaskOwnership()`. Same cross-house IDOR. | bugs.md |
| S3 | `lib/Controller/TaskController.php:441` + `lib/Service/TaskService.php:168` | `delete()`/`deleteTask()` have no ownership/visibility gate. Tasks have no `house_id` (global templates), so a member of house A can delete a task house B uses; no private/admin-only gate either. | bugs.md |
| S4 | `lib/Service/TaskService.php:144` | `updateTask()` does reflective mass-assignment (`foreach $updates → set*()`). Service trusts all keys; only the controller whitelists. Defense-in-depth: service should whitelist mutable fields. | bugs.md |
| S5 | `lib/Controller/RoomTaskController.php:700,753` | `userTasks()`/`userStats()` accept an arbitrary `userId` without verifying membership in the current house → cross-house task-stats leak. | bugs.md |
| S6 | `lib/Service/AuthorizationService.php:101,151,57` | `requireHouseMember/requireCurrentUserId/requireHouseOwner` throw `\RuntimeException`; controllers catch `\Exception` → **HTTP 500** instead of 403/401 on auth failure. Widespread pattern (all controllers). | bugs.md |
| S7 | `lib/Service/AuthorizationService.php:119-130` | `requireHouseMember()` is side-effecting: it **auto-creates** a membership (`createOrGet`) when none exists. A "require" check that mutates is surprising and mis-named (effectively `ensureHouseMember`). | bugs.md |

### Cross-house data leaks on live endpoints (→ `bugs.md` [P1])

| # | File:line | Issue | Routing |
|---|-----------|-------|---------|
| L1 | `lib/Db/RoomTaskMapper.php:226,270,301` → `RoomTaskService.php:129,140,152` → routed `room_task#overdue/dueToday/dueWithinDays` | `findOverdue/findDueToday/findDueWithinDays` are **unscoped** (no house filter) and are the live route handlers. The earlier `findOverdueByHouse` fix was never wired into these endpoints. Cross-house task listing. | bugs.md |
| L2 | `lib/Db/RoomTaskMapper.php:424,398` → `RoomTaskService.php:605,593` → routed `room_task#visibleToUser/groupTasks` | `findVisibleToUser`/`findByGroupId` are unscoped; group-name collision across houses leaks tasks. (Part of the legacy group-assignment feature — see D2.) | bugs.md |

### Gamification fairness / behavior (→ `phase-9-code-quality.md`)

| # | File:line | Issue |
|---|-----------|-------|
| G1 | `lib/BackgroundJob/StreakFreezeJob.php:61-86` | Does not consult `weekdayAvailability`. A user marked unavailable yesterday still consumes a freeze / gets a streak reset for that day. Violates the absence-day fairness rule. |
| G2 | `lib/BackgroundJob/DailyNotificationJob.php:77-106` | Same: sends "streak at risk" for days the user is scheduled unavailable. |
| G3 | `lib/Service/GameLogicService.php:203` | Combo multiplier hardcoded `*= 2`, not sourced from `RewardConfigService` (gamification rule: reward formulas are config-driven). |
| G4 | `lib/BackgroundJob/StreakFreezeJob.php:58-92` | One per-user failure aborts the whole loop (try wraps the foreach). Should isolate per-user errors and continue. |

### Dead / broken code (→ `phase-9-code-quality.md` cleanup)

| # | File:line | Issue |
|---|-----------|-------|
| D1 | `lib/Service/MedalService.php` (whole class) | **Entirely dead** — no callers in `lib/` (`GameController` uses `GameLogicService::checkAndAwardMedals`). Carries latent bugs: `hasMedalThisWeek` casts a Nextcloud string userId via `(int)` → `0` (always "not awarded"), a TOCTOU on insert, and an over-broad `catch (\Exception)`. Archive/remove; route all medal logic through `GameLogicService`. |
| D2 | Group-assignment feature: `RoomTaskController` `claimForUser/unclaim/assignToGroup/groupTasks/visibleToUser` + `RoomTaskMapper` `findByGroupId/findVisibleToUser/assignToGroup/claimForUser/unclaim` | References columns (`assigned_to_group`, `assignment_type`, `claimed_by_user`) of a legacy feature. Either fix the IDOR (S1/S2/L2) and finish it, or archive it (consistent with the CS-PLAN-003 dead-code purge). |
| D3 | `lib/Db/RoomTaskMapper.php:70,88,110,133,157` | 5 methods (`findByHouseId/findUnscheduledByHouseId/findByAssignedUser/findByDeadlineRange/findByScheduledDate`) query a non-existent `house_id` (and dead columns `deadline/priority/scheduled_for/assigned_to/completed_at`). **No callers** — dead, would crash if called. Remove. |
| D4 | `lib/Db/TaskMapper.php:269` | `findDailyTasks()` queries `house_id/scheduled_date/status` on `tasks` (don't exist). Dead. Remove. |
| D5 | `lib/Service/GameLogicService.php:427,526` | `calculatePoints/calculateStreakBonus/calculateComboBonus` and `updateStreakCount` appear unused by the live completion flow. Verify callers; remove if dead. |
| D6 | `lib/Titles/TitleCatalog.php:47` | `SIGNAL_TOTAL_SPARKS_EARNED` constant defined + handled but used by no pack. Dead. |

### Persistence / migration (→ `bugs.md` [P2] / `phase-9`)

| # | File:line | Issue |
|---|-----------|-------|
| P1 | `lib/Migration/Version1017Date20260321000000.php:93-96` | `renameColumn()` else-branch uses MySQL `CHANGE` syntax; PostgreSQL needs `RENAME COLUMN`. Migration fails on PG upgrades from the pre-coins schema. (Guarded by `hasColumn`, so no-op on fresh installs.) DB abstraction claims PG support. |
| P2 | `lib/Migration/Version1025Date20260719000000.php:109` | `postSchemaChange` backfill issues one UPDATE per user (N+1). Batch for large deployments. |
| P3 | `lib/Db/RoomTaskMapper.php:38-46` | `lockForUpdate()` doc claims a write lock, but the SELECT (`find`) runs unlocked and the no-op UPDATE acquires the lock only at execute. Clarify the contract (it serializes writers, not readers) or use a real `SELECT … FOR UPDATE`. |

### Frontend — store barrel incompleteness (→ `phase-9-code-quality.md`)

| # | File:line | Issue |
|---|-----------|-------|
| F1 | `src/store/index.js:1-15` | `useSchedulingStore` is **never imported** — the scheduling store is absent from the entire barrel (not reset, not cleared, not exported). Components must deep-import. |
| F2 | `src/store/index.js:66-84` | `resetAllStores()` omits `checklistStore` and `schedulingStore` (both have `reset()`). Same bug class as the titles-leak fixed in CS-PLAN-006 — state leaks on logout/house-switch. |
| F3 | `src/store/index.js:87-145` | `clearAllStoreErrors/getAnyStoreLoading/getAnyStoreError` omit `checklist/scheduling/titles`; `titles` & `minigame` stores define no `clearError()`; `useTitlesStore` is imported but missing from both named + default exports; `checklist` import is unused. |
| F4 | `lib/Db/HouseMapper.php:71` / `TaskMapper.php:335` | `findAll` / `findPrivateByOwner` are unscoped — trust the caller to authorize. Document as admin-/owner-only (low risk today; latent if misused). |

## Discarded (verified false positives / non-issues)

| Claim | Verdict | Evidence |
|-------|---------|----------|
| `GameLogicService::handleTaskCompletion` has no transaction | **False** | `RoomService::completeRoomTask()` wraps it: `beginTransaction()` (:338) → `handleTaskCompletion()` (:419) → `commit()` (:442) → `rollBack()` (:444). Atomic. |
| `RoomTaskMapper` house_id methods crash at runtime | **Downgraded block→minor** | They reference a non-existent column, but have **zero callers** → dead code (D3), not a live crash. |
| `countByUserOnDateGrouped` leaks across houses | **False** | Returns a map keyed by `house_user.id` (globally unique); `DailyNotificationJob` looks up each user by its own unique id. Correct per-user. |

## Recommendations (priority order)

1. **S1–S7 + L1/L2** — authorization & cross-house leak cluster. Highest priority; routed to `bugs.md` [P1]. Recommend a dedicated plan (these touch every controller's auth model).
2. **D1 (MedalService) + D3/D4 (dead mappers)** — quick wins: archive dead code, reduce the misleading surface that hid S/L findings.
3. **F1–F3** — store-barrel completeness; mechanical, low risk.
4. **G1/G2** — weekday-awareness in background jobs (gamification fairness).
5. **P1** — PostgreSQL migration portability (only if PG is a real target).

**Review outcome:** 3 mechanical fixes applied + green gate; 13 major + 9 minor + 4 nit
routed to backlog. No commit made — awaiting approval.

## Documentation-vs-Implementation Reconciliation (2026-07-24, 4 parallel `explore` agents)

A doc-vs-impl reconciliation (4 `explore` agents: architecture/schema, design/gamification,
testing/api, dead-code residue) found the documentation several plans behind the
implementation, plus a cluster of dead code/stale residue from the Sparks/Monsters
removal (CS-PLAN-002) and dead-code cleanup (CS-PLAN-003) that was never fully closed.
Tracked + closed under **CS-PLAN-010** (defaults confirmed: document reality for medals,
align combo docs to the implemented ×2 personal combo, drop the stale sparks columns).

### Findings

| # | Area | Drift | Resolution (CS-PLAN-010) |
|---|------|-------|--------------------------|
| R1 | `architecture.md` | Directory tree lists archived files (`TaskClaim`/`TaskParticipant`/`TaskScope`/`SchedulingView`); DB schema uses stale names (`difficulty` vs real `energy_cost`, `total_points` vs `coins`, PascalCase tables, `monster_count`); ~9 tables + dozens of columns undocumented; data-flow example references nonexistent `TaskItem.vue`/`RoomView.vue` + `GameStore.checkCombo()`. | Rewrite tree + schema + data-flow against real migrations/files (Phase 1). |
| R2 | `design.md` + reward concept docs | Medal names ("The Tank/Scout/Specialist/Teamplayer/Streak Keeper") don't match impl (`team_anchor/flow_keeper/room_guardian/team_player/streak_keeper`); only 2 of 5 medal types actually awarded. Reward tokens undocumented (streak freezes, combo bonus freeze, teamwork bonus, weekly goal target). Combo multiplier documented as ×1.5 multi-user, implemented as ×2 personal. | Align medal names + reward tokens to impl (Phase 2); combo = ×2 personal (Decision B). |
| R3 | `testing.md` + `api/README.md` | E2E spec count says 20 (real 32); phpunit command inconsistency (`phpunit.xml tests/integration/` vs `phpunit.integration.xml`). `api/README.md` documents phantom `PUT /room-tasks/{id}`; ~96 routed endpoints undocumented. | Fix E2E table + phpunit command (Phase 3); remove phantom route + document groups (Phase 4). |
| R4 | Code residue (sparks + dead services) | `HouseUser` serialises `currentSparks`/`totalSparksEarned` + dead `addSparks()`/`spendSparks()`; `MinigameService::getUserSparks()` reads `getCurrentSparks()` (only test-referenced); `TitleCatalog::SIGNAL_TOTAL_SPARKS_EARNED` unused (no pack uses it — Cozy Home uses `SIGNAL_COINS`); `Version1025` backfill SELECT/match arm reference the dead signal; `MedalService` fully dead; `UserStatsController` (8 actions, 0 routes); `RoomController::addTask()`/`removeTask()` unrouted; DB still carries `current_sparks`/`total_sparks_earned`. | Remove sparks residue + `Version1026` drop migration (Phase 5); archive `MedalService`/`UserStatsController`, remove `RoomController` dead actions (Phase 6). |

**Outcome:** Phases 1–4 (doc-only) and 5–7 (code/schema) delivered under CS-PLAN-010.
Phase 7 default applied — medal award gap documented ("2 of 5 awarded") + backlog item
logged for the missing award paths.
