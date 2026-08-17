# CS-PLAN-030: Task-Due Reminders + Quick-Win Bundle

> **Status:** Done (2026-08-17) — implemented, gate green, Standard-B review passed
> **Effort:** M (Phase A: S · Phase B: S)
> **Priority:** P2
> **Depends on:** none
> **Closes:** FEAT-NOTIFY-001 · CR-SVC-57 + CR-SVC-36 (duplicate) · CR-SVC-42 · CR-SVC-27 · CR-SVC-22 · CR-SVC-28 · CR-SVC-30 · CR-027-03 · CR-027-04

## 1. Problem Summary

Two independent, well-bounded work packages from [`backlog.md`](../../backlog/backlog.md):

**Phase A — FEAT-NOTIFY-001 (Task-Due Reminders):** ChoreStrike's daily
notification job only sends streak-at-risk reminders. Users get no proactive
"task X is due today/tomorrow" nudge, although the Nextcloud bell + mobile push
infrastructure (`NotificationService` → `ChoreStrikeNotifier`) is already in
place and proven (CS-PLAN-023). This was an explicit user request (2026-07-27).

**Phase B — Quick-Win Bundle (8 items):** eight small, mechanical backlog items
from the service reviews (2026-08-04 / 2026-08-11) and the CS-027 review
(2026-08-13), each XS–S, that individually don't justify a plan but bundle
cleanly (precedent: CS-PLAN-028):

| Item | Finding |
|------|---------|
| CR-SVC-57 (+CR-SVC-36 dup) | `calculateContributionScore` ignores its `$roomCleanliness` param (misleading signature) |
| CR-SVC-42 | `TestDataService::RESET_TABLES` still lists pre-release legacy table aliases (project-rules §1) |
| CR-SVC-27 | `notifyStreakAtRisk` objectId = bare streak number → NC dedup drops day 2's reminder |
| CR-SVC-22 | `RoomTaskService::removeAssignee` skips the membership guard its `addAssignee` sibling has |
| CR-SVC-28 | `SchedulingService::getUserPreferences` hardcodes `todayConsumedEnergy = 0` |
| CR-SVC-30 | `StreakRestockService::process`: one try/catch around the whole loop → one user's failure aborts the weekly restock |
| CR-027-03 | Wizard collects `homeType` but the apply payload ignores it (dead input) |
| CR-027-04 | Wizard preview shows floors/rooms but not the promised task count |

## 2. Strategy

**Phase A** mirrors the proven CS-PLAN-023 architecture 1:1: the job stays a
thin delegate; a new `TaskDueReminderService` holds the logic with an injected
date seam (`occ chorestrike:trigger-jobs --type daily-notification --as-of …`),
best-effort dispatch, and a stats array. Two new subjects
(`task_due_today` / `task_due_tomorrow`) are added to `NotificationService` +
`ChoreStrikeNotifier` using rich-subject parameters (task names are
user-controlled). Deduplication leans on Nextcloud's
`(app, user, objectType, objectId)` key with a **day-qualified objectId** —
directly applying the CR-SVC-27 lesson so a task reminded as "due tomorrow"
today is not swallowed by yesterday's notification or vice versa.

**Phase B** is eight surgical fixes, each with its regression test, ordered
backend-first. No schema changes, no API contract changes (only the internal
`removeAssignee` service signature gains a `houseId` param — pre-release, no
compat needed per project-rules §1).

### Decisions (D)

- **D1 — Recipients = assignees only.** A reminder goes to users assigned via
  `task_assignees` **or** the legacy `room_tasks.assigned_to_user_id` column.
  Unassigned tasks send nothing (they are visible in the dashboard daily list;
  broadcasting to the whole house would be spammy). Revisit only on user
  feedback.
- **D2 — No in-app per-category preference schema.** "Per-category
  preferences" (backlog wording) are satisfied by Nextcloud's built-in
  per-app notification settings (bell/mail/push per app in personal
  settings); ChoreStrike adds no own preference surface (KISS). The pass
  *does* respect scheduling `weekdayAvailability` (skip users unavailable on
  the due weekday), consistent with `StreakAtRiskService`.
- **D3 — Completed tasks need no explicit guard.** Completion advances
  `next_due` (recurring) or deactivates the row (one-time), so the due-date
  range query naturally excludes them. The residual race (complete-after-job-
  ran) is accepted — the reminder points at a task that *was* open at send
  time.
- **D4 — Global due query.** Jobs are instance-global (like
  `findAllForJobs()`); `RoomTaskMapper::findDueOnDate()` therefore has **no
  house scope**, documented as job-only in its PHPDoc.
- **D5 — CR-SVC-57: drop the parameter.** Contribution = energy cost is the
  documented, tested simplification; folding cleanliness in would change
  gamification payout behavior — out of scope for a quick win
  (if desired later: own backlog item + gamification review). The param and
  the misleading PHPDoc go; caller + tests updated. CR-SVC-36 is the same
  finding and closes with it.
- **D6 — CR-027-03: frontend-only effect.** `homeType` never needs a backend
  contract change: `apartment` pins the floor count to 1 and hides/disables
  the basement option; `house` restores the defaults. The input becomes
  effective without touching `applySetup`'s payload.
- **D7 — CR-027-04: compute counts client-side.** The catalog already ships
  all `templates` with their `roomTypes[]`; the wizard sums
  `templates.filter(t => t.roomTypes.includes(room.roomType)).length` per
  room and in total — no backend change. To mirror what `applySetup` really
  creates, the count must also (a) drop level-exclusive templates
  (`template.levels[intensityLevel] === null`) and (b) include the auto rooms
  that `HouseSetupService::injectAutoRooms()` adds server-side (one hallway
  per floor + the household room, per the `options` toggles) — otherwise the
  preview undercounts hallway/household tasks (both options default on) and
  overcounts on the relaxed level.

## 3. Backend / Frontend Changes

### Backend (`lib/`)

**Phase A:**

- `Db/RoomTaskMapper.php` — new `findDueOnDate(\DateTimeInterface $day): array`
  (range-comparison pattern from `findDailyTasks`, `is_active` filter, **no**
  house scope — D4).
- `Service/NotificationService.php` — new constants
  `SUBJECT_TASK_DUE_TODAY` / `SUBJECT_TASK_DUE_TOMORROW`, new
  `notifyTaskDue(string $recipientNextcloudUserId, string $taskName, int
  $roomTaskId, \DateTimeInterface $dueDate, bool $dueTomorrow): void` with
  objectId `'{roomTaskId}:{Y-m-d}'` on objectType `task_due`, where `{Y-m-d}`
  is the **pass run date** (day-qualified, D1/D3 dedup design). Run-date — not
  due-date — keying is what keeps a "due tomorrow" reminder sent on day D−1 and
  the "due today" reminder sent on day D as separate bell entries (§2 intent);
  same-day re-runs collapse on the same objectId.
- **new** `Service/TaskDueReminderService.php` — `process(\DateTimeInterface
  $today): array{processed: int, notifiedToday: int, notifiedTomorrow: int,
  skippedUnavailable: int}`: query due-today + due-tomorrow sets, batch-resolve
  assignees (`TaskAssigneeMapper::findUserIdsByRoomTasks` + collect
  `assigned_to_user_id`), resolve NC user IDs via
  `HouseUserMapper::findAllForJobs()` (single pass, map by NC user id), skip
  users unavailable on the due weekday (D2), dispatch per user × task.
  Task-name enrichment with a per-request cache loop (pattern:
  `RoomTaskService::getDailyTasksWithDetails`). Catch-all + stats like
  `StreakAtRiskService`.
- `Notification/ChoreStrikeNotifier.php` — two new `prepare()` cases with
  `setRichSubject` (task name as highlight param) + `setParsedSubject` /
  `setParsedMessage`.
- `BackgroundJob/DailyNotificationJob.php` — inject
  `TaskDueReminderService`; `run()` additionally calls
  `process($this->time->getDateTime())`.
- `Command/TriggerJobs.php` — `daily-notification` (and `all`) also runs the
  due pass with `$asOf`-derived "today".

**Phase B:**

- `Service/GameLogicService.php` — `calculateContributionScore(Task $task): int`
  (drop `$roomCleanliness`); update `handleTaskCompletion` call site (D5).
- `Service/TestDataService.php` — delete legacy aliases from `RESET_TABLES`
  (`chorestrike_chk_progress`, `chorestrike_task_parts`,
  `chorestrike_task_participants`, `chorestrike_task_claims`,
  `chorestrike_claims`, `chorestrike_chk_items`, … audit full list against the
  current migration chain).
- `Service/NotificationService.php` — `notifyStreakAtRisk` objectId becomes
  `'{Y-m-d}:{streak}'` (CR-SVC-27).
- `Service/RoomTaskService.php` — `removeAssignee(int $roomTaskId, string
  $userId, int $houseId)` + `assertHouseMembers([$userId], $houseId)`;
  `Controller/RoomTaskController.php` passes `$house->getId()`.
- `Service/SchedulingService.php` — move the
  `getConsumedEnergyForUserOnDate` call into `getUserPreferences`;
  `getUserPreferencesByNextcloudUserId` drops its duplicate re-computation.
- `Service/StreakRestockService.php` — try/catch inside the `foreach`,
  per-user continue + `failed` counter (mirror `StreakFreezeService`).

### Frontend (`src/`)

- `components/Setup/SetupWizard.vue`:
  - `homeType` watcher (D6): apartment → `floorCount = 1`, `basement = false`,
    basement checkbox hidden; house → defaults restored.
  - Preview step (D7): per-room task-count chip + grand total
    ("≈ {n} tasks will be created"), computed from
    `setupStore.templates`; `data-testid="wizard-preview-task-count"`.
- No other frontend surface (notifications arrive via the NC bell).

### Cross-layer contracts

- New notification subjects (backend-only contract with the NC bell/mobile
  clients; no HTTP API change).
- `setup/catalog` payload unchanged (D7); wizard apply payload unchanged (D6).

## 4. Tests

- **PHPUnit unit** (`tests/unit/…`, all files exist):
  - **new** `Service/TaskDueReminderServiceTest.php` — mirrors
    `StreakAtRiskServiceTest`: due-today/tomorrow dispatch, assignee-only
    recipients (both sources), weekday-availability skip, inactive tasks
    excluded, empty-assignee no-op, day-qualified objectId, stats shape.
  - `Service/NotificationServiceTest.php` — `notifyTaskDue` cases +
    **regression**: two `notifyStreakAtRisk` calls with different dates build
    different objectIds (CR-SVC-27).
  - `Notification/ChoreStrikeNotifierTest.php` — both new subjects render
    (rich + parsed) and unknown-subject still throws.
  - `BackgroundJob/DailyNotificationJobTest.php`,
    `Command/TriggerJobsTest.php` — both delegate the new service / run it
    under `daily-notification` + `all` with `--as-of`.
  - `Service/GameLogicServiceTest.php` — updated signature; completion
    result unchanged (contribution = energy cost).
  - `Service/RoomTaskServiceTest.php` — `removeAssignee` rejects non-members;
    happy path unchanged.
  - `Service/SchedulingServiceTest.php` — `getUserPreferences` returns the
    real consumed energy (not 0).
  - `Service/StreakRestockServiceTest.php` — one failing user no longer
    aborts the loop; others still restocked; `failed` counted.
  - `Service/TestDataServiceTest.php` — still green after alias removal.
- **Jest** — `tests/unit/components/SetupWizard.spec.js`: homeType→apartment
  pins floor count / hides basement; preview shows the per-room + total task
  count from catalog templates (expected values account for the level filter
  and auto rooms per D7).
- **Integration** — no new integration tests required (no SQL beyond the new
  mapper query; covered by unit-mocked mapper + existing real-DB mapper
  suites). `RoomTaskMapper::findDueOnDate` gets a real-DB regression test only
  if the existing mapper integration suite has a natural home for it —
  otherwise covered via `TaskDueReminderServiceTest` mocks (documented
  deferral in `## Review Outcome` if skipped).
- **E2E** — no new specs; the `Release:*` serial suite must stay green
  (notification subjects are not asserted in E2E today).

## 5. File Manifest

```
new:      lib/Service/TaskDueReminderService.php
new:      tests/unit/Service/TaskDueReminderServiceTest.php
modified: lib/Db/RoomTaskMapper.php
modified: lib/Service/NotificationService.php
modified: lib/Notification/ChoreStrikeNotifier.php
modified: lib/BackgroundJob/DailyNotificationJob.php
modified: lib/Command/TriggerJobs.php
modified: lib/Service/GameLogicService.php
modified: lib/Service/TestDataService.php
modified: lib/Service/RoomTaskService.php
modified: lib/Controller/RoomTaskController.php
modified: lib/Service/SchedulingService.php
modified: lib/Service/StreakRestockService.php
modified: src/components/Setup/SetupWizard.vue
modified: l10n/de.json + l10n/templates/chorestrike.pot (+ further l10n artifacts per i18n skill)
modified: tests/unit/Service/NotificationServiceTest.php
modified: tests/unit/Notification/ChoreStrikeNotifierTest.php
modified: tests/unit/BackgroundJob/DailyNotificationJobTest.php
modified: tests/unit/Command/TriggerJobsTest.php
modified: tests/unit/Service/GameLogicServiceTest.php
modified: tests/unit/Service/RoomTaskServiceTest.php
modified: tests/unit/Service/SchedulingServiceTest.php
modified: tests/unit/Service/StreakRestockServiceTest.php
modified: tests/unit/components/SetupWizard.spec.js
```

## 6. Implementation Order

1. **Phase B backend items** (independent, each with its regression test):
   CR-SVC-57 → CR-SVC-42 → CR-SVC-27 → CR-SVC-22 → CR-SVC-28 → CR-SVC-30.
   *Rationale: CR-SVC-27 touches `NotificationService` before Phase A extends
   it — the objectId fix lands first, Phase A then follows the corrected
   pattern.*
2. **Phase B frontend items:** CR-027-03 + CR-027-04 (+ Jest, l10n keys).
3. **Phase A:** mapper query → `NotificationService` subjects →
   `TaskDueReminderService` → notifier cases → job + command wiring → unit
   tests → l10n for the new subjects.
4. Full gate: `npm test` + `npm run lint` + `phpunit.unit.xml` +
   `phpunit.integration.xml` (Docker) + `cs:check` + `l10n:check-keys`.
5. Backlog moves: FEAT-NOTIFY-001 and the 8 CR items →
   `docs/backlog/archive/2026-08.md`; update `ACTIVE_TASKS.md`.

## 7. Risks & Mitigations

| Risk | Likelihood | Mitigation |
|------|-----------|------------|
| Reminder spam (job retries / clock skew double-run) | Medium | Day-qualified objectId → NC dedup collapses same-day duplicates (D3 design + CR-SVC-27 lesson); job interval 24 h unchanged |
| Timezone drift between server job time and user-local "today" | Medium | Use server time consistently (same as streak passes); single-tenant household = same TZ assumption is documented; not solvable per-user without TZ prefs — out of scope |
| `removeAssignee` signature change breaks an unknown caller | Low | Pre-release, no compat needed (project-rules §1); grep-verified single caller (`RoomTaskController:483`) |
| Legacy-table alias removal breaks a stale dev DB reset | Low | Test DBs are disposable (`down -v` + `up -v`); `reset()` already tolerates missing tables |
| Contribution-score signature change alters payouts | Low | Pure signature change; behavior covered by updated `GameLogicServiceTest` (contribution = energy cost) |
| Preview task count drifts from what `applySetup` really creates | Medium | Count is labeled "≈" (approximate); client count mirrors `applySetup`'s materialization (`roomTypes.includes` + level-exclusivity + `injectAutoRooms` rooms, see D7); E2E `release-setup-wizard` stays green |

## 8. Acceptance Criteria

**Phase A (FEAT-NOTIFY-001):**
- [x] `occ chorestrike:trigger-jobs --type daily-notification --as-of D` sends
      due-today (D) and due-tomorrow (D+1) reminders to assignees of matching
      active tasks, rendered localized (de) in the NC bell.
- [x] No reminder for unassigned tasks, unavailable users, or already-completed
      tasks (next_due advanced / inactive).
- [x] Re-running the pass for the same day sends no duplicates (objectId
      dedup).
- [x] Streak-at-risk reminders now survive consecutive days (CR-SVC-27
      regression test).

**Phase B:**
- [x] `calculateContributionScore(Task)` has no dead param; completion payouts
      unchanged (CR-SVC-57 + CR-SVC-36).
- [x] `RESET_TABLES` contains only canonical table names (CR-SVC-42).
- [x] `removeAssignee` validates membership like `addAssignee` (CR-SVC-22).
- [x] `getUserPreferences` reports real consumed energy (CR-SVC-28).
- [x] One failing user no longer aborts the weekly restock (CR-SVC-30).
- [x] Wizard: apartment → 1 floor, no basement; house → defaults (CR-027-03).
- [x] Wizard preview shows per-room + total task count (CR-027-04).

**Gate:**
- [x] Build gate green (`npm test` + `npm run lint` + PHPUnit unit +
      integration via Docker + `cs:check` + `l10n:check-keys`).
- [x] Durable concepts integrated into permanent docs: notification subject
      catalog + job pass documented in `docs/concepts/` (or
      `docs/engineering/`, wherever notifications are described today);
      CR-SVC-27's day-qualified-objectId rule noted next to the existing
      dedup remark in `NotificationService`/notifier docs.
- [x] `docs/backlog/ACTIVE_TASKS.md` updated; closed items moved to
      `docs/backlog/archive/2026-08.md`.
- [x] Standard-B review (`/review-chorestrike`) of the change set.

## Review Outcome

> **Standard-B review (2026-08-17)** — scope: the full uncommitted CS-PLAN-030
> change set (31 modified + 2 new files; diffs re-read end-to-end against the
> shared checklist).
>
> **Result: no blocking / major / minor findings.** Checklist clean:
> architecture (thin controllers — `removeAssignee` controller only passes
> `$house->getId()`; logic in services; `findDueOnDate` uses only
> `createNamedParameter`), authorization (`@NoAdminRequired` only; membership
> validated server-side, client userIds never trusted), security (no user
> content pre-interpolated into parsed subjects; task names ride as rich
> params; no PII in logs), KISS/minimal diffs, no workarounds/suppressed
> warnings, i18n (all new strings via `t()`; en/de/pot updated in sync, no
> duplicate keys; `l10n:check-keys` green), tests (regression test per CR
> item + new service coverage + real-DB mapper test).
>
> **Auto-fix applied (1):** `TestDataService` RESET_TABLES docblock —
> "FK dependency order" → "logical dependency order" (the schema declares
> indexes, not DB-level foreign keys). Comment-only; `cs:check` + `php -l`
> re-verified.
>
> **Report-only notes:**
> - `TaskDueReminderService::resolveAssignees` mirrors the ~8-line
>   batch+merge loop of `RoomTaskService::getAssigneeUserIdsByRoomTasks`
>   (plan-specified mapper-level design; avoids coupling the job service to
>   the CRUD service; rule of three not met).
> - `DailyNotificationJob::run` passes the factory's DateTime instance
>   uncloned to the due pass — safe (the service never mutates its input;
>   covered by `testRun_DoesNotMutateTheFactoryDateTime`).
> - Timezone handling follows the plan-accepted server-time assumption (§7).

## Implementation Notes (2026-08-17)

All Phase A/B items implemented per §3/§6 with their regression tests. Gate:
PHPUnit unit **714** / integration **156** (24 pre-existing skips) / Jest
**701** / lint + stylelint (0 errors) + `cs:check` + `l10n:check-keys` clean /
E2E `Release:*` serial **16/16** (`chromium-serial-db`, `--retries=0`).

Deviations / audit outcomes beyond the letter of §3 (all within its intent):

- **CR-SVC-42 audit:** `RESET_TABLES` also **gained** the canonical
  `chorestrike_task_assignees` (missing before, FK-safe delete order), beyond
  removing the six legacy aliases.
- **CR-SVC-57 (D5):** `handleTaskCompletion` keeps its `$roomCleanliness`
  parameter — its contract is pinned by `FamilyIntegrationTest` and changing
  it would exceed the plan's "no API contract changes" scope. Its PHPDoc now
  states the param is completion context only, not payout input.
- **CR-SVC-27:** `notifyStreakAtRisk` day-qualifies on the pass's injected
  reference day (`{pass-day Y-m-d}:{streak}`), which uniquely identifies each
  pass — semantically equivalent to run-date keying and testable via the
  existing time seam.
- **Integration test:** `RoomTaskMapper::findDueOnDate` DID get a real-DB
  regression test (`RoomTaskMapperDailyTest::testFindDueOnDate_…`) — the
  natural home existed, so no deferral.
- **E2E env note:** the `Release:*` run needed the family identities
  re-provisioned via `occ user:add` (the long-running container had been
  recreated with `TEST_DB_PROFILE=minimal` at some point) — environment
  state, not a code issue.
