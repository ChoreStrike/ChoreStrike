# CS-PLAN-051: E2E Full-Matrix Stabilization

> **Status:** Done (2026-08-26 — Standard-B review PASS + moved to `done/` 2026-08-27)
> **Effort:** M
> **Priority:** P3
> **Depends on:** none (follows CS-PLAN-050, which surfaced and logged the bug)
> **Closes:** BUG-E2E-004 (all four failure clusters + full-matrix verifiability)
> **Activation decisions signed off 2026-08-26:** D1 = ErrorState+retry,
> D2 = API-created fixtures, D3 = test-side determinism, D4 = scheduled CI job.

## Activation Decisions (sign-off required before `new/ → active/`)

- **D1 — Insights failure surface:** render the shared `ErrorState` (retry
  action) in the Fairness section when `fetchHouseUsers` failed, instead of the
  misleading "No members yet" hint (**recommended**, matches the FEAT-UX-031
  conventions landed with CS-PLAN-044) — vs. toast-only feedback — vs. leave
  as-is (not acceptable: a transient fetch failure currently renders a false
  product statement).
- **D2 — De-vacuous strategy for the two icon specs:** create the fixture task
  deterministically via the API inside the spec (task definition **plus**
  room-task link, insights-flow pattern) instead of relying on seed contents
  (**recommended**; precedent `release-visibility-roles.spec.ts:112-121`) —
  vs. extending the backend seed profile (couples CI seed to two specs).
- **D3 — Snake pause fix direction:** test-side determinism first (remove the
  time-consuming auto-wait assertion between Start and Space; press Space as
  the immediate next action) — vs. **additionally** a product-side auto-pause
  on `document.hidden` (`visibilitychange`) as a small UX hardening. Recommend
  test-side only; product auto-pause only if diagnosis shows real-user impact
  or the test cannot be made deterministic otherwise.
- **D4 — Full-matrix CI:** add a scheduled Forgejo workflow
  (`schedule:` weekly + `workflow_dispatch`, non-blocking, uploads the
  Playwright report) running all browser projects `--retries=0`
  (**recommended** — the release gate stays chromium-shaped per CS-PLAN-020
  D3, and `release-gate.yml:149-151` explicitly defers matrix folding until
  stability is proven) — vs. manual-only full-matrix runs documented in
  `testing.md`.

## 1. Problem Summary

The first full-matrix `--retries=0` run after CS-PLAN-050 (2026-08-26)
surfaced failures in four specs the plan never touched (all pre-existing per
diff; recent gates run chromium-shaped only, so firefox/webkit and cross-pool
load interactions were never exercised — BUG-E2E-004):

1. **`insights-flow.spec.ts:72`** "insights renders stats cards, fairness
   table, and activity feed" — intermittent in `chromium-serial-db`, also in
   the pre-change baseline. **Verified root mechanism (product bug, not a
   test bug):** `InsightsView.vue:117-129` runs
   `Promise.all([fetchInsights(), fetchHouseUsers()])` and swallows any error
   (catch → `logger.error` only). When `fetchHouseUsers` transiently fails
   under load, `userStore.houseUsers` stays `[]`, `members.length === 0`
   renders the `fairness-empty` hint (`InsightsView.vue:35-37`) — a false
   "No members yet — invite your family" statement for a 6-member house — and
   the spec's `member-fairness-table` assertion (`insights-flow.spec.ts:92`)
   fails. No retry, no error surface (the `insights-error` block at
   `InsightsView.vue:23-25` only covers `houseStore.error`, i.e.
   `fetchInsights`).
2. **`admin-only-tasks.spec.ts:24`** "admin-only task shows crown icon for
   owner" and **`private-tasks.spec.ts:30`** "private task shows lock icon in
   task list" — fail in firefox **and** webkit under full-matrix load, pass
   standalone. **Verified:** both specs run in all three browser projects
   (registered in no ignore list, `playwright.config.ts:13-78`) and their
   assertion bodies sit behind `if (await …count() > 0)` row guards (the
   crown check is count-guarded a second time) while no seed task name
   matches either `hasText` regex — a standalone hard failure can only come
   from the preamble
   (`waitForLoadState('networkidle', { timeout: 30000 })` at
   `admin-only-tasks.spec.ts:28` / `private-tasks.spec.ts:23`, the
   `task-manager-view` wait, or `createUserContext` → `primeCsrfToken`'s
   dashboard goto, `auth-contexts.ts:19`). `networkidle` (500 ms network
   silence) under 3-pool load is the prime suspect. Additionally both tests
   are **vacuously green** when no seed task matches their `hasText` regex —
   CS-PLAN-050 established de-vacuousing as the standing pattern.
3. **`snake-game.spec.ts:69`** "User can pause and resume game" — fails under
   full-matrix load in the non-chromium pools (webkit run 1, firefox run 2;
   once standalone in webkit, 14/15). **Verified hypothesis:** the snake
   starts at (10,10) heading right on a 20-cell grid at 150 ms/tick
   (`SnakeGame.vue:138-140,161-166`, `INITIAL_SPEED = 150`), i.e. wall death
   ≈ 1.4–1.5
   s after start (food at (15,10) accelerates slightly). The spec auto-waits
   `expect(start-overlay).not.toBeVisible()` **between** the Start click and
   the Space press — under load this window exceeds the survivable ticks,
   `gameOver` becomes true, and the pause overlay (`v-if="isPaused &&
   !gameOver"`, `SnakeGame.vue:70`) never renders.
4. **Full-matrix green is not a verifiable state** — nothing re-runs the
   matrix after fixes; whether a periodic CI job is worth it is an open
   sub-item of BUG-E2E-004.

TaskList icon facts (verified, needed for D2): rows are `{ task, roomTask }`
pairs (`TaskList.vue:68-72`) — a task definition only renders in `#/tasks`
when linked to a room task. The crown span carries
`data-testid="visibility-crown-icon"` (`TaskList.vue:114-120`); the private
lock span has **no testid** (`TaskList.vue:111-113`) and `Icon.vue:85`
renders `icon--lock` (no `data-icon` attribute), so
`private-tasks.spec.ts:38`'s `.icon-lock, svg[data-icon="lock"]` fallback
locator matches nothing — standalone that spec cannot fail on its assertion
at all; under matrix load it can hard-fail there only if a sibling-created
room-linked row (e.g. 'My Private Reminder', `private-tasks.spec.ts:56`)
matches the filter.

## 2. Strategy

Diagnose-first per failure cluster (one commit-sized step each), mirroring the
CS-PLAN-048 approach for BUG-E2E-003/001: reproduce standalone and under a
full-matrix run, fix the root cause — product fix where the product behavior
is the bug (insights error honesty), determinism fix where the spec is load-
or seed-coupled (icon specs, snake pause) — then re-run the full matrix twice
`--retries=0` to re-establish "full matrix green" as a verifiable state, and
(Decision D4) keep it observable via a scheduled non-blocking Forgejo job.

## 3. Backend / Frontend Changes

- **Frontend** (`src/`):
  - `views/InsightsView.vue`: track the `fetchHouseUsers` failure separately
    from `fetchInsights` (Promise.allSettled or sequential awaits); when the
    users fetch failed, render the shared `ErrorState`
    (`src/components/Common/ErrorState.vue`, retry + offline-aware) in the
    Fairness section instead of the `fairness-empty` hint; wire `retry` to a
    re-fetch. The stats/activity sections render from their own store state
    as today. New user-visible strings (if any beyond `ErrorState`'s
    existing defaults) via `t()` + l10n sync (D1).
  - `components/Tasks/TaskList.vue`: add
    `data-testid="visibility-lock-icon"` to the private-visibility span
    (`TaskList.vue:111-113`), mirroring the crown pattern (D2 prerequisite).
  - `components/Minigames/SnakeGame.vue`: only if D3 = product auto-pause —
    pause the game loop on `document.hidden` (`visibilitychange`), resume
    state stays explicit (no auto-resume).
- **Backend** (`lib/`): none expected. All four failures are frontend/spec
  side; if diagnosis contradicts this for a cluster, stop and re-scope the
  affected step before editing.
- **Cross-layer contracts:** none changed (no endpoint, payload, or store
  contract modifications; `user.js` already exposes `error` from
  `fetchHouseUsers`).
- **E2E infrastructure**:
  - `tests/e2e/admin-only-tasks.spec.ts` + `tests/e2e/private-tasks.spec.ts`:
    de-vacuous per D2 — owner/mom creates the fixture via API
    (`POST /tasks` with `visibilityType`, then `POST /room-tasks` with a
    roomId resolved via `floors/with-rooms`, insights-flow pattern), asserts
    the icon testid on exactly that row; `test.skip` only on non-200 fixture
    creation (release-visibility-roles precedent). Replace the load-fragile
    `waitForLoadState('networkidle')` preambles with an auto-waited
    `task-manager-view` visibility assertion (+ `task-item` wait), matching
    the CS-PLAN-050 migration idiom.
  - `tests/e2e/snake-game.spec.ts:69`: per D3 — press Space as the immediate
    next action after the Start click (drop the between-actions auto-wait
    assertion or make it non-blocking); keep the pause/resume outcome
    assertions. If still non-deterministic, escalate to the D3 product fix.
  - `tests/e2e/insights-flow.spec.ts`: no assertion change expected (the
    product fix makes the current assertion correct); optionally assert that
    no `error-state` testid is present after successful load.
  - `playwright.config.ts`: only if diagnosis attributes the icon-spec
    failures to their API mutations racing other pools — then register the
    two specs in `SEED_DEPENDENT_SPECS` (serial chromium pool) instead of
    running them in three pools.
- **CI**: new `.forgejo/workflows/full-matrix.yml` (only if D4 = yes):
  scheduled weekly + `workflow_dispatch`, reuses the release-gate Docker
  bring-up + seed steps verbatim, installs all three browsers, runs
  `npx playwright test --retries=0 --reporter=list,html`, non-blocking
  (`continue-on-error` on the test step or a dedicated summary), uploads the
  report artifact. The blocking release gate stays chromium-shaped
  (CS-PLAN-020 D3) — no change to `release-gate.yml` except (optionally) a
  pointer comment to the scheduled job.

## 4. Tests

- **Jest** (`tests/unit/views/InsightsView.spec.js`, extend): members-fetch
  failure → Fairness section renders `error-state` (+ not `fairness-empty`);
  retry click re-fetches and renders `member-fairness-table` on success;
  both-fetch-failure → `insights-error` block still renders (existing
  behavior pinned).
- **Jest** (`tests/unit/components/TaskList.spec.js`, extend): private row
  carries `visibility-lock-icon` (mirror of the existing crown pin at
  `TaskList.spec.js:173`).
- **Jest** (only if D3 product fix): `SnakeGame` pauses on
  `document.hidden` — jsdom `visibilitychange` dispatch pins loop suspension
  and `isPaused`.
- **E2E (Playwright, Docker family profile):**
  - Each fixed spec green 3× standalone `--retries=0` (project-scoped runs:
    firefox/webkit for the icon + snake specs, `chromium-serial-db` for
    insights-flow).
  - Full matrix (`npx playwright test --retries=0`, all projects) green in
    **two consecutive runs**.
  - The blocking chromium-shaped gate (`chromium` +
    `chromium-serial-db` projects) green.
- **Regression gates:** Jest / PHPUnit unit + integration / lint + stylelint /
  cs:check / l10n:check-keys / docs:encoding / test:noise — all green at the
  CS-PLAN-050 baselines (Jest 842, PHPUnit unit 885 / integration 200 with
  24 pre-existing skips).

## 5. File Manifest

```
modified: src/views/InsightsView.vue
modified: src/components/Tasks/TaskList.vue
modified: src/components/Minigames/SnakeGame.vue          (only D3 product fix)
modified: tests/unit/views/InsightsView.spec.js
modified: tests/unit/components/TaskList.spec.js
modified: tests/unit/components/SnakeGame.spec.js          (only D3 product fix; create if absent)
modified: tests/e2e/insights-flow.spec.ts
modified: tests/e2e/admin-only-tasks.spec.ts
modified: tests/e2e/private-tasks.spec.ts
modified: tests/e2e/snake-game.spec.ts
modified: playwright.config.ts                             (conditional, see §3)
new:      .forgejo/workflows/full-matrix.yml               (only D4 = yes)
modified: .forgejo/workflows/release-gate.yml              (comment pointer only, D4 = yes)
modified: l10n/en.json, l10n/de.json, l10n/templates/chorestrike.pot   (only if new strings)
modified: docs/engineering/testing.md                      (full-matrix runbook + state)
modified: docs/backlog/backlog.md, docs/backlog/ACTIVE_TASKS.md, docs/backlog/archive/2026-08.md
```

## 6. Implementation Order

1. **Baseline + reproduction:** Docker env up; run the four failing tests
   standalone per project (`--retries=0`) and one full-matrix run to capture
   the current failure signatures (traces/screenshots on).
2. **WP-A Insights (D1):** implement the failure/retry surface; extend the
   Jest specs; verify `insights-flow.spec.ts` 3× in `chromium-serial-db`.
3. **WP-B Icon specs (D2):** add the lock testid; de-vacuous + de-networkidle
   both specs; verify each 3× standalone in firefox **and** webkit (the pools
   that failed) plus chromium; decide the `playwright.config.ts`
   registration question from the diagnosis.
4. **WP-C Snake pause (D3):** apply the test-side determinism fix; verify 3×
   standalone in webkit + firefox; escalate to the product auto-pause (+
   Jest spec) only if the race persists.
5. **WP-D Matrix verification (D4):** two consecutive full-matrix
   `--retries=0` runs green; add the scheduled workflow if D4 = yes;
   document the full-matrix runbook in `testing.md`.
6. **Gate + close-out:** full blocking gate re-run; knowledge integration
   (testing.md; design.md error/empty conventions already cover WP-A — link
   the retryable fairness error); move BUG-E2E-004 to
   `archive/2026-08.md`; update ACTIVE_TASKS.md.

## 7. Risks & Mitigations

| Risk | Likelihood | Mitigation |
|------|-----------|------------|
| Full-matrix failures reproduce only under load — root cause ambiguous | Medium | Diagnose-first step 1 captures traces/screenshot evidence before any edit; one cluster per commit; standalone-green + load-green required separately |
| Insights retry re-triggers a failing fetch loop | Low | Single retry per user action (button), no automatic retry loop; failure state stable until retry |
| De-vacuous fixtures (task + room-task) mutate seed state visible to other specs | Medium | Unique probe names; if diagnosis shows cross-pool interference, move both specs to `SEED_DEPENDENT_SPECS` (§3) instead of leaving them in three pools |
| `networkidle` removal changes spec timing assumptions | Low | Replacement is the auto-waited `task-manager-view` + `task-item` assertion — strictly more robust; verified per-pool 3× |
| Snake race persists after test-side fix | Medium | D3 escalation path defined (product auto-pause + Jest pin); decision recorded, no scope creep beyond that |
| Scheduled full-matrix CI is flaky by nature (that is its job) | Medium | Non-blocking by design; report artifact upload; weekly cadence documented in testing.md |
| Scheduled workflow duplicates the release-gate Docker bring-up → the two drift apart | Low | Copy the bring-up verbatim at creation; the `release-gate.yml` pointer comment (§3) keeps the relationship visible; extract a shared composite action only on a third consumer |
| Full matrix runtime exceeds reasonable CI window | Medium | Measure in step 1/5; job is non-blocking and can be `workflow_dispatch`-only if the weekly cron proves too noisy |

## 8. Acceptance Criteria

- [x] All four BUG-E2E-004 clusters diagnosed with recorded root cause
      (plan/commit messages), each fixed in its own commit-sized step.
- [x] Insights: a failed members fetch renders the retryable error state
      (never the "No members yet" hint); Jest pins failure, retry-success,
      and both-fetch-failure paths.
- [x] Both icon specs assert their icon deterministically on an API-created
      fixture row (no vacuous conditional), without `networkidle` preambles;
      lock testid pinned in Jest.
- [x] Snake pause/resume deterministic standalone and under matrix load, or
      D3 product fix landed with its own Jest pin (test-side fix sufficed —
      single-task start+pause with bounded retry; no product change).
- [x] Full matrix (`npx playwright test --retries=0`, all projects) green in
      two consecutive runs (runs 4+5, 2026-08-26; runs 2/4/5 green overall);
      the chromium-shaped blocking gate green (184 passed / 11 pre-existing
      skips).
- [x] D4 decision recorded (yes); scheduled workflow merged (non-blocking)
      and full-matrix runbook in `docs/engineering/testing.md`.
- [x] Build gate green (`npm test` + lint + stylelint + backend tests + E2E
      blocking projects), plus noise/l10n/docs:encoding clean (Jest 847,
      PHPUnit unit 885 / integration 200 [24 pre-existing skips]).
- [x] Durable knowledge integrated (`docs/engineering/testing.md` full-matrix
      runbook + stabilization history) — plans are temporary.
- [x] BUG-E2E-004 moved to `docs/backlog/archive/2026-08.md`;
      `docs/backlog/ACTIVE_TASKS.md` updated.

## 9. Implementation Record (2026-08-26)

Diagnosis deviated from the plan's assumptions in three places; each was
re-scoped before editing and is recorded here:

1. **Cluster 1 (insights) — second failure mode found:** under load the
   *stats* fetch (`fetchInsights`) also fails transiently, rendering the
   plain-text `insights-error` block with no recovery path. The shared
   `ErrorState` + retry therefore covers **both** fetches: stats failure →
   retryable `insights-error` (new `retryInsights()`), members failure →
   retryable error state in the Fairness section (`retryHouseUsers()`).
   `insights-flow.spec.ts` additionally retries the bounded error-state
   recovery path under load before asserting the healthy render.
2. **Clusters 2a/2b (icon specs) — real root cause was a product packaging
   bug, not the preambles:** every Fluent icon (`createFluentSvgComponent`)
   rendered as an empty comment node under production bundling, so no icon
   could ever be `toBeVisible`. Two stacked causes, both fixed:
   `icons.js` used a runtime `template:` string (the esm-bundler Vue build
   ships no runtime compiler — replaced with an `h()` render), and the
   shared `@nextcloud/webpack-vue-config` asset rule (`asset/inline`, regex
   includes `svg`) matched the Fluent SVGs alongside the project's
   `svg-inline-loader` rule, so the loader's CJS output was base64-encoded
   as a data URI instead of exporting the SVG string (asset rule now
   excludes the Fluent directory). The plan's D2 work (lock testid,
   de-vacuoused fixture assertions, `networkidle` removal) landed as planned
   on top — the assertions are now genuinely load-bearing.
3. **Cluster 3 (snake) — test-side fix needed two stages:** pressing Space as
   the immediate next action (D3 primary direction) still lost the race in
   webkit and under matrix load (the Space round-trip can exceed the ~1.35 s
   wall-death window). Final shape: start click and Space keydown dispatch
   run in **one renderer task** (`page.evaluate`), with a bounded 3-attempt
   retry that clicks whichever start/play-again button is present. No
   product change; no D3 escalation to `document.hidden` auto-pause.

Additional out-of-plan work the matrix verification forced (each
plan-conform per §7's cross-pool interference mitigation):

- `task-checklist-and-features.spec.ts` moved to `SEED_DEPENDENT_SPECS` — it
  mutates seed state (checklist items + sub-task) and resolves rows via
  seed-coupled `.task-item` locators; a parallel serial-pool reseed replaced
  the expanded row mid-test under matrix load.
- Two further single-occurrence load flakes in untouched specs
  (`calendar.spec.ts` firefox, `scheduling.spec.ts:95` scheduling-owner) were
  observed during runs 3/4 and green in every other run plus solo
  `--retries=0` reruns; recorded here for the next integrity sweep, not
  backlogged as bugs without reproduction.
- `docs:encoding` gate repair: the plan-review note in
  `docs/backlog/ACTIVE_TASKS.md` contained the literal em-dash mojibake the
  CS-PLAN-050 review had removed from the script itself; rewritten to
  char-code notation.

Verification evidence: icon specs 3× firefox + 3× webkit + chromium green;
snake 3× webkit + 3× firefox + 3× heavy-load mixed runs green; insights 3×
`chromium-serial-db` green; full matrix `--retries=0` runs 2/4/5 green
(runs 4+5 consecutive, 382 passed / 25 pre-existing skips each); blocking
`chromium` + `chromium-serial-db` gate green (184 passed / 11 skips); Jest
847; lint + stylelint + noise + l10n + docs:encoding clean; PHPUnit unit
885 / integration 200 [24 pre-existing skips].

## Review Outcome (Standard B, 2026-08-27)

**PASS.** Scope: all uncommitted changes owning this plan (17 modified +
2 new files) against the shared checklist; no PHP changed → frontend gate
only (lint:fix clean, Jest 847/847, docs:encoding clean; backend Docker
tests not required for this round — no `lib/` changes).

Verified in depth: `webpack.config.js` exclusion actually matches the shared
config (exactly one `asset/inline` rule — `RULE_ASSETS`, regex includes
`svg`; the old `/\.svg$/` finder never matched it, consistent with the bug
story); `InsightsView.vue` retry paths round-trip the store contracts
(`fetchInsights` clears `houseStore.error` on entry, `usersError` is
view-local and reset per retry — no retry loop, mutually exclusive error
branches so the e2e `error-retry` locator can never go strict-mode);
`visibility-fixtures.ts` rides the CSRF-primed context (`createUserContext`
→ `setExtraHTTPHeaders`), matches the release-visibility-roles precedent;
snake spec retry is bounded and self-consistent (start/play-again selector);
at most one `error-state` testid can render per view. No security, auth,
logging, or i18n findings (no new user-visible strings; icon `innerHTML` is
local package markup, not user content).

Fixed inline (auto-fix):

- `docs/backlog/archive/2026-08.md` — 8 control-byte corruptions in the new
  BUG-E2E-004 record (escape-style `\r`/TAB/LF/BEL/VT ate word-initial
  letters: `retryInsights`, `retryHouseUsers`, `template`, `asset`,
  `visibility`, `tests`, `networkidle`, `task`, plus a trailing lone CR)
  and 1 pre-existing BEL (line 394, `availability-settings-flow`); all
  byte-repaired, rescan = 0 control bytes.

Deferred → backlog:

- **TECH-DOCS-002** (P3/S): the `docs:encoding` gate passed the corruption
  above — add a C0 control-character signature + self-test fixtures.

Manifest note: §5 omits `src/utils/icons.js`, `webpack.config.js`,
`scripts/check-docs-encoding.js`, `.gitignore`, and the new
`tests/e2e/fixtures/visibility-fixtures.ts`; icons/webpack are documented in
§9, the script repair in the plan-review note — recorded here so the
manifest gap is traceable.
