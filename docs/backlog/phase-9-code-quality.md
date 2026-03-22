# Phase 9: Code Quality & Technical Debt

**Status**: Planned  
**Priority**: Medium  
**Estimated Effort**: 2-3 days  
**Dependencies**: Phase 8 Release Preparation

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
**Status**: Planned

**Description**: `docs/ARCHITECTURE.md` and `docs/TESTING.md` describe CI and workflow structure, but the repository currently has no tracked `.github/workflows/*` pipeline. This leaves linting, PHPUnit, Jest, and a headless smoke slice unenforced outside manual execution.

**Implementation**:
1. Add a repository-tracked CI workflow for frontend lint, backend lint, PHPUnit, and Jest.
2. Add an optional non-blocking Playwright smoke job or documented nightly workflow.
3. Document the authoritative local and CI commands in one place.
4. Update `docs/ARCHITECTURE.md` and `docs/TESTING.md` so they match the real automation setup.

**Tests**:
- Verify the pipeline runs the documented commands successfully.
- Verify CI documentation matches the committed workflow files.

---

### P9-ARCH-002 - Introduce a Shared Frontend API Layer and Normalize URL Generation
**Priority**: HIGH  
**Effort**: 1 day  
**Status**: Planned

**Description**: The architecture document describes a frontend API layer, but current stores call `axios` directly and use mixed URL styles (`/apps/...` vs `/index.php/apps/...`). This increases drift risk and makes route-prefix bugs likely.

**Implementation**:
1. Add a shared frontend API client module for route construction and common response handling.
2. Replace direct store-level string URLs with shared helpers.
3. Standardize error mapping and request semantics across stores.
4. Update architecture docs to describe the real frontend data flow.

**Tests**:
- Add unit tests for API URL generation and error normalization.
- Add regression coverage for route prefixing in active stores.

---

### P9-ARCH-003 - Remove Duplicated Room-Task Logic Across Frontend Stores
**Priority**: HIGH  
**Effort**: 6 hours  
**Status**: Planned

**Description**: `src/store/tasks.js` and `src/store/roomTasks.js` both own room-task fetching and derived room-task state. This mixes catalog-task and assignment-task concerns and creates drift in business behavior, test coverage, and API evolution.

**Implementation**:
1. Define a single owner for room-task state and actions.
2. Reduce the other store to read-only composition or remove the overlap entirely.
3. Extract shared derived helpers where appropriate.
4. Update dependent views and tests to use the canonical store boundary.

**Tests**:
- Add regression tests for the canonical room-task state owner.
- Remove or rewrite duplicated test cases that validate the same logic in two stores.

---

### P9-TEST-002 - Add Route and Contract Coverage for Frontend/Backend Wiring
**Priority**: HIGH  
**Effort**: 1 day  
**Status**: Planned

**Description**: The review found missing contract protection between `appinfo/routes.php`, controller methods, and active frontend consumers. This gap would have caught route/controller mismatches and missing active endpoints earlier.

**Implementation**:
1. Add a backend test that validates each declared route targets an implemented controller method.
2. Add a frontend contract test for active stores/components against the expected API matrix.
3. Add a release-gate check for critical active endpoints.
4. Document how new endpoints must be covered.

**Tests**:
- Route matrix test for `appinfo/routes.php`.
- Store contract tests for active API consumers.

---

### P9-E2E-002 - Harden Headless E2E Stability and Add Lightweight UI Semantics Checks
**Priority**: MEDIUM  
**Effort**: 1 day  
**Status**: Planned

**Description**: Current Playwright coverage is broad, but many specs still rely on `waitForTimeout`, text selectors, and CSS implementation details. Visual or semantic GUI checks are minimal, so layout/accessibility regressions can slip through even when core flows pass.

**Implementation**:
1. Replace arbitrary waits with response-, state-, or test-id-driven synchronization.
2. Introduce stable selector rules for active views and shared components.
3. Add a small snapshot or semantic-render smoke suite for key screens.
4. Add lightweight accessibility assertions for critical flows.

**Tests**:
- Update fragile Playwright specs to use stable selectors.
- Add snapshot or semantic smoke checks for dashboard, task manager, settings, and leaderboard.

---

### P9-DOC-002 - Sync Architecture and Testing Documentation with the Live Application
**Priority**: MEDIUM  
**Effort**: 4 hours  
**Status**: Planned

**Description**: `docs/ARCHITECTURE.md` currently documents files and folders that do not exist or are no longer authoritative, including `.github/workflows`, `src/services/api.js`, and an outdated frontend structure. This reduces trust in the docs and makes reviews slower.

**Implementation**:
1. Update `docs/ARCHITECTURE.md` to reflect the live routed views, actual store list, active scheduling status, and current frontend boundaries.
2. Update `docs/TESTING.md` to separate release-gate suites from legacy or exploratory suites.
3. Mark unwired legacy components clearly or move them to a legacy area.
4. Align backlog references with current quality goals.

**Tests**:
- Review checklist verifies docs against real files and routes.

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
