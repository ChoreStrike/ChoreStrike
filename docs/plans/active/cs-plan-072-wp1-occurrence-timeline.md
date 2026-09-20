# CS-PLAN-072: WP1 - Occurrence Timeline

> **Status:** Active; child of CS-PLAN-070, delivering WP1 — Standard A `PASS` (2026-09-20), implementation not started
> **Effort:** L — 7-10 engineering days (revised 2026-09-20 from the parent's 5-8 allowance by Q-072-01 Option A; WP2 reduced to 5-7 accordingly)
> **Priority:** P1
> **Depends on:** [CS-PLAN-071 / WP0](../done/cs-plan-071-wp0-write-identity-foundation.md), delivered with final Standard B `PASS-WITH-FIXES` on 2026-09-19
> **Related backlog items:** CONCEPT-001 via [CS-PLAN-070](../active/cs-plan-070-family-rhythm-and-reward-clarification.md); implements RF-070-02 and the timeline portion of RF-070-04; extends RF-070-16/17 retention coverage without closing their later-package portions
> **Runtime baseline:** `master` at `9239d68`, inspected 2026-09-20

## 1. Problem Summary

WP0 supplies house-first locking, command receipts, persistent member/wallet
identity, tombstones and account erasure. It does not supply occurrence identity.
Today's execution and calendar paths still infer obligations from mutable task
definitions and `room_tasks.next_due`:

| Verified surface | WP1 gap |
|---|---|
| `RoomTask::markCompleted()`, `calculateNextStaticDue()`, `projectOccurrences()` | Fixed recurrence advances from completion time; calendar projection and execution do not consume the same durable occurrence. |
| `TaskCreationService::create()/createFromTemplate()`, `HouseSetupService::applySetup()` | All creation paths need an immutable initial schedule; setup inserts room tasks directly. |
| `TaskService::updateTask()`, `HouseService::updateHouse()` | Definition edits overwrite generation inputs; timezone changes bump a version but do not freeze existing agreements. |
| `RoomTaskReadService`, `RoomTaskService::getRangeTasks()` | Daily/overdue reads use `next_due`; calendar has write-free virtual dates but no stable execution token. |
| `CompletionService::complete()` | Completes by room-task ID alone, rejects another completion by the same person/day and advances using the completer's preferences. |
| `ChecklistService`, `CompletionEffectService` | Checklist progress/reset/restore is room-task-wide and can overwrite a later occurrence's progress. |
| Direct due-date/assignee writes and `SchedulePlanService::apply()` | Must not become a second authority beside the new occurrence rows; the exact WP1/WP2 cutover requires the decision in section 10. |

The canonical target is the parent's sections 3.1-3.5 and T1, plus the
[task-model timeline contract](../../concepts/task-model-and-sync.md#approved-timeline-contract-c1-2026-09-17).
Creating this plan is not implementation approval, activation or a review pass.

## 2. Strategy

Replace recurrence authority once, rather than adding an occurrence cache beside
the old calculator. `RecurrenceCalculator` provides pure date enumeration;
`OccurrenceService` owns selection, reconciliation and materialization; mappers
persist house-scoped facts. Reuse `CalendarService`, `TransactionExecutor` and
WP0 identity. Controllers validate and delegate; clients display server results.

### Scope and Handoffs

| Package | Boundary |
|---|---|
| WP1, this plan | Immutable rule revisions, stable occurrence keys, saved windows/time basis, deterministic target selection, write-free projections, timeline job, completion identity and occurrence-scoped checklist, minimal working client transport. |
| WP2 | Handover/pause/restart workflows, remaining whole-day capacity, normal/joint planning and preview fingerprint. Q-072-01 Option A (2026-09-20) moved the minimal common agreement writer, its `agreements` persistence and the necessary C2 deadline/authorization/capacity checks into WP1; no competing writer remains at this boundary. |
| WP3 | Actual participation/shares, fixed-rate wallet accounting, completion-ID correction routes and chronological room replay. Existing monetary behavior stays unchanged until its single cutover. |
| WP4 | Chronological streak confirmation, provisional weekly goal and awards. WP1 stores the planned-day/week evidence but does not claim the new rhythm is implemented. |
| WP5/WP6 | Digest/preferences and calm full UI. WP1 changes existing callers where needed for the new API; it does not defer a broken Complete button to WP6. |

No external service, new personal permission, production upgrade path or fallback
to bodyless completion is introduced. Intermediate packages are development and
review boundaries, not independently releasable versions of the family product.

## 3. Backend / Frontend Changes

### 3.1 Persistence and Identity

Create `Version1031Date20260918000000.php` with the parent's WP1 tables plus the
`agreements` persistence moved in by Q-072-01 Option A (parent §3.2 contract).
Later WP2 extends this migration in place with `pauses`; no upgrade/backfill for
disposable data.

| Table | Fields and invariant |
|---|---|
| `schedule_revs` | Room-task/revision, effective boundary, monotonic `agreed_through`, lifetime, inclusive active bounds, cadence mode/unit/interval, original anchor date/day, weekdays, early/late window days, saved zone, energy and responsibility/share/public/voluntary defaults. Unique `(room_task_id, revision)`. Definition inputs are immutable; only reconciliation/frontier and revision-closing metadata advance. |
| `occurrences` | House, room task, schedule revision and stable ordinal; kind; planned date/Monday week; saved zone, planned-day start and `[window_start, window_end)` UTC instants; frozen energy/public classification; version; coverage/suspension provenance; interval predecessor/source completion and valid completion reference. Unique `(schedule_rev_id, ordinal)` plus house/date and room-task/deadline indexes. |
| `occ_members` | Occurrence, persistent WP0 wallet/member identity, responsible/joint/helper role, obligation flag, share percent, work date/zone and disposition. Unique `(occurrence_id, member_id)` for live subjects; indexed member/work date. Stored defaults are facts, not authorization or new actual-work credit. |

Public `occurrenceId` is the string `<scheduleRevisionId>:<ordinal>`, never the
database surrogate. Define canonical numeric spelling and bounded integer
parsing; validate both parts and scope them through the visible house/room task.
Fixed ordinals are positions in the complete anchored sequence, not query-relative
row numbers. Persisted and virtual representations must produce the same key.

Edit Version1005 to key checklist progress by occurrence/item; add a progress
revision. Edit Version1029 to add completion occurrence references and frozen
timeline evidence. Keep existing monetary/room `before_state` fields until WP3,
but stop using their recurrence/checklist portion as whole-room-task authority.
Do not remove every snapshot column just because one consumer is replaced.

`next_due`/`ideal_due_date`, where still required by DTO consumers, become derived
read projections maintained by occurrence writers, not independent schedules.
Do not add duplicate house-version/tombstone columns already delivered in WP0;
change Version1000 only if an actual read-cache schema change is needed.

### 3.2 Rules, Agreement Frontier and Time

1. Enumerate fixed daily/weekly/monthly and interval rules with `CalendarService`
   using calendar dates, never elapsed 86,400-second arithmetic. Preserve the
   originally selected monthly day when clamping 29/30/31. Fortnightly dates use
   the chosen first anchor, not odd/even ISO weeks or the completion date.
2. Honor `once`, `recurring` and inclusive `bounded` lifetimes. An interval has
   one next pending occurrence; missed cron runs never invent successive interval
   completions. A real completion anchors its successor in the saved local basis.
3. Separate agreed occurrences from farther calendar projections. Materialize the
   existing seven-day agreement horizon and any farther window already open now.
   Persist enough frontier/effective-boundary metadata to derive the same already
   agreed virtual keys with cron stopped. Merely reading a preview agrees nothing.
4. Before an energy, cadence, lifetime, active-range, default-participant or zone
   edit, reconcile elapsed old agreements under the old immutable inputs, close
   the old revision and append the new revision in one house-first transaction.
   Existing concrete agreements retain ID, energy, zone and deadlines, including
   future agreements outside newly shortened active bounds. Apply house timezone
   edits across affected schedules atomically; update each mounted room task when
   a shared task definition changes. A failed edit rolls back the entire boundary.
5. Use deterministic bounded enumeration and indexed cursor batches for jobs;
   never silently truncate missed history at the existing 1,000-projection cap.
   A batch advances only the frontier it actually reconciled. Mutation-time
   reconciliation must be complete through its edit/selection instant or fail
   atomically with an explicit error, never publish a partially revised rule.
6. Resolve local boundaries once, preserving 23/25-hour days and Monday week
   attribution. A Saturday window through Monday belongs to Saturday's week.
   Expose the saved zone when it differs from the current house zone. Early work
   does not move the saved planned-day start forward for WP4's later evaluation.

`TimelineJob` runs on the injected Nextcloud clock at five-minute intervals,
registered through `appinfo/info.xml`; add a deterministic `timeline` job trigger.
It uses the same generator as creation and mutation, inside house-first
transactions. Natural revision/ordinal uniqueness and frontier updates supply
job idempotency: do not fabricate a member actor to call `CommandService`.
No GET or WP1 timeline pass mints awards, credits or notifications. WP4 adds its
time-driven rhythm consumer later; WP5 owns digest timing.

### 3.3 Target Selection, Completion and Checklist

- Among open unfulfilled windows choose smallest end, then planned date, then
  canonical identity. Show this planned date before completion. With no open
  window, select the latest missed, uncovered occurrence; never silently choose
  the next future projection instead.
- One completion fulfills one occurrence. Current work covers older catch-up
  cards without completing those historical misses or increasing their on-time
  numerator. Without an open window, record work late against the latest miss;
  retain earlier misses and fixed future dates. Expose one catch-up card per chore.
- Return `occurrenceId`, `expectedVersion`, `selectionToken`, agreement/projection
  classification, planned/work dates, saved window/zone and completion candidate
  on existing detail/list/daily/overdue/calendar DTOs. Token input covers relevant
  rule/agreement/participant versions and displayed attribution/status, not clock
  seconds. A window boundary or changed candidate invalidates the old selection.
- `POST /room-tasks/{id}/complete` requires `commandId`, `occurrenceId`,
  `expectedVersion`, `selectionToken`. WP3 adds actual allocation fields with its
  consuming code; WP1 must not accept and silently ignore unsupported shares.
  The server owns work time and target effort. Reject invalid payloads with 400,
  denied visible operations with 403, invisible/foreign targets with 404 and stale
  selection/state with 409, preserving the established envelope and formatter.
- Recheck current membership, visibility and tombstones under the WP0 house-first
  lock on both first execution and replay. Resolve a virtual agreed key, verify
  selection/version, insert it once and complete it in the same transaction.
  Materialization alone keeps version 0; domain mutation advances it. Replace
  the per-person/day duplicate guard with occurrence validity: two legitimate
  occurrences on one day are permitted, two completions of one target are not.
- Adopt `CommandService` for this completion seam now, advancing WP0's provisional
  WP3-only adoption schedule as required by parent T1. Authorization must run on
  the receipt-hit path as well as the callback: the current service skips the
  callback on replay. Replaying the same payload returns the original completion
  identity plus current authorized projections, even though that completion
  changed the selection token. Changed payload or new command on a fulfilled
  target conflicts. Do not repeat post-commit notifications/celebration events.
- Voluntary actionable work is separately classified and recordable without
  converting a farther unagreed projection into a personal obligation. Use a
  stable server-selected occurrence kind/key; pin this in T1 rather than inferring
  that every recorded completion necessarily creates a planned-day opportunity.
- Checklist reads/toggles carry occurrence identity and progress revision.
  Materialize virtual agreed progress through the same resolver; reject stale
  mutations. Completion closes only that occurrence's checklist. Existing latest
  undo resolves the recorded occurrence, reopens/re-derives its timeline effects
  and cannot overwrite another occurrence's progress or later valid completion.
  Keep monetary/room correction deficiencies explicit under RF-070-08 until WP3;
  WP1 does not claim isolated financial undo or introduce its new history routes.

### 3.4 Integration, Privacy and Clients

Route task creation, templates, setup, test seed, definition edits, house-zone
edits, job generation and completion through the common timeline. Audit every
`next_due`, active-state and assignee writer, including planner apply;
Q-072-01 Option A (2026-09-20) cuts `RoomTaskService::updateDueDate()/
setAssignees()/bulkAssignTasks()` and `SchedulePlanService::apply()` over to
the new agreement writer inside WP1. A mapper cache setter is not an
agreement API.
Keep current role/private-task checks; saved participants never confer blanket
owner/helper visibility, and authorization always uses active membership.

Tombstoning stops new scheduling but keeps minimum occurrence/completion evidence.
Ordinary departure retains historical internal identity without access or automatic
reassignment. Extend `AccountErasureService` to purge/anonymize new member links,
rule-default snapshots and any command result references containing identifiers;
retain only non-reidentifiable shared timeline facts. A recreated same-UID account
must not reattach history. Update reset order and all seed/fixture creation paths.

Change existing stores and their actual callers together: pass the shown target,
key checklist state by occurrence, key calendar rows by occurrence rather than
room-task alone, retain the same command UUID across uncertain-response retries,
and refresh authorized projections without silently retrying a 409 against a new
target. Keep the draft/selection visible for deliberate reload. Provide minimal
planned-date/window/zone labels and localized conflict feedback; no WP6 redesign.
New visible messages use `t()` with EN/DE/POT artifacts in the same package.

## 4. Tests

All rows below are required future evidence, not tests executed in this drafting
session. Use injected instants, UTC and `Europe/Berlin`; no host-clock assertions.
`N` means new and `M` means extend an existing suite.

| ID / ownership | Required assertions | Concrete files |
|---|---|---|
| T1-A / C1.1-2 | Jan 29/30/31 -> February clamp -> original March anchor, leap/non-leap; anchor 2026-12-26 -> 2027-01-09/23 despite early/late/missed work; local-date interval across DST; once/bounded edges. | N `tests/unit/Service/RecurrenceCalculatorTest.php`; M `tests/unit/Db/Entity/RoomTaskTest.php`, `tests/unit/Service/CalendarServiceTest.php` |
| T1-B / C1.3-5 | Earliest deadline, planned-date and identity tie-break; expired Tuesday plus open Saturday credits once; no-open-window catch-up selects latest miss; other misses remain and no fixed date drifts. | N `tests/unit/Service/OccurrenceServiceTest.php`, `tests/integration/Service/OccurrenceTimelineTest.php` |
| T1-C / C1.8 and C5k foundation | Early future work preserves planned-day start; Saturday-Monday preserves Saturday week; saved old zone/deadlines survive zone changes, new agreements use new zone; explicit projection vs agreement vs voluntary work. C1.6-8 streak/goal computation remains T5/WP4. | N `tests/integration/Service/OccurrenceTimelineTest.php`; M `tests/unit/Service/CalendarServiceTest.php` |
| T1-D / history | Stopped cron spans frequency, energy, lifetime, active-end and zone edits; old misses/defaults survive, already agreed future facts stay fixed; repeated jobs and interrupted batches converge; failed revision edit rolls back facts/frontier/cache. Include shared definitions mounted in multiple rooms. | N `tests/integration/Service/OccurrenceTimelineTest.php`, `tests/unit/BackgroundJob/TimelineJobTest.php`; M `tests/unit/Command/TriggerJobsTest.php`, `tests/integration/Command/TriggerJobsIntegrationTest.php` |
| T1-E / atomic API | GET causes no DB writes, receipt or reward; virtual GET -> materialize/complete once; two first materializations and two commands on one shown target; stale token/version and deadline boundary; same-command replay after success/lost response; current-right denial on replay; two valid same-day targets accepted. | N `tests/integration/Service/OccurrenceTimelineTest.php`; M `tests/unit/Service/CompletionServiceTest.php`, `tests/integration/Service/CompletionConcurrencyTest.php`, `tests/integration/Service/ConcurrentCompletionTest.php` |
| T1-F / checklist and undo seam | Distinct occurrence checklists, stale toggle conflicts, completion closes only target, undo preserves newer checklist/occurrence and explicit later agreements; failure after materialization or completion insertion rolls back all effects and receipt. | M `tests/integration/Db/ChecklistToggleConcurrencyTest.php`, `tests/integration/Service/CompletionUndoStateEqualityTest.php`; N `tests/integration/Service/OccurrenceTimelineTest.php` |
| T0 extension / privacy and schema | Fresh Version1031 constraints, all creation/seed/reset paths, cross-house/private denials, delete versus materialize, departure/rejoin, erasure from structured/default snapshots and same-UID recreation. | M `tests/integration/Db/FamilyRhythmSchemaTest.php`, `tests/integration/Service/AccountErasureTest.php`, `tests/integration/RoleGuardApiTest.php`; N `tests/integration/Service/OccurrenceTimelineTest.php` |
| Client contract | Shown key/version/token passed unchanged; command retry identity; 409 does not auto-complete another target; calendar instances/checklists do not collide; saved-zone label and reload remain correct. | M `tests/unit/store/{roomTasks,checklist}.spec.js`, `tests/unit/components/{TodayView,TaskList,TaskChecklist}.spec.js`; add focused assertions to existing view suites where their caller changes. |
| Browser / WP1 slice | Complete shown early/fixed occurrence, refresh without duplicate, late catch-up vs current target, stale tab 409 and occurrence-isolated checklist; adult/child authorized paths and private-target denial. | N `tests/e2e/family-rhythm.spec.ts` (WP1 cases only); M `tests/e2e/workflow-state-reconciliation.spec.ts`, `tests/e2e/task-completion-full-flow.spec.ts` and changed direct API callers. |

Concurrency tests use two real connections and barriers, not sequential calls or
sleeps. Inspect identities, frontier, completion count, checklist and unchanged
unrelated effects from fresh connections. Keep failed/unauthorized reads and
mutations write-free. A job must never fabricate command actors or emit awards.

Implementation gates: `npm test -- --runInBand`, `npm run lint`, `npm run build`,
`npm run test:noise`, `npm run l10n:check-keys`, Docker PHPUnit unit/integration
with `--fail-on-skipped`, `composer cs:check`, `npm run test:e2e:gate`,
`npm run backlog:check`, `npm run docs:encoding` and `git diff --check`.
Use the canonical [Docker workflow](../../standards/ai/skills/docker-dev/SKILL.md).
Update the runner, serial-pool configuration and exact required-scenario manifest
together. Future-package tests are added with their feature, not skipped stubs.
The final cross-DB/browser-repeat/family-evidence program gate remains WP9.

## 5. File Manifest

Paths with braces enumerate files. Existing shared files are changed only for
their WP1 seam. Manifest frozen by the 2026-09-20 Standard A review (Q-072-01
Option A): the moved agreement-writer seam adds the parent §3.2 `agreements`
table to Version1031 with its entity/mapper under `lib/Db/` and the C2
deadline/authorization/capacity checks onto the already-listed
`RoomTaskService`/`SchedulePlanService` files; tests in section 4 are part of
this manifest.

```text
new:      lib/Migration/Version1031Date20260918000000.php
new:      lib/Db/{ScheduleRevision,ScheduleRevisionMapper,Occurrence,OccurrenceMapper,OccurrenceMember,OccurrenceMemberMapper}.php
new:      lib/Service/{RecurrenceCalculator,OccurrenceService}.php
new:      lib/BackgroundJob/TimelineJob.php
modified: lib/Migration/{Version1005Date20260228160000,Version1029Date20260906000000}.php
modified: lib/Db/{Task,RoomTask,RoomTaskMapper,TaskAssignee,TaskAssigneeMapper,CompletedTask,CompletedTaskMapper,RoomTaskChecklistProgress,RoomTaskChecklistProgressMapper}.php
modified: lib/Service/{CalendarService,TaskCreationService,HouseSetupService,TaskService,HouseService,RoomTaskService,RoomTaskReadService,ChecklistService,TaskAccessPolicy}.php
modified: lib/Service/{CompletionService,CompletionEffectService,CompletionPostCommitService,CommandService,TestDataService,AccountErasureService}.php
modified: lib/Controller/{TaskController,RoomTaskController,ChecklistController}.php
modified: lib/Command/TriggerJobs.php
modified: appinfo/info.xml
modified: src/store/{roomTasks,checklist,scheduling,tasks}.js
modified: src/views/{TodayView,RoomDetail,TaskManager,CalendarView}.vue
modified: src/components/Tasks/{TaskList,TaskListItem,TaskListDetails,TaskChecklist,RoomTaskEditModal,TaskEditForm,RecurrencePresetSelector}.vue
modified: tests/integration/IntegrationTestCase.php
modified: tests/fixtures/{FamilyTestData,TestDataFixtures}.php
modified: tests/e2e/fixtures/{database-fixtures,occ-runner,test-house}.ts
modified: tests/e2e/required-scenarios.json
modified: scripts/run-e2e-gate.js
modified: playwright.config.ts
modified: l10n/{en,de}.json
modified: l10n/templates/chorestrike.pot
new:      docs/adr/ADR-013-occurrence-agreements-and-corrections.md
modified: docs/adr/{README,ADR-009-per-house-timezone,ADR-011-transactional-completion-and-undo,ADR-012-actionable-household-scheduling}.md
modified: docs/engineering/{architecture,development,testing}.md
modified: docs/api/README.md
modified: docs/concepts/task-model-and-sync.md
modified: docs/testing/release-test-plan.md
modified: docs/{README,plans/README,backlog/ACTIVE_TASKS,backlog/backlog}.md
modified: docs/plans/active/cs-plan-070-family-rhythm-and-reward-clarification.md
```

No new route path is required for the base WP1 scope. If the approved writer
decision brings forward occurrence routes, update `appinfo/routes.php`,
`tests/unit/RouteMatrixTest.php`, API docs and actual callers together. ADR-013 is
reserved by the parent: recheck availability, document the delivered timeline
portion and explicitly leave WP2/WP3 additions pending. Archive obsolete recurrence
implementations/tests only with replacement coverage; no blanket suite retirement.

## 6. Implementation Order

1. Standard A delivered 2026-09-20 (`PASS`): section 10 resolved via Q-072-01
   Option A, writer/API/schema boundaries frozen and the estimate revised to
   7-10 days (WP2 to 5-7). Implementation proceeds only from this activated
   plan.
2. Add fresh schema/entities/mappers and T0 extensions, including erasure/reset;
   implement the pure calculator and T1-A before wiring production consumers.
3. Implement frontiers/revision closure/materialization and the timeline job;
   wire every creation/edit/zone path with T1-C/D and rollback proofs.
4. Cut daily/detail/calendar reads and all agreed-date/assignment writers over to
   the shared occurrence authority according to the accepted boundary; prove
   write-free reads and no bypass before proceeding.
5. Wire completion receipts, explicit target resolution, checklist/undo seam and
   all current client callers together; run T1-B/E/F and client/browser cases.
6. Run full package gates, integrate durable docs and update parent finding
   evidence without closing later-package work. Final Standard B reviews this
   complete package; CONCEPT-001 and the parent remain open.

## 7. Risks & Mitigations

| Risk | Likelihood | Mitigation |
|---|---|---|
| WP1 and WP2 leave competing writers or disable working planning | High | Resolve section 10 before activation; no silent `next_due` bypass, temporary compatibility engine or fake-green endpoint stub. |
| Cron gaps reconstruct history from today's mutable task | High | Full immutable defaults, explicit frontier, reconcile-before-edit and failure/interruption tests. |
| Virtual and stored IDs diverge or a stale click hits the next task | High | Stable ordinals, deterministic tokens, materialization at version 0, explicit target + 409, two-connection tests. |
| Snapshot undo reintroduces old timeline state | High | Remove recurrence/checklist authority from restore now; preserve later facts; monetary/room replay stays explicitly WP3-owned. |
| Receipt replay bypasses authorization or duplicates post-commit effects | High | Under-lock authorization on hit/miss, original result IDs with fresh safe projections, replay-side-effect pins. |
| Historical default snapshots retain deleted people | High | Extend erasure with every new table/snapshot and test recreated UID; never infer permission from wallet identity. |
| Large historical reconciliation holds the house lock too long | Medium | Indexed cursor batches for jobs, measured worst-case edit reconciliation and atomic bounded failure, no silent loss of history. |
| API change outpaces UI/tests | High | Minimal caller migration and full browser gate in WP1; do not postpone transport to WP6. |

## 8. Acceptance Criteria

- [x] Standard A resolves the writer boundary, records decisions and activates the plan (2026-09-20: Q-072-01 Option A, estimate 7-10 days, WP2 5-7).
- [ ] One recurrence authority covers all fixed/interval/lifetime cases and preserves immutable historical generation inputs across edits and cron gaps.
- [ ] Read-only projection, agreed virtual identity and materialized identity select the same occurrence without GET writes or automatic new obligations.
- [ ] Saved zones, half-open windows, planned-day start and Monday week survive edits; early/late work does not move fixed anchors.
- [ ] Completion replay/races fulfill exactly one shown target; no person/day duplicate restriction blocks distinct legitimate occurrences.
- [ ] Checklist and timeline undo affect only the target occurrence, preserving newer progress/completions and explicit agreements.
- [ ] Every relevant writer and current client caller uses the occurrence contract; no second recurrence engine or cache-write bypass remains.
- [ ] Fresh install, seed/reset, tombstone retention, private access and account erasure include the new schema and snapshots.
- [ ] All section 4 tests and gates pass without missing, red or newly/unapproved skipped required cases.
- [ ] Parent RF-070-02/RF-070-04 evidence reflects only verified WP1 scope; broader CONCEPT-001 and later-package findings remain open.
- [ ] Durable behavior is integrated into concepts, ADR-013, engineering/API/testing docs with later-package limitations explicit.
- [ ] All section 9 findings and section 10 questions are resolved; final Standard B is `PASS` or `PASS-WITH-FIXES`; tracking updated.

## 9. Review Findings (Plan-Owned)

The 2026-09-20 Standard A review verified the full technical inventory against
the runtime at HEAD `9239d68` and raised no findings. Record newly discovered
in-scope findings here, not as duplicate backlog items. Parent findings retain
their parent IDs and are not closed by a plan or specification test table.

## 10. Activation Decisions

Parent-approved product rules are unchanged. The following engineering boundary
was settled in the 2026-09-20 Standard A question batch, not during coding:

| ID | Evidence and options | Resolution |
|---|---|---|
| Q-072-01: existing agreement writers at the WP1/WP2 boundary | `RoomTaskService::updateDueDate()/setAssignees()/bulkAssignTasks()` and `SchedulePlanService::apply()` mutate dates/assignees today. The parent assigns occurrence authority to WP1 but the replacement agreement writer to WP2. Leaving them unchanged corrupts the new authority; disabling them breaks the existing planner. Option A: bring the minimal common agreement writer, its `agreements` persistence and necessary C2 deadline/authorization/capacity checks into WP1, while pause/restart and remaining WP2 work stay there. Option B: implement/review WP1 and the dependent WP2 writer slice together as one integration boundary. Neither option permits duplicate recurrence engines or a false independent WP1 pass. | **RESOLVED 2026-09-20: Option A** (user-selected in the Standard A batch). Moved methods frozen: `updateDueDate()`/`setAssignees()`/`bulkAssignTasks()` and the planner `apply()` cutover, with their tests; the parent's package table now carries WP1 at 7-10 days and WP2 at 5-7. |

The following proposed technical allocations were ratified by the 2026-09-20
Standard A review as parent-derived, not new product consent: completion
transport and command adoption start in WP1 for T1 (parent T1 "same target
replay" plus the §3.4 `commandId` complete-route contract advance WP0's
provisional D6 schedule); job materialization uses natural keys without a fake
actor (parent: a timeline job, not a GET, extends the horizon); minimal
frontend caller migration precedes WP6; timeline and checklist restore stop
being snapshot-authoritative before WP3's monetary cutover. No new user
product decision is claimed from these allocations.

## Review Outcome — Standard A (2026-09-20)

- **Scope**: this plan file; owning parent CS-PLAN-070 (active) annotated.
- **Tier / outcome**: Standard A (plan) — `PASS`, no findings; eligible
  activation transition performed.
- **Base state**: `master` @ `9239d68`, index empty; unstaged-diff fingerprint
  `06e222da8f03853420b469487a33ee8f138007c1`, untracked plan blob
  `00e5b96a8318f95aa9ec56073ee8654175e0e94d` (SHA256
  `59434F394B44508DAB944E86F0BC078E6F384AD0584C800CB23A17DEB271A47A`) at
  capture.
- **Verification**: every §1 writer/read surface confirmed at cited locations;
  all §4 `M` test files and §5 manifest paths exist; migration chain ends at
  Version1030 with Version1031 free and the filename matching the parent's
  frozen manifest; ADR-009/011/012 present, ADR-013 free; concept-contract
  anchor valid; WP0 dependency delivered in `done/`; parent §3.1-3.5/T1/§9
  contracts present. Runtime spot-checks confirmed the day-duplicate guard
  (`CompletionService.php:185-194`), the replay callback skip
  (`CommandService.php:122-144`), `MAX_PROJECTION_OCCURRENCES = 1000`
  (`RoomTask.php:52`) and room-task-scoped checklist keys (Version1005).
- **Decisions recorded**: Q-072-01 = Option A; WP1 effort 7-10 days with WP2
  reduced to 5-7; §10 technical allocations ratified as parent-derived.
- **Fixes**: none required — no defects found; edits above are decision
  application and activation metadata only.
- **Gates**: `npm run backlog:check`, `npm run docs:encoding`,
  `git diff --check` green after edits.
- **Transition**: `new/ → active/` (2026-09-20); `docs/plans/README.md`,
  `docs/README.md`, CONCEPT-001 narrative, `ACTIVE_TASKS.md` and parent WP
  table updated; review record `docs/backlog/archive/CODE_REVIEW_2026-09-20.md`.

## Drafting Evidence

2026-09-20: parent/template/WP0 delivery and current runtime inspected; separate
read-only runtime inventory checked call sites and package-boundary hazards.
This session changes documentation only. No application tests, migration, family
trial, lifecycle transition or commit is implied. `npm run backlog:check`,
`npm run docs:encoding`, `git diff --check` and the new plan's standalone
whitespace check passed. The unchanged document checkers also passed with
tracked-plus-untracked Git inventory supplied in-process (25 link-scoped files,
741 encoding-scoped paths), without staging the draft or changing the checkers.
Q-072-01 remains a review/activation gate, not a completed implementation decision.
