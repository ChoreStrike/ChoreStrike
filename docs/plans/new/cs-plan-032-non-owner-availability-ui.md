# CS-PLAN-032: Non-Owner Availability Editing Surface

> **Status:** Plan (not started)
> **Effort:** M
> **Priority:** P2
> **Depends on:** `CS-PLAN-033` (batch scheduling saves) — **soft**: must work with per-day saves; adopt the bulk endpoint with a one-line swap if CS-033 lands first
> **Related issue:** backlog `CR-016-12` (review follow-ups — CS-016 Scheduling UI)

## 1. Problem Summary

The scheduling view's member-availability grid is owner-only by design:

- `SchedulingController::getMemberAvailability` requires `requireHouseOwner` (`lib/Controller/SchedulingController.php:180`), so `GET /api/v1/scheduling/members` returns 403 for non-owners. `initializeScheduling` swallows that 403 best-effort (`src/store/scheduling.js:383-391`), leaving `memberAvailability` empty.
- `AvailabilitySettings` then renders its generic empty state "No Members Found / Add members to your house…" (`src/components/Scheduling/AvailabilitySettings.vue:71-74`) — misleading for a member, who cannot add members at all.
- The grid's edit button only renders on the current user's own card and only when the grid has data (`v-if="member.isCurrentUser"`, `src/components/Scheduling/AvailabilitySettings.vue:32`). Non-owners therefore have **no UI to toggle their own weekday availability** — only their energy budget (`EnergyBudgetSettings`, which correctly uses the self-service endpoints).

The backend already has everything needed: `GET /api/v1/scheduling/preferences` (`getPreferences`, member-gated) returns the current user's `weekdayAvailability`, and `PUT /api/v1/scheduling/availability` (`updateAvailability`, `@NoAdminRequired` + `ensureHouseMember`, `lib/Controller/SchedulingController.php:133-165`) persists it. The gap is purely frontend.

## 2. Strategy

**UX decision (required up front): self-availability section driven by `getPreferences` data.**

Add a new self-service section component `SelfAvailabilitySettings.vue` ("My Availability") that binds directly to the scheduling store's existing `weekdayAvailability` state (populated for every member by `fetchUserPreferences` during `initializeScheduling`) and saves through the existing self-service store action `updateAvailability` — only for days that actually changed.

Justification (KISS + reuse):

- Zero backend changes, zero new endpoints, zero new store actions. The data (`weekdayAvailability`) is already client-side when the view mounts; the save path (`src/store/scheduling.js:239-256` → `PUT /api/v1/scheduling/availability`) is already member-gated and tested.
- Mirrors the proven self-service pattern of `EnergyBudgetSettings` ("My Energy Budget" renders for everyone via self endpoints) — consistent UX: personal settings sections first, household-wide grids for owners. Owners see the self section too (confirmed decision, 2026-08-18).
- The save handler keeps the snapshot discipline from `EnergyBudgetSettings.saveEnergyBudget` (`src/components/Scheduling/EnergyBudgetSettings.vue:224-252`) to avoid mid-loop store-sync resets.

**Rejected alternative: "self-scoped member fetch"** (relaxing `getMemberAvailability` to return only the caller's row, or adding `GET /api/v1/scheduling/members/me`). Both variants change the API surface (new route/controller/service code + tests) only to obtain data the client already has from `getPreferences`; relaxing the owner gate would also blur the endpoint's authorization semantics. Violates KISS for no user-visible gain.

Additionally, `SchedulingView` gates the owner-only grid (`AvailabilitySettings`) behind `userStore.isHouseOwner` (`src/store/user.js:24`), so non-owners no longer see the misleading "No Members Found" state. The `fetchMemberAvailability` call itself stays in `initializeScheduling` (unchanged): a member promoted to owner gets the grid on the next view load without a reload.

## 3. Backend / Frontend Changes

- **Backend** (`lib/`): **none.** Pure frontend solution on existing member-gated self-service endpoints (`appinfo/routes.php:104-109` unchanged).

- **Frontend** (`src/`):
  - **New** `src/components/Scheduling/SelfAvailabilitySettings.vue` (~250 lines incl. styles, within the 300-line guideline; `AvailabilitySettings` is already oversized per CR-016-03 — do not grow it):
    - Header "My Availability" + subtitle; "{n}/7 days available" counter (pattern from `AvailabilitySettings.vue:27`).
    - Seven toggle switches (reuse the `toggle-slider` markup/styles from the edit modal, `AvailabilitySettings.vue:84-99`) bound to a local copy of `schedulingStore.weekdayAvailability`; testids `self-availability-monday` … `self-availability-sunday`.
    - `hasChanges` guard (Save disabled until a toggle differs — echoes CR-016-14a, cheap in a new component) + Cancel (reset local from store).
    - Save: snapshot local state, diff against `schedulingStore.weekdayAvailability`, call `schedulingStore.updateAvailability(day, available)` per **changed** day only; success/error toasts via `@nextcloud/dialogs`; failures logged via `src/utils/logger.js` with `{ component: 'SelfAvailabilitySettings', action: 'saveAvailability' }`. **CS-033 hook:** if the bulk endpoint/action from CS-PLAN-033 exists at implementation time, replace the loop with the single bulk call — an isolated change inside this one handler.
  - **Modified** `src/views/SchedulingView.vue`:
    - Render `SelfAvailabilitySettings` as a new `scheduling-section` directly before `AvailabilitySettings` (personal settings first; for non-owners it becomes the first section).
    - Wrap the `AvailabilitySettings` section in `v-if="userStore.isHouseOwner"` (add `useUserStore` to `setup()`). The user store auto-initializes via the Pinia plugin (`src/store/index.js:213-224`), so the getter flips reactively for owners; no extra fetch needed here.
  - **i18n:** all new strings via `t('chorestrike', …)`; update `l10n/templates/chorestrike.pot`, `l10n/de.json`, `l10n/en.json` in the same change (guarded by `tests/unit/l10n/l10n-consistency.spec.js`).

- **Cross-layer contracts:** none new. Store state consumed: `scheduling.weekdayAvailability` (existing); store actions consumed: `updateAvailability` (existing; bulk variant optional via CS-033).

## 4. Tests

- **Unit (Jest):**
  - New `tests/unit/components/SelfAvailabilitySettings.spec.js` — follow the harness of `tests/unit/components/AvailabilitySettings.spec.js` (mock `@nextcloud/l10n`, `@nextcloud/dialogs`, `src/services/api.js`): renders 7 day toggles from store state; reflects current `weekdayAvailability`; Save disabled without changes; Save calls `updateAvailability` **only for changed days**; store state updated on success; error toast + no state corruption on API failure; Cancel reverts local edits.
  - Update `tests/unit/views/SchedulingView.spec.js`: self-availability section renders for every role; `AvailabilitySettings` section rendered when `currentUser.role === 'owner'`, absent otherwise (no "No Members Found" for members).
- **Unit/integration (PHPUnit):** none — no backend change. Existing coverage of the consumed endpoints stays authoritative: `tests/unit/Controller/SchedulingControllerTest.php` (`getPreferences`, `updateAvailability` incl. membership validation), `tests/unit/Service/SchedulingServiceTest.php`.
- **E2E (Playwright):** New `tests/e2e/self-availability-member.spec.ts`, run in the **default `chromium` project** with the standard `admin` storageState (`playwright.config.ts:100`) — `admin` is a plain house **member** under the unconditionally seeded `family` profile (see `playwright.config.ts:39-41` comment; seed roles in `lib/Service/TestDataService.php:291-318`: mom/dad = owner, teen/kid = child, grandma/friend = member — admin is bootstrapped as member and can never take the owner slot). `test.describe.configure({ mode: 'serial' })` + restore mutated days in-test so intra-file parallelism and other specs are unaffected. Cases:
  1. "My Availability" section visible with 7 toggles for a non-owner.
  2. Owner-only member grid hidden; misleading "No Members Found" empty state absent.
  3. Toggle a weekday → Save enabled → save succeeds → **reload** → value persisted (verifies the self-service `PUT` round-trip).
  4. Restore the original value and save (leaves seed state clean).

## 5. File Manifest

```
new:      src/components/Scheduling/SelfAvailabilitySettings.vue
new:      tests/unit/components/SelfAvailabilitySettings.spec.js
new:      tests/e2e/self-availability-member.spec.ts
modified: src/views/SchedulingView.vue
modified: tests/unit/views/SchedulingView.spec.js
modified: l10n/templates/chorestrike.pot
modified: l10n/de.json
modified: l10n/en.json
```

## 6. Implementation Order

1. Create `SelfAvailabilitySettings.vue` (toggles, hasChanges guard, snapshot-diff save via `updateAvailability`, testids, i18n strings).
2. Wire it into `SchedulingView.vue`; gate `AvailabilitySettings` on `userStore.isHouseOwner`.
3. Update `l10n/` artifacts (`pot`, `de.json`, `en.json`).
4. Jest: new component spec + `SchedulingView.spec.js` updates; make `npm test` green.
5. Playwright: add `self-availability-member.spec.ts`; run the spec (Docker env) under `chromium` plus the `chromium-scheduling-owner` regression (dad still sees grid + own-card Edit).
6. Lint (`npm run lint:fix`), full build gate, backlog/ACTIVE_TASKS bookkeeping.

## 7. Risks & Mitigations

| Risk | Likelihood | Mitigation |
|------|-----------|------------|
| Mid-run DB re-seed (serial specs) wipes the house while the member spec runs → admin re-bootstraps as owner of a fresh house → "grid hidden" assertion flakes | Low | Same exposure class as the existing `chromium-scheduling-owner` specs; `retries: 2` covers; core save/persist assertions hold for any role. Optionally assert role via `/api/v1/users/current` and skip with reason if owner. |
| Owner briefly doesn't see the member grid (user store auto-init is fire-and-forget, `src/store/index.js:215-222`) | Low | `v-if` is reactive — grid appears once `currentUser` resolves; view already renders behind the store `loading` skeleton. |
| Redundancy: owners can edit their own availability in both the grid modal and the new self section | Accepted | Consistent with existing overlap (energy editable in both `EnergyBudgetSettings` and the owner modal); one canonical self-service surface aids discoverability. |
| CS-033 lands after this plan → per-day save loop stays (≤7 calls, changed days only) | Accepted by design | Soft dependency: swap to bulk call is a one-line change in the save handler when available. |

## 8. Acceptance Criteria

- [ ] A non-owner (member/child) sees a "My Availability" section on the scheduling view, backed by `getPreferences` data, with a toggle per weekday.
- [ ] Saving toggles persists via the self-service `updateAvailability` endpoint (changed days only; bulk endpoint if CS-033 landed) and survives a page reload.
- [ ] Non-owners no longer see the owner-only grid or the misleading "No Members Found" state; owners keep the member grid unchanged.
- [ ] E2E coverage for the non-owner path exists (`self-availability-member.spec.ts`, default `chromium` project as `admin`) and is green; owner regression green.
- [ ] New user-visible strings use `t()` and `l10n/` artifacts are updated in the same change.
- [ ] Build gate green (`npm test` + lint + backend tests)
- [ ] Durable concepts/decisions integrated into the permanent docs (`docs/concepts/`, `docs/adr/`, `docs/engineering/`, `docs/api/`, `docs/standards/`) — plans are temporary
- [ ] `docs/backlog/ACTIVE_TASKS.md` updated
