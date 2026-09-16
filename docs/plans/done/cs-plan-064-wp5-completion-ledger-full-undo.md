# CS-PLAN-064: WP5 — Completion Ledger and Full Transactional Undo

> **Status:** Done (all steps 1–10 implemented 2026-09-09; Standard A `PASS-WITH-FIXES` 2026-09-06; interim Standard B `PASS-WITH-FIXES` backend slice + `PASS` step-6 round, both 2026-09-09; final Standard B `PASS-WITH-FIXES` 2026-09-09 — every gate rerun green by the review, RF-01..RF-12 resolved, CR-WF-02 + UX-015 delivered and archived; moved to `done/`)
> **Effort:** L
> **Priority:** P1
> **Depends on:** [CS-PLAN-058](cs-plan-058-core-workflow-bottom-up-masterplan.md) (active; this plan executes its work package WP5 — WP0..WP4 are Done), ADR-007 completion doctrine, ADR-010 visibility/404 standard, WP4 creation aggregate precedent (`TaskCreationService`, 2026-09-06)
> **Related backlog items:** CR-WF-02 (owned umbrella); UX-015 (owned); CR-053-20 WP5 undo/cache slice; CR-SVC-15/40 WP5 completion-path slices
>
> **Scope guard:** this plan owns the completion/undo critical path only:
> no scheduling preview/apply or `todayConsumedEnergy` reader changes (WP6),
> no canonical payload/store redesign beyond reconciling the owning stores from
> the one completion/undo response (WP7), no GameLogicService domain split or
> structural decomposition beyond extracting the completion/undo path (WP8),
> no E2E-harness or skip-inventory work (WP9), and no reward-formula, medal,
> title, or notification product changes — existing formulas and event
> vocabulary are frozen contracts (CS-PLAN-058 §1.1).

## 1. Problem Summary

CS-PLAN-058 §3/WP5 requires that "completion and undo become exact, idempotent
state transitions": a completion-effect record with authoritative before-state
and applied deltas, one documented deterministic lock order over room task,
task definition, room, acting membership, and reward state, occurrence-scoped
gates before any reward write, post-commit effects that can never turn a
committed success into an ambiguous failure, and one idempotent undo operation
that restores the full occurrence. The live tree does not satisfy this mandate;
all facts below were verified on 2026-09-06 against `master`.

### 1.1 No completion-effect record exists (CR-WF-02, masterplan D2)

- The completion ledger `chorestrike_completed_tasks`
  (`lib/Db/CompletedTask.php:42-77`) stores reward **outputs** only. The
  production completion path writes just six fields — `roomTaskId`, `userId`,
  `completedAt`, `coinsEarned`, `wasCombo`
  (`lib/Service/GameLogicService.php:113-119`); the columns
  `comboWithUserId`, `isTeamCompletion`, `participantCount`, `teamSparkBonus`,
  `wasClaimedCompletion`, `claimBonusSparks`, `daysOverdueWhenClaimed` have
  **no writer anywhere in the codebase** (setter matches exist only in the
  entity docblock).
- Nothing records the **before-state**: the previous `nextDue`/`idealDueDate`/
  `isActive`/`lastCompleted` of the room task, the claim fields, the room's
  cleanliness, or the completer's coins/contribution/streak/freezes/
  longest-streak/unlocked-titles snapshot. An exact inverse is therefore not
  computable — the ratified masterplan decision D2 ("store an immutable
  completion before-state plus applied deltas and use those deltas for undo")
  has no implementation.

### 1.2 Undo is a split, partial, non-idempotent mutation (CR-WF-02 / CR-053-20)

- `TaskController::uncomplete` (`lib/Controller/TaskController.php:235-268`)
  delegates to `RoomTaskService::uncompleteTask`
  (`lib/Service/RoomTaskService.php:977-982`), which only sets
  `lastCompleted = null`. The recurrence advance (`nextDue`/`idealDueDate`
  written by `markCompleted`, `lib/Db/RoomTask.php:215-265`), the deactivation
  of one-time tasks, the ledger row, coins/contribution/streak/freeze/title
  rewards, and the room cleanliness delta are **not reverted**.
- The frontend chains a second call: `undoRoomTaskCompletion` runs
  `uncompleteRoomTask` and — for inactive rows — `reactivateRoomTask`
  (`src/store/roomTasks.js:405-429`), i.e. `PUT /tasks/{id}/uncomplete` +
  `POST /room-tasks/{id}/reactivate`. Its own docblock documents the gap as a
  known CR-WF-02 limitation (`:409-411`).
- The undo actor gate is visibility only (`assertLifecycleVisibility`,
  `RoomTaskService.php:965-968`): **any member** who can see the task can
  uncomplete it — the ratified completer-or-house-owner policy (masterplan
  decision D3) is not enforced.
- Undo is not idempotent and not occurrence-linked: uncompleting a task that
  was never completed silently "succeeds" (a no-op `UPDATE`), and nothing ties
  the undo to a specific ledger occurrence.

### 1.3 Lock order and concurrency gaps (CR-WF-02 / CR-SVC-40 WP5 slice)

- Room cleanliness is an **unlocked read-modify-write**:
  `RoomService::updateRoomCleanliness` (`lib/Service/RoomService.php:225-229`)
  does find → `updateCleanliness` → update. Two concurrent completions of
  different tasks in the **same room** can clobber each other's cleanliness
  delta — exactly the "preserve concurrent room-state updates" acceptance
  criterion that is unmet.
- The completion path locks `room_task`
  (`RoomService::completeRoomTask:299` via `RoomTaskMapper::lockForUpdate`)
  and then the completer's `house_user` row
  (`GameLogicService::handleTaskCompletion:93` via `findForUpdate`), but the
  **room row and the task definition are not part of any documented
  deterministic order**, and `TransactionExecutor`'s lock-order docblock
  (`lib/Db/TransactionExecutor.php:24-45`) explicitly defers the
  "completion-ledger lookup/inversion primitives" to WP5 / ADR-011.
- The teamwork bonus performs a **second unlocked RMW seam**: after
  `handleTaskCompletion` returns, `completeRoomTask` re-fetches the house
  user, `addCoins($teamworkBonus)`, and updates
  (`RoomService.php:404-413`) — outside the locked reward write and invisible
  to any future effect record.

### 1.4 Occurrence gating gaps (CR-WF-02)

- There is **no `isActive` gate**: an inactive room task (deactivated by
  `markCompleted` for one-time recurrence, or by the unrouted
  `RoomTaskMapper::deactivate:253`) can still be completed and earn rewards.
- The duplicate guard is a per-user **calendar-day** proxy
  (`countByRoomTaskAndUserOnDate`, `RoomService.php:326-334`), not
  occurrence-scoped — the masterplan requires rejecting inactive, stale,
  unauthorized, or duplicate **occurrences** before any reward, cleanliness,
  checklist, or recurrence write.
- Occurrence-scoped checklist progress is **never reset**:
  `RoomTaskChecklistProgressMapper::resetProgress` (`:228`) has **zero
  callers** in the entire codebase — a member's checked items leak into the
  next occurrence of a recurring task.

### 1.5 Post-commit ambiguity (CR-WF-02)

- The post-commit section of `completeRoomTask`
  (`RoomService.php:429-502`) defers notifications correctly (ADR-007 #2),
  but the **weekly-goal read** (`getWeeklyGoalProgress`, `:434`) and the
  **notification claim** (`claimWeeklyGoalNotification`, `:478`) are not
  failure-isolated: an exception there propagates to the controller's generic
  500 handler although the completion is already committed — the client sees
  an ambiguous failure and retries into the day-duplicate 403. The masterplan
  mandates that a post-commit failure can never turn a committed completion
  into an ambiguous API failure.

### 1.6 Frontend reconciliation and single-slot undo (CR-053-20 WP5 slice / UX-015)

- The store action `completeRoomTask` (`src/store/roomTasks.js:318-368`)
  updates only the `roomTasks` slice (not `dailyTasks`, although
  `replaceRoomTaskInState:436-451` exists and handles both), does not
  reconcile the rooms store's cleanliness or the user/game coin/streak state
  from the authoritative response, registers energy on the local scheduling
  counter (`registerTodayEnergyConsumption`, WP6 scope, untouched here), and
  emits `chorestrike:task-completed` regardless of reconciliation
  (`:343-352`) — celebrations can fire on stale caches.
- The global `UndoSnackbar` (`src/components/Common/UndoSnackbar.vue:40-70`)
  keeps **one undo slot**: each `chorestrike:task-completed` overwrites the
  previous target (`UX-015`), so a second completion within the window
  silently destroys the first undo action.
- `TodayView` restores local rows from `chorestrike:task-uncompleted`
  (`src/views/TodayView.vue:211`) — that contract is kept.

### 1.7 Test coverage cannot detect a wrong or partial inverse (CR-WF-02)

- `ConcurrentCompletionTest` covers same-user sequential accumulation
  (`:58`), the `findForUpdate` winner state (`:110`), and no-spurious-takeover
  (`:236`) — all same-**task**. There is **no** same-room cleanliness race
  test, no retry-after-post-commit-failure test, no inactive-occurrence
  rejection test, and no completion → undo state-equality test (the WP5 exit
  gate artifacts).
- E2E pins the split undo chain as the contract:
  `release-task-lifecycle.spec.ts:13-14,140,150` drives `PUT
  /tasks/{id}/uncomplete` + `POST /room-tasks/{id}/reactivate`;
  `task-completion.spec.ts:173` route-intercepts the `uncomplete` PUT. These
  must be re-pinned to the undo aggregate (no-compat rule, project-rules §1).
- `GameLogicServiceTest` / `GameLogicServiceTeamworkTest` pin the reward
  formulas — they must stay green unchanged (frozen contract).

## 2. Strategy

Record what completion changes, undo exactly that record, and make both
transitions one-lock-order aggregates with occurrence gates. Each step starts
from a failing or missing test (CS-PLAN-058 §2.3):

1. **Extend the ledger into the effect record** (decision D1): fold new
   occurrence/before-state/delta/`undone_at` columns into
   `chorestrike_completed_tasks` via the existing migration chain
   (fresh-install only) and delete the six never-written dead columns.
2. **Extract `CompletionService`** (decision D2): the complete path moves out
   of `RoomService` into a dedicated service with the documented deterministic
   lock order `room_task → room → house_user`, occurrence gates, before-state
   snapshot, checklist reset, folded teamwork award, and effect-record insert.
3. **Build the idempotent undo aggregate** (decision D3): newest-revertible
   ledger lookup, completer-or-owner gate, LIFO restore of every recorded
   before-state value in one transaction, `undone_at` marking, no-op success
   when nothing is revertible.
4. **Migrate the routes**: new `POST /room-tasks/{id}/undo`; prune
   `task#uncomplete` and `room_task#reactivate` with their service/store
   seams (decision D5).
5. **Harden post-commit isolation** (decision D7): the weekly-goal read,
   notification claims, and dispatches run inside a log-and-degrade boundary —
   a committed completion always answers 200 with a flagged degraded rewards
   payload.
6. **Reconcile the client** (decision D10): one completion/undo payload
   reconciles every owning store before any celebration event; the undo
   snackbar becomes a queued list (UX-015).
7. **Prove it**: two-connection same-room race, retry-after-post-commit-
   failure, inactive-occurrence rejection, and full completion → undo state
   equality across persisted domain fields; Jest/E2E re-pins; full §4.5 gate;
   ADR-011 + durable docs + backlog dispositions.

## 3. Backend / Frontend Changes

### 3.1 Effect-record schema and entity (CR-WF-02)

- New migration `lib/Migration/Version1029Date20260906000000.php` (folded
  into the existing chain; no compatibility/backfill work — pre-release
  fresh-install policy):
  - `chorestrike_completed_tasks` gains:
    - `occurrence_due` (date, nullable) — the house-local due date of the
      completed occurrence (ADR-009 semantics);
    - `before_state` (JSON text, not null) — authoritative pre-mutation
      snapshot;
    - `deltas` (JSON text, not null) — every applied domain delta;
    - `undone_at` (datetime, nullable) — null = revertible occurrence.
  - The never-written columns `combo_with_user_id`, `is_team_completion`,
    `participant_count`, `team_spark_bonus`, `was_claimed_completion`,
    `claim_bonus_sparks`, `days_overdue_when_claimed` are **dropped from the
    fresh schema** (§1.1); `was_combo` and `coins_earned` stay.
- `before_state` / `deltas` JSON contract (pinned by a serialization test and
  documented in ADR-011):
  - `before_state.roomTask`: `nextDue`, `idealDueDate`, `isActive`,
    `lastCompleted`, `wasClaimed`, `originalAssignee`, `claimedByUser`,
    `claimedAt`;
  - `before_state.room`: `cleanliness`;
  - `before_state.houseUser`: `coins`, `contributionScore`, `currentStreak`,
    `longestStreak`, `streakFreezes`, `lastFreezeDate`, `unlockedTitles`;
  - `before_state.checklist`: the room task's progress rows
    (`checklistItemId`, `userId`, checked state);
  - `deltas`: `coinsAwarded` (base + teamwork), `contributionAwarded`,
    `streakBefore`/`streakAfter`, `freezeUsed`, `freezeAwarded`,
    `titlesAdded[]`, `cleanlinessChange`, `takeoverClaimed`, and the reward
    summary fields the API already returns.
- `CompletedTask` entity + `CompletedTaskMapper` follow: mapper gains
  `findNewestRevertibleByRoomTask(int $roomTaskId): ?CompletedTask`
  (`undone_at IS NULL`, newest `completed_at`) and
  `markUndone(int $id, \DateTime $at): void`; the dead setters are deleted
  with their columns.
- **Undone-row read semantics (decision D11):** the three existing ledger
  reads gain the same `undone_at IS NULL` predicate —
  `countByRoomTaskAndUserOnDate` (day-duplicate guard: undo → same-day
  re-completion is a legal, non-duplicate occurrence),
  `findRecentByUserId` (combo window: an undone completion neither keeps nor
  starts a combo), and `findCompletedRoomTaskIdsBetweenByHouse` (weekly-goal
  count: undo deflates the weekly goal). An undone occurrence is not a
  completion anywhere in the domain.

### 3.2 `CompletionService` — the completion aggregate owner (CR-SVC-15/40 WP5 slice)

- New `lib/Service/CompletionService.php` (~280 lines), injected with
  `TransactionExecutor`, `RoomTaskMapper`, `TaskMapper`, `RoomMapper`,
  `HouseMapper`, `HouseUserMapper`, `TaskAssigneeMapper`,
  `CompletedTaskMapper`, `RoomTaskChecklistProgressMapper`,
  `GameLogicService`, `HouseService`, `CalendarService`, `TaskAccessPolicy`,
  `AuthorizationService`, `NotificationService`, `IL10N`, `AppLogger`
  (decision D2).
- `complete(int $houseId, string $userId, int $roomTaskId): array`:
  1. Pre-transaction resolution (existing behavior): room task + definition +
     room + house; membership lookup with the clean-403 mapping
     (`RoomService.php:265-274` pattern); house timezone via
     `CalendarService` (CS-PLAN-057 threading stays).
  2. `transactions->run(...)` with the documented order:
     - `roomTaskMapper->lockForUpdate($roomTaskId)` — **first lock**, fresh
       entity (ADR-007 #3);
     - re-resolve acting membership under the lock (403 on miss);
     - `taskAccessPolicy->assertCanView` with the canonical assignee set
       (ADR-010 matrix, 404 anti-enumeration);
      - **occurrence gates before any mutation**: `isActive = false` → 409
        "Task is not active" (decision D4); per-user calendar-day duplicate
        (counted over non-undone rows only, decision D11) → the existing 403
        message;
     - `roomMapper->lockForUpdate($roomId)` — **second lock** (new
       primitive, same platform split as `RoomTaskMapper::lockForUpdate`);
       the task definition is stable read-only input (no lock; rationale in
       ADR-011);
     - **snapshot** the full `before_state` (room task fields, cleanliness,
       completer reward fields, checklist progress rows);
     - mutate: `markCompleted` recurrence advance (unchanged entity logic),
       `checklistProgressMapper->resetProgress($roomTaskId)` — its **first
       caller** (decision D6) — cleanliness on the locked room row,
       reward phase via `GameLogicService::handleTaskCompletion`
       (**formulas untouched**), with the teamwork bonus folded into the
       reward phase (decision D9: `handleTaskCompletion` receives the
       assignee id set and performs one locked write; the second unlocked
       RMW at `RoomService.php:404-413` is deleted);
     - insert the ledger row **with** `occurrence_due`, `before_state`, and
       `deltas`;
     - commit.
  3. **Guarded post-commit boundary** (decision D7): the weekly-goal read,
     the weekly-goal notification claim, takeover/title dispatches, and
     `NotificationService` calls run inside one try/catch that logs
     (`AppLogger`, stable keys) and degrades — `rewards.weeklyGoal = null`
     plus `rewards.degraded = true` on partial failure. The controller
     **never** receives an exception after commit.
  4. Response: the existing shape (`roomTask`, `room`, `task`, `rewards`)
     plus `rewards.totalCoins`, `rewards.totalContribution`,
     `rewards.longestStreak` (already computed in the game result) so owning
     stores reconcile authoritative values (decision D10).
- `RoomService::completeRoomTask` and its checklist-progress/transaction
  plumbing move; `RoomService` keeps `updateRoomCleanliness` only for its
  remaining non-completion callers (if none remain, it is deleted — verified
  at implementation). `RoomTaskService::completeTask` (`:495-497`) is
  deleted; `RoomTaskController::complete` calls `CompletionService`
  directly.

### 3.3 The undo aggregate (CR-WF-02)

- `CompletionService::undo(int $houseId, HouseUser $actor, int $roomTaskId):
  array`:
  1. Resolve the room task + definition; `assertCanView` first — 404
     anti-enumeration for invisible ids (ADR-010).
  2. **Actor gate** (masterplan D3, pre-ratified): the acting user must be
     the recorded completer **or** a house owner (`AuthorizationService`
     owner assert); a child may undo their **own** completion (child-friendly
     recovery) but not someone else's; otherwise 403.
  3. Idempotency: `findNewestRevertibleByRoomTask` → no row means "nothing
     to undo"; return 200 with the current `{roomTask, room, task}` and
     `undone: false` — **zero writes** (decision D3).
  4. `transactions->run(...)` in the same documented order:
     - lock the room task (first lock), re-check the chosen ledger row is
       still revertible under the lock (a concurrent undo winner makes this
       the idempotent no-op);
     - lock the room row; lock the **completer's** `house_user` row (the
       actor may differ from the completer);
     - **restore, never recalculate** (masterplan D2): assign every
       `before_state.roomTask` field (nextDue, idealDueDate, isActive,
       lastCompleted, claim fields), room cleanliness, completer
       coins/contribution/streak/longestStreak/freezes/lastFreezeDate, and
       remove exactly the `deltas.titlesAdded` keys still present in
       `unlockedTitles`; re-insert the snapshotted checklist progress rows;
     - `markUndone($ledgerRowId, now)`;
     - commit.
  5. Response: `{roomTask, room, task, rewards: {reverted deltas…}}` — the
     same owning-store reconciliation inputs as completion.
- **Documented non-retractions** (ADR-011, decision D8): medals already
  awarded, the house-level `weekly_goal_notified_week` claim, and dispatched
  notifications are not reverted — undo reverses recorded domain deltas, not
  derived side-channel history.

### 3.4 Routes and controllers (CR-WF-02)

- New: `['name' => 'room_task#undo', 'url' => '/api/v1/room-tasks/{id}/undo',
  'verb' => 'POST']` — `RoomTaskController::undo` with the standard envelope,
  404/403/409 mapping, and `logControllerException` hygiene.
- Pruned (decision D5): `task#uncomplete` (`appinfo/routes.php:38`,
  `TaskController::uncomplete`, `RoomTaskService::uncompleteTask`,
  store action `uncompleteRoomTask`) and `room_task#reactivate`
  (`appinfo/routes.php:56`, `RoomTaskController::reactivate`,
  `RoomTaskService::reactivateTask`, `RoomTaskMapper::reactivate`,
  store action `reactivateRoomTask`) — after §3.5 the only consumers are the
  undo aggregate and re-pinned E2E specs. `RouteMatrixTest` and the Jest
  `apiContract.spec.js` route map are updated in the same change
  (CS-PLAN-055 no-consumer route policy).
- Error semantics: 404 anti-enumeration (invisible room task, unknown id),
  403 (non-member, actor gate, day-duplicate), 409 (inactive occurrence),
  standard API envelope everywhere.

### 3.5 Frontend reconciliation and undo queue (CR-053-20 WP5 slice / UX-015)

- `src/store/roomTasks.js`:
  - `completeRoomTask`: after the one POST, reconcile **every owning store
    before emitting** — `roomTasks` + `dailyTasks` via
    `replaceRoomTaskInState`, the rooms store's cleanliness from
    `data.room`, the game/user coin/streak state from the extended
    `rewards` totals (decision D10); only then emit
    `chorestrike:task-completed` / `streak-freeze-used` / `title-unlocked`
    (names unchanged — frozen event vocabulary). The scheduling-counter
    registration stays (WP6 owns the reader-less path).
  - `undoRoomTaskCompletion` becomes **one** `POST /room-tasks/{id}/undo`;
    it applies the authoritative payload to the same owning stores and emits
    `chorestrike:task-uncompleted` with the final room task (TodayView's
    restore contract is preserved). `uncompleteRoomTask` and
    `reactivateRoomTask` are deleted.
- `src/components/Common/UndoSnackbar.vue`: the single slot becomes a
  **queued list** (UX-015 resolution): each completion pushes an entry with
  its own countdown; entries are individually dismissible/undoable, capped at
  a small visible maximum, keyboard-operable, with 44 px targets and
  localized labels — a second completion no longer destroys the first undo
  action. No reward internals are shown (copy stays "Task completed / Undo").
- `src/views/{TodayView,TaskManager,RoomDetail}.vue`: completion handlers
  consume the reconciled store state (no per-view cache mutation of their
  own beyond what exists); TodayView's undo restore path is unchanged.
- All new/changed strings through `t()`; `l10n/` artifacts updated in the
  same change.

### 3.6 Durable docs

- New `docs/adr/ADR-011-transactional-completion-and-undo.md` (path fixed by
  the CS-PLAN-058 §5 manifest): effect-record design (before-state/deltas
  JSON contract), the deterministic lock order `room_task → room →
  house_user` and its deadlock argument against setup/creation/assignment
  usage, the **ratified deviation from the masterplan WP5 lock wording** —
  the task definition is deliberately not locked (stable read-only input;
  decision D12), occurrence gates, the undone-row read semantics (decision
  D11), post-commit degradation boundary, undo
  idempotency/LIFO/actor policy, documented non-retractions, and the
  frozen-formula statement.
- `docs/adr/ADR-007` gains an annotation pointing to ADR-011 (the ledger
  refines, not replaces, the lock doctrine).
- `lib/Db/TransactionExecutor.php` lock-order docblock: completion/undo
  usage note replaces the "arrives with WP5" placeholder.
- `docs/engineering/development.md` ("Locking and transactions"):
  completion/undo order; `docs/api/README.md`: complete/undo endpoints and
  payload; `docs/concepts/task-model-and-sync.md`: completion/undo model.

## 4. Tests

- **Unit (PHPUnit) — new `CompletionServiceTest`; updated
  `GameLogicServiceTest`, `RoomTaskServiceTest`, `RoomTaskControllerTest`,
  `TaskControllerTest`, `RoomServiceTest`, `RouteMatrixTest`,
  `CompletedTaskMapperTest`:**
  - Gate order: inactive occurrence → 409 **before** any mapper mutation
    call; day-duplicate → 403; non-member → 403; invisible id → 404;
    actor gate (member ≠ completer → 403; owner → allowed; child-completer
    undoing own completion → allowed).
  - Snapshot discipline: `before_state`/`deltas` captured from pre-mutation
    values; ledger insert carries `occurrence_due`; checklist
    `resetProgress` called inside the transaction (first-caller pin).
  - Rollback sabotage: a mapper mock throwing after the ledger insert →
    `rollBack()`, no commit, zero reward/cleanliness/checklist writes.
  - Post-commit degradation: sabotage `getWeeklyGoalProgress` → response is
    still success with `rewards.weeklyGoal = null`, `rewards.degraded = true`,
    warning logged; no exception escapes.
  - Undo: newest-revertible selection; under-lock revertible re-check makes
    the concurrent-winner path a no-op 200; restore assigns recorded
    before-state values (mock mapper call order: lock room task → lock room
    → lock completer house_user → restores → markUndone);
    `titlesAdded` removal only for keys still present; no revertible row →
    200 zero-write.
  - Pruned routes gone from `RouteMatrixTest`; controller envelope/status
    mapping for `room_task#undo`; teamwork fold: one locked house-user write
    in `handleTaskCompletion` (mock verifies no second update).
  - D11 filters: day-duplicate guard, combo window, and weekly-goal count
    each exclude `undone_at`-marked rows (mapper-level query pins + a
    service-level undo → same-day re-complete → 200 case).
- **Integration (Docker, real DB) — new `CompletionUndoStateEqualityTest`,
  new `CompletionConcurrencyTest`; extended `ConcurrentCompletionTest`:**
  - **State equality** (WP5 exit gate): snapshot every persisted domain row
    (`room_tasks`, `rooms`, `house_users`, `chorestrike_completed_tasks`,
    `chorestrike_chk_progress`) before completion and after undo → exact
    equality, for `once`/recurring/dynamic/static/flex permutations and a
    takeover completion (claim fields restored); a complete → undo →
    same-day re-complete → undo roundtrip proves the D11 duplicate-guard
    filter on the real DB (two ledger rows, exactly one revertible at a
    time, final state equals the initial snapshot).
  - **Reward-side equality**: coins/contribution/streak/freezes/longest
    streak/`unlockedTitles` before vs after undo; a later completion's
    title unlocks are not removed by the earlier undo (LIFO interplay).
  - **Checklist**: progress rows cleared at completion, restored by undo.
  - **`CompletionConcurrencyTest`** (proc_open two-connection pattern,
    MySQL-only documented skip in the established wording): (a) two
    different tasks in the **same room** completed concurrently →
    cleanliness equals the sum of both deltas (fails on the unlocked RMW);
    (b) retry-after-post-commit-failure: force the weekly-goal read to
    throw → API 200, then a retried completion hits the day-duplicate 403
    with **no second ledger row**; (c) concurrent undo of the same
    occurrence → exactly one winner, loser gets the idempotent no-op.
  - Inactive-occurrence rejection: deactivated row → 409, zero writes.
- **Jest — `roomTasks.spec.js`, `UndoSnackbar.spec.js`,
  `apiContract.spec.js`, `TodayView.spec.js`:**
  - Store: completion reconciles roomTasks/dailyTasks/rooms/game state
    **before** emitting (event spy ordering); undo is one POST, applies the
    authoritative payload, emits `chorestrike:task-uncompleted`;
    `uncompleteRoomTask`/`reactivateRoomTask` are gone (specs re-pinned, not
    shimmed).
  - `UndoSnackbar`: two completions → two queue entries, both independently
    undoable within their windows; per-entry dismissal; a11y assertions
    (role, labels, 44 px class).
  - `apiContract`: new undo route, pruned routes removed.
- **E2E (Playwright) — re-pinned `release-task-lifecycle.spec.ts`,
  `task-completion.spec.ts`; extended `task-completion-full-flow.spec.ts`,
  `teamwork-rewards.spec.ts`, `today-view.spec.ts`:**
  - The lifecycle spec drives the new `POST /room-tasks/{id}/undo` (the
    split chain header comment is rewritten); the interception spec matches
    the undo route.
  - Full-flow: complete → assert exact UI state (row, coins, streak,
    cleanliness display) → undo → assert state equality **after reload**
    (no stale cache), recurrence display restored.
  - Queue journey: complete two tasks → both undo entries visible → undo
    both → both rows restored (UX-015 proof).
  - `teamwork-rewards.spec.ts` completion endpoint is unchanged; assertions
    stay green (formula freeze proof).
- **Gate (CS-PLAN-058 §4.5):** full gate including Docker unit +
  integration, `composer cs:check`, all applicable Playwright projects; no
  new documented skips beyond the established MySQL-only race-guard
  inventory.

## 5. File Manifest

```
new:      docs/adr/ADR-011-transactional-completion-and-undo.md
new:      lib/Service/CompletionService.php
new:      lib/Migration/Version1029Date20260906000000.php
new:      tests/unit/Service/CompletionServiceTest.php
new:      tests/integration/Service/CompletionUndoStateEqualityTest.php
new:      tests/integration/Service/CompletionConcurrencyTest.php
modified: lib/Db/CompletedTask.php (effect fields; dead columns/setters removed)
modified: lib/Db/CompletedTaskMapper.php (findNewestRevertibleByRoomTask, markUndone)
modified: lib/Db/RoomMapper.php (lockForUpdate primitive)
modified: lib/Db/RoomTaskMapper.php (reactivate deleted)
modified: lib/Db/RoomTaskChecklistProgressMapper.php (restore helper if row re-insert needs one)
modified: lib/Db/TransactionExecutor.php (lock-order docblock completion/undo note)
modified: lib/Service/RoomService.php (completeRoomTask + completion plumbing removed)
modified: lib/Service/RoomTaskService.php (completeTask/uncompleteTask/reactivateTask deleted)
modified: lib/Service/GameLogicService.php (teamwork fold into handleTaskCompletion; no formula changes)
modified: lib/Controller/RoomTaskController.php (undo endpoint; complete routes to CompletionService; reactivate pruned)
modified: lib/Controller/TaskController.php (uncomplete pruned)
modified: appinfo/routes.php (room_task#undo added; task#uncomplete + room_task#reactivate pruned)
modified: src/store/roomTasks.js (reconciliation-first complete; one-request undo; deleted actions)
modified: src/store/{rooms,game}.js (cleanliness/reward reconciliation hooks only, if needed)
modified: src/components/Common/UndoSnackbar.vue (queued entries)
modified: src/views/{TodayView,TaskManager,RoomDetail}.vue (completion/undo handlers on reconciled state)
modified: tests/unit/Service/{GameLogicServiceTest,RoomTaskServiceTest,RoomServiceTest}.php
modified: tests/unit/Controller/{RoomTaskControllerTest,TaskControllerTest}.php
modified: tests/unit/RouteMatrixTest.php · tests/unit/Db/Mapper/CompletedTaskMapperTest.php
modified: tests/integration/Service/ConcurrentCompletionTest.php (extended)
modified: tests/unit/store/roomTasks.spec.js · tests/unit/components/UndoSnackbar.spec.js
modified: tests/unit/store/apiContract.spec.js · tests/unit/components/TodayView.spec.js
modified: tests/e2e/{release-task-lifecycle,task-completion,task-completion-full-flow,teamwork-rewards,today-view}.spec.ts
modified: docs/adr/ADR-007-concurrency-safe-task-completion.md (annotation)
modified: docs/engineering/development.md · docs/api/README.md · docs/concepts/task-model-and-sync.md
modified: l10n/{en.json,de.json,templates/chorestrike.pot}
modified: docs/backlog/{backlog.md,ACTIVE_TASKS.md} (+ archive/2026-09.md entries — owned by the Standard B review)
modified: docs/plans/active/cs-plan-058-core-workflow-bottom-up-masterplan.md (§9 progress row WP5)
modified: docs/plans/active/cs-plan-064-wp5-completion-ledger-full-undo.md (this plan; moved by the Standard A review)
```

> **Manifest amendment (2026-09-09, steps 7–10 round):** `src/store/user.js`
> and `src/store/minigame.js` joined the reconcile-hook set ("game/user
> coin/streak state", D10 — they own the only live user-progress/coin-wallet
> UI state); the undo `rewards` payload gained `completerUserId` and the
> post-undo `weeklyGoal` (D10 inputs so the client can tell whose state
> totals describe); `docs/testing/release-test-plan.md` rows and the stale
> `game.js` docblocks updated; incidentally-fixed pre-existing WP4-era E2E
> debt: `tests/e2e/release-task-templates.spec.ts`,
> `tests/e2e/fixtures/test-house.ts`, `tests/e2e/room-management.spec.ts`
> (see RF-03). `teamwork-rewards.spec.ts` stayed untouched — its
> assertions passed unchanged (formula-freeze proof).

## 6. Implementation Order

1. Failing tests first: occurrence-gate, snapshot/ledger, actor-gate, and
   idempotency unit cases; store reconciliation-order and undo-queue Jest
   cases (red).
2. §3.1 schema/entity/mapper (migration fold, effect columns, dead-column
   drop, revertible lookup); migration smoke + mapper unit green.
3. §3.2 `CompletionService::complete` with lock order, gates, snapshot,
   checklist reset, teamwork fold; rewire `RoomTaskController::complete`;
   unit + controller green.
4. §3.3 undo aggregate + §3.4 routes (add `undo`, prune `uncomplete`/
   `reactivate`); `RouteMatrixTest`/`apiContract` updated; unit green.
5. §3.2 step 3 post-commit guard (degrade-not-fail); unit green.
6. Real-DB integration: state-equality matrix, checklist reset/restore,
   `CompletionConcurrencyTest` (same-room race, post-commit retry, undo
   race), inactive rejection — Docker green.
7. §3.5 frontend: reconciliation-first completion, one-request undo, queued
   `UndoSnackbar`; Jest green.
8. E2E re-pins and the queue/full-flow journeys; `chromium`,
   `chromium-serial-db`, `chromium-scheduling-owner` green.
9. Full §4.5 gate; scoped `npm run lint:fix` beforehand.
10. §3.6 durable docs (ADR-011 first); backlog dispositions (CR-WF-02,
    UX-015, CR-053-20 WP5 slice, CR-SVC-15/40 WP5 slices), CS-PLAN-058 §9
    WP5 row, `ACTIVE_TASKS.md`; request Standard B review.

## 7. Risks & Mitigations

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Completion/undo deadlocks against setup/creation/assignment locks | Medium | Documented order `room_task → room → house_user` extends the existing `house → room_task → dependents` doctrine without cycles (completion never takes the house lock; assignment shares `room_task` as first lock); two-connection race tests; ADR-011 deadlock argument |
| Effect-record JSON drifts from the restore logic | High | One serialization contract test pins the JSON shape; restore reads through typed accessors; ADR-011 owns the schema |
| Locking the room row serializes same-room completions | Low | Household-scale rates; short critical section (ADR-007 doctrine); same-room race test proves correctness, not just serialization |
| Undo semantics edge cases (titles re-earned later, streak recomputation temptation) | High | Recorded-deltas-only rule (masterplan D2): assign before-state, never recalculate; `titlesAdded` removal only for keys still present; documented non-retractions (D8) |
| 409 for inactive occurrences breaks optimistic UI double-click paths | Medium | Day-duplicate double-click keeps its 403; 409 only for genuinely inactive rows; E2E asserts the toast copy; client treats 409 as refresh-trigger |
| Post-commit degradation hides real failures | Medium | `rewards.degraded` flag + `AppLogger` warning with stable keys; integration test pins log emission; never a silent swallow (project-rules §3) |
| Pruning `uncomplete`/`reactivate` breaks hidden consumers | Low | Grep-verified inventory (store + 2 E2E specs, §1.2/§1.7); migration in the same change; `RouteMatrixTest` + `apiContract.spec.js` guard drift |
| Undo-queue UI grows beyond a snackbar | Medium | Small visible cap, stacked entries, existing design tokens; a11y + 44 px asserted in Jest/E2E |
| Teamwork fold changes reward outcomes | Low | Fold moves **where** the bonus is written (one locked write), not the amount; `GameLogicServiceTeamworkTest` math unchanged; integration asserts identical totals |
| Scope creep into WP6/WP7/WP8 | Medium | §Scope guard + manifest freeze: scheduling counter untouched, canonical payload redesign untouched, GameLogicService split untouched |
| Fresh-schema column drop loses seeded test history | Low | Disposable test DBs (`down -v` + `up -d`); pre-release no-backcompat policy |

## 8. Acceptance Criteria

- [x] A completion writes one immutable ledger row carrying `occurrence_due`,
      the full `before_state`, and every applied `delta`; the never-written
      legacy columns are gone from the fresh schema.
- [x] Completion validates and locks one active occurrence and every
      reward-driving input before mutation, in the documented order
      `room_task → room → house_user`; inactive occurrences answer 409 and
      day duplicates 403 with zero writes; concurrent same-room completions
      cannot lose room state (two-connection proof).
- [x] Recurrence advance and occurrence-scoped checklist reset happen inside
      the same transaction (`resetProgress` gains its first caller and test).
- [x] Undone ledger rows are excluded from the day-duplicate guard, the
      combo window, and the weekly-goal count (decision D11): undo →
      same-day re-complete succeeds with a fresh ledger row; each filter is
      pinned by a test.
- [x] Completion success remains unambiguous when post-commit effects fail:
      the API answers 200 with `rewards.degraded = true` and a logged
      warning; a retried completion hits the day-duplicate 403 with no
      second ledger row.
- [x] One idempotent undo operation restores recurrence, ledger marking,
      rewards/streak/freezes/titles, cleanliness, and checklist progress
      from the recorded effect — proven by exact before/after state equality
      across all persisted domain fields for the recurrence permutation
      matrix; completer-or-owner actor policy enforced; a no-op undo makes
      zero writes.
- [x] Existing reward formulas, event names (`chorestrike:task-completed`
      et al.), title/medal/notification contracts, minigames, and
      celebration behavior remain regression-green.
- [x] The frontend consumes one completion/undo payload, reconciles every
      owning store before emitting celebration events, and the undo
      snackbar queues multiple entries (UX-015) — both pinned in Jest and
      E2E including a reload-based state-equality assertion.
- [x] `PUT /tasks/{id}/uncomplete`, `POST /room-tasks/{id}/reactivate`,
      and their service/store seams are gone; `RouteMatrixTest` and
      `apiContract.spec.js` reflect the new contract.
- [x] WP5 exit gate met: real two-connection tests cover same-task and
      same-room concurrency, retry after post-commit failure, inactive
      occurrence rejection, and complete completion → undo state equality.
- [x] All new/changed strings localized; standard API envelope everywhere;
      `l10n:check-keys` green.
- [x] Build gate green (CS-PLAN-058 §4.5) with no new or unapproved skips.
- [x] Owning backlog items dispositioned: CR-WF-02 criteria checked and the
      item archived when fully delivered; UX-015 archived; CR-053-20 WP5
      slice annotated (WP7 remainder stays); CR-SVC-15/40 WP5 slices
      annotated (WP8 remainder stays); CS-PLAN-058 §9 WP5 row updated.
- [x] Durable docs updated (ADR-011, ADR-007 annotation, locking/api/concept
      docs) — plans are temporary.
- [x] All implementation decisions and review questions resolved and
       recorded.
- [x] Final Standard B review is `PASS` or `PASS-WITH-FIXES`;
      `ACTIVE_TASKS.md` updated.

## 9. Plan Review Decision Register

The Standard A review must ratify or revise these proposed decisions before
activating the plan:

| ID | Proposed decision | Rationale | Status |
|----|-------------------|-----------|--------|
| D1 | The effect record lives **on the `chorestrike_completed_tasks` ledger row** (JSON `before_state`/`deltas` + `occurrence_due` + `undone_at`), not in a separate table; the seven never-written columns are dropped | One occurrence = one ledger row = one undo unit; avoids a join and a second insert inside the hot path; fresh-install column drop is free pre-release | Ratified 2026-09-06 |
| D2 | A new `CompletionService` owns complete + undo; `RoomService::completeRoomTask`, `RoomTaskService::completeTask/uncompleteTask/reactivateTask` are deleted (callers rerouted) | CR-SVC-15/40 WP5 slices name the completion path for extraction; mirrors the `TaskCreationService`/`HouseSetupService` aggregate precedents; keeps `RoomTaskService` from growing before WP8 | Ratified 2026-09-06 |
| D3 | Undo actor gate is completer-or-house-owner (masterplan D3, pre-ratified); undo is idempotent — no revertible row returns a zero-write 200 with `undone: false` | Child-friendly self-recovery without making arbitrary users mutation principals; server-side idempotency is the masterplan mandate | Ratified 2026-09-06 |
| D4 | Occurrence gates: inactive → **409** with a localized message; per-user day duplicate keeps its 403; visibility denials keep the ADR-010 404/403 standard | 409 distinguishes "state conflict" from "forbidden" and has app precedent (WP3 setup recovery); existing tested semantics are not churned | Ratified 2026-09-06 |
| D5 | `POST /room-tasks/{id}/undo` replaces the split chain; `task#uncomplete` and `room_task#reactivate` are pruned with their seams | One aggregate endpoint per concept; both legacy routes lose their last consumer (grep-verified: store chain + 2 E2E specs); CS-PLAN-055 route policy | Ratified 2026-09-06 |
| D6 | Completion resets occurrence-scoped checklist progress (`resetProgress` first caller) and the undo restores the snapshotted rows | Masterplan WP5: "reset occurrence-scoped checklist progress" atomically; `resetProgress` exists unused | Ratified 2026-09-06 |
| D7 | Post-commit effects run inside a log-and-degrade boundary: committed completions always answer 200, `rewards.weeklyGoal = null` + `rewards.degraded = true` on failure | Masterplan: a post-commit failure must not turn a committed completion into an ambiguous API failure; degradation stays observable (flag + log), never silent | Ratified 2026-09-06 |
| D8 | Undo restores recorded deltas only; medals, the weekly-goal notification claim, and dispatched notifications are documented non-retractions | Masterplan D2 ("undo reverses recorded deltas; it does not recalculate policy"); side-channel history is not domain state | Ratified 2026-09-06 |
| D9 | The teamwork bonus is folded into `GameLogicService::handleTaskCompletion` as one locked write (amount formula unchanged); the second unlocked RMW is deleted | Removes the unlocked RMW seam (§1.3) and makes the bonus effect-record-visible; `calculateTeamworkBonus` and its tests stay intact | Ratified 2026-09-06 |
| D10 | Completion/undo responses gain `totalCoins`/`totalContribution`/`longestStreak` (already computed) and the client reconciles owning stores before emitting events; canonical payload redesign stays WP7 | WP5's mandate is reconciliation-from-authoritative-response; full enriched-payload contracts remain WP7 scope (scope guard) | Ratified 2026-09-06 |
| D11 | Undone ledger rows are excluded (`undone_at IS NULL`) from the day-duplicate guard, the combo window, and the weekly-goal count — an undone occurrence is not a completion anywhere in the domain | Undo → same-day re-complete is the core misclick-recovery flow (masterplan D3) and must not 403; undone rows must not keep combos alive or inflate the weekly goal; one predicate per query, pinned by tests | Ratified 2026-09-06 (Standard A review Q2) |
| D12 | The task definition is deliberately **not** locked; the completion lock order stays `room_task → room → house_user` | Ratified deviation from the literal masterplan WP5 lock wording: the definition is stable read-only input, read once before mutation; locking it would contend with edit paths for no integrity gain. ADR-011 documents the deviation and its rationale | Ratified 2026-09-06 (Standard A review Q3) |

Any rejected decision requires this plan, its affected backlog acceptance
criteria, and the corresponding risk/test sections to be updated before the
review can pass.

## 10. Review Outcome

**Standard A plan review — 2026-09-06 — `PASS-WITH-FIXES` (GO).**

- **Scope:** this plan file only (untracked in `new/` at review start).
  **Base:** `master` @ `b78c279`; reviewed-file SHA256
  `66E41AC8E2551FB5FAEFE715417FDEE98153539619DC432B386EC2955FF78E3A`
  (pre-fix). Unrelated dirty working-tree files (a `cs-plan-063` mojibake
  repair) were preserved unstaged and excluded from the review commit.
- **Verification:** ~20 factual claims spot-checked against the live tree and
  all confirmed — dead ledger columns (docblock-only setters), zero-caller
  `resetProgress`, the split `uncomplete`+`reactivate` undo chain and its
  complete consumer inventory, single-slot `UndoSnackbar`, unlocked
  cleanliness RMW + teamwork RMW seams, non-isolated post-commit reads,
  migration-chain head (`Version1028`), ADR-011 path free, masterplan WP5
  mandate/D2/D3 alignment, §4.5 gate, backlog ownership (CR-WF-02, UX-015,
  CR-053-20, CR-SVC-15/40), E2E pin references, and no overlap with the sole
  active plan (CS-PLAN-058 masterplan).
- **Findings & fixes:**
  1. *Major (fixed via D11):* `undone_at` read semantics unspecified — the
     day-duplicate guard, combo window, and weekly-goal count would treat
     undone rows as completions (undo → same-day re-complete 403, stale
     combos, inflated weekly goal). Resolved by user decision: filter
     `undone_at IS NULL` in all three; §3.1/§3.2/§3.6/§4/§8 updated.
  2. *Minor (fixed inline):* two wrong manifest test paths
     (`tests/unit/Db/Mapper/CompletedTaskMapperTest.php`,
     `tests/unit/components/TodayView.spec.js`) and the CS-PLAN-058
     relative link repaired for the plan's `active/` location after the
     move (`backlog:check` link gate).
  3. *Minor (deferred with backlog item):* the manual cleanliness endpoint
     (`RoomController.php:330` → `updateRoomCleanliness`) stays an unlocked
     RMW and can race the new room-row lock — out of WP5 scope per the scope
     guard; logged as **CR-SVC-63** (user decision).
  4. *Minor (ratified via D12):* task-definition no-lock deviates from the
     literal masterplan WP5 lock wording; ratified with ADR-011 rationale.
  5. *Nits (no action):* `complete()`/`undo()` signature asymmetry
     (`string $userId` vs `HouseUser $actor`) and the undo-queue cap
     eviction policy are implementation details within plan granularity.
- **Decisions:** D1–D10 ratified as proposed; D11 and D12 added and
  ratified (batched structured questions, all answered with the recommended
  option, 2026-09-06).
- **Gates:** docs-only change — `npm run docs:encoding` and
  `npm run backlog:check` green (no code touched; build/test gates apply to
  the implementation phase per §4.5).
- **Transition:** plan moved `new/ → active/`; owning backlog items marked
  In Progress; `ACTIVE_TASKS.md` updated; one scoped review commit on
  `master`.

---

**Interim Standard B code review — 2026-09-09 — `PASS-WITH-FIXES` (interim;
plan stays `active/`).**

- **Scope:** the uncommitted working-tree backend slice — implementation
  order steps 1–5 (schema/entity/mapper, `CompletionService` complete+undo,
  route migration, post-commit guard) plus the unit-test round: 30 modified
  tracked files (+805/−2280) and 3 untracked new files. **Base:** `master` @
  `ad3e1fb`; pre-fix unstaged-diff SHA256
  `1222C2CA70ABD2E8167A982FAED009969EAEE3F46D3C62142BD457B8411C65D1`;
  untracked hashes: migration `36859095…D76D7`, `CompletionService.php`
  `C2D24156…13B20`, `CompletionServiceTest.php` `4399C0FF…F8AC`. The
  unrelated dirty `docs/plans/done/cs-plan-063` mojibake repair was
  preserved unstaged and excluded from the review commit. Steps 6–10
  (real-DB integration suites, frontend, E2E, durable docs, backlog
  dispositions) were verified **not started** — this review is interim and
  proves nothing about plan completion.
- **Findings & fixes:** see `## 11. Review Findings`. Headline: one **block**
  migration defect (dead-column drops without their dependent index drops →
  MariaDB 1072, fresh install dead, all Docker gates unrunnable) fixed with
  user authorization; l10n artifacts for the two new strings added; unused
  `AuthorizationService` injection removed; six raw ledger-seed fixtures
  missing the new NOT NULL columns repaired; six stale controller
  construction sites rewired; `TaskLifecycleVisibilityApiTest` re-pinned
  from the pruned `uncomplete`/`reactivate` surface to the undo aggregate
  (D5, re-pin not shim); `GameLogicService` legacy self-insert made
  schema-safe; stale docblocks corrected.
- **Decisions (batched structured questions, both answered with the
  recommended option, 2026-09-08/09):** (1) fix the block migration defect
  now in `Version1029` (drop `idx_team_complete`/`idx_claimed_complete`
  before the columns); (2) interim reviewed-package commit — commit the
  verified backend slice + review evidence with the red Jest `apiContract`
  guard recorded as a plan-owned finding (frontend round pending), plan
  stays `active/`.
- **Gates:** ESLint green · PHPUnit unit **844 green** · PHPUnit integration
  **278 green** (10 skips = pre-existing documented inventory, unchanged) ·
  `composer cs:check` green (after a scoped fix of 4 reviewed files: PHPDoc
  alignment + CRLF normalization) · `l10n:check-keys` green · Docker fresh
  install + migration green after the F1 fix · Jest **814/815** —
  `apiContract.spec.js` red by design mid-implementation (store still calls
  the pruned routes until §3.5 lands; plan-owned finding RF-01) · Playwright
  not run (E2E re-pins are step 8, pending).
- **Transition:** none — interim review. One scoped review commit on
  `master`; `ACTIVE_TASKS.md` updated; review record
  `docs/backlog/archive/CODE_REVIEW_2026-09-09.md`.

---

**Interim Standard B code review (step-6 round) — 2026-09-09 — `PASS`
(interim; plan stays `active/`).**

- **Scope:** the uncommitted step-6 round — RF-02 real-DB suites
  (`CompletionUndoStateEqualityTest`, `CompletionConcurrencyTest`,
  `ConcurrentCompletionTest` two-process same-room race), the RF-05 undo
  deleted-completer edge, and the RF-12 `lastCleaned`/`lastActivity`
  snapshot/restore: 8 modified tracked files + 2 untracked new suites.
  **Base:** `master` @ `61c3280`; index clean at capture; pre-review
  unstaged-diff SHA256
  `008E502B81DEC6E10319D627EA0E02FC62574E218116B5BE12713EE7EED752D5`;
  untracked hashes: `CompletionConcurrencyTest.php` `67B1A3BB…5BEA0`,
  `CompletionUndoStateEqualityTest.php` `79D28A97…C785E4`. The unrelated
  dirty `docs/plans/done/cs-plan-063` mojibake repair was preserved from
  the review set and committed separately on explicit user request.
- **Verification:** RF-02/RF-05/RF-12 resolutions verified against the §4
  test plan — exit-gate exact-state matrix (once/dynamic/static/flex,
  takeover, D11 roundtrip, title LIFO), deterministic + true two-process
  race proofs, inactive-409 zero-write, unit roundtrip and RF-05 pins;
  **no new findings**. Steps 7–10 (RF-01 frontend, RF-03 E2E, RF-04
  durable docs) remain open — interim, no completion claim.
- **Gates:** PHPUnit unit **847 green** · PHPUnit integration **290 green**
  (10 skips = pre-existing documented inventory; the MySQL-only race tests
  ran) · `composer cs:check` green · ESLint green · Jest **814/815** —
  `apiContract.spec.js` red by design (RF-01, unchanged mid-implementation
  state, no new skips) · `l10n:check-keys` + `backlog:check` green ·
  Playwright not run (E2E re-pins are step 8).
- **Transition:** none — interim review; RF-01/RF-03/RF-04 keep the plan
  in `active/`. One scoped review commit on `master`; review evidence
  appended to `docs/backlog/archive/CODE_REVIEW_2026-09-09.md`.

---

**Final Standard B code review (steps 7–10 round) — 2026-09-09 —
`PASS-WITH-FIXES` (one stale plan-text reference fixed; plan complete;
moved to `done/`).**

- **Scope:** the uncommitted steps 7–10 round plus whole-plan completion
  verification: frontend reconciliation + one-request undo + queued
  `UndoSnackbar` (RF-01), E2E re-pins and the new full-flow/today-view
  journeys (RF-03), ADR-011 + durable-doc integration and backlog
  dispositions (RF-04), and the `revertedRewards` `completerUserId` +
  post-undo `weeklyGoal` amendment — 33 modified tracked files
  (+1111/−390) and 1 untracked new file. **Base:** `master` @ `2393686`;
  index clean at capture; pre-review unstaged binary-diff git-hash
  `ae64724f5707a2df7966622477a713786132d371`; untracked ADR-011 SHA256
  `64AC9F7D…1128`. No unrelated dirty files.
- **Verification:** full §4.5 gate rerun by the review (see Gates); every
  §8 acceptance criterion re-checked against the tree (effect record,
  lock order, occurrence gates, D11 filters, degradation boundary, exact
  inverse with state-equality proofs, frozen formulas/events, one-payload
  reconciliation before events, pruned routes gone from
  `RouteMatrixTest`/`apiContract`, l10n, gate, dispositions, durable
  docs); RF-01..RF-12 all resolved; manifest + amendment reconciled with
  the working tree; every `plans/active/cs-plan-064` link repaired for
  the `done/` move.
- **Findings & fixes:** one **minor** (fixed in review): the RF-04
  resolution text cited a "CR-SVC-64" backlog item that was never
  created — the title-celebration dead-event finding was actually folded
  into CR-WF-04 (correct routing); the plan text is corrected in place.
  One **nit (no action):** the sole stylelint warning
  (`NotAuthorizedState.vue` `margin-left`) is pre-existing in an
  unmodified file and already tracked as TECH-LINT-007.
- **Decisions:** none required — no security/auth, behavior-changing, or
  architectural question arose; all twelve register decisions stand
  ratified and implemented.
- **Gates (rerun by the review, final state):** Jest **820/820** (81
  suites) · PHPUnit unit (Docker) **847 green**, 2523 assertions ·
  PHPUnit integration (Docker) **290 green**, 10 pre-existing documented
  skips · `composer cs:check` **0/280** · ESLint green · stylelint 0
  errors (1 pre-existing TECH-LINT-007 warning) · `l10n:check-keys` ·
  `docs:encoding` (670 files) · `backlog:check` · `test:noise` ·
  webpack production build · `git diff --check` all green · Playwright
  full pool **twice**: 385 passed / 0 failed / 26 skips — all 26
  verified as the pre-existing data-conditional guard inventory
  ("No active tasks", "No overdue …", "No Kitchen room", etc.); every
  new CS-PLAN-064 journey (lifecycle one-aggregate + idempotent no-op,
  full-flow reload equality, today-view UX-015 queue) passed in both
  runs.
- **Transition:** plan moved `active/ → done/`; CS-PLAN-058 §9 WP5 row
  closed; CR-WF-02 + UX-015 archived (2026-09.md); CR-053-20 /
  CR-SVC-15 / CR-SVC-40 WP5 slices annotated (remainders stay WP7/WP8);
  `ACTIVE_TASKS.md` moved to Recently Completed; ADR-011 +
  archive/2026-09 links repaired to the `done/` path; one scoped review
  commit on `master`; review evidence appended to
  `docs/backlog/archive/CODE_REVIEW_2026-09-09.md`.

## 11. Review Findings (plan-owned)

Scope-first routing (project-rules §4): every open finding below lies inside
this plan's scope and blocks the `done/` move until resolved. Resolved
entries stay listed for traceability.

- [x] **RF-01** major · `src/store/roomTasks.js`, `UndoSnackbar.vue`, views,
      Jest specs · §3.5 frontend round (step 7) not started: the store still
      calls the pruned `uncomplete`/`reactivate` routes — Jest
      `apiContract.spec.js` red until the one-request undo + reconciliation
      + undo-queue land and the specs are re-pinned. **Resolved
      2026-09-09** (implementation round): `completeRoomTask` reconciles
      roomTasks+dailyTasks (assignees preserved), the rooms store
      (`reconcileRoom`), the game store (weekly goal), the user store
      (`applyRewardTotals`) and the minigame wallet (`reconcileCoins`) from
      the one payload **before** emitting (ordering pinned in
      `roomTasks.spec.js`); `undoRoomTaskCompletion` is one
      `POST /room-tasks/{id}/undo` applying the authoritative payload;
      `uncompleteRoomTask`/`reactivateRoomTask` deleted; `UndoSnackbar` is a
      queued list (per-entry countdown/dismissal, cap 3 + oldest-eviction,
      `aria-live`, 44 px, Jest re-pinned incl. UX-015 two-entry proof);
      the undo `rewards` payload gained `completerUserId` + post-undo
      `weeklyGoal` so the completer-vs-actor distinction reconciles safely
      (D10 inputs). `apiContract.spec.js` green; Jest 820 green.
- [x] **RF-02** major · `tests/integration/Service/` · step 6 real-DB suites
      missing: `CompletionUndoStateEqualityTest` (WP5 exit gate),
      `CompletionConcurrencyTest` (same-room race, post-commit retry,
      concurrent undo), `ConcurrentCompletionTest` extension,
      inactive-occurrence 409 integration case, and the §4 unit-level
      "undo → same-day re-complete → 200" service case. **Resolved
      2026-09-09** (implementation round): state-equality matrix over
      once/recurring-dynamic/static/flex permutations + takeover + D11
      roundtrip + title-LIFO (real DB, exact row equality incl. checklist
      reset/restore); deterministic two-connection races (same-room room-lock
      freshness, post-commit degraded-retry day-duplicate, concurrent-undo
      no-op loser) + a true two-process same-room race extension; inactive
      409 zero-write case; unit roundtrip case. Full integration suite 290
      green (10 pre-existing documented skips).
- [x] **RF-03** major · `tests/e2e/` · step 8 re-pins pending:
      `release-task-lifecycle.spec.ts` and `task-completion.spec.ts` still
      drive/intercept the pruned split chain; full-flow reload-equality and
      undo-queue journeys missing. **Resolved 2026-09-09** (implementation
      round): lifecycle spec drives the one undo aggregate (complete →
      undo → exact `nextDue`/`lastCompleted`/`isActive` inverse → idempotent
      second undo no-op); interception spec matches `POST
      /room-tasks/{id}/undo` on the queue entry; new full-flow journey
      `complete → undo → reload` asserts exact room/coin/recurrence equality
      from a runtime-resolved Kitchen id; new `today-view` queue journey
      proves UX-015 (two entries, independent undos, rows restored after
      reload, no stale cache). Incidental pre-existing reds blocking the
      gate were fixed in-round: `release-task-templates` re-pinned to the
      CS-PLAN-063 aggregate shape (`body.data.roomTask.id`),
      `createRoomViaSettings` made create-if-missing (duplicate-name strict
      assertion), and `room-management`'s member test opted into
      `seededData` so upstream role mutations cannot leak (cures the
      documented room-management:179 flake).
- [x] **RF-04** major · durable docs & dispositions · step 10 pending:
      ADR-011 (already referenced by `TransactionExecutor`/`CompletedTask`/
      `CompletionService` docblocks — must land), ADR-007 annotation,
      `development.md` locking section, `docs/api/README.md`,
      `task-model-and-sync.md`, backlog dispositions (CR-WF-02, UX-015,
      CR-053-20 WP5 slice, CR-SVC-15/40 WP5 slices), CS-PLAN-058 §9 WP5
      row. **Resolved 2026-09-09** (implementation round): ADR-011 written
      (effect-record JSON contract incl. the RF-12 `lastCleaned`/
      `lastActivity` keys, lock order + deadlock argument, D4 gates, D11
      read semantics, D7 degradation boundary, D3/D8 undo policy, D12
      no-lock deviation, frozen formulas); ADR-007 annotated; ADR index
      updated; `development.md` lock section replaced; api/README complete/
      undo contracts + prune notes; `task-model-and-sync.md` completion/
      undo model; release-test-plan undo/reactivate rows updated;
      game.js` corrected; backlog dispositions + CS-PLAN-058 §9 WP5 row executed;
      three out-of-scope findings routed per the fold-in rule: CR-WF-04 notes
      (the `newTitle`/`title-unlocked` dead-event finding folded into its
      payload-design criterion), BUG-E2E-005 fold-in (room-management flake
      root-cure), CR-SVC-63 cross-referenced from ADR-011 (manual cleanliness
      RMW). *(Review correction 2026-09-09: an earlier draft of this entry
      named a "CR-SVC-64" backlog item that was never created — the
      title-celebration finding lives in the CR-WF-04 fold-in above.)*
- [x] **RF-05** minor · `lib/Service/CompletionService.php:409,621-627` ·
      undo edge: if the completer's membership row was deleted after
      completion, the actor-gate `houseUserMapper->find()` throws
      `DoesNotExistException` → unhandled 500 instead of a clean 4xx; and
      `lockCompleterOrForbidden` reuses the completion-worded 403 message
      ("Only house members can complete tasks") in the undo context.
      Auth-adjacent → not auto-fixed (review skill §6). **Resolved
      2026-09-09** (implementation round, user-visible): actor-gate
      resolution is null-safe → `ForbiddenException` with the new
      undo-worded string "The completer is no longer a member of this
      house"; `lockCompleterOrForbidden` takes the context message so the
      undo path never answers with the completion wording (incl. the
      under-lock race); l10n artifacts updated (en/de/pot); two unit cases
      pin both edges.
- [x] **RF-06** block · `lib/Migration/Version1029Date20260906000000.php` ·
      dead-column drops missed the dependent indexes `idx_team_complete`
      (Version1008, on `is_team_completion`) and `idx_claimed_complete`
      (Version1009, on `was_claimed_completion`) → MariaDB error 1072 on
      fresh install; app activation failed, Docker env dead, all backend
      gates unrunnable. **Resolved in review 2026-09-09** (user-authorized
      migration fix: guarded `dropIndex` before the column drops; fresh
      install verified green).
- [x] **RF-07** major · `l10n/{en,de}.json`, `templates/chorestrike.pot` ·
      the two new strings ("Task is not active", "Only the completer or a
      house owner can undo a completion") were missing from the artifacts
      (rule 6: same-change). **Resolved in review** (added en/de/pot;
      `l10n:check-keys` green).
- [x] **RF-08** minor · `lib/Service/CompletionService.php` · unused
      `AuthorizationService` injection (the D3 actor gate uses
      `HouseUser::isOwner()` — functionally equivalent to the plan's
      wording). **Resolved in review** (dependency removed, test updated).
- [x] **RF-09** major · integration-test wiring for the new schema/
      signatures · six raw `chorestrike_completed_tasks` seed fixtures
      missing the NOT NULL `before_state`/`deltas` columns (SQLSTATE 1364);
      six `RoomTaskController`/`TaskController` construction sites on the
      old signatures; `TaskLifecycleVisibilityApiTest` calling the pruned
      `uncomplete()`/`reactivate()` endpoints (44 integration errors).
      **Resolved in review** (fixtures seeded with empty effect JSON;
      constructors rewired; lifecycle spec re-pinned to the undo aggregate
      incl. a once-task `is_active` restore case replacing the reactivate
      coverage; suite green).
- [x] **RF-10** minor · `lib/Service/GameLogicService.php` legacy
      self-insert path (frozen-test compatibility, `$ledgerRow === null`)
      inserted without the NOT NULL effect-record columns → would fail on a
      real DB. **Resolved in review** (empty-JSON defaults set before the
      legacy insert; production always threads the caller-owned row).
- [x] **RF-11** nit · stale docblocks referencing the deleted
      `RoomService::completeRoomTask` (`GameLogicService.php:113`,
      `RoomTask.php:271`). **Resolved in review.**
- [x] **RF-12** minor · `lib/Service/CompletionService.php` (before_state
      contract) · discovered in the step-6 round: the §3.1 JSON contract
      lacked the room's `last_cleaned` and the house user's `last_activity`,
      both of which the completion mutates — exact before/after state
      equality (the WP5 exit gate) is impossible without recording and
      restoring them (`last_activity` also drives the streak-absence
      calculation). **Resolved 2026-09-09** (implementation round): both
      fields added to `snapshotBeforeState` + the undo restore; the
      serialization-contract unit test re-pinned; ADR-011 (RF-04) must
      document the two extra keys.
