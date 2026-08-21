# CS-PLAN-040: Transparency & Insights UI — History, Fairness, Streak-Freezes, Overcapacity Warning

> **Status:** Done (2026-08-21) — implemented, Standard-B review clean (5 auto-fixes), moved to `done/`
> **Effort:** L
> **Priority:** P2
> **Depends on:** none — builds on the endpoint audit + UI use-case inventory
> (both 2026-08-21, CS-PLAN-039)
> **Related items:** FEAT-UX-016, FEAT-UX-017, FEAT-UX-020, FEAT-UX-022,
> CR-SVC-31 (all in `docs/backlog/backlog.md`)

## 1. Problem Summary

Four thematically related gaps share one theme — *shipped backend data with no
UI surface* — and are bundled here because they touch the same stores, views,
and concept docs:

1. **FEAT-UX-016 (P2, History):** design.md §6 promises a completed-task
   history ("who did what when"). Completions are stored in
   `chorestrike_completed_tasks` (actor, timestamp, combo flag) and
   `CompletedTaskMapper` already has house-scoped readers
   (`findCompletionsBetweenByHouse`, `getUserStatsByHouse`) — but no endpoint
   lists them and no UI shows them.
2. **FEAT-UX-017 (P2, Insights/Fairness):** `GET /house/stats`
   (`HouseController::stats`, `lib/Controller/HouseController.php:124`) has no
   consumer (dead wrapper `fetchHouseStats`, `src/store/house.js:70`);
   energy-and-balancing.md requires fairness comparisons normalized by each
   member's *available energy capacity*, not raw task counts, and mandates
   showing contribution and coins separately. The `completionRate` metric is
   wrong (CR-SVC-31: `(total − active)/total` counts deactivated/skipped tasks
   as completed; empty set returns 100%).
3. **FEAT-UX-020 (P3, Streak freezes):** restock job, combo bonus, and
   consumption all ship, but no surface shows inventory or active-freeze
   state. The count rides in every serialized `HouseUser`
   (`streakFreezes`, unused getter `gameStore.userStreakFreezes`,
   `src/store/game.js:123`); the consumption date (`lastFreezeDate`) is not
   serialized anywhere. A freeze-saved-streak toast already exists
   (`useToasts.js:35` on `chorestrike:streak-freeze-used`).
4. **FEAT-UX-022 (P3, Overcapacity):** the policy is locked in
   energy-and-balancing.md ("show a scheduling warning that explains which
   tasks were deferred and why"), but verification (grep of `src/` + `lib/`)
   confirms **no** planned-energy-vs-capacity comparison exists anywhere.
   Building blocks exist: `schedulingStore.getTotalEnergyForDay` (available
   capacity, `src/store/scheduling.js:148`), per-task `energyCost`, suggestions
   `totalEnergy`.

Additionally, implementing on these endpoints forces the expose-or-prune
decisions that FEAT-UX-016/017 explicitly require first — and the audit found
one security defect on the way: `GET /room-tasks/room/{roomId}/stats`
(`RoomTaskController.php:894`) validates house membership of the *caller* but
never that the *roomId belongs to that house* — any member can read another
house's room stat counts (counts-only leak; all sibling room endpoints call
`validateRoomOwnership`).

## 2. Strategy

Four phases, each independently shippable behind a green gate:

- **A — Decisions & cleanup (backend):** record expose-or-prune per dead
  stats endpoint (table below), delete pruned routes/actions/tests and the
  dead `UserStats*` classes, remove dead store wrappers. Fix `roomStats`
  authorization. Fix CR-SVC-31 at the root (count real completions).
- **B — Activity endpoint (backend):** new house-scoped
  `GET /api/v1/house/activity` returning enriched completions (actor,
  task title, room name, timestamp, combo flag) — the single data source for
  history *and* per-member completed-energy aggregation. Serialize
  `lastFreezeDate` on `HouseUser` for active-freeze display.
- **C — Insights surface (frontend):** new `/insights` route +
  `InsightsView.vue`: house stats cards (`GET /house/stats`), member fairness
  table (contribution vs coins in separate columns, load normalized by
  capacity), activity feed. Freeze badges on the Leaderboard streak display.
  RoomDetail gets a room-stats card (makes `fetchRoomTaskStats` live).
- **D — Overcapacity warning (frontend):** scheduling-store getters compute
  planned energy per day (active room tasks due that day × task energy cost)
  vs available capacity (`getTotalEnergyForDay`); a warning component in
  SchedulingView plus per-day markers in ScheduleCalendar explain *which*
  tasks would be deferred first (lowest priority first, per the locked
  policy).

No auto-deferral scheduler changes — the locked policy's *warning* duty is
implemented; deferral logic itself stays out of scope.

### Expose-or-prune decisions (confirmed at plan review)

| Endpoint / code | Decision | Rationale |
|---|---|---|
| `GET /house/stats` | **expose** | data source for Insights cards; wrapper becomes live |
| `GET /room-tasks/room/{roomId}/stats` | **expose after fix** | RoomDetail stats card; needs house-validation fix + CR-SVC-31 fix first |
| `GET /users/{id}/stats` (`user#stats`) | **prune** | fully dead (no wrapper, BUG-USER-001); every field also in `GET /users` serialization (`HouseUser::jsonSerialize`) |
| `GET /room-tasks/user/{userId}` (`userTasks`) | **prune** | reads only the legacy single-assignee column — misleading since multi-assignee (`task_assignees`); wrapper dead |
| `GET /room-tasks/user/{userId}/stats` (`userStats`) | **prune** | CR-SVC-31-flawed metric, no consumer; fairness uses energy-based activity data instead |
| Wrappers `fetchUserTasks`, `fetchUserTaskStats` | **delete** | dead; endpoints pruned |
| Wrapper `fetchHouseStats` | **replace** | superseded by `fetchInsights` (same endpoint, richer handling) |
| Wrapper `fetchRoomTaskStats` | **keep → live** | gains its first consumer (RoomDetail card) |
| `UserStatsService` / `UserStatsMapper` / `UserStats` entity | **delete** | zero live callers (migration comments only) |

Pre-release rule applies (project-rules §1): no upgrade paths, fresh installs
only, dev DBs disposable.

## 3. Backend / Frontend Changes

### Backend (`lib/`, `appinfo/routes.php`)

- **Prune:** routes + controller actions + dedicated tests for
  `user#stats` (`UserController.php:500`), `room_task#userTasks`
  (`RoomTaskController.php:850`), `room_task#userStats`
  (`RoomTaskController.php:924`); delete `lib/Service/UserStatsService.php`,
  `lib/Db/UserStatsMapper.php`, `lib/Db/UserStats.php`.
- **Security fix:** `roomStats` resolves the room via `RoomMapper::find` and
  enforces the same house check as sibling endpoints
  (`validateRoomOwnership` or equivalent) → 404/403 for foreign rooms;
  regression test.
- **CR-SVC-31 root fix:** `getRoomTaskStats` / completionRate computed from
  real completions (distinct completed room tasks via `CompletedTaskMapper`)
  instead of `(total − active)/total`; empty set → `0`. Rename stays
  unnecessary once semantics are correct; close CR-SVC-31.
- **Freeze visibility data:** add `lastFreezeDate` (ISO-8601 or null) to
  `HouseUser::jsonSerialize()` (`lib/Db/HouseUser.php:123`).
- **New `GET /api/v1/house/activity`** (`house#activity`):
  - Params: `days` (default 30, max 90), `limit` (default 50, max 200),
    optional `userId` (house-user scoping, membership-validated).
  - `@NoAdminRequired` + `ensureHouseMember` (same pattern as
    `HouseController::stats`).
  - Response: `{ completions: [{ id, completedAt, wasCombo, reward fields
    from CompletedTask::jsonSerialize, houseUserId, displayName, taskId,
    taskTitle, energyCost, roomId, roomName }], total }`.
  - New `ActivityService` (business logic, batch enrichment via the
    CS-PLAN-036 IN-map idioms — no N+1) over a new house-scoped mapper method
    on `CompletedTaskMapper` (period + limit + order by `completed_at DESC`;
    `findCompletionsBetweenByHouse` as reference for house scoping).
    Enrichment must be null-safe: room deletion cleans completions
    (`RoomService` → `deleteByRoomTaskIds`) but single-task deletion does
    not, and there is no FK cascade — missing task/room references render a
    fallback label instead of erroring.

### Frontend (`src/`)

- **Stores:**
  - `store/house.js`: replace `fetchHouseStats` with `fetchInsights` (state
    `insights`) + new `fetchActivity(days, limit)` (state `activity`,
    `activityLoading/error`); delete `fetchUserTasks` /
    `fetchUserTaskStats` in `store/roomTasks.js` (with their spec blocks).
  - `store/scheduling.js`: getters `plannedEnergyForDay(dayKey)` (Σ
    `energyCost` of active room tasks with `nextDue` on that day, joined via
    tasks store energy map) and `overcapacityDays()` (planned >
    `getTotalEnergyForDay`, with the sorted would-be-deferred list, lowest
    priority first).
- **Views/components:**
  - `views/InsightsView.vue` (route `/insights`, lazy chunk, nav entry in
    `App.vue` between Leaderboard and Scheduling): sections
    `components/Insights/HouseStatsCards.vue` (rooms clean/dirty/needing
    cleaning, house health), `MemberFairnessTable.vue` (per member:
    contributionScore **and** coins as separate columns, streak, freeze
    count, active-freeze badge, completed energy vs weekly capacity with
    normalized bar), `ActivityFeed.vue` (who did what when, relative dates,
    combo flag).
  - `Leaderboard.vue`: freeze indicator (❄ count) inside/beside the existing
    `.streak-badge` (`Leaderboard.vue:103`); "frozen" styling when
    `lastFreezeDate` is yesterday.
  - `RoomDetail.vue`: compact stats card (total/active/overdue/due-today/
    completion rate) via the now-live `fetchRoomTaskStats`.
  - `components/Scheduling/OvercapacityWarning.vue` mounted in
    `SchedulingView.vue`; per-day warning marker in `ScheduleCalendar.vue`
    day cells.
- **Cross-layer contracts:** `HouseUser` JSON gains `lastFreezeDate`;
  `/house/stats` and room/user stats payloads unchanged in shape
  (completionRate value semantics corrected); new `/house/activity` contract
  documented in `docs/api/README.md`.

## 4. Tests

- **PHPUnit unit:**
  - `ActivityServiceTest`: enrichment correctness (batch-mapped titles/names),
    null-safe enrichment for dangling task/room references (deleted task),
    period/limit clamping, per-user filtering, empty house → empty list.
  - Stats services: completionRate counts real completions; deactivated/skip
    cases excluded; empty set → 0 (CR-SVC-31 regression).
  - `HouseUser` serialization includes `lastFreezeDate`.
  - Controller tests: `house#activity` param validation + membership gate;
    `roomStats` foreign-room rejection.
- **PHPUnit integration (Docker):** activity endpoint happy path + scoping
  (completions of other houses never appear); `roomStats` cross-house 403/404
  regression; pruned routes return 404; freeze consumption stamps
  `lastFreezeDate` visible in serialized users (reuse CS-PLAN-023
  time-travel/`trigger-jobs --as-of` harness).
- **Jest:** house store (`fetchInsights`/`fetchActivity` success + error);
  scheduling store (`plannedEnergyForDay`, `overcapacityDays` incl. weekend
  budgets 10/15 fallback); `OvercapacityWarning` (hidden under capacity,
  warning + deferred-first ordering above); `InsightsView` smoke (cards,
  table columns separated contribution/coins, feed rows, loading/error);
  `Leaderboard` freeze badge; `RoomDetail` stats card; deletion of removed
  wrapper specs.
- **Playwright (E2E):** one spec in the existing release profile: complete a
  task via test-data API → open `/insights` → house stats render, activity
  feed shows the completion with actor; overcapacity marker visible on a
  seeded overloaded day (family profile).

Build gate: `npm test` + `npm run lint:fix` + stylelint + PHP lint/cs:check +
l10n + PHPUnit unit/integration (Docker) + E2E profile green.

## 5. File Manifest

```
new:      lib/Service/ActivityService.php
new:      src/views/InsightsView.vue
new:      src/components/Insights/HouseStatsCards.vue
new:      src/components/Insights/MemberFairnessTable.vue
new:      src/components/Insights/ActivityFeed.vue
new:      src/components/Scheduling/OvercapacityWarning.vue
new:      tests/unit/Service/ActivityServiceTest.php
new:      tests/unit/store/{insights-related house.spec additions}
new:      tests/unit/{components}/OvercapacityWarning.spec.js
new:      tests/unit/{components}/InsightsView.spec.js
new:      tests/e2e/specs/insights-flow.spec.ts (existing suite/profile)
modified: appinfo/routes.php (−3 pruned routes, +house#activity)
modified: lib/Controller/HouseController.php (+activity)
modified: lib/Controller/UserController.php (−stats)
modified: lib/Controller/RoomTaskController.php (−userTasks, −userStats; roomStats auth)
modified: lib/Service/RoomTaskService.php (CR-SVC-31 fix)
modified: lib/Db/HouseUser.php (serialize lastFreezeDate)
modified: lib/Db/CompletedTaskMapper.php (house-scoped period reader)
deleted:  lib/Service/UserStatsService.php
deleted:  lib/Db/UserStatsMapper.php
deleted:  lib/Db/UserStats.php
modified: src/store/house.js (fetchInsights/fetchActivity; −fetchHouseStats)
modified: src/store/roomTasks.js (−fetchUserTasks, −fetchUserTaskStats)
modified: src/store/scheduling.js (planned-energy getters)
modified: src/router.js (+/insights)
modified: src/App.vue (nav entry)
modified: src/views/Leaderboard.vue (freeze badge)
modified: src/views/RoomDetail.vue (stats card)
modified: src/views/SchedulingView.vue (warning mount)
modified: src/components/Scheduling/ScheduleCalendar.vue (day markers)
modified: l10n/ artifacts (all new strings via t())
modified: docs/api/README.md (+/house/activity, corrected stats semantics, −pruned)
modified: docs/concepts/ui-use-cases.md (UC-02/UC-13 rows, Appendix A re-count,
          Whitespace built-tags, gap index, Last verified footer)
modified: docs/concepts/energy-and-balancing.md (fairness formula + warning surface)
modified: docs/backlog/backlog.md (items move to archive/ on completion)
modified: docs/backlog/ACTIVE_TASKS.md
archived: docs/backlog/archive/2026-08.md (FEAT-UX-016/017/020/022, CR-SVC-31)
```

## 6. Implementation Order

1. Register in `ACTIVE_TASKS.md` → *In Progress*; create branch.
2. **Phase A1 — prune:** grep-audit the three endpoints + `UserStats*` classes
   for consumers (CS-PLAN-025 precedent), then remove routes, actions, tests,
   classes, and the two dead wrappers.
3. **Phase A2 — fixes:** `roomStats` house validation (+ regression test);
   CR-SVC-31 real-completion completionRate (+ tests); serialize
   `lastFreezeDate` (+ test). Gate green.
4. **Phase B — activity endpoint:** mapper reader → `ActivityService` →
   `HouseController::activity` → route → `docs/api/README.md`. Unit +
   integration tests. Gate green.
5. **Phase C1 — data layer:** house store slices, scheduling getters, wrapper
   deletions mirrored in specs. Jest green.
6. **Phase C2 — InsightsView** + three section components + router + nav.
   Component/view specs green.
7. **Phase C3 — freeze badges** (Leaderboard + MemberFairnessTable) and
   **RoomDetail stats card**. Specs green.
8. **Phase D — overcapacity:** `OvercapacityWarning.vue` + calendar markers +
   store wiring. Specs green.
9. **Phase E — E2E spec** + full build gate (frontend + Docker backend).
10. **Phase F — knowledge integration:** update `ui-use-cases.md` (statuses,
    appendix route re-count after pruning, whitespace tags → built, gap index,
    footer), fairness formula into `energy-and-balancing.md`, move
    FEAT-UX-016/017/020/022 + CR-SVC-31 to `archive/2026-08.md`, append
    `## Review Outcome` here, `ACTIVE_TASKS.md` completion entry, recommend
    `active/ → done/`.

## 7. Risks & Mitigations

| Risk | Likelihood | Mitigation |
|------|-----------|------------|
| Scope creep across four features | medium | phases A–D ship independently; gate after each phase |
| Fairness metric misinterpreted (counts vs energy) | medium | formula (completed energy ÷ available capacity share) documented in energy-and-balancing.md; contribution and coins stay separate columns per the concept mandate |
| Pruning breaks an unknown consumer | low | grep audit before each deletion (CS-PLAN-025 precedent); pre-release no-BC rule; route ledger re-count keeps docs honest |
| `completionRate` semantic change surprises someone | low | only new UI consumes it; value change asserted in tests; api README updated |
| `lastFreezeDate` display shows stale "frozen" state | low | display rule: frozen badge only when `lastFreezeDate` == yesterday (compared on server-timezone-normalized dates per task-model-and-sync.md); covered by time-travel integration test |
| Multi-assignee blind spot in per-member data | low | history is completion-based (actual completer is stored), not assignment-based; limitation noted in api README |
| Overcapacity computation drifts from scheduler suggestion totals | medium | reuse suggestions `totalEnergy` for cross-check in dev; getter documented as the canonical planned-energy source going forward |
| l10n churn | low | all strings via `t()`; artifacts updated in the same change |

## 8. Acceptance Criteria

- [x] FEAT-UX-016: "who did what when" surface reachable from Dashboard
      (entry point) rendering completions with actor + timestamp; the
      expose-or-prune decisions of §2 are recorded and implemented.
      *(Shipped as `/insights` (nav entry, UC-16) with `GET /house/activity`
      + `ActivityFeed`; decisions executed — 3 routes pruned, `/house/stats`
      and room-stats exposed.)*
- [x] FEAT-UX-017: Insights view renders `GET /house/stats`; CR-SVC-31 closed
      (completionRate from real completions, empty set → 0); fairness load
      normalized by weekday capacity/energy budget; contribution and coins
      shown separately. *(MemberFairnessTable; formula documented in
      energy-and-balancing.md.)*
- [x] FEAT-UX-020: owned freeze count + active-freeze state visible near the
      streak display (Leaderboard + member table); freeze-saves-streak
      feedback verified (existing toast) with test coverage. *(Freeze-saves
      toast was pre-existing; consumption stamping covered by the new
      integration test.)*
- [x] FEAT-UX-022: verified missing → warning implemented per the locked
      policy (explains which tasks would be deferred, lowest priority first);
      `ui-use-cases.md` UC-10/whitespace updated accordingly.
- [x] Security: `roomStats` rejects foreign-house room IDs (regression test).
- [x] Pruned endpoints/wrappers/classes removed; route coverage ledger in
      `ui-use-cases.md` re-counted and consistent. *(Ledger recounted:
      111 → 109 routes; beyond-manifest orphan removals:
      `getUpcomingTasksForUser`, `RoomTaskMapper::findByUserId`.)*
- [x] Build gate green: `npm test` + lint/stylelint + l10n + PHPUnit
      unit/integration (Docker) + E2E spec. *(PHPUnit unit 795 /
      integration 179 [24 pre-existing skips]; Jest 737; lint + stylelint
      0 errors; l10n clean; E2E insights-flow 3/3 + regression suites.)*
- [x] Durable outcomes integrated: `docs/api/README.md`, fairness formula in
      `docs/concepts/energy-and-balancing.md`, `ui-use-cases.md` refresh.
- [x] Backlog items moved to `docs/backlog/archive/2026-08.md`;
      `ACTIVE_TASKS.md` updated.

## 9. Implementation Outcome

**Implemented:** 2026-08-21 · **Branch:** `feat/cs-plan-040-transparency-insights`

Deviations from the manifest (all root-cause/KISS-driven, none behavioral):

1. **Beyond-manifest dead-code removal:** pruning `getUserTasks` left
   `RoomTaskService::getUpcomingTasksForUser` (its only consumer, itself
   route-less) and `RoomTaskMapper::findByUserId` orphaned — both deleted per
   project-rules §1 (no dead code pre-release).
2. **Pinia latent defect fixed en route:** option-store getters are called
   with the store only, so a second `(state, getters)` parameter is always
   `undefined`. The new `overcapacityDays` getter uses `this`, and the
   previously broken-by-construction `averageDailyEnergy` getter was migrated
   to `this` as well.
3. **Activity house-scoping via membership join:** the activity reader joins
   `chorestrike_house_users` instead of the rooms→floors chain so completions
   of later-deleted room tasks still appear (null-safe fallback labels);
   strict cross-house isolation is proven at mapper level
   (`HouseActivityIntegrationTest`), controller-level behavior on the shared
   instance house follows the WireStatusAuthDenialTest pattern.
4. **`getCapacityForDay` added** alongside the plan's getters: owners see
   member totals (existing `getTotalEnergyForDay` semantics), non-owners fall
   back to their own preferences with the locked 10/15 weekend budgets —
   otherwise every non-owner day would read capacity 0.

Test additions beyond the plan: `RouteMatrixTest::testPrunedStatsRoutesAreNotReintroduced`,
`HouseControllerTest` activity cases, `HouseActivityIntegrationTest`
(happy path, per-user filter, mapper scoping, roomStats cross-house,
freeze-stamp serialization), CR-SVC-31 regression pair in
`RoomTaskServiceTest`, `lastFreezeDate` entity tests, Jest suites for
InsightsView / OvercapacityWarning / scheduling getters / house store,
Leaderboard freeze-badge specs, RoomDetail stats-card spec.

## Review Outcome

**Standard-B review** (2026-08-21, uncommitted CS-PLAN-040 tree, ~40 files):
shared checklist clean on architecture (thin controllers → `ActivityService` →
mappers, batch IN-map enrichment, `createNamedParameter()` throughout),
authorization (`@NoAdminRequired` + `ensureHouseMember`; `roomStats` now
validates room-to-house ownership before reading stats; activity `userId`
filter membership-validated server-side), security (no `v-html`, no secrets
logged, curated error codes), logging (`AppLogger`/`logger.js` only), i18n,
and test scope (unit + integration regression pairs for the security fix and
CR-SVC-31; prune-guard tests in `RouteMatrixTest`/`WireStatusAuthDenialTest`).

**Auto-fixes applied (5):**

1. `lib/Db/CompletedTaskMapper.php` — corrupted docblock: the new
   `countDistinctCompletedRoomTasksByRoom` docblock had been inserted *inside*
   the existing `findCompletionsBetweenByHouse` docblock, orphaning its header
   and leaving the new method undocumented; structure restored.
2. `lib/Controller/HouseController.php` — FQN `\OCA\ChoreStrike\Exception\NotFoundException`
   in the `activity()` catch → proper `use` import (coding standard).
3. `l10n/en.json` + `l10n/de.json` — missing key
   `"No activity in this period yet."` (`ActivityFeed.vue` empty state) added.
4. `tests/unit/Controller/RoomTaskControllerTest.php` — orphaned docblock of
   the pruned `userTasks` cross-house test removed.
5. `lib/Service/ActivityService.php` — `composer cs:fix` PHPDoc alignment
   (cs:check was red on the new file).

**Deferred:** weekend fallback energy budget map now duplicated in 3 sites →
**TECH-DRY-001** in `backlog.md`. Report-only nits (no backlog): activity
query orders by `completed_at` only (tie order nondeterministic),
`plannedEnergyForDay`/`overcapacityDays` each walk `roomTasks` independently,
RoomDetail stats fetch shares the init try/catch (failure logs
"Failed to initialize room" though the view degrades gracefully).

**Gate re-verified green after fixes:** `npm run lint:fix` + `npm test`
(Jest 737) + stylelint + `l10n:check-keys` clean; PHPUnit unit 795 /
integration 179 [24 pre-existing skips] in Docker; `composer cs:check` 0/263;
`php -l` clean on edited files. E2E not re-run by the review (plan §8 records
insights-flow 3/3 + regression suites green).
