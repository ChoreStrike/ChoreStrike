# CS-PLAN-045: Backend Hardening & Correctness Sweep

> **Status:** Done (2026-08-24) — implemented + Standard-B reviewed (PASS, two passes); moved to `done/`
> **Effort:** M (six S-effort items bundled)
> **Priority:** P2 (security-driven)
> **Depends on:** none
> **Related backlog items:** BUG-SEC-002, CR-027-01, CR-016-15, CR-SVC-19, CR-SVC-32, CR-SVC-33
> **Decisions signed off (per plan proposal):** D1 = delete dead path ·
> D2 = reject invalid roomType with 400 · D3 = 403 `CANNOT_DEMOTE_SELF` / 409 `CANNOT_DEMOTE_LAST_OWNER`

## 1. Problem Summary

The GUI-debt wave (CS-PLAN-041…044) is landed; what remains open clusters into small,
backend-focused correctness and security gaps that pre-release quality should not ship with:

1. **BUG-SEC-002** — `UserController::updateRole` (lib/Controller/UserController.php:259)
   validates the role value and gates on ownership, but has **no guard** against an owner
   demoting themselves or demoting the **last remaining owner** — either orphans the house.
   The CS-038 members UI ships a frontend-only mitigation (disabled own-role select,
   MemberList.vue) that a direct API call bypasses trivially. The mutation also happens
   directly in the controller (`setRole` + mapper `update`), violating the thin-controller rule.
2. **CR-027-01** — `RoomController::createRoomResponse`
   (lib/Controller/RoomController.php:188) persists any client-supplied `roomType` string;
   overlong input (> 50 chars, DB column length) surfaces as a 500, unknown types degrade
   the wizard template picker. Only the house-setup wizard validates against the catalog
   (`HouseSetupService` line 257 → `TemplateService::isValidRoomType()`).
3. **CR-016-15** — the 1–20 energy-budget check exists at three independent sites
   (`SchedulingController::validatePreferencesSection` line ~296,
   `SchedulingService` lines ~78 and ~174). A bounds/message change must be made in all
   three or self path and owner path reject/persist different values for the same column.
4. **CR-SVC-19** — several `RewardConfigService` setters/getters return raw values
   (tier multiplier, coin base, freeze cap/count, early bonus): a negative multiplier or
   zero base can invert rewards. Admin-gated, but clamping should be consistent.
5. **CR-SVC-32** — `GameLogicService::calculateDaysEarly` (lib/Service/GameLogicService.php:244)
   divides a second-difference by 86400 and rounds; across DST transitions or near midnight
   this rounds to the wrong day and can flip the early-completion bonus by ±1 day.
6. **CR-SVC-33** — `TaskService::completeTask`/`uncompleteTask`
   (lib/Service/TaskService.php:380/393) set `status='completed'` with **no**
   coins/streak/cleanliness side effects, parallel to the canonical
   `RoomTaskService::completeTask`. Grep found **no production callers** (controllers use
   `RoomTaskService::completeTask` / `completeTaskSimple`); it is a footgun waiting for a caller.

## 2. Strategy

One sweep, six small fixes, ordered by project priority (security → correctness → KISS):

- Reuse the established error-envelope patterns (`CANNOT_REMOVE_OWNER` 403 shape,
  `ControllerErrorFormatter`) instead of inventing new response styles.
- Move the role mutation into `HouseService` (thin controllers → services) and add the two
  missing guards there, where sibling invariant `removeHouseMember` already lives.
- Make the energy bounds check single-source in `SchedulingService`; the controller delegates.
- Clamp at the set/get boundary in `RewardConfigService` and log when a clamp fires.
- Replace second-arithmetic with a calendar-day diff in `calculateDaysEarly`.
- Delete the dead reward-less completion path entirely (pre-release: no BC requirement).

### Decisions requested at activation

| ID | Decision | Plan proposal |
|----|----------|---------------|
| D1 | CR-SVC-33 fate | **Delete** `TaskService::completeTask`/`uncompleteTask` (+ their tests) — no callers, pre-release |
| D2 | CR-027-01 behavior on invalid `roomType` | **Reject with 400** `VALIDATION_ERROR` (not silent `'generic'` fallback — never trust client input) |
| D3 | BUG-SEC-002 status codes | Self-demotion → **403** `CANNOT_DEMOTE_SELF` (mirrors `CANNOT_REMOVE_OWNER` semantics); last-owner demotion → **409** `CANNOT_DEMOTE_LAST_OWNER` |

## 3. Backend / Frontend Changes

### Backend (`lib/`)

1. **Role guards (BUG-SEC-002)**
   - New `HouseService::updateUserRole(int $houseUserId, string $role, string $callerUserId): HouseUser`
     containing: role whitelist check (moved from controller), target lookup, and the two guards:
     - target's `nextcloudUserId === $callerUserId && $role !== 'owner'` → `ForbiddenException('Cannot demote yourself')`;
     - target `isOwner() && $role !== 'owner'` and exactly **one** owner row exists for the house →
       conflict exception mapped to `CANNOT_DEMOTE_LAST_OWNER`.
   - Owner-count query via `HouseUserMapper` (count owners scoped to `houseId`; add a small
     `countOwnersForHouse` if absent — prepared statement, no SQL concatenation).
   - `UserController::updateRole` becomes thin: find, gate `requireHouseOwner`, delegate,
     map exceptions to the JSON error envelope (`403 CANNOT_DEMOTE_SELF`,
     `409 CANNOT_DEMOTE_LAST_OWNER`), keep `VALIDATION_ERROR` 400 for bad role values.
     Build the custom-code envelopes inline per the `CANNOT_REMOVE_OWNER` precedent:
     `ControllerErrorFormatter::forbiddenResponse()` always emits code `FORBIDDEN` and
     there is no 409 formatter, so neither helper can carry the new codes.
2. **roomType validation (CR-027-01)**
   - In `createRoomResponse`, after resolving `$resolvedRoomType`: reject non-catalog types
     via `TemplateService::isValidRoomType()` and reject `strlen > 50` with
     `400 VALIDATION_ERROR`. Requires injecting `TemplateService` into `RoomController`.
     (`update()` takes only `name` today — no change there.)
3. **Single energy-bounds source (CR-016-15)**
   - Add `SchedulingService::assertValidEnergyBudget(mixed $value): void` (or a named
     constant pair + tiny helper) throwing the exact current message
     *Energy budget must be between 1 and 20*; call it from
     `SchedulingController::validatePreferencesSection`, `SchedulingService::updatePreferencesByNextcloudUserId`,
     and `applyMemberUpdates`. No behavioral change — pure DRY.
4. **Reward config clamping (CR-SVC-19)**
   - Clamp all numeric setters: multipliers `>= 0` (early-bonus multiplier `>= 1.0`),
     coin base `>= 0`, freeze cap/count/threshold `>= 0`, threshold-day counts `>= 0`,
     weekly-goal percent within its configured range; clamp in the getters as well so
     previously persisted out-of-range values are never applied raw (risk row 3);
     log an `AppLogger` warning with key + clamped value when clamping fires.
5. **Calendar-day diff (CR-SVC-32)**
   - `calculateDaysEarly`: normalize `idealDueDate` and `now` to local midnight
     (`setTime(0, 0, 0)` on clones) and diff day boundaries via `DateTime::diff` days count —
     no division of raw seconds.
6. **Dead-path removal (CR-SVC-33, gated on D1)**
   - Delete `TaskService::completeTask`/`uncompleteTask` and their unit tests; re-grep for
     callers immediately before the deletion commit.

### Frontend (`src/`)

- `MemberList.vue changeRole` already renders `error?.message` via `showError`; verify the
  shared API client surfaces the new 403/409 envelope messages end-to-end (no code change
  expected beyond a Jest spec asserting the toast + select reset on a rejected role change).
- l10n: any newly user-visible string (error messages are already server-side `t()`-wrapped
   following the `CANNOT_REMOVE_OWNER` precedent); sync `l10n/en.json` + `l10n/de.json`
   (+ `l10n/templates/chorestrike.pot`) artifacts in the same change.

## 4. Tests

- **PHPUnit unit**
  - `HouseServiceTest`: self-demotion rejected; last-owner demotion rejected; demotion of a
    co-owner while another owner remains allowed; valid promotion allowed.
  - `UserControllerTest` (mock-based): exception → status/code mapping for all three outcomes.
  - `RoomControllerTest`: unknown `roomType`, >50 chars, and omitted `roomType` (defaults
    `generic`) each pinned.
  - `SchedulingService`/`SchedulingControllerTest`: bounds helper rejects 0/21/non-int with
    the stable message at every call site (consistency pin).
  - `RewardConfigServiceTest`: each clamp bound (negative multiplier, zero base, cap floors)
    pinned incl. logger warning.
  - `GameLogicServiceTest`: `calculateDaysEarly` with fixed dates around a DST boundary and
    near-midnight completions (no wall-clock dependence).
- **Integration (Docker, real DB)**
  - Role-guard integration test mirroring the `CANNOT_REMOVE_OWNER` pattern: seed two-owner
    and one-owner houses, exercise the endpoint as owner, assert 403/409 codes and unchanged rows.
- **Jest**
  - `MemberList.spec.js`: rejected `changeRole` shows the server message and resets the select.
- **E2E**: no new specs; existing `members-owner` suite must stay green.

## 5. File Manifest

```
new:      tests/unit/Service/HouseUserRoleGuardTest.php (or extend HouseServiceTest)
new:      tests/integration/RoleGuardApiTest.php (real DB, per *ApiTest.php convention)
modified: lib/Controller/UserController.php
modified: lib/Service/HouseService.php
modified: lib/Mapper/HouseUserMapper.php (countOwnersForHouse, if absent)
modified: lib/Controller/RoomController.php
modified: lib/Controller/SchedulingController.php
modified: lib/Service/SchedulingService.php
modified: lib/Service/RewardConfigService.php
modified: lib/Service/GameLogicService.php
modified: lib/Service/TaskService.php (deletion, D1)
modified: tests/unit/components/MemberList.spec.js
modified: l10n/de.json, l10n/en.json, l10n/templates/chorestrike.pot (if new keys)
modified: docs/backlog/backlog.md (close the six items at completion -> archive/2026-08.md)
```

## 6. Implementation Order

1. **BUG-SEC-002** backend: mapper owner-count, `HouseService::updateUserRole` + guards,
   thin controller, unit + integration tests.
2. Frontend verification for the new codes (Jest spec; l10n sync if needed).
3. **CR-027-01** roomType validation + unit tests (inject `TemplateService`).
4. **CR-016-15** shared energy-bounds helper + call-site migration + consistency pin test.
5. **CR-SVC-19** clamping + logger warnings + tests.
6. **CR-SVC-32** calendar-day diff + fixed-date DST tests.
7. **CR-SVC-33** final caller re-grep, delete dead path + tests (D1).
8. Full build gate (§8), self-review vs. do/don't checklist, Standard review,
    backlog close-out (items → `archive/2026-08.md`), knowledge integration
    (role-guard contract → `docs/engineering/architecture.md`; no error-code section
    exists yet, so fold it into `API Design` or add a small subsection).

## 7. Risks & Mitigations

| Risk | Likelihood | Mitigation |
|------|-----------|------------|
| Role guard breaks a legit flow (e.g. owner handover via demote-self after promoting someone else) | Low | Guard only blocks demote-self while target stays non-owner; handover = promote co-owner first, then self-demote is still blocked — document explicit handover gap in plan output and, if desired, follow up as separate item rather than weakening the guard silently |
| Hidden caller of `TaskService::completeTask` surfaces late | Low | Re-grep immediately before deletion; full-suite gate after step 7 |
| Clamp changes previously persisted out-of-range config values | Medium | Clamp on read+write paths consistently; log warning; integration env uses fresh seeds anyway |
| DST test depends on host timezone | Medium | Fixed timestamps with explicit `DateTimeZone` in tests only |
| Energy-helper refactor alters response messages | Low | Exact message string preserved and pinned by tests |
| Windows toolchain re-corrupts backlog/docs UTF-8 (TECH-DOCS-001) | Medium | After doc edits, byte-verify non-ASCII content or keep new prose ASCII-safe |

## 8. Acceptance Criteria

- [x] Self-demotion and last-owner demotion rejected server-side with stable codes
      (`CANNOT_DEMOTE_SELF` 403 / `CANNOT_DEMOTE_LAST_OWNER` 409); role mutation lives in
      `HouseService`, controller is thin
- [x] Invalid/oversized `roomType` rejected with 400; catalog types and default `generic` pass
- [x] Exactly one energy-bounds check implementation; all three former sites delegate
- [x] All `RewardConfigService` numeric configs clamped; clamp events logged
- [x] `calculateDaysEarly` uses calendar-day diff; DST/midnight unit tests pass deterministically
- [x] Dead reward-less completion path removed (per D1) with green full suites
- [x] Build gate green: PHPUnit unit + integration (Docker), Jest, `npm run lint` +
      stylelint, webpack build, l10n clean, targeted E2E (`members-owner`)
- [x] The six backlog items closed (moved to `archive/2026-08.md`), `ACTIVE_TASKS.md` updated
- [x] Durable decisions integrated into permanent docs (error-code conventions /
      architecture notes), not left only in this plan file

## 9. Implementation Outcome (2026-08-24)

All six items landed as planned; deviations from the manifest are recorded here:

- **BUG-SEC-002:** `HouseService::updateUserRole(int $houseUserId, string $role,
  string $callerUserId)` carries the whitelist + both guards; new
  `lib/Exception/ConflictException.php` maps to 409;
  `HouseUserMapper::countOwnersForHouse` added. The controller also gained the
  sibling-style 404 `USER_NOT_FOUND` catch (parity with `remove()`; a missing
  id previously fell into the 500 catch-all). Manifest deviation:
  `tests/unit/Service/HouseUserRoleGuardTest.php` was folded into
  `HouseServiceTest` (plan-permitted alternative), and the mock-based
  `tests/integration/Controller/UserControllerIntegrationTest` updateRole
  tests were migrated to the new delegation (not listed in the manifest,
  required for a green suite).
- **409 reachability note:** via the endpoint the last-owner conflict is
  unreachable — `requireHouseOwner` gates the caller to owners, and with
  exactly one owner the target *is* the caller, so the self-guard (403)
  fires first. The 409 invariant is pinned at the service level
  (`RoleGuardApiTest`), protecting direct (non-controller) callers.
- **Handover gap (risk row 1, confirmed):** owner handover requires
  promoting a co-owner first; the former owner still cannot demote
  themselves afterwards. Documented in `docs/engineering/architecture.md`
  (Security → Authorization) as a follow-up candidate; the guard was not
  weakened.
- **CR-027-01:** `TemplateService` injected into `RoomController`;
  `ROOM_TYPE_MAX_LENGTH = 50` constant; messages follow the controller's
  existing non-`t()` validation style.
- **CR-016-15:** `SchedulingService::assertValidEnergyBudget` +
  `ENERGY_BUDGET_MIN/MAX` constants; exact message pinned via a
  data-provider test at the helper plus the pre-existing call-site tests.
- **CR-SVC-19:** clamp helpers (`clampInt`/`clampFloat`/`logClamp`) applied
  on read + write paths incl. the previously-clamped getters/setters, which
  now also log; `AppLogger` is an optional constructor dependency.
- **CR-SVC-32:** `calculateDaysEarly(RoomTask, ?\DateTime $now = null)` —
  the optional `$now` makes the DST/midnight tests wall-clock-independent.
- **CR-SVC-33:** re-grep immediately before deletion confirmed zero
  production callers; both methods deleted, no tests referenced them.
- **l10n:** new backend string `Cannot demote the last house owner`
  (en/de/pot); reused existing `You cannot change your own role` for the
  403 message and added its previously-missing POT entry.
- **Gate:** PHPUnit unit **838** OK / integration **196** OK
  [24 pre-existing skips] / Jest **831** / lint + stylelint 0/0 / noise
  clean / cs:check clean / l10n check clean / webpack build green / E2E
  members-owner **6/6** (`--retries=0`).

## Review Outcome (Standard B, 2026-08-24)

Scope: all changed files of this plan (9 lib files + new `ConflictException`,
new `RoleGuardApiTest`, 11 test files, l10n, backlog/docs). Checklist result:
architecture (thin controller, service-owned guards, prepared statements),
authorization (`@NoAdminRequired` + `requireHouseOwner`, server-side guards),
security (input rejected at the boundary, no secrets logged, safe 500s), KISS,
logging (`AppLogger` clamp warnings only when a value changes), and i18n
(en/de/pot synced) — all clean. Caller re-grep for `TaskService::completeTask`
/ `uncompleteTask` independently confirmed zero remaining references.

- **0 code findings.** No auto-fixes in PHP/JS were needed.
- **Auto-fixed (docs):** two stale `plans/active/cs-plan-043-…` links
  (`ACTIVE_TASKS.md`, `archive/2026-08.md`) updated to `plans/done/…` after
  the plan-043 move.
- **Deferred (backlog):** RoomController's new VALIDATION_ERROR messages stay
  unwrapped per this plan's recorded style decision; scope note appended to
  existing item **CR-SVC-37** instead of a new item.
- **Gate re-run during review:** lint:fix clean · Jest 831/831 · Docker
  PHPUnit unit 838 OK / integration 196 OK [24 pre-existing skips].
- UTF-8 byte-check on all edited docs: clean (TECH-DOCS-001 did not recur).

### Second-pass confirmation (Standard B, 2026-08-24)

Independent re-review of the full uncommitted change set (27 tracked files +
4 untracked: this plan, `ConflictException.php`, `RoleGuardApiTest.php`,
plan-043 `done/` copy). Shared checklist re-checked item by item: thin
controller / service-owned guards, `@NoAdminRequired` + `requireHouseOwner`
+ `requireCurrentUserId`, prepared statements in `countOwnersForHouse`,
boundary validation in `createRoomResponse` (default `'generic'` resolved
before the catalog check), single energy-bounds source, read+write clamping
with change-only `AppLogger` warnings, calendar-day diff with injectable
`$now`, dead-path deletion re-grepped clean (remaining `completeTask`
matches are `RoomTaskService`/`TaskSyncService` only), l10n en/de/pot in
sync, error-code conventions + owner invariants integrated into
`docs/engineering/architecture.md`. **0 findings, 0 auto-fixes.** Gate
re-verified: lint:fix clean · Jest 831/831 · Docker PHPUnit unit 838 OK /
integration 196 OK [24 pre-existing skips].
