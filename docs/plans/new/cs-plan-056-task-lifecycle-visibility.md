# CS-PLAN-056: Task Lifecycle Visibility Matrix (CR-053-06)

> **Status:** Plan (not started)
> **Effort:** S
> **Priority:** P2
> **Depends on:** CS-PLAN-055 (route pruning reshapes this item's scope — see §1)
> **Closes:** CR-053-06

## 1. Problem Summary

Task completion enforces the visibility matrix **in-service** (`RoomService::completeRoomTask:371-378`: private → assignee-only, admin-only → owner-only, both under the row lock), but the lifecycle siblings historically did not: `TaskController::uncomplete` (:839), `skip` (:879) and `reschedule` (:919) only check house membership + room-task ownership — any house member could uncomplete/skip/reschedule another member's private task or an admin-only task (no reward theft — the completion path is gated — but private-task integrity is violated). `RoomTaskService` has **zero** visibility checks (grep-verified in the CS-PLAN-053 review).

**Scope note (dependency on CS-PLAN-055):** of the three flagged endpoints, `task#skip`, `task#reschedule` (and `task#complete`) are test-only and are **pruned** by CS-PLAN-055; `task#uncomplete` **survives** — it is the production undo path (`roomTasks.js:554` `uncompleteRoomTask` → `undoRoomTaskCompletion`, CS-PLAN-041 UndoSnackbar). After CS-PLAN-055 this item therefore reduces to: enforce the matrix on the surviving lifecycle mutations — `uncompleteTask` plus the same-class mutations that remain reachable (`updateDueDate`, `reactivate`).

If executed **before** CS-PLAN-055 instead, apply the same guard to `skipTask`/`rescheduleTask` as well — but that work is then deleted days later; the recommended order is 055 → 056.

## 2. Strategy

Mirror the completion path's gate, but **centralized**: add one reusable guard in `RoomTaskService` (e.g. `assertLifecycleVisibility(RoomTask $roomTask, Task $task, HouseUser $houseUser, string $userId): void`) that resolves the assignee set (`TaskAssigneeMapper::findUserIdsByRoomTask` + `mergeLegacyAssignee`, same as `completeRoomTask:364-367`) and throws `ForbiddenException` for private→non-assignee and admin-only→non-owner. Call it from every surviving lifecycle mutation **before** the write. Controllers stay thin; semantics (403 + existing translated messages) match `complete` exactly so the API stays self-consistent. No controller-level matrix duplication (the CS-PLAN-053 review flagged the ×3 controller copies as debt — this plan adds none).

## 3. Backend / Frontend Changes

- **Backend** (`lib/`):
  - `RoomTaskService`: new `assertLifecycleVisibility()` guard (private → assignee; admin-only → owner; reuses `Task::VISIBILITY_ADMIN_ONLY` + `Task::isPrivate()`); called in `uncompleteTask` (:890), `updateDueDate`, and `reactivate` (each already receives/loads the room task + has the house context; thread `HouseUser`/`$userId` in as needed — the controller already resolves `$houseUser` in its preamble).
  - `TaskController::uncomplete`: pass the `$houseUser` (already resolved via `ensureHouseMember`) through to the service; no visibility logic in the controller.
  - `RoomTaskController::{updateDueDate, reactivate}`: same threading (both already run the ownership preamble).
  - i18n: reuse the existing translated messages from `completeRoomTask` ('Private tasks may only be completed by assigned users' family — introduce lifecycle-neutral phrasing 'Private tasks may only be modified by assigned users' / 'Admin-only tasks may only be modified by house owners' if the wording diverges; then sync `l10n/` en/de/pot).
- **Frontend** (`src/`): none required — the undo path only ever targets the current user's own completion (403 would surface via the existing toast).
- **Cross-layer contracts:** unchanged (403 envelope `FORBIDDEN` + message, as in `complete`).

## 4. Tests

- **Unit (PHPUnit):** `RoomTaskServiceTest` — private task + non-assignee member → `ForbiddenException`; private + assignee → ok; admin-only + non-owner member → `ForbiddenException`; admin-only + owner → ok; public task + any member → ok. Red→green proof (mutation check: guard removed ⇒ tests fail).
- **Integration (Docker, `*ApiTest.php` convention):** new `TaskLifecycleVisibilityApiTest` — member B uncompletes member A's private task → 403; assignee uncompletes own → 200; member reschedules/due-dates an admin-only task → 403; owner → 200.
- **Jest:** existing undo specs stay green (own-task undo unaffected).
- **E2E:** no new spec (no UI surface reaches foreign lifecycle mutations); blocking suite green.

## 5. File Manifest

```
modified: lib/Service/RoomTaskService.php (guard + 3 call sites)
modified: lib/Controller/{TaskController,RoomTaskController}.php (HouseUser threading, thinned)
modified: tests/unit/Service/RoomTaskServiceTest.php
new:      tests/integration/Controller/TaskLifecycleVisibilityApiTest.php
modified: l10n/{en.json,de.json,chorestrike.pot} (only if wording changes)
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
| Scope creep into assignee mutations (`setAssignees` family, CR-P2-06 latent) | medium | Out of scope here — CR-P2-06 stays defense-in-depth backlog; guard is lifecycle-only |
| 403 vs 404 anti-enumeration drift (CR-P3-08/CR-053-18) | accepted | 403 mirrors `complete` today; the enumeration-consistency decision stays with CR-053-18 |
| Message wording change breaks existing translations | low | Reuse exact existing keys where possible; `npm run l10n:check-keys` gate |

## 8. Acceptance Criteria

- [ ] No surviving lifecycle mutation (`uncomplete`, `updateDueDate`, `reactivate`) bypasses the visibility matrix; grep gate: each writes only after `assertLifecycleVisibility`
- [ ] Semantics identical to `complete` (403 `FORBIDDEN`, translated messages)
- [ ] Unit + integration tests as §4, no new skips; build gate green
- [ ] CR-053-06 moved to archive; ACTIVE_TASKS updated; auth notes in `docs/api/README.md`
