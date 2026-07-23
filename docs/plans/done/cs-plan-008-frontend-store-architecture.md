# CS-PLAN-008: Frontend Store Architecture — Shared API Layer & Room-Task Ownership

> **Status:** Done (2026-07-23)
> **Effort:** L
> **Priority:** P1
> **Depends on:** CS-PLAN-007 (release gate green — Jest 512/512, ESLint 0 errors, contract tests in place) · Done
> **Related issue:** —

## 1. Problem Summary

The release gate is now automated and green (CS-PLAN-007), but two **HIGH-priority** frontend-architecture items that CS-PLAN-007 explicitly scoped out remain open in `docs/backlog/phase-9-code-quality.md`:

- **P9-ARCH-002 — Mixed URL styles, no shared API layer.** All 10 Pinia stores call `@nextcloud/axios` directly (82 `axios.*` call sites) and use **two different URL prefixes** with no single source of truth:
  - `/apps/chorestrike/...` → `game.js`, `house.js`, `checklist.js`, `scheduling.js`, `minigame.js`, `user.js`
  - `/index.php/apps/chorestrike/...` → `rooms.js`, `tasks.js`, `roomTasks.js`, `titles.js`
  
  Both resolve in a default Nextcloud, but the split is accidental (per-store history), increases route-prefix drift risk, duplicates the response-unwrap (`response.data.success` → `response.data.data`) and error-mapping boilerplate in every action, and makes the contract test (`apiContract.spec.js`) normalize two prefix forms to compare against routes.
- **P9-ARCH-003 — Duplicated room-task logic across two stores.** `src/store/tasks.js` (the *catalog-task* store — templates, categories, search) and `src/store/roomTasks.js` (the canonical *room-task assignment* store, 800 lines) **both** own a `roomTasks` state slice, parallel getters (`getRoomTaskById`, `activeRoomTasks`, `overdueRoomTasks`, `dueTodayRoomTasks`, `dueSoonRoomTasks`, `roomTasksByRoom`, `roomTasksByUser`) and a `fetchRoomTasks()` action that hits the **identical** endpoint (`tasks.js:169` == `roomTasks.js:129` → `/api/v1/room-tasks?roomId=${roomId}&activeOnly=${activeOnly}`). This splits the single source of truth for room-task state, lets the two copies drift in business behavior, and complicates every view that touches room tasks (`RoomDetail.vue`, `TaskManager.vue`, `TaskForm.vue`, `src/store/index.js` all instantiate **both** stores).

CS-PLAN-007's `apiContract.spec.js` only asserts that declared store calls match a route — it does **not** detect this intra-frontend duplication. The existing passing gate therefore protects the contract boundary but not the architecture boundary. These two items are coupled (both live in the store layer and both benefit from a shared client), so they are delivered as one plan with a strict phase order.

## 2. Strategy

A pure refactor — **no happy-path or API-contract change, no new features.** (One deliberate, documented exception — a previously-silent error path now surfacing; see the minigame note in §3.) Two phases, each independently reviewable and each leaving the build gate green.

1. **Phase 1 (ARCH-002) — Introduce a shared API layer.** Create `src/services/api.js`, a thin wrapper over `@nextcloud/axios` that owns the canonical URL prefix (single constant), exposes typed `get/post/put/patch/delete` helpers, unwraps the `{ success, data, error }` envelope once, and normalizes errors to an `ApiError` shape whose message **prefers the backend's already-sanitized `error.message`** (the CS-PLAN-002 `ControllerErrorFormatter` output) and only falls back to a single translated generic message when the backend sends none. Migrate all 10 stores to import `api` instead of calling `axios` directly, deleting the per-action unwrap boilerplate. Every call's effective URL must stay bit-identical (the new client resolves the single prefix to the same path Nextcloud already serves).
2. **Phase 2 (ARCH-003) — Consolidate room-task ownership.** Make `roomTasks` the sole owner of room-task state/actions. Reduce `tasks.js` to catalog-task concerns only. Migrate the few consumers of the duplicated `tasks` room-task surface to the `roomTasks` store. Update `src/store/index.js` orchestration accordingly.
3. **Phase 3 — Docs, contract hardening, backlog sync.** Update `docs/ARCHITECTURE.md` + `docs/TESTING.md` to describe the real layer, tighten the contract test to assert a single prefix, and mark P9-ARCH-002/003 Done.

The order is deliberate: the shared client lands first so Phase 2's consolidated store uses it from the start.

## 3. Backend / Frontend Changes

- **Backend (`lib/`):** **None.** This plan touches the frontend only. No routes, controllers, services, mappers, or migrations change. (The `apiContract.spec.js` normalization that currently strips two prefixes will be simplified, but that is a test artifact, not a backend change.)
- **Frontend (`src/`):**
  - **New `src/services/api.js`** — shared client. Exposes:
    - a single `API_PREFIX` constant set to `/apps/chorestrike` (the majority form, already proven by 6 of 10 stores),
    - `request(method, path, config)` core that builds `${API_PREFIX}${path}`, calls `@nextcloud/axios`, unwraps `response.data`, and on any failure (HTTP non-2xx **or** `success === false`) throws a normalized `ApiError` whose message is the backend `error.message` when present, else a single translated generic message — i.e. the same `backend || generic` precedence every store already uses today,
    - `get / post / put / patch / delete` thin shims,
    - a JSDoc-documented contract so stores can rely on resolved `data` directly.
  - **All 10 stores migrated** to `import { api } from '../services/api.js'`; each action drops the `response.data.success` / `response.data.data` boilerplate. The per-action `tr('Failed …')` fallbacks are removed because `ApiError` carries the backend message with the same `backend || generic` precedence the stores already use.
  - **`src/store/tasks.js`** — remove the duplicated room-task surface (`state.roomTasks`, the 7 room-task getters, `fetchRoomTasks`). Keep catalog-task state/actions only (`tasks`, `templates`, categories, search, templates CRUD). If a catalog view needs a room-task derived value, it reads from the `roomTasks` store.
  - **`src/store/roomTasks.js`** — stays the canonical owner; gains any getter previously only on `tasks.js` that `roomTasks.js` lacks and that a migrated consumer needs.
  - **Consumer migration** — `RoomDetail.vue`, `TaskManager.vue`, `TaskForm.vue`, `src/store/index.js`: replace `tasksStore.roomTasks*` / `tasksStore.fetchRoomTasks` reads with the `roomTasks` store. (QuickStats, RoomCard, TaskList already use `roomTasks` only — no change.)
  - **Intentional, documented behavior change** — a few actions currently tolerate a `success:false` body silently (no `else` throw, error swallowed in `catch`): `minigame.js` `fetchLeaderboard` / `fetchWeeklyLeaderboard` / `fetchPersonalBest`. The throwing client surfaces these (sets `this.error` on `!success` instead of leaving it null). This is a deliberate error-visibility improvement, **not** a happy-path change: successful responses behave identically. It is covered by a dedicated test and called out explicitly so the "no behavior change" claim is read as "no happy-path / contract change".
- **Cross-layer contracts:** unchanged. Every request URL that leaves the browser is preserved; the server sees the same paths. The `{ success, data, error }` envelope is the only response shape and is unchanged.

## 4. Tests

- **Unit (Jest)**
  - **New `tests/unit/services/api.spec.js`** — asserts prefix construction, envelope unwrap, `!success` → `ApiError`, HTTP-error → `ApiError` with translated message, and that each method verb maps to the right axios call (mock `@nextcloud/axios`).
  - **New/extended store tests** — regression that each migrated store action still resolves to the same effective URL and returns unwrapped `data` (mock `api`, assert the path string the store passes in). Locks the "no URL change" guarantee.
  - **`tasks.spec.js` / `roomTasks.spec.js`** — update for the dedup: remove/rewrite the duplicated room-task assertions on `tasks`, keep/extend them on `roomTasks`. Add a regression that `useTasksStore()` no longer exposes `state.roomTasks` / `getRoomTaskById` / `fetchRoomTasks`.
  - **`apiContract.spec.js`** — simplify the prefix normalization (single prefix expected) and add an assertion that **no** store call uses the deprecated `/index.php/apps/chorestrike` prefix form.
- **Unit (PHPUnit):** unaffected — backend untouched. `RouteMatrixTest.php` stays green by construction.
- **Integration (PHPUnit, Docker):** unaffected; included in the gate run only as a guard against accidental backend edits.
- **E2E (Playwright):** no new specs (behavior unchanged). The existing room-task flows (`room-tasks`, `private-tasks`, `teamwork-rewards`, `overdue-task-claiming`) act as regression coverage that the refactor did not break the real data flow.

## 5. File Manifest

```
new:      src/services/api.js
new:      tests/unit/services/api.spec.js
modified: src/store/game.js
modified: src/store/house.js
modified: src/store/rooms.js
modified: src/store/checklist.js
modified: src/store/scheduling.js
modified: src/store/minigame.js
modified: src/store/user.js
modified: src/store/titles.js
modified: src/store/tasks.js          (room-task surface removed; catalog-task only)
modified: src/store/roomTasks.js      (canonical owner; gains any missing shared getter)
modified: src/store/index.js          (orchestration: drop tasks.roomTasks coupling)
modified: src/views/RoomDetail.vue
modified: src/views/TaskManager.vue
modified: src/components/Tasks/TaskForm.vue
modified: tests/unit/store/apiContract.spec.js   (single-prefix assertion)
modified: tests/unit/store/tasks.spec.js
modified: tests/unit/store/roomTasks.spec.js
modified: docs/ARCHITECTURE.md        (describe src/services/api.js + canonical store boundary)
modified: docs/TESTING.md             (api.spec.js in the release-gate list)
modified: docs/backlog/phase-9-code-quality.md  (P9-ARCH-002 / P9-ARCH-003 → Done)
```

No archives expected (no files deleted). If `tasks.js` becomes small enough to merge elsewhere, that decision is deferred — see Open Questions.

## 6. Implementation Order

1. **Spike the client.** Implement `src/services/api.js` + `tests/unit/services/api.spec.js`; get the unit suite green in isolation.
2. **Migrate one store as the template.** Pick `user.js` (smallest, single prefix) → migrate, run its spec + `apiContract.spec.js`, confirm identical URL. This validates the client before the bulk migration.
3. **Bulk URL migration (Phase 1).** Migrate the remaining 9 stores, store by store, running the gate (`npm test` + `npm run lint` + `npm run build`) after each. Both prefix forms collapse to the single `API_PREFIX`.
4. **Audit consumers (Phase 2 entry).** Grep for every read of the duplicated `tasks` room-task surface (`tasksStore.roomTasks`, `.getRoomTaskById`, `.fetchRoomTasks`, `.activeRoomTasks`, `.overdueRoomTasks`, `.dueTodayRoomTasks`, `.dueSoonRoomTasks`, `.roomTasksByRoom`, `.roomTasksByUser`) and list call sites.
5. **Consolidate ownership.** Remove the room-task surface from `tasks.js`; move any missing shared getter onto `roomTasks.js`; rewrite each consumer (views + `store/index.js`) to use `roomTasks`.
6. **Update tests.** Move/rewrite the duplicated room-task assertions onto `roomTasks`; add the "tasks no longer exposes roomTasks" regression; tighten `apiContract.spec.js` to a single prefix.
7. **Docs + backlog.** Update ARCHITECTURE.md / TESTING.md; mark P9-ARCH-002 / P9-ARCH-003 Done; add a "Recently Completed" entry to `ACTIVE_TASKS.md`.
8. **Final gate.** `npm test` + `npm run lint` + `npm run build` green; spot-check one room-task E2E spec locally if the Docker E2E env is available.

## 7. Risks & Mitigations

| Risk | Likelihood | Mitigation |
|------|-----------|------------|
| A migrated call's effective URL changes and breaks a route the contract test doesn't cover | Medium | Per-store migration with immediate `apiContract.spec.js` run; new store test asserts the exact path string passed to `api`; both old prefixes resolve identically in Nextcloud, so a verified mismatch is caught by tests before deploy. |
| `ApiError` message wording differs from the per-action `tr('Failed …')` fallbacks, surfacing in a test or UI | Low | `ApiError` keeps today's `backend-message || generic` precedence, so the user-visible text is unchanged wherever the backend already sends a message; only the rare generic-fallback path and the few specs asserting the old fallback strings change. |
| Throwing on `success:false` changes error visibility for the actions that currently tolerate it silently (minigame leaderboards / personal-best) | Low | Deliberate: those actions move from silent to surfaced `this.error`. Documented in §3 and covered by a dedicated test; happy-path results are unchanged. |
| A view silently depended on the `tasks` store's `roomTasks` state (now removed) and renders empty | Medium | Phase 2 starts with an explicit consumer audit (step 4) before any deletion; the "tasks no longer exposes roomTasks" regression + existing E2E specs guard the real flows. |
| `tasks.js` and `roomTasks.js` getters drifted subtly (e.g. `roomTasksByUser` `assignedToUserId` semantics) and consolidation changes behavior | Medium | Diff the two getter implementations during the audit; port the canonical (roomTasks) version; if they differ, prefer roomTasks and note it in the review. |
| Store-to-store coupling via `src/store/index.js` orchestration breaks on dedup | Low | `store/index.js` is migrated as an explicit step; its reset/hydrate helpers are covered by existing specs. |
| Scope creep into backend or new features | Low | Hard rule: no `lib/` edits, no API contract changes; any finding that needs a backend change is logged as a separate backlog item. |

## 8. Acceptance Criteria

- [x] `src/services/api.js` exists and all 10 stores consume it (no remaining raw `@nextcloud/axios` import in `src/store/`).
- [x] Exactly one URL prefix is used across the frontend (`API_PREFIX = /apps/chorestrike`); `apiContract.spec.js` asserts the deprecated `/index.php/apps/chorestrike` prefix form is absent.
- [x] User-facing error text stays domain-specific: `ApiError` surfaces the backend `error.message` first and uses the generic message only as a fallback (regression test asserts precedence).
- [x] `success:false` handling is documented and matched by a test; the minigame leaderboards/personal-best actions' new error-surfacing is intentional and covered (happy-path results unchanged).
- [x] Room-task state/actions live only in `roomTasks`; `useTasksStore()` no longer exposes `state.roomTasks`, room-task getters, or `fetchRoomTasks` (regression test present).
- [x] `src/store/index.js` orchestration (`resetAllStores()` + hydration) still functions after the `tasks` room-task surface is removed.
- [x] No happy-path/contract change: every affected view still loads/edits room tasks; E2E room-task specs (`room-tasks`, `private-tasks`, `teamwork-rewards`, `overdue-task-claiming`) pass.
- [x] `tests/unit/services/api.spec.js` passes and documents the client contract.
- [x] Build gate green: `npm test` (525/525) + `npm run lint` (0 errors) + `npm run build`; PHPUnit unit + integration green via the CS-PLAN-007 Docker recipe.
- [x] `docs/ARCHITECTURE.md` + `docs/TESTING.md` describe `src/services/api.js` and the canonical store boundary.
- [x] `docs/backlog/phase-9-code-quality.md` P9-ARCH-002 + P9-ARCH-003 marked Done; `docs/backlog/ACTIVE_TASKS.md` updated.

---

## Open Questions (decide before `new/ → active/`)

1. **Room-task dedup strategy on `tasks.js`.** Recommended: **Option A — remove** the room-task surface entirely (KISS, single owner). Alternative: **Option B — read-only composition** (`tasks.js` getters delegate to `roomTasks`, no own state). Option A is cleaner and matches the "define a single owner" wording of P9-ARCH-003; Option B keeps existing `tasksStore.*` call sites working at the cost of an indirection layer. → Plan recommends **A**.
2. **`ApiError` UX.** Resolved by review: `ApiError` carries the backend `error.message` (already safe/translated by CS-PLAN-002 `ControllerErrorFormatter`) and falls back to one shared generic key (`t('chorestrike', 'Request failed')`) only when the backend sends none. No per-action strings are needed; the user-visible text is unchanged wherever the backend already sends a message.
3. **Out of scope (logged, not done here):** `bugs.md` P3 "dead `users/{id}/stats` + `UserStatsController`" and P3 "security contact/GPG key" are unrelated small items — separate decisions, not bundled into this plan.
