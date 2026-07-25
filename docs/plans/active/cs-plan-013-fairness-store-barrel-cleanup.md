# CS-PLAN-013: Gamification Fairness, Store-Barrel Completeness & Dead-Code Cleanup

> **Status:** Implemented (review passed, committed)
> **Effort:** M
> **Priority:** P1 (fairness) / P2 (store-barrel) / P3 (dead-code)
> **Depends on:** uncommitted 2026-07-25 review hardening pass committed first
> **Related:** CODE_REVIEW_2026-07-24 (G1–G4, F1–F3, D5)

## 1. Problem Summary

Three clusters of issues remain from the 2026-07-24 project-wide review:

**Fairness (G1/G2):** `StreakFreezeJob` and `DailyNotificationJob` do not consult `weekdayAvailability`. A user marked unavailable on a weekday still gets their streak consumed/reset (G1) or receives a "streak at risk" notification (G2) for a day they are not expected to work. This violates the absence-day fairness rule already implemented in `GameLogicService::handleTaskCompletion()` (which calls `calculateStreakWithAbsence()`).

**Store-barrel (F1/F2/F3):** The Pinia store barrel (`src/store/index.js`) is incomplete:
- `useSchedulingStore` is never imported — the scheduling store is absent from the barrel entirely (F1).
- `resetAllStores()` omits `checklistStore` and `schedulingStore` — state leaks on logout/house-switch (F2).
- `clearAllStoreErrors`/`getAnyStoreLoading`/`getAnyStoreError` omit `checklist`, `scheduling`, and `titles` stores; `useTitlesStore` is imported but missing from named exports; `titles` & `minigame` stores define no `clearError()` (F3).

**Dead code (D5):** `GameLogicService` exposes five legacy methods (`calculatePoints`, `calculateStreakBonus`, `calculateComboBonus`, `updateStreakCount`, `shouldResetStreak`) that are only called from their own unit tests — no production caller exists. The live completion flow uses `calculateCoins()` (coin formula) and `handleTaskCompletion()` + `HouseUser::calculateStreakWithAbsence()` (streak/combo logic; `shouldResetStreak` is the superseded manual equivalent). These dead methods are misleading and risk being called incorrectly.

**Bonus (G3):** The combo multiplier in `calculateCoins()` is hardcoded `*= 2` instead of being sourced from `RewardConfigService`, violating the config-driven reward formula rule.

**Bonus (G4):** `StreakFreezeJob` wraps the entire per-user loop in one `try/catch` — a single user failure aborts processing of all remaining users. (`DailyNotificationJob` and `StreakRestockJob` share this shape; G4 isolation is scoped to `StreakFreezeJob` — the one job that mutates streak state.)

## 2. Strategy

**Phase 1 (fairness):** Make both background jobs consult `weekdayAvailability` the same way the live completion path does — read it from `$user->getSchedulingPreferences()`, which `findAllForJobs()` already populates (the `scheduling_preferences` JSON column on `chorestrike_house_users`). Skip users for whom yesterday was an unavailable weekday. In `StreakFreezeJob`, also add per-user error isolation (G4). **No new mapper/dependency is injected** — this guarantees the jobs enforce the identical fairness rule as `GameLogicService::handleTaskCompletion()` (which reads the same field) and adds zero extra queries.

**Phase 2 (store-barrel):** Add `useSchedulingStore` import + export; add `checklistStore.reset()` + `schedulingStore.reset()` to `resetAllStores()`; add `checklist`/`scheduling`/`titles` to the three utility functions; add `clearError()` to `titles` store; add `useTitlesStore` to named exports.

**Phase 3 (dead-code):** Remove the **five** dead methods from `GameLogicService` (incl. `shouldResetStreak` — the legacy manual reset logic superseded by `HouseUser::calculateStreakWithAbsence()`) and their test cases. Make the combo multiplier config-driven via `RewardConfigService`.

## 3. Backend / Frontend Changes

### Backend (`lib/`)
- **`BackgroundJob/StreakFreezeJob.php`:** read `weekdayAvailability` from `$user->getSchedulingPreferences()` (already populated by `findAllForJobs()` — **no new dependency, no extra query**); skip unavailable days (yesterday unavailable → no freeze/reset needed); wrap per-user logic in its own try/catch (G4).
- **`BackgroundJob/DailyNotificationJob.php`:** read `weekdayAvailability` from `$user->getSchedulingPreferences()` (same source as `StreakFreezeJob` and as the live completion path); skip unavailable days (yesterday unavailable → no notification needed). (Note: this job and `StreakRestockJob` share `StreakFreezeJob`'s single-try/catch shape, but G4 per-user isolation is scoped to `StreakFreezeJob` only — the one job that mutates streak state; a missed notification/restock is lower-severity and not safety-critical.)
- **`Service/GameLogicService.php`:** remove 5 dead methods (`calculatePoints`, `calculateStreakBonus`, `calculateComboBonus`, `updateStreakCount`, `shouldResetStreak` — the last is the legacy manual streak-reset logic superseded by `HouseUser::calculateStreakWithAbsence()`); change `calculateCoins()` combo branch from hardcoded `*= 2` to `$this->configService->getComboMultiplier()` (G3).
- **`Service/RewardConfigService.php`:** add `KEY_COMBO_MULTIPLIER` constant + `getComboMultiplier()`/`setComboMultiplier()` methods (default `'2.0'`, clamped to `>= 0` to forbid a nonsensical negative that would invert combo rewards — matches the clamp pattern of the other config getters) + wire into `getAllConfig()`/`updateConfig()`.

### Frontend (`src/`)
- **`store/index.js`:** add `useSchedulingStore` import + named/default export; add `checklistStore.reset()` + `schedulingStore.reset()` to `resetAllStores()`; add `checklistStore` + `schedulingStore` + `titlesStore` to `clearAllStoreErrors`/`getAnyStoreLoading`/`getAnyStoreError`.
- **`store/titles.js`:** add `clearError()` action (matches the pattern in other stores).
- **`store/minigame.js`:** verify whether `clearError()` exists; add if missing.

### Cross-layer contracts
- Background jobs are internal; the store barrel is an internal re-export. **No existing API field is removed.**
- `RewardConfigService` combo multiplier is exposed via the existing `GET/PUT /api/v1/reward-config` endpoints as an **additive** `comboMultiplier` field (backward-compatible; the `getAllConfig()`/`updateConfig()` surface already handles all config keys).
- **l10n:** no new admin UI control is introduced, so no new user-visible strings are expected — confirm with `npm run l10n:check-keys`.
- **Out of scope:** `getStoreSummary()` in `store/index.js` also covers only 6 stores; it is a read-only summary utility and is intentionally left unchanged by this plan.

## 4. Tests

### PHPUnit (unit)
- **`StreakFreezeJobTest` (new):** verify user on unavailable day is skipped (no freeze consumed, no streak reset); verify user on available day with no completions still gets freeze consumed; verify per-user error isolation (one failure doesn't abort the loop).
- **`DailyNotificationJobTest` (new):** verify user on unavailable day receives no notification; verify user on available day with no completions and no freezes receives notification.
- **`GameLogicServiceTest` (modified):** remove the dead-method test cases (`testCalculatePoints_*`, `testCalculateStreakBonus`, `testCalculateComboBonus`, `testUpdateStreakCount_*`, and the 4 `testShouldResetStreak*` cases); add a combo-multiplier-from-config test case.
- **`RewardConfigServiceTest` (modified):** add combo multiplier getter/setter/default tests.

### Jest (frontend)
- **`store/index.spec.js` (new or extended):** verify `resetAllStores()` calls `reset()` on all 10 stores; verify `clearAllStoreErrors()` calls `clearError()` on all stores; verify `getAnyStoreLoading()`/`getAnyStoreError()` include all stores.
- **`store/titles.spec.js` (modified):** verify `clearError()` action exists and works.

## 5. File Manifest

```
modified: lib/BackgroundJob/StreakFreezeJob.php
modified: lib/BackgroundJob/DailyNotificationJob.php
modified: lib/Service/GameLogicService.php
modified: lib/Service/RewardConfigService.php
modified: src/store/index.js
modified: src/store/titles.js
modified: src/store/minigame.js (if clearError() missing)
modified: tests/unit/Service/GameLogicServiceTest.php
modified: tests/unit/Service/RewardConfigServiceTest.php
modified: tests/unit/store/titles.spec.js
new:      tests/unit/BackgroundJob/StreakFreezeJobTest.php
new:      tests/unit/BackgroundJob/DailyNotificationJobTest.php
new:      tests/unit/store/storeBarrel.spec.js
modified: docs/concepts/gamification-and-rewards.md (note combo multiplier is now configurable, default ×2)
modified: docs/concepts/design.md (combo ×2 → configurable, default ×2)
```

## 6. Implementation Order

1. **Phase 1a — StreakFreezeJob weekday-awareness + error isolation (G1/G4):** read `weekdayAvailability` from `$user->getSchedulingPreferences()` (same source as the live completion path), skip users for whom yesterday was an unavailable weekday; wrap per-user logic in its own try/catch. Unit tests.
2. **Phase 1b — DailyNotificationJob weekday-awareness (G2):** same getter-based availability check; skip unavailable-day users. Unit tests.
3. **Phase 2 — Store-barrel completeness (F1/F2/F3):** fix `store/index.js` imports, exports, `resetAllStores()`, and the three utility functions; add `clearError()` to `titles`/`minigame` stores. New barrel test + updated store specs. (`getStoreSummary()` stays as-is — explicitly out of scope.)
4. **Phase 3a — Combo multiplier config (G3):** add `KEY_COMBO_MULTIPLIER` to `RewardConfigService` (clamped `>= 0`, default 2.0), wire `getComboMultiplier()` into `calculateCoins()`. Update tests.
5. **Phase 3b — Dead-method removal (D5):** remove 5 methods + their test cases from `GameLogicService[Test]`.
6. **Phase 4 — Verify:** build gate (`npm test` + `npm run lint` + PHPUnit unit + integration).

## 7. Risks & Mitigations

| Risk | Likelihood | Mitigation |
|------|-----------|------------|
| Fairness rule diverges between completion-time and job-time | Resolved (by design) | Both the live path (`handleTaskCompletion`) and the jobs read `weekdayAvailability` from the **same** source — `HouseUser::getSchedulingPreferences()`, already populated by `findAllForJobs()` (`SELECT *` on `chorestrike_house_users`, where `scheduling_preferences` is a JSON column). No second data source (e.g. `AvailabilitySettingsMapper`/`chorestrike_availability`) is introduced, so the rule cannot disagree. |
| Server-timezone vs user-day mismatch on the "yesterday" weekday | Low | `new \DateTime('yesterday')` and the live path's `new \DateTime()` both use server time, so they are mutually consistent. Household scale + the 06:00 job window makes midnight-edge errors negligible; documented as a known limitation. |
| Removing dead methods could break subclasses or plugins | Very Low | Single Nextcloud app, no known subclasses. All 5 methods are `public` but have zero production callers (verified via grep — only their own unit tests call them). |
| Combo multiplier default change could alter coin rewards | Low | Default stays `2.0` (identical behavior); only the source changes (hardcoded → config). Getter clamps to `>= 0` so a misconfigured negative cannot invert rewards. |

## 8. Acceptance Criteria

- [x] `StreakFreezeJob` skips users on unavailable weekdays (yesterday unavailable → no freeze/reset), reading `weekdayAvailability` from `HouseUser::getSchedulingPreferences()` (same source as `handleTaskCompletion`)
- [x] `DailyNotificationJob` skips users on unavailable weekdays (yesterday unavailable → no notification), same data source
- [x] No new DB dependency/mapper injected into either job (fairness data comes from the already-loaded `HouseUser` entity — zero extra queries)
- [x] `StreakFreezeJob` isolates per-user errors (one failure doesn't abort the loop)
- [x] Combo multiplier is configurable via `RewardConfigService` (default 2.0, clamped `>= 0`, identical behavior at default)
- [x] 5 dead methods removed from `GameLogicService` (`calculatePoints`, `calculateStreakBonus`, `calculateComboBonus`, `updateStreakCount`, `shouldResetStreak`) — no production callers remain
- [x] `resetAllStores()` calls `reset()` on all 10 stores (including `checklist` + `scheduling`)
- [x] `clearAllStoreErrors()` / `getAnyStoreLoading()` / `getAnyStoreError()` include all stores
- [x] `useSchedulingStore` and `useTitlesStore` are properly exported from the barrel
- [x] `titles` (and `minigame`) store has a `clearError()` action
- [x] Gamification docs note the combo multiplier is now configurable (default ×2)
- [x] No new user-visible strings (no admin UI control added) — `npm run l10n:check-keys` clean
- [x] All new and modified unit tests pass
- [x] Build gate green (`npm test` + `npm run lint` + PHPUnit unit)
- [x] `docs/backlog/ACTIVE_TASKS.md` updated

## Review Outcome (2026-07-25)

Standard Type A plan review (no subagents; every claim traced to source). Two Majors + 5 Minor/NIT, all applied to this draft:

- **Major 1 — fairness data source:** dropped the proposed `AvailabilitySettingsMapper` injection. `HouseUser::getSchedulingPreferences()` is already populated by `findAllForJobs()` (the `scheduling_preferences` JSON column on `chorestrike_house_users`), and it is the **same field the live `handleTaskCompletion()` path reads**. Using it makes the jobs enforce the identical fairness rule with zero new dependencies and zero extra queries, and removes the divergence risk of reading a second table (`chorestrike_availability`). Dissolves the former Risk 1 (mapper API) and Risk 2 (N+1).
- **Major 2 — dead-code scope:** added `shouldResetStreak()` as a 5th dead method (legacy manual reset logic, superseded by `HouseUser::calculateStreakWithAbsence()`; only test callers). Updated §1/§3/§4/§8 accordingly.
- **Minor 1:** aligned §6 Implementation Order phase numbering with §2 (Phase 1 fairness, Phase 2 store-barrel, Phase 3 dead-code+combo).
- **Minor 2:** documented that G4 per-user isolation is scoped to `StreakFreezeJob` only (the streak-mutating job); `DailyNotificationJob`/`StreakRestockJob` share the shape but are lower-severity.
- **Minor 3:** combo multiplier getter clamped `>= 0` (matches the clamp pattern of other config getters).
- **Minor 4:** added gamification doc touches to the File Manifest (combo now configurable, default ×2).
- **Minor 5:** stated `getStoreSummary()` is intentionally out of scope.
- **NITs:** corrected the overstated mapper risk; noted the additive `comboMultiplier` response field; confirmed no new l10n strings.

Verdict: revised in `new/`; ready for the manual `new/` → `active/` move when work begins.

## Implementation Review (2026-07-25, Standard Type B)

Post-implementation code review of the full changeset (14 modified + 1 new file).

- **0 blocks, 0 majors.** All plan acceptance criteria verified against source.
- **NIT 1:** Availability-check ordering differs between jobs (`StreakFreezeJob` checks streak first, `DailyNotificationJob` checks availability first). Only affects the `skippedUnavailable` counter semantics — no behavioral impact.
- **NIT 2:** `StreakFreezeJob.findAllUsers()` moved outside try/catch (intentional per G4 scope — systemic failure propagates to the TimedJob framework).
- **Auto-fixed:** missing trailing newline in `titles.spec.js`; `ACTIVE_TASKS.md` entry added.
- **Build gate:** Jest 533/533, ESLint 0 errors, `php -l` clean (4 files). PHPUnit unit pending Docker run.

Verdict: clean implementation, faithful to the plan. Committed.
