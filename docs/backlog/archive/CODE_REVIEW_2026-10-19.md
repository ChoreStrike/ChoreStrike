# Code Review Record — 2026-10-19

## Standard A - CS-PLAN-088 alpha3 feedback round

**Outcome:** `PASS-WITH-FIXES`. Documentation-only plan review; no implementation.

### Scope and capture

- Requested tier: Standard A, no subagents. Sole regular non-symlink candidate:
  `docs/plans/new/cs-plan-088-alpha3-feedback-round.md`; destination absent.
- Base HEAD `55275172ab83c38ef7ce742b9afe0a3322003f19`, initial plan blob
  `3c151ca84e8a7a18f7152021fd0c93dbab856145`, mode `100644`.
- Initial index and worktree clean, sorted untracked path set empty. Staged and
  unstaged binary diff SHA-256 each
  `e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855`.
- User chose the existing pinned `docs/alpha3-feedback-plan` worktree rather
  than a read-only review on `master`. Branch was zero ahead/behind `master`.
- Context inspected: owning backlog entries; active CS-PLAN-080; schedule/digest
  services and mapper; HoldButton tests; all token consumers; runtime-stage and
  test-runner boundaries; package builder inventory; release/retention rules;
  package engines/peers queried with `npm view`. No production or private exchange access.

### Findings

| ID | Severity | Original plan lines | Issue | Resolution |
|---|---|---|---|---|
| RF-088-01 | major | 44-46, 68-74, 248-253, 270 | Automatic date-driven deferral and pre-evidence final review could close required scope/tests; stretch was not contract-ready | Q-088-01 selects Must + Should, excludes stretch, requires user rescoping and post-evidence Full completion review |
| RF-088-02 | major | 95-99, 139-146 | Node ^22.11 is below ESLint 10 and Nextcloud config 9 engine minima; npm unspecified | Q-088-05 chooses Node ^24/npm ^11.8.2, strict minimum/current tests and compatible peer versions |
| RF-088-03 | major | 131-135, 194-195, 224 | Provisioning script has no PHPUnit execution; report ownership and clean-run closure undefined | Q-088-07 targets run-tests.js, unique reports, explicit verified targets, failure propagation and three-run bounded non-reproduction |
| RF-088-04 | major | 126-130, 191-193 | Chunked SELECT/UPDATE lacked stale-row predicates, progress contract and edge/interleaving tests | Q-088-06 approves conditional bounded updates, finite cursor/watermark progress and all-provider tests |
| RF-088-05 | major | 59-67, 85-90, 250-253 | Operation authorization and complete manual tag sign-off were not explicit; family-evidence ownership must remain independent | Q-088-03 records policy only; full sign-off and later explicit operation approvals required; no CS-PLAN-080 closure |
| RF-088-06 | minor | 122-125, 187-190, 209-232 | Manifest omitted layout consumers and precise tests | Exact 13 consumers and PHP/runner test paths; dynamic metrics/RTL/browser proof; separate review before any stretch admission |
| RF-088-07 | minor | 20, 55, 149, 262 | Seven items called eight; toolchain order inconsistent; lint-disable wording permitted suppression | Count/order corrected; rule-policy changes require approval; actual completion month owns archive entries |
| RF-088-08 | major | 139-144, 225 | Isolated package builder remains pinned to Node 20.19 despite proposed host/CI upgrade | Add docker/package-build.json, Dockerfile.package, builder npm/strict checks, regression tests and README requirements to WP-C |

All findings resolved at plan level, not claims of implemented fixes. Existing
architecture and security rules remain binding; no new endpoint, migration or
runtime service is introduced by this review. Feedback privacy and i18n/test
requirements are retained. No out-of-scope issue or new backlog item created.

### Questions and decisions

D-088-00: reuse existing pinned worktree. Q-088-01..07: all recommended options
selected through the structured tool: Must + Should, heading-only inset,
alpha.2/alpha.3 tag policy with separate authorization, deployment-dated
changelog, Node 24/npm 11, guarded digest batches, existing test-runner extension.
Alternatives and exact answers are recorded in the
[plan decision register](../../plans/active/cs-plan-088-alpha3-feedback-round.md#decisions-recorded-standard-a-operator-2026-10-19).
The initial layout question said 14 consumers; the verified manifest has 13
files (16 matches), corrected without changing the chosen behavior.

### Tests and validation

- `npm test -- --runInBand`: 155 suites / 2137 tests pass, no skipped tests.
  The intermediate unstaged lifecycle move produced four metadata-test ENOENT
  failures because `git ls-files` still named the deleted `new/` path; staging
  the reviewed move aligns the index before the final full rerun. No test is
  changed or skipped to accommodate the move.
- `npm run lint`: passes; the pre-existing Node 24 DEP0180 dependency warning
  is already owned by TECH-DEV-001, now selected for WP-C. No warning hidden.
- `npm run backlog:check`, `npm run docs:encoding`, `npm run agents:check`,
  `git diff --check`: pass.
- Jest's stale-Composer-lock and builder pull-retry messages are intentional
  negative-path fixture output (`testRuntime.spec.js`, `buildPackage.spec.js`),
  not a failed live package build or changed Composer lock.
- PHP/backend Docker, browser, production build and package qualification not
  run: no implementation/configuration changed in this docs-only Standard A.
  Those tests remain explicit requirements of the implementation/release plan.

### Documentation, backlog and finalization

Review package allowlist: old/new CS-PLAN-088 paths, `docs/plans/README.md`,
`docs/backlog/ACTIVE_TASKS.md`, `docs/backlog/backlog.md` and this record.
Selected seven items move to In Progress; RELEASE-002 assigns only WP-A/WP-D,
leaving CS-PLAN-080 family evidence and later beta onboarding independent.
FEAT-UX-015 and TECH-TEST-008 stay unassigned. No implementation item is closed.

Plan transition: `new/` to `active/`. One scoped conventional review commit on
the existing task branch; no master override, merge, push, tag, deployment or
feedback import. Implementation starts with TECH-DEV-001 under the plan's
qualification contract; this review does not execute that work.

## Full Review (final) — CS-PLAN-086 household arcade, chore unlocks and per-game highscores

**Outcome:** `PASS-WITH-FIXES` · **Transition:** `active/ → done/`, then fast-forward of `master` (D-086-10)

### Scope

| Item | Value |
|---|---|
| Owning plan | `docs/plans/done/cs-plan-086-household-arcade-and-chore-unlocks.md` (was `active/`) |
| Request | User, 2026-10-19: take on the plan's next items (WP-H: knowledge integration, plausibility tuning, full gate on both runtime rows, final review) |
| Branch / base HEAD | `feat/arcade-minigames` (main checkout, pinned, never pushed), rebased onto `master` `b13ecc9` at the start (`7225c08`..`880636c` → `f5cad76`..`519aa28`, no conflict); capture HEAD `519aa28e3af6cb573534c2deb5d87c084c7ece84` |
| Reviewed implementation | `master...519aa28`: all eight CS-PLAN-086 commits (139 files: `lib/`, `appinfo/routes.php`, `src/components/Minigames/`, store, composables, utils, styles, PHPUnit/Jest/Playwright specs, gate manifest, l10n, docs), plus the uncommitted WP-H package, the plan's manifest, acceptance criteria, decisions, durable-doc integration and evidence history |
| Index at capture | empty |
| Scope fingerprint at capture | unstaged binary diff `b4cc9ea5df4f9b16f13537d372e0c51e1f817cfd13118e73dadec25cf51b93bf` (WP-H package: Snake rule, autopilot spec, concept/ADR/API/plan edits), no untracked files |
| Tracks | four read-only reviewers in parallel: correctness, architecture, security/auth/data flow, performance/quality; every finding re-verified against the code before routing |
| Test targets | four fresh owned staged rows (`cs082-arc086h-*`) instead of the shared canonical stack, after an operator-approved Docker Desktop restart (TECH-TRANSPORT-001 grows with session age) |

### WP-H implementation in scope

- Crumb Snake plausibility (D-086-11): a shortest-path autopilot in `snakeLogic.spec.js` bounds real play from above; over seeds 1–1000 it averages at most about 17 points per second over a round of ten seconds or more and breaks the 100-point burst plus 12 per second in its luckiest rounds. The rule moves from 100 + 30 to 100 + 20 per second (`ArcadeCatalog`, `ArcadeCatalogTest`, `MinigameServiceTest`, API docs); the spec runs the six seeds closest to the rule, and a negative control at 12 per second turns it red.
- Knowledge integration: the gamification concept's "first slice supports Snake only", `ui-use-cases.md`'s Arcade row and UC-12 gap (2048/Memory prototypes that no longer exist), ADR-019's "once Spot Sprint ships", and the merge condition in CS-PLAN-080 and the plans README (D-086-10).

### Findings

| ID | Sev | Track | Area | Issue | Status |
|----|-----|-------|------|-------|--------|
| R086H-01 | minor | performance | `GameShell.vue` `finishRound` / unmount | Closing the game while its save was still running let the `round` guard pass: confetti and the milestone sound fired over the page and the live region was written after unmount | Fixed — unmount bumps `round`; regression with a pending save; negative control fails |
| R086H-02 | minor | security | `MinigameController::saveScore` | `int $score = 0` (already on `master`): a POST without `score` stored a zero row in the member's own list and daily cap; the play time was required, the score not | Fixed — `400 VALIDATION_ERROR` "Score is required" before the service; unit and integration tests; API docs; l10n |
| R086H-03 | minor | correctness | `store/minigame.js` `finishGame`, `GameShellOverlay.vue` | A save refused for a game locked again after an undo or for the daily cap showed the same "could not be saved" as a network error | Fixed — the store returns `failure` (`locked`, `limit`, `error`), the overlay names the first two in one neutral line each; store and shell tests; l10n |
| R086H-04 | minor | architecture | `games/{spots,socks,snake}/logic.js`, `games/dust/rooms.js` | Rule of three met: four direction tables, two `OPPOSITE` maps, two identical `moveCursor` and two tap checks | Fixed — `games/grid.js` (`DIRECTIONS`, `OPPOSITE`, `tappedCell`, `moveCursor`), `grid.spec.js`; snake and dust re-export the table; Dust Runner keeps its own `DIRECTION_ORDER`; all rule specs unchanged and green |
| R086H-05 | minor | architecture | `MinigameService::saveScore` | 56 lines | Fixed — the daily cap moves to `assertUnderDailyCap()`, behavior-neutral (the service tests pin it) |
| R086H-06 | minor | architecture | `GameShellOverlay.vue` | A third copy of the reduced-motion query, read once at setup, so the hint missed a live system change | Fixed — `confetti.js` exports `prefersReducedMotion()`, the overlay reads it per render; regression; negative control fails |
| R086H-07 | minor | performance | `effectsLayer.js` `drawPopups` | The popup font string was rebuilt on every frame while a popup lived ("no per-frame allocation") | Fixed — cached per cell size; spec covers a resized board |
| R086H-08 | minor | performance, correctness | `useGameEffects.js` | The header said reduced motion always wins over the motion opt-in, while the vibration followed the opt-in alone | Fixed (comment) — D-086-12: the vibration follows the Effects opt-in like the app's completion vibration; reduced motion governs visual motion |
| R086H-09 | nit | correctness | `GameShell.vue` `onKeyDown` | A held P key flipped pause and resume at key-repeat rate once focus moved to Resume | Fixed — repeats never toggle; regression; negative control fails |
| R086H-10 | nit | architecture | `TestDataService::reset` | Deleted the flag through its own `IAppConfig` with a hard-coded app name, beside the registry that owns the key | Fixed — `RewardConfigService::resetArcadeGateEnabled()`; both unit tests updated |
| R086H-11 | nit | quality | `sound.js`, `confetti.js`, `tiles/logic.js` | JSDoc named the celebration composable as the only gate caller and app start as the only audio unlock; Tile Breaker's event list missed `bounce` | Fixed |
| R086H-12 | nit | correctness | `snakeLogic.spec.js` | The replay test's second assertion was always true (the opening crumb cell is hose after the pickup); the autopilot seeds needed a re-derivation note | Fixed — compares two seeds; note added |
| R086H-13 | nit | architecture | `docs/engineering/architecture.md` | `GameShell.vue` recorded at 540 non-blank lines (506 at review, 510 after the fixes) | Fixed — row and component map (`games/grid.js`) updated |

Accepted without change (nits, recorded): `GameShell.resize()` reallocates
the canvas once more when a layout change keeps the board size — once per
layout change, not per frame, and the pinned resize-loop guard stays
untouched; the three canvas renderers compute the cell from their own `COLS`
instead of `view.cell` — equal by construction, since the registry's
`cols` are the logic's `COLS`; `ArcadeGateSetting`'s untracked 4-second
"saved" timer follows the `RewardCatalogEditor` pattern and only shortens the
note after a second save.

### Verified non-findings

- Authorization: both new routes `@NoAdminRequired` with CSRF unchanged; the gate writer checks membership and ownership in the controller and `requireHouseOwner()` again in the service; `setArcadeGateEnabled()` has no other caller and the registry has no other HTTP writer.
- The unlock cannot be bypassed: `assertUnlocked` runs inside the score transaction after the house-row lock, which completions and undos also take first; a member without a wallet counts zero; the flag is read server-side on every save.
- Input: the flag must be a real boolean, `gameId` is pattern- and catalog-checked, `score` and `durationMs` are required and bounded, read limits clamp to 1..50; `countValidSelectedByWallet` binds an int array parameter, no SQL concatenation.
- Economy separation: the wallet id is only the share attribution key; no Coin, balance, contribution, medal or ranking read or write; no cross-game score.
- Logging and errors: `AppLogger` contexts carry ids, game, score and duration only; `GameLockedException` extends `ForbiddenException` and is never logged as a rollback; 500s go through `safeMessage`; no raw `console.*`; no `v-html`.
- No test-only hook in `src/` (no seed, cheat, autoplay or `window.__` entry); `TestDataService::reset` acts only after `assertTestMode()`.
- Server and client rules agree for all five games: `durationMs` is stepped game time (never above wall time, pauses excluded); Spot Sprint's 60-second round reports about 60.0 s against 65 s; Sock Match's exact 800 is inside the burst and its untimed round inside 24 h; Tile Breaker and Dust Runner autopilots stay inside at every step; Crumb Snake as above.
- Frame loop, listeners, ResizeObserver and the window-resize fallback are released on unmount; one shared `AudioContext` and noise buffer, self-stopping nodes; logic and render steps allocate nothing per frame; the catalog is one grouped count query.
- i18n: every user-visible string in scope goes through `t()` with EN/DE entries (1333 keys).
- File sizes: only `GameShell.vue` exceeds ~300 lines, with its accepted row; `HouseController` matches its row; no leftover reference to the archived Snake components.

### Decisions recorded

- D-086-10 (operator, 2026-10-19): merge right after the final review, without waiting for the CS-PLAN-080 family evidence window (offered: keep `active/` until the window closes, recommended; close on the branch). Merging deploys nothing.
- D-086-11 (operator, 2026-10-19): Crumb Snake 100 + 20 per second, bounded by the perfect-play autopilot instead of real play (declined: keep 30 per second; leave the tuning open).
- D-086-12 (operator, 2026-10-19): the arcade vibration follows the Effects opt-in alone; only the comment changes (declined: silence it under reduced motion).
- Review fixes (operator, 2026-10-19): all four offered groups fixed now — the bug fixes, the required score, the save reasons and the refactors.
- Operations (operator, 2026-10-19): Docker Desktop restarted before the gates (done after a foreign Testcontainers run had finished; the restart-policy containers of another project came back); owned test rows removed after the evidence is recorded.

### Gates (after all fixes)

- Jest 154 suites / 2124 tests through the zero-noise gate (`npm run test:noise`); before the fixes 153 / 2116.
- `npm run lint`, `npm run stylelint`, `npm run l10n:check-keys` (1333 keys), `npm run backlog:check`, `npm run docs:encoding`, `npm run agents:check`, `git diff --check`, `composer cs:check` (0 of 423 files).
- Production build: spots 5.2, socks 4.9, snake 2.1, tiles 2.7, dust 3.7 KB gzip (budget 40 KB); no new shared chunk; no game code in the arcade chunk.
- `test:runtime:check` `current` (upstream 35.0.2, AIO 14.3.0 / NC 34.0.4, no drift), inventory `qualified`, both images `frozen-image-identity-match`.
- Four fresh owned rows from this checkout (`cs082-arc086h-pgsql` 8187, `-aio` 8188, `-mysql` 8189, `-sqlite` 8190; family seed, cron mode): PHPUnit unit 1459 and integration 623 with `--fail-on-skipped` on each — primary NC 35.0.2.2 / PHP 8.4.26 / PostgreSQL 18.6 (APCu), AIO NC 34.0.4.1 / PostgreSQL 18.6 / Redis (sessions, cache, locking), MariaDB 11.8.6, SQLite 3.46.1.
- The first four-way parallel run (before the fixes) had two unattributed SQLite integration failures (summary only); SQLite alone and two further four-way parallel runs on the final code passed — TECH-TEST-009.
- Full browser gate, primary row: `run-HyYmnO` **PASSED** first attempt — chromium 100, chromium-serial-db 179, chromium-scheduling-owner 39, mobile-chromium 15, mobile-webkit 15; zero skipped, failed or flaky.
- Full browser gate, AIO row: `run-4zMed1` **PASSED** first attempt — the same 100 / 179 / 39 / 15 / 15.
- Not run, with reason: the package smoke (`test:package:smoke`) — no deployment candidate is cut here; a release round qualifies the package.

### Outcome

`PASS-WITH-FIXES` (final). R086H-01..13 fixed with the gates above on the final
code, three nits accepted with reasons. One unattributed occurrence routed:
TECH-TEST-009. The plan moves `active/ → done/`; FEAT-ARCADE-001..003 are
archived; `master` is fast-forwarded to the review commit (D-086-10), nothing
is pushed or deployed.

## Full Review — `0.8.0-alpha.2` family-trial candidate

**Outcome:** `PASS-WITH-FIXES` · **Transition:** none (backlog items without an owning plan; CS-PLAN-080 stays `active/`, its RF-080-17 resolved)

### Scope

| Item | Value |
|---|---|
| Owning plan | N/A for TECH-TEST-006/007, CR-084-01/02/03 and UX-018 (backlog items); CS-PLAN-080 for RF-080-17 |
| Request | User, 2026-10-19: prepare the next deployment from the backlog — the candidate the family trial starts on; commits per item, local merge, package export (no push) |
| Branch / base HEAD | `fix/alpha2-trial-candidate` (worktree `~/worktrees/chorestrike-alpha2`, pinned, from `master` `af81772`); capture HEAD `a0e186ef480dca8a48853e477e579f601c1402a1` |
| Reviewed implementation | `master..a0e186e`: seven commits (41 files: `lib/` room-task read/controller/occurrence selection, `src/` store/calendar/room detail/fairness table, test entrypoint and doubles, migration guard, PHPUnit/Jest/Playwright specs, API/engineering docs, release metadata, screenshots) |
| Index / scope at capture | clean working tree, empty index |
| Tracks | four read-only reviewers in parallel: correctness, architecture, security/auth/data flow, performance/quality; every finding re-verified before routing |

### Findings

| ID | Sev | Track | Area | Issue | Status |
|----|-----|-------|------|-------|--------|
| RA2-01 | minor | correctness, architecture | `CompletionController` undo/attribute, completion replay, `SchedulePlanService::authoritativeRoomTasks` | CR-084-03 was incomplete: these responses still serialized the entity day rule (undo/attribution even in the server time zone through the raw entity) | Fixed — undo uses its fresh selection block, attribution and replay resolve the selection, schedule apply uses the batched selections; controller wiring tests and a schedule-apply integration assertion |
| RA2-02 | minor | security, performance | `RoomTaskController` create/due date | The new status read ran after the commit; a failure answered 500 for persisted work a client might repeat | Fixed — `RoomTaskReadService::committedRoomTaskPayload` degrades to the entity rule with a logged warning (create, due date, replay, attribution); unit test |
| RA2-03 | minor | performance | `RoomTaskService::getRangeTasks` | A second selection derivation (about six extra reads on every calendar, My Day side-panel and scheduling range read) only for `roomTask.status`, which no client reads | Fixed — the range row keeps the entity rule, documented in the API, docblock and archive; the per-day `late` mark stays the calendar's authority |
| RA2-04 | minor | correctness, performance | `roomTasks.js` `COMPLETION_IN_FLIGHT`, three completing views | The refusal showed "Could not complete the task" although the first completion succeeds; its English guidance was dead text and the refusal was logged twice | Fixed — `showCompletionError` shows a translated hint to check the split (POT/EN/DE), the store logs at debug; Jest |
| RA2-05 | minor | performance | controller specs | Nothing pinned which classification each payload receives | Fixed — complete, replay, due-today rows, due date (`RoomTaskControllerTest`), undo and attribution (`CompletionControllerTest`) |
| RA2-06 | minor | gate | `RoomTaskController::complete` | A replay response has no `occurrence` key; `$result['occurrence']` raised an undefined-key warning on every replayed completion (pre-existing, surfaced by the new replay test) | Fixed — `?? null` |
| RA2-07 | nit | security | `docker/test-entrypoint.sh` | The app-only `occ upgrade` relied on a core-version check that only runs with `TEST_RUNTIME_INTERNAL_VERSION` | Fixed — gated on it; provisioning case without frozen versions; the `curl` double defaults the variable |
| RA2-08 | nit | performance | `MemberFairnessTable.vue` | The new scroll box could not be scrolled by keyboard | Fixed — `role="region"`, `tabindex="0"`, accessible name from the existing "Fairness" string |
| RA2-09 | nit | correctness | `layout-clearance.spec.ts` | The phone check passed vacuously if the overflowing content never rendered | Fixed — Insights table and room task list must be visible before measuring |
| RA2-10 | nit | architecture | CS-PLAN-080 header, `design.md` | Header still listed RF-080-17 as open; the no-sideways-scroll rule lived only in the CHANGELOG and a test | Fixed |
| RA2-11 | nit | performance | `SuccessorOwnershipTest` | Two identical selection derivations per case | Fixed |
| RA2-12 | nit | architecture | `RoomTaskController` | The `occurrence.classification ?? null` lookup repeats at four call sites | Accepted — each site pairs the payload with its own block; a helper would hide which block applies |
| RA2-13 | nit | correctness | `SuccessorOwnershipTest` range case | Interval fixture only; no fixed-cadence range case with several past days | Accepted — `isMissed` is unit-tested for every predicate and applied identically to virtual and materialized entries; the integration case covers the open-window vs. missed boundary |
| RA2-14 | nit | performance | create/due-date responses | No query-count pin for the added single-row selection read | Accepted — one batched selection like the list rows, after the commit and best-effort; `WorkflowQueryBudgetTest` keeps pinning the writes |
| RA2-15 | gate | — | `RoomTaskControllerIntegrationTest` | The mocked read service returned `[]` for the new payload method (integration 624/625) | Fixed — the double delegates to `jsonSerialize` |

### Verified non-findings

- No cross-house or invisible-task exposure: the range read filters visibility before any selection or `late` computation; single-row paths re-serialize rows already authorized in the caller's house; the split comparison is a client UX guard, the server keeps validating participants.
- `isMissed` is behavior-identical to the former inline catch-up predicate; the window end stays exclusive.
- The migration guard matches all 26 pinned files on this CRLF checkout and is import-safe.

### Gates (after all fixes)

Jest 155 suites / 2137 tests, ESLint, Stylelint, production build, `test:noise`, `l10n:check-keys` (1334), `docs:encoding` (1100), `backlog:check`, `agents:check`, `composer cs:check` (0/423); Docker canonical stack (NC 35.0.2, family seed): PHPUnit unit 1467, integration 625 `--fail-on-skipped`; focused browser `layout-clearance` 3/3 plus setup. The qualification on both runtime rows, the provider suites and the package smoke follow on the reviewed candidate.

### Outcome

`PASS-WITH-FIXES`: all minor findings fixed, three nits accepted with reasons, no open question. Backlog items TECH-TEST-006/007, CR-084-01/02/03 and UX-018 were archived with their commits; RF-080-17 is resolved in CS-PLAN-080.
