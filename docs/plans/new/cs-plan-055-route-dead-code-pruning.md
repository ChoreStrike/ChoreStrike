# CS-PLAN-055: Route Pruning & Dead-Code Sweep (U2-A)

> **Status:** Plan (not started)
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
| Dead task/user/room wrappers ×7 | `task#byCategory`, `task#search`, `task#categories`, `task#myPrivate`, `task#delete`, `user#show`, `room#needingCleaning` |
| Dead checklist ×2 | `checklist#initializeProgress`, `checklist#getCompleteChecklist` |
| task-sync ×6 (whole surface) | `task_sync#complete`, `syncFromNextcloud`, `getReminders`, `getPendingSyncs`, `markAsSynced`, `linkTask` |
| Test-only lifecycle/leaderboard/config ×8 | `task#complete`, `task#skip`, `task#reschedule`, `task#daily`, `room_task#deactivate`, `game#leaderboard`, `reward_config#index`, `reward_config#update` |

**Not pruned** (explicitly): `task#uncomplete` (production consumer: undo path `roomTasks.js:554`), `room_task#reactivate` (undo path :584), `room_task#assignees` GET/PUT (production), all `/test/*` ×3.

### 3.2 Backend cascade (delete once caller-less; per-item grep gate)

- **Controllers:** `TaskSyncController` + `RewardConfigController` deleted whole. Method deletions: `TaskController` ({show, delete, complete, skip, reschedule, daily, byCategory, search, categories, myPrivate, `mapDailyTaskForApi`}); `FloorController` ({createAlias, createExplicit, index, show}); `RoomController` ({createAlias, createExplicit, index, show, needingCleaning}); `RoomTaskController` ({show, dueWithinDays, assign, unassign, addAssignee, removeAssignee, overdueClaims, deactivate}); `UserController::{show}`; `ChecklistController` ({initializeProgress, getCompleteChecklist}); `GameController::{leaderboard}`.
- **Services:** `TaskSyncService` deleted whole (only consumer = its controller) incl. `CompletedTaskMapper::findPendingSyncsForHouse` and the CR-SVC-21 reflection-guard test; `RoomService::completeRoomTask` loses the now-dead `$nextcloudTaskId` sync hook (CR-SVC-46 wiring simplified). Dead methods per L2-4: `TaskService::{getAllTasks, skipTask, rescheduleTask}`, `RoomTaskService::{getTasksAssignedToUser, getUnassignedTasks, completeTaskSimple, skipTask, rescheduleTask}` (their only callers are pruned routes; `uncompleteTask` **stays**), `RoomService::getRoom`, `FloorService::getFloor`, `RewardConfigService::getTierMultiplier`, `AuthorizationService::{isAuthenticated, isHouseMember}` (grep tests first). `RewardConfigService` itself stays (consumed by GameLogic/House/StreakRestock/Title services).
- **Entities/mappers (L1-4):** Cluster A `AvailabilitySettings` entity + mapper + `chorestrike_availability` table + `TestDataService` injection + fixture + RESET_TABLES entry. Cluster B entity/mapper helpers (`HouseUser` ×4, `CompletedTask::isRecent`, `RoomTaskChecklistProgress::wasCheckedRecently`, `Medal` ×2, `MedalMapper` ×3, `MinigameScoreMapper::findByHouseAndGame`, `Task::validate` + `TaskChecklistItem::validate` + their test-only suites). Cluster C mapper methods — **verify-before-delete**: `CompletedTaskMapper` ×5, `HouseUserMapper` ×3 + alias chain, `HouseMapper::findAll`/`RoomMapper::findAll` overrides, `RoomTaskMapper::findByScopeId`. Cluster D orphaned tables `task_scopes`/`task_parts`/`claims`.
- **Schema drift pair (CR-053-10):** delete `Task::$reminderType`/`$reminderMinutesBefore` (no columns; only reader was TaskSyncService) and `Room::$description` phantom field (jsonSerialize contract change accepted pre-release).
- **Migrations (pre-release chain edit, no upgrade paths):** delete no-op/dead steps Version1017, 1018, 1019, 1020; delete postSchemaChange backfill blocks in 1024/1025 (keep changeSchema); delete dead guard blocks in 1002/1003/1010/1015; remove orphan-table creation from V1001/V1008/V1009 and the availability table creation from its Version1013 step (ENV-003: two 1013-prefixed files — locate the right one).

### 3.3 Frontend dead stores (L4-3 + CR-053-20 dead half)

- Delete whole: `src/store/index.js` (barrel + all helpers; only consumer is its own spec) → transitively `house.js::initializeHouse`; `src/utils/mobile.js`; `src/utils/performance.js` (removes the last raw `console.log`).
- Store members: `game.js` (9 getters, 8 actions, `initializeGame`, `fetchLeaderboard`, `leaderboard` state); `user.js` (5 getters, 8 actions incl. the reward actions — **CR-P4-03 decision: delete**, verify `coins`/`currentStreak` liveness); `rooms.js` (4 actions, `currentFloor`/`currentRoom` + setters, `roomsNeedingCleaning`); `tasks.js` (5 actions, 4 getters); `roomTasks.js` (12 getters, 6 actions mirroring dead routes — keep `uncompleteRoomTask`/`reactivateRoomTask`/undo); `checklist.js` (`initializeProgress`, `getCompletionPercent`, `isItemCompleted`); `scheduling.js` (9 getters superseded by `utils/energyBudgets.js`); `minigame.js` (7 dead members).
- `roomIcons.js` internal `getRoomIconOption`/`ROOM_ICON_OPTIONS` — verify then delete.

### 3.4 Test rework (before the routes die)

- Delete: `release-reward-config.spec.ts` (tests the pruned surface), `DailyTasksApiTest` (task#daily/complete/skip/reschedule), `AvailabilityApiTest` (already stale), `TaskSyncServiceTest` + TaskSync integration tests, RewardConfig controller tests (service suite stays).
- Rework: `release-notifications.spec.ts` reward-config read/write → configure thresholds via test seed profiles/`TestDataService` extension; `release-task-templates.spec.ts:112` GET `/rooms/{id}` → use the with-rooms payload; `release-task-lifecycle.spec.ts` (skip/reschedule/deactivate) → re-scope onto surviving lifecycle routes (uncomplete/reactivate) or delete with coverage note; `time-travel-streak-notification.spec.ts:49` leaderboard → `GET /users`; `RouteMatrixTest` expected-route set → 71 (67 production + `/test/*` ×3 + `page#index`).

### 3.5 Docs (coupled truth-repair)

`docs/api/README.md` (pruned sections + count), `docs/concepts/ui-use-cases.md` (matrix/header), `docs/concepts/task-model-and-sync.md` (sync surface → pruned marker; FEAT-UX-021 closes on this), `docs/concepts/energy-and-balancing.md` (availability cluster), `docs/engineering/architecture.md:301` (availability table), `docs/standards/guidelines/security.md` (pruned-endpoint example reword — the CR-053-21 slice coupled to this prune).

## 4. Tests

- **Unit (PHPUnit):** update `RouteMatrixTest` (71 routes + shadowing rules still hold); delete suites of deleted classes; keep/adjust `AuthorizationServiceTest` for the deleted methods.
- **Integration (Docker):** reworked suites above stay green; `RoomTaskMapperDailyTest` unaffected; full suite no new skips/fails (baseline 233 / 24 pre-existing skips post-CS-054).
- **Jest:** delete `store/index.spec.js` + specs of deleted members; full run green (baseline 848).
- **E2E (Playwright):** reworked specs green; **blocking chromium-shaped gate re-run at the end** (route pruning changes backend behavior even though `src/` rendered behavior is unchanged).
- **Migration replay gate:** `down -v` + `up -d` + all migrations on the edited chain (fresh install must stay 100%).

## 5. File Manifest

```
modified: appinfo/routes.php
modified: lib/Controller/{TaskController,FloorController,RoomController,RoomTaskController,UserController,ChecklistController,GameController}.php
deleted:  lib/Controller/{TaskSyncController,RewardConfigController}.php
deleted:  lib/Service/TaskSyncService.php
modified: lib/Service/{TaskService,RoomTaskService,RoomService,FloorService,RewardConfigService,AuthorizationService,RoomService(completeRoomTask signature)}.php
deleted:  lib/Db/{AvailabilitySettings,AvailabilitySettingsMapper}.php
modified: lib/Db/{Task,Room,HouseUser,CompletedTask,RoomTaskChecklistProgress,Medal,MedalMapper,MinigameScoreMapper,CompletedTaskMapper,HouseUserMapper,HouseMapper,RoomMapper,RoomTaskMapper}.php
modified: lib/Migration/{Version1001,1002,1003,1008,1009,1010,1015,1017,1018,1019,1020,1013*,1024,1025}*.php (dead steps/blocks/table creations removed)
modified: lib/Service/TestDataService.php · tests/fixtures/TestDataFixtures.php
modified: src/store/{game,user,rooms,tasks,roomTasks,checklist,scheduling,minigame,house}.js
deleted:  src/store/index.js · src/utils/mobile.js · src/utils/performance.js
modified: src/constants/roomIcons.js (verify-then-delete internals)
modified: tests/unit/RouteMatrixTest.php · tests/integration/DailyTasksApiTest.php (deleted) · tests/e2e/{release-notifications,release-task-templates,release-task-lifecycle,time-travel-streak-notification}.spec.ts
deleted:  tests/e2e/release-reward-config.spec.ts · tests/integration/AvailabilityApiTest.php · tests/unit/Service/TaskSyncServiceTest.php (+ TaskSync/RewardConfig controller suites)
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
7. **WP-G — Docs + backlog**: §3.5 docs; move CR-053-14/15, BUG-ROOM-001, CR-053-05/08, FEAT-UX-021, CR-053-10 to archive; re-scope CR-053-06/16/18/20 remainders in `backlog.md`; update ACTIVE_TASKS.
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

- [ ] 39 routes deleted; route count 71 (67 production + 3 test harness + page#index); `RouteMatrixTest` green
- [ ] BUG-ROOM-001 closed: no GET route mutates state anywhere (`grep` gate: no `create` calls inside `index` methods)
- [ ] Task-sync surface (controller, service, routes, tests) and RewardConfig HTTP surface fully removed; `RewardConfigService` retained for its 4 service consumers
- [ ] Dead-store layer removed (§3.3 list) incl. the CR-053-20 wire-or-delete resolution (deleted); no new `console.*`
- [ ] Migration chain: fresh install replays clean; no dead steps, no orphan tables
- [ ] Backlog moves done (CR-053-14/15, BUG-ROOM-001, CR-053-05/08/10, FEAT-UX-021 → archive; CR-053-06/16/18/20 re-scoped)
- [ ] Build gate green (`npm test` + lint + stylelint + Docker PHPUnit unit/integration + blocking E2E project + migration replay)
- [ ] Durable decisions (route-disposition ADR reference, prune ledger) integrated into `docs/api/` + `ui-use-cases.md`; ACTIVE_TASKS updated
