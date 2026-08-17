# CS-PLAN-029: Service-Layer Hardening (CR-SVC-09/10/48/49)

> **Status:** Done (2026-08-16, Standard-B review clean — moved to `done/`)
> **Effort:** S
> **Priority:** P2
> **Depends on:** none (builds on CS-PLAN-025/028 patterns)
> **Related issue:** [`backlog.md`](../../backlog/backlog.md) — CR-SVC-09, CR-SVC-10, CR-SVC-48, CR-SVC-49 (all closed → [`archive/2026-08.md`](../../backlog/archive/2026-08.md))

## 1. Problem Summary

Four open security/robustness follow-ups from the `lib/Service` reviews remain:

- **CR-SVC-09 (P2):** `RoomTaskService::assignTask()` / `bulkAssignTasks()` are
  public mutators with **no house scoping**: no `houseId` parameter, no
  membership assertion, no room-task ownership check. Today only
  `RoomTaskController::assign()` calls them and performs the preamble — but one
  future caller that forgets it enables cross-house assignment / arbitrary-user
  writes. `bulkAssignTasks` also logs the raw `userId`.
- **CR-SVC-10 (P2):** `RoomService::completeRoomTask()` resolves `$houseUser`
  pre-transaction and dereferences it inside (`isOwner()`, `getId()`); a
  non-member (or a member removed mid-flight) yields a **500 instead of a clean
  403**, and ordinary tasks have no membership re-check inside the transaction.
- **CR-SVC-48 (P2):** `TaskSyncController::syncFromNextcloud` passes the
  client-controlled `$completedAt` straight into `new \DateTime(...)` —
  malformed input surfaces as a 500 (generic catch) instead of 400, and a
  far-future date pushes `next_due` out indefinitely. The reverse path also
  skips the room-cleanliness update; unlike the no-reward deviation this one is
  undocumented.
- **CR-SVC-49 (P3):** `ControllerErrorFormatter::safeMessage()` intentionally
  excludes `InvalidArgumentException`, so six 400-catches that route through it
  return the generic fallback instead of the curated validation message
  (`MinigameController::purchaseBoost`; `RoomTaskController::setAssignees` /
  `addAssignee`; `SchedulingController::updateEnergy` / `updateAvailability` /
  `updateMemberAvailability`). The `TemplateController:275` site from the
  backlog no longer exists (controller removed in CS-027 D7).

## 2. Strategy

- **H1 (CR-SVC-09):** add a required `int $houseId` parameter to
  `assignTask()` / `bulkAssignTasks()`; inside `assignTask()` call the existing
  `validateRoomTaskOwnership()` + `assertHouseMembers()` (membership half
  mirrors `setAssignees` / `addAssignee`; ownership mirrors the
  `createRoomTask` CR-SVC-01 chain). Drop the raw `userId` from the bulk warning
  log context. Controller passes `$house->getId()`; its existing preamble stays
  as defense in depth.
- **H2 (CR-SVC-10):** resolve the house user via the nullable
  `HouseUserMapper::findByHouseAndUser()` and throw `ForbiddenException` on
  null — the current `findByNextcloudUserId()` *throws*
  `DoesNotExistException` (uncaught → 500), it never returns null; re-resolve
  the house user **inside** the transaction right after `lockForUpdate` and
  throw `ForbiddenException` if it disappeared (member removed between fetch
  and lock). One cheap indexed lookup, acceptable inside the critical section
  (the CR-SVC-50 redundancy concern is a different query class and stays in
  the backlog).
- **H3 (CR-SVC-48):** validate `$completedAt` at the controller boundary
  (parse + future bound `now + 1 hour`) → 400 `VALIDATION_ERROR`. **Close** the
  cleanliness deviation instead of documenting it: `TaskSyncService` already
  injects `RoomService`, so the reverse path applies the same
  `energyCost * 10` cleanliness bump as the canonical path. Rewards remain
  skipped (existing documented deviation).
- **H4 (CR-SVC-49):** add `ControllerErrorFormatter::validation()` (400
  JSONResponse with `$e->getMessage()`) and migrate all
  `InvalidArgumentException` catches to it — the six `safeMessage` misuses plus
  the four existing inline `getMessage()` sites (`saveScore`, `create`,
  `fromTemplate`, `SetupController::apply`) and the new `assign` catch from H1,
  for one uniform pattern. `UserController::selectTitle` is a deliberate
  exception: it returns the curated translated constant
  (`l->t('Invalid title pack')`), not the raw exception message — migrating it
  would be an i18n regression. Message audit: all thrown messages at the
  migrated sites are service-curated constants or echo only caller-owned
  values (boost id, coin counts, bounds) — no schema leak.

## 3. Backend / Frontend Changes

- **Backend** (`lib/`):
  - `lib/Service/RoomTaskService.php` — `assignTask` / `bulkAssignTasks`
    signatures + internal validation; log context without `userId`.
  - `lib/Service/RoomService.php` — null guard + in-transaction membership
    re-check in `completeRoomTask`.
  - `lib/Service/TaskSyncService.php` — cleanliness bump in
    `syncCompletionFromNextcloud` + PHPDoc (cleanliness now applied; rewards
    still skipped by design).
  - `lib/Controller/TaskSyncController.php` — `$completedAt` validation, 400
    responses.
  - `lib/Controller/ControllerErrorFormatter.php` — new `validation()` helper.
  - `lib/Controller/{MinigameController,RoomTaskController,SchedulingController,SetupController}.php`
    — migrate `InvalidArgumentException` catches to `validation()`; `assign()`
    gains the catch. (`UserController::selectTitle` keeps its translated
    curated message.)
- **Frontend** (`src/`): none (error codes unchanged; curated messages replace
  the generic fallback on 400s, which is an improvement, not a contract break).
- **Cross-layer contracts:** none changed.

## 4. Tests

- **Unit (PHPUnit):**
  - `RoomTaskServiceTest` — `assignTask` ok with house scoping;
    `InvalidArgumentException` for non-member target; `NotFoundException` for a
    room task of another house; `bulkAssignTasks` threads `houseId`, skips
    failing items, and its warning log context carries no `userId`
    (extend `RoomTaskServiceLoggingTest` if the log assertion lives there).
  - `RoomServiceTest` — `completeRoomTask` with unknown user →
    `ForbiddenException`; member deleted between pre-fetch and lock (mock
    returns entity, then null) → `ForbiddenException` (regression: was a 500).
  - `TaskSyncServiceTest` — reverse sync calls
    `RoomService::updateRoomCleanliness(roomId, energyCost * 10)`; conflict
    path (local newer) still returns null without touching cleanliness.
  - `TaskSyncControllerTest` — malformed `$completedAt` → 400 VALIDATION_ERROR;
    far-future `$completedAt` → 400; valid input unchanged.
  - `ControllerErrorFormatterTest` — `validation()` returns 400 + verbatim
    message + given code.
  - `RoomTaskControllerTest` / `RoomTaskControllerIntegrationTest` — update
    `assignTask` mock signatures; new `assign` non-member → 400 path.
  - `MinigameControllerTest` / `SchedulingControllerTest` /
    `SetupControllerTest` — the migrated catches still return 400 with the
    curated message.
- **Integration (PHPUnit, Docker):** existing `TaskSyncCompletionTest` +
  completion suite must stay green (in-transaction re-check must not break the
  happy path); no new integration spec required.
- **E2E:** none (no UI change).

## 5. File Manifest

```
modified: lib/Service/RoomTaskService.php
modified: lib/Service/RoomService.php
modified: lib/Service/TaskSyncService.php
modified: lib/Controller/TaskSyncController.php
modified: lib/Controller/ControllerErrorFormatter.php
modified: lib/Controller/MinigameController.php
modified: lib/Controller/RoomTaskController.php
modified: lib/Controller/SchedulingController.php
modified: lib/Controller/SetupController.php
modified: tests/unit/Service/RoomTaskServiceTest.php
modified: tests/unit/Service/RoomServiceTest.php
modified: tests/unit/Service/TaskSyncServiceTest.php
modified: tests/unit/Controller/TaskSyncControllerTest.php
modified: tests/unit/Controller/ControllerErrorFormatterTest.php
modified: tests/unit/Controller/RoomTaskControllerTest.php
modified: tests/integration/Controller/RoomTaskControllerIntegrationTest.php
modified: tests/unit/Controller/MinigameControllerTest.php
modified: tests/unit/Controller/SchedulingControllerTest.php
modified: tests/unit/Controller/SetupControllerTest.php
modified: docs/backlog/backlog.md            (close CR-SVC-09/10/48/49)
modified: docs/backlog/ACTIVE_TASKS.md
modified: docs/standards/guidelines/security.md (one-line service-validation note, if warranted)
```

## 6. Implementation Order

1. **H4** — `ControllerErrorFormatter::validation()` + test; migrate the ten
   existing `InvalidArgumentException` catches (mechanical, unlocks H1's error
   path).
2. **H1** — `assignTask` / `bulkAssignTasks` house scoping + controller call
   site + tests (update mocked signatures).
3. **H2** — `completeRoomTask` null guard + in-transaction re-check + tests.
4. **H3** — `TaskSyncController` date validation + `TaskSyncService`
   cleanliness bump + tests.
5. Gate (`npm test`, `npm run lint`, PHPUnit unit + integration via Docker,
   `composer cs:check`, `l10n:check-keys`), self-review against
   `llm-do-dont-checklist.md`.
6. Backlog: move CR-SVC-09/10/48/49 to `archive/2026-08.md`; update
   `ACTIVE_TASKS.md`.

## 7. Risks & Mitigations

| Risk | Likelihood | Mitigation |
|------|-----------|------------|
| `assignTask` signature change breaks unknown callers | Low (grep shows only `RoomTaskController::assign` + `bulkAssignTasks`) | Compiler-free PHP → unit tests + full gate; grep-verified call sites in step 2 |
| In-transaction membership re-check adds latency inside the row lock | Low | Single indexed `(house_id, nextcloud_user_id)` lookup; far cheaper than the queries CR-SVC-50 targets |
| Cleanliness bump on reverse sync changes room state for NC-only completions | Low (intended fix) | Same formula as canonical path; conflict path untouched; covered by `TaskSyncServiceTest` |
| Future-date bound rejects legitimate clock-skewed clients | Low | `+1 hour` tolerance; malformed vs. future produce distinct messages |
| Curated message exposure leaks internals | Low (audited) | H4 audit: all nine sites throw constants or caller-owned values; `validation()` PHPDoc forbids un-audited use |

## 8. Acceptance Criteria

- [x] `assignTask` / `bulkAssignTasks` require `houseId` and validate ownership
      + membership internally; no raw `userId` in bulk log context (CR-SVC-09).
- [x] `completeRoomTask` returns a clean 403 (`ForbiddenException`) for
      non-members and re-checks membership inside the transaction (CR-SVC-10).
- [x] `syncFromNextcloud` rejects malformed/future `$completedAt` with 400
      VALIDATION_ERROR; reverse sync applies the room-cleanliness bump
      (CR-SVC-48).
- [x] `ControllerErrorFormatter::validation()` exists and all eleven migrated
      `InvalidArgumentException` catches use it (six former `safeMessage`
      misuses, four inline sites, the new `assign` catch);
      `UserController::selectTitle` keeps its translated curated message
      (CR-SVC-49). Bonus: `updateDueDate`'s uncaught date-format
      `InvalidArgumentException` (previously a 500) now also routes through
      `validation()` → 400.
- [x] Regression tests for each fix exist and pass.
- [x] Build gate green (`npm test` + lint + PHPUnit unit/integration +
      `cs:check` + l10n). Unit 696 / integration 155 (24 pre-existing skips) /
      Jest 699 / lint 0 / cs:check 0 / l10n clean.
- [x] CR-SVC-09/10/48/49 moved from `backlog.md` to `archive/2026-08.md`;
      `ACTIVE_TASKS.md` updated.
- [x] Durable decisions (service-internal validation for assign mutators;
      reverse-sync validation bounds) integrated into
      `docs/standards/guidelines/security.md` (client-supplied dates rule) and
      ADR-006 (Neutral follow-ups updated) — plans are temporary.

## Review Outcome (2026-08-16, Standard B)

Reviewed the full uncommitted CS-PLAN-029 change set (9 lib files, 11 test
files, 5 doc/backlog files). Shared checklist clean on all points:

- **Architecture** — validation lives in the services (`assignTask` /
  `bulkAssignTasks` ownership + membership, `completeRoomTask` null guard +
  in-transaction re-check right after `lockForUpdate`); controllers stay thin;
  `ControllerErrorFormatter::validation()` centralises the 400 shape; no SQL
  concatenation (named parameters throughout).
- **Authorization / Security** — `@NoAdminRequired` only; `ensureHouseMember`
  precedes every service call; `completedAt` parsed + future-bounded at the
  controller boundary before any house resolution; all twelve `validation()`
  catch sites audited (curated constants or caller-owned values);
  `UserController::selectTitle` correctly keeps its translated constant;
  `ForbiddenException` extends `\Exception` directly, so the
  `InvalidArgumentException`-first catch order is safe; bulk warning log now
  carries `houseId` instead of the raw target userId.
- **KISS / Workarounds / Logging / i18n** — focused minimal diff; no HACKs;
  `AppLogger` only; no new user-visible UI strings (API error constants
  follow the existing English convention; l10n keys stay synchronized).
- **Tests** — regression specs for all four fixes (house-scoped assign ok /
  non-member 400 / foreign-house 404; bulk skip + userId-free log context;
  unknown user → 403; member removed before lock → 403 with no writes;
  reverse cleanliness bump + untouched conflict path; malformed / future /
  small-skew `completedAt`; `validation()` helper; migrated catch sites).
  All verified green during the review.
- **Docs** — security.md client-supplied-dates rule + ADR-006 Neutral update
  integrate the durable decisions; backlog/archive/ACTIVE_TASKS housekeeping
  matches the backlog rules.

**Auto-fixes:** none required on the CS-029 change set itself. One
out-of-change-set doc defect found while running the gate and fixed inline:
the docker-dev skill + `docs/engineering/testing.md` documented
`docker compose run --rm nextcloud-test vendor/bin/phpunit …` for the backend
suites — `run` overrides the entrypoint, so the fresh container has no
installed Nextcloud ("Not installed" bootstrap error); canonical path is
`docker compose exec` / `docker exec chorestrike-test` against the running
container (also corrected the stale umbrella-`phpunit.xml` note to
`phpunit.integration.xml`).

**Findings:** none blocking/major/minor. Two nits recorded for the report
only: (1) `assignTask` performs one redundant indexed `find()` after
`validateRoomTaskOwnership` already resolved the entity — same query class as
CR-SVC-11/50, no standalone item; (2) `RoomTaskControllerIntegrationTest`
stubs `validateRoomTaskOwnership` on the mocked service, which the controller
never calls — harmless no-op.

**Gate re-verified green during the review:** ESLint clean (lint:fix, no
changes) / Jest 699 / PHPUnit unit 696 / integration 155 (24 pre-existing
skips) via `docker exec` / `composer cs:check` 0 of 254 / `l10n:check-keys`
synchronized.

### Pre-commit re-verification (2026-08-17, Standard B)

Fresh Standard-B pass over the same uncommitted tree before commit: checklist
re-walked independently (incl. re-audit of every migrated `validation()` catch
site and the `MinigameService` message constants) — no new findings; the two
nits above stay report-only. Correction to nit (2): the controller **does**
call `validateRoomTaskOwnership` in `assign()` (defense in depth), so the
integration-test stub is exercised, not dead. Gate re-run green: ESLint clean /
Jest 699 / PHPUnit unit 696 / integration 155 (24 pre-existing skips) /
`cs:check` 0 of 254 / l10n synchronized. (Environmental note: the Docker env
needed a `down -v` reset — stale DB volume vs. recreated app container made the
Nextcloud install fail with "The Login is already being used"; disposable-DB
workflow per project-rules §1.)
