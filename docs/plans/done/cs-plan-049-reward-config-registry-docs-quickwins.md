# CS-PLAN-049: RewardConfig Registry & Docs Quick-Wins

> **Status:** Implemented + Standard-B reviewed 2026-08-26 (D1=thin delegates /
> D2=AppLogger required; gates green — moved to `done/`)
> **Effort:** M
> **Priority:** P2
> **Depends on:** none
> **Closes:** CR-SVC-16 (P2), P9-DOC-005 (P2); partially CR-SVC-15 (RewardConfigService bullet), CR-SVC-41 (RewardConfigService `?AppLogger`)

## 1. Problem Summary

`lib/Service/RewardConfigService.php` is 689 lines (CR-SVC-15's "607" predates the
CS-PLAN-045 clamping) and every one of its 15 scalar config keys is defined in
4–5 separate places: the `KEY_*` const, a typed getter, a typed setter, an
`isset()` branch in `updateConfig()` (lines 563–612, a 50-line mirror of
`getAllConfig()`), and an entry in `getAllConfig()` (lines 537–556). The three
tier multipliers additionally carry two `match` arms each. Adding or changing a
key requires 4–5 coordinated edits — a proven drift risk (CR-SVC-19 found
exactly this class of inconsistency).

Secondly, `docs/engineering/development.md` carries two example-code drifts
(P9-DOC-005, verified against the tree):

- line 186: fictional `TaskService::calculateNextDue(Task): \DateTime` — no such
  method exists (`TaskSchedulerService`/`SmartSchedulerService`/`lib/Service/Scheduling/`
  do not exist either); scheduling preferences are owned by `SchedulingService`
  (`lib/Service/SchedulingService.php`), due-date logic by
  `RoomTask::calculateIdealDueDate()` / `RoomTaskService::updateDueDate()`.
- line 380: `axios.post('/apps/ChoreStrike/api/v1/tasks/new', …)` — contradicts
  the canonical `src/services/api.js` client enforced by `apiContract.spec.js`.

Also, `RewardConfigService::__construct` still takes `?AppLogger $appLogger = null`
(line 42) — the silent null-skip risk CR-SVC-41 tracks; the CS-PLAN-035
migration made `AppLogger` required in 5 comparable services.

## 2. Strategy

Introduce a single private registry const in `RewardConfigService` mapping each
IAppConfig key to its full spec — `type` (`'int'|'float'|'bool'`), `default`
(as the stored string), `min`/`max` clamp bounds, and the camelCase API field
name. Two generic private helpers (`readSpec`/`writeSpec`) do the
read→cast→clamp (reusing the existing `clampInt`/`clampFloat`/`logClamp`) and
the cast→clamp→write work for every entry. The public typed getters/setters
stay as one-line delegates over the registry, so the public API is unchanged
and all consumers/mocks are untouched. `getAllConfig()` and `updateConfig()`
become loops over the registry instead of hand-mirrored literals. The
title-threshold paths (dynamic per-pack/tier keys, `TitleCatalog` fallback,
ascending validation) stay outside the scalar registry — they are a different
key shape and already cohesive. While touching the constructor, `AppLogger`
becomes required and non-nullable (CS-PLAN-035 precedent).

The docs quick-win is a two-spot edit in `development.md` (drop/replace the
fictional example, rewrite the endpoint example against the shared `api` client).

## 3. Backend / Frontend Changes

- **Backend** (`lib/`):
  - `lib/Service/RewardConfigService.php` (only production file changed):
    - new `private const REGISTRY` with 15 entries: the 3 tier multipliers,
      early-bonus threshold/multiplier, energy max, coin base, streak freezes
      per week, streak freeze cap, combo bonus freeze threshold, combo
      multiplier, weekly goal target percent, teamwork bonus enabled/max pct,
      titles enabled;
    - `readSpec(string $key): int|float|bool` / `writeSpec(string $key, mixed $value): void`
      — single read/clamp and cast/clamp/write path per type;
    - existing public getters/setters become thin delegates (signatures and
      semantics unchanged, incl. `getTierMultiplier`'s unknown-tier → `1.0`
      fallback and its per-tier `match` over registry keys);
    - `getAllConfig()` iterates the registry (insertion order = current literal
      order) and appends `titleThresholds` as today; `updateConfig()` iterates
      the registry with the same `isset()` skip semantics (explicit `null` in a
      payload is skipped, unknown fields ignored) plus the unchanged
      `titleThresholds` special case;
    - ctor: `AppLogger` required, non-nullable (D2).
  - No controller, route, migration, or consumer changes
    (`GameLogicService`, `HouseService`, `RewardConfigController`,
    `StreakRestockService`, `TitleService` use getters only; all 16 setter call
    sites verified to be internal to `RewardConfigService` itself).
- **Frontend** (`src/`): none. No frontend consumer of the reward-config
  endpoints exists (verified); the `getAllConfig()` response shape is still
  treated as a stable public API contract and kept byte-identical.
- **Cross-layer contracts:** none affected — API payload shapes, defaults,
  clamping, and ignore/skip semantics are preserved exactly.

## 4. Tests

- Unit (PHPUnit):
  - existing `tests/unit/Service/RewardConfigServiceTest.php` (~750 lines,
    defaults/clamp/round-trip pins) must stay green unchanged in its
    assertions; only its constructor call sites gain the now-required
    `AppLogger` argument (mock or the existing `AppLogger` mock pattern at
    lines 716–743);
  - new table-driven registry specs in the same file: every registry key
    (a) returns its default when unset, (b) clamps to min/max on **read and
    write** (CR-SVC-19 parity), (c) bool keys round-trip `'1'`/`'0'`;
  - new parity specs: `updateConfig` ignores unknown fields, skips explicit
    `null` values (isset semantics), applies partial payloads;
    `getAllConfig` exposes exactly the 16 current fields (incl. nested
    `titleThresholds`) — shape pin;
  - existing `tests/unit/Controller/RewardConfigControllerTest.php` green
    (thin-delegate contract).
- Integration (PHPUnit, Docker): real-DI wiring verified by the existing
  `TeamworkRewardTest` + `TitleServiceIntegrationTest` (both fetch
  `RewardConfigService` from `\OC::$server` — catches a non-nullable-DI
  regression).
- E2E: not affected (no frontend surface); run the release-gate smoke project
  only if the full gate run flags interaction.

## 5. File Manifest

```
modified: lib/Service/RewardConfigService.php
modified: tests/unit/Service/RewardConfigServiceTest.php
modified: docs/engineering/development.md
modified: docs/backlog/backlog.md            (CR-SVC-16 + P9-DOC-005 → resolved)
modified: docs/backlog/archive/2026-08.md    (completion records)
modified: docs/backlog/ACTIVE_TASKS.md       (registration → completion entry)
```

No new files; nothing archived.

## 6. Implementation Order

1. **Baseline:** run `RewardConfigServiceTest` + `RewardConfigControllerTest`
   green before touching anything.
2. **Registry core:** add `REGISTRY`, `readSpec`/`writeSpec`; convert all
   getters/setters to delegates; make `AppLogger` non-nullable (D2) and fix the
   test constructor call sites. Gate: unit tests green.
3. **Loop rewrites:** `getAllConfig()` + `updateConfig()` registry-driven with
   exact order/isset semantics. Gate: full unit suite + controller tests green.
4. **New specs:** table-driven registry + parity tests (§4).
5. **Docs quick-win (P9-DOC-005):** replace the `calculateNextDue` example with
   a real service method (e.g. `SchedulingService::getSchedulingSuggestions()` or
   `RoomTaskService::updateDueDate()`); rewrite the `axios.post` example to the
   shared `api` client from `src/services/api.js` (`API_PREFIX` = `/apps/chorestrike`).
6. **Knowledge integration:** one-short-section registry-pattern note where
   `docs/engineering/architecture.md` documents service idioms (if no fitting
   section exists, extend the existing config/write-idioms area — do not
   create a new doc); update CR-SVC-15's RewardConfigService line-count bullet.
7. **Full gate:** Docker PHPUnit unit + integration, `npm test`, `npm run lint`
   (`cs:fix` first), `npm run test:noise`; l10n untouched (no user-visible
   string changes — the ascending-threshold `InvalidArgumentException` message
   stays byte-identical).
8. **Backlog moves:** CR-SVC-16 and P9-DOC-005 → `archive/2026-08.md`;
   CR-SVC-15/CR-SVC-41 partial-closure notes; ACTIVE_TASKS completion entry.

## 7. Risks & Mitigations

| Risk | Likelihood | Mitigation |
|------|-----------|------------|
| Cast/coercion drift in the generic read/write path (e.g. `'0'`, floats stored as strings) | Medium | Table-driven per-key parity specs + the existing ~750-line behavior suite must stay green unchanged in its assertions |
| `isset()` → `array_key_exists()` semantic flip silently accepting `null` payloads | Medium | Keep `isset()` explicitly; pin skip-`null` behavior with a spec |
| `getAllConfig()` field set/order drift (public API shape) | Low | Registry insertion order mirrors the current literal order; shape-pinning spec |
| DI breakage from non-nullable `AppLogger` | Low | `AppLogger` is container-registered (CS-PLAN-035 precedent); real-DI integration tests cover the wiring |
| Title-threshold paths regressing by accidentally folding them into the registry | Low | Explicitly out of scope; their tests (`TitleService*`, ascending-validation specs) must stay green untouched |
| File stays above the ~300-line guideline after the refactor | Medium | Expected landing ~350–400 lines; record the actual count in CR-SVC-15 rather than forcing artificial splits (KISS) |

## 8. Acceptance Criteria

- [x] Each of the 15 scalar config keys is defined exactly once (its registry entry); no residual 4–5× duplication (`KEY_*` consts remain as the registry array keys).
- [x] Public API of `RewardConfigService` unchanged: all method signatures identical; consumers and existing mocks compile untouched.
- [x] Behavior parity: same defaults, same clamp bounds on read **and** write, same `getAllConfig()` shape (16 fields incl. nested `titleThresholds`), same `updateConfig()` ignore-unknown/skip-`null` semantics.
- [x] `AppLogger` is required and non-nullable in `RewardConfigService` (D2).
- [x] `RewardConfigService.php` significantly reduced from 689 lines; actual count recorded in the CR-SVC-15 bullet. (689 → 538)
- [x] `development.md` contains no fictional API examples; the endpoint example uses the shared `api` client (P9-DOC-005 closed).
- [x] Registry pattern knowledge integrated into the permanent engineering docs (§6 step 6).
- [x] Build gate green (`npm test` + lint + Docker PHPUnit unit/integration + noise).
- [x] `docs/backlog/ACTIVE_TASKS.md` updated; CR-SVC-16 + P9-DOC-005 moved to `archive/2026-08.md`.

## Activation Decisions (sign-off at `new/ → active/` time)

- **D1 — Public setters:** keep the individual typed setters as thin registry
  delegates (**recommended**: zero consumer/mock churn, stable API) vs. delete
  them and route everything through `updateConfig` (only internal callers
  exist; pre-release no-BC would allow it — cost: higher test churn).
- **D2 — `AppLogger` non-nullability:** make it required now in this file
  (**recommended**: same file, CS-PLAN-035 precedent, closes the
  RewardConfigService bullet of CR-SVC-41) vs. defer to a dedicated CR-SVC-41
  plan covering all nullable services.

## Review Outcome (Standard B — 2026-08-26)

Reviewed all changed files against the shared checklist (architecture,
authorization, security, KISS, workarounds, logging, i18n, validation,
docs). **PASS** — behavior parity verified line-by-line (defaults, clamp
bounds on read+write, isset skip-`null`/unknown-ignore semantics,
`getAllConfig` field set + order, `getTierMultiplier` unknown-tier 1.0
fallback); DI change safe (only the unit test constructs the service
directly; the controller test mocks it; real-container wiring covered by the
integration suite); docs examples verified against the tree
(`RoomTaskService::updateDueDate(int, \DateTime): RoomTask`,
`SchedulingService::getSchedulingSuggestions`, `api.js` `post` +
`API_PREFIX` + envelope/ApiError comment). **2 minor doc findings,
auto-fixed inline:**

- Post-refactor line count was recorded as "489" but the file is 538 lines —
  corrected in this plan, `ACTIVE_TASKS.md`, `archive/2026-08.md` (×2), and
  backlog.md's CR-SVC-15 bullet.
- backlog.md lost the blank line before the `### [P2] CR-SVC-16` heading —
  restored.

**Gate re-verified after fixes:** `npm run lint:fix` clean · Jest 842/842 ·
noise gate clean · Docker PHPUnit unit 885/885 · integration 200 [24
pre-existing skips] · cs:check 0/267. No backlog items added. Plan moved to
`done/`.
