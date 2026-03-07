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
**Status**: Planned

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

---

### P9-FEATURE-001 - Complete Energy Budget Calculation
**Priority**: MEDIUM  
**Effort**: 2 hours  
**Status**: Planned

**Description**: `scheduling.js:113` shows total energy budget but doesn't subtract consumed energy from completed tasks.

**Implementation**:
1. Query `CompletedTask` records for current user and today's date
2. Sum `energyCost` from completed tasks
3. Return `total - consumed`

**Code Location**: `src/store/scheduling.js:113`

**Tests**:
- Unit test for energy calculation with completed tasks
- Unit test for energy calculation with no completed tasks
- Unit test for energy calculation with over-budget scenario

---

### P9-UX-001 - Implement Edit Floor Functionality
**Priority**: MEDIUM  
**Effort**: 1 hour  
**Status**: Planned

**Description**: Edit floor button exists but functionality is stubbed out (`FloorList.vue:326`).

**Implementation Options**:
1. **Option A**: Implement inline edit (recommended)
   - Click floor name to edit
   - Save on Enter, cancel on Escape
   - Similar to room name editing
2. **Option B**: Modal dialog
   - Reuse existing modal component
   - Form with name input
3. **Option C**: Remove edit button
   - If feature not needed

**Code Location**: `src/components/Dashboard/FloorList.vue:326`

**Tests**:
- E2E test for floor name editing
- Unit test for validation (empty name)

---

### P9-DEPRECATION-001 - Replace Deprecated OC.getCurrentUser()
**Priority**: MEDIUM  
**Effort**: 30 minutes  
**Status**: Planned

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

**Code Locations**:
- `src/components/Scheduling/AvailabilitySettings.vue:417-418`

**Tests**:
- Update unit tests to mock `@nextcloud/auth`

---

### P9-REFACTOR-001 - Production Logging Strategy
**Priority**: MEDIUM  
**Effort**: 3 hours  
**Status**: Planned

**Description**: 125 `console.log/error` statements in production code. Need proper logging strategy.

**Implementation**:
1. Create logging utility (`src/utils/logger.js`)
2. Environment-aware logging (dev vs prod)
3. Replace all console statements
4. Remove debug globals (e.g., `window.chorestrike`)

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

---

### P9-LINT-001 - ESLint Cleanup Pass
**Priority**: LOW  
**Effort**: 2 hours  
**Status**: Planned

**Description**: 49 ESLint warnings remaining (down from 457). Mostly JSDoc and formatting issues.

**Implementation**:
1. Run `npm run lint -- --fix` for auto-fixable issues
2. Manually address remaining warnings:
   - Add missing JSDoc comments
   - Fix `vue/singleline-html-element-content-newline`
   - Fix `vue/max-attributes-per-line`
3. Update `.eslintrc.js` if needed for project-specific rules

**Goal**: 0 warnings, 0 errors

**Tests**:
- Verify `npm run lint` passes with 0 warnings

---

## Success Criteria

- [ ] All high-priority security issues resolved
- [ ] No deprecated API usage
- [ ] Production logging strategy implemented
- [ ] ESLint passes with 0 warnings
- [ ] All tests passing (frontend + backend)
- [ ] Code review document updated

---

## Notes

- Phase 9 should be completed before Phase 8 production deployment
- Some tasks (P9-FEATURE-001, P9-UX-001) can be deferred to Phase 10 if time-constrained
- Security task (P9-SECURITY-002) is **mandatory** before production

---

**Created**: 2026-03-07  
**Last Updated**: 2026-03-07
