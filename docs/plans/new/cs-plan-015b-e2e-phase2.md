# CS-PLAN-015b: E2E Stabilization Phase 2 — Multi-User Auth & Stale Selector Cleanup

> **Status:** New (2026-07-26)
> **Effort:** M
> **Priority:** P2
> **Depends on:** CS-PLAN-015 (Phase 1–4 shipped; this plan resolves the remaining blockers)
> **Closes:** `docs/backlog/phase-9-code-quality.md` → **P9-E2E-002b**

## 1. Problem Summary

CS-PLAN-015 shipped Phase 1–4 (testid passthrough, storageState auth, worst-offender migration, dead-spec archival) but the Docker acceptance runs (2026-07-26 evening) revealed that the E2E suite is **not green** under `--retries=0`. Best result: **87/166 passed** (chromium only). The remaining ~80 failures fall into 6 categories (B1–B6), all detailed in `cs-plan-015-e2e-stabilization.md § Acceptance Run Results`.

The single biggest blocker is **B1: `loginAsUser()` logout destroys the shared session** under `fullyParallel` execution. All four logout approaches tested in CS-PLAN-015 fail. The fix requires a fundamental architecture change: per-test `BrowserContext` instead of a global `storageState`.

## 2. Strategy

Four thrusts, each independently shippable:

1. **Fix multi-user auth (B1):** Replace global `storageState` with per-test `BrowserContext` for specs that need non-admin users. Admin-only specs keep using `storageState`.
2. **Audit and fix stale CSS selectors (B2/B3):** Compare `snake-game.spec.ts` and `task-checklist-and-features.spec.ts` selectors against the actual rendered DOM. Update or archive.
3. **Fix route/phantom issues (B4/B5/B6):** Verify `#/tasks` route, modal flow, phantom testids.
4. **Full 3-browser acceptance:** After P1–P3 are green on chromium, run all 3 browsers.

## 3. Changes

### P1: Multi-user auth refactor
- **`tests/e2e/fixtures/database-fixtures.ts`:** Rewrite `loginAsUser()` to create a **new `BrowserContext`** (via `browser.newContext()`) when the target user differs from the storageState admin. Return the context so the spec can use it. Close context after test.
- **Affected specs (~15):** `admin-only-tasks`, `private-tasks`, `overdue-task-claiming`, `multi-user-interactions`, `task-completion-full-flow`, `teamwork-rewards`, `validation`, `error-handling` (teen/friend), `gui-scratch-flow`.
- **Alternative considered:** Keep global `storageState` but add a `storageState` per role (admin.json, adult.json, kid.json). Rejected: still causes races under `fullyParallel` because `databaseManager.seed()` mutates one shared DB.

### P2: Stale CSS selector audit
- **`snake-game.spec.ts`:** Compare `.arcade-section`, `.sparks-display`, `.pause-overlay`, `.boost-shop-overlay`, `.active-boosts`, `.sparks-value` against `src/components/SnakeGame/` (or `src/views/Dashboard.vue` arcade section). Update selectors or archive tests for missing components.
- **`task-checklist-and-features.spec.ts`:** Compare `.task-item`, `button[title="Show details"]`, `.progress-bar-container`, `.checklist-item` against `src/components/Tasks/TaskList.vue`. Update or archive.
- **`3-floor-house.spec.ts`:** Debug modal open flow — verify `showAddFloorModal = true` triggers the modal correctly.

### P3: Route and phantom fixes
- **`task-manager.spec.ts`:** Verify `#/tasks` route exists in `src/router.js`. If it does, check if the component mounts `task-manager-view` testid. If the route doesn't exist, update the test URL.
- **`title-selection.spec.ts`:** Add `active-pack-tag` testid to the title pack component, or fix the assertion.
- **`room-management.spec.ts`:** Verify `create-first-floor`, `room-name-input`, `create-room-button` testids exist in source.
- **`core-functionality.spec.ts`:** Fix `stat-card` count assertion (may need `>= 1` instead of `=== 3`).

### P4: Full acceptance
- Run `npx playwright test --retries=0` (all 3 browsers) after P1–P3.
- Target: 0 failures, 0 flaky.

## 4. Acceptance Criteria

- [ ] `loginAsUser()` works reliably under `fullyParallel` without destroying shared session.
- [ ] `snake-game.spec.ts` selectors match rendered DOM (or tests archived for missing components).
- [ ] `task-checklist-and-features.spec.ts` selectors match rendered DOM (or tests archived).
- [ ] `task-manager.spec.ts` `#/tasks` route loads correctly.
- [ ] `3-floor-house.spec.ts` modal flow works.
- [ ] 3 consecutive local E2E runs (chromium + firefox + webkit) pass with `--retries=0`.
- [ ] `docs/backlog/phase-9-code-quality.md` P9-E2E-002b marked Done.

## 5. Risks

| Risk | Likelihood | Mitigation |
|------|-----------|------------|
| Per-test BrowserContext is slow (each context = new browser session) | Medium | Only create new contexts for specs that need non-admin users (~15 of ~166 tests). Admin specs keep using fast storageState. |
| Stale selectors indicate components that don't exist | Medium | Archive tests for missing components rather than fixing selectors for non-existent UI. |
| `snake-game.spec.ts` has 12 failures — may need full rewrite | Low | If >50% of assertions target non-existent elements, archive the spec and create a minimal smoke test. |
