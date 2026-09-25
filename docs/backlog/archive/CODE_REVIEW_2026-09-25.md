# Code Review: CS-PLAN-073 WP2

- **Review Date:** 2026-09-25
- **Tier:** Standard B, final-review attempt, no subagents
- **Mode:** Deep Slice Review
- **Outcome:** `BLOCKED` (superseded by the final review below)
- **Owning Plan:** [CS-PLAN-073](../../plans/done/cs-plan-073-wp2-agreements-capacity-pauses.md)
- **Base:** `master` at `75f2da3e1fe3f8b3f46d128ad6f92263649bdb78`

## Summary

The critical-blocker early-stop rule applies. This is a bounded review of the
agreement/pause seams, not an exhaustive approval of the captured WP2 package.
Existing suites pass, but isolated required-contract probes reproduce defects.
The user selected a separate fix round and approved only the sanitized privacy
placeholder below. No runtime fix, lifecycle move, staging or commit occurred.

## Findings

The owning plan's section 9 holds the actionable open checklist and required
regression coverage. No in-scope finding was moved to the general backlog.

| ID | Severity | Source | Finding | State |
|---|---|---|---|---|
| RF-073-WP2-23 | major | `PauseProjectionService.php:83-87,263-280` | Full acknowledged revision history keeps shortened-away future dates covered. | Open; reproduced |
| RF-073-WP2-24 | major | `PauseProjectionService.php:329-350` | Explicit joint 50/50 work remains active when one obligated participant pauses. | Open; reproduced |
| RF-073-WP2-25 | major | `PauseService.php:467-487,553-560`; `PauseProjectionService.php:215-230` | Ordinary acknowledgment neutralizes expired misses without the explicit retrospective exception. | Open; reproduced |
| RF-073-WP2-26 | major | `PauseProjectionService.php:165-171` | Virtual entries ignore revision participants, so their pause state differs from materialized entries. | Open; reproduced |
| RF-073-WP2-27 | major | `AgreementService.php:664-686` | Date/catch-up validation uses revision defaults after a concrete handover rather than current occurrence participants. | Open; source-confirmed |
| RF-073-WP2-28 | block | WP2 privacy boundary | A WP2 privacy boundary finding remains unresolved and blocks finalization. | Open |

The existing shortening unit test filters out the old revision before asserting
future non-coverage (`PauseProjectionServiceTest.php:112-123`), unlike production.
The integration test named `testShortenToToday_ResumesFutureDuty_PreservesToday`
only asserts today's suspension (`PauseAgreementTest.php:161-179`). The existing
partial-coverage unit test asserts the wrong joint behavior. Consequently, green
test counts are not adequate evidence of these acceptance criteria.

## Review Coverage

- Loaded canonical `review`, `plan-management`, `git-workflow`, `docker-dev`,
  and `documentation` skills; consulted security rules and review playbook.
- Resolved the explicit owning plan; inspected its manifest, acceptance criteria,
  prior findings/fix record, and relevant activation/implementation/review history.
- Read the new pause service, projection, mapper/entity and controller; agreement
  command orchestration/validation; receipt transaction/replay behavior; relevant
  occurrence writer and capacity evaluator sections; selection projection callers.
- Compared the approved C2 pause contract with implementation and existing
  pause-projection/lifecycle tests. Reproduced four behavioral contract failures.
- Source-traced the date/catch-up follow-up capacity mismatch after handover.
- Remaining manifest, other implementation seams, comprehensive durable-doc
  verification and full post-remediation gates are still required. The earlier
  RF-01..22 fix record was not independently certified in its entirety.

## Capture

Index was empty. Index tree: `10c93ac062abac95b4707383becd2b65598b9142`.
The three pre-existing `docs/plans/{active,new,onhold}/.gitkeep` deletions are
excluded and untouched. No `--no-commit` override was supplied; the lack of a
commit is caused by `BLOCKED`, not by a read-only flag.

Fingerprints before tracked review edits:

- Raw staged binary diff Git blob SHA-1: `e69de29bb2d1d6434b8b29ae775ad8c2e48c5391` (empty).
- Raw unstaged binary diff, excluding the three `.gitkeep` paths: `fd5dc46751cbaf766f861d35c003a003f4264141`.
- Sorted complete changed path/content manifest: `20a202defcbfb1758dcf27577127bc9358da8f6f`.

Raw diff hashes use `cmd /d /s /c` to pipe `git diff --binary` into
`git hash-object --stdin` without PowerShell re-encoding. The manifest hash uses
the sorted union of `git diff --name-only HEAD` and
`git ls-files --others --exclude-standard`, excludes the three `.gitkeep` paths,
and hashes PowerShell-piped lines `<git hash-object result> <relative path>`.
Thus tracked and untracked file content is included. Per-file untracked hashes
were also captured in the tool transcript before edits.

Exact captured candidate manifest (72 modified, 17 untracked; capture does not
mean every file received a completed manual review):

```text
appinfo/routes.php
docs/adr/ADR-012-actionable-household-scheduling.md
docs/adr/ADR-013-occurrence-agreements-and-corrections.md
docs/api/README.md
docs/backlog/ACTIVE_TASKS.md
docs/backlog/archive/CODE_REVIEW_2026-09-24.md
docs/backlog/backlog.md
docs/concepts/energy-and-balancing.md
docs/concepts/task-model-and-sync.md
docs/concepts/ui-use-cases.md
docs/engineering/architecture.md
docs/engineering/testing.md
docs/plans/active/cs-plan-073-wp2-agreements-capacity-pauses.md
docs/testing/release-test-plan.md
l10n/de.json
l10n/en.json
l10n/templates/chorestrike.pot
lib/BackgroundJob/TimelineJob.php
lib/Controller/OccurrenceController.php
lib/Controller/PauseController.php
lib/Controller/RoomTaskController.php
lib/Controller/SchedulingController.php
lib/Db/AgreementMapper.php
lib/Db/CompletedTaskMapper.php
lib/Db/Occurrence.php
lib/Db/OccurrenceMapper.php
lib/Db/OccurrenceMemberMapper.php
lib/Db/Pause.php
lib/Db/PauseMapper.php
lib/Migration/Version1031Date20260918000000.php
lib/Service/AccountErasureService.php
lib/Service/AgreementCommandContext.php
lib/Service/AgreementService.php
lib/Service/CommandService.php
lib/Service/CompletionService.php
lib/Service/DailyCapacityService.php
lib/Service/HouseService.php
lib/Service/OccurrenceAgreementService.php
lib/Service/OccurrenceCompletionService.php
lib/Service/OccurrenceReconcileService.php
lib/Service/OccurrenceSelectionService.php
lib/Service/OccurrenceService.php
lib/Service/PauseProjectionService.php
lib/Service/PauseService.php
lib/Service/RoomTaskReadService.php
lib/Service/SchedulePlanService.php
lib/Service/TestDataService.php
src/components/Scheduling/SchedulePlanner.vue
src/store/scheduling.js
tests/e2e/core-household-journey.spec.ts
tests/e2e/family-rhythm.spec.ts
tests/e2e/required-scenarios.json
tests/e2e/scheduling-apply-flow.spec.ts
tests/integration/Db/CompletedTaskMapperEnergyTest.php
tests/integration/Db/FamilyRhythmSchemaTest.php
tests/integration/Db/WorkflowQueryBudgetTest.php
tests/integration/IntegrationTestCase.php
tests/integration/RoleGuardApiTest.php
tests/integration/Service/AccountErasureTest.php
tests/integration/Service/ConcurrentCompletionTest.php
tests/integration/Service/HouseMemberRemovalIntegrationTest.php
tests/integration/Service/OccurrenceTimelineTest.php
tests/integration/Service/PauseAgreementConcurrencyTest.php
tests/integration/Service/PauseAgreementTest.php
tests/integration/Service/RoomTaskServiceExtendedFeaturesTest.php
tests/integration/Service/SchedulePlanAggregateTest.php
tests/integration/Service/SchedulePlanConcurrencyTest.php
tests/unit/BackgroundJob/TimelineJobTest.php
tests/unit/Controller/OccurrenceControllerTest.php
tests/unit/Controller/PauseControllerTest.php
tests/unit/Controller/SchedulingControllerTest.php
tests/unit/RouteMatrixTest.php
tests/unit/Service/AccountErasureServiceTest.php
tests/unit/Service/AgreementServiceTest.php
tests/unit/Service/CompletionServiceTest.php
tests/unit/Service/DailyCapacityServiceTest.php
tests/unit/Service/HouseServiceLoggingTest.php
tests/unit/Service/HouseServiceTest.php
tests/unit/Service/OccurrenceServiceTest.php
tests/unit/Service/PauseProjectionServiceTest.php
tests/unit/Service/PauseServiceTest.php
tests/unit/Service/RoomTaskReadServiceTest.php
tests/unit/Service/RoomTaskServiceDailyTest.php
tests/unit/Service/RoomTaskServiceLoggingTest.php
tests/unit/Service/RoomTaskServiceTest.php
tests/unit/Service/SchedulePlanServiceTest.php
tests/unit/components/SchedulePlanner.spec.js
tests/unit/store/roomTasks.spec.js
tests/unit/store/scheduling.spec.js
```

## Validation

| Check | This review's result |
|---|---|
| Docker PHPUnit unit, `--fail-on-skipped` | PASS: 1046 tests, 3110 assertions |
| Docker PHPUnit integration, `--fail-on-skipped` | PASS: 438 tests, 2051 assertions; existing environment, no fresh reset |
| `npm test -- --runInBand` | PASS: 1073 tests, 100 suites; existing TECH-JEST-001 warnings reproduced |
| `npm run lint` | PASS |
| Isolated required-contract probes | FAIL: four behavioral assertions; separate blocker reproduction also confirmed |
| Build, E2E gate, fresh-install chain, composer style, noise, localization | Not rerun after critical-blocker stop; required after remediation |
| `npm run backlog:check` | PASS: 26 scoped files link-checked |
| `npm run docs:encoding` | PASS: 772 tracked files; new review record is ASCII |
| `git diff --check` and new-record `--no-index --check` | PASS after removing Markdown hard-break trailing spaces from the new record |

## Decisions and Finalization

Structured questions answered on 2026-09-25:

1. `Separate fix round (Recommended)`: no in-review behavior/security changes;
   retain plan ownership, remediate and complete a later final re-review.
2. `Allow placeholder (Recommended)`: explicitly approved only the sanitized
   RF-28 sentence; no detailed public disclosure.

Updated the plan status, plan-owned findings and Review Outcome, this record,
and live coordination. Existing TECH-JEST-001 receives a repeated-observation
note rather than a duplicate backlog item. No completion/archive of CR-SVC-64,
FEAT-UX-025 or parent work is claimed in this stopped review.

**Transition:** none; plan remains `active/`.

**Commit:** none; `BLOCKED` prohibits even an automatic evidence-only commit.

**Next owner:** WP2 fix round, then complete final Standard B review. No scope or
test waiver was approved; review evidence remains uncommitted.

Final state check: branch remains `master`, HEAD remains the captured SHA, and
`git diff --cached --name-only` is empty. The unrelated `.gitkeep` deletions
remain untouched. No automatic commit is eligible regardless of index emptiness.

## Final Standard B Review (R9) — PASS-WITH-FIXES

- **Review Date:** 2026-09-25 (after the fix round R0-R8)
- **Tier:** Standard B, final review of the entire WP2 increment, no subagents
- **Outcome:** `PASS-WITH-FIXES`
- **Owning Plan:** [CS-PLAN-073](../../plans/done/cs-plan-073-wp2-agreements-capacity-pauses.md) — moved `active/ → done/`
- **Base:** `master` at `75f2da3e1fe3f8b3f46d128ad6f92263649bdb78`, index empty

### Summary

Complete review of the 96-path WP2 increment: the new pause/agreement/capacity
services, controllers, entity/mapper/migration surfaces, all modified backend
and frontend files, the fix-round regressions, the R0 evidence map and the
durable docs. The fix-round contracts (RF-23..28) were verified at their
implementation seams; two minor orphan-code findings were fixed in-round and
all gates were rerun green on the reviewed source.

### Findings (both fixed in-round)

| ID | Severity | File | Finding | State |
|---|---|---|---|---|
| RF-073-WP2-29 | minor | `lib/Service/DailyCapacityService.php:362` | Dead public static `assertValidShares` — zero production/test callers; carried hardcoded English messages (unreachable, so never client-visible). | Fixed: removed; size inventory 551 → 517 |
| RF-073-WP2-30 | minor | `lib/Service/PauseProjectionService.php:45-56` | Unused `CalendarService` constructor dependency (assigned, never read). | Fixed: removed; test constructions updated; size inventory 488 → 485 |

No other findings: authorization/ownership clean on all nine new routes
(`@NoAdminRequired` only, membership/owner gates, 404 anti-enumeration,
`hash_equals` tokens), no SQL concatenation, no `console.*`/`v-html`/`// HACK:`,
i18n via `t()` with EN/DE/POT exact, logging via `AppLogger`/logger seams, no
test deletions or unapproved skips (the single `markTestSkipped` is the
established MariaDB two-connection-race guard, never taken in the Docker gate).

### Review Coverage

- Full read of the new core: `PauseService`, `PauseProjectionService`,
  `AgreementService`, `DailyCapacityService`, both new controllers,
  `Pause`/`PauseMapper`, `AgreementCommandContext`.
- Diff review of every modified backend/frontend file, the migration, routes
  and the l10n artifacts; docs diffs spot-checked (ADR-012/013, API README,
  architecture/testing, concepts, release-test plan).
- RF-23..28 resolution verified in source and by re-reading the owning
  regression bodies (evidence-map spot checks all real and substantive);
  CR-SVC-64 midnight proof re-read (`testApply_CrossingLocalMidnight_ConflictsWithoutWrites`:
  write-free 409 before relative-date rejection, malformed stays 400, clock
  restored re-applies).
- Size-exception inventory verified against current files (all values exact).
- Mtime verification: only two PHPUnit test files and tracking docs changed
  after the passing 2026-09-25 16:33 E2E gate run; `lib/` and `src/` are
  identical to the gated source.

### Capture

Index empty (staged diff sha1 `e69de29…`). Unstaged binary diff
`1cca50a9b2b1d1291042a4b50a8af1ee1edf6a83`; 18 untracked files with per-file
content hashes captured (manifest sha1 `4d4198d09fd934667fc977bc26d2b11979528f20`);
96 total changed paths (78 tracked incl. the three planned `.gitkeep`
deletions, 18 untracked). Unrelated changes preserved; `.gitkeep` deletions
left unstaged per repo convention.

### Validation

| Check | Result |
|---|---|
| Docker PHPUnit unit, `--fail-on-skipped` | PASS: 1073 tests, 3190 assertions, zero skips |
| Docker PHPUnit integration, `--fail-on-skipped` | PASS: 450 tests, 2208 assertions, zero skips |
| `npm test -- --runInBand` | PASS: 1073 tests, 100 suites |
| `npm run lint` / `npm run test:noise` | PASS / clean |
| `composer cs:check` (container) | PASS: 0 fixable of 351 files |
| `npm run l10n:check-keys` | PASS: 995 keys, POT/EN/DE exact |
| `npm run docs:encoding` | PASS: 772 files |
| `npm run backlog:check` / `git diff --check` | PASS (run again post-finalization) |
| E2E gate | Accepted per user decision: R8 evidence (3 pools, zero failed/skipped/flaky, 2026-09-25 16:33 on the final `lib/`/`src/`), mtime-verified unchanged since |

### Decisions and Finalization

Batched question answered: E2E verification depth → `Accept R8 evidence
(Recommended)`. Recorded here and in the plan's Review Outcome.

Findings routed per §6 scope-first: both minor findings were in-plan-scope and
fixed in-round (no backlog items). CR-SVC-64 closed and archived (midnight
proof verified); FEAT-UX-025 backend slice closed with the UI/digest/rhythm
portions left open; CONCEPT-001 and the parent CS-PLAN-070 remain open.
Plan status/acceptance/R9 updated, moved `active/ → done/`, plan links in
`backlog.md`/ADR-013/plans README/docs README/parent plan repaired to `done/`;
ACTIVE_TASKS consolidated. One scoped conventional review commit includes the
whole reviewed increment plus transition/tracking files.

**Transition:** `active/ → done/` (PASS-WITH-FIXES, all completion gates met).

## Implementation History Extract

Extracted from CS-PLAN-073 on 2026-09-25 at the user's request to separate the
remaining work from previous implementation. This supplement does not alter the
review above. It records work reported as implemented, not final acceptance or
closure of WP2. The active plan remains the sole owner of open findings and tasks.

### Reading Guide

| Record | Meaning |
|---|---|
| [Standard A, 2026-09-23](CODE_REVIEW_2026-09-23.md#code-review-cs-plan-073-standard-a-plan-activation) | Activation passed; Q-073-01/02/03 were resolved. |
| Implementation round, 2026-09-23 | Initial uncommitted WP2 increment, summarized below. |
| [Standard B, 2026-09-24](CODE_REVIEW_2026-09-24.md) | SF-1..7 applied; RF-01..18 opened; evidence-only commit `75f2da3`. |
| [Re-review, 2026-09-24](CODE_REVIEW_2026-09-24.md#standard-b-re-review-at-75f2da3) | `BLOCKED`; required coverage missing; RF-19..22 added. |
| Fix round, 2026-09-24 | RF-01..22 reported resolved, with the evidence map below. |
| [Final-review attempt, 2026-09-25](#summary) | `BLOCKED`; RF-23..28 remain open; no exhaustive final acceptance. |

The original draft's `296888f` code citations and 5-7-day estimate describe the
pre-implementation baseline, not the remaining effort. Standard A activated the
plan in `fedf212`; its detailed fingerprint and engineering validation remain
in the September 23 review record. Draft-only unanswered-question statements and
the superseded implementation order no longer belong in the live task list.

### Initial Implementation Record

Reported on 2026-09-23, in the working tree and uncommitted:

| Surface | Implemented increment |
|---|---|
| Persistence | Version1031 extended in place with revisioned `pauses`, occurrence suspension/provenance and `restart_of_id`; no upgrade path. |
| Projection | Lower `PauseProjectionService`, saved-zone interval helpers, batched read-only stamping and pause provenance. Its initial semantics are not approved by this history extract; RF-23..26 identify remaining defects. |
| Capacity | `DailyCapacityService`, frozen occurrence-effort input, exact planned minor units, occurrence dedupe and planner baseline cutover. |
| Agreements | `AgreementService` commands and existing occurrence writers for amendments, catch-up appointments and linked restart; one occurrence authority. |
| Pauses | Request/acknowledge, household, approve/reject, retrospective illness, shortening, reconciliation, departure and erasure plumbing. |
| API and receipts | Nine new routes; scheduling `commandId`; action/receipt integration and effective selection inputs. |
| Client and localization | Planner retry identity/DTO transport and EN/DE/POT generation; fuller minimum UI delivered in the fix round below. |
| Tests | Initial pause/agreement service and controller suites, schema/lifecycle/query-budget/route tests and client contract updates. |

Delivery gate report: unit 1023, integration 412 on a fresh migration chain,
Jest 1070, lint, PHP style, production build and 989-key localization check passed.
Later review findings supersede any implication that these counts proved the
complete C2 contract.

### Earlier In-review Fixes

| ID | Reported change | Evidence seam |
|---|---|---|
| SF-1 | Consume `scope` in PauseController before closed-shape service validation. | `PauseControllerTest` |
| SF-2 | Consume `roomTaskId` before forwarding agreement payloads. | `OccurrenceControllerTest` |
| SF-3 | Store compact planner result refs, rebuild authorized rows after commit/replay instead of exceeding the 8 KiB receipt bound. | Planner aggregate/receipt tests |
| SF-4 | Resolve the house timezone for authoritative room-task projections instead of an undefined variable. | Planner/read gate rerun |
| SF-5 | Include the illness subject in the receipt identity. | Changed-input receipt validation |
| SF-6 | Replace per-row pause-list queries with a revision pass and wallet map. | Pause-list/query review |
| SF-7 | Move `AgreementCommandContext` into its own PSR-4 file. | Autoload/style gates |

The detailed original findings and the September 24 review decisions remain in
the unmodified historical review record. The following fix-round evidence was
previously embedded in the active plan. References to plan sections in this
historical text refer to the pre-split plan; the current plan keeps the approved
contracts, required test groups and all still-open findings.

### RF-01 to RF-22 Fix-round Evidence

**Historical report, 2026-09-24:** all 22 findings were reported fixed in scope;
none was rescoped to the backlog. This report is retained as implementation
evidence, not promoted to an independent final-review verdict.

- **RF-01** - `OccurrenceAgreementService::amendOccurrenceDateForTargetUnderLock`
  (explicit-target writer; shared `applyDateAmendmentUnderLock` tail) and
  `AgreementService::amend` move the resolved occurrence and report the resulting
  version. Proof: `OccurrenceTimelineTest::testAmendDate_ExplicitTarget_MovesResolvedOccurrenceOnly`
  and `AgreementServiceTest::testAmend_DatePath_UsesExplicitTargetWriterAndReportsResultingVersion`.
- **RF-02** - `amendOccurrenceMembersUnderLock` derives and persists neutral
  `released` rows, retains role/share history and consent evidence. Proof:
  `testHandover_PersistsNeutralReleaseAndConsentEvidence`, including the
  handover-versus-share kind pin.
- **RF-03** - `excusedWalletsOn` returns a deduplicated provenance list per
  wallet and `deriveSuspension` freezes union evidence. Proof:
  `PauseProjectionServiceTest` and
  `PauseAgreementTest::testOverlappingPauses_UnionProvenancePersistedPerPauseRef`.
- **RF-04** - Catch-up work date/zone ride replacement member rows; released
  history retains its snapshot. Proof:
  `testCatchUp_WithParticipants_KeepsAppointmentIncludingZone`.
- **RF-05** - `AgreementService::resolveTarget` stamps effective projection
  facts and persists drifted suspension stamps under lock. Proof:
  `testRestart_EffectiveSuspension_DerivedAtCommandBoundary`. This did not cover
  the virtual read-path defect subsequently recorded as RF-26.
- **RF-06** - Planner apply requires `consent: true` in controller/service,
  receipt and evidence; the client checkbox gates Apply and survives stale
  retries. Proof: `SchedulePlanAggregateTest`, scheduling store/component specs,
  browser scenario `an apply without the explicit consent attestation is rejected 400`.
- **RF-07** - Explicit helper commitments receive exact-share capacity checks.
  Proof: `testAmend_HelperCommitment_IsCapacityChecked`,
  `testHandover_HelperCommitment_CapacityCheckedWithExactShare` and
  `DailyCapacityServiceTest::testAssertCommitmentsFit_Gates`.
- **RF-08** - Added `DailyCapacityServiceTest` with 11 tests: 34/33/33 vectors,
  dedupe, saved-zone mapping, appointments, suspension/helper release, child
  limits, overload, replacement removal and shared collection. The full original
  property/test matrix still requires verification; suite existence is not proof.
- **RF-09** - Added `PauseAgreementConcurrencyTest`: two-connection barrier
  races for plan/pause, duplicate restart, same-revision shortening and replayed
  receipts; failure-after-pause-insertion rollback. This inventory does not waive
  any other race required by the active plan.
- **RF-10** - Extended `OccurrenceTimelineTest`, `RoleGuardApiTest`,
  `AccountErasureTest`, `HouseMemberRemovalIntegrationTest` and
  `CompletionServiceTest` for WP2 contracts, departure, erasure and actual-work
  neutrality. Same-UID non-rebinding coverage pre-existed.
- **RF-11** - Added injected-clock
  `SchedulePlanAggregateTest::testApply_CrossingLocalMidnight_ConflictsWithoutWrites`:
  23:50 preview, 00:10 apply gives write-free 409; malformed stays 400; restoring
  the clock allows apply. CR-SVC-64 remains open until verified closure.
- **RF-12** - Extended scheduling/roomTasks/component contracts and six browser
  scenarios in `scheduling-apply-flow.spec.ts` and `family-rhythm.spec.ts`;
  exact titles registered in `required-scenarios.json`. Existing gate runner and
  Playwright pool registration were reused; the full gate was reported passed.
- **RF-13** - Added planner exact-share labels, consent checkbox, paused/away
  and overload clarification, keyboard/touch affordance and synchronized EN/DE/POT.
- **RF-14** - Updated ADR-012/013, API, architecture/testing, task/balancing/UI
  concepts and release-test-plan. Final correctness/integration review remains open.
- **RF-15** - Frozen-effort query uses an INNER join to saved occurrences, with
  no mutable-definition fallback. Proof: `CompletedTaskMapperEnergyTest` covers
  definition edits and unlinked-row exclusion.
- **RF-16** - `withSharedCollection()` scopes one batched capacity collection per
  evaluation. Proof: `testWithSharedCollection_RunsOneBatchedCollectPerPass`.
- **RF-17** - Size exceptions recorded in `docs/engineering/architecture.md`:
  PauseService 636, AgreementService 659, OccurrenceAgreementService 707,
  OccurrenceSelectionService 551, DailyCapacityService 550, PauseProjectionService
  361, SchedulePlanService 1234, SchedulePlanner.vue 765, SchedulingController
  466 and RoomTaskReadService 636. These are the historical measured values.
- **RF-18** - Derive kind from responsibility changes; unknown illness subject
  maps to 404; shortening only acknowledged pauses; each covering pause gets a
  referenceable evidence row. Proofs include
  `testRecognizeIllness_ForeignSubject_NotFound`,
  `testShorten_PendingRequest_Conflicts` and union `pause_ref` assertions.
- **RF-19** - Receipt identity includes action and normalized date, appointment,
  zone and participant inputs. Proof:
  `testAgreementReceipt_SemanticInputsAndActionIdentity` and unit receipt shapes.
- **RF-20** - Appointments derive independently of pause presence. Proof:
  `testCatchUp_WithParticipants_KeepsAppointmentIncludingZone` with zero pauses;
  `WorkflowQueryBudgetTest` completion budget updated from 58 to 61.
- **RF-21** - Planner checks the full exact-share vector after removing the
  replaced allocation; command capacity uses the same replacement-first rule.
  Proof: `testApply_SameDayReplacement_RemovesOldAllocationBeforeCheck`,
  `testPreview_JointSet_FailsWhenAnyMemberDoesNotFit` and unit replacement pin.
- **RF-22** - Under-lock authoritative selection token comparison with
  `hash_equals`. Proof: `testStaleSelectionToken_PauseDrift_ConflictsWithZeroWrites`
  and `testAmend_StaleSelectionToken_ConflictsWithoutWrites`.

### Historical Gate Reports

- September 24 fix-round report: Jest 1073; unit 1046; integration 438 with
  `--fail-on-skipped` on a fresh 1000-to-1031 migration chain; lint, build, noise,
  localization, composer style, backlog, encoding and whitespace checks passed.
  Full E2E gate reported all three pools with zero failed/skipped/flaky scenarios,
  discharging the earlier run deferral for that source state only.
- September 25 review reran the baseline suites described above and reproduced
  the new failures. Historical green reports do not replace post-fix execution.
- The documentation split itself closes no finding, backlog item or acceptance
  criterion and performs no lifecycle transition or commit.

### Original Planned Manifest

Retained from the pre-split section 5 so the final review can still inspect the
original caller/integration surface. `new`/`modified` describe the September 23
draft baseline, not instructions to recreate files that now exist. Required
test suites remain in section 4 of the active plan; the actual September 25
captured increment is separately listed in this record's Capture section.

```text
new:      lib/Db/{Pause,PauseMapper}.php
new:      lib/Service/{AgreementService,DailyCapacityService,PauseService,PauseProjectionService}.php
new:      lib/Controller/{OccurrenceController,PauseController}.php
modified: lib/Migration/Version1031Date20260918000000.php
modified: lib/Db/{Agreement,AgreementMapper,Occurrence,OccurrenceMapper,OccurrenceMember,OccurrenceMemberMapper,ScheduleRevision,ScheduleRevisionMapper,CompletedTaskMapper,TaskAssigneeMapper}.php
modified: lib/Service/{OccurrenceService,OccurrenceAgreementService,OccurrenceRowFactory,OccurrenceReconcileService,OccurrenceSelectionService,OccurrenceRevisionService,OccurrenceCompletionService}.php
modified: lib/Service/{SchedulePlanService,SchedulingService,RoomTaskService,RoomTaskReadService,TaskAccessPolicy,TaskCreationService,TaskService,HouseSetupService,HouseService,CompletionService,AccountErasureService,TestDataService}.php
modified: lib/Controller/{SchedulingController,RoomTaskController,TaskController}.php
modified: lib/BackgroundJob/TimelineJob.php
modified: appinfo/routes.php
modified: src/store/{scheduling,roomTasks,tasks}.js
modified: src/components/Scheduling/{SchedulePlanner,ScheduleCalendar,OvercapacityWarning}.vue
modified: src/components/Tasks/{RoomTaskEditModal,TaskEditForm,TaskList,TaskListItem,TaskListDetails,AssigneeChips,OverdueClaimDialog}.vue
modified: src/views/{SchedulingView,TodayView,TaskManager}.vue
modified: tests/integration/IntegrationTestCase.php
modified: tests/fixtures/{FamilyTestData,TestDataFixtures}.php
modified: tests/e2e/fixtures/{database-fixtures,occ-runner,test-house}.ts
modified: tests/e2e/fixtures/completion-api.ts
modified: tests/e2e/required-scenarios.json
modified: scripts/run-e2e-gate.js
modified: playwright.config.ts
modified: l10n/{en,de}.json
modified: l10n/templates/chorestrike.pot
modified: docs/adr/{ADR-012-actionable-household-scheduling,ADR-013-occurrence-agreements-and-corrections}.md
modified: docs/engineering/{architecture,testing}.md
modified: docs/api/README.md
modified: docs/concepts/{task-model-and-sync,energy-and-balancing,ui-use-cases}.md
modified: docs/testing/release-test-plan.md
```

### Documentation Split Validation

Documentation-only validation on 2026-09-25: `npm run backlog:check`,
`npm run docs:encoding`, `git diff --check` and the history record's standalone
whitespace check passed. The unchanged canonical checkers also passed with Git's
tracked-plus-untracked inventory supplied in-process, without staging files.
The encoding pass covered 790 entries including the untracked review record;
new history/plan fragment targets were checked against their headings.

Runtime changes were not edited: the before/after raw binary-diff Git blob
SHA-1 for `appinfo/lib/src/tests/l10n` is
`4dba4b9e57ede6c2f5f74ada788e5a1fa6c2c105`; the sorted untracked runtime
path/content fingerprint remains `83b9378ffc553289bc700e6097760c5b06862ddc`.
The index remains empty. Runtime gates were not rerun for this documentation
split; the prior review outcome and outstanding regression failures are unchanged.

---

# Code Review: CS-PLAN-074 Standard A (Plan Activation)

**Review Date**: 2026-09-25
**Reviewer**: Main agent (Kilo, skills: review, plan-management, git-workflow)
**Tier**: Standard A (plan) — pre-activation review of the WP3 work-accounting-and-replay child plan of CS-PLAN-070
**Scope**: `docs/plans/new/cs-plan-074-wp3-work-accounting-and-replay.md` (489 lines, untracked) plus its documentation-round package (6 dirty tracked doc files)
**Base Commit**: `master` @ `f365369f51e4e447cdadb34425306ad3982036e5`
**Owning plan**: CS-PLAN-074 (child of active CS-PLAN-070); findings routed per project-rules §4
**Outcome**: `PASS` — zero findings; plan activated `new/ → active/`

## Scope And Capture

- Branch `master`, HEAD `f365369f`, index empty at capture; the drafting round
  (plan + parent/README/backlog/tracking updates + the WP2 done-plan
  parent-link repair) was uncommitted by design and lands with this review.
- Scope fingerprint at capture: unstaged binary diff SHA256
  `905051b69a10986cbcf895d007dd03a24f2cb06b55a379df620f8be12c422fb6`; staged
  diff empty; untracked manifest = one path
  (`docs/plans/new/cs-plan-074-wp3-work-accounting-and-replay.md`, 46,383
  bytes, content SHA256
  `d98010cd1c8221cf582b1bbe3fad1d775091fb7f76585bac752412c1b751c28f`).
- Candidate validation: single regular non-symlink `*.md` in `new/`
  (dotfiles excluded); the `active/` destination did not exist; the explicit
  target path matched the resolution rule. The three pre-existing unrelated
  `.gitkeep` deletions (`docs/plans/{active,new,onhold}/`) stayed excluded
  from the review package and the commit, per the plan's §5 and the
  CS-PLAN-070/072/073 precedent.

## Review Verification

- **Template/structure:** sections 1-9 complete plus §10 activation decisions
  and a dated Draft Validation record; header fields present; acceptance
  criteria include gates, findings, tracking, durable docs and the final
  review requirement.
- **Baseline citations (all twelve exact at `f365369f`):**
  `src/store/roomTasks.js:452-457` (occurrence receipt without actual
  participants) and `:574-594` (latest-by-room-task undo);
  `CompletedTaskMapper::findEnergyUsageBetweenByHouse` (joined saved
  occurrence effort, completer-only attribution);
  `GameLogicService.php:114-140` (bonus math + `HouseUser` Coins/contribution
  writes) and `:142-212` (streak/freeze/title coupling through one locked
  update); `CompletionEffectService.php:119-154` (before-state snapshot
  restore); `RoomMapper.php:158-168` (creation cleanliness 50) and
  `:186-202` (absolute reset 100/0/`last_cleaned` NULL);
  `src/store/minigame.js:93-101` (Coins read) and `:209-268` (boost catalog/
  purchase, score earnings); `ArcadeSection.vue:148-149` (2048/Memory
  imports) and `:197-204` (prototype score loads);
  `StreakRestockService.php:61-68` (absolute freeze restock);
  `CommandService.php:35-38`/`:148-165` (payload hash + bounded id/version
  result refs); `OccurrenceCompletionService.php:298-313` (open-successor
  deletion protecting completed but not amended successors).
- **Manifest/tests:** every `M`/`R` path exists (180 checked: schema, services,
  Db, controllers, client, arcade retirement, §4 test matrix incl. entity/
  mapper/controller/view pins, fixtures/gates/l10n, durable docs, and the
  conditional Q-074-01-A set) and every `N` path is absent (24 incl.
  `RhythmService`/`WeeklyGoalService` + their tests, `WorkShare*`,
  `CompletionRevision*`, `RoomEvent*`, `EffortAllocationService`,
  `RoomReplayService`, `CompletionController` + test, `ParticipationDialog`
  + spec, `minorUnits` + spec, `ADR-014`, `CompletionCorrectionTest`,
  `RoomReplayConcurrencyTest`). `WalletService::ensureBinding` (line 32) and
  `WalletServiceTest` exist as the plan's retained WP0 base.
- **Migration chain:** `Version1031Date20260918000000` is the head; the
  parent's inventory reserves 1032 (`member_prefs`) and 1033 (`reward_items`),
  so the plan's "no new Version1034" statement correctly declines the next
  free number in favor of the in-place Version1030 extension — compliant with
  the pre-release fresh-install rule ("adjust the existing migration chain"),
  with the plan itself requiring the entire later chain to stay valid.
- **Parent fidelity:** WP3 row scope (effort-only multi-person ledger,
  negative wallets, targeted undo/attribution, room journal incl. reset,
  arcade mint/spend removal, T3/T4/T10 conservation across real races)
  matches the activated plan; the 7-11-day allowance plus the conditional
  3-5-day slice was consistent across the plan header, §6 and the parent row;
  ADR-014/015 reservations exact; the four durable-contract anchors
  (C3 accounting, C3 effort allocation, C3 correction, ADR-013) resolve.
- **Backlog routing:** CONCEPT-001 In Progress via the parent; CR-SVC-63 open
  with its journal/replay remainder explicitly mapped to this plan (no
  parallel item, per Finding Routing); FEAT-UX-025 UI portions correctly left
  to later packages.
- **No active-plan overlap:** the only active plan is the parent; WP0-WP2
  children are `done/`.
- **Type-A checks:** per-step ownership with individually validatable exit
  evidence; coherent scope; risks/dependencies named; no pre-baked workaround
  stacking (snapshot fallback, dummy legacy engines and skipped tests are
  explicitly banned); acceptance criteria complete; test plan adequate
  (T3/T4/T10 + conditional T5, real two-connection races, injected clocks,
  full gate list incl. `test:e2e:gate` and `--fail-on-skipped`).

## Decisions (Batched Question)

Both decisions were asked together through the structured question mechanism
with the recommended option first; the user selected the recommended option
in each case.

- **Q-074-01 — Correction closure across WP3/WP4: Option A.** The minimum
  planned-day/weekly/retained-recognition correction slice, its coupled
  legacy-effect retirement (freeze/restock/title snapshot effects) and the
  parent's T5 set move into WP3 (+3-5 days; total allowance 10-16 days).
  Consent/ranking and the remaining rhythm/UI build-out stay with
  WP5/WP4/WP6. WP3 can be independently finalized with a correct closed
  loop; no snapshot fallback, no disposable legacy engine.
- **Q-074-02 — Falsely attributed completer: Option A.** The completer or the
  authorized owner may remove the original completer from the actual shares
  while the original audit actor/time stay immutable; only the corrected
  actual people receive effort/Coins/personal credit. Initial completion
  still requires self-participation; no new task visibility, no approval
  queue.

## Outcome And Finalization

- **Findings:** none — no fixes required, no findings to route (nothing to
  §9, `backlog.md`, or fold-in). Outcome `PASS`.
- Applied decisions: plan header status/effort updated (Active; 10-16 days),
  §6 correction-closure slice row made unconditional, §10 resolutions
  recorded, intro/§9/Draft-Validation stale-drafting notes refreshed;
  parent WP3 row (active child, 10-16 days) and WP4 row (minimum slice moved
  out) updated.
- Transition: plan moved `new/ → active/`; CR-SVC-63 set In Progress with
  CS-PLAN-074 owning; CONCEPT-001 narrative, `ACTIVE_TASKS.md` (In Progress +
  activation one-liner), `docs/README.md` and `docs/plans/README.md` updated
  with repaired links.
- Gates: `npm run backlog:check` (26 files link-checked, all green),
  `npm run docs:encoding` (790 files clean), `git diff --check` staged +
  unstaged clean, plan standalone `git diff --no-index --check` whitespace
  clean (exit 1 = no-index differences-exist code with no error output).
  Runtime gates not applicable — documentation-only review; the runtime tree
  was not touched.
- Commit: one scoped conventional commit on `master` containing the activated
  plan, its documentation-round package, tracking/backlog/review-record
  updates; the pre-existing `.gitkeep` deletions remain unstaged.
