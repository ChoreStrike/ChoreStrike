# CS-PLAN-023: Time-Travel Testing in Docker

> **Status:** Implemented + verified (2026-08-04) — drafted 2026-07-30 from the manual-test review; revised 2026-07-30 after `/plan-review` (decision: refactor jobs for injectable time); implemented 2026-08-04 incl. four pre-existing defects found during verification (see §9). Gates: PHPUnit unit 786 green, integration 141 green, Jest 677 green, lint clean; E2E chromium+serial-db 160 passed with only the 4 pre-existing baseline failures (verified via stash round-trip).
> **Effort:** M
> **Priority:** P2
> **Backlog:** [`backlog.md`](../../backlog/backlog.md) → TECH-TEST-001 (done → [`archive/2026-08.md`](../../backlog/archive/2026-08.md))
> **Relates:** CS-015b (E2E infra), CS-017 (release test plan), CS-024 (release E2E regression)

## 1. Problem Summary

"Task overdue after N days", streak-freeze consumption, and notification timing
cannot be exercised in Docker without waiting real time. The three BackgroundJobs
(`DailyNotificationJob`, `StreakFreezeJob`, `StreakRestockJob`) already inject
`OCP\AppFramework\Utility\ITimeFactory`, and `TaskSyncService` injects
`OCP\ITimeFactory`.

**However**, the jobs today use that factory only for `setInterval()` scheduling —
their date logic is hardcoded `new \DateTime('yesterday')`
(`DailyNotificationJob.php:66`, `StreakFreezeJob.php:53`), and `StreakRestockJob`
uses no time at all. As a result the mocked clock has **no effect** on the existing
unit tests' date logic, and the only thing that moves "yesterday" right now is a
real clock shift. Integration / E2E in Docker runs against the real system clock and
the real cron-driven job list (cron is not started in the test container), so these
flows are untestable without multi-day waits.

This plan introduces a **real, injectable time seam** by refactoring the jobs, then
adds an `occ` trigger command and seeded profiles so the flows run deterministically
in seconds.

## 2. Strategy — refactor-first, then four complementary levers

The refactor is the foundation that unlocks the others; without it the time seam
does not exist at the logic level.

1. **Refactor the jobs for injectable time.** Each job derives "yesterday" from the
   injected `ITimeFactory` (`getTime()` → `clone` → `modify('-1 day')`) and delegates
   its body to a public service method that takes the date as a parameter. This makes
   time injectable at the logic level (so unit tests actually control it), keeps the
   jobs thin, and gives the trigger command a callable entry point. Replaces the
   `abstract protected function run()` dead-end for callers.
2. **`occ` trigger command** (`lib/Command/TriggerJobs.php`) calls the extracted
   services. An `--as-of` option sets "yesterday" explicitly, so an E2E can simulate
   "3 days passed" with **zero clock trickery**. Guarded by `isTestMode()` like
   `TestDataSeed`.
3. **Seed profiles with overdue/past due-dates** for the `TaskSyncService`
   "overdue after N days" path — not a job, so seeded past dates make it overdue
   against the real clock, no shift needed.
4. **(Optional, demoted) `libfaketime`** (`LD_PRELOAD`) only for cases that need a
   whole-container clock shift (e.g. global multi-day transitions you don't want to
   encode as `--as-of`). Default off.

> **Rule:** never combine `libfaketime` with time-relative seeded dates in one
> assertion — "yesterday" and seeded due-dates drift inconsistently under a shift.
> Prefer seed + `--as-of` (no clock shift) as the default; reserve faketime for the
> rare global-clock case.

## 3. Backend / Frontend Changes

- **Backend** (`lib/`):
  - new `lib/Service/StreakFreezeService.php` — `process(\DateTimeInterface $yesterday): array` (the freeze/reset loop).
  - new `lib/Service/StreakAtRiskService.php` — `process(\DateTimeInterface $yesterday): array` (the daily at-risk pass; uses the existing `NotificationService` as the low-level notifier).
  - new `lib/Service/StreakRestockService.php` — `process(): array` (time-independent restock; extracted for uniform trigger-ability).
  - modified jobs: each becomes thin — derives `$yesterday` from the injected factory and delegates to its service. `StreakRestockJob` delegates without a date.
  - new `lib/Command/TriggerJobs.php` — `occ chorestrike:trigger-jobs <type> [--as-of=YYYY-MM-DD] [--user=UID]` where `<type>` ∈ `daily-notification | streak-freeze | streak-restock | all`. Default `--as-of` = now via injected `ITimeFactory` (so "yesterday" = real yesterday). Refuses to run unless `TestDataService::isTestMode()` (`NEXTCLOUD_TEST_MODE`), mirroring `TestDataSeed`.
  - `[--user]` scopes to one user where the service supports it; if a service is not extended with a per-user filter in this plan, the command logs a notice and runs the full pass (the AC notes this scope).
  - register in `appinfo/info.xml` `<commands>`.
- **Frontend** (`src/`): none.
- **Cross-layer contracts**: none (time is not part of any payload).
- **Docker**:
  - `docker/seed-test-data.sh` / `TestDataService`: add overdue / stale-streak seed for the `family` profile.
  - (optional) `docker/Dockerfile.test`: `apt-get install faketime` only if lever 4 is pursued.

## 4. Tests

- **Unit (PHPUnit):**
  - new `TriggerJobsTest` — dispatches the right service per type; `all` runs all three exactly once; rejects unknown types; refuses outside test mode; `--as-of` controls the passed date.
  - new `StreakFreezeServiceTest` / `StreakAtRiskServiceTest` — assert behaviour **as a function of the injected `$yesterday`**. This is the regression that makes the refactor safe — these tests were not possible before.
  - update the existing `*JobTest` files — assert the job derives "yesterday" from the injected `ITimeFactory` and delegates to the service (keeps them green, makes them meaningful).
- **Integration (Docker):** smoke that runs `trigger-jobs` end-to-end against a seeded house.
- **E2E (Playwright),** profile `family`, split by flow:
  - **overdue** — seed a past due-date, assert `TaskSyncService` marks the task overdue (no clock shift).
  - **streak/notification** — seed a user with streak>0, 0 freezes, no completions; run `trigger-jobs streak-freeze --as-of=<tomorrow>` (yesterday = today); assert freeze consumed/reset and the at-risk notification is present.

## 5. File Manifest

As delivered (2026-08-04; differences from the draft explained in §9):

```
new:      lib/Service/StreakFreezeService.php
new:      lib/Service/StreakAtRiskService.php
new:      lib/Service/StreakRestockService.php
modified: lib/BackgroundJob/StreakFreezeJob.php            (thin delegate)
modified: lib/BackgroundJob/DailyNotificationJob.php       (thin delegate)
modified: lib/BackgroundJob/StreakRestockJob.php           (thin delegate)
new:      lib/Command/TriggerJobs.php
new:      tests/unit/Command/TriggerJobsTest.php
new:      tests/unit/Service/StreakFreezeServiceTest.php
new:      tests/unit/Service/StreakAtRiskServiceTest.php
new:      tests/unit/Service/StreakRestockServiceTest.php
modified: tests/unit/BackgroundJob/*JobTest.php            (delegate assertions)
modified: tests/unit/Service/TestDataServiceTest.php       (time-travel seed regression)
modified: tests/unit/RouteMatrixTest.php                   (route-shadowing guard)
modified: phpunit.unit.xml                                 (Command + Contract suites)
new:      tests/__mocks__/Symfony/Component/Console/**     (Command mock surface)
modified: tests/bootstrap-entity.php                       (Symfony mocks)
modified: appinfo/info.xml                                 (<commands> entry; dead
                                                            <notifications> tag removed)
modified: appinfo/routes.php                               (static routes before {id})
modified: lib/AppInfo/Application.php                      (registerNotifierService)
modified: lib/Service/TestDataService.php                  (overdue/stale-streak seed)
modified: docker/Dockerfile.test                           (+ notifications app)
modified: docker/test-entrypoint.sh                        (enable notifications app)
modified: tests/e2e/fixtures/database-fixtures.ts          (auth + POST seed fix)
modified: tests/e2e/auth.setup.ts                          (auth + POST seed fix)
new:      tests/e2e/fixtures/occ-runner.ts
new:      tests/e2e/time-travel-overdue.spec.ts
new:      tests/e2e/time-travel-streak-notification.spec.ts
modified: playwright.config.ts                             (serial-db pool)
new:      tests/integration/Command/TriggerJobsIntegrationTest.php
modified: docs/engineering/testing.md, docs/engineering/architecture.md,
          docs/concepts/gamification-and-rewards.md, docs/testing/release-test-plan.md
not done: docker/Dockerfile.test faketime                  (optional, demoted, not needed)
```

## 6. Implementation Order

1. Extract the three services + thin the jobs (derive "yesterday" from `ITimeFactory`). Update/add unit tests. Backend gate must be green before proceeding.
2. `TriggerJobs` command + unit test (`isTestMode()`-guarded, `--as-of`). Ships independent value even before E2E.
3. Seed overdue/stale-streak data (enables overdue tests with no clock shift).
4. E2E specs (overdue; streak/notification via trigger).
5. (Optional) `libfaketime` wiring if a global-clock-shift case is actually needed.

## 7. Risks & Mitigations

| Risk | Likelihood | Mitigation |
|------|-----------|------------|
| Refactor changes job behaviour | Medium | Parametric unit tests (services) + assert delegation in job tests; run the full backend suite in Docker before E2E |
| `libfaketime` breaks Nextcloud caching/timeouts | Medium | Demoted to optional, default off; only when lever 4 is chosen and documented |
| Job trigger races real cron in the container | Low | Test env has no cron daemon; the trigger command is the only path |
| Seed data drifts from real due-date semantics | Low | Reuse existing seed helpers; add a unit test asserting the seeded dates are in the past |
| `--as-of` / `trigger-jobs` misused in production | Medium | `isTestMode()` guard; documented as test-only |

## 8. Acceptance Criteria

- [x] Jobs derive "yesterday" from the injected `ITimeFactory` (no `new \DateTime('yesterday')` in job logic); each job is a thin delegate to a service.
- [x] `occ chorestrike:trigger-jobs` exists, is `isTestMode()`-guarded, is covered by a unit test, and supports `--as-of`.
- [x] E2E #1 exercises the overdue (`TaskSyncService`) flow via seeded past due-dates, with no real multi-day wait.
- [x] E2E #2 exercises a streak-freeze consumption + at-risk notification flow via `trigger-jobs --as-of`, with no real multi-day wait.
- [x] Existing suites (`TEST_DB_PROFILE=minimal` default) remain unaffected (faketime opt-in/off by default).
- [x] `docs/engineering/testing.md` documents how to run a time-shifted test (`trigger-jobs --as-of`; optional faketime).
- [x] `StreakRestockJob` path is noted as time-independent (triggering it once is sufficient).

## 9. Implementation Notes (2026-08-04)

Delivered per §5 manifest, plus deviations and findings:

- **Deviations:** `docker/seed-test-data.sh` needed no change (thin `occ`
  wrapper; the seed data lives in `TestDataService::seedFamily()`).
  `StreakRestockServiceTest` added beyond the manifest (new code requires a
  test). E2E triggers the occ command via `tests/e2e/fixtures/occ-runner.ts`
  (`docker exec`, container auto-detected: `chorestrike-test` locally /
  `nextcloud` in test.yml CI; override `NEXTCLOUD_CONTAINER`).
- **Pre-existing defects found & fixed during verification**
  (details: `docs/backlog/archive/2026-08.md`):
  1. Route shadowing — 12 static routes registered after `{id}` routes were
     swallowed by the order-based router (`/room-tasks/overdue` included).
     Reordered `routes.php`; regression:
     `RouteMatrixTest::testParameterizedRoutesDoNotShadowLaterStaticRoutes`
     (and RouteMatrixTest wired into `phpunit.unit.xml` — it was in no suite).
  2. `ChoreStrikeNotifier` never registered (legacy info.xml tag is dead on
     modern Nextcloud) → notifications persisted but never rendered in the
     bell. Fixed via `registerNotifierService()` in `Application::register()`.
  3. E2E seed/auth fixtures broken since CS-PLAN-019 (unauthenticated probes
     → 401; GET on POST-only routes → 405) → `seededData` always null.
     Fixed with Basic auth + POST.
  4. `notifications` app missing from the git-clone Docker test image (ships
     only in release tarballs) → added to `Dockerfile.test` + entrypoint.
- **Found & logged open:** `BUG-API-DAILY-001` — `/api/v1/tasks/daily` 500s
  (`DATE(rt.next_due)` used as a column in WHERE); un-shadowing surfaced it.
- Integration smoke boots the notifications app via
  `OC\AppFramework\Bootstrap\Coordinator::bootApp('notifications')` because
  the CLI test bootstrap only runs the registration phase.

---

## Review Outcome

Reviewed 2026-07-30 — Standard A (`/plan-review`), one plan file, verified against the
codebase. **Decision (user-approved): refactor the jobs for injectable time.** Findings
resolved in this revision:

- **#1 (block)** premise was wrong — `ITimeFactory` was used only for `setInterval()`;
  jobs hardcode `new \DateTime('yesterday')`. Now refactored to derive the date from
  the injected factory (§1, §2, §3).
- **#2 (major)** `run()` is `abstract protected` (confirmed via the mock `TimedJob`
  and `StreakFreezeJobTest::invokeRun()` reflection) — uncallable from a command.
  Resolved by extracting service methods (§3).
- **#3 (major)** added the `isTestMode()` safety guard (§3, §8), matching `TestDataSeed`.
- **#4 (major)** `[<user>]` has no job API today — scoped via the service extraction
  with explicit fallback behaviour (§3).
- **#5 (major)** faketime propagation under-specified — demoted to optional; with
  `--as-of` the global clock shift is no longer required for the jobs (§2, §3).
- **#6 (minor)** added the seed/faketime non-mixing rule (§2).
- **#7 (minor)** `StreakRestockJob` noted as time-independent (§2, §8).
- **#8 (minor)** split AC #2 into overdue vs. streak/notification (§8).
- **#9 (nit)** manifest updated for the new service + test files (§5).
- **#10 (nit)** two `ITimeFactory` interfaces in the codebase (`OCP\ITimeFactory` in
  `TaskSyncService` vs `OCP\AppFramework\Utility\ITimeFactory` in the jobs; two mock
  files exist) — pre-existing, logged as backlog `TECH-CLEANUP-ITIMEFACTORY`; normalize
  when `TaskSyncService` is touched.

Ready for the user to move `new/ → active/` once approved.
*(Done: moved to `active/` and implemented 2026-08-04 — see §9/§10.)*

## 10. Verification Record (2026-08-04)

- **Backend:** PHPUnit unit **786 green** (incl. new Service/Command/Contract
  suites), integration **141 green** (24 pre-existing skips, incl. new
  `TriggerJobsIntegrationTest`), `composer lint` clean.
- **Frontend:** Jest **677 green**, ESLint clean (no frontend feature code
  changed; only E2E fixtures).
- **E2E:** `--project=chromium --project=chromium-serial-db` — **160 passed**,
  13 conditional skips, **4 failed — all four proven pre-existing** via a
  `git stash` round-trip against the baseline tree (snake pause/resume,
  title-selection pack-active, 3-floor-house navigation/add-task). All four
  time-travel tests and every seed-dependent suite (error-handling,
  validation, multi-user-interactions — previously broken by the CS-PLAN-019
  fixture regression) pass.

---

**Review:** `/plan-review` completed 2026-07-30.

## 11. Standard B Code Review (2026-08-04)

Standard — Type B (changed files only, no subagents) of the full uncommitted
CS-023 changeset (3 services, TriggerJobs command, 3 thinned jobs, routes
reorder, Application notifier registration, TestDataService seed, 7 unit test
files, integration test, Symfony Console mocks, 2 E2E specs + occ-runner,
E2E fixture/auth fixes, Docker image/entrypoint, docs + backlog).

**Checklist:** architecture ✓ (logic in services, thin jobs, QueryBuilder
`createNamedParameter` everywhere, no SQL concatenation); authorization ✓
(command is `isTestMode()`-guarded like `TestDataSeed`, CLI-only — no
`@NoAdminRequired`/`@PublicPage` applies); security ✓ (`--as-of` strictly
validated, no `v-html`, no secrets/PII logged); KISS/minimal diff ✓;
workarounds ✓ (none; `bootApp('notifications')` in the integration test is
documented, not a `// HACK:`); logging ✓ (services log only int userId +
exception message + int stats — nothing sensitive); i18n ✓ (no new user-visible
UI strings; OCC CLI output is admin-only, consistent with `TestDataSeed`);
tests ✓ (behaviour-preserving extraction with the time-seam regression coverage
that was not possible before).

**Findings — all nit/deferred, none block or major, none auto-fixed:**
- (nit) `deriveYesterday()` is duplicated verbatim in `DailyNotificationJob`
  and `StreakFreezeJob` (4 lines). Rule-of-three not met (only 2 jobs; the
  restock job is time-independent) → a trait now would be premature abstraction.
- (nit) The three new services inject `Psr\Log\LoggerInterface` directly rather
  than `AppLogger`. This faithfully preserves the original jobs' logger and
  matches the peer `NotificationService`; nothing sensitive is logged, so the
  redaction/context benefit is moot here. Switching to `AppLogger` would change
  log-context semantics → left as a codebase-wide normalization, not this
  changeset's job.
- (pre-existing, faithful) Guard order differs across the two streak services
  (freeze = streak-check first; at-risk = availability-check first). Verified
  identical to the original jobs via `git show HEAD` — no behaviour change.
- (pre-existing, already logged) `BUG-API-DAILY-001` (`DATE(rt.next_due)` as a
  column in WHERE, `RoomTaskMapper.php:373` — confirmed real) and
  `TECH-CLEANUP-ITIMEFACTORY` are tracked open in `backlog.md`.

**Auto-fixes applied:** none — no finding fell in the auto-fix column.
**Quality gate (re-verified, all green):** ESLint `src`+`tests/e2e` exit 0;
`l10n:check-keys` pass; Jest **677 passed**; `php -l` 14/14 new PHP files OK;
PHPUnit unit **786 OK**; PHPUnit integration **141 tests (24 pre-existing
skips), OK**. E2E not re-run (uncommitted tree; plan §10 already recorded
160 passed with the 4 baseline failures proven pre-existing via stash).

**Commit:** not committed — awaiting user approval (no `--commit` flag).
