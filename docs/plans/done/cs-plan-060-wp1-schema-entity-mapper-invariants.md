# CS-PLAN-060: WP1 — Schema, Entity, and Mapper Invariants

> **Status:** Done (2026-09-03) — final Standard B review PASS-WITH-FIXES
> **Effort:** L
> **Priority:** P1
> **Depends on:** [CS-PLAN-058](../active/cs-plan-058-core-workflow-bottom-up-masterplan.md) (active; this plan executes its work package WP1 — WP0 is Done), WP0 baseline (2026-09-02)
> **Related backlog items:** CR-053-07 (owned), CR-053-09 (owned), CR-053-19 persistence/schema parts (owned), CR-055-03 (owned), CR-055-04 (owned), CR-SVC-40 foundation portion (owned); CR-P1-21 verification only (already resolved)

## 1. Problem Summary

CS-PLAN-058 §3/WP1 requires one authoritative persisted representation for every
core workflow concept before WP2 touches authorization. The live tree does not
satisfy that; all facts below were verified on 2026-09-02.

### 1.1 Bootstrap races (CR-053-07)

- `houses.nextcloud_instance_id` has only a non-unique index
  (`Version1000Date20260224000000.php:39`); concurrent first requests can create
  two houses. `HouseMapper::createForInstance` (`lib/Db/HouseMapper.php:62-79`)
  and `HouseService::getOrCreateHouse` (`lib/Service/HouseService.php:173-188`)
  are unlocked check-then-insert.
- First-owner assignment races: `AuthorizationService::ensureHouseMember`
  (`lib/Service/AuthorizationService.php:86-115`) computes
  `role = count(existing) === 0 ? 'owner' : 'member'` with no transaction or
  lock — two distinct users first-touching concurrently can both become owner.
- `HouseUserMapper::createOrGet` (`lib/Db/HouseUserMapper.php:133-145`) is
  check-then-insert; the unique index `uniq_house_user`
  (`Version1004Date20260228131728.php:50`) makes the race loser hit an
  unhandled `UniqueConstraintViolationException` (500, no duplicate rows).

### 1.2 Competing persisted representations

- **Visibility:** `tasks.is_private` (`Version1021Date20260712000000.php:65-70`,
  never dropped, never written — `TaskMapper::create` at
  `lib/Db/TaskMapper.php:111-138` only sets `visibilityType`/`ownerUserId`)
  competes with the canonical `visibility_type` + `owner_user_id`
  (`Version1024Date20260719000000.php:45-63`). The `Task` entity carries both
  plus BC shims (`lib/Db/Task.php:170,:289-298`), `jsonSerialize` emits a derived
  `isPrivate` key (`:244`), `TaskController` accepts and normalizes an
  `isPrivate` parameter (`lib/Controller/TaskController.php:107,:191-192,:314-319`),
  and the frontend still reads/sends it (`src/views/TaskManager.vue:267`,
  `src/components/Tasks/TaskList.vue:111,415`,
  `src/components/Tasks/TaskEditForm.vue:386,410,446`).
- **Assignees:** legacy scalar `room_tasks.assigned_to_user_id`
  (`Version1000Date20260224000000.php:93`) competes with the canonical
  `chorestrike_task_assignees` join table (`Version1021:32-60`). Writers are
  split (column: `RoomTaskMapper::create:209-221`, `assignToUser:252-263`,
  `clearAssignedUserForHouse:409-425`; join table:
  `TaskAssigneeMapper::replaceAssignees`), and readers merge both at
  read/completion time (`TaskAssigneeMapper::mergeLegacyAssignee:91-107`;
  `RoomTaskService.php:444,460,921` merge reads, `:747-748` claimable-overdue
  legacy filter; `RoomService.php:278,314-316`;
  `TaskDueReminderService.php:128-130`; frontend fallbacks
  `TaskList.vue:402,418-419,427`, `src/views/TodayView.vue:229`). Initial
  assignment writes only the legacy column, never the join table. The
  abandoned group-assignment design additionally leaves writer-less/constant
  remnant columns: `assignment_type` (constant `'user'` via
  `assignToUser:257`), `assigned_to_group` (always NULL via `:258`) plus
  `idx_room_tasks_group` (`Version1007Date20260228180000.php:28-60`), and
  `assigned_by_user_id`/`assigned_at`
  (`Version1011Date20260301220000.php:20-31`, never written — the join
  table's `assigned_by`/`assigned_at` are canonical). `claimed_by_user`
  (`Version1007:48-55`) and `original_assignee`
  (`Version1009Date20260228200000.php`) stay — live claim feature
  (decision D9).
- **Recurrence:** `RoomTask::markCompleted`/`projectOccurrences` branch on
  `'one_time'` (`lib/Db/RoomTask.php:274,648`) while the validation boundary
  accepts `['recurring','once','bounded']` (`lib/Service/TaskService.php:295`)
  — a validation-accepted `'once'` task never deactivates on completion
  (CR-053-09; silent infinite recurrence). The frontend uses no
  recurrence-value vocabulary beyond `'recurring'`
  (`src/views/CalendarView.vue:210`).

### 1.3 Writer-less sync remnants (CR-055-03)

`room_tasks.nextcloud_task_id` (`Version1002Date20260224180000.php:34-37`) and
`completed_tasks.nextcloud_task_id` + `synced_to_nextcloud` + `idx_ct_sync`
(`Version1004Date20260228131728.php:63-69`) have had no writer since the
`TaskSyncService` removal; rows are always NULL while `RoomTask`/`CompletedTask`
still expose the property and a `nextcloudTaskId` payload key.

### 1.4 Transaction plumbing spread (CR-SVC-40, foundation portion)

Seven services inject `IDBConnection` purely for `beginTransaction`/`commit`/
`rollBack`: `HouseService:113`, `FloorService:159`, `HouseSetupService:99`,
`MinigameService:116,281`, `RoomService:151,256`, `RoomTaskService:594`, plus
`TestDataService` (`getQueryBuilder` only). Locking lives as two parallel
SQLite-tolerant mapper primitives (`HouseUserMapper::findForUpdate:67-88`,
`RoomTaskMapper::lockForUpdate:49-70`), one mapper self-transaction
(`TaskAssigneeMapper::replaceAssignees:244-254`), and no documented lock order.

### 1.5 Mapper correctness minors (CR-053-19 persistence parts, CR-055-04)

- PostgreSQL-incompatible boolean compare: `CASE WHEN ct.was_combo = 1`
  (`lib/Db/CompletedTaskMapper.php:81`).
- Inclusive week-window end bound: `+7 days` passed to `lte` filters
  (`GameLogicService.php:329` → `CompletedTaskMapper.php:87,235`; further `lte`
  windows at `:281,:364,:455`) — a completion exactly at next Monday 00:00
  counts in two windows, while the day-window convention is half-open
  (`:124-198`) and `HouseService.php:383` papers over it with `-1 second`.
- Unlocked checklist toggle RMW (`RoomTaskChecklistProgressMapper.php:84-105`
  via `ChecklistService::toggleItem:230-240`).
- `FloorMapper::create` hand-rolled INSERT + re-select instead of
  `insert($entity)` (`lib/Db/FloorMapper.php:79-103`); dead `findAll` override
  with zero callers (`:62-70`, CR-055-04).
- `MinigameScoreMapper::getUserRank` scans the whole house+game result set in
  PHP (`lib/Db/MinigameScoreMapper.php:109-134`).
- No `estimatedMinutesMin <= estimatedMinutesMax` cross-check
  (`TaskService::validateTaskData:264-268` checks 1..480 only;
  `Task::getEstimatedTimeRange:254-259` renders inverted ranges).
- `TaskMapper::findVisibleByHouse` accepts `$houseId` but never scopes by house
  (`lib/Db/TaskMapper.php:156-192`) — task definitions have no usable
  house-ownership query, so multi-house data would leak across houses.
- `findActiveByHouse` (dead column query) was already deleted — re-verify only.

## 2. Strategy

Bottom-up inside the package, each step gated by a failing or missing test
first (CS-PLAN-058 §2.3):

1. **Fold the schema** to the canonical representations directly in the
   existing migration chain (pre-release policy: fresh installs only, no
   upgrade shims, disposable dev databases).
2. **Make bootstrap race-safe at the persistence layer**: unique instance
   index, constraint-violation-tolerant inserts (first write wins), and a
   house-row lock serializing first-owner role assignment.
3. **Migrate code mechanically** to the canonical representations — no
   behavior redesign; aggregate semantics stay with WP3/WP4/WP5.
4. **Give transaction/lock plumbing exactly one owner** in `lib/Db` and route
   the existing service call sites through it; document the deterministic lock
   order as the foundation for WP5's completion ledger.
5. **Fix the mapper correctness minors** inside mappers/validation.
6. **Prove everything from a clean Docker volume** with real-DB integration
   tests and MySQL-only two-connection concurrency tests (existing
   `proc_open` pattern from `ConcurrentCompletionTest`).

## 3. Backend / Frontend Changes

### 3.1 Schema fold in the migration chain

- `Version1000Date20260224000000.php`: `idx_house_inst` becomes a **unique**
  index on `nextcloud_instance_id`; drop the `assigned_to_user_id` column from
  the initial `room_tasks` create.
- `Version1002Date20260224180000.php`: **delete the file** — its only remaining
  live content is `room_tasks.nextcloud_task_id` + index (same folding
  precedent as the deleted Version1017–1020 chain entries).
- `Version1004Date20260228131728.php`: remove `nextcloud_task_id`,
  `synced_to_nextcloud`, and `idx_ct_sync` from the `completed_tasks` create.
- `Version1007Date20260228180000.php`: remove `assigned_to_group`,
  `assignment_type`, and `idx_room_tasks_group`; keep `claimed_by_user`
  (live claim feature, decision D9).
- `Version1011Date20260301220000.php` / `Version1015Date20260304000000.php`:
  remove the indexes on the dropped `assigned_to_user_id`
  (`idx_room_tasks_assigned`, `rt_user_id_idx`); additionally remove the
  writer-less `assigned_by_user_id`/`assigned_at` column adds from
  Version1011 (decision D9).
- `Version1021Date20260712000000.php`: remove the `is_private` column add;
  keep the `task_assignees` create unchanged.
- Keep the duplicate Version1004 file numbering as-is (their contents are
  order-independent; renumbering is churn — decision D8).

### 3.2 Bootstrap and membership race safety (CR-053-07)

- `HouseMapper::createForInstance` catches
  `UniqueConstraintViolationException` and re-fetches via `findByInstanceId`
  (first insert wins; no retry loops).
- New `HouseMapper::findForUpdate(int $houseId): House` following the existing
  SQLite-tolerant `SELECT … FOR UPDATE` pattern.
- `AuthorizationService::ensureHouseMember` computes the role inside one
  transaction started through the new plumbing (§3.5) after locking the house
  row — deterministic order **house → house_users**; the second concurrent
  first-toucher counts one member and becomes `member`.
- `HouseUserMapper::createOrGet` catches `UniqueConstraintViolationException`
  and re-fetches the existing row.

### 3.3 One canonical representation per concept

**Visibility** — canonical: `visibility_type` + `owner_user_id`.

- Remove the `isPrivate` entity property, `isPrivate()`/`setIsPrivate()` shims,
  `jsonSerialize` key (`Task.php`), the `TaskMapper::create` parameter, the
  `TaskController` `isPrivate` request parameter and its normalization blocks,
  and the `TaskService::createTask` parameter chain.
- Frontend (mechanical key removal only, no UI redesign): drop
  `task.isPrivate` reads and the `isPrivate` payload field in
  `TaskEditForm.vue`, `TaskList.vue`, `TaskManager.vue`.

**Assignees** — canonical: `chorestrike_task_assignees` join table.

- Remove `assigned_to_user_id` from the `RoomTask` entity, `jsonSerialize`,
  `RoomTaskMapper::create`/`assignToUser`/`clearAssignedUserForHouse`, and
  `RoomTaskService::unassignTask` (`clearAssignedUserForHouse` becomes
  join-table deletes). Also remove the writer-less remnant columns' entity
  surface: `assignmentType`, `assignedToGroup`, `assignedByUserId`, and
  `assignedAt` properties, `addType` calls, and `jsonSerialize` keys
  (decision D9).
- All single-assignee writers call `TaskAssigneeMapper` primitives
  (`replaceAssignees` is the atomic replacement; add a `clearForUser` /
  `findByUser` primitive where needed). Initial assignment in
  `RoomTaskService::createRoomTask` writes join rows after insert — sequential
  calls preserve today's atomicity level; wrapping creation+assignment into
  one aggregate transaction is **WP4** scope, not added here.
- All readers use the join table (`RoomTaskController` already enriches an
  `assignees` array at `:154`); delete `mergeLegacyAssignee` and every
  legacy-fallback read in `RoomTaskService`, `RoomService`,
  `TaskDueReminderService`, `TaskList.vue`, `TodayView.vue`.
- `original_assignee` stays as the claim-feature snapshot; its restore path
  writes to the join table. `HouseService::removeHouseMember` clears join rows
  and claim/assignment state in the already-transactional aggregate.

**Recurrence** — canonical vocabulary: `recurring` | `once` | `bounded`.

- Add `Task::RECURRENCE_*` class constants; `TaskService::validateTaskData`
  and `RoomTask::markCompleted`/`projectOccurrences` branch on them
  (`'once'` replaces `'one_time'`). No schema change — `'once'` is what
  validation already persists.

### 3.4 House ownership chain for task definitions

`TaskMapper::findVisibleByHouse` scopes through the real chain
(`room_tasks → rooms → floors → houses`) via an `EXISTS` subquery, making the
previously dead `$houseId` parameter authoritative. Task definitions without
any room-task link stay visible — the CR-053-01 convention (unlinked = linked
to the instance's house, as in `RoomTaskMapper::findHouseIdsForTask`) — so
the current create-then-link flow keeps showing fresh definitions; only
linked tasks of other houses are rejected (decision D11). Verify supporting
indexes for the chain and add them to the folded chain only if a query plan
needs them.

### 3.5 Transaction and lock plumbing — one owner (CR-SVC-40 foundation)

- New `lib/Db/TransactionExecutor`: `begin`/`commit`/`rollBack` plus a
  `run(callable)` wrapper (begin → callable → commit, rollBack on
  `Throwable`). No business logic; it is the single place allowed to call
  `IDBConnection` transaction methods.
- Route the six transactional services (`House`, `Floor`, `HouseSetup`,
  `Minigame`, `Room`, `RoomTask`) through it and remove their `IDBConnection`
  injection. `TestDataService` (test tooling, query-builder only) is
  refactored onto mappers where trivial, else documented as exempted in
  CR-SVC-40 (decision D6).
- Keep the per-mapper `findForUpdate`/`lockForUpdate` primitives (they are
  mapper-specific), add the house-row lock (§3.2), and document the
  deterministic lock order — **house → room_task → task/assignee/reward
  rows** — in `docs/engineering/development.md`, referencing ADR-007's
  completion ordering rather than duplicating it.
- Completion-effect lookup/inversion primitives are **deferred to WP5** with
  ADR-011, which defines the effect record; WP1 delivers their foundations
  (lock order, atomic replacement, half-open window query convention)
  (decision D7).

### 3.6 Mapper correctness minors

- `CompletedTaskMapper:81`: `CASE WHEN ct.was_combo THEN 1 ELSE 0 END`
  (portable across SQLite/MySQL/PostgreSQL).
- Week/window queries become half-open `[gte, lt)`; `GameLogicService` passes
  the exclusive `+7 days` bound; remove `HouseService.php:383`'s
  `-1 second` workaround where it targets the corrected queries.
- `RoomTaskChecklistProgressMapper`: toggle becomes a mapper-owned locked RMW
  (transaction via `TransactionExecutor` + row lock) with
  unique-violation-tolerant insert.
- `FloorMapper::create` uses `$this->insert($entity)`; delete the dead
  `findAll` override (CR-055-04).
- `MinigameScoreMapper::getUserRank` computes the rank with a
  `COUNT(*) + 1 WHERE score > (user's best)` query instead of an unbounded
  scan.
- `TaskService::validateTaskData` cross-checks
  `estimatedMinutesMin <= estimatedMinutesMax`.

## 4. Tests

- **Unit (PHPUnit, host):** extend `FreshInstallMigrationSmokeTest` — replay
  still returns no null steps; assert the folded schema (unique instance
  index; no `is_private`, `nextcloud_task_id`, `synced_to_nextcloud`,
  `assigned_to_user_id`, `assignment_type`, `assigned_to_group`,
  `assigned_by_user_id`, or `assigned_at` columns; `task_assignees` and
  `claimed_by_user` intact) via the `RecordingSchema`. New entity tests:
  `Task` serialization carries `visibilityType` only; `RoomTask`
  serialization carries no `assignedToUserId`/`nextcloudTaskId`/
  `assignmentType`/`assignedToGroup`/`assignedByUserId`/`assignedAt`.
  Validation tests: recurrence vocabulary constants; `estimatedMinutes`
  inverted range rejected.
- **Integration (Docker, real DB):**
  - `BootstrapConcurrencyTest` (MySQL-only guard, `proc_open` two-connection
    pattern from `ConcurrentCompletionTest`): concurrent
    `getOrCreateHouse` → exactly one house; concurrent `ensureHouseMember`
    with two fresh users → exactly one `owner`, loser gets `member`;
    `createOrGet` race loser receives the existing row (no exception).
  - `RecurrenceOnceDeactivationTest`: `'once'` room task deactivates on
    completion; `'recurring'` projects the next occurrence (CR-053-09
    regression).
  - Assignee representation: assign/unassign/clear/member-removal leave
    canonical join-table state only; completion reads join-table assignees;
    schema contains no legacy column.
  - `findVisibleByHouse` house-scoping with a two-house fixture.
  - Week-window boundary: a completion exactly at the window boundary counts
    in exactly one window; weekly-goal totals unchanged off-boundary.
  - Checklist toggle concurrency: parallel toggles produce a deterministic
    final state, no lost update.
  - `getUserRank` correctness on a fixed score fixture;
    `FloorMapper::create` returns the persisted entity with id.
  - `TransactionExecutor` commit/rollback/exception semantics through one
    real service path.
- **Jest:** update payload fixtures and component tests for
  `visibilityType`-only / `assignees`-only contracts
  (`TaskEditForm`, `TaskList`, `TaskManager`, `TodayView`).
- **E2E:** run the affected `chromium` projects (mechanical frontend changes
  only; no new profiles — WP3/WP9 own those).
- **Gate (CS-PLAN-058 §4.5):** full gate including Docker unit + integration,
  `composer cs:check`, and the applicable Playwright projects; clean-volume
  `down -v` + `up -d` install before the integration run.

## 5. File Manifest

```
deleted:  lib/Migration/Version1002Date20260224180000.php
modified: lib/Migration/Version1000Date20260224000000.php
modified: lib/Migration/Version1004Date20260228131728.php
modified: lib/Migration/Version1007Date20260228180000.php
modified: lib/Migration/Version1011Date20260301220000.php
modified: lib/Migration/Version1015Date20260304000000.php
modified: lib/Migration/Version1021Date20260712000000.php
new:      lib/Db/TransactionExecutor.php
modified: lib/Db/{House,Task,RoomTask,CompletedTask}.php
modified: lib/Db/{HouseMapper,HouseUserMapper,FloorMapper,TaskMapper,RoomTaskMapper,TaskAssigneeMapper,RoomTaskChecklistProgressMapper,MinigameScoreMapper,CompletedTaskMapper}.php
modified: lib/Service/{AuthorizationService,HouseService,FloorService,HouseSetupService,MinigameService,RoomService,RoomTaskService,TaskService,ChecklistService,GameLogicService,TaskDueReminderService}.php
modified: lib/Service/TestDataService.php (mapper-based QB access or documented exemption)
modified: lib/Controller/{TaskController,RoomTaskController}.php
modified: src/components/Tasks/{TaskEditForm,TaskList}.vue · src/views/{TaskManager,TodayView}.vue
modified: tests/unit/Migration/FreshInstallMigrationSmokeTest.php (+ new unit tests)
new:      tests/integration/Db/BootstrapConcurrencyTest.php (or Service/, following existing placement)
new:      tests/integration/Db/{RecurrenceOnceDeactivationTest,AssigneeRepresentationTest}.php (+ further integration tests from §4)
modified: tests/integration/**/* and tests/unit/**/* where fixtures reference dropped keys
modified: docs/engineering/development.md (lock order, migration-chain baseline update)
modified: docs/backlog/{backlog.md,ACTIVE_TASKS.md} + docs/backlog/archive/2026-09.md (completion entries)
modified: docs/plans/active/cs-plan-058-core-workflow-bottom-up-masterplan.md (§9 progress row WP1)
modified: docs/plans/new/cs-plan-060-wp1-schema-entity-mapper-invariants.md (this plan; moves to active/ on review pass)
```

`l10n/` changes: one new validation error string (inverted
`estimatedMinutesMin`/`estimatedMinutesMax` range, §3.6) plus payload-key
removal only; artifacts update in the same change (`npm run l10n:check-keys`).

## 6. Implementation Order

1. Write the failing regressions first: bootstrap race (MySQL two-connection),
   `'once'` deactivation, toggle race, week-window boundary,
   `findVisibleByHouse` scoping.
2. Fold the schema (§3.1); reset the disposable Docker volume; migration
   smoke + clean-install integration green.
3. Bootstrap race safety (§3.2) + `TransactionExecutor` (§3.5); route the six
   services; concurrency tests green.
4. Visibility unification (§3.3) backend → frontend mechanical → Jest green.
5. Assignee unification (§3.3) backend → frontend fallback removal →
   integration green.
6. Recurrence constants (§3.3); deactivation regression green.
7. Mapper minors (§3.6); their integration tests green.
8. House-scoped `findVisibleByHouse` (§3.4) incl. index verification.
9. Full §4.5 gate from a clean volume; `npm run lint:fix` (scoped) beforehand.
10. Update CS-PLAN-058 §9 (WP1 row), backlog dispositions, `ACTIVE_TASKS.md`,
    durable docs (lock order, migration baseline); request Standard B review.

## 7. Risks & Mitigations

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Schema fold breaks fixtures/tests referencing dropped keys | High | Step 2 runs first with clean volume; grep-audited call-site inventory in §1.2/§1.3 is the migration checklist |
| Assignee migration touches the WP5 completion hot path | Medium | Mechanical reader/writer swaps only; no aggregate redesign; full integration suite + concurrency tests rerun |
| SQLite cannot prove row-lock races | Certain | MySQL-only two-connection tests (existing guard pattern); SQLite keeps the tolerant no-op lock path |
| Unique instance index breaks multi-house dev leftovers | Low | Pre-release: dev DBs are disposable (`down -v`); documented in plan |
| `TransactionExecutor` becomes a god-helper | Medium | Only begin/commit/rollBack/run; no query-builder access; mappers keep lock primitives |
| Renamed/removed payload keys reach overlooked consumers | Medium | Contract grep for `isPrivate`, `assignedToUserId`, `nextcloudTaskId`, `assignmentType`, `assignedToGroup`, `assignedByUserId`, `assignedAt` (room-task payload) across `src/` + `tests/` as an acceptance check |
| Week-window semantics change alters existing totals | Medium | Boundary-focused tests plus off-boundary equality assertions; `-1 second` workaround removed only where the corrected query is used |
| `TestDataService` refactor exceeds mechanical scope | Low | Decision D6 exemption path; WP8 re-evaluates |
| Fold misses an index on a dropped column → install failure | Low | Migration smoke test + clean-volume install gate per profile |

## 8. Acceptance Criteria

- [x] Clean-volume Docker install produces the folded final schema; the
      migration smoke test asserts the unique instance index and the absence
      of `is_private`, `assigned_to_user_id`, `nextcloud_task_id`,
      `synced_to_nextcloud`, `assignment_type`, `assigned_to_group`,
      `assigned_by_user_id`, and `assigned_at`, while `claimed_by_user` and
      `original_assignee` remain.
- [x] Concurrent first-request bootstrap yields exactly one house and exactly
      one owner (two-connection MySQL test); `createOrGet` races return the
      existing row without a 500.
- [x] Exactly one persisted representation per concept: visibility via
      `visibility_type`/`owner_user_id`, assignees via the join table,
      recurrence via the `Task::RECURRENCE_*` vocabulary; no core read merges
      legacy and canonical representations (contract grep + integration
      assertions).
- [x] A validation-accepted `'once'` task deactivates on completion; the
      `'one_time'` literal no longer exists in `lib/`.
- [x] `findVisibleByHouse` scopes task definitions by the real house chain;
      a two-house fixture proves no cross-house leakage.
- [x] Only `TransactionExecutor` calls `IDBConnection` transaction methods;
      the deterministic lock order is documented in engineering docs.
- [x] Mapper minors fixed and regression-tested (portable `was_combo` compare,
      half-open week windows, locked toggle, `insert()`-based floor create,
      deleted dead `findAll`, bounded `getUserRank`, minute-range
      cross-check).
- [x] Build gate green (`npm test` + lint + stylelint + Docker unit +
      integration + `cs:check` + applicable Playwright projects +
      `backlog:check`), with no new or unapproved skips.
- [x] Owning backlog items (CR-053-07, CR-053-09, CR-055-03, CR-055-04,
      CR-053-19 persistence parts, CR-SVC-40 foundation portion) archived or
      re-scoped to their exact remainder; CS-PLAN-058 §9 updated.
- [x] Durable outcomes integrated into permanent docs (lock order,
      migration-chain baseline) — plans are temporary.
- [x] All implementation decisions and review questions resolved and recorded.
- [x] Final Standard B review is `PASS` or `PASS-WITH-FIXES`;
      `ACTIVE_TASKS.md` updated.

## 9. Plan Review Decision Register

| ID | Proposed decision | Rationale |
|----|-------------------|-----------|
| D1 | Canonical visibility is `visibility_type` + `owner_user_id`; drop `is_private` column, shims, payload key, controller parameter, and frontend reads — no compat layer | Pre-release policy: update call sites, delete old code; the column is already writer-less |
| D2 | Canonical assignee store is the `task_assignees` join table; drop `assigned_to_user_id`; `original_assignee` remains the claim-feature snapshot restoring into the join table | One representation usable by WP2 authorization and the WP4 aggregate; merge logic deleted |
| D3 | Canonical recurrence vocabulary is `recurring`/`once`/`bounded` as `Task::RECURRENCE_*` constants; entity branches change `'one_time'` → `'once'`; no schema change | Validation boundary already persists `'once'`; constants prevent re-divergence |
| D4 | Drop the writer-less `nextcloud_task_id`/`synced_to_nextcloud` remnants instead of re-scoping them | No writer since TaskSyncService removal; a future Nextcloud Tasks sync requires a new ADR and fresh migration |
| D5 | First-owner safety = unique instance index + violation-tolerant insert + house-row lock before the member count; lock order `house → room_task → dependents` documented in engineering docs | DB constraints beat application-level check-then-insert; documented order is the WP5 ledger foundation |
| D6 | `lib/Db/TransactionExecutor` is the single transaction-plumbing owner; `TestDataService` is mapper-refactored where trivial, otherwise explicitly exempted as non-production test tooling | Stops the IDBConnection spread without a WP8-scale refactor; exemption stays visible in CR-SVC-40 |
| D7 | Completion-effect lookup/inversion mapper primitives are deferred to WP5 with ADR-011 | The effect-record design defines them; WP1 delivers only their foundations (lock order, atomic replacement, window convention) |
| D8 | Keep the duplicate Version1004 file numbering; do not renumber the chain | The two files' contents are order-independent; renumbering is churn with install risk and no defect fixed |
| D9 | Fold the writer-less group-assignment remnants into the schema fold: drop `assignment_type`, `assigned_to_group` + `idx_room_tasks_group` (Version1007) and `assigned_by_user_id`/`assigned_at` (Version1011) plus their `RoomTask` entity/`jsonSerialize` surface; `claimed_by_user`/`original_assignee` stay (live claim feature) | Same writer-less-remnant policy as D4; no frontend consumers (contract grep clean); completes the one-canonical-assignee-representation invariant. Ratified by user 2026-09-02 |
| D10 | WP1 includes the mechanical frontend contract-key removals of §3.3 despite CS-PLAN-058 WP1's "no frontend changes beyond contract fixtures" | Removing backend payload keys without removing frontend readers/senders would leave dead contract reads; no UI redesign — WP3/WP7 keep owning UI work; CS-PLAN-058's WP1 frontend line is superseded for contract-key removal (record it when updating CS-PLAN-058 §9 in step 10). Ratified by user 2026-09-02 |
| D11 | `findVisibleByHouse` house-scoping keeps unlinked task definitions visible (CR-053-01 convention: unlinked = linked to the instance's house); only linked tasks of other houses are rejected | Preserves today's behavior for the create-then-link flow under one-house-per-instance; no behavior redesign in a mechanical package. Ratified by user 2026-09-02 |

## 10. Review Outcome

**Standard A plan review — 2026-09-02: PASS (GO-WITH-CHANGES, changes applied).**

- Reviewed against the review skill §3 shared checklist plus plan-only
  checks; every file:line reference in §1 was re-verified against the live
  tree (migrations 1000/1002/1004×2/1007/1009/1011/1015/1021/1024, all cited
  mappers/services/controllers, frontend call sites, test infrastructure,
  backlog IDs, ADR-007, Playwright projects, `backlog:check`). Findings:
  references accurate; `findActiveByHouse` confirmed already deleted
  (CR-P1-21 verification-only claim holds).
- Inline fixes: corrected mapper method names (`assignToUser`,
  `clearAssignedUserForHouse`, `RoomTaskService::unassignTask`) and reader
  line citations in §1.2/§3.3; reworded the §5 l10n note to anticipate the
  one new validation error string (§3.6 cross-check).
- Scope decisions resolved with the user and recorded as D9 (fold the
  writer-less group-assignment remnant columns; §1.2/§3.1/§3.3/§4/§5/§7/§8
  updated accordingly), D10 (ratified mechanical frontend contract-key
  removals as a deviation from CS-PLAN-058 WP1's frontend line), and D11
  (unlinked task definitions stay visible in `findVisibleByHouse`).
- No open findings. Recommended next state: `active/`; the move remains a
  manual user action per project-rules §7. No backlog items added (plan
  review); the only noted follow-up is the CS-PLAN-058 §9/WP1 frontend-line
  supersession record due in implementation step 10.

## 11. Implementation Record (2026-09-03)

Implemented per §6 order. Full §4.5 gate green from a clean Docker volume
(`down -v` + `up -d`): PHPUnit unit 781 OK, PHPUnit integration 229
(21 pre-existing skips, no new skips), Jest 780 OK, ESLint + stylelint +
`composer lint` + `cs:check` clean, `l10n:check-keys` / `backlog:check` /
`docs:encoding` green, Playwright `chromium` project 92 passed / 7 skipped.

Notes beyond the letter of §3 (recorded for the Standard B review):

- `TransactionExecutor` also owns the mapper-level transaction in
  `TaskAssigneeMapper::replaceAssignees` and the new locked
  `RoomTaskChecklistProgressMapper::toggle` (acceptance criterion "only
  TransactionExecutor calls IDBConnection transaction methods"); both
  mappers inject it via constructor DI.
- New SQLite-tolerant lock primitive
  `RoomTaskChecklistProgressMapper::findForUpdate` backs the locked RMW
  toggle (§3.6); `HouseMapper::findForUpdate` (§3.2) completes the house-row
  lock. Lock order documented in `docs/engineering/development.md`
  ("Locking and transactions").
- Initial-assignment writers beyond `RoomTaskService::createRoomTask` were
  migrated to the join table as part of D2: `HouseSetupService` (wizard
  rooms) and `TestDataService` (family seed).
- The claim snapshot source in `RoomService::completeRoomTask` takes the
  first canonical assignee (`$assigneeUserIds[0]`); `claimed_by_user`,
  `was_claimed`, `original_assignee`, `claimed_at` stay (D9).
- `TaskService::assignTask`/`unassignTask` preserve the former
  single-assignee-write semantics (assignee-set replacement + stale
  `claimed_by_user` reset) via `replaceSingleAssignee`.
- §3.4 `findVisibleByHouse` uses two correlated EXISTS subqueries
  (linked-to-viewer-house OR completely unlinked) with the house parameter
  bound on the outer builder (TaskAssigneeMapper subquery pattern); existing
  indexes proved sufficient, no new index needed.
- `RoomTaskService::getRangeTasks` keeps the inclusive end-of-day bound for
  the recurrence-projection base set and passes the exclusive next-day-midnight
  bound only to `findCompletionsBetweenByHouse` (half-open convention).
- One new l10n string: "Estimated time minimum must not be greater than the
  maximum" (en/de/templates updated in the same change).
- Migration-chain baseline updated in `docs/engineering/development.md`
  (21 files; Version1002 folded out); CS-PLAN-058 §9 WP1 row updated
  including the D10 frontend-line supersession note; backlog dispositions
  archived to `docs/backlog/archive/2026-09.md`.

**Standard B final code review — 2026-09-03: PASS-WITH-FIXES.**

- **Scope:** the full uncommitted WP1 package — 77 tracked modified/deleted
  files + 9 untracked files (this plan, `lib/Db/TransactionExecutor.php`,
  7 new integration tests). Base: `master` @ `49be3fed5dba86ed8b3b030c6397ebc366837f67`,
  nothing staged; unstaged diff SHA-1 `874d90dd0f0a959072e1acb74983b6811dd45bc8`,
  untracked content hashes recorded per file in the review record
  ([`../../backlog/archive/CODE_REVIEW_2026-09-03.md`](../../backlog/archive/CODE_REVIEW_2026-09-03.md)).
- **Checks:** review skill §3 shared checklist against every changed file;
  acceptance contract greps (`one_time` in `lib/` = 0 hits;
  `beginTransaction` outside `TransactionExecutor` = 0 hits; removed payload
  keys across `src/` = 0 hits); all migrations, mappers, services,
  controllers, frontend edits, and the 7 new integration tests read in full.
- **Findings (all fixed in-review, no open findings):**
  1. **major** — stale E2E consumers of removed payload keys:
     `overdue-task-claiming.spec.ts` filters silently degraded the claim /
     private-task gating tests to skips in the `chromium` project, and
     `time-travel-overdue.spec.ts` / `multi-user-interactions.spec.ts` would
     outright fail in `chromium-serial-db` (not part of the implementation
     gate run). Fixed mechanically to the canonical `assignees` contract;
     `TestDataService` family seed now returns room tasks enriched with the
     canonical `assignees` key (test tooling, D6 scope). `release-task-lifecycle`
     and `error-handling` filters updated for contract hygiene.
  2. **major** — durable docs contradicted the implemented contract:
     `docs/api/README.md` still documented the `isPrivate` compat behavior
     and the legacy `assigned_to_user_id` merge; `docs/engineering/architecture.md`
     schema inventory still listed the folded columns. Both corrected.
  3. **minor** — `lib/Db/Task.php` formatting glitch (`*/` and method
     declaration collapsed onto one line) — fixed.
  4. **minor** — stale Jest fixture keys (`assignedToUserId` in
     `TodayView.spec.js`, `roomTasks.spec.js`) against the §4
     assignees-only contract — removed.
  5. **nit** — pre-existing `docs/api/README.md` room-tasks example uses
     snake_case keys that predate WP1 — logged into CR-053-21 (backlog),
     not fixed here (out of scope).
- **Gates rerun after fixes (all green):** Jest 780/780; ESLint + stylelint
  clean; PHPUnit unit (Docker) 781 OK; PHPUnit integration (Docker,
  clean-volume install) 229 OK with the same 21 pre-existing skips;
  `php-cs-fixer` 0 findings (scoped `php -l` on changed files clean);
  `l10n:check-keys`, `backlog:check`, `docs:encoding` green; Playwright
  `chromium` + `chromium-serial-db` 181 passed, 0 failed (the previously
  skipping claim-gating tests now actively run). One environment retry: the
  first Playwright run failed in setup because the review brought the stack
  up without `TEST_DB_PROFILE=family` (user provisioning gap) — re-created
  clean per docker-dev; not a code defect.
- **Questions:** none — no behavior, architecture, migration, or security
  decision required user arbitration; decisions D1–D11 stand as ratified.
- **Outcome:** `PASS-WITH-FIXES`; every acceptance criterion verified;
  plan moves `active/ → done/`.
