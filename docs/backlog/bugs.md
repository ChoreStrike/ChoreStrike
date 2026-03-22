# Bug Backlog

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

**Status**: Partially Fixed  
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
- [ ] Docker Container startet vollständig und Nextcloud-App wird aktiviert
- [ ] Backend Unit Tests können in Docker ausgeführt werden

### Notes
- Teilweise gefixt in Commits vom 2026-03-01 (Migration-Fixes)
- Container stürzt weiterhin ab - weitere unbekannte Migration-Issues möglich
- Betrifft: `lib/Migration/Version1005Date20260228160000.php`, `Version1008Date20260228190000.php`, `Version1009Date20260228200000.php`
- Siehe Docker-Logs für weitere Details

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

**Status**: Planned  
**Assignee**: Unassigned  
**Estimated Effort**: M  
**Phase**: 1

### Description
Current authorization integration tests expect exceptions where controllers now return JSON responses.
Some expected exception types/messages no longer match service behavior.

### Acceptance Criteria
- [ ] Tests assert response code/payload semantics for controllers.
- [ ] Service-level tests assert exact exception classes/messages where relevant.
- [ ] Security regression tests are added for IDOR and impersonation paths.

### Notes
- See `tests/integration/Controller/AuthorizationIntegrationTest.php`.

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
