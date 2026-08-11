# Backlog — Open Items

> **Single source of truth for everything not yet done.** Bugs, features,
> tech-debt, and review follow-ups are consolidated here (the old `bugs.md` /
> `features.md` / `phase-9-*` / `phase-8-*` files are dissolved).
> **Done work does not live here** — completed items move to
> [`archive/YYYY-MM.md`](archive/) at completion. See [`README.md`](README.md)
> for the workflow and [`docs/standards/ai/rules/project-rules.md` §4](../standards/ai/rules/project-rules.md)
> for the rules.
>
> Priority: **P0** critical · **P1** high · **P2** should-have · **P3** nice-to-have · **P4** future.

---

## Bugs

### [P3] BUG-SEC-001 — Dedicated security reporting address + GPG key
**Status**: Backlog · **Effort**: S · **Source**: CS-001 security review

`SECURITY.md` points reporters to the Codeberg user profile (no private advisory
feature). A dedicated `security@` address + published GPG key would make private,
encrypted reporting frictionless.

- [ ] Dedicated security contact published in `SECURITY.md` + Codeberg profile.
- [ ] GPG public key published; fingerprint referenced in `SECURITY.md`.
- [ ] `SECURITY.md` "maintainer note" removed once the channel exists.

---

### [P3] BUG-USER-001 — `GET /api/v1/users/{id}/stats` has no frontend consumer (dead surface)
**Status**: Backlog · **Effort**: S · **Source**: CS-007 review (2026-07-23)

`user#stats` (`UserController::stats()`) is registered + functional (the
`getTeamSparks()`→`getCoins()` bug was fixed in CS-007) but no Pinia store calls
it; stats are sourced from `/game/state` + `/house/stats`. The separate
`UserStatsController` (8 public methods) is **entirely unrouted** = dead code.

- [ ] Decide: wire a frontend consumer (profile/detail view) **or** remove the
      dead route + method + the unrouted `UserStatsController`.

---

### [P3] BUG-TEMPLATE-001 — `TemplateService::bulkImport` silently swallows exceptions
**Status**: Open · **Effort**: XS · **Source**: CS-022 review (2026-07-30)

`TemplateService::bulkImport()` (`lib/Service/TemplateService.php`) catches
`\Exception` with the comment "Log error and continue" but never logs — violates
project-rules §3 ("Never swallow exceptions silently"). Pre-existing, not
introduced by CS-022.

- [ ] Add an `AppLogger` warning/error for each skipped template ID in the catch block.

---

### [P3] BUG-ROOM-001 — Re-evaluate the `RoomController::index` POST→GET rewrite workaround
**Status**: Deferred (low) · **Effort**: S · **Source**: CS-020 (deferred AC)

The body-sniffing POST→GET rewrite workaround was left as harmless dead code
after the Docker env got correct `mod_rewrite` routing (`docker/apache-nextcloud.conf`).
Confirm whether production shapes still need it; if not, remove it.

- [ ] Confirm production routing; remove the workaround if no longer needed.

---

### [P1] BUG-UI-001 — Overdue/priority colors hardcoded, unreadable in Dark Mode
**Status**: Open · **Effort**: S · **Source**: Manual test 2026-07-30

Several overdue / priority / status styles use hardcoded light hex colors that
flip to near-white in Nextcloud Dark Mode → white-on-white text. `CalendarView.vue`
already uses `var(--color-error-hover)` correctly; the rest must follow.

- [ ] `src/components/Tasks/TaskList.vue` L526–529 (`.task-item.overdue { background: #fdf2f2 }`)
      and priority badges L605–618 (`#d5f4e6`/`#fef9e7`/`#fdedec` + hardcoded text) →
      `--color-error-hover` / `--color-success-hover` / `--color-warning-hover` + `--color-error/-success/-warning`.
- [ ] `src/components/Tasks/FlexWindowDisplay.vue` L298–303, L350–363 (status-early/overdue) → same variables.
- [ ] `src/components/Dashboard/RoomCard.vue` L431–438 (`#F44336`/`#FF9800`) → `--color-error`/`--color-warning`.
- [ ] Verify visually in Light + Dark; add a theme-consistency unit assertion where feasible.

---

### [P1] BUG-I18N-001 — Frontend stays English even when Nextcloud user language is German
**Status**: Open · **Effort**: S · **Source**: Manual test 2026-07-30

ChoreStrike has no own language switch; language comes from the Nextcloud user
setting. Backend (`IL10N`) honors it, but the Vue frontend relies on Nextcloud's
`OC.L10N` injection and there is **no `registerTranslations()` in `main.js`** and
the `l10n/de.json` is not pulled into the bundle. In dev-mount / stale-cache
setups `translate()` falls back to the English source string.

- [ ] Add `registerTranslations('chorestrike', de.translations)` in `src/main.js`
      (import `l10n/de.json`) as a defense-in-depth fallback.
- [ ] Confirm on the personal server: app enabled via `occ app:enable chorestrike`
      (not just volume/symlink) so Nextcloud injects the bundle; hard-refresh.
- [ ] Jest: extend the `@nextcloud/l10n` mock + a spec asserting a registered
      translation is returned by `translate()`.

See also FEAT-I18N-001 (German default) — same change set.

---

## Features

### [P2] FEAT-I18N-001 — German as default language
**Status**: Planned · **Effort**: XS · **Source**: User request 2026-07-30

German should be the default for users who have not chosen a language (manual
testing happens primarily in German for the user's children).

- [ ] Add `<default_language>de</default_language>` to `appinfo/info.xml`.
- [ ] Document that the personal Nextcloud server's `config.php` should also set
      `'default_language' => 'de'` (server-global fallback) in `docs/engineering/deployment.md`.
- [ ] Per-user override remains available (Nextcloud's user language setting is not removed).

---

### [P2] FEAT-NOTIFY-001 — Task-Due Push Reminders
**Status**: Planned · **Effort**: S · **Source**: User request 2026-07-27

Extend `DailyNotificationJob` / `NotificationService` to send "task X is due
today / tomorrow" via the Nextcloud bell + mobile push (no CalDAV needed — the
NC notification system already delivers to mobile).

- [ ] New subjects `task_due_today`, `task_due_tomorrow`; localized rich params.
- [ ] `DailyNotificationJob` (or dedicated job) queries due-today/tomorrow per user.
- [ ] Respect per-category notification preferences; no duplicate on completed tasks.
- [ ] Unit tests for job + notifier rendering.

---

## Tech Debt / Code Quality

### [P2] TECH-LINT-001 — Stylelint config reports "Unknown rule" for every file (stylelint 16.x mismatch)
**Status**: Open · **Effort**: S · **Source**: discovered during BUG-UI-001 (2026-07-30)

`npm run stylelint` fails with 181 "Unknown rule" errors across **every** CSS/Vue
file (incl. untouched `tokens.css`, `mobile.css`). The referenced rules
(`indentation`, `number-leading-zero`, `string-quotes`, `selector-list-comma-newline-after`)
were removed as built-ins in Stylelint 15/16 and must now come from a SCSS/Vue
plugin config. `@nextcloud/stylelint-config` references them as if still built-in.
This is a config/versions mismatch, **not** introduced by any individual change.

- [ ] Reconcile `@nextcloud/stylelint-config` with the installed `stylelint@16`:
      either pin a compatible stylelint version or add the required
      plugin configs so `npm run stylelint` runs clean.
- [ ] Re-enable stylelint in CI once green (currently `npm run lint` / `npm test`
      are the effective gate; stylelint is in the pre-deployment checklist only).

---

### [P2] BUG-API-DAILY-001 — `GET /api/v1/tasks/daily` 500s: `DATE(rt.next_due)` used as a column in WHERE
**Status**: Open · **Effort**: S · **Source**: discovered during CS-PLAN-023 E2E verification (2026-08-04)

`TaskController::daily()` → `RoomTaskService::getDailyTasksWithDetails()` fails
with `SQLSTATE[42S22] Column not found: 1054 Unknown column 'DATE(rt.next_due)'
in 'WHERE'` — a `DATE(...)` function expression is compared as if it were a
column. The endpoint has been silently 500ing (route-shadowed by
`/tasks/{id}` until CS-PLAN-023 un-shadowed it, which surfaced the SQL bug).
The frontend daily view degrades to an empty state, so no UI crash hid it.

- [ ] Fix the query builder expression (compare `rt.next_due` against the
      day's `[start, end)` range like `CompletedTaskMapper::countByUserOnDate`).
- [ ] Regression test (mapper unit test + integration smoke).

---

### [P3] TECH-TEST-002 — E2E regression suite covering the automatable part of CS-017
**Status**: Planned · **Effort**: L (grown over releases) · **Source**: User request 2026-07-30 · **Plan**: [`docs/plans/new/cs-plan-024-release-e2e-regression-suite.md`](../plans/new/cs-plan-024-release-e2e-regression-suite.md)

CS-017 is deliberately manual (exploratory UX, mobile feel, multi-user social
flows). Build a companion E2E regression suite that fills the "covered by E2E?"
markers in `release-test-plan.md`, so the manual pass per release shrinks to the
genuinely non-automatable items. Anti-rot rule already in CS-017 §7.

- [ ] Map each `release-test-plan.md` section to "automated / manual / partial".
- [ ] Promote partial items to full Playwright specs (full task lifecycle incl.
      skip/reschedule/recurrence/checklist, overdue-claim + teamwork, visibility filtering).
- [ ] Notification regression depends on TECH-TEST-001's occ trigger.
- [ ] Update `release-test-plan.md` markers as specs land.

---

### [P3] TECH-CLEANUP-ITIMEFACTORY — Normalize the two `ITimeFactory` interfaces
**Status**: Backlog · **Effort**: S · **Source**: CS-023 review (2026-07-30)

`TaskSyncService` imports `OCP\ITimeFactory` while the three BackgroundJobs import
`OCP\AppFramework\Utility\ITimeFactory`; two mock files exist under
`tests/__mocks__/OCP/`. Pre-existing inconsistency. Normalize to one interface
(and drop the redundant mock) next time `TaskSyncService` is touched.

---

### [P2] P9-E2E-002b — E2E long-tail `waitForTimeout` cleanup
**Status**: Backlog · **Effort**: M–L · **Source**: carved out of CS-015

~13 specs still carry ~27 `waitForTimeout` calls + residual text/positional
selectors (`multi-user-interactions`, `smoke-tests`, `minimal-smoke-tests`,
`daily-tasks-flow`, `room-management`, + 1-offs). Migrate to
`getByTestId`/`getByRole` + auto-waiting. Prerequisite met: CS-018 proved the
suite flake-free (3× full-matrix `--retries=0`).

---

### [P2] P9-DOC-005 — `development.md` example-code audit
**Status**: Open · **Effort**: S · **Source**: CS-014 review (2026-07-26)

Two deferred example-code drifts: (1) a fictional
`TaskService::calculateNextDue(Task): \DateTime` in the coding-standards example
(no such method — scheduling is owned by `SchedulingService`/`TaskSchedulerService`
/`SmartSchedulerService`/`lib/Service/Scheduling/`); (2) the "new API endpoint"
example shows raw `axios.post('/apps/ChoreStrike/...')`, contradicting the
canonical `src/services/api.js` client enforced by `apiContract.spec.js`.

- [ ] Replace/drop the fictional `calculateNextDue` example; rewrite the endpoint
      example to use the shared `api` client.

---

### [P2] P9-CONFIG-001 — Align PHP minimum version (8.0 vs 8.1)
**Status**: Open · **Effort**: XS · **Source**: CS-014 review (2026-07-26)

`info.xml` declares `<php min-version="8.0" max-version="8.3"/>`, `composer.json`
requires `^8.0`, but `AGENTS.md` says "PHP 8.1+". Decide the real minimum, then
align all three (CI pins PHP 8.2 for `composer validate`).

---

### [P4] P9-CONFIG-002 — Node `punycode` DEP0040 deprecation (informational)
**Status**: Tracked no-action (external) · **Effort**: 0 · **Source**: CS-018 baseline

Jest emits a `punycode` deprecation (Node DEP0040), surfaced transitively by a
dependency — no first-party import, does not affect results. Re-surface only if
it becomes an error in a future Node major.

---

## Review Follow-ups — CS-016 (Scheduling UI)

> From [`archive/CODE_REVIEW_2026-07-28.md`](archive/CODE_REVIEW_2026-07-28.md).
> CR-016-11 is **resolved** (CS-018); the rest are open.

### [P2] CR-016-01 — Regenerate `l10n/templates/chorestrike.pot`
**Effort**: S — `.pot` not regenerated (~90 scheduling keys missing). Decide on
an `l10n:extract` tool; `chorestrike.pot` must contain every `t()` string;
`l10n:check-keys` stays green.

### [P2] CR-016-02 — Batch the scheduling save endpoints
**Effort**: M — `AvailabilitySettings.saveAvailability` (≤14 calls) +
`EnergyBudgetSettings` (7) save per-day sequentially. Add a bulk `PUT` for a
full week; call once; loading flicker gone.

### [P2] CR-016-10 — Regression test for `getConsumedEnergyForUserOnDate` SQL fix
**Effort**: S — mock-based mapper test cannot catch SQL errors; needs a real-DB
mapper/integration test (0 with no completions; >0 seeded). Unblock the
integration harness first (`AvailabilityApiTest` `Class OC not found`).

### [P2] CR-016-12 — Non-owner availability editing surface
**Effort**: M — the member-availability grid is owner-only and the edit button
only renders on the current user's card, so non-owners have **no UI to toggle
their own weekday availability** (only energy budget). Self-availability section
driven by `getPreferences` + self-service `updateAvailability`; E2E for the
non-owner path.

### [P3] CR-016-03 — Split oversized scheduling components
**Effort**: M — `AvailabilitySettings.vue` (694) + `EnergyBudgetSettings.vue`
(584) exceed the 300-line guideline (>2×). Extract edit modal + budget analysis.

### [P3] CR-016-04 — DRY scheduling constants & helpers
**Effort**: S — `weekDays`, `ENERGY_MIN/MAX`, `isMemberAvailable`/`getMemberEnergy`,
template defs duplicated across 5 components → shared
`src/constants/scheduling.js` + helpers.

### [P3] CR-016-05 — Merge SchedulePreview + SchedulingSuggestions
**Effort**: M — near-identical suggestion-loading + display logic → one canonical
component (or shared composable).

### [P3] CR-016-06 — Locale-aware date formatting in ScheduleCalendar
**Effort**: S — `weekRange` uses bare `toLocaleDateString()` (system-locale
dependent). Use Nextcloud's date formatter or explicit translated names.

### [P3] CR-016-09 — `DailyTaskList.vue` tech debt (pre-existing)
**Effort**: S — bypasses the API service (raw `axios`), dead ternary, empty
`rescheduleTask` stub, missing `data-testid`s. Route through store/API; fix stubs.

### [P3] CR-016-13 — Scheduling dead code + oversized suggestion payload
**Effort**: S — (a) `SchedulingService::updateMemberAvailability(int…)` no longer
called (dead, kept alive by its test); (b) store action `updateMemberAvailability`
has no UI consumer; (c) `getSuggestions` sends the full task array though backend
only uses `count($tasks)` → send `{ taskCount }`.

### [P3] CR-016-14 — AvailabilitySettings save guard + unreachable loading states
**Effort**: S — (a) no `hasChanges` guard (Save with no edits fires ≤14 calls);
(b) per-component `loading` blocks are unreachable dead UI (components mount only
inside the view's `v-else`, loading=false).

### [P4] CR-016-08 — Convert `SchedulingView` to `<script setup>`
**Effort**: S — uses Options API while all 5 children use `<script setup>`.

---

## Review Follow-ups — Full Review of `lib/Service` (2026-08-04)

> From [`archive/CODE_REVIEW_2026-08-04.md`](archive/CODE_REVIEW_2026-08-04.md).
> Full Review (4 parallel reviewer subagents) of all 29 `lib/Service` files. Three
> minor findings were **auto-fixed** this review (gate green); the rest are open.

> **Resolved 2026-08-09 by [CS-PLAN-025](../plans/active/cs-plan-025-service-security-concurrency-cleanup.md)**
> (record: [`archive/2026-08.md`](archive/2026-08.md)):
> the 2 P0 + 6 P1 items **CR-SVC-01/02/03/04/05/06/07/12** and the dead-cluster
> delete **CR-SVC-08** landed. The remaining open items below (CR-SVC-09/10/11/…,
> P2/P3) stay here.
>
> **Reopened 2026-08-09 by the CS-025 implementation review:** **CR-SVC-04**
> plus follow-ups **CR-SVC-45/46/47** — all four **resolved 2026-08-10**
> (record: [`archive/2026-08.md`](archive/2026-08.md)).
> The review's smaller follow-ups **CR-SVC-48…52** stay open below.

### [P2] CR-SVC-48 — Reverse-sync input validation + undocumented deviations
**Status**: Open · **Effort**: S · **Source**: Review of CS-PLAN-025 (CR-SVC-07)
`TaskSyncService::syncCompletionFromNextcloud` passes the client-controlled
`$completedAt` straight into `new \DateTime(...)` — a malformed string surfaces
as a 500 (generic catch) instead of a 400 VALIDATION_ERROR, and an attacker-
supplied far-future date pushes `next_due` out indefinitely (house-scoped, no
reward impact). The reverse path also skips the room-cleanliness update; the
no-reward deviation is documented, the cleanliness deviation is not.
- [ ] Validate `$completedAt` at the controller boundary (400 on malformed /
      unreasonable dates); document or close the cleanliness deviation.

### [P3] CR-SVC-49 — `safeMessage()` defeats the dedicated `InvalidArgumentException` 400 catches
**Status**: Open · **Effort**: S · **Source**: Review of CS-PLAN-025
`ControllerErrorFormatter::safeMessage()` intentionally excludes
`InvalidArgumentException`, so the 400 catches that route through it return the
generic "An unexpected error occurred" instead of the curated validation message.
Fixed for the two CS-025-introduced catches (`MinigameController::saveScore`,
`RoomTaskController::create`) by returning `$e->getMessage()` directly; the same
misuse remains at `MinigameController:307`, `RoomTaskController:335,388`,
`SchedulingController:113,159,234`, `TemplateController:275`.
- [ ] Surface curated messages at the remaining sites (or add a
      `validationMessage()` helper), auditing each message for client-controlled
      echo before exposing it.

### [P3] CR-SVC-50 — Extra queries inside the `house_user` lock (critical section)
**Status**: Open · **Effort**: S · **Source**: Review of CS-PLAN-025 (CR-SVC-04)
`GameLogicService::handleTaskCompletion` runs `roomTaskMapper->find($roomTaskId)`
after `findForUpdate` although `RoomService::completeRoomTask` already holds that
entity, and calls `findRecentByUserId` twice (limit 5, then 10) when combo. Both
add round-trips inside the new row lock; the plan's risk table promised a short
critical section.
- [ ] Pass the loaded `RoomTask` into `handleTaskCompletion` (or fetch before
      the lock); fetch the last 10 completions once and slice for the combo check.

### [P3] CR-SVC-51 — `MinigameController::saveScore` recomputes the coin formula
**Status**: Open · **Effort**: S · **Source**: Review of CS-PLAN-025 (CR-SVC-05 adjacent)
The controller recomputes `coinsEarned = floor($score/10)` from raw client input
for the response, duplicating `MinigameService::saveScore` — and diverging when
the house user is missing (service awards 0, response reports >0).
- [ ] Have `saveScore` return the awarded coins; controller only shapes the response.

### [P3] CR-SVC-52 — CR-SVC-12 unit test does not assert dispatch-after-commit ordering
**Status**: Open · **Effort**: S · **Source**: Review of CS-PLAN-025
`RoomServiceTest::testCompleteRoomTask_NewTitleInGameResult_DispatchesTitleNotificationAfterCommit`
asserts `notifyTitleUnlocked` is dispatched, but with the mocked `IDBConnection`
a dispatch **before** commit would pass too — the ordering guarantee (the point
of CR-SVC-12) is untested.
- [ ] Record the call order (commit callback appends to a sequence log) and
      assert `notifyTitleUnlocked` runs after the commit entry.

> **2026-08-11 — Full Review of the 4 active plans** (CS-017/022/023/025; 4 parallel
> `reviewer` subagents + gate re-verification; record:
> [`CODE_REVIEW_2026-08-11.md`](CODE_REVIEW_2026-08-11.md)). Gate re-verified green on
> the uncommitted CS-025 reopened-items tree + 1 auto-fix (`TaskSyncService::setSparksEarned`
> dead line → `setCoinsEarned`): PHPUnit unit 690 / integration 150 (24 pre-existing
> skips) / Jest 677 / ConcurrentCompletionTest 2/2 (incl. the 2.1 s real-lock proof) /
> ESLint clean. **One auto-fix applied**, **one new [P1] concurrency finding
> (CR-SVC-53)** surfaced, and the minor perf/quality follow-ups below (CR-SVC-54..58).

### [P1] CR-SVC-53 — `RoomService::completeRoomTask` operates on a stale `RoomTask` after `lockForUpdate`
**Status**: Open · **Effort**: S · **Source**: Full Review 2026-08-11 (CS-PLAN-025 concurrency slice)
`RoomTaskMapper::lockForUpdate()` returns `void`; `RoomService::completeRoomTask`
fetches `$roomTask` at `:276` (before `beginTransaction()` at `:293`), locks at `:295`,
but never refreshes the entity. Every post-lock decision then runs on the pre-lock
snapshot: `isOverdue()` (`:331`), `markClaimed()` (`:334`), and `markCompleted()`
(`:354`, which derives recurrence from a stale `next_due`/`idealDueDate`). This is the
exact freshness asymmetry `HouseUserMapper::findForUpdate` was hardened to avoid (it
returns the freshest committed row in one `SELECT … FOR UPDATE`); `lockForUpdate` was
hardened to the self-assigning-UPDATE defect class but the fix was **not** threaded to
the caller. Under READ COMMITTED, a concurrent winner completing the same overdue task
advances `next_due`/clears overdue state, but this transaction still sees the stale
overdue state → **spurious overdue-takeover notification** + claim state from a stale
snapshot + recurrence computed from a stale `next_due`. Pre-existing (lockForUpdate
predates CS-025), but surfaced by this review of the completion-path concurrency — the
sibling gap to CR-SVC-04. No integration test covers `RoomTaskMapper::lockForUpdate`
(only `HouseUserMapper::findForUpdate` has the two-process proof).
- [ ] Make `lockForUpdate` return a fresh `RoomTask` via `SELECT … FOR UPDATE` on
      locking engines (mirror `findForUpdate`), or re-fetch `$roomTask` after the lock;
      add a two-connection integration test analogous to `ConcurrentCompletionTest`.

### [P3] CR-SVC-54 — `MinigameService::saveScore` coin award has no row lock/transaction
**Status**: Open · **Effort**: S · **Source**: Full Review 2026-08-11
`MinigameService::saveScore` (`:75-86`) runs the score insert + the `house_user` coin
increment (`addCoins` + `update`) without a transaction or `findForUpdate` — the same
lost-update class CR-SVC-04 fixed for task completion. Two concurrent minigame
submissions for the same user can clobber each other's coin increment, and a partial
failure between `insert()` and `update()` leaves a score row with no coins.
- [ ] Wrap in a transaction and call `HouseUserMapper::findForUpdate` before the coin
      increment (or document the deferral with a reason).

### [P3] CR-SVC-55 — `StreakFreezeService::process` per-user N+1
**Status**: Open · **Effort**: S · **Source**: Full Review 2026-08-11
The daily loop calls `completedTaskMapper->countByUserOnDate($user->getId(), $yesterday)`
once per house user (`StreakFreezeService.php:81`) — N queries for N users. The sibling
`StreakAtRiskService` was already optimized to a single GROUP BY
(`countByUserOnDateGrouped`, `StreakAtRiskService.php:53`); `StreakFreezeService` was not.
- [ ] Reuse `countByUserOnDateGrouped` and look up each user's count from the map.

### [P3] CR-SVC-56 — `MinigameService::getLeaderboard` N+1 display-name enrichment
**Status**: Open · **Effort**: S · **Source**: Full Review 2026-08-11
`getLeaderboard` (`:96`) and `getWeeklyLeaderboard` (`:122`) call
`findByHouseIdAndNextcloudUserId` per score row to enrich `displayName` — N extra
queries for an N-entry leaderboard (default limit 10 → 10 round trips per fetch).
- [ ] Add a batch lookup (`findByHouseIdAndNextcloudUserIds`) or a JOIN in
      `MinigameScoreMapper::getLeaderboard` that returns `displayName` alongside the rows.

### [P3] CR-SVC-57 — `GameLogicService::calculateContributionScore` ignores its `$roomCleanliness` parameter
**Status**: Open · **Effort**: XS · **Source**: Full Review 2026-08-11
`calculateContributionScore(Task $task, int $roomCleanliness)` (`:184`) has a comment
"Simplified: contribution = energy cost" and never reads `$roomCleanliness`. Dead
parameter on a public method is a maintenance trap (callers reasonably assume
cleanliness affects the score).
- [ ] Use `$roomCleanliness` in the formula, drop the parameter, or mark `@deprecated`
      with a TODO referencing the simplification.

### [P3] CR-SVC-58 — `phpunit.unit.xml` Entity/Mapper suites double-run 5 mapper tests
**Status**: Open · **Effort**: XS · **Source**: Full Review 2026-08-11 (CS-PLAN-022 D2)
The 5 newer mapper tests (`TaskAssignee`, `TaskChecklistItem`, `RoomTaskChecklistProgress`,
`MinigameScore`, `AvailabilitySettings`) are listed in the `Mapper` suite's `<file>` set
but **not** in the `Entity` suite's `<exclude>` set, so the `Entity` suite's
`<directory>tests/unit/Db</directory>` glob matches them too — they execute in **both**
suites. The directory+exclude+file-list pattern already drifted once (CS-022); harmless
(same result twice) but wasteful and fragile.
- [ ] Split by subdirectory (`tests/unit/Db/Entity/` + `tests/unit/Db/Mapper/`), each
      suite globbing its own dir; delete the exclude list so the split can't silently drift.

### [P2] CR-SVC-09 — Harden `RoomTaskService::assignTask`/`bulkAssignTasks` internally
**Status**: Open · **Effort**: S · **Source**: Review of `lib/Service` (2026-08-04)
The HTTP path validates today, but the public methods do not (`:228,:502`);
`bulkAssignTasks` also logs the userId in plaintext (`:513`). One new caller that forgets
the preamble = cross-house assignment / arbitrary-user write.
- [ ] Require `houseId` + assert membership + room-task ownership inside `assignTask`
      (mirror `setAssignees`/`addAssignee`); stop logging the raw userId.

### [P2] CR-SVC-10 — `completeRoomTask` membership re-check + null safety
**Status**: Open · **Effort**: S · **Source**: Review of `lib/Service` (2026-08-04)
Ordinary (non-private, non-admin-only) tasks have no membership re-check inside the
transaction (`RoomService.php:280`); a null `$houseUser` yields a 500, not a clean 403.
- [ ] Assert house membership for `$userId` at the top of the transaction; throw
      `ForbiddenException` if `$houseUser === null`.

### [P2] CR-SVC-11 — N+1 query cluster across services
**Status**: Open · **Effort**: L · **Source**: Review of `lib/Service` (2026-08-04)
Pervasive per-row mapper calls in loops. Hot spots: `HouseService::calculateHouseHealth`/
`getHouseStats`/`resetHouse`; `GameLogicService::checkAndAwardMedals`/`getMedals`;
`FloorService::getFloorsWithRooms`/`reorderFloors`; `RoomService::getRoomWithTasks`/
`deleteRoom`; `RoomTaskService::getDailyTasksWithDetails`/`getRangeTasks`/`bulkAssignTasks`;
`MinigameService` leaderboards; `TaskSyncService::getPendingSyncsForHouse`;
`StreakFreezeService::process`.
- [ ] Add batch mapper queries (`findByHouseId`, `findByIds` IN, JOINs, grouped counts);
      replace per-id `find` loops with set-based lookups. (Scheduler-cluster N+1s were
      retired by the CR-SVC-08 deletions in CS-PLAN-025.)

### [P2] CR-SVC-13 — Migrate raw `LoggerInterface` services to `AppLogger`
**Status**: Open · **Effort**: M · **Source**: Review of `lib/Service` (2026-08-04)
`NotificationService`, `StreakAtRiskService`, `StreakFreezeService`, `StreakRestockService`
inject raw `Psr\Log\LoggerInterface` instead of `AppLogger` (project-rules §2), bypassing
sensitive-key sanitization; ~16 services have no logger at all.
- [ ] Inject `AppLogger` in the 4 services; route dispatch/process failures through it.

### [P2] CR-SVC-15 — Decompose God-methods / God-services
**Status**: Open · **Effort**: L · **Source**: Review of `lib/Service` (2026-08-04)
Over the ~300-line/~30-line guidelines: `RoomService::completeRoomTask` (~200 lines),
`GameLogicService` (517, 7 concerns), `handleTaskCompletion` (~113) /
`checkAndAwardMedals` (~62, 5 near-identical award blocks), `RoomTaskService` (833),
`RewardConfigService` (607), `TaskService::createTask` (24 positional params).
- [ ] Extract cohesive steps (overdue-takeover, post-commit notification dispatch,
      medal-rules table) and split GameLogicService along domain lines.

### [P2] CR-SVC-16 — `RewardConfigService` registry refactor (DRY)
**Status**: Open · **Effort**: M · **Source**: Review of `lib/Service` (2026-08-04)
Every config key is touched in 4-5 places (const + get + set + `updateConfig` branch +
`getAllConfig` entry); `updateConfig` mirrors `getAllConfig`.
- [ ] Drive get/set/update/all from a single `key => [type, default, clamp]` registry.

### [P3] CR-SVC-17 — `TaskService::updateTask` missing task-visibility matrix
**Effort**: S — asymmetric with `deleteTask`; `ownerUserId` is whitelisted so a private
task can be silently re-assigned away from its owner. Mirror `deleteTask`'s caller/owner
checks.

### [P3] CR-SVC-19 — `RewardConfigService` inconsistent clamping
**Effort**: S — several setters/getters return raw values (tier multiplier, coin base,
freeze cap/count, early-bonus) that can invert rewards (negative multiplier). Admin-gated.
Clamp to `>= 0`/`>= 1` consistently.

### [P3] CR-SVC-21 — `TaskSyncService` inconsistent house-scoping + unscoped public mutators
**Effort**: S — uses `room->getHouseId()` directly while the authoritative path resolves
`room->floor->house`; the non-`ForHouse` variants (`completeTask`, `linkTask`,
`markAsSynced`, `getPendingSyncs`) are unscoped public mutators. Make them private,
expose only the `*ForHouse` wrappers.

### [P3] CR-SVC-22 — `RoomTaskService::removeAssignee` unguarded
**Effort**: XS — inconsistent with `addAssignee`; require `houseId` + validate membership
+ room-task ownership.

### [P3] CR-SVC-23 — `MinigameService::purchaseBoost` lost-update
**Effort**: S — read-check-deduct with no lock; concurrent purchases can pay once for two
boosts. Wrap in a transaction with `SELECT … FOR UPDATE` on the `house_user` row.

### [P3] CR-SVC-26 — `removeUserFromHouse` leaves orphaned assignee references
**Effort**: S — root cause of the defensive re-check at `RoomService.php:419`. On removal,
null `room_tasks.assigned_to_user_id` and delete `task_assignees` rows for the user.

### [P3] CR-SVC-27 — `notifyStreakAtRisk` objectId not day-qualified
**Effort**: XS — Nextcloud dedups on `(app,user,objectType,objectId)`; using the bare
streak number drops the second day's reminder. Qualify with the date.

### [P3] CR-SVC-28 — `SchedulingService::getUserPreferences` stale consumed energy
**Effort**: XS — hardcodes `todayConsumedEnergy=0` (only the `…ByNextcloudUserId` variant
computes the real value). Compute it in both, or drop the field from this variant.

### [P3] CR-SVC-30 — `StreakRestockService::process` non-resilient loop
**Effort**: XS — one try/catch wraps the whole loop; a single user's failure aborts the
weekly restock. Move the try/catch inside the `foreach` (match `StreakFreezeService`).

### [P3] CR-SVC-31 — `completionRate` conflates inactive with completed
**Effort**: S — `getRoomTaskStats`/`getUserTaskStats` compute `(total-active)/total`,
counting deactivated/skipped/one-time tasks as completed. Count real completions or
rename the metric.

### [P3] CR-SVC-32 — `calculateDaysEarly` DST/timezone edge cases
**Effort**: S — divides a second-difference by 86400; across DST / near midnight this can
round to the wrong day and flip the early bonus. Use calendar-day `diff`.

### [P3] CR-SVC-33 — `TaskService::completeTask` reward-less Task-level path
**Effort**: S — sets `status='completed'` with no coins/streak/cleanliness, parallel to
the real `RoomTaskService::completeTask`. Remove or document as admin/template-only.

### [P3] CR-SVC-36 — `calculateContributionScore` unused `$roomCleanliness` param
**Effort**: XS — public method ignores the param (signature/PHPDoc mislead). Drop it
(update caller + test) or fold cleanliness in per the documented intent.

### [P3] CR-SVC-37 — Service-layer i18n: hardcoded user-facing strings
**Effort**: S — `TaskService::validateTaskData` validation messages and
`MinigameService::purchaseBoost` exception messages are English literals returned to
clients. Inject `IL10N`, wrap with `t()`, update `l10n/` artifacts.

### [P3] CR-SVC-40 — `RoomService` reaches below its layer (`IDBConnection`)
**Effort**: M — injects `IDBConnection` for `beginTransaction`/`lockForUpdate` (persistence
plumbing in a service). Move transaction+lock into the mapper/repository.

### [P3] CR-SVC-41 — Service DI inconsistencies
**Effort**: M — nullable `?AppLogger` in 3 services (silent null-skip risk);
`getFilteredDailyTasksForUser` takes `HouseUserMapper` as a param despite ctor injection;
duplicated stat helpers; duplicated `validate*Ownership` across Floor/Room/RoomTask services.

### [P3] CR-SVC-42 — `TestDataService::RESET_TABLES` legacy table aliases
**Effort**: XS — pre-release compat shim (project-rules §1 forbids). Drop the legacy names.

### [P3] CR-SVC-44 — Minor performance cluster
**Effort**: M — `TaskService::getAllTasks` unbounded `findAll`; `createDefaultTemplates`
15 sequential inserts; `StreakFreeze`/`StreakRestock` per-user UPDATEs; `assignTask`/
`unassignTask`/`deactivate`/`reactivate`/`updateDueDate` triple-fetch pattern;
`extractHouseIdFromSchedule` 3 dependent queries.

---

## Release / Rollout

> Stage path: `v0.8.x` alpha → `v0.9.x` beta → `v1.0.0` stable (manual install).
> App Store submission is explicitly deferred until stable quality is proven.

### [P2] RELEASE-002 — Beta deployment + real-world alpha usage
**Status**: Pending — deploy to a personal Nextcloud instance; verify no impact
on other apps; real-world `v0.8.x` alpha usage; (optional) private tester
onboarding for `v0.9.x` beta with structured feedback intake.

### [P2] RELEASE-003 — v1.0.0 stable artifacts
**Status**: Pending — release notes, app screenshots, `v1.0.0` stable
manual-install release, App Store readiness evaluation (post-stable only).

### Milestone exit criteria (not yet satisfied)
- **`v0.9.x` Beta**: private tester onboarding; reproducible core flows across
  setups; documented install/update/rollback for testers; known-issues list;
  feedback intake operational; no open P0 / no unaccepted P1.
- **`v1.0.0` Stable**: documented supported workflows; release notes + known
  issues + install/upgrade guides; lint clean for supported paths; targeted
  automated + manual validation; release decision independent of App Store.

> Done release-prep work (security audit, CI release gate, integration/E2E
> stabilization, deployment checklist) is recorded in [`archive/`](archive/).
