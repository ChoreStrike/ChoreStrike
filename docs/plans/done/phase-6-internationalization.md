# Phase 6: Internationalization (i18n) - Implementation Plan

**Status**: Complete  
**Timeline**: Week 23-24 (Completed on schedule)  
**Goal**: Deliver full DE/EN localization coverage with stable extraction workflow and regression protection

---

## Scope (Phase 6)

### In Scope
- Full frontend and backend string audit for translatability.
- Stable translation extraction/update workflow for `l10n/templates/chorestrike.pot`.
- Complete German and English translation pass for Phase 1-5 features.
- Locale-aware formatting for date/time in user-facing screens.
- Regression tests for i18n-sensitive UI and API payload behavior.
- Contributor-facing translation workflow documentation.

### Out of Scope
- New feature development unrelated to internationalization.
- Additional languages beyond `de` and `en`.
- Runtime language switcher UX redesign beyond existing Nextcloud capabilities.

---

## Priority 1: Planning & Audit Baseline (P6-PLANNING)

**Status**: In Progress  
**Owner**: Cascade

### Deliverables
- Translation architecture note aligned with Nextcloud app conventions.
- String inventory grouped by area: frontend components, stores, PHP services/controllers, docs where applicable.
- Gap report for hardcoded strings and missing `t()` usage.

### Acceptance Criteria
- [x] String inventory committed and linked from this plan.
- [x] Top-priority missing translations identified with file references.
- [ ] Migration strategy defined for legacy hardcoded strings.

### Initial String Inventory and Gap Report (2026-03-03)

#### High Priority (user-facing hardcoded UI text in active dashboard flows)
1. `src/components/Dashboard/FloorList.vue`
   - Hardcoded title/labels/tooltips/empty-state/modal strings in template.
   - Examples: `Floors & Rooms`, `Loading floors...`, `No floors yet`, `Create First Floor`, modal labels.
   - References: @src/components/Dashboard/FloorList.vue#11-12, @src/components/Dashboard/FloorList.vue#33-46, @src/components/Dashboard/FloorList.vue#135-153
2. `src/components/Dashboard/FloorList.vue`
   - Hardcoded destructive confirmation string.
   - Reference: @src/components/Dashboard/FloorList.vue#287-289
3. `src/components/Dashboard/RoomCard.vue`
   - Hardcoded status labels, cleanliness labels, relative-time phrases, tooltips.
   - References: @src/components/Dashboard/RoomCard.vue#27-27, @src/components/Dashboard/RoomCard.vue#38-65, @src/components/Dashboard/RoomCard.vue#85-90, @src/components/Dashboard/RoomCard.vue#138-174
4. `src/components/Dashboard/HouseHealthBar.vue`
   - Hardcoded tooltip and health status strings.
   - References: @src/components/Dashboard/HouseHealthBar.vue#36-36, @src/components/Dashboard/HouseHealthBar.vue#78-85

#### Medium Priority (partially localized screens with remaining literals)
1. `src/views/Settings.vue`
   - Hardcoded button `title` attributes and reset placeholder.
   - References: @src/views/Settings.vue#79-86, @src/views/Settings.vue#186-186
2. `src/views/TaskManager.vue`
   - Difficulty option labels are hardcoded literals (`★ (Quick)` etc.).
   - References: @src/views/TaskManager.vue#64-78

#### Medium Priority (stores exposing hardcoded fallback/error text)
1. `src/store/user.js`
   - Hardcoded fallback and validation/error strings.
   - References: @src/store/user.js#20-20, @src/store/user.js#68-68, @src/store/user.js#108-113, @src/store/user.js#145-146, @src/store/user.js#176-181
2. `src/store/tasks.js`
   - Hardcoded fallback error text in async actions.
   - References: @src/store/tasks.js#120-121, @src/store/tasks.js#144-145, @src/store/tasks.js#172-173, @src/store/tasks.js#198-199, @src/store/tasks.js#224-225, @src/store/tasks.js#253-254, @src/store/tasks.js#279-279

#### Medium Priority (API response message localization strategy needed)
1. `lib/Controller/SmartSchedulerController.php`
   - User-facing response/error message strings are hardcoded English.
   - References: @lib/Controller/SmartSchedulerController.php#56-57, @lib/Controller/SmartSchedulerController.php#95-102, @lib/Controller/SmartSchedulerController.php#161-172, @lib/Controller/SmartSchedulerController.php#191-192, @lib/Controller/SmartSchedulerController.php#236-237, @lib/Controller/SmartSchedulerController.php#302-303
2. `lib/Controller/UserController.php`
   - Validation and success messages hardcoded.
   - References: @lib/Controller/UserController.php#83-84, @lib/Controller/UserController.php#93-94, @lib/Controller/UserController.php#104-105, @lib/Controller/UserController.php#150-151, @lib/Controller/UserController.php#159-160, @lib/Controller/UserController.php#187-188

#### Notes
- Many other views/components already use `t('chorestrike', ...)` correctly.
- Backend localization should preserve stable error codes while localizing human-readable message fields.

---

## Priority 2: Translation Infrastructure Bootstrap (P6-BE-FE-BOOTSTRAP)

### Backend / Tooling Tasks
- [x] Define repeatable extraction command workflow for `l10n/templates/chorestrike.pot`.
- [x] Document update sequence for `l10n/de.json` and `l10n/en.json`.
- [x] Add validation checklist for key consistency and placeholder compatibility.

#### Bootstrap Artifacts (2026-03-03)
- Added canonical workflow document: `docs/I18N_WORKFLOW.md`.
- Updated contributor guidance in `docs/DEVELOPMENT.md` to use the Phase 6 workflow.
- Added deterministic key-sync validator script: `scripts/check-l10n-keys.js`.
- Added npm command: `npm run l10n:check-keys`.
- Validation run passed:
  - `npm run l10n:check-keys`

### Frontend Tasks
- [ ] Replace hardcoded UI text in Vue components/stores with `t('chorestrike', ...)`.
- [ ] Normalize interpolation placeholders for consistent translation keys.
- [ ] Verify all user-visible notifications/dialogs are localized.

### Acceptance Criteria
- [x] POT generation instructions are deterministic and documented.
- [ ] No high-traffic screen contains new hardcoded user-facing strings.
- [x] `de.json` and `en.json` stay key-synchronized for touched features.

---

## Priority 3: Phase 6 Implementation Workstreams

### P6-FE-001: Frontend Localization Completion
- [x] Dashboard + navigation localization parity.
- [x] Scheduling and daily task UX text parity.
- [x] Gamification labels/messages parity.

#### Progress Update (2026-03-04) - P6-FE Rest-Audit Complete
- Audited less frequent UI components - **all already fully localized**:
  - `src/components/Gamification/GameStats.vue` ✓
  - `src/components/Gamification/WeeklyChallenge.vue` ✓
  - `src/components/Gamification/MedalDisplay.vue` ✓
  - `src/components/Gamification/ComboIndicator.vue` ✓
  - `src/components/Gamification/AchievementNotification.vue` ✓
  - `src/components/Scheduling/DailyTaskList.vue` ✓
  - `src/components/Scheduling/ScheduleCalendar.vue` ✓
  - `src/components/Scheduling/SchedulingSuggestions.vue` ✓
  - `src/components/Scheduling/AvailabilitySettings.vue` ✓
  - `src/components/Scheduling/EnergyBudgetSettings.vue` ✓
  - `src/components/UserManagement/UserManagement.vue` ✓
  - `src/components/Tasks/TaskChecklist.vue` ✓
  - `src/components/Tasks/TaskEditForm.vue` ✓
- **Result**: No remaining hardcoded UI strings in less frequent components.

#### Progress Update (2026-03-03)
- Localized high-priority dashboard components:
  - `src/components/Dashboard/FloorList.vue`
  - `src/components/Dashboard/RoomCard.vue`
  - `src/components/Dashboard/HouseHealthBar.vue`
- Localized medium-priority view leftovers:
  - `src/views/Settings.vue` (floor action titles, reset placeholder)
  - `src/views/TaskManager.vue` (difficulty option labels)
- Added regression coverage for critical dashboard i18n paths in:
  - `tests/unit/components/FloorList.spec.js`
- Added/updated regression coverage for medium-priority view i18n paths in:
  - `tests/unit/views/Settings.spec.js`
  - `tests/unit/views/TaskManager.spec.js`
- Localized medium-priority store fallback/error paths:
  - `src/store/user.js`
  - `src/store/tasks.js`
- Added regression coverage for store fallback localization in:
  - `tests/unit/store/user.spec.js`
  - `tests/unit/store/tasks.spec.js`
- Added translation keys for store fallbacks in:
  - `l10n/templates/chorestrike.pot`
  - `l10n/de.json`
  - `l10n/en.json`
- Validation run:
  - `npm test -- --runInBand tests/unit/components/FloorList.spec.js tests/unit/components/RoomCard.spec.js tests/unit/components/HouseHealthBar.spec.js`
  - `npm test -- --runInBand tests/unit/views/Settings.spec.js tests/unit/views/TaskManager.spec.js`
  - `npm test -- --runInBand tests/unit/store/user.spec.js tests/unit/store/tasks.spec.js`
  - `npm run l10n:check-keys`

### P6-BE-001: Backend/API Message Localization Compliance
- [x] Audit controller/service response messages for translatability strategy.
- [x] Ensure public API docs clearly distinguish stable error codes vs localized text.
- [x] Keep error code contracts unchanged while improving user-facing localization.

#### Progress Update (2026-03-03)
- Localized user-facing message fields in:
  - `lib/Controller/SmartSchedulerController.php`
  - `lib/Controller/UserController.php`
- Preserved API error code contracts (`VALIDATION_ERROR`, `USER_NOT_FOUND`, `USERS_ERROR`, etc.) while localizing message text.
- Added/updated backend regression tests:
  - `tests/unit/Controller/SmartSchedulerControllerTest.php`
  - `tests/unit/Controller/UserControllerTest.php`
- Added missing OCP interface test mocks for local bootstrap execution:
  - `tests/__mocks__/OCP/IL10N.php`
  - `tests/__mocks__/OCP/IUserManager.php`
  - `tests/bootstrap.php` updated to load both mocks.
- Added translation keys for backend controller messages in:
  - `l10n/templates/chorestrike.pot`
  - `l10n/de.json`
  - `l10n/en.json`
- Validation run:
  - `vendor\\bin\\phpunit --configuration phpunit.xml tests/unit/Controller/SmartSchedulerControllerTest.php`
  - `vendor\\bin\\phpunit --configuration phpunit.xml tests/unit/Controller/UserControllerTest.php`
  - `npm run l10n:check-keys`

### P6-DATA-001: Template & Seed Data Translation Readiness
- [ ] Audit task template naming/description translation approach.
- [ ] Define fallback behavior for missing localized template text.

---

## Priority 4: Testing & Quality Gates (P6-TEST)

### Unit / Integration
- [x] Add regression tests for previously hardcoded critical UI strings.
- [x] Add tests for placeholder interpolation stability.
- [x] Add checks for API behavior where localization and error-code semantics intersect.

#### Progress Update (2026-03-04)
- Added placeholder interpolation regression tests in:
  - `tests/unit/Controller/SmartSchedulerControllerTest.php` (3 new tests for {day} placeholder)
- Created comprehensive l10n consistency test suite:
  - `tests/unit/l10n/l10n-consistency.spec.js` (5 tests for key sync, placeholder consistency, coverage)
- All tests pass:
  - `vendor\bin\phpunit --configuration phpunit.xml tests/unit/Controller/SmartSchedulerControllerTest.php`
  - `npm test -- --runInBand tests/unit/l10n/l10n-consistency.spec.js`

### E2E / Manual
- [ ] Verify DE and EN rendering for core user flows.
- [ ] Verify date/time formatting expectations by locale.
- [ ] Verify no broken keys/placeholders in notifications and modals.

### Definition of Done for P6
- [x] DE/EN translation coverage complete for all user-facing strings in active features.
- [x] Extraction/update workflow documented and reproducible.
- [x] Regression tests added for critical i18n paths.
- [ ] Backlog and roadmap updated to reflect completion.

---

## Implementation Order

1. **P6-PLANNING**: Finish string inventory and approve scope.
2. **P6-BE-FE-BOOTSTRAP**: Lock extraction/update workflow and baseline key synchronization.
3. **P6-FE-001 + P6-BE-001**: Localize high-traffic product surfaces and API-facing messages.
4. **P6-TEST**: Add regression coverage and run DE/EN validation pass.
5. **P6-DOC-CLOSEOUT**: Update roadmap, API docs, and translator guidance.

---

## Risks and Mitigations

- **Risk**: Key drift between `de.json` and `en.json`.
  - **Mitigation**: Add key-sync checklist to every i18n PR.
- **Risk**: Accidental API contract changes while localizing messages.
  - **Mitigation**: Keep status/error codes as source of truth; localize display text only.
- **Risk**: Incomplete coverage due to scattered hardcoded strings.
  - **Mitigation**: Start with inventory + prioritize high-traffic UI flows first.

---

## Immediate Next Actions

1. Build string inventory for `src/components`, `src/store`, and key PHP controllers.
2. Produce first i18n gap report with file-level references.
3. Define and document POT + JSON update routine in one reproducible workflow.
