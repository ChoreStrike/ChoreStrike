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

### [P3] BUG-ROOM-001 — Re-evaluate the `RoomController::index` POST→GET rewrite workaround
**Status**: Deferred (low) · **Effort**: S · **Source**: CS-020 (deferred AC)

The body-sniffing POST→GET rewrite workaround was left as harmless dead code
after the Docker env got correct `mod_rewrite` routing (`docker/apache-nextcloud.conf`).
Confirm whether production shapes still need it; if not, remove it.

- [ ] Confirm production routing; remove the workaround if no longer needed.

---

### [P3] BUG-E2E-001 — `3-floor-house.spec.ts`: 2 pre-existing failures in the serial pool
**Status**: Open · **Effort**: M · **Source**: CS-PLAN-031 gate run (2026-08-18)

Running the full `chromium-serial-db` project (family profile) fails 2 of 13
tests in `tests/e2e/3-floor-house.spec.ts`:

- `Navigation: Can navigate to all 3 floors from dashboard` (`:45`)
- `Task Management: Add cleaning task to Gästebad` (`:192` — times out
  waiting for a `Save`/`Create` button)

Verified **pre-existing** during the CS-031 gate: both tests fail identically
on the unmodified tree (stash-verified). All 16 `Release:*` specs pass; the
plan-level gates (`Release:*`) never covered this spec. Not related to any
CS-031 change (backend-only, no floor/task-create surface touched).

- [ ] Reproduce standalone, capture the error context, and fix the spec or
      the underlying UI flow; re-add to the blocking serial gate.

---

## Tech Debt / Code Quality

### [P3] TECH-LINT-003 — Build/test noise (remaining console.error)
**Status**: Open · **Effort**: S · **Source**: compiler-warnings review (2026-08-12) → [CS-PLAN-026](../plans/active/cs-plan-026-fix-compiler-warnings.md)

Most of the 2026-08-12 noise sweep landed in CS-026 (browserslist data refreshed;
punycode `DEP0040` suppressed via `tests/jest.setup.deprecations.js`; the two
named error-path specs silenced). The remaining noise is folded into
**TECH-LINT-005** below. This item stays open only as the umbrella tracker for
"keep the Jest output clean"; close it once TECH-LINT-005 lands.

---

### [P2] TECH-LINT-004 — Stylelint `use-logical` warnings (26, physical→logical property sweep)
**Status**: Open · **Effort**: M · **Source**: surfaced after CS-026 fixed the stylelint config (2026-08-12)

Once `@nextcloud/stylelint-config@3.x` was aligned (CS-026), the previously-silent
`stylelint-use-logical` rule started firing: **26 warnings** across `src/`
recommending logical properties (`left`→`inset-inline-start`,
`border-left`→`border-inline-start`, `text-align: left`→`start`, …). These are
**RTL/i18n layout improvements, not formatting** — auto-fixing them would change
rendering (e.g. a `left: 50%`+`translateX` centering pair becomes inconsistent
under the logical rewrite), so CS-026 deliberately left them as warnings (gate
still exits 0: warnings ≠ error-severity). `npm run stylelint` → 0 errors,
26 warnings.

- [ ] Audit each `use-logical` hit and convert to logical properties where the
      full positioning/transform context is verified for both LTR and RTL (or
      add a scoped `stylelint-disable-next-line` with a reason where physical is
      intentional).
- [ ] Consider adding RTL/Playwright coverage so the conversions are verifiable.

> **Warning (2026-08-13, CS-027 review):** `npm run stylelint:fix` applies these
> conversions mechanically across all 15 files (the rule is auto-fixable). A
> review gate run did exactly that and the churn had to be reverted. Do **not**
> run `stylelint:fix` repo-wide until this item is resolved — fix file-by-file
> with verification instead.

---

### [P2] TECH-LINT-005 — Jest `[ChoreStrike] [error]` noise from `api.js`↔mock gap (95 lines, ~8 store specs)
**Status**: Open · **Effort**: M · **Source**: surfaced during CS-026 noise sweep (2026-08-12)

`src/services/api.js::request()` calls `axios({ method, url, …config })` — the
**default export** of `@nextcloud/axios` — but the store/component unit-test
mocks only provide `{ get, post }`. Every API call therefore throws, falls into
the store's catch block, and logs via `src/utils/logger.js` → `console.error`.
The tests pass (the catch is graceful), but **~95 `[ChoreStrike] [error]` lines**
flood the Jest output across `minigame`, `house`, `roomTasks`, `titles`, `user`,
`game`, `tasks`, `rooms` store specs. CS-026 silenced the two originally-named
specs (MemoryGame, SchedulingView) per plan; the systemic mock gap is the real
fix and is deferred here.

- [ ] Standardize the `@nextcloud/axios` mock to also export a `default: jest.fn()`
      (matching how `api.js` calls it) and switch the store-spec setups from
      `axios.get.mockResolvedValue(…)` to `axios.mockResolvedValue(…)`, so the
      API calls resolve instead of hitting the catch/logger.
- [ ] Alternatively, if the default-export call is unintended, change `api.js`
      to use `axios.get`/`axios.post` (named exports) — but that is a production
      change needing its own review.

---

### [P3] TECH-LINT-006 — npm peer-dep ERESOLVE requires `--legacy-peer-deps`
**Status**: Open · **Effort**: S · **Source**: CS-026 dependency reinstall (2026-08-12)

`npm install` fails with `ERESOLVE`: `eslint-config-standard@17.1.0` wants
`eslint-plugin-n@^15 || ^16` but `package.json` pins `eslint-plugin-n@^17.24.0`
(via `@nextcloud/eslint-config@8.3.0`). The repo therefore installs with
`--legacy-peer-deps`. Pre-existing (not introduced by CS-026), but now documented.
Resolving it cleanly likely needs an `eslint-plugin-n`/`eslint-config-standard`
bump coordinated with the Nextcloud eslint config.

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

## Review Follow-ups — CS-016 (Scheduling UI)

> From [`archive/CODE_REVIEW_2026-07-28.md`](archive/CODE_REVIEW_2026-07-28.md).
> CR-016-11 is **resolved** (CS-018); the rest are open.

### [P2] CR-016-02 — Batch the scheduling save endpoints
**Effort**: M — `AvailabilitySettings.saveAvailability` (≤14 calls) +
`EnergyBudgetSettings` (7) save per-day sequentially. Add a bulk `PUT` for a
full week; call once; loading flicker gone. **Decision (2026-08-18):** sections
optional — energy-only/availability-only payloads allowed, each provided
section must be the complete week, neither section → 400. **Plan:** CS-PLAN-033.

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

> **Resolved 2026-08-09 by [CS-PLAN-025](../plans/done/cs-plan-025-service-security-concurrency-cleanup.md)**
> (record: [`archive/2026-08.md`](archive/2026-08.md)):
> the 2 P0 + 6 P1 items **CR-SVC-01/02/03/04/05/06/07/12** and the dead-cluster
> delete **CR-SVC-08** landed. **CR-SVC-09/10** were **resolved 2026-08-16** by
> [CS-PLAN-029](../plans/done/cs-plan-029-service-hardening.md)
> (record: [`archive/2026-08.md`](archive/2026-08.md)). The remaining open items
> below (CR-SVC-11/13/…, P2/P3) stay here.
>
> **Reopened 2026-08-09 by the CS-025 implementation review:** **CR-SVC-04**
> plus follow-ups **CR-SVC-45/46/47** — all four **resolved 2026-08-10**
> (record: [`archive/2026-08.md`](archive/2026-08.md)).
> The review's smaller follow-ups **CR-SVC-48/49** were **resolved 2026-08-16**
> by [CS-PLAN-029](../plans/done/cs-plan-029-service-hardening.md)
> (record: [`archive/2026-08.md`](archive/2026-08.md)); **CR-SVC-50…52** stay open below.

### [P3] CR-SVC-50 — Extra queries inside the `house_user` lock (critical section)
**Status**: Open · **Effort**: S · **Source**: Review of CS-PLAN-025 (CR-SVC-04)
`GameLogicService::handleTaskCompletion` runs `roomTaskMapper->find($roomTaskId)`
after `findForUpdate` although `RoomService::completeRoomTask` already holds that
entity, and calls `findRecentByUserId` twice (limit 5, then 10) when combo. Both
add round-trips inside the new row lock; the plan's risk table promised a short
critical section.
- [ ] Pass the loaded `RoomTask` into `handleTaskCompletion` (or fetch before
      the lock); fetch the last 10 completions once and slice for the combo check.

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
- [ ] Inject `AppLogger` in the 5 services (incl. `TaskDueReminderService`, added by
      decision 2026-08-18 — it postdates the 2026-08-04 source review); route
      dispatch/process failures through it. **Plan:** CS-PLAN-035.

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

### [P3] CR-SVC-19 — `RewardConfigService` inconsistent clamping
**Effort**: S — several setters/getters return raw values (tier multiplier, coin base,
freeze cap/count, early-bonus) that can invert rewards (negative multiplier). Admin-gated.
Clamp to `>= 0`/`>= 1` consistently.

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

### [P3] CR-SVC-44 — Minor performance cluster
**Effort**: M — `TaskService::getAllTasks` unbounded `findAll`; `createDefaultTemplates`
15 sequential inserts; `StreakFreeze`/`StreakRestock` per-user UPDATEs; `assignTask`/
`unassignTask`/`deactivate`/`reactivate`/`updateDueDate` triple-fetch pattern;
`extractHouseIdFromSchedule` 3 dependent queries.

---

## Review Follow-ups — CS-027 (House Setup Wizard & Cleaning Templates)

> From the Standard-B review of the CS-PLAN-027 implementation (2026-08-13).
> Six smaller findings were auto-fixed inline (plan §13); these two remain
> (CR-027-03/04 were closed by CS-PLAN-030).

### [P3] CR-027-01 — Free room create/update persists unvalidated `roomType`
**Status**: Open · **Effort**: S · **Source**: Review of CS-PLAN-027 (2026-08-13)
`RoomController` create/update accepts any client `roomType` string and persists
it (DB column length 50 → overlong input surfaces as a 500). Only the wizard
validates against the catalog registry. An unknown type degrades the template
picker to house-wide templates for that room.
- [ ] Validate `roomType` against `TemplateService::isValidRoomType()` at the
      controller boundary (400 or 'generic' fallback — decide); reject >50 chars.

### [P3] CR-027-02 — `TemplateService::searchTemplates` dead + unlocalized since D7
**Status**: Open · **Effort**: S · **Source**: Review of CS-PLAN-027 (2026-08-13)
The legacy `template#search` route was removed with `TemplateController`, leaving
`searchTemplates()` without a caller. It also matches the raw English source
text, not the localized view, so German queries (e.g. „Staubsaugen") miss.
- [ ] Wire it into the template picker as a search field (localize the match),
      or remove the method + its tests.

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
