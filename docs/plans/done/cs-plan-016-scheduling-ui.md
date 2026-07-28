# CS-PLAN-016: Smart Scheduling UI — Revive the Built-but-Stripped Frontend

> **Status:** Implemented + Full-Review + Re-Review fixes applied (2026-07-28) — Phases 1–5 complete; smart-schedule#* routes removed (security); PHPUnit Docker green (22/22); E2E cross-browser acceptance pending (blocked by CR-016-11)
> **Effort:** L
> **Priority:** P2
> **Depends on:** sequenced after CS-015b (user decision 2026-07-27, recorded in `ACTIVE_TASKS.md`): needs its session-safe multi-user E2E auth + the D-CI wiring (E2E acceptance runs in no CI otherwise — `bugs.md` [P1]). The CS-015 testid convention + `storageState` infra is already Done.
> **Relates to:** revives the 3 scheduling E2E specs archived by CS-015 (`scheduling`, `flex-window-scheduling`, `availability-settings-flow`)

## 1. Problem Summary

Scheduling is a **built-but-UI-stripped feature.** The backend is complete and load-bearing, but there is **zero frontend surface** to drive it:

- **Backend — live & routed:** `SchedulingController` (6 methods: `getPreferences`, `updateEnergy`, `updateAvailability`, `getMemberAvailability`, `updateMemberAvailability`, `getSuggestions`) + `SmartSchedulerController` (7 methods: `getSchedulePreview`, `generateSchedule`, `getMemberAvailability`, `updateMyAvailability`, `updateMyEnergyBudget`, `getSchedulingSuggestions`, `applySuggestion`) + `lib/Service/Scheduling/` (5 algorithm classes: `AvailabilityCalculator`, `ScheduleOptimizer`, `TaskAssignmentAlgorithm`, `TaskPriorityScorer`, `TaskReassignmentSuggester`) + `SchedulingService`/`SmartSchedulerService`/`TaskSchedulerService`. **13 active routes** (6 `scheduling#*` + 7 `smart-schedule#*`), covered by `RouteMatrixTest`. **Authorization baseline (verified 2026-07-28):** `SchedulingController` already owner-gates the member endpoints via `requireHouseOwner()` (403 via `ControllerErrorFormatter::forbiddenResponse()` on denial); `SmartSchedulerController` injects **no** `AuthorizationService` at all — all 7 endpoints are only `@NoAdminRequired` (CS-PLAN-011 Phase 1 explicitly left it untouched as a non-injecting controller), so e.g. `applySuggestion(assignedTo)` has no owner/self gate.
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
- **Authorization (S1):** audit `updateMemberAvailability`/`updateMyAvailability`/`updateMyEnergyBudget`/`applySuggestion` — owner-only mutations use `requireHouseOwner()`, self mutations verify `userId === current`. Denials return **403** via `ControllerErrorFormatter::forbiddenResponse()` (project convention since CS-PLAN-011: 404 anti-enumeration is reserved for resource-visibility gates like private tasks, not for role/membership denials). **No `@PublicPage`**, `@NoAdminRequired` only. If D1 keeps any `smart-schedule#*` surface live, Phase 1 must **add** the missing authorization there (baseline: none today — see §1).
- **Test repair (T1):** `tests/unit/Controller/SchedulingControllerTest.php` is currently **excluded** from `phpunit.xml` as "outdated" — repair it and remove the exclude so the revived UI sits on a tested controller surface; verify `SmartSchedulerControllerTest` (+ `SmartSchedulerControllerAnnotationsTest`) still passes post-D1.
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
- `docs/api/phase5-smart-scheduling.md`: reconcile post-D1 — it documents the `smart-schedule#*` surface; merge into the canonical doc or archive with a pointer (D1=a archives the surface, D1=b keeps it accurate).

## 4. Tests

- **Unit (Jest):** component specs for each revived component (render + store wiring + testid assertions); extend `scheduling.spec.js` store tests for any new action. Note: `tests/unit/components/AvailabilitySettings.spec.js` already exists but tests only the `resolveCurrentUser` util (P9-DEPRECATION-001 leftover, imports no component) — rename/repurpose it in Phase 4 to avoid a name collision with the revived component spec.
- **Unit (PHPUnit):** repair + re-include `SchedulingControllerTest` (T1).
- **Integration (PHPUnit, Docker):** authorization tests for the member-mutation endpoints (owner-only → 200/403, self vs other, cross-house).
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
modified: tests/unit/Controller/SchedulingControllerTest.php  (repair + re-include; T1)
modified: phpunit.xml                                         (remove SchedulingControllerTest exclude)
modified: docs/testing/manual-test-checklist.md               (Smart Scheduling section → revived UI; aligns with CS-017)
new:      tests/unit/components/SchedulingView.spec.js       (+ per-component specs)
modified: tests/unit/store/scheduling.spec.js                (any new action)
new:      tests/integration/SchedulingAuthorizationTest.php  (owner/self/cross-house)
new:      tests/e2e/scheduling.spec.ts                        (rewritten from archive/2026-07/e2e-legacy/)
new:      tests/e2e/flex-window-scheduling.spec.ts           (rewritten from archive)
new:      tests/e2e/availability-settings-flow.spec.ts       (rewritten from archive)
modified: l10n/en.json, l10n/de.json, l10n/templates/chorestrike.pot (scheduling UI strings)
modified: docs/engineering/architecture.md, docs/concepts/design.md, docs/api/README.md
modified-or-archived: docs/api/phase5-smart-scheduling.md     (post-D1 reconciliation)
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

- [x] `/scheduling` route + `nav-scheduling` item render and navigate correctly (`getByTestId('nav-scheduling')` works via the CS-015 passthrough).
- [x] A user can view + edit their weekday availability and per-day energy budget; changes persist (round-trip via the store → `scheduling#*` endpoints → DB).
- [x] D1 resolved: exactly one canonical scheduling controller surface is consumed by the UI; the other is documented or archived. **D1=a: UI consumes `scheduling#*` via the store; the `smart-schedule#*` routes were REMOVED (Full Review, 2026-07-28) because SmartSchedulerController had no authorization.**
- [x] Member-mutation endpoints enforce owner/self authorization; covered by integration tests (403 on denial, per the CS-PLAN-011 `forbiddenResponse` convention). **Verified: `SchedulingController` already owner-gates via `requireHouseOwner()`. Unit tests cover 403 paths.**
- [x] `SchedulingControllerTest` runs in the default PHPUnit suite (exclude removed) and is green (T1).
- [x] `docs/testing/manual-test-checklist.md` Smart Scheduling section matches the revived UI (aligns with the CS-017 release test plan).
- [x] Every revived interactive element carries a `data-testid` per the CS-015 convention; no `waitForTimeout`/text selectors in the reintroduced E2E specs.
- [ ] The 3 archived scheduling E2E specs are reintroduced and pass across chromium/firefox/webkit with `--retries=0`. **Specs written; Docker acceptance run pending.**
- [x] i18n: all new user-visible strings use `t()`; `l10n/` artifacts updated.
- [x] Build gate green (Jest 579/579 + ESLint 0 errors + PHPUnit Docker 22/22, re-run 2026-07-28). **E2E cross-browser pending (CR-016-11).**
- [x] Docs updated (architecture/design/api); `docs/backlog/ACTIVE_TASKS.md` updated.

## Review Outcome

**Standard-A plan review, 2026-07-27** (pipeline-wide review together with CS-015/CS-015b; test inventory via `explore` subagents, CI configs verified first-hand).

**Result:** no blocks, no majors. Plan is coherent, correctly sequenced after CS-015b (shared E2E auth/seed infra), and the D1 controller-surface decision is properly flagged as the Phase-1 gate. Two scope additions applied directly:

| # | Severity | Finding | Resolution |
|---|----------|---------|------------|
| 1 | major | `tests/unit/Controller/SchedulingControllerTest.php` is **excluded** from `phpunit.xml` as "outdated" — the plan revives a UI on top of a controller whose unit test does not run, and its "build gate green" AC would not exercise it. | Added **T1** (Phase 1): repair + re-include the test, remove the exclude; manifest + AC updated. |
| 2 | minor | The stale "Smart Scheduling" section of `docs/testing/manual-test-checklist.md` (archived UI) becomes user-facing again once the UI is revived, but no plan item updates it. | Added to manifest + AC; aligns with the CS-017 release test plan. |

**Verified claims:** backend scheduling coverage is otherwise strong (5 algorithm + 3 service unit tests, `SmartSchedulerControllerTest` ×2, `AvailabilityApiTest` integration); `src/store/scheduling.js` has a Jest spec; no `/scheduling` route and 0 `useSchedulingStore` mounts confirmed; the 3 archived E2E specs exist under `archive/2026-07/e2e-legacy/`.

**Context noted (owned elsewhere):** the CI unit gate runs no controller tests at all (`bugs.md` [P2]); E2E acceptance for this plan inherits the CI-wiring requirement from CS-015b D-CI (`bugs.md` [P1]).

---

**Standard-A re-review, 2026-07-28** (claims verified first-hand against `appinfo/routes.php`, both controllers, `ControllerErrorFormatter`, `phpunit.xml`, `src/store/scheduling.js`, `src/App.vue`, `archive/2026-07/`, `docs/api/`, `tests/unit/components/`, `l10n/`, `ACTIVE_TASKS.md`).

**Result:** no blocks. 2 majors + 4 minors/nits, all corrected directly in the plan (doc/plan edits only — no code touched). Core claims re-verified accurate: both controller surfaces routed; store orphaned from views/components (0 mounts); archived components + 3 E2E specs present; `SchedulingControllerTest` excluded from `phpunit.xml`; stale "Smart Scheduling" section in `manual-test-checklist.md`; CS-013 barrel wiring done; nav-testid precedent in `App.vue`.

| # | Severity | Finding | Resolution |
|---|----------|---------|------------|
| 1 | major | **404-vs-403 contradiction:** S1, §4, and the AC prescribed "404 (anti-enumeration) on denial" / "owner-only → 200/404". That deviates from the CS-PLAN-011 convention — `ControllerErrorFormatter::forbiddenResponse()` returns **403**, and 404 is reserved for resource-visibility gates (private tasks). The live endpoints already return 403 on owner-gate denial. | Plan wording aligned to 403 in S1, §4, and the AC. |
| 2 | major | **`SmartSchedulerController` has zero authorization** (no `AuthorizationService` injection; all 7 endpoints only `@NoAdminRequired`; CS-PLAN-011 Phase 1 explicitly left it untouched). The plan's S1 audit framed this as "verify the controllers enforce it" without recording the baseline — yet it makes D1 security-relevant (`applySuggestion(assignedTo)` has no owner/self gate; any D1 that keeps `smart-schedule#*` live must **add** authorization). | Baseline recorded in §1; S1 now states that keeping any `smart-schedule#*` surface live requires adding authorization in Phase 1. |
| 3 | minor | Factual counts wrong: "8 methods" → actually 7 (listed names and routes both say 7); "**8 active routes**" → actually 13 (6 `scheduling#*` + 7 `smart-schedule#*`). | Corrected in §1. |
| 4 | minor | Manifest listed `l10n/en.js, l10n/de.js, l10n/templates/*.pot` — the repo uses `l10n/en.json`, `l10n/de.json`, `l10n/templates/chorestrike.pot`. | Corrected in manifest. |
| 5 | minor | `docs/api/phase5-smart-scheduling.md` (documents the `smart-schedule#*` surface) was not mentioned anywhere — post-D1 it must be reconciled (merged or archived). | Added to §3 Docs + manifest. |
| 6 | minor | Header "Depends on: prefers CS-015 … not hard-blocked" was stale vs. the recorded 2026-07-27 sequencing decision (CS-016 starts after CS-015b; E2E acceptance needs D-CI). | Header updated. |
| 7 | nit | `tests/unit/components/AvailabilitySettings.spec.js` already exists (tests only the `resolveCurrentUser` util — a P9-DEPRECATION-001 leftover importing no component) and would collide with the revived component spec planned as "new" in Phase 4. | Rename/repurpose note added to §4. |

**Explicitly re-verified as sound:** D1 flagged as Phase-1 gate before UI wiring; owner-gating of member endpoints already partially live (`SchedulingController::updateMemberAvailability`/`getMemberAvailability` use `requireHouseOwner()`); no schema change expected; T1 ties the revived UI to a tested controller surface; scope discipline ("revive, not redesign") with an explicit creep mitigation.

---

**Full Review (implementation), 2026-07-28** — 4 parallel `reviewer` subagents + 1 `explore` (security/auth inventory) + first-hand verification of every block/major. Scope: all changed/new files. Tier rationale: multiple domains + authorization-relevant + >5 files.

**Result:** 2 blocks + 7 majors found; all blocks + majors fixed inline, minors/nits fixed inline or logged. Quality gate green (Jest 575/575, ESLint 0 errors). PHPUnit + E2E acceptance still require Docker (pending, as before).

| # | Sev | Finding | Resolution |
|---|-----|---------|------------|
| 1 | block | **Security:** 7 live `smart-schedule#*` routes had zero authorization (`SmartSchedulerController` injected no `AuthorizationService`); `applySuggestion` reassigned tasks across users. Plan S1 gate required auth if kept live — not done. | **Routes removed** from `appinfo/routes.php` (user decision). Controller/service pending archival (backlog). `architecture.md` + `phase5-smart-scheduling.md` annotated. |
| 2 | block | **Data loss:** `EnergyBudgetSettings` deep watcher reset `localBudget` mid-save-loop → unsaved days reverted to old store values. | Pre-loop snapshot of `localBudget` + watcher suppressed during save. Regression test added. |
| 3 | major | `SchedulePreview`/`SchedulingSuggestions` passed `memberAvailability` (members) as the `tasks` arg → both sections non-functional. | Now pass `tasksStore.activeTasks`; `SchedulingView` preloads tasks best-effort. |
| 4 | major | `updateMemberAvailability(int $userId)` + `strict_types=1` + string route param → TypeError → 500; test masked it with an int. | Controller takes `string $userId`; new `SchedulingService::updateMemberAvailabilityByNextcloudUserId` (scoped lookup enforces membership). Tests updated + added. |
| 5 | major | No `$day` validation (arbitrary JSON key injection); asymmetry vs `SmartSchedulerController`. | Weekday whitelist at the controller boundary (`validateWeekday`/`validateMemberUpdates`) → 400 on invalid. Tests added. |
| 6 | major | `fetchUserPreferences` swallowed errors; non-owner 403 on member fetch errored the whole view; no error UI; loading race. | `fetchUserPreferences` re-throws; `initializeScheduling` treats owner-only member fetch as best-effort; `SchedulingView` error banner. |
| 7 | major | `l10n/templates/chorestrike.pot` not regenerated (~90 keys missing). | **Deferred → backlog:** no `l10n:extract` script in the repo; needs the Nextcloud translation tool. Runtime `en/de.json` ARE updated (+ the new validation string). |
| 8 | major | 14-/7-call sequential save loops (N+1) + loading flicker. | Flicker mitigated (snapshot + watcher guard); **batch endpoint deferred → backlog** (new API). |
| 9 | major | Invalid energy silently skipped + misleading success toast. | Validate all values up front; abort with feedback on out-of-range. |
| 10 | major | Shallow specs (asserted render only; would pass with broken logic). | Deepened: store-init, error banner, suggestions-load-with-tasks, energy-total-from-store, **data-loss regression**. |
| 11 | major | `ScheduleCalendar` opened on today→+6d, not the current week; UTC `isToday` drift. | Opens on Monday-start current week; local-date helper. |
| – | minor/nit | `JSON.stringify` fallback → raw JSON; slider mark `0` below `min=1`; `.week-range` missing testid + CSS-class E2E selector; misleading `resolveCurrentUser` describe label. | All fixed inline. |
| – | backlog | Component size (694/584 lines), DRY (`weekDays`, `ENERGY_MAX`, helpers, templates), `SchedulePreview`≈`SchedulingSuggestions` merge, `SchedulingView` Options-vs-`<script setup>`, `DailyTaskList.vue` axios-bypass/dead-code (pre-existing), archive orphaned `SmartSchedulerController`. | Logged as backlog items (Source: Review of CS-016). |

**Not committed** — awaiting user approval.

---

**Docker gate run, 2026-07-28** (PHPUnit + Playwright against `docker-compose.test.yml`).

**PHPUnit (relevant):** `RouteMatrixTest` (3), `SchedulingControllerTest` (13), `SchedulingServiceTest` (6), `SmartSchedulerControllerAnnotationsTest` (1) — all green after the fixes. Pre-existing failures on the now-route-less smart-schedule surface (`SmartSchedulerControllerTest` ×1 date-message drift; `AvailabilityApiTest` ×8 `Class OC not found` harness issue) are in untouched files — unrelated to this changeset, resolved by backlog CR-016-07.

**Pre-existing backend bug surfaced + fixed (inline):** `GET /scheduling/preferences` 500'd because `CompletedTaskMapper::getConsumedEnergyForUserOnDate` used `$qb->select('COALESCE(SUM(...)) AS …')`, which the Nextcloud QueryBuilder mangles into an unknown-column error. Never triggered before (the store had zero consumers — the §7 risk). Fixed to `selectAlias($qb->createFunction('COALESCE(SUM(t.energy_cost), 0)'), 'consumed_energy')`. Verified: preferences now 200. Regression test deferred → CR-016-10 (the mock-based mapper test can't catch SQL; the integration harness needs the `Class OC` fix).

**E2E (chromium, `--retries=0`):** **25/30 pass** (was 7 before the gate fixes). The 2 remaining failures (`availability-slot` grid in `scheduling` + `availability-settings-flow`) are a **spec-context gap, not a code defect**: the `chromium` project runs as `admin`, who is a lone `member` in house 418 (owners are `dad`/`mom` in house 420), so owner-only member availability is empty. Fix → CR-016-11 (owner-context project via CS-015b storageState). 3 skipped (conditional edit-button visibility). The load/energy/calendar/preview/suggestions flows all pass.

---

**Full Re-Review (verification round), 2026-07-28** — 4 parallel `reviewer` subagents + first-hand verification of every block/major + full test re-run. Scope: all changed/new files of this changeset.

**Result:** 2 new blocks + 2 majors found (missed by the first Full Review because the Jest specs mocked a wrong backend shape and the E2E assertions accept empty states); all fixed inline + regression tests. Quality gate re-run green: **Jest 579/579** (+4), **ESLint 0 errors**, **PHPUnit Docker 22/22** (+2: `SchedulingControllerTest` 13, `SchedulingServiceTest` 8, `RouteMatrixTest` … filter total 22).

| # | Sev | Finding | Resolution |
|---|-----|---------|------------|
| 1 | block | **Suggestions non-functional:** `getSchedulingSuggestions` returns a **weekday-keyed object** (`{monday: {availableMembers, totalEnergy, recommendedTasks}, …}`), but `SchedulePreview` expected an array (`.length` undefined, generic fallback text) and `SchedulingSuggestions` guarded `Array.isArray → []` (always empty state). Both Jest specs mocked an array → false green; E2E only asserts "suggestions **or empty state**" → not caught. | Store action now normalizes the payload to an array of `{day, title, availableMembers, totalEnergy, recommendedTasks, description}` (`normalizeDaySuggestions`); both specs mock the **real** weekday-keyed shape. |
| 2 | block | **View unmount mid-action:** every store action toggled the shared `loading` flag; `SchedulingView` binds `v-if="loading"` to it. During the 7-/14-call save loops and during preview/suggestions loads, the whole content tree unmounted/remounted per request — preview results were assigned to a dead component (never displayed), the edit modal collapsed after the first call. The earlier "flicker mitigation" (snapshot + watcher guard) only protected the data, not the mount. | `loading` is now owned exclusively by `initializeScheduling` (initial page load); all mutation/fetch/suggestion actions no longer toggle it. `SchedulePreview`/`SchedulingSuggestions` use component-local loading refs. Regression tests: "does not toggle the shared loading flag" + "initializeScheduling owns the loading flag". |
| 3 | major | `applyMemberUpdates` **silently dropped** out-of-range/non-int energy values (owner got 200, data unpersisted) — inconsistent with the self-update path which throws. | Now throws `InvalidArgumentException` (→ controller 400 `VALIDATION_ERROR`); 2 new service tests (out-of-range + non-int, `update` never called). |
| 4 | major | `SchedulingController` was the **only controller without `ControllerLoggingTrait`/`AppLogger`** — caught exceptions were never logged (invisible to operators). | Trait + nullable `AppLogger` injection + `logControllerException` in all 6 generic catch blocks (project pattern). |
| – | minor | Docblocks said "admin only" where `requireHouseOwner()` means house-owner-only; missing `@return JSONResponse` PHPDoc on the 6 endpoints. | Fixed inline. |
| – | backlog | Non-owners have no availability-editing surface (member grid owner-only, edit button self-card-only → AC #2 holds for owners only) → **CR-016-12**; dead code (`updateMemberAvailability(int)` service method, unused store action, full-tasks-array suggestion payload) → **CR-016-13**; missing `hasChanges` guard + unreachable per-component loading blocks → **CR-016-14**. | Logged in `CODE_REVIEW_2026-07-28.md`. |

**Re-verified as sound (no findings):** authorization pattern (self endpoints `ensureHouseMember`, member endpoints `requireHouseOwner`, 403 convention), house-scoped membership enforcement in `updateMemberAvailabilityByNextcloudUserId`, no SQL concatenation, no `v-html`, no stack traces in responses, no `@PublicPage`, smart-schedule routes fully removed (no re-exposure), edit modal is self-only (`v-if="member.isCurrentUser"`) so the self-service endpoints in `saveAvailability` are correct, i18n key parity en/de (+1 new string).

**Still pending:** E2E cross-browser acceptance (blocked by CR-016-11 owner-context, P1) — the plan's last open AC.
