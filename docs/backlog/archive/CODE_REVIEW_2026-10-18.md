# Code Review Record — 2026-10-18

## Final Standard B — CS-PLAN-070 parent closure and consolidation into CS-PLAN-080

**Outcome:** `PASS-WITH-FIXES` · **Transition:** CS-PLAN-070 `active/ → done/`; CS-PLAN-080 stays `active/`

### Scope

| Item | Value |
|---|---|
| Request | User, 2026-10-18: check whether CS-PLAN-070 and CS-PLAN-080 can be closed, otherwise consolidate them into one plan |
| Owning plan | `docs/plans/done/cs-plan-070-family-rhythm-and-reward-clarification.md` (was `active/`); consolidation target `docs/plans/active/cs-plan-080-wp9-cross-db-and-family-evidence.md` |
| Reviewed | the parent's whole record (manifest, §5 removal list, acceptance criteria, §9 findings, WP9 evidence reconciliation) against the tree, plus the durable docs it names |
| Base | `master` `55532c7`; separate worktree on `docs/consolidate-wp9-plans`, clean at capture (empty index, no unstaged or untracked paths) |
| Preserved unrelated changes | the main checkout on `feat/arcade-minigames` (CS-PLAN-086 WP-F in progress in another session) — untouched |
| Tier | Standard B (documentation-only closure; no runtime, schema, l10n or test change) |

### Closure Assessment

Neither plan could close as it stood. Both waited on the same human gates:
the operator's real-device checklist (release-test-plan §14.1, TECH-TEST-005),
HUMAN-01 adult/child comprehension, HUMAN-02 14 days of voluntary use, and the
final review. The release test plan records every scenario as `Not performed`,
and the user confirmed that no unrecorded results exist. The parent's technical
scope (WP0-WP8 via CS-PLAN-071..079, WP9 technical gates) was complete apart
from the pending whole-item verdicts.

### Verification

- All 31 tests cited by the parent's WP9 evidence reconciliation exist under
  their named classes.
- The parent's §5 removal list is absent from the tree: freeze/restock services
  and jobs, at-risk and due reminders, BoostShop/2048/Memory, TitlePicker, the
  boost-shop browser spec.
- No combo, multiplier or Coin-minting path is reachable in `lib/` (the one dead
  combo read is RF-080-17). The dashboard tagline is calm, and `HouseService` delegates the
  weekly goal to `WeeklyGoalService`.
- ADR-004 and ADR-011 mark their superseded rules; ADR-012 carries the WP1/WP2
  amendments; ADR-013..017 and `calm-core-ux.md` hold the delivered contracts.

### Findings

| ID | Sev | Area | Issue | Status |
|----|-----|------|-------|--------|
| — | minor | `docs/concepts/{energy-and-balancing,gamification-and-rewards,task-model-and-sync,ui-use-cases,product-vision}.md` | Status labels and lines still said "Not Yet Implemented", "target only" or "remain open" for behavior WP1-WP8 delivered (e.g. per-assignee capacity, unenforced overrides, no catalog API, snapshot undo) | Fixed — corrected to the delivering packages and ADRs; approved rules unchanged |
| — | minor | ADR-009, ADR-011, four concept docs | Seven links still pointed to `plans/new/cs-plan-070-…` | Fixed — all 30 links to the parent now point to `done/` |
| — | nit | `docs/README.md` | The CS-PLAN-080 line claimed "no baseline adoption", although `0.8.0-alpha.1` was adopted on 2026-10-17 | Fixed |
| — | nit | `archive/2026-09.md`, CS-PLAN-071/074 | Three older broken links (archive-relative paths, review-record anchor slugs) | Fixed |
| RF-080-17 | minor | `lib/Db/CompletedTaskMapper.php` `findRecentByUserId` | The former combo-window read has no production caller since the WP3 combo retirement; only its unit pin calls it | User-rescoped to CS-PLAN-080 §9 (next code round, Docker PHPUnit unit gate) |

### Decisions (batched, 2026-10-18)

1. Consolidate into CS-PLAN-080: the parent closes after this review, and its G3
   family evidence and the CONCEPT-001 closure move there unchanged. Alternatives
   not chosen: keep the parent as the consolidated plan; close both by moving the
   human evidence to the backlog.
2. Work in a separate worktree, make one scoped commit and merge it into `master`
   locally (no push).
3. No real-device, comprehension or observation results exist yet.
4. The dead combo-window read becomes RF-080-17 in CS-PLAN-080. Alternatives not
   chosen: remove it now (shared test stack in use by another session); backlog
   item.

### Gates

`npm run docs:encoding`, `npm run backlog:check`, `git diff --check`, a link and anchor check of every changed Markdown file (one false positive: a quoted dead link inside a code span in `CODE_REVIEW_2026-09-16.md`), `npm test -- --runInBand` (130 suites / 1885 tests) and `npm run lint` are green. No PHP, JS, l10n or test file changed, so the Docker backend suites, build and browser gates were not rerun; their last full run is the `0.8.0-alpha.1` candidate qualification.

### Transition and Tracking

- CS-PLAN-070 → `done/`, with RF-070-01..17 verdicts and the acceptance criteria
  updated (family evidence user-rescoped to CS-PLAN-080).
- CS-PLAN-080: consolidation note, ownership of G3 and CONCEPT-001, RF-080-17,
  acceptance criteria and implementation order wording.
- CONCEPT-001 stays In Progress, now owned by CS-PLAN-080. `ACTIVE_TASKS.md`,
  `archive/2026-10.md`, `docs/plans/README.md` and `docs/README.md` updated.

## Standard B (interim) — CS-PLAN-086 WP-C Spot Sprint, DOM game path and phone touch controls

**Outcome:** `PASS-WITH-FIXES` · **Transition:** none (interim; plan stays `active/`)

### Scope

| Item | Value |
|---|---|
| Owning plan | `docs/plans/active/cs-plan-086-household-arcade-and-chore-unlocks.md` (WP-C) |
| Branch / base HEAD | `feat/arcade-minigames` (worktree pinned) @ `39d0f19e89cabdf3726967b502a69f5b48f36998` |
| Index at capture | empty |
| Scope fingerprint at capture | `52b2904ae58c89062899f0eb42168369ca2357ca8df1ed83bf7595cdac6988d9` (SHA-256 over the staged binary diff `e3b0c442…b855` (empty), the unstaged binary diff `899e229e…c4b8` and the sorted untracked paths with content hashes `1369b701…8c43`); 25 modified tracked paths, 7 untracked paths |
| Reviewed | `lib/Service/ArcadeCatalog.php`; `src/` (Spot Sprint `logic.js`, `Board.vue`, `game.js`, `index.js`; `GameShell.vue`, `gameInput.js`, `gameRegistry.js`); every touched PHPUnit/Jest/Playwright test, the gate manifest and Playwright config; l10n; API, concept, use-case, architecture and third-party docs; plan, backlog (UX-019) and ACTIVE_TASKS |
| Test target | the isolated staged row `cs082-arcade-wpc` (NC 35.0.2, PHP 8.4.26, PostgreSQL 18.6, port 8186), not the shared canonical stack (TECH-TEST-008) |

### Findings

| ID | Sev | Area | Issue | Status |
|----|-----|------|-------|--------|
| R086C-01 | nit | `docs/engineering/testing.md` | The phone core set section named only `mobile-core.spec.ts`, although both phone projects now also run `arcade-mobile.spec.ts` | Fixed — the section names the arcade touch spec and what it proves |

### Verified non-findings

- Game rules: deterministic opening (stain under the cursor at tile 5), cursor moves clamp at the walls, taps on empty or out-of-range tiles change nothing, drying stains vanish without penalty, the golden sponge doubles for five seconds and never spawns while active, socks never spawn in the first ten seconds and never push the score below zero, no spawn on a tile that still shows its wipe, the round ends at 60 s with a `timeup` event and ignores later input — each pinned in `spotsLogic.spec.js`.
- Score integrity: a seeded perfect player stays within `100 + 50 per second` at every step and below 3000 over five seeds; the stepped play time of a full round (≈ 60 017 ms) is inside the 65 s bound; the browser round saved with `durationMs ≥ 60000` and HTTP 200.
- Shell: the canvas path is unchanged for Crumb Snake (`snake-game.spec.ts` 12/12); a DOM game re-reads its view only on a revision change; canvas sparkles are no longer collected for DOM games; Enter acts only while the shell itself has focus, so a focused button keeps its own activation; swipes steer only D-pad games.
- The board/scrollbar resize loop was found by the new phone spec during implementation, not by this review: the board grows only for a new viewport; the regression test fails without the guard (negative control). Its width-only comparison is sufficient because `fitBoard` scales both edges by one cell size.
- Accessibility: the board is decorative (`aria-hidden`, no text), items differ by shape (blob, dashed drying ring, rectangular sponge, sock), the cursor has a visible outline, tiles are at least 44 px on the phone projects, and the wipe sparkle is suppressed without the motion opt-in or under reduced motion. The golden frame during double points is a supplementary cue; the effect itself shows in the score.
- No authorization, persistence or schema change; the arcade chunk carries no game logic; `game-spots` is 4051 bytes gzip (budget 40 KB), `game-snake` unchanged at 1610.
- Out of scope, routed: UX-019 (Nextcloud's navigation toggle stays above open dialogs on phones, every `Modal`).

### Decisions recorded

None needed.

### Gates (after all fixes)

- Jest 139 suites / 1952 tests through the zero-noise gate (`npm run test:noise`).
- PHPUnit on the staged row: unit 1453, integration 622 (`--fail-on-skipped`, default order, after a `minimal` reseed of the row), `composer cs:check` (0 of 423 files).
- `npm run lint`, `npm run stylelint`, `npm run l10n:check-keys` (1315 keys), `npm run backlog:check`, `npm run docs:encoding`, `git diff --check`, `npm run build`, `node scripts/run-e2e-gate.js --self-test`; scenario manifest regenerated with `--write-manifest`.
- Browser on the staged row (`NEXTCLOUD_URL=http://127.0.0.1:8186`, `NEXTCLOUD_CONTAINER=cs082-arcade-wpc-nextcloud-pgsql-1`): `arcade-games.spec.ts` 5/5 (serial pool), `arcade-mobile.spec.ts` 2/2 in `mobile-chromium` and in `mobile-webkit`, `snake-game.spec.ts` 12/12 and `color-contrast.spec.ts` 2/2 (chromium), auth setup 3/3 per run.
- Not run, with reason: the full browser gate and the AIO row (WP-G); the MySQL/SQLite provider matrix (WP-G).

### Outcome

`PASS-WITH-FIXES` (interim). R086C-01 was fixed; the gates above ran on the final code. Nothing was routed to the plan's §9; UX-019 is out of scope and in `backlog.md`. The plan stays `active/` for WP-D..WP-G. This review authorizes one scoped commit on `feat/arcade-minigames`.

## Standard B (interim) — CS-PLAN-086 WP-D Sock Match and the browser unlock proof

**Outcome:** `PASS-WITH-FIXES` · **Transition:** none (interim; plan stays `active/`)

### Scope

| Item | Value |
|---|---|
| Owning plan | `docs/plans/active/cs-plan-086-household-arcade-and-chore-unlocks.md` (WP-D) |
| Branch / base HEAD | `feat/arcade-minigames` (worktree pinned, 0 behind `master`) @ `e5a584af6f8fd77ab04aced909612eb087dc7641` |
| Index at capture | empty |
| Scope fingerprint at capture | `e5d67a7569706828342b753a6079ccd3aea164913c69d0d30a30bd3a2218a714` (SHA-256 over the staged binary diff `e3b0c442…b855` (empty), the unstaged binary diff `2b5366ac…f416` and the sorted untracked paths with content hashes `eb1e2e61…72f9`); 28 modified tracked paths, 6 untracked paths |
| Reviewed | `lib/Service/ArcadeCatalog.php`; `src/` (Sock Match `logic.js`, `Board.vue`, `game.js`, `index.js`; `GameShell.vue`, `GameShellOverlay.vue`, `gameInput.js`, `gameRegistry.js`); every touched PHPUnit/Jest/Playwright test, the gate script, manifest and Playwright config; l10n; API, concept, use-case, architecture, testing and third-party docs; plan, backlog and ACTIVE_TASKS |
| Test target | the isolated staged row `cs082-arcade-wpc` (NC 35.0.2, PHP 8.4.26, PostgreSQL 18.6, port 8186), not the shared canonical stack (TECH-TEST-008) |

### Findings

| ID | Sev | Area | Issue | Status |
|----|-----|------|-------|--------|
| R086D-01 | nit | `docs/api/README.md`, `docs/THIRD_PARTY.md`, `scripts/run-e2e-gate.js`, plan §5 | The per-game rule sentence nested its Sock Match rationale inside the list; the phone-gate comment still said "a DOM and a canvas game"; a broken line wrap; `docs/engineering/testing.md` (changed since WP-C) was missing from the file manifest | Fixed |
| R086D-02 | minor | `lib/Service/ArcadeCatalog.php` (`socks.maxDurationMs`) | An untimed round left running past one hour of unpaused play (a focused but idle window) was rejected with `400` and the player saw "Your score could not be saved." in a game without a fail state; the bound protects nothing because the ceiling binds | Fixed — 24 hours (operator, recommended option); `ArcadeCatalogTest` pins the bound, `ArcadeUnlockTest` saves a two-hour round; the negative control fails with the old bound ("Play time must be between 1 and 3600000 milliseconds") |
| R086D-03 | note | Sock Match board | Like Spot Sprint's board it is decorative for assistive technology (the live region carries score and state), so screen-reader users cannot play it; the plan's accessibility bar is no timer, no fail state and keyboard play | Accepted (operator, recommended option); recorded in the plan's WP-D note |

### Verified non-findings

- Game rules: every pattern twice; the opening pair at cells 0/1 for every seed; a wrong guess stays visible until the next turn, which turns it back first, and cursor moves never hide it; turning an up or matched sock and out-of-range taps change nothing; a pair scores 100 − 5 per wrong guess so far, at least 20; game time without input changes nothing; the round ends when every pair is found and ignores later input — each pinned in `socksLogic.spec.js`. A turned-down sock carries no pattern in the view or the DOM (`socksBoard.spec.js`).
- Score integrity: a perfect player scores exactly the 800 ceiling over five seeds, a careless one keeps at least 160; with the burst equal to the ceiling the rate never binds, by design for a game that never rewards speed (catalog comment, API docs, plan). A crafted score cannot exceed what a perfect round earns.
- Unlock: threshold 3 holds server-side in `assertUnlocked` under the house lock (`ArcadeUnlockTest`: locked after two real completions, open after the third, Crumb Snake still locked); the browser test unlocks it with three real completions and the progress line counts from "3 to go" to the open card while Crumb Snake shows "7 to go". The removed one-completion test is covered by these steps.
- Shell: `overTitle`/`overAnnouncement` are optional and paired (registry spec); the overlay falls back to "Game Over!" (asserted in the save-failure test) and composes no translated fragments; an untimed DOM game shows no clock; the canvas and timed DOM paths are unchanged (`snake-game.spec.ts` 12/12, Spot Sprint tests).
- Browser strategy: the perfect-memory round only taps socks that are down at that moment (a shown wrong guess can only pair with an older seen sock, which is turned first and hides it), so every wait proves a processed turn.
- Accessibility: eight distinct emblem shapes (pinned), the cursor outline, a dashed border for a wrong guess and a check mark for a found pair carry meaning without color; cards are at least 44 px on both phone projects; turn and pair animations follow the motion opt-in and reduced motion.
- No authorization, persistence or schema change; `game-socks` is 4251 bytes gzip (budget 40 KB), its logic only in that chunk (the `main` bundle carries only the l10n catalog entry, as for Spot Sprint).
- The cursor movement repeats Spot Sprint's eight lines: the second use, below the rule of three. `scripts/run-e2e-gate.js` sits outside the ESLint gate (CLI output by design); its results are identical on HEAD.

### Decisions recorded

- R086D-02: Sock Match's `maxDurationMs` raised from one hour to 24 hours (operator, recommended option over keeping one hour or an idle auto-pause backlog item).
- R086D-03: the decorative board is accepted for this plan (operator, recommended option over a backlog follow-up or announcements in WP-D).

### Gates (after all fixes)

- Jest 141 suites / 1972 tests through the zero-noise gate (`npm run test:noise`).
- PHPUnit on the staged row: unit 1454, integration 623 (`--fail-on-skipped`, default order, after a `minimal` reseed of the row), `composer cs:check` (0 of 423 files).
- `npm run lint`, `npm run stylelint`, `npm run l10n:check-keys` (1320 keys), `npm run backlog:check`, `npm run docs:encoding`, `npm run agents:check`, `git diff --check`, `npm run build`, `node scripts/run-e2e-gate.js --self-test`; scenario manifest regenerated with `--write-manifest`.
- Browser on the staged row (`NEXTCLOUD_URL=http://127.0.0.1:8186`, `NEXTCLOUD_CONTAINER=cs082-arcade-wpc-nextcloud-pgsql-1`): `arcade-games.spec.ts` 6/6 (serial pool), `arcade-mobile.spec.ts` 3/3 in `mobile-chromium` and in `mobile-webkit`, `snake-game.spec.ts` 12/12 and `color-contrast.spec.ts` 2/2 (chromium), auth setup 3/3 per run; both Sock Match tests rerun after R086D-02.
- Not run, with reason: the full browser gate and the AIO row (WP-G); the MySQL/SQLite provider matrix (WP-G).

### Outcome

`PASS-WITH-FIXES` (interim). R086D-01 and R086D-02 were fixed and R086D-03 accepted by the operator; the gates above ran on the final code. Nothing was routed to the plan's §9 or the backlog. The plan stays `active/` for WP-E..WP-G. This review authorizes one scoped commit on `feat/arcade-minigames`.

## Standard B (interim) — CS-PLAN-086 WP-E Tile Breaker and the paddle input mode

**Outcome:** `PASS-WITH-FIXES` · **Transition:** none (interim; plan stays `active/`)

### Scope

| Item | Value |
|---|---|
| Owning plan | `docs/plans/active/cs-plan-086-household-arcade-and-chore-unlocks.md` (WP-E) |
| Branch / base HEAD | `feat/arcade-minigames` (worktree pinned, rebased onto `master` `ca7f98e` before the review, 0 behind) @ `150fda0e7c9602969d8b79c484b5bfa18173694d` |
| Index at capture | empty |
| Scope fingerprint at capture | `4957221ff463bdd46d82210cf22af232e89a0e8d4e20daf4d42b8573d9d5d2a7` (SHA-256 over the staged binary diff `e3b0c442…b855` (empty), the unstaged binary diff `7e5889c3…b0e6` and the sorted untracked paths with content hashes `ea48eb57…4321`); 27 modified tracked paths, 8 untracked paths |
| Reviewed | `lib/Service/ArcadeCatalog.php`; `src/` (Tile Breaker `logic.js`, `render.js`, `game.js`, `index.js`; `GameShell.vue`, the new `GameShellHeader.vue`, `gameInput.js`, `gameRegistry.js`; `ArcadeCatalog.vue` through the visual check); every touched PHPUnit/Jest/Playwright test, the gate script, manifest and Playwright config; l10n; API, concept, use-case, architecture, testing and third-party docs; plan, backlog and ACTIVE_TASKS |
| Test target | the isolated staged row `cs082-arcade-wpc` (NC 35.0.2, PHP 8.4.26, PostgreSQL 18.6, port 8186), not the shared canonical stack (TECH-TEST-008) |

### Findings

| ID | Sev | Area | Issue | Status |
|----|-----|------|-------|--------|
| R086E-01 | minor | `src/components/Minigames/gameInput.js` (`createSteerKeys`) | Held keys were counted per side, so releasing one of two keys for the same side (ArrowLeft and KeyA) stopped the squeegee while the other key was still down | Fixed — keys are held by `KeyboardEvent.code` and a value is reported only when it changes; `gameInput.spec.js` pins the two-key case |
| R086E-02 | minor | `src/components/Minigames/ArcadeCatalog.vue` (pre-existing since WP-A) | Nextcloud's global list styles give `dt`/`dd` `inline-block`, a 130 px `dt`, 12 px padding and end alignment; the best/rank row needed 283 px in a 249 px phone card, so "Rank" was cut off on every card (found in the visual check) | Fixed — scoped reset of the card's `dt`/`dd`; `arcade-mobile.spec.ts` asserts no card's stat list overflows on both phones; the negative control fails with four overflowing cards |
| R086E-03 | nit | `src/components/Minigames/games/tiles/logic.js` | 405 lines, above the ~300-line file rule | Fixed — split into `constants.js` (49, geometry), `logic.js` (193, the round) and `physics.js` (216, movement, collisions, drops) without an import cycle; every rule spec and browser test reran on the split code |

### Verified non-findings

- Game rules: the opening bubble launches by itself after one second (Enter at once) and cleans the first tile without randomness; without input it misses three times and the round ends at 60 points for every drop source tried (never, every kind, seeded); after the first tile, held Left or the pointer at the left wall catches the returning bubble; steer and aim move the squeegee and the latest control wins; unknown inputs change nothing; bounces never go straight up and reach 60 degrees at the edges; two-layer tiles need two hits; a clean wall brings the next, faster one and the last layout repeats; drops fall straight and vanish when missed; sponge, mud, wax and spray work as specified; a lost last bubble ends effects and drops — each pinned in `tilesLogic.spec.js`.
- Score integrity: an autopilot that never misses stays inside 300 + 20 per second at every step over five minutes for three seeds; over an hour it scores about 24000 and at most about 15 per second over any minute (simulation), below the 50000 ceiling, which the one-hour bound reaches (`ArcadeCatalogTest`). Unlock threshold 25 is pinned server-side (`ArcadeAccessServiceTest`, the integration catalog list) and shown as "22 to go" after three real completions in the browser.
- Shell: a paddle control replaces a queued one without loss, because the latest control alone sets the control state; the negative controls for coalescing and for the still start after a resume fail without the code; the pointer is ignored outside paddle games and Up/Down are swallowed only while a paddle game plays; the D-pad, taps and swipes of the other games are unchanged (`snake-game.spec.ts` 12/12, the Spot Sprint and Sock Match tests).
- The browser tests keep the mouse off the board (the squeegee follows the pointer) and restart with Enter; the catch after the first tile has about 0.9 s of game-time margin for the keyboard and more for a tap (simulation).
- Accessibility: keyboard play (held arrows, Enter, Space), lives in the DOM header, drop kinds and double grime by shape, the board decorative for assistive technology like the other games (R086D-03); visual check in light and dark on desktop and phone width; contrast 0 violations on the arcade view and an open game.
- `GameShellHeader.vue` is a behavior-neutral split (same test ids; the shell spec passes unchanged). No authorization, persistence or schema change; no Coin, wallet or ranking surface; `game-tiles` is 2649 bytes gzip with its logic only in that chunk.
- Integration: after the rebase onto `master` `ca7f98e`, master's teleported `Modal` (CS-PLAN-087 RF-087-09) and its global Jest teleport stub replace the dialog's content elements on re-render, so `GameLeaderboard.spec.js` read a detached tab; it re-queries now (a probe confirmed the component renders the pressed state). The `ACTIVE_TASKS.md` rebase conflict kept both entries and master's TECH-DOCS-005 state.

### Decisions recorded

- None needed: the rule numbers are implementation choices within the plan; WP-G tunes the rates from real play.

### Gates (after all fixes)

- Jest 147 suites / 2046 tests through the zero-noise gate (`npm run test:noise`).
- PHPUnit on the staged row: unit 1456, integration 623 (`--fail-on-skipped`, default order, after a `minimal` reseed of the row), `composer cs:check` (0 of 423 files); no PHP changed after this run.
- `npm run lint`, `npm run stylelint`, `npm run l10n:check-keys` (1324 keys), `npm run backlog:check`, `npm run docs:encoding`, `npm run agents:check`, `git diff --check`, `npm run build`, `node scripts/run-e2e-gate.js --self-test`; scenario manifest regenerated with `--write-manifest`.
- Browser on the staged row (`NEXTCLOUD_URL=http://127.0.0.1:8186`, `NEXTCLOUD_CONTAINER=cs082-arcade-wpc-nextcloud-pgsql-1`), on the final code: `arcade-games.spec.ts` 7/7 (serial pool), `arcade-mobile.spec.ts` 4/4 in `mobile-chromium` and in `mobile-webkit`, `snake-game.spec.ts` 12/12 and `color-contrast.spec.ts` 2/2 (chromium), auth setup 3/3 per run.
- Not run, with reason: the full browser gate and the AIO row (WP-G); the MySQL/SQLite provider matrix (WP-G).

### Outcome

`PASS-WITH-FIXES` (interim). R086E-01..03 were fixed and the gates above ran on the final code. Nothing was routed to the plan's §9 or the backlog. The plan stays `active/` for WP-F and WP-G. This review authorizes one scoped commit on `feat/arcade-minigames`.
