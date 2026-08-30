# CS-PLAN-057: Per-House Timezone for Calendar-Day Semantics (U1-A / CR-053-12)

> **Status:** Done (2026-08-30 — implemented, gate green, Standard-B review PASS; see §9 + §10)
> **Effort:** L
> **Priority:** P2
> **Depends on:** none (CS-PLAN-055/056 both landed 2026-08-30; this plan touches some of the same files — `RoomTaskService`/`RoomTaskController`/l10n — and runs strictly after them)
> **Closes:** CR-053-12 (incl. the CR-P4-07 day-key unification) · Partially closes: CR-053-11(a) (see D3), CR-053-20 (CR-P4-06 null-date guard half folded in)

## 1. Problem Summary

All calendar-day semantics bucket at **server-TZ midnight** — and a Nextcloud server runs UTC: streak days, freeze coverage, at-risk detection, due-today windows, energy windows, medal/leaderboard weeks, and the duplicate-completion day guard all flip at UTC midnight instead of the household's local midnight (review CR-P2-14; line refs re-verified post-CS-055/056: `StreakFreezeService:43`, `HouseUser:176-179`, `RoomTask:185-221`, `RoomTaskMapper:143-146,343,376`, `CompletedTaskMapper` day-boundary preambles ×4 (:113,:144,:167,:194), `GameLogicService:250-251`, `HouseService:388`, `MinigameService:163`, `TaskDueReminderService:66-69`, `RoomTaskController:567-570,703`). For any non-UTC household, "today", "this week", and streak continuity are wrong around local midnight and across DST.

The frontend has two coexisting day-key conventions that can disagree: `roomTasks.js:65-94 rangeTasksByDay` slices the **UTC** ISO string (`.slice(0, 10)`) while `scheduling.js:23 localDateKey` builds **browser-local** keys (CR-P4-07) — the same task can land on different days in TaskCalendar vs. scheduling/overcapacity views.

**Design decision (user, 2026-08-29): per-house timezone.** `houses.timezone` column; the backend buckets all day boundaries in the house TZ (explicit `DateTimeZone` injection at the call sites); the frontend unifies both day-key conventions on the house TZ.

## 2. Strategy

One timezone source of truth per house, threaded **explicitly** (no global state, no `\OC::$server`): a new migration adds `houses.timezone`; a small `CalendarService` (pure, unit-testable) computes day/week windows for a `(DateTime, DateTimeZone)` pair with correct Monday week-start semantics (adopted from `GameLogicService::getWeekStart:472`, the correct sibling); services resolve `$house->getTimezone()` once per request/job pass and pass either the `\DateTimeZone` or precomputed `[start, end)` bounds into mappers and entity methods. This simultaneously de-duplicates the day-boundary preambles the review flagged as a ×6 copy-paste cluster (L1-5; current tree: 4 sites in `CompletedTaskMapper` + 3 in `RoomTaskMapper`). On the frontend, the house store exposes `timezone` (from the existing `GET /house` serialization) and one shared `dateKeyInZone(iso, tz)` util (Intl-based) replaces both the UTC slice and the browser-local `localDateKey`. DST correctness is pinned by unit/integration tests on both sides of a transition.

## 3. Backend / Frontend Changes

### 3.1 Backend

- **Schema:** new migration `Version1028…` adds `houses.timezone` (`VARCHAR(64) NOT NULL DEFAULT 'UTC'`; column add on an existing table follows the Version1023 precedent). `House` entity: typed field + `jsonSerialize` gains `timezone`. **D1 (creation default):** capture the creator's NC timezone at house creation (`IConfig->getUserValue($uid, 'core', 'timezone')`, fallback `date_default_timezone_get()`), not a bare `'UTC'`.
- **Validation + surface:** `HouseController::update` (already owner-gated) accepts `timezone`; closed-set validation against `\DateTimeZone::listIdentifiers()` → 400 `VALIDATION_ERROR` on unknown ids; message via `t()`, l10n synced.
- **`CalendarService`** (`lib/Service/CalendarService.php`, ~80 lines): `getTimeZone(house)`, `dayBounds(\DateTimeInterface $now, \DateTimeZone $tz): array{\DateTime,\DateTime}` (`[start, end)` convention), `weekStart($now, $tz)` (Monday, correct "monday this week" semantics), `dayKey($now, $tz)`.
- **Call-site threading** (each: resolve tz once at the entry, pass down explicitly):
  - `RoomService::completeRoomTask` — duplicate-guard day (call at :284-290 → `CompletedTaskMapper::countByRoomTaskAndUserOnDate` preamble :143-146) and the recurrence/markCompleted path get house-TZ bounds/`DateTimeZone`.
  - `RoomTask` entity day math (:185-221 due-today/is-overdue, :471 flex window, :604-605 projection anchor, :713 weekly origin) — methods gain an explicit `?\DateTimeZone` parameter supplied by the calling service (no silent default on the hot path).
  - `RoomTaskMapper` (:143-146 dueToday bounds, :343/:376 day windows) — accept bounds as parameters (replaces the hand-rolled preambles).
  - `CompletedTaskMapper` day-boundary preambles ×4 (:113, :144, :167, :194) — same bounds-parameter treatment (the review's ×6 cluster includes the two `RoomTaskMapper` windows above; the medal **week** window lives in `GameLogicService:302-304`).
  - `GameLogicService` (:250-251 days-early compare, `getWeekStart:472` → delegate to CalendarService, medal week window :302-304), streak/freeze/at-risk passes (`StreakFreezeService:43`, `StreakAtRiskService`, `HouseUser:176-179` streak-day compare — compare moves behind CalendarService day keys; the cross-house grouped `countByUserOnDateGrouped` call becomes per-house/TZ-grouped — shape-only change under the single-house invariant), `HouseService:388` weekly-goal week key, `MinigameService:163` weekly leaderboard (**D3**: fix the Sunday `'monday this week'` bug CR-P2-11 = CR-053-11(a) here, since the helper is rewritten anyway — recommended fold-in), `TaskDueReminderService` day key, `RoomTaskController::range :567-570` normalization (+ `filteredDaily :703` `new \DateTime()`), `RoomTaskService::getFilteredDailyTasksForUser` `$date` bucketing.
  - Background jobs resolve the house (single-house-per-instance is the design invariant; the CR-053-07 bootstrap race is still open) → tz per pass; multi-house future noted in the ADR only.
- **`HouseController::update` + Settings API:** see frontend.

### 3.2 Frontend

- `src/store/house.js`: state gains `timezone` (from `GET /house` payload); owner Settings → House section gains a timezone `<select>` (grouped `\DateTimeZone::listIdentifiers()` — ship a curated subset list constant; save via existing `PUT /house`), i18n keys + l10n sync.
- New `src/utils/dateKeys.js`: `dateKeyInZone(dateIso, timeZone)` → `YYYY-MM-DD` via `Intl.DateTimeFormat('en-CA', { timeZone, … })`; fallback `UTC` when the house isn't loaded yet.
- Replace both conventions: `roomTasks.js rangeTasksByDay` `.slice(0,10)` → `dateKeyInZone(..., houseStore.timezone)`; `totalDueTodayTasks`/`totalOverdueTasks` getters (:39-56) → house-TZ day bounds **and** the `new Date(null)`-epoch guard folded in (CR-P4-06 = CR-053-20 half, same lines); `scheduling.js` module-local `localDateKey` → shared util with house TZ (overcapacity/energy-today buckets); day-bucketing in TaskList/TodayView/ScheduleCalendar aligned where they bucket by day key.
- Cross-layer: house payload ↔ store ↔ util; no new endpoints.

## 4. Tests

- **Unit (PHPUnit):** `CalendarServiceTest` — UTC vs Europe/Berlin vs Pacific/Auckland around DST switches (spring-forward/gap + fall-back/overlap), `[start,end)` bounds, Monday week-start incl. Sunday, dayKey stability across midnight in house TZ. Entity/mapper tests updated with explicit tz params (red→green where behavior changed).
- **Integration (Docker):** completion at 22:30 UTC (00:30 Berlin) counts for the **next** Berlin day (duplicate guard + streak day); a Berlin house's weekly window differs from UTC's; leaderboard week pinned via D3 fix.
- **Jest:** `dateKeys.js` pins (UTC/Berlin/Auckland crossing midnight; invalid/empty iso → guard); store getter specs for house-TZ due-today/overdue incl. `null` nextDue.
- **E2E:** blocking suite green (container runs UTC — timezone behavior covered at integration level; optional non-UTC assertion via Settings save + calendar render).

## 5. File Manifest

```
new:      lib/Migration/Version1028Date2026….php · lib/Service/CalendarService.php · src/utils/dateKeys.js · tests/unit/Service/CalendarServiceTest.php · tests/integration/Controller/HouseTimezoneApiTest.php · tests/unit/utils/dateKeys.spec.js
modified: lib/Db/{House,RoomTask}.php · lib/Db/{RoomTaskMapper,CompletedTaskMapper}.php
modified: lib/Service/{RoomService,RoomTaskService,GameLogicService,StreakFreezeService,StreakAtRiskService,TaskDueReminderService,MinigameService,HouseService}.php · lib/Db/HouseUser.php (streak compare)
modified: lib/Controller/{HouseController,RoomTaskController}.php · lib/BackgroundJob/{StreakFreezeJob,DailyNotificationJob,…}.php
modified: src/store/{house,roomTasks,scheduling}.js · src/views/Settings.vue (timezone select)
modified: l10n/{en.json,de.json} · l10n/templates/chorestrike.pot
modified: docs/api/README.md · docs/engineering/{architecture,testing}.md · docs/adr/ADR-009-per-house-timezone.md (new) · docs/backlog/{backlog.md,ACTIVE_TASKS.md}
```

## 6. Implementation Order

1. Schema + entity + `HouseController::update` (D1 creation default) + `CalendarService` + unit tests.
2. Completion-path threading (duplicate guard, streak day, `RoomTask` entity params) + integration tests.
3. Remaining backend call sites (mapper bounds params, week windows, jobs; D3 fold-in) + tests.
4. Frontend: house store + `dateKeys.js` + both convention replacements + Settings select + l10n.
5. ADR-009 + architecture/testing docs (day-boundary conventions section) + backlog moves (CR-053-12 → archive; CR-053-11(a) and CR-053-20's CR-P4-06 half partial-close notes).
6. Full build gate (`npm test` + lint + stylelint + Docker PHPUnit + blocking E2E project).

## 7. Risks & Mitigations

| Risk | Likelihood | Mitigation |
|------|-----------|------------|
| TZ threading misses a site → mixed-TZ state worse than uniform UTC | medium | Inventory table in §3.1 is the tracked checklist; grep gate: no bare `new \DateTime('…')` day-math outside CalendarService callers; integration test pins end-to-end day bucketing |
| Entity API churn (`RoomTask` gains tz params) breaks callers | medium | Explicit nullable param + all call sites updated in the same step; unit suite pins each |
| DST edge cases (gap/overlap) shift occurrences | medium | CalendarServiceTest pins both transitions in 3 zones; `[start,end)` bounds avoid double-count windows |
| House TZ absent (house not loaded) in frontend | low | `dateKeyInZone` UTC fallback + loading state; Jest pin |
| Owner changes house TZ mid-period → day/week keys shift; streak continuity and medal/weekly-goal dedup keys (`weekStart`) can double-count or skip in the transition week | low | Accept + document in ADR-009 (pre-release, gamification state non-critical, no prod data); integration test pins week-key shape per TZ |
| Scope explosion across ~15 files | medium | Phased order (completion path first, highest value); each phase independently green |

## 8. Acceptance Criteria

- [x] `houses.timezone` exists; captured at creation (D1) and owner-editable in Settings with closed-set validation
- [x] All day-boundary logic (streaks, freezes, at-risk, due-today, energy windows, duplicate guard, medal/leaderboard weeks) buckets in house TZ — no site computes server-TZ day bounds (grep gate)
- [x] Frontend has exactly one day-key convention (`dateKeys.js`, house TZ); UTC-slice and browser-local keys are gone (grep gate)
- [x] D3 resolved (recommended: Sunday leaderboard fix folded in with pin); `new Date(null)` epoch guard in place
- [x] Tests as §4 (DST ×3 zones, integration midnight-crossing); build gate green
- [x] ADR-009 records the per-house-TZ decision; conventions in `docs/engineering/`; CR-053-12 moved to archive; ACTIVE_TASKS updated

## 9. Implementation Outcome (2026-08-30)

Implemented per the phased order; all acceptance criteria met. Gate results
recorded in `ACTIVE_TASKS.md`. Deviations/notes against the manifest:

- **Store action name**: the frontend keeps `houseStore.updateHouseName(name, timezone?)`
  (single `PUT /house` call, timezone optional) instead of adding a new
  `updateHouse` action — Settings' two save buttons both go through it.
- **`RoomTaskController::range` normalization** lives in
  `RoomTaskService::getRangeTasks` (reinterpret the controller's `Y-m-d`
  keys as house-TZ bounds); the controller's parse/validate code is
  unchanged. Occurrence keys returned by the range endpoint are house-local
  `Y-m-d` (bucketing convention shared with the frontend).
- **`jsonSerialize(?\DateTimeZone)`**: `RoomTask` serialization gains an
  optional tz (server fallback for `json_encode`-style callers); all
  RoomTask endpoints in `RoomTaskController` + `getRangeTasks` thread the
  house timezone.
- **Background passes** resolve the house themselves via
  `HouseMapper::findOneForJobs()` (new, first-row read) inside the pass
  instead of the jobs resolving and passing tz — same "tz per pass" outcome,
  smaller surface; no house yet → pass is a no-op.
- **New test file beyond the manifest**: `tests/integration/Service/TimezoneDayBucketingTest.php`
  (real-DB pins for duplicate-guard midnight crossing, weekly-window TZ
  difference, leaderboard house week) alongside the manifest's
  `HouseTimezoneApiTest`.
- **Migration replay**: the dev test DB required `down -v` + `up -d`
  (Nextcloud 31 exposes no per-migration execute); fresh-install replay
  verified with the new Version1028 in the chain.
- `HouseUser::calculateStreakWithAbsence` / `GameLogicService::calculateDaysEarly`
  implement the house-TZ day keys inline (entities cannot depend on
  services) — same convention as `CalendarService::dayKey`.

## 10. Review Outcome (2026-08-30, Standard-B, PASS)

Reviewed: full uncommitted CS-PLAN-057 changeset (66 modified + 10 new
files) against the §3 shared checklist.

- **Architecture/auth/security** — clean: controllers stay thin (timezone
  validation is boundary validation, ownership gate unchanged), business
  logic in services, mapper day windows take bound parameters
  (`createNamedParameter` only, no SQL concatenation), `PUT /house`
  timezone stays owner-gated with closed-set IANA validation, no
  secrets/PII logged.
- **Grep gates (§8)** — verified: no `.slice(0, 10)` or browser-local
  day keys left in `src/` (only a doc-comment mention in `dateKeys.js`);
  no `'monday this week'` / server-TZ-midnight preambles left in `lib/`
  (the `RoomTaskService::getRangeTasks` house-TZ reinterpretation of the
  controller's `Y-m-d` input is the documented exception); entity
  `setTime(0,0,0)` sites are the tz-parameterized day-math methods.
- **Auto-fixes (4)** — three escape-style text corruptions in the NEW
  `architecture.md` conventions section (`ew DateTime` → `new \DateTime`,
  TAB-ated letters in `todayKeyInZone`/`addDaysToKey`/`timezone`
  restored) + one pre-existing corruption of the same class in
  `docs/backlog/archive/2026-08.md` (CS-PLAN-043 record:
  `esting/release-test-plan.md` + U+FFFD → `testing/release-test-plan.md §11`);
  CRLF → LF normalization in 5 files the changeset wrote with Windows
  line endings (`.gitattributes` enforces `eol=lf`).
- **Backlog items added** — none (no deferred findings).
- **Gate re-verified green after fixes** — lint:fix + stylelint:fix 0/0 ·
  Jest 80 suites / 778 tests · Docker PHPUnit unit 781 / integration 211
  [21 pre-existing skips] · l10n:check-keys · docs:encoding 614 clean ·
  test:noise clean · cs:check 0/258.
