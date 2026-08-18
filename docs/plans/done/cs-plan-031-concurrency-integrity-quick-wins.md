# CS-PLAN-031: Concurrency & Integrity Quick Wins

> **Status:** Done 2026-08-18 (Standard-B review passed — see `## Review Outcome`)
> **Effort:** M
> **Priority:** P2
> **Depends on:** none
> **Related:** ADR-007 (concurrency-safe task completion), CS-PLAN-028 (CR-SVC-53 lock fix), CS-PLAN-029/030 precedents

## 1. Problem Summary

Six open backlog items share one theme — **data integrity under concurrency and on
member removal** — and are all small enough to land as one coherent bundle
(the CS-028/030 "quick wins" pattern):

| Item | Defect | Where |
|---|---|---|
| **CR-SVC-54** (P3) | `MinigameService::saveScore` inserts the score row and increments coins **without a transaction or row lock** — the same lost-update class CR-SVC-04/53 fixed for task completion. Two concurrent same-user submissions clobber each other's coin increment; a partial failure between `insert()` and `update()` leaves a score row with no coins. | `lib/Service/MinigameService.php:63-94` |
| **CR-SVC-23** (P3) | `MinigameService::purchaseBoost` is a lock-less read-check-deduct: two concurrent purchases with coins for one both pass the check → **two boosts bought, paid once**. | `lib/Service/MinigameService.php:197-225` |
| **CR-SVC-51** (P3) | `MinigameController::saveScore` recomputes `coinsEarned = floor($score/10)` from raw client input for the response — diverging from the service when the house_user row is missing (service awards 0, response claims >0). | `lib/Controller/MinigameController.php:120-131` |
| **CR-SVC-26** (P3) | `UserController::remove` deletes the `house_users` row **in the controller** but leaves `room_tasks.assigned_to_user_id` set and `task_assignees` rows alive → orphaned references to a former member (architecture violation + the root cause of the defensive membership re-check at `RoomService.php:433-446`). | `lib/Controller/UserController.php:204-224` |
| **CR-SVC-17** (P3) | `TaskService::updateTask` enforces no task-visibility matrix (asymmetric with `deleteTask`): `ownerUserId` is whitelisted, so a **private task can be silently re-assigned away from its owner** by any caller. | `lib/Service/TaskService.php:166-188` |
| **CR-SVC-21** (P3) | `TaskSyncService` exposes unscoped public mutators (`completeTask`, `linkTask`, `markAsSynced`, `getPendingSyncs`) beside the house-scoped `*ForHouse` wrappers. Production callers (TaskSyncController) already use only the `*ForHouse` variants — the unscoped ones are an authorization-bypass hazard kept alive by tests. | `lib/Service/TaskSyncService.php` |

Plus two XS housekeeping items that ride along:

- **CR-SVC-58** — `phpunit.unit.xml` Entity/Mapper suites double-run 5 mapper tests
  (file-list drift); split by subdirectory so it cannot drift again.
- **TECH-CLEANUP-ITIMEFACTORY** — the lib is already normalized to
  `OCP\AppFramework\Utility\ITimeFactory`; only the redundant
  `tests/__mocks__/OCP/ITimeFactory.php` mock remains → delete it and close the item.

**Out of scope** (stay in `backlog.md`): CR-SVC-55/56 (minigame N+1s — separate
perf cluster), CR-SVC-37 (service i18n literals), CR-SVC-50 (lock-section round
trips), CR-SVC-40 (transaction-in-mapper relocation — see Strategy note below).

## 2. Strategy

Follow the **established ADR-007 / CR-SVC-53 pattern** (service-level
`beginTransaction()` → `HouseUserMapper::findForUpdate()` → mutate → `commit()`,
with the SQLite self-assign fallback inside the mapper) for both minigame
mutators. Service-level transactions diverge from CR-SVC-40's long-term wish
(mapper/repository-owned transactions) but match what the codebase does today —
CR-SVC-40 will unify the location for *all* services later; do not introduce a
second pattern here.

For member removal, move the whole operation into `HouseService` as a
transactional aggregate operation (house membership is a house concern): delete
`house_users` + null `room_tasks.assigned_to_user_id` + delete `task_assignees`
rows for the removed user, scoped to that house. The controller shrinks to
auth-gate + delegate + response shaping.

For `updateTask`, mirror `deleteTask`'s visibility matrix with the same
non-nullable authorization-context parameters, and update the single production
call site (`TaskController.php:433`).

For `TaskSyncService`, make the unscoped mutators private; rewrite the unit
tests that called them directly to go through the `*ForHouse` wrappers.

## 3. Backend / Frontend Changes

### Backend (`lib/`)

**D1 — CR-SVC-54: transactional `saveScore`** (`lib/Service/MinigameService.php`)
- Inject `IDBConnection` (Nextcloud auto-wiring).
- Wrap insert + coin award in `beginTransaction()`/`commit()` with `rollBack()` in
  a `catch (\Throwable)` re-throw, mirroring `RoomService::completeRoomTask`.
- Resolve the `HouseUser` id via `findByHouseIdAndNextcloudUserId` (outside the
  lock is fine — locking by primary key), then `findForUpdate($id)` inside the
  transaction and `addCoins()` + `touch()` on the locked entity before `update()`.
- Missing house_user row keeps today's behavior: score saved, 0 coins awarded
  (now *reported* correctly too, via D3).

**D2 — CR-SVC-23: transactional `purchaseBoost`** (`lib/Service/MinigameService.php`)
- Same lock pattern: `beginTransaction()` → `findForUpdate()` → sufficiency check
  **on the locked row** → `setCoins($coins - $cost)` (drop the `max(0, …)` mask;
  the locked check is authoritative and the mask hides bugs) → `update()` → `commit()`.

**D3 — CR-SVC-51: single source of truth for awarded coins**
- `MinigameService::saveScore` returns `array{score: MinigameScore, coinsEarned: int}`
  instead of the bare entity.
- `MinigameController::saveScore` uses the returned `coinsEarned` — delete the
  controller-side `floor($score/10)` recomputation. Response shape stays unchanged.

**D4 — CR-SVC-26: transactional member removal**
- New `HouseService::removeHouseMember(int $houseUserId): void`:
  `requireHouseOwner` context stays at the controller; the service throws
  `ForbiddenException` when the target `isOwner()` (keeps the API's
  `CANNOT_REMOVE_OWNER` 403 mappable), then in one transaction:
  1. `RoomTaskMapper::clearAssignedUserForHouse($ncUserId, $houseId)` (new:
     `UPDATE room_tasks SET assigned_to_user_id = NULL WHERE assigned_to_user_id = :user
     AND room_id IN (house-scoped subquery rooms→floors)`, built with the QB
     subquery pattern — no raw SQL),
  2. `TaskAssigneeMapper::deleteByUserForHouse($ncUserId, $houseId)` (new,
     same scoping),
  3. delete the `house_users` row.
- `UserController::remove` delegates; the `houseUserMapper->delete` and owner
  check move out of the controller (thin-controller rule).
- Update the stale comment at `RoomService.php:429-432` (root cause fixed; the
  re-check stays as documented defense-in-depth). **No behavior change** there.

**D5 — CR-SVC-17: `updateTask` visibility matrix** (`lib/Service/TaskService.php`)
- Signature: `updateTask(int $taskId, array $updates, string $callerUserId, bool $isOwner = false): Task`
  (auth context non-nullable, mirroring `deleteTask`).
- Enforce the same matrix as `deleteTask`: `private` → only `ownerUserId` may
  update; `admin_only` → only house owners; `public` → any caller.
- Update `TaskController::update` (`:433`) to pass `getCurrentUserId()` + owner flag.

**D6 — CR-SVC-21: privatize unscoped `TaskSyncService` mutators**
- `completeTask`, `linkTask`, `markAsSynced`, `getPendingSyncs` → `private`
  (the `*ForHouse` wrappers are the only public surface). Leave reads
  (`getRoomTask`, `mapRemindersToNextcloud`) untouched — not in the item's scope.

**D7 — CR-SVC-58: PHPUnit suite split**
- `git mv` the 25 `tests/unit/Db/*.php` files into `tests/unit/Db/Entity/`
  (pure entity tests) and `tests/unit/Db/Mapper/` (the 14 mock-based mapper tests
  incl. `TaskMapperVisibilityTest.php`).
- `phpunit.unit.xml`: Entity suite globs `tests/unit/Db/Entity`, Mapper suite
  globs `tests/unit/Db/Mapper`; **delete** the `<exclude>` list and the per-file
  `<file>` entries so the split cannot silently drift again.

**D8 — TECH-CLEANUP-ITIMEFACTORY**
- Delete `tests/__mocks__/OCP/ITimeFactory.php` (redundant; lib + tests already
  use `OCP\AppFramework\Utility\ITimeFactory`). Close the backlog item.

### Frontend (`src/`)

None. All endpoints keep their request/response contracts (`coinsEarned` in the
saveScore response is now service-sourced but identical for the normal path).

### Cross-layer contracts

- `minigame#save_score` response: unchanged shape.
- `user#remove` response: unchanged (incl. `CANNOT_REMOVE_OWNER` 403 code).
- `task#update`: unchanged wire contract; new 403s mirror `task#delete` semantics.

## 4. Tests

### Unit (PHPUnit)
- `MinigameServiceTest`: saveScore/purchaseBoost open a transaction and use
  `findForUpdate` (mocked IDBConnection asserts begin/commit/rollback);
  `saveScore` returns `coinsEarned` (incl. 0 for missing house_user);
  `purchaseBoost` rejects on the *locked* row's balance; rollback re-throws.
- `MinigameControllerTest`: response `coinsEarned` comes from the service (no
  client-input recomputation — assert divergence case score=X, service returns 0).
- `HouseServiceTest`: `removeHouseMember` calls both cleanup mappers + delete in
  order; owner target throws `ForbiddenException`.
- `UserControllerTest`: `remove` delegates to the service; `CANNOT_REMOVE_OWNER`
  mapping intact.
- `TaskServiceTest`/`TaskServiceWhitelistTest`: visibility matrix for `updateTask`
  (private×non-owner, private×owner OK, admin_only×member, public×any) + existing
  whitelist behavior unchanged with the new params.
- `TaskSyncServiceTest`: rewrite the direct-mutator tests (lines ~92/134/319/477/506)
  to call the `*ForHouse` wrappers (mock the room→floor→house chain).
- Suite split (D7) is a move — no test-content changes.

### Integration (PHPUnit, Docker — real DB, regression-grade)
- New `MinigameConcurrencyTest` (two-connection, modeled on CS-028's
  `ConcurrentCompletionTest`): **must fail on the pre-fix code** —
  (a) two interleaved `saveScore` calls both credit their coins;
  (b) two interleaved `purchaseBoost` calls with a single-boost balance → exactly
  one succeeds.
- New `HouseMemberRemovalIntegrationTest`: seed house + member + assigned
  room_tasks + task_assignees → `removeHouseMember` → assert
  `assigned_to_user_id IS NULL` and no `task_assignees` rows remain for the user
  (house-scoped: another house's assignments untouched).

### E2E (Playwright)
- No new specs (backend-only, contracts unchanged). Gate: `Release:*` suite stays
  green (covers `release-boost-shop` = purchase flow).

## 5. File Manifest

```
modified:  lib/Service/MinigameService.php
modified:  lib/Controller/MinigameController.php
modified:  lib/Service/HouseService.php
modified:  lib/Controller/UserController.php
modified:  lib/Service/RoomService.php          (comment only, D4)
modified:  lib/Service/TaskService.php
modified:  lib/Controller/TaskController.php
modified:  lib/Service/TaskSyncService.php
modified:  lib/Db/RoomTaskMapper.php            (clearAssignedUserForHouse)
modified:  lib/Db/TaskAssigneeMapper.php        (deleteByUserForHouse)
modified:  phpunit.unit.xml
moved:     tests/unit/Db/*.php → tests/unit/Db/{Entity,Mapper}/*.php
deleted:   tests/__mocks__/OCP/ITimeFactory.php
modified:  tests/unit/Service/MinigameServiceTest.php
modified:  tests/unit/Controller/MinigameControllerTest.php
modified:  tests/unit/Service/HouseServiceTest.php
modified:  tests/unit/Controller/UserControllerTest.php
modified:  tests/unit/Service/TaskServiceTest.php
modified:  tests/unit/Service/TaskServiceWhitelistTest.php
modified:  tests/unit/Service/TaskSyncServiceTest.php
new:       tests/integration/Service/MinigameConcurrencyTest.php   (path per existing layout)
new:       tests/integration/Service/HouseMemberRemovalIntegrationTest.php
modified:  docs/backlog/backlog.md               (close CR-SVC-54/23/51/26/17/21, CR-SVC-58, TECH-CLEANUP-ITIMEFACTORY)
modified:  docs/backlog/ACTIVE_TASKS.md
modified:  docs/adr/ADR-007-concurrency-safe-task-completion.md   (extend: coin/boost mutations follow the same lock)
```

## 6. Implementation Order

1. **D7 suite split** — mechanical `git mv` + `phpunit.unit.xml` rewrite first, so
   later test-count changes are attributable.
2. **D1+D2+D3 minigame cluster** — service locks + service-sourced `coinsEarned`
   in one step (unit tests, then the two-connection integration proof).
3. **D4 member removal** — mapper methods, `HouseService::removeHouseMember`,
   controller slim-down, integration test, comment update in `RoomService`.
4. **D5 `updateTask` matrix** — signature + matrix + controller call site + tests.
5. **D6 TaskSyncService privatization** — visibility change + test rewrite.
6. **D8 mock deletion** — verify no references, delete, close item.
7. **Gate + docs** — full build gate, ADR-007 note, backlog/ACTIVE_TASKS updates,
   propose review.

## 7. Risks & Mitigations

| Risk | Likelihood | Mitigation |
|------|-----------|------------|
| QB `UPDATE … WHERE id IN (subquery)` portability (SQLite/MySQL/PG) | Medium | Use the documented Nextcloud QB subquery pattern (no raw SQL); integration suite must run green on all DB profiles. |
| Two-connection proof behaves differently per engine (SQLite single-writer serializes anyway) | Medium | Model exactly on CS-028's `ConcurrentCompletionTest` engine-aware structure; assert end-state totals, not lock timing. |
| Making TaskSyncService mutators private breaks direct unit tests | Certain | Expected rework — tests go through `*ForHouse` (mock room→floor→house chain), which also restores the coverage intent. |
| `updateTask` signature change misses a caller | Low | Only one production call site (`TaskController.php:433`, verified); unit test compile failures surface the rest. |
| Member-removal subqueries miss rows on deep chains (room→floor→house) | Low | Integration test seeds all three states (assigned task, assignee row, other-house control) and asserts counts. |
| Response-contract drift for saveScore/remove endpoints | Low | Contracts asserted unchanged in controller unit tests; `Release:*` E2E as the outer net. |
| `setCoins($coins - $cost)` without `max(0,…)` regresses on race | Low | The locked-row check guarantees sufficiency; the two-connection integration test is the regression proof. |

## 8. Acceptance Criteria

- [x] CR-SVC-54: `saveScore` runs insert + coin award in one transaction behind
      `findForUpdate`; two-connection integration test proves no lost update and
      fails on the old code. *(verified 2026-08-18: pre-fix simulation →
      "Failed asserting that 12 is identical to 22")*
- [x] CR-SVC-23: `purchaseBoost` locks before check-deduct; two-connection test
      proves single-boost purchase under concurrency. *(verified: pre-fix
      simulation → second purchase not rejected)*
- [x] CR-SVC-51: `coinsEarned` in the saveScore response originates from the
      service; the controller no longer recomputes it.
- [x] CR-SVC-26: member removal nulls `assigned_to_user_id` and deletes
      `task_assignees` rows house-scoped, transactionally, from a service method;
      `UserController::remove` contains no mapper deletes.
- [x] CR-SVC-17: `updateTask` enforces the same visibility matrix as `deleteTask`
      with non-nullable auth context; `TaskController::update` passes it.
- [x] CR-SVC-21: `completeTask`/`linkTask`/`markAsSynced`/`getPendingSyncs` are
      private; only `*ForHouse` wrappers are public.
- [x] CR-SVC-58: suites split by subdirectory; no `<exclude>`/`<file>` drift
      possible; no mapper test runs twice. *(Entity 111 / Mapper 72 — disjoint)*
- [x] TECH-CLEANUP-ITIMEFACTORY: redundant mock deleted; item closed.
- [x] Build gate green: `npm test` + `npm run lint` + stylelint/cs:check +
      PHPUnit unit + integration (Docker) + E2E `Release:*`.
      *(unit 734 / integration 160 [24 pre-existing skips] / Jest 701 /
      ESLint clean / stylelint 0 errors [26 TECH-LINT-004 warnings] /
      cs:check 0 of 257 / Release:* 16/16)*
- [x] Durable decisions integrated: ADR-007 extended (coin/boost mutations use
      the same lock pattern); backlog items closed to `archive/2026-08.md`.
- [x] `docs/backlog/ACTIVE_TASKS.md` updated.

### Implementation notes (2026-08-18)

- The suite split also realigned the moved tests' namespaces to
  `OCA\ChoreStrike\Tests\Unit\Db\{Entity,Mapper}` (PSR-4; composer maps
  `OCA\ChoreStrike\Tests\` → `tests/`) — one mechanical namespace-line change
  per moved file, no test-logic changes.
- `UserController::remove` additionally maps a missing target to 404
  `USER_NOT_FOUND` (previously a 500 through the generic catch), matching the
  `update()`/`titles()` endpoints' existing contract.
- `TaskController::update` maps a service-level visibility denial to 404
  (anti-enumeration), mirroring `delete()`.
- Pre-existing, unrelated: 2 failures in `tests/e2e/3-floor-house.spec.ts`
  reproduce identically on the unmodified tree (stash-verified) → logged as
  **BUG-E2E-001** in `backlog.md`. The full `chromium-serial-db` pool is not
  part of this plan's gate (CS-030's precedent gate is `Release:*` 16/16,
  which passes).

## Review Outcome (Standard-B, 2026-08-18)

Scope: the plan's full uncommitted change set (lib/ + tests/ + docs). Tier:
Standard-B (code review, no subagents; backend-only, single thematic bundle).

**Findings:** no block/major/minor findings on the implementation. Report-only
nits: (1) new `TaskController::update` 404 reuses the hardcoded (non-`t()`)
`'Task not found'` message — copied from the pre-existing `show()`/`delete()`
pattern, consistent, tracked under the CR-SVC-37 i18n debt cluster; (2)
`SET assigned_to_user_id = NULL` binds via `createNamedParameter(null)` with
the default string type — proven correct against the real MariaDB test DB by
`HouseMemberRemovalIntegrationTest`, mirrors NC mapper null-binding behavior.

**Auto-fixes:** none needed on code. One docs follow-up applied: the stale
enforcement-site list in `docs/engineering/architecture.md` (visibility
matrix) now includes `TaskService::deleteTask()`/`updateTask()` matrices +
`TaskController::delete()`/`update()` gates (CS-PLAN-031 CR-SVC-17).

**Gate re-verified green (Docker, family profile):** PHPUnit unit 734 /
integration 160 (24 pre-existing skips, incl. `MinigameConcurrencyTest` +
`HouseMemberRemovalIntegrationTest`) / Jest 701 / `npm run lint:fix` clean /
`cs:check` 0 of 257 / `l10n:check-keys` clean. E2E `Release:*` 16/16 taken
from the plan's gate (backend-only change set, contracts unchanged; not
re-run in review).

**Deferred / follow-ups:** none new — BUG-E2E-001 (pre-existing E2E failures)
and CR-SVC-55/56/50/40 remain open per plan scope.

**Commit status:** not committed — awaiting approval (conventional commit,
`fix:` or `refactor:` scope).
