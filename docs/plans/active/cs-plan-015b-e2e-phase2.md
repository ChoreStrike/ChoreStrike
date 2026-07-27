# CS-PLAN-015b: E2E Stabilization Phase 2 — Session-Safe Multi-User Auth, Seed-Race Containment & Acceptance

> **Status:** Active (moved `new/ → active/` 2026-07-27 per user decision; revised 2026-07-27, replaces the 2026-07-26 draft)
> **Effort:** L
> **Priority:** P2
> **Depends on:** CS-PLAN-015 (Phase 1–4 shipped; acceptance run failed — this plan resolves the blockers)
> **Blocks:** CS-016 (scheduling UI revive should start only after this lands — it reuses the multi-user auth infra and touches the same E2E surface)
> **Closes:** the two open acceptance criteria of CS-PLAN-015 (3× clean `--retries=0` runs; E2E build gate). **Does NOT close P9-E2E-002b** (long-tail `waitForTimeout` migration) — that item stays open per its own deferral wording ("once the CS-015 foundation is proven flake-free").

## 1. Problem Summary

CS-PLAN-015 shipped Phase 1–4 (testid passthrough, storageState auth, worst-offender migration, dead-spec archival), but the Docker acceptance runs (2026-07-26 evening, `--retries=0`) failed: best result **87/166** (chromium), with Run #4 regressing to **50/166** after the `fetch('/logout')` change. CS-015 documented six blocker categories (B1–B6) and deferred them here.

Plan-drafting verification (2026-07-27, source-level) sharpened the picture considerably:

- **B1 root cause is now precise.** `fetch('/logout')` invalidates the **server-side Nextcloud session** that the saved `admin.json` cookie points to. Every parallel spec reusing that storageState dies with it — one logout kills the whole worker pool. **Any logout-based user switch is doomed by design**, not by implementation detail.
- **B2/B3 ("stale CSS selectors") are mostly false positives.** The supposedly missing classes exist in source: `.arcade-section` (`ArcadeSection.vue:2`), `.pause-overlay` / `.active-boosts` (`SnakeGame.vue:20,70`), `.boost-shop-overlay` (`BoostShop.vue:2`), `.progress-bar-container` / `.checklist-item` (`TaskChecklist.vue:16,25`), `button[title="Show details"]` (`TaskList.vue:137`). The tests fail because the app never renders after the session is destroyed — collateral of B1, not selector rot. Only `.sparks-display` / `.sparks-value` are genuinely absent (likely coins rename).
- **B4 is not a routing bug.** Route `/tasks` exists (`router.js:17`), testid `task-manager-view` exists (`TaskManager.vue:7`). Timing/session fallout.
- **Per-user storageState is viable.** `TestDataService::reset()` truncates only app tables (`RESET_TABLES`), never `oc_users` — NC accounts and their sessions survive re-seeding. The draft's reason for rejecting per-role storageState ("seed races") conflated two orthogonal problems.
- **Latent, previously unaddressed: the seed race.** The `seededData` fixture re-seeds per test (`seed()` → `reset()` = DELETE of all app rows) while `fullyParallel: true` — parallel specs wipe each other's data mid-test. ~4 spec files / ~40 tests affected (`error-handling`, `validation`, `multi-user-interactions`, `task-completion-full-flow`). Likely the second-biggest flake source after B1.

The 2026-07-26 draft's core direction (per-test `BrowserContext`) was right in spirit but under-specified: 39 `loginAsUser(page, …)` call sites across 10 specs pass a `page` and expect `void`; changing the helper's return type without a fixture design would have produced a broken migration. This revised plan replaces the helper instead of morphing it.

## 2. Strategy

**Diagnose before treating — kill the root cause — contain the secondary race — fix only verified-real residue.**

1. **Phase 0 — Measure.** A serial baseline run separates real bugs from parallelism fallout *before* any selector is touched. Expectation: most of B2–B6 evaporate once B1 is gone; anything red in a serial, session-safe run is genuine.
2. **Phase 1 — Session-safe auth (kills B1 + its collateral).** Per-user `storageState` files created once in the setup project; a small context fixture for multi-user flows; the logout path is **deleted**, not fixed. Invariant: the shared admin session is never invalidated by any spec.
3. **Phase 2 — Seed-race containment.** Seed-dependent specs move into a serial project; the parallel pool never touches the seed endpoints.
4. **Phase 3 — Verified residue.** Fix only what Phases 0–2 still show red (`.sparks-display`, `active-pack-tag`, genuine timing issues).
5. **Phase 4 — Acceptance & docs.** 3× chromium → 3× all browsers, `--retries=0`, flaky column = 0; document the auth architecture.

Each phase ends with a green gate before the next begins.

## 3. Changes

E2E-infrastructure only. No backend change. `src/` changes are limited to **additive `data-testid` attributes** confirmed missing in Phase 3.

### Phase 1 — Auth architecture (B1)

- **`tests/e2e/auth.setup.ts`:** log in every `PROFILE_USERS` account once and save `.playwright/.auth/{userId}.json` (plus the existing `admin.json`). Each `setup()` test gets its own browser context, so the logins are parallel-safe. Accounts survive re-seeding (verified, see §1).
- **New `tests/e2e/fixtures/auth-contexts.ts`:**
  - `userStatePath(userId)` → `.playwright/.auth/{userId}.json`.
  - `createUserContext(browser, userId)` → `{ context, page }` from the saved storageState, for flows needing a second simultaneous user; caller closes the context (try/finally) or uses the fixture-managed variant with auto-cleanup.
  - Two consumption patterns, replacing all `loginAsUser` calls:
    1. **Single-user describe** → `test.use({ storageState: userStatePath('mom') })` at describe level; no `beforeEach` login at all.
    2. **Two users in one test** (e.g. `admin-only-tasks`, `multi-user-interactions`) → default `page` keeps its role; additional users via `createUserContext(browser, 'teen')`. In-tree precedent exists (`multi-user-interactions.spec.ts:193+` already uses `browser.newContext()`).
- **`tests/e2e/fixtures/database-fixtures.ts`:** remove `loginAsUser`, `submitLoginForm`, `currentUserId` entirely. Keep `PROFILE_USERS` / `getUserCredentials` (credentials source for setup + API-level tests).
- **Migration map (10 specs, 39 call sites):** `multi-user-interactions` (9), `admin-only-tasks` (6), `overdue-task-claiming` (5), `private-tasks` (4), `task-completion-full-flow` (4), `validation` (3), `error-handling` (3), `teamwork-rewards` (3), `settings` (1), `title-selection` (1) — the last two are admin `beforeEach` calls → delete, storageState already covers it. `gui-scratch-flow` does **not** use `loginAsUser` (it has its own inline `loginSecondary` from `test-fixtures.ts`) — a separate `createUserContext` candidate, Phase 3 or later.
- **No-logout guard:** no spec may navigate to or fetch `/logout`. Enforced by code review + a grep line in the testing docs checklist.

### Phase 2 — Seed-race containment

- **`playwright.config.ts`:** split by concern.
  - `chromium` / `firefox` / `webkit` (unchanged: `fullyParallel`, admin storageState) — **excluding** the seed-dependent set via `testIgnore`.
  - New `chromium-serial-db` project (`fullyParallel: false`, `workers: 1`, chromium only — the seed assertions are browser-agnostic data checks) running the 4 seed-dependent specs: `error-handling`, `validation`, `multi-user-interactions`, `task-completion-full-flow`.
- Alternative considered and rejected: seed-once-globally + non-destructive specs — requires rewriting inherently mutating flows (task completion) and weakens isolation.

### Phase 3 — Verified residue (only serial-reproducible failures)

- `snake-game.spec.ts`: `.sparks-display` / `.sparks-value` absent from `src/` (coins rename) → add a `data-testid` to the coins display surface per convention and select via `getByTestId`; drop the assertion only if the surface itself is gone.
- `title-selection.spec.ts`: `active-pack-tag` — check `TitlePicker.vue`; add the testid if the element exists, otherwise fix the assertion against the verified DOM. **Rule: add the testid, don't weaken the assertion.**
- `room-management.spec.ts`: verify `create-first-floor`, `room-name-input`, `create-room-button` in `Settings.vue`; add if missing.
- `core-functionality.spec.ts`: `stat-card` count + `room-card-*` flow — re-check on session-safe runs; fix only against verified DOM.
- `gui-scratch-flow.spec.ts`: `createRoomResponse.ok()` — re-check (likely seed-race fallout).
- B4 (`task-manager-view` timing) + B5 (`3-floor-house` modal): debug **only if the serial run still reproduces them**.

### Phase 4 — Acceptance & docs

- 3× consecutive chromium `--retries=0` green → 3× consecutive full matrix (chromium + firefox + webkit) `--retries=0` green; flaky/retried column = 0.
- **CI wiring (D-CI):** the E2E suite must run in CI on `master` — today it runs **nowhere** (`.forgejo/workflows/test.yml` triggers only on `main`/`develop`, and its E2E job never mounts the app into the Nextcloud service; see `bugs.md` [P1] E2E-CI item). Minimal fix: correct `test.yml` triggers + mount/enable the app + pin the Nextcloud image (currently `nextcloud:latest`), and include the `chromium-serial-db` project in the CI invocation. Folding E2E into `release-gate.yml` as a blocking job is follow-up release-test-gate work, not this plan.
- `docs/standards/guidelines/e2e-testid-conventions.md`: add the multi-user auth pattern (per-user storageState, `createUserContext`, no-logout rule).
- `docs/engineering/testing.md`: auth architecture, `chromium-serial-db` project, acceptance-run procedure.
- `docs/backlog/bugs.md`: update E2E status entries.
- `docs/backlog/phase-9-code-quality.md`: P9-E2E-002b **stays open**; remove its stale `phase-4-advanced-mechanics` reference (spec archived in CS-015).
- CS-PLAN-015: check off the two open ACs after acceptance (folder move to `done/` is manual).
- `docs/backlog/ACTIVE_TASKS.md` update.

**Explicitly out of scope:** P9-E2E-002b long-tail `waitForTimeout` migration (separate backlog item, starts after acceptance); CS-016 scheduling UI; lowering config `retries: 2` (post-acceptance decision); per-user storageState for firefox/webkit beyond what specs explicitly need.

## 4. Tests

- **E2E (Playwright) is the test.** Per-phase gates:
  - Phase 0: serial baseline documented (numbers in the plan's Implementation Outcome).
  - Phase 1: chromium `fullyParallel --retries=0` ≥ prior best (87) with **zero** "session destroyed" cascade failures.
  - Phase 2: both projects green; parallel pool contains zero seed-endpoint calls (grep).
  - Phase 4: full acceptance matrix (§8).
- **Jest:** additive testids from Phase 3 covered by extending the owning component specs (`TitlePicker.spec.js` / coins-display component). No other `src/` change.
- **No PHPUnit/integration change.**

## 5. File Manifest

```
new:      tests/e2e/fixtures/auth-contexts.ts           (userStatePath, createUserContext)
modified: tests/e2e/auth.setup.ts                       (per-user storageState logins)
modified: tests/e2e/fixtures/database-fixtures.ts       (loginAsUser + helpers removed)
modified: playwright.config.ts                          (chromium-serial-db project split)
modified: .forgejo/workflows/test.yml                    (D-CI: trigger branches + app mount + pinned NC image + serial-db project)
modified: tests/e2e/multi-user-interactions.spec.ts     (auth migration, 9 call sites)
modified: tests/e2e/admin-only-tasks.spec.ts            (auth migration, 6)
modified: tests/e2e/overdue-task-claiming.spec.ts       (auth migration, 5)
modified: tests/e2e/private-tasks.spec.ts               (auth migration, 4)
modified: tests/e2e/task-completion-full-flow.spec.ts   (auth migration, 4)
modified: tests/e2e/validation.spec.ts                  (auth migration, 3)
modified: tests/e2e/error-handling.spec.ts              (auth migration, 3)
modified: tests/e2e/teamwork-rewards.spec.ts            (auth migration, 3)
modified: tests/e2e/gui-scratch-flow.spec.ts            (no loginAsUser — inline loginSecondary → createUserContext candidate, Phase 3+)
unchanged: tests/e2e/fixtures/test-fixtures.ts          (legacy fixture; gui-scratch-flow's loginSecondary stays for now — noted for later consolidation)
modified: tests/e2e/settings.spec.ts                    (remove admin beforeEach login)
modified: tests/e2e/title-selection.spec.ts             (remove admin beforeEach login; active-pack-tag per Phase 3)
modified: tests/e2e/snake-game.spec.ts                  (sparks selectors, if Phase 3 confirms)
modified: tests/e2e/room-management.spec.ts             (phantom testids, if Phase 3 confirms)
modified: tests/e2e/core-functionality.spec.ts          (stat-card/room-card, if Phase 3 confirms)
modified: tests/e2e/3-floor-house.spec.ts               (modal flow, only if serial-reproducible)
modified: tests/e2e/task-manager.spec.ts                (only if serial-reproducible)
modified: src/components/Profile/TitlePicker.vue        (active-pack-tag testid, if confirmed missing)
modified: src/components/<coins-display>.vue            (testid, if Phase 3 confirms)
modified: tests/unit/components/<owner>.spec.js         (testid assertions for added testids)
modified: docs/standards/guidelines/e2e-testid-conventions.md  (multi-user auth pattern)
modified: docs/engineering/testing.md                   (auth architecture + serial-db project)
modified: docs/backlog/bugs.md                          (E2E status)
modified: docs/backlog/phase-9-code-quality.md          (002b stays open; stale ref removed)
modified: docs/backlog/ACTIVE_TASKS.md
modified: docs/plans/active/cs-plan-015-e2e-stabilization.md   (AC check-off after acceptance)
```

## 6. Implementation Order

1. **Phase 0 — Diagnose (no code change).** Chromium `--workers=1 --retries=0` (serial) + chromium `fullyParallel --retries=0` baselines on the current tree. Classify every failure into {auth-caused, seed-race, genuine}. Record numbers in the plan.
2. **Phase 1 — Auth.** `auth.setup.ts` per-user states → `auth-contexts.ts` fixture → migrate specs in failure-count order (multi-user-interactions → admin-only-tasks → overdue-task-claiming → private-tasks → task-completion-full-flow → validation/error-handling → teamwork-rewards → gui-scratch-flow → settings/title-selection cleanup) → remove `loginAsUser` → **gate:** chromium `fullyParallel --retries=0`, zero session cascades.
3. **Phase 2 — Seed race.** Config split; **gate:** both projects green; parallel pool grep-clean of seed calls.
4. **Phase 3 — Residue.** Fix the Phase-0-classified *genuine* failures in order of failure count; each fix verified against the rendered DOM before touching the spec.
5. **Phase 4 — Acceptance & docs.** 3× chromium → 3× full matrix `--retries=0`; docs; backlog; CS-015 AC check-off.

## 7. Risks & Mitigations

| Risk | Likelihood | Mitigation |
|------|-----------|------------|
| Per-user storageState invalid after re-seed | Low | Verified at source level (`reset()` touches only app tables, never `oc_users`); Phase 1 smoke test: seed → reuse `mom.json` → expect 200 before migrating all specs. |
| Phase 0 reveals *more* genuine failures than expected | Medium | Phase 3 is timeboxed; anything beyond spills into a new `bugs.md` entry with a deferral reason instead of ballooning this plan. |
| Serial-db project slows the suite | Medium | Only 4 spec files, chromium-only; the parallel pool (majority) is unaffected. |
| Two-user flows mis-manage context lifecycle (leaks, cross-test state) | Medium | `createUserContext` returns an owned context; try/finally close or fixture-managed auto-cleanup; review each migrated spec's role assumptions (which user asserts what). |
| NC brute-force rate limiter re-triggered by ~9 setup logins | Low | Already disabled in the test Docker config (CS-015). |
| Setup wall-time grows (~9 logins × 3–5 s) | Low | Once per run, replaces per-spec form logins; net win. |
| `test.use({ storageState })` per describe confuses roles in mixed-role files | Low | Migrate file-by-file; each describe's role is explicit at its top; code review checklist. |
 | Phase 3 adds a testid to a component that is itself legacy | Low | Verify the component is mounted by a routed view before adding (same dead-UI check as CS-015). |
 | CI E2E repair (D-CI) balloons — app mount + seed into a service container is real work | Medium | Timebox to the minimal `test.yml` fix; if the service-container approach resists, run E2E in CI exactly as done locally (`docker-compose.test.yml` up + Playwright against it) and log the cleaner design as follow-up. |

## 8. Acceptance Criteria

- [ ] No spec navigates to or fetches `/logout`; the shared admin session is never invalidated (grep guard + review).
- [ ] `loginAsUser` removed; all 39 former call sites migrated to `test.use({ storageState })` or `createUserContext`.
- [ ] Seed-dependent specs run only in `chromium-serial-db`; the parallel pool contains zero seed-endpoint calls.
- [ ] **3 consecutive chromium runs `--retries=0` green, then 3 consecutive full runs (chromium + firefox + webkit) `--retries=0` green; flaky/retried column = 0.**
- [ ] E2E runs in CI on `master` (repaired `test.yml` per D-CI or equivalent) and is green, including the `chromium-serial-db` project.
- [ ] Every Phase-0-classified *genuine* failure fixed or explicitly deferred to `bugs.md` with a reason.
- [ ] CS-PLAN-015's two open ACs (3× clean runs; E2E build gate green) checked off in its plan file.
- [ ] P9-E2E-002b remains open with corrected wording (stale `phase-4-advanced-mechanics` reference removed).
- [ ] `e2e-testid-conventions.md` + `testing.md` document the auth architecture and the serial-db project.
- [ ] Build gate green: Jest + ESLint (additive testids covered by component specs); no backend change.
- [ ] `docs/backlog/ACTIVE_TASKS.md` updated.

## Review Outcome

**Standard-A plan review, 2026-07-27.** Source-level verification of all key claims (B1 root cause, `RESET_TABLES` scope, `loginAsUser` call sites, `seededData` fixture, `browser.newContext()` precedent, `auth.setup.ts` current state, `playwright.config.ts` structure). No blocks, no majors. Plan is ready for `new/ → active/` after the minor corrections below.

| # | Severity | Finding | Resolution |
|---|----------|---------|------------|
| 1 | minor | `loginAsUser` call-site count is **39** (grep-verified), not 42. The migration map lists `gui-scratch-flow (1)` but that spec imports from `test-fixtures.ts` and has its own inline `loginSecondary` — it does **not** use `loginAsUser`. | Correct the count to 39 / 9 specs; move gui-scratch-flow out of the `loginAsUser` migration map (it's a separate `createUserContext` candidate, not a `loginAsUser` removal). |
| 2 | minor | `gui-scratch-flow.spec.ts` uses `tests/e2e/fixtures/test-fixtures.ts` (older fixture with `console.log`, Basic Auth headers). The file manifest doesn't mention `test-fixtures.ts`. If the auth unification goal covers secondary-user patterns, this file should be in scope or explicitly noted as out-of-scope. | Add `test-fixtures.ts` to the manifest (even if only to note "unchanged — gui-scratch-flow's `loginSecondary` is a Phase-3-or-later candidate for `createUserContext` adoption"). |
| 3 | nit | Phase 3 manifest uses `<coins-display>` / `<owner>` placeholders. The coins rename target is likely `SnakeGame.vue` or a scoreboard component — could be narrowed during Phase 0. | Acceptable as-is; resolve during Phase 0 classification. |

**Verified claims:**
- B1: `fetch('/logout')` at `database-fixtures.ts:201` invalidates the server-side session — confirmed.
- `RESET_TABLES` (`TestDataService.php:47-69`): only `chorestrike_*` tables, never `oc_users` — confirmed.
- `seededData` fixture re-seeds per test under `fullyParallel: true` — confirmed; 4 specs affected (error-handling, multi-user-interactions, task-completion-full-flow, validation).
- `browser.newContext()` in-tree precedent — confirmed (`multi-user-interactions.spec.ts:195-196`, `gui-scratch-flow.spec.ts:115`).
- `auth.setup.ts` currently saves only `admin.json` — confirmed.
- No overlap with active plans (CS-015 is the predecessor; CS-016 is explicitly sequenced after this plan).

## Review Outcome (2nd pass — test-pipeline context)

**Standard-A re-review, 2026-07-27** — requested as a pipeline-wide review together with CS-015 + CS-016. Full automated-test inventory via two `explore` subagents; `phpunit.xml`, `phpunit.unit.xml`, and both CI workflows verified first-hand.

**Applied to the plan body in this pass:**
- The three corrections from the first review had been recorded but never applied — now fixed: 39 call sites / 10 specs, `gui-scratch-flow` out of the `loginAsUser` map (own inline `loginSecondary`), `test-fixtures.ts` noted in the manifest.
- **New scope item D-CI (Phase 4):** finding F1 of the pipeline review — E2E currently runs in **no** CI (`test.yml` triggers on `main`/`develop` only while the repo branch is `master`; the job also never mounts the app into the Nextcloud service and pins `nextcloud:latest`). Closing CS-015's "E2E build gate" AC requires E2E to actually run on the gate's branch, so Phase 4 now includes the minimal `test.yml` repair + an AC for CI-green E2E. Also logged in `bugs.md` [P1].

**Pipeline findings routed elsewhere (not this plan's scope):**
- `bugs.md` [P2]: the CI PHPUnit unit gate runs **no** controller tests (`phpunit.unit.xml` has no Controller suite; `phpunit.xml` excludes 5 controller test files as "outdated", incl. the actively-maintained `RoomTaskControllerTest`).
- `phase-9-code-quality.md` → new **P9-E2E-003**: E2E coverage *extension* beyond stabilization (reward config, task templates, notifications, minigame economy, mobile) — sequenced after this plan + CS-016.
- `phase-9-code-quality.md` → new **P9-TEST-003**: backend unit gaps (`TemplateService`, `RewardConfigController`, `TemplateController`, `TaskSyncController`, 5 mappers, migration smoke test).
- P9-E2E-002b wording corrected ("proven flake-free in CI" referenced a CI E2E run that does not exist).
- Manual release test plan (user requirement: no release without it) → **CS-PLAN-017** drafted in `new/`.

## Implementation Outcome

**Implemented 2026-07-27.** Phases 1–4 code complete; Docker acceptance runs pending.

### Phase 1 — Auth architecture (B1)
- `auth.setup.ts`: now logs in every `PROFILE_USERS` account once and saves `.playwright/.auth/{userId}.json` (admin + mom/dad/teen/kid/grandma/friend/adult). Each setup test gets its own browser context.
- New `tests/e2e/fixtures/auth-contexts.ts`: `userStatePath(userId)` + `createUserContext(browser, userId)` → `{ context, page }`.
- All 39 `loginAsUser` call sites across 10 specs migrated:
  - `multi-user-interactions` (9) → `createUserContext` per test / two-context flows.
  - `admin-only-tasks` (6) → `createUserContext`; the two-user "non-owner cannot complete" test uses two owned contexts.
  - `overdue-task-claiming` (5) → `createUserContext`.
  - `private-tasks` (4) → `createUserContext`.
  - `task-completion-full-flow` (4) → `createUserContext`.
  - `validation` (3) → `createUserContext`.
  - `error-handling` (3) → `createUserContext`.
  - `teamwork-rewards` (3) → `test.use({ storageState: userStatePath('mom') })` (single-user describe).
  - `settings` (1) → removed admin `beforeEach` login (project storageState covers it).
  - `title-selection` (1) → removed admin `beforeEach` login.
- `loginAsUser`, `submitLoginForm`, `currentUserId` removed from `database-fixtures.ts`. `PROFILE_USERS` / `getUserCredentials` retained.
- No-logout guard: grep confirms zero `/logout` references in any spec.

### Phase 2 — Seed-race containment
- `playwright.config.ts`: chromium/firefox/webkit projects exclude the 4 seed-dependent specs via `testIgnore`. New `chromium-serial-db` project (`fullyParallel: false`, `workers: 1`, chromium only) runs `error-handling`, `validation`, `multi-user-interactions`, `task-completion-full-flow`.
- Playwright `--list` confirms 415 tests across 27 files; serial-db project picks up exactly the 4 specs.

### Phase 3 — Verified residue
- `snake-game.spec.ts`: `.sparks-display` / `.sparks-value` → genuinely absent (coins rename). Added `data-testid="coins-display"` + `data-testid="coins-value"` to `ArcadeSection.vue`; spec updated to `getByTestId`. Jest spec extended with testid assertions.
- `title-selection.spec.ts`: `active-pack-tag` testid exists in `TitlePicker.vue:37` — no change needed.
- `room-management.spec.ts`: `create-first-floor`, `room-name-input`, `create-room-button` all exist in source — no change needed.
- `core-functionality.spec.ts`: `stat-card`, `room-card-*` exist — no change needed.

### Phase 4 — Acceptance & docs
- **D-CI:** `.forgejo/workflows/test.yml` repaired: triggers on `master` (was `main`/`develop`), Nextcloud pinned to `31-apache` (was `latest`), app mounted + enabled via `docker cp` + `occ app:enable`, `chromium-serial-db` project included in the Playwright invocation.
- `e2e-testid-conventions.md` §6 rewritten: per-user storageState patterns, `createUserContext`, no-logout rule + checklist item.
- `testing.md`: auth architecture, serial-db project, CI status updated; Playwright integration example rewritten.
- `bugs.md` [P1] E2E-CI: marked Done.
- `phase-9-code-quality.md` P9-E2E-002b: stale `phase-4-advanced-mechanics` reference removed; stays open.
- `ACTIVE_TASKS.md` updated.

### Build gate
- ESLint: 0 errors (69 pre-existing JSDoc warnings).
- Jest: 542/542 pass.
- Playwright `--list`: 415 tests compile across 27 files.
- No backend change.

### Pending
- Docker acceptance runs: 3× chromium `--retries=0` → 3× full matrix `--retries=0`.
- CS-PLAN-015 AC check-off after acceptance.

## Review Outcome

**Full review (4 parallel `reviewer` subagents), 2026-07-27.** Tier = Full (>1 domain, >5 files, auth-relevant). Quality gate green: Jest 542/542, `npm run lint` (src) 0 errors / 69 pre-existing warnings. **Not committed — 2 blocks must be fixed first.** e2e TS is outside the project lint gate (`eslint --ext .js,.vue src`), so e2e findings below are not gate-breakers but are tracked.

### Blocks (must fix before acceptance)
1. **`auth.setup.ts:83-104` — per-user storageState corrupted.** The "authenticate profile users" loop reuses one `page`; after the first login `/login` bounces to `/apps`, the `if (!page.url().includes('/apps/'))` guard skips the form, and the first user's session is saved as every subsequent `{userId}.json`. All multi-user specs would silently run as the first user. **Fix:** create a fresh `browser.newContext()` + `newPage()` per user (close in `finally`), or `clearCookies()` between iterations. (Confirmed by 3 of 4 subagents; auth-relevant → not auto-fixed.)
2. **`.forgejo/workflows/test.yml:134` — `chromium-serial-db` appended on every matrix row.** Only `${{ matrix.browser }}` is installed, so firefox/webkit rows fail with a missing-Chromium error. Also wasteful (4 seed specs × every row). **Fix:** run `--project=chromium-serial-db` only on the chromium row (`${{ matrix.browser == 'chromium' && '--project=chromium-serial-db' || '' }}`), or install Chromium unconditionally.

### Auto-fixed inline (this review)
- `snake-game.spec.ts`: stale "Sparks" test name + `sparksValue` var → "Coins" / `coinsValue` (testid is `coins-value`).
- `auth-contexts.ts`: JSDoc `@param`/`@returns` added; `AUTH_DIR` exported (was duplicated in `auth.setup.ts`).
- `auth.setup.ts`: local `AUTH_DIR` removed — imported from `auth-contexts` (DRY).
- `multi-user-interactions.spec.ts`: unused `PROFILE_USERS` import removed.
- `test.yml`: seed `curl -s -o /dev/null` so the seeded JSON body (user ids/names/roles) is not written to CI logs.

### Deferred → backlog
- `TestController.php` `@PublicPage` on seed/reset/profiles + `profiles()` lacks `isTestMode()` guard + `isTestMode()` triggers on any `getenv('CI')` → pre-existing security surface (test endpoints reachable in any CI env). Logged in `docs/backlog/bugs.md`. (Not introduced by this change.)
- `createUserContext` throws raw ENOENT on a missing storageState file — add a descriptive error + `fs.existsSync` check.
- Two-user tests (`admin-only-tasks`, `multi-user-interactions`) leak the first context if the second `createUserContext` throws — nest the try/finally.
- `docker cp ${{ github.workspace }}/.` copies the whole repo (node_modules/.git) into the container — selective copy.
- `getUserCredentials` in `database-fixtures.ts` now has zero callers (dead code) — author intended to retain for API tests; confirm and remove or document.
- Consider a `userContext` Playwright fixture (auto-cleanup) to replace the 32× `try/finally` boilerplate (rule-of-three).
- e2e TS has no lint gate (`npm run lint` is `src/`-only) — consider adding `tests/e2e` to lint.

## Review Outcome (B1 resolution — verified in Docker, 2026-07-27)

**Block B1 resolved and E2E-verified.** `auth.setup.ts` "authenticate profile users" now creates a fresh `browser.newContext()` + page per user (closed in `finally`), so each `{userId}.json` is the user's own session; `loginAndSave` unchanged (its `/apps/` guard is correct again because every call starts unauthenticated). Admin + seed setups untouched; no `/logout` anywhere.

**Verification (Docker test env, `TEST_DB_PROFILE=family`, fresh `down -v` + `up -d`):**
- `--project=setup` green; every `.playwright/.auth/{userId}.json` opened in a throwaway context and identified via `GET /ocs/v2.php/cloud/user` — 7 distinct identities, each matching its filename.
- `--project=chromium-serial-db`: 39 passed / 0 failed (multi-user-interactions two-context flows included).
- `--project=chromium tests/e2e/admin-only-tasks.spec.ts --retries=0`: **8/8 passed**, including the two-user "non-owner cannot complete admin-only task" actually executing (no skip).
- Build gate: ESLint 0 errors (69 pre-existing warnings), Jest 542/542.

**Pre-existing defects that blocked B1 verification (fixed as enablers, all verified pre-existing on the unmodified tree):**
- *E2E infra:* specs call the app's regular API routes without the CSRF `requesttoken` header the frontend sets (`src/main.js`) → HTTP 412 on every `page.request` API call. Fix: `createUserContext` primes the context (loads the dashboard once, registers `OC.requestToken` as an extra header).
- *Docker env:* `Dockerfile.test` never enabled `mod_rewrite`, so NC's front controller was dead and `/apps/...` URLs fell through to the `ErrorDocument 404` handler, which silently turns POST into GET (an in-app workaround comment in `RoomController::index` documents the same symptom). Fix: `docker/apache-nextcloud.conf` front-controller vhost + `a2enmod rewrite headers` + disable the stock default site.
- *Docker env:* NC brute-force protection was enabled (the plan's risk table claimed CS-015 disabled it — it did not); the expected `adult` login failures in setup accumulate and exponentially delay all later logins until setup times out. Fix: `test-entrypoint.sh` sets `auth.bruteforce.protection.enabled=false`.
- *admin-only-tasks.spec.ts data/contract bugs:* `energyCost: 8` exceeds the domain max of 5 (`TaskService::validateTaskData`) → 400/skip (fixed to 5); hardcoded `roomId: 1` does not survive re-seeding (fixed via `floors/with-rooms`); 403 assertions now accept NC's OCS envelope (see new `bugs.md` item) while still proving denial.

**New follow-up logged:** `bugs.md` [P2] — `OCSController`-derived controllers on non-OCS routes have their 401/403 `JSONResponse`s re-wrapped by NC's `OCSMiddleware` into an OCS envelope (HTTP 200, XML by default); the frontend receives 200/XML for authorization denials.

**Still open from the Full review:** block B2 (`.forgejo/workflows/test.yml` `chromium-serial-db` on every matrix row) and all deferred backlog items — unchanged by this fix. Phase 4 acceptance (3× clean runs, full matrix) remains pending.
