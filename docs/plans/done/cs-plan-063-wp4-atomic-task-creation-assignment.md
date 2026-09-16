# CS-PLAN-063: WP4 — Atomic Task Creation and Canonical Assignment

> **Status:** Done (implementation 2026-09-05; final Standard B `PASS-WITH-FIXES` 2026-09-06 — moved to `done/`)
> **Effort:** L
> **Priority:** P1
> **Depends on:** [CS-PLAN-058](cs-plan-058-core-workflow-bottom-up-masterplan.md) (active; this plan executes its work package WP4 — WP0..WP3 are Done), WP2 authorization baseline (ADR-010), WP3 setup aggregate precedent (`HouseSetupService`, 2026-09-05)
> **Related backlog items:** CR-WF-03 task/assignment half (owned); CR-SVC-41 WP4 remainder (ownership-validation dedup; the `getFilteredDailyTasksForUser` sub-item is stale — see §1.5); CR-062-01 (folded into §3.3)
>
> **Scope guard:** the CR-WF-03 setup/structure half was closed by
> [CS-PLAN-062](../done/cs-plan-062-wp3-atomic-house-setup-structure.md).
> This plan owns the remaining task-creation half only: no completion/undo
> changes (WP5), no scheduling preview/apply (WP6), no payload/store
> reconciliation beyond the creation surface (WP7), no structural service
> decomposition (WP8 — `RoomTaskService` at 1126 lines stays over the size
> guideline until then), no E2E-harness redesign (WP9).

## 1. Problem Summary

CS-PLAN-058 §3/WP4 requires that "every user-facing create path produces one
visible, valid room task or no persisted task at all", backed by one
service-owned transaction for task definition, room-task materialization, and
initial assignees, reused for the custom and template paths, with canonical
assignment semantics (one authoritative assignee store, actor policy, lock
order, response shape) across single/set/bulk/setup assignment. The live tree
does not yet satisfy this mandate; all facts below were verified on 2026-09-05
against `master` @ `ab660cd`.

### 1.1 Task creation is a client-owned three-request aggregate (CR-WF-03)

- The frontend orchestrates creation across three sequential HTTP requests:
  `createTaskWithRoomTask` in `src/store/roomTasks.js:239-262` performs
  `POST /tasks` (`:241`) → `POST /room-tasks` with `nextDue: null` (`:246`) →
  `PUT /room-tasks/{id}/assignees` (`:255`). Its own docblock documents the
  defect as design: "The definition stays created when a later stage fails —
  the result reports which stage failed so the caller can toast partial
  success" (`roomTasks.js:230-232`, feature "UX-001").
- Consequences pinned by the UI: a room-task failure leaves an **orphan task
  definition** (re-submitting duplicates it), and an assignment failure
  **closes the modal and reports success-with-warning**
  (`TaskManager.vue:360-369`, same orchestration in `RoomDetail.vue:396-424`).
- The masterplan requires the inverse contract: one aggregate succeeds or no
  write persists, and the form stays open with input preserved until it does.

### 1.2 Both backend creation paths are sequential auto-commit writes (CR-WF-03)

- `RoomTaskService::createRoomTask` (`lib/Service/RoomTaskService.php:271-356`)
  inserts the room task and then writes initial assignee join rows as separate
  auto-committed writes. The code labels the gap itself: "Sequential calls
  preserve today's atomicity level; wrapping creation+assignment into one
  aggregate transaction is WP4 scope" (`:346-349`).
- `RoomTaskService::createFromTemplate` (`:380-409`) materializes the catalog
  template, inserts the task definition (`taskMapper->create`, `:394-406`),
  and delegates to `createRoomTask` (`:408`) — two to three independent
  auto-commit writes; a mid-path failure strands the definition.
- The aggregate pattern to reuse exists and is proven:
  `HouseSetupService::applySetup` (`lib/Service/HouseSetupService.php:126-235`)
  creates definitions + room tasks + assignees inside one
  `TransactionExecutor` transaction behind the house lock, with membership
  revalidation under the transaction boundary (WP3).

### 1.3 Creation validation is split, incomplete, and path-dependent (CR-WF-03 / CR-062-01)

- Constraint checking lives in `TaskService::validateTaskData`
  (`lib/Service/TaskService.php:288-371`) but is invoked **by the controller**
  (`TaskController.php:190-201`); `createTask` itself (`:134-188`, 24
  positional parameters) performs no validation beyond the child-actor gate.
- The constraint set is not closed: `schedulingMode` has no closed-set check
  anywhere on the create path, and `fixedScheduleTime`, `fixedScheduleDays`,
  `flexDays`, `instructions`, and `tips` are not validated at all
  (`TaskService.php:288-371`).
- The template path **bypasses `validateTaskData` entirely** —
  `createFromTemplate` applies only the 6-field override whitelist
  (`TemplateService.php:45-52`) and hard-codes `schedulingMode: 'dynamic'`,
  `scopeType: 'room'` (`:289-290`); catalog materialization and custom
  creation share no validation.
- Non-string types are coerced rather than rejected on adjacent surfaces:
  `HouseSetupService::validateHouseName` keeps `null` for any non-string
  `houseName`, answering 200 instead of 400 (backlog **CR-062-01**, P3,
  explicitly routed to WP4).

### 1.4 Scheduling fields do not round-trip through creation (CR-WF-03)

- `flexDays` is exposed in the creation form (`TaskEditForm.vue:205-211`) and
  submitted, but `TaskController::create` has no such parameter
  (`lib/Controller/TaskController.php:89-108`) — the field is **dead at
  creation** (always persisted as the entity default 0, `lib/Db/Task.php:180`);
  `TaskList` then renders flex windows that are always empty for new tasks.
- There is **no explicit creation due date**: the store sends
  `nextDue: null` (`roomTasks.js:246`) and the service defaults to tomorrow
  (`RoomTaskService.php:333-337`); a due date is settable only post-create via
  the edit modal.
- `instructions`/`tips` are hardcoded `null` at creation
  (`TaskController.php:233-236`) and the form offers no instructions field,
  although the masterplan WP4 field list includes instructions and the
  template path persists catalog instructions.

### 1.5 Assignment is canonical in storage but not in lock discipline or service seams (CR-SVC-41 WP4 slice)

- The canonical assignee store is done (WP1 D2): all assignment writes go to
  `chorestrike_task_assignees`; `TaskAssigneeMapper::replaceAssignees`
  (`lib/Db/TaskAssigneeMapper.php:261-270`) is an atomic delete-then-insert
  via `TransactionExecutor` with a DB unique backstop
  (`Version1021Date20260712000000.php:61`).
- But `setAssignees` (`RoomTaskService.php:535-543`) and
  `bulkAssignTasks` (`:731-801`) acquire **no room-task row lock** before
  replacing assignees, and bulk runs per-item transactions with no documented
  lock order — the masterplan's "same … lock order" requirement for
  set/bulk/setup assignment is unmet (the documented order in
  `lib/Db/TransactionExecutor.php:24-32` is `house → room_task → dependents`).
- The `validate*Ownership` room→floor→house walk is duplicated three times:
  `RoomTaskService::validateRoomTaskOwnership` (`:116-134`),
  `RoomService::validateRoomOwnership` (`lib/Service/RoomService.php:99-110`),
  `FloorService::validateFloorOwnership` (`lib/Service/FloorService.php:61-71`).
- The CR-SVC-41 sub-item "`getFilteredDailyTasksForUser` takes `HouseUserMapper`
  as a param despite ctor injection" is **stale**: the current signature is
  `(int $houseId, HouseUser $viewer, \DateTime $date)`
  (`RoomTaskService.php:1093-1097`) and no method parameter of that type
  remains in `lib/Service` (constructor injection only). The backlog text
  needs an annotation, not code.

### 1.6 The creation surface leaks owner/member/child boundaries and dead controls (CR-WF-03 / CR-WF-08 creation line)

- The "New Task" button (`TaskManager.vue:12`) and "Add Task" button
  (`RoomDetail.vue:108`) are **not role-gated**: a child can open and submit
  the full form and receives the backend 403 (`TaskService.php:160-162`)
  only after click — the exact "hidden editing controls that fail after click"
  anti-pattern the masterplan forbids.
- Template prefill is **broken**: `RecurrencePresetSelector` emits only
  `{id, name, icon, recurrence}` presets (`:40-65`, no props declared) while
    `TaskEditForm.applyTemplate` (`:373-390`) reads
    `name/description/energyCost/…/flexDays/visibilityType` — only the `name`
    branch picks anything up (the preset's own label, e.g. "Daily", lands in
    the task-name field), every other branch falls through to defaults, and
    the preset's actual `recurrence` payload is never consumed; the passed
    `:selected-template` prop (`TaskEditForm.vue:12`) is never declared.
- The form has **no concise preview**, no due-date control, and no
  instructions field; the dead `fetchAssignees` store action
  (`roomTasks.js:176-190`) has no consumer.
- What already holds and stays: the cascading floor→room picker with locked
  preset mode (`RoomPicker.vue`), multi-select assignee chips
  (`AssigneeChips.vue`), the in-form visibility explanation with owner-gated
  `admin-only` option (`TaskEditForm.vue:244-257`, `:432-443`), TaskList's D5
  child gating (edit/delete/bulk hidden, complete/claim kept), and the one-call
  bulk toolbar with partial-success toasts (`TaskList.vue:36-65`, `:526-553`).

### 1.7 Test coverage cannot detect an orphaned or partial creation (CR-WF-03)

- **Creation atomicity has zero pins**: no rollback test exists for any
  creation path; transaction pins exist only for delete
  (`RoomTaskServiceTest.php:843-928`). `createFromTemplate` has **no unit
  test at all**, and no create test asserts the `addAssignee` write.
- The two controller "integration" suites mock the services
  (`TaskControllerIntegrationTest.php:31-35`,
  `RoomTaskControllerIntegrationTest.php:32-35`) — there is **no real-DB
  test of the creation endpoints**; visibility creation (private owner
  stamping, admin-only 403) is covered only by E2E API calls.
- Jest **pins the orphan contract as desired behavior**
  (`roomTasks.spec.js:444` "keeps the definition when the room task creation
  fails", `:460`; `TaskManager.spec.js:186` partial-success close) — these
  must be re-pinned to the aggregate contract (no-compat rule, project-rules
  §1).
- No creation/assignment race test exists; the proven `proc_open`
  two-connection pattern (`BootstrapConcurrencyTest.php:58-110`, refined by
  `SetupApplyConcurrencyTest` incl. zero-write proofs `:138-208`) is ready to
  copy. No GUI test covers bulk assignment (zero `bulk-assign` matches in
  `tests/e2e`); `task-manager.spec.ts:63` opens the creation modal but never
  submits.

## 2. Strategy

Introduce one service-owned creation aggregate and route every user-facing
create path through it; canonicalize assignment lock discipline; then rework
the creation form to the new single-request contract. Each step starts from a
failing or missing test (CS-PLAN-058 §2.3):

1. **Consolidate validation** into one closed constraint set inside the
   service layer, shared by custom and template paths, with strict type
   rejection (folds CR-062-01's alignment) and newly creatable
   `flexDays`/`instructions` (decision D3).
2. **Add `TaskCreationService`** owning the aggregate: one
   `TransactionExecutor` transaction — house lock first, under-lock
   membership revalidation, duplicate recheck, then definition insert,
   room-task materialization (explicit `nextDue`, flex-aware
   `idealDueDate`), and initial assignee join rows (decisions D1/D2).
3. **Route both endpoints through it**: `POST /room-tasks` gains an inline
   `task` definition variant plus `assigneeUserIds[]` and optional `nextDue`;
   `POST /room-tasks/from-template` delegates to the same service method and
   returns the same response shape. The definition-only `POST /tasks` loses
   its last consumer and is pruned with its store action (decision D1).
4. **Canonicalize assignment locks**: `setAssignees`/`bulkAssignTasks` acquire
   the `room_task` row lock in the documented order before replacement, with
   under-lock membership revalidation; the per-item partial-success bulk
   contract is preserved (decision D5); deduplicate the ownership walk
   (CR-SVC-41 slice).
5. **Rework the creation form** to the single-request contract: due-date and
   flex controls that persist, optional instructions, a concise preview,
   repaired prefill wiring, aggregate-bound submit that never reports success
   on failure, and `canManageTasks` gating of every creation entry point
   (decisions D4/D6).
6. **Prove it**: unit (gates, validation, rollback sabotage), real-DB
   integration (atomicity, zero-write rollback, visibility/date/recurrence
   permutations, mount/duplicate), `proc_open` races (duplicate mount,
   membership removal during create), Jest re-pins, and a GUI creation
   journey incl. bulk assignment and a forced-failure recovery step
   (BUG-E2E-005 date-trap aware).
7. **Durable docs + backlog dispositions**; full quality gate.

## 3. Backend / Frontend Changes

### 3.1 Closed validation set in the service layer (CR-WF-03 / CR-062-01)

- `TaskService::validateTaskData` becomes the **single** creation/update
  constraint authority and is called by services, not controllers:
  - existing checks unchanged (name required, energyCost 1–5, priority 1–3,
    estimatedMinutes 1–480 + min≤max, frequencyType closed set,
    frequencyValue ≥ 1, scopeType closed set, recurrenceMode closed set,
    activeFrom ≤ activeUntil, visibilityType closed set);
  - new checks: `schedulingMode` ∈ new `Task::SCHEDULING_MODES`
    (`dynamic|static`) closed set; `fixedScheduleTime` parseable time;
    `fixedScheduleDays` a JSON array of canonical weekday names;
    `flexDays` an integer in `[0, 7]` (decision D3 — bound ratified by
    review); `instructions`/`tips` length ≤ 2000; non-scalar payloads and
    non-string names/dates are **rejected with 400**, never coerced.
- `HouseSetupService::validateHouseName` aligned: a present but non-string
  `houseName` yields `400 VALIDATION_ERROR` instead of a silent 200
  (closes CR-062-01; same change, same rule).
- The controller's duplicated pre-checks (date parsing, visibility closed
  set) collapse into the service; `TaskController::update` keeps only
  envelope shaping and the owner-gate mapping it already owns.

### 3.2 `TaskCreationService` — the aggregate owner (CR-WF-03)

- New `lib/Service/TaskCreationService.php` (~200 lines), injected with
  `TransactionExecutor`, `TaskMapper`, `RoomTaskMapper`, `TaskAssigneeMapper`,
  `HouseMapper`, `HouseUserMapper`, `RoomService`, `TaskService`,
  `TemplateService`, `TaskAccessPolicy`, `AuthorizationService`, `IL10N`,
  `AppLogger` (decision D2).
- `create(int $houseId, HouseUser $actor, array $payload): array` where the
  payload carries either an inline `task` definition (custom path) or a
  `taskId` (mount-existing path), plus `roomId`, `assigneeUserIds[]`, and an
  optional `nextDue` (house-local date, ADR-009 semantics — parsed like
  `RoomTaskController::updateDueDate`):
  1. Pre-transaction fast-fail: `assertCanManageTasks($actor)` (children
     cannot create), full `validateTaskData` on the materialized payload
     (custom: client payload; template: `TemplateService::materializeTemplate`
     output + overrides; mount: existing definition re-read), room→floor→house
     ownership, task visibility (`TaskAccessPolicy`), admin-only creation
     owner gate, private creation `ownerUserId` stamping.
  2. `transactions->run(...)`: `houseMapper->findForUpdate($houseId)` (house
     is the documented first lock — WP3 precedent), **revalidate every
     distinct assignee membership under the lock**
     (`HouseUserMapper::findByHouseAndUser`; miss → 400, rollback), recheck
     the duplicate guard `roomTaskMapper->exists($roomId, $taskId)` (mount
     variant; → 400), then write definition (`taskMapper->create`, named
     args — replaces the 24-positional-param seam), room task
     (`roomTaskMapper->create` with explicit `nextDue` or the existing
     tomorrow default, flex-aware `idealDueDate` via
     `RoomTask::calculateIdealDueDate`), and initial assignee join rows.
  3. Returns `{task, roomTask, assignees}` — one response shape for both
     paths (decision D1).
- `createFromTemplate(int $houseId, HouseUser $actor, int $roomId, string
  $templateId, ?string $level, array $overrides, ?string $assigneeUserId)`
  materializes via `TemplateService`, then runs the same `create` body with
  the scalar assignee normalized to a one-element set (setup parity,
  decision D1).
- `RoomTaskService::createRoomTask`/`createFromTemplate` are deleted; their
  callers (`RoomTaskController::create`/`fromTemplate`) route to
  `TaskCreationService`. `TaskService::createTask` loses its last caller and
  is deleted together with the pruned endpoint (decision D1). Setup keeps its
  internal aggregate (it already writes the same canonical rows under the
  same lock inside its own transaction — §1.2; no double-locking via a call
  out).
- `TransactionExecutor`'s lock-order docblock gains the creation usage note
  (creation takes only the house lock and fresh inserts; assignment takes
  `room_task` locks — no cycle, per the documented order).

### 3.3 Aggregate API contract (CR-WF-03)

- `POST /room-tasks` becomes the one creation endpoint. Payload variants:
  - custom create: `{ task: { name, description?, energyCost, priority,
    frequencyType, frequencyValue, estimatedMinutes, category,
    recurrenceMode?, activeFrom?, activeUntil?, schedulingMode?,
    fixedScheduleTime?, fixedScheduleDays?, flexDays?, instructions?,
    visibilityType? }, roomId, assigneeUserIds?: string[], nextDue?: "YYYY-MM-DD" }`
  - mount existing: `{ taskId, roomId, assigneeUserIds?: string[], nextDue? }`
    (the legacy scalar `assignedToUserId` and `scopeId` body keys are folded
    out — no consumer sends them; the store sends `nextDue: null` today and
    is updated in §3.5).
- `POST /room-tasks/from-template` keeps `{roomId, templateId, level?,
  overrides?, assigneeUserId?}` and now returns the aggregate shape
  `{task, roomTask, assignees}`.
- `POST /tasks` (definition-only) and the `task#create` route are **pruned**
  with `tasksStore.createTask` — after §3.5 nothing consumes them; the
  no-consumer route policy (CS-PLAN-055) applies, `RouteMatrixTest` and the
  Jest `apiContract.spec.js` route map are updated in the same change
  (decision D1). `PUT /tasks/{id}` (definition edit) is unchanged.
- Error semantics unchanged: 400 validation, 403 role denials (child actor,
  member→admin-only), 404 anti-enumeration for cross-house/unknown rooms and
  invisible definitions (ADR-010 standard).

### 3.4 Canonical assignment locks and seams (CR-SVC-41 slice / CR-WF-03)

- `RoomTaskService::setAssignees`: inside one transaction, lock the room-task
  row (`roomTaskMapper->lockForUpdate`), revalidate the target memberships
  under the lock, then `replaceAssignees`. Response stays the assignee set;
  its inner shape is identical to the aggregate's `assignees` element.
- `RoomTaskService::bulkAssignTasks`: preserves the pinned per-item
  partial-success contract (pre-filtered foreign/invisible ids are skipped
  and reported; a non-member target fails fast with 400 and **zero writes**),
  but each item write now runs in its own transaction with the `room_task`
  lock acquired in ascending-id order across the batch, and the single target
  user's membership is revalidated under the first lock (decision D5).
- Deduplicate the ownership walk: `RoomTaskService::validateRoomTaskOwnership`
  resolves roomTask→roomId and delegates to `RoomService::validateRoomOwnership`
  (the one canonical room→floor→house implementation);
  `FloorService::validateFloorOwnership` stays (different entry point).
- CR-SVC-41 backlog annotation: the `getFilteredDailyTasksForUser` param
  sub-item is recorded as already resolved (§1.5); "duplicated stat helpers"
  stay WP8 (out of the creation/assignment path).

### 3.5 Creation form and entry points (CR-WF-03 / CR-WF-08 creation line)

- `roomTasks.js`: `createTaskWithRoomTask` is replaced by
  `createTaskAggregate(payload)` → one `POST /room-tasks`; the orphan/partial
  branches are deleted; the dead `fetchAssignees` action is deleted;
  `tasks.js::createTask` is deleted with the endpoint.
- `TaskManager.vue` / `RoomDetail.vue`: single-call handlers — failure keeps
  the modal open with input preserved and a localized error; success closes
  the modal, refreshes the room-task list, and adds the definition to the
  tasks store from the aggregate response. The "New Task"/"Add Task" buttons
  and the form are gated on `userStore.canManageTasks` (owner/member);
  children keep the read/execute TaskList surface unchanged (decision D6).
- `TaskEditForm.vue`:
   - submit payload gains `nextDue` (date picker, house-local) and persists
     `flexDays` — the flex input's `max` attribute is aligned from 14 to the
     ratified bound 7 (decision D3, Standard A finding F4); an optional
     `instructions` textarea is added; `description` and all existing fields
     unchanged;
  - a concise preview block (room path, recurrence summary, due/flex window,
    assignee chips, visibility) renders above the submit button;
  - prefill repaired (decision D4): `RecurrencePresetSelector` declares its
    props, emits its actual preset shape, and `applyTemplate` consumes only
    that shape under an honest "Repeat" label; catalog templates prefill the
    form (name/category/energy/frequency/estimatedMinutes/instructions)
    filtered by the selected room's type, reusing the `GET /setup/catalog`
    surface the `TaskTemplatePicker` already consumes;
  - `admin-only` stays owner-gated with the existing visibility explanation.
- `RoomTaskEditModal` (due date + assignees) stays a two-field-level edit
  surface — only its `setAssignees` error path benefits from §3.4; folding it
  into an aggregate is explicitly out of scope (decision D7).
- All new/changed strings through `t()`; `l10n/` artifacts updated in the
  same change.

### 3.6 Checklist at creation — explicitly out of scope

The aggregate does not accept `checklistItems` and the form gets no checklist
editor (decision D8): the masterplan WP4 form list omits a checklist control,
checklist-definition management is house-owner-only by recorded decision
(WP2 D4), and WP7's My Day work consumes checklists read-only. Checklist
items remain a post-creation, owner-managed surface (`ChecklistService`
unchanged).

### 3.7 Durable docs

- `docs/api/README.md`: rewrite the Tasks/Room Tasks creation sections —
  aggregate contract (both variants + from-template), pruned `POST /tasks`,
  unified response shape, lock/validation semantics.
- `docs/concepts/task-model-and-sync.md`: replace the three-step "Creation
  Model (CS-PLAN-038)" with the single-aggregate model (atomicity, due/flex
  at creation, entry-point roles).
- `docs/engineering/development.md` locking section + `TransactionExecutor`
  docblock: creation-aggregate house-lock usage and assignment `room_task`
  lock order.
- No new ADR: the aggregate refines ADR-007's transaction/lock doctrine (as
  WP3 did); ADR-010's visibility/404 standard is cited, not changed.

## 4. Tests

- **Unit (PHPUnit) — new `TaskCreationServiceTest`; updated
  `TaskServiceTest`, `RoomTaskServiceTest`, `TaskControllerTest`,
  `RoomTaskControllerTest`, `HouseSetupServiceTest`:**
  - Both paths invoke the closed validation set (template payload with an
    out-of-range override now fails 400 — closes the bypass); `flexDays`
    bounds, `schedulingMode` closed set, weekday-JSON, non-string → 400
    matrix; CR-062-01: non-string `houseName` → 400.
  - Gate order: child actor 403 before any mapper call; private creation
    stamps `ownerUserId` = creator; member + `admin-only` → 403; cross-house
    room → 404; mount-duplicate recheck under lock → 400 with no second
    insert.
  - Rollback sabotage: a mapper mock throwing after the definition insert →
    `rollBack()` called, `commit()` never, zero assignee writes (mock-level;
    real-DB proof below).
  - `setAssignees`/`bulkAssignTasks`: `lockForUpdate` precedes
    `replaceAssignees` (sorted ids for bulk); under-lock membership miss →
    400 and zero writes; bulk partial-success semantics re-pinned
    (skipped foreign/invisible, non-member fast-fail).
  - Controller: new payload variants map to the service; envelope/status
    unchanged; `POST /tasks` gone from `RouteMatrixTest`.
- **Integration (Docker, real DB) — new `TaskCreationAggregateTest`,
  new `TaskCreationConcurrencyTest`; extended `AssigneeRepresentationTest`,
  `BulkAssignIntegrationTest`:**
  - Custom create persists definition + room task + assignees + explicit
    `nextDue` + `flexDays`/`idealDueDate` in the aggregate shape; template
    create identical; mount-existing variant reuses the definition.
  - Zero-write proofs: assignee membership removed between pre-validation
    and apply → 400 with **zero** `chorestrike_tasks`, `room_tasks`, and
    `task_assignees` rows (orphan-definition proof); duplicate mount → 400
    with no second row.
  - Visibility creation matrix: private (owner stamp), admin-only (member
    403, owner 200), public default — real-DB via `actingAs` +
    real-services controller build (`BulkAssignIntegrationTest::buildController`
    precedent), replacing the mock-service "integration" holes for creation.
  - Date/recurrence permutations: `once`/`bounded`/`recurring`,
    `activeFrom/Until`, `dynamic`/`static` + fixed time/weekdays, `flexDays`
    0..7 → persisted and round-tripped.
  - `TaskCreationConcurrencyTest` (proc_open pattern copied from
    `SetupApplyConcurrencyTest`, MySQL-only documented skip in the exact
    established wording): (a) two concurrent mounts of the same definition →
    exactly one row, one 400; (b) membership removed while the creator waits
    on the house lock → 400 and zero writes.
- **Jest — `roomTasks.spec.js`, `TaskManager.spec.js`, `TaskEditForm.spec.js`,
  `tasks.spec.js`, `RoomTaskEditModal.spec.js`, `TaskTemplatePicker.spec.js`,
  `apiContract.spec.js`:**
  - Store: `createTaskAggregate` performs exactly one `POST /room-tasks`
    with the aggregate payload; the three-request chain, orphan retention,
    `fetchAssignees`, and `tasksStore.createTask` are gone (specs re-pinned,
    not shimmed).
  - Views: failure → modal open, input preserved, error toast, no success;
    success → close + refresh + definition added; child sees no "New
    Task"/"Add Task" (button and form gated).
  - Form: payload includes `nextDue`/`flexDays`/`instructions`; preview
    renders room/recurrence/due-flex/assignees/visibility; preset wiring
    applies recurrence fields; catalog prefill fills supported fields;
    `admin-only` remains owner-only.
- **E2E (Playwright) — extended `task-manager.spec.ts` (parallel pool),
  updated `admin-only-tasks.spec.ts`, `release-task-templates.spec.ts`,
  `private-tasks.spec.ts`, `insights-flow.spec.ts`,
  `release-visibility-roles.spec.ts`, `teamwork-rewards.spec.ts`
  (endpoint/payload migration — the full `POST /tasks` consumer inventory,
  re-grepped at Standard A review: `insights-flow.spec.ts:141`,
  `release-visibility-roles.spec.ts:113`, `teamwork-rewards.spec.ts:41,90`
  were missing from the draft list):**
  1. Owner creates a custom task through the form — room picker, recurrence,
     due date, flex, assignee chips, visibility — asserts the visible row,
     its assignees, and the due/flex display; a private variant asserts
     visibility filtering.
  2. Owner assigns via row chips (multi) and applies the bulk toolbar to two
     rows — the first GUI bulk-assignment coverage; partial-success toast on
     a mixed selection stays assertable at store level (Jest).
  3. Template creation via RoomDetail's picker returns the aggregate shape
     and the row appears.
  4. Forced failure: Playwright request interception aborts the aggregate
     POST → modal stays open, input preserved, no success toast, no new row
     (GUI rollback-adjacent proof; DB-level rollback is the integration
     proof above).
  5. Date probes are anchored to today/tomorrow labels — never weekday-
     dependent calendar positions (BUG-E2E-005 lesson).
  - `task-manager.spec.ts` stays in the parallel `chromium` pool (family
    profile, no seed mutation).
- **Gate (CS-PLAN-058 §4.5):** full gate including Docker unit +
  integration, `composer cs:check`, all applicable Playwright projects; no
  new documented skips beyond the established MySQL-only race-guard
  inventory.

## 5. File Manifest

```
new:      lib/Service/TaskCreationService.php
new:      tests/unit/Service/TaskCreationServiceTest.php
new:      tests/integration/Service/TaskCreationAggregateTest.php
new:      tests/integration/Service/TaskCreationConcurrencyTest.php
modified: lib/Controller/RoomTaskController.php (aggregate routing, payload variants, response shape)
modified: lib/Controller/TaskController.php (create pruned; validation delegation; update stays)
modified: lib/Service/TaskService.php (validateTaskData closed set + service-invoked; createTask deleted)
modified: lib/Service/RoomTaskService.php (createRoomTask/createFromTemplate deleted; setAssignees/bulkAssign locks; ownership dedup)
modified: lib/Service/RoomService.php (canonical ownership helper if the signature moves)
modified: lib/Service/FloorService.php (only if the dedup touches it)
modified: lib/Service/HouseSetupService.php (CR-062-01 strict houseName)
modified: lib/Db/Task.php (SCHEDULING_MODES constant)
modified: lib/Db/TransactionExecutor.php (lock-order docblock creation/assignment usage)
modified: appinfo/routes.php (POST /tasks pruned)
modified: src/store/roomTasks.js (createTaskAggregate; chain + fetchAssignees deleted)
modified: src/store/tasks.js (createTask deleted)
modified: src/views/TaskManager.vue · src/views/RoomDetail.vue (single-call handlers, entry-point gating)
modified: src/components/Tasks/TaskEditForm.vue (due/flex/instructions, preview, prefill repair, gating)
modified: src/components/Tasks/RecurrencePresetSelector.vue (props/emit/preset wiring; label)
modified: src/components/Tasks/TaskTemplatePicker.vue (aggregate response shape only, if consumed)
modified: tests/unit/Service/{TaskServiceTest,RoomTaskServiceTest,HouseSetupServiceTest}.php
modified: tests/unit/Controller/{TaskControllerTest,RoomTaskControllerTest}.php
modified: tests/unit/RouteMatrixTest.php (route↔controller guard for the pruned route)
modified: tests/integration/Db/AssigneeRepresentationTest.php · tests/integration/Controller/BulkAssignIntegrationTest.php
modified: tests/unit/store/{roomTasks,tasks}.spec.js · tests/unit/views/TaskManager.spec.js
modified: tests/unit/components/{TaskEditForm,RoomTaskEditModal,TaskTemplatePicker}.spec.js · tests/unit/store/apiContract.spec.js
modified: tests/e2e/task-manager.spec.ts · tests/e2e/admin-only-tasks.spec.ts · tests/e2e/release-task-templates.spec.ts · tests/e2e/private-tasks.spec.ts · tests/e2e/insights-flow.spec.ts · tests/e2e/release-visibility-roles.spec.ts · tests/e2e/teamwork-rewards.spec.ts
modified: docs/api/README.md · docs/concepts/task-model-and-sync.md · docs/engineering/development.md
modified: l10n/{en.json,de.json,templates/chorestrike.pot}
modified: docs/backlog/{backlog.md,ACTIVE_TASKS.md} (+ archive/2026-09.md completion entries — owned by the Standard B review)
modified: docs/plans/active/cs-plan-058-core-workflow-bottom-up-masterplan.md (§9 progress row WP4)
modified: docs/plans/active/cs-plan-063-wp4-atomic-task-creation-assignment.md (this plan; moved to active/ by the Standard A review 2026-09-05)
```

## 6. Implementation Order

1. Failing tests first: closed-validation unit cases (incl. template-path
   bypass + CR-062-01), aggregate gate/rollback unit cases, store/form Jest
   cases re-pinned to the one-request contract (red).
2. §3.1 validation consolidation (+ CR-062-01); unit green.
3. §3.2 `TaskCreationService` + §3.3 endpoint routing and prune; unit +
   controller tests green; `RouteMatrixTest`/`apiContract` updated.
4. §3.4 assignment locks + ownership dedup; unit green.
5. Real-DB integration: aggregate, zero-write, visibility, date/recurrence
   permutations; `TaskCreationConcurrencyTest` (proc_open) — Docker green.
6. §3.5 frontend: store action, views, form controls, preview, prefill
   repair, gating; Jest green.
7. E2E: task-manager journey extension, endpoint migrations in the affected
   specs, forced-failure recovery step; `chromium`, `chromium-serial-db`,
   `chromium-scheduling-owner` green.
8. Full §4.5 gate; scoped `npm run lint:fix` beforehand.
9. §3.7 durable docs; backlog dispositions (CR-WF-03 task-half criteria,
   CR-SVC-41 WP4 slice + stale sub-item annotation, CR-062-01 archive),
   CS-PLAN-058 §9 WP4 row, `ACTIVE_TASKS.md`; request Standard B review.

## 7. Risks & Mitigations

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| House lock on every creation serializes write throughput | Low | Household-scale creation rates; lock held for a short critical section (no external calls, ADR-007 doctrine); completion (WP5) locks `room_task` only — no cross contention |
| Pruning `POST /tasks` breaks hidden consumers | Medium | Grep-verified consumer inventory (store + E2E specs) migrated in the same change; `RouteMatrixTest` + `apiContract.spec.js` guard drift; pre-release no-compat policy |
| Template-path validation tightening rejects catalog payloads | Medium | Catalog fixture test: every template × level must pass the closed set before the check goes live; overrides stay whitelist-bounded |
| Jest/E2E re-pins churn the suite in one step | Medium | Re-pins land with the contract change in implementation step 1+6 pairs (red→green per layer), never after the fact |
| Concurrent bulk item transactions deadlock | Low | Ascending-id lock order within a batch; per-item transactions match the existing `replaceAssignees` usage; two-connection race test covers the replace path |
| `flexDays` bound (0–7) rejects existing PUT-edited rows | Low | Pre-release fresh-install policy; integration permutation test pins 0 and 7 as valid, 8 as 400 |
| Prefill/cascading-picker rework shifts E2E locators | Low | Stable `data-testid`s (RoomPicker already exposes them); journey uses the same controls |
| Forced-failure E2E via route interception is new territory | Medium | Abort-and-assert pattern is scoped to one test; if flaky, the DB-level zero-write integration proof remains the mandatory atomicity evidence and the GUI case degrades to a logged deferral — never a silent skip |
| Scope creep into WP5 completion or WP8 decomposition | Medium | §Scope guard + manifest freeze: `RoomTaskService` shrinks only by deletion/delegation; no completion-path edits |
| Date-dependent E2E flakiness (BUG-E2E-005 class) | Medium | Due-date assertions anchored to stored `nextDue` and row labels, never calendar-weekday geometry |

## 8. Acceptance Criteria

- [x] Custom create, template create, and mount-existing all run as one
      service-owned transaction (`TaskCreationService`): definition insert,
      room-task materialization (explicit `nextDue`, flex-aware
      `idealDueDate`), and initial assignee join rows commit or roll back
      together — proven by mock-sabotage unit tests and real-DB zero-write
      integration tests (no orphan task definitions in any failure path).
- [x] Both HTTP create paths share one validation authority
      (`validateTaskData`, service-invoked) with a closed constraint set
      including `schedulingMode`, `fixedScheduleTime/Days`, `flexDays`
      (0–7), and instructions/tips; non-string/malformed values yield 400;
      the template path no longer bypasses validation; CR-062-01 closed
      (non-string `houseName` → 400).
- [x] `flexDays`, an explicit creation due date, and `instructions`
      round-trip from form → API → persistence → store/UI; the previously
      dead creation `flexDays` field is consumed.
- [x] Assignment is canonical across surfaces: `setAssignees` and each bulk
      item acquire the `room_task` lock (ascending ids) before replacement,
      revalidate membership under the lock, preserve the pinned bulk
      partial-success semantics, and expose one assignee-set shape shared
      with the aggregate response; the duplicated ownership walk is deduped
      (CR-SVC-41 WP4 slice; stale sub-item annotated).
- [x] The creation form submits exactly one request, never closes or reports
      success on failure, preserves input on recoverable errors, offers a
      concise preview, repaired prefill (presets + catalog), due/flex
      controls, optional instructions, and the existing visibility
      explanation; "New Task"/"Add Task" and the form are hidden for
      children (`canManageTasks`), who keep the read/execute surface.
- [x] `POST /tasks`, `createTaskWithRoomTask`, `tasksStore.createTask`, and
      `fetchAssignees` are gone; `RouteMatrixTest` and `apiContract.spec.js`
      reflect the pruned/changed contract.
- [x] Tests from §4 exist and pass: unit (gates, validation, rollback,
      locks), real-DB integration (atomicity, zero-writes, visibility,
      date/recurrence permutations), `proc_open` concurrency (duplicate
      mount, membership removal; MySQL-only documented skip in the
      established wording), Jest re-pins, and the GUI journey (custom,
      template, multi/bulk assignment, forced-failure recovery).
- [x] WP4 exit gate met: custom/template, single/multi/bulk assignment,
      private/admin/public, rollback, and date/recurrence permutations pass
      unit, real-DB integration, and GUI tests with no orphan task
      definitions.
- [x] All new/changed strings localized; standard API envelope everywhere;
      `l10n:check-keys` green.
- [x] Build gate green (CS-PLAN-058 §4.5) with no new or unapproved skips.
- [x] Owning backlog items dispositioned: CR-WF-03 task-half criteria
      checked (umbrella closes when both halves are done), CR-SVC-41 WP4
      slice annotated, CR-062-01 archived; CS-PLAN-058 §9 WP4 row updated.
- [x] Durable docs updated (API README, task-model concept, locking note) —
      plans are temporary.
- [x] All implementation decisions and review questions resolved and
      recorded.
- [x] Final Standard B review is `PASS` or `PASS-WITH-FIXES`;
      `ACTIVE_TASKS.md` updated.

## 9. Plan Review Decision Register

The Standard A review must ratify or revise these proposed decisions before
activating the plan:

| ID | Proposed decision | Rationale | Status |
|----|-------------------|-----------|--------|
| D1 | One aggregate contract: `POST /room-tasks` accepts an inline `task` definition or `taskId` plus `assigneeUserIds[]` and optional `nextDue`; `from-template` delegates to the same service and returns the same `{task, roomTask, assignees}` shape; the definition-only `POST /tasks` and its store action are pruned (no consumer) | The room task is the user-visible unit (masterplan WP4 goal); one endpoint per concept; no-compat pre-release policy and the CS-PLAN-055 route-consumer rule | Ratified (user pre-ratification confirmed by Standard A 2026-09-05) |
| D2 | A new `TaskCreationService` owns the aggregate; `RoomTaskService::createRoomTask`/`createFromTemplate` and `TaskService::createTask` are deleted (callers rerouted) | Keeps the 1126-line `RoomTaskService` from growing (its structural split is WP8); mirrors the `HouseSetupService` aggregate precedent; removes the 24-positional-param seam | Ratified by Standard A 2026-09-05 |
| D3 | Validation is consolidated in `TaskService::validateTaskData` (service-invoked, both paths) with a closed set incl. `flexDays` ∈ [0,7], `SCHEDULING_MODES`, weekday-JSON, and strict non-string 400s; `flexDays` and `instructions` become creatable | Masterplan's "one closed set of task constraints"; the 0–7 bound keeps flex windows within the scheduling horizon; strictness folds CR-062-01 | Ratified by Standard A 2026-09-05 (bound [0,7]; form `max` aligned 14→7, §3.5) |
| D4 | Prefill = repaired recurrence presets (honest "Repeat" wiring) **plus** catalog-template prefill filtered by the selected room's type, reusing `GET /setup/catalog` | Masterplan WP4 lists "optional template prefill"; the catalog already exists and filters by room type in `TaskTemplatePicker` | Ratified (user pre-ratification confirmed by Standard A 2026-09-05) |
| D5 | Bulk assignment keeps per-item transactions and the pinned partial-success contract; adds ascending-id `room_task` locks and under-lock membership revalidation; `setAssignees` locks the row likewise | Preserves UI-tested semantics while meeting the lock-order mandate; one batch-wide transaction would change pinned skip-not-abort behavior | Ratified by Standard A 2026-09-05 |
| D6 | Creation entry points ("New Task", "Add Task", the form) are gated on `canManageTasks`; children keep read/execute | Masterplan WP4: "children receive a read/execute view, not hidden editing controls that fail after click" | Ratified by Standard A 2026-09-05 |
| D7 | The RoomDetail edit modal (due date + assignees) stays a two-field-level surface — not folded into the aggregate | Field-level edits are independent and independently recoverable; the masterplan's aggregate mandate targets creation; avoids contract churn beyond the gate | Ratified by Standard A 2026-09-05 |
| D8 | No `checklistItems` in the aggregate and no checklist editor in the form | Masterplan WP4's form list omits a checklist control; checklist-definition management is owner-only by WP2 decision D4; WP7 consumes checklists read-only | Ratified (user pre-ratification confirmed by Standard A 2026-09-05) |
| D9 | Setup (`HouseSetupService`) keeps its internal aggregate writes rather than calling `TaskCreationService` | It already writes the same canonical rows under the same house lock inside its own transaction (WP1 D2 + WP3); routing it through a second house-lock acquisition adds a seam without changing behavior | Ratified by Standard A 2026-09-05 |

Any rejected decision requires this plan, its affected backlog acceptance
criteria, and the corresponding risk/test sections to be updated before the
review can pass.

## 10. Review Outcome

- **Tier / date:** Standard A (plan review) · 2026-09-05
- **Scope:** this plan file only (untracked draft, blob
  `51fe828f6fece5d361b080bce13ffeff3c5c2e29`) + its `ACTIVE_TASKS.md` draft
  entry; base `master` @ `ab660cd`, index clean, no other reviewed-scope drift.
- **Outcome:** `PASS-WITH-FIXES` → plan moved `new/ → active/`.
- **Verification:** ~25 factual pins (§1.1–§1.7 line references, mapper
  primitives `findForUpdate`/`lockForUpdate`/`exists`, `TransactionExecutor`
  lock order, backlog item texts, masterplan WP4 mandate, Jest/E2E pins)
  re-checked against the live tree — all accurate except F2. No overlap with
  other active plans (CS-PLAN-058 §9 WP4 row: "Not started"). Every work item
  has an owning backlog ID (CR-WF-03 task half, CR-SVC-41 WP4 slice,
  CR-062-01).
- **Findings & fixes (all fixed in-plan, safe fixes):**
  - **F1 (major):** `POST /tasks` E2E consumer inventory incomplete —
    `insights-flow.spec.ts:141`, `release-visibility-roles.spec.ts:113`,
    `teamwork-rewards.spec.ts:41,90` also consume the pruned endpoint. Fixed:
    added to §4 migration list and §5 manifest.
  - **F2 (minor):** §1.6 "prefill is a no-op" imprecise — `applyTemplate`
    consumes `template.name`, so preset labels land in the task-name field.
    Wording corrected.
  - **F3 (minor):** §5 route-guard manifest line under-specified; pinned to
    `tests/unit/RouteMatrixTest.php`.
  - **F4 (minor):** D3 bound [0,7] vs form control `max="14`
    (`TaskEditForm.vue:210`); §3.5 amended to align the control (per ratified
    D3 answer).
- **Decisions:** D2, D3 (bound [0,7] + form alignment), D5, D6, D7, D9
  ratified as proposed; D1, D4, D8 user pre-ratifications confirmed — all via
  one batched structured question (answers recorded in §9). D8 interpretation
  recorded: the masterplan's "checklist definition" persistence bullet is
  satisfied by `ChecklistService` remaining the sole owner-managed checklist
  surface (WP2 D4; WP7 consumes read-only) — no checklist scope in WP4.
- **Gates:** docs-only change set — `npm run docs:encoding`,
  `npm run backlog:check` green (no PHP/JS touched; build/test gates not
  applicable to a plan activation).

### Standard B (final code review) · 2026-09-06

- **Scope:** the plan's full §5 manifest — 54 modified files + 5 untracked
  (`lib/Exception/ValidationException.php`,
  `lib/Service/TaskCreationService.php`, `tests/unit/Service/TaskCreationServiceTest.php`,
  `tests/integration/Service/TaskCreationAggregateTest.php`,
  `tests/integration/Service/TaskCreationConcurrencyTest.php`) on `master` @
  `7cb798fd`, index empty; pre-review unstaged diff fingerprint (git binary
  diff, SHA-1) `7291137a1d1867d50c86fb5d31d372f86a61b797`, staged diff empty.
  Full record: [`archive/CODE_REVIEW_2026-09-06.md`](../../backlog/archive/CODE_REVIEW_2026-09-06.md).
- **Outcome:** `PASS-WITH-FIXES` → plan moved `active/ → done/`.
- **Findings & dispositions (all resolved):**
  - **F1 (major, logic):** `validateTaskData` update path let a present
    `name: null` pass (`?? null` conflates absent and null) → `setName(null)`
    → NOT NULL column → 500, where the pre-WP4 controller flow answered a
    curated 400. **User decision (batched question): fix inline + test.**
    Fixed: key-presence check (`array_key_exists`) restores the 400
    "Task name is required"; regression tests added
    (`TaskServiceWhitelistTest::testUpdateTask_NullName_RejectedAsValidation400`,
    provider row `null name is rejected, never passed through`).
  - **F2 (minor, i18n):** `Unknown or immutable field: ` concatenated raw
    (user-visible 400) in the new `TaskCreationService` site and the cloned
    `TaskService` site — violates the `t()`-only rule. Fixed: both sites via
    `t('Unknown or immutable field: %1$s', [$field])`, l10n en/de keys added,
    `TaskServiceWhitelistTest` l10n mock upgraded to parameter substitution.
  - **F3 (minor, logging):** `TaskCreationService` injected `AppLogger`
    unused; the cross-house creation denial lost its `securityWarning` (the
    deleted `createRoomTask` logged it; `RoomService` throws silently).
    Fixed: catch-log-rethrow around `validateRoomOwnership` (log-only,
    stable keys `service/roomId/houseId`).
  - **F4 (minor, dead code):** 3 orphaned `validateTaskData` mock setups in
    `TaskControllerTest` (the controller no longer calls it). Removed.
  - **F5 (nit):** ambiguous `(D5)` actor-policy comment refs →
    `(CS-PLAN-061 D5)` (service) vs CS-PLAN-063 D6 (UI gating). Clarified.
  - **F6 (nit):** `createFromTemplate` store action did not cache the
    definition in the tasks store (custom path does). Fixed + new Jest spec.
  - **F7 (nit, recorded, no change):** `assigneeUserIds` silently drops
    non-string entries (definition fields are strict-400). Benign — nothing
    invalid can persist (filter precedes insert; membership is revalidated
    under the lock); strictness alignment is future WP7 payload-reconciliation
    territory if ever needed.
- **Deviations ratified:** none new; §11 record (F5–F7 implementation
  deviations, journey placement, `fetchAssignees` retention) accepted as
  documented — §1.6's plan pin was already corrected in §11.
- **Gates (rerun by the review after fixes):** Docker PHPUnit unit 845 OK
  (+2 review tests), integration 278 OK (+10 pre-existing documented skips,
  incl. the two `proc_open` creation races executing on MySQL), `composer
  cs:check` 0 findings, Jest 815 OK (+1 review spec), ESLint clean,
  `l10n:check-keys`/`docs:encoding`/`backlog:check` green, webpack build
  clean, Playwright chromium 92 passed / 7 pre-existing skips,
  chromium-serial-db 92 / 5 (rerun post-fix), chromium-scheduling-owner
  31 / 0 (pre-fix; only store/i18n/logging surfaces changed afterwards,
  which that pool does not exercise).
- **Environment note (review-caused, no product defect):** the first E2E
  attempt failed in `auth.setup` because the reviewer's `docker compose up`
  omitted `TEST_DB_PROFILE=family` (entrypoint created only `minimal`
  users); the documented disposable reset (`down -v` + `up -d` with the
  profile) restored the family identities. Reviewer also re-learned: the
  compose test service keeps Nextcloud config in the container layer —
  changing compose env requires a full `down -v` reset, not a recreate.
- **Dispositions executed:** CR-WF-03 both halves delivered → archived to
  `archive/2026-09.md`; CR-SVC-41 WP4 slice annotated (item stays open for
  WP8); CR-062-01 already archived; CS-PLAN-058 §9 WP4 row → Done;
  `ACTIVE_TASKS.md` updated; durable docs (API README, task-model concept,
  locking note) verified as integrated.

## 11. Implementation Record (2026-09-05)

All §6 steps executed; §8 acceptance criteria verifiably met (Standard B
review to confirm). Full gate green: PHPUnit unit 843 OK, integration 278 OK
(10 pre-existing documented skips), `composer cs:check` clean, Jest 814 OK,
Playwright chromium 92 passed / 7 pre-existing skips (incl. the two
`proc_open` creation races), `npm run lint` + `docs:encoding` +
`l10n:check-keys` + `backlog:check` green.

Implementation deviations and discoveries, recorded for the Standard B
review:

- **F5 (plan pin wrong): §1.6 claimed `fetchAssignees` has no consumer.**
  `TodayView.vue:270` actively consumes it (N+1 per-item assignee fetch for
  the My-tasks filter). The action was **kept**; only the dead
  `createTaskWithRoomTask` chain and `tasksStore.createTask` were deleted.
  The `fetchAssignees` removal moves to the WP7 payload-reconciliation scope
  (the N+1 itself is CR-WF-04 territory).
- **F6 (found by E2E): `TaskMapper::create` never persisted `flexDays`.**
  The creation contract requires it (§3.3); the mapper gained an optional
  `int $flexDays = 0` parameter and the aggregate passes it (fresh-install
  safe; integration permutation test pins the round-trip).
- **F7 (found by E2E): the form's `nextDue` leaked into the `task`
  definition payload** ("Unknown or immutable field: nextDue") — the view
  destructuring now separates `nextDue` from the definition. Pinned by the
  TaskManager Jest re-pin.
- **Journey placement:** the atomic-creation GUI journey (create-with-
  assignee → one POST → immediate assignee render; API-forced failure → no
  orphan) lives in `3-floor-house.spec.ts` (serial, seed-mutating pool) —
  the plan's suggested `task-manager.spec.ts` is in the parallel
  no-seed-mutation pool, which forbids exactly these writes.
- **Validation nuance:** `validateTaskData` grew a `requireName` flag —
  creation requires the name, partial updates only validate it when present;
  empty strings clear date fields (client blanks), all strict-400 otherwise.
- **Test construction updates** (pure contract churn, no behavior): the
  dropped `TemplateService` ctor param (`RoomTaskService`), the added
  `TaskCreationService` param (`RoomTaskController`), and the removed
  `TransactionExecutor` param (`TaskAssigneeMapper` — its last
  self-transactional method was deleted).
- **l10n:** new strings added to `l10n/{en,de}.json` (backend validation
  messages, creation endpoint payloads, form/preview labels); the two
  partial-success toast strings were removed; `templates/chorestrike.pot`
  is generated from source on release tooling and not hand-edited.
