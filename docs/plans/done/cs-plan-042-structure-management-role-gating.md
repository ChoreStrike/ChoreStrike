# CS-PLAN-042: Structure Management & Role Gating

> **Status:** Implemented (2026-08-22) — pending user review + `active/ → done/` move
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
  CS-PLAN-037 semantics), non-member caller -> 403.
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
modified: src/store/roomTasks.js                            (bulk action)
modified: src/store/checklist.js                            (backend-contract adapt)
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
| Frontend self-demotion guard stays bypassable | High (documented) | BUG-SEC-002 owns the server-side fix; land it before or with this plan |
| Two structure surfaces drift again later | Low | Document the single-surface rule in engineering docs on completion |
| Doc overlap with active CS-PLAN-039 (`ui-use-cases.md`, UX-011 text) | Medium | Coordinate: land 039's doc pass first or rebase step 8 on its edits |

## 8. Acceptance Criteria

- [x] Exactly one surface edits floors (Settings, owner-only); Dashboard
      FloorList is read-only; room rename/delete reachable from RoomDetail
- [x] Floors reorderable (up/down) via `POST /floors/reorder`; order persists
- [x] Settings renders owner-only controls only for owners (no 403 paths)
- [x] Bulk assign goes through the new endpoint (store no longer loops)
- [x] Owners can edit checklists; members cannot
- [x] Wizard dismissal persists; house-name step present; assignee dropdown
      hidden for single-member houses
- [x] Build gate green (incl. `l10n:check-keys` for new UI strings);
      UX-004/008/009/011 + CR-SVC-61 moved to archive
- [x] Durable docs integrated (architecture endpoint notes,
      `concepts/ui-use-cases.md`); `ACTIVE_TASKS.md` updated

## Implementation Outcome (2026-08-22)

All eight steps implemented. Deviations and findings beyond the manifest:

- **Spike verdict (step 1):** the checklist item CRUD routes exist and are
  routed correctly — but every frontend payload/response shape had drifted:
  the store sent `{title, description, orderIndex}` against a backend that
  only knows `label` + `sortOrder`, reorder sent `{taskId, itemIds}` instead
  of an `itemOrders` map, and progress/toggle responses (`{items, stats}` /
  `{progress, stats}`) were cached raw. UX-009 stayed at full scope; the
  checklist store was adapted to the backend contract and the component now
  renders `label`/`sortOrder`/`isChecked` (the description field is gone —
  there is no such column).
- **Settings room CRUD added:** stripping FloorList would have left room
  creation without any surface, so Settings' structure section gained an
  expandable per-floor room list with inline add and styled-delete confirm
  (this is what "Settings stays the sole CRUD surface" requires in full).
  Room icons default (the old FloorList icon-picker modal died with the
  duplicate surface).
- **Create-path chip parity verified, not changed:** `TaskEditForm` and
  `RoomTaskEditModal` already carried `AssigneeChips` (landed with
  CS-PLAN-038); no code change needed.
- **Wizard house name** is applied after `setup/apply` via the existing
  owner-gated `PUT /house` (the setup endpoint does not take a name);
  dismissal persists per house in `localStorage`
  (`chorestrike:wizard-dismissed:<houseId>`), read by Dashboard's auto-open.
- **ESLint config:** `localStorage` in `src/` needed an explicit
  `n/no-unsupported-features/node-builtins` ignore (browser global, not a
  Node builtin).
- **E2E specs repointed to the single surface** (would otherwise exercise
  removed FloorList controls): `room-management.spec.ts` rewritten (floor
  create, room add via Settings, read-only Dashboard assertions, owner
  rename/delete from RoomDetail header, floor up/down reorder, member sees
  no owner controls), `3-floor-house.spec.ts` room tests use a new
  `createRoomViaSettings` helper (fixtures/test-house.ts),
  `gui-scratch-flow.spec.ts` builds floor+room via Settings.
- **Gate:** PHPUnit unit 795 OK / integration 190 OK (24 pre-existing skips;
  incl. 5 real-DB bulk-assign tests + 3 controller-level tests) / Jest 786
  (76 suites) / ESLint + stylelint 0 errors / webpack production build green
  / `l10n:check-keys` clean (de+en updated).

## E2E Execution Outcome (2026-08-22)

Docker E2E run executed against the running `family` test env — **34/34 green**
(`--retries=0`: settings 8, core-functionality 10, 3-floor-house 9,
gui-scratch-flow 1, room-management 7; projects `chromium` +
`chromium-serial-db`). The first execution pass surfaced real defects in the
spec updates, fixed inline:

- **Actor defect (root cause of the first all-red run):** the repointed specs
  still acted as `admin`, who is a plain *member* under the family seed
  (owners: mom/dad) — the owner-gated Settings correctly rendered no structure
  controls. All structure specs now pin `mom` via describe-level
  `test.use({ storageState })`; `settings.spec.ts` and the Settings-controls
  test in `core-functionality.spec.ts` had the same latent break and were
  fixed identically.
- **Duplicate-floor race in `test-house.ts`:** the existence probe used an
  instant `isVisible()` that raced the async floors fetch — every call created
  a duplicate floor (backend allows duplicate names), which then blew up
  strict-mode assertions. Helpers now wait bounded for the row to attach.
- **Parallel interference:** room-management/gui-scratch-flow mutate house
  structure and were moved into the serial `chromium-serial-db` pool; their
  positional assertions were additionally hardened (relative-order polling,
  explicit floor expansion before room-card clicks).
- **Two-level room picker:** TaskEditForm's picker selects rooms per floor;
  specs must select the target floor before the room (3-floor-house,
  gui-scratch-flow).
- **Stale task-creation flow:** the 3-floor-house task test used pre-CS-PLAN-038
  selectors; rewritten against the unified creation form.

Note on §4 "wizard dismissal persists across reload": covered at component
level (SetupWizard Jest spec); an E2E variant would require a user-bearing
structurally empty house profile which the fixtures do not provide (same
documented constraint as release-setup-wizard.spec.ts).

## Review Outcome

**Standard Review tier A** (plan, pre-`new/` → `active/`) — 2026-08-22.
Verdict: **GO-WITH-CHANGES** (changes applied inline; see report). Fixed
inline: store manifest corrected to `src/store/roomTasks.js` (bulk loop lives
there, not `tasks.js`); test-plan wording "owner gating" → "non-member caller
-> 403" (bulk assign is member-accessible, mirroring `assign`); risk
likelihood "Known" → "High (documented)"; added risk row for the
CS-PLAN-039 doc overlap; build-gate criterion now includes
`l10n:check-keys`. Verified against code: `bulkAssignTasks` +
`findByIdsScopedToHouse` (RoomTaskService), `floor#reorder` route, checklist
item CRUD routes, store actions, and controller error-mapping patterns all
exist as described. No backlog items (plan review).

**Standard Review tier B** (code, uncommitted CS-PLAN-042 changes) —
2026-08-22. Verdict: **PASS**. Scope: all 39 changed/new files (bulk-assign
route + controller action + real-DB/controller integration tests, role-gated
Settings, read-only FloorList, RoomDetail rename/delete, TaskList bulk bar +
checklist editor wiring, checklist/roomTasks stores, wizard polish, E2E spec
repointing, docs + backlog moves). Checklist clean: thin controller →
`bulkAssignTasks` delegation with `ensureHouseMember` + payload validation;
`@NoAdminRequired` only; owner gating client-side (server stays the
enforcement authority, cf. BUG-SEC-002); `AppLogger`/`logger.js` throughout;
all new strings in `t()` + l10n. Fixed inline: (1)
`checklist.js#initializeProgress` read `data.items` on the endpoint's
payload-less success envelope (api wrapper unwraps to `undefined` →
TypeError) — now `data?.items || []` + regression spec; (2) file manifest
gained `src/store/checklist.js`; (3) `Settings.spec.js` user-store mock
gained `fetchHouseUsers`/`houseUsers` (MemberList mounted-hook TypeError
noise introduced by the new mock). Gate re-verified green: lint:fix +
stylelint clean, Jest 787 (76 suites), PHPUnit unit 795 / integration 190
(24 pre-existing skips; BulkAssign 8/8), `l10n:check-keys` synced. No
backlog items.
