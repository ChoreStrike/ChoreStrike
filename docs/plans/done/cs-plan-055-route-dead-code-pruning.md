# CS-PLAN-055: Route Pruning & Dead-Code Sweep (U2-A)

> **Status:** Implemented (2026-08-29) · **Standard-B reviewed PASS 2026-08-30** +
> `/review uncommitted` second pass (6 findings fixed) — moved to `done/`.
> Execution record at the bottom.
> **Effort:** L
> **Priority:** P2
> **Depends on:** none (CS-PLAN-056 builds on this plan's outcome)
> **Closes:** CR-053-14, CR-053-15, CR-053-20 (dead-store half), BUG-ROOM-001 · **Moots:** CR-053-05, CR-053-08, FEAT-UX-021, CR-053-10 · **Re-scopes:** CR-053-06, CR-053-16, CR-053-18, CR-053-20 (correctness remainder), CR-SVC-44

## 1. Problem Summary

The CS-PLAN-053 full review (2026-08-27, [`CODE_REVIEW_2026-08-27.md`](../../backlog/CODE_REVIEW_2026-08-27.md)) verified that of 110 routes only 67 are production-consumed: **30 have no consumer and 12 are test-only** (3 of the 12 are the `/test/*` E2E harness and stay). The remaining **39 routes** are dead surface — including the whole task-sync surface ×6, both reward-config routes, the 4 documented-dead alias routes, and the test-only task-level lifecycle routes. The frontend mirrors this with a grep-proven dead-store layer (~40 store getters/actions, the whole `src/store/index.js` barrel, 2 util files), and the backend carries a verified-dead persistence cluster (AvailabilitySettings entity+mapper+table, ~10 service methods, entity/mapper helper clusters, dead migration steps, 3 orphaned tables).

**User decision (2026-08-29, CR-053-14): prune now** — delete the no-consumer + test-only routes together with the mirroring dead-store layer; re-add surfaces only when a consumer exists. This also resolves **BUG-ROOM-001**: the CSRF-able GET-create branches in `FloorController::index` (:51-71) and `RoomController::index` (:55-88) die with their (no-consumer) index routes.

Pre-release rules apply: no BC, no upgrade paths — dev DBs are disposable (`down -v` + `up -d`), migration-chain edits are in scope.

## 2. Strategy

Execute the prune top-down in gated clusters, each with a **re-verification grep gate** before deletion (no-caller proofs are recorded in the review record; they must be re-run at execution time because CS-PLAN-054 shifted code): routes first (behavior change), then the now caller-less controller/service/entity/mapper layers, then migration-chain cleanup, then the frontend dead stores, then coupled docs/backlog truth-repair. Test consumers of pruned routes (2 reward-config E2E specs, `release-task-lifecycle.spec.ts`, `DailyTasksApiTest`, `release-task-templates.spec.ts:112`, `time-travel-streak-notification.spec.ts:49`) are reworked **before** the routes disappear, keeping every gate green at each step.

Two coupled product decisions ride along and are resolved by this plan: **CR-053-20's wire-or-delete** → delete the never-called user-store reward actions (`addCoins`/`addContributionScore`/`updateStreak`); **CR-053-10** → delete the column-less reminder fields (their only reader, `TaskSyncService`, dies with the task-sync prune) and the phantom `Room::$description` API field.

## 3. Backend / Frontend Changes

### 3.1 Routes pruned (39 — full disposition list; kept: 67 production + 3 `/test/*` harness)

| Group | Routes (routes.php name) |
|---|---|
| Dead aliases ×4 (BUG-ROOM-001 family) | `floor#createAlias`, `room#createAlias`, `floor#createExplicit`, `room#createExplicit` |
| No-consumer reads ×6 | `floor#index`, `floor#show`, `room#index`, `room#show` (test-only), `task#show`, `room_task#show` |
| Dead room-task variants ×6 | `room_task#dueWithinDays`, `room_task#assign`, `room_task#unassign`, `room_task#addAssignee`, `room_task#removeAssignee`, `room_task#overdueClaims` |
| Dead task/user/room wrappers ×7 | `task#byCategory`, `task#search`, `task#categories`, `task#myPrivate`, `task#delete`, `user#update` (PUT `/users/{id}`), `room#needingCleaning` |
| Dead checklist ×2 | `checklist#initializeProgress`, `checklist#getCompleteChecklist` |
| task-sync ×6 (whole surface) | `task_sync#complete`, `syncFromNextcloud`, `getReminders`, `getPendingSyncs`, `markAsSynced`, `linkTask` |
| Test-only lifecycle/leaderboard/config ×8 | `task#complete`, `task#skip`, `task#reschedule`, `task#daily`, `room_task#deactivate`, `game#leaderboard`, `reward_config#index`, `reward_config#update` |

**Not pruned** (explicitly): `task#uncomplete` (production consumer: undo path `roomTasks.js:554`), `room_task#reactivate` (undo path :584), `room_task#assignees` GET/PUT (production), all `/test/*` ×3.

> **Plan-review correction (2026-08-29):** the earlier draft (and the review record's wrapper row, "GET `/users/{id}`") listed `user#show` — **no such route exists** (git-verified: never present in `routes.php`; `UserController` has no `show()`). The intended no-consumer route is **`user#update`** (PUT `/users/{id}`), whose only frontend consumer is the dead `updateUserProfile` action (§3.3). The 39/71 arithmetic closes only with this correction; re-scoping CR-053-18 loses its schedulingPreferences-validation remainder along with the route.

### 3.2 Backend cascade (delete once caller-less; per-item grep gate)

- **Controllers:** `TaskSyncController` + `RewardConfigController` deleted whole. Method deletions: `TaskController` ({show, delete, complete, skip, reschedule, daily, byCategory, search, categories, myPrivate, `mapDailyTaskForApi`}); `FloorController` ({createAlias, createExplicit, index, show}); `RoomController` ({createAlias, createExplicit, index, show, needingCleaning}); `RoomTaskController` ({show, dueWithinDays, assign, unassign, addAssignee, removeAssignee, overdueClaims, deactivate}); `UserController::{update}`; `ChecklistController` ({initializeProgress, getCompleteChecklist}); `GameController::{leaderboard}`.
- **Services:** `TaskSyncService` deleted whole (only consumer = its controller) incl. `CompletedTaskMapper::findPendingSyncsForHouse` and the CR-SVC-21 reflection-guard test; `RoomService::completeRoomTask` loses the now-dead `$nextcloudTaskId` sync hook (CR-SVC-46 wiring simplified). Dead methods per L2-4: `TaskService::{getAllTasks, skipTask, rescheduleTask}`, `RoomTaskService::{getTasksAssignedToUser, getUnassignedTasks, completeTaskSimple, skipTask, rescheduleTask}` (their only callers are pruned routes; `uncompleteTask` **stays**), `RoomService::getRoom`, `FloorService::getFloor`, `RewardConfigService::getTierMultiplier`, `AuthorizationService::{isAuthenticated, isHouseMember}` (grep tests first — caller `AuthorizationIntegrationTest:73-80` reworks to `ensureHouseMember`), plus the CS-PLAN-054 checklist pair `ChecklistService::{initializeProgress:246, getCompleteChecklist:261}` → `RoomTaskChecklistProgressMapper::initializeProgress:113` (their only caller is the pruned `ChecklistController`; CS-054 guard specs trim with them). `RewardConfigService` itself stays (consumed by GameLogic/House/StreakRestock/Title services).
- **Entities/mappers (L1-4):** Cluster A `AvailabilitySettings` entity + mapper + `chorestrike_availability` table + `TestDataService` injection + fixture + RESET_TABLES entry. Cluster B entity/mapper helpers (`HouseUser` ×4, `CompletedTask::isRecent`, `RoomTaskChecklistProgress::wasCheckedRecently`, `Medal` ×2, `MedalMapper` ×3, `MinigameScoreMapper::findByHouseAndGame`, `Task::validate` + `TaskChecklistItem::validate` + their test-only suites). Cluster C mapper methods — **verify-before-delete**: `CompletedTaskMapper` ×5, `HouseUserMapper` ×3 + alias chain, `HouseMapper::findAll`/`RoomMapper::findAll` overrides, `RoomTaskMapper::findByScopeId`. Cluster D orphaned tables `task_scopes`/`task_parts`/`claims`.
- **Schema drift pair (CR-053-10):** delete `Task::$reminderType`/`$reminderMinutesBefore` (no columns; only reader was TaskSyncService) and `Room::$description` phantom field (jsonSerialize contract change accepted pre-release).
- **Migrations (pre-release chain edit, no upgrade paths):** delete no-op/dead steps Version1017, 1018, 1019, 1020; delete postSchemaChange backfill blocks in 1024/1025 (keep changeSchema); delete dead guard blocks in 1002/1003/1010/1015; remove orphan-table creation from V1001 (`task_scopes`)/V1008 (`task_parts`)/V1009 (`claims`) and the `chorestrike_availability` creation from `Version1013Date20260301210000.php:17-68`, plus its now-dead index block in `Version1015` (:128-142) (ENV-003 **resolved by 2026-08-29 plan review**: only **one** Version1013 file exists — the record's "1013 appears twice" was the same-date-stamp as Version1010's; the real duplicated version is Version1004 ×2, and no Version1014 exists).

### 3.3 Frontend dead stores (L4-3 + CR-053-20 dead half)

- Delete whole: `src/store/index.js` (barrel + all helpers; only consumer is its own spec) → transitively `house.js::initializeHouse`; `src/utils/mobile.js`; `src/utils/performance.js` (removes the last raw `console.log`).
- Store members: `game.js` (9 getters, 8 actions, `initializeGame`, `fetchLeaderboard`, `leaderboard` state); `user.js` (5 getters, 8 actions incl. the reward actions **and `updateUserProfile` — sole consumer of the pruned `user#update` route** — **CR-P4-03 decision: delete**, verify `coins`/`currentStreak` liveness); `rooms.js` (4 actions, `currentFloor`/`currentRoom` + setters, `roomsNeedingCleaning`); `tasks.js` (5 actions, 4 getters); `roomTasks.js` (12 getters, 6 actions mirroring dead routes — keep `uncompleteRoomTask`/`reactivateRoomTask`/undo); `checklist.js` (`initializeProgress`, `getCompletionPercent`, `isItemCompleted`); `scheduling.js` (9 getters superseded by `utils/energyBudgets.js`); `minigame.js` (7 dead members).
- `src/utils/roomIcons.js` internal `getRoomIconOption`/`ROOM_ICON_OPTIONS` — plan-review verified: only consumer is `tests/unit/utils/roomIcons.spec.js` → delete both (spec cases with them); `resolveRoomIcon` stays.

### 3.4 Test rework (before the routes die)

- Delete: `release-reward-config.spec.ts` (tests the pruned surface), `DailyTasksApiTest` (task#daily/complete/skip/reschedule), `AvailabilityApiTest` (already stale — targets `/smart-schedule/*` removed 2026-07-28), `TaskSyncServiceTest` + TaskSync integration tests (`tests/integration/Service/TaskSyncCompletionTest.php`), RewardConfig controller tests (service suite stays, minus the `getTierMultiplier` cases); the dead stores' specs trim in the same WP: `storeBarrel.spec.js`, `utils/mobile.spec.js` + `utils/performance.spec.js` (sole importers of the deleted utils), `TaskSyncControllerTest`, Room/Floor index-create cases, `roomTasks.spec.js:244` (POST assignees), `checklist.spec.js:207` (initialize), `user.spec.js:254` (updateUserProfile).
- Rework: `release-notifications.spec.ts` reward-config read/write → configure thresholds via test seed profiles/`TestDataService` extension; `release-task-templates.spec.ts:112` GET `/rooms/{id}` → use the with-rooms payload; `release-task-lifecycle.spec.ts` (skip/reschedule/deactivate) → re-scope onto surviving lifecycle routes (uncomplete/reactivate) or delete with coverage note; `time-travel-streak-notification.spec.ts:49` leaderboard → `GET /users`; `RouteMatrixTest` — it has **no count/expected-set assertion today** (only structural suite-tests over whatever `routes.php` declares), so **add** the pin: 71 expected routes (67 production + `/test/*` ×3 + `page#index`) + a not-reintroduced guard for the 39 pruned names, modeled on `testPrunedStatsRoutesAreNotReintroduced` (:189).

### 3.5 Docs (coupled truth-repair)

`docs/api/README.md` (pruned sections + count), `docs/concepts/ui-use-cases.md` (matrix/header), `docs/concepts/task-model-and-sync.md` (sync surface → pruned marker; FEAT-UX-021 closes on this), `docs/concepts/energy-and-balancing.md` (availability cluster), `docs/engineering/architecture.md:301` (availability table), `docs/standards/guidelines/security.md` (pruned-endpoint example reword — the CR-053-21 slice coupled to this prune).

## 4. Tests

- **Unit (PHPUnit):** new `RouteMatrixTest` route-count + pruned-name guard (71 routes; shadowing tests must still hold); delete suites of deleted classes; trim cases of deleted **methods** from surviving suites (`RewardConfigServiceTest` getTierMultiplier cases; skip/reschedule/daily/simple-completion cases in `TaskServiceTest`, `RoomTaskServiceTest`, `RoomTaskServiceDailyTest`, `TaskControllerDailyTest`, `TaskControllerIntegrationTest`; Checklist guard specs, `AuthorizationServiceTest`, `AuthorizationIntegrationTest:73-80`, `TestDataServiceTest`); `TaskSyncServiceTest` (incl. the CR-SVC-21 reflection guard) deleted with the class.
- **Integration (Docker):** reworked suites above stay green; `RoomTaskMapperDailyTest` unaffected; full suite no new skips/fails (baseline 233 / 24 pre-existing skips post-CS-054).
- **Jest:** delete `tests/unit/store/storeBarrel.spec.js` (the barrel's "own spec"), `tests/unit/utils/{mobile,performance}.spec.js` (sole importers of the deleted utils) + cases/specs of deleted store members; full run green (baseline 848).
- **E2E (Playwright):** reworked specs green; **blocking chromium-shaped gate re-run at the end** (route pruning changes backend behavior even though `src/` rendered behavior is unchanged).
- **Migration replay gate:** `down -v` + `up -d` + all migrations on the edited chain (fresh install must stay 100%).

## 5. File Manifest

```
modified: appinfo/routes.php
modified: lib/Controller/{TaskController,FloorController,RoomController,RoomTaskController,UserController,ChecklistController,GameController}.php
deleted:  lib/Controller/{TaskSyncController,RewardConfigController}.php
deleted:  lib/Service/TaskSyncService.php
modified: lib/Service/{TaskService,RoomTaskService,RoomService,FloorService,RewardConfigService,AuthorizationService,ChecklistService}+`RoomService::completeRoomTask` signature (drop `$nextcloudTaskId`)
deleted:  lib/Db/{AvailabilitySettings,AvailabilitySettingsMapper}.php
modified: lib/Db/{Task,Room,HouseUser,CompletedTask,RoomTaskChecklistProgress,RoomTaskChecklistProgressMapper,Medal,MedalMapper,MinigameScoreMapper,CompletedTaskMapper,HouseUserMapper,HouseMapper,RoomMapper,RoomTaskMapper}.php
modified: lib/Migration/{Version1001,1002,1003,1008,1009,1010,1015,1017,1018,1019,1020,1013*,1024,1025}*.php (dead steps/blocks/table creations removed)
modified: lib/Service/TestDataService.php · tests/fixtures/TestDataFixtures.php
modified: src/store/{game,user,rooms,tasks,roomTasks,checklist,scheduling,minigame,house}.js
deleted:  src/store/index.js · src/utils/mobile.js · src/utils/performance.js
modified: src/utils/roomIcons.js (delete getRoomIconOption/ROOM_ICON_OPTIONS)
modified: tests/unit/RouteMatrixTest.php (new count + pruned-names guard) · tests/e2e/{release-notifications,release-task-templates,release-task-lifecycle,time-travel-streak-notification}.spec.ts · trimmed surviving suites {TaskServiceTest,RoomTaskServiceTest,RoomTaskServiceDailyTest,TaskController*(Daily)Test,RoomTaskControllerTest,ChecklistControllerTest,ChecklistApiTest*,RewardConfigServiceTest,Authorization*Test,TestDataServiceTest,store specs,unit Db/Mapper specs of deleted helpers}
deleted:  tests/e2e/release-reward-config.spec.ts · tests/integration/{AvailabilityApiTest,DailyTasksApiTest}.php · tests/integration/Service/TaskSyncCompletionTest.php · tests/unit/Service/TaskSyncServiceTest.php · tests/unit/Controller/{TaskSyncControllerTest,RewardConfigControllerTest}.php · tests/unit/Db/Entity/AvailabilitySettingsTest.php · tests/unit/Db/Mapper/AvailabilitySettingsMapperTest.php
modified: docs/api/README.md · docs/concepts/{ui-use-cases,task-model-and-sync,energy-and-balancing}.md · docs/engineering/architecture.md · docs/standards/guidelines/security.md
modified: docs/backlog/{backlog.md,ACTIVE_TASKS.md} · docs/backlog/archive/2026-08.md (moves)
```

## 6. Implementation Order

1. **WP-A — Test-consumer rework first** (§3.4): E2E/integration specs off the pruned routes; `TestDataService` reward-config seeding for `release-notifications`. Gates stay green with routes still present.
2. **WP-B — BUG-ROOM-001 cluster**: delete 4 alias routes + `floor#index`/`room#index` (GET-create branches die with them); prune their controller methods. Gate: integration + Jest.
3. **WP-C — Remaining route pruning** (§3.1) + controller cascade. Gate: `RouteMatrixTest` + full PHPUnit + blocking E2E subset.
4. **WP-D — Backend dead-code cascade** (§3.2 services/entities/mappers + reminder/description fields), each deletion behind a fresh grep gate. Gate: unit + integration.
5. **WP-E — Migration chain cleanup** (§3.2 migrations) + fresh-install replay gate (`down -v`/`up -d`, oc_migrations complete).
6. **WP-F — Frontend dead stores** (§3.3). Gate: Jest + lint + stylelint + `test:noise`.
7. **WP-G — Docs + backlog**: §3.5 docs; move CR-053-14/15, BUG-ROOM-001, CR-053-05/08, FEAT-UX-021, CR-053-10 to archive; re-scope CR-053-06/16/18/20 remainders in `backlog.md`; record the ENV-003 verdict (resolved by the 2026-08-29 plan review, see §3.2); update ACTIVE_TASKS.
8. Full build gate (`npm test` + lint + Docker PHPUnit + blocking E2E project).

## 7. Risks & Mitigations

| Risk | Likelihood | Mitigation |
|------|-----------|------------|
| Hidden consumers surface after deletion (proofs from 2026-08-27 stale post-CS-054) | medium | Per-cluster re-grep gate before each deletion; `RouteMatrixTest` + full Jest/PHPUnit catch stragglers |
| E2E harness depends on reward-config surface | high (verified: 2 specs) | WP-A moves config into test seed profiles; routes still pruned — no conditional registration |
| Migration-chain edit breaks fresh install | medium | Replay gate in WP-E (fresh volume, 28→edited-count migrations, app boots, integration suite runs) |
| `task#uncomplete` accidentally swept with lifecycle routes | low | Explicit keep-list in §3.1; `undoRoomTaskCompletion` spec pins the undo path |
| Deleting `Room::$description` changes API payload shape | accepted | Pre-release, no BC obligation; documented in api/README |
| Cascaded deletions uncover further dead code | certain (benign) | Record-and-delete inline when grep-proofed; anything uncertain stays + backlog note |

## 8. Acceptance Criteria

- [x] 39 routes deleted; route count 71 (67 production + 3 test harness + page#index); `user#update` (PUT `/users/{id}`) is one of the 39; new `RouteMatrixTest` count + pruned-names guard green
- [x] BUG-ROOM-001 closed: no GET route mutates state anywhere (`grep` gate: no `create` calls inside `index` methods — both `index()` methods are gone)
- [x] Task-sync surface (controller, service, routes, tests) and RewardConfig HTTP surface fully removed; `RewardConfigService` retained for its 4 service consumers
- [x] Dead-store layer removed (§3.3 list) incl. the CR-053-20 wire-or-delete resolution (deleted); no new `console.*`
- [x] Migration chain: fresh install replays clean; no dead steps, no orphan tables
- [x] Backlog moves done (CR-053-14/15, BUG-ROOM-001, CR-053-05/08/10, FEAT-UX-021 → archive; CR-053-06/16/18/20 re-scoped)
- [x] Build gate green (`npm test` + lint + stylelint + Docker PHPUnit unit/integration + blocking E2E project + migration replay)
- [x] Durable decisions (route-disposition ADR reference, prune ledger) integrated into `docs/api/` + `ui-use-cases.md`; ACTIVE_TASKS updated — **done**; a standalone route-disposition ADR was ruled **not warranted** at the Standard-B review (KISS — the canonical disposition record already lives in `routes.php` prune notes + the `RouteMatrixTest` 71-pin/reintro guards + `docs/api/README.md` + `ui-use-cases.md` Appendix A; an ADR would duplicate, not decide, anything).

## Review Outcome

**Standard-A plan review (2026-08-29): GO-WITH-CHANGES** — fixes applied inline in `new/`:

- **User-disposition correction:** draft listed phantom `user#show` (route and controller method never existed, git-verified); the real no-consumer route behind the dead `updateUserProfile` wrapper is **`user#update`** (PUT `/users/{id}`) — §3.1/§3.2/§8 corrected; 39/71 arithmetic now closes against `routes.php` (manually recounted: 110 total).
- **`RouteMatrixTest`:** §3.4/§4/§8 corrected — the test has no count/expected-set assertion today; the plan now *adds* the 71-route pin + pruned-names guard.
- **Cascade completeness:** added the CS-PLAN-054 `ChecklistService::{initializeProgress, getCompleteChecklist}` → `RoomTaskChecklistProgressMapper::initializeProgress` pair (caller-less once the checklist routes die).
- **Test-plan + manifest repairs:** real spec names/paths (`storeBarrel.spec.js`, `utils/{mobile,performance}.spec.js` are their sole importers, `src/utils/roomIcons.js` not `constants/`, `TaskSyncCompletionTest`), explicit surviving-suite trims (`RewardConfigServiceTest`, `Task*ServiceTest`, `AuthorizationIntegrationTest:73-80`, …), ENV-003 parenthetical corrected (one Version1013 file; the duplication is Version1004 ×2, no 1014).
- **Verified against the tree:** 110-route total; §3.1 all other names exist; GET-create refs `FloorController:51-71`/`RoomController:55-88`; keep-list lines `roomTasks.js:554/:584`; all six named test consumers (§3.4) incl. `release-notifications.spec.ts:48/57`; no other e2e/integration consumer of pruned URLs found; `TaskSyncService` only consumed by its controller (+ the `$nextcloudTaskId` param — TaskSync sole pass-through caller); `getTierMultiplier` test-only; `isAuthenticated`/`isHouseMember` test-only (integration caller named); `RewardConfigService` 4–5 service consumers; availability cluster + V1001/1008/1009 orphan-table refs; migration count 28; baselines (Jest 848, integration 233/24 skips); CR-053-14/15/20, CR-P4-03, FEAT-UX-021, BUG-ROOM-001 mappings; CS-PLAN-056 dependency direction consistent.
- **Open verification items for the WP grep gates (not blockers):** `room_task#assignees` GET/PUT labeled "production" while no `src/` call site was found (only `teamwork-rewards.spec.ts:75`); re-check under WP-C's gate. Record L3-0's per-bucket labels carry the same one phantom the plan inherited — the totals (30/12) were not re-derivable from the record alone; WP-C's RouteMatrix guard is the authoritative check.

**Standard-B code review (2026-08-30): PASS** — full uncommitted diff (~140 files) checked against the shared §3 checklist; both WP open items resolved (assignees GET = live via TodayView `fetchAssignees`; route arithmetic verified mechanically: 110→71, removed set = the exact 39 of §3.1, `RouteMatrixTest` count pin + re-intro guard match URL-for-URL):

- **Verified:** BUG-ROOM-001 closed (no `index()` left in Floor/Room controllers, no GET mutation); `@PublicPage` nowhere; surviving user-mutations keep `requireHouseOwner`; deleted class/method/mapper-name census shows zero surviving callers or dangling imports in `lib/`, `src/`, `tests/`; migration chain 21 files (1003/1010/1013/1017–1020 gone, Version1004 ×2 untouched, backfill/guard/index removals carry docblock truth-notes); reward-config seed knob uses the real registry key (`RewardConfigService::KEY_WEEKLY_GOAL_TARGET_PERCENT`) and `reset()` symmetric delete; IConfig mock extended with `deleteAppValue` only; e2e reworks (notifications→family seed, leaderboard→`GET /users`, SEED_DEPENDENT_SPECS trim) consistent; `householdProgress` reduction matches its only live consumer (`HouseholdGoalBar` via `gameStore.weeklyGoal`); no new `console.*`; `t()` coverage unaffected (deletions only).
- **Fixed inline (3 newly-orphaned leftovers the WP-D grep gates missed, each with a fresh no-caller proof):** `RoomTaskMapper::findByNextcloudTaskId` (sole caller was `TaskSyncService`), `RoomService::calculateRoomStats` private (sole caller was the deleted `getRoomWithTasks`), `RoomService::getRoomsNeedingCleaning` + `RoomMapper::findNeedingCleaning` + their 2 self-testing unit cases (sole callers were the deleted `RoomController::needingCleaning` / pruned `fetchRoomsNeedingCleaning` action). The archived CR-053-15 note's "newly-orphaned items deleted" claim is now true.
- **Gate re-run after fixes:** lint 0 · Jest **754** · PHPUnit unit **755** (757 − 2 deleted self-tests) · integration **198** [21 pre-existing skips] — all green; E2E not re-run (fixes are caller-less internal deletions; HTTP surface unchanged).
- **Deferred (backlog):** CR-055-03 (`nextcloud_task_id` writer-less columns/payload keys — migration-chain decision), CR-055-04 (`FloorMapper::findAll` pre-existing dead). CR-055-01/02 were already logged at WP-G. No separate route-disposition ADR (see §8 last checkbox rationale).

**Post-review `/review uncommitted` second pass (2026-08-30): 6 findings, all fixed** — independent 6-track review of the same diff:
- **WARNING fixed**: `release-notifications` weekly-goal bell check now dismisses stale rows before completing (OCS key field is `notification_id`; `reset()` never purges the server-wide bell, so a bare "exists" check could pass vacuously once the family seed made `target=1` global); verified by a live serial-pool E2E run (spec green). While debugging, a **pre-existing** non-CS-055 flaw surfaced: `insights-flow` overcapacity-marker test structurally fails on Sundays (probe "tomorrow" crosses the calendar's Monday week start) → **BUG-E2E-003** (the 08-29 run only passed by date-luck; full serial re-run 2026-08-30: 92 pass / 1 known-date fail / 4 pre-existing skips).
- **Auto-fixed (S)**: seed knob now references `RewardConfigService::KEY_WEEKLY_GOAL_TARGET_PERCENT` (seed + its unit test; kills the hidden string-mirror drift), `CompletedTaskMapper::findLatestByRoomTask` deleted (4th newly-orphaned leftover: sole callers were TaskSyncService + its deleted tests), dead `$changed` local removed from Version1024, `@see` file paths repaired in Version1027/1026 (they pointed at steps this diff deleted).
- **Rejected with proof**: `Room::monsterCount` "orphan" claim (live reader `FloorService::calculateFloorStats`), `resetProgress`/`Task` getter claims (pre-existing or in-file users), route-guard spelling audit (39/39 URL-exact), authz/PHPDoc-rot claims (zero residual references). Re-gate after fixes: lint 0 · Jest 754 · PHPUnit unit **755** · integration 198 [21 pre-existing skips] · encoding/noise/l10n green.



## Execution Log (2026-08-29)

All work packages executed in plan order; gates green at each step. Deviations
and discoveries (grep-gated per §6):

- **WP-A** — E2E reworks as planned + two extras: `release-task-lifecycle`
  re-scoped onto the SURVIVING due-date route (its reschedule semantics) +
  complete→uncomplete→reactivate trio. `TestDataService.seedFamily()` now seeds
  `weekly_goal_target_percent=1` via `FAMILY_REWARD_CONFIG_OVERRIDES`
  (reset() deletes it again); **defect fixed en route**: the first draft wrote
  app values through `IConfig` (whose mock accepted it) — corrected to the
  real app-value API surface + the mock extended, pinned by a new
  TestDataServiceTest expectation. Deleted: `release-reward-config.spec.ts`
  (+ its `SEED_DEPENDENT_SPECS` entry), `DailyTasksApiTest`,
  `AvailabilityApiTest`, `TaskSyncCompletionTest`.
- **WP-B/C** — 39 routes deleted (routes.php prune note); controller cascade
  done whole (incl. `mapDailyTaskForApi`, private resolve-helpers kept for the
  create paths; stale `show()/delete()` cross-references in `TaskController/
  TaskService` docblocks reworded). **Open-verification item resolved**:
  `room_task#assignees` GET IS production (TodayView per-row resolution via
  `fetchAssignees`) — kept GET+PUT as planned. Test trims as listed in §3.4.
- **WP-D** — §3.2 executed; *newly-orphaned-by-this-plan* deletions recorded
  per the risk row: `TaskService::{getTasksByCategory,searchTasks,
  getTaskCategories,deleteTask}` + `TaskMapper::{findAll,findByCategory,
  searchByName,getCategories}`; `RoomService::{getRooms,getRoomWithTasks}`;
  `FloorService::{getFloors,getFloorWithRooms}`; `GameLogicService::
  getLeaderboard` + `HouseUserMapper::getLeaderboard`; `MedalMapper::
  findByUserId`; `CompletedTaskMapper::{findByUserId,findByRoomTaskId,
  getUserStats,getMaxRoomCompletionCountByUser,findPendingSyncs,
  findPendingSyncsForHouse}`; ChecklistService pair + progress mapper.
  `TaskService::getMyPrivateTasks` KEPT (integration privacy coverage, per plan).
  **Cluster C "verify-before-delete" outcomes**: `RoomTaskMapper::findByScopeId`
  KEPT (live via `room_task#index` scopeId branch); the HouseUserMapper alias
  chain KEPT (every link has live lib callers); `HouseUserMapper::
  findActiveByHouse` deleted (CR-P1-21 — queried a column the schema never had).
  `updateMemberPreferences`/scheduling paths untouched.
- **WP-E** — migration chain 28→**21** steps: files deleted 1003 (house_users
  did not exist yet — always a no-op), 1010 (create-guard superseded by
  Version1004 inline), 1013 (availability), 1017-1020 (renames no-op; the four
  hook-added columns exist via Version1027 changeSchema); dead guard blocks
  removed in 1002 (completed_tasks branch — table did not exist yet) and
  1015 (availability/scopes/claims/participants index blocks + empty pre/post
  stubs); orphan table creations removed in 1001/1008/1009. Replay gate:
  `down -v` + `up -d` → `oc_migrations` complete, 12 canonical tables, no
  orphans, `task_tier/flex_days/ideal_due_date/coin_multiplier` present.
  `Version1015…Test` expectation updated (8→4 hasTable guards);
  `FreshInstallMigrationSmokeTest` passes untouched (globs the chain).
- **WP-F** — §3.3 executed; counts differ slightly from the plan prose (grep
  gate is authoritative): game.js lost **10** getters (not 9) + the 6 named
  actions + initializeGame/fetchLeaderboard + `leaderboard`/`combos` state,
  and the live-shape `householdProgress` was reduced to its `weeklyGoal`
  snapshot slice (aggregation fields lost their getters+actions together);
  weeklyGoal getter + celebrations machinery + triggerCelebration KEPT
  (HouseholdGoalBar/setWeeklyGoal). user.js lost **8** getters (incl.
  coins/currentStreak — the "verify" half: no `userStore.coins` consumers
  exist; the `.coins` hits are API payload fields, not the getters) + 8
  actions; minigame.js lost 6 members (`showLeaderboard` STATE is live via
  SnakeGame direct access — only `toggleLeaderboard` went); scheduling.js
  lost exactly the 9 superseded getters + the now reader-less
  `todayRemainingEnergy`; `roomTasks.js` lost 10 getters + the 6 named
  actions; tasks.js: `initializeTasks` confirmed dead on re-grep (TaskManager
  has its own method of that name); roomIcons/api barrel per plan.
  `registerTodayEnergyConsumption` + `todayConsumedEnergy` KEPT (plan
  boundary) — flagged as a remaining reader-less cluster for the backlog note.
  Full Jest 754 green (baseline 848 − pruned members' cases/specs).
- **WP-G** — docs per §3.5 (incl. ADR-005 truth-note + the new security.md
  "No Mutations on GET" rule) + backlog: closes/rewrites as listed; moves in
  `archive/2026-08.md`.
- **Deferred follow-up (recommend backlog at review)**: scheduling store's
  `todayConsumedEnergy` + `registerTodayEnergyConsumption` now have no reader
  (written by roomTasks, never surfaced; the server-side preferences payload
  still carries the field) — candidate for a follow-up dead-code entry or a
  deliberate "energy remaining" UI feature. → logged as **CR-055-01** at WP-G.

**Standard-B review (2026-08-30): PASS** — see Review Outcome for checks, the
3 inline fixes (`RoomTaskMapper::findByNextcloudTaskId`,
`RoomService::calculateRoomStats`, `RoomService::getRoomsNeedingCleaning` +
`RoomMapper::findNeedingCleaning`), re-greened gates, and the CR-055-03/04
backlog deferrals. Plan complete; ready for the manual `active/ → done/` move.
