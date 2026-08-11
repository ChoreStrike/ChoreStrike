# CS-PLAN-025: `lib/Service` Security, Concurrency & Dead-Code Cleanup

> **Status:** Ready for `active/ → done/` (2026-08-10) — all 4 phases landed, the
> post-implementation review's reopened **[P0] CR-SVC-04** and follow-ups
> **[P1] CR-SVC-45/46/47** are resolved with regression tests, and the full gate is
> green again. See `## Review Outcome` and `## Reopened Items — Resolution (2026-08-10)`.
> **Effort:** L
> **Priority:** P1
> **Depends on:** none hard (CR-SVC-03 tests reuse the CS-PLAN-023 time-travel harness, landed 2026-08-04)
> **Relates to:** CS-PLAN-022 (Phase 2 deletes coverage tests its Done phases added for the dead classes)
> **Source:** Full Review of `lib/Service` → [`docs/backlog/archive/CODE_REVIEW_2026-08-04.md`](../../backlog/archive/CODE_REVIEW_2026-08-04.md)

## 1. Problem Summary

The 2026-08-04 Full Review of `lib/Service` found **2 P0 (block)** authorization /
data-safety defects, **6 P1** correctness/concurrency defects, and a **dead-code
cluster** of ~11 unreachable files (the smart-scheduler chain, whose routes were removed
for a security gap in CS-016, plus 3 orphan services). This plan lands the P0/P1 fixes
and deletes the dead cluster (decision confirmed by the user: "der dead cluster kann weg").

The module is otherwise architecturally sound (no circular deps, consistent thin-controller
layering); the P2/P3 refactors (God-method splits, N+1 batching, logger migration) plus the
[P2]-triaged security items **CR-SVC-09** (`assignTask`/`bulkAssignTasks` internal guards)
and **CR-SVC-10** (`completeRoomTask` membership re-check) are **out of scope** here and
remain as CR-SVC-* backlog items. Note: the review record's recommendation listed
CR-SVC-09/10 in this package; `backlog.md` triaged them to [P2] — re-scope into Phase 4
if the user re-prioritizes.

Findings covered (all from the review record):

- **P0** CR-SVC-01 — `RoomTaskService::createRoomTask` cross-house IDOR.
- **P0** CR-SVC-02 — `TestDataService` destructive ops have no internal `isTestMode()` self-gate.
- **P1** CR-SVC-03 — `StreakFreezeService::process` is not idempotent (data loss on re-run).
- **P1** CR-SVC-04 — lost-update race on `house_user` coins/streak under concurrent completion.
- **P1** CR-SVC-05 — `MinigameService::saveScore` unbounded client score (coin farming).
- **P1** CR-SVC-06 — `requireHouseOwner`/`isHouseOwner` auto-create a membership on a miss.
- **P1** CR-SVC-07 — `TaskSyncService` divergent, reward-less, lock-less completion path.
- **P1** CR-SVC-12 — `completeRoomTask` holds the DB transaction across a notification dispatch.
- **P2(decision)** CR-SVC-08 — delete the unreachable scheduler dead cluster + 3 orphan services.

## 2. Strategy

Four independently-validatable, independently-committable phases, ordered by risk/value.
Each phase ends green (lint + `npm test` + PHPUnit in Docker) before the next begins.

- **Phase 1 — P0 Authorization & Data-Safety** (CR-SVC-01, CR-SVC-02): close the IDOR and
  the prod-wipe footgun first; smallest, highest-value changes.
- **Phase 2 — Dead-Code Cleanup** (CR-SVC-08): delete the cluster early so later phases
  don't touch code that is about to disappear, and so the tree shrinks before refactors.
- **Phase 3 — P1 Concurrency** (CR-SVC-04, CR-SVC-12, CR-SVC-03): locking, transaction
  scope, and job idempotency — the data-integrity core.
- **Phase 4 — P1 Security Hardening** (CR-SVC-05, CR-SVC-06, CR-SVC-07): input bounds,
  the owner-check side effect, and collapsing the duplicate completion path.

Architectural principle: authorization is enforced **inside the service** (or via the
existing `AuthorizationService` primitives) so no caller can forget the preamble — the
review showed controller-only gating is exactly what produced CR-SVC-01.

## 3. Backend / Frontend Changes

All changes are backend (`lib/`, `tests/`). No frontend changes; no payload contract
changes for live endpoints.

### Phase 1 — P0

**CR-SVC-01 — `RoomTaskService::createRoomTask` IDOR**
- Change `createRoomTask(...)` to accept the caller's `int $houseId` and validate **inside
  the service**: resolve the target room's house via the floor chain
  (`room → floor → house`) and reject (`NotFoundException`) if it ≠ `$houseId`; if
  `$assignedToUserId` is non-null, assert it is a member of `$houseId`
  (`houseUserMapper->findByHouseAndUser(...) !== null`, else `InvalidArgumentException`).
- Update `RoomTaskController::create` to pass `$house->getId()`; remove reliance on the
  controller being the sole gate. Reuse `FloorMapper`/`RoomMapper` already injected in
  `RoomTaskService`.
- Audit all `createRoomTask` callers before implementation (grep as of 2026-08-09 shows
  `RoomTaskController::create` is the only live caller; the inventory's
  `TaskController`/`ChecklistController` mention was not confirmed) and pass the house id.

**CR-SVC-02 — `TestDataService` self-gate**
- Add `private function assertTestMode(): void` that throws unless `$this->isTestMode()`.
  Call it as the first line of `reset()`, `seedEmpty()`, `seedMinimal()`, `seedFamily()`,
  `seed()`.

### Phase 2 — Dead-Code Cleanup (CR-SVC-08)

Delete (verified dead: every consumer is inside the cluster or its own test):

- `lib/Controller/SmartSchedulerController.php`
- `lib/Service/SmartSchedulerService.php`, `lib/Service/TaskSchedulerService.php`
- `lib/Service/Scheduling/` (all 5: `AvailabilityCalculator`, `ScheduleOptimizer`,
  `TaskAssignmentAlgorithm`, `TaskPriorityScorer`, `TaskReassignmentSuggester`)
- `lib/Service/ComboService.php`, `lib/Service/HouseUserService.php`,
  `lib/Service/UserStatsService.php`
- `lib/Db/ComboMapper.php` (+ `Combo` entity if unreferenced),
  `lib/Db/UserStatsMapper.php` (+ `UserStats` entity if unreferenced)
- Their tests: `SmartSchedulerControllerTest`, `SmartSchedulerControllerAnnotationsTest`,
  `SmartSchedulerServiceTest`, `TaskSchedulerServiceTest`, the 5 `Scheduling/*Test`,
  `ComboServiceTest`, `ComboMapperTest`, `HouseUserServiceTest`, `UserStatsServiceTest`,
  `UserStatsMapperTest`.
- Tables `chorestrike_combos` + `chorestrike_user_stats`: remove their creation from the
  existing migration chain (pre-release — fresh installs only, project-rules §1; **no**
  drop/upgrade migration) and drop them from `TestDataService::RESET_TABLES` (also
  resolves CR-SVC-42 legacy-alias cleanup for these tables).
- Clean the stale comments at `appinfo/routes.php:114-120` and re-anchor the dead
  `HouseUserService::removeUserFromHouse` reference in the `RoomService.php:418` comment
  to the actual cleanup location (CR-SVC-26, tracked separately).
- Doc updates: `docs/standards/ai/skills/gamification/SKILL.md` (drop `ComboService`
  component mention), `docs/adr/ADR-005-smart-scheduling.md` (mark superseded/archived),
  `docs/release/roadmap.md` (`TaskReassignmentSuggester` checkbox).

> The live `SchedulingController`/`SchedulingService` depend on **none** of the deleted
> classes (verified: `SchedulingService` injects only `HouseUserMapper` +
> `CompletedTaskMapper`). `GameLogicService` inlines its own combo logic and does **not**
> depend on `ComboService`.

### Phase 3 — P1 Concurrency

**CR-SVC-04 — `house_user` lost-update race**
- Inside the existing `completeRoomTask` transaction, lock the `house_user` row before
  `GameLogicService::handleTaskCompletion` reads/mutates coins/streak. Add
  `HouseUserMapper::findForUpdate(int $houseId, string $userId): ?HouseUser`
  (`SELECT … FOR UPDATE`) and call it at the top of `handleTaskCompletion` (it already
  runs inside the parent transaction/`lockForUpdate`).

**CR-SVC-12 — transaction held across notification dispatch**
- `GameLogicService::handleTaskCompletion` currently calls
  `NotificationService::notifyTitleUnlocked` *inside* the lock. Change it to **return**
  the title-unlock payload(s) instead of dispatching, and have `RoomService::completeRoomTask`
  dispatch `notifyTitleUnlocked` **after** `commit()` — mirroring how the takeover and
  weekly-goal notifications are already deferred.

**CR-SVC-03 — `StreakFreezeService::process` idempotency**
- Add a per-user last-processed-day guard so a re-run for the same `$yesterday` is a no-op.
  Preferred: a `last_freeze_date` column on `chorestrike_house_users` (migration edit,
  fresh-install only). `process()` skips a user when `last_freeze_date === $yesterday`; sets it after
  consuming/resetting. (If a new column is undesirable, fall back to a
  `frozen_for_<date>` marker row; decide in implementation.)

### Phase 4 — P1 Security Hardening

**CR-SVC-05 — `MinigameService::saveScore` score bound**
- Validate `$score >= 0` and `<= MinigameService::MAX_SCORE` (new constant, sane per-game
  ceiling) before insert and before the `floor($score/10)` coin award; reject otherwise
  (`InvalidArgumentException`).

**CR-SVC-06 — `requireHouseOwner`/`isHouseOwner` must not mutate**
- Switch both from `ensureHouseMember` (mutates) to the read-only `assertHouseMember`
  (`AuthorizationService.php:192,206`). Audit every caller: confirm membership is ensured
  upstream (`getOrCreateHouse` + `ensureHouseMember`/`assertHouseMember`) so the change is
  behavior-neutral on the happy path and only removes the phantom-row side effect.

**CR-SVC-07 — collapse the `TaskSyncService` divergent completion path**
- Route CalDAV-sync completions through the canonical `RoomService::completeRoomTask`
  (or a shared private completion core extracted from it) so rewards, locking, the daily
  duplicate guard, cleanliness update, and recurrence (`RoomTask::markCompleted`) all apply
  uniformly. Delete `TaskSyncService::calculateNextDueDateFromTask`. Keep the
  `*ForHouse` wrappers as the only public surface (also advances CR-SVC-21).
- This is the highest-risk phase; implement last, behind the Phase-1/2/3 green gate.

## 4. Tests

Every phase adds/updates regression tests (project-rules §2: no completion without tests).

- **Unit (PHPUnit):**
  - CR-SVC-01: `RoomTaskServiceTest` — create rejects a room from another house; rejects a
    non-member assignee; accepts valid inputs. Controller test asserts the house id is passed.
  - CR-SVC-02: `TestDataServiceTest` — every destructive method throws when `isTestMode()` is false.
  - CR-SVC-04: `GameLogicServiceTest` — asserts `houseUserMapper->findForUpdate` is invoked
    inside the completion path.
  - CR-SVC-05: `MinigameServiceTest` — negative and over-cap scores rejected; no coins awarded.
  - CR-SVC-06: `AuthorizationServiceExceptionTest`/new — a denied `requireHouseOwner` leaves no
    `house_users` row; happy path unchanged.
  - CR-SVC-12: `RoomServiceTest` — `notifyTitleUnlocked` is called after `commit`, not inside
    the transaction.
  - CR-SVC-07: `TaskSyncServiceTest` — a sync completion delegates to the canonical path and
    awards rewards/advances recurrence identically to a normal completion.
- **Integration (PHPUnit, Docker, real DB):**
  - CR-SVC-03: using the CS-PLAN-023 time-travel harness, re-run `StreakFreezeService::process`
    for the same `$yesterday` twice → no double freeze consumption / no reset of a preserved streak.
  - CR-SVC-04: two concurrent same-user completions → coins/streak accumulate correctly (no lost update).
  - Delete-after: the full PHPUnit suite stays green after Phase 2 (no dangling references).
- **E2E (Playwright):** not required for these backend-internal fixes; the existing task-completion
  / daily-flow specs guard the user-visible behavior.

## 5. File Manifest

```
# Phase 1
modified: lib/Service/RoomTaskService.php          (createRoomTask house-scoping + assignee check)
modified: lib/Controller/RoomTaskController.php     (pass houseId)
modified: lib/Service/TestDataService.php           (assertTestMode self-gate)
modified: tests/unit/Service/RoomTaskServiceTest.php
modified: tests/unit/Service/TestDataServiceTest.php
modified: tests/unit/Controller/RoomTaskControllerTest.php

# Phase 2 (deletions)
deleted:  lib/Controller/SmartSchedulerController.php
deleted:  lib/Service/SmartSchedulerService.php
deleted:  lib/Service/TaskSchedulerService.php
deleted:  lib/Service/Scheduling/AvailabilityCalculator.php
deleted:  lib/Service/Scheduling/ScheduleOptimizer.php
deleted:  lib/Service/Scheduling/TaskAssignmentAlgorithm.php
deleted:  lib/Service/Scheduling/TaskPriorityScorer.php
deleted:  lib/Service/Scheduling/TaskReassignmentSuggester.php
deleted:  lib/Service/ComboService.php
deleted:  lib/Service/HouseUserService.php
deleted:  lib/Service/UserStatsService.php
deleted:  lib/Db/ComboMapper.php (+ Combo entity if unused)
deleted:  lib/Db/UserStatsMapper.php (+ UserStats entity if unused)
deleted:  tests/unit/Controller/SmartSchedulerController{,Annotations}Test.php
deleted:  tests/unit/Service/{SmartSchedulerService,TaskSchedulerService,ComboService,HouseUserService,UserStatsService}Test.php
deleted:  tests/unit/Service/Scheduling/*Test.php
deleted:  tests/unit/Db/{ComboMapper,UserStatsMapper}Test.php
modified: lib/Service/TestDataService.php           (RESET_TABLES: drop combos/user_stats + legacy aliases → also CR-SVC-42)
modified: lib/Service/RoomService.php               (re-anchor :418 comment)
modified: appinfo/routes.php                        (clean stale SmartScheduler comments)
modified: lib/Migration/Version1010Date20260301210000.php  (remove chorestrike_user_stats creation)
modified: lib/Migration/Version1012Date20260301230000.php  (remove chorestrike_combos creation)
modified: lib/Migration/Version1015Date20260304000000.php  (remove chorestrike_user_stats index block)
modified: docs/standards/ai/skills/gamification/SKILL.md
modified: docs/adr/ADR-005-smart-scheduling.md
modified: docs/release/roadmap.md

# Phase 3
modified: lib/Db/HouseUserMapper.php                (findForUpdate)
modified: lib/Service/GameLogicService.php          (return title payload instead of dispatching)
modified: lib/Service/RoomService.php               (dispatch title notif after commit)
modified: lib/Service/StreakFreezeService.php       (idempotency guard)
modified: lib/Db/HouseUser.php + house_users create-migration  (last_freeze_date on chorestrike_house_users, fresh-install edit)
modified: tests/unit/Service/{GameLogic,RoomService}Test.php
modified: tests/unit/Service/StreakFreezeServiceTest.php
new:      tests/integration/... (idempotency + concurrency)

# Phase 4
modified: lib/Service/MinigameService.php           (score bound)
modified: lib/Service/AuthorizationService.php      (assertHouseMember in owner checks)
modified: lib/Service/TaskSyncService.php           (route through canonical completion; drop divergent recurrence)
modified: tests/unit/Service/{Minigame,AuthorizationService*,TaskSync}Test.php
```

> Phase 2 deletions are confirmed via grep audit (step 3 / Phase 2.1). If the audit finds an
> unexpected live consumer, that file is **kept** and the finding is re-logged rather
> than force-deleted.

## 6. Implementation Order

1. **Phase 1.1** CR-SVC-02 (TestDataService self-gate) — trivial, unblocks nothing.
2. **Phase 1.2** CR-SVC-01 (createRoomTask IDOR) — service + controller + callers + tests.
   → commit `fix(security): scope createRoomTask to the caller's house + validate assignee`.
3. **Phase 2.1** Grep audit: confirm zero live consumers of every deletion target.
4. **Phase 2.2** Delete the cluster + mappers/entities/tables/migrations + tests; clean
   comments; update docs. Run full PHPUnit suite.
   → commit `refactor: remove unreachable smart-scheduler cluster + orphan services`.
5. **Phase 3.1** CR-SVC-04 (`house_user` `findForUpdate`) + CR-SVC-12 (defer title notif).
   → commit `fix(concurrency): lock house_user + defer title notification past commit`.
6. **Phase 3.2** CR-SVC-03 (StreakFreeze idempotency) + integration tests.
   → commit `fix(jobs): make StreakFreezeService idempotent per day`.
7. **Phase 4.1** CR-SVC-05 (score bound) + CR-SVC-06 (owner-check no-mutate).
   → commit `fix(security): bound minigame scores + stop owner checks from creating memberships`.
8. **Phase 4.2** CR-SVC-07 (collapse TaskSync completion path) — highest risk, last.
   → commit `refactor(sync): route CalDAV completions through the canonical completion core`.
9. Final full gate (`npm test` + lint + full PHPUnit suite in Docker); update
   `ACTIVE_TASKS.md` + move resolved CR-SVC-* items from `backlog.md` to `archive/2026-08.md`.

## 7. Risks & Mitigations

| Risk | Likelihood | Mitigation |
|------|-----------|------------|
| A live consumer of a "dead" class is missed → broken boot/500. | Low | Step 3 (Phase 2.1) grep audit before deletion; full PHPUnit suite + a smoke `occ` run after Phase 2. |
| Removing `chorestrike_combos`/`chorestrike_user_stats` tables breaks a seed/migration assumption. | Low | Fresh-install-only edit (project-rules §1); `down -v && up -d` reproves a clean install; dev DBs disposable. |
| `requireHouseOwner` → `assertHouseMember` changes a controller that relied on auto-join. | Low | Audit all callers (step 7); the happy path already ensures membership upstream; regression test for the side-effect removal. |
| CR-SVC-07 refactor changes completion semantics subtly (rewards/recurrence drift). | Medium | Implement last, behind green gate; reuse `RoomTask::markCompleted` verbatim; add an integration test asserting a sync completion == a normal completion's reward/recurrence. |
| Concurrency fixes (CR-SVC-04/03) hard to prove at unit level. | Medium | Real-DB integration tests in Docker (CS-PLAN-023 time-travel harness for idempotency; two-connection concurrent completion for the race). |
| Holding the `house_user` lock adds lock contention on the hot completion path. | Low | Row-level lock scoped to one user; short critical section (no external calls inside — CR-SVC-12 already removes the notification from the lock). |
| Phase 2 deletes tests CS-PLAN-022 (Done) recently added → its recorded coverage baseline shifts. | Low | Deletion list already includes every CS-022-added test for the dead classes (grep-verified 2026-08-09); note the baseline shift in the Phase 2 commit + `ACTIVE_TASKS.md` entry. |

## 8. Acceptance Criteria

- [x] CR-SVC-01: a member cannot create a room task in another house's room, nor stamp a
      non-member as assignee (unit + controller test).
- [x] CR-SVC-02: `TestDataService` destructive methods throw unless `isTestMode()` (test).
- [x] CR-SVC-08: the scheduler cluster + 3 orphan services + their tables/mappers/tests are
      gone; no dangling references; full PHPUnit suite green.
- [x] CR-SVC-04: `house_user` coins/streak are locked during completion
      (`HouseUserMapper::findForUpdate`; real-DB integration test via the canonical
      completion path). *Reopened 2026-08-09 (stale-write lock + missing test),
      **resolved 2026-08-10**: the lock is now a true `SELECT … FOR UPDATE` on
      locking engines (SQLite: no-op self-assigning UPDATE + fresh re-read) — no
      pre-read data is ever written back. The real-DB concurrency test
      (`tests/integration/Service/ConcurrentCompletionTest.php`) reproduces the
      clobber against the old implementation (verified failing) and passes with
      the fix.*
- [x] CR-SVC-12: `notifyTitleUnlocked` dispatches after `commit`, never inside the
      transaction (test).
- [x] CR-SVC-03: `StreakFreezeService::process` is idempotent per day (integration test).
- [x] CR-SVC-05: out-of-range minigame scores are rejected; no coins awarded (test).
- [x] CR-SVC-06: a denied `requireHouseOwner` creates no `house_users` row (test).
- [x] CR-SVC-07: CalDAV-sync completions produce identical rewards/recurrence to normal
      completions; divergent recurrence helper deleted (integration test).
- [x] Build gate green: `npm run lint` + `npm test` + full PHPUnit suite in Docker
      (unit 690 / integration 150 [24 pre-existing skips] / Jest 677 / lint clean —
      re-run 2026-08-10 after the reopened items landed).
- [x] Resolved CR-SVC-* items moved from `backlog.md` to `archive/2026-08.md`;
      `docs/backlog/ACTIVE_TASKS.md` updated.
- [x] `gamification` SKILL / ADR-005 / roadmap updated to reflect the deletions.

> **Follow-up logged:** ~~CR-SVC-45 (`CompletedTask.completed_by_user_id` unmapped → SELECT
> throws)~~ — **resolved 2026-08-10** with the reopened items (dead columns dropped from
> the Version1011 migration). CR-SVC-21 (TaskSync `*ForHouse`-only
> public surface) only partially advanced — the floor-chain house-scoping bug was fixed,
> but the base mutator methods remain public.

## Review Outcome

**2026-08-09 — Standard→Full implementation review (4 parallel `reviewer` subagents:
correctness / architecture / security-auth / perf-quality), commit range `48e5f15..1b4e680`.**

Verified clean: CR-SVC-01 (IDOR fix airtight — service-internal floor-chain check +
assignee membership, single live caller passes the caller's house), CR-SVC-02
(`assertTestMode` first line of all five destructive methods, server-side env gate),
CR-SVC-05 (both bounds enforced before insert and coin award), CR-SVC-12 (title
payload returned from `GameLogicService`, dispatched only after `commit()` in
`RoomService`), CR-SVC-03 (guard stamps on both consume and reset paths; Y-m-d key
is timezone-stable), CR-SVC-07 canonical direction (delegates to
`RoomService::completeRoomTask`; divergent recurrence helper deleted), Phase-2
deletions (no dangling references in `lib/`, `tests/`, `appinfo/`, migrations, or
`RESET_TABLES`).

**Reopened / blocked (never auto-fixed — security & data-integrity):**

- **[P0] CR-SVC-04 (reopened)** — `HouseUserMapper::findForUpdate` acquires the lock by
  UPDATE-ing `contribution_score` back to the value read **before** the lock was held
  (lib/Db/HouseUserMapper.php:58-69). A concurrent same-user completion of a different
  task commits while this transaction waits; the stale UPDATE then clobbers the committed
  increment and the post-lock re-read builds on the clobbered row (on MySQL REPEATABLE
  READ the plain re-read can be snapshot-stale too, so coins/streak are also at risk).
  Fix: acquire the lock without writing pre-read data (self-assigning `SET id = :id`
  with a row-count existence check, or a true `SELECT … FOR UPDATE`), then re-read.
  The promised two-connection concurrency integration test (plan §4, acceptance line
  "CR-SVC-04 … real-DB integration test") was never added and must land with the fix.
- **[P1] CR-SVC-46** — CR-SVC-07 refactor regression: completions via
  `TaskSyncService::completeTask` no longer stamp `completed_tasks.nextcloud_task_id`,
  and `findPendingSyncs` filters on exactly that column → sync-out never sees them.
- **[P1] CR-SVC-47** — CR-SVC-06 audit gap: five owner-only endpoints
  (HouseController:95,202; SchedulingController:192,225; UserController:174) run
  `getOrCreateHouse` (no membership side effect) then `requireHouseOwner` — a first-time
  user previously auto-bootstrapped to owner, now gets 403. Fails closed but breaks the
  fresh-install bootstrap when one of these is the first endpoint reached.

**Auto-fixed in this review (minor, gate re-run):**

- `MinigameController::saveScore` + `RoomTaskController::create`: the dedicated
  `InvalidArgumentException` 400 catches routed through `safeMessage()`, which
  intentionally excludes that exception type — the curated validation messages never
  reached the client. Now returned verbatim (service-curated, safe). Remaining
  instances of the pattern → CR-SVC-49.
- `StreakFreezeService::process`: moved the `last_freeze_date` guard above
  `countByUserOnDate` so a same-day re-run skips the per-user query.
- `TaskSyncService`: removed dead `use Room` import left by the CR-SVC-07 refactor.
- PHPDoc: `GameLogicService::handleTaskCompletion` `@return array{…}` contract
  (incl. `newTitle`), `MinigameService::saveScore` `@return`/`@throws`,
  `AuthorizationService::isHouseOwner` `@param`/`@return`.
- Docs: `docs/engineering/architecture.md` (deleted classes/tables removed from the
  tree + Supporting Tables), `docs/api/phase5-smart-scheduling.md` (cluster deleted,
  no longer "pending archival"), backlog hygiene (CR-016-07 + CR-SVC-39 retired to
  `archive/2026-08.md`; deleted class names dropped from CR-SVC-11/44).

**Logged only (→ backlog.md, Source: Review of CS-PLAN-025):** CR-SVC-46, CR-SVC-47,
CR-SVC-48 (reverse-sync `completedAt` validation + undocumented cleanliness deviation),
CR-SVC-49 (`safeMessage` pattern at 6 remaining catches), CR-SVC-50 (extra queries
inside the house_user lock), CR-SVC-51 (controller recomputes the coin formula),
CR-SVC-52 (CR-SVC-12 test asserts dispatch but not after-commit ordering).

**Rejected subagent findings (verified non-issues):** whitespace-only assignee does
**not** bypass the membership check (fails closed as a non-member → 400);
`TestDataServiceTest` **does** restore the env in `tearDown()`.

## Reopened Items — Resolution (2026-08-10)

All four reopened/follow-up items landed with regression tests; full gate green
(unit 690 / integration 150 [24 pre-existing skips] / Jest 677 / lint clean).

- **[P0] CR-SVC-04 (reopened)** — `HouseUserMapper::findForUpdate`
  (lib/Db/HouseUserMapper.php) no longer writes pre-read data back:
  - MySQL/MariaDB/PostgreSQL: a true locking read (`SELECT … FOR UPDATE` via a
    prepared raw statement) acquires the exclusive row lock **and** returns the
    freshest committed row in one statement. A no-op UPDATE + plain re-read is
    insufficient: Nextcloud sessions run at READ COMMITTED
    (`SetTransactionIsolationLevel`), and the stale-write clobber manifests when
    the concurrent winner commits between the lock's inner pre-read and its lock
    write.
  - SQLite (no FOR UPDATE): a no-op **self-assigning** UPDATE
    (`SET contribution_score = contribution_score` — never a pre-read value)
    acquires the database-wide write lock, then a fresh re-read.
  - `RoomTaskMapper::lockForUpdate` hardened with the same self-assigning
    pattern (same defect class: it wrote the pre-lock `is_active` back).
  - The promised real-DB concurrency integration test landed
    (`tests/integration/Service/ConcurrentCompletionTest.php`): a second PHP
    process locks the `house_user` row, holds the lock, and commits a winner
    increment (+5/+10) while the test's `findForUpdate` blocks — asserting the
    lock builds on the winner state and both increments land (no lost update).
    **Verified the test fails against the old stale-write implementation**
    (clobber reproduced: asserted 5, got 0) and passes with the fix. A second
    test covers sequential same-user completions accumulating both rewards.
- **[P1] CR-SVC-45** (blocker for the concurrency test) — the dead
  `completed_by_user_id` / `points_earned` / `bonus_points` columns + their two
  indexes were **removed from the Version1011 migration** (fresh-install chain
  edit, project-rules §1; no code referenced them). Every `SELECT *` entity
  mapping on `completed_tasks` threw before; the new sync + sequential
  completion integration tests map full completion rows on the real DB.
- **[P1] CR-SVC-46** — `TaskSyncService::completeTask` threads the
  `nextcloudTaskId` through `RoomService::completeRoomTask` →
  `GameLogicService::handleTaskCompletion`, which stamps it on the completion
  row inside the canonical transaction (optional parameter; non-sync completions
  stay un-stamped). `findPendingSyncs` sees sync completions again — integration
  test asserts the completion appears in `getPendingSyncsForHouse` with the id.
- **[P1] CR-SVC-47** — decision: **restore the old bootstrap** (backlog
  option 1). The five `getOrCreateHouse` + `requireHouseOwner` sites
  (HouseController update/reset, SchedulingController get/updateMemberAvailability,
  UserController invite) now call `ensureHouseMember()` **before** the owner
  check: the first user on a fresh instance becomes owner (bootstrap works
  whichever endpoint is reached first), consistent with the app-wide auto-join
  model; `requireHouseOwner` itself remains read-only (CR-SVC-06 intact).
  Regression tests: real-DB empty-house bootstrap to owner
  (AuthorizationIntegrationTest) + controller call-order tests
  (HouseControllerIntegrationTest update/reset).

## Full Review (2026-08-11)

**Tier:** Full (4 parallel `reviewer` subagents: correctness / architecture /
security-auth / perf-quality) of all 4 active plans (CS-017/022/023/025), with the
focus on the uncommitted CS-025 reopened-items changeset. Record:
[`docs/backlog/CODE_REVIEW_2026-08-11.md`](../../backlog/CODE_REVIEW_2026-08-11.md).

**Gate re-verified green on the uncommitted tree (+ 1 auto-fix):**
PHPUnit unit **690/690** (1842 assertions) · integration **150/150** (24 pre-existing
skips) · Jest **677/677** · `ConcurrentCompletionTest` **2/2** (incl. the 2.1 s
real-lock "Find for update builds on committed winner state" proof) · ESLint clean.
E2E (Playwright) was **not** re-run this turn; plan §10 records 160 passed at
implementation time (4 baseline failures proven pre-existing via stash round-trip).

**Verified clean (no action):** CR-SVC-01 IDOR (service-internal floor-chain +
assignee membership, single live caller passes caller houseId), CR-SVC-02 test-mode
gate (all 5 destructive methods, strict `NEXTCLOUD_TEST_MODE === 'true'`, no CI
fallback), CR-SVC-05 score bound (both bounds before insert + coin award),
CR-SVC-06 owner-check read-only (`assertHouseMember`, no row created), CR-SVC-03
idempotency (guard before the per-user query, stamps both paths, TZ-stable Y-m-d key),
CR-SVC-12 title dispatch after commit, CR-SVC-45 dead columns removed (zero `lib/`
references), CR-SVC-46 `nextcloudTaskId` threaded fully, CR-SVC-47 bootstrap ordering
correct at all 5 endpoints + `requireHouseOwner` stays read-only (minor accepted
tradeoff: those 5 endpoints re-introduce the auto-join side effect CR-SVC-06 removed
from the owner check itself — documented), Phase-2 dead-code deletions (no dangling
references), `HouseUserMapper::findForUpdate` (true `SELECT … FOR UPDATE`, fresh row,
injection-safe), `ConcurrentCompletionTest` (deterministic, strict assertions, clean
tearDown).

**Auto-fixed this review (minor, gate re-run green):**
- `TaskSyncService::syncCompletionFromNextcloud` (`:172`): dead
  `$completedTask->setSparksEarned(0)` (the only `sparksEarned` reference left in the
  codebase — `completed_tasks.sparks_earned` was renamed to `coins_earned` by
  `Version1017`; the entity declares `coinsEarned`, not `sparksEarned`) →
  `setCoinsEarned(0)`.

**New findings (→ `backlog.md`, Source: Full Review 2026-08-11):**
- **[P1] CR-SVC-53** — `RoomService::completeRoomTask` operates on a **stale
  `RoomTask`** after `lockForUpdate` (`RoomService.php:276/295/331/354`):
  `lockForUpdate()` returns `void` and `$roomTask` is never refreshed, so the
  overdue-takeover check, `markClaimed`, and `markCompleted` recurrence all run on the
  pre-lock snapshot — the exact freshness asymmetry `findForUpdate` was hardened to
  avoid. Pre-existing (predates CS-025), surfaced by this concurrency slice; sibling
  gap to CR-SVC-04. **Decision point for `active/ → done/`**: reopen the CR-SVC-04
  class to cover `lockForUpdate`'s caller, or accept as a P1 backlog follow-up.
- **[P3] CR-SVC-54** — `MinigameService::saveScore` coin award has no row
  lock/transaction (lost-update class, mirror of CR-SVC-04).
- **[P3] CR-SVC-55** — `StreakFreezeService::process` per-user N+1 (sibling
  `StreakAtRiskService` already uses a GROUP BY).
- **[P3] CR-SVC-56** — `MinigameService::getLeaderboard` N+1 display-name enrichment.
- **[P3] CR-SVC-57** — `GameLogicService::calculateContributionScore` ignores its
  `$roomCleanliness` parameter (dead param).
- **[P3] CR-SVC-58** — `phpunit.unit.xml` Entity/Mapper suites double-run the 5 newer
  mapper tests (fragile split that already drifted once).

**Confirmed-open (already in `backlog.md`, no change):** CR-SVC-48 (unvalidated
`$completedAt` in reverse-sync — confirmed it also throws uncaught on malformed input),
CR-SVC-50 (extra queries inside the `house_user` lock — confirmed), CR-SVC-51
(MinigameController recomputes the coin formula — confirmed), CR-SVC-49/52.

**Deferred nits (logged in the CODE_REVIEW record, not separate backlog items):**
`deriveYesterday()` duplicated in 2 jobs (rule-of-three not met); the 3 streak services
inject `LoggerInterface` instead of `AppLogger` (codebase-wide normalization, relates to
CR-SVC-13); `TriggerJobs --user` declared but not honored + error message mentions CI;
i18n gaps (HouseController/MinigameController/RoomTaskController raw English strings);
PHPDoc `@return` gaps on ~15 touched public methods; `ConcurrentCompletionTest`
hardcoded `/var/www/html/lib/base.php`; `TaskMapperVisibilityTest` misleading name.

**Separate finding (not part of this plan's scope, surfaced during review):**
`docs/plans/done/` is completely empty in the working tree — 24 tracked done-plan
files deleted, unstaged (looks like an accidental or half-finished archival); 7
dangling links in `ACTIVE_TASKS.md` + 4 doc references. Reported to the user; no
action taken in this review.
