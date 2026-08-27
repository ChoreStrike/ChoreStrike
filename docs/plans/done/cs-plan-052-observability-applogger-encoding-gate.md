# CS-PLAN-052: Observability Hardening — AppLogger Adoption & Encoding-Gate C0 Signature

> **Status:** Done (2026-08-27 — Standard-B review PASS + moved to `done/` same day)
> **Effort:** M
> **Priority:** P3
> **Depends on:** none (CS-PLAN-051 reviewed 2026-08-27; all baselines same-day)
> **Closes:** TECH-DOCS-002 (full) · CR-SVC-59 (full, survey-driven) · CR-SVC-41 (nullable-`AppLogger` half only — the `HouseUserMapper` param, duplicated stat helpers, and duplicated `validate*Ownership` remain open)

## 1. Problem Summary

Three open diagnosability/quality items, all verified against the tree on 2026-08-27:

**a) TECH-DOCS-002 — the `docs:encoding` gate is blind to C0 control bytes.**
`scripts/check-docs-encoding.js` validates strict UTF-8 and three cp1252-mojibake
signatures, but every C0 byte other than TAB/LF/CR is *valid* UTF-8 and passes.
The CS-PLAN-051 Standard-B review found **nine** such corruptions that had shipped
past a green gate (escape-style `\r`/BEL/VT/TAB/LF bytes had eaten word-initial
letters in `archive/2026-08.md`); all nine were repaired manually, but the gate
still cannot catch a recurrence.

**b) CR-SVC-41 (AppLogger half) — nullable `?AppLogger` with silent null-skip.**
4 services + 5 controllers + the shared trait accept `?AppLogger = null` and
null-guard every call, so a wiring mistake degrades to *permanently silent*
logging with no failure signal:

- Services: `AuthorizationService` (:35/:41), `HouseService` (:46/:60),
  `HouseSetupService` (:49/:60), `RoomTaskService` (:42/:56).
- Controllers: `GameController` (:35), `HouseController` (:39),
  `SchedulingController` (:43), `TaskController` (:45) — trait pattern with
  `if ($appLogger !== null) { $this->initializeControllerLogger(...) }`;
  `SetupController` (:40/:49) — own field, `$this->appLogger?->error(...)` at
  :78/:141.
- `ControllerLoggingTrait` (:17): `private ?AppLogger $appLogger = null` plus
  early-`return` null-guards in all four helpers.

**Root-cause defect found during verification (bundled):**
`RewardConfigController` uses `ControllerLoggingTrait` (:20) but has **no
`AppLogger` constructor parameter and never calls `initializeControllerLogger`**
— its `logDebug()` calls (:39/:49) are permanent silent no-ops today.

**c) CR-SVC-59 — logger-less services.** 11 services in `lib/Service` have no
logger at all: `ActivityService`, `FloorService`, `GameLogicService`,
`MinigameService`, `RoomService`, `SchedulingService`, `TaskService`,
`TaskSyncService`, `TemplateService`, `TestDataService`, `TitleService`.
Pre-scan of their failure paths:

| Service | catch sites | nature |
|---|---|---|
| FloorService | :186 | rollback + rethrow, unlogged |
| MinigameService | :120, :276 | rollback + rethrow, unlogged |
| RoomService | :172, :457 | rollback + rethrow, unlogged |
| GameLogicService | :411, :417 | NotFound→create comment; **unique-constraint race swallowed silently** |
| ActivityService | :81 | rethrow as `NotFoundException` (controller boundary logs it) |
| TestDataService | :133 | swallows missing-table (dev/E2E seeding tooling) |
| Scheduling/Task/TaskSync/Template/TitleService | 0 | no catch blocks — controller boundary logging only |

The rollback boundaries and the GameLogicService race-swallow are the concrete
unlogged-failure sites; the final adoption set is frozen by the step-1 survey.

## 2. Strategy

Three work packages, ordered so the gate hardening lands **first** and protects
the doc edits made later in this same plan:

- **WP-A (TECH-DOCS-002):** add a C0 control-byte signature to the encoding
  gate — any byte in `0x00–0x08, 0x0B, 0x0C, 0x0E–0x1F` (i.e. C0 except
  TAB/LF/CR) and any **lone CR** (0x0D not followed by 0x0A) → finding; extend
  `--self-test` with planted C0/lone-CR fixtures plus clean TAB/CRLF fixtures.
  Residual limitation documented honestly in the docblock: a TAB/LF that *ate a
  word letter* is indistinguishable from legitimate code indentation and stays
  undetectable (backlog item closes on the "non-TAB/LF/CRLF C0 bytes → invalid"
  signature it asks for).
- **WP-B (CR-SVC-41 half):** flip all 9 nullable sites + the trait to required
  non-nullable `AppLogger` (mirrors the CS-PLAN-049 `RewardConfigService`
  precedent, D2): remove ctor defaults, remove null-guards/`?->`, drop the
  `if ($appLogger !== null)` initializer blocks, give `RewardConfigController`
  a required `AppLogger` param + `initializeControllerLogger` call.
- **WP-C (CR-SVC-59):** survey-first (freeze the list before edits, CS-PLAN-047
  pattern): inject required `AppLogger` into the logger-less services that have
  a real failure or best-effort-skip site (pre-scan: Floor, GameLogic, Minigame,
  Room; borderline Activity), route rollback/catch boundaries through
  `logException()`/`warning()` with stable `event`+`service` context keys.
  Services with no failure path (Scheduling/Task/TaskSync/Template/Title,
  pre-scan) stay logger-free — a dead dependency is worse than none (KISS);
  the survey record justifies each exclusion. TestDataService excluded per D3.

## 3. Backend / Frontend Changes

- **Backend (`lib/`)** — no frontend changes, no API contract changes:
  - `scripts/check-docs-encoding.js`: new `C0-control` + `lone-CR` signatures
    in `scanFile()` (byte-level, before UTF-8 decode), self-test cases.
  - `lib/Controller/ControllerLoggingTrait.php`: non-nullable `AppLogger`,
    null-guard removal.
  - `lib/Controller/{GameController,HouseController,SchedulingController,TaskController,SetupController,RewardConfigController}.php`:
    required `AppLogger` ctor param; `SetupController` `?->` → `->`.
  - `lib/Service/{AuthorizationService,HouseService,HouseSetupService,RoomTaskService}.php`:
    required non-nullable `AppLogger` (drop `= null` default + null-guard).
  - WP-C adopted services (final set frozen by survey):
    `lib/Service/{FloorService,GameLogicService,MinigameService,RoomService}.php`
    (+ `ActivityService` only if the survey confirms a site).
- **Cross-layer contracts:** none — logging is server-side only; error
  responses unchanged.

## 4. Tests

- **Gate self-test (WP-A):** `node scripts/check-docs-encoding.js --self-test`
  — new cases: BEL/VT/ESC fixture (flagged), lone-CR fixture (flagged),
  TAB-indented + CRLF clean fixtures (green). Then full-tree scan must stay
  green (660-file baseline).
- **PHPUnit unit:** update ctor wiring in every suite constructing an affected
  class directly (verified grep 2026-08-27; full list in §5):
  `tests/unit/Service/{AuthorizationServiceException,HouseService,HouseServiceLogging,HouseSetupService,RoomTaskService,RoomTaskServiceDaily,RoomTaskServiceLogging}Test.php`
  and
  `tests/unit/Controller/{GameController,HouseController,SchedulingController,SetupController,TaskController,TaskControllerLogging,TaskControllerDaily,RewardConfigController}Test.php`
  (WP-B); WP-C service suites per survey freeze. New failure-path assertions:
  rollback sites log via `logException` (mock expectation), GameLogicService
  race-swallow logs a `warning`. Red→green check for at least one new
  assertion (mutate the log call away, watch it fail).
- **Integration (Docker):** five suites construct affected classes directly
  without a logger arg and need ctor wiring updates
  (`tests/integration/Controller/{GameController,HouseController,TaskController}IntegrationTest.php`,
  `tests/integration/Service/RoomTaskServiceExtendedFeaturesTest.php` — WP-B;
  `tests/integration/FamilyIntegrationTest.php` — WP-C `GameLogicService`);
  remainder unchanged, suite must stay green (DI wiring proof: container
  resolves the now-required `AppLogger` everywhere).
- **E2E:** not affected (no behavior change); no new specs.
- **Baselines:** Jest 847 · PHPUnit unit 885 / integration 200 [24 pre-existing
  skips] · lint/stylelint 0/0 · noise clean · `docs:encoding` 660 files green.

## 5. File Manifest

```
modified: scripts/check-docs-encoding.js
modified: docs/standards/guidelines/testing.md            (gate signature doc)
modified: docs/standards/guidelines/logging.md            (required-AppLogger convention, WP-C survey record)
modified: lib/Controller/ControllerLoggingTrait.php
modified: lib/Controller/GameController.php
modified: lib/Controller/HouseController.php
modified: lib/Controller/SchedulingController.php
modified: lib/Controller/SetupController.php
modified: lib/Controller/TaskController.php
modified: lib/Controller/RewardConfigController.php
modified: lib/Service/AuthorizationService.php
modified: lib/Service/HouseService.php
modified: lib/Service/HouseSetupService.php
modified: lib/Service/RoomTaskService.php
modified: lib/Service/FloorService.php                    (WP-C, survey-frozen)
modified: lib/Service/GameLogicService.php                (WP-C)
modified: lib/Service/MinigameService.php                 (WP-C)
modified: lib/Service/RoomService.php                     (WP-C)
modified: lib/Service/ActivityService.php                 (WP-C, only if survey confirms)
modified: tests/unit/Service/FloorServiceTest.php
modified: tests/unit/Service/GameLogicServiceTest.php
modified: tests/unit/Service/MinigameServiceTest.php
modified: tests/unit/Service/RoomServiceTest.php
modified: tests/unit/Service/HouseServiceTest.php
modified: tests/unit/Service/HouseServiceLoggingTest.php           (WP-B ctor wiring)
modified: tests/unit/Service/HouseSetupServiceTest.php
modified: tests/unit/Service/RoomTaskServiceTest.php
modified: tests/unit/Service/RoomTaskServiceDailyTest.php          (WP-B ctor wiring)
modified: tests/unit/Service/RoomTaskServiceLoggingTest.php        (WP-B ctor wiring)
modified: tests/unit/Service/AuthorizationServiceExceptionTest.php (WP-B ctor wiring)
modified: tests/unit/Service/GameLogicServiceTeamworkTest.php      (WP-C ctor wiring)
modified: tests/unit/Controller/GameControllerTest.php
modified: tests/unit/Controller/HouseControllerTest.php
modified: tests/unit/Controller/SchedulingControllerTest.php
modified: tests/unit/Controller/SetupControllerTest.php
modified: tests/unit/Controller/TaskControllerTest.php
modified: tests/unit/Controller/TaskControllerLoggingTest.php      (WP-B ctor wiring)
modified: tests/unit/Controller/TaskControllerDailyTest.php        (WP-B ctor wiring)
modified: tests/unit/Controller/RewardConfigControllerTest.php
modified: tests/integration/Controller/GameControllerIntegrationTest.php    (WP-B ctor wiring)
modified: tests/integration/Controller/HouseControllerIntegrationTest.php   (WP-B ctor wiring)
modified: tests/integration/Controller/TaskControllerIntegrationTest.php    (WP-B ctor wiring)
modified: tests/integration/Service/RoomTaskServiceExtendedFeaturesTest.php (WP-B ctor wiring)
modified: tests/integration/FamilyIntegrationTest.php              (WP-C ctor wiring)
modified: docs/backlog/backlog.md · docs/backlog/ACTIVE_TASKS.md · docs/backlog/archive/2026-08.md
```

(Test-manifest detail per adopted service is finalized when the step-1 survey
freezes the WP-C set; every ctor change is accompanied by its suite update.)

## 6. Implementation Order

1. **WP-A:** extend the gate signature + self-test; run `docs:encoding`
   full-tree + self-test (must be green — the 051 review repaired all live
   corruptions).
2. **Survey freeze (WP-C):** re-grep catch/best-effort-skip sites in the 11
   logger-less services; record adopt/exclude per service (with reason) in the
   plan §9 before any edit.
3. **WP-B:** nullable→required sweep (services → trait → controllers,
   `RewardConfigController` wiring fix last); update every directly-constructing
   unit + integration suite (§5 manifest); PHPUnit unit + integration green.
4. **WP-C:** inject `AppLogger` into the frozen set; `logException`/`warning`
   at rollback + race-swallow sites; failure-path assertions with one
   red→green proof; PHPUnit unit green.
5. **Docs & backlog:** `logging.md` (required-`AppLogger` convention + survey
   record), `testing.md` (gate signature), move TECH-DOCS-002 / CR-SVC-59 /
   CR-SVC-41-half entries to `archive/2026-08.md`, update `ACTIVE_TASKS.md`
   (CR-SVC-41 remainder stays open in `backlog.md`).
6. **Gate:** `npm run lint` + `npm test` (Jest) + `npm run docs:encoding` +
   `npm run test:noise` + Docker PHPUnit unit + integration.

## 7. Risks & Mitigations

| Risk | Likelihood | Mitigation |
|------|-----------|------------|
| Ctor changes break existing tests constructing with `null`/defaults | High (mechanical) | Same shape as CS-PLAN-049's ctor change; affected unit + integration suites enumerated via verified grep (§5 manifest) and updated in the same step; full unit + integration suite is the gate. |
| New C0 signature flags legitimate tracked files (e.g. fixtures with control bytes) | Low | Full-tree gate run is step 1's exit criterion; binary files keep the NUL-probe skip; legitimate TAB/CRLF covered by clean self-test fixtures. |
| False security — TAB/LF word-eating subclass stays undetectable | Accepted | Documented residual in the gate docblock + close note; deterministic detection is impossible without language-aware parsing. |
| Logging noise / hot-path cost from new log sites | Low | D1 failures-only scope; no success-path `info` additions; `debug` level stays off by default. |
| `RewardConfigController` debug logs suddenly become live | Low | `debug()` level only — no user-visible or noise-gate impact; verify Jest noise gate stays green. |
| Survey scope creep into CR-SVC-41's non-logger sub-items | Medium | Explicitly out of scope in this plan's header; those remain in `backlog.md`. |

## 8. Acceptance Criteria

- [x] `docs:encoding` flags planted C0 (non-TAB/LF/CR) and lone-CR bytes in
      `--self-test`; full-tree scan green (≈660 files). — self-test 7/7
      (BEL/VT/ESC + lone-CR flagged, TAB/CRLF clean fixtures green),
      full tree 660 files green.
- [x] Zero `?AppLogger` occurrences in `lib/` (grep-verified); trait helpers
      have no null-guards; `RewardConfigController` initializes its logger.
- [x] WP-C adoption set frozen by the recorded survey (§10); every adopted service
      logs failures with stable `event`+`service` keys; excluded services have
      a recorded reason.
- [x] Failure-path test assertions added (incl. one red→green proof); Jest +
      PHPUnit unit + Docker integration at/above baselines; lint/stylelint/
      noise/l10n clean; no E2E impact. — red→green verified on the
      GameLogicService race warning (mutated away → test failed → restored).
- [x] Durable conventions integrated into `docs/standards/guidelines/logging.md`
      and `testing.md`; `backlog.md`/`archive/2026-08.md`/`ACTIVE_TASKS.md`
      updated (CR-SVC-41 remainder stays open with corrected scope note).
- [x] Build gate green (`npm test` + lint + backend tests).

## 9. Activation Decisions (sign-off required)

All four decisions were activated with the plan's recommended option:
**D1 = failures-only · D2 = required non-nullable everywhere · D3 = TestDataService excluded · D4 = non-TAB/LF/CR C0 + lone-CR signature.**

- **D1 — logging scope for newly adopted services:** failures-only
  (`logException` at rollback/catch boundaries + `warning` on the
  expected-race swallow) — **recommended** — vs. also success-path `info`
  events (CS-PLAN-035 style).
- **D2 — make `AppLogger` required non-nullable everywhere (9 sites + trait):**
  **recommended** (CS-PLAN-049 precedent) vs. services-only, controllers keep
  the nullable trait.
- **D3 — `TestDataService`:** exclude from adoption (dev/E2E seeding tooling;
  failures surface in occ output) — **recommended** — vs. include.
- **D4 — gate signature scope:** non-TAB/LF/CR C0 bytes + lone CR, TAB/LF
  word-eating documented as an undetectable residual — **recommended** — vs.
  attempting word-adjacency heuristics (rejected: every indented JS line is
  TAB-followed-by-letter).

## 10. Implementation Record

### WP-C Survey Record (frozen 2026-08-27, before any edit)

Re-grepped all catch/best-effort-skip sites in the 11 logger-less services;
matches the §1 pre-scan exactly. Adoption set frozen:

| Service | Sites (re-verified) | Decision | Reason |
|---|---|---|---|
| FloorService | :186 | **ADOPT** | rollback + rethrow, unlogged |
| MinigameService | :120, :276 | **ADOPT** | rollback + rethrow, unlogged |
| RoomService | :172, :457 | **ADOPT** | rollback + rethrow, unlogged |
| GameLogicService | :411, :417 | **ADOPT** (race site only) | :411 is expected control flow (NotFound → create, idempotency check — no log); :417–426 unique-constraint race is swallowed silently → `warning` (D1) |
| ActivityService | :81 | EXCLUDE | expected-condition translation (DoesNotExistException → NotFoundException), no transaction/state impact; the controller boundary logs the thrown domain exception |
| TestDataService | :133 | EXCLUDE (D3) | dev/E2E seeding tooling; failures surface in occ output |
| SchedulingService | 0 catch blocks | EXCLUDE | no failure path; controller boundary logging only (dead dependency is worse than none, KISS) |
| TaskService | 0 catch blocks | EXCLUDE | no failure path; controller boundary logging only |
| TaskSyncService | 0 catch blocks | EXCLUDE | no failure path; controller boundary logging only |
| TemplateService | 0 catch blocks | EXCLUDE | no failure path; controller boundary logging only |
| TitleService | 0 catch blocks | EXCLUDE | no failure path; controller boundary logging only |

### Implementation Record (2026-08-27)

**WP-A:** `scripts/check-docs-encoding.js` — new `firstForbiddenControlByteOffset()`
+ `firstLoneCrOffset()` byte-level checks wired into `scanFile()` before UTF-8
decode; docblock documents the signatures + the accepted TAB/LF word-eating
residual (D4); self-test extended to 7 cases (BEL/VT/ESC flagged, lone-CR
flagged, TAB-indented + CRLF clean). Self-test 7/7; full tree 660 files green.

**WP-B:** `AuthorizationService`, `HouseService`, `HouseSetupService`,
`RoomTaskService` (property + ctor + `?->`→`->`), `ControllerLoggingTrait`
(non-nullable + 4 null-guards removed), `Game/House/Scheduling/Task/Setup`
controllers (required param, initializer blocks collapsed),
`RewardConfigController` (required `AppLogger` param +
`initializeControllerLogger` — silent-no-op defect fixed). Grep-verified:
zero `?AppLogger` / `appLogger?->` / null-guard occurrences left in `lib/`.

**WP-C (survey-frozen set above):** `AppLogger` injected (required, appended
as last ctor param) into Floor/Minigame/Room services with `logException` at
all 5 rollback boundaries; `GameLogicService` race-swallow logs `warning`
(`medal_award_race_lost`). **Deviation from the manifest:** `GameLogicService`'s
ctor places `AppLogger $appLogger` *before* the optional `?TitleService
$titleService = null` (PHP 8 deprecation forbids an optional param before a
required one; first attempt appended last and tripped the deprecation in the
unit run). All `GameLogicService` call sites (unit ×5, teamwork ×1,
`FamilyIntegrationTest`) pass the logger at that position with `null` for the
title service.

**Tests:** 31 ctor-wiring sites updated across 25 unit + 5 integration suites
(`RoomTaskServiceLoggingTest`, `HouseServiceLoggingTest`,
`TaskControllerLoggingTest` already passed loggers — unchanged). New
failure-path assertions: rollback sites expect `logException` with message +
stable context keys (`FloorServiceTest`, `MinigameServiceTest` ×2,
`RoomServiceTest` deleteRoom + new `testCompleteRoomTask_TransactionFailure_
RollsBackAndLogs`); `GameLogicServiceTest` race test expects the `warning`.
Red→green proof: warning call mutated away → race test failed ("expected 1
times, actually 0 times") → restored → green.

**Docs:** `logging.md` (required-AppLogger convention, adoption criteria,
rollback/race logging rules, 2 checklist lines), `testing.md` (C0 + lone-CR
gate signature + residual). Backlog: TECH-DOCS-002 + CR-SVC-59 resolved →
`archive/2026-08.md`; CR-SVC-41 rewritten as remainder-only entry;
`ACTIVE_TASKS.md` updated.

**Gate (final):** lint + stylelint 0/0 · Jest 847/847 · PHPUnit unit **886**
[0 skips] (+1 = the new RoomService rollback-logging test) · integration 200
[24 pre-existing skips — unchanged] · `docs:encoding` 660 files green ·
`test:noise` clean · E2E untouched.

---

## Review Outcome (Standard A — 2026-08-27)

**Tier:** Standard A (plan review, pre-`new/` → `active/`). **Verdict:
GO-WITH-CHANGES** — all changes applied inline during this review; no scope or
architecture change.

**Verified accurate against the tree (2026-08-27):** all nine nullable
`?AppLogger` sites (services :35/:41, :46/:60, :49/:60, :42/:56; controllers
:35, :39, :43, :45, :40/:49; trait :17 with null-guards in all four helpers);
the bundled `RewardConfigController` silent-no-op defect (trait :20, no ctor
param, `logDebug` :39/:49); the 11 logger-less services; every WP-C catch site
(Floor :186, Minigame :120/:276, Room :172/:457, GameLogic :411/:417, Activity
:81, Test :133); the encoding gate's missing C0/lone-CR signature; backlog
items TECH-DOCS-002 / CR-SVC-59 / CR-SVC-41 as cited; no overlap with active
plans (none active). Baselines: `docs:encoding` re-run live — 660 files green;
Jest 847 / PHPUnit unit 885 / integration 200 [24 skips] match the same-day
CS-PLAN-051/050 records.

**Fixed inline (test-scope accuracy only):**

| # | Severity | Finding | Fix |
|---|----------|---------|-----|
| 1 | major | §4 claimed `AuthorizationService` has no direct `new AuthorizationService(` in tests — `AuthorizationServiceExceptionTest.php:42` does (3 args, relies on the `= null` default; breaks under WP-B) | §4 corrected; suite added to §5 |
| 2 | major | §4 claimed the integration suite is unchanged — four WP-B integration suites construct affected classes directly without a logger arg (`GameControllerIntegrationTest.php:32`, `HouseControllerIntegrationTest.php:34`, `TaskControllerIntegrationTest.php:39`, `RoomTaskServiceExtendedFeaturesTest.php:58`) plus one WP-C suite (`FamilyIntegrationTest.php:37` builds `GameLogicService`) | §4 corrected; all five added to §5 |
| 3 | minor | §5 missed six further unit suites constructing affected classes directly (`HouseServiceLoggingTest`, `RoomTaskServiceDailyTest`, `RoomTaskServiceLoggingTest`, `TaskControllerLoggingTest`, `TaskControllerDailyTest`, `GameLogicServiceTeamworkTest`) | §5 completed via verified grep over `tests/` |
| 4 | nit | §6 step 3 carried a stale suite count ("6 controller + 4 service suites"); §7 risk row 1 named only the unit suite as gate | Step 3 now references the §5 manifest; risk row 1 includes integration |

**Notes (no edit):** (1) backlog CR-SVC-41's stale "3 services" count becomes
moot when step 5 rewrites the remainder entry with the corrected scope note
(acceptance criterion 5 already requires it). (2) WP-C test-manifest detail
stays survey-frozen per the §5 footnote; `ActivityServiceTest.php`
(direct ctor at :50) joins only if the survey confirms adoption.

---

## Review Outcome (Standard B — 2026-08-27)

**Tier:** Standard B (code review of the plan's uncommitted manifest). **Verdict:
PASS** — implementation matches the plan; no scope or architecture deviation
beyond the documented §10 ctor-reorder deviation.

**Verified:** zero `?AppLogger` / `appLogger?->` / null-guard occurrences in
`lib/` (grep re-run); trait + 6 controllers + 4 services match §3 exactly;
`RewardConfigController` logger wired (defect closed); variable scope correct
at all 6 new log sites (Floor :191, Minigame :125/:288, Room :177/:467,
GameLogic :433 — all vars are params or pre-try locals); `AppLogger`
autowire-safe (interface-only ctor, no registration needed); no direct
construction sites in `lib/` outside the container; WP-C exclusions match the
§10 survey; failure-path assertions present for every adopted site with stable
`event`+`service` keys; backlog/archive bookkeeping correct (TECH-DOCS-002 +
CR-SVC-59 → archive, CR-SVC-41 remainder-only).

**Quality gate (re-run this review):** lint:fix clean · stylelint 0/0 ·
Jest 847/847 · PHPUnit unit **886 OK, 0 skips** · integration 200 OK
[24 pre-existing skips] · `docs:encoding` self-test 7/7 + full tree 660 green ·
noise gate clean.

**Fixed inline:** 1 auto-fix — `GameLogicService::awardMedalIfNew` docblock
claimed the race loser "re-reads the existing medal"; it returns null
directly (the winner's medal exists). Docblock corrected; unit suite re-run
green.

**Deferred / backlog:** none — no new findings.
