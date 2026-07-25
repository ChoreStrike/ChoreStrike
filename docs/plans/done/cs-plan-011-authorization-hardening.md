# CS-PLAN-011: Authorization Hardening, Cross-House Leak Repair & Fresh-Install Schema Fix

> **Status:** Implemented (2026-07-25) — build gate green; awaiting `active/ → done/` move
> **Effort:** L
> **Priority:** P1
> **Depends on:** none (ideally the uncommitted 2026-07-24 review changes are committed first)
> **Related issues:** bugs.md [P1] Authorization gaps & cross-house data leaks (S1–S7, L1/L2); bugs.md [P2] Fresh-install schema gap; bugs.md [P2] PostgreSQL-incompatible column rename

## 1. Problem Summary

The 2026-07-24 project-wide review confirmed a cluster of authorization and data-isolation defects (S1–S7, L1/L2) that violate the project rule "validate house membership / ownership on every mutation; never trust client input." None blocks a single-house install, but each is exploitable in a multi-house deployment via direct API calls. Additionally, two P2 deployment blockers exist: a fresh-install schema gap (Version1018–1020 columns never persist → runtime 500s) and a PostgreSQL-incompatible migration.

**Root causes identified during plan research:**

- **S6:** `AuthorizationService::require*()` methods throw `\RuntimeException`; every controller catches `\Exception` → HTTP 500 instead of 403 on auth denial.
- **S7:** `requireHouseMember()` auto-creates a membership on miss — a "require" check that mutates.
- **S1/S2/L2/D2:** The legacy group-assignment feature (`claimForUser`, `unclaim`, `assignToGroup`, `groupTasks`, `visibleToUser`) has **zero frontend consumers**, references a phantom method (`getCurrentUserGroupIds()` — does not exist in `AuthorizationService`, would crash at runtime), and lacks ownership guards. Archiving it resolves S1, S2, L2, and the phantom-call crash in one move.
- **S3:** `TaskController::delete()` checks house membership but not task visibility/ownership — tasks are global templates (no `house_id`), so any member can delete any task.
- **S4:** `TaskService::updateTask()` does reflective mass-assignment (`foreach $updates → set*()`); only the controller whitelists.
- **S5:** `userTasks()`/`userStats()` accept an arbitrary `userId` without verifying it belongs to the caller's house.
- **L1:** `findOverdue`/`findDueToday`/`findDueWithinDays` are unscoped (no house filter); the house-scoped `findOverdueByHouse` exists but was never wired into the service/controller for `dueToday`/`dueWithinDays`.
- **D3/D4:** 5 dead `RoomTaskMapper` methods query a non-existent `house_id` column; `TaskMapper::findDailyTasks()` queries non-existent columns. Zero callers.
- **Fresh-install schema gap:** Version1018/1019/1020 add columns in `preSchemaChange()` (void return) instead of `changeSchema()` (returns `?ISchemaWrapper`). The Nextcloud migration runner only persists schema diffs from `changeSchema()`. Version1025/1026 use `changeSchema()` correctly — confirming the root cause.
- **PostgreSQL:** `Version1017::renameColumn()` else-branch uses MySQL `ALTER TABLE … CHANGE`; PostgreSQL needs `RENAME COLUMN`.

## 2. Strategy

Eight phases, each independently reviewable:

1. **AuthorizationService exception model** — split `requireHouseMember` into a pure assertion vs. an explicit ensure; throw `ForbiddenException` (app-level, already exists) instead of `\RuntimeException`; add 403 catch blocks across all controllers.
2. **House-scoped due/overdue queries (L1)** — add `findDueTodayByHouse`/`findDueWithinDaysByHouse` via the existing `applyHouseScope` helper; wire into service + controller; remove unscoped variants if caller-free.
3. **Task delete ownership gate (S3)** — enforce visibility/owner rules on task deletion.
4. **Mass-assignment whitelist (S4)** — explicit mutable-field whitelist in `TaskService::updateTask()`.
5. **Cross-house user stats (S5)** — verify target userId is a house member before returning stats.
6. **Group-assignment feature archival (S1/S2/L2/D2)** — archive the entire dead feature (5 routes, 5 controller actions, 6 service methods, 6 mapper methods).
7. **Dead mapper cleanup (D3/D4)** — remove 5 dead `RoomTaskMapper` methods + `TaskMapper::findDailyTasks()`.
8. **Fresh-install schema fix + PostgreSQL migration (P2)** — new `Version1027` migration using `changeSchema()` with `hasColumn` guards; add PG branch to Version1017; verify on clean Docker volume.

## 3. Backend / Frontend Changes

### Backend (`lib/`)

**Phase 1 — AuthorizationService + controllers:**
- `AuthorizationService`: rename `requireHouseMember()` → `ensureHouseMember()` (keeps auto-create semantics, used by `getOrCreateHouse` flows); add `assertHouseMember(int $houseId): HouseUser` (pure check, throws `ForbiddenException` on miss); change `requireCurrentUserId()` and `requireHouseOwner()` to throw `ForbiddenException` instead of `\RuntimeException`.
- All 12 controllers that inject `AuthorizationService` (Checklist, Floor, Game, House, Minigame, Room, RoomTask, Scheduling, TaskSync, Task, Template, User): add explicit `catch (ForbiddenException $e) → 403` blocks (some already have them — `RoomTaskController::complete`, `TaskController::update/delete`). Audit every `catch (\Exception)` to ensure `ForbiddenException` is caught first. Four controllers do NOT inject `AuthorizationService` and are untouched: `PageController`, `SmartSchedulerController`, `RewardConfigController`, `TestController`.
- Internal callers: `isHouseOwner()` and `requireHouseOwner()` both call `requireHouseMember()` internally — update these to `ensureHouseMember()`.

**Phase 2 — House-scoped queries:**
- `RoomTaskMapper`: add `findDueTodayByHouse(int $houseId, ?int $roomId)` and `findDueWithinDaysByHouse(int $houseId, int $days, ?int $roomId)` using `applyHouseScope()`. Extend the existing `findOverdueByHouse(int $houseId)` with an optional `?int $roomId = null` parameter (it currently lacks the room filter the controller passes).
- `RoomTaskService`: change `getOverdueTasks`/`getDueTodayTasks`/`getDueWithinDaysTasks` to accept `$houseId` and delegate to the house-scoped mapper methods.
- `RoomTaskController`: pass `$house->getId()` to the three service calls.
- Remove `findOverdue`/`findDueToday`/`findDueWithinDays` — verified: only `RoomTaskService` calls them (3 call sites); no background jobs or other callers.

**Phase 3 — Task delete gate:**
- `TaskController::delete()`: load the task, check `visibilityType`/`ownerUserId` rules (mirror the existing `show()` logic). Private tasks: only owner can delete. Admin-only: only house owner. Public: any member.
- `TaskService::deleteTask()`: accept `$houseId` + `$callerUserId` + `$isOwner` for the visibility check (defense in depth).

**Phase 4 — Mass-assignment whitelist:**
- `TaskService::updateTask()`: define `MUTABLE_FIELDS` constant (whitelist of user-editable fields: `name`, `description`, `energyCost`, `priority`, `frequencyType`, `frequencyValue`, `estimatedMinutes`, `category`, `scopeType`, `scopeLabel`, `recurrenceMode`, `activeFrom`, `activeUntil`, `isVoluntary`, `schedulingMode`, `fixedScheduleTime`, `fixedScheduleDays`, `instructions`, `tips`, `estimatedMinutesMin`, `estimatedMinutesMax`, `visibilityType`, `ownerUserId`, `taskTier`, `flexDays`). Reject unknown keys with `\InvalidArgumentException` (→ 400). Note: `ownerUserId` is whitelisted for transfer, but the controller-level authorization (CS-PLAN-005) still governs *who* may change it — the whitelist is defense-in-depth against mass-assignment, not an authorization bypass.

**Phase 5 — Cross-house user stats:**
- `RoomTaskController::userTasks()`/`userStats()`: after `ensureHouseMember`, verify the target `$userId` is a member of the same house. Add `assertUserInHouse(int $houseId, string $userId): void` to `AuthorizationService` (keeps controllers thin — no new mapper injection needed). Throw `ForbiddenException` if not a member.

**Phase 6 — Group-assignment archival:**
- Remove from `appinfo/routes.php`: 5 routes (`assignToGroup`, `claimForUser`, `unclaim`, `groupTasks`, `visibleToUser`).
- Remove from `RoomTaskController`: 5 actions (`assignToGroup`, `claimForUser`, `unclaim`, `groupTasks`, `visibleToUser`).
- Remove from `RoomTaskService`: 6 methods (`assignToGroup`, `claimForUser`, `unclaim`, `getGroupTasks`, `getTasksVisibleToUser`, + any helper).
- Remove from `RoomTaskMapper`: 6 methods (`findByGroupId`, `findVisibleToUser`, `assignToGroup`, `claimForUser`, `unclaim`, + any helper).
- Archive the removed code to `archive/2026-07/group-assignment/`.
- DB columns (`assigned_to_group`, `assignment_type`, `claimed_by_user`) are retained harmlessly (no drop migration — avoids data loss on existing installs; can be dropped in a future cleanup).
- Run `RouteMatrixTest` to verify no orphaned route→controller references remain (the test parses `routes.php` dynamically — removing the routes auto-removes them from the test scope).

**Phase 7 — Dead mapper cleanup:**
- Remove from `RoomTaskMapper`: `findByHouseId`, `findUnscheduledByHouseId`, `findByAssignedUser`, `findByDeadlineRange`, `findByScheduledDate` (all query non-existent `house_id`/dead columns, zero callers).
- Remove from `TaskMapper`: `findDailyTasks()` (queries non-existent `house_id`/`scheduled_date`/`status` on `tasks`, zero callers — `TaskService::getDailyTasks()` delegates to it; remove both).
- Remove `TaskService::getDailyTasks()` (only caller of the dead mapper method; no controller/service callers).

**Phase 8 — Schema fix + PostgreSQL:**
- New `Version1027Date20260725000000`: `changeSchema()` adds `task_tier` (VARCHAR 10, NOT NULL, DEFAULT 'silver') to `chorestrike_tasks`, `coin_multiplier` (DECIMAL 3,2, NOT NULL, DEFAULT 1.00) to `chorestrike_house_users`, `flex_days` (INT, NOT NULL, DEFAULT 0) to `chorestrike_tasks`, `ideal_due_date` (DATETIME, NULL) to `chorestrike_room_tasks` — all with `hasColumn` guards (idempotent; no-op on upgraded installs where the columns already exist via the manual ALTER workaround or cached images).
- `Version1017`: add explicit `postgresql` platform branch using `ALTER TABLE … RENAME COLUMN` (or use the Doctrine schema manager declaratively).
- Verify: `docker compose -f docker-compose.test.yml down -v` → `up -d --build` → integration suite green without manual `ALTER TABLE`.

### Frontend (`src/`)

No frontend changes. All fixes are backend-only. The group-assignment feature has zero frontend consumers (verified by grep).

### Cross-layer contracts

- `overdue`/`dueToday`/`dueWithinDays` endpoints: response shape unchanged (still `RoomTask[]`), but now house-scoped. Frontend stores already pass through the authenticated session — no store change needed.
- `userTasks`/`userStats`: response shape unchanged; new 403 on cross-house access. Frontend only queries the current user's own stats — no store change needed.
- Group-assignment routes removed: no frontend consumer exists — no contract break.

## 4. Tests

### Unit (PHPUnit)

- `AuthorizationServiceTest`: `assertHouseMember` throws `ForbiddenException` on non-member; `ensureHouseMember` auto-creates; `requireCurrentUserId`/`requireHouseOwner` throw `ForbiddenException`; `assertUserInHouse` throws `ForbiddenException` for cross-house userId.
- `TaskServiceTest`: `updateTask` rejects non-whitelisted fields with `\InvalidArgumentException`; whitelisted fields apply correctly.
- `TaskControllerTest`: delete enforces visibility rules (public/private/admin-only × member/owner/non-member matrix).
- `RoomTaskControllerTest`: `userTasks`/`userStats` return 403 for cross-house userId.
- `RoomTaskMapperTest`: house-scoped `findDueTodayByHouse`/`findDueWithinDaysByHouse` return only same-house tasks.
- `RouteMatrixTest`: updated for removed group routes.

### Integration (PHPUnit, Docker)

- `AuthorizationIntegrationTest`: extend with cross-house denial cases for `userTasks`/`userStats` (403), task delete visibility gate, auth-denial → 403 (not 500).
- `RoomTaskMapperIntegrationTest` (new or extended): house-scoped due/overdue queries return only same-house tasks on a real DB.
- Fresh-install schema verification: clean Docker volume → all four columns exist → integration suite green.

### E2E (Playwright)

No new E2E specs. Existing specs cover the affected views (Dashboard, TaskManager, RoomDetail). The authorization fixes are backend-only and covered by unit + integration tests.

## 5. File Manifest

```
new:      lib/Migration/Version1027Date20260725000000.php
new:      tests/unit/Service/AuthorizationServiceExceptionTest.php
new:      tests/unit/Service/TaskServiceWhitelistTest.php

modified: lib/Service/AuthorizationService.php
modified: lib/Controller/RoomTaskController.php
modified: lib/Controller/TaskController.php
modified: lib/Controller/FloorController.php
modified: lib/Controller/RoomController.php
modified: lib/Controller/HouseController.php
modified: lib/Controller/GameController.php
modified: lib/Controller/UserController.php
modified: lib/Controller/ChecklistController.php
modified: lib/Controller/MinigameController.php
modified: lib/Controller/SchedulingController.php
modified: lib/Controller/TaskSyncController.php
modified: lib/Controller/TemplateController.php
modified: lib/Service/TaskService.php
modified: lib/Service/RoomTaskService.php
modified: lib/Db/RoomTaskMapper.php
modified: lib/Db/TaskMapper.php
modified: lib/Migration/Version1017Date20260321000000.php
modified: appinfo/routes.php
modified: tests/unit/RouteMatrixTest.php
modified: tests/unit/Service/TaskServiceTest.php
modified: tests/unit/Controller/TaskControllerTest.php
modified: tests/unit/Controller/RoomTaskControllerTest.php
modified: tests/integration/AuthorizationIntegrationTest.php
modified: docs/backlog/bugs.md
modified: docs/backlog/phase-9-code-quality.md
modified: docs/backlog/ACTIVE_TASKS.md

archived: archive/2026-07/group-assignment/ (extracted group-assignment code)
```

## 6. Implementation Order

1. **Phase 1 — AuthorizationService exception model (S6, S7).** Split `requireHouseMember` → `assertHouseMember` + `ensureHouseMember`. Throw `ForbiddenException`. Add 403 catch blocks to all controllers. Unit tests. *This is the foundation — all subsequent phases rely on `ForbiddenException` propagation.*
2. **Phase 2 — House-scoped due/overdue queries (L1).** Add `findDueTodayByHouse`/`findDueWithinDaysByHouse`. Wire into service + controller. Remove unscoped variants. Unit + integration tests.
3. **Phase 3 — Task delete ownership gate (S3).** Visibility/owner check on delete. Unit tests.
4. **Phase 4 — Mass-assignment whitelist (S4).** `MUTABLE_FIELDS` in `TaskService::updateTask()`. Unit tests.
5. **Phase 5 — Cross-house user stats (S5).** House-membership check on target userId. Unit + integration tests.
6. **Phase 6 — Group-assignment archival (S1/S2/L2/D2).** Remove 5 routes + 5 controller actions + 6 service methods + 6 mapper methods. Archive. Update `RouteMatrixTest`.
7. **Phase 7 — Dead mapper cleanup (D3/D4).** Remove 5 dead `RoomTaskMapper` methods + `TaskMapper::findDailyTasks()` + `TaskService::getDailyTasks()`.
8. **Phase 8 — Fresh-install schema fix + PostgreSQL (P2).** `Version1027` migration via `changeSchema()`. PG branch in Version1017. Clean-volume Docker verification.
9. **Phase 9 — Build gate + backlog sync.** Full gate: PHPUnit unit + integration, Jest, ESLint, `npm run build`, `php -l`. Update `bugs.md` (close S1–S7, L1/L2, schema gap, PG), `phase-9-code-quality.md`, `ACTIVE_TASKS.md`.

## 7. Risks & Mitigations

| Risk | Likelihood | Mitigation |
|------|-----------|------------|
| `assertHouseMember` breaks the auto-join flow (first user creates house) | Medium | `ensureHouseMember` preserves auto-create for `getOrCreateHouse` flows; `assertHouseMember` is used only for pure authorization checks. Audit every call site. |
| Removing unscoped `findOverdue`/`findDueToday`/`findDueWithinDays` breaks a background job or test | Low | Grep for all callers before removal; background jobs (`StreakFreezeJob`, `DailyNotificationJob`) use `CompletedTaskMapper`, not these methods. |
| Group-assignment archival removes a route a third-party API consumer depends on | Very low | Zero frontend consumers; no documented external API; pre-1.0 alpha. |
| `Version1027` conflicts with the manual `ALTER TABLE` workaround on existing Docker images | Low | `hasColumn` guards make it idempotent. |
| `MUTABLE_FIELDS` whitelist misses a field the frontend legitimately updates | Low | Derive the whitelist from `TaskController::update()`'s existing controller-level whitelist + the `TaskEditForm.vue` field set. Cross-check with `apiContract.spec.js`. |
| 403 catch blocks change error response shape for existing frontend error handling | Low | Frontend `api.js` already normalizes `ApiError` from any non-2xx; 403 vs 500 is a status-code improvement, not a shape change. |

## 8. Acceptance Criteria

- [x] S1/S2: group-assignment feature archived (routes, controller, service, mapper); `RouteMatrixTest` updated (stays green — parses routes dynamically).
- [x] S3: task delete enforces visibility/owner rules; regression tests cover public/private/admin-only × member/owner matrix.
- [x] S4: `updateTask` whitelists mutable fields; unknown keys rejected with 400; regression test.
- [x] S5: `userTasks`/`userStats` verify target userId is in the caller's house; 403 on cross-house; regression test.
- [x] S6: auth-denial paths return 403 (not 500); `ForbiddenException` thrown by `AuthorizationService`; all controllers catch it; regression tests.
- [x] S7: `assertHouseMember` (pure) separated from `ensureHouseMember` (auto-create); both tested.
- [x] L1: overdue/dueToday/dueWithinDays endpoints house-scoped; cross-house regression tests.
- [x] L2: resolved by group-assignment archival (S1/S2).
- [x] D3/D4: dead mapper methods removed; no `lib/` references remain.
- [x] Fresh install (`down -v` → `up`) produces `task_tier`, `flex_days`, `ideal_due_date`, `coin_multiplier` without manual intervention; integration suite green on clean volume.
- [x] Version1017 PostgreSQL branch added (or declarative schema manager used).
- [x] Build gate green: PHPUnit unit + integration, Jest, ESLint, `npm run build`, `php -l`.
- [x] `docs/backlog/ACTIVE_TASKS.md` updated.
