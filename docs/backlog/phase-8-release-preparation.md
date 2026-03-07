# Phase 8: Release Preparation

> **Status**: Planned  
> **Timeline**: Week 29-32  
> **Depends on**: Phase 7 (Complete)

---

## Overview

Final preparation for the staged rollout path: `v0.8.x` alpha, `v0.9.x` private beta, and `v1.0.0` stable manual-install release. App Store submission is explicitly deferred until after stable quality is proven.

## Agreed Rollout Order

1. **A - Release Strategy**
   - Define the staged rollout plan before public distribution.
2. **B - Issue and Release Workflow**
   - Define how bugs, feedback, validation, and releases move through the project.
3. **C - Exit Criteria**
   - Define release gates for `v0.8 Alpha`, `v0.9 Beta`, and `v1.0 Stable`.

## Minimal Issue And Release Workflow

### Shared Workflow

1. **Capture**
   - Record issues and feedback in the backlog or issue tracker.
2. **Confirm**
   - Reproduce, classify, and prioritize the issue.
3. **Plan**
   - Decide whether to fix now or defer to a later milestone.
4. **Implement**
   - Fix the issue and add regression coverage where practical.
5. **Validate**
   - Re-run targeted tests and manual verification.
6. **Release Decision**
   - Only promote a new release tag when blocking issues are resolved or consciously deferred.

### Alpha Rule
- Keep issue intake lightweight and optimized for fast iteration on a personal instance.

### Beta Rule
- Require structured tester feedback and explicit tracking of setup, upgrade, and usability issues.

### Stable Rule
- Require documented known issues, validation notes, and a release decision separate from App Store submission.

---

## Tasks

### P6-P7-CODE-REVIEW: Code Review (Day 1 - First Priority!)
**Priority**: High  
**Status**: In Progress

- [x] Review all Phase 6 (i18n) code changes
- [x] Review all Phase 7 (Performance) code changes
- [x] PSR-12 compliance check
  - [x] Run php-cs-fixer on all PHP files
  - [x] Fix any violations
- [x] ESLint compliance check
  - [x] Run eslint on all JS/Vue files
  - [x] Fix remaining warnings
- [ ] Dead code removal
  - [ ] Identify unused imports
  - [ ] Remove commented-out code
  - [ ] Remove unused functions/methods

#### Progress Update (2026-03-04)
- Hardened `SmartSchedulerController` endpoints from `@PublicPage` to `@NoAdminRequired`.
- Fixed migration runtime issue in `Version1015Date20260304000000` by initializing schema from closure.
- Added regression tests:
  - `tests/unit/Controller/SmartSchedulerControllerAnnotationsTest.php`
  - `tests/unit/Migration/Version1015Date20260304000000Test.php`
- Added missing migration mocks and bootstrap loading for local PHPUnit execution.
- Fixed dashboard navigation regression: `RoomCard.vue` now routes to `/room/:id` and has explicit navigation assertions.
- Validation runs:
  - `vendor\\bin\\phpunit --configuration phpunit.xml tests/unit/Controller/SmartSchedulerControllerTest.php`
  - `vendor\\bin\\phpunit --configuration phpunit.xml tests/unit/Controller/SmartSchedulerControllerAnnotationsTest.php tests/unit/Migration/Version1015Date20260304000000Test.php`
  - `npm test -- --runInBand tests/unit/components/RoomCard.spec.js tests/unit/views/TaskManager.spec.js`
  - `npm run l10n:check-keys`
- Follow-up updates:
  - Stabilized TypeScript-ESLint toolchain for TypeScript `5.9.x` by pinning `@typescript-eslint/eslint-plugin`, `@typescript-eslint/parser`, and `@typescript-eslint/typescript-estree` to `8.56.1`.
  - Added npm `overrides` to enforce the same TypeScript-ESLint version inside `@vue/eslint-config-typescript` dependency tree.
  - Validated clean frontend lint run with:
    - `npx eslint --ext .js,.vue src --quiet`
  - Hardened smart scheduling suggestion assignment flow:
    - `SmartSchedulerService::applySuggestion()` now maps missing house-member assignees to `InvalidArgumentException('Assignee must be a house member')`.
    - Added regression test `testApplySuggestion_NonHouseMember_ThrowsInvalidArgumentException` in `tests/unit/Service/SmartSchedulerServiceTest.php`.
  - Completed full PSR-12 formatter pass over backend and test code:
    - `vendor\\bin\\php-cs-fixer fix --using-cache=no --rules=@PSR12 lib`
    - `vendor\\bin\\php-cs-fixer fix --using-cache=no --rules=@PSR12 tests`
    - Verification:
      - `vendor\\bin\\php-cs-fixer fix --dry-run --using-cache=no --rules=@PSR12 lib`
      - `vendor\\bin\\php-cs-fixer fix --dry-run --using-cache=no --rules=@PSR12 tests`
  - Validation after PSR-12 formatting:
    - `npx eslint --ext .js,.vue src --quiet` ✅
    - `npm test -- --runInBand tests/unit/components/RoomCard.spec.js tests/unit/views/TaskManager.spec.js` ✅
    - `vendor\\bin\\phpunit --configuration phpunit.xml tests/unit` ⚠️ fails in existing broad-suite mocks (`OCP\\DB\\QueryBuilder\\IQueryBuilder`, `OCP\\IConfig`) and is tracked as pre-existing test environment gap

### P8-SECURITY-001: Security Audit
**Priority**: High  
**Status**: ✅ Completed

- [x] Input validation review
  - [x] Check all controller endpoints for proper input sanitization
  - [x] Verify parameter type validation
  - [x] Check for SQL injection vulnerabilities
- [x] XSS prevention check
  - [x] Verify all user input is escaped in templates
  - [x] Check Vue component v-html usage (none found)
- [x] CSRF token verification
  - [x] Verify all POST/PUT/DELETE endpoints check CSRF (OCSController handles)
- [x] Authorization review
  - [x] Verify ownership checks on all resource mutations
  - [x] Check house membership validation
- [x] **Nextcloud Isolation Verification** (CRITICAL)
  - [x] App exceptions cannot crash Nextcloud server
  - [x] Database errors are caught and handled gracefully
  - [x] App uses own database tables (no shared schema pollution)
  - [x] App respects Nextcloud's app isolation boundaries
- [x] Create SECURITY.md documentation

### P8-INTERNAL-TEST: Internal Testing Phase
**Priority**: High  
**Status**: In Progress

- [ ] Test DB Exploration
  - [ ] Set up local test database
  - [ ] Manual testing of all features
  - [ ] Edge case exploration
  - [ ] Stress testing (many tasks, users)
- [x] Beta Deployment Preparation
  - [x] Create deployment checklist (docs/DEPLOYMENT_CHECKLIST.md)
  - [x] Verify backup strategy for Nextcloud (documented in checklist)
  - [x] Document rollback procedure (documented in checklist)
- [x] Manual Testing Checklist
  - [x] Create comprehensive test checklist (docs/MANUAL_TEST_CHECKLIST.md)
- [ ] Beta Deployment on Personal Server
  - [ ] Deploy to personal Nextcloud instance
  - [ ] Verify no impact on other Nextcloud apps
  - [ ] Real-world usage testing for `v0.8.x` alpha
- [ ] Beta Testers (Optional)
  - [ ] Invite private testers for `v0.9.x` beta
  - [ ] Collect structured feedback
  - [ ] Document issues found

### P8-TEST-001: Integration Tests
**Priority**: Medium  
**Status**: Pending

- [ ] Run integration tests with Docker test environment
  - [ ] Fix Docker test container startup issues
  - [ ] Run `phpunit.xml` integration suite
  - [ ] Verify all API endpoints work end-to-end

### P8-TEST-002: E2E Test Stabilization
**Priority**: Medium  
**Status**: Pending

- [ ] Stabilize remaining E2E tests
  - [ ] Fix flaky tests
  - [ ] Add missing test coverage
  - [ ] Verify cross-browser compatibility

### P8-DOC-001: Documentation
**Priority**: Medium  
**Status**: ✅ Completed

- [x] Update ROADMAP.md with Phase 7 completion
- [x] User guide (README.md enhancement)
  - [x] Add feature walkthrough
  - [x] Add installation instructions
  - [x] Add user guide sections
- [x] Create CHANGELOG.md for v1.0.0
- [ ] API documentation completion (optional - API.md exists)
  - [ ] Verify all endpoints documented
  - [ ] Add request/response examples
- [ ] Add screenshots (requires running app)

### P8-RELEASE-001: Release Preparation
**Priority**: High  
**Status**: Pending

- [ ] Create release notes
- [ ] Prepare screenshots for app store
- [ ] Create `v1.0.0` stable manual-install release
- [ ] Evaluate App Store readiness after stable release validation
- [ ] Submit to Nextcloud App Store in a later release only if readiness criteria are met
- [ ] Create GitHub release

---

## Security Architecture Notes

### Nextcloud App Isolation (Built-in Protections)

Nextcloud apps are **isolated by design**:
- **Own database tables**: Each app uses prefixed tables (`oc_chorestrike_*`)
- **Exception handling**: Uncaught app exceptions don't crash Nextcloud
- **Route isolation**: App routes are namespaced (`/apps/chorestrike/`)
- **No direct filesystem access**: Apps use Nextcloud's filesystem abstraction

### What We Ensure
1. **All database operations use prepared statements** (via Doctrine QB)
2. **All user input is validated** before processing
3. **All mutations check ownership** (house membership)
4. **Exceptions are caught** and return proper error responses
5. **No hardcoded paths** or external dependencies

---

## Dependencies

- Docker test environment must be functional for integration tests
- All unit tests must pass (✓ 300 tests passing)

---

## Success Criteria

- [ ] All security vulnerabilities addressed
- [ ] Code passes linting standards
- [ ] Integration tests pass
- [ ] E2E tests stable
- [ ] Internal testing completed without data loss
- [ ] Beta deployment successful
- [ ] Documentation complete
- [ ] `v1.0.0` stable manual-install release completed
- [ ] App Store submission decision documented

## Milestone Exit Criteria

### `v0.8.x` Alpha Exit Criteria
- [ ] Manual install works on the personal Nextcloud instance
- [ ] Core flows work in real usage (floors, rooms, tasks, completion, scheduling screens)
- [ ] No known data-loss issue remains open
- [ ] No unresolved P0 issue exists
- [ ] Alpha findings are documented in backlog or issue tracking
- [ ] Alpha validation note exists before tagging

### `v0.9.x` Beta Exit Criteria
- [ ] Private tester onboarding is possible
- [ ] Core flows are reproducible across more than one setup
- [ ] Install, update, and rollback steps are documented for testers
- [ ] Known issues are documented before beta release
- [ ] Feedback intake and prioritization are operational
- [ ] No unresolved P0 issue exists
- [ ] No unresolved P1 issue remains in supported beta workflows unless documented and accepted

### `v1.0.0` Stable Exit Criteria
- [ ] Supported workflows are documented
- [ ] Release notes, known issues, install guide, and upgrade guide are ready
- [ ] No unresolved P0 issue exists
- [ ] No unresolved P1 regression remains in core supported workflows
- [ ] Linting is clean for supported code paths
- [ ] Targeted automated tests and manual validation are completed for supported workflows
- [ ] Stable release decision is documented independently from App Store submission

---

**Last Updated**: 2026-03-07
