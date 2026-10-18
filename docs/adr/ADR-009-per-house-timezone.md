# ADR-009: Per-House Timezone for Calendar-Day Semantics

**Status**: Accepted
**Date**: 2026-08-30
**Deciders**: ChoreStrike contributors (design decision: user, 2026-08-29)
**Implements**: CS-PLAN-057 (closes CR-053-12, CR-P4-07 day-key unification; partially closes CR-053-11(a) and CR-053-20/CR-P4-06)

## Context

> **Target clarification (2026-09-17):** this ADR describes the implemented
> per-house timezone baseline, including its accepted mid-period change limitation.
> [CS-PLAN-070 C5](../plans/done/cs-plan-070-family-rhythm-and-reward-clarification.md#c5-delivery-and-ux-decision-log-2026-09-17)
> now requires preserved historical/agreed time bases, explicit amendment of
> existing commitments and bounded digest delivery across timezone changes.
> The [delivery concept](../concepts/task-model-and-sync.md#approved-delivery-contract-c5-2026-09-17)
> records that target; exact versioning and implementation await review. The
> original limitation below is not permission to rewrite the newly agreed history.

> **Occurrence-timeline amendment (2026-09-20, CS-PLAN-072 WP1 / ADR-013):**
> a house timezone edit now appends an immutable schedule revision
> (`OccurrenceService::reviseForZoneChangeUnderLock`). Already-saved occurrences
> keep their stored zone, `day_start` and half-open window deadlines; only
> agreements generated after the change resolve in the new zone. This delivers
> the preserved historical/agreed time base the C5 target requires for the
> timeline portion (bounded digest delivery remains a later package).

> **Digest-schedule amendment (2026-09-29, CS-PLAN-070 WP5 / ADR-016):**
> `CalendarService::localTimeToInstant(dayKey, 'HH:mm', tz)` resolves
> house-local wall times to UTC instants with the C5b DST rules (spring gap →
> first valid later instant, autumn fold → first occurrence) — the primitive
> behind the digest slot schedule (`DigestScheduleService`). A timezone edit
> re-resolves pending digest slots in the new zone under the 24-elapsed-hour
> spacing rule (`DigestScheduleService::reviseForZoneChangeUnderLock`),
> completing the bounded digest delivery the C5 target asked for.

Every calendar-day semantic in ChoreStrike — streak days, freeze coverage,
at-risk detection, due-today windows, energy windows, medal/leaderboard
weeks, and the duplicate-completion guard — bucketed days at **server-TZ
midnight**. A Nextcloud server runs UTC, so for any household not in UTC,
"today", "this week" and streak continuity flipped at the wrong local
midnight, and DST transitions shifted occurrences by an hour. The frontend
had two coexisting day-key conventions that could disagree (a UTC ISO-slice
in the calendar vs browser-local keys in scheduling views).

## Decision

**One timezone per house, threaded explicitly — no global state.**

1. **Schema**: `chorestrike_houses.timezone` (`VARCHAR(64) NOT NULL DEFAULT 'UTC'`,
   migration Version1028). At house creation the creator's Nextcloud
   timezone (`core.timezone` user preference, falling back to the server
   default) is captured — never a bare UTC default when avoidable.
   Owner-editable via `PUT /house` with closed-set validation against IANA
   identifiers (unknown id → 400 VALIDATION_ERROR).
2. **`CalendarService`** (`lib/Service/CalendarService.php`) is the single
   source of day/week arithmetic: `getTimeZone(house)`, `dayBounds(now, tz)`
   (half-open `[start, end)` window of the house-local day),
   `weekStart(now, tz)` (Monday 00:00 house-local — with correct Sunday
   semantics, fixing the `'monday this week'` bug that returned the NEXT
   Monday on Sundays), and `dayKey(now, tz)`.
   - Boundary DateTimes are normalized to **UTC** because the DB layer
     formats bound parameters in the value's own timezone and every stored
     timestamp is a UTC wall-clock string; a UTC-normalized boundary
     represents the correct absolute instant in all SQL comparisons.
   - Day/week *keys* (`Y-m-d`) are computed in the house timezone; they are
     local labels, never compared against stored timestamps directly.
3. **Explicit threading**: every service resolves the house timezone once
   per request/job pass and passes either the `DateTimeZone` or precomputed
   bounds down the call chain (`RoomService`, `RoomTaskService`,
   `HouseService`, `GameLogicService`, `MinigameService`,
   `SchedulingService`, `StreakFreezeService`, `StreakAtRiskService`,
   `TaskDueReminderService`, controllers). Mapper day-window methods
   (`CompletedTaskMapper` ×4, `RoomTaskMapper` ×3) take explicit
   `[start, end)` bounds instead of hand-rolled server-midnight preambles;
   `RoomTask`/`HouseUser` day-math methods take an optional
   `?\DateTimeZone` (server-TZ fallback only for `json_encode`-style
   callers).
4. **Frontend**: `src/utils/dateKeys.js` provides `dateKeyInZone()` (Intl-
   based, `en-CA` `YYYY-MM-DD` in the house timezone), `todayKeyInZone`,
   `addDaysToKey` and `weekdayOfKey` — the single day-key convention. The
   house store exposes `timezone` (from `GET /house`); the owner edits it in
   Settings → House via a curated-subset select (backend validates against
   the full IANA set, so out-of-list values still round-trip).
5. **Background jobs** (freeze, at-risk, due reminders) resolve the single
   instance house per pass (`HouseMapper::findOneForJobs()`) and bucket in
   its timezone. No house yet (bootstrap race) → the pass is a no-op.

## Consequences

### Positive
- Streaks, freezes, due-today, energy windows, duplicate guard and
  medal/leaderboard weeks flip at the household's local midnight; DST
  transitions yield 23/25-hour windows that still span exactly one local
  day.
- The frontend has exactly one day-key convention (house timezone); the
  UTC-slice and browser-local conventions are gone.
- The ×7 duplicated day-boundary preambles in the mappers are replaced by
  explicit window parameters.

### Negative
- An owner changing the timezone mid-period shifts day/week keys: streak
  continuity and medal/weekly-goal dedup keys can double-count or skip in
  the transition week. Accepted (pre-release, no production data); the
  integration pins document the per-TZ week-key shape.
- Background jobs still assume one house per instance (existing design
  invariant). A multi-house future requires per-house passes — noted here,
  deliberately not built.
- `RoomTask::jsonSerialize()` without an explicit zone falls back to the
  server timezone for the derived `status`/`daysUntilDue` fields; API
  callers thread the house timezone explicitly.

### Neutral
- The Settings timezone select ships a curated subset (`TIMEZONE_GROUPS`);
  it is a UI affordance, not a limit — the API accepts any valid IANA id.
- The E2E container runs UTC; timezone behavior is pinned at the
  unit/integration level (DST ×3 zones, Berlin-midnight completion).

## Alternatives Considered
- **Server-side user timezones** (bucket per completing user): rejected —
  gamification state (streaks, medals, weekly goals) is household-level;
  per-user buckets would make shared semantics incoherent.
- **UTC everywhere + client-side rendering**: rejected — server decisions
  (duplicate guard, freeze passes, due reminders) need the correct local
  day themselves.
- **Instance-wide timezone setting**: rejected — a single Nextcloud
  instance hosts one household today, but the timezone is a household
  attribute, not a server attribute; per-house storage costs nothing more
  and survives multi-house.

## References
- Plan: `docs/plans/done/cs-plan-057-per-house-timezone.md`
- Review findings: CR-053-12, CR-053-11(a) (CR-P2-11), CR-053-20 (CR-P4-06/07)
- `docs/engineering/architecture.md` — day-boundary conventions
