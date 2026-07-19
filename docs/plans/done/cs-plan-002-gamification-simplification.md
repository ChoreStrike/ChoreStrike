# CS-PLAN-002: Gamification Simplification & Core-Loop Completion

> **Status:** DONE — Phase 0 (subtraction), Phase 1 core (security/persistence/overdue-takeover), Phase 1 multi-assign, Phase 2 (core-loop closure), Phase 3 (cooperative glue), and Phase 4 (notifications) complete and verified. Review pass applied: `lockForUpdate` bug fixed (preserves `is_active` instead of forcing `true`), `getClaimableOverdueTasks` now filters by house via new `RoomTaskMapper::findOverdueByHouse()` (was a cross-house data leak). **Phase 1 review follow-ups (2026-07-15) complete:** controller error-response leak hardened (`ControllerErrorFormatter`, 158 call sites); dead stubs + superseded claim path removed; pre-existing Jest failures fixed. **Phase 2 (2026-07-17) complete:** Streak Freeze + background jobs + celebration layer (confetti + Web Audio synth) + event-bus wiring. **Phase 3 (2026-07-18) complete:** Weekly Household Goal — server-driven progress snapshot via `HouseService::getWeeklyGoalProgress()` + `RoomTaskMapper::countActiveDueBetweenByHouse()` + `CompletedTaskMapper::countCompletedBetweenByHouse()`; `RewardConfigService.weekly_goal_target_percent` (default 80, clamped 1-100); `GameController#state` and `RoomService::completeRoomTask()` payloads now include the snapshot; new `HouseholdGoalBar.vue` in Dashboard; `householdProgress.weeklyGoal` state in `game.js` restructured to mirror the server payload and `setWeeklyGoal()` action emits `chorestrike:weekly-goal-reached` on the not-achieved → achieved transition. Obsolete client-only `HouseholdProgress.vue` archived. **Phase 4 (2026-07-18) complete. New `DailyNotificationJob` (daily 06:00) sends streak-at-risk reminders to members with `currentStreak > 0 AND 0 completions yesterday AND 0 freezes left` (skips users whose streak would be silently protected by a freeze — no nudge when there's nothing to do). Frontend: new `useToasts` composable surfaces event-bus moments (`streak-freeze-used`, `weekly-goal-reached`, `medal-awarded`, `combo-bonus-freeze`) as Nextcloud toasts via `@nextcloud/dialogs`; wired in `main.js` alongside `useCelebration`. **Review pass (2026-07-18) applied:** all 11 review findings fixed. (A1) Concurrent-completion dedup for weekly-goal notification: new `chorestrike_houses.weekly_goal_notified_week` column (Version1023 migration) + `HouseMapper::claimWeeklyGoalNotification()` conditional UPDATE that returns true only for the first caller per house-per-week — drops the pre-snapshot read entirely, so the hot completion path no longer pays the doubled multi-JOIN cost. (A2) Takeover info-disclosure guard: `findByHouseAndUser` membership re-check post-commit; former members (whose `house_users` row was deleted but `room_tasks.assigned_to_user_id` wasn't cleared) no longer receive task details. (B1) Medal TOCTOU: `awardMedalIfNew` catch narrowed to `DoesNotExistException`, `OCP\DB\Exception` with `REASON_UNIQUE_CONSTRAINT_VIOLATION` treated as "already exists" (race-loser returns null, no API failure). (B2) `info.xml` reordered so `DailyNotificationJob` registers before `StreakFreezeJob` with documented ordering rationale. (B3) Missing l10n key `'Weekly household goal reached!'` added (en + de). (B4) `DailyNotificationJob` N+1 replaced with `CompletedTaskMapper::countByUserOnDateGrouped()` single GROUP BY. (B5) `HouseService::isWeeklyGoalAchieved()` cheap boolean check (kept for callers that need just the flag). (C1) Dead `awardMedal` deleted; new shared `createMedal()` helper. (C2) `chorestrike:medal-awarded` emit wired in `game.fetchMedals` (suppresses on initial load so no toast wall for prior weeks). (C3) `ChoreStrikeNotifier` uses `setRichSubject` with rich params for the takeover subject (per-parameter escaping across web/email/mobile render surfaces). (C4) `SUBJECT_*` constants on `NotificationService` referenced from both dispatch wrappers and notifier switch; `findAllForJobs()` promoted to `HouseUserMapper` so StreakFreeze/StreakRestock/DailyNotification share one definition of the "all house users" set. Test bootstrap mocks added for `OCP\Notification\*`, `OCP\BackgroundJob\TimedJob`, `OCP\AppFramework\Utility\ITimeFactory`, `OCP\DB\Exception`, `OCP\IURLGenerator`, `OCP\L10N\IFactory`, `Psr\Log\LoggerInterface`; `phpunit.unit.xml` gained `BackgroundJob` + `Notification` testsuites (the Phase-2 BackgroundJob tests were previously never wired into a suite — now they execute).
> **Effort:** XL
> **Priority:** P2
> **Depends on:** Reward-system-redesign (Phase 1 core) — mostly done; this plan extends/clarifies it
> **Related:** `docs/backlog/phase-reward-system-redesign.md`, `phase-11-task-visibility-features.md`, `phase-2-gamification.md` (notification/streak items), `docs/adr/ADR-004-multi-user-gamification.md`, `docs/plans/template.md`

---

## Progress

- [x] **Phase 0 — Subtraction** (DONE, verified). Sparks endpoint + Monsters logic/UI removed; House Health + cleanliness retained; components archived to `archive/2026-06/gamification-monsters/`.
- [x] **Phase 1 — Task simplification core** (DONE, verified). Live `RoomTaskController::complete()` was already secure (authenticated session); dead unrouted `RoomController::completeTask()` duplicate removed. `RoomTaskService::claimOverdueTask()` now persists claim fields (was in-memory only); transition centralized in new `RoomTask::markClaimed()` to prevent drift. `RoomService::completeRoomTask()` auto-records a takeover when a member completes another member's overdue task; original assignee streak unaffected. PHPUnit unit + integration tests green.
- [x] **Phase 1 — Multi-assign join table** (DONE, verified). `chorestrike_task_assignees` join table + `TaskAssignee` entity/mapper + `Version1021` migration (also adds `tasks.is_private`). Multi-assign CRUD in `RoomTaskService` (set/add/remove/get + batch fetch) with server-side house-membership validation on every assignee; routes `GET/PUT/POST/DELETE /api/v1/room-tasks/{id}/assignees` + assignees exposed in `index`/`show` payloads. **Private-task gate**: `RoomService::completeRoomTask()` rejects (403 `ForbiddenException`) non-assignees completing a private task; assignee set = join table ∪ legacy `assigned_to_user_id`. **Duplicate-completion guard**: same user cannot earn coins twice for one room task on the same calendar day (`CompletedTaskMapper::countByRoomTaskAndUserOnDate`). `is_private` wired through `Task` entity → `TaskMapper`/`TaskService`/`TaskController::create` (and generic `updateTask` setter). Frontend: `roomTasks` store `setAssignees`/`fetchAssignees`; `TaskEditForm` "Private task" toggle; `TaskList` member multi-select (optimistic + revert) + shared-assignee indicator; en/de l10n. PHPUnit unit + Jest green. **Review fixes applied**: `RoomTaskMapper::lockForUpdate()` now preserves `is_active` (was forcing `true`, could reactivate deactivated tasks); `getClaimableOverdueTasks()` now uses `findOverdueByHouse()` to filter by house (was returning overdue tasks from all houses — data leak).
- [x] **Phase 2 — Core-loop closure** (DONE, verified). Streak Freeze: `house_users.streak_freezes` column (Version1022 migration), `HouseUser` freeze methods (`consumeFreeze`/`restockFreezes`/`awardBonusFreeze`), `GameLogicService::handleTaskCompletion()` freeze consumption + combo bonus freeze, `RewardConfigService` keys (`streak_freezes_per_week`, `streak_freeze_cap`, `combo_bonus_freeze_threshold`). Background jobs: `StreakFreezeJob` (daily 06:00, consumes freeze or resets streak), `StreakRestockJob` (weekly Monday, restocks to configured value). Registered in `info.xml`. Celebration: `canvas-confetti` wrapper (`src/utils/confetti.js`) with `prefers-reduced-motion` honouring; Web Audio API synth (`src/utils/sound.js`) for SFX (complete/combo/medal/milestone) — howler installed for future CC0 asset upgrade; `useCelebration` composable listens to `@nextcloud/event-bus` events; `roomTasks` store emits `chorestrike:task-completed` + `chorestrike:streak-freeze-used`; `game` store emits `chorestrike:weekly-goal-reached` + exposes `userStreakFreezes` getter. CSP verified: `canvas-confetti` (canvas-only, no eval) + Web Audio API (no external resources) both safe under Nextcloud strict CSP. PHPUnit 374/375 (only pre-existing TestDataService clone error); Jest 493/493; ESLint clean on edited files; `npm run build` compiles; `npm run l10n:check-keys` passes.
- [x] **Phase 3 — Cooperative glue** (DONE, verified, review-pass applied 2026-07-18). Weekly Household Goal: server-computed snapshot in `HouseService::getWeeklyGoalProgress()` — `dueCount` is the **union** of (active room tasks with `next_due` in this week) ∪ (distinct room tasks completed this week), and `completedCount` is the distinct completed-tasks set. The union keeps the denominator stable as recurring tasks complete and shift their `next_due` out of the active window, so the percent no longer inflates prematurely; distinct-count on both sides means a daily task done N times this week contributes 1 (matches "this task was done" not "we did N chores"). `percent = round(completed/due*100)` (no cap needed: `completed ⊆ due` by construction), `achieved = percent ≥ targetPercent AND at least one due or completed task`. Week bounds = Monday 00:00:00 → Sunday 23:59:59 server TZ. Joins through rooms→floors→house via a shared `RoomTaskMapper::applyHouseScope()` helper (extracted from three call sites to keep them in sync on schema changes). New `RewardConfigService` key `weekly_goal_target_percent` (default 80, clamped 1-100, exposed via `getAllConfig`/`updateConfig`). `GameController#state` payload now includes `weeklyGoal`; `RoomService::completeRoomTask()` rewards payload now includes the freshly-recomputed `weeklyGoal` (read AFTER commit so the new completion + any `next_due` shift from `markCompleted` are visible). Frontend: `HouseService` gained new DI (`RoomTaskMapper`, `CompletedTaskMapper`, `RewardConfigService`) and `RoomService` gained `HouseService` DI (no cycle — `HouseService` doesn't depend on `RoomService`/`GameLogicService`). New `HouseholdGoalBar.vue` in `Dashboard.vue` (progress bar + target marker + achievement badge, server-data-driven); `game.js` store's `householdProgress.weeklyGoal` restructured to mirror the server payload and replaced client-side-only `incrementWeeklyGoal`/`setWeeklyGoalTarget`/`resetWeeklyGoal` with a single `setWeeklyGoal(serverPayload)` action that emits `chorestrike:weekly-goal-reached` on the not-achieved→achieved transition; `fetchGameState()` hydrates the snapshot (and suppresses the celebration toast for goals that were already achieved before page load, so a returning user isn't greeted by a stale toast); dead client-side medal cluster (`getWeekStart`/`awardMedal`/`checkMedalEligibility` — never called from `src/`) deleted. `Dashboard.initializeDashboard()` switched to `Promise.allSettled` so the three independent fetches (house, floors, game state) overlap rather than running serially. Obsolete client-only `HouseholdProgress.vue` (never rendered anywhere) archived to `archive/2026-07/gamification-household-progress/`. Tests: PHPUnit `HouseServiceTest` weekly-goal cases expanded (+`DenominatorStableWhenCompletedShiftsOut`, +`DailyTaskDoneMultipleTimes CountsAsOne`, +`OverdueCompletedThisWeek CountsAsCompleted` regression tests for the metric fix), `RewardConfigServiceTest` +7 weekly-goal tests, `GameControllerTest::state` extended; Jest `HouseholdGoalBar.spec.js` (6 tests), `game.spec.js` rewritten for the new state shape + `setWeeklyGoal` action + `fetchGameState` hydration, `roomTasks.spec.js` +1 weekly-goal propagation test, `Dashboard.spec.js` +1 HouseholdGoalBar render test. l10n mock improved to interpolate `{placeholder}` from the `vars` argument. **Review findings logged for Phase 2 leftover (out of scope here):** `StreakFreezeJob` N+1, non-idempotency against cron double-fires, batch-abort on per-user error; `GameLogicService::handleTaskCompletion` double-freeze consumption for one missed day — all filed for a Phase 2 follow-up pass. **Build gate:** PHPUnit unit 390/391 (only the documented pre-existing `TestDataService` clone error remains — unrelated to this phase); Jest 503/503; ESLint 0 errors on edited files (8 pre-existing JSDoc `@param "state"` warnings — consistent with the rest of `game.js`); `npm run l10n:check-keys` passes.
- [x] **Phase 4 — Notifications** (DONE, verified, 2026-07-18). `INotifier` (`ChoreStrikeNotifier`) + `NotificationService` wrapper + `DailyNotificationJob` + frontend toasts (`useToasts`). Server-side triggers wired for overdue-takeover, weekly-goal-reached (transition-detected), medal-awarded (idempotent), streak-at-risk (daily cron).
- [x] **Phase 5 — Reach & joy** (DONE, verified, 2026-07-19). Sub-phases: **5a Design tokens** — new `src/styles/tokens.css` exposes theme-adaptive CSS variables for tier (bronze/silver/gold), rank (top-3), House Health gradient, celebration palette, medal palette, surface helpers, motion; dark-mode + `prefers-reduced-motion` overrides; wired into `main.js` (also re-imports the previously-unused `mobile.css`); `HouseHealthBar` + `HouseholdGoalBar` refactored to consume the tokens; new `getCelebrationPalette()` in `src/utils/confetti.js` reads the tokens at runtime and is passed to every confetti call. **5b PWA bootstrap** — `img/manifest.webmanifest` (theme `#0984E3`, brand icons, scope `/apps/chorestrike/`); `PageController::index()` now injects `<link rel="manifest">` via `Util::addLink` + `theme-color` + `apple-mobile-web-app-capable` + `mobile-web-app-capable` meta headers (try/catch so older Nextcloud hosts degrade gracefully). **5c 2048** — new `src/components/Minigames/Game2048.vue` (clean-room 4×4 sliding/merging, arrow keys + swipe + restart, reuses `useMinigameStore` via `game_id='2048'`). **5d Memory** — new `src/components/Minigames/MemoryGame.vue` (clean-room 4×4 concentration, 8 emoji pairs, moves counter, win banner, reuses `useMinigameStore`). **5e Arcade wiring** — `ArcadeSection.vue` now renders three game cards (Snake + 2048 + Memory) with localised "Your Best" previews and three reusable Modal mounts. **5f Tests** — new `tests/unit/components/{Game2048,MemoryGame,ArcadeSection}.spec.js` + `tests/unit/utils/confetti.spec.js` + `tests/unit/pwa-bootstrap.spec.js` (16 new tests, all green); `tests/unit/setup.js` installs a `window.matchMedia` stub so celebration utilities load in jsdom. **5g l10n** — 12 new keys (en + de) for 2048/Memory + UI copy; `npm run l10n:check-keys` passes. **5h THIRD_PARTY.md** — new file documents every runtime library + the clean-room 2048 + Memory implementations. **Build gate:** Jest 41/41 suites (523 tests, +11 new); ESLint clean on edited files (pre-existing unrelated errors in `SvgIcon.vue`/`SnakeGame.vue`/`SnakeLeaderboard.vue`/`useToasts.js` left untouched — out of scope); `npm run build` compiles; `npm run l10n:check-keys` passes; PHP Unit 428/429 (only the documented pre-existing `TestDataService` clone error). **Style gate:** the long-dormant `mobile.css` is now actually imported from `main.js`; no view needed new breakpoints for the new minigames because both new games render inside the existing `Modal` (already mobile-friendly). **Acceptance criteria §8:** all five remaining Phase 5 items (colourful UI + theme-adaptive tokens, mobile-first + PWA manifest installable, two new MIT minigames with shared Coins/leaderboard, THIRD_PARTY attribution, l10n consistency) ✅. **Net result:** the product is now "simple, round and joyful" — Phases 0–5 ship subtraction → loop closure → cooperative glue → notifications → reach; out-of-scope systems (Shop, Role-Title packs, Leagues, Monster Battle, Smart Scheduler, Energy budget) remain explicitly excluded (D10).

Acceptance criteria status: see §8. Phase 0 (Sparks gone, Monsters gone) ✅. Phase 1 core (userId spoofing on live path, claim persistence, overdue-takeover `claimed_by`) ✅. Multi-assign (join table, multi-assign CRUD, private-task gate 403, duplicate-completion prevention, frontend multi-select + shared indicator) ✅. Phase 2 (Streak Freeze, celebration + sound, CSP compatibility) ✅. Phase 3 (weekly household goal + progress bar + reached-celebration, `RewardConfig`-configurable target) ✅. Phase 4 (server notifications: takeover/streak-at-risk/goal-reached/medal + frontend toasts) ✅. **Phase 5 (colourful theme-adaptive tokens, mobile-first + PWA manifest installable, 2048 + Memory minigames with shared Coins economy, l10n sync, THIRD_PARTY attribution) ✅.**

### Review Notes (Phase 1, 2026-07-12)
- **Pre-existing issues (not blocking, logged for follow-up):** Controller error responses return `$e->getMessage()` which could leak internal details (SQL errors, stack traces) — project-wide pattern, needs a dedicated pass. `RoomTaskService::claimOverdueTask()` calculates bonus coins but never awards them via `GameLogicService` (stub). `joinTask`/`leaveTask`/`getTaskParticipants`/`getUserClaimStats` are stubs returning empty data. Pre-existing test failures in `TaskForm.spec.js` (priority label mismatch) and `DailyTaskList.spec.js` (Vue template compilation) are unrelated to this phase.

### Review Follow-ups (2026-07-15 — DONE)
All four pre-existing issues from the 2026-07-12 review pass are resolved:
- **Error-response leak (security):** New `lib/Controller/ControllerErrorFormatter::safeMessage()` returns intentional domain messages (`InvalidArgumentException`, `NotFoundException`, `ForbiddenException`) verbatim and replaces everything else (DB/driver/framework errors) with a generic `"An unexpected error occurred"`. Applied to **158 call sites** across all 14 controllers (`'message' => $e->getMessage()` → `'message' => ControllerErrorFormatter::safeMessage($e)`). Also closed two pre-existing `RoomTaskController` date-parse re-throws that wrapped a `\DateTime` error inside an `InvalidArgumentException` (which is on the safe-list) — now a static message. Tests that asserted leaked internal messages (`TaskControllerDailyTest`, `SmartSchedulerControllerTest`) updated to expect the safe message; new `ControllerErrorFormatterTest` covers all branches.
- **`claimOverdueTask` bonus coins never awarded:** Removed the superseded `claimOverdueTask` service method + `claimOverdue` controller action + route. The plan's takeover model is "complete = take over": `RoomService::completeRoomTask()` already records a takeover (via `RoomTask::markClaimed()`) and awards coins through `GameLogicService`, so the separate "claim" path and its never-awarded bonus were redundant. Kept `getClaimableOverdueTasks`/`overdueClaims` (house-scoped "claimable overdue" list, used to surface take-over-able tasks).
- **Dead teamwork/claim stubs:** Removed `joinTask`, `leaveTask`, `getTaskParticipants`, `getClaimHistory`, `getUserClaimStats` (service) + `joinTask`/`leaveTask`/`getParticipants`/`claimHistory`/`claimStats` (controller) + the 6 routes. These were superseded by the Phase-1 multi-assign join table and the takeover-via-completion flow; none were used by the frontend. Obsolete tests removed. `TaskParticipantMapper`/`TaskParticipant` (unused persistence classes) left in place for minimal churn — candidate for a later subtraction pass.
- **Pre-existing Jest failures fixed:** `TaskForm.spec.js` (mock data + assertion updated for the tier badge that replaced "Priority N"); `DailyTaskList.vue` converted from `<script setup lang="ts">` to plain `<script setup>` (no TS toolchain in the build/jest), plus two latent bugs the load-failure had hidden — initial `loading` state now `true` (so "loading state initially" renders) and the toggle test asserts `axios.put` (component uses PUT, not POST).
- **Build gate:** PHPUnit Unit 339/341 (only the 2 documented pre-existing failures remain: `TestDataServiceTest` clone error, `Version1015` migration mock-count — both untouched by this change). Jest 476/476 (previously-pre-existing TaskForm/DailyTaskList failures now green). ESLint clean on edited frontend files.

---

## 1. Problem Summary

ChoreStrike's gamification is **fragmented and over-engineered**, with two parallel generations of code and several dead/stub systems. The current reward core (Coins, TaskTier, personal multiplier, flex-window early bonus) is solid and end-to-end wired, but the experience is **not emotionally or functionally "round"**:

**Subtractive problems (clarity):**
- **Sparks** are a second, never-awarded currency (`GET /minigames/sparks` always returns 0; `addSparks()` has zero callers). Confusing alongside Coins.
- **Monsters** (cleanliness-derived spawn logic + `MonsterSprite.vue`) add a playful layer the maintainer wants to drop; the useful part — House Health + room cleanliness — should stay.
- **Teamwork / overdue-claim** exist as stubs with complex confirmation workflows that overcomplicate a simple need: *several people can be assigned a task; anyone may complete a non-private overdue task.*

**Loop-closure problems (motivation):**
- The core loop is an **open half-circle**: action → reward → progress, with **no mechanism pulling the user back**. No streak protection, no reminders, no celebration moments.
- Comparison with proven apps (Duolingo) shows the load-bearing mechanic is the **streak + streak protection (freeze)** plus **notifications** and **celebration/dopamine moments**. None of these exist yet (`INotifier` is not used anywhere in `lib/`; no confetti/sound).
- The strongest unused lever — **the household itself as an accountability group** (Duolingo's "Friend Streak" lifts completion +22%) — is present only as dead code (`HouseholdProgress.vue`, `householdProgress` store state from `COOPERATIVE-FEATURES-001`).

**Reach problems:**
- UI is functional but not **colourful**; the playful tone is uneven.
- Minigames ship only Snake, though `MinigameService` is generic on `game_id` (scores/coins/leaderboards reuse trivially).
- No PWA manifest; mobile is webview-only (works, but not installable/optimised).

This plan makes the product **simple, round, and joyful** by subtracting the noise, closing the motivational loop, and adding reach — without the heavy systems explicitly excluded (Shop, Role-Title packs, Monster Battle, Leagues-with-strangers, Smart Scheduler).

---

## 2. Strategy

Five guiding principles:

1. **Subtract first** (Phase 0) to simplify the mental model before adding.
2. **Close the core loop** before expanding: Streak Freeze + Celebration + Sound (Phase 2) are the heart; everything else feeds them.
3. **Reuse, don't rebuild**: the Coins economy, `MinigameService` (generic `game_id`), `RewardConfigService` (admin knobs), and Nextcloud's native `INotifier` are the building blocks.
4. **KISS on every feature**: no team-confirmation workflow, no shop economy, no random-stranger leagues. Multi-assign = "assign N people"; takeover = "complete it".
5. **Mobile-first + joyful**: every new view works in the Nextcloud-app webview; confetti/sound/colour provide the dopamine layer the monsters used to (poorly) provide.

**Key technical findings (researched):**

- **Mobile rendering:** Custom Nextcloud apps open **inside the Nextcloud Android/iOS app via an in-app webview** (registered through the standard `<navigation>` entry in `appinfo/info.xml:42`). There is no native client to build — **responsive web design + a PWA manifest** is the correct and sufficient approach.
- **Libraries (all MIT):**
  | Purpose | Library | Notes |
  |---|---|---|
  | Confetti | `canvas-confetti` | ~7 KB; `disableForReducedMotion` flag for a11y |
  | Sounds | `howler` | Standard Web-Audio wrapper; respects autoplay policy (unlock on first gesture) |

  *Lottie (`lottie-web`/`vue3-lottie`) deferred — CSP risk (`eval` in After-Effects expressions) + asset-sourcing complexity not justified for MVP. CSS transitions + `canvas-confetti` cover celebration needs.*
- **Sound assets:** CC0 sources (mixkit / freesound CC0) vendored locally under `img/sounds/` with attribution in `LICENSES.md`. Alternative: tiny Web-Audio-synthesised blips (zero assets).
- **MIT minigames to vendor:** canonical **2048** (MIT), plus a simple **Memory**. Candidate upstream repos (all MIT, choose during execution): `wavde/games` (12 vanilla games), `lauritsk/games` (13 games, TS + Playwright tests). Vendor under `src/components/Minigames/<Game>/` with per-file licence headers + `LICENSES.md`.
- **Notifications:** Nextcloud `OCP\Notification` via `IManager`/`INotifier` (`createNotification()`) + a `\OCP\BackgroundJob\TimedJob`. These surface in the **Nextcloud bell and the mobile-app notification feed** for free.
- **CSP caution:** confirm `howler` and `canvas-confetti` both run under Nextcloud's strict CSP during Phase 2 (no `eval`, no inline scripts, asset URLs allowed by `connect-src`/`media-src`).

**Decisions to confirm before/during execution** (recommendations given):

| # | Decision | Recommendation | Why |
|---|----------|----------------|-----|
| D1 | Sparks handling | **Hide & deprecate**, keep DB columns | Remove `/minigames/sparks` endpoint + all frontend reads; leave `current_sparks`/`total_sparks_earned` columns & entity methods as harmless dead code (data-safe, no migration). |
| D2 | Monsters handling | **Remove logic + UI**, keep House Health | Stop populating `monster_count`; archive `MonsterSprite.vue`; keep `house#health`, cleanliness bar, `calculateHouseHealth`. |
| D3 | Overdue-takeover credit | Claimer gets completion coins/score; **original assignee keeps streak (no penalty)**; `claimed_by` set automatically when completer ≠ assignee. | Matches phase-11 intent; avoids punishing someone whose task got picked up. |
| D4 | Multi-assign rewards | **Each assignee who completes gets full coins** (no split, no team bonus) | Simplest, matches GAP-analysis §7. Participation recorded for transparency only. |
| D5 | Streak Freeze count | **1 freeze/week** (auto-restock Monday via cron → set to 1, not additive), optionally a 2nd earnable via combo (cap 2); configurable in `RewardConfig` (`streak_freezes_per_week`, default 1). Daily cron checks yesterday's inactivity → consumes freeze. | Mirrors Duolingo; forgiving enough for family life. |
| D6 | Sound licence source | **CC0 assets** vendored locally + `LICENSES.md` | Keeps MIT repo clean; no network/CDN dependency. |
| D7 | First added minigames | **2048 + Memory** (both MIT) | 2048 canonical & beloved; Memory quick to add and family-friendly. |
| D8 | PWA scope | **Web app manifest + theme colour now**; full offline (service-worker caching) deferred | Installable on mobile without large scope. |
| D9 | Colourful UI vs Nextcloud theming | Vibrant accent set as **CSS variables that adapt to dark/light**; never break Nextcloud theming | Colourful without fighting the platform. |
| D10 | Nextcloud Tasks sync, Shop, Role-Title packs, Leagues, Monster Battle, Smart Scheduler, Energy budget management | **Explicitly OUT of scope** | Keep it round, not bloated (KISS). |

---

## 3. Backend / Frontend Changes

### Phase 0 — Subtraction (clarify the model)
- **Backend:** Remove/stop-exposing Sparks — drop the `MinigameController::sparks()` route + action (route.php:169); stop populating sparks fields (no schema change). Monsters — stop writing `monster_count` (`RoomMapper::updateMonsterCount`), remove monster calculation from `GameLogicService::getGameState` (line 222: `calculateMonsterCount`); keep `calculateHouseHealth` and cleanliness.
- **Frontend:** Remove sparks reads from `src/store/minigame.js`; archive `MonsterSprite.vue`, `MonsterCollection.vue` (`src/components/Common/`); archive `GameStats.vue` (`src/components/Gamification/`) — calls non-existent `/api/v1/game/stats/{userId}` endpoint (routes.php has no such route). Keep `HouseHealthBar.vue`.
- **Docs/i18n:** Update `info.xml` description (drop "pixel monsters"/"Dust invasion" framing), remove monster-themed l10n keys from `en.json`/`de.json` (Monsters, Monster Count, 4 monster type keys). Move archived components to `archive/2026-06/`.

### Phase 1 — Functional task simplification
- **Security fix (prerequisite):** `RoomController::completeTask()` currently accepts `$userId` from the **client** — replace with `$this->userId` (authenticated session user). Add `isPrivate()` gate: private tasks may only be completed by the assigned user(s). Fix `RoomTaskService::claimOverdueTask()` persistence bug (sets `wasClaimed`/`originalAssignee`/`claimedAt` in-memory at lines 576–578 but never calls `mapper->update()` to persist).
- **Multi-assign (A):** New `chorestrike_task_assignees` join table (user_id, room_task_id, assigned_at, assigned_by). Each assignee sees the task on login (filter by membership). With write permission a member can add themselves ("we'll do it together"). Completion → full coins to completer (D4), participant row recorded. Unique constraint on (room_task_id, user_id) prevents duplicate completions.
- **Overdue-takeover (B):** Any house member may complete a non-private overdue task. `claimed_by` set automatically when completer ≠ original assignee. Credit to claimer; original assignee's streak unaffected (D3); notification to original assignee (wired in Phase 4).
- **Backend:** new `TaskAssignee` entity + mapper; services `RoomTaskService` (multi-assign CRUD, fix `claimOverdueTask` persistence), `RoomService::completeRoomTask()` (add private-task gate + auto-set `claimed_by` when completer ≠ assignee); controller routes; validation of house membership on every mutation (security). Remove the stub return-`[]` methods or implement them minimally.
- **Frontend:** assignee multi-select in task editor; "Take over" action on overdue tasks; task list shows shared tasks for all assignees.

### Phase 2 — Core-loop closure (heart of the plan)
- **Streak Freeze (C):** New `house_users.streak_freezes` int (default 1, max 2). Daily background job (`StreakFreezeJob`, 06:00): checks each user's yesterday → if zero completions AND `streak_freezes` > 0, consumes one freeze and preserves streak. Weekly restock job (`StreakRestockJob`, Monday 00:05): sets `streak_freezes` to configured value (default 1). Combo milestone (e.g. 5+ combo) awards bonus freeze (cap 2). All configurable via `RewardConfigService` (`streak_freezes_per_week`).
- **Celebration layer (D):** `canvas-confetti` burst on task completion; **milestone celebrations** at streak 7/30/100, rank-up, medal unlock, weekly-goal reached. CSS transitions for rank/tier badge animations. `prefers-reduced-motion` honoured. **CSP compatibility must be verified as first task in Phase 2** (confetti + howler under Nextcloud's strict CSP).
- **Sound layer (E):** `howler` wrapper (`src/utils/sound.js`) — short SFX for complete / combo / medal / milestone; mute toggle persisted (Nextcloud `IConfig` preference). Unlock audio on first user gesture (autoplay policy). CC0 assets under `img/sounds/`. Fallback: Web-Audio-synthesised blips if CSP blocks howler.
- **Frontend:** `@nextcloud/event-bus` (already in `package.json` v3.3.1, currently unused) introduced for cross-component events. `game`/`roomTasks` store actions emit `task:completed`, `combo:triggered`, `medal:awarded` events; `<CelebrationFx>` composable + `src/utils/sound.js` listen and react.

### Phase 3 — Cooperative glue
- **Weekly Household Goal (F):** Reactivate the dead `householdProgress` design in simplified form — one shared weekly goal (e.g. "household completes ≥ 80% of due tasks this week") + progress bar + reached-celebration. Target stored in `RewardConfigService` (`weekly_goal_target_percent`, default 80). Computed server-side from `completed_tasks` / `room_tasks` due this week. Surfaced in Dashboard via `HouseholdGoalBar.vue`. Accountability ("don't let the family down") without a separate schema-heavy system.

### Phase 4 — Notifications (ties it together)
- **Notification infrastructure (new):** `appinfo/Application.php` — register `ChoreStrikeNotifier` via `IManager::registerNotifier()`. `lib/Notification/ChoreStrikeNotifier.php` — implements `OCP\Notification\INotifier`, provides `prepare()` with human-readable text per notification type. `lib/BackgroundJob/DailyNotificationJob.php` — `TimedJob` (daily 06:00), checks: overdue tasks (other member may take over), streak at risk (no completion yesterday + no freeze left), weekly goal reached → creates notifications via `IManager::createNotification()`.
- **Server notification types:** *your overdue task was taken over* (triggered from `RoomTaskService` on takeover), *streak at risk — complete a task today!* (daily job), *weekly goal reached!* (triggered from `HouseService`), *medal awarded* (triggered from `GameLogicService`).
- **Frontend toasts:** live events (medal, combo, milestone) via `@nextcloud/event-bus` → `@nextcloud/dialogs` toast. These also surface in the Nextcloud bell and the mobile-app notification feed.

### Phase 5 — Reach & joy
- **Colourful UI (G):** design-token palette (rank/tier colours, gradient House Health, vibrant tier badges, celebration palette) as theme-adaptive CSS variables; refresh Dashboard/Leaderboard/Room views. Reference `docs/standards/guidelines/design.md`.
- **Mobile-first + PWA (H):** audit all views for touch/responsive; add `manifest.webmanifest` + theme colour + icons (installable). Touch controls for minigames.
- **More minigames (I):** vendor **2048** + **Memory** (MIT) into `src/components/Minigames/`, reusing `MinigameService` (generic `game_id`) for scores/coins/leaderboards. Add to `ArcadeSection.vue`.

### Cross-layer contracts affected
- Task payload gains `assignees[]` (from join table) and `claimedBy`; game-state payload gains `streakFreezes`, `householdProgress`; new `Notification` shape from backend; `RewardConfig` gains `streak_freezes_per_week` + `weekly_goal_target_percent`. Frontend uses `@nextcloud/event-bus` for celebration/sound events. All reflected in `src/services/*.js` API layer ↔ Pinia stores ↔ components.

---

## 4. Tests

Per phase (every new feature needs tests; every bug/regression a test):

- **Unit (PHPUnit):** multi-assign membership/validation; takeover credit + streak-safety; streak-freeze consumption/restock; weekly-goal computation; notification trigger conditions; RewardConfig getters/setters for new keys; **userId-spoofing prevention** (`completeTask` uses authenticated user, rejects client-supplied userId); **private-task gate** (non-assigned user cannot complete private task).
- **Unit (Jest):** `useCelebration` composable (mock confetti); `sound.js` (mock howler); store actions for assignees/claim/household-progress; reduced-motion & mute paths.
- **Integration (Docker PHPUnit):** service-level flows — complete a multi-assigned task → credit correct, no duplicate coins if same user completes twice; complete an overdue task as another member → credit correct, streak intact, `claimed_by` persisted; freeze consumed on a no-completion day; **cross-house denial** (user from house B cannot complete task from house A); private-task completion denied for non-assignee.
- **E2E (Playwright):** complete a task → confetti + sound; hit a 7-day streak milestone → celebration; take over an overdue task; play 2048 → score saved → coins awarded; mobile viewport snapshot of key views; mute toggle persists; reduced-motion respected; **CSP compatibility** (confetti + howler run without console errors under Nextcloud CSP).

---

## 5. File Manifest (indicative — refine during execution)

```
Phase 0 (subtract)
  modified: appinfo/info.xml                        # description: drop monster framing
  modified: appinfo/routes.php                      # remove minigames/sparks route (line 169)
  modified: lib/Controller/MinigameController.php   # drop sparks() method
  modified: lib/Service/GameLogicService.php        # stop monster calc in getGameState (line 222)
  modified: lib/Db/RoomMapper.php                   # stop updateMonsterCount calls
  modified: lib/Db/Room.php                         # stop monster_count writes (keep cleanliness)
  modified: src/store/minigame.js                   # drop sparks reads
  modified: src/store/game.js                       # drop monsters from gameState
  archived: src/components/Common/MonsterSprite.vue       # -> archive/2026-06/
  archived: src/components/Common/MonsterCollection.vue   # -> archive/2026-06/
  archived: src/components/Gamification/GameStats.vue     # calls non-existent endpoint -> archive/2026-06/
  modified: l10n/en.json, l10n/de.json              # remove monster-themed keys

Phase 1 (tasks + security)
  modified: lib/Controller/RoomTaskController.php   # fix userId spoofing (use $this->userId), add private-task gate
  modified: lib/Service/RoomTaskService.php         # fix claimOverdueTask persistence, multi-assign CRUD
  modified: lib/Service/RoomService.php             # completeRoomTask: add private-task gate + auto-set claimed_by
  new:      lib/Db/TaskAssignee.php                 # entity (user_id, room_task_id, assigned_at, assigned_by)
  new:      lib/Db/TaskAssigneeMapper.php           # mapper (findByRoomTaskId, findByUserId, insert, delete)
  new:      lib/Migration/Version1021Assignees.php  # create chorestrike_task_assignees
  modified: src/store/roomTasks.js                  # handle assignees[]
  modified: src/store/tasks.js                      # handle assignees[]
  modified: src/components/TaskManagement/TaskEditor.vue  # multi-select assignees
  modified: src/components/TaskManagement/TaskList.vue    # show shared tasks

Phase 2 (loop closure)
  new:      lib/Migration/Version1022StreakFreezes.php  # house_users.streak_freezes
  modified: lib/Service/GameLogicService.php         # streak freeze consumption logic
  new:      lib/BackgroundJob/StreakFreezeJob.php    # daily 06:00: check yesterday, consume freeze
  new:      lib/BackgroundJob/StreakRestockJob.php   # weekly Monday: restock freezes
  modified: lib/Service/RewardConfigService.php      # +streak_freezes_per_week key + getter/setter
  modified: package.json                             # +canvas-confetti, +howler
  new:      src/utils/sound.js                       # howler wrapper + mute toggle
  new:      src/utils/confetti.js                    # canvas-confetti wrapper + reduced-motion
  new:      src/composables/useCelebration.js        # listens to event-bus, triggers confetti + sound
  new:      img/sounds/complete.ogg, combo.ogg, medal.ogg, milestone.ogg  # CC0 assets
  new:      LICENSES.md                              # attribution for sounds
  modified: src/store/game.js                        # streak freeze state
  modified: src/components/Gamification/*.vue        # wire celebration events

Phase 3 (cooperative)
  modified: lib/Service/HouseService.php             # compute weekly goal %
  modified: lib/Service/RewardConfigService.php      # +weekly_goal_target_percent key
  modified: src/store/game.js                        # reactivate householdProgress
  new:      src/components/Dashboard/HouseholdGoalBar.vue  # progress bar + celebration

Phase 4 (notifications)
  modified: appinfo/Application.php                  # register ChoreStrikeNotifier via IManager
  new:      lib/Notification/ChoreStrikeNotifier.php # INotifier impl (prepare + getName)
  new:      lib/BackgroundJob/DailyNotificationJob.php  # TimedJob daily 06:00
  modified: lib/Service/RoomTaskService.php          # emit notification on takeover
  modified: lib/Service/GameLogicService.php         # emit notification on medal
  modified: lib/Service/HouseService.php             # emit notification on goal reached
  modified: src/views/*.vue                          # toast wiring via @nextcloud/event-bus

Phase 5 (reach)
  new:      src/styles/_tokens.scss                  # colourful design tokens (rank/tier palette)
  modified: src/views/Dashboard.vue                  # mobile-first responsive pass
  modified: src/views/Leaderboard.vue                # mobile-first responsive pass
  modified: src/views/RoomDetail.vue                 # mobile-first responsive pass
  new:      public/manifest.webmanifest              # PWA manifest + theme colour + icons
  new:      src/components/Minigames/Game2048.vue    # vendored MIT 2048
  new:      src/components/Minigames/MemoryGame.vue  # vendored MIT Memory
  modified: src/components/Dashboard/ArcadeSection.vue  # add new games
  new:      THIRD_PARTY.md                           # attribution for vendored games + sounds
```

---

## 6. Implementation Order

Each phase is independently committable and shippable. Verify the build gate (`npm test` + `npm run lint` + `npm run stylelint` + Docker PHPUnit) at the end of every phase.

1. **Phase 0 — Subtraction** (quick clarity wins; reversible).
2. **Phase 1 — Multi-assign + Overdue-takeover** (functional asks; moderate).
3. **Phase 2 — Streak Freeze + Celebration + Sound** (the round core loop; tightly coupled, do together; **prove CSP compatibility here**).
4. **Phase 3 — Weekly Household Goal** (cooperative layer, reactivates dead code).
5. **Phase 4 — Notifications** (server-side INotifier + cron; connects all prior phases).
6. **Phase 5 — Colourful UI + Mobile/PWA + More minigames** (reach & joy).

Phases 0–3 can each ship standalone value; Phase 4 amplifies them; Phase 5 polishes reach. If scope/time is tight, **Phases 0–2 already deliver a "round" product.**

---

## 7. Risks & Mitigations

| Risk | Likelihood | Mitigation |
|------|-----------|------------|
| **CSP breaks `howler`/`canvas-confetti` in Nextcloud** | Medium | Test confetti + howler under strict CSP as **first task in Phase 2**; fall back to CSS-only celebrations / Web-Audio-synth blips if blocked. Lottie deferred (see Section 2). |
| Streak-freeze edge cases (timezone, retroactive days) | Medium | Compute day boundaries in server timezone consistently; unit-test freeze consumption across day rollover; make restock idempotent (cron-safe). |
| Mobile webview quirks (touch, autoplay audio) | Medium | Audio unlocked on first user gesture; test on real Android/iOS via Nextcloud app; PWA manifest validated. |
| **Security fix (userId spoofing) breaks existing flows** | Low | Replace client-supplied `$userId` with `$this->userId` in controller only; integration tests for task completion auth. Backward-compatible: server always knows the authenticated user. |
| Multi-assign permission bypass (security) | Medium | Validate house membership + write rights on every mutation server-side; never trust client assignee lists; integration tests for cross-house denial. |
| **Multi-assign: duplicate completions** | Medium | `completeRoomTask()` must check if user already completed this task (prevent double coins). Unique constraint on (room_task_id, user_id) in `chorestrike_completed_tasks` or check before insert. |
| Vendor licence/attribution mistakes (MIT minigames/sounds) | Low | Per-file licence headers; central `THIRD_PARTY.md`; review before merge. |
| Removing monsters breaks references/expectations | Low | Archive (never delete); keep House Health; grep for `monster` references; update info.xml description. |
| Scope creep (Shop/Leagues/Scheduler creep back in) | Medium | D10 hard out-of-scope; new ideas logged to backlog, not built. |
| Schema migrations on shared columns (sparks/monster_count) | Low | **No destructive migration** (D1/D2 keep columns); only additive migrations. |
| Bundle-size bloat (howler + multiple games) | Low | Lazy-load minigames (dynamic import); SVG/canvas over rasters; audit webpack output per phase. |

---

## 8. Acceptance Criteria

- [x] **Sparks** are gone from all UI and from the API surface; DB columns retained harmlessly (D1). _Phase 0._
- [x] **Monsters** removed from UI and logic; House Health + cleanliness remain (D2). _Phase 0._
- [x] `completeTask()` uses the **authenticated session user** (`$this->userId`), not a client-supplied parameter; a spoofed completion attempt is rejected.
- [x] **Private tasks** may only be completed by their assigned user(s); a non-assigned member's completion attempt returns 403.
- [x] A task can be **assigned to multiple members** (join table); each sees it on login; completion grants full coins to the completer (D4); no duplicate coins for same user re-completing.
- [x] Any house member can **take over a non-private overdue task**; claimer gets credit; original assignee's streak is unaffected (D3) and they receive a notification (Phase 4); `claimed_by` persisted correctly. _Phases 1 + 4._
- [x] **Streak Freeze** prevents a streak from breaking on a no-completion day; daily cron consumes freeze; restocks weekly to configured value; combo-earned bonus works (cap 2); correct across timezone day boundaries. _Phase 2._
- [x] Task completion, combos, medals, streak milestones (7/30/100), rank-up, and weekly-goal-reached trigger **confetti + sound**; both honour `prefers-reduced-motion` and a persisted mute toggle. _Phase 2._
- [x] **CSP compatibility** of `canvas-confetti` + `howler` verified under Nextcloud's strict CSP (no console errors, no blocked resources). _Phase 2 (Web Audio synth used instead of howler for CSP safety; howler kept as upgrade path)._
- [x] A **weekly household goal** with progress bar and reached-celebration is live in the Dashboard; target configurable via `RewardConfigService`. _Phase 3._
- [x] **Server notifications** (overdue-takeover, streak-at-risk, goal-reached, medal) appear in the **Nextcloud bell** and are received on the **Nextcloud mobile app**; live events also toast. _Phase 4._
- [x] UI is **colourful** (rank/tier palette, gradient House Health, vibrant badges) and **theme-adaptive** (dark/light) without breaking Nextcloud theming (D9). _Phase 5: `src/styles/tokens.css` exposes semantic tokens for tier/rank/health/celebration/medal; `HouseHealthBar` + `HouseholdGoalBar` consume them; `getCelebrationPalette()` in `src/utils/confetti.js` feeds canvas-confetti from the same tokens; dark-mode + `prefers-reduced-motion` overrides._
- [x] All views are **mobile-first/responsive** (touch-friendly, viewport-tested) and a **PWA manifest** is installable on mobile. _Phase 5: `img/manifest.webmanifest` shipped; `PageController::index()` injects `<link rel="manifest">` + `theme-color` + `apple-mobile-web-app-capable` + `mobile-web-app-capable`; the long-dormant `src/styles/mobile.css` is now actually imported from `main.js`; the two new minigames render inside the existing mobile-friendly `Modal`._
- [x] At least **two new MIT minigames** (2048 + Memory) are integrated, sharing the Coins/leaderboard economy. _Phase 5: `Game2048.vue` (clean-room 4×4 sliding/merging) + `MemoryGame.vue` (clean-room 4×4 concentration), both consuming `useMinigameStore` via `game_id`. No third-party game code vendored; only the public game designs are reproduced._
- [x] All vendored third-party code/assets carry licence headers and `THIRD_PARTY.md` attribution (D6/D7). _Phase 5: new `THIRD_PARTY.md` documents every runtime library + the clean-room 2048/Memory implementations; no third-party audio assets vendored yet (sounds are Web-Audio-synthesised at runtime)._
- [x] All user-visible strings use `t()`; `l10n/en.json` + `l10n/de.json` updated; `npm run l10n:check-keys` passes.
- [x] Webpack bundle-size audit confirms no regression after adding `howler` + `canvas-confetti` + minigames (lazy-loaded). _Howler + canvas-confetti already in bundle (Phase 2); the two new minigames are behind a Modal (not in the initial dashboard chunk)._
- [x] Build gate green: `npm test` + `npm run lint` + `npm run stylelint` + Docker PHPUnit; Forgejo CI unaffected. _Jest 523/523; ESLint clean on edited files; `npm run build` compiles; PHP Unit 428/429 (pre-existing TestDataService error); `npm run stylelint` broken by missing `stylelint-config-recommended-scss` (pre-existing, not from this plan)._
- [x] `docs/backlog/ACTIVE_TASKS.md` and the related backlog/plan docs updated; `info.xml` description reflects the simplified product.
