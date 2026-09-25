# CS-PLAN-073: WP2 - Agreements, Capacity and Pauses

> **Status:** Done; final Standard B `PASS-WITH-FIXES` (2026-09-25, R9): the whole WP2 increment (implementation, RF-01..28 fix rounds, durable docs) reviewed; two minor orphan-code findings fixed in-round (dead `DailyCapacityService::assertValidShares`, unused `PauseProjectionService` calendar dependency); gates green on the reviewed source (Docker unit 1073 / integration 450 zero-skip, Jest 1073, lint, cs:check 0-of-351, l10n 995 keys, backlog/encoding/diff-check; E2E gate accepted per user decision on mtime-verified R8 evidence — no E2E-relevant file changed after the passing 2026-09-25 16:33 run). Plan moved `active/ → done/`.
> **Priority:** P1; the former block-level finding RF-28 was resolved under restricted handling (R1).
> **Effort:** Original implementation allowance was 5-7 engineering days; the R0-R9 fix/closure round completed 2026-09-25.
> **Depends on:** [WP1 / CS-PLAN-072](../done/cs-plan-072-wp1-occurrence-timeline.md) and [WP0 / CS-PLAN-071](../done/cs-plan-071-wp0-write-identity-foundation.md), delivered.
> **Related backlog items:** CONCEPT-001 via [CS-PLAN-070](../active/cs-plan-070-family-rhythm-and-reward-clarification.md); FEAT-UX-025 backend slice (delivered; UI/digest portions open); CR-SVC-64 (closed → archive 2026-09).
> **Last reviewed source:** `master` at `75f2da3` plus the committed WP2 increment (final review capture: unstaged diff `1cca50a9…`, 18 untracked, 96 paths).

**Remaining work:** [section 6](#6-implementation-order). **Open findings:**
[section 9](#9-review-findings-plan-owned). **Previous implementation and fixes:**
[separate history extract](../../backlog/archive/CODE_REVIEW_2026-09-25.md#implementation-history-extract).

## 1. Problem Summary

The WP2 implementation and RF-01..22 fix round exist in the working tree.
They introduced pause persistence, command coordination, exact-share capacity,
agreement writers, minimal client integration and regression suites. Do not
reimplement these foundations or treat the original implementation order as new
work. Their evidence is now in the separate history extract, not this task list.

The September 25 review stopped early: RF-23..26 were reproduced, RF-27 was
source-confirmed, and RF-28 blocks finalization. Passing existing suites did not
establish the missing contracts. The whole increment, earlier fixes and durable
documentation still require final verification, not just the six newest findings.

This document was split on 2026-09-25 at the user's request. It retains the
approved scope, decisions, test obligations and completion gates; it does not
rescope work, authorize implementation in this documentation round, close a
finding, or move the plan to `done/` or `onhold/`.

## 2. Strategy

Keep one command coordinator (`AgreementService`), one under-lock occurrence
writer (`OccurrenceAgreementService` behind `OccurrenceService`), one daily
capacity evaluator and one lower pause evaluator. Correct the shared facts and
then exercise every consumer; do not repair GET, job and mutation paths with
independent rules or add compatibility fallbacks.

| Owner | Boundary that remains unchanged |
|---|---|
| This plan / WP2 | Planned roles/shares, concrete amendments, catch-up/restart, pause lifecycle, whole-day capacity, stale preview and working minimum client surface. Owns the relevant parent RF-070-03/04/10/11 portions and new retention coverage. |
| WP3 | Actual multi-person participation, `work_shares`, Coins and financial attribution/correction. Never infer actual coworkers from planned assignments. |
| WP4 / WP5 | Rhythm/goals/freeze retirement and quiet digest/preferences. WP2 provides neutral facts, not these features. |
| WP6 | Full pause-management and participation UI, recovery flows and My Day redesign. Existing touched buttons/labels must work in WP2. |
| WP9 | Combined cross-DB, repeated-browser and voluntary family evidence. No family trial is authorized here. |

R0-R9 are implementation order, not separate plans. One WP2 implementation owner
coordinates shared files; test/client/doc work follows its contracts. No Agent
Manager sessions or new branches are requested. Each package produces code,
regressions and evidence together when implementation is separately requested.

## 3. Backend / Frontend Changes

These are preserved acceptance contracts, not claims about current correctness.

### 3.1 Persistence and Service Ownership

- Keep immutable rule revisions and prospective defaults distinct from concrete
  occurrence agreements. Use existing wallet, occurrence and revision identities.
- Preserve responsible/joint/helper roles, obligation, positive integer shares,
  saved work date/zone and active/released/excused dispositions. An executable
  allocation totals exactly 100%; at most 100 positive whole-percent participants;
  reject duplicate subjects, invalid totals and unsafe arithmetic before writes.
- Equal-share remainders follow stable wallet order (34/33/33). Planned energy
  is integer hundredths `E * P`; never redistribute a paused member's share.
- Keep pause group/revision identity, bounded reason codes, saved zone,
  retrospective marker, decision timing and next-day boundaries traceable.
  Retain append-only agreement evidence and per-pause union provenance.
- Fix missing persistence facts only through a reviewed change to the existing
  fresh-install Version1031 chain; no upgrade migration, backfill or legacy shim.
  R2/R3 must first establish whether existing timestamps retain the needed facts.
- Lower projection code may depend on mappers/pure helpers, not command services.
  Preserve house-first locking, sorted subsequences and existing batch budgets.

### 3.2 Whole-Day Capacity and the WP3 Seam

- Count frozen actual completion effort plus active outstanding shares, including
  commitments outside planner selection. Dedupe by occurrence; undone work and
  fulfilled obligations must not be charged twice. Helpers' agreed commitments
  consume capacity, but an offer alone creates no obligation.
- Resolve concrete current participants rather than revision defaults after an
  amendment. Remove the replaced occurrence allocation once before checking the
  replacement; validate every affected person's availability, pause and energy,
  plus owner-set child `maxDailyTasks`, with no per-item owner override.
- Completed effort comes from the saved occurrence join, never mutable task
  definition energy. WP2 attributes it to the recorded completer only; WP3 owns
  actual multi-person allocation. Early work consumes its actual day; catch-up
  consumes its agreed appointment without rewriting the miss.
- Retain overload and existing agreed work visibly, reject extra commitments and
  offers, allow atomic capacity-fitting replans, and never block actual completion
  on capacity. Do not truncate duties to make the UI appear under budget.
- Preserve Q-073-02 saved-zone rules in section 10. Return exact completed/planned/
  remaining minor units, distinct counts, limits and clarification reasons.
  Include hidden commitments internally without exposing their IDs or snapshots.

### 3.3 Agreements, Catch-up and Restart

- Resolve target identity, membership, role, visibility, effective version and
  selection token under the house-first aggregate. Ordinary amendments require
  an unfulfilled target and `now < oldWindowEnd`; the exclusive end conflicts.
- Owner-recorded explicit consent covers affected responsible/joint subjects;
  preserve before/after evidence, neutral release and occurrence identity.
  Concrete handovers do not change future defaults. Date/week changes move the
  same occurrence's attribution once; release is not completion.
- Catch-up is only for the current unfulfilled uncovered miss. Keep its original
  planned date/window/miss while changing the work appointment and participants.
  Current open work retains completion precedence over stale catch-up cards.
- Restart requires a neutral suspended interval predecessor, one linked live
  successor and explicit agreement. Only real restart completion advances the
  next interval; fixed schedules keep their anchors.
- Joint resumption is an explicit feasible 100% agreement before the original
  deadline, not automatic redistribution or a blanket date-amendment bypass.
  R6 verifies the existing command/writer path can express that approved action.
- Audit due-date, single/bulk assignment, planner and generic definition-edit
  callers together: none may bypass owner, cutoff, consent or whole-day capacity.

### 3.4 Pause Lifecycle and Neutral History

| Action | Required effect |
|---|---|
| Child requests personal pause | Pending; no changed duty, deadline, capacity or goal. Expiry neither approves nor rejects it. |
| Adult acknowledges self / owner creates household pause | Recognized coverage for eligible unfulfilled obligations; no synthetic work. |
| Owner approves/rejects pending request | Explicit expected-revision decision; ordinary late approval cannot erase an expired miss. |
| Owner recognizes retrospective illness | Explicit audited exception for qualifying unfulfilled historical obligations; preserve completed work. |
| Shorten/revoke acknowledged pause | Revision effective no earlier than next local day; preserve today/past, remove superseded future coverage. Child changes still need owner acknowledgment. |
| Pause one obligated joint participant | Suspend until an explicit feasible agreement; retain the original shares, zero live planned capacity while suspended. |
| Pause optional helper only | Apply Q-073-01: release that commitment, retain the responsibility and remaining shares; show non-executable if infeasible. |

Coverage uses the saved planned-date label, not the window end. Saturday in a
Friday-Monday window is affected by Saturday, not merely Monday. Different pause
groups compose as a union; a newer revision within one group must not keep its
superseded future coverage alive. Shortening one group cannot remove another
group's excuse. Future reactivation must fit capacity; interval work still needs
explicit restart. Voluntary actual work remains possible without restoring duty.

Virtual/persisted reads, completion, restart and cron must use the same decision.
GET stays write-free. Mutations/job passes persist effects idempotently, without
fictional actor receipts. Preserve completed facts and recognized historical
excuses across departure, shortening, erasure and later reconciliation.

### 3.5 APIs, Transactions and Clients

- Keep `/occurrences/{id}/amend|catch-up|restart`, pause list/create/decision/
  update actions, `/pauses/recognize-illness`, scheduling preview/apply and
  daily/detail/range/overdue reads consistent. `{id}` is the public occurrence key.
- Validate closed command payloads, date/enums, expected versions/revisions and
  explicit consent. Error classes remain malformed 400, visible role denial 403,
  invisible/foreign target 404, stale/business conflict 409.
- Keep `@NoAdminRequired`, CSRF protection and `TaskAccessPolicy`. Authorize before
  receipt replay; same UUID/normalized semantic payload returns original identity
  plus current safe state; changed action/input conflicts. Facts, evidence and
  receipt commit/roll back together, with no network work inside transactions.
- Fingerprints cover effective occurrence/rule/member/pause inputs, saved zones,
  limits/availability, completed work, proposal and evaluation day. Preserve
  CR-SVC-64: midnight drift yields write-free 409 before relative-date rejection;
  genuinely malformed input remains 400.
- Preview remains write-free, at most 100 selected rows/seven days. Keep exact
  shares, consent attestation, stable retry UUID, 409 draft retention/reload and
  neutral/overload clarification. Check touched controls on keyboard, desktop
  and mobile. Every new string uses `t()` with EN/DE/POT updated together.
- Public handling of RF-28 remains limited to the approved placeholder in
  section 9; this documentation split does not authorize broader disclosure.

### 3.6 Retention and Durable Documentation

Preserve Q-073-03 departure behavior and WP0 erasure/no-rebinding rules. Audit
pause subjects/requesters/deciders, consent snapshots and command references;
retain only non-reidentifiable shared facts. Exclude tombstones from planning;
production reset is not a history purge. Keep every new table in test cleanup.

Update ADR-012/013, API, architecture/testing, task/balancing/UI concepts and
release-test-plan with the corrected behavior. ADR-014/015 remain reserved for
later packages. The plan/history extract cannot be the sole durable description.

## 4. Tests

All original WP2 obligations remain required. Test files below exist now; a
reported passing suite is not proof that each assertion is present. R0 maps each
case to a concrete method/scenario; R7 fills any gap before final review.

| Group | Required assertions | Concrete suites |
|---|---|---|
| T2-A / C2.2-3 | Neutral handover release, same key, old/new consent, week move; fulfilled/expired/exact-deadline and private/foreign/role denials; replay authorization. | `tests/unit/Service/AgreementServiceTest.php`; `tests/integration/Service/OccurrenceTimelineTest.php`; `tests/integration/RoleGuardApiTest.php`. |
| T2-B / C2.4-8 | Saturday vs Monday, pending across deadline, explicit illness, completed facts retained; sole/joint suspension and 50/50 snapshot with zero capacity; feasible resumption; ten occurrences minus two suspensions means eight eligible facts, not two completions. | `tests/unit/Service/{PauseService,PauseProjectionService}Test.php`; `tests/integration/Service/PauseAgreementTest.php`. |
| T2-C / C2.13-14 | Next-day shortening, today retained, overlapping groups, voluntary paused completion, one linked interval restart, fixed anchor, stopped-cron projection and job replay. | `tests/integration/Service/{PauseAgreement,OccurrenceTimeline}Test.php`; `tests/unit/BackgroundJob/TimelineJobTest.php`. |
| T3-A | Energy 1-5, group sizes 1-100, reordered/equal/custom partitions; invalid totals/duplicates/overflow; actual/outstanding dedupe; early/catch-up/helper work, both child limits, every joint participant; hidden commitments counted without disclosure. | `tests/unit/Service/{DailyCapacity,SchedulePlan}ServiceTest.php`; `tests/integration/Service/SchedulePlanAggregateTest.php`. |
| T3-B | Real over-limit completion accepted; no extra offer or hidden existing duty; frozen effort after definition edit; planned coworkers not actual workers; undo removes actual load and preserves neutral evidence. | `tests/unit/Service/{Completion,DailyCapacity}ServiceTest.php`; `tests/integration/Service/OccurrenceTimelineTest.php`; `tests/integration/Db/CompletedTaskMapperEnergyTest.php`. |
| Preview / CR-SVC-64 | Illegal date override; every joint participant fits; old load removed once; pause/completion/role/zone/limit drift; injected midnight write-free 409 versus malformed 400. | `tests/unit/Service/SchedulePlanServiceTest.php`; `tests/integration/Service/SchedulePlanAggregateTest.php`. |
| Races / rollback | Two plans compete for one child slot; plan vs pause/completion/role loss; completion vs acknowledgment/shortening; duplicate restart, concurrent revisions/replay; failure after pause/evidence insertion rolls back all facts and receipt. | `tests/integration/Service/{SchedulePlanConcurrency,PauseAgreementConcurrency}Test.php`. |
| T0 extension | Fresh schema/indexes/reset, tombstones, pause/audit/receipt erasure and same-UID non-rebinding; membership/private-task matrix including owner without private access; GET creates no facts. | `tests/integration/Db/FamilyRhythmSchemaTest.php`; `tests/integration/Service/{AccountErasure,PauseAgreement}Test.php`; `tests/integration/RoleGuardApiTest.php`; `tests/unit/RouteMatrixTest.php`. |
| Controller / read | Strict shapes, revision/actor/state mapping, pause/token drift, virtual write-free reads, departure/rejoin and bounded queries. | `tests/unit/Controller/{Occurrence,Pause,Scheduling}ControllerTest.php`; `tests/unit/Service/OccurrenceServiceTest.php`; `tests/integration/Service/HouseMemberRemovalIntegrationTest.php`; `tests/integration/Db/WorkflowQueryBudgetTest.php`. |
| Client | Exact DTOs, consent, one retry UUID, stale draft/reload, retained paused/overloaded rows; editor, single/bulk assignment and claim contracts. | `tests/unit/store/{scheduling,roomTasks,apiContract}.spec.js`; `tests/unit/components/{SchedulePlanner,SchedulingView,OvercapacityWarning,RoomTaskEditModal,TaskList,TaskListDetails,AssigneeChips,OverdueClaimDialog,TodayView}.spec.js`. |
| Browser | Owner applies shares/consent; child sees agreements/overload; API-created pause renders clarification; role/private denials; stale target/plan retains draft. | `tests/e2e/{scheduling-apply-flow,family-rhythm,workflow-state-reconciliation,family-core-ux}.spec.ts`; adapt `{scheduling,flex-window-scheduling}.spec.ts` if contracts change. |

Use injected clocks, UTC and Europe/Berlin DST/house-zone changes. Real races need
two connections and barriers, not sequential idempotency calls. Check final
facts/evidence/receipts from a fresh connection. Do not delete awkward tests,
substitute green subsets or add skips. TECH-TEST-003 and TECH-JEST-001 remain
separate harness/tooling owners, not waivers or permission to suppress failures.

## 5. File Manifest

This is the remaining change surface, not a requirement to edit every file.
The [captured full increment](../../backlog/archive/CODE_REVIEW_2026-09-25.md#capture)
and [original planned manifest](../../backlog/archive/CODE_REVIEW_2026-09-25.md#original-planned-manifest)
also belong to the final review. Keep already delivered changes in scope even
when no further edit is needed; audit unchanged callers listed in the original
manifest rather than assuming their old contracts still hold.

| Package | Expected files |
|---|---|
| R2/R3 temporal rules | `lib/Service/{PauseService,PauseProjectionService}.php`; if necessary `lib/Db/{Pause,PauseMapper}.php`, `lib/Migration/Version1031Date20260918000000.php`; temporal unit/integration suites from section 4. |
| R4 virtual participants | `lib/Service/{PauseProjectionService,OccurrenceSelectionService,OccurrenceReconcileService,OccurrenceService,OccurrenceRowFactory}.php`; occurrence/pause/TimelineJob tests. |
| R5 concrete capacity | `lib/Service/{AgreementService,DailyCapacityService,OccurrenceAgreementService,OccurrenceService}.php`, `lib/Db/OccurrenceMemberMapper.php` as needed; agreement/capacity/timeline/aggregate tests. |
| R6 joint resumption | Shared R2-R5 services; `lib/Db/OccurrenceMember.php` and existing revision/member writers if needed; `PauseAgreementTest`, `OccurrenceTimelineTest`, `DailyCapacityServiceTest`. |
| R7 cross-path/client | `lib/Service/{HouseService,AccountErasureService,CompletionService,OccurrenceCompletionService,RoomTaskReadService,SchedulePlanService}.php`, `lib/BackgroundJob/TimelineJob.php`; section 4 tests; existing scheduling/roomTasks stores and planner/task-list views only where needed. |
| Conditional schema/test cleanup | Existing Version1031, `IntegrationTestCase`, test fixtures and `TestDataService`; no production backfill. |
| Browser/i18n | Section 4 browser specs, `tests/e2e/required-scenarios.json`, `scripts/run-e2e-gate.js`, `playwright.config.ts`; keep registrations aligned, change pools/runner only if needed; `l10n/{en,de}.json`, POT if strings change. |
| Durable docs | ADR-012/013; `docs/api/README.md`; `docs/engineering/{architecture,testing}.md`; `docs/concepts/{task-model-and-sync,energy-and-balancing,ui-use-cases}.md`; `docs/testing/release-test-plan.md`. |
| Coordination/finalization | This plan, parent WP2 status, plans/docs indexes, `ACTIVE_TASKS.md`, owning backlog entries and review record; monthly archive only on actual item completion. |

R1 remains subject to its restricted evidence/approval handling; do not add
unremediated security details or private file links to this public manifest.

## 6. Implementation Order

Each unchecked task is pending. Close it only with the specified evidence, then
update the corresponding finding in section 9. The documentation split executes
none of these tasks.

### R0 - Capture and Regression Inventory

**Owner:** WP2 implementation lead. **Depends on:** an implementation request.

- [x] Capture branch, HEAD, index and all changed/untracked scope fingerprints;
  preserve the existing increment and unrelated `.gitkeep` deletions.
- [x] Map every section 4 assertion and prior RF-01..22 proof to a current test
  method/title. Distinguish passing evidence, missing assertion and red assertion;
  do not use suite counts or fix-report checkmarks as acceptance.
- [x] Establish the remaining data-model decisions for acknowledgment time,
  revision effective boundaries and departure history. Inspect existing fields
  before proposing schema edits; explicitly approve necessary migration/security
  decisions in the implementation round, not by inference from this split.
- [x] Replace diagnostic-only proof with maintained regressions in the owning
  suites. First demonstrate RF-23..26 fail on the current implementation; add a
  failing real handover-follow-up case for RF-27. Follow R1 handling separately.

**Exit:** evidence map, deterministic failing cases and explicit implementation
decisions exist; no unexplained omitted requirement. Reassess remaining effort.

**R0 record (2026-09-25):** captured on `master` @ `75f2da3` — index empty,
unstaged binary diff (excluding the three planned `.gitkeep` deletions)
`4b1fe1abc752cb8dd18f90cbcf91ca610403ac83`, 93-path sorted manifest
`2aa92ccb74dba96ac04fbc2a3e009a8645e5a78c`; the increment was preserved
untouched. All five diagnostic probes reproduced RF-23..26/28 red on the
current implementation and RF-27 was source-confirmed before any fix; the
probes now pass green against the fixed sources (production-shaped fixtures).
Data-model decisions, explicitly authorized by the user in the round's
question batch: (1) the decision/acknowledgment time is the persisted
`updated_at` of an acknowledged revision (NOT NULL, re-stamped only by an
explicit owner decision on a pending row) — no schema edit; (2) the
shortening effective boundary is the day after the decision day in the
pause's saved zone, with per-group precedence of the latest acknowledged
revision whose boundary is at or before the date — no schema edit; (3)
departure history needs no schema change (current-wallet filtering plus
pending cancellation already retain the required facts).

**Evidence map (RF-01..28 → owning tests, verified 2026-09-25):** RF-01..22
keep the owning methods listed in the review record's section 4 matrix; all
of them re-ran green on the fresh family-profile install (Docker unit 1073,
integration 450, Jest 1073, E2E gate 47 specs / 3 pools) — no in-scope
assertion was found missing, so nothing moved to section 9. The fix-round
findings map as follows (probes = the private diagnostic suite, superseded by
these maintained regressions):

| Finding | Owning test methods |
|---|---|
| RF-23 | `PauseProjectionServiceTest::testExcusedWallets_ShorteningRemovesSupersededFutureDatesWithFullHistory`, `..._MultipleShortenings_EachDecisionGovernsFromItsOwnBoundary`, `..._PendingOrRejectedShortening_LeavesAcknowledgedCoverageUnchanged`, `..._ShorteningOneGroup_NeverRemovesAnotherGroupsExcuse`, `testEffectiveBoundaryKey_UsesSavedZoneMidnight_NotEvaluationZone`; `PauseAgreementTest::testShortening_RemovesSupersededFutureCoverage_FullPersistedHistory`, `testChildShortening_PendingLeavesCoverageUnchanged_UntilOwnerAcknowledges`; probes 1-2. |
| RF-24 | `PauseProjectionServiceTest::testDeriveSuspension_OnePausedJointParticipant_SuspendsTheWholeOccurrence`, `..._PausedResponsibleWithHelper_SuspendsAndReleasesHelperOnlyWhenCovered`, `..._HelperOnly_ReleasesCommitment`, `..._AllCovered_Suspends`; `AgreementServiceTest::testAmend_Participants_ResumingSuspendedTarget_PersistsRestampedFacts`, `..._UnchangedSuspension_NoExtraStampWrite`; `PauseAgreementTest::testJointPause_SuspendsWholeOccurrence_ExplicitFeasibleResumption`, `testJointResumption_AfterDeadlineOrWithoutConsent_RejectedWithZeroWrites`; probe 4 semantics. |
| RF-25 | `PauseProjectionServiceTest::testExcusedWallets_LateOrdinaryAcknowledgment_DoesNotExcuseExpiredDeadline`, `..._DecisionExactlyAtTheOriginalDeadline_IsNotTimely`, `..._RetrospectiveIllnessMarker_ExcusesQualifyingOldMiss`, `..._RequestBeforeExpiryApprovedAfter_UsesTheActualDecisionTime`, `..._DayLevelRule_...`, `testStampSuspension_LateOrdinaryAcknowledgment_LeavesExpiredMissUnsuspended`; `PauseAgreementTest::testLateOrdinaryAcknowledgment_DoesNotExcuseExpiredMiss_RetrospectiveIllnessDoes`, `testRequestBeforeExpiryApprovedAfterDeadline_UsesTheDecisionTime`; probe 3. |
| RF-26 | `PauseProjectionServiceTest::testStampSuspensionOnEntries_VirtualEntry_UsesSavedRevisionParticipants`, `..._MaterializedEntry_UsesConcreteRowsNeverRevisionDefaults`, `..._VirtualAndMaterializedTwins_Agree`; `OccurrenceTimelineTest::testStoppedCron_VirtualProjection_MatchesLaterMaterializedRow`, `testStoppedCron_RestartOfFixedVirtualTwin_ConflictsWithZeroWrites`; probe 4. |
| RF-27 | `AgreementServiceTest::testAmend_DateOnly_AfterHandover_ValidatesConcreteArrangement`, `testCatchUp_WithoutParticipants_ValidatesConcreteArrangement`, `testAmend_DateOnly_WithoutConcreteRows_FallsBackToRevisionDefaults`; `OccurrenceTimelineTest::testAmend_DateOnly_AfterHandover_FollowsConcreteArrangement`, `testAmend_DateOnly_ConcreteParticipantPaused_ConflictsDespiteFreeDefault`, `testCatchUp_WithoutParticipants_FollowsConcreteArrangement`. |
| RF-28 | `PauseServiceTest::testListForViewer_*` (unrelated adult/child, every non-pending state, owner, self, house scope, pending hidden, departed subject); `RoleGuardApiTest::testPauseIndex_WireResponse_PersonalMetadataIsSelfOwnerOnly` (real wire); probe 5. |

### R1 - Resolve the Block-level Finding

**Owner:** WP2 implementation lead with explicit security-change authorization.
**Depends on:** R0. **Finding:** RF-28.

- [x] Resolve RF-28 under the previously approved restricted handling; retain
  unremediated details outside tracked documentation and obtain necessary explicit
  authorization before security logic changes.
- [x] Supply regression evidence at the affected boundaries and verify the
  approved contract, including denial cases and absence of secondary disclosure.
- [x] Record only an approved sanitized resolution in section 9. Do not expand
  the public placeholder or publish diagnostic material through this plan.

**Exit:** RF-28 is verifiably remediated and its handling recorded. Until then,
finalization remains `BLOCKED`, regardless of other green tests.

### R2 - Make Shortening Effective

**Owner:** WP2 backend. **Depends on:** R0 temporal-fact decisions. **Finding:** RF-23.

- [x] Resolve revision precedence within each pause group before unioning coverage
  across groups. An undecided/rejected child shortening must leave acknowledged
  coverage unchanged; an acknowledged shortening removes only that group's
  superseded future dates and retains today/past in the pause's saved zone.
- [x] Keep the decision/effective boundary reproducible after later revisions,
  house-zone changes and job replay. Do not infer it from evaluation wall time or
  discard old evidence to make the new projection pass.
- [x] Feed the same effective coverage into read stamping, persistent effects,
  capacity and fingerprints. Reactivation must fit capacity; preserve the
  explicit interval-restart rule and neutral history.
- [x] Replace the unit test that manually filters out revision 1. Test the full
  history: October 5-9 shortened on October 6 to October 6 preserves October 5/6
  and removes October 7-9, unless a separate group covers those dates.
- [x] Add multiple-shortening, pending/rejected-shortening, overlap, saved-zone
  midnight/DST, future occurrence/capacity and repeated-job assertions in
  `PauseProjectionServiceTest`, `PauseServiceTest` and `PauseAgreementTest`.

**Exit:** future coverage actually shrinks with full persisted history; historical
excuses and independent-group provenance survive; repeated reconciliation is inert.

### R3 - Enforce the Original Deadline for Recognition

**Owner:** WP2 backend. **Depends on:** R2 temporal facts. **Finding:** RF-25.

- [x] Distinguish range inclusion from eligibility to excuse a particular
  occurrence. Ordinary recognition must predate the original exclusive deadline;
  a later read/job must not reinterpret a timely decision as late or vice versa.
- [x] Use the actual recognition decision, not a child's earlier request time.
  Pending/rejected requests remain ineffective; crossing the deadline does not
  approve, reject or extend them. Keep retrospective illness an explicit marker.
- [x] Apply the same eligibility rule to immediate writes, stopped-cron virtual
  projections and later materialization. Completed/covered facts stay intact;
  no synthetic completion, success, reward or interval advance.
- [x] With an injected clock, test recognition just before, exactly at and after
  the original deadline; also test a request before expiry approved after it.
  Contrast ordinary recognition with the explicit retrospective-illness action
  for the same unfulfilled occurrence, using materialized and virtual cases.

**Exit:** only the approved explicit exception can neutralize a qualifying old
miss; timely recognition survives late reconciliation without rewriting history.

### R4 - Align Virtual and Persisted Projections

**Owner:** WP2 backend. **Depends on:** R2/R3. **Finding:** RF-26.

- [x] Resolve participant facts from concrete rows for materialized occurrences
  and the associated saved revision for genuinely virtual occurrences. Never
  replace concrete handovers/releases with current definition defaults or hide
  a corrupt materialized row behind a compatibility fallback.
- [x] Pass identical role/share/disposition and appointment facts into the common
  suspension decision. Preserve pause-independent catch-up date/zone derivation.
- [x] Compare the same occurrence before/after materialization: public key,
  effective suspension/provenance, appointment and selection semantics agree;
  legitimate version changes remain explicit rather than self-conflicting.
- [x] Test stopped cron followed by GET, completion, restart and job replay.
  GET creates no occurrence/evidence/receipt. A changed pause invalidates a stale
  token with zero committed writes; unchanged effective facts do not spuriously
  invalidate a displayed target solely because it was materialized.

**Exit:** unit projection tests and real timeline/job/command tests prove parity,
including helpers, released rows, joint roles and saved-zone appointments.

### R5 - Validate the Concrete Arrangement

**Owner:** WP2 backend. **Depends on:** R4 participant resolution. **Finding:** RF-27.

- [x] Resolve active occurrence members under lock for date-only amendments and
  catch-ups with no participant payload; use defaults only when deriving a
  virtual arrangement. Include helper commitments in capacity and the correct
  affected responsible/joint people in consent; released/excused history is not
  a live allocation. Do not silently change the restart's approved arrangement.
- [x] Validate the exact people/shares/work basis that the writer will persist.
  Remove the old occurrence's load once, then check energy, availability, pause
  and child task limits. Capacity failure rolls back facts, evidence and receipt.
- [x] Add a real A-to-B handover followed by date change and catch-up without
  participants. B full/paused/unavailable must fail even when A has space;
  B fitting must succeed even when released A does not. Assert consent, shares,
  same occurrence key and unchanged future defaults as well as the response.
- [x] Cover custom joint shares, agreed helpers, same-day replacement, explicit
  participant payloads and cross-zone appointments. Keep existing receipt
  replay/changed-input and stale-token regressions green.

**Exit:** validation/evidence/writes use one authoritative arrangement, proven in
`AgreementServiceTest`, `DailyCapacityServiceTest` and `OccurrenceTimelineTest`.

### R6 - Suspend and Explicitly Resume Joint Work

**Owner:** WP2 backend. **Depends on:** R2-R5. **Finding:** RF-24.

- [x] For explicit joint work, pausing any obligated participant suspends the
  occurrence; retain the 50/50 snapshot and charge zero live planned capacity.
  Keep sole responsibility and optional-helper rules distinct.
- [x] Verify the approved owner-recorded resumption can be expressed through the
  existing single-occurrence writer before the original deadline. Require consent
  and a feasible full 100% arrangement; no automatic 50-to-100 redistribution,
  general suspended-date bypass, duplicate occurrence or default rewrite.
- [x] Preserve release/excusal/provenance on resumption and make the next GET/job
  agree with the accepted arrangement. An incomplete or over-capacity proposal
  must not partially apply. If the existing action cannot express the contract,
  obtain explicit approval for the minimal API/validation change before coding.
- [x] Test 0/1/all paused obligated participants, helper-only pause, released
  historical rows, overlapping groups, valid pre-deadline resumption and
  no-consent/over-budget/exact-deadline rejection with zero writes.

**Exit:** projection, capacity and DB-backed command tests establish both neutral
suspension and explicit resumption, not only a changed boolean unit assertion.

### R7 - Complete Cross-path, Client and Documentation Coverage

**Owner:** WP2 lead coordinating backend/test/client work. **Depends on:** R1-R6.

- [x] Finish the R0 evidence map for the entire original scope. Independently
  verify RF-01..22, not just the newest fixes; put uncovered in-scope obligations
  in section 9 rather than declaring them later-package work.
- [x] Run real plan/pause/completion/shortening/role-loss races and failure-after-
  evidence-insertion rollback from section 4. Check fresh-connection facts and
  receipts; retain wallet/erasure/departure/rejoin and helper-history coverage.
- [x] Trace legacy due-date, editor, single/bulk assignment, claim and planner
  consumers. Pin corrected DTOs, stale drafts and visible neutral/overload state
  in their existing unit/browser suites; no new full WP6 screen.
- [x] Register exact browser titles in `required-scenarios.json`, verify pool
  ownership/discovery and only change runner/config if selection requires it.
  Test touched controls using keyboard and desktop/mobile layouts.
- [x] Update permanent docs from verified behavior; retain WP3/WP4/WP5/WP6
  boundaries. Refresh changed string artifacts and measured query/size inventory
  where needed; do not claim this plan or an archived report is the living spec.

**Exit:** assertion-to-test evidence is complete, durable docs are truthful, client
contracts work and no required test is missing, silently deferred or newly skipped.

### R8 - Run the Complete Gates

**Owner:** WP2 implementation lead. **Depends on:** R7; final candidate source fixed.

- [x] Follow the [Docker workflow](../../standards/ai/skills/docker-dev/SKILL.md)
  to verify the fresh install and schema/reset fixtures; disposable DB only.
- [x] Run `npm test -- --runInBand`, `npm run lint`, `npm run build`,
  `npm run test:noise` and `npm run l10n:check-keys`.
- [x] Run Docker PHPUnit unit and integration suites with `--fail-on-skipped`,
  plus `composer cs:check` in the test container.
- [x] Run the complete `npm run test:e2e:gate` after backend DB-mutating suites,
  not concurrently; all three pools with no failed/skipped/flaky required tests.
- [x] Run `npm run backlog:check`, `npm run docs:encoding`, `git diff --check`
  and explicit validation of new/untracked documents without modifying the index.
- [x] Record commands, source fingerprint, environment and results. A timeout,
  required missing/skipped suite or earlier source-state report is not green.

**Exit:** current-source full gates pass. Subsequent fixes rerun affected gates;
no final review is based only on the pre-fix September 24 results.

**R8 record (2026-09-25, final fix-round source):** fresh disposable install
(`down -v` + `up -d`, `TEST_DB_PROFILE=family`) ran the full migration chain
incl. Version1031 and the family seed without error. Gates on that install:
Docker PHPUnit unit `1073 tests / 3190 assertions` and integration
`450 tests / 2207 assertions`, both `--fail-on-skipped`, zero skips;
`composer cs:check` 0 fixable of 351 files; `npm test` 1073 Jest tests in 100
suites; `npm run lint`, `npm run build`, `npm run test:noise`,
`npm run l10n:check-keys` (995 keys, EN/DE/POT exact), `npm run backlog:check`,
`npm run docs:encoding` (772 files) and `git diff --check` all clean;
`npm run test:e2e:gate` PASSED — discovery 47 required specs owned by exactly
one pool, then chromium, chromium-serial-db and chromium-scheduling-owner
sequentially with `--workers=1 --retries=0 --forbid-only`, zero
skipped/failed/flaky required scenarios (local evidence under the gitignored
`build/e2e-gate/` run dir; the gate ran against the final `lib/`/`src/`
sources — only test files changed afterwards, and the PHPUnit suites were
rerun on the final sources). Environment: Docker Desktop on Windows,
`chorestrike-test` PHP 8.2.33 / PHPUnit 9.6.34, MariaDB per compose file.

### R9 - Final Review and Closure

**Owner:** Final review coordinator. **Depends on:** R8 and all acceptance evidence.

- [x] Request a separate complete Standard B or Full review of the entire WP2
  increment, original manifest, history, required tests and durable docs.
  Batch and resolve any remaining decisions; preserve restricted handling.
  **Done 2026-09-25:** final Standard B `PASS-WITH-FIXES` — full-increment
  review (see Review Outcome); the one batched decision (E2E verification
  depth) was answered with the recommended option (accept the mtime-verified
  R8 gate evidence).
- [x] Only after a passing final review, mark verified acceptance criteria and
  resolved findings, update parent/index/tracking and move `active/` to `done/`.
- [x] Archive CR-SVC-64 when its midnight proof is verified. Close only the
  completed FEAT-UX-025 backend slice; leave its UI/digest/rhythm portions,
  CONCEPT-001 and the parent open for their remaining packages.
- [x] Apply the canonical outcome-specific scoped review commit rules. No
  automatic commit for `BLOCKED`, no unrelated staged changes, branch switch,
  push or PR. ~~The present documentation request authorizes no commit.~~
  (Superseded: the final-review request authorized the one scoped review
  commit; unrelated `.gitkeep` deletions stay unstaged per repo convention.)

**Exit:** passing final review and eligible canonical finalization, or explicit
remaining blockers with the plan still active. Green subsets never close WP2.

## 7. Risks & Mitigations

| Risk | Mitigation |
|---|---|
| Moving history looks like approval | History extract labels prior implementation claims; open findings and acceptance stay here, plan remains active. |
| Old rows undo shortening or late cron rewrites misses | R2/R3 use stable decision/effective facts, full-history and deadline-boundary tests. |
| Virtual/default fallback overwrites concrete handovers | R4/R5 distinguish virtual derivation from authoritative materialized rows. |
| Joint fix silently redistributes effort or cannot resume | R6 tests explicit full arrangement, consent and capacity through the real command. |
| Shared evaluator changes regress other consumers | R7 retains the full original matrix, actual-work neutrality and real races. |
| Privacy or private-task disclosure | R1 restricted handling, existing access policy and explicit security authorization. |
| New abstraction/query fan-out | One lower evaluator/writer, reuse existing seams, batch reads and measure budgets. |
| Scope drifts into later packages | Preserve section 2 ownership; no actual-share accounting or new full pause UI. |

## 8. Acceptance Criteria

Standard A already passed and Q-073-01/02/03 remain resolved (section 10).
All implementation-completion criteria were verified by the final Standard B
review (2026-09-25):

- [x] Normal/joint responsibilities and planned shares are explicit, exact and conserved; helpers are not silently obligated.
- [x] Concrete amendments enforce owner, consent, original deadline, visibility and whole-day capacity through one writer; catch-up/restart use only explicit historical exceptions.
- [x] Handover/date/week changes preserve identity and neutral release; catch-up never rewrites a miss.
- [x] Pause request/recognition/illness/shortening and joint resumption satisfy C2 without fake work or automatic redistribution.
- [x] Virtual/persisted timelines agree; interval restart is explicit, fixed anchors unchanged.
- [x] Completed/outstanding effort and counts are deduplicated; real overload stays recordable/visible while new commitments/offers are blocked.
- [x] T2 and WP2's T3 portions pass; actual multi-person accounting stays WP3.
- [x] CR-SVC-64 yields write-free 409 across local midnight; malformed remains 400.
- [x] Commands, competing plans, completion/pause races and injected failures prove atomicity and current-right replay checks.
- [x] Fresh schema/reset, tombstones, erasure and private-task boundaries have required evidence.
- [x] Existing clients retain stale drafts, display shares and neutral/overload work; EN/DE/POT match.
- [x] All section 4/R8 gates pass without missing or newly skipped required tests; new browser scenarios are discovered.
- [x] Durable docs are integrated with no claim that WP4/WP5/WP6 features shipped here.
- [x] Every section 9 finding is resolved or explicitly user-rescoped; final review passes before `done/`.
- [x] Owning backlog items and tracking reflect actual completion; parent and later packages remain open.

## 9. Review Findings (Plan-Owned)

Only open findings live here. SF-1..7 and the RF-01..22 implementation reports
are in the [history extract](../../backlog/archive/CODE_REVIEW_2026-09-25.md#rf-01-to-rf-22-fix-round-evidence);
R7 still verifies their adequacy. No finding was rescoped by this split.

- [x] **RF-073-WP2-23, major:** shortening retains superseded future coverage (`PauseProjectionService::recognizedForHouse/excusedWalletsOn`, `PauseService::shorten`). **Resolved 2026-09-25 (R2):** per-group revision precedence from the persisted decision fact (`Pause::decisionTime`, `effectiveBoundaryKey`, `governingRevisionsOn`) removes superseded future coverage with the full history in place; see the R0 evidence map.
- [x] **RF-073-WP2-24, major:** one paused obligated joint participant does not suspend the occurrence (`PauseProjectionService::deriveSuspension`). **Resolved 2026-09-25 (R6):** any active obligated participant covered suspends the occurrence; explicit feasible 100% agreements resume it through the existing writer with persisted restamped facts; see the R0 evidence map.
- [x] **RF-073-WP2-25, major:** ordinary late acknowledgment can neutralize an expired miss (`PauseService::decide/applyPauseEffectsUnderLock`, `PauseProjectionService::stampSuspension`). **Resolved 2026-09-25 (R3):** the recognition-eligibility gate (`isEffectiveFor`) applies the original exclusive deadline to reads, immediate writes, virtual projections and job materialization alike; the retrospective-illness marker is the sole exception; see the R0 evidence map.
- [x] **RF-073-WP2-26, major:** virtual entries ignore revision participants (`PauseProjectionService::stampSuspensionOnEntries`). **Resolved 2026-09-25 (R4):** materialized occurrences resolve concrete member rows, genuinely virtual twins the saved revision defaults; parity proven before/after materialization incl. the stopped-cron GET/completion/job-replay paths; see the R0 evidence map.
- [x] **RF-073-WP2-27, major:** follow-up capacity/consent use revision defaults after concrete handover (`AgreementService::assertRevisionDayCapacity`). **Resolved 2026-09-25 (R5):** `assertArrangedDayCapacity` validates the concrete active members under lock for date-only amendments and participant-less catch-ups; defaults remain the basis only for virtual derivations and the restart successor; see the R0 evidence map.
- [x] **RF-073-WP2-28, block:** Resolved 2026-09-25 (R1) under the approved
  restricted handling and explicit user authorization: the pause viewer
  projection is now an explicit field whitelist — the personal reason and the
  retrospective-illness marker are self/owner data in every pause state, while
  unrelated members receive only the neutral shared facts; house-scope pauses
  stay household-visible. Remediation is proven at the service boundary
  (`PauseServiceTest::testListForViewer_*`) and on the real wire
  (`RoleGuardApiTest::testPauseIndex_WireResponse_PersonalMetadataIsSelfOwnerOnly`).

The RF-28 entry above is the approved sanitized resolution. No private
evidence or diagnostic paths are linked in tracked docs.

## 10. Activation Decisions

Resolved through the Standard A question batch on 2026-09-23; not reopened by the
documentation split. The original alternatives/review evidence remain in the
[activation record](../../backlog/archive/CODE_REVIEW_2026-09-23.md#code-review-cs-plan-073-standard-a-plan-activation).

**Q-073-01: keep responsibility, release the helper.** A helper-only pause releases
only that concretely agreed commitment, with disposition/provenance. The responsible
obligation and remaining shares do not grow or disappear. An infeasible remaining
arrangement is non-executable until an owner-recorded capacity-fitting agreement.

**Q-073-02: saved-zone comparison.** Preserve all five frozen rules:

1. Candidate day `(D, Z)` is `[D 00:00 Z, D+1 00:00 Z)` in UTC. Test completed
   instants against it; each instant belongs to one candidate day per zone.
2. A saved appointment `(D_w, Z_w)` counts iff its half-open day interval
   intersects the candidate interval. Within one zone this is date-label equality;
   across zones it can constrain both boundary days, once per occurrence per day.
3. Keep each fact's historical work-date/week labels and saved zone. No device-
   or current-house-zone rebucketing of historical labels.
4. Pause inclusion uses the occurrence's saved planned-date label against inclusive
   saved pause date labels, without zone translation. The pause's zone controls
   its own next-day shortening boundary.
5. Frozen vector: after Berlin-to-New-York house-zone change, completion at
   `2026-10-01T22:30:00Z` retains Berlin work date `2026-10-02` (00:30 local).
   New York October 1 is `[2026-10-01T04:00Z, 2026-10-02T04:00Z)` and counts it;
   October 2 is `[2026-10-02T04:00Z, 2026-10-03T04:00Z)` and does not. A Berlin
   October 2 appointment intersects both candidate days, once per day. A Berlin
   pause October 1-7 covers planned label October 7 regardless of that label's
   zone. Shortening on October 3 Berlin evening takes effect no earlier than
   October 4 Berlin; history keeps its original work-date/week labels.

**Q-073-03: stop future personal effects at departure.** Preserve neutral history,
cancel pending requests and stop future effects. Rejoin requires a new pause under
current roles. House pauses still cover current members. Retained wallet identity,
no access while absent and no same-UID rebinding after erasure remain unchanged.

## Review Outcome

Earlier final-review attempt (superseded): **Standard B `BLOCKED`, 2026-09-25**. Full scope,
fingerprints, test results and user decisions are retained in the
[review record](../../backlog/archive/CODE_REVIEW_2026-09-25.md).
The user selected `Separate fix round (Recommended)` and
`Allow placeholder (Recommended)`. No fix, test waiver, commit or lifecycle move
followed. Earlier reviews are linked from the separate history extract.

The September 25 documentation split is not a new review verdict. Before coding,
review the R0-R9 execution breakdown against the frozen contracts and resolve any
implementation decisions. A final passing code review remains mandatory.

**Fix round 2026-09-25 (R0-R8) completed:** all six findings resolved with
maintained regressions (R0 evidence map), the durable docs carry the corrected
contracts (ADR-012/013, API README, architecture/testing engineering docs,
task-model concept, release-test plan), the size exception inventory was
re-measured, and the complete R8 gate set passed on a fresh family-profile
install (record above).

**Final review (R9, 2026-09-25): Standard B `PASS-WITH-FIXES`.** Scope: the
entire 96-path WP2 increment (implementation + RF-01..28 fix rounds + durable
docs) at `master` `75f2da3`, index empty, capture fingerprint unstaged
`1cca50a9…` / 18 untracked. Full-increment checklist review (architecture,
authorization, security, i18n, logging, KISS, workarounds, validation),
fix-round hotspot verification (RF-23..28 regression bodies re-read), R0
evidence-map spot checks, mtime verification that no E2E-relevant file changed
after the passing 2026-09-25 16:33 gate, and fresh gate runs. Two minor
orphan-code findings fixed in-round (dead
`DailyCapacityService::assertValidShares` — zero callers; unused
`PauseProjectionService` calendar dependency) with the size inventory
re-measured (488 → 485, 551 → 517). One batched user decision: E2E
verification via the accepted R8 evidence (recommended option). Gates: Docker
unit 1073/3190 and integration 450/2208 `--fail-on-skipped` zero-skip, Jest
1073/100 suites, lint, cs:check 0-of-351, l10n 995 keys, backlog:check,
docs:encoding 772, `git diff --check` clean. All section 9 findings resolved;
plan moved `active/ → done/` with the scoped review commit (review record:
[CODE_REVIEW_2026-09-25.md](../../backlog/archive/CODE_REVIEW_2026-09-25.md)).
