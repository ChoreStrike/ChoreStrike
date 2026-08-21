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

### [P3] BUG-E2E-003 — `title-selection.spec.ts` "Selecting a pack marks it active" flaky
**Status**: Open · **Effort**: S · **Source**: CS-PLAN-038 full-suite gate run (2026-08-21)

`tests/e2e/title-selection.spec.ts:39` intermittently fails (initial attempt,
passes on retry) even standalone on the unmodified tree — **stash-verified**
during the CS-038 gate: the `active-pack-tag` does not appear within 10 s of
clicking `select-pack-button`. Likely a race between admin's house-membership
bootstrap (`ensureHouseMember` on `/users/current`) and the pack-selection PUT,
or a concurrent family re-seed wiping admin's house user mid-test in parallel
runs.

- [ ] Reproduce with tracing, identify the failing request (PUT vs membership
      bootstrap), fix the race or harden the spec's wait; keep it out of
      soft-skip territory.

---

### [P3] BUG-SEC-002 — `PUT /users/{id}/role` lacks self-demotion / last-owner guard
**Status**: Open · **Effort**: S · **Source**: CS-PLAN-038 Phase C risk register (2026-08-21)

`UserController::updateRole` (via `HouseService::updateUserRole`) validates the
role value and caller ownership but has **no guard** against (a) an owner
demoting *themselves* or (b) demoting the **last remaining owner** of a house —
either can orphan the house with no owner able to manage it. Contrast:
`remove` already blocks owners (`CANNOT_REMOVE_OWNER`). The CS-038 members UI
ships a frontend mitigation only (own role select disabled, `MemberList.vue`),
which is trivially bypassed by a direct API call — the root cause must be fixed
server-side.

- [ ] Backend: reject self-demotion and last-owner demotion in
      `updateUserRole` with a 400/409 error code; integration tests for both
      cases (mirror `CANNOT_REMOVE_OWNER` pattern).
- [ ] Frontend: surface the new error code in `MemberList.vue` role-change
      error toast (keep the disabled own-select as UX sugar).

---

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

### [P3] BUG-E2E-002 — Own-card Edit button never visible in E2E → owner modal tests skip vacuously
**Status**: Open · **Effort**: S · **Source**: CS-PLAN-032 gate run (2026-08-18)

In `tests/e2e/availability-settings-flow.spec.ts` (chromium-scheduling-owner,
`dad`), `edit-availability-button` never becomes visible, so the three guarded
modal tests (`can toggle weekday availability`, `shows energy budget inputs`,
`can apply quick templates`) call `test.skip()` and `shows edit button …`
passes vacuously. Verified **pre-existing** during the CS-032 gate: the skip
also occurs on the unmodified tree (stash-verified). Suspect:
`resolveCurrentUser()` (`@nextcloud/auth getCurrentUser`) returns null in the
E2E page context, so `member.isCurrentUser` is never true.

- [ ] Root-cause why the own-card Edit button does not render for `dad`;
      un-guard the three modal tests so they assert instead of skipping.

---

## Tech Debt / Code Quality

### [P3] SEC-AUDIT-001 — Security hardening follow-ups from P8-SECURITY-001 audit
**Status**: Open · **Effort**: M · **Source**: security audit 2026-03-05 (record archived 2026-08-20, DOC-STD-001)

- [ ] Rate limiting for sensitive endpoints (user management).
- [ ] Audit logging for owner-level operations.
- [ ] Document security assumptions in the API documentation (`docs/api/`).

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

### [P3] TECH-LINT-006 — npm peer-dep ERESOLVE requires `--legacy-peer-deps`
**Status**: Open · **Effort**: S · **Source**: CS-026 dependency reinstall (2026-08-12)

`npm install` fails with `ERESOLVE`: `eslint-config-standard@17.1.0` wants
`eslint-plugin-n@^15 || ^16` but `package.json` pins `eslint-plugin-n@^17.24.0`
(via `@nextcloud/eslint-config@8.3.0`). The repo therefore installs with
`--legacy-peer-deps`. Pre-existing (not introduced by CS-026), but now documented.
Resolving it cleanly likely needs an `eslint-plugin-n`/`eslint-config-standard`
bump coordinated with the Nextcloud eslint config.

---

### [P3] TECH-DRY-001 — Weekend fallback energy budget map duplicated (3 sites)
**Status**: Open · **Effort**: S · **Source**: Review of CS-PLAN-040 (2026-08-21)

The weekday→energy fallback budget (`monday…friday: 10, saturday/sunday: 15`)
now lives in three places: `getCapacityForDay` in `src/store/scheduling.js`,
the fairness rows in `src/components/Insights/MemberFairnessTable.vue`, and the
per-member `?? 10` default in `getTotalEnergyForDay`. Rule of three reached —
extract one shared constant/helper (e.g. `src/utils/energyBudgets.js`) and use
it in all three sites so a balancing change touches one place.

- [ ] Extract the shared fallback-budget constant + weekday list.
- [ ] Replace all three sites; keep Jest coverage for the 10/15 weekend values.

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

### [P3] TECH-DOCS-001 — Recurring `?` corruption of non-cp1252 characters in backlog docs
**Status**: Open · **Effort**: S · **Source**: Review of CS-PLAN-035 (2026-08-19)

`docs/backlog/archive/2026-08.md` repeatedly accumulated literal `?` bytes in
place of `→` / `≤` (CS-033 and CS-034 commits carried them; the CS-035 review
repaired all of them). Signature: characters present in Windows-1252 (`—`, `§`)
survive, characters outside it (`→`, `≤`) become `?` — i.e. a write path that
mis-detects the file as ANSI while its content is still cp1252-safe, then
re-encodes on save. Files that already contain a non-cp1252 character are
detected as UTF-8 and write fine.

- [ ] After editing backlog/archive docs, byte-verify non-ASCII content
      (e.g. scan for literal `0x3F` in prose) or write with explicit UTF-8;
      consider a small `npm run docs:encoding` check in the doc-touching gates.

---

## Review Follow-ups — CS-016 (Scheduling UI)

> From [`archive/CODE_REVIEW_2026-07-28.md`](archive/CODE_REVIEW_2026-07-28.md).
> CR-016-11 is **resolved** (CS-018); CR-016-12 is **resolved** (CS-032);
> CR-016-02 is **resolved** (CS-033); the rest are open.

### [P2] CR-016-10 — Regression test for `getConsumedEnergyForUserOnDate` SQL fix
**Effort**: S — mock-based mapper test cannot catch SQL errors; needs a real-DB
mapper/integration test (0 with no completions; >0 seeded). Unblock the
integration harness first (`AvailabilityApiTest` `Class OC not found`).

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
**Status**: Resolved 2026-08-21 (CS-PLAN-038, component archived) → [`archive/2026-08.md`](archive/2026-08.md)

### [P3] CR-016-13 — Scheduling dead code + oversized suggestion payload
**Effort**: S — (a) `SchedulingService::updateMemberAvailability(int…)` no longer
called (dead, kept alive by its test); (b) store action `updateMemberAvailability`
has no UI consumer; (c) `getSuggestions` sends the full task array though backend
only uses `count($tasks)` → send `{ taskCount }`.

### [P3] CR-016-14 — AvailabilitySettings save guard + unreachable loading states
**Effort**: S — (a) no `hasChanges` guard (Save with no edits fires 1 bulk call);
(b) per-component `loading` blocks are unreachable dead UI (components mount only
inside the view's `v-else`, loading=false).

### [P3] CR-016-15 — Shared backend energy-bounds validation (drift risk)
**Effort**: S — the 1–20 check + curated message now exists at three sites:
`SchedulingController::validatePreferencesSection`, `SchedulingService::updatePreferencesByNextcloudUserId`,
and the pre-existing `SchedulingService::applyMemberUpdates`. A bounds/message
change must be made in all three or the self path and the owner path reject/persist
different values for the same column. Source: Review of CS-PLAN-033 (2026-08-19).

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
> (record: [`archive/2026-08.md`](archive/2026-08.md)).
> **CR-SVC-50** was **resolved 2026-08-20** by
> [CS-PLAN-036](../plans/done/cs-plan-036-query-batch-optimization.md)
> (record: [`archive/2026-08.md`](archive/2026-08.md)); **CR-SVC-52** stays open below.

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

> **Resolved 2026-08-20 by
> [CS-PLAN-036](../plans/done/cs-plan-036-query-batch-optimization.md)**
> (record: [`archive/2026-08.md`](archive/2026-08.md)): **CR-SVC-55/56** and the
> **read-path** cluster of **CR-SVC-11** are closed; the write-path remainder
> (resetHouse, deleteRoom, reorderFloors, bulkAssignTasks) is split into
> **CR-SVC-60** below.

### [P3] CR-SVC-59 — Add `AppLogger` to logger-less services
**Status**: Open · **Effort**: M · **Source**: CR-SVC-13 remainder (review of `lib/Service`, 2026-08-04; split off 2026-08-19)
~16 services have no logger at all. The raw-`LoggerInterface` half of CR-SVC-13 is
closed by CS-PLAN-035 (5 services migrated to `AppLogger`); this remainder tracks
incremental `AppLogger` adoption in services that swallow failures silently.
- [ ] Survey the logger-less services and inject `AppLogger` where failure paths
      (catch blocks, best-effort skips) currently go unlogged.

> **Resolved 2026-08-20 by
> [CS-PLAN-037](../plans/done/cs-plan-037-bulk-write-batching.md)**
> (record: [`archive/2026-08.md`](archive/2026-08.md)): **CR-SVC-60** closed —
> all four write hot spots converted to set-based writes with explicit,
> transactional, orphan-free delete cascades (D1=YES/D2=DELETE).

### [P3] CR-SVC-61 — `bulkAssignTasks` has no controller/route caller
**Status**: Open · **Effort**: S · **Source**: Review of CS-PLAN-037 (2026-08-20)
The CS-PLAN-037 Phase-D batch pre-validation (`RoomTaskMapper::findByIdsScopedToHouse`)
is currently exercised only by tests: no controller/route calls
`RoomTaskService::bulkAssignTasks` — the frontend store still loops
`assignRoomTask` per item ("API doesn't have bulk endpoint yet"), so the
optimized write path is dead code until an endpoint is wired.
- [ ] Add a bulk-assign endpoint (route + controller with `ensureHouseMember`
      and `InvalidArgumentException` → 400) and switch the store to call it.

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

## Review Follow-ups — Usability & UI Review (2026-08-20)

> From the usability/UI workflow review (house setup → tasks → assignment →
> completion, adults + children). Full record:
> [`archive/USABILITY_REVIEW_2026-08-20.md`](archive/USABILITY_REVIEW_2026-08-20.md).
> **UX-001/002/003 resolved 2026-08-21 by CS-PLAN-038**; `CR-016-09` closed by
> archiving DailyTaskList. Related existing items: `CR-016-05`/`CR-016-03`
> (scheduling merge/split), `CR-SVC-61` (bulkAssign), `BUG-USER-001`,
> `CR-SVC-33` (reward-less Task-level path).

### [P3] FEAT-UX-015 — Leave house (self-service membership exit)
**Status**: Open · **Effort**: M · **Source**: UX-003 remainder (CS-PLAN-038)

Members cannot leave a house on their own; removal is owner-only. Needs a
product decision on owner-transfer semantics when the *last* owner leaves
(interplay with the BUG-SEC-002 last-owner guard), then a backend endpoint +
Settings action.

- [ ] Decide owner-transfer/last-owner semantics for leaving.
- [ ] Backend leave endpoint (+ guards) + Settings Leave button + tests.

### [P1] UX-001 — Task creation is three inconsistent paths; TaskManager creates invisible tasks
**Status**: Resolved 2026-08-21 (CS-PLAN-038) → [`archive/2026-08.md`](archive/2026-08.md)

### [P1] UX-002 — No "Today / my tasks" surface (child persona gap)
**Status**: Resolved 2026-08-21 (CS-PLAN-038) → [`archive/2026-08.md`](archive/2026-08.md)

### [P1] UX-003 — Member management has zero UI (backend + stores complete)
**Status**: Resolved 2026-08-21 (CS-PLAN-038) → [`archive/2026-08.md`](archive/2026-08.md); leave-house remainder → FEAT-UX-015

### [P2] UX-004 — Structure gaps: room rename/delete + floor reorder UI-less; floor mgmt duplicated
**Status**: Open · **Effort**: M · **Source**: Usability review 2026-08-20

`updateRoomName`/`deleteRoom` store actions have no buttons;
`POST /floors/reorder` + `reorderFloors` exist but FloorList has no drag&drop.
Floor management exists twice (Dashboard FloorList + Settings). Settings shows
owner-only controls (rename/reset/floor CRUD) to all members → 403s instead of
being role-gated in the template.

- [ ] Room rename/delete in RoomDetail header; floor reorder (drag or ↑/↓);
      keep floor CRUD in one surface; role-gate owner-only Settings controls.

### [P2] UX-005 — Dead/misleading controls
**Status**: Open · **Effort**: M · **Source**: Usability review 2026-08-20

Leaderboard period filter has no effect (state set, never re-fetched); medals
section hardcoded `[]` while `GET /game/medals`/`leaderboard`/`check-medals`
and `initializeGame()` are unreachable → medal celebrations never fire;
QuickStats "My Private Tasks" emits `toggle-private-filter` that Dashboard never
binds.

- [ ] Fix or remove each control; wire the game endpoints so medals evaluate
      and celebrate.

### [P2] UX-006 — No undo for completion; native confirms; silent Settings saves
**Status**: Open · **Effort**: M · **Source**: Usability review 2026-08-20

`uncomplete`/`reactivate` endpoints have no UI (misclick = permanent); task/
floor deletes use native `confirm()`; Settings saves give no toast feedback.

- [ ] Post-complete undo snackbar (~5 s) → uncomplete/reactivate; styled
      confirmation dialogs; toasts on Settings save/rename.

### [P2] UX-007 — Kid-sized controls & inconsistent vocabulary
**Status**: Open · **Effort**: M · **Source**: Usability review 2026-08-20

Complete button 28 px, claim 24 px, action icons ~16 px (mobile.css itself
demands ≥44 px touch targets); icon-only buttons rely on `title` tooltips;
priority labels differ per surface (Low/Medium/High vs High/Normal/Low vs
Urgent/Normal/Can Wait); abstract terms (Admin-Only, Dynamic/Static, Scope).
Review of CS-PLAN-038 (2026-08-21): the new chip toggles (`AssigneeChips.vue`,
weekday chips) hide their checkbox with `display:none`, removing them from
keyboard tab order.

- [ ] ≥44 px targets + aria-labels; one priority vocabulary; friendlier labels
      or child-mode wording.
- [ ] Make chip toggles keyboard-accessible (visually-hidden checkbox instead
      of `display:none`, or `role="checkbox"` + key handling).

### [P3] UX-008 — Assignment discoverability
**Status**: Open · **Effort**: S · **Source**: Usability review 2026-08-20

Multi-assign chips only inside an expanded task row; absent from all create
forms; no bulk-assign UI (cf. `CR-SVC-61`).

### [P3] UX-009 — Checklist editor implemented but permanently locked
**Status**: Open · **Effort**: S · **Source**: Usability review 2026-08-20

`TaskChecklist.vue` supports add/edit/delete/reorder; the only mount passes
`:can-edit="false"`; item CRUD endpoints unused; items only originate from
templates. Unlock for owners (or remove the dead editor).

### [P3] UX-010 — Hardcoded colors bypass tokens; stale mobile.css selectors
**Status**: Open · **Effort**: M · **Source**: Usability review 2026-08-20

RoomDetail stat values, FloorList action buttons, TaskList badge fallbacks etc.
hardcode hex colors instead of NC vars/`--cs-*` tokens (dark-mode drift);
`mobile.css` still targets legacy class names (`.room-card`, `.task-item`,
`.leaderboard-entry`, `.mobile-only`) not used by current components.

### [P3] UX-011 — Setup wizard polish
**Status**: Open · **Effort**: S · **Source**: Usability review 2026-08-20

"Start empty" dismissal is session-only → wizard re-opens every reload while
the house is empty; no house-name step; per-room assignee dropdown is useless
at first run (single member). The invite step is **done** (wizard step 4,
shipped with CS-PLAN-038 — see UC-01 in
[`../concepts/ui-use-cases.md`](../concepts/ui-use-cases.md)); the original
"no invite step (see UX-003)" note is obsolete.

### [P3] UX-012 — Modal accessibility
**Status**: Open · **Effort**: S · **Source**: Usability review 2026-08-20

Shared `Modal.vue` has no ESC handling, focus trap/restore, `aria-modal`, or
scroll lock; CalendarView prev/next use raw `<`/`>` glyphs without aria-labels.

### [P3] UX-013 — Boost prices duplicated client-side
**Status**: Open · **Effort**: S · **Source**: Usability review 2026-08-20

`BOOST_COSTS` hardcoded in `store/minigame.js`; authoritative
`GET /minigames/boosts` never fetched → price drift between affordability
check and purchase. Fetch the catalog.

### [P3] UX-014 — SchedulingView redundancy & suggestions without action
**Status**: Open · **Effort**: M · **Source**: Usability review 2026-08-20

Owners get two parallel editors for their own availability; owner grid is
read-only for others while implying management (`PUT /scheduling/members/{id}`
unused); Preview + Suggestions duplicate the same endpoint (cf. `CR-016-05`);
suggestions have no "apply" action.

---

## Review Follow-ups — UI Use-Case Review (2026-08-21)

> From the Standard-B review of
> [`../concepts/ui-use-cases.md`](../concepts/ui-use-cases.md) and the endpoint
> audit (both 2026-08-21). These are **whitespace candidates**: documented
> intent without UI surface (Group A) and review ideas (Group B), indexed from
> the *Whitespace* section of `ui-use-cases.md`. No expose-or-prune decision is
> pre-empted by their existence; P4 items are explicitly "future / needs
> decision". **FEAT-UX-016/017/020/022 were resolved by CS-PLAN-040
> (2026-08-21) → [`archive/2026-08.md`](archive/2026-08.md).**

### [P3] FEAT-UX-018 — Task-approval workflow for children
**Status**: Open · **Effort**: L · **Source**: Review of docs/concepts/ui-use-cases.md (2026-08-21)

design.md §5 lists "task approval workflow for children"; gamification open
decisions touch it too. Today every completion is final immediately.

- [ ] Product decision recorded: approval flow vs. current auto-complete
      (which roles, which tasks).
- [ ] Backend: needs-review state + owner approve/reject endpoints with
      membership/owner gating + tests.
- [ ] Owner approval queue UI + child-facing "waiting for approval" feedback.

### [P4] FEAT-UX-019 — Task Pass & support actions (gag mechanic)
**Status**: Open · **Effort**: M · **Source**: Review of docs/concepts/ui-use-cases.md (2026-08-21)

gamification-and-rewards.md sketches a post-MVP "Task Pass" gag mechanic;
explicitly future / needs decision.

- [ ] Product decision whether the gag mechanic fits the target audience.
- [ ] Only after decision: concept extension + endpoint design sketch.

### [P3] FEAT-UX-021 — NC-Tasks sync surface
**Status**: Open · **Effort**: M · **Source**: Review of docs/concepts/ui-use-cases.md (2026-08-21)

Six `task-sync` routes are backend-only (task-model-and-sync.md). Blocked on
the cluster's expose-or-prune decision.

- [ ] Record the expose-or-prune decision for the 6 `task-sync` routes first.
- [ ] If exposed: link/unlink + pending/conflict list UI (RoomDetail or
      TaskManager) + tests; if pruned: close this item referencing the ADR.

### [P3] FEAT-UX-023 — Onboarding & help layer
**Status**: Open · **Effort**: M · **Source**: Review of docs/concepts/ui-use-cases.md (2026-08-21)

No tour, glossary, or contextual hints; vocabulary drift (UX-007) makes
self-service discovery harder.

- [ ] Help entry point per major view (Dashboard, TodayView, SchedulingView).
- [ ] Glossary unifying the UX-007 vocabulary drift (priority labels,
      Admin-Only, Scope).
- [ ] Contextual hints for gamification terms (combo, medal, streak freeze).

### [P3] FEAT-UX-024 — Notification settings
**Status**: Open · **Effort**: M · **Source**: Review of docs/concepts/ui-use-cases.md (2026-08-21)

UC-14 reminders are passive server jobs with no settings surface.

- [ ] Backend: per-user notification preferences (storage + membership-gated
      endpoints).
- [ ] Settings surface: digest toggle, quiet hours; background jobs respect
      them.
- [ ] Service + settings tests.

### [P4] FEAT-UX-025 — Vacation / house-pause mode
**Status**: Open · **Effort**: M · **Source**: Review of docs/concepts/ui-use-cases.md (2026-08-21)

Future / needs decision: pause semantics undefined (streaks? due dates?
notifications? per-member vs whole-house).

- [ ] Product decision on pause semantics + scope recorded.
- [ ] Concept extension before any implementation.

### [P4] FEAT-UX-026 — Real-world reward shop
**Status**: Open · **Effort**: L · **Source**: Review of docs/concepts/ui-use-cases.md (2026-08-21)

Future / needs decision: coins → owner-defined real-world rewards beyond the
coin/boost model.

- [ ] Product decision + gamification-doc concept extension (reward model,
      redemption flow).
- [ ] Implementation only after the concept lands.

### [P4] FEAT-UX-027 — Print view (weekly plan / calendar)
**Status**: Open · **Effort**: S · **Source**: Review of docs/concepts/ui-use-cases.md (2026-08-21)

Future: paper-friendly weekly plan for fridge-door households.

- [ ] Print styles or dedicated print route reusing CalendarView range data.
- [ ] No new endpoints unless a gap is proven.

### [P3] FEAT-UX-028 — Global search
**Status**: Open · **Effort**: M · **Source**: Review of docs/concepts/ui-use-cases.md (2026-08-21)

`GET /tasks/search` exists with a dead store wrapper (`searchTasks`); no UI.

- [ ] Search entry point covering task definitions + room tasks (NC search
      integration or in-app field).
- [ ] Decide fate of the dead `searchTasks` wrapper with the implementation.
- [ ] Tests.

### [P4] FEAT-UX-029 — Bulk maintenance ops
**Status**: Open · **Effort**: M · **Source**: Review of docs/concepts/ui-use-cases.md (2026-08-21)

Future: owner tools to reschedule/clear overdue tasks in bulk and seasonally
deactivate/reactivate by filter (cf. CR-SVC-61 bulk-endpoint pattern).

- [ ] Server-side bulk reschedule endpoint + owner action on the overdue list.
- [ ] Seasonal deactivate/reactivate by filter (room/category/idle).
- [ ] Styled confirmations per cross-cutting conventions + tests.

### [P3] FEAT-UX-030 — A11y & kids-mode conventions
**Status**: Open · **Effort**: M · **Source**: Review of docs/concepts/ui-use-cases.md (2026-08-21)

Accessibility conventions exist piecemeal (UX-007, mobile.css targets);
reduced-motion and screen-reader support are undocumented; simplified child
surface is an open question.

- [ ] Codify conventions in design.md (≥44 px targets, aria-labels, focus
      states, reduced-motion) and apply to new components.
- [ ] Fix the UX-007 chip-toggle keyboard regression as part of this.
- [ ] Answer the open question: simplified child surface yes/no (recorded).

### [P3] FEAT-UX-031 — Error/empty/offline-state conventions
**Status**: Open · **Effort**: M · **Source**: Review of docs/concepts/ui-use-cases.md (2026-08-21)

Error/empty states are ad-hoc per component; offline behavior undefined.

- [ ] Shared empty/error/offline state components with retry actions.
- [ ] Conventions documented in design.md; applied to Dashboard, TodayView,
      TaskList.
- [ ] Offline detection strategy decided within NC ecosystem constraints.

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
