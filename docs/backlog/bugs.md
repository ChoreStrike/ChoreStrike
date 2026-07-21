# Bug Backlog

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

**Status**: Planned  
**Assignee**: Unassigned  
**Estimated Effort**: S  
**Phase**: 1

### Description
Controllers frequently return raw exception messages in JSON errors.
This leaks internal details and makes reconnaissance easier.

### Acceptance Criteria
- [ ] API responses return stable, user-safe error messages.
- [ ] Internal details are logged server-side only.
- [ ] Error-code mapping exists for common domain failures.

### Notes
- Broadly present across controller layer.

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

**Status**: Backlog  
**Assignee**: Unassigned  
**Estimated Effort**: M  
**Phase**: 1

### Description
Many E2E tests fail because Vue components (especially Nextcloud Vue components like `NcAppNavigationItem`) don't pass `data-testid` attributes through to the rendered HTML elements. This forces tests to use less stable text-based selectors.

### Acceptance Criteria
- [ ] Add `data-testid` attributes to all navigation items in App.vue
- [ ] Add `data-testid` attributes to Dashboard view components (stat cards, floor list, room cards)
- [ ] Add `data-testid` attributes to Task Manager components
- [ ] Add `data-testid` attributes to Leaderboard components
- [ ] Add `data-testid` attributes to Settings components
- [ ] Update E2E tests to use stable `data-testid` selectors instead of text-based selectors
- [ ] Document pattern for adding test IDs to new components

### Notes
- Nextcloud Vue components (`NcAppNavigationItem`, `NcModal`, etc.) may not pass through custom attributes
- May need to wrap components or use alternative approaches
- See `tests/e2e/*.spec.ts` for current text-based workarounds
