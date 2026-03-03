# Phase 6: Internationalization (i18n) - Implementation Plan

**Status**: In Progress (Planning)  
**Timeline**: Week 23-24  
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
- [ ] String inventory committed and linked from this plan.
- [ ] Top-priority missing translations identified with file references.
- [ ] Migration strategy defined for legacy hardcoded strings.

---

## Priority 2: Translation Infrastructure Bootstrap (P6-BE-FE-BOOTSTRAP)

### Backend / Tooling Tasks
- [ ] Define repeatable extraction command workflow for `l10n/templates/chorestrike.pot`.
- [ ] Document update sequence for `l10n/de.json` and `l10n/en.json`.
- [ ] Add validation checklist for key consistency and placeholder compatibility.

### Frontend Tasks
- [ ] Replace hardcoded UI text in Vue components/stores with `t('chorestrike', ...)`.
- [ ] Normalize interpolation placeholders for consistent translation keys.
- [ ] Verify all user-visible notifications/dialogs are localized.

### Acceptance Criteria
- [ ] POT generation instructions are deterministic and documented.
- [ ] No high-traffic screen contains new hardcoded user-facing strings.
- [ ] `de.json` and `en.json` stay key-synchronized for touched features.

---

## Priority 3: Phase 6 Implementation Workstreams

### P6-FE-001: Frontend Localization Completion
- [ ] Dashboard + navigation localization parity.
- [ ] Scheduling and daily task UX text parity.
- [ ] Gamification labels/messages parity.

### P6-BE-001: Backend/API Message Localization Compliance
- [ ] Audit controller/service response messages for translatability strategy.
- [ ] Ensure public API docs clearly distinguish stable error codes vs localized text.
- [ ] Keep error code contracts unchanged while improving user-facing localization.

### P6-DATA-001: Template & Seed Data Translation Readiness
- [ ] Audit task template naming/description translation approach.
- [ ] Define fallback behavior for missing localized template text.

---

## Priority 4: Testing & Quality Gates (P6-TEST)

### Unit / Integration
- [ ] Add regression tests for previously hardcoded critical UI strings.
- [ ] Add tests for placeholder interpolation stability.
- [ ] Add checks for API behavior where localization and error-code semantics intersect.

### E2E / Manual
- [ ] Verify DE and EN rendering for core user flows.
- [ ] Verify date/time formatting expectations by locale.
- [ ] Verify no broken keys/placeholders in notifications and modals.

### Definition of Done for P6
- [ ] DE/EN translation coverage complete for all user-facing strings in active features.
- [ ] Extraction/update workflow documented and reproducible.
- [ ] Regression tests added for critical i18n paths.
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
