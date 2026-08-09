# CS-PLAN-025: `lib/Service` Security, Concurrency & Dead-Code Cleanup

> **Status:** Plan (not started)
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

- [ ] CR-SVC-01: a member cannot create a room task in another house's room, nor stamp a
      non-member as assignee (unit + controller test).
- [ ] CR-SVC-02: `TestDataService` destructive methods throw unless `isTestMode()` (test).
- [ ] CR-SVC-08: the scheduler cluster + 3 orphan services + their tables/mappers/tests are
      gone; no dangling references; full PHPUnit suite green.
- [ ] CR-SVC-04: `house_user` coins/streak are locked during completion (integration test:
      no lost update under concurrency).
- [ ] CR-SVC-12: `notifyTitleUnlocked` dispatches after `commit`, never inside the
      transaction (test).
- [ ] CR-SVC-03: `StreakFreezeService::process` is idempotent per day (integration test).
- [ ] CR-SVC-05: out-of-range minigame scores are rejected; no coins awarded (test).
- [ ] CR-SVC-06: a denied `requireHouseOwner` creates no `house_users` row (test).
- [ ] CR-SVC-07: CalDAV-sync completions produce identical rewards/recurrence to normal
      completions; divergent recurrence helper deleted (integration test).
- [ ] Build gate green: `npm run lint:fix` + `npm test` + full PHPUnit suite in Docker.
- [ ] Resolved CR-SVC-* items moved from `backlog.md` to `archive/2026-08.md`;
      `docs/backlog/ACTIVE_TASKS.md` updated.
- [ ] `gamification` SKILL / ADR-005 / roadmap updated to reflect the deletions.
