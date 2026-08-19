# CS-PLAN-033: Batch the scheduling save endpoints (bulk weekly preferences PUT)

> **Status:** Implemented (2026-08-18) — see Implementation Notes
> **Effort:** M
> **Priority:** P2
> **Depends on:** none
> **Related issue:** backlog CR-016-02 (`docs/backlog/backlog.md` §"Review Follow-ups — CS-016"). Adjacent but **out of scope**: CR-016-14 (missing `hasChanges` guard in `AvailabilitySettings`). **CS-PLAN-032** (implemented + reviewed 2026-08-18, `docs/plans/done/`) records a CS-033 adoption hook in its shipped `SelfAvailabilitySettings` save handler (per-day loop → one bulk call) — this plan lands that hook.

## 1. Problem Summary

Saving scheduling preferences fires one HTTP request per weekday:

- `AvailabilitySettings.saveAvailability` (`src/components/Scheduling/AvailabilitySettings.vue:248`) loops over 7 days and calls `schedulingStore.updateAvailability` + `schedulingStore.updateEnergyBudget` per day — up to **14 sequential PUTs** (`AvailabilitySettings.vue:266-274`).
- `EnergyBudgetSettings.saveEnergyBudget` (`src/components/Scheduling/EnergyBudgetSettings.vue:224`) loops over 7 days calling `schedulingStore.updateEnergyBudget` — up to **7 sequential PUTs** (`EnergyBudgetSettings.vue:242-244`). It also needs a snapshot + watcher workaround (`EnergyBudgetSettings.vue:225-228`, `258-263`) because the store mutates day-by-day mid-save.

Backend counterpart: `SchedulingController::updateEnergy` (`lib/Controller/SchedulingController.php:93`) and `::updateAvailability` (`SchedulingController.php:133`) each perform a full house lookup, membership check, and a separate `house_user` UPDATE per call — even though both write the same `schedulingPreferences` JSON column on the same row (`lib/Db/HouseUser.php:83`).

The flicker symptom is mitigated (store actions no longer toggle `loading`, `src/store/scheduling.js:200-208`), but the N+1 request pattern, store churn, and the mid-save revert hazard remain.

## 2. Strategy

Add **one combined bulk endpoint** — `PUT /api/v1/scheduling/preferences` (route `scheduling#updatePreferences`) — that persists a full week of availability and/or energy in a single request. Decision rationale:

- The backlog AC wants availability + energy for a full week in ONE request. `AvailabilitySettings`' edit modal edits both concerns together, so two per-concern bulk endpoints would still force it to send 2 requests and reintroduce partial-failure states.
- **Sections are optional; each provided section must be complete** (decided 2026-08-18): `energyBudget`-only and `weekdayAvailability`-only payloads are valid — `EnergyBudgetSettings` then saves without re-sending availability. A payload with **neither** section is rejected 400.
- Persistence is a single JSON column on one `house_user` row — one validated read-modify-write + one `UPDATE` is inherently atomic; no multi-statement transaction is needed.

Payload contract (at least **one** section required; each provided section exactly the 7 canonical weekdays — absent sections are left unchanged):

```json
PUT /api/v1/scheduling/preferences
{
  "weekdayAvailability": { "monday": true, …, "sunday": true },
  "energyBudget":        { "monday": 10,  …, "sunday": 15 }
}
```

- A payload with **neither** section, any missing/extra weekday key within a provided section, non-bool availability, or non-int energy outside 1–20 → **400** (`ControllerErrorFormatter::validation`, `lib/Controller/ControllerErrorFormatter.php`) with the curated `InvalidArgumentException` messages already used by `updateEnergy`.
- Merge is **section-granular, never day-granular**: each provided section is replaced wholesale; absent sections and sibling keys (e.g. `maxDailyTasks`) are preserved.
- Response: `{ "success": true, "data": { "weekdayAvailability": …, "energyBudget": … } }` (always the full persisted preferences) — the store replaces its state from the response, mirroring `getPreferences` (`SchedulingController.php:57-85`).

**Pre-release cleanup (no backward compatibility, project-rules §1):** the per-day endpoints are removed. Call-site analysis (re-verified 2026-08-18, including the CS-PLAN-032 surface landed that day): the consumers of `PUT /scheduling/energy` and `PUT /scheduling/availability` are the two per-day store actions, called by **three** components — `AvailabilitySettings`, `EnergyBudgetSettings`, and `SelfAvailabilitySettings.vue` (CS-PLAN-032's member self-service save, `src/components/Scheduling/SelfAvailabilitySettings.vue:120`). Test consumers: `tests/unit/components/SelfAvailabilitySettings.spec.js:96-97` (asserts per-day PUTs) and `tests/e2e/self-availability-member.spec.ts:138,167` (waits for `PUT */scheduling/availability`). All three components and both specs migrate to the bulk endpoint in this plan. The owner-scoped `scheduling#updateMemberAvailability` (`SchedulingController.php:208`) is a different consumer and stays untouched.

## 3. Backend / Frontend Changes

- **Backend** (`lib/`, `appinfo/`):
  - `appinfo/routes.php:103-109` — remove `scheduling#updateEnergy` + `scheduling#updateAvailability`; add `['name' => 'scheduling#updatePreferences', 'url' => '/api/v1/scheduling/preferences', 'verb' => 'PUT']`. The static `/preferences` path sits on a different first segment than the block's only parameterized route (`scheduling#updateMemberAvailability` at `/members/{userId}`), so nothing shadows it — the shadowing rule stays guarded by `RouteMatrixTest`.
  - `lib/Controller/SchedulingController.php` — delete `updateEnergy`/`updateAvailability`; add `updatePreferences(?array $weekdayAvailability, ?array $energyBudget)` following the existing pattern: `@NoAdminRequired`, `getOrCreateHouse` + `getCurrentUserId` + `ensureHouseMember`, controller-boundary validation (at least one section present; each present section = exact 7-day key set via `VALID_WEEKDAYS` at `SchedulingController.php:27-29`, bool/int-1-20 checks), then a single service call. `\InvalidArgumentException` → 400 via `ControllerErrorFormatter::validation`; `ForbiddenException` → 403; generic → 500 `UPDATE_ERROR`.
  - `lib/Service/SchedulingService.php` — delete `updateEnergyBudget` (:74), `updateEnergyBudgetByNextcloudUserId` (:114), `updateAvailability` (:127), `updateAvailabilityByNextcloudUserId` (:100); add `updatePreferencesByNextcloudUserId(int $houseId, string $nextcloudUserId, ?array $weekdayAvailability, ?array $energyBudget): array` — resolve the user via the house-scoped `HouseUserMapper::findByNextcloudUserId` (persistence-layer membership, same as `updateMemberAvailabilityByNextcloudUserId` at `SchedulingService.php:191`), re-validate energy bounds (never trust callers), replace **the provided** sections in `schedulingPreferences` (section-granular merge), one `houseUserMapper->update()`, return the persisted preferences.
- **Frontend** (`src/`):
  - `src/store/scheduling.js` — replace `updateEnergyBudget` (:209) and `updateAvailability` (:239) with one action `saveWeeklyPreferences({ weekdayAvailability, energyBudget })` (either section omittable): single `api.put('/api/v1/scheduling/preferences', …)` with only the provided sections, then set `this.weekdayAvailability` / `this.energyBudget` from the response `data`. Keep the "does not touch `loading`" convention.
  - `src/components/Scheduling/AvailabilitySettings.vue` — `saveAvailability` becomes one `await schedulingStore.saveWeeklyPreferences(...)` call. Preserve the current "empty energy input = leave unchanged" semantics (`AvailabilitySettings.vue:270-273`) by defaulting unset days from the edited member's stored `energyBudget` when building the payload. Keep the up-front 1–20 client validation. **No `hasChanges` guard** — that is CR-016-14, deliberately not folded in.
  - `src/components/Scheduling/EnergyBudgetSettings.vue` — `saveEnergyBudget` sends `{ energyBudget: snapshot }` only (energy-only payload; decided 2026-08-18) in one call. The snapshot workaround and the `saving`-guard in the deep watcher become obsolete (the store now mutates once, after the response) — remove both comments/workarounds, keep the watcher itself.
  - `src/components/Scheduling/SelfAvailabilitySettings.vue` — adopt the CS-033 hook recorded in CS-PLAN-032: `saveAvailability` builds the **full week** `{ weekdayAvailability }` from local state and sends it in one `saveWeeklyPreferences` call instead of the per-day changed-days loop. The `saving`-guard in the watcher is obsolete (same single-mutation reasoning) and is removed; the watcher itself stays.
- **Cross-layer contracts:** store ↔ route contract stays enforced by `tests/unit/store/apiContract.spec.js` (removed calls and removed routes cancel out; the new call matches the new route). No new user-visible strings (backend messages are existing curated English strings; toasts in both components already exist), so no `l10n/` changes.

## 4. Tests

- **Unit (PHPUnit, via `phpunit.unit.xml`):**
  - `tests/unit/Controller/SchedulingControllerTest.php` — remove the per-day `testUpdateEnergy*` / `testUpdateAvailability*` cases; add `testUpdatePreferences_PersistsFullWeek_SuccessResponse` (both sections; delegates once to `updatePreferencesByNextcloudUserId`), `testUpdatePreferences_EnergyOnly_Success` + `testUpdatePreferences_AvailabilityOnly_Success` (absent section left unchanged), 400 cases for: **neither section present**, partial week (6 days), extra/unknown day key, non-integer energy, energy out of 1–20, non-bool availability; plus `testUpdatePreferences_ReturnsForbiddenForNonMember` (403, mirroring `testGetPreferencesReturnsForbiddenForNonMember`).
  - `tests/unit/Service/SchedulingServiceTest.php` — remove `testUpdateAvailabilityByNextcloudUserId_*` (:99) and `testUpdateEnergyBudgetByNextcloudUserId_*` (:133); add mocked-mapper tests for `updatePreferencesByNextcloudUserId` (provided sections replaced, absent section + sibling keys like `maxDailyTasks` preserved, out-of-range energy throws, house-scoped lookup used).
  - `tests/unit/RouteMatrixTest.php` — no edits; it automatically verifies the new route maps to an existing `SchedulingController::updatePreferences` (and would fail on the removed routes if left behind).
- **Integration (PHPUnit, Docker, `docker-compose.test.yml`):**
  - New `tests/integration/Service/SchedulingPreferencesIntegrationTest.php` extending `IntegrationTestCase` (`tests/integration/IntegrationTestCase.php` — real DB + `actingAs`/`createHouse` helpers): bulk save persists and `getUserPreferencesByNextcloudUserId` re-reads the exact week; energy-only save leaves availability untouched; second bulk save overwrites cleanly (no day-merge residue); invalid energy rejected without partial persistence (row unchanged). Run with `$env:TEST_DB_PROFILE='family'; docker compose -f docker-compose.test.yml up -d` then `docker compose -f docker-compose.test.yml exec nextcloud-test vendor/bin/phpunit --configuration phpunit.integration.xml --filter SchedulingPreferences` (PowerShell syntax per project-rules §2). The stale `tests/integration/AvailabilityApiTest.php` (dead smart-schedule endpoints) stays untouched — tracked by CR-016-10.
- **Unit (Jest, `npm test`):**
  - `tests/unit/store/scheduling.spec.js` — replace `updateEnergyBudget`/`updateAvailability` describes (:180-:203) with `saveWeeklyPreferences`: asserts exactly **one** `api.put('/api/v1/scheduling/preferences', …)` call, state replaced from response `data`, error propagated, shared `loading` flag untouched (existing "loading flag ownership" describe).
  - `tests/unit/components/AvailabilitySettings.spec.js` + `tests/unit/components/EnergyBudgetSettings.spec.js` — update the save flows: mocked api client asserting `api.put` (or the store action) is called **once** per save; adapt the existing snapshot-persistence test (`EnergyBudgetSettings.spec.js:82`) to the single-call behavior (payload = `{ energyBudget }` only), including unset-energy-defaults-from-member for the modal.
  - `tests/unit/components/SelfAvailabilitySettings.spec.js` — migrate the save tests to the bulk contract: exactly **one** `api.put('/api/v1/scheduling/preferences', { weekdayAvailability: <full week> })` per save (replacing the per-day PUT assertions at :96-97); failure path still asserts one call + intact store/local state.
  - `tests/unit/store/apiContract.spec.js` — no edits; must stay green (gate for route ↔ store drift).
- **E2E (Playwright):** `tests/e2e/scheduling.spec.ts` currently only reads/interacts (no save). Add one spec: change a slider value → click `save-energy-budget-button` → assert exactly **one** `PUT */scheduling/preferences` request via request interception, success toast, and the value survives reload. Follow the mutate→restore discipline of `self-availability-member.spec.ts` (serial within file; restore the changed slider value at the end so seeded values asserted elsewhere — e.g. `scheduling.spec.ts:58`, `flex-window-scheduling.spec.ts:85` — stay intact). Adjust `flex-window-scheduling.spec.ts` only if it asserts save behavior (it does not today). `tests/e2e/self-availability-member.spec.ts:138,167` switches its PUT wait from `/scheduling/availability` to `/scheduling/preferences`.

## 5. File Manifest

```
modified: appinfo/routes.php
modified: lib/Controller/SchedulingController.php
modified: lib/Service/SchedulingService.php
modified: src/store/scheduling.js
modified: src/components/Scheduling/AvailabilitySettings.vue
modified: src/components/Scheduling/EnergyBudgetSettings.vue
modified: src/components/Scheduling/SelfAvailabilitySettings.vue
new:      tests/integration/Service/SchedulingPreferencesIntegrationTest.php
modified: tests/unit/Controller/SchedulingControllerTest.php
modified: tests/unit/Service/SchedulingServiceTest.php
modified: tests/unit/store/scheduling.spec.js
modified: tests/unit/components/AvailabilitySettings.spec.js
modified: tests/unit/components/EnergyBudgetSettings.spec.js
modified: tests/unit/components/SelfAvailabilitySettings.spec.js
modified: tests/e2e/scheduling.spec.ts
modified: tests/e2e/self-availability-member.spec.ts
modified: docs/api/README.md (knowledge integration, see §8)
```

No archiving needed: deleted per-day code is code removal (pre-release, no BC), not doc/script retirement.

## 6. Implementation Order

1. Backend: add `SchedulingService::updatePreferencesByNextcloudUserId` + `SchedulingController::updatePreferences` (with validation) and register the route; keep per-day endpoints temporarily.
2. Backend tests: new unit tests (controller + service) green; then remove per-day controller methods, service methods, routes, and their tests in the same commit-sized step.
3. Frontend store: add `saveWeeklyPreferences`, then switch both components to it and remove the per-day actions (single commit with step 2's removals to keep the contract test green).
4. Jest updates for store + both components; run `apiContract.spec.js`.
5. New Docker integration test for the real-DB write path.
6. Playwright save-flow spec.
7. Docs + backlog: API section in `docs/api/README.md`, move CR-016-02 to `docs/backlog/archive/2026-08.md`, update `ACTIVE_TASKS.md`.

## 7. Risks & Mitigations

| Risk | Likelihood | Mitigation |
|------|-----------|------------|
| Behavior change: per-day endpoints disappear for hypothetical external consumers | Low | Pre-release: no consumers outside the two components (grep-verified); project-rules §1 forbids compat shims |
| Empty energy inputs in `AvailabilitySettings` modal must not overwrite stored values | Medium | Default unset days from the edited member's stored budget when building the payload; covered by a dedicated Jest case |
| Concurrent writes to `schedulingPreferences` (owner editing a member while the member saves) | Low | Same last-write-wins semantics as today (per-day saves had the same race); single-row JSON UPDATE stays atomic |
| Route/shadowing regressions from editing `routes.php` | Low | `RouteMatrixTest` + `apiContract.spec.js` both gate the change |
| Section-granular merge misread as day-granular (partial section silently overwriting days) | Low | Each provided section must be the exact 7-day key set (400 otherwise); controller + service + integration tests pin the semantics |
| `hasChanges` gap in `AvailabilitySettings` still fires 1 (not 0) request on no-op saves | Certain | Out of scope by design — CR-016-14 tracks it; bulk reduces the blast radius from ≤14 calls to 1 |

## 8. Acceptance Criteria

- [x] `PUT /api/v1/scheduling/preferences` persists provided sections (full week each) in **one** request; energy-only and availability-only payloads are valid; a payload with neither section is rejected **400**; response returns the persisted preferences.
- [x] Partial weeks, unknown day keys, non-bool availability, and energy outside 1-20 within a provided section are rejected with **400** before any write; absent sections stay unchanged; non-members get **403**.
- [x] `AvailabilitySettings.saveAvailability` and `EnergyBudgetSettings.saveEnergyBudget` each issue exactly **one** API call; per-day endpoints, store actions, and their tests are removed.
- [x] The EnergyBudgetSettings snapshot/watcher workaround is gone and saving no longer reverts unsaved days.
- [x] Regression tests updated per §4 (PHPUnit unit + Docker integration, Jest store/component, Playwright save-flow).
- [x] Build gate green (`npm test` + lint + backend tests)
- [x] Durable concepts/decisions integrated into the permanent docs (`docs/api/README.md` scheduling section + route-list note corrected)
- [x] `docs/backlog/ACTIVE_TASKS.md` updated

## Implementation Notes (2026-08-18)

- **Plan corrections from the review findings (block #1 / major #2)** were applied before implementation: §2 call-site analysis, §3/§4/§5 now cover `SelfAvailabilitySettings.vue` + its Jest/E2E specs; the header reflects the landed CS-PLAN-032 state.
- **Root-cause fix beyond the manifest:** `Modal.vue` never rendered the `#footer` slot, so the `AvailabilitySettings` edit modal's Save/Cancel buttons were silently dropped (pre-existing). Added `<slot name="footer" />`; the new Jest save-flow test is the regression test.
- Gate: Jest 714 / ESLint + stylelint 0 errors (27 TECH-LINT-004 warnings) / l10n clean / `cs:check` 0 of 258 / PHPUnit unit 740 / integration 164 [24 pre-existing skips] / E2E `scheduling.spec.ts` 15/15 (incl. new bulk-PUT save flow), `self-availability-member.spec.ts` 4/4 (migrated PUT wait), `availability-settings-flow` + `flex-window-scheduling` 17 passed [3 pre-existing BUG-E2E-002 skips].

## Review Outcome (2026-08-19, Standard /review of uncommitted changes, 6 tracks)

Security and deploy-safety tracks: no findings. One WARNING + seven suggestions
found; all fixed inline in the same change set:

- **WARNING (fixed):** removing the `saving` guard from the deep watchers let the
  save response clobber edits made while the PUT was in flight (inputs were still
  enabled). Fix: `:disabled="saving"` on sliders, number inputs, templates, and
  toggles in `EnergyBudgetSettings.vue` + `SelfAvailabilitySettings.vue`; regression
  tests assert disabled-during-save in both component specs.
- **Fixed:** weekend energy fallback in `AvailabilitySettings` now uses the
  backend day-dependent defaults (10 weekdays / 15 saturday+sunday) instead of a
  flat 10; Jest case covers a partial stored budget.
- **Fixed:** redundant `snapshot` copy removed from `SelfAvailabilitySettings`.
- **Fixed (perf):** `SchedulingService::buildPreferencesPayload()` extracted; the
  bulk save builds its response from the in-memory entity (no second `find`).
  Unit tests now pin `find` is never re-called on the save path. The extraction
  initially typed the payload builder with `array` — a fresh `house_user` row
  holds `NULL` schedulingPreferences, so `GET /preferences` crashed with a
  TypeError (caught by the E2E run, not by unit tests). Fixed via
  `?array` + `?? []` normalization; `HouseUser` getter docblock corrected;
  integration regression `testGetPreferences_FreshMemberWithoutStoredPreferences_ReturnsDefaults`.
- **Fixed:** E2E save-flow spec merged into one mutate→assert→restore test with
  try/finally (restore no longer skippable via serial-group failure).
- **Fixed:** dangling `plans/active/` links + stale status wording in
  `ACTIVE_TASKS.md` and `backlog/archive/2026-08.md`.
- **Backlog:** triplicated energy-bounds validation logged as **CR-016-15**
  (drift risk, KISS — deliberate defense-in-depth retained); CR-016-14 wording
  corrected (≤14 calls → 1 bulk call).

Final gate (re-run after the fixes): Jest 717 (71 suites) / ESLint 0 errors /
stylelint 0 errors (27 TECH-LINT-004 warnings) / l10n clean / `cs:check` 0 of 258 /
PHPUnit unit 740 / integration 165 [24 pre-existing skips] / E2E
`chromium-scheduling-owner` 14/14 (save-flow merged into one test) +
`self-availability-member` 4/4.
