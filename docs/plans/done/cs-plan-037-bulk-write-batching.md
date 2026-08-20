# CS-PLAN-037: Bulk Write-Path Batching — Set-Based Writes for the CR-SVC-60 Cluster

> **Status:** Done (2026-08-20)
> **Effort:** M
> **Priority:** P2
> **Depends on:** CS-PLAN-036 (done — read-path conversion + batch idioms)
> **Related:** CR-SVC-60 (closed), CR-SVC-11 (write remainder, closed)

## 1. Problem Summary

CS-PLAN-036 eliminated the read-path N+1 cluster but deliberately deferred the
write paths (`docs/backlog/backlog.md` → CR-SVC-60): bulk-UPDATE/DELETE/CASE-WHEN
conversions carry FK-semantics and error-isolation contracts that need their own
verification. That verification has now been done — with a decisive result:

**FK verification (the CR-SVC-60 precondition): the schema has NO foreign-key
constraints at all** — every cross-table reference (`completed_tasks.room_task_id`,
`task_assignees.room_task_id`, `room_task_checklist_progress.room_task_id`, …)
is a plain index (`lib/Migration/Version1004…1021`). There is no DB-level cascade.
Consequence: `RoomService::deleteRoom` (`lib/Service/RoomService.php:148–158`)
and `RoomTaskService::deleteRoomTask` (`:518–521`) **leave orphaned rows** in
`completed_tasks`, `task_assignees`, and `room_task_checklist_progress` today.
This plan therefore fixes a data-integrity defect, not only query counts.

| Hot spot | Current cost | Additional defect |
|---|---|---|
| `HouseService::resetHouse` (`lib/Service/HouseService.php:219–240`) | 3 + N_floors + N_rooms queries (floors loop → rooms loop → per-room `update`) | — |
| `RoomService::deleteRoom` (`:148–158`) | 3 + N_roomTasks (per-roomTask `delete` loop) | orphans in 3 dependent tables |
| `FloorMapper::deleteWithRooms` (`lib/Db/FloorMapper.php:126–137`) | 2 + N_rooms (per-room delete loop) | orphans (same 3 tables); **architecture violation**: `new RoomMapper($this->db)` cross-instantiation inside a mapper + misleading "cascade will handle this" comment (there are no FKs) |
| `FloorService::reorderFloors` (`lib/Service/FloorService.php:103–122`) | 1 + 2N (per-floor `updateSortOrder` + `find` re-fetch) | — |
| `RoomTaskService::bulkAssignTasks` (`:598–616`) | ≈ 7N (`assignTask` per item: 3-query ownership chain `:71–89` + membership fetch `:356–368` + 2× `find` + `assignToUser`) | — |

## 2. Strategy

Extend the three CS-PLAN-036 batch idioms to writes, plus the two in-repo
bulk-write precedents:

- **House-scoped subquery UPDATE/DELETE** — precedent: the CR-SVC-26
  house-scoped mappers (`TaskAssigneeMapper::deleteByUserForHouse` — QB
  subquery, `lib/Db/TaskAssigneeMapper.php:192`;
  `AvailabilitySettingsMapper::deleteForUser` — direct house_id delete,
  `:113`). Portable (`WHERE … IN (SELECT …)` works on
  MariaDB/SQLite/PostgreSQL; avoids the non-portable `UPDATE … JOIN`).
- **Bulk DELETE by id list** — precedent: `TaskAssigneeMapper::deleteByRoomTask`
  (`:166`), `RoomTaskChecklistProgressMapper::deleteByRoomTaskId` (`:192`),
  `TaskChecklistItemMapper::deleteByTaskId` (`:108`) — extend to plural-id
  variants with empty-input early returns.
- **CASE-WHEN bulk UPDATE** for `reorderFloors` (sort orders are validated
  ints → safely inlined via `createFunction`; no parameter placeholders needed
  inside the CASE list).

Per-item error isolation (`bulkAssignTasks`) and the per-item write in the
assign loop stay per-item on purpose — the API promises item-granular skip
behavior. **Decisions D1/D2 below change DB-state semantics (orphan cleanup),
not API contracts — they need explicit user sign-off before implementation.**

## 3. Backend Changes

All new mapper methods get PHPDoc, empty-input early returns, and named
parameters. No controllers, routes, or payloads change.

### Phase A — `resetHouse` bulk UPDATE

- **`RoomMapper::resetByHouseId(int $houseId): int`** (new): one UPDATE
  `chorestrike_rooms` SET `cleanliness = 100, monster_count = 0,
  last_cleaned = NULL` WHERE `floor_id IN (SELECT id FROM chorestrike_floors
  WHERE house_id = :house)` (subquery via `createFunction($subQb->getSQL())`,
  CR-SVC-26 pattern). Returns affected row count.
- `HouseService::resetHouse`: keep house find/update + warning log, replace the
  floors→rooms double loop with the single call (3 + N_f + N_r → 3 queries).

### Phase B — `deleteRoom` / `deleteWithRooms` explicit cascade

- **D1 (decision, recommend YES):** make room deletion orphan-free by cascading
  explicitly, in dependency order, each as one bulk statement over the room's
  room-task ids: `room_task_checklist_progress` → `task_assignees` →
  `completed_tasks` → `room_tasks` → `rooms`.
- **D2 (decision, recommend DELETE):** `completed_tasks` of deleted room tasks —
  delete with the cascade (recommended: the task no longer exists; medals are
  already-awarded snapshots and remain valid; streaks/coins on `house_users`
  are denormalized and unaffected) vs. keep as orphaned archive (status quo).
- New mapper methods: `RoomTaskMapper::findIdsByRoomId(int $roomId): int[]`,
  `RoomTaskMapper::deleteByRoomId(int $roomId): void`,
  `CompletedTaskMapper::deleteByRoomTaskIds(array $roomTaskIds): void`,
  `TaskAssigneeMapper::deleteByRoomTasks(array $roomTaskIds): void`,
  `RoomTaskChecklistProgressMapper::deleteByRoomTaskIds(array $roomTaskIds): void`.
- `RoomService::deleteRoom`: find room → cascade (constant ~5 queries).
- `RoomTaskService::deleteRoomTask`: same cascade for a single id (fixes the
  single-delete orphan defect too).
- **Architecture fix:** `FloorMapper::deleteWithRooms` loses the
  `new RoomMapper($this->db)` instantiation and the cascade moves to
  `FloorService::deleteFloor` (inject the same helpers; mapper only deletes
  floors). Delete the misleading "cascade will handle this" comment.

### Phase C — `reorderFloors` CASE-WHEN + no re-fetch

- **`FloorMapper::updateSortOrders(array $floorIdToOrder): void`** (new): one
  `UPDATE … SET sort_order = CASE id WHEN :i0 THEN :o0 … ELSE sort_order END
  WHERE id IN (…)`. Ids/orders are validated `int`s (cast + `sprintf('%d')`
  into the CASE list via `createFunction` — no user strings reach SQL).
  Empty-input early return.
- `FloorService::reorderFloors`: keep the house-membership validation
  (`:107–113`); build the return payload by setting `sort_order` on the
  already-fetched floor entities (drop the per-floor `find` re-fetch);
  1 + 2N → 2 queries.

### Phase D — `bulkAssignTasks` batch pre-validation

- **`RoomTaskMapper::findByIdsScopedToHouse(array $roomTaskIds, int $houseId): RoomTask[]`**
  (new): IN-clause fetch joined to `rooms → floors` filtered on
  `floors.house_id` — replaces the per-item 3-query ownership chain
  (`validateRoomTaskOwnership`). Items absent from the result are foreign or
  missing → skipped per-item (isolation contract preserved).
- `bulkAssignTasks`: one scoped fetch + one `assertHouseMembers` (unchanged) +
   per-item `assignToUser` writes inside the existing per-item try/catch +
   one `findByIds` re-fetch (successfully-assigned ids only) for the return
   payload. ≈7N → 3 + N.
- `assignTask` (single-item public API) keeps `validateRoomTaskOwnership` —
  only the bulk path is re-based (no BC concern; both stay correct).

## 4. Tests

**Unit — mapper query shape** (pattern per `tests/unit/Db/Mapper/TaskMapperTest.php`):
`resetByHouseId` (subquery SQL + named param), `deleteByRoomIds`/`deleteByRoomTaskIds`
variants (IN-parameter type + empty-input early return), `updateSortOrders`
(CASE-WHEN shape, int quoting), `findByIdsScopedToHouse` (house-scoped join).

**Unit — services:** `HouseServiceTest::testResetHouse*` (bulk call instead of
loops); `RoomServiceTest`/`FloorServiceTest` delete cascades (call order via
mock expectations); `RoomTaskServiceTest` bulk (mixed valid/invalid list →
isolation regression; foreign-house ids skipped); `reorderFloors` payload built
without re-fetch.

**Integration — real DB (Docker, extend `tests/integration/Db/`):**
- `resetByHouseId` two-house isolation: only the target house's rooms reset.
- **Orphan-free regression (the D1/D2 proof):** seed room + room tasks +
  completions + assignees + checklist progress → `deleteRoom` → all five
  tables empty for those ids, sibling house untouched.
- `reorderFloors`: persisted order survives re-fetch; foreign floor id →
  `InvalidArgumentException` unchanged.
- `bulkAssignTasks`: house-scoped skip proven on a real cross-house id.
- Existing behavior nets stay green unchanged: `HouseControllerIntegrationTest`,
  `ConcurrentCompletionTest`, `StreakFreezeIdempotencyTest`.

**E2E:** no UI/API change — `Release:*` gate must stay green (16/16).

## 5. File Manifest

```
modified: lib/Service/HouseService.php
modified: lib/Service/RoomService.php
modified: lib/Service/FloorService.php
modified: lib/Service/RoomTaskService.php
modified: lib/Db/RoomMapper.php
modified: lib/Db/RoomTaskMapper.php
modified: lib/Db/FloorMapper.php
modified: lib/Db/CompletedTaskMapper.php
modified: lib/Db/TaskAssigneeMapper.php
modified: lib/Db/RoomTaskChecklistProgressMapper.php
modified: tests/unit/Service/HouseServiceTest.php
modified: tests/unit/Service/RoomServiceTest.php
modified: tests/unit/Service/FloorServiceTest.php
modified: tests/unit/Service/RoomTaskServiceTest.php
modified: tests/unit/Db/Mapper/RoomMapperTest.php
modified: tests/unit/Db/Mapper/RoomTaskMapperTest.php
modified: tests/unit/Db/Mapper/FloorMapperTest.php
modified: tests/unit/Db/Mapper/CompletedTaskMapperTest.php
modified: tests/unit/Db/Mapper/TaskAssigneeMapperTest.php
modified: tests/unit/Db/Mapper/RoomTaskChecklistProgressMapperTest.php
modified: tests/integration/Db/BatchQueryRegressionTest.php  (write-path section or sibling class)
modified: docs/engineering/architecture.md                  (§ Batch Query Patterns → write idioms)
modified: docs/backlog/backlog.md                           (at completion: close CR-SVC-60)
modified: docs/backlog/ACTIVE_TASKS.md
```

## 6. Implementation Order

1. **D1/D2 sign-off** (user) — orphan-cascade semantics.
2. **Phase A** — `resetByHouseId` + `resetHouse` (+ tests).
3. **Phase B** — cascade mappers + `deleteRoom`/`deleteRoomTask`/
   `deleteFloor` restructure + `FloorMapper` architecture fix (+ orphan-free
   integration regression).
4. **Phase C** — `updateSortOrders` CASE-WHEN + payload without re-fetch.
5. **Phase D** — `findByIdsScopedToHouse` + `bulkAssignTasks` re-base.
6. **Docs/bookkeeping**: architecture.md write-idiom section; CR-SVC-60 →
   `archive/`; ACTIVE_TASKS update.

## 7. Risks & Mitigations

| Risk | Likelihood | Mitigation |
|------|-----------|------------|
| D2 decision wrong (history loss on delete) | Medium | Explicit user sign-off before Phase B; medals/streaks verified denormalized (not derived) |
| CASE-WHEN portability (MariaDB/SQLite/PostgreSQL) | Low | All three support it; ints only via `sprintf('%d')`; covered by mapper unit tests + real-DB integration |
| Subquery UPDATE unsupported via QB on some platform | Low | CR-SVC-26 precedent (`deleteByUserForHouse`) already runs in production tests; `IN (SELECT …)` is SQL-92 |
| Bulk delete hides per-item failures the loop surfaced | Medium | Deletes have no per-item API contract; wrap cascade in one transaction (ADR-007 style) so it is all-or-nothing |
| `bulkAssignTasks` isolation semantics drift | Medium | Mixed-list unit + real-DB integration tests pin skip-on-foreign/missing |
| reorderFloors payload drift (entity mutation instead of re-fetch) | Low | Assert returned entities equal a fresh `findByHouseId` in integration test |
| Write-path scope creep (new hot spots) | Medium | Strictly the four CR-SVC-60 paths; anything else → backlog |

## 8. Acceptance Criteria

- [x] D1/D2 decided by the user and implemented as decided (D1=YES cascade,
      D2=DELETE completions); orphan-free deletion proven by real-DB
      regression (`WriteBatchRegressionTest`: `deleteRoom` across all five
      tables with sibling-house survival, plus `deleteRoomTask` and
      `deleteFloor` variants).
- [x] All four hot spots converted; query-count reduction documented in
      §9.
- [x] Per-item error isolation in `bulkAssignTasks` preserved (unit:
      foreign/missing skip, write-failure isolation, empty input; real-DB:
      cross-house skip on a real id).
- [x] No API/payload/UI change; `release-*` E2E gate green unchanged (16/16).
- [x] `FloorMapper` architecture violation removed (no cross-mapper `new`;
      `deleteWithRooms` deleted, cascade in `FloorService`).
- [x] New mapper methods PHPDoc'd + unit-tested; integration suite green
      (175 tests, 24 pre-existing skips).
- [x] Build gate green (`npm test` 717 + lint + PHPUnit unit 785 +
      integration 175 + stylelint 0 errors + cs:check 0/260 + l10n clean).
- [x] Write-path batch idioms documented in `architecture.md`
      (§ Batch Query Patterns, idioms 4–6).
- [x] CR-SVC-60 closed and moved to `archive/2026-08.md`; ACTIVE_TASKS
      updated.

## 9. Implementation Outcome (2026-08-20)

**Decisions:** D1 = YES (explicit orphan-free cascade), D2 = DELETE
(completions go with their task) — signed off by the user up front.

**Query-count reductions (all verified against the shipped code):**

| Hot spot | Before | After |
|---|---|---|
| `HouseService::resetHouse` | 3 + N_floors + N_rooms | 3 (`RoomMapper::resetByHouseId` subquery UPDATE) |
| `RoomService::deleteRoom` | 3 + N_roomTasks + orphans | ~7 constant, orphan-free, transactional |
| `RoomTaskService::deleteRoomTask` | 2 + orphans | ~6 constant, orphan-free, transactional |
| `FloorService::deleteFloor` (was `FloorMapper::deleteWithRooms`) | 2 + N_rooms + orphans | ~8 constant, orphan-free, transactional |
| `FloorService::reorderFloors` | 1 + 2N | 2 (CASE-WHEN + entity-mutation payload) |
| `RoomTaskService::bulkAssignTasks` | ≈ 7N | 3 + N |

**Mapper additions:** `RoomMapper::resetByHouseId` / `deleteByFloorId`;
`RoomTaskMapper::findIdsByRoomId(s)` / `deleteByRoomId(s)` /
`findByIdsScopedToHouse`; `CompletedTaskMapper::deleteByRoomTaskIds`;
`TaskAssigneeMapper::deleteByRoomTasks`;
`RoomTaskChecklistProgressMapper::deleteByRoomTaskIds`;
`FloorMapper::updateSortOrders`. Removed (pre-release, no BC):
`FloorMapper::deleteWithRooms`, `FloorMapper::updateSortOrder`.

**Shared cascade:** `RoomService::deleteRoomTaskDependents(array $roomTaskIds)`
(checklist progress → assignees → completions) is consumed by `deleteRoom`,
`deleteRoomTask`, and `deleteFloor`; each caller wraps it in one transaction.
It lives in `RoomService` because `RoomTaskService` already depends on
`RoomService` — the dependency arrow can only point that way.

**Behavior deltas (pre-release, no BC):**
- `bulkAssignTasks` with a non-member target user now throws
  `InvalidArgumentException` up front instead of silently returning `[]` —
  the target user is a request-level parameter, not a per-item concern.
- Deleting a room/floor/room task now removes its completion history (D2).
  Medals/streaks/coins on `house_users` are denormalized snapshots and stay
  valid.
- Skipped foreign/missing bulk-assign items log `reason:
  'not_found_in_house'` instead of an exception class (no exception is
  thrown on the batch path anymore).

**Root-cause fix beyond the manifest (surfaced by the new real-DB
regression suite):** the checklist mappers referenced tables that never
existed — `RoomTaskChecklistProgressMapper` used
`chorestrike_room_task_checklist_progress` and `TaskChecklistItemMapper`
used `chorestrike_checklist_items`, while Version1005 creates
`chorestrike_chk_progress` / `chorestrike_chk_items` (nothing renames them;
`TestDataService::RESET_TABLES` and the CR-SVC-42 unit test carried the same
inverted names). Every `ChecklistController` call would have 500'd on a real
DB. All sites fixed to the migration-truth names.

**Tests:** +24 unit (mapper query shape incl. CASE-WHEN poisoned-key proof,
subquery SQL + named params, IN-array deletes with empty-input early
returns; service cascade/rollback; bulk-assign isolation matrix), +6
real-DB integration (`tests/integration/Db/WriteBatchRegressionTest.php`).

**Gate:** PHPUnit unit 785 / integration 175 [24 pre-existing skips] /
Jest 71 suites · 717 tests / ESLint clean / stylelint 0 errors (27
TECH-LINT-004 warnings) / cs:check 0/260 / l10n synchronized / E2E
`release-*` 16/16 (chromium-serial-db).

## Review Outcome (Standard B — 2026-08-20)

Reviewed the full uncommitted implementation against the shared checklist
(architecture, authorization, security, KISS, workarounds, logging, i18n,
tests). **No findings — no auto-fixes, no backlog items.**

- **Architecture:** `FloorMapper::deleteWithRooms` (cross-mapper `new
  RoomMapper`) removed; the cascade lives in `FloorService::deleteFloor`
  and the shared `RoomService::deleteRoomTaskDependents`. Dependency arrow
  verified acyclic (`FloorService → RoomService → HouseService`, no cycle).
- **Authorization:** all four mutations still gated at the controller
  boundary (`ensureHouseMember` + ownership validation); `@NoAdminRequired`
  only. `bulkAssignTasks` target-membership is validated up front; foreign/
  missing ids are skipped via the house-scoped fetch (no cross-house write).
- **Security:** CASE-WHEN inlines validated ints via `sprintf('%d')`
  (poisoned-key unit proof); subquery UPDATE binds the house param on the
  outer builder (CR-SVC-26 precedent); IN lists use `PARAM_INT_ARRAY`. No
  SQL concatenation, no `v-html`, no secrets logged.
- **Logging/i18n:** `AppLogger` with stable keys, no raw `userId` in the
  bulk-skip log; no new user-visible strings (`l10n:check-keys` clean).
- **Root-cause fix verified:** checklist mapper/`TestDataService` table
  names now match Version1005 (`chorestrike_chk_progress`/
  `chorestrike_chk_items`); no stale references remain in `lib/`.
- **Gate re-run green:** `npm run lint:fix` clean · Jest 717 · PHPUnit unit
  785 · integration 175 [24 pre-existing skips] · `cs:check` 0/260 ·
  `l10n:check-keys` synchronized. (stylelint not re-run — no styles touched.)

Scope note (no action): `bulkAssignTasks` has no controller/route caller yet
(the frontend store still loops `assignRoomTask` per item), so Phase D is
currently exercised by unit + real-DB tests only — consistent with the plan's
"no API change" scope; the endpoint wiring is future work.
