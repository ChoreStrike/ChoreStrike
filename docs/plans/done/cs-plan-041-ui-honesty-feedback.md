# CS-PLAN-041: UI Honesty & Feedback

> **Status:** Implemented (2026-08-22) — awaiting review; recommend `active/ → done/` after review
> **Effort:** M
> **Priority:** P2
> **Depends on:** none
> **Closes:** UX-005, UX-006, UX-013

## 1. Problem Summary

Three classes of dishonest UI survived the usability review (2026-08-20):

- **Controls that lie (UX-005):** the Leaderboard period filter sets state but
  never re-fetches; the weekly-medals section renders a hardcoded empty array
  (`Leaderboard.vue` local placeholder `medals()`) although the game store's
  `fetchMedals()` plus `GET /game/medals`, `/game/leaderboard`,
  `/game/check-medals` and `initializeGame()` exist — so medal evaluation and
  celebrations never fire; `QuickStats.vue` emits `toggle-private-filter` but
  Dashboard never binds it.
- **Unsafe/silent actions (UX-006):** completions cannot be undone even though
  `uncomplete`/`reactivate` endpoints exist; task/floor deletes use native
  `confirm()`; Settings saves give no feedback.
- **Drifting duplicated data (UX-013):** `BOOST_COSTS` is hardcoded in
  `src/store/minigame.js` while `GET /minigames/boosts` is authoritative —
  affordability check and purchase price can drift apart.

## 2. Strategy

Pure frontend consolidation onto existing endpoints (no new backend surface).
Reuse the styled-confirm and toast patterns introduced with the Members
section (CS-PLAN-038). For every dead control the default is *wire it*;
*remove it* only if the backing endpoint cannot express what the control
promises (decision recorded per item during the step-1 spike).

## 3. Backend / Frontend Changes

- **Backend** (`lib/`): none expected. Plan review verified
  `GameLogicService::getLeaderboard(int $houseId, int $limit = 10)` accepts no
  period parameter (`GameController` passes only the house id) — per the
  pre-decided path, remove the period filter instead of adding backend surface
  in this plan (logged decision).
- **Frontend** (`src/`):
  - `Leaderboard.vue`: bind the period filter to an actual store re-fetch (or
    remove it); replace the local `medals()` placeholder with
    `gameStore.medals` fed by `fetchMedals()` (including the check-medals
    pre-pass noted in the store) so `MedalBadge` celebrations fire for
    genuinely new awards only.
  - `Dashboard.vue`: bind `@toggle-private-filter` from `QuickStats.vue` to
    the room-task private filter (semantics consistent with TodayView's
    My-tasks scope).
  - Undo snackbar: after a successful completion show a ~5 s snackbar with an
    *Undo* action calling the existing `uncomplete`/`reactivate` endpoints via
    new roomTasks store actions (optimistic revert on *Undo*; snackbar hides
    on dismiss/timeout).
  - Replace native `confirm()` for task/floor deletes with the styled
    confirmation-dialog pattern (extract shared `ConfirmDialog.vue` if
    MemberList's inline variant does not generalize).
  - Settings saves (and rename flows) get success/error toasts.
  - `minigame.js`: fetch the boost catalog once (`GET /minigames/boosts`) into
    store state; affordability checks and `BoostShop.vue` pricing read the
    catalog; hardcoded `BOOST_COSTS` deleted; with the catalog unavailable,
    purchases are disabled with a clear error instead of stale prices.
  - All new user-visible strings (snackbar, confirm dialogs, toasts,
    disabled-purchase message) via `t()`; `l10n/` artifacts updated in the
    same change.

## 4. Tests

- **Jest**: game store (period re-fetch; medals fetch + celebrate-once
  semantics), minigame store (catalog load, purchase blocked without
  catalog), roomTasks undo actions (incl. failure path), snackbar component,
  ConfirmDialog usage.
- **E2E (Playwright)**: extend leaderboard/dashboard regression specs —
  period filter changes output (or is absent), private toggle filters the
  list, complete -> undo restores the task.

## 5. File Manifest

```
new:      src/components/Common/UndoSnackbar.vue
new:      src/components/Common/ConfirmDialog.vue          (if extraction needed)
modified: src/views/Leaderboard.vue
modified: src/views/Dashboard.vue
modified: src/views/Settings.vue
modified: src/store/game.js
modified: src/store/minigame.js
modified: src/store/roomTasks.js
modified: src/components/Minigames/BoostShop.vue           (reads catalog, BOOST_COSTS import removed)
modified: src/components/Members/MemberList.vue            (shared ConfirmDialog)
modified: tests/unit/...                                    (affected store/component specs)
modified: tests/e2e/...                                     (leaderboard/dashboard specs)
archived: -
```

## 6. Implementation Order

1. Spike: verify leaderboard-period and boosts/uncomplete endpoint contracts
   against `routes.php` + services; record wire-vs-remove decisions.
2. Medals wiring (store-driven Leaderboard section, celebrate-once).
3. Period filter wire-or-remove; QuickStats private-filter binding.
4. Shared ConfirmDialog + migrate native `confirm()` call sites.
5. Undo snackbar + `uncomplete`/`reactivate` store actions.
6. Settings save toasts.
7. Boost catalog fetch + delete `BOOST_COSTS`.
8. Gate + `concepts/ui-use-cases.md` UC status refresh.

## 7. Risks & Mitigations

| Risk | Likelihood | Mitigation |
|------|-----------|------------|
| Leaderboard period unsupported server-side | Medium | Pre-decided removal path; no backend creep |
| Medal celebration spam on first load | Medium | Celebrate only awards newly returned vs previous store state |
| Undo races with polling/refresh | Low | Optimistic revert keyed on task id + completion timestamp |
| Catalog fetch failure blocks purchases | Low | Explicit disabled state with error message, no silent stale pricing |
| Undo API call fails after optimistic revert | Low | Error toast + store re-fetch to resync task state |

## 8. Acceptance Criteria

- [x] No known dead/misleading control remains (each UX-005 control works or
      is removed with a logged decision)
- [x] Completion offers Undo (~5 s); deletes use styled dialogs; Settings
      saves toast success/failure
- [x] Boost prices originate solely from `GET /minigames/boosts`
- [x] Build gate green (`npm test` + lint + backend tests + `npm run l10n:check-keys`)
- [x] Durable decisions integrated (`concepts/ui-use-cases.md` UC statuses,
      design.md feedback patterns)
- [x] `docs/backlog/ACTIVE_TASKS.md` updated; UX-005/006/013 moved to archive

## Spike Decisions (step 1, 2026-08-22)

| Item | Decision | Rationale (verified against routes.php + services) |
|------|----------|----------------------------------------------------|
| Leaderboard period filter | **Remove** | `GameLogicService::getLeaderboard(int $houseId)` has no period parameter; `GameController::leaderboard()` passes only the house id. Pre-decided removal path. |
| Medals | **Wire** | New `game.checkMedals()` store action (`POST /game/check-medals`, best-effort) then `fetchMedals()`; celebrate-once stays guarded by previous-snapshot size in `fetchMedals`. |
| QuickStats private filter | **Wire via navigation** | Dashboard renders no task list of its own (FloorList → RoomCard show counts only). Binding navigates to `/tasks?visibility=private`; TaskManager gains a route-initialized client-side visibility filter over its existing list — same mechanism class as TodayView's client-side My-tasks scope. `GET /tasks/my-private` stays unused (definitions only, no room-task context). |
| Completion undo | **Wire both endpoints** | `PUT /tasks/{id}/uncomplete` takes the *room task* id and only clears `lastCompleted`; a completed one-time task also has `isActive=false` (`RoomTask::markCompleted`). Undo therefore calls `uncomplete` and, when the result is inactive, the existing `reactivate`. Known limitation (accepted, pre-release): server-side rewards/streaks/recurrence advance are not reverted. |
| Boost catalog | **Fetch once into store** | `GET /minigames/boosts` returns `{ boostId: cost }`; `BOOST_DESCRIPTIONS` (name/icon/effect copy) stays client-side. Purchases blocked while catalog absent. |

## Implementation Notes (2026-08-22)

- **UndoSnackbar** is mounted globally in `App.vue` (listens for
  `chorestrike:task-completed`); views restore their local rows via the new
  `chorestrike:task-uncompleted` event — TodayView updates matching rows and
  deliberately does *not* resurrect rows already removed from the list (the
  server keeps the advanced `nextDue` for recurring tasks).
- `reactivateRoomTask` now returns the server payload (previously `undefined`)
  so `undoRoomTaskCompletion` can chain it.
- `MedalBadge.vue` exports `MEDAL_LABELS` so the Leaderboard medal cards reuse
  the label mapping instead of duplicating it.
- The private-filter wiring navigates (Dashboard → `/tasks?visibility=private`)
  instead of filtering in place: the Dashboard renders no task list of its own,
  and TaskManager's new Visibility filter is the same client-side scope
  mechanism class as TodayView's My-tasks filter. `GET /tasks/my-private`
  stays backend-only (definitions without room-task context).
- `TaskChecklist` sub-task deletes were migrated to the shared ConfirmDialog as
  well (same native-`confirm()` pattern, trivially covered).
- `reactivateRoomTask`'s Jest coverage extended; new specs:
  `ConfirmDialog.spec.js`, `UndoSnackbar.spec.js`.

## Review Outcome

**Standard Review Type A (plan)** — 2026-08-22, verdict **GO**. All factual
claims verified against the codebase (dead period filter + `medals()` placeholder
in `Leaderboard.vue`, unbound `toggle-private-filter`, `uncomplete`/`reactivate`
routes, `GET /minigames/boosts`, `fetchMedals`/check-medals pre-pass,
MemberList confirm pattern, hardcoded `BOOST_COSTS` consumers). Inline fixes:
corrected `GameService` → `GameLogicService` and recorded the review-verified
fact that `getLeaderboard` takes no period parameter (removal path confirmed);
clarified undo-snackbar semantics (revert on *Undo*, hide on dismiss/timeout);
added missing `BoostShop.vue` to the file manifest (imports `BOOST_COSTS`,
build would break without the edit); added i18n requirement (`t()` + `l10n/`);
added undo-API-failure risk row + failure-path Jest coverage; extended build
gate with `l10n:check-keys`. No backlog items (plan review).

**Standard Review Type B (code)** — 2026-08-22, verdict **PASS**. All 44
changed files checked against the shared checklist (architecture, auth,
security, KISS, workarounds, logging, i18n, tests, docs). Verified: medal
payload shape matches `GameLogicService::getMedals` (`id`/`medalType`/
`userName`); celebrate-once guard intact; `BOOST_COSTS` fully removed (grep
clean); endpoint contracts (`PUT /tasks/{id}/uncomplete`, `POST
/room-tasks/{id}/reactivate`, `GET /minigames/boosts`) match store calls;
e2e testids migrated to the shared ConfirmDialog. Auto-fix (1):
`FloorList.confirmDeleteFloor` failure path was logger-only — added
`showError` toast to satisfy the new design.md feedback rule (consistency with
Settings/TaskManager/RoomDetail) + failure-path Jest spec + logger/dialogs
mocks in `FloorList.spec.js`. Deferred (1): undo snackbar holds only the
latest completion → **UX-015** in `backlog.md`. Nits (no action): pre-existing
U+FFFD mojibake on the CS-PLAN-040 archive heading (unchanged); `undoing`
flag in `UndoSnackbar` is unobservable (bar hides before the await);
`minigameStore.initialize()` rejects on catalog failure (pre-existing
unhandled-rejection pattern in `SnakeGame.vue` onMounted, not a regression).
Gate after fixes: lint 0 / stylelint 0 / Jest 765 (765/765) /
`l10n:check-keys` clean / test-noise clean. No PHP changed → no backend
Docker run required. Recommend `active/ → done/`.
