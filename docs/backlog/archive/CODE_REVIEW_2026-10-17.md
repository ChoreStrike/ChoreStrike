# Code Review Record — 2026-10-17

## Full Review (final) — CS-PLAN-084 family-trial UX baseline and deployment candidate `0.8.0-alpha.1`

**Outcome:** `PASS-WITH-FIXES` · **Transition:** `active/ → done/`

### Scope

| Item | Value |
|---|---|
| Owning plan | `docs/plans/done/cs-plan-084-family-trial-ux-baseline.md` (was `active/`) |
| Reviewed implementation | `2dece85..c5b890f` — all eleven CS-PLAN-084 implementation rounds (lib, src, tests, scripts, docker, CI), plus the plan's manifest, acceptance criteria, durable-doc integration and evidence history |
| Reviewed round 12 | uncommitted pre-deployment round on `chore/pre-deployment-0.8.0-alpha.1` (separate worktree from `master` `993e563`): version bump, CHANGELOG, TECH-TEST-004 / TECH-DOCS-006 / TECH-DOCS-004 / TECH-PKG-001, mojibake repairs |
| Index at capture | empty |
| Unstaged diff fingerprint at capture | `07901f63df5a37b1f700bba676e26b2e9bdc1ecfccdecbbd3395f17e8a87fd51` (26 paths, no untracked files) |
| Preserved unrelated changes | the main checkout on `feat/arcade-minigames` (CS-PLAN-086 draft, TECH-DOCS-005 work) — untouched; this review ran in its own worktree |
| Tracks | four read-only reviewers in parallel: correctness, architecture, security/auth/data flow, performance/quality |

### Findings

| ID | Sev | Track | Area | Issue | Status |
|----|-----|-------|------|-------|--------|
| RF-084-04 | major | architecture | `src/views/RoomDetail.vue` `overdueCount` | Header *Overdue* count still used `nextDue < now`: a task due today counted (red) from midnight, unlike the rows, RoomCard and room stats (D-084-08) | Fixed — shared `isOverdue`; regression; negative control 3 ≠ 1 |
| RF-084-05 | minor | correctness | `HoldButton.vue` hold timer | A button disabled or made busy mid-hold still emitted `confirm` | Fixed — watchers abort, timer re-checks; regression for both props; negative control fails |
| RF-084-06 | minor | quality | `taskDisplay.dueText` | "Overdue by 1 days" reachable through the one-day clamp | Fixed — singular key, DE copy, l10n regenerated (1289), regression |
| — | nit | correctness | `scripts/check-backlog.js` | `git ls-files` ran in the process cwd without `-z`: subdirectory runs and quoted non-ASCII names broke the index (and, pre-existing, the plan-ID lookup) | Fixed — all listings run at the repo root with NUL separation; verified from `docs/plans` |
| — | nit | correctness | `scripts/check-docs-encoding.js` | Emoji (`U+00F0 U+0178`) and embedded-BOM mojibake not flagged | Fixed — signature + self-test fixture; tree green |
| — | nit | correctness | `docs/release/roadmap.md` | Stale "app version is `0.1.1`" | Fixed |
| CR-084-01 | minor | correctness | `CalendarView.isOverdue` | Per-day rule ignores an open flex window | User-rescoped to `backlog.md` |
| CR-084-02 | minor | correctness | `roomTasksStore.completeRoomTask` | A joined in-flight completion drops the second call's allocation | User-rescoped to `backlog.md` |
| CR-084-03 | minor | architecture | `RoomTask::getStatus` vs `statusFor` | Single-row payloads serialize the entity status | User-rescoped to `backlog.md` |
| CR-084-04 | minor | architecture | `HoldButton.vue` | Above the ~300-line guidance | User-rescoped to `backlog.md` |

Accepted without change (nits, recorded): the claim-allocation completion block
exists in two views (within the rule of three); Dashboard expands all floors in
the view; `CompletionService` evaluates the agreed-completer check before the
cheap `late` test; the on-demand *Completed* read returns the whole house list
and re-reads on re-entry (D-084-07 accepted one extra read); room stats repeat
three primary-key lookups; `TaskList` re-sorts an already sorted page;
`dueClass`/`dueText` compute the day offset twice. None affects behavior or the
request budgets.

The security track found nothing: visibility stays server-enforced
(`visibleRoomTasks`), the takeover rule is stricter than before, client presets
are allow-listed and only filter, error toasts show only a validated code,
there is no `v-html`, the Redis test seam is validated and confined to
`docker/`, and the tooling shells out with fixed argument arrays. Coverage note:
the security and architecture reviewers did not read the test patch in detail;
the correctness reviewer covered tests and tooling.

### Decisions recorded (operator, 2026-10-17)

App version `0.8.0-alpha.1`; final Full review; this candidate's full gate
counts as CS-PLAN-080 RF-080-15's full-gate requalification; this deployment is
also the first retained-baseline adoption (recorded in CS-PLAN-080); a second
docs-only commit records the package evidence; the real-device checklist moves
to CS-PLAN-080; the four minor findings go to `backlog.md`.

### Gates (after all fixes)

| Gate | Result |
|---|---|
| Jest | 124 suites / 1807 tests |
| ESLint, Stylelint, `test:noise` | clean |
| `l10n:check-keys` | 1289 keys, POT/EN/DE exact |
| `docs:encoding`, `backlog:check` (+ self-test), `agents:check` | green |
| e2e-gate and package-smoke self-tests | passed |
| `composer cs:check` | 0 of 414 files |
| `test:runtime:check` / images | `current` (upstream 35.0.2, AIO 14.3.0 / NC 34.0.4); inventory `qualified`; both images `frozen-image-identity-match` |
| Primary row `cs082-pd17-pgsql` (NC 35.0.2.2, PHP 8.4.26, PostgreSQL 18.6) | unit 1385, integration 613; browser gate `run-nKi6vJ` PASSED 95 / 171 / 39 / 10 / 10 |
| AIO row `cs082-pd17-aio` (NC 34.0.4.1, PHP 8.4.26, PostgreSQL 18.6, Redis 8.10.2) | unit 1385, integration 613; browser gate `run-p5CXyQ` PASSED 95 / 171 / 39 / 10 / 10 |
| MariaDB 11.8.6 / SQLite 3.46.1 (NC 35.0.2) | unit 1385, integration 613 each |

All backend suites ran with `--fail-on-skipped`; both browser gates passed on
the first attempt with zero skips, failures or flakes. The package smoke needs
a clean committed HEAD and follows in the evidence commit.

### Outcome

All required tracks completed; every finding is fixed or user-rescoped, and the
plan's acceptance criteria, required tests and durable-doc integration are
complete. Plan moved to `done/`; UX-017, FEAT-UX-033..036 and TECH-RUNTIME-002
archived; TECH-TEST-005 stays open for the real-device run under CS-PLAN-080.
No deployment, adoption, tag or push.

---

## Standard B (Code) — TECH-DOCS-005 commits `b52e374` + `1ee898d`

**Outcome:** `NEEDS-FOLLOW-UP` (non-blocking) · **Plan:** N/A (backlog TECH-DOCS-005) · **Transition:** none

### Scope

| Item | Value |
|---|---|
| Reviewed commits | `b52e374` (README brand avatar, screenshots, status badge, `design.md` §4) and `1ee898d` (house-health bar, icon registration, `--cs-view-inline-start` inset in all 13 routed views, three guards, `layout-clearance.spec.ts`) — committed by a parallel session on `master` |
| Base | `master` `f7aff5d`, clean worktree; review record and backlog routing on branch `docs/review-tech-docs-005` |
| Gate evidence | already part of the `0d27d38` requalification: Jest 127/1836, backend 1385/613 on both rows, browser gates `run-I3xSLN` (primary) and `run-6jDStt` (AIO) incl. `layout-clearance` pinned in the manifest, package run `mvcfy3ro` |

### Findings

| ID | Sev | Area | Issue | Status |
|----|-----|------|-------|--------|
| CR-LAYOUT-01 | minor | `src/styles/tokens.css` | `--cs-view-inline-start` is declared on `:root`, where Nextcloud defines neither `--default-clickable-area` (`[data-theme-*]` on body) nor `--app-navigation-padding` (`.app-content`), so it is always the 50 px fallback; correct on NC 34/35, not derived as claimed. Folded in: the whole-view inset narrows phone content (16 → 50 px) where only the heading row needs clearance | Backlog — user-deferred until after the deployment (frozen candidate, no behavior difference on the target runtimes) |
| CR-NAV-01 | minor | `src/App.vue` (pre-existing) | Five navigation entries use legacy core icon classes that no longer exist and render without an icon, visible in the new README screenshots | Backlog |

### Verified non-findings

- **Correctness:** DOM order now paints the fill over its track; `Icon` imported from `Common/index.js` and registered; header gap and `min-width: 0` stop the name touching the percentage; every routed view and both reward sub-views take `padding-inline-start` from the token, including their media-query shorthands (static guard); logical `inline-start` keeps RTL correct.
- **Tests:** the HouseHealthBar paint-order and registration regressions and the repository-wide `Icon`/`Emoji` registration guard target the real stub-masking cause; `layout-clearance.spec.ts` measures 1280 and 390 px geometry and is now a required gate spec with a pinned inventory.
- **Security/privacy:** the brand SVG has no script, event handler or external reference (one internal gradient); the four public screenshots show only the seeded demo household and demo accounts; no `v-html`, no authorization or data-flow change.
- **i18n/logging:** no new user-visible string or log call.

### Outcome

No finding affects the deployed behavior; both are routed to the backlog with
the user's decision on timing. No code change, plan transition, push or
deployment.

---

## Standard A (Plan) — CS-PLAN-086 Household arcade, chore unlocks and per-game highscores

**Outcome:** `PASS-WITH-FIXES` · **Transition:** `new/ → active/`

### Scope

| Item | Value |
|---|---|
| Reviewed artifact | `docs/plans/new/cs-plan-086-household-arcade-and-chore-unlocks.md` (staged as added at review time) |
| Drafting package | backlog FEAT-ARCADE-001, FEAT-ARCADE-002, FEAT-ARCADE-003 (staged) |
| Content hash at capture | `fc713e50d0dba682b8c35855645f8a304a1d7035ca4a4a67890e48df97e42aa2` |
| Branch | `feat/arcade-minigames` (created for this plan at the operator's suggestion) |
| Base HEAD | `993e563186b77dceb57e9d543596e37925859cec` |
| Staged diff fingerprint | `2e23009d7627b4b0e6ed8793c5849ba20aadcb89a0880934efde38b0f29a0764` |
| Unstaged diff / untracked at capture | empty / none |
| Concurrent change during the review | another session added a TECH-DOCS-005 entry to `docs/backlog/ACTIVE_TASKS.md` (2026-10-16 23:52); outside the reviewed scope, preserved unstaged and not part of this review's commit |
| Owning backlog items | FEAT-ARCADE-001..003 (moved to `In Progress`, `Owning: CS-PLAN-086`) |

### Findings (20 fixed, 1 routed)

| ID | Sev | Area | Issue | Status |
|----|-----|------|-------|--------|
| A-01 | Major | §1/§5/WP-G | Claimed the arcade was undocumented; it is described in `gamification-and-rewards.md` §Arcade, `calm-core-ux.md` §Optional areas, `product-vision.md` and `docs/api/README.md` §Minigames. The manifest planned duplicate homes (`docs/concepts/arcade.md`, `docs/api/minigames.md`) | Fixed — existing sections are the integration targets |
| A-02 | Major | §1/§2/§10 | Conflict with approved CS-PLAN-070 decisions not surfaced: D7d/RF-070-09 separate arcade and chore value, D11 limits the first slice to Snake | Fixed — D-086-01 (B) and D-086-05 (A) record a scoped amendment; ADR-019 planned |
| A-03 | Major | Header/§6/§7 | No sequencing against the CS-PLAN-080 family evidence: a chore-coupled arcade in the trial build would confound the calm-core evidence | Fixed — D-086-07 (A): merge only after the 084 final review and the close of the 080 window |
| A-04 | Major | §3.2/§3.3 | Daily opening and free-day rule had no concrete read owner ("identified in WP-B") and duplicated a day count | Fixed by removal — D-086-01 = B drops the daily coupling (the review had identified `DailyCapacityService::evaluateDays()` as the owner) |
| A-05 | Medium | §3.3 | Optional `durationMs` plausibility is bypassed by simply omitting the field | Fixed — `durationMs` required (D-086-04) |
| A-06 | Medium | §3.3 | A save-time daily check would reject games finished after midnight | Fixed by removal with D-086-01 = B; saves enforce the permanent unlock only |
| A-07 | Medium | §4 | E2E seed "through the registry" would ship a test hook in the production bundle (browser gates run the built package) | Fixed — deterministic openings by game design; seeded RNG only as a Jest function parameter |
| A-08 | Minor | §3.5 | Parity guard via an id list exported by a PHP test is cross-language coupling | Fixed — catalog-driven UI (server ∩ registry, mismatch logged) plus per-side pinned id lists |
| A-09 | Minor | §3.1 | `useGameEffects` would copy `useCelebration`'s opt-in gate sync | Fixed — sync extracted and shared |
| A-10 | Minor | §3.2/§3.4 | Requirement copy was count-dependent; the project has no `n()` usage and the l10n checker owns only two manual plural branches | Fixed — plural-free copy (thresholds ≥ 3), rule for any future branch recorded |
| A-11 | Minor | §3.3/§5 | `lib/Service/Arcade/` sub-namespace contradicts the flat `lib/Service/` layout | Fixed — flat placement |
| A-12 | Minor | §5 | `tests/unit/store/minigame.spec.js` listed as modified but does not exist; spec deletion instead of rename; archive month hard-coded | Fixed |
| A-13 | Nit | §1 | Citation `ArcadeSection.vue:59-63` | Fixed — `60-63` |
| A-14 | Nit | §1 | S2/S6 wording overstated (page-wide capture; "unavoidable") | Fixed — precise wording |
| A-15 | Minor | §4/§8 | Missing criteria/tests: existing `snake` scores preserved, bundle budget, owner-switch E2E, `limit` regressions | Fixed |
| A-16 | Minor (pre-existing) | `docs/api/README.md` §Minigames | Documented the retired Coin minting (`coinsEarned`), `boostsUsed`, `/minigames/coins`, `/minigames/boosts`, boost purchase and a 5000 cap; code has six score routes and `MAX_SCORE = 100000` | Fixed in this review — section rewritten to the shipped scores-only API, verified against `appinfo/routes.php`, `MinigameController`, `MinigameService`, `MinigameScoreMapper` |
| A-17 | Minor (pre-existing) | `gamification-and-rewards.md:134-135` | "Playable 2048/Memory prototypes" — the prototypes were retired with the WP3 cutover | Fixed in this review |
| A-18 | Nit | Header | Effort L understated the sum of its own WP estimates | Fixed — XL |
| A-19 | Minor | §3.3 | Planned `arcade_gate_enabled` column as NOT NULL boolean, contradicting the nullable-boolean convention (`Version1000Date20260224000000.php:45-46`) | Fixed, then obsolete with A-20 |
| A-20 | Major | §3.3/§4/§5 | The owner-switch migration would have been the first schema step on the protected production install; no harness proves an app-build upgrade on populated data (P1 = same-build recreate, P2 = Nextcloud patch upgrade), and the manifest pointed at a non-existent `tests/integration/Migration/` | Fixed — D-086-02 follow-up: typed `arcade_gate_enabled` flag in the `RewardConfigService` registry, no schema change |
| RF-086-01 | Minor (pre-existing) | `MinigameController` reads | Client `limit` reaches `setMaxResults()` unclamped (whole board for large values, `LIMIT -n` for negative ones) | Routed — plan §9, resolved in WP-A |

No security-sensitive finding: RF-086-01 is membership-gated and house-scoped
and exposes no data beyond the member's own house board.

### Key verification performed during the review

- Backend contract: routes (`appinfo/routes.php:204-209`), score column and
  `mgs_hgs` index, caps, house-row lock order, erasure deletion by raw UID.
- Attribution: `WorkShareMapper::sumValidSelectedByWallet()` and
  `findSelectedSharesBetweenByHouse()` filter `undone_at IS NULL` and the
  selected allocation revision per wallet; `CompletedTaskMapper::countByUserOnDate()`
  counts undone rows and only the actor; the regular completion path sets
  `work_date` from the house-local day of completion
  (`CompletionService.php:394-397`), while owner catch-up commands carry an
  explicit `workDate` — irrelevant after D-086-01 removed the daily coupling.
- `DailyCapacityService::evaluateDays()` exposes per-member planned and
  completed energy, pause and availability (owner of the daily rule before
  D-086-01 removed it).
- `RewardConfigService` is the registry for scalar app config with no HTTP
  writer; one house per instance (`HouseService::getOrCreateHouse()`);
  `requireHouseOwner()` exists.
- Preservation harness: `scripts/test-runtime-preservation.ps1` P1/P2/P3 cover
  same-build persistence, Nextcloud patch upgrades and recovery, not app-build
  upgrades; `deployment.md` requires real populated-baseline upgrades for new
  schema steps.
- All seven Snake defects confirmed in `SnakeGame.vue`.
- `npm run backlog:check`, `npm run docs:encoding`, `git diff --check` and the
  docs/scripts Jest suites — green after all fixes.

### Gates not run, with reason

PHPUnit, the Playwright browser gate and `npm run build` were not run: this
round changed only Markdown planning, backlog, concept and API documents — no
code, string catalogue or adapter source. The plan's §4 gate list binds at
implementation time.

### Decisions recorded

D-086-01 progression-only unlocks (operator chose B against the recommended
A), D-086-02 typed app-config owner flag (follow-up after A-20), D-086-03
original names, D-086-04 ceilings plus required duration, D-086-05 all five
games, D-086-06 finished-chore count 0/3/10/25/50, D-086-07 branch-only until
the 084 final review and the 080 family evidence window — one batched round
plus one follow-up, recorded in the plan's §10.

---

## Standard B (interim) — CS-PLAN-086 WP-A arcade shell, registry, per-game store and Crumb Snake

**Outcome:** `PASS-WITH-FIXES` · **Transition:** none (interim; plan stays `active/`)

### Scope

| Item | Value |
|---|---|
| Owning plan | `docs/plans/active/cs-plan-086-household-arcade-and-chore-unlocks.md` (WP-A) |
| Branch / base HEAD | `feat/arcade-minigames` (worktree pinned) @ `315f40872d95590044cb075c39cc8a0ceec81a6b` (rebased onto `master` `8747f4d` on 2026-10-17: `d9eb433`, the WP-A commit `ebc3c30`; again onto `37bcd5c`: `cdf1fc7`, `ce81102`) |
| Index at capture | 4 paths staged by the implementation (`git rm` of `ArcadeSection.vue`, `SnakeGame.vue`, `SnakeLeaderboard.vue`; `git mv` of `SnakeLeaderboard.spec.js` → `GameLeaderboard.spec.js`) |
| Scope fingerprint at capture | `6c1c35294aad8100b665b5b207763693835a42746867321167404dc4b5b48767` (SHA-256 over staged binary diff `1d27cc43…0616`, unstaged binary diff `8f5a80c1…fcd7c` and the sorted untracked paths with content hashes `41240979…fc0d`); 29 modified tracked paths, 28 untracked paths |
| Reviewed | `lib/` (ArcadeCatalog, ImplausibleScoreException, MinigameService, MinigameController), `src/` (Minigames shell/registry/games/snake, composables, store, effect gates, sound, ArcadeView), all touched PHPUnit/Jest/Playwright tests, l10n, durable docs, plan, backlog and ACTIVE_TASKS |

### Findings

| ID | Sev | Area | Issue | Status |
|----|-----|------|-------|--------|
| R086A-01 | minor | `GameShell.vue` | `GameLeaderboard` rendered inside the `role="application"` region: screen readers in application mode could not browse the board dialog | Fixed — rendered next to the region; regression in `GameShell.spec.js` (`closest('[role="application"]')` is null) |
| R086A-02 | minor | `ArcadeCatalog.vue` | The "Scores could not be loaded." note followed any minigame store error, so a failed save inside a game (e.g. 429) showed a false load failure on the catalog | Fixed — the note follows the stats load only (`loadFailed`); regression in `ArcadeCatalog.spec.js`, negative control fails with the old condition |
| R086A-03 | nit | `GameShell.vue` `draw()` | One render-input object allocated per frame, against the plan's "no per-frame allocations in hot paths" | Fixed — one view object rebuilt on layout |
| R086A-04 | nit | `tests/unit/helpers/` | Helper file named `animationFrames.js` also holds the seeded RNG | Fixed — renamed `gameTestHelpers.js` |

### Verified non-findings

- `memberRead()` maps `\InvalidArgumentException` to a verbatim 400: no read dependency (`HouseService::getOrCreateHouse`/`getInstanceId`, `AuthorizationService::assertHouseMember`, `CalendarService::getTimeZone`/`weekStart`, the score mapper, the display-name enrichment) throws it — only the service's curated "Unknown game"; same pattern as the pre-existing `saveScore` catch.
- Membership is checked before any input detail (missing `durationMs` from a non-member is a 403, unit-pinned); the house-row lock order and the daily cap are unchanged; validation runs before the cap query and the transaction.
- Plausibility arithmetic: `30 * 3 600 000` fits `int`; the catalog test pins that every ceiling is reachable within its longest round.
- Scores-only: no Coin, wallet, contribution, medal or ranking read/write added; catalog spec and E2E assert no Coin/boost surface.
- Lazy loading: the production `game-snake` chunk is 1.6 KB gzip (budget 40 KB); the arcade chunk contains no Snake logic (`cleared`/`roundRect` only in the game chunk).
- Implementation-time defects already fixed before the review: `TimezoneDayBucketingTest` used a non-catalog game id (reads now reject unknown ids → catalog id `snake`, fresh house isolates rows); the store briefly built API paths dynamically, which the static `apiContract.spec.js` guard cannot verify → literal paths restored.

### Decisions recorded

D-086-08 (operator, 2026-10-17): the kept canonical volumes had the app installed as `0.1.1` and the test entrypoint aborted on "require upgrade" after the `0.8.0-alpha.1` bump; the operator chose the recommended `down -v` of this checkout's disposable canonical stack. The missing app-upgrade path is routed out of scope as TECH-TEST-006 (`backlog.md`). Recorded in the plan's §10.

### Gates (after all fixes)

- Jest 136 suites / 1908 tests through the zero-noise gate (`npm run test:noise`).
- PHPUnit on the fresh canonical stack (NC 35.0.2, PHP 8.4.26, PostgreSQL 18.6): unit 1421, integration 614, skips forbidden. The review fixes touched no PHP.
- `npm run lint`, `npm run stylelint`, `composer cs:check` (0 of 417 files), `npm run l10n:check-keys` (1303 keys), `npm run backlog:check`, `npm run docs:encoding`, `git diff --check`.
- `npm run build`: `game-snake` chunk 1609 bytes gzip.
- Browser on the canonical stack (`test:runtime:canonical` verified after a restart from this checkout): `snake-game.spec.ts` 12/12 and `color-contrast.spec.ts` 2/2, with the arcade view and an open game in light and dark at 0 violations; auth setup 3/3.
- Not run, with reason: the full browser gate, the AIO row and the phone projects. WP-A changes no mobile-core scenario; the plan schedules the touch E2E with the first DOM game (WP-C) and the full gate on both rows for WP-G.

### Outcome

`PASS-WITH-FIXES` (interim). R086A-01..04 were fixed and the gates rerun. Nothing was routed to the plan's §9; RF-086-01 was resolved by WP-A. TECH-TEST-006 is out of scope and in `backlog.md`. The plan stays `active/` for WP-B..WP-G. This review authorizes one scoped commit on `feat/arcade-minigames`.

---

## Standard B (interim) — CS-PLAN-086 WP-B chore unlock gate and owner switch

**Outcome:** `PASS-WITH-FIXES` · **Transition:** none (interim; plan stays `active/`)

### Scope

| Item | Value |
|---|---|
| Owning plan | `docs/plans/active/cs-plan-086-household-arcade-and-chore-unlocks.md` (WP-B) |
| Branch / base HEAD | `feat/arcade-minigames` (worktree pinned) @ `ce8110212b5d251854589fc0f882f24dcf0d9f23` (rebased onto `master` `37bcd5c` before the round) |
| Index at capture | empty |
| Scope fingerprint at capture | `629aa263b59b420ffc548a1bf89741267958f46d56d8903e74c921c75511a864` (SHA-256 over the staged binary diff `e3b0c442…b855` (empty), the unstaged binary diff `fd8bc2ce…936e` and the sorted untracked paths with content hashes `db944208…3712`); 44 modified tracked paths, 10 untracked paths |
| Reviewed | `lib/` (WorkShareMapper count, ArcadeCatalog thresholds, ArcadeAccessService, GameLockedException, MinigameService, MinigameController, HouseController, RewardConfigService, TestDataService), `appinfo/routes.php`, `src/` (minigame and house stores, ArcadeCatalog, ArcadeGateSetting, Settings), every touched PHPUnit/Jest/Playwright test and the gate manifest, l10n, ADR-019, API/concept/architecture docs, plan, backlog and ACTIVE_TASKS |

### Findings

| ID | Sev | Area | Issue | Status |
|----|-----|------|-------|--------|
| R086B-01 | minor | `RewardCatalogEditor.vue` | The catalog gate's error note followed the shared `houseStore.error`, so a failed arcade-gate save (new in WP-B) — and, before, a failed name, timezone or ranking save — showed "The catalog setting could not be saved" on the same Settings page | Fixed by user decision — the note follows the gate's own save (`gateFailed`); regression in `RewardCatalogEditor.spec.js`, the negative control fails with the old condition |
| R086B-02 | nit | docs | The plan still named `getCatalog(houseId, HouseUser)`/`assertUnlocked(houseId, …)` signatures, the API section of `PUT /house/arcade-gate` omitted its `500`, and the browser proof of a full unlock had no owning WP | Fixed — plan WP-B note (membership-only signatures, `ArcadeUnlockTest` as the full-unlock proof), WP-D line (browser unlock with Sock Match's threshold 3), API `500 HOUSE_UPDATE_ERROR` |

### Verified non-findings

- Authorization: both new routes are `@NoAdminRequired` with the AppFramework CSRF check (a token-less E2E call got 412); the catalog requires membership; the gate writer checks the owner in the controller before the payload and again in `ArcadeAccessService::setGateEnabled()`; the lock is enforced server-side inside the score transaction after the house lock and the membership check, from the stored membership's wallet — no client-supplied identity.
- Count semantics: `COUNT(DISTINCT ws.completion_id)` with the selected-revision join and `undone_at IS NULL` mirrors `sumValidSelectedByWallet()`; `uniq_wshare_key` makes the `DISTINCT` defensive only; `idx_wshare_wallet` carries the filter (the ADR-019 cost claim holds). Proven on PostgreSQL by `WorkShareCountTest` (helper counted, undone and revised-away excluded) and `ArcadeUnlockTest` (unlock at ten, re-lock after an undo, board still readable).
- No schema change, no stored arcade state, no Coin/wallet/contribution/medal/ranking read or write beyond the wallet id as attribution key; `RewardConfigService::updateConfig()` can write the new key but has no HTTP caller.
- The house payload change is additive (`arcadeGateEnabled` appended after the entity fields, unit-pinned) and present on every response the store replaces its house with; `GET /house/stats` keeps its nested entity payload, which the client never stores as the house.
- The parallel-pool specs that open the gate write the household flag; the canonical gate runs the pools sequentially and every reseed restores the default, so no required scenario depends on the order. Ad-hoc concurrent runs were already unsupported because of the serial pool's reseeds.
- A failed catalog refresh keeps the previous, server-derived catalog with a neutral retry note; the retry reloads catalog and scores together.
- Bundle: `game-snake` unchanged at 1610 bytes gzip; the arcade chunk carries the shell, no game logic.

### Decisions recorded

R086B-01 (operator, 2026-10-17): fix the pre-existing shared-error coupling in `RewardCatalogEditor` now (recommended) instead of routing it to the backlog.

### Gates (after all fixes)

- Jest 137 suites / 1931 tests through the zero-noise gate (`npm run test:noise`).
- PHPUnit on a freshly provisioned canonical stack (NC 35.0.2, PHP 8.4.26, PostgreSQL 18.6): unit 1451, integration 622, skips forbidden; `composer cs:check` (0 of 423 files). The review fixes touched no PHP.
- `npm run lint`, `npm run stylelint`, `npm run l10n:check-keys` (1311 keys), `npm run backlog:check`, `npm run docs:encoding`, `npm run agents:check`, `git diff --check`, `npm run build`.
- Browser on the canonical stack (recreated with the `family` profile after the backend run): `arcade-games.spec.ts` 4/4 and `family-rewards.spec.ts` 9/9 (serial pool), `snake-game.spec.ts` 12/12 and `color-contrast.spec.ts` 2/2 (chromium), auth setup 3/3; the scenario manifest was regenerated with `--write-manifest`.
- Not run, with reason: the full browser gate, the AIO row, the MySQL/SQLite matrix and the phone projects — scheduled for WP-C (touch E2E) and WP-G (full gate on both rows, provider matrix).

### Outcome

`PASS-WITH-FIXES` (interim). R086B-01 and R086B-02 were fixed and the affected gates rerun. Nothing was routed to the plan's §9 or the backlog. The plan stays `active/` for WP-C..WP-G. This review authorizes one scoped commit on `feat/arcade-minigames`.
