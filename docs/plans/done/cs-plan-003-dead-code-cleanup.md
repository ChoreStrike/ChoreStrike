# CS-PLAN-003: Dead-Code Cleanup & Build-Gate Hygiene

> **Status:** Done (implemented 2026-07-19)
> **Effort:** M
> **Priority:** P1
> **Depends on:** none (independent of CS-PLAN-002; safe to start)
> **Related:** `docs/backlog/bugs.md` (P1 "Pre-existing test/lint gate failures"), `docs/backlog/phase-9-code-quality.md` (open follow-ups), `docs/plans/template.md`

## Implementation Summary (2026-07-19)

All 6 phases executed in one logical commit.

- **Phase 0 — Build gate:** ESLint 0 errors (SvgIcon v-for key, SnakeGame unused watch/purchasedBoosts, SnakeLeaderboard unused emit, TaskForm span-in-option fixed). Stylelint config loads after installing `stylelint-config-recommended-scss@14` + `stylelint-config-recommended-vue` + `postcss-html`; 133 "Unknown rule" errors remain (upstream `@nextcloud/stylelint-config@2.4.0` vs `stylelint@16.26.1` version mismatch — unfixable, doc in bugs.md). PHPUnit 425/426 (pre-existing `TestDataServiceTest` clone error untouched).
- **Phase 1 — `lang="ts"` audit:** No-op — all 12 `lang="ts"` files are dead components archived in Phase 2.
- **Phase 2 — Dead Vue archive:** 15 components archived to `archive/2026-07/legacy-unwired/` (SchedulingView + 5 Scheduling/* + 4 Gamification/* + 3 Common/* + 2 UserManagement/*). 3 stale test files deleted (MedalList, MedalDetails, ComboNotification specs). Jest 490/490 (38 suites).
- **Phase 3 — Dead PHP archive:** 6 entities/mappers archived (TaskClaim* + TaskParticipant* + TaskScope* + 2 TaskScope test files).
- **Phase 4 — `logSyncEvent`:** Removed the no-op method + 3 call sites from `TaskSyncService` (Option B). TaskSyncService tests pass unchanged.
- **Phase 5 — Docs & meta:** ARCHITECTURE.md tree updated, GameStats/Monster refs removed from ARCHITECTURE + ROADMAP, phase-10 annotated obsolete, SmartScheduler `@deprecated` removed, bugs.md P1 section updated, ACTIVE_TASKS.md entry added.

---

## 1. Problem Summary

The 2026-07-19 codebase review surfaced a **large, persistent block of dead code, unwired components, and broken build-gate items** that survived the CS-PLAN-001 cleanup and CS-PLAN-002 simplification. They inflate maintenance cost, distort documentation, and keep the build gate red:

- **Build gate is not green** — `npm run lint` has 25 errors / 63 warnings; `npm run stylelint` fails on a missing dependency; one PHPUnit error (`TestDataServiceTest` clone) is logged but unfixed. None of these are introduced by recent work, but the gate has been red for months.
- **~4 700 lines of unwired Vue components** (`SchedulingView.vue` + the 5 dead `Scheduling/*` components + 4 dead `Gamification/*` components + `ComboNotification.vue` + `MedalList.vue` + `MedalDetails.vue` + the two `UserManagement/*` components) are not reachable from `src/router.js`, call non-existent API endpoints, or both.
- **~520 lines of unwired PHP entities/mappers** (`TaskClaim*`, `TaskParticipant*`, `TaskScope*`) are not referenced anywhere outside their own files. Remnants of the original "team claim" concept that Phase 1 of CS-PLAN-002 removed from the service layer.
- **Backend placeholder** — `TaskSyncService::logSyncEvent()` is an empty method body called 3× (currently does nothing).
- **`lang="ts"` in 13 Vue components** without any TypeScript toolchain in the build (0 `.ts` files in `src/`; no `@babel/preset-typescript` or `ts-loader`). This is the same latent bug CS-PLAN-001 Phase 1 fixed for `DailyTaskList.vue`.
- **Raw `console.error` in production code** (5× in unwired `UserManagement/*` components) — violates `docs/standards/LOGGING.md`; harmless while the components are dead, but must be fixed before any re-enablement.
- **Documentation drift** — `docs/ARCHITECTURE.md` and `docs/ROADMAP.md` still reference archived components (`MonsterSprite`, `MonsterCollection`, `GameStats`, `updateMonsters()`).
- **`SmartSchedulerService` carries `@deprecated`** but is still actively routed via 7 routes in `appinfo/routes.php` — the deprecation notice is misleading.
- **`docs/backlog/ACTIVE_TASKS.md` shows cs-plan-002 uncommitted** (`deleted` + `untracked` in `git status`); the file move `active/ → done/` was made locally without `git mv`.

This plan subtracts the noise, restores the build gate, and updates the documentation — **without changing any active feature**. Each phase is independently committable and reversible via `git revert`.

---

## 2. Strategy

Five guiding principles:

1. **Subtract before changing** — archive (do not delete) every unwired artefact under `archive/2026-07/legacy-unwired/` (per `project-rules.md` §5). Archived content is **gitignored** and stays locally for reference.
2. **Restore the build gate first** — auto-fixable lint and stylelint config issues are mechanical and low-risk. Fix the `lang="ts"` drift in a single small phase, since the missing TS toolchain makes the type annotations fiction anyway.
3. **Delete only after verifying zero callers** — every archived file must pass an automated `grep` that proves it is not imported, required, or referenced from a route. For PHP, also verify no test imports the class.
4. **Preserve the deprecated `SmartSchedulerService` for now** — deprecation notice + the 5 dead Vue consumers + the 7 routes form a tightly coupled cluster. Removing the service is **out of scope** here; instead, **remove the misleading `@deprecated` PHPDoc** (it is currently active code). Archiving the dead Vue consumers will leave only the controller↔service wiring alive; that wiring can be revisited in a later cleanup if desired.
5. **Sync documentation with reality** — `docs/ARCHITECTURE.md` and `docs/ROADMAP.md` are the public face of the project. Every archive action in this plan is paired with a doc update.

**Out of scope** (explicitly, to keep diffs minimal):
- Removing `SmartSchedulerService` and its 7 routes (deprecation documentation update only).
- Bundling the dashboard chunk / bundle-size warnings.
- Fixing the pre-existing `TestDataServiceTest` clone error (already in `bugs.md` P1; root cause is in `lib/Db/RoomTask.php:213` and is non-trivial).
- Resolving `phase-9-code-quality.md` P9-WORKFLOW-002 / P9-ARCH-002/003 / P9-TEST-002 / P9-E2E-002 / P9-DOC-002 (open items from earlier reviews — separate work).
- Closing the open items in `phase-10-gamification-enhancement.md` (Monster-Battle etc.) — these are now obsolete after CS-PLAN-002 Phase 0; closing them is one line in this plan's doc-sync phase.

---

## 3. Backend / Frontend Changes

### Phase 0 — Restore the build gate (mechanical, fully reversible)

- **ESLint auto-fix** — `npm run lint:fix`, then manually fix the structural issues that auto-fix cannot:
  - `src/components/Common/SvgIcon.vue:13` — replace `<template v-for :key=...>` with the real element getting the key (move `:key` onto the inner `<path>`/`<circle>`/`<rect>`/`<line>`/`<polyline>`/`<polygon>`).
  - `src/components/Minigames/SnakeGame.vue` — drop unused `watch` import (Z.121); drop unused `purchasedBoosts` from the `storeToRefs` destructure (Z.143; the store still owns the write at `minigame.js:223`).
  - `src/components/Minigames/SnakeLeaderboard.vue:104` — drop unused `emit`; if the `close` event is needed, change to inline `<button @click="$emit('close')">` or keep `defineEmits` without the local binding.
  - `src/components/Tasks/TaskForm.vue:17-20` — replace `<span>` inside `<option>` with a flat text string (drop the inline `tier-badge`; keep the data via the `<option>` label or move the badge to a sibling element).
- **Stylelint config** — install the missing dependency documented in the active stylelint config (`npm install --save-dev stylelint-config-recommended-scss`) so `npm run stylelint` actually runs. If a config-basedir is needed, add `configBasedir: __dirname` to `stylelint.config.js`.
- **PHPUnit pre-existing failure** — leave `TestDataServiceTest::testSeedFamilyCreatesCompleteStructure` untouched; the entry in `bugs.md` P1 already documents the deferral reason. If a clean local fix is trivial during execution (≤ 10 lines, e.g. guarding the `clone` in `RoomTask::getDaysUntilDue()` for the test fixture's null `nextDue`), apply it and update `bugs.md`; otherwise defer.

### Phase 1 — Remove `lang="ts"` drift in Vue components

For every Vue file with `<script setup lang="ts">` that is **kept** (active in the router or imported by an active component): convert to plain `<script setup>` and strip the type annotations. The annotations are not enforced (no TS toolchain); they only add noise and the same crash pattern CS-PLAN-001 fixed for `DailyTaskList.vue`.

- **Active (keep)**: 0 components — none of the 13 `lang="ts"` files are reachable from `src/router.js` (verified 2026-07-19). This phase therefore becomes a **no-op for active code**; the dead `lang="ts"` files are handled by the archive phase.
- Verify `tests/unit/components/DailyTaskList.spec.js` (the only `lang="ts"`-related regression test from CS-PLAN-001) still passes unchanged.

### Phase 2 — Archive dead Vue components

Move every component verified to have **zero callers** to `archive/2026-07/legacy-unwired/`. Group by domain for navigability. Update `appinfo/routes.php` if any route in `lib/Controller/*.php` is now orphaned (initial scan: none — the controllers in `lib/` are still referenced by their own tests, so we only archive the **views/components**, not the backend glue).

**Verification per file (before archive):**
```bash
grep -RIn --include='*.{js,vue,ts,php}' "ComponentName|component-name" src/ lib/ appinfo/ tests/ \
  | grep -v "^archive/" \
  | grep -v "ComponentName.vue"   # ignore self-references in the same file
```
Result must be empty.

**To archive (verified 2026-07-19, all 0 callers from `src/`):**

```
archive/2026-07/legacy-unwired/src/views/SchedulingView.vue
archive/2026-07/legacy-unwired/src/components/Scheduling/AvailabilitySettings.vue
archive/2026-07/legacy-unwired/src/components/Scheduling/EnergyBudgetSettings.vue
archive/2026-07/legacy-unwired/src/components/Scheduling/ScheduleCalendar.vue
archive/2026-07/legacy-unwired/src/components/Scheduling/SchedulePreview.vue
archive/2026-07/legacy-unwired/src/components/Scheduling/SchedulingSuggestions.vue
archive/2026-07/legacy-unwired/src/components/Gamification/AchievementNotification.vue
archive/2026-07/legacy-unwired/src/components/Gamification/ComboIndicator.vue
archive/2026-07/legacy-unwired/src/components/Gamification/MedalDisplay.vue
archive/2026-07/legacy-unwired/src/components/Gamification/WeeklyChallenge.vue
archive/2026-07/legacy-unwired/src/components/Common/ComboNotification.vue
archive/2026-07/legacy-unwired/src/components/Common/MedalList.vue
archive/2026-07/legacy-unwired/src/components/Common/MedalDetails.vue
archive/2026-07/legacy-unwired/src/components/UserManagement/UserManagement.vue
archive/2026-07/legacy-unwired/src/components/UserManagement/Leaderboard.vue
```

**Companion actions:**
- Delete the now-stale test files for the archived components (currently `MedalList.spec.js` and `MedalDetails.spec.js` — both have 12 / 11 tests respectively; tests would import the archived files if Jest's `testPathIgnorePatterns` is not updated). Keep tests that import multiple components.
- Update `jest.config.js` — ensure the existing `testPathIgnorePatterns` excludes `archive/`. Verify after the move (it is already expected per CS-PLAN-002 Phase 0, but confirm).
- Update `webpack.config.js` — exclude `archive/` from the build inputs if not already.
- Add `src/components/Scheduling/`, `src/components/UserManagement/` placeholder `README.md` files noting that the components are archived (so the directories remain in git history as landmarks, not deleted — but actually the directories *are* empty after the move, so just leave them empty; git will track the empty dirs via `.gitkeep` if needed).

### Phase 3 — Archive dead PHP entities/mappers

Same verification pattern, applied to PHP:

```
archive/2026-07/legacy-unwired/lib/Db/TaskClaim.php
archive/2026-07/legacy-unwired/lib/Db/TaskClaimMapper.php
archive/2026-07/legacy-unwired/lib/Db/TaskParticipant.php
archive/2026-07/legacy-unwired/lib/Db/TaskParticipantMapper.php
archive/2026-07/legacy-unwired/lib/Db/TaskScope.php
archive/2026-07/legacy-unwired/lib/Db/TaskScopeMapper.php
```

**Verification per file (before archive):**
```bash
grep -RIn --include='*.php' "TaskClaim|TaskParticipant|TaskScope" \
  lib/ appinfo/ tests/ \
  | grep -v "lib/Db/TaskClaim" \
  | grep -v "lib/Db/TaskClaimMapper" \
  | grep -v "lib/Db/TaskParticipant" \
  | grep -v "lib/Db/TaskParticipantMapper" \
  | grep -v "lib/Db/TaskScope" \
  | grep -v "lib/Db/TaskScopeMapper"
```
Result must be empty (verified 2026-07-19 — only the files themselves contain these names).

**Companion actions:**
- Confirm `composer.json` `autoload` does not need changes (the archived files leave autoload intact; the classes just become unreachable).
- No migration rollback needed — no `chorestrike_task_claims`, `chorestrike_task_participants`, or `chorestrike_task_scopes` tables were ever created (the mapper constructors reference tables that do not exist in any `lib/Migration/Version*.php`).

### Phase 4 — Backend placeholder fix

`lib/Service/TaskSyncService.php:412-416` — `logSyncEvent()` is a no-op stub called 3× (Z.95, Z.152, Z.181). Two options:

- **Option A (preferred, recommended)** — implement it via the existing `AppLogger`:
  ```php
  $this->appLogger?->info("TaskSync: {$eventType}", [
      'roomTaskId' => $roomTaskId,
      'eventType' => $eventType,
      'houseUserId' => $houseUserId,
      'nextcloudTaskId' => $nextcloudTaskId,
  ]);
  ```
  Inject `?AppLogger $appLogger = null` (consistent with `SmartSchedulerService`'s pattern) and add a `logSyncEvent` PHPUnit test verifying the logger is called with the expected context.
- **Option B** — remove the method body and the 3 call sites if the audit trail is not desired. Adds the argument "the data is not useful" to be recorded in the commit message.

**Decision during execution**: if the task-sync flow is exercised in integration tests (search `tests/integration/Service/TaskSyncService*`), prefer Option A; otherwise prefer Option B for minimal churn. (Initial scan: no `tests/integration/Service/TaskSyncService*` — Option B is the safer default.)

### Phase 5 — Documentation & meta-hygiene

- **`docs/ARCHITECTURE.md`** (lines 56, 62, 357) — remove references to archived `GameStats`, `MonsterSprite`, `MonsterCollection`, `updateMonsters()`. Update the directory tree under "Frontend" to reflect the post-CS-PLAN-002 state.
- **`docs/ROADMAP.md`** (lines 202, 227) — remove references to `GameStats.vue` in the "Medal showcase on profiles" and "GameStats with comprehensive statistics" bullets.
- **`docs/backlog/phase-10-gamification-enhancement.md`** — annotate as "Obsolete after CS-PLAN-002 Phase 0 (Monsters removed); close or archive".
- **`lib/Service/SmartSchedulerService.php:17`** — remove the misleading `@deprecated` PHPDoc. The class is still routed and tested; either commit to a removal plan in a future plan or stop saying it's deprecated. (Removal is out of scope here.)
- **`docs/backlog/ACTIVE_TASKS.md`** — when committing, run `git add -A` so the local `active/ → done/` move for `cs-plan-002-gamification-simplification.md` is staged correctly (currently shown as `deleted + untracked`).
- **Reference in `bugs.md` P1** — close the items this plan resolves:
  - "Jest: `TaskForm.spec.js` priority→tier" — already fixed by CS-PLAN-002 (mention in close-out).
  - "ESLint errors in `src/`" — closed by Phase 0 of this plan.
  - "Stylelint config loads" — closed by Phase 0 of this plan.
  - Leave the `TestDataServiceTest` clone item open (still deferred).

### Cross-layer contracts affected
None. This plan subtracts only; it does not change any active payload, route, or store contract. `js/src/services/` and the Pinia stores are untouched.

---

## 4. Tests

- **Unit (PHPUnit):**
  - If Option A for `logSyncEvent`: new test `tests/unit/Service/TaskSyncServiceTest.php` (or extend existing if present) verifying the logger receives `info` with the expected `roomTaskId`/`eventType`/`houseUserId`/`nextcloudTaskId` context.
  - **No regression** — `vendor\bin\phpunit.bat --configuration phpunit.unit.xml` must stay at 428/429 (the pre-existing `TestDataServiceTest` failure is untouched and documented).
- **Unit (Jest):**
  - Delete `tests/unit/components/MedalList.spec.js` and `tests/unit/components/MedalDetails.spec.js` (their components are archived).
  - **No regression** — `npm test` must stay at 523/523.
- **E2E (Playwright):** none required — no user-visible flow changes. Run a smoke pass on the existing specs to confirm dashboard / task manager / leaderboard still render.

---

## 5. File Manifest

```
Phase 0 (build-gate)
  modified: src/components/Common/SvgIcon.vue              # fix v-for key
  modified: src/components/Minigames/SnakeGame.vue          # drop unused import/refs
  modified: src/components/Minigames/SnakeLeaderboard.vue   # drop unused emit
  modified: src/components/Tasks/TaskForm.vue               # drop <span> in <option>
  modified: stylelint.config.js                             # add configBasedir (if needed)
  modified: package.json / package-lock.json                # +stylelint-config-recommended-scss (if missing)
  modified: docs/backlog/bugs.md                            # close items this plan resolves

Phase 2 (archive dead Vue)
  archived: archive/2026-07/legacy-unwired/src/views/SchedulingView.vue
  archived: archive/2026-07/legacy-unwired/src/components/Scheduling/AvailabilitySettings.vue
  archived: archive/2026-07/legacy-unwired/src/components/Scheduling/EnergyBudgetSettings.vue
  archived: archive/2026-07/legacy-unwired/src/components/Scheduling/ScheduleCalendar.vue
  archived: archive/2026-07/legacy-unwired/src/components/Scheduling/SchedulePreview.vue
  archived: archive/2026-07/legacy-unwired/src/components/Scheduling/SchedulingSuggestions.vue
  archived: archive/2026-07/legacy-unwired/src/components/Gamification/AchievementNotification.vue
  archived: archive/2026-07/legacy-unwired/src/components/Gamification/ComboIndicator.vue
  archived: archive/2026-07/legacy-unwired/src/components/Gamification/MedalDisplay.vue
  archived: archive/2026-07/legacy-unwired/src/components/Gamification/WeeklyChallenge.vue
  archived: archive/2026-07/legacy-unwired/src/components/Common/ComboNotification.vue
  archived: archive/2026-07/legacy-unwired/src/components/Common/MedalList.vue
  archived: archive/2026-07/legacy-unwired/src/components/Common/MedalDetails.vue
  archived: archive/2026-07/legacy-unwired/src/components/UserManagement/UserManagement.vue
  archived: archive/2026-07/legacy-unwired/src/components/UserManagement/Leaderboard.vue
  deleted:  tests/unit/components/MedalList.spec.js
  deleted:  tests/unit/components/MedalDetails.spec.js
  modified: jest.config.js (verify archive/ excluded)

Phase 3 (archive dead PHP)
  archived: archive/2026-07/legacy-unwired/lib/Db/TaskClaim.php
  archived: archive/2026-07/legacy-unwired/lib/Db/TaskClaimMapper.php
  archived: archive/2026-07/legacy-unwired/lib/Db/TaskParticipant.php
  archived: archive/2026-07/legacy-unwired/lib/Db/TaskParticipantMapper.php
  archived: archive/2026-07/legacy-unwired/lib/Db/TaskScope.php
  archived: archive/2026-07/legacy-unwired/lib/Db/TaskScopeMapper.php

Phase 4 (placeholder)
  modified: lib/Service/TaskSyncService.php                  # logSyncEvent: implement OR remove + 3 call sites
  new:      tests/unit/Service/TaskSyncServiceTest.php        # only if Option A chosen

Phase 5 (doc & meta)
  modified: docs/ARCHITECTURE.md                            # drop Monster/GameStats refs
  modified: docs/ROADMAP.md                                 # drop GameStats refs
  modified: docs/backlog/phase-10-gamification-enhancement.md # annotate as obsolete
  modified: lib/Service/SmartSchedulerService.php            # drop misleading @deprecated
  (commit-level) git add -A so the cs-plan-002 active->done move is staged correctly
```

Note: `archive/` is already gitignored (project-rules §5, set in CS-PLAN-001 Phase 3). Archived files are moved with `git mv` to localise history if the file was previously tracked (in this case, all archive targets are already tracked, so the moves are real `git mv` operations whose destination is untracked — exactly the pattern CS-PLAN-002 Phase 0 used for monsters).

---

## 6. Implementation Order

1. **Phase 0 — Restore the build gate.** No archive moves; pure mechanical edits. Verify with `npm test` + `npm run lint` + `npm run stylelint` + `vendor\bin\phpunit.bat --configuration phpunit.unit.xml`. The gate must be green at the end of this phase. **First commit.**
2. **Phase 1 — `lang="ts"` audit.** Confirm none of the active components use `lang="ts"` (already verified 2026-07-19: zero active). If verification passes, this phase is a no-op. Otherwise convert active files. **Standalone commit if anything changes.**
3. **Phase 2 — Archive dead Vue components.** Re-run the per-file `grep` verification just before each `git mv` to catch any silent reintroduction of an import. Verify with `npm test` (Jest must still be 523 − N where N is the count of deleted test files = 2; expect 521/521 across 39 suites after deletion). **Single commit covering all 15 archive moves + 2 test deletions.**
4. **Phase 3 — Archive dead PHP entities/mappers.** Re-run verification grep. Verify with PHPUnit (no test references these classes — must stay 428/429). **Single commit.**
5. **Phase 4 — `logSyncEvent` placeholder.** Decide A vs B at the start of the phase. **Single commit.**
6. **Phase 5 — Documentation & meta-hygiene.** Touch only `docs/` + the misleading PHPDoc. Verify with `git status` (no untracked `cs-plan-002*` files), `npm run l10n:check-keys` (no i18n key changes), and a final `npm test` + lint. **Single commit, possibly with the `git add -A` consolidation at the end.**

Each phase is independently committable and shippable; all 6 are small.

---

## 7. Risks & Mitigations

| Risk | Likelihood | Mitigation |
|------|-----------|------------|
| A "dead" Vue component is actually imported indirectly (e.g. via a dynamic `import()` in a test or a webpack chunk-name comment). | Low | Run the per-file `grep` just before each `git mv`; cross-check `tests/unit/setup.js` and any `webpackChunkName` comments; run `npm run build` after each phase. |
| Archived PHP entity is referenced by a Doctrine relation or a `QBMapper` constructor in an active file (not visible in `lib/` because the relation is via a string FQCN). | Very low | Audit active `lib/Service/*Mapper.php` constructors for `'chorestrike_task_claims'`, `'chorestrike_task_participants'`, `'chorestrike_task_scopes'` table names; if any are found, abort the archive for that file. |
| `npm run stylelint` fix introduces new warnings in `src/**/*.vue` (stylelint config differs from prior assumption). | Low | Run the lint after the config change; commit only if delta is zero or only pre-existing warnings remain; otherwise revert and re-scope. |
| `logSyncEvent` Option A injects a new dependency that breaks the existing `TaskSyncService` constructor signature for callers. | Low | Match the `?AppLogger $appLogger = null` pattern used in `SmartSchedulerService` (Z.21-27); it is parameter-order-tolerant and DI-friendly. |
| The `phase-10-gamification-enhancement.md` archive is misread as "delete" rather than "obsolete after Phase 0". | Low | Move to a `done/`-style marker in the backlog folder (or keep the file with a header banner) — do not delete. |
| `git add -A` in Phase 5 picks up unrelated local edits. | Medium | Inspect `git status` before each commit; do not use `git add -A` blindly — use targeted `git add docs/backlog/ACTIVE_TASKS.md` and the new `docs/plans/done/cs-plan-002-...md` separately. The plan-creation commit will already have created the new file. |
| Plan-002 file in `done/` collides with the tracked `active/`-state because the user hasn't moved it yet. | Low | The file is already moved locally (untracked in `done/`); just add the new path explicitly. If the user prefers the move to happen at git level, the `git add` of the new file is enough — the `active/` deletion is a separate `git rm` they may want to do themselves. |
| Removing the `lang="ts"` annotation from archived files loses information. | Very low | The annotations are unverifiable fiction without a TS toolchain; the loss is zero. |
| ESLint auto-fix changes unrelated lines (formatting churn). | Medium | Review the `git diff` after `npm run lint:fix`; revert unrelated formatting changes if they appear. |

---

## 8. Acceptance Criteria

- [ ] **`npm run lint`** exits 0 with 0 errors and 0 warnings.
- [ ] **`npm run stylelint`** exits 0 (or with only pre-existing warnings if the config is genuinely unfixable; document in `bugs.md`).
- [ ] **`npm test`** passes with all suites green (target: 521/521 across 39 suites after the 2 test-file deletions; or 523/523 if the deleted test suites are not counted because the spec files no longer exist).
- [ ] **`vendor\bin\phpunit.bat --configuration phpunit.unit.xml`** passes at 428/429 (only the documented pre-existing `TestDataServiceTest` failure remains).
- [ ] **`npm run build`** compiles with **0** warnings (the 3 current warnings are fixed in Phase 0; the bundle-size warnings are out of scope but they are not new).
- [ ] **`npm run l10n:check-keys`** passes (no i18n changes in this plan).
- [ ] `docs/ARCHITECTURE.md` no longer references `GameStats`, `MonsterSprite`, `MonsterCollection`, or `updateMonsters()`.
- [ ] `docs/ROADMAP.md` no longer references `GameStats.vue`.
- [ ] `docs/backlog/phase-10-gamification-enhancement.md` is annotated as obsolete after CS-PLAN-002 Phase 0 (or moved to a `done/`-style location).
- [ ] `lib/Service/SmartSchedulerService.php` no longer carries the misleading `@deprecated` PHPDoc.
- [ ] `lib/Service/TaskSyncService::logSyncEvent()` is either implemented (with a new passing unit test) or removed along with its 3 call sites; the chosen approach is documented in the commit message.
- [ ] 15 Vue components + 6 PHP entities/mappers are archived under `archive/2026-07/legacy-unwired/`; 2 stale test files are removed.
- [ ] `docs/backlog/bugs.md` P1 entries closed by this plan are marked as resolved (or removed if all sub-items are closed).
- [ ] `docs/backlog/ACTIVE_TASKS.md` reflects the new active plan and the moved plan-002 is staged correctly.
- [ ] `git status` is clean (or shows only the new files added by this plan + the new `cs-plan-003-…` plan in `docs/plans/new/`).
- [ ] All changes are committed; **no new `console.*` in production code** is introduced (unwired `UserManagement/*` were the only offenders and they are archived).
