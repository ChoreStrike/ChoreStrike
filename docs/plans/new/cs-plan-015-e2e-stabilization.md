# CS-PLAN-015: E2E Stabilization — data-testid, Stable Selectors & Auth Optimization

> **Status:** Plan (not started)
> **Effort:** L
> **Priority:** P2
> **Depends on:** none (CS-PLAN-007 Phase 4 did the partial auth fix; release-gate views already carry root testids)
> **Closes:** `docs/backlog/bugs.md` → **[P2] E2E Tests: Missing data-testid attributes**; `docs/backlog/phase-9-code-quality.md` → **P9-E2E-002**

## 1. Problem Summary

The Playwright E2E suite (31 specs, 3 browser projects) is broad but fragile. A pre-plan audit (2026-07-25) quantified the anti-patterns:

- **176 `waitForTimeout` calls** across 26 specs + 5 in fixtures (`browser-aware-navigation.ts` uses 2000–5000 ms render-bodges per browser).
- **102 text-based selectors** (`text=Tasks`, `button:has-text("Complete")`, `th:has-text("Points")`, …) across 22 specs.
- **Zero** use of Playwright's semantic locator API (`getByRole` / `getByLabel` / `getByPlaceholder`).
- **20+ testids that specs query but do NOT exist in source** (e.g. `[data-testid="scheduling-view"]`, `[data-testid="user-management"]`, `[data-testid="floor-item"]`, `[data-testid="task-item"]`, `[data-testid="private-filter-toggle"]`, `[data-testid="daily-progress"]`).
- **Root cause of navigation text selectors:** `<NcAppNavigationItem>` in `App.vue` does **not** pass `data-testid` through to the rendered DOM, so the `nav-*` testids it declares are silently dropped. Specs fall back to `text=Tasks` etc. (a code comment in `dashboard.spec.ts:74` admits this).
- **No `storageState` auth optimization** — every spec re-logs in (~3–5 s each) or relies on implicit session persistence; inconsistent across specs.
- **`phase-3-user-management.spec.ts` is legacy** (244 lines, 7 `waitForTimeout`, references 8 non-existent testids, tests the archived User-Management/group-assignment UI that no longer exists).

The release-gate views (`Dashboard`, `Settings`, `TaskManager`, `RoomDetail`, `Leaderboard`) are already well-covered with testids on root containers + key elements; the gaps are in **sub-components** (Scheduling, `Modal`, individual task rows, private-filter toggle) and the **NcAppNavigationItem passthrough**.

## 2. Strategy

Three thrusts, sequenced so each unlocks the next:

1. **Foundation:** fix the `NcAppNavigationItem` passthrough so the declared `nav-*` testids actually render; introduce a `storageState` auth setup so specs stop re-logging in; write a one-page testid convention so future components stay stable.
2. **Close the testid gaps** in the sub-components specs actually interact with, then **migrate the worst-offender specs** off `waitForTimeout`/text selectors onto `getByTestId` + auto-waiting locators (auto-waits replace explicit `waitForTimeout`).
3. **Retire the legacy spec** (rewrite or formally exclude) and add a small, optional a11y/role-check layer.

Each phase ships green independently; no phase depends on a later one.

## 3. Changes

### Frontend (`src/`)
- **`src/App.vue`:** make the `nav-*` testids survive `NcAppNavigationItem`. Preferred approach: wrap each nav item's link content in a stable element carrying `data-testid` (e.g. put `data-testid` on the inner element `NcAppNavigationItem` renders as its slot, or switch to a `role`/`aria-label` on the item and select via `getByRole('link', { name: ... })`). Decision point in Phase 1 (see Risks).
- **`src/components/Scheduling/`** (`AvailabilitySettings.vue` and siblings) + the Scheduling view root: add the testids specs already query (`scheduling-view`, `scheduling-title`, `availability-settings`, `availability-settings-toggle`, `availability-grid`, `availability-slot`, `energy-budget-settings`, `schedule-preview`, `scheduling-suggestions`, `daily-progress`). **Decision:** Scheduling is currently an unwired sub-view (per CS-PLAN-003/010). If it is not part of the active routed UI, the specs that target it (`scheduling.spec.ts`, `flex-window-scheduling.spec.ts`) should be **excluded/rewritten** rather than testids added to dead UI — confirm before implementing.
- **`src/components/Common/Modal.vue`:** add `data-testid` on the dialog surface + close/confirm buttons so modal specs can drop `.modal-content` CSS waits.
- **`src/components/Tasks/TaskList.vue`:** add a per-row `task-item` testid (and `visibility-crown-icon`, `private-filter-toggle` on the filter control) so specs stop using `room-task-list` container-only selectors.

### E2E (`tests/e2e/`)
- **`tests/e2e/fixtures/database-fixtures.ts`** (+ a new `auth.setup.ts` or `globalSetup`): introduce `storageState` — log in once per role (`admin`/`adult`/`kid`) into a saved context, reuse across specs. Removes ~3–5 s/spec × ~31 specs.
- **`browser-aware-navigation.ts`:** replace the 2000–5000 ms per-browser render-bodges with `page.waitForLoadState('networkidle')` or a `getByTestId(...).waitFor()`.
- **Worst-offender spec rewrites** (migrate `waitForTimeout` → auto-waiting locators; text → `getByTestId`/`getByRole`):
  - `snake-game.spec.ts` (32 `waitForTimeout`) — also replace `.tab-btn:nth-child()` positional selectors.
  - `flex-window-scheduling.spec.ts` (25) — resolve the Scheduling dead-UI question first.
  - `scheduling.spec.ts` (17) — same.
  - `core-functionality.spec.ts` (15 `waitForTimeout` + 10 text) — primary source of the `text=Tasks/Settings/Leaderboard/Dashboard` nav selectors; unlocked by the `App.vue` passthrough fix.
  - `task-completion*.spec.ts` (11 + 11) — wait on a visible reward/state change instead of `waitForTimeout(1000)`.
  - `gui-scratch-flow.spec.ts`, `dashboard.spec.ts`, `task-manager.spec.ts`, `phase-3-user-management.spec.ts`, `fast-core-tests.spec.ts`, `3-floor-house.spec.ts` (27 text selectors).
- **`phase-3-user-management.spec.ts`:** rewrite to exercise the **current** user-surface (`UserController` profile/title flows, Leaderboard rows) **or** formally exclude it from the release-gate set (move to `archive/2026-07/e2e-legacy/` with a documented reason). Decision point in Phase 4.

### Docs
- New `docs/standards/guidelines/e2e-testid-conventions.md` (or a section in `testing.md`): the testid naming pattern (`{view}-{element}`, dynamic suffixes allowed, every interactive element an E2E touches must carry one), the `NcAppNavigationItem` workaround, and the "prefer `getByRole`/`getByLabel`, fall back to `getByTestId`" rule.
- `docs/engineering/testing.md`: document the `storageState` auth setup + the release-gate E2E set.

## 4. Tests

- **E2E (Playwright):** the suite **is** the test. Success = the migrated specs pass reliably (0 flakes over 3 consecutive local runs across chromium/firefox/webkit) **and** the `waitForTimeout`/text-selector counts drop to near-zero on the migrated specs.
- **Jest:** `src/` changes (testids, `App.vue` wrapper, `Modal.vue`) are pure attribute additions — extend existing component specs to assert the testid renders (regression against the `NcAppNavigationItem` passthrough regression). No new behavior.
- **No PHPUnit/integration change** (frontend/E2E only).

## 5. File Manifest

```
modified: src/App.vue                                    (nav testid passthrough)
modified: src/components/Common/Modal.vue               (testids)
modified: src/components/Tasks/TaskList.vue             (task-item, crown, private toggle)
modified: src/components/Scheduling/AvailabilitySettings.vue (+ siblings)  (testids — IF active UI)
new:      tests/e2e/auth.setup.ts (or globalSetup)       (storageState)
modified: tests/e2e/fixtures/database-fixtures.ts        (loginAsUser -> reuse context)
modified: tests/e2e/fixtures/browser-aware-navigation.ts (drop render-bodges)
modified: playwright.config.ts                           (storageState + projects)
modified: tests/e2e/<worst-offender specs>               (selector migration)
archived: archive/2026-07/e2e-legacy/phase-3-user-management.spec.ts  (if excluded)
new:      docs/standards/guidelines/e2e-testid-conventions.md  (or testing.md section)
modified: docs/engineering/testing.md                    (auth setup + release-gate set)
modified: docs/backlog/bugs.md                           ([P2] -> Done)
modified: docs/backlog/phase-9-code-quality.md           (P9-E2E-002 -> Done)
new:      docs/plans/active/cs-plan-015-e2e-stabilization.md (manual new/ -> active/ move)
```

## 6. Implementation Order

1. **Phase 1 — Foundation (unblocks the rest):**
   a. Fix `App.vue` `NcAppNavigationItem` testid passthrough; add a Jest assertion that the `nav-*` testid renders.
   b. Introduce `storageState` auth setup (`auth.setup.ts` / globalSetup) + wire into `playwright.config.ts` projects; refactor `loginAsUser` to reuse the saved context.
   c. Write the testid-convention doc.
2. **Phase 2 — Close testid gaps:** add the missing testids to `Modal.vue`, `TaskList.vue` (+ `private-filter-toggle`, `visibility-crown-icon`); resolve the Scheduling dead-UI decision (add testids **or** mark specs for exclusion). Extend component specs to assert each new testid.
3. **Phase 3 — Migrate worst-offender specs:** `core-functionality` (nav selectors), `snake-game`, `task-completion*`, `gui-scratch-flow`, `dashboard`, `task-manager`, `3-floor-house`, `fast-core-tests` — replace `waitForTimeout` with auto-waiting locators, text with `getByTestId`/`getByRole`. Migrate in spec-count order; run green after each.
4. **Phase 4 — Retire legacy spec:** rewrite **or** archive `phase-3-user-management.spec.ts`; document the decision.
5. **Phase 5 — (Optional stretch) a11y layer:** add lightweight `getByRole`-based assertions on `Leaderboard`/`Settings`/`TaskManager` semantic structure.
6. **Phase 6 — Verify:** 3× clean local E2E runs (chromium+firefox+webkit), `waitForTimeout` grep near-zero on migrated specs, Jest green, ESLint 0 errors, docs + backlog updated.

## 7. Risks & Mitigations

| Risk | Likelihood | Mitigation |
|------|-----------|------------|
| `NcAppNavigationItem` attr passthrough can't be made to work | Medium | Two fallbacks ready: (a) put `data-testid` on the slotted inner element the component renders; (b) select via `getByRole('link', { name })` / `aria-label`. Phase 1a spikes both before committing to one. |
| Adding testids to Scheduling UI that is actually dead/archived | Medium | Confirm Scheduling route status in Phase 2 (grep `router.js` + `App.vue` nav). If dead, **exclude** `scheduling.spec.ts`/`flex-window-scheduling.spec.ts` with a documented reason (same precedent as the archived group-assignment spec) instead of adding testids to dead components. |
| `storageState` breaks test isolation (seed drift) | Medium | Keep per-role contexts; re-seed via `database-fixtures.ts` `seedProfile()` in `globalSetup`; document the seed dependency. Each role context is independent. |
| Migrating 176 `waitForTimeout` is large/risks regressions | Medium | Phase 3 migrates **spec-by-spec**, each verified green before the next. Auto-waiting locators are strictly more correct than fixed sleeps; no behavior change expected, only stability. |
| Legacy spec rewrite is itself a mini-feature | Low | Prefer **formal exclusion + archive** (faster, matches the archived User-Management feature) unless the user wants the rewrite. Decision flagged in Phase 4. |
| Browser-render bodges were masking real timing issues | Low | `waitForLoadState('networkidle')` + `getByTestId().waitFor()` are the standard replacements; if a genuine timing gap appears, fix it at the source (loading state) rather than re-adding a sleep. |

## 8. Acceptance Criteria

- [ ] `nav-*` testids render in the DOM (Jest assertion + E2E `getByTestId('nav-tasks')` works) — fixes the `App.vue` passthrough root cause.
- [ ] `storageState` auth is configured; specs reuse saved contexts (no per-spec form-login in the migrated set).
- [ ] `browser-aware-navigation.ts` has no `waitForTimeout` > 0 (render-bodges replaced).
- [ ] Migrated worst-offender specs use `getByTestId`/`getByRole`/`getByLabel` instead of text/positional selectors; `waitForTimeout` count on migrated specs ≈ 0.
- [ ] Every testid a release-gate spec queries exists in source (no phantom testids).
- [ ] `phase-3-user-management.spec.ts` is either rewritten against the current UI **or** archived with a documented reason.
- [ ] 3 consecutive local E2E runs (chromium + firefox + webkit) pass without flakes on the migrated set.
- [ ] testid convention documented; `testing.md` documents the auth setup + release-gate spec set.
- [ ] `bugs.md` [P2] + `phase-9-code-quality.md` P9-E2E-002 marked Done.
- [ ] Build gate green (Jest + ESLint 0 errors; E2E green on migrated set).
- [ ] `docs/backlog/ACTIVE_TASKS.md` updated.
