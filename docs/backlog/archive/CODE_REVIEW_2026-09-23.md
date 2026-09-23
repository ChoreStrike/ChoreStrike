# Code Review: CS-PLAN-072 WP1 — Standard B Final Round 2 (Occurrence Timeline Package + RF-18 Collaborator Split)

**Review Date**: 2026-09-23 (started 2026-09-22)
**Reviewer**: Main agent (Kilo, skills: review, plan-management, git-workflow, docker-dev)
**Tier**: Standard B (code, final — round 2) — full-package review against the owning plan's manifest, acceptance criteria, required-test evidence, durable-doc integration and implementation history; delta focus: the RF-072-WP1-18 resolving-round collaborator split
**Scope**: all uncommitted changes on `master` (at capture: 99 tracked modified files + 1 package deletion + 25 untracked new files, +3600/−2072 tracked) implementing `docs/plans/active/cs-plan-072-wp1-occurrence-timeline.md` (moved to `done/` by this review)
**Base Commit**: `master` @ `e844f31`
**Owning plan**: CS-PLAN-072 / WP1 — findings routed per project-rules §4 Finding Routing (plan-owned vs. out-of-scope backlog)

---

## Scope And Capture

- Branch `master`, HEAD `e844f31`, index empty at capture (2026-09-22); the
  entire WP1 delivery (implementation + RF-06..17 resolving round + RF-18
  split) remains uncommitted (unstaged + untracked).
- Scope fingerprint (SHA256) at capture: unstaged binary diff
  `0785E494A14109292BAE23040074DE1E951BE19179275C5E0D634F86D5325B93`; staged
  diff EMPTY (`E3B0C442…B855`, 0 bytes); untracked manifest (25 sorted paths
  with per-file content hashes)
  `E158005422B07CF51CD4B652AC715C4FD73E22503C7C6284E9F332C7F4749444`.
- The three pre-existing unrelated `.gitkeep` deletions
  (`docs/plans/{active,new,onhold}/`) stayed excluded from the review
  package and from the commit, consistent with prior rounds.

## Outcome

`PASS-WITH-FIXES` — the RF-072-WP1-18 behavior-neutral collaborator split is
verified in code; one plan-owned finding (RF-072-WP1-19) was fixed in-round;
one safe doc fix was applied; one recurring harness flake was routed
out-of-scope to the backlog. All §9 findings resolved, all acceptance
criteria met, full gate set rerun green including a user-selected E2E rerun —
the `active/ → done/` move was performed.

### RF-072-WP1-18 verification (split is behavior-neutral)

- Facade `OccurrenceService.php` (408 lines): full public API preserved —
  28 delegating methods + the 4 public constants re-exported as aliases
  (`HORIZON_DAYS`/`JOB_BATCH_SIZE`/`JOB_MATERIALIZE_BUDGET` →
  `OccurrenceReconcileService`, `SELECTION_LOOKBACK_DAYS` →
  `OccurrenceSelectionService`); transaction convention documented and kept
  (public mutators own house-first transactions; `UnderLock` methods never
  begin one).
- Collaborator line counts match the resolving-round note exactly:
  `OccurrenceRowFactory` 81, `OccurrenceReconcileService` 456,
  `OccurrenceSelectionService` 496, `OccurrenceRevisionService` 420,
  `OccurrenceCompletionService` 366, `OccurrenceAgreementService` 353,
  `RecurrenceCalculator` 283.
- Layering acyclic as documented: RowFactory → ReconcileService →
  {Selection, Revision}Service → {Completion, Agreement}Service; facade over
  all five.
- Consumer seams unchanged: every production consumer (`TimelineJob`,
  `TriggerJobs`, `ChecklistService`, `CompletionService`, `HouseService`,
  `HouseSetupService`, `RoomTaskReadService`, `RoomTaskService`,
  `SchedulePlanService`, `TaskCreationService`, `TaskService`,
  `TestDataService`) injects the facade only; the collaborator classes are
  referenced nowhere else except the facade and the rewired
  `RoomTaskServiceExtendedFeaturesTest` (builds the real collaborators from
  the same mocked mappers). Nextcloud DI auto-wires the new concrete
  classes; no `Application.php` registration needed or added.
- Shared pure day-key helpers (`offsetDays` public, `dayAfter`/`dayBefore`/
  `dayKeyToDate`) live once in `RecurrenceCalculator`; repo-wide grep found
  no private duplicates.
- Job-failure log context `service` key names `OccurrenceReconcileService`;
  the unused `attachMembersUnderLock` `$houseId` parameter is gone.
- Previously verified RF-01..17 seams re-spot-checked and intact: RF-11
  shared `openOccurrenceDayLoadByWallet` counter feeding
  `RoomTaskService::assertChildCapacityForDate`; RF-13
  `validateOccurrenceKey` via `Occurrence::parsePublicKey` on both checklist
  endpoints; RF-14 scheduled-kind miss filter +
  completion/coverage-null voluntary fallback; RF-15 `selectionBlock` →
  `occurrenceBlock` delegation adding only `savedZoneDiffers`; RF-08
  `JOB_MATERIALIZE_BUDGET`/`dayAfter(frontier)` bounds + localized 409
  (EN/DE/POT present); `TimelineJob` registered in `appinfo/info.xml`
  (5-minute interval).
- Durable docs reflect the split: `architecture.md` completion-path §
  (facade + collaborator descriptions), `development.md`, ADR-013 code map.

### RF-072-WP1-19 — §4-named unit suite missing (fixed in-round, plan-owned)

- Severity: minor (required-test-evidence gap; completion gate)
- File: `tests/unit/Service/OccurrenceServiceTest.php` (absent)
- Issue: the plan's §4 T1-B row names this file as a required new suite; it
  was never delivered, and the canonical-selection tie-break assertions
  (earliest deadline → planned date → identity; latest-miss catch-up
  classification) had no explicit unit pin — integration coverage exercised
  the semantics but not the tie-break order.
- Fix (in-round, test-only): delivered the named file with 8 deterministic
  pins through the facade (`selectFromAgreed` smallest-window-end /
  planned-date / canonical-identity tie-breaks, fulfilled/covered/voluntary
  skipping, latest-miss `late` catch-up, covered-miss exclusion,
  open-voluntary recordability, future-window never selected); injected
  instant, no host-clock assertions. Unit suite 963 → 971 green.

### Safe doc fix (no finding id) — Size Guideline Exception Inventory

`docs/engineering/architecture.md`: the inventory's maintenance rule
requires every production file above the ~300-non-blank-line guideline to
receive an entry "in the same change" — the RF-18 split added six such
files (330-465) without entries, and WP1-grown rows carried stale counts.
Re-measured the whole table (non-blank lines, 2026-09-22): new entries for
the Occurrence facade + five >300 collaborators (accepted, cohesive
collaborators per the CompletionService/WP8 precedent),
`ChecklistController` 307, `ChecklistService` 415, `SchedulingService` 304
(pre-existing omission); updated WP1-grown rows (`RoomTaskController` 951,
`CompletionService` 730, `RoomTaskService` 846, `RoomTaskReadService` 619,
`SchedulePlanService` 1071, `TaskService` 538, `HouseService` 501,
`HouseSetupService` 490, `TaskCreationService` 453, `TestDataService` 566,
`CompletedTaskMapper` 536, `roomTasks.js` 967) and shrunk rows (`RoomTask`
357 after the legacy-engine removal, `RoomTaskMapper` 503); corrected
WP0-era drift on unchanged files (`MinigameService` 369, `TaskEditForm.vue`
891, `minigame.js` 381).

### Flake routing (out of plan scope) — TECH-TEST-003

The first integration run after a disposable-volume reset (pre-crash
environment) failed ONE controller-validation test
(`ChecklistApiTest::testDeleteTaskItem_Member_Returns403`,
`DoesNotExistException` on the shared instance house during
`IntegrationTestCase::initializeScheduleForFixture` →
`RoomTaskReadService::timeZoneForHouse`) — the same first-run-after-reset
class the RF-18 resolving round noted. After the Docker Desktop engine
crash mid-review, a full recycle (`down -v` + `up -d`, family profile)
produced two consecutive green full runs (403 tests / 1831 assertions
each). Production behavior is correct (the house lookup SHOULD fail for a
deleted house); the defect is the shared-house lifecycle in the
integration harness (process-memoized `HouseService` singleton +
structural-deletion tests). Cross-cutting test infrastructure needing
dedicated root-cause effort → routed per Finding Routing step 4 to
`TECH-TEST-003` in `backlog.md` (P3, with deferral reason and the
two-green-rerun protocol as the interim trust basis). Does not block the
plan transition.

## Gates (rerun in this review)

- Docker PHPUnit unit: **971 tests / 2908 assertions — OK**
  (`--fail-on-skipped`, zero skips; 963 baseline + 8 new RF-19 pins).
- Docker PHPUnit integration: **403 tests / 1831 assertions — OK**, two
  consecutive green runs (`--fail-on-skipped`, zero skips, `family`
  profile, clean volume after the full recycle).
- Jest: **1069/1069 — OK** (100 suites, `--runInBand`).
- `npm run lint`: clean. `npm run build`: compiled successfully.
  `npm run test:noise`: clean.
- `composer cs:check`: 0 of 334 fixable.
- `npm run l10n:check-keys`: 957 keys, POT/EN/DE exact coverage.
- `npm run backlog:check`, `npm run docs:encoding` (744 files),
  `git diff --check`: green (rerun after the finalization edits).
- **E2E gate: PASSED** (`run-cRpU8q`) — 47 required specs, all three pools
  sequential, zero skipped/failed/flaky. Rerun by user decision (below).
- Environment note: Docker Desktop's engine crashed once mid-review (daemon
  pipe lost; containers exited 255; the restart's entrypoint hit an install
  conflict against the persisted DB — "The Login is already being used").
  Diagnosed per the docker-dev skill; the disposable recycle restored a
  clean state. All backend/E2E evidence above is post-recycle.

## Questions / Decisions

Batched question (Kilo `question` tool, 2026-09-22):

1. **E2E gate evidence** — user selected "Rerun E2E gate now": this final
   review produced its own authoritative E2E evidence (`run-cRpU8q`) rather
   than accepting the RF-18 resolving round's same-day `run-q6rUyU` report —
   consistent with the previous final review's decision.

## Fixes

1. RF-072-WP1-19: delivered `tests/unit/Service/OccurrenceServiceTest.php`
   (8 pins; test-only, safe).
2. Size Guideline Exception Inventory completed and re-measured
   (`docs/engineering/architecture.md`; doc-only, safe).

## Plan Transition

`PASS-WITH-FIXES` with every completion gate met: all §9 findings
(RF-072-WP1-01..19) resolved, all §10 questions resolved, all acceptance
criteria checked, required-test evidence complete (incl. the §4-named unit
suite), durable-doc integration verified (concepts/ADR-013/engineering/API/
testing + the size-exception inventory), tracking updated. The plan moved
`active/ → done/`; `docs/plans/README.md`, `docs/README.md`, parent
CS-PLAN-070 (header + WP table), the CONCEPT-001 narrative,
`ACTIVE_TASKS.md` (consolidated per-plan one-liner within the ~10-entry
cap; the two older 072 entries rolled to `archive/2026-09.md`) and the
tracked links were updated. CONCEPT-001 and the parent remain open
(WP2-WP9); no owning backlog item is completed by this delivery alone.
The full implementation package is committed with this review's scoped
commit; the three unrelated `.gitkeep` deletions remain unstaged.
