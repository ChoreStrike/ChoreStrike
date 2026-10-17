# CS-PLAN-086: Household Arcade — Game Catalog, Chore Unlocks and Per-Game Highscores

> **Status:** Active — Standard A `PASS-WITH-FIXES` 2026-10-17 (20 findings fixed, D-086-01..08 resolved); WP-A delivered 2026-10-17 after interim Standard B `PASS-WITH-FIXES` (RF-086-01 resolved); WP-B delivered 2026-10-17 after interim Standard B `PASS-WITH-FIXES` (R086B-01/02 fixed); next: WP-C
> **Effort:** XL (seven work packages; no schema change)
> **Priority:** P3 (optional add-on, never on the core critical path)
> **Depends on:** work proceeds on branch `feat/arcade-minigames`; merge to
> `master` and any deployment only after the CS-PLAN-084 final review **and**
> the close of the CS-PLAN-080 family evidence window (D-086-07). Amends
> CS-PLAN-070 D7d and extends its D11 slice (D-086-01, D-086-05; ADR-019).
> **Related backlog items:** FEAT-ARCADE-001, FEAT-ARCADE-002, FEAT-ARCADE-003

## 1. Problem Summary

The operator wants the arcade to grow into a small, household-themed game
collection in the spirit of learning apps such as ANTON: members finish chores
and thereby unlock short arcade games with per-game highscores. Inventory of
the current state (verified 2026-10-16/17 on `993e563`):

**Reusable today.**

- The backend is already multi-game shaped. `gameId` is a route segment
  (`appinfo/routes.php:204-209`, `[a-z0-9-]+`) and a column indexed as
  `mgs_hgs (house_id, game_id, score)`
  (`lib/Migration/Version1016Date20260314000000.php:84`). All-time and weekly
  leaderboards, personal best, rank and recent scores are per game
  (`lib/Service/MinigameService.php`). Writes lock the house row first, check
  membership, cap saves per user and house-local day
  (`DAILY_SCORE_SAVE_LIMIT = 100`) and bound the score (`MAX_SCORE = 100000`).
- Account erasure already deletes score rows by raw UID
  (`lib/Service/AccountErasureService.php:169-172`).
- The surface is optional per member: `arcadeVisible`
  (`src/store/preferences.js:32`, `lib/Service/PreferenceService.php:47`) hides
  the navigation entry (`src/App.vue:53-60`) and the view
  (`src/views/ArcadeView.vue`).
- Effects exist with the right defaults: `src/composables/useCelebration.js`
  syncs the personal opt-ins into `src/utils/confetti.js` (opt-in,
  `prefers-reduced-motion` as hard floor), `src/utils/sound.js` (opt-in,
  synthesized, no assets) and `src/utils/haptics.js`.
- The ADR-014 work ledger already attributes completions fairly:
  `WorkShareMapper::sumValidSelectedByWallet()` (`lib/Db/WorkShareMapper.php:156`)
  aggregates only **valid** completions (`undone_at IS NULL`) in their
  **selected** allocation revision, per participant wallet — helpers included.
- The arcade is documented: `docs/concepts/gamification-and-rewards.md`
  §"Arcade (play-only since WP3)" and its D7 bullets, `docs/concepts/calm-core-ux.md`
  §"Optional areas", `docs/concepts/product-vision.md`, and the Minigames
  section of `docs/api/README.md` (rewritten to the shipped scores-only API
  during this plan's Standard A review, A-16).
- `RewardConfigService` is the single registry for scalar app configuration
  (CR-SVC-16; typed `IAppConfig` keys, already holding the `titles_enabled`
  flag, no HTTP writer yet), and there is exactly one house per Nextcloud
  instance (`HouseService::getOrCreateHouse()`), so a household flag needs no
  schema change.

**Blocking the target.**

- One hard-wired game: `MinigameService::VALID_GAMES = ['snake']`, one global
  score ceiling, `'snake'` defaults in every controller/service/store
  signature, and a single-slot store (`src/store/minigame.js`: one `score`,
  `personalBest`, `leaderboard`) — a second game would overwrite the first
  game's state.
- No catalog: `src/components/Dashboard/ArcadeSection.vue` renders one
  hard-coded Snake card and still lives under `components/Dashboard/` although
  only `src/views/ArcadeView.vue` uses it since CS-PLAN-070 WP6.
- No relation between chores and play.
- `CompletedTaskMapper::countByUserOnDate()` (`lib/Db/CompletedTaskMapper.php:185`)
  counts undone rows and only the actor, so it is **not** a valid unlock basis.
- The read routes pass the client `limit` unclamped to `setMaxResults()`
  (RF-086-01).

**Decisions this plan amends.** CS-PLAN-070 deliberately separated the arcade
from chore value: D7d keeps "an optional, separate arcade with its own
highscores" that neither mints nor spends Coins and forbids inventing another
family currency; RF-070-09 and the parent's risk table record "separate arcade
and chore value"; D11 and the gamification concept limit the first slice to
Snake (the 2048/Memory prototypes and the boost shop were retired with the
WP3 cutover, comment in `ArcadeSection.vue:60-63`). The operator decided on
2026-10-17 to amend this in a scoped way (D-086-01, D-086-05): arcade
**access** may derive from valid chore history as permanent milestones; the
arcade still never mints, spends or displays Coins, wallet values,
contribution or household ranking, and there is no daily coupling, so the
arcade stays "outside the daily routine" (`calm-core-ux.md`). ADR-019 records
the amendment.

**Snake defects found during the inventory** (fixed in WP-A, because the shell
replaces exactly this code):

| # | Location | Defect |
|---|---|---|
| S1 | `SnakeGame.vue` board `@keydown` + `window` listener | Every key is handled twice while the board has focus; Space pauses and immediately resumes. |
| S2 | `SnakeGame.vue` window listener | Arrow keys and Space are `preventDefault`ed at window level whenever the component is mounted, even before a game starts. |
| S3 | `setInterval` loop | Keeps ticking (throttled) while the tab or phone app is hidden; the player returns to a lost game. |
| S4 | fixed 20 px cells, `transform: scale(0.9)` | The 400 px board is not responsive and overflows narrow phones (CS-PLAN-084 mobile projects: Pixel 7, iPhone 14). |
| S5 | `getCellClass`, 400 cell divs | Every tick re-scans snake and obstacle arrays per cell; all empty cells are exposed to assistive technology; the focusable board has no role or label. |
| S6 | `generateObstacles` | Obstacles re-randomize on every pickup and can spawn directly in front of the head — practically unavoidable at higher speeds. |
| S7 | `lives` ref | Dead state. |

## 2. Strategy

1. **Keep the backend contract, generalize its edges.** Reuse the score table,
   routes and leaderboards. Replace `VALID_GAMES`/`MAX_SCORE` with one
   server-side game catalog (id → score ceiling, plausibility rate, unlock
   threshold), drop the silent `'snake'` defaults, add one catalog read
   endpoint. No new score schema.
2. **Unlocks are derived milestones, never stored.** A game's lock state is a
   pure read of the existing work ledger (valid selected work shares per
   wallet). No balances, tokens or counters are persisted, so there is nothing
   to farm, migrate, reset or replay, and the retained-data boundary
   (project-rules §1) stays untouched. Like retained recognition, the state
   re-derives from valid history. There is no schema change at all: the owner
   switch is a typed app-config flag (D-086-02).
3. **One shell, many small games.** A shared `GameShell` + `useGameLoop` own
   canvas sizing, input (keyboard, swipe, on-screen controls), pause on hide,
   overlays, accessibility and effects. Each game is a lazy chunk made of a
   **pure logic module** (`createState(rng)`, `step(state, input, dt)`) plus a
   thin renderer — deterministic and testable in Jest without timers or canvas.
4. **Household theme, own identity.** Cleaning utensils are the items: sponge,
   squeegee, spray bottle, soap bubbles, dust bunnies, socks. Names, mazes and
   characters are original; no clone of a trademarked title (D-086-03).
5. **Calm, optional, fair.** The arcade stays optional per member and off My
   Day and the Dashboard; there is no daily coupling (D-086-01); locked cards
   show neutral progress without countdowns, notifications or nagging; effects
   stay personal opt-ins with `prefers-reduced-motion` as the floor.
6. **Ship in slices.** Shell first, then the unlock gate, then one game per
   work package. Every game WP leaves a consistent, releasable catalog.

### Non-goals

- No Coins, wallet, contribution, medals or household-ranking coupling; no
  shop, boosts or paid retries (D7d's economy separation stays intact).
- No daily play gate, play streaks, daily play rewards or notifications that
  pull members into the arcade.
- No cross-game aggregate score or "arcade champion" — highscores are per game
  only.
- No realtime multiplayer (the app platform offers no WebSocket channel).
- No game engine dependency (Phaser, PixiJS), no WASM (blocked by the default
  Nextcloud CSP), no external assets or CDNs.
- No falling-block puzzle (that trade dress is aggressively enforced).
- No test-only hook in the production bundle (seeds, cheats, autoplay).

## 3. Backend / Frontend Changes

### 3.1 Game catalog

| `gameId` | English / German name | Mechanic and household theme | Renderer | Effort |
|---|---|---|---|---|
| `spots` | Spot Sprint / Fleckenjagd | Stains pop up on a tiled floor; wipe them before they dry. A golden sponge doubles points for a few seconds; a dropped sock costs points. 60-second rounds. | DOM grid | XS |
| `socks` | Sock Match / Sockensuche | Memory pairs with patterned socks from a laundry basket. Untimed, no fail state; the score rewards few attempts. The accessible entry game. | DOM grid | S |
| `snake` | Crumb Snake / Krümelschlange | The existing Snake, re-themed: a vacuum hose collects crumbs while furniture blocks the way; an occasional golden crumb is worth more. **The `gameId` stays `snake`**, so existing scores stay valid. | Canvas | S (moved onto the shell) |
| `tiles` | Tile Breaker / Fliesenblitz | A squeegee bounces a soap bubble against a wall of grimy tiles; every hit cleans a tile. Drops: sponge (wider squeegee), spray bottle (extra bubbles), floor wax (slower bubble), mud splash (narrower squeegee). | Canvas | M |
| `dust` | Dust Runner / Staubjagd | A robot vacuum clears dust from room-shaped floor plans while dust bunnies roam; docking at the charging station makes the bunnies catchable for a few seconds. Levels are rooms of a house (hallway, kitchen, living room). | Canvas | L |

Every game has a **deterministic opening** (the first stain, crumb, tile hit or
dust pile is reachable without randomness), so browser tests reach a score
without any seed hook. Names stay changeable (D-086-03).

Effects go through one small `useGameEffects` composable that reuses the
opt-in gate sync **extracted** from `useCelebration` (one shared helper, no
copy) before calling the existing modules: sparkle burst on a cleaned tile or
stain, new synthesized `pop` and `swish` profiles in `sound.js`, a short haptic
pulse on pickups, confetti only on a new personal best. Every game stays fully
playable and legible with all effects off.

### 3.2 Unlock gate (milestones, D-086-01 = B)

- A game unlocks permanently once the member's lifetime count of finished
  chores reaches its threshold: `spots` 0, `socks` 3, `snake` 10, `tiles` 25,
  `dust` 50 (D-086-06; one constant map in `ArcadeCatalog`).
- A chore counts for every member holding a share in the completion's
  **selected** allocation revision while the completion is **valid**
  (`undone_at IS NULL`) — the attribution of `sumValidSelectedByWallet()`. So
  helpers count, and an undone completion no longer counts; because the state
  is derived, a game can re-lock until the count is reached again.
- No daily coupling: an unlocked game is playable at any time.
- Locked cards show neutral, plural-free progress, e.g. "Unlocks at 10
  finished chores · 7 to go" (thresholds are ≥ 3, so no count-dependent
  plural branch is needed; a new branch would have to be registered as an
  owned manual branch in the l10n checker, `i18n.md`).
- Owners can switch the gate off house-wide (D-086-02); then every game is
  open for everyone.
- Leaderboards of locked games stay readable.

### 3.3 Backend (`lib/`)

- **`ArcadeCatalog`** (new, `lib/Service/ArcadeCatalog.php`, flat like the
  rest of `lib/Service/`): the one source for valid game ids, per-game score
  ceilings, maximum durations, points-per-second rates and unlock thresholds.
  `MinigameService` reads it instead of `VALID_GAMES`/`MAX_SCORE`.
- **`WorkShareMapper::countValidSelectedByWallet(int[] $walletIds)`** (new):
  lifetime `COUNT(DISTINCT ws.completion_id)` per wallet with the same joins
  and validity filters as `sumValidSelectedByWallet()`.
- **`ArcadeAccessService`** (new, `lib/Service/ArcadeAccessService.php`):
  `getCatalog(houseId, HouseUser)` →
  `[{gameId, unlocked, threshold, finishedChores, gateEnabled}]`;
  `assertUnlocked(houseId, HouseUser, gameId)`.
- **`MinigameService::saveScore`**: explicit `gameId`; per-game ceiling;
  `assertUnlocked` (a crafted POST cannot fill a locked game's board → 403
  `MINIGAME_LOCKED`); **required** positive `durationMs`, bounded per game,
  with a per-game points-per-second cap plus a small burst allowance
  (D-086-04; 400 `MINIGAME_IMPLAUSIBLE_SCORE`, a missing duration is a 400
  `VALIDATION_ERROR`). House-row lock order and daily cap unchanged. Frontend
  and backend ship together; there are no external API consumers.
- **`MinigameController`**: `catalog()` + route `GET /api/v1/minigames/catalog`
  (`@NoAdminRequired`, membership-checked; the literal segment cannot collide
  with `/minigames/{gameId}/…`, pinned by a router test and the route count
  in `tests/unit/RouteMatrixTest.php`); read `limit` parameters clamped at the
  boundary (RF-086-01); a missing or unknown `gameId` is a 400 on reads and
  writes (today unknown ids read as an empty board).
- **Owner switch (D-086-02):** a typed `bool` key `arcade_gate_enabled`
  (default `'1'`, `apiField` `arcadeGateEnabled`) in the `RewardConfigService`
  registry with a dedicated setter. `PUT /api/v1/house/arcade-gate`
  (`HouseController` → `ArcadeAccessService::setGateEnabled()`, owner check
  through `requireHouseOwner()` in the service, boolean validation, idempotent
  by value, last write wins) is the registry's first and only HTTP writer; the
  flag is serialized as `gateEnabled` in the catalog payload and in
  `GET /api/v1/house`. No migration — the first post-install schema step and
  its app-upgrade proof do not arise (A-20).
- Controller, service and store signatures lose their `'snake'` defaults.

### 3.4 Frontend (`src/`)

- **`src/composables/useGameLoop.js`** (new): fixed-timestep
  `requestAnimationFrame` loop with `start/pause/resume/stop`, auto-pause on
  `visibilitychange` and window `blur`, guaranteed cleanup on unmount, no
  double start (fixes S3).
- **`src/components/Minigames/GameShell.vue`** (new): score/best header,
  start/pause/game-over overlays, input layer (keyboard bound to the shell
  element only, fixes S1/S2; swipe; on-screen D-pad or tap layer chosen by
  the game), canvas sizing via `ResizeObserver` with device pixel ratio capped
  at 2 (fixes S4), `role="application"` with a label, a polite live region for
  score and state, decorative layers `aria-hidden` (fixes S5). Sends
  `durationMs` with every save.
- **`src/components/Minigames/games/<gameId>/`** (new per game): `logic.js`
  (pure; the RNG is a function parameter, seeded only in Jest), `render.js`
  (canvas) or a small DOM component, `index.js` (registry entry: id, names,
  icon, input mode, lazy import).
- **`src/components/Minigames/gameRegistry.js`** (new): client registry; every
  entry lazy-loads its chunk (`webpackChunkName: "game-<id>"`).
- **`src/components/Minigames/ArcadeCatalog.vue`** (replaces
  `components/Dashboard/ArcadeSection.vue`): driven by the catalog endpoint —
  renders server ids that the registry knows (a mismatch is logged through
  `logger.js`, never shown); cards with icon, name, personal best, per-game
  rank, lock state and progress; opens the game in the existing
  `Common/Modal.vue`.
- **`src/components/Minigames/GameLeaderboard.vue`** (replaces
  `SnakeLeaderboard.vue`): `gameId` prop, all-time and weekly tabs, own rank.
  Never merged into `Leaderboard.vue` or the household ranking.
- **`src/store/minigame.js`**: per-game slices (`byGame[gameId]`: leaderboard,
  weekly, personal best, rank, recent), a `catalog` slice, and the session
  state of the one active game; `useStoreReset.js` keeps resetting it.
- **Settings:** the owner toggle sits next to the catalog toggle.
- **i18n:** every new string through `t()`, `l10n/` updated in the same round
  (`npm run l10n:check-keys`), German names as in §3.1.

### 3.5 Cross-layer contracts

- `GET /api/v1/minigames/catalog` payload ↔ `catalog` slice ↔
  `ArcadeCatalog.vue` ↔ `data-testid="arcade-card-<gameId>"`.
- Game ids: the server catalog is the runtime source; `ArcadeCatalogTest`
  (PHPUnit) and the registry spec (Jest) each pin the expected id list, so
  adding a game fails both until both sides are updated.
- `POST /api/v1/minigames/{gameId}/scores` takes `{score, durationMs}` and
  gains `MINIGAME_LOCKED` (403) and `MINIGAME_IMPLAUSIBLE_SCORE` (400).
  `docs/api/README.md` §Minigames documents every route.

## 4. Tests

- **Jest**
  - `useGameLoop`: fixed timestep, pause on `visibilitychange`/`blur`, resume,
    no double start, cleanup on unmount (mocked rAF).
  - One pure-logic suite per game with a seeded RNG: movement, collisions,
    scoring, pickups and power-ups, win/lose, the deterministic opening; Snake
    regressions for S6 (no obstacle in the head's next cells) and the
    reverse-direction guard.
  - `GameShell`: exactly one handler call per key press (S1), no window-level
    `preventDefault` (S2), swipe mapping, overlay states, a11y attributes,
    live-region updates, `durationMs` on save.
  - `useGameEffects`: shares the extracted gate sync with `useCelebration`;
    no effect without opt-in; reduced motion respected.
  - `ArcadeCatalog`: locked, unlocked and gate-off states with progress texts;
    registry mismatch is logged, not rendered; `GameLeaderboard` tabs and own
    rank.
  - Store (`tests/unit/store/minigame.spec.js`, new): per-game isolation (a
    `tiles` save never changes `snake` state); catalog refresh after a save.
  - Registry: pinned id list; every game import is lazy.
- **PHPUnit unit**
  - `ArcadeCatalog`, `ArcadeAccessService`: thresholds, gate off, helpers
    counted, undone completions ignored, re-lock after an undo.
  - `MinigameService`: per-game ceilings, unknown or missing `gameId` 400,
    locked game 403, missing `durationMs` 400, implausible score 400, daily
    cap unchanged.
  - `MinigameController`: catalog mapping, error codes, `limit` clamping
    (RF-086-01).
  - `HouseController`/`ArcadeAccessService`: owner-only gate toggle, boolean
    validation; `RewardConfigService`: the new registry key and its default.
- **PHPUnit integration (Docker)**
  - `countValidSelectedByWallet` against real rows (selected vs. superseded
    revision, undone, shared completion with a helper).
  - Per-game leaderboard isolation; existing `snake` rows stay listed after
    the catalog change; catalog endpoint membership; unlock after real
    completions and re-lock after an undo; erasure still removes the member's
    scores of every game.
  - Gate toggle over real HTTP: a non-owner gets 403; an owner write persists
    through `IAppConfig` and opens every game for every member.
- **Playwright**
  - New `tests/e2e/arcade-games.spec.ts`: the catalog lists every game; a
    locked card shows neutral progress; finishing chores unlocks a game; each
    game starts and reaches a score through its deterministic opening, and the
    score appears only in that game's leaderboard; the owner switch opens
    every game; no Coin, wallet or boost surface.
  - `snake-game.spec.ts` adapted to the shell. Its titles are pinned in
    `tests/e2e/required-scenarios.json:353-363`; every rename or new test
    updates the manifest in the same round.
  - axe contrast on the arcade view and on an open game in light and dark
    (extends the CS-PLAN-084 WP-B spec); `mobile-chromium` and `mobile-webkit`
    run touch controls for one DOM game and one canvas game.
- **Build:** each game chunk measured on the production build against the
  40 KB gzip budget; the catalog view loads no game chunk.

## 5. File Manifest

```
new:      lib/Service/ArcadeCatalog.php
new:      lib/Exception/ImplausibleScoreException.php               (WP-A: 400 MINIGAME_IMPLAUSIBLE_SCORE)
new:      lib/Service/ArcadeAccessService.php
new:      lib/Exception/GameLockedException.php                     (WP-B: 403 MINIGAME_LOCKED)
modified: lib/Service/TestDataService.php                     (WP-B: test reset restores the gate default)
modified: lib/Db/WorkShareMapper.php                          (countValidSelectedByWallet)
modified: lib/Service/MinigameService.php
modified: lib/Service/RewardConfigService.php                 (arcade_gate_enabled key)
modified: lib/Controller/MinigameController.php
modified: lib/Controller/HouseController.php
modified: appinfo/routes.php
new:      src/composables/useGameLoop.js
new:      src/composables/useGameEffects.js
new:      src/utils/effectGates.js                            (WP-A: the extracted gate sync)
modified: src/composables/useCelebration.js                   (extracted gate sync)
new:      src/components/Minigames/GameShell.vue
new:      src/components/Minigames/{GameShellOverlay,GameShellDpad}.vue   (WP-A: split from the shell)
new:      src/components/Minigames/{board,gameInput}.js         (WP-A: board fit/palette, key/swipe mapping)
new:      src/components/Minigames/ArcadeCatalog.vue
new:      src/components/Minigames/ArcadeGateSetting.vue      (WP-B: the owner switch)
new:      src/components/Minigames/GameLeaderboard.vue
new:      src/components/Minigames/gameRegistry.js
new:      src/components/Minigames/games/{spots,socks,snake,tiles,dust}/…   (index.js entry, game.js chunk, logic.js, render.js)
modified: src/store/minigame.js
modified: src/store/house.js                                  (WP-B: arcadeGateEnabled getter + writer)
modified: src/views/ArcadeView.vue
modified: src/views/Settings.vue
modified: src/components/Rewards/RewardCatalogEditor.vue, tests/unit/components/RewardCatalogEditor.spec.js   (WP-B review: R086B-01)
modified: src/utils/sound.js                                  (pop/swish profiles)
modified: src/styles/tokens.css                               (WP-A: comment only)
modified: l10n/*
archived: archive/YYYY-MM/arcade-snake-v1/{SnakeGame.vue,SnakeLeaderboard.vue,ArcadeSection.vue}   (month of removal)
new:      tests/unit/composables/{useGameLoop,useGameEffects}.spec.js
new:      tests/unit/minigames/*.spec.js
new:      tests/unit/helpers/animationFrames.js               (WP-A: manual rAF driver, seeded RNG)
new:      tests/unit/components/{GameShell,ArcadeCatalog,ArcadeGateSetting}.spec.js
modified: tests/unit/store/house.spec.js, tests/unit/views/Settings.spec.js   (WP-B)
modified: tests/unit/Service/TestDataServiceTest.php          (WP-B)
renamed:  tests/unit/components/SnakeLeaderboard.spec.js → GameLeaderboard.spec.js
new:      tests/unit/store/minigame.spec.js
modified: tests/unit/views/ArcadeView.spec.js
modified: tests/unit/composables/useStoreReset.spec.js         (WP-A: per-game slices)
modified: tests/unit/services/coreWorkflowLocalization.spec.js (WP-A: ArcadeCatalog path)
new:      tests/unit/Service/{ArcadeCatalogTest,ArcadeAccessServiceTest}.php
modified: tests/unit/Service/{MinigameServiceTest,RewardConfigServiceTest}.php
modified: tests/unit/Controller/{MinigameControllerTest,HouseControllerTest}.php
modified: tests/unit/RouteMatrixTest.php
modified: tests/integration/Controller/{MinigameControllerIntegrationTest,HouseControllerIntegrationTest,HouseTimezoneApiTest}.php
new:      tests/integration/Db/WorkShareCountTest.php
new:      tests/integration/Service/ArcadeUnlockTest.php         (WP-B: unlock after real completions, re-lock after an undo)
new:      tests/e2e/arcade-games.spec.ts
new:      tests/e2e/fixtures/arcade-gate.ts                    (WP-B: owner switch for specs that play a game)
modified: playwright.config.ts, scripts/run-e2e-gate.js       (WP-B: serial-pool owner of arcade-games.spec.ts)
modified: tests/e2e/snake-game.spec.ts
modified: tests/e2e/color-contrast.spec.ts                    (WP-A: arcade view + open game)
modified: tests/e2e/required-scenarios.json
new:      docs/adr/ADR-019-arcade-unlocks-from-completion-history.md
modified: docs/adr/README.md
modified: docs/concepts/gamification-and-rewards.md           (§Arcade, D7 bullets)
modified: docs/concepts/calm-core-ux.md                       (§Optional areas: owner switch)
modified: docs/api/README.md                                  (§Minigames: catalog, durationMs, codes)
modified: docs/engineering/architecture.md, docs/concepts/ui-use-cases.md, docs/THIRD_PARTY.md   (WP-A: component map, UC-12, game implementations)
```

## 6. Implementation Order

Each WP is one reviewable round with an interim Standard B on
`feat/arcade-minigames`, rebased on `master` after every CS-PLAN-084 round.

1. **WP-A — Shell, registry, keyed store, Snake on the shell**
   (FEAT-ARCADE-001). `useGameLoop`, `useGameEffects` (gate sync extracted
   from `useCelebration`), `GameShell`, `gameRegistry`, keyed store,
   `ArcadeCatalog.vue`, `GameLeaderboard.vue`; Snake rebuilt as `games/snake`
   (pure logic + canvas) fixing S1–S7; server `ArcadeCatalog` with explicit
   ids, per-game ceilings and the required `durationMs` plausibility (catalog
   still `snake` only); `limit` clamping (RF-086-01). Old components archived.
   Users see the same arcade minus the defects.
   *Implemented 2026-10-17.* Implementation choices within the plan:
   `ArcadeCatalog.vue` renders the client registry until WP-B adds the
   catalog endpoint; the extracted gate sync is `src/utils/effectGates.js`
   (useCelebration and useGameEffects both call it); the pickup vibration
   rides on the motion opt-in (`animationEnabled`), sound on `soundEnabled`;
   the D-pad shows on touch and narrow screens; keyboard focus moves to the
   overlay's main action on pause/game over. Crumb Snake: 10 points per crumb,
   30 per golden crumb (15 %), 150 → 60 ms per move, one new piece of
   furniture per 50 points (max. 10, never within two cells or four cells
   ahead of the nozzle); server rules `maxScore` 20000, `maxDurationMs` one
   hour, 30 points per second + 100 burst (tuned in WP-G). Read limits clamp
   to 1..50; unknown ids are 400 on reads.
2. **WP-B — Unlock gate and owner switch** (FEAT-ARCADE-002). Lifetime count
   query, `ArcadeAccessService`, catalog endpoint, `assertUnlocked` on save,
   lock states and progress in the catalog, owner toggle as a typed
   app-config flag, ADR-019.
   *Implemented 2026-10-17.* Implementation choices within the plan:
   the catalog payload is `{gateEnabled, finishedChores, games: [{gameId,
   threshold, unlocked}]}` (the count is per member, not per game); the
   house payload carries the flag as `arcadeGateEnabled` (the registry
   `apiField`) on every response the client stores as its house (`GET`/`PUT
   /house`, `PUT /house/catalog`, `PUT /house/arcade-gate`, `POST
   /house/reset`), because the store replaces its house object with each of
   them; the writer takes `{arcadeGateEnabled: bool}`, checks the owner in
   the controller (before the payload) and in the service, and writes an
   `arcade.gate.updated` audit line; `getCatalog(HouseUser)` and
   `assertUnlocked(HouseUser, gameId)` take the membership only (it carries
   the house id); `assertUnlocked` runs inside the score transaction after
   the house lock and the membership check; a `HouseUser` without a wallet
   counts zero. Client: the catalog renders server games the
   registry knows and logs a mismatch either way; a locked card has no Play
   button, one neutral progress line and a readable leaderboard; a failed
   catalog read shows "The games could not be loaded." with a retry; the
   "catalog refresh after a save" (§4) refreshes on a `MINIGAME_LOCKED`
   rejection, since a successful save never changes a lock; the owner switch
   is `ArcadeGateSetting.vue` in an owner-only Arcade section after Rewards.
   Tests: the test-mode reset restores the gate default, so every reseeded
   spec starts locked; the parallel-pool specs that play a game
   (`snake-game.spec.ts`, `color-contrast.spec.ts`) first open the gate as the
   owner `mom` through the real endpoint; `arcade-games.spec.ts` owns the
   lock scenarios in the serial pool and proves that a real completion counts
   toward the unlock (the full browser unlock follows with Sock Match's
   threshold of 3 in WP-D; ten seeded completions are not reliably available
   in E2E, the full unlock and re-lock are proven in `ArcadeUnlockTest`); the
   gate toggle's "real HTTP" proof is that browser spec, the DI-level proof is
   in `MinigameControllerIntegrationTest`. Until Spot Sprint (threshold 0)
   arrives with WP-C, a member with fewer than ten finished chores sees only
   a locked Crumb Snake — consistent with D-086-06 and acceptable on the
   branch.
3. **WP-C — Spot Sprint** (FEAT-ARCADE-003). Proves the DOM path and the tap
   input mode on phones.
4. **WP-D — Sock Match** (FEAT-ARCADE-003). Untimed accessible entry game,
   plus the browser proof that finished chores unlock a game (threshold 3).
5. **WP-E — Tile Breaker** (FEAT-ARCADE-003). Canvas physics and power-ups.
6. **WP-F — Dust Runner** (FEAT-ARCADE-003). Room-shaped mazes and chaser
   behavior.
7. **WP-G — Knowledge integration and final gate.** Concept, calm-core and API
   sections updated, ADR-019 final, plausibility rates tuned from real play,
   full browser gate on both runtime rows, final review. Merge to `master`
   only under D-086-07.

## 7. Risks & Mitigations

| Risk | Likelihood | Mitigation |
|------|-----------|------------|
| The arcade changes family behavior during the CS-PLAN-080 trial and confounds its calm-core evidence | High if merged early | Branch-only until the 084 final review and the close of the 080 family evidence window (D-086-07). |
| Trademark or trade-dress conflict (well-known arcade titles, maze layouts, characters) in a public MIT repository | Medium | Original names and art, room-shaped mazes, no pellet or ghost look, no falling-block game (D-086-03). |
| Bundle growth slows the app for everyone | Medium | One lazy chunk per game; the catalog loads no game code; 40 KB gzip budget per game measured each round. |
| Canvas performance on low-end phones | Medium | Fixed timestep, pixel ratio capped at 2, no per-frame allocations in hot paths, measured in the mobile projects. |
| Client-reported scores can be faked | Certain | Per-game ceilings, required duration plausibility, daily cap, server-side unlock check. Residual risk accepted: a faked score buys nothing (scores-only). |
| Locked games frustrate young members | Medium | Spot Sprint is always open, low early thresholds, visible neutral progress, owner switch, per-member `arcadeVisible`. |
| Parents worry about screen time | Low–Medium | Owner switch; arcade optional per member; no daily coupling, streaks or rewards for playing. A daily play limit stays out of scope. |
| Five games are a lot of scope | High | One game per WP, each releasable; rescoping after any game WP leaves a consistent catalog. |
| The owner flag has no version counter | Low | A boolean toggle is idempotent by value; last write wins and is documented; owner-only endpoint. No schema change touches the protected production install (D-086-02). |
| Parallel work with CS-PLAN-084 conflicts in `App.vue`, preferences and the mobile gate | Medium | Separate branch, rebased after every 084 round (D-086-07). |
| Action games exclude members with motor or vision impairments | Medium | Sock Match is untimed without fail state; every game is keyboard-playable; pause always available; no flashing effects; reduced motion respected. |

## 8. Acceptance Criteria

- [ ] The catalog lists `spots`, `socks`, `snake`, `tiles` and `dust` with
      original names, icons and German translations (D-086-03/05).
- [ ] Every game is playable with keyboard, touch and on-screen controls on
      desktop and on the phone projects; pause works, and hiding the app pauses
      the game.
- [ ] Highscores exist per game only (all-time, weekly, own rank); no
      aggregate arcade ranking; nothing appears in the household ranking.
- [ ] Existing `snake` scores stay listed and keep their personal bests.
- [ ] Unlock gate: progression derives from valid selected work shares;
      helpers count; an undone completion no longer counts; no daily coupling;
      leaderboards of locked games stay readable; the owner switch opens every
      game.
- [ ] A score POST for a locked or unknown game, without `durationMs` or with
      an implausible score is rejected with the documented code; the daily cap
      is unchanged; read `limit` values are clamped.
- [ ] Snake defects S1–S7 fixed, each with a regression test.
- [ ] Effects only through the existing opt-in modules (gate sync shared with
      `useCelebration`); with every opt-in off and with
      `prefers-reduced-motion`, every game stays playable and legible.
- [ ] No Coin, wallet, contribution, medal or ranking read or write added
      (code search plus E2E assertion).
- [ ] axe: 0 violations on the arcade view and an open game in light and dark.
- [ ] Every game chunk ≤ 40 KB gzip on the production build; no test-only hook
      in the bundle.
- [ ] No schema change; the owner switch is the `arcade_gate_enabled` registry
      flag with the owner-only endpoint as its only HTTP writer.
- [ ] Merged to `master` only after the CS-PLAN-084 final review and the close
      of the CS-PLAN-080 family evidence window (D-086-07).
- [ ] Build gate green (`npm test` + lint + backend tests)
- [ ] Required tests ran without new or unapproved skips
- [ ] All implementation decisions and review questions resolved and recorded
- [ ] All plan-owned review findings (§9) resolved or user-rescoped to `backlog.md`
- [ ] Owning backlog items updated or archived according to their actual completion state
- [ ] Durable concepts/decisions integrated into the permanent docs (`docs/concepts/`, `docs/adr/`, `docs/engineering/`, `docs/api/`, `docs/standards/`) — plans are temporary
- [ ] Final Standard B or Full review is `PASS` or `PASS-WITH-FIXES`
- [ ] `docs/backlog/ACTIVE_TASKS.md` updated

## 9. Review Findings (plan-owned)

Findings **within this plan's scope** from implementation rounds or reviews
(scope-first routing per `project-rules.md` §4). Resolve before `done/`;
rescoping to `backlog.md` needs explicit user approval. Out-of-scope findings
go directly to `backlog.md` (or fold into an existing item) instead.

- [x] **RF-086-01 minor (pre-existing)** · `lib/Controller/MinigameController.php`
      `leaderboard`/`weeklyLeaderboard`/`recentScores` → `MinigameScoreMapper`
      `setMaxResults($limit)` · the client `limit` reaches the query unclamped:
      large values return the whole house board, negative values reach the
      database as `LIMIT -n` · resolved in WP-A: clamped to 1..50 at the
      controller boundary (`MinigameControllerTest::testReads_ClampLimit`,
      integration check with `-3` and `100000`).

## 10. Decisions (resolved 2026-10-17, Standard A)

- **D-086-01 — Unlock model: B, progression only** (operator; the
  recommended A, progression plus daily opening after the first finished
  chore, was declined). Games unlock permanently as milestones of valid
  history; no daily coupling. Scoped amendment of CS-PLAN-070 D7d: arcade
  access may derive from valid chore history, the arcade never mints, spends
  or displays value or currency (ADR-019).
- **D-086-02 — Owner control: typed app-config flag** (operator follow-up).
  The operator first chose A (house column through a new migration); the
  review then found (A-20) that this would be the first migration on the
  protected production install and need a not yet existing app-upgrade proof
  on populated data for all providers. On that basis the operator chose the
  recommended `arcade_gate_enabled` flag in the `RewardConfigService`
  registry (default enabled, owner-only toggle in Settings, no schema change).
- **D-086-03 — Names: A.** Original household names as in §3.1, no
  trademarked titles in code, UI or docs; names stay changeable.
- **D-086-04 — Score integrity: A.** Per-game ceilings plus a required
  `durationMs` with a per-game points-per-second cap (review fix A-05 made
  the duration mandatory, since an optional field is bypassed by omission).
- **D-086-05 — Game set: A.** All five games in the order Spot Sprint → Sock
  Match → Tile Breaker → Dust Runner after Snake moves onto the shell; extends the
  CS-PLAN-070 D11 "Snake only" first slice as its follow-up.
- **D-086-06 — Unlock unit: A.** Count of finished chores; thresholds
  0 / 3 / 10 / 25 / 50.
- **D-086-07 — Integration: A.** Work on `feat/arcade-minigames`, rebased
  after every CS-PLAN-084 round; merge to `master` and deploy only after the
  084 final review and the close of the CS-PLAN-080 family evidence window.
  The 084 final review is done (`bce10bb`); the branch was rebased onto
  `master` `0d27d38` on 2026-10-17 and the worktree pinned to it.
- **D-086-08 — Canonical test volumes reset** (operator, 2026-10-17, WP-A).
  The kept canonical volumes had the app installed as `0.1.1`; after the
  `0.8.0-alpha.1` bump the test entrypoint aborted on "require upgrade". The
  operator chose the recommended `down -v` of this checkout's disposable
  canonical stack over a one-off `occ upgrade` container or an isolated
  project; the missing app-upgrade path is routed out of scope as
  TECH-TEST-006.

## Review Outcome

### Standard A — 2026-10-17 — `PASS-WITH-FIXES` (`new/ → active/`)

- **Scope:** this plan plus its drafting package (backlog FEAT-ARCADE-001..003).
  Base `feat/arcade-minigames` @ `993e563`; index at capture: plan (added) and
  `backlog.md`; staged diff SHA256 `2e23009d7627b4b0…0764`, plan SHA256
  `fc713e50d0dba682…42aa2`; no unstaged or untracked changes.
  [Record](../../backlog/archive/CODE_REVIEW_2026-10-17.md).
- **Verified against the code:** score routes, column, index, caps and lock
  order; erasure coverage; `arcadeVisible` gating; effect opt-ins; ADR-014
  share attribution (`sumValidSelectedByWallet`,
  `findSelectedSharesBetweenByHouse`); `countByUserOnDate` counting undone
  rows; `work_date` semantics; the nullable-boolean column convention; the
  `RewardConfigService` registry and one house per instance; the preservation
  harness (P1 same-build recreate, P2 Nextcloud patch upgrade — no app-build
  upgrade proof); all seven Snake defects; CS-PLAN-070 D7d/D11/RF-070-09;
  concept and API docs.
- **Fixed (20):** A-01 false "undocumented" claim and duplicate doc targets;
  A-02 unsurfaced conflict with D7d/D11 (→ D-086-01/05, ADR-019); A-03 no
  sequencing against the CS-PLAN-080 family evidence (→ D-086-07); A-04 daily
  opening without a concrete read owner and A-06 save-time daily check
  rejecting games finished after midnight (both removed with D-086-01 = B);
  A-05 optional `durationMs` bypassable (now required); A-07 E2E seed hook in
  the production bundle (deterministic openings instead); A-08 cross-language
  id parity guard (catalog-driven UI, per-side pinned lists); A-09 duplicated
  opt-in sync (extracted); A-10 count-dependent plural copy; A-11
  `lib/Service/Arcade/` sub-namespace (flat); A-12 manifest errors; A-13
  citation; A-14 S2/S6 wording; A-15 acceptance/test gaps; A-16 stale
  `docs/api/README.md` Minigames section rewritten to the shipped API; A-17
  stale 2048/Memory sentence in `gamification-and-rewards.md`; A-18 effort
  L → XL; A-19 NOT NULL boolean column contradicting the repository
  convention; A-20 the owner-switch migration would have been the first
  schema step on the protected production install without an app-upgrade
  proof and pointed at a non-existent `tests/integration/Migration/` (→
  D-086-02 follow-up: app-config flag, no schema change; A-19 obsolete with it).
- **Routed:** RF-086-01 (§9, WP-A). No security-sensitive detail.
- **Decisions:** D-086-01..07 (§10): one batched operator round plus one
  follow-up on D-086-02 after A-20.
- **Gates:** backlog check, docs encoding, `git diff --check`, docs/scripts
  Jest suites green; no runtime code changed.

### Standard B (interim, WP-A) — 2026-10-17 — `PASS-WITH-FIXES` (stays `active/`)

- **Scope:** the uncommitted WP-A package on `feat/arcade-minigames` @
  `315f408` (fingerprint at capture `6c1c3529…8767`: 4 staged deletions or
  renames, 29 modified and 28 untracked paths). Rebased onto `master`
  `8747f4d` on 2026-10-17: `315f408` → `d9eb433`, the WP-A commit → `ebc3c30`;
  rebased again onto `master` `37bcd5c` the same day: `cdf1fc7` and `ce81102`.
  [Record](../../backlog/archive/CODE_REVIEW_2026-10-17.md).
- **Fixed (4):**
  - R086A-01: the board dialog moved out of the shell's `role="application"` region.
  - R086A-02: the catalog's load note follows only the stats load, so a failed save no longer shows as a load failure.
  - R086A-03: no per-frame allocation in `draw()`.
  - R086A-04: the test helper is renamed.
  - Each has a regression test where testable; the R086A-02 negative control fails with the old condition.
- **Verified:**
  - The read envelope's `\InvalidArgumentException` mapping has no other thrower in the read path.
  - Membership is checked before input validation.
  - The game chunk stays lazy (1609 bytes gzip).
  - There is no Coin, wallet or ranking surface.
- **Decisions:** D-086-08 (canonical volumes reset; TECH-TEST-006 routed).
- **Gates:**
  - Jest 136/1908, noise-free.
  - PHPUnit unit 1421 and integration 614 on the fresh canonical stack.
  - lint, stylelint, `cs:check`, l10n (1303 keys), backlog, encoding and build checks.
  - Browser: `snake-game.spec.ts` 12/12 and `color-contrast.spec.ts` 2/2 (light and dark, including the arcade view and an open game) at 0 violations.
  - Not run: the full gate, the AIO row and the phone projects, which are scheduled for WP-C and WP-G.

### Standard B (interim, WP-B) — 2026-10-17 — `PASS-WITH-FIXES` (stays `active/`)

- **Scope:** the uncommitted WP-B package on `feat/arcade-minigames` @
  `ce81102` (rebased onto `master` `37bcd5c`; fingerprint at capture
  `629aa263…a864`: empty index, 44 modified and 10 untracked paths).
  [Record](../../backlog/archive/CODE_REVIEW_2026-10-17.md).
- **Fixed (2):**
  - R086B-01: the reward catalog's gate error note followed the shared house-store error, so a failed arcade-gate save showed a false catalog error; it now follows the gate's own save (regression with negative control).
  - R086B-02: stale signatures and the missing full-unlock owner in the plan, the missing `500` of `PUT /house/arcade-gate` in the API docs.
- **Verified:**
  - Both routes `@NoAdminRequired` with CSRF; the owner check runs in the controller and the service.
  - The lock is enforced server-side under the house lock from the stored membership.
  - The count mirrors `sumValidSelectedByWallet()` and is indexed.
  - No stored arcade state, no Coin/wallet/ranking coupling.
  - The house payload change is additive.
- **Decisions:** R086B-01 fixed now (operator, recommended option).
- **Gates:**
  - Jest 137/1931, noise-free.
  - PHPUnit unit 1451 and integration 622 on a freshly provisioned stack; `cs:check`.
  - lint, stylelint, l10n (1311 keys), backlog, encoding, agents and build checks.
  - Browser: `arcade-games.spec.ts` 4/4, `family-rewards.spec.ts` 9/9, `snake-game.spec.ts` 12/12, `color-contrast.spec.ts` 2/2.
  - Not run: the full gate, the AIO row, the provider matrix and the phone projects (WP-C, WP-G).
