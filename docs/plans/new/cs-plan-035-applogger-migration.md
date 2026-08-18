# CS-PLAN-035: Migrate raw `LoggerInterface` services to `AppLogger`

> **Status:** Plan (not started)
> **Effort:** M
> **Priority:** P2
> **Depends on:** none
> **Related:** backlog CR-SVC-13 (`docs/backlog/backlog.md`); review notes `docs/backlog/CODE_REVIEW_2026-08-11.md`

## 1. Problem Summary

Five services inject raw `Psr\Log\LoggerInterface` instead of the app-level
logging facade `AppLogger` (`lib/Service/AppLogger.php`), violating
project-rules §2 and `docs/standards/guidelines/logging.md` ("Use `AppLogger`
as the single app-level logging facade"):

- `lib/Service/NotificationService.php:14,54` — logger used in `dispatch()` (line 306)
- `lib/Service/StreakAtRiskService.php:13,36` — call sites lines 102, 108
- `lib/Service/StreakFreezeService.php:13,30` — call sites lines 101, 108
- `lib/Service/StreakRestockService.php:12,26` — call sites lines 59, 66
- `lib/Service/TaskDueReminderService.php:17,43` — call sites lines 79, 85 (added with CS-PLAN-030)

Raw PSR logging bypasses `AppLogger::sanitizeContext()` (sensitive-key
redaction, `AppLogger.php:145-180`) and the automatic `currentUserId`
enrichment (`AppLogger.php:134-138`). The 2026-08-11 review confirmed nothing
sensitive is currently logged by the streak services — this is a
codebase-wide normalization, not an active leak.

**Out of scope** (tracked separately in CR-SVC-13 notes): ~16 services that
have no logger at all. **Included by user decision (2026-08-18):**
`lib/Service/TaskDueReminderService.php:17,43` (call sites 79, 85) — a 5th
raw-`LoggerInterface` service that postdates CR-SVC-13's 2026-08-04 source
review (added with CS-PLAN-030) — is migrated in this plan too (identical
mechanical swap); the CR-SVC-13 backlog text names it.

## 2. Strategy

Swap the constructor dependency from `Psr\Log\LoggerInterface` to
`OCA\ChoreStrike\Service\AppLogger` in all five services and migrate every
call site to `AppLogger`'s API. `AppLogger` lives in the same namespace as
the five services, so no new `use` import is needed — the PSR import is
simply deleted. `AppLogger` is auto-wired by the Nextcloud container (its
constructor deps `ILogFactory` + `IUserSession` are core services); it is
**not** registered in `lib/AppInfo/Application.php` and needs no registration
— already-migrated services (e.g. `HouseService.php:42`, `RoomTaskService.php:50`)
receive it via container resolution today.

Injection style: **required, non-nullable** `AppLogger` — these services'
failure-path logging is a semantic guarantee, unlike the incremental
`?AppLogger $appLogger = null` pattern used by `HouseService`/`RoomTaskService`
for optional adoption. Pre-release rules apply: no compatibility shims; tests
are updated in the same change.

While touching each call site, enrich the context with stable `event` and
`service` keys (logging.md "Required Context Keys"). Messages stay as-is
(minimal diff).

## 3. Backend Changes

**API mapping** (`Psr\Log\LoggerInterface` → `AppLogger`, `lib/Service/AppLogger.php`):

| Current call | Replacement | Note |
|---|---|---|
| `warning($msg, $ctx)` / `info($msg, $ctx)` | `warning($msg, $ctx)` / `info($msg, $ctx)` | Identical shape; optional 3rd `?IRequest` param stays unused in services |
| `error($msg, ['exception' => $e->getMessage(), ...])` | `logException($msg, $e, $ctx)` | `logException` (`AppLogger.php:100-111`) adds standardized `exception`/`exceptionMessage`/`exceptionCode` context and logs at `error` level |

Do **not** pass a `Throwable` object inside the context array:
`sanitizeContext()` renders objects as `[object:ClassName]` (`AppLogger.php:159-161`),
losing the message — always use `logException()` for exceptions.

### NotificationService (`lib/Service/NotificationService.php`)

- Constructor (lines 51-56): `private LoggerInterface $logger` →
  `private AppLogger $appLogger` (property renamed for codebase consistency);
  delete `use Psr\Log\LoggerInterface;` (line 14).
- **Verified:** the logger is *not* forwarded to any collaborator — the
  constructor deps are `IManager` + `HouseUserMapper`, neither receives it;
  the only usage is the `dispatch()` catch block (line 306). The swap is
  fully local.
- `dispatch()` catch (lines 305-314): `warning(... ['exception' => $e->getMessage()])`
  → `$this->appLogger->logException('Failed to dispatch ChoreStrike notification', $e, [`
  `'event' => 'notification_dispatch_failed', 'service' => 'NotificationService',`
  `'subject' => $subject, 'objectType' => $objectType, 'objectId' => $objectId])`.
  Level moves `warning` → `error` — intentional per logging.md ("unexpected
  business logic failures at `error` level when they are swallowed");
  alternative noted in §7.

### StreakAtRiskService (`lib/Service/StreakAtRiskService.php`)

- Constructor (lines 32-38): swap type, rename property to `$appLogger`,
  delete PSR import (line 13).
- Catch (lines 101-106): `error('StreakAtRiskService failed', ['exception' => ...])`
  → `logException('StreakAtRiskService failed', $e, ['event' => 'streak_at_risk_pass_failed', 'service' => 'StreakAtRiskService'])`.
- Completed (line 108): `info('StreakAtRiskService completed', $stats + ['event' => 'streak_at_risk_pass_completed'])`.

### StreakFreezeService (`lib/Service/StreakFreezeService.php`)

- Constructor (lines 27-32): swap type, rename property, delete PSR import (line 13).
- Per-user catch (lines 99-105): → `logException('StreakFreezeService: per-user failure', $e, ['event' => 'streak_freeze_user_failed', 'service' => 'StreakFreezeService', 'userId' => $user->getId()])`.
  Note: `userId` is the `HouseUser` DB row id (not a Nextcloud UID) —
  non-sensitive; `AppLogger` auto-appends `currentUserId` (null in cron
  context — harmless).
- Completed (line 108): `info('StreakFreezeService completed', $stats + ['event' => 'streak_freeze_pass_completed'])`.

### StreakRestockService (`lib/Service/StreakRestockService.php`)

- Constructor (lines 23-28): swap type, rename property, delete PSR import (line 12).
- Per-user catch (lines 57-63): → `logException('StreakRestockService: per-user failure', $e, ['event' => 'streak_restock_user_failed', 'service' => 'StreakRestockService', 'userId' => $user->getId()])`.
- Completed (line 66): `info('StreakRestockService completed', $stats + ['event' => 'streak_restock_pass_completed'])`.

### TaskDueReminderService (`lib/Service/TaskDueReminderService.php`)

- Constructor (lines 37-44): swap type, rename property to `$appLogger`, delete PSR import (line 17).
- Catch (lines 78-83): `error('TaskDueReminderService failed', ['exception' => $e->getMessage()])` → `logException('TaskDueReminderService failed', $e, ['event' => 'task_due_reminder_pass_failed', 'service' => 'TaskDueReminderService'])`.
- Completed (line 85): `info('TaskDueReminderService completed', $stats + ['event' => 'task_due_reminder_pass_completed'])`.

No frontend (`src/`) changes; no cross-layer contracts affected.
Callers (jobs `lib/BackgroundJob/DailyNotificationJob.php`, `StreakFreezeJob.php`,
`StreakRestockJob.php`, `lib/Command/TriggerJobs.php`, other services) resolve
these services via DI — no manual instantiation exists in `lib/`, so no caller
changes.

## 4. Tests

**Unit (PHPUnit)** — swap `$this->logger = $this->createMock(LoggerInterface::class)`
for `createMock(AppLogger::class)` (same pattern as `HouseServiceLoggingTest.php:35`,
`RoomTaskServiceLoggingTest.php:38`) and assert `AppLogger` interaction on
failure paths:

- `tests/unit/Service/NotificationServiceTest.php` — setUp (lines 34-50, PSR
  import line 14); `testDispatch_ManagerThrows_LogsAndSwallows` (line 246):
  expected method `warning` → `logException` (once).
- `tests/unit/Service/StreakAtRiskServiceTest.php` — setUp (lines 37-50, import
  line 14); **new** failure test: `findAllForJobs()` throws →
  `logException` expected once, `process()` still returns zeroed stats.
- `tests/unit/Service/StreakFreezeServiceTest.php` — setUp (lines 35-46, import
  line 13); **new** per-user failure test: `update()` throws → `logException`
  once, `$stats['errors'] === 1`, loop continues (mirrors the CR-SVC-30
  pattern in `StreakRestockServiceTest::testProcess_OneFailingUser_DoesNotAbortTheLoop`,
  lines 102-127).
- `tests/unit/Service/StreakRestockServiceTest.php` — setUp (lines 28-41,
  import line 13); line 119 expected method `error` → `logException`.
- `tests/unit/Service/TaskDueReminderServiceTest.php` — setUp (PSR import
  line 18, mock line 49, injection line 58); failure assertion at line 214
  expected method `error` → `logException` (once).

**Integration (PHPUnit, Docker)** — `tests/integration/Service/StreakFreezeIdempotencyTest.php:49,81`
resolves `StreakFreezeService` via `\OC::$server->get(...)`, so it exercises
the real container wiring of `AppLogger` (ILogFactory/IUserSession
auto-resolution) after the constructor change — must stay green.
`tests/integration/FamilyIntegrationTest.php:36` mocks `NotificationService`
— unaffected. Job/command tests (`DailyNotificationJobTest`,
`StreakFreezeJobTest`, `StreakRestockJobTest`, `TriggerJobsTest`) mock the
services — unaffected.

**E2E (Playwright)** — no spec changes; log-only refactor, behavior-neutral.

## 5. File Manifest

```
modified: lib/Service/NotificationService.php
modified: lib/Service/StreakAtRiskService.php
modified: lib/Service/StreakFreezeService.php
modified: lib/Service/StreakRestockService.php
modified: lib/Service/TaskDueReminderService.php
modified: tests/unit/Service/NotificationServiceTest.php
modified: tests/unit/Service/StreakAtRiskServiceTest.php
modified: tests/unit/Service/StreakFreezeServiceTest.php
modified: tests/unit/Service/StreakRestockServiceTest.php
modified: tests/unit/Service/TaskDueReminderServiceTest.php
```

No new files; nothing archived.

## 6. Implementation Order

1. `NotificationService` constructor + `dispatch()` swap; update
   `NotificationServiceTest` (mock + `logException` assertion).
2. `StreakAtRiskService` swap; update test + add failure-path test.
3. `StreakFreezeService` swap; update test + add per-user failure test.
4. `StreakRestockService` swap; update test (`error` → `logException`).
5. `TaskDueReminderService` swap; update test (`error` → `logException`).
6. Verify: `grep -R "Psr\\Log\\LoggerInterface" lib/Service` matches only
   `AppLogger.php` (its own PSR-compatibility implementation).
7. Build gate: PHPUnit unit tests, Docker integration suite, `npm run lint`.
8. Update `docs/backlog/ACTIVE_TASKS.md`; on completion tick CR-SVC-13 and
   move it to `docs/backlog/archive/2026-08.md`.

## 7. Risks & Mitigations

| Risk | Likelihood | Mitigation |
|------|-----------|------------|
| Constructor ripple across unit tests | Certain | Exhaustive list in §4 (4 setUps + 2 assertion updates); job/command/integration tests mock the services and are unaffected |
| `NotificationService` might forward its logger to collaborators | Ruled out by research | Logger is used only in `dispatch()` (line 306); `IManager`/`HouseUserMapper` never receive it — re-verify during review |
| `logException` raises notification-dispatch log level `warning` → `error` | Certain (deliberate) | Per logging.md (swallowed unexpected failures → `error`); confirmed by user (2026-08-18); fallback if the calmer level is ever preferred: keep `warning()` with manual `exception`/`exceptionMessage` context keys |
| Passing `Throwable` in context gets stringified to `[object:ClassName]` | Avoidable | Rule in §3: exceptions only via `logException()`, never as context values |
| DI resolution of `AppLogger` in cron/E2E contexts | Low | Same auto-wired path already proven by `HouseService`/`RoomTaskService`; Docker integration suite (`StreakFreezeIdempotencyTest`) covers it |
| Scope creep / hidden 5th service | Resolved | `TaskDueReminderService.php:17,43` also injects raw `LoggerInterface` (postdates CR-SVC-13's 2026-08-04 source review) — **included by user decision (2026-08-18)** |
| ~16 logger-less services remain | Accepted | Separate follow-up, stays in CR-SVC-13 notes in `backlog.md`; not planned here |

**Decisions (user, 2026-08-18):** (1) `TaskDueReminderService` is **included**
(option A — same mechanical swap, +1 test file `TaskDueReminderServiceTest.php`);
(2) the notification-dispatch log level move `warning` → `error` via
`logException` is **confirmed**.

## 8. Acceptance Criteria

- [ ] The 5 services inject `AppLogger`; no `use Psr\Log\LoggerInterface` remains in them
- [ ] All dispatch/process failure paths route through `AppLogger` (`logException` for exceptions) with stable `event` + `service` context keys
- [ ] Unit tests construct the services with mocked `AppLogger` and assert the failure routing (`logException` once per failure path, including 2 new tests)
- [ ] Docker integration suite green (verifies container wiring of `AppLogger`)
- [ ] Build gate green (`npm test` + lint + backend tests)
- [ ] Durable concepts/decisions integrated into the permanent docs (`docs/concepts/`, `docs/adr/`, `docs/engineering/`, `docs/api/`, `docs/standards/`) — plans are temporary (here: `logging.md` already canonical; no doc change expected)
- [ ] `docs/backlog/ACTIVE_TASKS.md` updated
