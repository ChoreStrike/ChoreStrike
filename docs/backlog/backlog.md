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

### [P3] CR-016-07 — Archive orphaned SmartScheduler surface
**Effort**: M — after the 7 `smart-schedule#*` routes were removed (security),
`SmartSchedulerController` + `SmartSchedulerService` + their tests exercise a
route-less surface (dead code). Decide archive-vs-keep.

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
> (record: [`archive/2026-08.md`](archive/2026-08.md#cs-plan-025--libservice-security-concurrency--dead-code-cleanup)):
> the 2 P0 + 6 P1 items **CR-SVC-01/02/03/04/05/06/07/12** and the dead-cluster
> delete **CR-SVC-08** landed. The remaining open items below (CR-SVC-09/10/11/…,
> P2/P3) stay here.

### [P1] CR-SVC-45 — `CompletedTask` entity/schema mismatch (`completed_by_user_id` unmapped)
**Status**: Open · **Effort**: S · **Source**: CS-PLAN-025 (found by the CR-SVC-07 integration test)
`oc_chorestrike_completed_tasks` has a `completed_by_user_id` column that the
`CompletedTask` entity does **not** declare, so any SELECT that maps the full row throws
`BadFunctionCallException: completedByUserId is not a valid attribute` on the real DB
(e.g. `CompletedTaskMapper::findLatestByRoomTask`, used by `TaskSyncService::syncCompletionFromNextcloud`).
INSERTs work (they only set declared columns), masking the bug. Also note CR-SVC-21
(TaskSync `*ForHouse`-only public surface) was only **partially** advanced by CS-025 — the
base `completeTask`/`syncCompletionFromNextcloud`/… methods are still public.
- [ ] Declare `completed_byUserId` on the `CompletedTask` entity (addType + getter/setter)
      or drop the column; add a real-DB `findLatestByRoomTask` regression test.

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
`TaskSchedulerService::getActiveRoomTasks` (3-level); `TaskPriorityScorer::calculateScores`;
`FloorService::getFloorsWithRooms`/`reorderFloors`; `RoomService::getRoomWithTasks`/
`deleteRoom`; `RoomTaskService::getDailyTasksWithDetails`/`getRangeTasks`/`bulkAssignTasks`;
`MinigameService` leaderboards; `TaskSyncService::getPendingSyncsForHouse`;
`StreakFreezeService::process`.
- [ ] Add batch mapper queries (`findByHouseId`, `findByIds` IN, JOINs, grouped counts);
      replace per-id `find` loops with set-based lookups. (Scheduler N+1s retire with CR-SVC-08.)

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

### [P3] CR-SVC-39 — Missing PHPDoc on Scheduling public members
**Effort**: S — `AvailabilityCalculator` (and others) public methods carry only a
one-line summary, no `@param`/`@return`. Add mechanically. (Retired if CR-SVC-08 deletes.)

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
`ScheduleOptimizer` O(n²) recompute; `extractHouseIdFromSchedule` 3 dependent queries.

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
