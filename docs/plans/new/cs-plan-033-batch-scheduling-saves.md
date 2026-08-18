# CS-PLAN-033: Batch the scheduling save endpoints (bulk weekly preferences PUT)

> **Status:** Plan (not started)
> **Effort:** M
> **Priority:** P2
> **Depends on:** none
> **Related issue:** backlog CR-016-02 (`docs/backlog/backlog.md` §"Review Follow-ups — CS-016"). Adjacent but **out of scope**: CR-016-14 (missing `hasChanges` guard in `AvailabilitySettings`). The planned **CS-PLAN-032 depends on this plan** (it builds on the batched save surface).

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

**Pre-release cleanup (no backward compatibility, project-rules §1):** the per-day endpoints are removed. Call-site analysis: the only consumers of `PUT /scheduling/energy` and `PUT /scheduling/availability` are the two per-day store actions, whose only callers are the two components above (verified via grep across `src/`); no backend or E2E consumer exists. The owner-scoped `scheduling#updateMemberAvailability` (`SchedulingController.php:208`) is a different consumer and stays untouched.

## 3. Backend / Frontend Changes

- **Backend** (`lib/`, `appinfo/`):
  - `appinfo/routes.php:103-109` — remove `scheduling#updateEnergy` + `scheduling#updateAvailability`; add `['name' => 'scheduling#updatePreferences', 'url' => '/api/v1/scheduling/preferences', 'verb' => 'PUT']`. Static path precedes nothing conflicting (no `{id}` routes in this block), satisfying the shadowing rule guarded by `RouteMatrixTest`.
  - `lib/Controller/SchedulingController.php` — delete `updateEnergy`/`updateAvailability`; add `updatePreferences(?array $weekdayAvailability, ?array $energyBudget)` following the existing pattern: `@NoAdminRequired`, `getOrCreateHouse` + `getCurrentUserId` + `ensureHouseMember`, controller-boundary validation (at least one section present; each present section = exact 7-day key set via `VALID_WEEKDAYS` at `SchedulingController.php:27-29`, bool/int-1-20 checks), then a single service call. `\InvalidArgumentException` → 400 via `ControllerErrorFormatter::validation`; `ForbiddenException` → 403; generic → 500 `UPDATE_ERROR`.
  - `lib/Service/SchedulingService.php` — delete `updateEnergyBudget` (:74), `updateEnergyBudgetByNextcloudUserId` (:114), `updateAvailability` (:127), `updateAvailabilityByNextcloudUserId` (:100); add `updatePreferencesByNextcloudUserId(int $houseId, string $nextcloudUserId, ?array $weekdayAvailability, ?array $energyBudget): array` — resolve the user via the house-scoped `HouseUserMapper::findByNextcloudUserId` (persistence-layer membership, same as `updateMemberAvailabilityByNextcloudUserId` at `SchedulingService.php:191`), re-validate energy bounds (never trust callers), replace **the provided** sections in `schedulingPreferences` (section-granular merge), one `houseUserMapper->update()`, return the persisted preferences.
- **Frontend** (`src/`):
  - `src/store/scheduling.js` — replace `updateEnergyBudget` (:209) and `updateAvailability` (:239) with one action `saveWeeklyPreferences({ weekdayAvailability, energyBudget })` (either section omittable): single `api.put('/api/v1/scheduling/preferences', …)` with only the provided sections, then set `this.weekdayAvailability` / `this.energyBudget` from the response `data`. Keep the "does not touch `loading`" convention.
  - `src/components/Scheduling/AvailabilitySettings.vue` — `saveAvailability` becomes one `await schedulingStore.saveWeeklyPreferences(...)` call. Preserve the current "empty energy input = leave unchanged" semantics (`AvailabilitySettings.vue:270-273`) by defaulting unset days from the edited member's stored `energyBudget` when building the payload. Keep the up-front 1–20 client validation. **No `hasChanges` guard** — that is CR-016-14, deliberately not folded in.
  - `src/components/Scheduling/EnergyBudgetSettings.vue` — `saveEnergyBudget` sends `{ energyBudget: snapshot }` only (energy-only payload; decided 2026-08-18) in one call. The snapshot workaround and the `saving`-guard in the deep watcher become obsolete (the store now mutates once, after the response) — remove both comments/workarounds, keep the watcher itself.
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
  - `tests/unit/store/apiContract.spec.js` — no edits; must stay green (gate for route ↔ store drift).
- **E2E (Playwright):** `tests/e2e/scheduling.spec.ts` currently only reads/interacts (no save). Add one spec: change a slider value → click `save-energy-budget-button` → assert exactly **one** `PUT */scheduling/preferences` request via request interception, success toast, and the value survives reload. Adjust `flex-window-scheduling.spec.ts` only if it asserts save behavior (it does not today).

## 5. File Manifest

```
modified: appinfo/routes.php
modified: lib/Controller/SchedulingController.php
modified: lib/Service/SchedulingService.php
modified: src/store/scheduling.js
modified: src/components/Scheduling/AvailabilitySettings.vue
modified: src/components/Scheduling/EnergyBudgetSettings.vue
new:      tests/integration/Service/SchedulingPreferencesIntegrationTest.php
modified: tests/unit/Controller/SchedulingControllerTest.php
modified: tests/unit/Service/SchedulingServiceTest.php
modified: tests/unit/store/scheduling.spec.js
modified: tests/unit/components/AvailabilitySettings.spec.js
modified: tests/unit/components/EnergyBudgetSettings.spec.js
modified: tests/e2e/scheduling.spec.ts
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

- [ ] `PUT /api/v1/scheduling/preferences` persists provided sections (full week each) in **one** request; energy-only and availability-only payloads are valid; a payload with neither section is rejected **400**; response returns the persisted preferences.
- [ ] Partial weeks, unknown day keys, non-bool availability, and energy outside 1–20 within a provided section are rejected with **400** before any write; absent sections stay unchanged; non-members get **403**.
- [ ] `AvailabilitySettings.saveAvailability` and `EnergyBudgetSettings.saveEnergyBudget` each issue exactly **one** API call; per-day endpoints, store actions, and their tests are removed.
- [ ] The EnergyBudgetSettings snapshot/watcher workaround is gone and saving no longer reverts unsaved days.
- [ ] Regression tests updated per §4 (PHPUnit unit + Docker integration, Jest store/component, Playwright save-flow).
- [ ] Build gate green (`npm test` + lint + backend tests)
- [ ] Durable concepts/decisions integrated into the permanent docs (`docs/concepts/`, `docs/adr/`, `docs/engineering/`, `docs/api/`, `docs/standards/`) — plans are temporary
- [ ] `docs/backlog/ACTIVE_TASKS.md` updated
