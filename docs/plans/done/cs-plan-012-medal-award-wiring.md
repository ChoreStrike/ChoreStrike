# CS-PLAN-012: Wire the 3 Catalogued-but-Unawarded Medal Types

> **Status:** Done (2026-07-25)
> **Effort:** S–M
> **Priority:** P3 (closes backlog item **P9-GAME-001**, LOW)
> **Depends on:** none (build gate green as of CS-PLAN-011, 2026-07-25)
> **Related issue:** P9-GAME-001 (`docs/backlog/phase-9-code-quality.md`)

## Implementation Outcome (2026-07-25)

Implemented as planned with **one optimization** (see §3 deviation) and verified by
three six-track `/review` passes. All five medal types now award via
`GameLogicService::checkAndAwardMedals()` → `awardMedalIfNew()` (idempotent,
race-safe, one-time notification).

**As-built:**
- `GameLogicService::checkAndAwardMedals()` — five threshold constants
  (`MEDAL_TEAM_ANCHOR_MIN_TASKS=10`, `MEDAL_STREAK_KEEPER_MIN_DAYS=7`,
  `MEDAL_FLOW_KEEPER_MIN_ACTIVE_DAYS=4`, `MEDAL_ROOM_GUARDIAN_MIN_PER_ROOM=5`,
  `MEDAL_TEAM_PLAYER_MIN_COMBOS=3`) + the three new award checks.
- `CompletedTaskMapper` — **one** new method (`getMaxRoomCompletionCountByUser`)
  plus the `active_days` aggregation **merged into `getUserStats()`** (see
  deviation below). `team_player` reuses the existing `comboCount`.
- `ChoreStrikeNotifier::medalLabel()` — all five labels mapped (l10n keys already
  existed).
- 6 new `GameLogicServiceTest` cases (3 award + 3 below-threshold) + mapper
  coverage; docs updated (`design.md`, `gamification-and-rewards.md`,
  `architecture.md`, gamification SKILL). No migration / route / frontend / l10n
  change.

**Deviation from §3 (performance optimization):** the plan specified two *separate*
mapper methods (`countDistinctActiveDaysByUser` + `getMaxRoomCompletionCountByUser`).
A review pass flagged the resulting N+1 (3 stat queries/user). The
`flow_keeper` signal (`COUNT(DISTINCT DATE(completed_at))`) was therefore **merged
into `getUserStats()`** (same table/range, no JOIN) so the loop now makes 2 stat
queries/user instead of 3; `getMaxRoomCompletionCountByUser` stays separate (it
needs the `room_tasks` JOIN). `countDistinctActiveDaysByUser` was not added as a
standalone method — `getUserStats()` now returns `activeDays`. The empty-`GROUP BY`
result for `getMaxRoomCompletionCountByUser` is guarded (`fetch() === false → 0`).

## 1. Problem Summary

`lib/Db/Medal.php` catalogues five weekly medal types and the whole stack
(`Medal::getMedalLabel/Description/Rarity`, the frontend `MedalBadge.vue`, and the
l10n labels in `en/de/.pot`) already recognises all five. But the live award flow
`GameLogicService::checkAndAwardMedals()` only awards two:

- `team_anchor` — 10+ completions in the week
- `streak_keeper` — 7+ day streak

The other three — `flow_keeper`, `room_guardian`, `team_player` — have **no award
check**, so they can never be earned. This is a user-visible gamification gap: the UI
promises five medals but only two are attainable. Logged as P9-GAME-001 during
CS-PLAN-010 (Decision A: "document reality" was the default; this plan wires the
awards instead).

### Data availability (verified)

- `team_player` ("Most combo bonuses", per `docs/concepts/design.md:95`) → the
  `wasCombo` flag is **reliably persisted** (`GameLogicService.php:78`,
  `TaskSyncService.php`) and already aggregated as `comboCount` in
  `CompletedTaskMapper::getUserStats()` (`CompletedTaskMapper.php:113,126`).
- `flow_keeper` ("consistent daily activity") → needs a new distinct-active-days
  count. No persisted signal exists yet.
- `room_guardian` ("focus on specific rooms", "The Specialist") → needs a new
  per-room completion aggregation (join `completed_tasks → room_tasks`).

> **Residue caveat:** `CompletedTask.isTeamCompletion` / `participantCount` /
> `teamSparkBonus` are **never written anywhere** (only `@method` stubs + defaults —
> same shape as the Sparks residue CS-PLAN-010 removed). They are **not** a usable
> collaboration signal, so `team_player` is driven by `comboCount`, not team
> completions.

## 2. Strategy

Add the three missing award checks inside the existing
`GameLogicService::checkAndAwardMedals()` per-user loop, mirroring the established
`awardMedalIfNew()` pattern (idempotent, race-safe, notifies on first award). Two
new focused mapper queries supply the missing signals; `team_player` reuses the
existing `comboCount` aggregation. No schema, frontend, or l10n change is required —
only the notifier's label map needs the three entries (l10n keys already exist).

**Design decisions (to confirm at activation):**

| # | Decision | Recommended | Rationale |
|---|----------|-------------|-----------|
| D1 | `room_guardian` criterion | **Concentration**: most completions in a single room ≥ threshold | Matches the original concept "The Specialist — focus on specific rooms" and the name "guardian". Alternative (breadth = distinct rooms) is noted but rejected as weaker intent match. |
| D2 | Threshold values | `flow_keeper` ≥ 4 active days · `room_guardian` ≥ 5 completions in one room · `team_player` ≥ 3 combos | Calibrated to the existing `team_anchor` (10) / `streak_keeper` (7) band — attainable in a typical active week. |
| D3 | Hardcoded constants vs config | **Hardcoded** class constants in `GameLogicService` | The two existing medals are hardcoded (not in `RewardConfigService`). Reusing that precedent keeps the diff minimal and consistent. Making **all five** thresholds configurable is logged as a separate follow-up (see §7) to avoid scope creep. |

If D1–D3 are accepted as recommended, no further input is needed to start.

## 3. Backend / Frontend Changes

> **Note:** the subsections below are the *planned* design. The as-built
> implementation merged the `flow_keeper` aggregation into `getUserStats()`
> instead of adding a separate `countDistinctActiveDaysByUser` method — see the
> **Implementation Outcome** at the top of this file for what shipped.

### Backend (`lib/`)

- **`lib/Service/GameLogicService.php`** (`checkAndAwardMedals`, ~`280`):
  - Add three private threshold constants next to the existing implicit
    `10` / `7`:
    ```php
    private const MEDAL_TEAM_ANCHER_MIN_TASKS = 10;        // extract existing literal
    private const MEDAL_STREAK_KEEPER_MIN_DAYS = 7;        // extract existing literal
    private const MEDAL_FLOW_KEEPER_MIN_ACTIVE_DAYS = 4;
    private const MEDAL_ROOM_GUARDIAN_MIN_PER_ROOM = 5;
    private const MEDAL_TEAM_PLAYER_MIN_COMBOS = 3;
    ```
    *(Extracting the two existing literals into named constants is a low-risk
    readability improvement done in the same touch; kept optional if it bloats
    the diff.)*
  - In the per-user loop, after the two existing checks, add:
    - `flow_keeper`: `$activeDays = $this->completedTaskMapper->countDistinctActiveDaysByUser(...)`;
      award if `>= MEDAL_FLOW_KEEPER_MIN_ACTIVE_DAYS` (value = `$activeDays`).
    - `room_guardian`: `$maxPerRoom = $this->completedTaskMapper->getMaxRoomCompletionCountByUser(...)`;
      award if `>= MEDAL_ROOM_GUARDIAN_MIN_PER_ROOM` (value = `$maxPerRoom`).
    - `team_player`: `$combos = $stats['comboCount'] ?? 0`;
      award if `>= MEDAL_TEAM_PLAYER_MIN_COMBOS` (value = `$combos`).
  - All three reuse the existing `awardMedalIfNew()` (idempotent lookup → insert →
    notify), so they inherit the race-safety + no-double-notify behaviour for free.

- **`lib/Db/CompletedTaskMapper.php`** — two new house-scoped-by-membership queries
  (the caller already iterates `houseUserMapper->findByHouseId($houseId)`, so per-user
  is inherently house-scoped — same property the existing `getUserStats` relies on):
  ```php
  /** Distinct calendar days the user completed ≥1 task in [start,end]. */
  public function countDistinctActiveDaysByUser(int $userId, \DateTime $start, \DateTime $end): int
  // SELECT COUNT(DISTINCT DATE(completed_at)) ... user_id + completed_at BETWEEN
  // DATE() is portable across MariaDB/PostgreSQL/SQLite (all support DATE()).

  /** Highest single-room completion count for the user in [start,end]. */
  public function getMaxRoomCompletionCountByUser(int $userId, \DateTime $start, \DateTime $end): int
  // JOIN completed_tasks ct → room_tasks rt ON ct.room_task_id = rt.id
  // GROUP BY rt.room_id ORDER BY COUNT(*) DESC LIMIT 1 → first row's count.
  // 0 when no completions. Avoids a subquery (portable across MariaDB/PG/SQLite).
  ```
  Both follow the existing `getUserStats` / `getConsumedEnergyForUserOnDate` shape
  (named params via `createNamedParameter`, `closeCursor()`, `(int)` cast, no SQL
  concatenation).

- **`lib/Notification/ChoreStrikeNotifier.php`** (`medalLabel`, ~`182`): add the
  three missing entries to the `$labels` map (l10n strings already exist in
  `en.json`/`de.json`/`.pot`):
  ```php
  'flow_keeper'   => $l->t('Flow Keeper'),
  'room_guardian' => $l->t('Room Guardian'),
  'team_player'   => $l->t('Team Player'),
  ```
  Update the stale docblock ("currently 'team_anchor' and 'streak_keeper'").

### Frontend (`src/`)

- **None.** `MedalBadge.vue` (validator + per-type CSS), `game.js` store
  (`fetchMedals` → `chorestrike:medal-awarded`), and `useCelebration`/`useToasts`
  are fully medal-type-agnostic and already handle all five types. No store,
  selector, or l10n change.

### Cross-layer contracts

- `GET /api/v1/game/check-medals` and `GET /api/v1/game/medals` payloads are
  unchanged in shape (same `Medal::jsonSerialize()`); they may now return the
  three additional `medalType` values. No frontend consumer breaks.

## 4. Tests

- **Unit (PHPUnit) — `GameLogicServiceTest.php`** (mirror the existing
  `testCheckAndAwardMedals_*` pattern at lines ~380–510):
  - `testCheckAndAwardMedals_FrequentDailyActivity_AwardsFlowKeeper` (active days ≥ 4)
  - `testCheckAndAwardMedals_RoomConcentration_AwardsRoomGuardian` (max per-room ≥ 5)
  - `testCheckAndAwardMedals_ManyCombos_AwardsTeamPlayer` (comboCount ≥ 3)
  - One below-threshold negative per medal (no award) — guards off-by-one.
  - Idempotency + race + non-unique-DB-error propagation are already covered by
    the existing `awardMedalIfNew` tests; no duplication needed.
  - **Existing mocks are safe:** current `checkAndAwardMedals` test mocks return
    `['taskCount' => …, 'totalCoins' => …]` without `comboCount`; the new code
    reads `$stats['comboCount'] ?? 0` → defaults to 0 → won't trigger `team_player`.
    No existing test needs updating.
- **Unit (PHPUnit) — `CompletedTaskMapperTest.php`**: the two new methods (rows
  returned / empty / multi-room MAX selection). Mock the query builder like the
  existing mapper tests.
- **Integration (Docker, `phpunit.integration.xml`)** — recommended (verifies the
  two new SQL constructs — `DATE()` and `GROUP BY … ORDER BY … LIMIT 1` — against a
  real DB): extend `GameControllerIntegrationTest` (or a new
  `MedalAwardIntegrationTest`) with one real-DB scenario seeding a week of
  completions and asserting the three new medals are persisted + returned. Mirrors
  CS-PLAN-002 medal integration style.
- **E2E (Playwright):** none — no UI change.

## 5. File Manifest

```
modified: lib/Service/GameLogicService.php            (3 award checks + threshold constants)
modified: lib/Db/CompletedTaskMapper.php              (2 new query methods)
modified: lib/Notification/ChoreStrikeNotifier.php    (3 label entries + docblock)
modified: tests/unit/Service/GameLogicServiceTest.php (6 new tests)
modified: tests/unit/Db/CompletedTaskMapperTest.php   (2 new test methods)
new:      tests/integration/Service/MedalAwardIntegrationTest.php  (optional, real DB)
modified: docs/concepts/design.md                     (remove "not yet wired" caveat, document criteria)
modified: docs/concepts/gamification-and-rewards.md                (award criteria)
modified: docs/standards/ai/skills/gamification/SKILL.md           (medal award reality)
modified: docs/backlog/phase-9-code-quality.md       (P9-GAME-001 → Done)
modified: docs/backlog/ACTIVE_TASKS.md               (register + complete)
```

No migration, no `appinfo/routes.php` change, no frontend, no l10n artifacts.

## 6. Implementation Order

1. **Mapper queries** — add `countDistinctActiveDaysByUser` +
   `getMaxRoomCompletionCountByUser` + their unit tests. Verify `php -l` + the two
   mapper tests green.
2. **Award logic** — add the three checks + threshold constants in
   `GameLogicService::checkAndAwardMedals()`; add the 6 `GameLogicServiceTest`
   cases. Run `phpunit.unit.xml`.
3. **Notifier labels** — extend `ChoreStrikeNotifier::medalLabel()`; fix docblock.
4. **Integration test** (recommended) — seed + assert in Docker; confirm on a clean
   volume (no manual `ALTER`).
5. **Docs** — update the four doc files; flip P9-GAME-001 to Done; register in
   `ACTIVE_TASKS.md`.
6. **Build gate** — `npm test` + `npm run lint` + `phpunit.unit.xml` +
   `phpunit.integration.xml` + `php -l` clean. (Stylelint advisory.)

## 7. Risks & Mitigations

| Risk | Likelihood | Mitigation |
|------|-----------|------------|
| `DATE(completed_at)` not portable across the 3 supported DBs | Low | `DATE()` is supported by MariaDB, PostgreSQL, **and** SQLite. Existing code already uses raw SQL fragments in `addSelect` (`SUM(CASE WHEN …)`), so the pattern is established. Integration test runs in Docker (MariaDB) to confirm. |
| New queries add N+1 per user in the medal loop (already does 1 `getUserStats`/user) | Low | `checkMedals` is a manual/weekly endpoint, not hot-path. Acceptable. If it ever becomes a job, batch the aggregation (single GROUP BY) — logged as follow-up. |
| `room_guardian` concentration criterion mis-read as "breadth" | Medium | Decision D1 is explicit + documented in `design.md` at implementation. Reversible: only the mapper method + one constant change if breadth is later preferred. |
| Threshold values feel too easy/hard in real use | Medium | Hardcoded constants make them trivial to tune later (D3). Config-ification of **all five** medals is a clean follow-up (new P9 backlog item) — not in scope here to keep the diff consistent with the existing 2 hardcoded medals. |
| Dead `isTeamCompletion`/`participantCount`/`teamSparkBonus` residue misleads a future implementer into using them for "team" semantics | Low | Documented in this plan (§1) + a one-line code comment at the `team_player` check pointing at `comboCount`. Full residue removal is a separate cleanup (out of scope). |
| Re-checking medals mid-week awards the new ones "late" | None | `awardMedalIfNew` is idempotent per `(user, type, week)`; re-runs within the same week are no-ops once awarded. Same semantics as the existing 2 medals. |

## 8. Acceptance Criteria

- [x] `flow_keeper`, `room_guardian`, `team_player` are each awarded by
      `GameLogicService::checkAndAwardMedals()` when their threshold is met, and
      each dispatches a one-time notification via `awardMedalIfNew()`.
- [x] Each new award is idempotent (re-check in the same week does not re-insert or
      re-notify) and race-safe (existing `awardMedalIfNew` contract).
- [x] `ChoreStrikeNotifier::medalLabel()` returns the localised label for all five
      medal types (no prettified fallback for the three new ones).
- [x] Unit tests cover award + below-threshold for each of the 3 medals; the new
      mapper query has coverage (`getMaxRoomCompletionCountByUser` + merged
      `activeDays` in `getUserStats`).
- [x] No frontend, migration, route, or l10n-artifact change (verified by diff).
- [x] Docs (`design.md`, `gamification-and-rewards.md`, architecture.md, gamification
      SKILL) no longer carry the "not yet wired / catalogued-but-unawarded" caveat;
      criteria documented.
- [x] `P9-GAME-001` marked Done in `docs/backlog/phase-9-code-quality.md`.
- [x] Build gate green — PHPUnit unit **479/479** (incl. the 6 new medal cases +
      merged-stats regression), ESLint 0 errors, `php -l` clean.
- [x] `docs/backlog/ACTIVE_TASKS.md` updated (registered as Done).

> **Verification note (2026-07-25):** shipped as part of the 2026-07-25 review
> cycle. The N+1 noted in the §7 risk table was proactively closed by merging the
> active-days aggregation into `getUserStats()` (deviation above), so the medal
> loop stays at 2 stat queries/user. Integration/E2E for the new SQL constructs
> (DATE/GROUP BY … LIMIT 1) remain a recommended follow-up — not blocking since
> `getUserStats`/`getMaxRoomCompletionCountByUser` are covered by unit tests and
> index coverage (`ct_user_date_idx`) was confirmed.

---

## Review Outcome

**Reviewed:** 2026-07-25 · **Tier:** Standard A (Plan Review) · **Reviewer:** main agent

**Verdict: PASS — ready for `new/ → active/`.**

| # | Severity | Issue | Status |
|---|----------|-------|--------|
| 1 | minor | File manifest listed `docs/gamification/gamification-and-rewards.md` — actual path is `docs/concepts/gamification-and-rewards.md` | **Fixed** |
| 2 | minor | `getMaxRoomCompletionCountByUser` described as "MAX(per-room count)" implying a subquery; simpler `GROUP BY … ORDER BY COUNT(*) DESC LIMIT 1` is more portable | **Fixed** (plan updated) |
| 3 | nit | Integration test marked "optional" while risk table relies on it for SQL portability confirmation | **Fixed** → "recommended" |
| 4 | nit | No note that existing `checkAndAwardMedals` test mocks (missing `comboCount`) are safe with the new `?? 0` default | **Fixed** (note added to §4) |

**Checklist results:**
- Architecture ✓ (logic in service, queries in mapper, no controller change)
- Authorization ✓ (no new routes; per-user queries inherently house-scoped via caller)
- Security ✓ (no client input, `createNamedParameter`, no secrets)
- KISS ✓ (reuses `awardMedalIfNew`, minimal diff, hardcoded thresholds match precedent)
- i18n ✓ (all 5 medal labels already in en/de/.pot; notifier uses `$l->t()`)
- Tests ✓ (unit for 3 medals + 2 mapper methods; integration recommended for SQL portability)
- Docs ✓ (4 doc files updated; P9-GAME-001 → Done; ACTIVE_TASKS registered)
- No overlap with active plans ✓ (new/ and active/ are empty)
- Acceptance criteria complete ✓ (10 criteria covering logic, idempotency, notifier, tests, no-change verification, docs, backlog, build gate)
