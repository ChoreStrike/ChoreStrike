# CS-PLAN-054: P1 Security Quick-Closes

> **Status:** Done — implemented 2026-08-29 (all four WPs + l10n/docs + build gate
> green; CR-053-01…04 + CR-053-22 Checklist remainder archived to
> [`archive/2026-08.md`](../../backlog/archive/2026-08.md)); Standard-B
> **reviewed PASS 2026-08-29** (§11) and moved to `done/`
> **Effort:** M (3×S + 1×M bundled)
> **Priority:** P1 (security)
> **Depends on:** none (findings from CS-PLAN-053, record [`CODE_REVIEW_2026-08-27.md`](../../backlog/CODE_REVIEW_2026-08-27.md))
> **Related backlog items:** CR-053-01, CR-053-02, CR-053-03, CR-053-04 (+ CR-053-22 partial: Checklist integration suite)
> **Scope note:** review §6.7 proposed CR-053-01 as a separate CS-PLAN-055; per user
> direction this plan bundles **all four P1 security items**. CR-053-06 (P2, lifecycle
> visibility matrix) stays a follow-up and is *not* in scope.

## 1. Problem Summary

CS-PLAN-053 verified four **P1** authorization gaps. All file:line refs re-verified
against the tree on 2026-08-29:

1. **CR-053-01 — Checklist BOLA (business logic also in the wrong layer).**
   `ChecklistController` (lib/Controller/ChecklistController.php, 437 lines) validates
   only *caller house membership* on every endpoint:
   - Task-item endpoints never check the task at all: `getTaskItems` (:57), `addTaskItem`
     (:87), `updateTaskItem` (:134), `deleteTaskItem` (:175), `reorderTaskItems` (:206) —
     any member of any house can read/create/relabel/delete/reorder the checklist items
     of **any** house's tasks by scanning ids; within a house, private/admin-only task
     items are exposed to members/children.
   - `reorderTaskItems` passes the raw `itemOrders` request array straight to
     `updateSortOrders` (:208 → :224) — the array keys become item ids (corruption vector).
   - Room-task endpoints (`getRoomTaskProgress` :248, `toggleItem` :291,
     `initializeProgress` :334, `getCompleteChecklist` :379) do call
     `validateRoomTaskOwnership` (RoomTaskService.php:79) but enforce **no visibility
     matrix**, and `toggleItem` never checks that `itemId` belongs to that room task's
     task (:296–298).
   - All of this logic lives in the controller (architecture violation: thin controllers
     → services).
2. **CR-053-02 — `GET /room-tasks` IDOR.** `RoomTaskController::index` (:113–131) passes
   client-supplied `roomId`/`scopeId` straight to `RoomTaskService::getRoomTasks` (:130)
   / `getScopeTasks` (:305), which delegate to `RoomTaskMapper::findByRoomId` /
   `findByScopeId` (:246) with **no house scoping** — cross-house room-task reads
   (including assignee user ids) by scanning ids. `roomStats()` received exactly this fix
   in CS-PLAN-040 (:944–947: `validateRoomOwnership($roomId, $house->getId())`);
   `index()` didn't.
3. **CR-053-03 — Floor/room deletion lacks the documented owner gate.**
   `FloorController::delete` (:357–364) and `RoomController::delete` (:381–388) gate on
   `ensureHouseMember` + ownership validation only. security.md RBAC and the owner-only
   Settings/RoomDetail UI (CS-PLAN-042) intend **owner-only** — today any member (child
   role) can cascade-delete structure including completions.
4. **CR-053-04 — `taskTier` mass-assignment → self-service 2× coin multiplier.**
   `taskTier` sits in `TaskService::MUTABLE_FIELDS` (:38) with no closed-set check and no
   owner gate; `updateTask` (:185–217) applies it via the generic whitelist loop
   (`TaskController::update` already passes `$callerUserId` + `$houseUser->isOwner()`,
   :436–441, so the service has the context it needs). Any member can set `gold` on any
   public task and earn the 2.0× multiplier on every completion. No frontend sender
   exists (grep: zero `taskTier` in `src/` — tier references are title/medal visuals
   only), so gating breaks no UI flow.

## 2. Strategy

One backend-only sweep, four work packages ordered smallest-first, reusing established
patterns instead of inventing new ones:

- Owner gate: `AuthorizationService::requireHouseOwner` (:211; precedent
  `HouseController::104` — read-only, requires upstream `ensureHouseMember`, which both
  delete controllers already run).
- House scoping: the `roomStats` validation pattern (:944–947) for the `roomId` branch;
  the batched skip-on-missing `RoomTaskMapper::findByIdsScopedToHouse` (:522,
  CS-PLAN-037 idiom) to house-filter the `scopeId` branch.
- Checklist gate: new `ChecklistService` enforcing house linkage (room-chain
  room_task → room → floor → house, mirroring `validateRoomTaskOwnership`) plus the
  task-visibility matrix already codified in `TaskMapper::findVisibleByHouse`
  (:230–260: public → any member; private → `owner_user_id` match; admin-only → house
  owner) and `RoomService:371–378`.
- Anti-enumeration: visibility/house denials surface as 404 `RESOURCE_NOT_FOUND`
  (the `TaskController::update` precedent, :442–446), never a 403 that confirms a
  resource exists. Role denials (owner-gated deletes, tier changes) surface as 403.

### Decisions requested at activation

| ID | Decision | Plan proposal |
|----|----------|---------------|
| D1 | CR-053-04 `taskTier` handling | **Owner-gate + closed set**: remove from the generic whitelist loop, accept `taskTier` only when `$isOwner` and `in_array($value, ['bronze','silver','gold'])` (no creation path sets a tier — `createTask`/`materializeTemplate` have no tier field, entity default is `silver`; owners may adjust after creation; members never) |
| D2 | CR-053-02 `scopeId` branch | **House-filter now**: filter `findByScopeId` results through `findByIdsScopedToHouse` (one extra batched query) so the fix is independent of the CR-053-14 route-disposition outcome (the scope surface is production-unconsumed and may be pruned later) |

## 3. Backend / Frontend Changes

### Backend (`lib/`)

1. **WP-1 (CR-053-02): `RoomTaskController::index` house scoping**
   - `roomId` branch: add `$this->roomTaskService->validateRoomOwnership($roomId, $house->getId())`
     before fetching — byte-for-byte the `roomStats` fix pattern (:944–947).
     `index()` currently catches only `ForbiddenException` + generic `\Exception`
     (→ 500), so also add the `NotFoundException` → 404 `RESOURCE_NOT_FOUND`
     catch mirroring `roomStats` (:955–962) — otherwise the ownership denial
     would surface as 500.
   - `scopeId` branch (D2): new house-scoped read, e.g.
     `RoomTaskService::getScopeTasksScopedToHouse(int $scopeId, int $houseId, bool $activeOnly)`:
     fetch via `findByScopeId`, then intersect with `findByIdsScopedToHouse` ids;
     cross-house entries are silently absent (skip-on-missing semantics).
   - Controller stays thin; no response-shape change for legitimate callers.
2. **WP-2 (CR-053-03): owner-only structure deletion**
   - `FloorController::delete` / `RoomController::delete`: after the existing
     `ensureHouseMember`, add `$this->authService->requireHouseOwner($house->getId())`
     (403 `FORBIDDEN` via the existing catch → `ControllerErrorFormatter::forbiddenResponse`).
   - No service change: `deleteFloor`/`deleteRoom` (transactional cascades, CS-PLAN-037)
     stay as-is.
3. **WP-3 (CR-053-04): `taskTier` gate in `TaskService::updateTask`**
   - Remove `taskTier` from `MUTABLE_FIELDS` (:38) and handle it explicitly before the
     generic loop: `array_key_exists('taskTier', $updates)` → require `$isOwner === true`
     (else `ForbiddenException`, translated) + closed-set validation
     `['bronze','silver','gold']` (else `\InvalidArgumentException` → 400). Update the
     `MUTABLE_FIELDS` docblock.
   - **Mapping note:** `TaskController::update`'s inner catch currently maps **every**
     `ForbiddenException` from `updateTask` to 404 (CR-SVC-17 visibility
     anti-enumeration, :442–453). The tier role denial must surface as 403 per
     §2/§8, so the implementation must route it around that catch (e.g. a
     controller-side pre-check mirroring the existing controller-level visibility
     gates, with the service gate kept as defense-in-depth). `TaskController.php`
     joins the manifest either way.
   - Creation path (templates/task creation) is untouched — only `updateTask` changes.
4. **WP-4 (CR-053-01): `ChecklistService` extraction**
   - New `lib/Service/ChecklistService.php` (≤ ~300 lines) owning all checklist logic;
     `ChecklistController` becomes thin (preamble + delegate + envelope), mirroring
     sibling controllers.
   - Core guard `assertTaskAccessible(int $taskId, int $houseId, HouseUser $viewer): Task`:
     1. task exists (else 404 `NotFoundException`),
     2. house linkage via room-chain EXISTS (room_tasks → rooms → floors → houseId) —
        small mapper helper (prepared statement, no SQL concatenation); a task with no
        room-task linkage yet is treated as house-linked to the caller's house
        (single-house-per-instance today; pinned by a test),
     3. visibility matrix per `findVisibleByHouse` rules; denial → 404
        (`NotFoundException`, anti-enumeration, TaskController precedent).
   - Endpoint wiring: task-item endpoints (`get/add/update/delete/reorder`) run the guard
     for the affected task(s); item endpoints additionally resolve item → task linkage
     (`updateTaskItem`/`deleteTaskItem` reject foreign item ids with 404);
     `reorderTaskItems` validates the payload shape (int item ids **belonging to one
     task of this house**, int orders) before delegating; room-task endpoints
     (`progress/toggle/initialize/complete`) keep `validateRoomTaskOwnership` + gain the
     guard via the room task's own `taskId`; `toggleItem` verifies `itemId` belongs to
     that task's checklist.
   - Logging: `AppLogger` with stable keys (`service`, `action`, `taskId`, `houseId`);
     no raw user content.

### Frontend (`src/`)

- **None.** The UI already owner-gates the structure surfaces (Settings + RoomDetail,
  CS-PLAN-042/044), `TaskEditForm` sends no `taskTier`, and the new 403/404 envelopes
  flow through existing error toasts unchanged.

### Cross-layer contracts

- Error envelopes unchanged (`FORBIDDEN` 403, `RESOURCE_NOT_FOUND` 404,
  `VALIDATION_ERROR` 400 via `ControllerErrorFormatter`); happy-path payloads
  byte-identical.

### i18n

- New user-visible messages (`t()`-wrapped, e.g. tier gate + reorder validation) synced
  to `l10n/en.json`, `l10n/de.json`, and the POT template in the same change;
  `npm run l10n:check-keys` green.

## 4. Tests

- **PHPUnit unit** (`tests/unit/`):
  - `Service/TaskServiceTest.php` (exists): D1 matrix — member + `taskTier` → 403;
    owner + invalid tier → 400; owner + `gold` → persisted; member + `name` → ok
    (whitelist loop regression).
  - `Controller/RoomTaskControllerTest.php` (exists): cross-house `roomId` → 404;
    scope branch returns no cross-house rows.
  - `Controller/ChecklistControllerTest.php` (exists): rewire to `ChecklistService`
    mocks; guard-called assertions per endpoint.
  - `Service/RoomTaskServiceTest.php` (exists): scope-filter semantics
    (skip-on-missing intersection).
  - `Service/TaskServiceWhitelistTest.php` (exists): drop `taskTier` from the
    multi-field atomicity payload (:82–92 — today applied as a non-owner).
- **Integration, real DB in Docker** (`tests/integration/Controller/`):
  - New `ChecklistApiTest.php` — the CR-053-22 partial companion: cross-house
    task/item access → 404; private-task items → 404 for a non-owner member;
    admin-only task items → 404 for member, 200 for owner; foreign item in
    update/delete/reorder/toggle → 404; happy paths for all eight endpoints.
  - New `RoomTaskIndexAuthorizationTest.php`: cross-house `roomId` → 404;
    foreign `scopeId` yields zero cross-house rows; same-house → 200 with assignees.
  - Extend `FloorControllerIntegrationTest.php` + `RoomControllerIntegrationTest.php`
    (RoleGuardApiTest pattern): member/child delete → 403 + structure intact;
    owner delete → 200.
- **Jest:** none (no frontend change).
- **E2E (Playwright):** none required — UI flows for legitimate users are
  behavior-neutral (structure specs already use the owner actor); regression protection
  lives in the integration suites above.

## 5. File Manifest

```
new:      lib/Service/ChecklistService.php
new:      tests/integration/Controller/ChecklistApiTest.php
new:      tests/integration/Controller/RoomTaskIndexAuthorizationTest.php
modified: lib/Controller/ChecklistController.php
modified: lib/Controller/RoomTaskController.php
modified: lib/Controller/FloorController.php
modified: lib/Controller/RoomController.php
modified: lib/Controller/TaskController.php (tier-gate 403 routing — see WP-3 mapping note)
modified: lib/Service/TaskService.php
modified: lib/Service/RoomTaskService.php
modified: lib/Db/RoomTaskMapper.php
modified: tests/unit/Service/TaskServiceTest.php
modified: tests/unit/Service/TaskServiceWhitelistTest.php (taskTier leaves the whitelist payload)
modified: tests/unit/Service/RoomTaskServiceTest.php
modified: tests/unit/Controller/RoomTaskControllerTest.php
modified: tests/unit/Controller/ChecklistControllerTest.php
modified: tests/integration/Controller/FloorControllerIntegrationTest.php
modified: tests/integration/Controller/RoomControllerIntegrationTest.php
modified: l10n/en.json
modified: l10n/de.json
modified: l10n/templates/chorestrike.pot
modified: docs/api/README.md (auth notes for the hardened endpoints)
```

## 6. Implementation Order

1. **WP-2** owner gates on floor/room delete + integration tests (smallest, unblocks
   nothing else, proves the gate pattern).
2. **WP-3** `taskTier` gate + unit tests (whitelist loop untouched for all other
   fields — regression-pinned).
3. **WP-1** `index()` house scoping (roomId validation + scope filter) + unit +
   integration tests.
4. **WP-4** `ChecklistService` extraction + thin controller + `ChecklistApiTest`
   (largest; lands last so its suite can assert the final guard semantics).
5. l10n artifact sync + `docs/api/README.md` auth notes.
6. Full build gate (`npm test` + `npm run lint` + Docker PHPUnit unit + integration),
   `ACTIVE_TASKS.md` update, move CR-053-01…04 (+ CR-053-22 partial) to
   `archive/2026-08.md`, propose next tasks.

## 7. Risks & Mitigations

| Risk | Likelihood | Mitigation |
|------|-----------|------------|
| Owner gate breaks member-initiated delete flows | Low | UI is already owner-only (CS-PLAN-042/044); E2E structure specs use the owner actor — full suites re-run in step 6 |
| Stricter checklist guards change response codes for calls that "worked" before | Certain (intended) | Pre-release: no BC requirement (project-rules §1); new contract pinned by `ChecklistApiTest` |
| `taskTier` removal from the whitelist loop breaks task-creation flows | Low | No creation-path writer exists (`createTask`/`materializeTemplate` have no tier field; entity default `silver`; creation path untouched); grep-verified no other `taskTier` writer; `TaskServiceTest` + `TaskServiceWhitelistTest` regression pins |
| Scope filtering adds one batched query per `scopeId` request | Low | Negligible (CS-PLAN-037 idiom); branch is production-unconsumed anyway |
| Room-chain EXISTS helper wrong for tasks without room tasks | Medium | Explicit test for the unlinked-task case; single-house-per-instance makes the fallback safe today; revisit under CR-053-14/15 |

## 8. Acceptance Criteria

- [x] Cross-house checklist task/item access returns 404 in all eight endpoints
      (pinned by `ChecklistApiTest`).
- [x] Private/admin-only task checklist items are invisible to non-entitled members
      (visibility matrix pinned).
- [x] `reorderTaskItems` rejects foreign/malformed item ids; `toggleItem` rejects
      items not belonging to the room task's checklist.
- [x] `GET /room-tasks` with a foreign `roomId` returns 404; foreign `scopeId`
      returns no cross-house rows.
- [x] Member/child floor + room delete returns 403 and leaves structure intact;
      owner delete succeeds.
- [x] `taskTier` via `updateTask`: member → 403, invalid value → 400, owner + valid →
      persisted; all other whitelisted fields behave unchanged.
- [x] Checklist business logic lives in `ChecklistService`; `ChecklistController`
      contains no persistence or authorization logic (thin-controller rule).
- [x] New user-visible strings wrapped in `t()` and synced to `l10n/` artifacts;
      `l10n:check-keys` green.
- [x] Build gate green (`npm test` + lint + stylelint + Docker PHPUnit unit +
      integration; no new skips/failures vs the CS-PLAN-053 baseline).
- [x] CR-053-01…04 moved to `archive/2026-08.md`; CR-053-22 updated (Checklist suite
      closed, remainder tracked); `ACTIVE_TASKS.md` updated.
- [x] Durable outcomes integrated: auth semantics recorded in `docs/api/README.md`
      (endpoint auth notes; no new `security.md` rules needed — the existing
      membership/owner-gate primitives already codify the patterns applied here).
- [x] Zero raw `console.*` / no secrets logged; `AppLogger` + stable context keys only.

## 9. Implementation Record (2026-08-29)

All four WPs implemented as planned (§6 order: WP-2 → WP-3 → WP-1 → WP-4 →
l10n/docs → gate). Manifest deviations (all additive):

- `lib/Controller/ControllerErrorFormatter.php` (modified, not in §5): the
  thin ChecklistController reuses two new shared helpers
  (`notFoundResponse`, `serverErrorResponse`) instead of nine inline
  catch blocks — DRY, same envelopes.
- `tests/unit/Controller/TaskControllerTest.php` (modified, not in §5):
  pins the WP-3 mapping note (member + taskTier → 403 FORBIDDEN, not the
  inner Forbidden→404 catch; invalid tier → 400) at the endpoint level.
- The two `*IntegrationTest` files were converted to extend
  `IntegrationTestCase` (mock-based endpoint tests kept unchanged
  side-by-side) so the RoleGuardApiTest-pattern real-DB delete-gate tests
  could live in them per §5.

Gate: Docker PHPUnit unit **914** (baseline 893 + 21 new) / integration
**233** (baseline 200 + 33 new, 24 pre-existing skips) · Jest 848 · lint +
stylelint 0/0 · cs:check 0/272 · noise + `l10n:check-keys` +
`docs:encoding` clean.

## 10. User Decision Block (CR-053-12 / 14 / 21 / 22)

> **Not part of this plan's implementation scope.** These are the four decisions the
> CS-PLAN-053 review (§6.7 item 3) packaged for the user; they gate the *follow-up*
> plans. **Answered by the user 2026-08-29 — all four recommendations accepted** and
> mirrored into the backlog items.

| ID | Item (Prio/Effort) | Decision | Options (★ = recommended) | Answer |
|----|--------------------|----------|---------------------------|--------|
| U1 | CR-053-12 — Calendar-day semantics on server TZ (P2, L) | Timezone design basis for all day-boundary logic (streaks, freezes, due-today, energy windows) + the two frontend day-key conventions | ★ **A. Per-house timezone** — `houses.timezone` column; backend buckets in house TZ; frontend unified on house TZ · B. Per-user timezone (inconsistent streaks across members) · C. Keep server TZ, document as limitation | **A — per-house timezone** (2026-08-29) |
| U2 | CR-053-14 — Route disposition (P2, M) | Fate of the 30 no-consumer + 12 test-only routes (incl. task-sync ×6, reward-config ×2, 4 dead alias routes + their GET-create branches = BUG-ROOM-001, and the mirroring dead-store layer CR-053-15/20) | ★ **A. Prune now** — delete no-consumer/test-only routes + dead wrappers (pre-release, no BC; smallest attack surface; re-add when a consumer exists) · B. Keep + harden only (authorization fixes, no deletion) · C. Split: prune the clearly-dead aliases/variants now, decide task-sync + reward-config with a product call | **A — prune now** (2026-08-29) |
| U3 | CR-053-21 — Root README truth-repair (P3, M) | Root `README.md` still markets tower-defense/Sparks/removed features and "v1.0.0 RC" | ★ **A. Rewrite** as an accurate project README (features, stack, install, v0.8.x alpha status) · B. Historical banner + move marketing copy to `docs/`, new lean README | **A — rewrite** (2026-08-29) |
| U4 | CR-053-22 — Deferred test-gap closures (P3, L) | Approve or reject the CS-PLAN-053 deferral of: integration suites for Minigame/RewardConfig/TaskSync controllers (Checklist closes via CS-PLAN-054 WP-4), `TestDataSeedTest`, 8 component Jest specs | ★ **A. Approve deferral** with backlog tracking (risk accepted: 3 controllers without integration coverage until then) · B. Reject — schedule a dedicated catch-up plan next | **A — approved** (2026-08-29) |

**Consequences of the accepted answers:** U2-A turns CR-053-15/20 + BUG-ROOM-001
into one deletion plan and moots the `RewardConfigController` authorization question
(CR-053-05) for as long as the routes are gone; U1-A scopes the follow-up as
migration + `DateTimeZone` injection at the day-boundary call sites; U4-A keeps
CR-053-22 open (deferral approved) with the Checklist remainder already closed by
this plan; U3-A scopes CR-053-21's README work as a full rewrite.

## 11. Review Outcome (Standard B — 2026-08-29)

Code review of the uncommitted CS-PLAN-054 working tree against the §3 shared
checklist (authorization / security focus). **Verdict: PASS.**

**Verified (no findings):**
- *Guard placement / anti-enumeration* — `ChecklistService::assertTaskAccessible`
  returns `NotFoundException` (404) for every cross-house and visibility-matrix
  denial; the controller's `requireViewer()` still surfaces genuine role denials
  via `ensureHouseMember`. The room-chain EXISTS helper (`findHouseIdsForTask`)
  matches the visibility matrix used by `TaskMapper::findVisibleByHouse`
  (public / private-owner / admin-only-owner).
- *Gate ordering* — the `taskTier` owner gate sits **after** the visibility
  access gate in both `TaskController::update` (pre-check) and
  `TaskService::updateTask` (defense-in-depth), so a private/admin-only task
  denial is 404 before a tier denial is ever evaluated; closed set
  `['bronze','silver','gold']` enforced with a strict `in_array`.
- *reorder payload* — sanitized to `int id => int order`, cross-checked that all
  ids resolve (else 404) and map to exactly one accessible task (else 400) before
  `updateSortOrders`; `toggleItem` verifies item→task linkage. The raw client map
  never reaches the mapper — the CR-053-01 corruption vector is closed.
- *WP-1/WP-2* — `index()` validates `roomId` via `validateRoomOwnership` (→404)
  and filters `scopeId` via `getScopeTasksScopedToHouse` (skip-on-missing,
  original order preserved); floor/room `delete` add `requireHouseOwner` after
  the existing `ensureHouseMember` (403).
- *Tests* — `ChecklistApiTest` + `RoomTaskIndexAuthorizationTest` (real SQL,
  persisted-state negative assertions) + rewired unit/controller tests match plan
  §4 scope; controllers stay thin (business logic in `ChecklistService`).
- *Logging/i18n* — `AppLogger` + `securityWarning` with stable keys, no
  `console.*`, no sensitive values logged; 5 new `t()` strings synced to en/de/pot.

**Auto-fix applied (§5, inline):**
1. `RoomTaskController::index` — replaced the hand-inlined 404 envelope with the
   shared `ControllerErrorFormatter::notFoundResponse()` (the sibling
   `serverErrorResponse` helper introduced by WP-4; identical shape, DRY). A
   `catch (NotFoundException)` that predated this plan in `roomStats()` was left
   as-is (out of the CR-053 change surface).

**Quality gate re-run after the fix (all green):** `eslint --fix` (lint:fix) 0
err/warn · Jest **848** · PHPUnit unit **914** · integration **233** [24
pre-existing skips; the flagged `FamilyIntegrationTest` skip is pre-existing on
the CS-PLAN-053 baseline] · php-cs-fixer **0/272** · `l10n:check-keys` +
`docs:encoding` (664 files) clean · no styles touched (stylelint not required).

**Docs/plan:** `docs/api/README.md` auth notes re-verified against the code; the
Checklists section + `GET /room-tasks` scoping wording match the implemented
envelopes. No backlog changes — CR-053-01…04 remain closed; nothing new deferred.

Plan moved `active/ → done/` per the user's explicit instruction.
