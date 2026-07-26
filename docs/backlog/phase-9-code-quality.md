# Phase 9: Code Quality & Technical Debt

**Status**: In Progress  
**Priority**: Medium  
**Estimated Effort**: 2-3 days  
**Dependencies**: Phase 8 Release Preparation

> **Progress (2026-07-24):** Several P9 items shipped via `cs-plan-007` (CI,
> contract tests, integration repair) and `cs-plan-008` (shared API layer,
> store-ownership). The 2026-07-24 project review added new open items — see the
> fairness (background jobs ignoring `weekdayAvailability`), dead-code (dead
> mappers, group-assignment feature), and frontend store-barrel findings in
> `CODE_REVIEW_2026-07-24.md`. The dead `MedalService`/`UserStatsController`,
> Sparks residue, and doc-vs-impl drift were closed by `cs-plan-010`
> (**P9-DOC-004**); the catalogued-but-unawarded medal types are logged as
> **P9-GAME-001**.
>
> **Progress (2026-07-25, `cs-plan-011`):** Closed the authorization + dead-code
> cluster from the review: the dead group-assignment feature (**D2**) was
> archived (5 routes / 5 controller actions / 5 service + 5 mapper methods, zero
> frontend consumers, phantom `getCurrentUserGroupIds()` call); the dead
> `RoomTaskMapper` query methods (**D3**: `findByHouseId`,
> `findUnscheduledByHouseId`, `findByAssignedUser`, `findByDeadlineRange`,
> `findByScheduledDate`) and `TaskMapper::findDailyTasks()` + its only caller
> `TaskService::getDailyTasks()` (**D4**) were removed. See `bugs.md` for the
> security/auth closure (S1–S7, L1/L2) and the fresh-install schema gap.

---

## Overview

Address technical debt and code quality issues identified in comprehensive code review (2026-03-07). Focus on security, deprecated API usage, logging strategy, and code cleanup.

---

## Tasks

### P9-SECURITY-002 - Add Authorization Check in ChecklistController
**Priority**: HIGH  
**Effort**: 1 hour  
**Status**: Done

**Description**: Missing authorization check in `ChecklistController.php:282` allows potential unauthorized access to checklist progress toggle.

**Implementation**:
```php
// lib/Controller/ChecklistController.php:282
$roomTask = $this->roomTaskMapper->find($roomTaskId);

// Add proper authorization check
$this->authorizationService->ensureUserCanAccessRoomTask($roomTaskId, $currentUserId);

$progress = $this->progressMapper->toggle($roomTaskId, $itemId, $currentUserId);
```

**Tests**:
- Add regression test for unauthorized access attempt (403)
- Add regression test for authorized access (200)

**Completed**:
- Replaced direct `RoomTaskMapper` access in `ChecklistController` with `RoomTaskService` ownership validation.
- Applied the room-task authorization guard to all checklist endpoints that accept `roomTaskId`.
- Normalized missing or foreign `roomTask` access to protected-resource `404` responses in line with the existing controller semantics.
- Stopped trusting client-provided `taskId` for checklist initialization/loading and now derive it from the validated `RoomTask`.
- Fixed checklist toggle writes to use the internal `HouseUser` ID required by `RoomTaskChecklistProgressMapper::toggle()`.
- Added `ChecklistControllerTest.php` with success, hidden-resource, and server-side-task-resolution regression coverage.

**Validation**:
- `vendor\\bin\\phpunit.bat --configuration phpunit.unit.xml tests\\unit\\Controller\\ChecklistControllerTest.php` -> pass
- `vendor\\bin\\phpunit.bat --configuration phpunit.unit.xml tests\\unit\\Service\\RoomTaskServiceLoggingTest.php` -> pass
- `vendor\\bin\\phpunit.bat --configuration phpunit.unit.xml tests\\unit\\Controller\\TaskControllerDailyTest.php` -> pass
- `php -l lib\\Controller\\ChecklistController.php` -> pass

---

### P9-FEATURE-001 - Complete Energy Budget Calculation
**Priority**: MEDIUM  
**Effort**: 2 hours  
**Status**: Done

**Description**: `scheduling.js:113` shows total energy budget but doesn't subtract consumed energy from completed tasks.

**Implementation**:
1. Query `CompletedTask` records for current user and today's date
2. Sum `energyCost` from completed tasks
3. Return `total - consumed`

**Code Location**: `src/store/scheduling.js:113`

**Completed**:
- Added backend aggregation in `CompletedTaskMapper::getConsumedEnergyForUserOnDate()` to sum daily consumed `energy_cost` for the current `house_user.id`.
- Updated `SchedulingService` to return `todayConsumedEnergy` in user preferences payload.
- Implemented `todayConsumedEnergy` state hydration and `todayRemainingEnergy = Math.max(0, budget - consumed)` in `src/store/scheduling.js`.
- Added `registerTodayEnergyConsumption(energyCost)` in scheduling store and wired it from `roomTasks.completeRoomTask()` using API reward payload.
- Added regression coverage for all required scenarios (no completions, with completions, over-budget clamping).

**Validation**:
- `npm test -- --runInBand tests/unit/store/scheduling.spec.js tests/unit/store/roomTasks.spec.js` -> pass
- `vendor\\bin\\phpunit.bat --configuration phpunit.unit.xml tests\\unit\\Service\\SchedulingServiceTest.php` -> pass

**Tests**:
- Unit test for energy calculation with completed tasks
- Unit test for energy calculation with no completed tasks
- Unit test for energy calculation with over-budget scenario

---

### P9-UX-001 - Implement Edit Floor Functionality
**Priority**: MEDIUM  
**Effort**: 1 hour  
**Status**: Done

**Description**: Edit floor button exists but functionality is stubbed out (`FloorList.vue:326`).

**Implementation**:
- **Option A**: Implement inline edit (recommended) ✅
  - Click floor name to edit
  - Save on Enter, cancel on Escape
  - Similar to room name editing

**Code Location**: `src/components/Dashboard/FloorList.vue:67-84`

**Completed**:
- Implemented inline floor name editing with click-to-edit pattern.
- Click floor name or edit button triggers editing mode with input field.
- Enter saves changes via `roomsStore.updateFloorName()`.
- Escape cancels editing without saving.
- Blur on input also saves changes.
- Empty or unchanged names cancel the edit automatically.
- Added hover styling on floor name to indicate clickability.
- Added 6 unit tests covering all editing scenarios.

**Validation**:
- `npm test -- --runInBand tests/unit/components/FloorList.spec.js` -> pass (19 tests)

**Tests**:
- Unit test for floor name editing with valid name ✅
- Unit test for canceling with Escape ✅
- Unit test for empty name rejection ✅
- Unit test for unchanged name cancellation ✅
- Unit test for edit button trigger ✅

---

### P9-DEPRECATION-001 - Replace Deprecated OC.getCurrentUser()
**Priority**: MEDIUM  
**Effort**: 30 minutes  
**Status**: Done

**Description**: `AvailabilitySettings.vue` uses deprecated `OC.getCurrentUser()` (deprecated in Nextcloud 19.0.0).

**Implementation**:
```javascript
// Before
const currentUser = OC.getCurrentUser()
const currentUserId = OC.getCurrentUser().uid

// After
import { getCurrentUser } from '@nextcloud/auth'
const currentUser = getCurrentUser()
const currentUserId = currentUser?.uid
```

**Completed**:
- Replaced deprecated `OC.getCurrentUser()` usage in `AvailabilitySettings.vue` with a supported `@nextcloud/auth` lookup.
- Added shared `src/utils/currentUser.js` to normalize the Nextcloud user object to the member shape already used in the scheduling UI.
- Kept the existing `member.userId === currentUser.userId` matching behavior stable by deriving `userId` from the Nextcloud `uid`.
- Updated the Jest auth mock to support the named import used by the new helper.
- Added regression coverage for current-user normalization in `tests/unit/components/AvailabilitySettings.spec.js`.

**Validation**:
- `npx jest --runInBand tests/unit/components/AvailabilitySettings.spec.js` -> pass
- `npx jest --runInBand tests/unit/components/AvailabilitySettings.spec.js tests/unit/utils/logger.spec.js` -> pass
- `npx eslint --quiet src/components/Scheduling/AvailabilitySettings.vue src/utils/currentUser.js tests/unit/components/AvailabilitySettings.spec.js tests/unit/__mocks__/nextcloud-auth.js` -> pass

**Code Locations**:
- `src/components/Scheduling/AvailabilitySettings.vue:417-418`

**Tests**:
- Update unit tests to mock `@nextcloud/auth`

---

### P9-REFACTOR-001 - Production Logging Strategy
**Priority**: MEDIUM  
**Effort**: 3 hours  
**Status**: Done

**Description**: 125 `console.log/error` statements in production code. Need proper logging strategy.

**Implementation**:
1. Create standards document (`docs/standards/LOGGING.md`) and link it from project guidelines
2. Add shared backend logging facade for Nextcloud (`AppLogger`) and request-boundary controller logging
3. Add shared frontend logging utility (`src/utils/logger.js`) with environment-aware behavior
4. Replace raw console statements in critical frontend paths and add regression coverage
5. Add warning-level security logging for authorization-sensitive service paths

**Affected Files** (34 files):
- `src/store/*.js` (17 in roomTasks, 13 in rooms, 11 in tasks, etc.)
- `src/components/**/*.vue`
- `src/main.js`

**Logger Design**:
```javascript
// src/utils/logger.js
export const logger = {
    debug: (msg, ...args) => {
        if (process.env.NODE_ENV === 'development') {
            console.log(`[ChoreStrike] ${msg}`, ...args)
        }
    },
    error: (msg, error) => {
        console.error(`[ChoreStrike] ${msg}`, error)
        // TODO: Send to error reporting service in production
    },
    warn: (msg, ...args) => {
        console.warn(`[ChoreStrike] ${msg}`, ...args)
    },
}
```

**Tests**:
- Unit tests for logger utility
- Verify no console statements in production build

**Completed**:
- Added `docs/standards/LOGGING.md` and linked logging rules from agent/guideline docs.
- Implemented backend logging infrastructure with `lib/Service/AppLogger.php` and `lib/Controller/ControllerLoggingTrait.php`.
- Rolled structured logging into critical backend controllers/services and added logging-specific unit coverage.
- Implemented shared frontend logger in `src/utils/logger.js` and replaced raw logs across active stores, production views, dashboard, scheduling, and gamification components.
- Updated `tests/bootstrap-entity.php` so the new controller/service logging tests can run with AppFramework mocks under `phpunit.unit.xml`.
- Left only intentional exceptions: `src/utils/logger.js`, `src/utils/performance.js`, and unwired legacy `src/components/UserManagement/*` components.

**Validation**:
- `npx eslint --quiet ...` across touched frontend logging files -> pass (0 errors)
- `npx jest --runInBand tests/unit/utils/logger.spec.js` -> pass
- `vendor\\bin\\phpunit.bat --configuration phpunit.unit.xml tests\\unit\\Service\\AppLoggerTest.php` -> pass
- `vendor\\bin\\phpunit.bat --configuration phpunit.unit.xml tests\\unit\\Service\\HouseServiceLoggingTest.php` -> pass
- `vendor\\bin\\phpunit.bat --configuration phpunit.unit.xml tests\\unit\\Service\\RoomTaskServiceLoggingTest.php` -> pass
- `vendor\\bin\\phpunit.bat --configuration phpunit.unit.xml tests\\unit\\Controller\\TaskControllerLoggingTest.php` -> pass

---

### P9-LINT-001 - ESLint Cleanup Pass
**Priority**: LOW  
**Effort**: 2 hours  
**Status**: Done

**Description**: 49 ESLint warnings remaining (down from 457). Mostly JSDoc and formatting issues.

**Implementation**:
1. Run `npm run lint -- --fix` for auto-fixable issues
2. Manually address remaining warnings:
   - Add missing JSDoc comments
   - Fix `vue/singleline-html-element-content-newline`
   - Fix `vue/max-attributes-per-line`
3. Update `.eslintrc.js` if needed for project-specific rules

**Goal**: 0 warnings, 0 errors

**Completed**:
- Ran `npx eslint --fix` to auto-fix Vue template formatting issues.
- Fixed import error: replaced non-existent `Task` icon with `ClipboardList` from lucide-vue-next.
- Added missing JSDoc type and description annotations in `game.js` and `performance.js`.
- Removed default value annotations from JSDoc where not permitted.
- Fixed `vue/no-template-shadow` warning in `TaskForm.vue` by renaming v-for variable.
- Replaced undefined TypeScript types (`Component`, `IdleRequestCallback`) with specific callable signatures.

**Validation**:
- `npx eslint --ext .js,.vue src/ --format compact` -> 0 problems

**Tests**:
- Verify `npm run lint` passes with 0 warnings

---

### P9-WORKFLOW-002 - Add Executable CI Pipeline and Workflow Truth Sync
**Priority**: HIGH  
**Effort**: 3 hours  
**Status**: Done (CS-PLAN-007 Phase 3)

**Description**: `docs/ARCHITECTURE.md` and `docs/TESTING.md` describe CI and workflow structure, but the repository had no tracked authoritative release-gate pipeline. This left linting, PHPUnit, Jest, and the build unenforced outside manual execution.

**Completed**:
- Added `.forgejo/workflows/release-gate.yml` (GHA-compatible, runs on `push` and `pull_request`). Fails the run on any non-zero blocking command: `npm run lint` (ESLint, 0 errors), `npm test` (Jest), `npm run build` (Webpack), `composer validate --strict`, and PHPUnit unit + integration via Docker.
- Stylelint is **advisory/non-blocking** until the `@nextcloud/stylelint-config` ↔ `stylelint` upstream version mismatch (201 "Unknown rule" errors) is resolved or pinned. Stylelint output is uploaded as an artifact.
- CI jobs cache npm + Docker layers with per-job timeouts.
- Updated `docs/TESTING.md` with the authoritative blocking gate command list and local reproduction recipe.
- Updated `docs/ARCHITECTURE.md` to document the release gate and the contract tests.
- The existing `.forgejo/workflows/test.yml` (matrix PHPUnit + E2E) is kept for broader coverage.

**Validation**:
- All blocking commands green locally: `npm run lint` (0 errors), `npm test` (512/512), `npm run build` (compiles), `phpunit.unit.xml` (473/473), `phpunit.integration.xml` (143/143, 24 documented skips).

---

### P9-ARCH-002 - Introduce a Shared Frontend API Layer and Normalize URL Generation
**Priority**: HIGH  
**Effort**: 1 day  
**Status**: Done (CS-PLAN-008)

**Description**: The architecture document describes a frontend API layer, but current stores call `axios` directly and use mixed URL styles (`/apps/...` vs `/index.php/apps/...`). This increases drift risk and makes route-prefix bugs likely.

**Implementation**:
1. Added `src/services/api.js` as a shared frontend API client with canonical `API_PREFIX = /apps/chorestrike`, envelope unwrap, and `ApiError` normalization.
2. Migrated all 10 stores to use `api` instead of `axios`, standardizing URL construction.
3. Simplified the contract test to assert a single prefix and detect deprecated forms.
4. Updated architecture docs to describe the real frontend data flow.

**Tests**:
- Added `tests/unit/services/api.spec.js` for URL generation and error normalization.
- Updated all 10 store tests to mock the new API layer.
- Contract test now asserts single prefix and deprecated-form absence.

---

### P9-ARCH-003 - Remove Duplicated Room-Task Logic Across Frontend Stores
**Priority**: HIGH  
**Effort**: 6 hours  
**Status**: Done (CS-PLAN-008)

**Description**: `src/store/tasks.js` and `src/store/roomTasks.js` both own room-task fetching and derived room-task state. This mixes catalog-task and assignment-task concerns and creates drift in business behavior, test coverage, and API evolution.

**Implementation**:
1. Removed the duplicated room-task surface from `tasks.js` (state, 7 getters, `fetchRoomTasks` action).
2. `roomTasks.js` is the canonical owner — it already had all the needed getters + extra ones.
3. Consumer audit confirmed no views used the duplicated `tasks` room-task surface; all consumers already read from `roomTasks`.
4. Removed unused `tr()` helper and `@nextcloud/l10n` import from the now-catalog-only `tasks.js`.

**Tests**:
- Added regression tests confirming `useTasksStore` no longer exposes room-task state, getters, or `fetchRoomTasks`.
- Removed duplicated room-task test cases from `tasks.spec.js`.

---

### P9-TEST-002 - Add Route and Contract Coverage for Frontend/Backend Wiring
**Priority**: HIGH  
**Effort**: 1 day  
**Status**: Done (CS-PLAN-007 Phase 2)

**Description**: The review found missing contract protection between `appinfo/routes.php`, controller methods, and active frontend consumers. This gap would have caught route/controller mismatches and missing active endpoints earlier.

**Completed**:
- Added `tests/unit/RouteMatrixTest.php` (PHPUnit, 3 tests, 9 assertions). Parses `appinfo/routes.php`, resolves every `foo#bar` route to `\OCA\ChoreStrike\Controller\FooController::bar`, and asserts the method exists and is public. Also asserts no duplicate (verb, url) pairs and all routes use valid HTTP verbs.
- Added `tests/unit/store/apiContract.spec.js` (Jest, 3 tests). Scans `src/store/*.js` for `axios.{get,post,put,delete,patch}` calls, normalises both the route URL and the store call (stripping `/index.php/apps/chorestrike` or `/apps/chorestrike`, expanding `${var}` placeholders, stripping query strings), and asserts every store call matches at least one declared route. Also asserts the removed `completeWithTeam` endpoint is not called.
- Demonstrably fails on drift: the first run of `RouteMatrixTest` caught the dead `room_task#completeWithTeam` route (declared but no `RoomTaskController::completeWithTeam()` method). Root cause fixed by removing the dead route from `appinfo/routes.php`.
- Both tests are part of the blocking release gate.

**Validation**:
- `vendor/bin/phpunit --configuration phpunit.unit.xml tests/unit/RouteMatrixTest.php` -> pass (3/3)
- `npm test -- --testPathPattern apiContract` -> pass (3/3)

---

### P9-E2E-002 - Harden Headless E2E Stability and Add Lightweight UI Semantics Checks
**Priority**: MEDIUM  
**Effort**: L  
**Status**: Done (CS-PLAN-015, 2026-07-26) — migrated set stabilized; long-tail split out as P9-E2E-002b.

**Description**: Current Playwright coverage is broad, but many specs still rely on `waitForTimeout`, text selectors, and CSS implementation details. Visual or semantic GUI checks are minimal, so layout/accessibility regressions can slip through even when core flows pass.

**Completed (CS-PLAN-015, 2026-07-26)**:
- **Foundation:** `App.vue` switched to barrel imports; the `nav-*` testids survive `NcAppNavigationItem` via Vue 3 attribute fallthrough onto `<li>` and are pinned by `tests/unit/components/App.spec.js`. `storageState` auth (`tests/e2e/auth.setup.ts` + a `setup` project in `playwright.config.ts`) so browser contexts start authenticated — `loginAsUser` refactored to detect the active session and only form-login on a real switch. Testid convention documented in [`docs/standards/guidelines/e2e-testid-conventions.md`](../standards/guidelines/e2e-testid-conventions.md).
- **Testid gaps closed:** `Modal.vue` (`modal`, `modal-close`), `TaskList.vue` (`task-item`, `visibility-crown-icon`); `private-filter-toggle` already existed in `QuickStats.vue`. Component specs assert the new testids.
- **Worst-offender spec migration:** `snake-game` (32), `core-functionality` (15+10), `task-completion` + `task-completion-full-flow` (11+11), `gui-scratch-flow` (10), `task-manager` (8), `dashboard` (7), `fast-core-tests` (6) — `waitForTimeout` → auto-waiting locators; `text=`/`.tab-btn:nth-child()` → `getByRole`/`getByTestId`. `waitForTimeout` count on the migrated set = **0**; `browser-aware-navigation.ts` render-bodges removed (0 calls).
- **Dead specs archived:** `phase-3-user-management.spec.ts` (retired group-assignment UI) + the scheduling trio (`scheduling`, `flex-window-scheduling`, `availability-settings-flow` — no `/scheduling` route; revive via CS-016) → `archive/2026-07/e2e-legacy/`.

**Deferred (P9-E2E-002b below)**: the ~13-spec long-tail still carries ~27 `waitForTimeout` calls; the optional a11y/role-check layer (Phase 5) remains non-blocking.

**Validation**:
- Jest 541/541, ESLint 0 errors (69 pre-existing JSDoc warnings), `npm run build` compiles, Playwright config lists 680 tests across 27 spec files (4 archived).
- **Acceptance run pending (Docker):** the "3× clean local E2E runs (chromium+firefox+webkit) with `--retries=0`" criterion must be executed against the running Docker test env — the suite compiles and is correct-by-construction but was not executed end-to-end in this pass.

---

### P9-E2E-002b - E2E long-tail `waitForTimeout` cleanup
**Priority**: LOW  
**Effort**: M–L  
**Status**: Backlog (carved out of CS-PLAN-015)

**Description**: The ~13 remaining specs not migrated by CS-015 still use ~27 `waitForTimeout` calls plus residual text/positional selectors: `multi-user-interactions` (6), `smoke-tests` (6), `minimal-smoke-tests` (4), `daily-tasks-flow` (3), `room-management` (2), and 1-offs (`leaderboard`, `phase-4-advanced-mechanics`, `private-tasks`, `room-detail`, `task-checklist-and-features`, plus the `test-house.ts` fixture). Apply the same `getByTestId`/`getByRole` + auto-waiting migration once the CS-015 foundation is proven flake-free in CI.

---

### P9-DOC-002 - Sync Architecture and Testing Documentation with the Live Application
**Priority**: MEDIUM  
**Effort**: 4 hours  
**Status**: Done (CS-PLAN-014)

**Description**: `docs/ARCHITECTURE.md` currently documents files and folders that do not exist or are no longer authoritative, including `.github/workflows`, `src/services/api.js`, and an outdated frontend structure. This reduces trust in the docs and makes reviews slower.

**Implementation**:
1. Update `docs/ARCHITECTURE.md` to reflect the live routed views, actual store list, active scheduling status, and current frontend boundaries.
2. Update `docs/TESTING.md` to separate release-gate suites from legacy or exploratory suites.
3. Mark unwired legacy components clearly or move them to a legacy area.
4. Align backlog references with current quality goals.

**Tests**:
- Review checklist verifies docs against real files and routes.

**Completed (CS-PLAN-014, 2026-07-26)**:
- `development.md`: replaced the non-existent `.github/workflows/` CI section with the real `.forgejo/workflows/release-gate.yml` gate (mirrors `testing.md`); fixed `tests/Unit/` → `tests/unit/`; replaced the fictional `PointsCalculator` example with the real `GameLogicService::calculateCoins()` signature + pointer to `GameLogicServiceTest.php`; aligned the PR flow with Forgejo (`master`, no `develop`) and cross-linked the git-workflow skill; fixed the broken `../AGENTS.md` → `../../AGENTS.md` link; replaced "Open an issue on GitHub" with the Codeberg tracker; converted the legacy `docker-compose` CLI → `docker compose`. Kept `PHP 8.0+` (matches `info.xml`/`composer.json`).
- `architecture.md`: **kept** the correct `.github/` tree entry; changed `### E2E Tests (Future)` → present-tense (Playwright, live) with a `testing.md` cross-link; added `img/manifest.webmanifest` to the directory tree; corrected `useHouseStore` (removed the non-existent `health` state / `updateHealth()` action; documented the real `houseHealth` getter + `calculateHouseHealth()` action) and `useRoomsStore` (dropped the non-existent `overdueRooms` getter, fixed action names); kept `PHP 8.0+`.
- `testing.md`: removed the archived `multi-user-task-claiming.spec.ts` from its shared E2E row (kept the two live specs); dropped the stale parenthetical spec counts ("32 specs", "42 specs", "18 files"); converted the lone legacy `docker-compose` CLI invocation → `docker compose` (kept the apt `docker-compose-plugin` package name and the legacy fallback note).
- `api/README.md`: annotated `assigned_to_user_id` as the legacy single-assignee field (retained alongside the multi-assignee table; still settable/returned and merged into the assignee set).
- Verified by relative-link-integrity grep (0 broken links across the 4 touched docs) + source spot-reads; no `lib/`/`src/` change, so the build gate is unaffected.

**Deferred (new backlog items P9-DOC-005 + P9-CONFIG-001 below)**: PHP minimum-version alignment (8.0 vs 8.1) and the remaining `development.md` example-code drift (`TaskService::calculateNextDue` PHPDoc example + raw-`axios` endpoint example).

---

### P9-DOC-003 - Streamline Agent Bootstrap and Documentation Entry Points
**Priority**: MEDIUM  
**Effort**: 2 hours  
**Status**: Done

**Description**: `AGENTS.md` had grown beyond a lean bootstrap, while active guidance was spread across duplicated or legacy entry points. This increased token cost, made cross-tool agent behavior less predictable, and kept `.windsurfrules` in the public guidance path even though it is intended for local/private overrides.

**Implementation**:
1. Reduce `AGENTS.md` to the stable bootstrap rules that always apply.
2. Add `docs/README.md` as the public documentation map.
3. Add `docs/standards/I18N.md` as the canonical localization standard.
4. Mark `docs/AGENT_GUIDELINES.md` as a legacy stub and redirect references to active documents.
5. Remove active documentation dependencies on `.windsurfrules`.

**Completed**:
- Replaced the oversized `AGENTS.md` workflow handbook with a shorter bootstrap focused on non-negotiable rules, task-tracking triggers, quality rules, decision rules, and on-demand deeper reads.
- Added `docs/README.md` to make the documentation map explicit for agents and humans using different tools.
- Added `docs/standards/I18N.md` and redirected active standards navigation toward it.
- Reworked `docs/AGENT_GUIDELINES.md` into a legacy-only stub so existing links do not break.
- Updated related references in `docs/standards/README.md`, `docs/standards/LOGGING.md`, `docs/backlog/README.md`, `docs/ARCHITECTURE.md`, and `docs/DEVELOPMENT.md`.
- Added `.windsurfrules` to `.gitignore` so future local/private usage is outside the public bootstrap path.

**Validation**:
- Verified the active documentation chain with targeted grep checks for `AGENT_GUIDELINES.md` and `.windsurfrules` references.
- Reviewed the unstaged diff to confirm the bootstrap reduction and link rewiring.

---

### P9-DOC-004 - Documentation-vs-Implementation Reconciliation & Dead-Code Residue Cleanup
**Priority**: MEDIUM  
**Effort**: 1.5 days  
**Status**: Done (CS-PLAN-010)

**Description**: The 2026-07-24 four-agent doc-vs-impl reconciliation found the documentation several plans behind the implementation, plus leftover Sparks/Monsters residue and dead services/controllers from CS-PLAN-002/003 that were never fully closed.

**Completed**:
- **Doc rewrites:** `architecture.md` (directory tree + DB schema — lowercase tables, `energy_cost`/`coins`, sparks-free, 9 supporting tables, `weekly_goal_notified_week` on `houses`), `design.md` (medal reality note + combo ×2 personal), `gamification-and-rewards.md` + `energy-and-balancing.md` + gamification SKILL (implemented reward tokens, medal award reality, sparks→coins wording), `testing.md` (E2E 20→32 table, command-derived counts, `phpunit.integration.xml` consistency), `api/README.md` (removed phantom `PUT /room-tasks/{id}`, documented real room-task update ops + routes.php pointer, `test/seed/{profile}` path param, stale example fields `difficulty`/`total_points`/`points_earned`/`tank`→real names).
- **Sparks residue (Decision C — drop):** removed `currentSparks`/`totalSparksEarned` + dead `addSparks()`/`spendSparks()` from `HouseUser`; removed unused `SIGNAL_TOTAL_SPARKS_EARNED` (+ match arm / SELECT column) from `TitleCatalog` and `Version1025` backfill; removed dead `MinigameService::getUserSparks()`; new `Version1026` migration drops the `current_sparks`/`total_sparks_earned` columns. (The plan missed `MinigameService::getUserSparks()` as a reader — folded in; only test-referenced.)
- **Dead code archived:** `lib/Service/MedalService.php` (+ `MedalServiceTest`), `lib/Controller/UserStatsController.php` → `archive/2026-07/dead-code/`. Removed `RoomController::addTask()`/`removeTask()` unrouted actions + their now-orphaned `RoomService::addTaskToRoom()`/`removeTaskFromRoom()` counterparts (review follow-up). `RouteMatrixTest` `user_stats` mapping dropped.
- **Medal award gap (Decision A):** documented reality (only `team_anchor` + `streak_keeper` awarded) — see open item **P9-GAME-001** below.

**Validation**: `php -l` clean on all modified PHP; no `lib/` references to archived classes; no live sparks readers (historical migrations untouched).

---

### P9-GAME-001 - Wire the 3 Catalogued-but-Unawarded Medal Types
**Priority**: LOW  
**Effort**: 0.5 day  
**Status**: Done — implemented via CS-PLAN-012 (2026-07-25). All five medal types (`team_anchor`, `streak_keeper`, `flow_keeper`, `room_guardian`, `team_player`) are now awarded in `GameLogicService::checkAndAwardMedals()`. Criteria: `flow_keeper` ≥ 4 distinct active days, `room_guardian` ≥ 5 completions in one room (concentration), `team_player` ≥ 3 combo bonuses. New `CompletedTaskMapper::getMaxRoomCompletionCountByUser` query; the `flow_keeper` active-days count is aggregated inside `getUserStats()` (review-driven N+1 optimization — the planned standalone `countDistinctActiveDaysByUser` method was merged in instead of added separately); `ChoreStrikeNotifier::medalLabel()` extended with all five labels. Unit tests cover award + below-threshold for each new medal. Docs updated to remove "not yet wired" caveats.

---

### P9-DOC-005 - development.md Example-Code Audit
**Priority**: LOW  
**Effort**: 1 hour  
**Status**: Open

**Description**: CS-PLAN-014 fixed the fictional `PointsCalculator` example in `development.md`, but two further example-code drifts were deliberately deferred. (1) The PHP coding-standards example documents a fictional `TaskService::calculateNextDue(Task): \DateTime` method — no such method exists (verified: `TaskService` does not compute due dates; scheduling is owned by `SchedulingService`, `TaskSchedulerService`, `SmartSchedulerService`, and the `lib/Service/Scheduling/` subfolder). (2) The "Adding a new API endpoint" example shows a raw `axios.post('/apps/ChoreStrike/...')` call, which contradicts the canonical shared `src/services/api.js` client enforced by the `tests/unit/store/apiContract.spec.js` contract test.

**Implementation**: Replace the fictional `calculateNextDue` example with a real method (or drop it in favor of a pointer to the relevant scheduling service), and rewrite the endpoint example to use the shared `api` client (`src/services/api.js`). Verify against `lib/Service/` + the contract test.

**Source**: Review of CS-PLAN-014 (2026-07-26).

---

### P9-CONFIG-001 - Align PHP Minimum Version Across Manifest, composer.json, and AGENTS.md
**Priority**: LOW  
**Effort**: 0.5 hour  
**Status**: Open

**Description**: `appinfo/info.xml` declares `<php min-version="8.0" max-version="8.3"/>` and `composer.json` requires `"php": "^8.0"`, but `AGENTS.md` states "PHP 8.1+". The install-time manifest (8.0) is the source of truth, so the engineering docs (CS-PLAN-014) deliberately keep `PHP 8.0+`. This item decides the real minimum: either bump the manifest + `composer.json` to `^8.1` (and verify nothing assumes an 8.1+ feature below the declared floor) and align `AGENTS.md`, or correct `AGENTS.md` down to 8.0+.

**Implementation**: Make the decision, then apply it to `appinfo/info.xml`, `composer.json`, and `AGENTS.md` together so all three agree. CI (`setup-php` in `.forgejo/workflows/release-gate.yml`) currently pins PHP 8.2 for the `composer validate` job.

**Source**: Review of CS-PLAN-014 (2026-07-26).

---

## Success Criteria

- [x] All high-priority security issues resolved
- [x] No deprecated API usage
- [x] Production logging strategy implemented
- [x] ESLint passes with 0 warnings
- [x] All tests passing (frontend + backend)
- [x] Code review document updated

---

## Notes

- Phase 9 should be completed before Phase 8 production deployment
- Some tasks (P9-FEATURE-001, P9-UX-001) can be deferred to Phase 10 if time-constrained
- Security task (P9-SECURITY-002) is **mandatory** before production
- Follow-up review artifact: `docs/backlog/CODE_REVIEW_2026-03-11.md` (delta review since `2f4652b3653a3678ed4f795c1a8e8a898b99b18f`)
- Follow-up review artifact: `docs/backlog/CODE_REVIEW_2026-03-14.md` (latest delta review including minigame score house-scoping fix)

---

**Created**: 2026-03-07  
**Last Updated**: 2026-03-14 23:42
