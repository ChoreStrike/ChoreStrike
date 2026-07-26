# CS-PLAN-016: Smart Scheduling UI — Revive the Built-but-Stripped Frontend

> **Status:** Plan (not started)
> **Effort:** L
> **Priority:** P2
> **Depends on:** prefers CS-015 (testid convention + `storageState` E2E auth infra), not hard-blocked
> **Relates to:** revives the 3 scheduling E2E specs archived by CS-015 (`scheduling`, `flex-window-scheduling`, `availability-settings-flow`)

## 1. Problem Summary

Scheduling is a **built-but-UI-stripped feature.** The backend is complete and load-bearing, but there is **zero frontend surface** to drive it:

- **Backend — live & routed:** `SchedulingController` (6 methods: `getPreferences`, `updateEnergy`, `updateAvailability`, `getMemberAvailability`, `updateMemberAvailability`, `getSuggestions`) + `SmartSchedulerController` (8 methods: `getSchedulePreview`, `generateSchedule`, `getMemberAvailability`, `updateMyAvailability`, `updateMyEnergyBudget`, `getSchedulingSuggestions`, `applySuggestion`) + `lib/Service/Scheduling/` (5 algorithm classes: `AvailabilityCalculator`, `ScheduleOptimizer`, `TaskAssignmentAlgorithm`, `TaskPriorityScorer`, `TaskReassignmentSuggester`) + `SchedulingService`/`SmartSchedulerService`/`TaskSchedulerService`. **8 active routes** (`scheduling#*` + `smart_scheduler#*`), covered by `RouteMatrixTest`.
- **Store — live but orphaned:** `src/store/scheduling.js` (370 lines, 7 actions, all hitting `/api/v1/scheduling/*`). Its data is consumed indirectly (StreakFreeze/DailyNotification jobs read `weekdayAvailability` from the DB; child task filtering + energy-budget calc depend on it), but **no view mounts it** (verified: 0 `useSchedulingStore` mounts in `src/views`+`src/components`).
- **UI — archived:** CS-PLAN-003 moved `SchedulingView.vue` + 5 components (`AvailabilitySettings`, `EnergyBudgetSettings`, `ScheduleCalendar`, `SchedulePreview`, `SchedulingSuggestions`) to `archive/2026-07/legacy-unwired/`. `router.js` has no `/scheduling` route; only `DailyTaskList.vue` survives in `src/components/Scheduling/`.

**Consequence today:** users cannot edit their availability/energy budget; the jobs run against migration defaults only. The 3 scheduling E2E specs were archived by CS-015 as "revive when scheduling UI ships" — this is that plan.

## 2. Strategy

Wire a real Scheduling UI to the **existing** backend+store — revive/adapt the archived Vue components rather than greenfield. Lower risk because the data model, store actions, endpoints, and algorithms already exist and the background jobs prove the contract works.

1. **Reconcile the two controller surfaces** (`scheduling#*` vs `smart-schedule#*`) — decide which the UI consumes; the store currently calls `scheduling#*`. Avoid two parallel member-availability endpoints.
2. **Revive + adapt** the 6 archived components against the current store + Nc* component versions; add a `/scheduling` route + a `nav-scheduling` item in `App.vue` (using the CS-015 testid passthrough).
3. **Authorize** member-mutation endpoints (`updateMemberAvailability`) as owner-only (`requireHouseOwner`) — verify the controllers enforce it.
4. **Reintroduce** the 3 archived scheduling E2E specs (from `archive/2026-07/e2e-legacy/`) rewritten against the real, testid-bearing UI as the final phase.

## 3. Changes

### Backend (`lib/`)
- **Decision (D1):** reconcile `SchedulingController` vs `SmartSchedulerController`. Options: (a) UI consumes only `scheduling#*` and the `smart-schedule#*` surface is archived/removed if unused; (b) consolidate the two into one controller. Prefer (a) unless the smart-schedule preview/generate UX is wanted.
- **Authorization (S1):** audit `updateMemberAvailability`/`updateMyAvailability`/`updateMyEnergyBudget`/`applySuggestion` — owner-only mutations use `requireHouseOwner()`, self mutations verify `userId === current`. Return 404 (anti-enumeration) on denial. **No `@PublicPage`**, `@NoAdminRequired` only.
- Likely **no schema/migration change** (columns exist: `scheduling_preferences` JSON, `weekdayAvailability`, `maxDailyTasks`, energy fields). Confirm during Phase 1.

### Frontend (`src/`)
- **`src/router.js`:** add `{ path: '/scheduling', name: 'scheduling', component: () => import('./views/SchedulingView.vue') }`.
- **`src/App.vue`:** add `<NcAppNavigationItem :to="{ name: 'scheduling' }" data-testid="nav-scheduling" :name="t('chorestrike', 'Scheduling')" />` (uses the CS-015 nav-testid passthrough).
- **`src/views/SchedulingView.vue`** + **`src/components/Scheduling/{AvailabilitySettings,EnergyBudgetSettings,ScheduleCalendar,SchedulePreview,SchedulingSuggestions}.vue`:** revive from `archive/2026-07/legacy-unwired/`, adapt to the current store API + current `@nextcloud/vue` component API + Composition-API conventions. Add `data-testid` per the CS-015 convention (`scheduling-view`, `availability-settings`, `availability-grid`, `availability-slot`, `energy-budget-settings`, `schedule-preview`, `scheduling-suggestions`, `daily-progress`, …).
- **`src/store/scheduling.js`:** verify `initializeScheduling()` is called from `SchedulingView` `onMounted`; ensure `reset()` is in `resetAllStores()` (CS-PLAN-013 already wired the store into the barrel — confirm).

### E2E (`tests/e2e/`)
- Reintroduce + rewrite the 3 archived specs against the real UI (`getByTestId`/`getByRole`, auto-waiting locators, no `waitForTimeout`): `scheduling.spec.ts`, `flex-window-scheduling.spec.ts`, `availability-settings-flow.spec.ts`.

### Docs
- `docs/engineering/architecture.md`: document the revived scheduling view + which controller surface is canonical (post D1).
- `docs/concepts/design.md`: add the scheduling UX concept (currently absent — grep found no scheduling mention).
- `docs/api/README.md`: document the canonical scheduling endpoints (post D1).

## 4. Tests

- **Unit (Jest):** component specs for each revived component (render + store wiring + testid assertions); extend `scheduling.spec.js` store tests for any new action.
- **Integration (PHPUnit, Docker):** authorization tests for the member-mutation endpoints (owner-only → 200/404, self vs other, cross-house).
- **E2E (Playwright):** the 3 reintroduced specs against the real UI (family profile). Success = stable across chromium/firefox/webkit (`--retries=0`, per CS-015's measurement rule).

## 5. File Manifest

```
new:      src/views/SchedulingView.vue                       (revived from archive/2026-07/legacy-unwired/)
new:      src/components/Scheduling/AvailabilitySettings.vue (revived + adapted)
new:      src/components/Scheduling/EnergyBudgetSettings.vue (revived + adapted)
new:      src/components/Scheduling/ScheduleCalendar.vue     (revived + adapted)
new:      src/components/Scheduling/SchedulePreview.vue      (revived + adapted)
new:      src/components/Scheduling/SchedulingSuggestions.vue (revived + adapted)
modified: src/router.js                                       (/scheduling route)
modified: src/App.vue                                         (nav-scheduling item)
modified: src/store/scheduling.js                             (mount wiring if needed)
modified: lib/Controller/SchedulingController.php            (auth audit; post-D1)
modified: lib/Controller/SmartSchedulerController.php        (auth audit; post-D1 — archive if D1=a)
new:      tests/unit/components/SchedulingView.spec.js       (+ per-component specs)
modified: tests/unit/store/scheduling.spec.js                (any new action)
new:      tests/integration/SchedulingAuthorizationTest.php  (owner/self/cross-house)
new:      tests/e2e/scheduling.spec.ts                        (rewritten from archive/2026-07/e2e-legacy/)
new:      tests/e2e/flex-window-scheduling.spec.ts           (rewritten from archive)
new:      tests/e2e/availability-settings-flow.spec.ts       (rewritten from archive)
modified: l10n/en.js, l10n/de.js, l10n/templates/*.pot       (scheduling UI strings)
modified: docs/engineering/architecture.md, docs/concepts/design.md, docs/api/README.md
new:      docs/plans/active/cs-plan-016-scheduling-ui.md     (manual new/ -> active/ move)
```

## 6. Implementation Order

1. **Phase 1 — Reconcile & verify backend:** decide D1 (controller surface); audit/fix authorization on member-mutation endpoints; confirm no schema change needed; add integration auth tests.
2. **Phase 2 — Route + nav + mount:** add `/scheduling` route + `nav-scheduling` item; revive `SchedulingView.vue` skeleton that calls `initializeScheduling()` and renders the store data; smoke that it loads.
3. **Phase 3 — Revive components:** bring back the 5 sub-components adapted to current APIs + testids; wire to store actions; i18n (`t()` + `l10n/` artifacts in the same change).
4. **Phase 4 — Component Jest specs:** render + store-wiring + testid assertions for each revived component.
5. **Phase 5 — Reintroduce E2E:** rewrite the 3 archived scheduling specs against the real, testid-bearing UI; green across 3 browsers with `--retries=0`.
6. **Phase 6 — Verify:** build gate green (Jest + ESLint 0 errors + PHPUnit integration + E2E), docs updated, backlog sync.

## 7. Risks & Mitigations

| Risk | Likelihood | Mitigation |
|------|-----------|------------|
| Two overlapping controller surfaces (`scheduling#*` + `smart-schedule#*`) cause drift | Medium | Resolve D1 in Phase 1 before any UI wiring. Prefer one canonical surface; archive the other if unused (precedent: group-assignment archival in CS-011). |
| Archived components target an older `@nextcloud/vue` API (deprecated `OC.getCurrentUser`, old `NcModal`/`NcButton` props) | High | P9-DEPRECATION-001 already migrated `AvailabilitySettings` off `OC.getCurrentUser` before it was archived — re-apply that pattern. Audit each revived component against the current `@nextcloud/vue` version in Phase 3. |
| Member-mutation endpoints not owner-gated | Medium | Phase 1 auth audit + integration tests (owner-only → 200/404, self vs other, cross-house). Security finding → never auto-fix; surface explicitly. |
| Revived UI reveals backend bugs/edge cases never exercised (the store has had zero consumers) | Medium | Phase 2 smoke + Phase 4 component specs surface contract gaps early; fix at the source (loading state) rather than re-adding sleeps. |
| Scope creep into "redesign scheduling UX" | Medium | This plan **revives** the archived UX, not redesigns it. New UX is a separate decision/plan. |

## 8. Acceptance Criteria

- [ ] `/scheduling` route + `nav-scheduling` item render and navigate correctly (`getByTestId('nav-scheduling')` works via the CS-015 passthrough).
- [ ] A user can view + edit their weekday availability and per-day energy budget; changes persist (round-trip via the store → `scheduling#*` endpoints → DB).
- [ ] D1 resolved: exactly one canonical scheduling controller surface is consumed by the UI; the other is documented or archived.
- [ ] Member-mutation endpoints enforce owner/self authorization; covered by integration tests (404 anti-enumeration on denial).
- [ ] Every revived interactive element carries a `data-testid` per the CS-015 convention; no `waitForTimeout`/text selectors in the reintroduced E2E specs.
- [ ] The 3 archived scheduling E2E specs are reintroduced and pass across chromium/firefox/webkit with `--retries=0`.
- [ ] i18n: all new user-visible strings use `t()`; `l10n/` artifacts updated; `npm run l10n:check-keys` passes.
- [ ] Build gate green (Jest + ESLint 0 errors + PHPUnit integration + E2E on the scheduling set).
- [ ] Docs updated (architecture/design/api); `docs/backlog/ACTIVE_TASKS.md` updated.

## Review Outcome

_Pending — propose a Standard-A review after the user moves `new/ → active/` (or now, on request). Key open question to confirm at review time: the D1 controller-surface decision._
