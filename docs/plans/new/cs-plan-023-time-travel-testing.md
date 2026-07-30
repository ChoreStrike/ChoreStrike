# CS-PLAN-023: Time-Travel Testing in Docker

> **Status:** Plan (not started) — drafted 2026-07-30 from the manual-test review
> **Effort:** M
> **Priority:** P2
> **Backlog:** [`backlog.md`](../../backlog/backlog.md) → TECH-TEST-001
> **Relates:** CS-015b (E2E infra), CS-017 (release test plan), CS-024 (release E2E regression)

## 1. Problem Summary

"Task overdue after N days", streak-freeze consumption, and notification timing
cannot be exercised in Docker without waiting real time. The three BackgroundJobs
(`DailyNotificationJob`, `StreakFreezeJob`, `StreakRestockJob`) and
`TaskSyncService` already inject `OCP\AppFramework\Utility\ITimeFactory`, so the
**unit** tests mock the clock (e.g. `StreakRestockJobTest`). The gap is
**integration / E2E in Docker**, which runs against the real system clock and the
real cron-driven job list — so time-dependent flows are currently untestable
without multi-day waits.

## 2. Strategy — three complementary levers

No single lever covers every case; the three compose:

1. **`libfaketime`** (`LD_PRELOAD`) in `docker/Dockerfile.test`, gated by an env
   flag (e.g. `FAKETIME=+3d`). Shifts the container clock for the whole run —
   Apache, cron, PHP and the Jobs see the shifted "now". Default off so existing
   suites are unaffected.
2. **`occ` job trigger command** (`lib/Command/TriggerJobs.php`) runs the three
   jobs immediately for a given user/house — usable from Playwright via
   `docker exec` so an E2E can force "the daily job just ran" without waiting.
3. **Seed profiles with past due-dates** (`docker/seed-test-data.sh`) — exercise
   overdue logic against the real clock by seeding data that is already late,
   no clock shift needed.

## 3. Backend / Frontend Changes

- **Backend** (`lib/`):
  - new `lib/Command/TriggerJobs.php` — `occ chorestrike:trigger-jobs <type> [<user>]`
    where `<type>` ∈ `daily-notification | streak-freeze | streak-restock | all`.
    Dispatches each job's `run()` (or the underlying service call) once.
  - register in `appinfo/info.xml` `<commands>`.
- **Frontend** (`src/`): none.
- **Cross-layer contracts**: none (time is not part of any payload).
- **Docker**:
  - `docker/Dockerfile.test`: install `faketime`; wrap Apache via env in
    `docker/test-entrypoint.sh` when `FAKETIME` is set.

## 4. Tests

- **Unit (PHPUnit):** `TriggerJobsTest` — asserts each type dispatches exactly
  once and rejects unknown types. Mock `ITimeFactory` is already the established
  pattern; reuse it for any new time-dependent assertions.
- **Integration (Docker):** optional smoke that runs the command end-to-end
  against a seeded house.
- **E2E (Playwright):** `time-travel-overdue.spec.ts` (profile `family`) —
  advance clock via `FAKETIME=+3d` (or seed overdue data + trigger-jobs), assert
  streak-freeze consumed and the at-risk notification is present. Blocked-on
  decision: faketime vs. seed+trigger — pick per scenario.

## 5. File Manifest

```
new:      lib/Command/TriggerJobs.php
new:      tests/unit/Command/TriggerJobsTest.php
modified: appinfo/info.xml                                  (<commands> entry)
modified: docker/Dockerfile.test                            (faketime install)
modified: docker/test-entrypoint.sh                         (optional LD_PRELOAD gate)
modified: docker/seed-test-data.sh                          (overdue/stale-streak seed)
new:      tests/e2e/time-travel-overdue.spec.ts             (or reuse family profile)
```

## 6. Implementation Order

1. `TriggerJobs` command + unit test (independent, ships value even without faketime).
2. Seed overdue/stale-streak data (enables overdue tests without any clock trickery).
3. `libfaketime` wiring in Docker (optional opt-in; enables "advance clock" E2E).
4. E2E time-travel spec.

## 7. Risks & Mitigations

| Risk | Likelihood | Mitigation |
|------|-----------|------------|
| `libfaketime` breaks Nextcloud caching/timeouts | Medium | Gate behind env flag; default off; document the opt-in run command |
| Job trigger races real cron in the container | Low | Test env has cron disabled; trigger command is the only path |
| Seed data drifts from real due-date semantics | Low | Reuse existing seed helpers; add a unit test asserting the seeded dates are in the past |

## 8. Acceptance Criteria

- [ ] `occ chorestrike:trigger-jobs` exists and is covered by a unit test.
- [ ] At least one E2E exercises an overdue/streak time-dependent flow without a real multi-day wait.
- [ ] Existing suites (`TEST_DB_PROFILE=minimal` default) remain unaffected (faketime opt-in).
- [ ] `docs/engineering/testing.md` documents how to run a time-shifted test.

---

**Review:** proposed `/plan-review` after creation (mandatory for plans before `new/` → `active/`).
