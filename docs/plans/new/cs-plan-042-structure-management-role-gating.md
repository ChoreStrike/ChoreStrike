# CS-PLAN-042: Structure Management & Role Gating

> **Status:** Plan (not started)
> **Effort:** L
> **Priority:** P2
> **Depends on:** none (soft: coordinate with BUG-SEC-002 — see Risks)
> **Closes:** UX-004, UX-008, UX-009, UX-011, CR-SVC-61 (endpoint half)

## 1. Problem Summary

House structure is half-manageable: `updateRoomName`/`deleteRoom` store actions
have no buttons; `POST /floors/reorder` + `reorderFloors` exist but FloorList
has no reorder affordance; floor management exists twice (Dashboard FloorList +
Settings); Settings shows owner-only controls (rename/reset/floor CRUD) to all
members, producing runtime 403s instead of hidden controls (UX-004).
Multi-assign chips hide inside an expanded task row and are absent from create
forms; there is no bulk-assign UI because the optimized `bulkAssignTasks`
write path (CS-PLAN-037) has no controller/route caller — the frontend still
loops `assignRoomTask` per item (CR-SVC-61, UX-008). `TaskChecklist.vue` is a
full editor mounted permanently with `:can-edit="false"` (UX-009). The setup
wizard re-opens on every reload while the house is empty, lacks a house-name
step, and shows a useless per-room assignee dropdown at first run (UX-011).

## 2. Strategy

Make Settings the single owner surface for house structure; demote Dashboard
FloorList to read-only navigation. Add RoomDetail-header rename/delete for
owners and arrow-button floor reorder (KISS over drag&drop) hitting the
existing reorder endpoint. Role-gate owner-only Settings sections in the
template. Expose `RoomTaskService::bulkAssignTasks` through a new endpoint
(closes CR-SVC-61) and switch the store to it. Unlock the checklist editor for
owners against the existing item CRUD endpoints. Polish the wizard: persistent
"start empty" dismissal, house-name step, assignee dropdown suppressed while
the house has a single member.

## 3. Backend / Frontend Changes

- **Backend** (`lib/`):
  - Bulk-assign endpoint (CR-SVC-61): route entry + thin controller action
    with `ensureHouseMember` and `InvalidArgumentException` -> 400, delegating
    to `RoomTaskService::bulkAssignTasks` (batch pre-validation already in
    place via `findByIdsScopedToHouse`).
- **Frontend** (`src/`):
  - `RoomDetail.vue`: owner-visible rename/delete in the header (wires the
    rooms-store `updateRoomName`/`deleteRoom`; styled delete confirm).
  - `FloorList.vue` / `Settings.vue`: per-floor up/down reorder ->
    `POST /floors/reorder`; Dashboard FloorList loses edit controls
    (read-only); Settings stays the sole CRUD+reorder surface; owner-gated
    rendering (`v-if` owner role) for rename/reset/floor sections.
  - Assignment: `AssigneeChips` surfaced consistently in `TaskEditForm.vue` /
    `RoomTaskEditModal.vue` create paths (parity with edit), plus a
    bulk-assign action on TaskList selection -> single bulk API call
    replacing the per-item store loop.
  - `TaskChecklist.vue`: `can-edit` derived from ownership; item
    add/edit/delete/reorder wired to the checklist endpoints.
  - `SetupWizard.vue`: persist "start empty" dismissal (per-house flag), add
    a house-name step, hide the per-room assignee dropdown when
    `members.length <= 1`.

## 4. Tests

- **Integration (PHPUnit, Docker)**: bulk-assign endpoint — membership
  scoping, non-member target -> 400, per-item failure isolation (mirrors
  CS-PLAN-037 semantics), owner gating.
- **Jest**: rooms/floors reorder actions, bulk-assign store action, role-gated
  rendering (owner vs member), checklist editor enablement.
- **E2E**: structure spec — owner renames/deletes a room and reorders floors,
  member sees no owner controls; wizard dismissal persists across reload.

## 5. File Manifest

```
modified: appinfo/routes.php                                (bulk-assign route)
modified: lib/Controller/RoomTaskController.php             (bulk-assign action)
modified: src/views/RoomDetail.vue
modified: src/views/Settings.vue
modified: src/components/Dashboard/FloorList.vue
modified: src/components/Tasks/TaskList.vue                 (bulk-assign entry)
modified: src/components/Tasks/TaskChecklist.vue
modified: src/components/Setup/SetupWizard.vue
modified: src/store/tasks.js                                (bulk action)
modified: tests/...                                          (unit/integration/e2e as above)
archived: -
```

## 6. Implementation Order

1. Spike: confirm the checklist item CRUD routes exist and work (currently
   uncalled); if any are missing, scope UX-009 down to editor removal
   (recorded decision).
2. Backend bulk-assign endpoint + integration tests (CR-SVC-61).
3. Role-gate Settings sections; strip Dashboard FloorList to read-only.
4. RoomDetail header rename/delete; floor up/down reorder.
5. Store switch to bulk-assign + selection UI in TaskList.
6. Checklist editor unlock (owners).
7. Wizard polish (persistent dismissal, house-name step, single-member guard).
8. Gate + `concepts/ui-use-cases.md` UC status refresh.

## 7. Risks & Mitigations

| Risk | Likelihood | Mitigation |
|------|-----------|------------|
| Checklist CRUD endpoints incomplete/unrouted | Medium | Step-1 spike with explicit fall-back decision (remove editor) |
| Bulk-assign partial failures confuse users | Medium | Per-item isolation + partial-success toast pattern (CS-PLAN-038) |
| Frontend self-demotion guard stays bypassable | Known | BUG-SEC-002 owns the server-side fix; land it before or with this plan |
| Two structure surfaces drift again later | Low | Document the single-surface rule in engineering docs on completion |

## 8. Acceptance Criteria

- [ ] Exactly one surface edits floors (Settings, owner-only); Dashboard
      FloorList is read-only; room rename/delete reachable from RoomDetail
- [ ] Floors reorderable (up/down) via `POST /floors/reorder`; order persists
- [ ] Settings renders owner-only controls only for owners (no 403 paths)
- [ ] Bulk assign goes through the new endpoint (store no longer loops)
- [ ] Owners can edit checklists; members cannot
- [ ] Wizard dismissal persists; house-name step present; assignee dropdown
      hidden for single-member houses
- [ ] Build gate green; UX-004/008/009/011 + CR-SVC-61 moved to archive
- [ ] Durable docs integrated (architecture endpoint notes,
      `concepts/ui-use-cases.md`); `ACTIVE_TASKS.md` updated
