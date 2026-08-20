# CS-PLAN-036: Batch Query Optimization — N+1 Elimination Across Services

> **Status:** Implemented (2026-08-20) — Standard-B review passed (2026-08-20)
> **Effort:** L
> **Priority:** P2
> **Depends on:** none
> **Related:** CR-SVC-11 (read-path cluster), CR-SVC-50, CR-SVC-55, CR-SVC-56

## 1. Problem Summary

The 2026-08-04 full review of `lib/Service` (CR-SVC-11) found pervasive per-row
mapper calls in loops. A detailed survey (2026-08-19) confirmed the concrete
shapes:

| Hot spot | Current cost |
|---|---|
| `HouseService::calculateHouseHealth` / `getHouseStats` | `RoomMapper::findByFloorId` per floor (1 + N_floors queries) |
| `GameLogicService::handleTaskCompletion` | **inside the `house_user` lock**: redundant `roomTaskMapper->find` (entity already held by caller) + 2 `findRecentByUserId` window queries (CR-SVC-50) |
| `GameLogicService::checkAndAwardMedals` | 2 aggregate queries per user (stats + max-room-count), unconditionally |
| `GameLogicService::getMedals` | `medalMapper->findByUserId` per user |
| `FloorService::getFloorsWithRooms` | delegates to `getFloorWithRooms` → 2 queries per floor |
| `RoomService::getRoomWithTasks` | `taskMapper->find` per roomTask |
| `RoomTaskService::getDailyTasksWithDetails` / `getRangeTasks` | `taskMapper->find` + `roomMapper->find` per distinct id |
| `MinigameService::getLeaderboard` / `getWeeklyLeaderboard` | `findByHouseIdAndNextcloudUserId` per score row (CR-SVC-56) |
| `TaskSyncService::getPendingSyncsForHouse` | 3-query ownership chain per row over up to 250 fetched rows |
| `StreakFreezeService::process` | `countByUserOnDate` per user; sibling `StreakAtRiskService` already uses the grouped batch (CR-SVC-55) |

The codebase already contains the three canonical batch patterns to fix these
(IN-clause id→row map, grouped aggregate, house-scoped JOIN) — they are simply
not applied consistently.

**Out of scope (write paths):** `HouseService::resetHouse`,
`RoomService::deleteRoom`, `FloorService::reorderFloors`, and the write half of
`RoomTaskService::bulkAssignTasks`. These are bulk-UPDATE/DELETE/CASE-WHEN
conversions with FK-semantics and error-isolation contracts to verify first;
they are split into a new backlog item (see §8 / §6 step 7).

## 2. Strategy

Convert service **read paths** from per-row mapper loops to set-based lookups,
exclusively by pattern-matching the three existing in-repo batch idioms:

1. **IN-clause id→row map** — pattern: `TaskAssigneeMapper::findUserIdsByRoomTasks`
   (`lib/Db/TaskAssigneeMapper.php:69–88`): `array_fill_keys` default, empty-input
   early return, `expr()->in(..., createNamedParameter($ids, PARAM_*_ARRAY))`.
2. **Grouped aggregate (single GROUP BY → PHP map)** — pattern:
   `CompletedTaskMapper::countByUserOnDateGrouped` (`lib/Db/CompletedTaskMapper.php:279–298`),
   consumed by `StreakAtRiskService.php:58,81`.
3. **House-scoped JOIN** — pattern: `CompletedTaskMapper::findCompletionsBetweenByHouse`
   (`lib/Db/CompletedTaskMapper.php:389–417`) and `RoomMapper::findByHouseId`
   (`lib/Db/RoomMapper.php:61–70`).

Per-row logic that is **inherently** per-row stays per-row and is documented as
such (medal existence checks/inserts with unique-constraint race handling,
per-award notifications, per-item error isolation). CR-SVC-50 is fixed by
threading the already-locked `RoomTask` entity into
`GameLogicService::handleTaskCompletion` and merging the two completion-window
queries. **No API contract, payload, or user-visible behavior changes** — this
is a behavior-neutral refactor; the frontend is untouched.

## 3. Backend Changes

All new mapper methods get PHPDoc and follow the `QBMapper` conventions of
their class. No DI wiring changes needed (services already receive mappers via
constructor injection).

### Phase A — drop-in swaps with existing batch methods

- **`HouseService::calculateHouseHealth`** (`lib/Service/HouseService.php:146–168`)
  and **`getHouseStats`** (`:175–212`): replace the floors→`findByFloorId` loop
  with one `RoomMapper::findByHouseId($houseId)` call. `calculateHouseHealth`
  no longer needs the floors fetch at all (1 + N_floors → 1 query);
  `getHouseStats` keeps `floors` in its payload (1 + N_floors → 2 queries).
- **`StreakFreezeService::process`** (`lib/Service/StreakFreezeService.php:39–114`):
  replace the per-user `countByUserOnDate` (L80–83) with one
  `countByUserOnDateGrouped($yesterday)` + `$map[$userId] ?? 0`, mirroring
  `StreakAtRiskService.php:58,81` verbatim. *(closes CR-SVC-55)*

### Phase B — CR-SVC-50: shrink the `house_user` lock section

- **`GameLogicService::handleTaskCompletion`** (`lib/Service/GameLogicService.php:78–190`):
  - Change the signature to accept the locked `RoomTask` **entity** (held by
    `RoomService::completeRoomTask` since `RoomService.php:294`,
    `lockForUpdate`) instead of re-fetching via `roomTaskMapper->find($roomTaskId)`
    (L84). Verify `RoomService` is the only caller before changing; delete dead
    code, no BC shim (pre-release).
  - Merge the two window queries — `findRecentByUserId($userId, 5)` (L87) and
    `findRecentByUserId($userId, 10)` (L128) — into **one 10-minute fetch,
    taken before the `completedTaskMapper->insert` (at the L87 position)**;
    derive `$isCombo` in PHP by filtering on `completed_at >= now − 5 min`.
    ⚠️ The numeric argument is a **minute window, not a row limit** — a naive
    "limit-10 slice" is wrong; filter on the timestamp.
    ⚠️ Off-by-one: today the 10-min query (L128) runs **post-insert** and thus
    includes the in-flight completion, while the 5-min query (L87) runs
    pre-insert and does not. With one pre-insert fetch, `$comboCount` must be
    `count($recent) + 1` so the freeze-threshold comparison
    (`comboCount >= threshold`) stays behavior-neutral.
  - Net effect inside the critical section: −1 `find`, 2 window queries → 1.

### Phase C — new batch mapper methods + consumers

- **`HouseUserMapper::findByHouseIdAndNextcloudUserIds(string $houseId, array $ncUserIds): array`**
  (new, IN-clause `PARAM_STR_ARRAY`, returns map `nextcloud_user_id → HouseUser`,
  absent users simply missing). Consumers:
  `MinigameService::getLeaderboard` (`lib/Service/MinigameService.php:135–153`)
  and `getWeeklyLeaderboard` (`:163–182`) — build the map once, keep the
  `displayName ?? userId` fallback (L145). Chosen over a JOIN because the score
  queries also feed `getUserRank`/`getPersonalBest` and the fallback is cleaner
  in PHP. *(closes CR-SVC-56)*
- **`TaskMapper::findByIds(array $ids): array`** and **`RoomMapper::findByIds(array $ids): array`**
  (new, IN-clause `PARAM_INT_ARRAY`, map `id → entity`, **missing ids tolerated**).
  Consumers:
  - `RoomService::getRoomWithTasks` (`lib/Service/RoomService.php:176–200`):
    replace per-taskId `find` + try/catch with one map lookup; missing task ⇒
    absent key ⇒ skip (current skip-on-missing semantics preserved exactly).
  - `RoomTaskService::getDailyTasksWithDetails` (`:700–725`) and
    `getRangeTasks` (`:748–812`): replace the per-distinct-id loops (L709/714,
    L761–768) with two batch calls (1 + N_tasks + N_rooms → 3 queries).
- **`MedalMapper::findByUserIds(array $userIds): array`** (new, IN-clause, map
  `user_id → Medal[]`). Consumer: `GameLogicService::getMedals`
  (`:494–514`) — one fetch + PHP grouping (N_users → 1).
- **`CompletedTaskMapper::findPendingSyncsForHouse(string $houseId, int $limit): array`**
  (new, JOIN `completed_tasks → room_tasks → rooms → floors` on
  `floors.house_id`, `completed_at ASC`, `LIMIT n` — pattern:
  `findCompletionsBetweenByHouse`). Consumer:
  `TaskSyncService::getPendingSyncsForHouse` (`lib/Service/TaskSyncService.php:280–295`)
  — removes the per-row `roomTaskBelongsToHouse` chain (3 queries × up to 250
  rows, `:387–393`) and the `limit*5` overfetch (1 + 3N → 1). If
  `roomTaskBelongsToHouse` has no remaining callers, delete it (pre-release).

### Phase D — grouped stats + floor listing

- **`CompletedTaskMapper::getUserStatsByHouse(...)`** and
  **`getMaxRoomCompletionCountByHouse(...)`** (new grouped siblings of
  `getUserStats` / `getMaxRoomCompletionCountByUser`, `GROUP BY user_id` over
  house users). Consumer: `GameLogicService::checkAndAwardMedals`
  (`:292–353`) — fetch both grouped maps once (2N → 2 queries), look up per
  user. **Medal existence checks, inserts, and notifications stay per-row**
  (unique-constraint race handling at `GameLogicService.php:382–394` +
  per-award notify is inherently per-row — documented, not batched).
- **`FloorService::getFloorsWithRooms`** (`lib/Service/FloorService.php:190–199`):
  fetch all rooms once via `RoomMapper::findByHouseId`, group by `floor_id` in
  PHP; restructure so the plural method no longer delegates 2-queries-per-floor
  through `getFloorWithRooms` (`:139–148`) while the single-floor endpoint
  keeps its behavior. (2×N_floors → 2 queries.)

## 4. Tests

**Unit — new mapper methods** (mock `IDBConnection`/`IQueryBuilder` pattern per
`tests/unit/Db/Mapper/TaskAssigneeMapperTest.php:24–33`): one test class update
each for `HouseUserMapperTest`, `TaskMapperTest`, `RoomMapperTest`,
`MedalMapperTest`, `CompletedTaskMapperTest` — assert query shape, IN-parameter
type, map keys, and the **empty-array early return**.

**Unit — service test updates** (mocked mappers return `null` on un-stubbed
new calls → must be re-stubbed in the same change):
- `HouseServiceTest` (`:156–255`) — `findByHouseId` instead of floor loop.
- `StreakFreezeServiceTest` — grouped-count stubs (shape per
  `StreakAtRiskServiceTest.php:62–203`); **rework**
  `testProcess_PerUserErrorIsolation_ContinuesAfterFailure` (`:236–263`) — the
  count fetch moves out of the per-user loop, isolation now concerns only the
  freeze write.
- `GameLogicServiceTest` — new signature at ~10 call sites (`:119,164,196,859,
  894,930,975,1087,1129,1187`); one `findRecentByUserId` stub (10-min window);
  **boundary tests** for the 5/10-minute combo window (completions at −4:59,
  −5:01, −9:59, −10:01) plus a combo-bonus-freeze case seeding
  `threshold − 1` prior completions (proves the in-flight completion still
  counts toward `$comboCount`).
- `RoomServiceTest` (`:288+`) — `findByIds` map stub incl. skip-on-missing case.
- `RoomTaskServiceDailyTest` (`:117,160,202`) — batch stubs; the per-id caching
  test (`:202`) is adapted/renamed (batch fetch supersedes the per-id cache).
- `MinigameServiceTest` (`:224–262`) — `exactly(2)` singular lookups → one batch
  call; **add the missing `getWeeklyLeaderboard` test** (fallback + enrichment).
- `TaskSyncServiceTest` (`:387–466`) — rewrite the `exactly(3)`-per-mapper chain
  assertions to the house-scoped query.
- `FloorServiceTest` (`:313–349`) — batch shape; `reorderFloors` tests (`:217,241`)
  untouched.

**Integration — real DB (Docker, `phpunit.integration.xml`)**, pattern
`tests/integration/Db/RoomTaskMapperDailyTest.php` (currently the only real-DB
mapper test). New `tests/integration/Db/BatchQueryRegressionTest.php`:
- `findByIds` with a missing id → map without the key (skip-on-missing proof).
- `findByHouseIdAndNextcloudUserIds` — multi-member house, absent user, and
  **cross-house isolation** (same NC user id in two houses).
- `findPendingSyncsForHouse` — house scoping, `completed_at ASC` ordering,
  limit boundary (rows beyond limit belong to another house).
- Grouped stats **equivalence**: seed completions for several users, assert
  `getUserStatsByHouse`/`getMaxRoomCompletionCountByHouse` maps equal the
  per-user methods' results row-for-row (regression guard for the GROUP BY).
- Behavior safety net (existing, must stay green unchanged):
  `FamilyIntegrationTest` (`:88–147` completion path incl. lock),
  `TaskSyncCompletionTest`, `StreakFreezeIdempotencyTest`,
  `HouseControllerIntegrationTest`.

**E2E:** no dedicated specs (no UI/API change); the `Release:*` gate must stay
green (16/16).

## 5. File Manifest

```
modified: lib/Service/HouseService.php
modified: lib/Service/StreakFreezeService.php
modified: lib/Service/GameLogicService.php
modified: lib/Service/RoomService.php
modified: lib/Service/RoomTaskService.php
modified: lib/Service/MinigameService.php
modified: lib/Service/TaskSyncService.php
modified: lib/Service/FloorService.php
modified: lib/Db/HouseUserMapper.php
modified: lib/Db/TaskMapper.php
modified: lib/Db/RoomMapper.php
modified: lib/Db/MedalMapper.php
modified: lib/Db/CompletedTaskMapper.php
modified: tests/unit/Service/HouseServiceTest.php
modified: tests/unit/Service/StreakFreezeServiceTest.php
modified: tests/unit/Service/GameLogicServiceTest.php
modified: tests/unit/Service/RoomServiceTest.php
modified: tests/unit/Service/RoomTaskServiceDailyTest.php
modified: tests/unit/Service/MinigameServiceTest.php
modified: tests/unit/Service/TaskSyncServiceTest.php
modified: tests/unit/Service/FloorServiceTest.php
modified: tests/unit/Db/Mapper/HouseUserMapperTest.php
modified: tests/unit/Db/Mapper/TaskMapperTest.php
modified: tests/unit/Db/Mapper/RoomMapperTest.php
modified: tests/unit/Db/Mapper/MedalMapperTest.php
modified: tests/unit/Db/Mapper/CompletedTaskMapperTest.php
new:      tests/integration/Db/BatchQueryRegressionTest.php
modified: docs/engineering/architecture.md        (batch-query pattern doc, §8)
modified: docs/backlog/backlog.md                 (at completion: close items, add write-path split)
modified: docs/backlog/ACTIVE_TASKS.md
```

Possibly removed (verify no remaining callers): `TaskSyncService::roomTaskBelongsToHouse`.

## 6. Implementation Order

Each step is one reviewable commit; full unit suite green after every step.

1. **Phase A** — 3 drop-in swaps (+ test updates).
2. **Phase B** — `handleTaskCompletion` signature + window merge (own commit;
   grep all callers first; `GameLogicServiceTest`/`RoomServiceTest` churn).
3. **Phase C1** — `HouseUserMapper` batch + Minigame leaderboards.
4. **Phase C2** — `TaskMapper`/`RoomMapper::findByIds` + 3 consumers.
5. **Phase C3** — `MedalMapper::findByUserIds` + `findPendingSyncsForHouse`.
6. **Phase D** — grouped medal stats + `getFloorsWithRooms`.
7. **Real-DB regression suite + docs**: `BatchQueryRegressionTest` seeded and
   green; batch-query pattern section added to `docs/engineering/architecture.md`.
8. **Backlog bookkeeping** (at completion): CR-SVC-50/55/56 + CR-SVC-11
   (read-path) → `archive/2026-08.md`; new write-path item (working title
   **CR-SVC-60 — Bulk write-path batching**: `resetHouse` bulk UPDATE,
   `deleteRoom` bulk DELETE with FK verification on `completed_tasks.room_task_id`,
   `reorderFloors` CASE-WHEN, `bulkAssignTasks` read pre-validation) added to
   `backlog.md` with the deferral reason; `ACTIVE_TASKS.md` updated.

## 7. Risks & Mitigations

| Risk | Likelihood | Mitigation |
|------|-----------|------------|
| Mocked service tests fatal (`null` from un-stubbed new mapper calls) | High | Update mocks in the same change; full unit suite after every step |
| Window merge misread as row limits (5/10 are **minutes**); merged fetch placed post-insert, or `$comboCount` missing the in-flight `+1` | Medium | Pre-insert fetch + `count + 1` (§3 Phase B); boundary unit tests (±4:59/5:01/9:59/10:01) + `threshold − 1` freeze case |
| `handleTaskCompletion` signature churn breaks hidden callers | Low | Grep callers first; only `RoomService` expected; separate commit |
| Skip-on-missing semantics drift (`getRoomWithTasks`, `findByIds`) | Medium | Map-absence = skip; explicit integration + unit cases |
| IN-clause with empty array on MariaDB/SQLite | Medium | Early-return guard per `findUserIdsByRoomTasks` pattern; covered in mapper unit tests |
| GROUP BY drift vs per-user aggregates (timezone/window edges) | Medium | Equivalence integration test: grouped maps must equal per-user results on seeded data |
| Medal award race behavior accidentally changed | Low | `awardMedalIfNew` untouched; per-row insert/notify explicitly out of batch scope |
| Lock-section rewrite changes timing/semantics | Medium | Two-connection lock tests exist (`ConcurrentCompletionTest`); `FamilyIntegrationTest` must stay green |
| Write-path scope creep | Medium | Write paths explicitly out of scope (§1); split item in step 8 |

## 8. Acceptance Criteria

- [x] CR-SVC-55, CR-SVC-56, CR-SVC-50 closed (moved to `archive/2026-08.md`).
- [x] CR-SVC-11 read-path cluster closed; write-path remainder split into the
      new backlog item (CR-SVC-60) with deferral reason.
- [x] All read hot spots from §1 converted; query-count reduction per hot spot
      documented in the plan's review outcome (§9).
- [x] Per-row exceptions (medal award, per-item isolation) documented with
      rationale in code comments and `architecture.md` (§ Batch Query Patterns).
- [x] New mapper methods PHPDoc'd; unit tests for each; real-DB integration
      regression suite green incl. equivalence + isolation cases.
- [x] No API/payload/UI change; existing integration tests
      (Family/TaskSync/StreakFreezeIdempotency) green unchanged
      (169 tests, 24 pre-existing skips). `Release:*` E2E gate unchanged by
      design (no UI/API change) — see §9 for the gate run.
- [x] Build gate green (`npm test` + `npm run lint` + PHPUnit unit + integration
      [24 pre-existing skips] + stylelint 0 errors + cs:check + l10n — no new
      user-facing strings).
- [x] Batch-query patterns documented in `docs/engineering/architecture.md`
      (§ Performance Optimization → Batch Query Patterns).
- [x] `docs/backlog/ACTIVE_TASKS.md` updated.

## 9. Implementation Outcome (2026-08-20)

All phases implemented as planned. Query-count reductions per hot spot:

| Hot spot | Before | After |
|---|---|---|
| `HouseService::calculateHouseHealth` | 1 + N_floors | 1 |
| `HouseService::getHouseStats` | 1 + N_floors | 2 |
| `GameLogicService::handleTaskCompletion` (inside lock) | 1 find + 1–2 window queries | 1 window query (merged, pre-insert; entity threaded in) |
| `GameLogicService::checkAndAwardMedals` | 2 per user | 2 total |
| `GameLogicService::getMedals` | 1 per user | 1 total |
| `FloorService::getFloorsWithRooms` | 2 per floor | 2 total |
| `RoomService::getRoomWithTasks` | 1 per room task | 1 batch call |
| `RoomTaskService::getDailyTasksWithDetails` | per distinct task + room id | 2 batch calls |
| `RoomTaskService::getRangeTasks` | per distinct task + room id | 2 batch calls |
| `MinigameService::getLeaderboard` / `getWeeklyLeaderboard` | 1 per score row | 1 batch call |
| `TaskSyncService::getPendingSyncsForHouse` | 1 overfetch query + 3 per row (≤250) | 1 house-scoped query |
| `StreakFreezeService::process` | 1 per user | 1 grouped query |

Deviations / notes beyond the plan text:

- `handleTaskCompletion`: the dead `$roomCleanliness` param (documented
  completion-context-only since CR-SVC-57) and the now-unused `RoomTaskMapper`
  dependency were removed in the same signature change (pre-release, no BC
  shim). `TaskSyncService::roomTaskBelongsToHouse` stays — `requireRoomTaskInHouse`
  still consumes it (only the `getPendingSyncsForHouse` caller was removed).
- **Root-cause fix discovered by the equivalence test:** `getUserStats` and
  `getMaxRoomCompletionCountByUser` built their aggregate expressions
  (`COUNT(*) AS …`, `SUM(…)`) as raw select strings — quoted as column names
  → SQL error on a real database (only mock tests existed before, so this had
  never executed). All aggregates (per-user + new grouped siblings) now go
  through `createFunction`; the equivalence regression test pins it.
- `completed_tasks.user_id` is NOT NULL on the real DB — the regression test
  seeds real house-user ids (the plan's `user_id: null` reverse-sync shape is
  a separate, pre-existing observation outside this plan's scope).

Gate: PHPUnit unit 761 tests / 2138 assertions green; integration 169 tests
[24 pre-existing skips] green incl. 4 new `BatchQueryRegressionTest` cases;
Jest + lint green (see ACTIVE_TASKS.md). Plans-are-temporary rule satisfied:
durable batch-pattern knowledge lives in
`docs/engineering/architecture.md` (§ Batch Query Patterns), not only here.

## Review Outcome (2026-08-20, Standard B)

Scope: the full uncommitted changeset (33 files) against this plan's manifest.

- **Shared checklist** (architecture, authorization, security, KISS/minimal
  diff, workarounds, logging, i18n, tests): conformant. All new mapper
  methods use the query builder with named parameters, `createFunction`
  aggregates, and empty-input early returns; no controller/route changes; no
  user-visible strings; no logging changes.
- **Equivalences verified:** `handleTaskCompletion` has exactly one caller
  (`RoomService`); the merged pre-insert 10-minute fetch with `count + 1`
  reproduces the old post-insert threshold semantics (±4:59/5:01 boundary
  tests present); `findPendingSyncsForHouse` filters match `findPendingSyncs`
  exactly; grouped-vs-per-user aggregate equivalence and cross-house
  isolation are pinned by the real-DB regression suite; skip-on-missing is
  pinned in unit + integration tests.
- **Findings:** 1 minor — `tests/unit/components/CalendarDayCell.spec.js`
  (unrelated UTC→local-date flaky-test fix mixed into this backend-only
  changeset; keep, but commit as its own `test:` commit). 1 intentional,
  documented deviation accepted — `getDailyTasksWithDetails`/`getRangeTasks`
  now skip orphaned task/room rows instead of throwing.
- **Auto-fixes applied:** none necessary.
- **Gate re-run in review:** ESLint clean · Jest 71 suites / 717 tests ·
  PHPUnit unit 761 / 2138 · PHPUnit integration 169 [24 pre-existing skips] ·
  php-cs-fixer 0/259 files · `l10n:check-keys` synchronized.
- **Backlog leftovers:** none (write-path split CR-SVC-60 already logged).
