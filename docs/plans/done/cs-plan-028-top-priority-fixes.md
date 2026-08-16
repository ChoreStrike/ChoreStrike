# CS-PLAN-028: Top-Priority Fixes — Completion Concurrency, Daily-Tasks SQL, Dark-Mode Colors

> **Status:** Done (2026-08-16) — implemented + Standard-B reviewed, gate green
> **Effort:** M (three independent S-slices + housekeeping)
> **Priority:** P1
> **Depends on:** none
> **Related backlog items:** CR-SVC-53 (P1, closed), BUG-API-DAILY-001 (P2, closed), BUG-UI-001 (P1, closed), BUG-I18N-001 + FEAT-I18N-001 (stale → closed)

## 1. Problem Summary

Three high-priority defects from `docs/backlog/backlog.md` remain open; a fourth
and fifth item turned out to be already implemented (backlog drift) and are
closed as housekeeping.

1. **CR-SVC-53 (P1) — stale `RoomTask` after `lockForUpdate`.**
   `RoomService::completeRoomTask()` fetches the entity **before**
   `beginTransaction()` (`RoomService.php:262`), then locks via
   `RoomTaskMapper::lockForUpdate()` (`:281`), which returns `void`
   (self-assigning UPDATE, `RoomTaskMapper.php:37`). Every post-lock decision —
   `isOverdue()` (`:317`), `markClaimed()` (`:320`), `markCompleted()` (`:340`,
   which derives recurrence from `next_due`/`idealDueDate`) — runs on the
   pre-lock snapshot. Under READ COMMITTED a concurrent winner completing the
   same overdue task advances `next_due`, but this transaction still sees the
   stale overdue state → **spurious overdue-takeover notification**, claim state
   from a stale snapshot, and recurrence computed from a stale `next_due`.
   `HouseUserMapper::findForUpdate()` was already hardened to avoid exactly this
   freshness asymmetry; `lockForUpdate` was not.

2. **BUG-API-DAILY-001 (P2) — `GET /api/v1/tasks/daily` 500s.**
   `RoomTaskMapper::findDailyTasks()` (`RoomTaskMapper.php:355`) passes the
   function expression `DATE(rt.next_due)` as the *column* argument of
   `eq()` — the query builder escapes it as a quoted column name →
   `SQLSTATE[42S22] Unknown column 'DATE(rt.next_due)'`. The endpoint has been
   silently 500ing since CS-PLAN-023 un-shadowed the route; the frontend daily
   view degrades to an empty state.

3. **BUG-UI-001 (P1, remainder) — hardcoded status colors, unreadable in Dark Mode.**
   `TaskList.vue` and `FlexWindowDisplay.vue` already use
   `var(--color-*, <hex-fallback>)` (landed after the item was written). Still
   raw hex, no dark-mode adaptation: `RoomCard.vue` status badges + cleanliness
   fills (`.status-badge/.cleanliness-fill.excellent…terrible`, ~L330–414) and
   `FloorList.vue` cleanliness badges + delete-button hover (~L805–868).

4. **BUG-I18N-001 / FEAT-I18N-001 — stale backlog entries.**
   `initI18n()` + `loadTranslations()` incl. Jest mock & spec exist
   (commit `1c889f2`), `<default_language>de</default_language>` is in
   `appinfo/info.xml:25`, and `docs/engineering/deployment.md:48-58` documents
   the server-side `default_language` fallback. Everything code-side is done;
   the remaining "confirm on the personal server" checkbox is an ops task, not
   a code task. Both items are closed and moved to `archive/2026-08.md`.

## 2. Strategy

Three independent slices, ordered by risk, each shippable on its own:

- **Slice A (CR-SVC-53):** mirror `HouseUserMapper::findForUpdate()`: make
  `RoomTaskMapper::lockForUpdate()` **return a fresh `RoomTask`** — on locking
  engines a real `SELECT … FOR UPDATE` re-fetch, on SQLite the existing
  self-assign UPDATE (which serializes writers via the database-wide write
  lock) followed by `find()`. Replace the pre-lock entity in
  `completeRoomTask()` with the post-lock one so all gates and mutations see
  the freshest committed state.
- **Slice B (BUG-API-DAILY-001):** replace the broken `DATE(rt.next_due) = :day`
  equality with a `[start, end)` range comparison on `rt.next_due`
  (`gte` day-start / `lt` next-day-start, `PARAM_DATE`), the proven pattern from
  `CompletedTaskMapper::countByUserOnDate()`. Real-DB integration test as the
  regression guard (a mock cannot catch SQL shape errors).
- **Slice C (BUG-UI-001):** sweep the remaining raw *status/cleanliness* hex
  in `RoomCard.vue` and `FloorList.vue` to Nextcloud CSS variables (the
  selectors listed in §3); keep TaskList/FlexWindowDisplay as they are
  (var + fallback is compliant). Decorative icon-shell colors
  (`.room-icon-shell.*`, `.floor-icon-shell.*`) are intentionally out of
  scope. Backlog housekeeping for the two stale i18n items rides along in the
  same change set.

## 3. Backend / Frontend Changes

- **Backend** (`lib/`):
  - `lib/Db/RoomTaskMapper.php` — `lockForUpdate(int $roomTaskId): RoomTask`
    (platform split like `HouseUserMapper::findForUpdate`); PHPDoc updated.
  - `lib/Service/RoomService.php` — `completeRoomTask()` discards the pre-lock
    `$roomTask` for the locked, fresh entity (`$roomTask = …lockForUpdate(…)`).
    The pre-lock fetch stays (it provides `$task`, `$room`, and the early
    membership resolution before the transaction is opened).
- **Frontend** (`src/`):
  - `src/components/Dashboard/RoomCard.vue` — `.status-badge.*` and
    `.cleanliness-fill.*`: `#4CAF50`→`var(--color-success)`,
    `#2196F3`→`var(--color-primary)`, `#FF9800`→`var(--color-warning)`,
    `#FF5722`→`var(--color-warning)` (poor), `#F44336`→`var(--color-error)`;
    badge text `white`→`var(--color-primary-text)`.
  - `src/components/Dashboard/FloorList.vue` — `.cleanliness-badge.*`: same
    mapping; `.delete-button:hover` `#F44336`→`var(--color-error)`.
- **Cross-layer contracts:** none (no payload/route changes).
- **Docs/backlog:** close BUG-I18N-001 + FEAT-I18N-001 (move to
  `docs/backlog/archive/2026-08.md` with a "already implemented in `1c889f2` /
  info.xml" note); rescope BUG-UI-001's checklist to RoomCard/FloorList before
  closing it in this plan (the TaskList/FlexWindowDisplay rows are already
  done; FloorList was not on the original checklist).

## 4. Tests

- **Unit (PHPUnit):**
  - `RoomServiceTest` — existing specs must stay green with the refreshed
    entity; add a case asserting the entity used for the overdue-takeover
    decision comes from `lockForUpdate()` (mock returns a fresh object whose
    overdue state differs from the pre-lock fetch → takeover must be skipped).
- **Integration (PHPUnit, Docker):**
  - `tests/integration/Service/ConcurrentCompletionTest.php` — extend (or add a
    sibling `ConcurrentOverdueTakeoverTest`) with a two-connection scenario:
    connection 1 completes the overdue task and commits; connection 2 then runs
    `completeRoomTask()` for a non-assignee on the same task → **no**
    takeover notification, recurrence based on the advanced `next_due`.
    Must fail on the old (void-returning) code — analogous to the existing
    `findForUpdate` proof.
  - New `RoomTaskMapperDailyTest` (real DB): tasks due today/tomorrow/yesterday
    + inactive → only today's row returned; cross-day boundary (23:59 vs
    00:00) covered. This is the BUG-API-DAILY-001 regression test.
- **Frontend (Jest):**
  - Where a component-level spec exists for RoomCard/FloorList status classes,
    assert the classes (`.status-badge.fair` etc.) rather than colors; CSS
    variables are not resolvable in jsdom — the meaningful automated assertion
    is "no raw hex left in the swept selector blocks" via a small lint-style
    spec (regex over the SFC source, scoped to `.status-badge.*`,
    `.cleanliness-fill.*`, `.cleanliness-badge.*`, `.delete-button:hover`),
    mirroring the theme-consistency assertion BUG-UI-001 asks for. A
    whole-file raw-hex regex would false-positive on out-of-scope colors
    (`.room-icon-shell.*`, `.floor-icon-shell.*`, `.room-card.needs-cleaning`).
- **E2E (Playwright):** no new specs; `Release:task-lifecycle` and the daily
  flow specs cover the endpoint + surfaces in `chromium-serial-db`.

## 5. File Manifest

```
modified: lib/Db/RoomTaskMapper.php
modified: lib/Service/RoomService.php
modified: src/components/Dashboard/RoomCard.vue
modified: src/components/Dashboard/FloorList.vue
new:      tests/integration/Db/RoomTaskMapperDailyTest.php
modified: tests/integration/Service/ConcurrentCompletionTest.php (or new sibling)
modified: tests/unit/Service/RoomServiceTest.php (or new sibling spec)
new:      tests/unit/theme-consistency.spec.js (RoomCard/FloorList raw-hex guard)
modified: docs/backlog/backlog.md (close BUG-I18N-001, FEAT-I18N-001, BUG-UI-001 remainder; link this plan)
modified: docs/backlog/archive/2026-08.md (records for the closed items)
```

## 6. Implementation Order

1. **Slice B first (unblocks a 500ing endpoint, smallest risk):** fix
   `findDailyTasks()` range comparison + integration regression test + run
   PHPUnit integration (Docker).
2. **Slice A:** mapper `lockForUpdate` → fresh-entity return; caller refresh;
   unit test + two-connection integration proof; run full PHPUnit.
3. **Slice C:** color sweep RoomCard/FloorList + theme-consistency spec; visual
   light/dark check in the Docker env.
4. **Housekeeping:** backlog updates (close stale i18n items + BUG-UI-001),
   `ACTIVE_TASKS.md` entry, gate run (`npm test`, `npm run lint`,
   `composer cs:check`, PHPUnit unit+integration, relevant E2E specs).
5. Propose `/plan-review` before `new/ → active/`.

## 7. Risks & Mitigations

| Risk | Likelihood | Mitigation |
|------|-----------|------------|
| `SELECT … FOR UPDATE` re-fetch returns no row (row deleted between fetch and lock) | Low | `findForUpdate` already handles `DoesNotExistException`; let it bubble → 404-style handling stays with the controller. SQLite path unaffected (self-assign UPDATE on 0 rows is a no-op; `find()` throws as today). |
| Behavior change: takeovers that used to fire now correctly don't | Medium | That is the intended fix; unit + integration tests pin the new semantics. |
| Daily-range comparison changes which tasks count as "due today" (time-component rows previously matched by `DATE()` on MySQL) | Medium | Range `[00:00, +1d)` is semantically identical to `DATE(col) = :day`; boundary tests pin it. The old code **never worked** on any platform (500), so there is no working behavior to preserve. |
| Color mapping shifts light-mode look (NC vars ≠ Material palette) | Medium | `var(--color-*, <old-hex>)` fallbacks keep light mode close; visual check both themes; poor/terrible both map to error/warning as appropriate. |
| `stylelint:fix` auto-applies use-logical churn (TECH-LINT-004 warning) | Medium | Never run repo-wide `stylelint:fix`; edit the two SFCs by hand, run `npm run stylelint` (warnings allowed, 0 errors). |

## 8. Acceptance Criteria

- [x] `RoomTaskMapper::lockForUpdate()` returns the freshest committed
      `RoomTask`; `completeRoomTask()` uses it for every post-lock decision.
- [x] Two-connection integration test proves no spurious overdue-takeover and
      correct recurrence after a concurrent completion (fails on old code —
      verified 2026-08-16: reverting the caller to discard the fresh entity
      makes the test fail with `was_claimed = 1`).
- [x] `GET /api/v1/tasks/daily` returns 200 with the correct day's tasks in the
      Docker integration env; regression test present
      (`tests/integration/Db/RoomTaskMapperDailyTest.php`).
- [x] No raw status hex left in `RoomCard.vue` / `FloorList.vue` styles;
      theme-consistency spec guards it; light + dark visually verified
      (computed-style check: badges resolve to the NC dark palette variables
      under `prefers-color-scheme: dark`, `--color-main-background` #171717).
- [x] BUG-I18N-001, FEAT-I18N-001, BUG-UI-001 remainder moved to
      `docs/backlog/archive/2026-08.md`; `backlog.md` + `ACTIVE_TASKS.md`
      updated; CR-SVC-53 + BUG-API-DAILY-001 linked to this plan.
- [x] Build gate green (`npm test` 699 + `npm run lint` + stylelint 0 errors +
      `composer cs:check` + PHPUnit unit 682 & integration 155 [24 pre-existing
      skips]; E2E `Release:*` suite 16/16 in `chromium-serial-db`,
      `--retries=0`). One pre-existing CS nit auto-fixed along the way
      (`TemplateService.php` PHPDoc alignment, committed-tree leftover).
- [x] Durable outcomes integrated: concurrency pattern note appended to
      ADR-007 (fresh-entity-after-lock), `docs/api/` daily-tasks entry
      checked (documents the contract only, not the SQL filter — nothing to
      correct).
- [x] `docs/backlog/ACTIVE_TASKS.md` updated.

## 9. Implementation Notes (2026-08-16)

- `RoomTaskMapperDailyTest` rows each need their own task definition: the
  `uniq_rt` unique (room_id, task_id) constraint rejects assigning the same
  task to the same room twice (caught on first integration run).
- The two-connection proof lives in `ConcurrentCompletionTest` (third test):
  T1 (helper process) holds the room-task row lock, then commits a winner's
  `next_due` advance; T2 runs `completeRoomTask()` for a non-assignee whose
  pre-lock fetch saw the overdue state.
- Environment note: the E2E env must come up with `TEST_DB_PROFILE=family`
  (PowerShell: `$env:TEST_DB_PROFILE='family'`) or the auth setup cannot
  log in mom/dad/teen (the entrypoint provisions those NC accounts).
- Docker env had to be reset once (`down -v` + `up -d`) — stale volume left
  the entrypoint install failing with "The Login is already being used".

## Review Outcome (2026-08-16, Standard B)

Reviewed the full uncommitted CS-PLAN-028 change set (3 lib files, 2 SFCs,
4 test files, 4 doc/backlog files). Shared checklist clean on all points:

- **Architecture** — `lockForUpdate()` mirrors `HouseUserMapper::findForUpdate`
  exactly (platform split, named parameters, `DoesNotExistException`); no SQL
  concatenation; layering intact.
- **Authorization / Security** — no controller or input-surface changes;
  membership/ownership gates untouched; typed int IDs only.
- **KISS / Workarounds / Logging / i18n** — minimal focused diff; no HACKs;
  no logging changes; no user-visible strings (l10n keys stay synchronized).
- **Tests** — unit fresh-entity spec, two-connection integration proof
  (MySQL-gated, deterministic), real-DB daily-range regression incl. day
  boundaries + house isolation, scoped theme-consistency guard. All verified
  green during the review.
- **Docs** — ADR-007 durable note appended; backlog/archive/ACTIVE_TASKS
  housekeeping matches the backlog rules.

**Auto-fixes:** none required. **Findings:** none blocking/major/minor. One
nit recorded for the report only (decorative raw hex outside BUG-UI-001 scope,
e.g. `TaskList.vue` `.completed-check` — readable in both themes, no action).

**Gate re-verified during review:** ESLint clean · Jest 699/699 · stylelint
0 errors (26 pre-existing use-logical warnings, TECH-LINT-004) ·
`l10n:check-keys` clean · `composer cs:check` 0/254 · PHPUnit unit 682/682 ·
PHPUnit integration 155 OK (24 pre-existing skips), incl. the 5 new/extended
regression tests run explicitly.

**Result:** approved — moved to `docs/plans/done/` (2026-08-16).
