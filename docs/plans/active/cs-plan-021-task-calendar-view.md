# CS-PLAN-021: Task Calendar View (Week / Rolling 30-Day)

> **Status:** Plan (not started)
> **Effort:** L
> **Priority:** P1
> **Depends on:** none hard-blocked; reuses the CS-015/015b E2E conventions (`getByTestId`/`getByRole`, per-user `storageState` auth) and the CS-008 shared `api` client.
> **Related issue:** `docs/backlog/features.md` → [P1] Week / Month (30-day) Calendar View for Tasks (user request 2026-07-27).

## 1. Problem Summary

The app offers only **list** surfaces for tasks (Dashboard, Task Manager, Room Detail, daily list). Users have no **temporal overview** to answer "which chores pile up next week?" or "how is the month distributed?". Planning ahead — the single most common reason people look at a task system — is impossible at a glance.

Relevant current state (verified first-hand):

- **Backend — house-scoped range primitives already exist**, but none returns a single round-trip "tasks in [from, to]" payload:
  - `RoomTaskMapper::findActiveDueIdsBetweenByHouse($houseId, $start, $end)` (`lib/Db/RoomTaskMapper.php:420`) returns **IDs only** (used by the weekly household goal denominator) — via the shared `applyHouseScope()` JOIN chain (rooms → floors → `house_id`).
  - `RoomTaskMapper::findDailyTasks($houseId, $date)` (`lib/Db/RoomTaskMapper.php:366`) returns entities for **one day** (`DATE(next_due)` = date).
  - `CompletedTaskMapper::findCompletedRoomTaskIdsBetweenByHouse($houseId, $start, $end)` (`lib/Db/CompletedTaskMapper.php:372`) returns **distinct IDs** of completed-in-range tasks.
  - Enrichment precedent: `RoomTaskService::getRoomTaskWithDetails()` (`lib/Service/RoomTaskService.php:431`) + `getDailyTasksWithDetails()` (called at `:712`) return `{roomTask, task, room}` tuples.
- **No range route exists** — `appinfo/routes.php` has `room_task#filteredDaily` (`/api/v1/room-tasks/filtered-daily`), `#overdue`, `#dueToday`, `#dueWithinDays`, but **no `?from=&to=` endpoint**. A 30-day calendar built on `dueWithinDays`/`filteredDaily` would fire N daily round-trips — explicitly called out as undesirable in the feature note.
- **No calendar view** — `src/router.js` has 6 routes (dashboard/tasks/room/leaderboard/scheduling/settings); `src/views/` has no calendar; `src/App.vue` has 5 nav items. The `ScheduleCalendar.vue` in Scheduling is an availability grid, not a task calendar.

## 2. Strategy

Add one **house-scoped range endpoint** that returns everything the calendar needs in a single request, then build a week/month calendar view on top of it. Lower risk because every building block exists (house-scoping, enrichment, flex-window bounds, completed-instance tracking); only the date-range composition + the UI are new.

1. **One range endpoint** (`D1`) — `GET /api/v1/room-tasks/range?from=&to=` returns `{ scheduled, completed }` enriched with task/room details + flex window. Single round-trip replaces N daily queries.
2. **Calendar view** (`D3`) — `/calendar` route + `nav-calendar` item (CS-015 nav-testid passthrough). Toggle between **week** (Mon–Sun) and **month** (rolling 30 days). A day cell shows a compact count/badge list; clicking a day expands its task list (reuse the existing list-component look).
3. **Occurrences** (`D2`) — recurring room tasks store only their **next** due date. Phase 1 shows the next-due occurrence of each task in the window **plus all completed instances in range** (history). Full future-occurrence projection (a daily task appearing on all 30 days) is a **Phase-2** enhancement flagged as a decision.
4. **Reuse** — house-scoping via `applyHouseScope`, authorization via the established `ensureHouseMember` + `ControllerErrorFormatter` pattern, shared `src/services/api.js` client, CS-015 testid convention + `storageState` E2E auth.

## 3. Changes

### Backend (`lib/`)

- **New mapper method** `RoomTaskMapper::findActiveDueBetweenByHouse(int $houseId, \DateTimeInterface $start, \DateTimeInterface $end): RoomTask[]` — entity-returning sibling of `findActiveDueIdsBetweenByHouse` (reuses `applyHouseScope`; `WHERE is_active AND next_due BETWEEN start AND end`; `ORDER BY next_due`). *Precedent: `findDailyTasks`.*
- **New mapper method** `CompletedTaskMapper::findCompletionsBetweenByHouse(int $houseId, \DateTimeInterface $start, \DateTimeInterface $end): array` — returns completion rows joined to `room_tasks → tasks → rooms` (reuse the JOIN chain from `findCompletedRoomTaskIdsBetweenByHouse`) projected to `{roomTaskId, taskId, taskName, roomId, roomName, completedAt, completedByUserId}`. House-scoped by `f.house_id`. *This is the "completed instances" data for muted/checkmark rendering.*
- **New service method** `RoomTaskService::getRangeTasks(int $houseId, \DateTime $from, \DateTime $to): array` — mirrors `getDailyTasksWithDetails()` enrichment but **batch-fetches** task + room rows by unique-ID sets (avoid an N+1 over a 30-day range), and composes each scheduled item as `{roomTask, task, room, flexWindow}` where `flexWindow` reuses `RoomTask::getFlexWindowBounds($task->getFlexDays())`. Returns `['scheduled' => [...], 'completed' => [...]]`.
- **New controller action** `RoomTaskController::range(string $from, string $to)` — `@NoAdminRequired`; resolves house via `houseService->getInstanceId()`/`getOrCreateHouse()`; `ensureHouseMember($house->getId())`; **validates** `from`/`to` are parseable dates and `from <= to` (→ 400 `VALIDATION_ERROR`) and **clamps the span** to a maximum (e.g. 60 days) to bound the payload (→ 400 on overflow); catches `ForbiddenException` → `forbiddenResponse()` (403) and `\Exception` → 500 `RANGE_TASKS_ERROR` via `ControllerErrorFormatter::safeMessage()`. Mirrors the exact controller pattern of `dueWithinDays`/`filteredDaily`.
- **New route** `room_task#range` → `GET /api/v1/room-tasks/range` (after `#dueWithinDays` in the Room Task API block).
- **No schema/migration change** — all columns exist (`next_due`, `ideal_due_date`, `completed_at`, `energy_cost`, `task_tier`, `flex_days`). Confirm during Phase 1.

### Frontend (`src/`)

- **`src/router.js`:** add `{ path: '/calendar', name: 'calendar', component: () => import(/* webpackChunkName: "calendar" */ './views/CalendarView.vue') }`.
- **`src/App.vue`:** add `<NcAppNavigationItem :to="{ name: 'calendar' }" icon="<unused-calendar-icon>" data-testid="nav-calendar" :name="t('chorestrike', 'Calendar')" />`. *Scheduling already uses `icon-calendar`; pick an unused Nextcloud calendar icon (e.g. `icon-calendar-week` / `icon-calendar-dark`) and verify it renders.*
- **`src/views/CalendarView.vue`** (new) — week/month toggle (default week), range selector, calls `fetchRangeTasks(from, to)` on mount + on range change, renders `<TaskCalendar>`, owns the page `loading`/`error` (mirror the CS-016 "initialize owns the loading flag" lesson — sub-components use local refs). Testids: `calendar-view`, `calendar-mode-toggle`, `calendar-mode-week`, `calendar-mode-month`, `calendar-prev`, `calendar-next`, `calendar-range-label`.
- **`src/components/Tasks/TaskCalendar.vue`** (new) — grid: 7 columns (week) or day rows / scroll (month). Renders `CalendarDayCell` per day; groups `scheduled` by date + overlays `completed`. Completed instances rendered muted/checkmarked; overdue (nextDue < now) highlighted on their original due date. Testids: `task-calendar`, `calendar-day-{YYYY-MM-DD}`, `calendar-day-count-{YYYY-MM-DD}`.
- **`src/components/Tasks/CalendarDayCell.vue`** (new, optional — can be inline if small) — compact day cell; on click emits `select-day` → `CalendarView` expands a per-day list (reuse `TaskList.vue` look / a shared list render, scoped to that day's items). Testid `calendar-day-list`.
- **`src/store/roomTasks.js`:** add `rangeTasks` state (`{ scheduled: [], completed: [], from: null, to: null }`), a `rangeTasksByDay` getter (groups by `YYYY-MM-DD`), and `fetchRangeTasks(from, to)` action hitting `/api/v1/room-tasks/range` via the shared `api` client. Add `reset()` coverage for the new state (store barrel completeness — `resetAllStores` calls `reset()`).
- **i18n:** all new strings via `t()`; update `l10n/en.json`, `l10n/de.json`, `l10n/templates/chorestrike.pot`. *(Note: the repo has no `l10n:extract` script — CS-016 deferred the `.pot` regen to a backlog item; this plan updates the runtime `en/de.json` and adds keys to the `.pot` manually, consistent with CS-016's resolved approach.)*

### E2E (`tests/e2e/`)

- New `calendar.spec.ts` (family profile, per-user `storageState` auth, no `waitForTimeout`): nav → `nav-calendar`; week/month toggle; prev/next changes the range label; a day with a scheduled task shows a count and the day-list expands; completed instances render. Uses `chromium-serial-db` project only if it mutates seed data (it does not — read-only view; standard `chromium` project is fine).

### Docs

- `docs/engineering/architecture.md`: document the new `/calendar` view + the `range` endpoint in the room-task route group.
- `docs/concepts/design.md`: add the calendar UX concept (currently absent).
- `docs/api/README.md`: document `GET /api/v1/room-tasks/range?from=&to=` (payload shape + max-span clamp).

## 4. Tests

- **Unit (PHPUnit):**
  - `RoomTaskMapperRangeTest` — `findActiveDueBetweenByHouse` returns only active tasks in `[from,to]`, excludes out-of-range, is house-scoped (mock/fixture-based; cross-house isolation asserted at the integration layer).
  - `CompletedTaskMapperRangeTest` — `findCompletionsBetweenByHouse` returns only in-range completions with task/room names.
  - `RoomTaskControllerRangeTest` (extend the existing `RoomTaskControllerTest`) — happy path 200 shape; `from > to` → 400; unparseable date → 400; span-over-max → 400; non-member → 403.
- **Integration (PHPUnit, Docker):** `RangeQueryAuthorizationTest` — house-scoped: tasks/completions from another house never appear; non-member of the caller's house → 403; a 7-day and a 30-day window both resolve against the real DB.
- **Unit (Jest):** `store/roomTasks.spec.js` — `fetchRangeTasks` calls the right endpoint + unwraps; `rangeTasksByDay` grouping (scheduled by `nextDue`, completed by `completedAt`). New `components/TaskCalendar.spec.js` (+ `CalendarView.spec.js`) — renders the grid, toggle switches modes, day cell shows count, `select-day` expands the list, testids present. Add `apiContract.spec.js` parity: the new `range` store call matches a declared route (auto-covered once the route exists).
- **E2E (Playwright):** `calendar.spec.ts` green across chromium (read-only → standard project; `--retries=0` per the CS-015 measurement rule). Full matrix (firefox/webkit) follows the existing test.yml matrix.

## 5. File Manifest

```
new:      lib (method) lib/Db/RoomTaskMapper.php            (findActiveDueBetweenByHouse)
new:      lib (method) lib/Db/CompletedTaskMapper.php       (findCompletionsBetweenByHouse)
new:      lib (method) lib/Service/RoomTaskService.php      (getRangeTasks)
new:      lib (method) lib/Controller/RoomTaskController.php (range action)
modified: appinfo/routes.php                                 (room_task#range)
new:      src/views/CalendarView.vue
new:      src/components/Tasks/TaskCalendar.vue
new:      src/components/Tasks/CalendarDayCell.vue           (optional; inline if small)
modified: src/router.js                                       (/calendar route)
modified: src/App.vue                                         (nav-calendar item)
modified: src/store/roomTasks.js                             (rangeTasks state + getter + action + reset)
new:      tests/unit/Db/RoomTaskMapperRangeTest.php
new:      tests/unit/Db/CompletedTaskMapperRangeTest.php
modified: tests/unit/Controller/RoomTaskControllerTest.php   (range cases)
new:      tests/integration/RangeQueryAuthorizationTest.php
modified: tests/unit/store/roomTasks.spec.js                 (range action + getter)
new:      tests/unit/components/TaskCalendar.spec.js
new:      tests/unit/components/CalendarView.spec.js
new:      tests/e2e/calendar.spec.ts
modified: l10n/en.json, l10n/de.json, l10n/templates/chorestrike.pot
modified: docs/engineering/architecture.md, docs/concepts/design.md, docs/api/README.md
new:      docs/plans/active/cs-plan-021-task-calendar-view.md (manual new/ -> active/ move)
```

## 6. Implementation Order

1. **Phase 1 — Backend range endpoint:** add the two mapper methods + `getRangeTasks` (batch fetch) + `range` controller action + route; date validation + max-span clamp; integration auth test (house-scoped isolation). Confirm no schema change.
2. **Phase 2 — Route + nav + store:** add `/calendar` route, `nav-calendar` item, store `fetchRangeTasks` + `rangeTasksByDay` getter; smoke that the view loads and the endpoint returns data.
3. **Phase 3 — Calendar UI:** `TaskCalendar` grid (week/month) + day cells + completed-overlay + overdue highlight + per-day drill-down; testids; i18n (`t()` + `l10n/` artifacts in the same change).
4. **Phase 4 — Jest component + store specs:** render + toggle + grouping + testid assertions; store action + getter.
5. **Phase 5 — E2E:** `calendar.spec.ts` on the real, testid-bearing UI; green on chromium `--retries=0`.
6. **Phase 6 — Verify:** build gate green (Jest + ESLint 0 errors + PHPUnit unit/integration via Docker + E2E), docs updated, backlog sync.

## 7. Risks & Mitigations

| Risk | Likelihood | Mitigation |
|------|-----------|------------|
| N+1 over a 30-day range (per-item task/room fetch) | High | `getRangeTasks` **batch-fetches** task + room rows by unique-ID sets (one query per relation), mirroring how the medal loop was de-N+1'd in CS-PLAN-012. Enforced by a Jest/PHP spec that counts mapper calls is optional; the batch design is the mitigation. |
| Future-occurrence projection is expected but hard (recurring tasks store only `nextDue`) | Medium | **D2:** Phase 1 shows next-due occurrence + completed history only; full recurrence projection is a documented Phase-2 enhancement (client-side expansion using `frequencyType/value` + the weekday rules already in `RoomTask::calculateNextStaticDue`). AC #6 states this scope explicitly so expectations are set. |
| Revived/new view surfaces a backend bug never exercised at the range granularity | Medium | Phase 2 smoke + Phase 4 specs surface contract gaps early; fix at the source (e.g. a `selectAlias`/SQL-shape issue like the CS-016 `getConsumedEnergyForUserOnDate` regression) rather than masking. |
| Shared `loading` flag unmounts the calendar mid-fetch (CS-016 lesson) | Medium | `CalendarView` owns the page loading flag; `TaskCalendar`/day cells use local refs. Regression test: "fetching does not unmount the grid." |
| Max-span abuse / huge payloads | Low | Controller clamps `to - from` to a max (e.g. 60 days) → 400 on overflow. |
| Date/TZ boundary drift (UTC vs local "day") | Medium | Bucket by the server-local date of `next_due`/`completed_at` using a shared local-date helper (precedent: CS-016 `ScheduleCalendar` Monday-start + local-date fix); cover with an edge-day Jest case. |
| `l10n/templates/chorestrike.pot` not regenerated (no `l10n:extract` script) | Medium | Update runtime `en/de.json` + hand-edit the `.pot` (consistent with CS-016's resolved approach); log a backlog item if a script is wanted. |

## 8. Acceptance Criteria

- [ ] `/calendar` route + `nav-calendar` item render and navigate (`getByTestId('nav-calendar')` via the CS-015 passthrough).
- [ ] Week/Month toggle works; prev/next changes the visible range; the range label is correct and localized.
- [ ] `GET /api/v1/room-tasks/range?from=&to=` returns `{ scheduled, completed }` in a single round-trip, enriched with task + room details + flex window; `from > to` / unparseable date / span-over-max → 400.
- [ ] A day with a scheduled task shows a count and expands to its task list on click; completed instances render distinctly (muted/checkmark); overdue tasks are highlighted on their original due date.
- [ ] **D2 scope stated:** Phase 1 shows each task's next-due occurrence + completed history in range; full future-occurrence projection is a documented Phase-2 follow-up (not blocking this plan's "done").
- [ ] House-scoped: tasks/completions from other houses never appear; non-member of the caller's house → 403 (integration test). `@NoAdminRequired` only; never `@PublicPage`.
- [ ] Every interactive element carries a `data-testid` per the CS-015 convention; the E2E spec uses `getByTestId`/`getByRole` + auto-waiting locators, no `waitForTimeout`.
- [ ] i18n: all new user-visible strings use `t()`; `l10n/` artifacts updated.
- [ ] Build gate green (`npm test` + `npm run lint` 0 errors + `npm run build` + PHPUnit unit/integration via Docker + E2E chromium).
- [ ] Docs updated (architecture/design/api); `docs/backlog/ACTIVE_TASKS.md` updated; `features.md` [P1] item marked shipped.
