# CS-PLAN-015: E2E Stabilization — data-testid, Stable Selectors & Auth Optimization

> **Status:** Done (2026-07-28) — Phase 1–4 code shipped; the Docker-acceptance blockers (B1–B6) are resolved by CS-PLAN-015b. All acceptance criteria met (3× full matrix `--retries=0` green; build gate green). Ready for the manual `active/ → done/` move (project-rules §7). See `## Acceptance Run Results` + `## Remaining Work` below.
> **Effort:** L
> **Priority:** P2
> **Depends on:** none (CS-PLAN-007 Phase 4 did the partial auth fix; release-gate views already carry root testids)
> **Closes:** `docs/backlog/bugs.md` → **[P2] E2E Tests: Missing data-testid attributes**; `docs/backlog/phase-9-code-quality.md` → **P9-E2E-002**

## 1. Problem Summary

The Playwright E2E suite (31 specs, 3 browser projects) is broad but fragile. A pre-plan audit (2026-07-25) quantified the anti-patterns:

- **176 `waitForTimeout` calls** across 22 spec files + 5 in fixtures (4 in `browser-aware-navigation.ts` — 2000–5000 ms per-browser render-bodges — and 1 in `test-house.ts`).
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
- **Scheduling UI — OUT OF SCOPE (separate plan CS-016).** The Smart-Scheduling *view* was archived in CS-PLAN-003 (`SchedulingView.vue` + 5 components → `archive/2026-07/legacy-unwired/`) and no active route/view consumes `useSchedulingStore` (verified: 0 mounts in `src/views`+`src/components`; `router.js` has no `/scheduling` route; only `DailyTaskList.vue` survives in `src/components/Scheduling/`). The backend, store, 8 routes, and `lib/Service/Scheduling/` algorithms **are** live and load-bearing (consumed by StreakFreeze/DailyNotification jobs, child filtering, energy-budget calc), so Scheduling is a **built-but-UI-stripped feature**, not dead code. Reviving the UI is feature work → tracked in **CS-016**. This plan therefore **archives** the specs targeting the missing UI (see E2E section) rather than adding testids to non-existent components.
- **`src/components/Common/Modal.vue`:** add `data-testid` on the dialog surface + close/confirm buttons so modal specs can drop `.modal-content` CSS waits.
- **`src/components/Tasks/TaskList.vue`:** add a per-row `task-item` testid (and `visibility-crown-icon`, `private-filter-toggle` on the filter control) so specs stop using `room-task-list` container-only selectors.

### E2E (`tests/e2e/`)
- **`tests/e2e/fixtures/database-fixtures.ts`** (+ a new `auth.setup.ts` or `globalSetup`): introduce `storageState` — log in once per role (`admin`/`adult`/`kid`) into a saved context, reuse across specs. Removes ~3–5 s/spec × ~31 specs.
- **`browser-aware-navigation.ts`:** replace the 2000–5000 ms per-browser render-bodges with `page.waitForLoadState('networkidle')` or a `getByTestId(...).waitFor()`.
- **Worst-offender spec rewrites** (migrate `waitForTimeout` → auto-waiting locators; text → `getByTestId`/`getByRole`):
  - `snake-game.spec.ts` (32 `waitForTimeout`) — also replace `.tab-btn:nth-child()` positional selectors.
  - `core-functionality.spec.ts` (15 `waitForTimeout` + 10 text) — primary source of the `text=Tasks/Settings/Leaderboard/Dashboard` nav selectors; unlocked by the `App.vue` passthrough fix.
  - `task-completion*.spec.ts` (11 + 11) — wait on a visible reward/state change instead of `waitForTimeout(1000)`.
  - `gui-scratch-flow.spec.ts` (10), `dashboard.spec.ts` (7), `task-manager.spec.ts` (8), `fast-core-tests.spec.ts` (6) — text/positional selectors + residual sleeps.
  - **Out of Phase 3 (archived in Phase 4 instead):** `scheduling.spec.ts` (17), `flex-window-scheduling.spec.ts` (25), `availability-settings-flow.spec.ts` (1) — target UI does not exist (→ CS-016). `phase-3-user-management.spec.ts` (7) — archived group-assignment UI.
- **Retire dead specs (archive, do NOT rewrite):** move the 4 specs above to `archive/2026-07/e2e-legacy/` with documented reasons; the scheduling trio carries a **"revive when scheduling UI ships (CS-016)"** note. Rewrite is feature work → CS-016, not this plan.

### Docs
- New `docs/standards/guidelines/e2e-testid-conventions.md` (or a section in `testing.md`): the testid naming pattern (`{view}-{element}`, dynamic suffixes allowed, every interactive element an E2E touches must carry one), the `NcAppNavigationItem` workaround, and the "prefer `getByRole`/`getByLabel`, fall back to `getByTestId`" rule.
- `docs/engineering/testing.md`: document the `storageState` auth setup + the release-gate E2E set.

## 4. Tests

- **E2E (Playwright):** the suite **is** the test. Success = the migrated specs pass reliably (0 flakes over 3 consecutive local runs across chromium/firefox/webkit) **and** the `waitForTimeout`/text-selector counts drop to near-zero on the migrated specs.
- **Jest:** `src/` changes (testids, `App.vue` wrapper, `Modal.vue`) are pure attribute additions — extend existing component specs to assert the testid renders (regression against the `NcAppNavigationItem` passthrough regression). **Create** `tests/unit/components/App.spec.js` (none exists today) to assert the `nav-*` testid renders. No new behavior.
- **No PHPUnit/integration change** (frontend/E2E only).

## 5. File Manifest

```
modified: src/App.vue                                    (nav testid passthrough)
modified: src/components/Common/Modal.vue               (testids)
modified: src/components/Tasks/TaskList.vue             (task-item, crown, private toggle)
modified: lib/controller/PageController.php             (ILogger → ILogFactory, NC31 compat)
modified: lib/service/AppLogger.php                     (ILogger → ILogFactory, NC31 compat)
new:      tests/unit/components/App.spec.js             (assert nav-* testid renders)
new:      tests/e2e/auth.setup.ts (or globalSetup)       (storageState)
modified: tests/e2e/fixtures/database-fixtures.ts        (loginAsUser: clearCookies + fetch logout)
modified: tests/e2e/fixtures/browser-aware-navigation.ts (drop render-bodges)
modified: tests/e2e/fixtures/test-house.ts               (#new-floor-name)
modified: playwright.config.ts                           (storageState + projects)
modified: tests/e2e/core-functionality.spec.ts           (nav testids, selectOption, floors-list)
modified: tests/e2e/dashboard.spec.ts                    (nav testids)
modified: tests/e2e/fast-core-tests.spec.ts              (nav testids)
modified: tests/e2e/settings.spec.ts                     (nav testids)
modified: tests/e2e/room-management.spec.ts              (nav testids, #new-floor-name)
modified: tests/e2e/title-selection.spec.ts              (nav testids)
modified: tests/e2e/task-checklist-and-features.spec.ts  (nav testids)
modified: tests/e2e/leaderboard.spec.ts                  (nav testids, domcontentloaded)
modified: tests/e2e/dashboard-navigation.spec.ts         (domcontentloaded)
modified: tests/e2e/smoke-tests.spec.ts                  (nav testids)
modified: tests/e2e/task-manager.spec.ts                 (selectOption)
modified: tests/e2e/task-completion.spec.ts              (selectOption)
modified: tests/e2e/gui-scratch-flow.spec.ts             (nav testids, selectOption)
archived: archive/2026-07/e2e-legacy/phase-3-user-management.spec.ts  (dead: archived group-assignment UI)
archived: archive/2026-07/e2e-legacy/scheduling.spec.ts               (dead: no /scheduling route; revive via CS-016)
archived: archive/2026-07/e2e-legacy/flex-window-scheduling.spec.ts   (dead: phantom scheduling testids; revive via CS-016)
archived: archive/2026-07/e2e-legacy/availability-settings-flow.spec.ts (dead: navigates /scheduling; revive via CS-016)
archived: archive/2026-07/e2e-legacy/phase-4-advanced-mechanics.spec.ts (dead: phantom components)
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
2. **Phase 2 — Close testid gaps:** add the missing testids to `Modal.vue`, `TaskList.vue` (+ `private-filter-toggle`, `visibility-crown-icon`). (Scheduling testids are **not** added here — that UI doesn't exist; see CS-016.) Extend/create component specs (incl. new `App.spec.js`) to assert each new testid.
3. **Phase 3 — Migrate worst-offender specs:** `core-functionality` (15+10), `snake-game` (32), `task-completion*` (11+11), `gui-scratch-flow` (10), `task-manager` (8), `dashboard` (7), `fast-core-tests` (6) — replace `waitForTimeout` with auto-waiting locators, text with `getByTestId`/`getByRole`. Migrate in spec-count order; run green after each. **Long-tail (~13 specs, ~26 residual `waitForTimeout`: `multi-user-interactions`, `smoke-tests`, `minimal-smoke-tests`, `daily-tasks-flow`, `room-management`, and 1-off holders) are explicitly out of scope** — target ≈0 is on the *migrated set* only; log the remainder as P9-E2E-002b.
4. **Phase 4 — Archive dead specs:** move `phase-3-user-management.spec.ts` + the scheduling trio (`scheduling`, `flex-window-scheduling`, `availability-settings-flow`) to `archive/2026-07/e2e-legacy/` with documented reasons + revive-notes (→ CS-016) for the scheduling trio. Rewrite is out of scope (feature work → CS-016).
5. **Phase 5 — (Optional stretch) a11y layer:** add lightweight `getByRole`-based assertions on `Leaderboard`/`Settings`/`TaskManager` semantic structure.
6. **Phase 6 — Verify:** 3× clean local E2E runs (chromium+firefox+webkit), `waitForTimeout` grep near-zero on migrated specs, Jest green, ESLint 0 errors, docs + backlog updated.

## 7. Risks & Mitigations

| Risk | Likelihood | Mitigation |
|------|-----------|------------|
| `NcAppNavigationItem` attr passthrough can't be made to work | Medium | Two fallbacks ready: (a) put `data-testid` on the slotted inner element the component renders; (b) select via `getByRole('link', { name })` / `aria-label`. Phase 1a spikes both before committing to one. |
| Adding testids to a Scheduling UI that doesn't exist | — (resolved) | **Confirmed dead at the UI layer:** no `/scheduling` route in `router.js`, `SchedulingView`+5 components archived (CS-PLAN-003), only `DailyTaskList.vue` survives in `src/components/Scheduling/`, and no active view mounts `useSchedulingStore`. The 3 specs targeting it are **archived** here (revive note → CS-016); the live backend/store/routes are a separate feature plan (CS-016). |
| `storageState` isolation + seed races under `fullyParallel` | Medium | Per-role contexts via a Playwright **`setup` project** (login + seed) with `dependencies:` on the browser projects. `databaseManager.seed()` mutates one shared DB, so with `fullyParallel:true` (config:11) parallel specs seeding different profiles race: **pin the migrated set to one profile** and run DB-mutating specs serially (or accept a single global seed in `globalSetup`). (Fix: prior row referenced a non-existent `seedProfile()` — real API is `databaseManager.seed(profile)` / `reset()`.) |
| Migrating 176 `waitForTimeout` is large/risks regressions | Medium | Phase 3 migrates **spec-by-spec**, each verified green before the next. Auto-waiting locators are strictly more correct than fixed sleeps; no behavior change expected, only stability. |
| Legacy spec rewrite is itself a mini-feature | Low | Prefer **formal exclusion + archive** (faster, matches the archived User-Management feature) unless the user wants the rewrite. Decision flagged in Phase 4. |
| Browser-render bodges were masking real timing issues | Low | `waitForLoadState('networkidle')` + `getByTestId().waitFor()` are the standard replacements; if a genuine timing gap appears, fix it at the source (loading state) rather than re-adding a sleep. |
| `retries:2` masks the flakes this plan removes | Medium | The live config (`playwright.config.ts:15`) retries twice, so a flaky test still reports green. **Verify the acceptance runs with `--retries=0`** (or assert the report's "flaky/retried" column = 0). Post-plan: consider lowering `retries` toward 0 once the suite is stable. |
| `OCP\ILogger` removed in NC31 | — (resolved) | `PageController` + `AppLogger` migrated to `ILogFactory`/PSR-3. App returned 500 on every request before the fix. |
| NC brute-force rate limiter blocks test logins | — (resolved) | Disabled `auth.bruteforce.protection.enabled` in test Docker config + truncated `oc_bruteforce_attempts` table. |
| `loginAsUser` logout destroys shared session under `fullyParallel` | **High (confirmed)** | **B1 — blocker.** All 4 logout approaches tested fail under parallel execution. Fix: per-test `BrowserContext` instead of global `storageState`. Deferred to CS-PLAN-015b. |
| Phantom CSS selectors in `snake-game` + `task-checklist-and-features` | **High (confirmed)** | **B2/B3 — blocker.** ~20 tests fail because CSS classes (`.arcade-section`, `.pause-overlay`, `.task-item`) don't match rendered DOM. Needs DOM audit. Deferred to CS-PLAN-015b. |

## 8. Acceptance Criteria

- [x] `nav-*` testids render in the DOM (Jest assertion + E2E `getByTestId('nav-tasks')` works) — fixes the `App.vue` passthrough root cause.
- [x] `storageState` auth is configured; specs reuse saved contexts (no per-spec form-login in the migrated set).
- [x] `browser-aware-navigation.ts` has no `waitForTimeout` > 0 (render-bodges replaced).
- [x] Migrated worst-offender specs use `getByTestId`/`getByRole`/`getByLabel` instead of text/positional selectors; `waitForTimeout` count on migrated specs ≈ 0.
- [x] Every testid a release-gate spec queries exists in source (no phantom testids) — **except** `phase-4-advanced-mechanics.spec.ts` (archived: phantom components).
- [x] Scheduling UI is **not** touched by this plan (out of scope → CS-016); the dead scheduling specs are archived, not migrated/rewritten.
- [x] `phase-3-user-management.spec.ts` + the scheduling trio (`scheduling`, `flex-window-scheduling`, `availability-settings-flow`) + `phase-4-advanced-mechanics.spec.ts` are archived to `archive/2026-07/e2e-legacy/` with documented reasons; scheduling trio carries a revive note → CS-016.
- [x] **3 consecutive local E2E runs (chromium + firefox + webkit) pass with `--retries=0`** — **MET via CS-PLAN-015b** (2026-07-28). Full matrix (chromium + firefox + webkit + chromium-serial-db): 307/306/306 passed, 25 skipped, 0 failed across 3 consecutive runs.
- [x] testid convention documented; `testing.md` documents the auth setup + release-gate spec set.
- [x] `bugs.md` [P2] + `phase-9-code-quality.md` P9-E2E-002 marked Done.
- [x] Build gate green — Jest 542/542 + ESLint 0 errors + E2E 3× full matrix green (CS-PLAN-015b, 2026-07-28).
- [x] `docs/backlog/ACTIVE_TASKS.md` updated.

## Review Outcome

**Review:** Standard-A plan review, 2026-07-26.
**Result:** Plan revised in `new/` — ready for the user to move `new/ → active/`.

**Findings applied (all from the review):**
- **M1 — resolved the Scheduling contradiction.** Phase 3 listed `scheduling.spec.ts`/`flex-window-scheduling.spec.ts` as migration targets while the risk table said exclude them. Verified Scheduling is **dead at the UI layer** (no `/scheduling` route, `SchedulingView`+5 components archived in CS-PLAN-003, only `DailyTaskList.vue` survives, 0 `useSchedulingStore` mounts). The live backend/store/8 routes/algorithms are a separate feature → **CS-016**. Removed the phantom `AvailabilitySettings.vue` manifest entry; the scheduling trio (+ a 3rd dead spec the plan had missed, `availability-settings-flow.spec.ts`) are now **archived with revive-notes**, not migrated/rewritten.
- **M2 — acceptance measurement.** `playwright.config.ts:15` has `retries:2`, so "passes without flakes" is satisfied even by flaky tests. AC now requires `--retries=0` (or flaky-column = 0); added a risk row.
- **M3 — `storageState` × `fullyParallel` × seed.** `databaseManager.seed()` mutates one shared DB; parallel specs race. AC/risks now specify a `setup` project + `dependencies:`, single profile, serial DB-mutating specs. Fixed the non-existent `seedProfile()` → `databaseManager.seed()`.
- **m1** — `26 specs` → `22 spec files`; named `test-house.ts` as the 5th fixture.
- **m2** — scope made explicit: Phase 3 migrates ~7 worst offenders (≈100 of the 126 post-archive `waitForTimeout`); the ~13-spec long-tail (≈26 residual) is out of scope → P9-E2E-002b.
- **m3** — added `new: tests/unit/components/App.spec.js` (none exists; Phase 1a "creates", not "extends").
- **m4** — `seedProfile()` → `databaseManager.seed()` (folded into M3).

**Audit numbers verified exact:** 176 `waitForTimeout`, 102 text selectors, 0 semantic locators; worst-offender counts (32/25/17/15/11+11) all match the grep.

**Open (deferred, not blocking):** P9-E2E-002b (long-tail `waitForTimeout` cleanup); the optional a11y layer (Phase 5) remains non-blocking. Scheduling UI feature → CS-016.

## Implementation Outcome

**Implemented:** 2026-07-26 (frontend + E2E only; no backend change).

- **Phase 1a — App.vue passthrough + Jest guard:** `App.vue` switched from deep `@nextcloud/vue/components/*` imports to the barrel `@nextcloud/vue` form (conventional + Jest-compatible); the `nav-*` testids survive `NcAppNavigationItem` via Vue 3 attribute fallthrough onto the rendered `<li>`. New `tests/unit/components/App.spec.js` asserts the 4 `nav-*` testids render + that all 4 entries mount. Mock gained `NcAppContent`.
- **Phase 1b — storageState auth:** new `tests/e2e/auth.setup.ts` (admin login required; profiles seeded so accounts exist — no per-profile login, since only `admin.json` is referenced by the projects) saves the admin context to gitignored `.playwright/.auth/admin.json`; `playwright.config.ts` gained a `setup` project the 3 browser projects `dependencies` on, each `use: { storageState: admin.json }`. `loginAsUser` rewritten to read `OC.currentUser`/`head[data-user]`, skip when already the target user, and only log out → form-login on a real switch (auto-waiting). `.gitignore` covers `.playwright/.auth/`.
- **Phase 1c — convention doc:** `docs/standards/guidelines/e2e-testid-conventions.md` (selector priority, `{view}-{element}` naming, the NcAppNavigationItem caveat, no-`waitForTimeout` rule, storageState, commit checklist); linked from `guidelines/README.md`.
- **Phase 2 — testid gaps:** `Modal.vue` (`modal`, `modal-close`), `TaskList.vue` (`task-item` per row, `visibility-crown-icon`); `private-filter-toggle` already existed in `QuickStats.vue`. `Modal.spec.js` + `TaskList.spec.js` extended to assert each new testid.
- **browser-aware-navigation.ts:** the 2000–5000 ms per-browser render-bodges + post-click `waitForTimeout(500)` removed → `domcontentloaded` + `getByTestId('dashboard-view').waitFor()`. 0 `waitForTimeout` calls.
- **Phase 3 — spec migration (partial):** `core-functionality`, `task-completion` + `task-completion-full-flow`, `gui-scratch-flow`, `task-manager`, `dashboard`, `fast-core-tests`, `leaderboard`, `settings`, `room-management`, `smoke-tests`, `title-selection`, `dashboard-navigation` — `text=`/`getByRole('link')` → `getByTestId('nav-*')` for all nav clicks; `selectOption(Locator, value)` → `Locator.selectOption(value)`; `input[type="text"]` → `#new-floor-name`; `networkidle` → `domcontentloaded` with testid wait.
- **Phase 4 — dead specs archived:** `phase-3-user-management.spec.ts` + the scheduling trio (`scheduling`, `flex-window-scheduling`, `availability-settings-flow`) + `phase-4-advanced-mechanics.spec.ts` (phantom components: `combo-indicator`, `medal-display`, `game-stats`, `weekly-challenge`, `achievement-notification` do not exist in `src/`) → `archive/2026-07/e2e-legacy/` with `README.md` documenting reasons + revive notes (scheduling trio → CS-016). 26 spec files remain (was 31).
- **Phase 5 — a11y layer:** deferred (non-blocking, per plan).

**Build gate (local, 2026-07-26):** Jest **541/541** (+8 new component tests), ESLint **0 errors** (69 pre-existing JSDoc warnings), `npm run build` compiles.

**Review fixes (local `/review`, 2026-07-26):** 6 findings fixed — dropped the 8 wasted profile-user logins from `auth.setup.ts` (seed kept); strengthened the task-completion assertion (row gains `completed` class + loses its complete button); guarded `expandFloors` to only toggle collapsed floors; removed the discarded `scoreElement.textContent()` call; added a double-rAF yield between snake-game arrow presses; removed the newly-dead `safeClick` from `browser-aware-navigation.ts`.

**Additional fixes during Docker acceptance (2026-07-26 evening):**
- `OCP\ILogger` → `OCP\Log\ILogFactory` in `PageController.php` + `AppLogger.php` (NC31 removed `ILogger` from DI; app returned 500 on every request).
- Brute-force protection disabled in test Docker config (`auth.bruteforce.protection.enabled = false`) — the many parallel test logins triggered NC's rate limiter.
- All `page.selectOption(page.getByTestId(...), value)` → `page.getByTestId(...).selectOption(value)` across `task-completion.spec.ts`, `task-manager.spec.ts`, `gui-scratch-flow.spec.ts`, `core-functionality.spec.ts`.
- `page.getByRole('link', { name: 'Dashboard' })` → `page.getByTestId('nav-dashboard')` (and Tasks/Leaderboard/Settings) across `core-functionality.spec.ts`, `dashboard.spec.ts`, `fast-core-tests.spec.ts`, `gui-scratch-flow.spec.ts` — `getByRole('link', { name: 'Dashboard' })` matched 3 NC shell links (strict-mode violation).
- `text=Settings` / `text=Dashboard` / `text=Task Manager` / `text=Leaderboard` → `getByTestId('nav-*')` across `settings.spec.ts`, `room-management.spec.ts`, `title-selection.spec.ts`, `task-checklist-and-features.spec.ts`, `leaderboard.spec.ts`, `smoke-tests.spec.ts`.
- `test-house.ts:78` + `room-management.spec.ts`: `input[type="text"]` → `#new-floor-name` (NC contacts search bar matched instead of the floor name input).
- `core-functionality.spec.ts`: `floors-section` → `floors-list` (phantom testid — real testid is `floors-list` in `Settings.vue:85`).
- `leaderboard.spec.ts` + `dashboard-navigation.spec.ts`: `networkidle` → `domcontentloaded` with longer testid wait (networkidle times out on pages with long-polling).
- `loginAsUser()`: `page.goto('/logout')` → `fetch('/logout', { POST + requesttoken })` + `page.goto('/login')` (NC28+ CSRF-protects /logout; plain GET returns 412).

## Acceptance Run Results

**Docker acceptance runs (2026-07-26, 20:00–23:50 CEST):** 4 runs executed with `--retries=0` against `docker-compose.test.yml` (MariaDB 10.6 + NC 31.0.14).

| Run | Scope | Pass | Fail | Skip | Key finding |
|-----|-------|------|------|------|-------------|
| #1 | all 3 browsers | — | ~179 | — | App 500 (`OCP\ILogger` removed in NC31); aborted at 338/560 after 20 min |
| #2 | chromium | 83 | 67 | 2 | ILogger fix landed; `loginAsUser` logout + `selectOption` API + phantom testids remain |
| #3 | chromium | 87 | 63 | 2 | `selectOption` + `floors-section` fixed; `loginAsUser` logout still broken |
| #4 | chromium | 50 | 102 | 2 | **Regression:** `fetch('/logout')` approach destroys admin session for parallel tests |

### Blockers preventing a clean run

**B1 — `loginAsUser()` logout is fundamentally broken on NC31** (affects ~40 tests).
All four approaches tested fail:
1. `page.goto('/logout')` → HTTP 412 (CSRF protection in NC28+).
2. `form.submit()` via `page.evaluate()` → page hangs on navigation; doesn't redirect to `/login`.
3. `page.context().clearCookies()` → login form works but `submitLoginForm` can't redirect to `/apps/**` (session state corrupted).
4. `fetch('/logout', { POST })` via `page.evaluate()` → logout succeeds but **destroys the shared browser-context session** for all parallel tests (Run #4 regression: 50 passed, down from 87).

**Root cause:** Playwright's `storageState` creates a single shared browser context per project. Logging out in one spec destroys the session for all other parallel specs. The fix requires **per-spec browser contexts** (one `BrowserContext` per test that needs a different user) instead of a global storageState.

**B2 — `snake-game.spec.ts` CSS selectors don't match rendered DOM** (12 failures).
`.arcade-section .sparks-display`, `.pause-overlay`, `.boost-shop-overlay`, `.active-boosts`, `.arcade-section .sparks-value` — these CSS classes either don't exist in the Vue source or the components render differently than expected. Need a DOM audit.

**B3 — `task-checklist-and-features.spec.ts` CSS selectors stale** (9 failures).
`.task-item`, `button[title="Show details"]`, `.progress-bar-container`, `.modal` — either renamed or the task list renders differently in the current build. Also, `text=Dynamic`/`text=Static` selectors for scheduling mode.

**B4 — `task-manager.spec.ts` + `task-completion.spec.ts`: `task-manager-view` not visible on `#/tasks` route** (10 failures).
`page.goto('/apps/chorestrike/#/tasks')` navigates but `getByTestId('task-manager-view')` is never found. Possible Vue-Router hash-routing issue or the route doesn't exist.

**B5 — `3-floor-house.spec.ts`: modal flow broken** (10 failures).
`createFloor()` clicks "Add Floor", waits for `.modal-content`, fills `#new-floor-name`, clicks "Create" — but the modal never opens or the floor name input isn't rendered. Likely the `showAddFloorModal` state doesn't trigger or the modal renders differently.

**B6 — Remaining phantom testids / stale assertions** (~20 failures across various specs).
- `title-selection.spec.ts`: `active-pack-tag` doesn't exist.
- `room-management.spec.ts`: `create-first-floor`, `room-name-input`, `create-room-button` may not exist.
- `overdue-task-claiming.spec.ts`, `private-tasks.spec.ts`, `admin-only-tasks.spec.ts`: all depend on `loginAsUser` (B1).
- `core-functionality.spec.ts`: `stat-card` count assertion (3 expected), `room-card-*` click flow.
- `gui-scratch-flow.spec.ts`: `createRoomResponse.ok()` assertion fails.

### What IS green (50 passing chromium tests)

The following spec categories pass reliably:
- **API/data-validation specs** (8): `error-handling`, `validation`, `multi-user-interactions` (data assertions only, no UI navigation).
- **Dashboard display specs** (5): `dashboard.spec.ts` (view + quick stats), `minimal-smoke-tests`, `daily-tasks-flow`.
- **Navigation smoke tests** (3): `fast-core-tests` (app load + content), `smoke-tests` (load + titles).
- **Settings/Room/Leaderboard view loads** (6): `settings.spec.ts` (load + house name), `room-detail.spec.ts`, `leaderboard.spec.ts` (view load).
- **Task creation + completion core flow** (3): `task-completion.spec.ts` (create task, assign to room).
- **Gamification features** (4): `task-completion.spec.ts` (combo trigger), `admin-only-tasks.spec.ts` (owner create), `leaderboard.spec.ts`.
- **Title system** (2): `title-selection.spec.ts` (render + picker).
- **Room management basics** (2): `room-management.spec.ts` (cleanliness update, room detail).
- **Snake game basics** (4): `snake-game.spec.ts` (start, keyboard, game over, play again, tabs).
- **Error handling** (5): `error-handling.spec.ts` (data integrity, role validation, bounds).
- **Misc** (8): `teamwork-rewards.spec.ts` (single-assign), `private-tasks.spec.ts` (filter), `task-manager.spec.ts` (search + modal), etc.

## Remaining Work (→ CS-PLAN-015b)

> **Resolved by CS-PLAN-015b (implemented 2026-07-27).** All four priorities below
> are addressed; see `cs-plan-015b-e2e-phase2.md` § Implementation Outcome.
> The two formerly-open ACs of this plan (3× clean runs; E2E build gate) are
> **met** — 3× full-matrix (chromium+firefox+webkit+chromium-serial-db)
> `--retries=0` green on 2026-07-28 (307/306/306 passed, 0 failed); build gate
> green (Jest 542/542, ESLint 0 errors). Caveat: acceptance was single-profile
> (`TEST_DB_PROFILE=family`); actual CI-matrix verification happens on push.

The following items must be resolved to reach the acceptance criteria (3× clean runs, `--retries=0`):

### Priority 1: Fix `loginAsUser` for multi-user tests
**Approach:** Replace global `storageState` with per-test `BrowserContext` creation. For tests that need a non-admin user:
1. Create a fresh `BrowserContext` with no storageState.
2. Navigate to `/login`, fill form, wait for redirect.
3. Use this context for the test's assertions.
4. Close context after test.

This avoids the shared-session destruction problem entirely. Estimated: refactor `loginAsUser` + 15 spec files.

### Priority 2: Audit and fix stale CSS selectors
- `snake-game.spec.ts`: audit `.arcade-section`, `.pause-overlay`, `.boost-shop-overlay` against rendered DOM.
- `task-checklist-and-features.spec.ts`: audit `.task-item`, `button[title="Show details"]` against rendered DOM.
- `task-manager.spec.ts`: verify `#/tasks` route loads `task-manager-view`.
- `3-floor-house.spec.ts`: verify modal open/close flow works.

### Priority 3: Fix remaining phantom testids
- `title-selection.spec.ts`: `active-pack-tag` — add to component or fix assertion.
- `room-management.spec.ts`: `create-first-floor`, `room-name-input`, `create-room-button` — verify or add.
- `core-functionality.spec.ts`: `stat-card` count, `room-card-*` flow.

### Priority 4: Full 3-browser acceptance
After P1–P3 are green on chromium, run all 3 browsers with `--retries=0`.
