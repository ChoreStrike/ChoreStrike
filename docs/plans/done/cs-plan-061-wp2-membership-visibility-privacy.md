# CS-PLAN-061: WP2 — Membership, Role, Visibility, and Privacy Boundaries

> **Status:** Done (final Standard B review PASS-WITH-FIXES, 2026-09-04)
> **Effort:** L
> **Priority:** P1
> **Depends on:** [CS-PLAN-058](../active/cs-plan-058-core-workflow-bottom-up-masterplan.md) (active; this plan executes its work package WP2 — WP0 and WP1 are Done), WP1 persistence baseline (2026-09-03)
> **Related backlog items:** CR-WF-01 (owned), CR-053-16 (owned), CR-053-17 (owned), CR-053-18 core remainders (owned), FEAT-UX-015 owner-transfer/last-owner semantics only (owned)

## 1. Problem Summary

CS-PLAN-058 §3/WP2 requires service-layer authorization to match the recorded
household model: invite-only membership after the first owner, creator-plus-
assignees private-task access, owner-only structure mutations, standardized
403/404 responses, and log hygiene. The live tree does not satisfy this; all
facts below were verified on 2026-09-03.

### 1.1 Auto-join membership violates invite-only (CR-WF-01)

- `AuthorizationService::ensureHouseMember`
  (`lib/Service/AuthorizationService.php:94-135`) auto-creates a membership
  for **any** authenticated user on a miss — the second user becomes `member`
  (`:121`). The recorded decision (2026-09-02) is invite-only after the
  first owner.
- 57 controller call sites across 11 controllers use `ensureHouseMember`
  for ordinary reads and mutations (House ×6, RoomTask ×14, Floor ×5,
  Minigame ×8, Scheduling ×5, User ×5, Room ×4, Task ×4, Setup ×2,
  Game ×3, Checklist ×1).
  Two feed it a **target-derived house id**, auto-joining a foreign house on
  a read path: `UserController::titles` (`lib/Controller/UserController.php:335`)
  and `RoomController::create` (`lib/Controller/RoomController.php:113-114`).
- Consequence: removing a member
  (`HouseService::removeHouseMember`, `lib/Service/HouseService.php:103-117`)
  is **not durable** — the removed user silently re-joins as `member` on the
  next `user#current` boot call (`UserController::current` at
  `lib/Controller/UserController.php:103`; frontend auto-init in
  `src/store/userAutoInit.js:18-32`).

### 1.2 Three divergent private-task semantics (CR-WF-01)

The recorded decision: private tasks are accessible to their **creator plus
explicit assignees**. Three incompatible matrices coexist:

| Surface | Private semantics | Location |
|---------|-------------------|----------|
| Definition lists | creator-only | `TaskMapper::findVisibleByHouse` `lib/Db/TaskMapper.php:193-196` |
| Task update | creator-only | `TaskService::updateTask` `lib/Service/TaskService.php:185-189` |
| Checklist access | creator-only | `ChecklistService::assertTaskAccessible` `lib/Service/ChecklistService.php:88-89` |
| Lifecycle (reactivate/due-date/uncomplete) | assignees-only (creator excluded) | `RoomTaskService::assertLifecycleVisibility` `lib/Service/RoomTaskService.php:951-961` |
| Completion | assignees-only (creator excluded), inline | `RoomService::completeRoomTask` `lib/Service/RoomService.php:281-288` |
| Frontend hint | "Only assigned members" | `src/components/Tasks/TaskEditForm.vue:432-441` |

### 1.3 Room-task reads leak private/admin-only definitions (CR-WF-01)

No `RoomTaskMapper` query contains a visibility predicate. Leaking surfaces:
`RoomTaskController::index` (`lib/Controller/RoomTaskController.php:123-171`),
`overdue` (`:516-540`), `dueToday` (`:547-571`), `range` (`:581-641` —
serializes the full task definition at `lib/Service/RoomTaskService.php:915`),
`filteredDaily` (`:705-743` → `RoomTaskService.php:803-832`). A private
task's name, description, and `ownerUserId` (`lib/Db/Task.php:253-254`) are
returned to every house member.

### 1.4 Unguarded mutations and missing actor policy (CR-WF-01)

- `setAssignees` (`lib/Service/RoomTaskService.php:502-508`),
  `bulkAssignTasks` (`:698-751`), `createRoomTask` (`:221-292` — materializes
  any private/admin-only definition), and `deleteRoomTask` (`:612-619`) have
  **no visibility gate**.
- `assertHouseMembers` (`lib/Service/RoomTaskService.php:454-466`) validates
  only that **targets** are members; no path checks the **acting** user's
  role. Any member (or child) can re-assign, bulk-assign, or delete any task.
- Owner-only structure/checklist-definition mutations are enforced only in
  controllers, not services (violates ADR-006): floor create/reorder
  (`lib/Controller/FloorController.php:73-74,219-220`) and room rename
  (`lib/Controller/RoomController.php:207`) are merely membership-gated;
  `HouseService::updateHouse` (`lib/Service/HouseService.php:209-217`) and
  checklist-definition CRUD (`lib/Service/ChecklistService.php:132-206`) have
  no service-level owner gate. The frontend already hides these controls for
  non-owners (`src/views/Settings.vue:39,92,101,212`,
  `src/components/Tasks/TaskList.vue:223`) — the backend must match.
- Dead unguarded mutators whose routes were pruned remain in
  `RoomTaskService`: `assignTask` (`:403-412`), `unassignTask` (`:419-426`),
  `addAssignee` (`:519-528`), `removeAssignee` (`:541-545`),
  `deactivateTask` (`:552-558`).
- `ownerUserId` can be transferred via `TaskService::updateTask` (whitelisted
  in `MUTABLE_FIELDS`, `lib/Service/TaskService.php:35-43`) without checking
  that the target is a house member.

### 1.5 403/404 inconsistency and missing 404 mapping (CR-053-16/18)

- The same private task yields 404 on `task#update`
  (`lib/Controller/TaskController.php:280-298` — anti-enumeration precedent)
  but **403** on complete/uncomplete/reactivate/update-due-date via
  `ForbiddenException` from `assertLifecycleVisibility` and
  `RoomService.php:282,287` — an enumeration oracle.
- `UserController::remove`/`updateRole` find the target row before the owner
  gate (`lib/Controller/UserController.php:211-212,266-267`): a foreign-house
  id returns 403, a nonexistent id 404 — confirming which global house_user
  ids exist.
- Nonexistent/foreign ids become 500 (raw
  `OCP\AppFramework\Db\DoesNotExistException` or uncaught app
  `NotFoundException`) in RoomTaskController ×6 (`assignees`, `setAssignees`,
  `reactivate`, `updateDueDate`, `complete`, `delete`), FloorController
  `update`/`delete`, and RoomController `create`/`update`/`delete`/
  `updateCleanliness`. Root cause: `FloorService::validateFloorOwnership`
  (`lib/Service/FloorService.php:57-63`), `RoomService::validateRoomOwnership`
  (`lib/Service/RoomService.php:93-100`), `RoomService::getFloor`/`getRoomTask`
  (`:108-110,205-215`), and `TaskService::getTask` (`:76-78`) do not wrap
  `DoesNotExistException`.
- `SchedulingController` throws bare `\Exception('Not authenticated')` at
  `lib/Controller/SchedulingController.php:65,106` → logged but returned as
  **500** instead of 403.

### 1.6 Silent exception swallowing and log hygiene (CR-053-17, CR-WF-01)

- Generic `catch (\Exception)` blocks with zero logging in RoomTask (15
  sites), Checklist (7 — `serverErrorResponse` does not log,
  `lib/Controller/ControllerErrorFormatter.php:108-120`), Floor (5), Minigame
  (9), Room (4), Test (2), User (7) controllers; `PageController` swallows
  two `\Throwable` in `registerPwaHeaders` (`lib/Controller/PageController.php:52,69`);
  `SetupController` uses a weaker hand-rolled variant
  (`lib/Controller/SetupController.php:77-82,140-145`).
- `ControllerLoggingTrait` exists (`lib/Controller/ControllerLoggingTrait.php:21-75`)
  and is used by Task/Scheduling/House/Game controllers only.
- User-authored values in logs: `HouseService::getOrCreateHouse` logs the
  house name (`lib/Service/HouseService.php:172-176`); the frontend
  serializes full API response bodies (member display names, invite emails)
  through `logger.error` (`src/utils/logger.js:77-89` no redaction;
  e.g. `src/components/Setup/SetupWizard.vue:554`, `src/store/user.js:55`).

### 1.7 CR-053-18 core remainders

- `saveScore` accepts any `gameId` (no whitelist —
  `lib/Service/MinigameService.php:89-145` validates only score) and has no
  call-frequency cap; each call mints `floor(score/10)` coins
  (`:110`) — bounded replay farming.
- No route has `requirements` (`appinfo/routes.php` — zero entries; e.g.
  `{id}` routes at `:19-26,34-35,49-54,84-87`, `{gameId}` at `:128-135`).
- Weekday divergence: owner path validates case-sensitively
  (`lib/Controller/SchedulingController.php:279-291`), self-service path
  case-insensitively (`:264-268`), and `SchedulingService::applyMemberUpdates`
  stores keys verbatim (`lib/Service/SchedulingService.php:206-224`) while
  every reader looks up lowercase keys (`:241-245`;
  `RoomTaskService.php:1017-1020`; `TaskDueReminderService.php:168,191-192`)
  — a `'Monday'` write silently disables the availability filter.
- Claim notification passes the display name where the notifier requires a
  uid (`lib/Service/RoomService.php:322` →
  `lib/Notification/ChoreStrikeNotifier.php:85` — `id` must be a uid).
- Medal notifications collapse: objectType `medal` + objectId `weekStart`
  only (`lib/Service/NotificationService.php:99-108`) — Nextcloud dedups on
  (app, user, objectType, objectId), so two medals in one week collapse
  (contrast the fixed pack-qualified pattern at `:168-199`).
- The reminder pass aborts on one orphaned task:
  `TaskDueReminderService::dispatchReminders` calls `taskMapper->find($taskId)`
  unguarded in the loop (`lib/Service/TaskDueReminderService.php:171-182`);
  the escaping `DoesNotExistException` kills the whole pass via the
  pass-level `catch (\Throwable)` (`:97-103`).
- Test-mode routes carry `@NoCSRFRequired` with a runtime
  `NEXTCLOUD_TEST_MODE` gate only (`appinfo/routes.php:122-125`;
  `lib/Service/TestDataService.php:485-488`).

### 1.8 Frontend has no authorization state (CR-WF-01)

- `isHouseOwner` (`src/store/user.js:25`) is the only role primitive; no
  child/member distinction, no authorization state.
- A non-member gets `error.message` strings and generic ErrorState views
  (`src/views/Dashboard.vue:17-21`); `ApiError` keeps status/code only
  nested in `data.error.code` (`src/services/api.js:13-27,46-57`) and no
  store/view branches on it. There is no invited/removed/not-authorized
  state anywhere.
- The setup wizard entry is not owner-gated (`src/views/Dashboard.vue:38-43`):
  a non-owner member of an empty house runs the wizard and then hits
  swallowed 403s (house-name save `src/components/Setup/SetupWizard.vue:515-521`,
  invites `:535-561`).
- The authorization integration matrix `TaskAuthorizationTest` has 10
  unconditional skips (`tests/integration/Controller/TaskAuthorizationTest.php:53-127`)
  — the WP2 exit gate requires it rewritten with no live skips.

## 2. Strategy

Enforce boundaries bottom-up, each step gated by a failing or missing test
first (CS-PLAN-058 §2.3):

1. **Make membership invite-only at the primitive**: restrict
   `ensureHouseMember` to the one-time first-owner bootstrap, sweep ordinary
   call sites to the pure `assertHouseMember`, and designate the bootstrap
   entry points.
2. **Centralize the visibility matrix** in one policy service (creator ∪
   assignees for private; owner for admin-only) and apply it to definitions,
   room-task reads, mutations, checklists, lifecycle, and completion.
3. **Move actor and owner checks into services** (ADR-006): assignment actor
   policy, owner-only structure/checklist-definition/house mutations,
   target-principal validation.
4. **Standardize the response contract**: 404 anti-enumeration for
   foreign/visibility-denied ids, 403 for role denials; close the 500 gaps;
   adopt controller logging; strip user-authored values and raw payloads
   from logs.
5. **Close the CR-053-18 hardening remainders** (saveScore, route
   requirements, weekdays, notifier, medal dedup, reminder isolation).
6. **Give the frontend an explicit authorization state** driven by the
   authoritative `user#current` payload, and align control visibility with
   the backend matrix.
7. **Rewrite the authorization integration matrix** with zero skips and run
   the full quality gate.

No aggregate/transaction redesign here: WP3 owns setup atomicity, WP4 the
creation/assignment aggregate, WP5 completion/undo. WP2 only decides **who**
may do what and enforces it consistently.

## 3. Backend / Frontend Changes

### 3.1 Invite-only membership and bootstrap separation (CR-WF-01)

- `AuthorizationService::ensureHouseMember` keeps its name and transactional
  bootstrap (WP1 lock order) but changes semantics: auto-create fires **only
   when the house has zero members** (first-owner bootstrap); when members
   exist and the current user is not among them it throws
   `ForbiddenException` (same message as `assertHouseMember` — no new
   enumeration vector). Docblock updated: bootstrap-only.
- Designated bootstrap entry points keep `ensureHouseMember` (decision D1):
  `user#current`, `house#get`, `user#invite` (existing CR-SVC-47 preamble),
  `setup#catalog`, `setup#apply`. The other 52 call sites across the same 11
  controllers switch to `assertHouseMember` — including the two
  target-derived-id bugs (`UserController::titles`,
  `RoomController::create`).
- Removal becomes durable end-to-end: the deleted membership row is no
  longer resurrected by auto-join; the next request of the removed user 403s.
- `UserController::remove`/`updateRole` resolve the **caller's** instance
  house first, then look the target up **scoped to that house**; a foreign or
  nonexistent house-user id maps to 404 (closes the 403/404 oracle).
- FEAT-UX-015 semantics decision (D6) recorded in ADR-010: the last owner
  cannot leave (consistent with `CANNOT_REMOVE_OWNER` /
  `CANNOT_DEMOTE_LAST_OWNER`); a member leave endpoint + Settings UI remain
  FEAT-UX-015 scope and stay open in the backlog.

### 3.2 One visibility policy service (CR-WF-01)

- New `lib/Service/TaskAccessPolicy` (small, mapper-backed):
  - `canView(Task $task, HouseUser $viewer, ?array $assigneeUserIds): bool`
    — public: member; private: `ownerUserId === viewer` **∪** assignee;
    admin-only: `viewer->isOwner()`.
  - `assertCanView(...): void` — throws the app `NotFoundException`
    (anti-enumeration 404, TaskController precedent).
  - `filterVisible(array $roomTasks, HouseUser $viewer): array` — batch
    post-fetch filter for read paths (one house/task-scoped assignee fetch
    via a new `TaskAssigneeMapper::findUserIdsByTaskIds` primitive; no N+1).
- `TaskMapper::findVisibleByHouse` private branch becomes creator ∪
  assignees (`EXISTS` on `task_assignees` joined through `room_tasks`);
  admin-only branch unchanged.
- `RoomTaskService::assertLifecycleVisibility` and the inline gates in
  `RoomService::completeRoomTask` delegate to the policy (creator now
  included; one matrix everywhere).
- `ChecklistService::assertTaskAccessible` delegates to the policy for the
  visibility decision (creator ∪ assignees for private), keeping its
  house-linkage and DoesNotExist → NotFound conversion.
- `TaskService::updateTask` visibility block delegates to the policy.

### 3.3 Read-path visibility filtering (CR-WF-01)

Apply `TaskAccessPolicy::filterVisible` before serialization/response in
`RoomTaskService`: `getRoomTasks`, `getOverdueTasks`, `getDueTodayTasks`,
`getDueWithinDaysTasks`, `getScopeTasksScopedToHouse`,
`getClaimableOverdueTasks`, `getDailyTasksWithDetails`/
`getFilteredDailyTasksForUser`, and `getRangeTasks` (full task definition at
`:915` included). `TaskService::getVisibleTasks`/`findVisibleByHouse` gains
the assignee branch. Completion-history rows stay house-scoped (aggregate
stats; they carry ids, not task content — recorded as decision D9).
SQL-level push-down is explicitly deferred to WP8 (contract freeze first).

### 3.4 Actor policy and service-level mutation gates (CR-WF-01, ADR-006)

Role/actor matrix (decision D5): owner — everything; member — task-domain
mutations within the visibility matrix; child — execution only (complete,
checklist progress toggle, own preferences/minigames); no task create/
update/delete/assign, no structure, no checklist definitions.
Clarified at the Standard B review (2026-09-04, user decision): the
lifecycle executions reactivate/due-date/uncomplete are visibility-gated
EXECUTION surface (not actor-gated) — the ungated UndoSnackbar undo flow
requires them for every viewer; `assertCanManageTasks` guards the
management surface (create/update/delete/assign).

- **Assignment**: `setAssignees` and `bulkAssignTasks` first
  `assertCanView` (404 for invisible tasks), then validate the acting user
  is owner or member (child → 403); target membership validation
  (`assertHouseMembers`) stays. `createRoomTask` and `createFromTemplate`
  `assertCanView` the definition before materializing; `deleteRoomTask`
  asserts visibility + actor.
- **Task definitions**: `TaskService::createTask`/`updateTask` receive the
  acting `HouseUser` (mechanical parameter threading from controllers);
  child actors → 403. `updateTask` additionally validates a changed
  `ownerUserId` target via `assertUserInHouse` (closes the unvalidated
  transfer). The controller-level gates in `TaskController::update`
  (`:284-333`) stay as defense in depth.
- **Structure/house (owner-only in services)**:
  `FloorService` create/update/delete/reorder, `RoomService` create/update/
  delete, `HouseService::updateHouse`, and `HouseSetupService::applySetup`
  call `requireHouseOwner` internally (setup keeps its
  `ensureHouseMember` bootstrap preamble before the owner gate).
  `RoomService::updateCleanliness` stays membership-gated (game mechanic,
  not structure). Controller checks remain as defense in depth.
- **Checklist definitions**: `addTaskItem`/`updateTaskItem`/`deleteTaskItem`/
  `reorderTaskItems` become owner-only in `ChecklistService` (decision D4);
  progress read/toggle stays on the visibility matrix (execution surface).
- **Dead mutators**: delete `RoomTaskService::assignTask`, `unassignTask`,
  `addAssignee`, `removeAssignee`, `deactivateTask` after verifying zero
  callers (routes pruned; pre-release no-dead-code policy). `TaskService::`
  `assignTask`/`unassignTask` are verified for callers and deleted if dead.

### 3.5 403/404 standardization and 404 mapping (CR-053-16/18)

- Standard (decision D3): foreign-house/nonexistent ids and visibility
  denials on id-addressed resources → **404** anti-enumeration shape;
  role/actor denials on otherwise visible house resources → **403**.
  Documented in `docs/standards/guidelines/security.md` and `docs/api/README.md`.
- Services wrap `DoesNotExistException` into the app `NotFoundException`:
  `FloorService::validateFloorOwnership`, `RoomService::validateRoomOwnership`/
  `getFloor`/`getRoomTask`, `TaskService::getTask`/`updateTask`.
- Controllers add `NotFoundException` → 404 catches: RoomTaskController
  (`assignees`, `setAssignees`, `reactivate`, `updateDueDate`, `complete`,
  `delete`), FloorController (`update`, `delete`), RoomController (`create`,
  `update`, `delete`, `updateCleanliness`).
- Visibility `ForbiddenException`s from `assertCanView` remain
  `NotFoundException`s end-to-end (404); lifecycle/role denials stay 403 —
  after §3.2 both come from one policy, so the status is uniform.
- `SchedulingController:65,106`: throw `ForbiddenException` instead of bare
  `\Exception` (403, not 500).

### 3.6 Controller logging and log hygiene (CR-053-17, CR-WF-01)

- Adopt `ControllerLoggingTrait` + `initializeControllerLogger` in
  RoomTask, Checklist, Floor, Minigame, Page, Room, Test, User controllers;
  replace SetupController's hand-rolled catches with the trait. Every
  generic `catch (\Exception)` logs via `logControllerException` and returns
  the existing 500 envelope.
- `PageController::registerPwaHeaders` best-effort swallows become
  `debug`-level logged exceptions (documented best-effort stays).
- Backend log hygiene: `HouseService::getOrCreateHouse` stops logging the
  house name (houseId only); grep-audit `lib/` for other user-authored
  values (names, emails, free-text) in log contexts and remove them.
- Frontend log hygiene: `src/utils/logger.js` redacts `ApiError` objects to
  `{status, code, message}` (no response body); stores/views keep passing
  errors to the logger. `SetupWizard` invite-failure logging no longer
  serializes emails/response bodies.

### 3.7 CR-053-18 core remainders

- `MinigameService`: `VALID_GAMES` constant whitelist (unknown `gameId` →
  400 `VALIDATION_ERROR`); per-user daily saveScore cap
  (`MinigameScoreMapper::countByUserSince`, limit 100/day → 429
  `MINIGAME_RATE_LIMITED`) (decision D7).
- `appinfo/routes.php`: add `requirements` (`\d+` for id params,
  `[a-z0-9-]+` for `{gameId}`/`{profile}`/`{userId}`, weekday-tolerant
  patterns where applicable).
- Weekdays: `SchedulingService::applyMemberUpdates` and
  `updatePreferencesByNextcloudUserId` normalize keys with `strtolower`
  before persisting; `validatePreferencesSection` validates
  case-insensitively (align both paths; readers unchanged).
- Claim notification: `RoomService` dispatches `claimerUserId` +
  `claimerDisplayName`; `NotificationService::notifyTaskClaimed` and
  `ChoreStrikeNotifier` use uid as `id` and display name as `name`.
- Medal notifications: objectId becomes `"{weekStart}:{medalType}"`
  (pack-qualification precedent).
- `TaskDueReminderService::dispatchReminders`: per-task
  `DoesNotExistException` catch → warning log + skip that task, pass
  continues.
- Test-mode routes (decision D8): keep `@NoCSRFRequired` + the runtime
  `NEXTCLOUD_TEST_MODE` gate; document the test-only contract and the
  "never set in production" rule in `docs/standards/guidelines/security.md`.

### 3.8 ADR and durable docs

- New `docs/adr/ADR-010-invite-only-house-membership.md`: one-time
  first-owner bootstrap condition, designated bootstrap endpoints,
  invite-only thereafter, durable removal, last-owner leave semantics (D6),
  relationship to ADR-006 (narrows its bootstrap description; the
  service-authorization decision stands).
- ADR-006 gets a short annotation pointing to ADR-010 for the bootstrap
  flow.
- `docs/standards/guidelines/security.md`: authorization primitives
  (`ensureHouseMember` bootstrap-only), 403/404 standard, test-mode contract.
- `docs/api/README.md`: invite-only membership, 403/404 standard, saveScore
  validation/rate cap, checklist-definition owner-only.

### 3.9 Frontend changes (CR-WF-01)

- `src/services/api.js`: `ApiError` gains first-class `status` and `code`
  fields (from `error.code`); response body no longer leaves `data` except
  through explicit access.
- `src/store/user.js`: add `role`, `isChild`, `canManageTasks`
  (owner ∥ member) getters beside `isHouseOwner`; membership state
  (`'unknown' | 'member' | 'denied'`) set from `initializeCurrentUser`
  (403/`FORBIDDEN` → `denied`).
- New `src/components/Common/NotAuthorizedState.vue` (EmptyState/ErrorState
  pattern): icon + localized copy ("You are not a member of this household
  yet. Ask the owner to invite you.") + retry. `App.vue` renders it
  app-wide instead of nav/views when state is `denied` (removed vs.
  never-invited deliberately indistinguishable — anti-enumeration).
- Role-matched controls: task create/edit/delete, assignee chips, bulk
  toolbar, due-date/reactivate actions hidden for children
  (`canManageTasks`); setup wizard entry button auto-open gated to
  `isHouseOwner` (`Dashboard.vue`); checklist definition editing already
  owner-gated (`TaskList.vue:223`) now matches the backend.
- `TaskEditForm.vue` visibility hints corrected to the actual matrix
  (private: "Only you and assigned members can see and complete this task";
  admin-only: owner wording; explain assignee implication) via `t()`;
  l10n artifacts updated in the same change.
- `MemberList.vue`: branch on `error.code` for
  `CANNOT_REMOVE_OWNER`/`CANNOT_DEMOTE_SELF`/`CANNOT_DEMOTE_LAST_OWNER`
  with specific localized messages (codes now first-class).

## 4. Tests

- **Unit (PHPUnit, host):**
  - `AuthorizationService`: member → returns; zero members → bootstraps
    owner; members exist + stranger → `ForbiddenException` (mocked mappers).
  - `TaskAccessPolicy` matrix: public/private/admin-only × owner/member/
    child/creator/assignee/stranger.
  - Weekday normalization; medal objectId qualification; notifier uid/name
    params; saveScore gameId whitelist + daily cap; reminder pass continues
    past an orphaned task (mocked mapper).
- **Integration (Docker, real DB):**
  - **Rewrite `TaskAuthorizationTest`** (10 unconditional skips → live
    tests): owner/member/child × foreign-house/non-member/removed-member
    across read, create, assign, complete, lifecycle, structure, checklist —
    positive and negative, current API via `IntegrationTestCase`
    (`actingAs`/`getController`). Zero skips in the file.
  - New `InviteOnlyMembershipTest`: empty house + first user via
    `user#current` → owner (exactly one); second authenticated user → 403
    **and no membership row created**; invited member → 200; removed member
    → 403 after reload and direct API calls (durable removal).
  - Visibility matrix extensions: `PrivateTaskAuthorizationTest` (creator ∪
    assignee across definition list, room-task index/range/filteredDaily,
    checklist, lifecycle, completion), `AdminOnlyTaskTest`,
    `TaskLifecycleVisibilityApiTest` (creator now included),
    `RoomTaskIndexAuthorizationTest`, `ChecklistApiTest` (assignee access;
    owner-only definition CRUD 403 for member/child),
    `BulkAssignIntegrationTest` (child 403; non-viewer 404).
  - 404 mapping: `FloorControllerIntegrationTest`,
    `RoomControllerIntegrationTest`,
    `RoomTaskControllerIntegrationTest`, `UserControllerIntegrationTest`
    extended with nonexistent + foreign ids → 404 (no 500), including
    `remove`/`updateRole` foreign-id 404.
  - Actor policy: member floor/room create/update → 403; child task
    create/assign/delete → 403; child complete + checklist toggle → 200.
  - E2E-profile seed (`TestDataService`) still passes with invite-only
    (seeds create memberships directly).
- **Jest:** user store role getters + `denied` state; `NotAuthorizedState`
  render; App-level gate; `api.js` `status`/`code` fields; logger redaction
  of `ApiError`; `TaskEditForm` hint copy; child control hiding in
  `TaskList`.
- **E2E (Playwright):** audit family-profile specs for flows that now 403
  (member/child performing owner-only or child-forbidden operations) and
  fix them to act as the correct role or assert the denial; run `chromium`,
  `chromium-serial-db`, `chromium-scheduling-owner`.
- **Gate (CS-PLAN-058 §4.5):** full gate including Docker unit +
  integration, `composer cs:check`, all applicable Playwright projects.

## 5. File Manifest

```
new:      docs/adr/ADR-010-invite-only-house-membership.md
new:      lib/Service/TaskAccessPolicy.php
new:      src/components/Common/NotAuthorizedState.vue
new:      tests/integration/Controller/InviteOnlyMembershipTest.php
new:      tests/unit/Service/{TaskAccessPolicyTest,AuthorizationServiceBootstrapTest}.php
modified: lib/Service/AuthorizationService.php (bootstrap-only ensureHouseMember)
modified: lib/Service/{HouseService,TaskService,RoomTaskService,RoomService,FloorService,ChecklistService,HouseSetupService,SchedulingService,MinigameService,NotificationService,TaskDueReminderService}.php
modified: lib/Db/{TaskMapper,TaskAssigneeMapper,MinigameScoreMapper}.php
modified: lib/Notification/ChoreStrikeNotifier.php
modified: lib/Controller/{HouseController,SetupController,FloorController,RoomController,TaskController,RoomTaskController,ChecklistController,UserController,SchedulingController,MinigameController,GameController,PageController,TestController}.php
modified: appinfo/routes.php (requirements)
modified: src/services/api.js · src/utils/logger.js · src/store/user.js
modified: src/App.vue · src/views/{Dashboard,Settings}.vue
modified: src/components/{Tasks/TaskEditForm,Tasks/TaskList,Members/MemberList,Setup/SetupWizard,Common/index}.vue
modified: tests/integration/Controller/{TaskAuthorizationTest,PrivateTaskAuthorizationTest,AdminOnlyTaskTest,TaskLifecycleVisibilityApiTest,RoomTaskIndexAuthorizationTest,ChecklistApiTest,BulkAssignIntegrationTest,FloorControllerIntegrationTest,RoomControllerIntegrationTest,RoomTaskControllerIntegrationTest,UserControllerIntegrationTest}.php
modified: tests/unit/**/* where fixtures reference changed contracts
modified: tests/e2e/**/* where roles hit new boundaries
modified: docs/adr/ADR-006-service-layer-authorization.md (annotation)
modified: docs/standards/guidelines/security.md · docs/api/README.md
modified: l10n/{en.json,de.json,templates/chorestrike.pot} (hint copy, not-authorized copy, minigame/rate-limit strings)
modified: docs/backlog/{backlog.md,ACTIVE_TASKS.md} + docs/backlog/archive/2026-09.md (completion entries)
modified: docs/plans/active/cs-plan-058-core-workflow-bottom-up-masterplan.md (§9 progress row WP2)
modified: docs/plans/new/cs-plan-061-wp2-membership-visibility-privacy.md (this plan; moves to active/ on review pass)
```

**As-built reconciliation (Standard B review, 2026-09-04):** the ADR landed as
`docs/adr/ADR-010-invite-only-membership-visibility-matrix.md` (broader title —
it records the visibility matrix too); `lib/Exception/RateLimitException.php`
(new, the 429 carrier) and `tests/unit/components/NotAuthorizedState.spec.js`
join the new-file list; the bootstrap-semantics unit matrix was consolidated
into the existing `tests/unit/Service/AuthorizationServiceExceptionTest.php`
instead of a separate `AuthorizationServiceBootstrapTest`; `src/views/Settings.vue`
needed no change (its owner gating already matched); `src/components/Common/index.js`
gained the `NotAuthorizedState` barrel export during the review.

## 6. Implementation Order

1. Write failing tests first: invite-only membership matrix, rewritten
   `TaskAuthorizationTest` scenarios (red), visibility-matrix read leaks.
2. §3.1 membership semantics + call-site sweep + caller-scoped
   remove/updateRole; integration tests green.
3. §3.2 `TaskAccessPolicy` + §3.3 read filtering; matrix tests green.
4. §3.4 mutation gates + actor policy + dead-mutator deletion;
   actor/visibility tests green.
5. §3.5 404 mapping + 403/404 standardization + §3.6 logging adoption and
   log hygiene; mapping tests green.
6. §3.7 CR-053-18 cluster; unit tests green.
7. §3.9 frontend (state, gating, hints, logger redaction) + Jest green;
   E2E audit and fixes; Playwright projects green.
8. Full §4.5 gate; `npm run lint:fix` (scoped) beforehand.
9. ADR-010 + durable docs; CS-PLAN-058 §9 (WP2 row), backlog dispositions,
   `ACTIVE_TASKS.md`; request Standard B review.

## 7. Risks & Mitigations

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| E2E family specs break where members/children perform now-forbidden operations | High | Explicit E2E audit step (order step 7); fix specs to act as the correct role or assert the denial; serial-db project in the gate |
| Read filtering hides data other consumers count on (Dashboard counters, scheduling) | Medium | Filtering applies to room-task reads only; scheduling reads no tasks (verified); counters derive from already-filtered service outputs; full integration+E2E rerun |
| Existing users on dev instances lose access (invite-only) | Certain | Pre-release policy: dev DBs are disposable; documented in ADR-010; `TestDataService` seeds memberships directly |
| Policy post-fetch filtering costs extra queries | Medium | One batched assignee fetch per read (`findUserIdsByTaskIds`); SQL push-down deferred to WP8 after contract freeze |
| Child actor policy restricts previously working member-like behavior | Medium | Decision D5 ratified in review; frontend hides controls so children never see failing actions; child execution surface (complete, checklist progress) explicitly kept |
| `ensureHouseMember` sweep misses a call site | Low | Grep acceptance check: `ensureHouseMember` in `lib/Controller` remains only in the five designated endpoints |
| 404 re-mapping changes response contracts tests rely on | Medium | 404 shape already established (TaskController/ChecklistController precedent); integration tests updated in the same step |
| Rate cap breaks legitimate high-frequency play in E2E | Low | 100/day cap far above E2E usage; cap value constant, adjustable |
| Removed-member lockout has no self-service recovery | Medium | By design (invite-only); owner re-invite is the recovery path; ADR-010 documents it |

## 8. Acceptance Criteria

- [x] After the first owner, no authenticated user gains membership without
      an owner invitation: second user on `user#current` gets 403 and no
      membership row (integration test); all designated-bootstrap greps hold.
      — `InviteOnlyMembershipTest`, `AuthorizationServiceExceptionTest`
- [x] Removed members stay removed: 403 on reload and direct API calls;
      no auto-join path remains on ordinary reads/mutations.
      — `InviteOnlyMembershipTest::testRemovedMember_StaysRemoved_*`
- [x] Exactly one private-task matrix: creator ∪ explicit assignees (admin-
      only: owners) across definition lists, room-task reads
      (index/overdue/dueToday/range/filteredDaily), checklists, lifecycle,
      completion, and update — proven by positive/negative integration
      tests for owner, member, child, creator, assignee, and non-viewer.
      — `PrivateTaskAuthorizationTest`, `TaskLifecycleVisibilityApiTest`,
      `ChecklistApiTest`, `TaskAccessPolicyTest`
- [x] Actor policy enforced in services: child cannot create/update/delete/
      assign tasks or edit structure/checklist definitions (403); lifecycle
      executions (reactivate/due-date/uncomplete) stay visibility-gated
      execution surface (ratified at the Standard B review 2026-09-04);
      members cannot mutate structure, house settings, or checklist
      definitions (403); assignment of invisible tasks yields 404.
      — `TaskAuthorizationTest`, `BulkAssignIntegrationTest`,
      `ChecklistApiTest`, `RoleGuardApiTest`,
      `TaskLifecycleVisibilityApiTest`
- [x] 403/404 standard holds: foreign/nonexistent ids and visibility
      denials → 404; role denials → 403; no 500 from uncaught
      DoesNotExist/NotFoundException on the mapped endpoints
      (RoomTask ×6, Floor ×2, Room ×4, User remove/updateRole).
- [x] Every controller generic catch logs via `ControllerLoggingTrait`;
      no user-authored names/emails or raw response payloads in backend or
      frontend logs (audited).
- [x] CR-053-18 cluster closed: saveScore whitelist + daily cap, route
      requirements, weekday normalization, notifier uid, medal dedup,
      reminder pass isolation, test-mode contract documented (the
      `@NoCSRFRequired` test-mode route item is re-scoped to the WP9
      release-hardening checklist — recorded in `backlog.md`).
- [x] Frontend: `denied` membership state renders the explicit
      NotAuthorizedState app-wide; owner/child controls match the backend
      matrix; visibility hints state the real rules; `ApiError` exposes
      status/code; logger redacts.
      — Jest: `App.spec`, `NotAuthorizedState.spec`, `user.spec`,
      `api.spec`, `logger.spec`, `TaskList.spec`, `TaskEditForm.spec`,
      `Dashboard.spec`
- [x] `TaskAuthorizationTest` rewritten with zero skips; the authorization
      matrix (owner, member, child, invited, removed, non-member) has
      positive and negative controller/service coverage — the WP2 exit gate.
- [x] Build gate green (CS-PLAN-058 §4.5) with no new or unapproved skips.
      (2026-09-03: unit 810 OK; integration 254 OK + 10 documented
      pre-existing skips; Jest 803 passed; ESLint clean; `cs:check` clean;
      Playwright chromium 92 passed/7 skipped, chromium-serial-db 93
      passed/4 skipped, chromium-scheduling-owner 31 passed.)
- [x] Owning backlog items dispositioned (CR-WF-01, CR-053-16, CR-053-17,
      CR-053-18 implemented + noted pending review ratification; CR-053-18's
      test-mode CSRF remainder re-scoped to WP9; FEAT-UX-015 unchanged on
      its owner-transfer decision); CS-PLAN-058 §9 updated. Final archive
      moves are owned by the Standard B review.
- [x] ADR-010 recorded, ADR-006 annotated, security/API docs updated —
      plans are temporary.
- [x] All implementation decisions and review questions resolved and
      recorded. (Standard B review 2026-09-04: three batched decisions —
      lifecycle-actor semantics ratified, completion-history content
      redaction, four residual authorization gaps fixed; see §10.)
- [x] Final Standard B review is `PASS` or `PASS-WITH-FIXES`;
      `ACTIVE_TASKS.md` updated. (PASS-WITH-FIXES, 2026-09-04; §10.)

## 9. Plan Review Decision Register

The Standard A review must ratify or revise these proposed decisions before
activating the plan:

| ID | Proposed decision | Rationale | Status |
|----|-------------------|-----------|--------|
| D1 | `ensureHouseMember` auto-creates only when the house has zero members; designated bootstrap endpoints are `user#current`, `house#get`, `user#invite`, `setup#catalog`, `setup#apply`; every other call site switches to `assertHouseMember` | Whatever screen the first user opens, they become owner (journey step 1); every later access requires an invited membership; the pure check avoids needless house-lock attempts | Ratified 2026-09-03 |
| D2 | One visibility matrix everywhere: private = creator ∪ explicit assignees; admin-only = house owner; owners do not implicitly see private tasks | Recorded user decision 2026-09-02; today's three divergent matrices are the defect | Ratified by user 2026-09-02 (recorded program decision); reconfirmed by review 2026-09-03 |
| D3 | 403/404 standard: foreign/nonexistent ids and visibility denials on id-addressed resources → 404 anti-enumeration; role/actor denials on visible house resources → 403 | Extends the established TaskController precedent to all boundaries; closes the complete/uncomplete 403 enumeration oracle | Ratified 2026-09-03 |
| D4 | Checklist-definition mutations (item add/update/delete/reorder) become owner-only in the service | Matches the existing frontend gating (`TaskList.vue:223`) and CS-PLAN-058 WP2 text; alternative (creator-or-owner) rejected as KISS — WP4 may revisit for member-created tasks | Ratified by user 2026-09-03 |
| D5 | Actor matrix: owner = all; member = task-domain mutations within visibility; child = execution only (complete, checklist progress, own preferences/minigames) | Matches CS-PLAN-058 WP4/WP7 ("children receive a read/execute view"); backend enforcement belongs to WP2's "validate the acting user" scope | Ratified by user 2026-09-03; clarified at the Standard B review 2026-09-04 (user decision): lifecycle executions reactivate/due-date/uncomplete are visibility-gated execution surface, not actor-gated management — required by the child undo flow |
| D6 | FEAT-UX-015 semantics: the last owner cannot leave; ownership must be transferred first (leave endpoint/UI stay open in FEAT-UX-015) | Consistent with `CANNOT_REMOVE_OWNER`/`CANNOT_DEMOTE_LAST_OWNER`; an ownerless house would allow stranger re-bootstrap | Ratified by user 2026-09-03 |
| D7 | saveScore hardening: `VALID_GAMES` whitelist + per-user 100/day cap (429 `MINIGAME_RATE_LIMITED`) | Closes coin farming with nextcloud-internal means only; values as constants | Ratified by user 2026-09-03 |
| D8 | Test-mode routes keep `@NoCSRFRequired` + runtime `NEXTCLOUD_TEST_MODE` gate; contract documented as test-only | E2E infrastructure depends on plain API seeding; the env gate is the real boundary; CSRF tokens in test tooling add churn without a production threat | Ratified by user 2026-09-03 |
| D9 | Completion-history rows stay house-scoped (not visibility-filtered); room tasks and task definitions are filtered | Completions carry ids, not task content; aggregate stats must stay house-wide; content leakage is closed by filtering the task payloads | Ratified 2026-09-03 |

Any rejected decision requires this plan, its affected backlog acceptance
criteria, and the corresponding risk/test sections to be updated before the
review can pass.

## 10. Review Outcome

**Standard A plan review — 2026-09-03: PASS (GO).**

- **Scope:** exactly one plan candidate in `docs/plans/new/`
  (`cs-plan-061-wp2-membership-visibility-privacy.md`; `.gitkeep` excluded).
  Base: `master` @ `70a4037ef83d044ede39d15299a90f2b300733ae`, nothing
  staged; reviewed diff = untracked plan file + the `ACTIVE_TASKS.md`
  In-Progress entry (both part of this review package).
- Reviewed against the review skill §3 shared checklist plus plan-only
  checks. Load-bearing file:line references were re-verified against the
  live tree: `AuthorizationService` (bootstrap/auto-join, `assertHouseMember`,
  `requireHouseOwner`), `ensureHouseMember` call-site inventory (57 sites,
  11 controllers, incl. the target-derived ids in `UserController::titles`
  and `RoomController::create`), the three visibility matrices
  (`TaskMapper::findVisibleByHouse`, `assertLifecycleVisibility`,
  `ChecklistService::assertTaskAccessible`, `RoomService::completeRoomTask`,
  `TaskService::updateTask`, `TaskController::update`), unguarded mutations
  (`setAssignees`, `bulkAssignTasks`, `createRoomTask`, `deleteRoomTask`,
  `assertHouseMembers` target-only checks, dead mutators), 403/404 sites
  (`ControllerErrorFormatter`, TaskController precedent, UserController
  remove/updateRole ordering), logging inventory
  (`ControllerLoggingTrait` users vs silent controllers), CR-053-18 cluster
  (`MinigameService::saveScore`, routes requirements, weekday divergence,
  `ChoreStrikeNotifier` claimer, medal objectId, reminder loop), frontend
  state (`user.js` role getter, `userAutoInit`, `api.js` error shape,
  `logger.js` redaction, wizard gating, visibility hints), skipped
  authorization matrix (`TaskAuthorizationTest` 10 unconditional skips),
  owned backlog IDs (CR-WF-01, CR-053-16/17/18, FEAT-UX-015 — all
  `Owning: CS-PLAN-058 WP2`), and `HouseSetupService::applySetup` (exists,
  `lib/Service/HouseSetupService.php:86`).
- **Decisions:** D4–D8 ratified by the user in one batched structured
  question round (all recommended options); D1/D3/D9 ratified by this
  review as technical defaults within the WP2 mandate; D2 reconfirms the
  recorded 2026-09-02 program decision.
- **Inline fixes:** corrected the §1.1/§3.1 call-site inventory (11
  controllers, 57 sites, 52 non-designated — not "~60/12/~55"); made D2's
  ratification provenance explicit.
- Plan-only checks: every step has one semantic owner and a test gate; no
  overlap with active plans (`docs/plans/active/` holds only the CS-PLAN-058
  master plan that this package executes); risks, dependencies, acceptance
  criteria, and test plan complete; no workaround-stacking (SQL push-down
  deferral to WP8 is a scoped contract-freeze decision, not a shim).
- No open findings. Recommended next state: `active/`; owned backlog items
  marked `In Progress`.

**Final Standard B code review — 2026-09-04: PASS-WITH-FIXES.**

- **Scope:** the complete WP2 implementation — 104 modified + 7 new files
  (backend services/controllers/mappers, frontend auth state, tests, l10n,
  durable docs). Base: `master` @ `6cf6a3e6b30104ef7e3538badb506a1c74509035`,
  empty index; reviewed diff fingerprint SHA256
  `D7BFCB250BE3D7D5AD9150552E8422AAF55EBACD860C11186342348F12FF4E66`
  (unstaged, 625,538 bytes) + 7 untracked file hashes recorded at capture.
- **Verification:** full file manifest, all acceptance criteria, the decision
  register, durable-doc integration (ADR-010 new, ADR-006 annotated,
  security.md + api/README.md updated), and the implementation history
  reviewed against the shared checklist. Grep audits: `ensureHouseMember`
  remains only in the five designated bootstrap endpoints; no `@PublicPage`;
  no raw `console.*` outside `logger.js`; no `v-html`; no `HACK` markers;
  prepared statements throughout.
- **Fixes applied in review (all rerun through the gate):**
  1. Repaired 14 UTF-8 mojibake comment sites (`—`/`→`) the package had
     introduced across 8 controllers.
  2. Log hygiene (§3.6): removed user-authored `taskName` / `houseName`
     from the TaskController/HouseController exception-log contexts.
  3. Closed four residual authorization gaps (user decision, batched question
     3): `roomTask#assignees` read gains the visibility gate (404 for
     invisible tasks); `roomTask#roomStats` counts are visibility-filtered
     (new `CompletedTaskMapper::countDistinctCompletedRoomTasksInSet`);
     `user#selectTitle` resolves membership before the target lookup
     (scoped 404, closing the find-before-gate oracle); `user#invite`
     runs the owner gate before the email lookup (closing the
     email-existence oracle). Regression tests added in
     `TaskAuthorizationTest` and `UserControllerIntegrationTest`.
  4. Completion-history content redaction (user decision, batched question
     2): `range` completions and `house#activity` keep house-scoped rows
     (D9) but redact task name/energy of tasks invisible to the viewer
     (`RoomTaskService::redactInvisibleCompletionRows`,
     `ActivityService` viewer threading); CalendarView renders the shared
     "Unknown task" fallback. Tests in `PrivateTaskAuthorizationTest`,
     `HouseActivityIntegrationTest`, `ActivityServiceTest`.
  5. Barrel export for `NotAuthorizedState` in `Common/index.js`; repaired
     the plan's broken CS-PLAN-058 relative link.
- **Decisions recorded (user, 2026-09-04, one batched question round):**
  1. Lifecycle actor semantics ratified as implemented: reactivate /
     due-date / uncomplete are visibility-gated EXECUTION surface (not
     actor-gated management) — required by the ungated child undo flow;
     `assertCanManageTasks` guards create/update/delete/assign. §3.4/§8/D5
     and ADR-010 wording updated accordingly.
  2. Completion-history content redacted for invisible tasks (rows stay,
     D9 aggregates preserved).
  3. All four residual gaps fixed now (none deferred).
- **Findings:** every finding was fixed in review or resolved by a recorded
  decision; none remain open. Pre-existing observations outside the package
  (not findings): `RoomTaskService::getRoomTaskWithDetails` has no
  production caller (dead-code sweep candidate for a later work package).
- **Gate (rerun by this review, fresh disposable Docker env):** PHPUnit unit
  813 OK; PHPUnit integration 263 OK + 10 documented pre-existing skips;
  Jest 803 passed; ESLint clean; `composer cs:check` clean (0 of 269);
  `l10n:check-keys` synchronized; `docs:encoding` clean; Playwright
  chromium + chromium-serial-db + chromium-scheduling-owner 210 passed +
  11 documented skips (0 failures).
- **Dispositions:** CR-WF-01, CR-053-16, CR-053-17, CR-053-18 archived to
  `docs/backlog/archive/2026-09.md`; the CR-053-18 test-mode CSRF remainder
  lives on as the `CR-053-18-WP9` stub (WP9 release hardening);
  FEAT-UX-015 keeps its open endpoint/UI scope (semantics decided by D6);
  CS-PLAN-058 §9 WP2 row set to Done. Plan moved `active/ → done/`.
