# CS-PLAN-057: Per-House Timezone for Calendar-Day Semantics (U1-A / CR-053-12)

> **Status:** Plan (not started)
> **Effort:** L
> **Priority:** P2
> **Depends on:** none (independent of CS-PLAN-055/056; different file families — can run in parallel after 055 lands to avoid mapper-context churn)
> **Closes:** CR-053-12 (incl. the CR-P4-07 day-key unification) · Partially closes: CR-053-11(a) (see D3), CR-P4-06 (null-date guard folded in)

## 1. Problem Summary

All calendar-day semantics bucket at **server-TZ midnight** — and a Nextcloud server runs UTC: streak days, freeze coverage, at-risk detection, due-today windows, energy windows, medal/leaderboard weeks, and the duplicate-completion day guard all flip at UTC midnight instead of the household's local midnight (review CR-P2-14: `StreakFreezeService:43`, `HouseUser:176-179`, `RoomTask:185-221`, `RoomTaskMapper:143-146,360,393`, `CompletedTaskMapper` day-boundary preambles ×6, `GameLogicService:260-261`, `HouseService:388`, `MinigameService:163`, `TaskDueReminderService`, `RoomTaskController:888`). For any non-UTC household, "today", "this week", and streak continuity are wrong around local midnight and across DST.

The frontend has two coexisting day-key conventions that can disagree: `roomTasks.js:130-158 rangeTasksByDay` slices the **UTC** ISO string (`.slice(0, 10)`) while `scheduling.js:23 localDateKey` builds **browser-local** keys (CR-P4-07) — the same task can land on different days in TaskCalendar vs. scheduling/overcapacity views.

**Design decision (user, 2026-08-29): per-house timezone.** `houses.timezone` column; the backend buckets all day boundaries in the house TZ (explicit `DateTimeZone` injection at the call sites); the frontend unifies both day-key conventions on the house TZ.

## 2. Strategy

One timezone source of truth per house, threaded **explicitly** (no global state, no `\OC::$server`): a new migration adds `houses.timezone`; a small `CalendarService` (pure, unit-testable) computes day/week windows for a `(DateTime, DateTimeZone)` pair with correct Monday week-start semantics (adopted from `GameLogicService::getWeekStart:495`, the correct sibling); services resolve `$house->getTimezone()` once per request/job pass and pass either the `\DateTimeZone` or precomputed `[start, end)` bounds into mappers and entity methods. This simultaneously de-duplicates the ×6 day-boundary preambles the review flagged (L1-5 copy-paste cluster). On the frontend, the house store exposes `timezone` (from the existing `GET /house` serialization) and one shared `dateKeyInZone(iso, tz)` util (Intl-based) replaces both the UTC slice and the browser-local `localDateKey`. DST correctness is pinned by unit/integration tests on both sides of a transition.

## 3. Backend / Frontend Changes

### 3.1 Backend

- **Schema:** new migration `Version1028…` adds `houses.timezone` (`VARCHAR(64) NOT NULL DEFAULT 'UTC'`; column add on an existing table follows the Version1023 precedent). `House` entity: typed field + `jsonSerialize` gains `timezone`. **D1 (creation default):** capture the creator's NC timezone at house creation (`IConfig->getUserValue($uid, 'core', 'timezone')`, fallback `date_default_timezone_get()`), not a bare `'UTC'`.
- **Validation + surface:** `HouseController::update` (already owner-gated) accepts `timezone`; closed-set validation against `\DateTimeZone::listIdentifiers()` → 400 `VALIDATION_ERROR` on unknown ids; message via `t()`, l10n synced.
- **`CalendarService`** (`lib/Service/CalendarService.php`, ~80 lines): `getTimeZone(house)`, `dayBounds(\DateTimeInterface $now, \DateTimeZone $tz): array{\DateTime,\DateTime}` (`[start, end)` convention), `weekStart($now, $tz)` (Monday, correct "monday this week" semantics), `dayKey($now, $tz)`.
- **Call-site threading** (each: resolve tz once at the entry, pass down explicitly):
  - `RoomService::completeRoomTask` — duplicate-guard day (`countByRoomTaskAndUserOnDate` at :383-387) and the recurrence/markCompleted path get house-TZ bounds/`DateTimeZone`.
  - `RoomTask` entity day math (:185-221 due-today/is-overdue, :471 flex window, :604-605 projection anchor, :713 weekly origin) — methods gain an explicit `?\DateTimeZone` parameter supplied by the calling service (no silent default on the hot path).
  - `RoomTaskMapper` (:143-146 dueToday bounds, :360/:393 day windows) — accept bounds as parameters (replaces the hand-rolled preambles).
  - `CompletedTaskMapper` day-boundary preambles ×6 (:187, :308, :331, :358 + medals windows) — same bounds-parameter treatment.
  - `GameLogicService` (:260-261 days-early compare, `getWeekStart:495` → delegate to CalendarService), streak/freeze/at-risk passes (`StreakFreezeService:43`, `StreakAtRiskService`, `HouseUser:176-179` streak-day compare — compare moves behind CalendarService day keys), `HouseService:388` weekly-goal week key, `MinigameService:163` weekly leaderboard (**D3**: fix the Sunday `'monday this week'` bug CR-P2-11 here, since the helper is rewritten anyway — recommended fold-in), `TaskDueReminderService` day key, `RoomTaskController::888` range normalization, `RoomTaskService::getFilteredDailyTasksForUser` `$date` bucketing.
  - Background jobs resolve the house (single-house-per-instance invariant holds today) → tz per pass; multi-house future noted in the ADR only.
- **`HouseController::update` + Settings API:** see frontend.

### 3.2 Frontend

- `src/store/house.js`: state gains `timezone` (from `GET /house` payload); owner Settings → House section gains a timezone `<select>` (grouped `\DateTimeZone::listIdentifiers()` — ship a curated subset list constant; save via existing `PUT /house`), i18n keys + l10n sync.
- New `src/utils/dateKeys.js`: `dateKeyInZone(dateIso, timeZone)` → `YYYY-MM-DD` via `Intl.DateTimeFormat('en-CA', { timeZone, … })`; fallback `UTC` when the house isn't loaded yet.
- Replace both conventions: `roomTasks.js rangeTasksByDay` `.slice(0,10)` → `dateKeyInZone(..., houseStore.timezone)`; `totalDueTodayTasks`/`totalOverdueTasks` getters (:104-121) → house-TZ day bounds **and** the `new Date(null)`-epoch guard folded in (CR-P4-06 same lines); `scheduling.js` module-local `localDateKey` → shared util with house TZ (overcapacity/energy-today buckets); day-bucketing in TaskList/TodayView/ScheduleCalendar aligned where they bucket by day key.
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
modified: l10n/{en.json,de.json,chorestrike.pot}
modified: docs/api/README.md · docs/engineering/{architecture,testing}.md · docs/adr/ADR-009-per-house-timezone.md (new) · docs/backlog/{backlog.md,ACTIVE_TASKS.md}
```

## 6. Implementation Order

1. Schema + entity + `HouseController::update` (D1 creation default) + `CalendarService` + unit tests.
2. Completion-path threading (duplicate guard, streak day, `RoomTask` entity params) + integration tests.
3. Remaining backend call sites (mapper bounds params, week windows, jobs; D3 fold-in) + tests.
4. Frontend: house store + `dateKeys.js` + both convention replacements + Settings select + l10n.
5. ADR-009 + architecture/testing docs (day-boundary conventions section) + backlog moves (CR-053-12 → archive; CR-053-11(a)/CR-P4-06 partial-close notes).
6. Full build gate (`npm test` + lint + stylelint + Docker PHPUnit + blocking E2E project).

## 7. Risks & Mitigations

| Risk | Likelihood | Mitigation |
|------|-----------|------------|
| TZ threading misses a site → mixed-TZ state worse than uniform UTC | medium | Inventory table in §3.1 is the tracked checklist; grep gate: no bare `new \DateTime('…')` day-math outside CalendarService callers; integration test pins end-to-end day bucketing |
| Entity API churn (`RoomTask` gains tz params) breaks callers | medium | Explicit nullable param + all call sites updated in the same step; unit suite pins each |
| DST edge cases (gap/overlap) shift occurrences | medium | CalendarServiceTest pins both transitions in 3 zones; `[start,end)` bounds avoid double-count windows |
| House TZ absent (house not loaded) in frontend | low | `dateKeyInZone` UTC fallback + loading state; Jest pin |
| Scope explosion across ~15 files | medium | Phased order (completion path first, highest value); each phase independently green |

## 8. Acceptance Criteria

- [ ] `houses.timezone` exists; captured at creation (D1) and owner-editable in Settings with closed-set validation
- [ ] All day-boundary logic (streaks, freezes, at-risk, due-today, energy windows, duplicate guard, medal/leaderboard weeks) buckets in house TZ — no site computes server-TZ day bounds (grep gate)
- [ ] Frontend has exactly one day-key convention (`dateKeys.js`, house TZ); UTC-slice and browser-local keys are gone (grep gate)
- [ ] D3 resolved (recommended: Sunday leaderboard fix folded in with pin); `new Date(null)` epoch guard in place
- [ ] Tests as §4 (DST ×3 zones, integration midnight-crossing); build gate green
- [ ] ADR-009 records the per-house-TZ decision; conventions in `docs/engineering/`; CR-053-12 moved to archive; ACTIVE_TASKS updated
