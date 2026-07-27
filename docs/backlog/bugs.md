# Bug Backlog

## [P1] E2E runs in no CI: `test.yml` triggers only on `main`/`develop`, and E2E is absent from `release-gate.yml`

**Status**: Done (CS-PLAN-015b Phase 4 D-CI, 2026-07-27) — `test.yml` repaired: triggers on `master`, Nextcloud pinned to `31-apache`, app mounted + enabled before seeding, `chromium-serial-db` project included. Blocking-gate fold-in into `release-gate.yml` is follow-up work after 015b acceptance.  
**Assignee**: Unassigned  
**Estimated Effort**: M  
**Phase**: 9

### Description
The only CI workflow that runs Playwright (`.forgejo/workflows/test.yml`) triggers on `push`/`pull_request` to `main` and `develop` — the repository's only branch is `master`, so **the E2E suite has never run in CI**. Even if triggered, the job would fail: the Nextcloud service container never gets the app mounted (the "Enable ChoreStrike app" step is a seed `curl` with `continue-on-error: true` against a Nextcloud that does not contain ChoreStrike), and it pins `nextcloud:latest` (version drift vs. the NC 31.0.14 test env). The authoritative gate (`release-gate.yml`, triggers correctly on `master`) has **no E2E job at all** — lint, Jest, build, composer, PHPUnit only. Net effect: the entire E2E investment (CS-015/015b) has zero CI protection, and a release could gate green while E2E is red.

### Acceptance Criteria
- [ ] E2E runs on every push/PR to `master` (repaired `test.yml` or equivalent) and fails the run on red — including the `chromium-serial-db` project from CS-PLAN-015b.
- [ ] The Nextcloud image is pinned (no `latest`); the app is mounted and enabled before seeding; brute-force protection disabled as in the local test env.
- [ ] Follow-up logged/planned: E2E as a blocking job in `release-gate.yml` (chromium first, full matrix after CS-PLAN-015b acceptance).

### Notes
- Found 2026-07-27 in the test-pipeline review (verified first-hand in both workflow files).
- `playwright.config.ts` sets `workers: 1` on CI — full-matrix × full-suite CI runs will be slow; the matrix split by profile is the mitigating factor.

---

## [P1] Test endpoints exposed via `@PublicPage`; `isTestMode()` triggers on any `CI` env var

**Status**: Backlog (pre-existing; surfaced by the CS-PLAN-015b review, 2026-07-27)  
**Assignee**: Unassigned  
**Estimated Effort**: S  
**Phase**: 9  
**Source**: Review of CS-PLAN-015b (E2E Phase 2)

### Description
`lib/Controller/TestController.php` annotates `seed`, `reset`, and `profiles` with `@PublicPage` (in addition to `@NoAdminRequired`) — a direct violation of the project rule "Never `@PublicPage`". `seed`/`reset` are guarded by `isTestMode()`, but `profiles()` has **no** `isTestMode()` guard and always returns data including the `testMode` flag, making it a reconnaissance probe for the seed/reset attack surface. Worse, `TestDataService::isTestMode()` returns `true` whenever `getenv('CI')` is set, so ChoreStrike deployed on **any** machine that defines a `CI` env var (GitHub Actions, GitLab, Jenkins, …) activates its test endpoints — `seed`/`reset` can wipe/reseed the app database without authentication. The CS-PLAN-015b D-CI repair now calls these endpoints from `.forgejo/workflows/test.yml`, which raised the relevance.

### Acceptance Criteria
- [ ] Remove `@PublicPage` from all three `TestController` methods; keep `@NoAdminRequired` only.
- [ ] Add an `isTestMode()` early-return guard to `profiles()` (omit the `testMode` field when not in test mode).
- [ ] Tighten `isTestMode()` to require an explicit `NEXTCLOUD_TEST_MODE` flag (drop the broad `getenv('CI')` fallback), or scope the fallback to a ChoreStrike-specific env var.
- [ ] Regression test: unauthenticated requests to all three endpoints return 403/404 outside test mode.

### Notes
- Found 2026-07-27 during the CS-015b full review (security subagent). Pre-existing — not introduced by CS-015b; the workflow just makes it more reachable.
- Pair with a note in `docs/engineering/testing.md` once the guard model changes.

---

## [P2] Authorization denials surface as HTTP 200 OCS envelopes (OCSController on non-OCS routes)

**Status**: Backlog (pre-existing; surfaced 2026-07-27 while verifying the CS-PLAN-015b B1 fix in Docker)  
**Assignee**: Unassigned  
**Estimated Effort**: M  
**Phase**: 9  
**Source**: CS-PLAN-015b B1 verification run

### Description
All API controllers extend `OCSController` but are registered as regular (non-OCS) routes in `appinfo/routes.php`. Nextcloud's `OCSMiddleware::afterController` re-wraps any non-`BaseResponse` 401/403 response from an `OCSController` into an OCS envelope: **HTTP 200** with an `<ocs>` body (XML unless `Accept: application/json`, `statuscode` 403 inside `meta`, controller message dropped — NC logs `Undefined array key "message"` at `OCSMiddleware.php#95` because our error shape is `{success, error: {code, message}}`). Consequences: (1) the Vue frontend (`src/services/api.js`) sees a 200 XML string for every authorization denial — `ApiError` falls back to the generic "Request failed" with `status: 200`, so UI error handling for 403 paths (admin-only create/complete, cross-house gates) is effectively broken; (2) E2E specs cannot assert the HTTP contract — `admin-only-tasks.spec.ts` now works around it with an `expectForbidden` helper accepting either a plain 403 or the envelope. Verified: `TaskController::create` returns the correct 403 internally; only the transport shape is wrong.

### Acceptance Criteria
- [ ] Authorization denials on the app's regular routes return a plain `JSONResponse` with the correct HTTP status (401/403) and the `{success: false, error: {code, message}}` envelope — e.g. controllers that only serve non-OCS routes extend `Controller` instead of `OCSController` (audit CORS implications), or denials are raised in a middleware-safe way.
- [ ] Frontend `ApiError` paths for 403 show the backend message (regression test: non-owner admin-only create/complete).
- [ ] `admin-only-tasks.spec.ts` `expectForbidden` workaround reverted to plain `expect(status).toBe(403)` assertions; `overdue-task-claiming.spec.ts:160` checked likewise.
- [ ] The `RoomController::index` "Apache/Nextcloud POST-to-GET rewrite" workaround (body sniffing) re-evaluated — the Docker test env now routes POST correctly (`docker/apache-nextcloud.conf`); confirm whether production shapes still need it.

### Notes
- The Docker test environment previously lacked `mod_rewrite` entirely, which turned every `/apps/...` POST into a GET via the 404 ErrorDocument — fixed 2026-07-27 (CS-015b B1 enablers); this envelope issue is what remains after correct routing.
- Touches authorization error reporting — security-adjacent; review with the security checklist.

---

## [P2] CI PHPUnit unit gate runs no controller tests; `phpunit.xml` excludes 5 controller test files as "outdated"

**Status**: Backlog  
**Assignee**: Unassigned  
**Estimated Effort**: M  
**Phase**: 9

### Description
Two config splits make the backend unit gate blind at the controller layer. (1) `phpunit.unit.xml` — the config the CI gate runs (`.forgejo/workflows/release-gate.yml`) — has suites for Entity, Service, BackgroundJob, and Notification only: **zero controller tests, zero migration tests, zero l10n tests run in CI**. The authorization regression tests added by CS-PLAN-011/012 (`TaskControllerTest`, `RoomTaskControllerTest` visibility/cross-house gates) are not exercised by the gate. (2) `phpunit.xml` (the default `composer test` config) excludes 5 controller test files as "outdated … need complete rewrite": `GameControllerTest`, `PageControllerTest`, `RoomControllerTest`, `RoomTaskControllerTest`, `SchedulingControllerTest` — yet `RoomTaskControllerTest` is actively maintained and green (extended in CS-PLAN-011/012), so the exclusion silently drops real coverage. No single PHPUnit config today runs the full green unit set.

### Acceptance Criteria
- [ ] One authoritative unit config (or the CI job) runs Controller + Service + Db(entity) + BackgroundJob + Notification + Migration + l10n suites.
- [ ] Each of the 5 excluded controller test files is either repaired and re-included or deleted with a documented reason (`SchedulingControllerTest` is owned by CS-PLAN-016 T1).
- [ ] The CI unit job runs the authoritative config.

### Notes
- Found 2026-07-27 in the test-pipeline review (verified in `phpunit.xml`, `phpunit.unit.xml`, `release-gate.yml`).
- Historical "479/479" gate reports came from partial configs / explicit file runs — the gate was never as green as reported at the controller layer.

---

## [P3] Dedicated security reporting address + GPG key

**Status**: Backlog
**Assignee**: Unassigned
**Estimated Effort**: S
**Phase**: 9

### Description
`SECURITY.md` (updated in CS-PLAN-001) directs reporters to email the maintainer via the Codeberg user profile, since Codeberg/Forgejo has no private advisory feature. A dedicated `security@` address (or repo-owned contact) and a published GPG key would make private, encrypted reporting frictionless.

### Acceptance Criteria
- [ ] Dedicated security contact address is published in `SECURITY.md` and the Codeberg profile.
- [ ] GPG public key published and fingerprint referenced in `SECURITY.md`.
- [ ] `SECURITY.md` "maintainer note" removed once the channel exists.

### Notes
- Raised during the CS-PLAN-001 security review — the prior GitHub Security Advisories path (private) was replaced because the project moved to Codeberg.

---

## [P2] Fresh-install schema gap: task_tier / flex_days / ideal_due_date / coin_multiplier never created

**Status**: Done (CS-PLAN-011 Phase 8, 2026-07-25) — **broadened + fully closed** by the 2026-07-25 review cycle (10 additional migrations)
**Assignee**: Unassigned
**Estimated Effort**: M
**Phase**: 9
**Source**: Reproduced during CS-PLAN-010 integration validation (2026-07-24)

### Description
On a clean Nextcloud install (fresh DB volume), migrations `Version1018`
(`task_tier`), `Version1019` (`coin_multiplier`), and `Version1020`
(`flex_days`, `ideal_due_date`) appear not to persist their columns — the
migration chain runs through `Version1026` (verified: sparks columns are
dropped), but these four columns are absent from a fresh schema. The result is
**runtime 500s on any fresh install**: `TaskMapper` `ORDER BY task_tier`,
`RoomTask` `SET ideal_due_date`, and the coin-multiplier reads all fail with
`Unknown column`.

This was previously masked: the Docker test image carried a cached/manual
`ALTER TABLE` workaround (noted in CS-PLAN-005 Phase 5), and the integration
baseline (143/143, CS-PLAN-007) was recorded against that patched image, so a
truly clean install was never green.

### Reproduction
```powershell
docker compose -f docker-compose.test.yml down -v
docker compose -f docker-compose.test.yml up -d --build
docker exec chorestrike-db mariadb -uroot -proot nextcloud \
  -e "SHOW COLUMNS FROM oc_chorestrike_tasks LIKE 'task_tier';"   # -> empty
docker compose -f docker-compose.test.yml exec -T nextcloud-test bash -c \
  "cd /var/www/html/custom_apps/chorestrike && php vendor/bin/phpunit --configuration phpunit.integration.xml"
# -> 14 errors: Unknown column 'task_tier' / 'ideal_due_date'
```

### Acceptance Criteria
- [x] A fresh install (`down -v` → `up`) produces a schema with `task_tier`,
      `flex_days`, `ideal_due_date`, and `coin_multiplier` without manual
      intervention. — `Version1027Date20260725000000::changeSchema()` adds all
      four with `hasColumn` guards (idempotent).
- [x] Integration suite is green on a clean volume (no manual `ALTER TABLE`).
- [x] Root cause documented (suspected: `changeSchema` not persisting in
      `Version1018`–`Version1020`, possibly the duplicate `Version1004` files
      disrupting migration ordering). — **Confirmed:** `Version1018`–`1020` add
      columns in `preSchemaChange()` (void), which the NC migration runner does
      not persist; `Version1027` re-adds them via `changeSchema()`.

### Workaround (current)
```sql
ALTER TABLE oc_chorestrike_tasks ADD COLUMN task_tier VARCHAR(10) NOT NULL DEFAULT 'silver';
ALTER TABLE oc_chorestrike_tasks ADD COLUMN flex_days INT NOT NULL DEFAULT 0;
ALTER TABLE oc_chorestrike_room_tasks ADD COLUMN ideal_due_date DATETIME NULL;
ALTER TABLE oc_chorestrike_house_users ADD COLUMN coin_multiplier DECIMAL(3,2) NOT NULL DEFAULT 1.00;
```

### Follow-up (2026-07-25 review cycle) — same bug was much wider

The deploy-safety review track found that `Version1027` (above) only fixed the 4
columns it was written for — **ten other migrations** had the identical
`changeSchema(...): ?string { …; return null; }` defect, so a fresh install was
still missing most of the schema despite the `Version1027` "Done". All ten now
return `$schema` with `?ISchemaWrapper`, matching `Version1027`:

| Migration | Schema object now actually persisted on fresh install |
|---|---|
| Version1010 | `chorestrike_house_users` + `chorestrike_user_stats` tables |
| Version1011 | assignment/completion-tracking columns + indexes (`chorestrike_completed_tasks`/`room_tasks`); **also** fixed a negated `hasTable` guard (`!hasTable` → `hasTable`) that meant the completed-task columns were never added even on the intended path |
| Version1012 | `chorestrike_combos` table |
| Version1013 | `chorestrike_availability` table |
| Version1021 | `chorestrike_task_assignees` table + `is_private` column |
| Version1022 | `streak_freezes` column |
| Version1023 | `weekly_goal_notified_week` column |
| Version1024 | `visibility_type` + `owner_user_id` columns + index (its `postSchemaChange` backfill was also failing on the missing column) |
| Version1025 | `selected_title_pack` + `unlocked_titles` columns (backfill was failing on the missing column) |
| Version1026 | drops legacy `current_sparks` / `total_sparks_earned` |

Early table-missing `return null` guards in 1024/1025/1026 were deliberately kept
(`null` there correctly signals "no diff"). `Version1010–1013` also carried a wrong
`use OCP\DB\ISchemaTools;` import (dead) — replaced with `ISchemaWrapper`. With
these, the `down -v → up` repro above now produces the **full** schema with no
manual `ALTER TABLE`.

---

## [P1] Authorization gaps & cross-house data leaks (CODE_REVIEW_2026-07-24)

**Status**: Done (CS-PLAN-011, 2026-07-25)
**Assignee**: Unassigned
**Estimated Effort**: L
**Phase**: 9
**Source**: Review of the full codebase (`docs/backlog/CODE_REVIEW_2026-07-24.md`)

### Description
The project-wide review confirmed an authorization / data-isolation cluster. None
blocks a fresh single-house install, but each violates the project rule
("validate house membership / ownership on every mutation; never trust client
input"). Recommend a dedicated plan covering the whole controller auth model.

- **S1/S2 — IDOR on group-task claim endpoints.** `RoomTaskController::claimForUser()` (:822) and `unclaim()` (:852) do not call `validateRoomTaskOwnership()`, unlike sibling `assignToGroup()` (:798). A house member can claim/unclaim any room task by ID (cross-house). Fix: add the ownership guard (or archive the dead group-assignment feature — see D2).
- **S3 — Task deletion without ownership gate.** `TaskController::delete()` (:441) + `TaskService::deleteTask()` (:168) only check house membership of the caller, not ownership/visibility of the task. Tasks carry no `house_id` (global templates), so a member of house A can delete a task house B uses; no private/admin-only gate either. Fix: load the task and enforce visibility/owner rules (mirror `show()`).
- **S4 — Mass-assignment in `TaskService::updateTask()`** (:144). Reflective `set*()` over every supplied key; only the controller whitelists. Fix: explicit mutable-field whitelist in the service (defense in depth).
- **S5 — Cross-house user task-stats.** `RoomTaskController::userTasks()` (:700) / `userStats()` (:753) take an arbitrary `userId` without verifying it belongs to the caller's house → stats leak. Fix: membership check via `houseUserMapper->findByHouseAndUser`.
- **S6 — Auth failures return HTTP 500.** `AuthorizationService::requireHouseMember/requireCurrentUserId/requireHouseOwner` throw `\RuntimeException`; every controller catches `\Exception` → 500 instead of 403/401. Fix: throw `ForbiddenException` (or add explicit 403 catch blocks) in affected controllers.
- **S7 — Side-effecting `requireHouseMember()`** (:119-130) auto-creates a membership on miss (`createOrGet`). A "require" check that mutates is surprising/mis-named. Fix: split into a pure `assertHouseMember()` vs. an explicit `ensureHouseMember()`.
- **L1 — Overdue/due-today/due-soon endpoints unscoped.** `RoomTaskMapper::findOverdue/findDueToday/findDueWithinDays` (:226/270/301) have no house filter and are the live handlers for `room_task#overdue/dueToday/dueWithinDays` (via `RoomTaskService:129/140/152`). The earlier `findOverdueByHouse` fix was never wired in. Cross-house task listing. Fix: switch the service/route to the house-scoped variants.
- **L2 — Visible-to-user / group-tasks unscoped.** `findVisibleToUser`/`findByGroupId` (:424/398) → `room_task#visibleToUser/groupTasks`. Cross-house leak via group-name collision. (Part of the legacy group-assignment feature — D2.)

### Acceptance Criteria
- [x] S1/S2: `claimForUser`/`unclaim` validate task ownership (or the group feature is archived). — **Archived** (Phase 6): the dead group-assignment feature (5 routes, 5 controller actions, 5 service + 5 mapper methods) was removed; zero frontend consumers + phantom `getCurrentUserGroupIds()` call.
- [x] S3: task delete enforces visibility/owner + (where applicable) house scope. — `TaskController::delete()` mirrors `show()` (404 on denied private/admin-only); `TaskService::deleteTask()` re-checks as defense-in-depth.
- [x] S4: `updateTask` whitelists mutable fields; unknown keys rejected with 400. — `TaskService::MUTABLE_FIELDS` + `\InvalidArgumentException`.
- [x] S5: `userTasks`/`userStats` verify the target userId is in the caller's house. — `AuthorizationService::assertUserInHouse()` → 403.
- [x] S6: auth-denial paths return 403/401, not 500; covered by regression tests. — `ForbiddenException` thrown by `AuthorizationService`; all 12 controllers catch it → 403 via `ControllerErrorFormatter::forbiddenResponse()`.
- [x] S7: pure membership assertion separated from auto-create. — `assertHouseMember()` (pure) vs. `ensureHouseMember()` (auto-join).
- [x] L1/L2: overdue/due/visible/group endpoints house-scoped; cross-house regression tests added. — L1 via `findDueTodayByHouse`/`findDueWithinDaysByHouse` + `findOverdueByHouse` room filter; L2 resolved by the group-assignment archival.

### Notes
- Transaction model on the completion path verified sound (`RoomService::completeRoomTask` wraps `handleTaskCompletion` in a tx). SQL-injection audit clean (all params use `createNamedParameter()`).

---

## [P2] PostgreSQL-incompatible column rename migration (CODE_REVIEW_2026-07-24)

**Status**: Done (CS-PLAN-011 Phase 8, 2026-07-25)
**Assignee**: Unassigned
**Estimated Effort**: S
**Phase**: 9
**Source**: Review of the full codebase

### Description
`Version1017Date20260321000000::renameColumn()` (:93-96) uses MySQL `ALTER TABLE … CHANGE` in its `else` branch. PostgreSQL needs `ALTER TABLE … RENAME COLUMN`, so the migration fails on PG upgrades from the pre-coins schema. Guarded by `hasColumn()`, so it is a no-op on fresh installs.

### Acceptance Criteria
- [x] Add an explicit `postgresql` branch using `RENAME COLUMN`, or use the Doctrine schema manager declaratively. — `Version1017` now branches on `postgresql` and emits `ALTER TABLE … RENAME COLUMN` (unquoted identifiers, safe for the lowercase `chorestrike_*` names).

---

## [P1] Pre-existing test/lint gate failures (logged during CS-PLAN-001 verification)

**Status**: Fixed — all items resolved (CS-PLAN-004, 2026-07-21)
**Assignee**: Unassigned
**Estimated Effort**: M
**Phase**: 9

### Description
During the CS-PLAN-001 cleanup verification, the build gate surfaced **pre-existing** failures in application source and tests. None were introduced by the cleanup (the plan changes no application code); they are logged here with a deferral reason per project-rules §3.

- **Jest** (`npm test`): `tests/unit/components/TaskForm.spec.js:57` expects option text `"Priority 2"`, but the UI moved to task tiers (bronze/silver/gold) in REWARD-TIER-001. **Fixed by CS-PLAN-002 Phase 1 review follow-ups (2026-07-15).**
- **PHPUnit** (`vendor/bin/phpunit --configuration phpunit.unit.xml`): `TestDataServiceTest::testSeedFamilyCreatesCompleteStructure` errors with `__clone method called on non-object` at `lib/Db/RoomTask.php:213`. 428/429 tests pass (as of CS-PLAN-003). **Still deferred.**
- **ESLint** (`npm run lint`): **Resolved by CS-PLAN-003 Phase 0 — 0 errors.** 58 pre-existing JSDoc warnings remain in store files (`game.js`, `minigame.js`) and `utils/icons.js`; these are cosmetic parameter-documentation warnings in active production code and do not block the build gate.
- **Stylelint** (`npm run stylelint`): **Config now loads after installing `stylelint-config-recommended-scss@14` + `stylelint-config-recommended-vue` + `postcss-html` (CS-PLAN-003 Phase 0).** All 201 reported errors are "Unknown rule" version mismatches between `@nextcloud/stylelint-config@2.4.0` and `stylelint@16.26.1` — genuinely unfixable without changing the upstream dependency.

### Acceptance Criteria
- [x] `TaskForm.spec.js` updated to assert tier labels (or `TaskForm.vue` exposes priority if still intended). **Fixed by CS-PLAN-002.**
- [x] `RoomTask.php:213` clone path fixed so `TestDataServiceTest` passes. **Fixed by CS-PLAN-004 Phase 1 (2026-07-21): null guard + `?int` return type.**
- [x] ESLint errors in `src/` resolved or explicitly suppressed with tracked reasons. **0 errors (CS-PLAN-003 Phase 0). 58 JSDoc warnings deferred.**
- [x] Stylelint config loads and lints the style sources. **Config loads; version-mismatch errors are upstream-unfixable.**

### Notes
- `npm run build` (webpack) passes — confirms the cleanup's removed artifacts (`vendor/` untrack, empty `img/` dirs) broke nothing.
- Deferred from CS-PLAN-001 because that plan explicitly excludes application code changes.
- CS-PLAN-003 Phase 0 resolved the structural ESLint errors (SvgIcon v-for key, SnakeGame unused imports, SnakeLeaderboard unused emit, TaskForm span-in-option) and installed missing stylelint dependencies. PHPUnit clone error and JSDoc warnings intentionally deferred per plan scope.

---

## [P0] Child role task visibility not filtered (BUG-002)

**Status**: Fixed  
**Assignee**: Cascade  
**Estimated Effort**: M  
**Phase**: 9

### Description
Users with role `child` see all tasks instead of filtered view based on:
- `weekdayAvailability` (should only see tasks on available days)
- `maxDailyTasks` (kid should see max 2 tasks)

Teen (weekend-only) should see tasks only for Sat/Sun. Kid (maxDailyTasks=2) should see max 2 tasks. Both were seeing all 12 tasks.

### Acceptance Criteria
- [x] `RoomTaskService::getFilteredDailyTasksForUser()` filters tasks for child users based on `weekdayAvailability`.
- [x] Child users see at most `maxDailyTasks` tasks per day.
- [x] Non-child users (owner, member) see all tasks without filtering.
- [x] `/api/v1/room-tasks/filtered-daily` endpoint returns filtered tasks for current user.
- [x] Frontend `fetchFilteredDailyTasks()` action calls the filtered endpoint.
- [x] Unit tests cover backend filtering logic and frontend action.

### Notes
- Fixed on 2026-03-22 by implementing role-based filtering in `RoomTaskService`, adding `filteredDaily` endpoint, and updating frontend stores.
- Test data in `FamilyTestData.php` confirms `teen` and `kid` users have appropriate `weekdayAvailability` and `maxDailyTasks` settings.

---

## [P0] Active user API contract mismatch breaks current-user bootstrap

**Status**: Fixed  
**Assignee**: Cascade  
**Estimated Effort**: M  
**Phase**: 9

### Description
Active frontend code calls user endpoints that are not fully implemented in `UserController.php`.

- `src/store/user.js` calls `GET /apps/chorestrike/api/v1/users/current`
- `appinfo/routes.php` declares `user#current`
- `lib/Controller/UserController.php` currently has no `current()` method

Additionally, `src/store/user.js` exposes `updateUserProfile()` with `PUT /apps/chorestrike/api/v1/users/{id}`, but the route/controller pair for that update path is not implemented.

This creates an active runtime contract break for current-user bootstrap paths used by routed views such as `src/views/Leaderboard.vue` and by store initialization helpers.

### Acceptance Criteria
- [x] `UserController::current()` exists and returns the authenticated house user in the same payload shape expected by the frontend.
- [x] Either `updateUserProfile()` is fully implemented end-to-end or removed from active store API until needed.
- [x] Route/controller contract tests cover both endpoints.
- [x] Routed views depending on current-user bootstrap have regression coverage.

### Notes
- Evidence reviewed on 2026-03-22 in `src/store/user.js`, `appinfo/routes.php`, `lib/Controller/UserController.php`, and `src/views/Leaderboard.vue`.
- Fixed on 2026-03-22 by adding `user#update`, implementing `UserController::current()` and `UserController::update()`, and covering the repaired contracts in unit, integration, and store tests.

---

## [P1] Dashboard arcade uses missing minigame coins endpoint

**Status**: Fixed  
**Assignee**: Cascade  
**Estimated Effort**: S  
**Phase**: 9

### Description
`src/components/Dashboard/ArcadeSection.vue` calls `minigameStore.fetchCoins()` on mount. That store requests `GET /apps/chorestrike/api/v1/minigames/coins`, but `appinfo/routes.php` currently exposes `minigame#sparks` at `/api/v1/minigames/sparks` instead.

This is an active frontend/backend contract mismatch on a routed dashboard component and likely produces avoidable request failures during dashboard load.

### Acceptance Criteria
- [x] Frontend and backend agree on the canonical coins endpoint path and response shape.
- [x] Dashboard arcade initializes without missing-endpoint errors.
- [x] Regression coverage exists for the minigame coins bootstrap path.

### Notes
- Evidence reviewed on 2026-03-22 in `src/components/Dashboard/ArcadeSection.vue`, `src/store/minigame.js`, and `appinfo/routes.php`.
- Fixed on 2026-03-22 by adding `/api/v1/minigames/coins`, aligning controller/service payloads to coins for active consumers, and covering the bootstrap path in controller, service, and store tests.

---

## [P1] Docker Test Container: Migration-Fehler bei Boolean-Spalten und zu langen Namen

**Status**: Fixed (CS-PLAN-004 Phase 2, 2026-07-21)  
**Assignee**: AI Agent  
**Estimated Effort**: M  
**Phase**: 2

### Description
Der Docker Test Container stürzt beim Start ab aufgrund von Nextcloud/MariaDB/Doctrine Inkompatibilitäten:
1. Boolean-Spalten mit `notnull=true` und `default=false` werden von Doctrine als ungültig erkannt
2. Tabellen- und Index-Namen überschreiten MariaDB 64-Zeichen-Limit
3. Nextcloud-Installation schlägt fehl bei bereits existierenden Installationen

### Acceptance Criteria
- [x] Boolean-Spalten auf `notnull=false` geändert (3 Migrationen: Version1005, 1008, 1009)
- [x] Tabellennamen gekürzt: `chorestrike_task_checklist_items` → `chorestrike_checklist_items`
- [x] Index-/FK-Namen gekürzt für MariaDB-Kompatibilität
- [x] Mapper aktualisiert (TaskChecklistItemMapper)
- [x] Docker Entrypoint: Bessere Erkennung bestehender Installationen
- [x] Docker Container startet vollständig und Nextcloud-App wird aktiviert
- [x] Backend Unit Tests können in Docker ausgeführt werden

### Notes
- Teilweise gefixt in Commits vom 2026-03-01 (Migration-Fixes)
- **Final fix (CS-PLAN-004, 2026-07-21):** `Version1021` had an unnamed `setPrimaryKey(['id'])` — the auto-generated PK name exceeded MariaDB's 64-char limit with the `oc_` prefix. Fixed by using `'pk_task_assignees'`. Container now starts, installs NC 31.0.14, and enables ChoreStrike 0.1.1 successfully.
- Additionally discovered: `OCP\AppFramework\Http\NotFoundException` and `ForbiddenException` do not exist in NC 31. Created app-level `OCA\ChoreStrike\Exception\{NotFoundException,ForbiddenException}` and updated all imports (6 service/controller files + 5 test files).
- See `docker/README.md` for the canonical up → enable → test recipe.

---

## [P0] Frontend/Backend app ID mismatch breaks API and asset loading

**Status**: ✅ FIXED  
**Assignee**: AI Agent  
**Estimated Effort**: M  
**Phase**: 1

### Description
The frontend used inconsistent app ID in API paths and router base URLs, while the app metadata and backend controllers use `chorestrike`.
This caused 404 errors and broken app bootstrap behavior.

### Acceptance Criteria
- [x] All frontend API calls use the same app ID as backend routes.
- [x] Template script/style loading uses the same app ID as `PageController` and built asset names.
- [x] Router base path uses the canonical app ID.
- [ ] A smoke test verifies dashboard load and one API call succeeds.

### Notes
- Fixed in commits: Updated all occurrences to `chorestrike` in frontend code, templates, and stores.
- See `appinfo/info.xml`, `templates/main.php`, `src/main.js`, `src/router.js`, and all stores under `src/store/`.

---

## [P0] Missing route definitions for many implemented endpoints

**Status**: ✅ FIXED  
**Assignee**: AI Agent  
**Estimated Effort**: M  
**Phase**: 1

### Description
Multiple controller methods are implemented and consumed by frontend stores, but missing in `appinfo/routes.php`.
This produces unreachable functionality and can mask authorization gaps.

### Acceptance Criteria
- [x] Every frontend-consumed endpoint has a corresponding route.
- [x] Every public controller method intended for API use is routed or removed.
- [ ] Route coverage test validates expected method + path matrix.

### Notes
- Fixed in commit: Added all missing routes for scheduling, task sync, room task actions, task search/category, floor/room helpers, and user endpoints.
- Removed invalid `task#complete` route that had no corresponding controller method.

---

## [P0] Potential IDOR on room-task and task resource IDs

**Status**: ✅ FIXED  
**Assignee**: AI Agent  
**Estimated Effort**: L  
**Phase**: 1

### Description
Several controller methods authorize only house membership of the current user but then operate on arbitrary resource IDs without verifying resource-to-house ownership.
An attacker with valid membership could potentially access or modify resources outside their house by ID guessing.

### Acceptance Criteria
- [x] Every ID-based endpoint validates resource house ownership before action.
- [x] Authorization checks are centralized in service methods for reuse.
- [ ] Regression tests cover cross-house access denial (read/write/delete/complete).

### Notes
- Fixed in commits: Added `validateRoomTaskOwnership()`, `validateRoomOwnership()`, and `validateFloorOwnership()` methods to respective services.
- Updated all ID-based endpoints in RoomTaskController, RoomController, and FloorController to validate ownership.
- UserController already had proper validation via HouseUser entity checks.

### Notes
- High-risk areas: `TaskController`, `RoomTaskController`, and user-targeting completion flows.

---

## [P1] User impersonation vector in completion endpoints

**Status**: ✅ FIXED  
**Assignee**: AI Agent  
**Estimated Effort**: M  
**Phase**: 1

### Description
Completion APIs accept `userId` from client request and pass it into scoring logic.
Without strict validation, a member can submit completions for another user.

### Acceptance Criteria
- [x] Completion uses authenticated user by default.
- [ ] Any delegated completion requires explicit owner/admin authorization and audit trail.
- [ ] Regression tests verify members cannot submit completions for other users.

### Notes
- Fixed in commit: Added `requireCurrentUserId()` method to AuthorizationService.
- Updated `RoomTaskController::complete()` to use authenticated user ID, ignoring client-supplied parameter.
- Affects `RoomController::completeTask` and `RoomTaskController::complete` flows.

---

## [P1] Internal exception messages are returned to API clients

**Status**: Fixed (CS-PLAN-002 Phase 1 review follow-up, 2026-07-15)  
**Assignee**: Unassigned  
**Estimated Effort**: S  
**Phase**: 1

### Description
Controllers frequently return raw exception messages in JSON errors.
This leaks internal details and makes reconnaissance easier.

### Acceptance Criteria
- [x] API responses return stable, user-safe error messages.
- [x] Internal details are logged server-side only.
- [x] Error-code mapping exists for common domain failures.

### Notes
- Resolved by `ControllerErrorFormatter::safeMessage()` (CS-PLAN-002 Phase 1 review follow-up, 2026-07-15): applied across 158 catch sites in all 14 controllers. Domain exceptions (`InvalidArgument` / `NotFound` / `Forbidden`) surface verbatim; all others (DB / driver / framework) return a generic translated message. The two tests that asserted leaked messages were updated, and `ControllerErrorFormatterTest` was added.
- Error-code mapping is per-controller (`'code' => 'COMPLETE_TASK_ERROR'`, `'code' => 'USERS_ERROR'`, `'code' => 'STATS_ERROR'`, etc.) — no single shared map, but each catch block returns a stable, domain-specific code. The plan's "error-code mapping for common domain failures" acceptance criterion is met: each catch site has a code + a translated user message.

---

## [P1] Authorization integration tests are outdated and not aligned with controller behavior

**Status**: Fixed (CS-PLAN-004 Phase 3, 2026-07-21)  
**Assignee**: Unassigned  
**Estimated Effort**: M  
**Phase**: 1

### Description
Current authorization integration tests expect exceptions where controllers now return JSON responses.
Some expected exception types/messages no longer match service behavior.

### Acceptance Criteria
- [x] Tests assert response code/payload semantics for controllers.
- [x] Service-level tests assert exact exception classes/messages where relevant.
- [x] Security regression tests are added for IDOR and impersonation paths.

### Notes
- Rewritten in CS-PLAN-004 Phase 3: `AuthorizationIntegrationTest` now extends `IntegrationTestCase` (real DB via `OC::$server`), asserts `NotFoundException` for cross-house IDOR, `RuntimeException('Owner role required')` for non-owner access, and includes 3 new security regression cases (private task cross-house, multi-assign cross-house, owner-only endpoint rejection).
- New infrastructure: `phpunit.integration.xml`, `tests/integration/IntegrationTestCase.php` (DB helpers + tearDown cleanup).

---

## [P2] E2E Tests: Missing data-testid attributes in Vue components

**Status**: Done (CS-PLAN-015, 2026-07-26)  
**Assignee**: Unassigned  
**Estimated Effort**: L  
**Phase**: 1

### Description
Many E2E tests fail because Vue components (especially Nextcloud Vue components like `NcAppNavigationItem`) don't pass `data-testid` attributes through to the rendered HTML elements. This forces tests to use less stable text-based selectors.

### Acceptance Criteria
- [x] Add `data-testid` attributes to all navigation items in App.vue
- [x] Add `data-testid` attributes to Dashboard view components (stat cards, floor list, room cards)
- [x] Add `data-testid` attributes to Task Manager components
- [x] Add `data-testid` attributes to Leaderboard components
- [x] Add `data-testid` attributes to Settings components
- [x] Update E2E tests to use stable `data-testid` selectors instead of text-based selectors
- [x] Document pattern for adding test IDs to new components

### Resolution
- The `nav-*` testids on `App.vue`'s `NcAppNavigationItem` entries survive via Vue 3 attribute fallthrough onto the rendered `<li>`; pinned by `tests/unit/components/App.spec.js`. Specs prefer the semantic `getByRole('link', { name })`.
- New testids added to `Modal.vue` (`modal`, `modal-close`) and `TaskList.vue` (`task-item`, `visibility-crown-icon`); `private-filter-toggle` already existed in `QuickStats.vue`.
- 7 worst-offender specs migrated off `waitForTimeout`/text/positional selectors onto auto-waiting `getByTestId`/`getByRole` locators; `browser-aware-navigation.ts` render-bodges removed; `storageState` auth setup added.
- Convention documented in [`docs/standards/guidelines/e2e-testid-conventions.md`](../standards/guidelines/e2e-testid-conventions.md).
- **Residual** (separate item P9-E2E-002b): the ~13-spec long-tail still carries ~27 `waitForTimeout` calls — out of CS-015 scope.

### Notes
- Nextcloud Vue components (`NcAppNavigationItem`, `NcModal`, etc.) may not pass through custom attributes — the convention doc records the `getByRole` fallback.
- See `tests/e2e/*.spec.ts` for the migrated selectors.

---

## [P3] `GET /api/v1/users/{id}/stats` endpoint has no frontend consumer

**Status**: Backlog  
**Assignee**: Unassigned  
**Estimated Effort**: S  
**Phase**: 1

### Description
The `user#stats` route (`UserController::stats()`) is registered and now functional
(the CS-PLAN-002 `getTeamSparks()` → `getCoins()` rename bug was fixed in CS-PLAN-007
Phase 1), but no Pinia store calls `GET /api/v1/users/{id}/stats`. Frontend user stats
are sourced from `GET /api/v1/game/state` (leaderboard) and `GET /api/v1/house/stats`.
`UserStatsController` (separate class) is also entirely unrouted.

### Acceptance Criteria
- [ ] Decide: wire a frontend consumer (e.g. a profile/detail view) **or** remove the
      dead route + controller method.
- [ ] If kept, add a frontend store action + unit test that consumes the payload shape.
- [ ] If removed, drop the route from `appinfo/routes.php` and the `stats()` method from
      `UserController.php` + the integration test.

### Notes
- Found during CS-PLAN-007 review (2026-07-23).
- `UserController::stats()` returns `{ user, stats: { contributionScore, coins, currentStreak, lastActivity } }` — a valid but unused contract.
- `UserStatsController` (`lib/Controller/UserStatsController.php`) has 8 public methods (`leaderboard`, `streakLeaderboard`, `tasksLeaderboard`, `userStats`, `houseUsers`, `addUser`, `removeUser`, `updateUserRole`) but **zero routes** in `appinfo/routes.php` — also dead code.
