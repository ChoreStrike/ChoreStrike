# CS-PLAN-038: Core UX — Task Creation, Today View & Member Management

> **Status:** Done (2026-08-21) — implemented, Standard-B review clean, moved to `done/`
> **Decisions signed off:** D1 = Remove scope selector · D2 = Archive TaskForm/DailyTaskList · D3 = Default "My tasks"
> **Effort:** L (three M-sized phases)
> **Priority:** P1
> **Depends on:** none
> **Closes:** `UX-001`, `UX-002`, `UX-003` (backlog, section *Usability & UI Review 2026-08-20*; full record: [`docs/backlog/archive/USABILITY_REVIEW_2026-08-20.md`](../../backlog/archive/USABILITY_REVIEW_2026-08-20.md))
> **Related:** `CR-016-09` (DailyTaskList debt — closed by archiving it here), `UX-008` (assignment discoverability — partially improved, remainder stays open)

## Implementation Outcome (2026-08-21)

All three phases implemented; no backend changes (as planned). Beyond the
manifest: the members invite flow **refetches the house-user list** after a
successful invite (the endpoint returns no `exists` flag); TodayView per-item
`fetchAssignees` makes the "My tasks" filter multi-assign-aware; recurring
completions **leave the Today list** (next due moves out of today) instead of
showing a completed check; `gui-scratch-flow`, `task-completion`, and
`private-tasks` E2E specs were migrated off the removed definition-only flow.
New findings logged: **BUG-SEC-002** (updateRole self/last-owner backend gap —
frontend guard only), **BUG-E2E-003** (title-selection flake, stash-verified
pre-existing); leave-house spun off as **FEAT-UX-015**. Gate: Jest 714 /
lint + stylelint 0 errors / l10n clean / PHPUnit unit 785 + integration 175
[24 pre-existing skips] / E2E `Release:*` 21/21 + new specs 8/8.

## 1. Problem Summary

The usability review (2026-08-20) found the core daily loop broken for the
product's two personas:

1. **Task creation is a trap (UX-001).** Three inconsistent paths; the
   TaskManager "New Task" form (`TaskEditForm` → `POST /tasks`) creates only a
   task *definition* — no RoomTask instance, no room picker — so the task
   appears in no list. RoomDetail's custom path needs a definition dropdown and
   a free-text userId assignee. RoomDetail's edit modal shows a full form but
   persists only the due date.
2. **No "today" surface (UX-002).** A child (or hurried parent) has no single
   "my tasks today — check them off" screen. `DailyTaskList.vue` is orphaned,
   the child-aware `GET /room-tasks/filtered-daily` has no UI, and the
   Dashboard *Due Today* / *Overdue* stat cards are not clickable.
3. **Member management has zero UI (UX-003).** Invite / remove / role-change
   (incl. promote-to-owner) are complete in backend *and* Pinia stores but
   have no buttons; the setup wizard ends without an invite step, so a "family"
   app starts as a household of one.

Goal: a family can onboard (wizard → invite), see their own tasks for today,
and create tasks that are actually visible — with every mutation giving
feedback. Out of scope (stay in backlog): UX-004…UX-014 (P2/P3), *leave house*
(needs a product decision on owner-transfer semantics — kept open in UX-003).

## 2. Strategy

Three independent phases, **no backend changes required** — every needed
endpoint already exists (`POST /room-tasks` with `{roomId, taskId, nextDue}`,
`PUT /room-tasks/{id}/assignees`, `POST /users/invite`, `PUT /users/{id}/role`,
`DELETE /users/{id}`, `GET /room-tasks/filtered-daily`):

- **Phase A — one task-creation path:** extend `TaskEditForm` with a required
  room picker and assignee chips; every create flow materializes a room task
  (definition → `POST /room-tasks` → optional `setAssignees`). Replace
  RoomDetail's custom tab and the misleading edit modal; archive `TaskForm.vue`.
- **Phase B — Today view:** new `/today` route + `TodayView.vue` on top of
  `fetchFilteredDailyTasks()`, kid-sized complete buttons (reusing the existing
  celebration event flow), Dashboard stat cards become links.
- **Phase C — Members:** owner-gated Members section in Settings (invite, role,
  remove, styled confirms, toasts) + optional wizard invite step.

New UI follows the review's kid-friendliness bar: ≥44 px touch targets, no
native `confirm()`, icon+text buttons, `t()` for every string.

### Decision points (need sign-off before/during implementation)

| # | Decision | Recommendation |
|---|----------|----------------|
| D1 | Custom-task **scope selector** (room/floor/house) in `TaskEditForm`: remove it (every custom task is room-scoped; floor/house materialization stays a template/wizard capability) **or** keep it and add a backend bulk-materialize endpoint | **Remove** — KISS; kills the only backend work in this plan; "whole floor/house" tasks via templates/wizard only |
| D2 | Archive now: `TaskForm.vue` (superseded) and `DailyTaskList.vue` (orphaned, superseded by TodayView; closes `CR-016-09`) **or** keep until later | **Archive both** (+ their specs) to `archive/2026-08/` — pre-release, no BC |
| D3 | TodayView default filter | **"My tasks"** (assigned to me or unassigned/claimable); "All" switchable — best for the child persona; owners can flip to All |

## 3. Backend / Frontend Changes

**Backend (`lib/`): none.** All endpoints exist and are tested. (If D1 is
rejected, Phase A grows a `POST /room-tasks/materialize` endpoint + integration
tests — effort +S.)

**Frontend (`src/`):**

- `TaskEditForm.vue` — add *Where* section (cascading floor→room selects from
  `roomsStore`, required in create mode; hidden/locked when a room is preset);
  add assignee chips (pattern from `TaskList.vue:153-171`, source
  `userStore.houseUsers`); D1: drop the Scope select. Extract `RoomPicker.vue`
  and `AssigneeChips.vue` sub-components if the file would exceed ~300 lines.
- `TaskManager.vue` — create flow chains `createTask` → `createRoomTask` →
  `setAssignees`; error/success toasts (`useToasts`); refresh list after.
- `RoomDetail.vue` — "Custom task" tab renders `TaskEditForm` with the room
  preset+locked; edit uses the new slim modal.
- `RoomTaskEditModal.vue` (new) — due date + assignee chips + Save/Cancel;
  persists exactly what it shows (`updateRoomTaskDueDate` + `setAssignees`).
- `TodayView.vue` (new, route `/today`) — sections *Overdue* / *Today*, rows
  with task name, room, ⚡ energy, ≥44 px complete button; D3 filter toggle;
  friendly empty state. Reuses `completeRoomTask` (celebration events fire
  globally via `useCelebration`).
- `QuickStats.vue` — *Due Today* / *Overdue* cards become router links to
  `/today` (`?filter=overdue`); keep `data-testid`s.
- `MemberList.vue` (new, `src/components/Members/`) — owner-gated: member rows
  (name, role, title badge), role change owner/member/child (`updateUserRole` —
  backend already accepts `child`), remove with **styled** confirm modal
  (`Modal.vue`), invite form (email validation → `inviteUser`), toasts for all
  outcomes.
- `Settings.vue` — mount Members section behind `userStore.isHouseOwner`.
- `SetupWizard.vue` — optional step 4 "Invite your family" after apply:
  email list (add/remove rows) → `inviteUser` each; skippable, non-blocking.
- `App.vue` + `router.js` — nav item + route for `/today`.
- `store/roomTasks.js` — optional `createRoomTaskWithAssignees` helper
  encapsulating the create→assign chain (error-safe: task stays created if
  assign fails, toast reports partial success).

**Cross-layer contracts:** `POST /room-tasks` payload unchanged
(`{roomId, taskId, nextDue}`); assignees via existing
`PUT /room-tasks/{id}/assignees` (array of nextcloud userIds). No API doc
changes needed.

## 4. Tests

- **Jest (unit):**
  - `TaskEditForm.spec.js` — room required (create), preset room locked,
    chips toggle, submit payload `{...definition, roomId, assigneeUserIds}`,
    D1: no scope field.
  - `TaskManager.spec.js` — create chain calls stores in order; toast on error.
  - `RoomTaskEditModal.spec.js` — persists due date + assignees; cancel clean.
  - `TodayView.spec.js` — sections from fixture payload, filter toggle
    (mine: assigned-to-me ∨ unassigned), complete emits store call, empty state.
  - `QuickStats.spec.js` — cards link to `/today`.
  - `MemberList.spec.js` — owner gating, email validation, confirm-before-remove,
    role change emits, invite success/error toasts.
  - `SetupWizard.spec.js` — step 4 render, skip, invite calls.
- **PHPUnit:** no new backend code → no new tests (existing suites must stay
  green; run in Docker as gate).
- **Playwright (E2E):**
  - `tests/e2e/today-view.spec.ts` — family profile: open `/today`, see seeded
    due-today rows, complete one, assert row state change (not confetti).
  - `tests/e2e/members-owner.spec.ts` — chromium/admin (owner): invite →
    appears in list; role change; remove with confirm modal. Serial + restore,
    following the `self-availability-member.spec.ts` pattern.

## 5. File Manifest

```
new:      src/views/TodayView.vue
new:      src/components/Members/MemberList.vue
new:      src/components/Tasks/RoomTaskEditModal.vue
new:      src/components/Tasks/RoomPicker.vue            (if extracted)
new:      src/components/Tasks/AssigneeChips.vue         (if extracted)
new:      tests/unit/components/TodayView.spec.js
new:      tests/unit/components/MemberList.spec.js
new:      tests/unit/components/RoomTaskEditModal.spec.js
new:      tests/e2e/today-view.spec.ts
new:      tests/e2e/members-owner.spec.ts
modified: src/components/Tasks/TaskEditForm.vue
modified: src/views/TaskManager.vue
modified: src/views/RoomDetail.vue
modified: src/views/Settings.vue
modified: src/components/Dashboard/QuickStats.vue
modified: src/components/Setup/SetupWizard.vue
modified: src/App.vue
modified: src/router.js
modified: src/router.spec.js
modified: src/store/roomTasks.js                         (helper, optional)
modified: l10n/*.json                                    (same change)
archived: src/components/Tasks/TaskForm.vue              → archive/2026-08/cs-plan-038/
archived: src/components/Scheduling/DailyTaskList.vue    → archive/2026-08/cs-plan-038/
archived: tests/unit/components/DailyTaskList.spec.js    → archive/2026-08/cs-plan-038/
archived: tests/unit/components/TaskForm.spec.js         → archive/2026-08/cs-plan-038/
```

## 6. Implementation Order

1. **Phase A — unified creation:** TaskEditForm (room picker + chips + D1) →
   TaskManager chain → RoomDetail custom-tab swap + slim edit modal → archive
   TaskForm/DailyTaskList (D2) → Jest green.
2. **Phase B — Today view:** route/nav → TodayView + QuickStats links →
   Jest + `today-view.spec.ts` green.
3. **Phase C — Members:** MemberList → Settings section → wizard step 4 →
   Jest + `members-owner.spec.ts` green.
4. **Sweep:** i18n artifacts (`l10n:check-keys`), touch-target + toast audit
   of everything new, `npm run lint:fix`, full gate
   (Jest + lint + PHPUnit Docker + `Release:*` E2E + new specs).
5. **Close-out:** move UX-001/002/003 + CR-016-09 to `archive/2026-08.md`,
   update `ACTIVE_TASKS.md`, integrate durable decisions (scope-model change,
   TodayView concept) into `docs/concepts/` / `docs/engineering/`.

Phases are independently shippable; each ends with its own green gate.

## 7. Risks & Mitigations

| Risk | Likelihood | Mitigation |
|------|-----------|------------|
| `filtered-daily` payload shape differs from assumption (`{roomTask, task, room}`) | medium | Verify against `RoomTaskController::filteredDaily` in Phase B kickoff; fallback: compose from `fetchRoomTasks` + stores |
| Create chain partially fails (definition created, room task not) | medium | Helper reports partial success via toast; definition is reusable (template picker dropdown), not lost |
| Owner self-demotion / demoting the last owner via role change | medium | Verified: `remove` already blocks owners (`CANNOT_REMOVE_OWNER`); `updateRole` has **no** self/last-owner guard — pre-existing backend gap. Frontend guard (disable own role change) + confirm and log the backend gap as a backlog item in Phase C (security-relevant → never silent) |
| `fetchFilteredDailyTasks` mutates the shared `roomTasks` store → Today items bleed into TaskManager's flat list | medium | Keep TodayView data in a dedicated store slice/local state (don't reuse the shared list), or filter TaskManager by room membership; verify in Phase B kickoff |
| Invite by email requires an existing NC account — confusing for families | high | Surface the backend error text clearly + helper text under the input ("uses existing Nextcloud accounts"); invite-by-share-link is future backlog |
| `TaskEditForm` exceeds ~300-line rule with new sections | high | Extract `RoomPicker` / `AssigneeChips` (already in manifest) |
| E2E flakiness from celebrations/sound on complete | low | Assert list/row state, not canvas effects; sounds muted in test env |
| Scope-selector removal (D1) breaks existing floor/house test data | low | Pre-release: dev DBs disposable (`down -v`); no BC shims per project-rules §1 |

## 8. Acceptance Criteria

- [x] Every task-creation path (TaskManager, RoomDetail template, RoomDetail
      custom) ends in a room task visible in its room list, TaskManager, and
      (when due) the Today view — no path creates invisible definitions.
- [x] No free-text assignee input remains; assignment uses member chips.
- [x] RoomDetail edit persists exactly what its form shows (due date +
      assignees).
- [x] `/today` is reachable via nav; shows Overdue/Today; completing a task
      gives the existing celebration feedback; Dashboard *Due Today*/*Overdue*
      cards link into it (overdue pre-filtered).
- [x] Owners can invite (email), change roles, and remove members from
      Settings; non-owners never see the section; the wizard offers an
      invite step after apply.
- [x] New UI: ≥44 px touch targets, no native `confirm()`, toasts on every
      mutation, all strings via `t()` with `l10n/` updated.
- [x] `TaskForm.vue` + `DailyTaskList.vue` archived; no dead imports remain.
- [x] Build gate green: `npm test` + `npm run lint` + stylelint + PHPUnit
      (Docker) + E2E `Release:*` + the two new specs.
- [x] Durable outcomes integrated into permanent docs; UX-001/002/003 +
      CR-016-09 moved to `archive/2026-08.md`; `ACTIVE_TASKS.md` updated.

## Review Outcome (Standard B, 2026-08-21)

Scope: all uncommitted changes of this plan (~32 modified + 12 new files,
frontend-only). Shared checklist passed: thin views, store-owned logic, no
raw `console.*`, no `v-html`, all strings via `t()` with `l10n/` updated in
the same change, no secrets logged, archived components preserved under
`archive/2026-08/cs-plan-038/`, no dead imports left.

- **Fixed inline:** `TodayView.complete()` now moves a recurring *overdue*
  task into the Today section when its next occurrence lands today
  (symmetric to the existing leave-the-list rule) + regression test.
- **Accepted deviation:** the Overdue stat card links to `/today?scope=all`
  instead of the planned overdue pre-filter — the card counts house-wide, so
  a "mine" default would hide the counted tasks; documented in code.
- **Deferred (already in backlog):** native `confirm()` deletes → UX-006;
  chip toggles not keyboard-accessible → added to UX-007; invite-as-owner
  option in MemberList noted as product nit (no escalation path — section is
  owner-gated).
- Gate after review: Jest 715 / lint + stylelint 0 errors. No PHP changed →
  backend suites not re-run (green at implementation time).
