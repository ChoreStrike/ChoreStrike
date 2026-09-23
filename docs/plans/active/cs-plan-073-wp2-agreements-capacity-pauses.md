# CS-PLAN-073: WP2 - Agreements, Capacity and Pauses

> **Status:** Active; child of CS-PLAN-070; Standard A `PASS-WITH-FIXES` (2026-09-23), Q-073-01/02/03 resolved with the recommended options; implementation not started
> **Effort:** L - 5-7 engineering days, the parent's post-Q-072-01 WP2 allowance; confirmed against the resolved section 10 decisions at Standard A (2026-09-23) - all three options are bounded, the allowance holds
> **Priority:** P1
> **Depends on:** [CS-PLAN-072 / WP1](../done/cs-plan-072-wp1-occurrence-timeline.md), delivered with final Standard B `PASS-WITH-FIXES` on 2026-09-23; [CS-PLAN-071 / WP0](../done/cs-plan-071-wp0-write-identity-foundation.md) delivered
> **Related backlog items:** CONCEPT-001 via [CS-PLAN-070](../active/cs-plan-070-family-rhythm-and-reward-clarification.md); FEAT-UX-025 backend slice; CR-SVC-64
> **Runtime baseline:** `master` at `296888f`, inspected 2026-09-23

## 1. Problem Summary

WP1 supplies stable occurrences, immutable rule revisions, saved time bases,
write-free projections, completion receipts and occurrence-scoped checklists.
Q-072-01 Option A also delivered `agreements` and the common date/participant
writers, now in `OccurrenceAgreementService` behind `OccurrenceService`.
WP2 must extend these seams, not introduce another recurrence/agreement authority.

| Verified baseline at `296888f` | Remaining WP2 change |
|---|---|
| `OccurrenceAgreementService.php:86-147,161-216,331-352` | Date/participant writers and a child-load counter exist; add explicit target/consent, window, pause, catch-up/restart and common whole-day evaluation. Participant writes currently revise all open instances; a single-occurrence handover must not accidentally change future defaults. |
| `SchedulePlanService.php:628-706,1068`; `CompletedTaskMapper.php:166-184` | Planner counts full effort per assignee; completed-effort query joins current `tasks.energy_cost`. Replace both inputs with occurrence-based planned shares and frozen occurrence effort. |
| `Agreement.php:45-61,85-88`; `OccurrenceMember.php:53-76` | Kind constants, `pauseRef`, consent and dispositions/shares exist; no pause lifecycle or suspension provenance is implemented. |
| `OccurrenceSelectionService.php:319-334,478-489` | DTO work date equals planned date; add real work appointments, pause/neutral state and effective agreement inputs to tokens. |
| `src/store/scheduling.js:376-417` | Apply has `previewVersion` and 409 draft retention, but no command UUID. Reuse the established completion retry-identity pattern. |

Remaining work is the C2 agreement lifecycle: explicit responsibility and shares,
whole-day capacity, dated pauses, traceable exceptions, catch-up appointments and
completion-relative restart. The existing planner, execution reads and assignment
callers must consume the same facts; a new pause table alone is not delivery.

Canonical requirements are the parent's C2 decision log, sections 3.2-3.5 and
T2/T3, plus [task agreements](../../concepts/task-model-and-sync.md#approved-agreement-contract-c2-2026-09-17),
[capacity](../../concepts/energy-and-balancing.md#approved-whole-day-capacity-c2-2026-09-17)
and [ADR-013](../../adr/ADR-013-occurrence-agreements-and-corrections.md).
This drafting request authorizes documentation only, not implementation,
activation, a formal review verdict or a commit.

## 2. Strategy

Use one command-level agreement coordinator, the existing occurrence writers,
one reusable daily-capacity evaluator and a revisioned pause lifecycle. Preserve
house-first transactions and current task visibility. Treat neutral history,
planned participation and recorded actual work as different facts.

| Owner | Boundary / deliverable |
|---|---|
| WP2, this plan | Normal/joint planned roles and exact shares; owner-recorded handover/date/window/share amendments; catch-up/restart; adult/child/house pause lifecycle; whole-day capacity and stale preview; working existing clients and minimum neutral/overload labels. |
| WP3 | Actual multi-person participation, `work_shares`, effort-only Coins, attribution correction and isolated financial/room undo. WP2 must not invent actual coworkers from assignments or partially switch the economy. |
| WP4 | Chronological personal rhythm, eligible/on-time weekly goal, retained recognition and freeze retirement. WP2 provides neutral/released/planned-week evidence, not a replacement streak algorithm. |
| WP5 | Quiet digest and personal preferences. WP2 provides pause/relevance facts; it does not claim current notifications implement the new policy. |
| WP6 | Complete pause/request management UI, participation dialogs, calm My Day redesign and full recovery workflows. Existing WP2-touched actions must still work now. |
| WP9 | Combined cross-DB, repeat-browser and voluntary family evidence. No family contact or trial is authorized by this draft. |

T3 is incremental as already specified by the parent: planning/capacity exits
with WP2; multi-person actual allocation/accounting exits with WP3. Packages are
development/review boundaries, not independently releasable family products.

## 3. Backend / Frontend Changes

### 3.1 Persistence and Service Ownership

Extend `Version1031Date20260918000000.php` in place for fresh installs. Reuse
`Agreement`, `AgreementMapper`, `OccurrenceMember` and the WP1 collaborators;
do not recreate their tables or add upgrade/backfill code.

| Surface | Required change / invariant |
|---|---|
| `schedule_revs` / participant defaults | Freeze explicit normal/joint mode and participant role/share/work-date defaults. Definition edits remain prospective revisions; they cannot rewrite concrete agreements. |
| `occ_members` | Distinguish responsible/joint/helper, obligated flag, positive integer `share_pct`, work date/zone and active/released/excused disposition. A normal executable plan has one responsible person; a joint plan identifies its obligated participants explicitly. Helpers acquire no obligation merely by helping. |
| `occurrences` | Persist neutral suspension/provenance and a link from an interval restart to its neutral predecessor. Planned date/week/window remain separate from an agreed catch-up work appointment. A suspended plan retains its former 100% snapshot but consumes no live planned capacity. |
| `agreements` | Extend existing append-only old/new evidence with kind, affected subjects, recorded consent and pause reference/revision. Persist handover release rather than merely deleting the former participant; current rows are a projection, not the only historical evidence. |
| New `pauses` | House, stable group identity, nullable subject wallet for house scope, requester/decider, inclusive date range, saved IANA zone, bounded reason code, state, revision, event time, retrospective-illness marker and next-day shortening boundary. Unique `(pause_group_id, revision)`; indexed house/subject/date lookup. No medical free text. |

An executable participant revision totals exactly 100%; equal shares use stable
WP0 wallet order for remainders (34/33/33, never role/name/request order). Maximum
100 positive whole-percent participants. Energy uses integer hundredths `E * P`;
reject duplicate subjects, invalid totals and unsafe integer arithmetic before
writes. Do not make remaining unpaused shares sum to 100 by silent redistribution.

`AgreementService` coordinates authorized commands and planner batches;
`OccurrenceAgreementService` remains the single under-lock occurrence writer.
`DailyCapacityService` supplies planning, agreement validation and offer filtering.
`PauseService` owns pause commands; a lower `PauseProjectionService` resolves
effective revisions/excuses for timeline, selection and capacity consumers.
The lower evaluator may depend on mappers and pure date helpers, not command
services: no `OccurrenceService -> PauseService -> OccurrenceService` DI cycle.
Prefer extending existing methods over duplicating recurrence/date arithmetic.

### 3.2 Whole-Day Capacity and the WP3 Seam

For each person/work day, derive capacity from actual valid work plus outstanding
active commitments, including tasks outside the planner selection. Use occurrence
identity to deduplicate; never count a fulfilled obligation as both planned and
completed. Remove the old allocation before testing a replacement/batch plan.

- Planned effort is each person's agreed share, including a concretely agreed
  helper commitment; merely offering help does not create a duty. Each distinct
  occurrence counts once toward that person's task limit.
- WP2 consumes the recorded completer, work date/zone and occurrence key,
  excluding undone rows. Change `CompletedTaskMapper` to join the saved occurrence
  by revision/ordinal for its frozen energy; the current query joins mutable
  `tasks.energy_cost`, and `CompletedTask` has no dedicated frozen-energy column.
  Do not split that recorded work across planned assignees. WP3 replaces this
  input with its frozen completion/actual-share revision and tests attribution
  corrections in the same cutover; no compatibility fallback is added.
- Early work consumes its actual work day, not the future planned day. Agreed
  catch-up consumes its work appointment, without changing the historical miss.
  Unscheduled voluntary work counts when actually recorded, not as an invented
  outstanding commitment.
- Check every affected participant's availability, pause and energy budget, plus
  owner-set `maxDailyTasks` for children. No per-item owner override, no successful
  placement based on only one member of a shared plan.
- Existing overload remains visible with completed/outstanding/remaining amounts
  and a clarification flag. Reject additional commitments/offers; allow an
  atomic capacity-fitting replan. Do not truncate the agreed task list to hide
  overload, and never gate actual completion on remaining capacity.
- Preserve saved work-date/time-basis attribution across house-zone changes.
  Include those bases in capacity input and preview fingerprints; no device-zone
  calculation or re-bucketing of historical labels. Standard A must pin the
  cross-zone comparison described in section 10 before implementation.

Return integer `completedEnergyMinor`, `plannedEnergyMinor`, `remainingEnergyMinor`
and distinct task counts, together with limits/overload and eligibility reasons.
Only expose details the viewer may read. Internal capacity must include invisible
commitments without returning their task IDs, names or audit snapshots; review
whether aggregate disclosure follows the existing authorized capacity surface.

### 3.3 Agreements, Catch-up and Restart

1. Resolve the public occurrence key, current visibility and version under the
   house lock. An ordinary amendment requires an unfulfilled occurrence and
   `now < oldWindowEnd`. At exactly the exclusive end it conflicts. Check the
   old deadline before considering the proposed new one.
2. The owner records agreement with all affected responsible/joint people; this
   is an explicit attestation, not a new confirmation queue. Store the consenting
   subject set and before/after evidence. Private-task access remains unchanged.
3. Handover releases the former responsibility neutrally and installs the new
   capacity-checked responsibility on the same occurrence. Share/date/window
   amendments follow the same writer. Week-crossing changes move that occurrence's
   planned-week attribution once, never copy it or count release as completion.
4. `/catch-up` accepts only the current unfulfilled uncovered miss. Change its
   work appointment and participants within capacity, retaining the original
   planned date/window and miss. A current open occurrence still wins completion
   selection under C1; stale catch-up appointments cannot defeat that precedence.
5. `/restart` accepts a neutral suspended interval predecessor, even after its old
   deadline. Create one linked agreed restart through the existing revision/key
   mechanism; preserve the predecessor's neutral history. The locked predecessor
   and a portable uniqueness invariant prevent duplicate live restarts. Only
   the restart's real completion creates the next completion-relative interval.
6. Ordinary fixed schedules retain their anchor through pauses and restart of
   other work. No synthetic completion, reward, success or interval advance is
   generated by acknowledgment, shortening, handover or a timeline pass.

Cut existing due-date, single/bulk assignment and planner callers over together.
Future default participants are distinct from a concrete occurrence amendment;
generic task edits must not become an owner/cutoff/capacity bypass. Remove obsolete
route contracts only with all consumers/tests updated, without compatibility
aliases. Retain the WP1 public occurrence key and facade/collaborator split.

### 3.4 Pause Lifecycle and Neutral History

| Action | Actor and transition | Projection effect |
|---|---|---|
| Request personal pause | Child for self: create `requested` | Pending only; no changed duty, deadline, capacity or goal eligibility. |
| Acknowledge own pause | Adult/owner for self: create `acknowledged` | Apply recognized dates to eligible unfulfilled obligations. |
| Household pause | Current owner: create `acknowledged`, house scope | Same neutral rules; no new access to private tasks. |
| Approve / reject child request | Current owner, expected revision | Acknowledge or reject explicitly; no automatic deadline extension or rejection. Ordinary approval cannot excuse an expired miss. |
| Recognize retrospective illness | Current owner, explicit illness action and audit marker | May neutralize qualifying unfulfilled historical obligations; not an ordinary backdated amendment or fabricated completion. |
| Shorten / revoke acknowledged pause | Adult self; child changes require owner acknowledgment; house scope owner | Append a revision effective no earlier than the next local day; preserve today's and earlier recognized excuses. |

Evaluate inclusion by the occurrence's saved planned date, not the end of its
completion window: Saturday in a Friday-Monday window is affected by a Saturday
pause, not merely a Monday one. Correct completed work is retained. Pending or
rejected requests have no execution effect. Expired requests stay pending until
an authorized decision; retrospective recognition is explicit, not implied by
ordinary approval.

For sole responsibility and explicit joint work, suspend unresolved occurrences
neutrally without handing duties or effort to others. Joint resumption requires
an owner-recorded, feasible 100% agreement before the old deadline. A helper-only
pause is an unresolved boundary in section 10, not permission to cancel someone
else's obligation silently.

Overlapping recognized personal/house pauses compose as a union: one neutral
occurrence, all applicable provenance retained. Revoking one pause does not
remove another excuse. Future reactivation must fit capacity; already neutral
today/past stays neutral, and interval work requires explicit restart.
Voluntary actual work during a pause remains recordable under existing completion
rights without restoring an obligation or rewriting neutral history.

Apply these rules to materialized and virtual agreed occurrences, including a
stopped cron followed by read/complete/reconcile. Record each agreement effect
idempotently against its pause revision. GET remains write-free; mutations and
`TimelineJob` persist required effects without receipts for fictional actors.
Keep necessary suspended work visible only to authorized viewers for clarification,
not as an automatic overdue task or unsolicited child offer.

### 3.5 APIs, Transactions and Clients

All routes below are relative to `/apps/chorestrike/api/v1`. Controllers use
`@NoAdminRequired`, normal CSRF protection, strict fields/enums/date validation
and `ControllerErrorFormatter`; services recheck active membership, role and
`TaskAccessPolicy`. Errors: malformed 400, visible role denial 403, invisible or
foreign target 404, stale version/deadline/business conflict 409.

| Route | Contract |
|---|---|
| `POST /occurrences/{id}/amend`, `/catch-up`, `/restart` | Kind-specific normalized payload, `commandId`, `expectedVersion`, `selectionToken`, explicit recorded agreement and participant roles/shares. Return original/result occurrence identity, agreement revision and freshly authorized affected projections. |
| `GET/POST /pauses`; `POST /pauses/{id}/approve`, `/reject`, `/recognize-illness`; `PUT /pauses/{id}` | Stable pause group ID, inclusive dates/saved zone/reason code; command UUID and expected revision for updates. Return pending/recognized state and viewer-safe impacts. List is bounded; raw personal requests/reasons are self/owner data, not household activity. |
| Existing `/scheduling/suggestions`, `/scheduling/apply` | Preview remains write-free and bounded to 100 selected rows/seven days. Carry explicit occurrence targets/shares/consent; apply identical normalized input plus `previewVersion` and command UUID atomically. |
| Existing daily/detail/range/overdue reads | Expose agreement classification, roles/shares/work appointment, pause/clarification state and exact capacity. Do not return an unbounded missed-occurrence stack or silently hide already agreed overload. |

Fingerprint authoritative occurrence/rule/member/pause versions, saved zones,
availability/limits, completed-work inputs, proposal and evaluation local day.
Revalidate before relative-to-today rejection: a valid preview crossing midnight
must yield write-free 409 (CR-SVC-64); a newly malformed request remains 400.
Check proposed legal dates and every participant in preview and apply alike.
Pause/consent/participant changes must invalidate selection tokens for virtual as
well as persisted occurrences. A row version bump alone cannot cover a read-only
virtual target; fingerprint its effective pause/agreement inputs too.

Use `CommandService` for new commands and planner apply. Authorize before receipt
replay; same UUID/payload returns original identities plus current safe state,
changed payload conflicts. Lost responses reuse the UUID; a new UUID is not a
license to repeat a transition. Domain facts, versions, evidence and receipt
commit/roll back together. Keep the WP0 lock order and sorted ID subsequences;
no notification/network work inside transactions.

Minimum client changes belong here: exact-share preview labels, new command
payloads, UUID retention, consent attestation for existing owner amendment/apply
controls, neutral/overload clarification and preservation of drafts after 409.
Do not make an existing button send an invalid or newly forbidden request and
defer its repair to WP6. Full new pause screens and participation entry stay WP6.
All new messages use `t()` and synchronized EN/DE/POT artifacts; test keyboard,
desktop and mobile for touched controls.

### 3.6 Retention and Durable Documentation

Extend account erasure to pause subjects/requesters/deciders, consent/participant
snapshots and command references, preserving only non-reidentifiable shared
neutral/work facts. Ordinary membership removal never grants access through a
retained wallet or automatically redistributes work. Test same-UID recreation.
The future effect of personal pauses after departure/rejoin is unresolved in
Q-073-03; do not conflate the approved wallet retention with renewed pause consent.
Tombstones remain excluded from active planning; production house reset is not
an excuse/history purge. Add every new table to test reset/seed cleanup order.

Update ADR-012/013, API, architecture, testing and task/balancing/UI concepts in
the implementing package. Distinguish delivered agreement/capacity facts from
pending WP3 accounting, WP4 goals and WP5/6 presentation. No new ADR number is
needed; ADR-014/015 remain reserved for later parent packages.

## 4. Tests

Required future evidence, not tests run by this documentation session. Parent
scenario numbers refer to the ordered C2 acceptance table. `M` means extend an
existing suite, `N` a new suite; brace lists enumerate concrete paths.

| Group | Required assertions | Concrete files |
|---|---|---|
| T2-A / C2.2-3 | Handover neutral release, same key, old/new consent evidence, week move; fulfilled/expired/exact-deadline rejection; private/foreign/role denials and replay authorization. | N `tests/unit/Service/AgreementServiceTest.php`; M `tests/integration/Service/OccurrenceTimelineTest.php`, `tests/integration/RoleGuardApiTest.php` |
| T2-B / C2.4-8 | Saturday vs Monday pause; pending child request across deadline; explicit retroactive illness; completed work retained; sole/joint neutral suspension; suspended 50/50 stays 50/50 with zero outstanding capacity; feasible 100% resumption. Ten occurrences minus two suspensions yields eight eligible facts, not two completions. Actual weekly-goal projection stays T5/WP4. | N `tests/unit/Service/{PauseService,PauseProjectionService}Test.php`, `tests/integration/Service/PauseAgreementTest.php` |
| T2-C / C2.13-14 | Next-day shortening, today's excuse preserved, overlapping personal/house pauses, paused voluntary completion, interval restart and one linked successor, fixed anchor unchanged; stopped cron/virtual projection/job replay. | N `tests/integration/Service/PauseAgreementTest.php`; M `tests/integration/Service/OccurrenceTimelineTest.php`, `tests/unit/BackgroundJob/TimelineJobTest.php` |
| T3-A / C2.8-10, planning C3.2-3 | Exact planned shares (energy 1-5, group sizes 1-100, reordered members, custom partitions); invalid totals/duplicates/overflow; actual plus outstanding dedupe; catch-up/early/helper commitments, both child limits and every joint participant; hidden commitments counted without disclosure. | N `tests/unit/Service/DailyCapacityServiceTest.php`; M `tests/unit/Service/SchedulePlanServiceTest.php`, `tests/integration/Service/SchedulePlanAggregateTest.php` |
| T3-B / C2.11 | Over-limit real completion accepted, no extra offer, no hidden existing obligation; frozen actual energy survives definition edit; planned coworkers not mistaken for actual workers; undo removes actual consumption and preserves neutral evidence. Multi-person work credit/correction is T3/WP3, not a skipped WP2 test. | M `tests/unit/Service/CompletionServiceTest.php`, `tests/integration/Service/OccurrenceTimelineTest.php`; N `tests/unit/Service/DailyCapacityServiceTest.php` |
| Preview / CR-SVC-64 | Outside-legal-date override rejected; every joint participant fits; selected old load replaced once; any relevant pause/completion/role/zone/limit drift invalidates; preview before midnight -> apply after midnight returns 409 with zero writes; malformed input remains 400. | M `tests/unit/Service/SchedulePlanServiceTest.php`, `tests/integration/Service/SchedulePlanAggregateTest.php` |
| Real races / rollback | Two plans compete for one child slot; plan vs pause/complete/role loss; complete vs acknowledgment/shortening; duplicate restart, concurrent revisions/replayed receipts; injected failure after pause/evidence insertion rolls back all effects and receipt. | M `tests/integration/Service/SchedulePlanConcurrencyTest.php`; N `tests/integration/Service/PauseAgreementConcurrencyTest.php` |
| T0 extension / C2.12 | Fresh Version1031 schema/indexes/reset, tombstone filters, pause/audit/receipt erasure, same-UID non-rebinding, membership/private-task matrix including owner without private access; GET creates no facts. | M `tests/integration/Db/FamilyRhythmSchemaTest.php`, `tests/integration/Service/AccountErasureTest.php`, `tests/integration/RoleGuardApiTest.php`, `tests/unit/RouteMatrixTest.php`; N `tests/integration/Service/PauseAgreementTest.php` |
| Controller / read seams | Strict payloads, expected revision, actor/state error mapping, effective pause/selection tokens, write-free virtual reads and departure behavior selected in Q-073-03. | N `tests/unit/Controller/{OccurrenceController,PauseController}Test.php`; M `tests/unit/Controller/SchedulingControllerTest.php`, `tests/unit/Service/OccurrenceServiceTest.php`, `tests/integration/Service/HouseMemberRemovalIntegrationTest.php`, `tests/integration/Db/WorkflowQueryBudgetTest.php` |
| Client contract | Exact-share/capacity DTOs, consent payload, one UUID across uncertain retries, retained 409 draft, explicit reload, paused/overloaded agreed rows not lost; existing editor, single/bulk assignment and claim consumers stay consistent. | M `tests/unit/store/{scheduling,roomTasks,apiContract}.spec.js`, `tests/unit/components/{SchedulePlanner,SchedulingView,OvercapacityWarning,RoomTaskEditModal,TaskList,TaskListDetails,AssigneeChips,OverdueClaimDialog,TodayView}.spec.js` |
| Browser | Existing owner planner apply with shares/consent; child sees all agreements and overload; API-created pause then rendered neutral/clarification state; denied child approval/private owner amendment; stale plan/target retains draft. No claim of a not-yet-built pause-management UI. | M `tests/e2e/{scheduling-apply-flow,family-rhythm,workflow-state-reconciliation,family-core-ux}.spec.ts`; update `tests/e2e/{scheduling,flex-window-scheduling}.spec.ts` where their caller contract changes; exact titles/pools pinned with the implementing scenarios |

Use injected clocks (UTC, Europe/Berlin DST, house-zone change), fresh fixtures
and real two-connection barriers. Sequential calls only prove idempotency, not
concurrency. Check final facts/evidence/receipts from fresh connections and prove
no false completions/credit from pauses. Current TECH-TEST-003 remains a separate
harness issue, not permission to suppress failures or add skips.

Implementation gates: `npm test -- --runInBand`, `npm run lint`, `npm run build`,
`npm run test:noise`, `npm run l10n:check-keys`, Docker PHPUnit unit/integration
with `--fail-on-skipped`, `composer cs:check`, `npm run test:e2e:gate`,
`npm run backlog:check`, `npm run docs:encoding`, `git diff --check`.
Use the [canonical Docker workflow](../../standards/ai/skills/docker-dev/SKILL.md).
Update `scripts/run-e2e-gate.js`, `playwright.config.ts` and
`tests/e2e/required-scenarios.json` together. No new/unapproved skips, green subset
substitutions or obsolete-suite deletion to simulate completion.

## 5. File Manifest

Proposed implementation manifest; section 4 test files are part of it. Existing
shared files change only for the WP2 seam. Confirm concrete additions in Standard A
after resolving section 10; no runtime file is created in this drafting round.

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

This documentation round changes this new plan, the parent, `docs/plans/README.md`,
`docs/README.md`, `docs/backlog/{backlog,ACTIVE_TASKS}.md` and the monthly archive
only for rolling coordination history. Pre-existing `.gitkeep` deletions remain
untouched; no staging, commit or lifecycle move is part of plan creation.

## 6. Implementation Order

1. Run Standard A separately: resolve section 10 as one decision batch, validate
   estimates/schema/API/test ownership, then activate only on a passing review.
2. Extend fresh schema/entities and pause/consent erasure/reset with T0 tests;
   freeze normal/joint participant and capacity DTOs before parallel UI work.
3. Implement exact planned allocation and daily capacity; wire all existing
   writers/preview/offer paths with T3-A/B and overload visibility.
4. Add command-level agreements, handover, catch-up and atomic planner batches;
   convert actual callers and fix midnight stale-preview classification.
5. Add the revisioned pause lifecycle, shared projections, neutral timeline
   reconciliation and linked interval restart with T2 and race/rollback tests.
6. Finish the minimal client integration, focused browser scenarios, localization
   and durable documentation; run all required gates without skips.
7. Final Standard B or Full review; resolve plan-owned findings before moving
   this child to `done/`. Close/archive CR-SVC-64 only after its proof passes.
   Keep CONCEPT-001, FEAT-UX-025's UI/digest/rhythm portions and the parent open.

The 5-7-day allowance covers backend, minimum client adaptation, tests, docs and
one correction pass; it does not absorb WP3 accounting or WP6's full UI. Re-estimate
if section 10 changes scope. No visible Agent Manager sessions are requested.

## 7. Risks & Mitigations

| Risk | Likelihood | Mitigation |
|---|---|---|
| Duplicate agreement authority after WP1 split | High | Reuse facade/lower writer; audit every legacy date/assignment/default writer and test bypass attempts. |
| Planning shares mistaken for actual work | High | Keep explicit WP3 seam, frozen completer facts now, no inferred absent-worker credit or partial wallet cutover. |
| Pause is only applied to materialized rows | High | Shared write-free projection plus idempotent mutation/job reconciliation; stopped-cron and future-frontier tests. |
| Neutral suspension leaks private task or medical data | High | Existing TaskAccessPolicy, self/owner request reads, reason codes only, viewer-filtered impacts and erasure tests. |
| Resume/shortening silently creates overload or old misses | High | Preserve historical excuses; validate future capacity and explicit interval restart under house lock. |
| Apply differs from preview or crosses midnight | High | One evaluator, authoritative fingerprint and 409-before-relative-date validation; real competing transactions. |
| Growing collaborators / query fan-out | Medium | Acyclic lower evaluators, batched house/date reads, existing query-budget regression and measured size exceptions. |
| Cross-zone days or helper-only pause invent product policy | Medium | Explicit section 10 decisions; no implementation by assumption. |

## 8. Acceptance Criteria

- [x] Standard A passes (`PASS-WITH-FIXES` 2026-09-23); section 10 decisions recorded with the frozen Q-073-02 cross-zone mapping; no scope change, 5-7-day estimate confirmed.
- [ ] Normal/joint responsibilities and planned shares are explicit, exact and conserved; helpers are not silently obligated.
- [ ] All ordinary concrete amendments enforce owner, consent, original deadline, visibility and whole-day capacity through one writer; catch-up/restart use only their explicit historical-state exceptions.
- [ ] Handover/date/week changes preserve occurrence identity and traceable neutral release; catch-up never rewrites a miss.
- [ ] Pause request/acknowledgment/illness/shortening and joint resumption satisfy C2 without fake work or automatic redistribution.
- [ ] Virtual/persisted timelines agree; interval restart requires explicit agreement and fixed anchors remain unchanged.
- [ ] Completed plus outstanding effort/counts are deduplicated; real overload remains recordable/visible while new commitments/offers are blocked.
- [ ] T2 and WP2's T3 portions pass; actual multi-person accounting remains explicitly owned by WP3, not falsely claimed complete.
- [ ] CR-SVC-64 returns write-free 409 across local midnight; malformed requests retain 400.
- [ ] Commands, competing plans, completion/pause races and injected failures prove one atomic outcome and current-right replay checks.
- [ ] Fresh schema, reset/seed, tombstones, erasure and private-task boundaries are covered by required tests.
- [ ] Existing clients work with the new payloads, retain stale drafts, show exact shares and do not hide overload/neutral work; EN/DE/POT match.
- [ ] All section 4 gates pass without missing/newly skipped required tests; browser discovery includes the new required scenarios.
- [ ] Durable API/architecture/concept/test docs integrated; no claim that WP4 goals, WP5 digest or WP6 pause UI shipped here.
- [ ] Section 9 findings resolved or explicitly user-rescoped; final Standard B/Full passes before `done/`.
- [ ] Tracking updated; CR-SVC-64 archived only when fixed; CONCEPT-001, remaining FEAT-UX-025 and parent packages stay open.

## 9. Review Findings (Plan-Owned)

Standard A ran 2026-09-23 (`PASS-WITH-FIXES`): one nit (the §4 T2-B scenario
label, fixed in-round to `C2.4-8`); no open plan-owned findings. WP2 owns the
agreement/capacity/pause portions of parent RF-070-03/04/10/11, plus retention
coverage as its new data is introduced. Do not duplicate those findings into new
backlog entries. New in-scope findings from implementation rounds belong here;
out-of-scope items follow the canonical routing rules.

## 10. Activation Decisions

Approved parent C2/C3/C5 rules are not reopened. These remaining boundaries need
one explicit Standard A decision batch before implementation, with recommendations
distinguished from user approval:

| ID | Boundary / recommendation | Alternative / consequence |
|---|---|---|
| Q-073-01 | **Helper-only pause:** keep the responsible person's obligation, release only the optional helper's commitment, and require a capacity-fitting agreement for any changed remaining shares. Until clarified, show a non-executable plan rather than silently increasing someone's share. | Neutrally suspend the whole occurrence as for joint work; simpler but makes an optional helper's absence cancel the responsible person's obligation. Needs explicit product approval. |
| Q-073-02 | **Capacity across saved zones:** use the candidate work day's saved-zone half-open UTC interval to test completed instants and existing work appointments, while retaining each fact's historical date/week labels. Freeze the exact outstanding-appointment mapping and pause-date comparison with a cross-zone example in Standard A. | Compare saved date labels regardless of zone; simpler but can split the same real work day after a timezone change. No silent device/current-zone re-bucketing. |
| Q-073-03 | **Personal pauses after departure/rejoin:** preserve already neutral history, cancel pending personal requests and stop future personal-pause effects at departure; rejoin requires a new pause under current roles. House-wide pauses remain applicable to all current members. | Retain the acknowledged personal pause's remaining date range for the same persistent identity on rejoin; simpler continuity, but revives a prior membership's exception. Neither option changes wallet retention, grants access while absent or permits same-UID rebinding after erasure. |

The WP2/WP3 T3 split, minimum client scope, privacy-filtered impact DTOs and the
one-writer/service dependency graph also need engineering validation in Standard A;
they are proposed implementation details, not new product approvals. If review
finds another user-visible ambiguity, batch it with these questions rather than
guessing or activating with unanswered questions.

### Resolutions (Standard A, 2026-09-23)

Answered through one batched structured question; all three selected the
recommended option. The engineering-validation items above passed in the same
review (see Review Outcome). Implementation follows these resolutions.

- **Q-073-01 — RESOLVED: keep the obligation, release the helper.** A
  helper-only pause releases just that helper's concretely agreed commitment
  (disposition change with pause provenance); the responsible person's
  obligation and every remaining share stay unchanged — no silent share
  increase, no whole-occurrence suspension. Where a release leaves the
  remaining arrangement infeasible, the plan shows non-executable until an
  owner-recorded, capacity-fitting agreement replaces it.
- **Q-073-02 — RESOLVED: saved-zone half-open UTC interval.** Frozen
  comparison rules (required before implementation):
  1. *Capacity day interval:* a candidate work day `D` with saved zone `Z` is
     the half-open UTC interval `[D T00:00 Z, D+1 T00:00 Z)`. Completed instants
     are tested against it as instants; each instant falls in exactly one
     candidate day per zone.
  2. *Outstanding work appointments:* an appointment with saved work date `D_w`
     and zone `Z_w` counts against candidate `(D, Z)` iff
     `[D_w T00:00 Z_w, D_w+1 T00:00 Z_w)` intersects `[D T00:00 Z, D+1 T00:00 Z)`.
     Within one zone this degenerates to exact date-label equality; across zones
     it conservatively constrains both boundary days. Each distinct occurrence
     counts at most once per candidate-day evaluation (occurrence-identity
     dedupe, section 3.2).
  3. *Labels:* every fact retains its historical date/week labels computed in
     its own saved zone; no device-zone or current-house-zone calculation, no
     re-bucketing of historical labels.
  4. *Pause inclusion:* a recognized pause covers an unfulfilled obligation iff
     the occurrence's saved planned-date label lies within the pause's inclusive
     saved start/end date labels (civil-date comparison, no zone translation);
     the pause's saved IANA zone governs its own next-day shortening/revocation
     boundary.
  5. *Frozen cross-zone test vector:* house zone changes Europe/Berlin →
     America/New_York. A completion recorded at `2026-10-01T22:30:00Z` keeps its
     saved work-date basis `2026-10-02` (Berlin, local 00:30). Candidate day
     `2026-10-01` on a New York basis = `[2026-10-01T04:00Z, 2026-10-02T04:00Z)`
     contains the instant → consumes October 1 capacity; candidate `2026-10-02`
     (New York) = `[2026-10-02T04:00Z, 2026-10-03T04:00Z)` does not. History
     still shows work date `2026-10-02` and its Berlin week label. A saved
     Berlin appointment for `2026-10-02` intersects both the New York October 1
     and October 2 intervals → it constrains both boundary days, counted once
     within each day's evaluation. A pause acknowledged with saved zone Berlin
     and dates `[2026-10-01, 2026-10-07]` covers an occurrence whose saved
     planned-date label is `2026-10-07`, whichever zone agreed that label; a
     shortening recorded on `2026-10-03` Berlin evening takes effect no earlier
     than `2026-10-04` Berlin.
- **Q-073-03 — RESOLVED: stop future effects at departure.** Already neutral
  history is preserved; pending personal pause requests are cancelled at
  departure; future personal-pause effects stop. Rejoin requires a new pause
  under current roles. House-wide pauses remain applicable to all current
  members. Wallet retention, no access while absent and no same-UID rebinding
  after erasure are unchanged (Q-070-01/02, delivered WP0).

## Draft Validation

**2026-09-23, documentation only:** `npm run backlog:check`,
`npm run docs:encoding`, `git diff --check` and the new plan's standalone
whitespace check passed. The unchanged document checkers also passed with Git's
tracked-plus-untracked inventory supplied in-process, covering this unstaged
draft without changing the index or checker files. Backend/client inventory and
the WP1 handoff informed the manifest; this is not a Standard A verdict.
Runtime suites and family evidence were not run for documentation-only changes.
Q-073-01/02/03 remain unanswered and block activation, not completion of this
drafting task. No runtime implementation, activation or commit has occurred.

## Review Outcome

**Tier/Outcome: Standard A (plan) — `PASS-WITH-FIXES` (2026-09-23).**

- **Scope:** this plan file (419 lines at capture, untracked), SHA256
  `a6284d0ac1261f46d28862a22461876c71febe196897b515c505aa5ce313fca4`; base
  `master` @ `296888fe53fa487a52d56ef1d3a8491e9e923c8e`; empty index; unstaged
  binary-diff fingerprint of the 9-file documentation-round working tree
  `58429563e1d67f16e36cd6ea335e892079d9590bb239aa577dea1fee02c648c7`
  (preserved; the three pre-existing `.gitkeep` deletions stay excluded per
  section 5 and the CS-PLAN-070/072 precedent). The file is a Nextcloud
  Files On-Demand placeholder (reparse tag `0x9000601A`) like every file in
  this sync folder — a regular hydrated file, not a symlink; the single
  candidate in `new/`; the `active/` destination was free.
- **Verified:** template sections 1-9 plus section 10 present; no overlap with
  an active plan (only the parent program plan; child boundaries explicit in
  §2); all seven §1 baseline code citations exact at `296888f`
  (`OccurrenceAgreementService.php:86-147/161-216/331-352` date/participant
  writers + day-load counter, participant writes revising all open instances;
  `SchedulePlanService.php:628-706/1068` full effort per assignee +
  fingerprint; `CompletedTaskMapper.php:166-184` mutable `tasks.energy_cost`
  join with no frozen-energy column; `Agreement.php:45-61/85-88` kinds +
  `pauseRef`/consent; `OccurrenceMember.php:53-76` dispositions/roles/shares;
  `OccurrenceSelectionService.php:319-334/478-489` `workDate`=`plannedDate`
  and token material without pause/agreement inputs; `scheduling.js:376-417`
  apply with `previewVersion` + 409 draft retention and no `commandId`); every
  `modified:`/`M` manifest and test path exists and every `new:`/`N` path is
  absent; `Version1031Date20260918000000` is the chain head and its docblock
  pre-authorizes the in-place `pauses` extension; parent fidelity confirmed
  (C2a-k → §3.3/§3.4 rules, C3c precision → §3.1, §3.2 persistence contract,
  §3.4 API routes/error classes, §3.5 lock order/replay semantics, T2/T3 test
  ownership incl. the suspended-50/50 pin and property cases, gate list,
  ADR-013 delivered and ADR-014/015 reserved); referenced backlog items open
  (CONCEPT-001 In Progress, FEAT-UX-025, CR-SVC-64, TECH-TEST-003); both
  concept anchors exist; the `CommandService` receipt pattern and the client
  UUID-retention pattern (`roomTasks.js:445-453`) are established for reuse;
  preview bounds match the runtime (`MAX_BATCH_SIZE=100`, `WINDOW_DAYS=7`);
  `CompletedTask` already carries `workDate`/`workZone`/`scheduleRevId`/
  `occurrenceOrdinal` for the §3.2 capacity input.
- **Engineering validation (§10 closing paragraph):** the WP2/WP3 T3 split is
  sound (planning/capacity exits now on the occurrence-join frozen effort; WP3
  cuts the same input over to its work-share revision in one cutover, no
  compat fallback); minimum client scope is sound (existing control seams
  only; `PauseSettings`/`ParticipationDialog` remain WP6 per the parent
  manifest); privacy-filtered impact DTOs match the parent's viewer-filtered
  aggregate rule; the four-service graph is acyclic (`PauseProjectionService`
  as lower evaluator prevents an `OccurrenceService → PauseService →
  OccurrenceService` DI cycle; `OccurrenceAgreementService` stays the single
  under-lock occurrence writer); the 5-7-day allowance holds under all three
  resolved options.
- **Findings:** 0 block / 0 major / 0 minor / 1 nit — fixed in-round: the §4
  T2-B label `C2.4-7` did not cover its included C2.8 assertions (ten-minus-two
  eligible facts; suspended 50/50 with zero live capacity) → `C2.4-8`. No open
  findings; §9 stays empty.
- **Decisions (batched question tool, answered 2026-09-23):** Q-073-01 keep
  the obligation and release only the helper; Q-073-02 saved-zone half-open
  UTC interval with the frozen mapping and cross-zone test vector recorded in
  §10; Q-073-03 stop future personal-pause effects at departure. All three
  match the plan's recommendations; recorded in §10 Resolutions.
- **Gates:** `npm run backlog:check`, `npm run docs:encoding`,
  `git diff --check` green (documentation-only round; runtime suites unchanged
  and not required). Review record:
  [CODE_REVIEW_2026-09-23.md](../../backlog/archive/CODE_REVIEW_2026-09-23.md#cs-plan-073-standard-a-plan-activation).
- **Transition:** `new/ → active/` with this review's scoped commit, which
  includes the full documentation-round package (plan, parent status/WP-table
  rows, `docs/README.md`, `docs/plans/README.md`, backlog notes/statuses,
  `ACTIVE_TASKS.md`, monthly archive roll) and excludes the three unrelated
  `.gitkeep` deletions. FEAT-UX-025 (backend slice) and CR-SVC-64 set
  In Progress; CONCEPT-001 and the parent stay open (WP3-WP9).
