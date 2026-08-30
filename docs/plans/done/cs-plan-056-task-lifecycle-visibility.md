# CS-PLAN-056: Task Lifecycle Visibility Matrix (CR-053-06)

> **Status:** Done (2026-08-30) — implemented, Standard-B review **PASS** (2026-08-30, see `## Review Outcome`); moved to `done/`
> **Effort:** S
> **Priority:** P2
> **Depends on:** ~~CS-PLAN-055~~ — **landed 2026-08-29** (its route pruning reshaped this item's scope — see §1)
> **Closes:** CR-053-06

## 1. Problem Summary

Task completion enforces the visibility matrix **in-service** (`RoomService::completeRoomTask:275-281` post-CS-055: private → assignee-only, admin-only → owner-only, both under the row lock), but the lifecycle siblings historically did not: the pre-CS-055 `TaskController::uncomplete` (:839), `skip` (:879) and `reschedule` (:919) only checked house membership + room-task ownership — any house member could uncomplete/skip/reschedule another member's private task or an admin-only task (no reward theft — the completion path is gated — but private-task integrity is violated). `RoomTaskService` has **zero** visibility checks (grep-verified in the CS-PLAN-053 review).

**Scope note (dependency on CS-PLAN-055):** of the three flagged endpoints, `task#skip`, `task#reschedule` (and `task#complete`) were test-only and **pruned** by CS-PLAN-055; `task#uncomplete` **survives** (`TaskController.php:455`) — it is the production undo path (`src/store/roomTasks.js:387` `uncompleteRoomTask` + `:418` `undoRoomTaskCompletion`, CS-PLAN-041 UndoSnackbar). After CS-PLAN-055 this item therefore reduces to: enforce the matrix on the surviving lifecycle mutations — `uncompleteTask` plus the same-class mutations that remain reachable (`updateDueDate`, `reactivate`).

*(CS-PLAN-055 landed 2026-08-29, so the "if executed before 055" fallback is moot — the scope below already reflects the pruned surface.)*

## 2. Strategy

Mirror the completion path's gate, but **centralized**: add one reusable guard in `RoomTaskService` (e.g. `assertLifecycleVisibility(RoomTask $roomTask, Task $task, HouseUser $houseUser, string $userId): void`) that resolves the assignee set (`TaskAssigneeMapper::findUserIdsByRoomTask` + `mergeLegacyAssignee`, same as `completeRoomTask:268-271`) and throws `ForbiddenException` for private→non-assignee and admin-only→non-owner. Call it from every surviving lifecycle mutation **before** the write. Controllers stay thin; semantics (403 + existing translated messages) match `complete` exactly so the API stays self-consistent. No controller-level matrix duplication (the CS-PLAN-053 review flagged the ×3 controller copies as debt — this plan adds none).

## 3. Backend / Frontend Changes

- **Backend** (`lib/`):
  - `RoomTaskService`: new `assertLifecycleVisibility()` guard (private → assignee; admin-only → owner; reuses `Task::VISIBILITY_ADMIN_ONLY` + `Task::isPrivate()` — both exist in `lib/Db/Task.php:80/:289`); called in `uncompleteTask` (:857), `updateDueDate` (:524) and `reactivateTask` (:510) (each already loads the `RoomTask`; the service injects `TaskMapper`, `TaskAssigneeMapper` and `HouseUserMapper`, so all guard inputs are in reach; thread `HouseUser`/`$userId` in as needed).
  - `TaskController::uncomplete`: capture the `HouseUser` returned by `authService->ensureHouseMember(...)` (the call exists but its result is currently discarded) and pass it through to the service; no visibility logic in the controller.
  - `RoomTaskController::{updateDueDate, reactivate}`: same capture + threading (both already run the ownership preamble; `ensureHouseMember` returns `HouseUser`).
  - i18n: reuse the existing translated messages from `completeRoomTask` ('Private tasks may only be completed by assigned users' family — introduce lifecycle-neutral phrasing 'Private tasks may only be modified by assigned users' / 'Admin-only tasks may only be modified by house owners' if the wording diverges; then sync `l10n/` en/de/pot).
- **Frontend** (`src/`): none required — the undo path only ever targets the current user's own completion (403 would surface via the existing toast).
- **Cross-layer contracts:** unchanged (403 envelope `FORBIDDEN` + message, as in `complete`).

## 4. Tests

- **Unit (PHPUnit):** `RoomTaskServiceTest` — private task + non-assignee member → `ForbiddenException`; private + assignee → ok; admin-only + non-owner member → `ForbiddenException`; admin-only + owner → ok; public task + any member → ok. Red→green proof (mutation check: guard removed ⇒ tests fail).
- **Integration (Docker, `*ApiTest.php` convention):** new `TaskLifecycleVisibilityApiTest` — member B uncompletes member A's private task → 403; assignee uncompletes own → 200; member due-dates (`updateDueDate`) or reactivates an admin-only task → 403; owner → 200.
- **Jest:** existing undo specs stay green (own-task undo unaffected).
- **E2E:** no new specs (no UI surface reaches foreign lifecycle mutations); the blocking suite may need the `release-task-lifecycle` fixture-pick adjustment noted in §7.

## 5. File Manifest

```
modified: lib/Service/RoomTaskService.php (guard + 3 call sites)
modified: lib/Controller/{TaskController,RoomTaskController}.php (HouseUser threading, thinned)
modified: tests/unit/Service/RoomTaskServiceTest.php
new:      tests/integration/Controller/TaskLifecycleVisibilityApiTest.php
modified: l10n/en.json · l10n/de.json · l10n/templates/chorestrike.pot (only if wording changes)
modified: docs/api/README.md (lifecycle endpoints' auth notes) · docs/backlog/{backlog.md,ACTIVE_TASKS.md}
```

## 6. Implementation Order

1. Add `assertLifecycleVisibility()` + wire into `uncompleteTask` (unit tests red→green).
2. Wire into `updateDueDate` + `reactivate` (+ unit tests).
3. Controller threading + i18n/l10n sync if needed.
4. Integration suite; docs + backlog move (CR-053-06 → `archive/2026-08.md`).

## 7. Risks & Mitigations

| Risk | Likelihood | Mitigation |
|------|-----------|------------|
| Undo regression: assignee's own undo suddenly 403s | low | Assignee set includes legacy single assignee (`mergeLegacyAssignee`); Jest undo specs + integration case pin it |
| Scope creep into assignee mutations (`setAssignees` family, CR-P2-06 latent) | medium | Out of scope here — CR-P2-06 (record: `CODE_REVIEW_2026-08-27`) stays defense-in-depth; guard is lifecycle-only |
| 403 vs 404 anti-enumeration drift (CR-P3-08/CR-053-18) | accepted | 403 mirrors `complete` today; the enumeration-consistency decision stays with CR-053-18 |
| Blocking E2E fixture 403s: `release-task-lifecycle.spec.ts` `pickActiveRoomTask` takes the *first* active due-dated task as owner — its docstring premise ("owner can act on any returned task") breaks for a **private foreign-assignee** task under the new guard (admin-only stays owner-safe) | medium | Filter the pick to public (or owner-assigned) tasks — or create a dedicated fixture task in the test — before flipping the guard; re-run blocking suite |
| Message wording change breaks existing translations | low | Reuse exact existing keys where possible; `npm run l10n:check-keys` gate |

## 8. Acceptance Criteria

- [x] No surviving lifecycle mutation (`uncomplete`, `updateDueDate`, `reactivate`) bypasses the visibility matrix; grep gate: each writes only after `assertLifecycleVisibility`
- [x] Semantics identical to `complete` (403 `FORBIDDEN`, translated messages — lifecycle-neutral wording, l10n en/de/pot synced)
- [x] Unit + integration tests as §4, no new skips; build gate green (Jest 754 · PHPUnit unit 760 / integration 204 [21 pre-existing skips] · lint + cs:check 0 · webpack build · `release-task-lifecycle` E2E 5/5)
- [x] CR-053-06 moved to archive; ACTIVE_TASKS updated; auth notes in `docs/api/README.md`

## Review Outcome

**Standard B (Code) review — 2026-08-30: PASS.** Scope: the full uncommitted
CS-PLAN-056 change set (guard + controller threading, unit/integration tests,
l10n ×3, E2E pick filter, docs/backlog).

- Guard mirrored line-by-line against the completion gate
  (`RoomService::completeRoomTask:266-282`): same assignee resolution
  (`findUserIdsByRoomTask` + `mergeLegacyAssignee`), strict `in_array`, same
  gate order (private → admin-only); runs **before** every write in
  `uncompleteTask`/`updateDueDate`/`reactivateTask`; grep gate clean — no
  stale 2-arg callers.
- Controllers thin (capture `ensureHouseMember()`'s `HouseUser` + thread, no
  matrix duplication); `ForbiddenException` → shared `forbiddenResponse()`
  (403 `FORBIDDEN`) in all three endpoints; membership → ownership (404) →
  visibility (403) ordering preserved; `@NoAdminRequired` only.
- Integration fixture genuinely pins the legacy-assignee path
  (`assignTaskToRoom` writes only the legacy column); all denials carry
  unchanged-row no-op assertions.
- E2E `pickActiveRoomTask` filter matches the acting owner (`mom`).
- Gate re-verified: `npm run lint:fix` clean · Jest 754/754 · Docker PHPUnit
  unit 760/760 · integration 204 [21 pre-existing skips] (`TaskLifecycleVisibilityApiTest`
  6/6, `RoomTaskServiceTest` 44/44) · `l10n:check-keys` green · `composer cs:check` 0/253.

Findings: 0 block/major/minor; 0 auto-fixes; 0 new backlog items. Two no-action
nits recorded: (a) the guard is lock-free unlike the completion path's
in-transaction gate — matches the plan's "before the write" scope and every
other guard in the codebase (theoretical TOCTOU on a concurrent visibility flip
only); (b) an orphaned room-task row (task definition missing) would surface as
500 via the raw `DoesNotExistException` from the guard's `TaskMapper::find` —
unreachable in practice (CS-PLAN-037 orphan-free delete cascades), anomaly-only.
Not committed — awaiting user approval.
