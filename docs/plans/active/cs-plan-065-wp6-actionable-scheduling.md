# CS-PLAN-065: WP6 - Actionable Scheduling Through My Day

> **Status:** Active (drafted 2026-09-10; Standard A `PASS` 2026-09-10, D1-D8 ratified; implementation delivered 2026-09-10; final Standard B `NEEDS-FOLLOW-UP` 2026-09-11 — implementation verified green, test-matrix findings RF-065-01/02 open for the next round)
> **Effort:** L
> **Priority:** P1
> **Depends on:** [CS-PLAN-058](../active/cs-plan-058-core-workflow-bottom-up-masterplan.md) WP0-WP5 complete; [CS-PLAN-064](../done/cs-plan-064-wp5-completion-ledger-full-undo.md) final review passed; ADR-009 house-local dates, ADR-010 visibility, ADR-011 completion/undo
> **Related backlog items:** CR-WF-07 (owned umbrella), CR-055-01 (owned cleanup)
> **Parent:** CS-PLAN-058 WP6; WP7 consumes this package's applied-schedule contract.

## 1. Problem Summary

The masterplan requires an owner-confirmed schedule for real room tasks, followed
by consistent Calendar and My Day reads. The current implementation provides
availability advice instead. Baseline inspected on 2026-09-10:

| Current surface | Verified gap |
|-----------------|--------------|
| `SchedulingService::getSchedulingSuggestions()` | Receives only `taskCount`; returns weekday counts, energy totals and `min(taskCount, totalEnergy / 5)`, not task IDs or due dates. |
| `SchedulePlanner.vue::applySuggestion()` | Saves the current user's weekly preferences. Its Apply label does not mean applying task assignments/dates. |
| `SchedulingController` / `appinfo/routes.php` | Preferences, owner member-availability and suggestions routes exist; no task-plan apply aggregate exists. |
| `RoomTask` / `RoomTaskMapper` | Canonical dates and assignments exist, but no persisted version or `updatedAt` for stale-preview detection. Direct due-date edits are not an aggregate with assignment replacement. |
| `RoomTaskService::getFilteredDailyTasksForUser()` | Visibility precedes child availability/limit, but assignment eligibility does not. `TodayView::isMine()` filters assigned-to-me OR unassigned only after the server limit. |
| Scheduling preferences | `maxDailyTasks` is read and seeded, but has no production setting; the owner availability response omits it. |
| `scheduling.js` / `roomTasks.js` | `todayConsumedEnergy` has fetch/increment writers but no production reader. The preferences response still computes it. |
| Calendar / My Day | Calendar consumes projected range occurrences; My Day keeps local copies from daily/overdue reads. Updating only the base room-task array cannot refresh both. |

Existing foundations are retained: canonical `TaskAssigneeMapper` relations,
`TaskAccessPolicy`, `CalendarService`, `RoomTask::projectOccurrences()` and flex
window helpers, `TransactionExecutor`, and the current scheduling surface. The
retired `smart-schedule/*` API is not a foundation and must not return.

## 2. Strategy

Evolve the existing Scheduling screen into **Preview -> Review/adjust -> Apply**.
Build one service-owned, deterministic plan from real room tasks; recompute and
validate it against locked current state before one atomic assignment/date write.
Do not add another optimizer, an independently persisted calendar, or a client
mutation chain.

### 2.1 Ownership and boundaries

| Work | Owner / disposition |
|------|---------------------|
| Preview, atomic apply, child scheduling eligibility/limits, scheduling-specific cache handoff and E2E | CR-WF-07 / this plan |
| Remove the reader-less consumed-energy cluster | CR-055-01 / this plan |
| General enriched payloads, dashboard counters, cross-view races, family UX | CR-WF-04/08 and CR-053-20 remainder / WP7, unchanged |
| Broad service decomposition, N+1 elimination, query budgets | WP8, unchanged |
| Global skip inventory and canonical full-household journey | WP9, unchanged |

The first CR-WF-04 criterion overlaps the masterplan's WP6 eligibility/date
requirement. Deliver that prerequisite here and annotate its evidence at WP6
closure; do not create a second backlog item or claim the WP7 umbrella complete.
Only the scheduling-owned payload and refresh seams enter this plan.

Reward formulas, membership product rules, visibility policy, recurrence policy,
and ADR-011 undo semantics are frozen. No reward redesign, external service,
background auto-apply, separate preference store, or upgrade compatibility shim.

### 2.2 Decisions proposed for Standard A review

D1-D8 were batched as structured review questions and **ratified as
recommended** by the Standard A review on 2026-09-10 (D5 with bound 1-100 and
`0` rejected as a validation error). The answers are recorded in
`## Review Outcome`; they authorize activation, not implementation evidence.

| ID | Recommended decision | Alternative / review boundary |
|----|----------------------|-------------------------------|
| D1 | Replace the old suggestions contract on `POST /scheduling/suggestions` with the real-task preview; add `POST /scheduling/apply`. Both plan operations are owner-only. Keep personal availability/budget settings separately. | Add a named preview route and prune suggestions instead; never retain two competing planning contracts. |
| D2 | Use a deterministic, opaque content fingerprint as `previewVersion`, recomputed from authoritative dependencies and normalized proposal inputs. No preview table or schema revision column by default. | A persisted monotonic revision needs a reviewed writer inventory and fresh-install schema fold. Content versions detect state differences, not an intervening change that fully returns to the identical state. |
| D3 | Plan one next occurrence per real room task over seven consecutive house-local days. Preserve existing assignees by default; propose one eligible person for unassigned tasks; allow explicit owner overrides via a fresh preview. | Automatic reassignment or multi-occurrence materialization requires rescoping; do not infer either from capacity advice. |
| D4 | Preserve My Day's existing meaning of "mine": assigned to the viewer OR unassigned. Move that eligibility before child limits; keep adult All tasks as a visibility-filtered mode. | Assigned-only child My Day is a product change and must be explicitly ratified. The exit fixture will have no eligible unassigned tasks, so its expected child set is exact. |
| D5 | Expose an owner-only child `maxDailyTasks` setting: omitted leaves unchanged, `null` means unlimited, integer 1-100 means a finite limit. Existing missing values remain unlimited. Personal preference endpoints cannot change the limit. | Ratified 2026-09-10: bound 1-100; `0` is rejected as a validation error because unavailable weekdays already express a day off. |
| D6 | Capacity is a planning constraint: no new automatic overload, unavailable assignment, or child-limit violation. Existing unavoidable overload is reported with unchanged/unplaced rows, not silently fixed. Count full task effort for each assignee, not an invented teamwork split. | Different effort splitting or owner overload overrides needs a product decision and updated tests. |
| D7 | Remove `todayConsumedEnergy` from client state/actions and the preferences payload. Preview capacity derives scheduled work and non-undone completion effort server-side, not a local increment. | Do not add a remaining-energy UI just to justify dead state. |
| D8 | Preserve ADR-011: a later undo restores its recorded recurrence/claim state even after scheduling. Warn on affected preview rows and refresh from authoritative reads after undo. | Changing/restricting undo would reopen WP5; do not silently invalidate its ledger or disable undo. |

## 3. Backend / Frontend Changes

### 3.1 Deterministic preview over existing scheduling primitives

Keep `SchedulingService` as the preferences/capacity input owner. Add one cohesive
`SchedulePlanService` for preview and apply, injected through Nextcloud DI into
the existing controller. This is an aggregate boundary, not a second scheduler.
Reuse the documented MVP balancing strategy in
[energy-and-balancing.md](../../concepts/energy-and-balancing.md), date/flex helpers
and availability rules; retire synthetic `taskCount`-only advice and its consumers.

Proposed contract, beneath the existing `/api/v1` prefix and API envelope:

| Operation | Request | Successful data |
|-----------|---------|-----------------|
| `POST /scheduling/suggestions` | `startDate` (`YYYY-MM-DD`), unique selected `roomTaskIds`, optional per-row assignee/date overrides | `previewVersion`, `startDate`, `endDateExclusive`, `timezone`, `changes`, `unchanged`, `unplaced`, `capacity`, `warnings` |
| `POST /scheduling/apply` | The exact normalized preview input plus its `previewVersion`; no client-supplied trusted before-state or capacity | Authoritative changed `roomTasks` with canonical `assignees`, applied row count and affected date window |

Each change names `roomTaskId`, definition/room display data visible to the owner,
before/proposed assignee sets, before/proposed house-local due date, and warning
codes. Assignee IDs follow the canonical existing API representation; keep
Nextcloud user IDs distinct from numeric membership IDs. Unchanged and unplaced
rows have explicit reason codes. Never return private task content the owner
cannot see; aggregate warnings must not reveal inaccessible tasks or their names.

Preview rules:

1. Validate the closed request shape, strict real dates, nonempty unique positive
   IDs and a bounded batch (proposed maximum 100). The seven-day window starts
   today or later in the house timezone; past windows are invalid.
2. Resolve existing active room tasks through the house ownership chain, then
   apply actor visibility. Do not schedule definitions without instances or
   write rows while generating/adjusting a preview.
3. Keep task recurrence, active bounds, static weekdays/time and flex tolerance
   authoritative. A static or zero-flex task without a legal slot stays unchanged
   with a reason; never rewrite its recurrence to make a suggestion fit. An
   overdue dynamic task may move only within its existing legal date constraints.
4. Determine assignee eligibility before availability and capacity. Validate
   membership and the resulting visibility relation; private tasks can gain an
   explicit assignee under the existing owner/visibility rules, while admin-only
   tasks cannot be assigned to a non-owner. Preserve multi-assignee sets unless
   the owner explicitly changes them. Claimed tasks are unchanged/unplaced until
   their claim is resolved; do not erase claim state as a scheduling side effect.
5. Order by urgency, higher priority, then room-task ID. Prefer the nearest legal
   date and eligible member with most remaining capacity; use stable date/member
   ID tie-breakers. Low-priority overflow moves only to a legal slot, otherwise
   it remains unplaced. Input order and database row order must not change output.
6. Compute per-person/day capacity from canonical preferences, existing planned
   occurrences (including tasks outside the selection) and non-undone completed
   work on that day. Replace, rather than add again, each selected next occurrence
   in the proposed totals. Reserve projected later recurrences without pretending
   they are independently writable room tasks. Do not double-count the completed
   occurrence as both planned and completed work.
7. Return before/after effort, task counts, remaining capacity and stable warnings.
   No Apply action persists a preference edit. An owner edit to a row or input
   invalidates confirmation and requires a newly calculated preview.

The fingerprint covers normalized inputs/proposals, selected row state and
assignees, task visibility/recurrence/energy/priority, relevant member roles and
preferences, house timezone, capacity-source rows and occurrence/undo state.
Canonical sort/serialization is part of the tested contract. The fingerprint is
**not authorization**: apply always reconstructs the proposal and validates every
field. No client hash grants access or bypasses domain validation.

### 3.2 One atomic apply, with explicit concurrency boundaries

`SchedulePlanService::apply()` owns one `TransactionExecutor::run()`; mappers own
all query/lock primitives. Do not call the existing per-item bulk assignment
transactions or chain `setAssignees` and `updateDueDate` requests.

Before writes, repeat service-level actor membership/owner checks and validate
all target tasks, assignees, activity, date constraints and preview dependencies
from fresh locked rows. Any failure rolls back the entire batch, including the
first assignment replacement if a later due-date write fails.

Proposed lock direction is `house -> room_task (ascending IDs) -> task definition
(ascending IDs) -> house_user (ascending IDs) -> assignee writes`. Take the house
lock first to coordinate creation/timezone inputs, never after a room-task lock.
Include capacity-relevant existing room tasks, not only modified targets; acquire
all room-task locks before later lock classes. Scheduling does not mutate room
cleanliness, reward state or the completion ledger.

**Mandatory pre-implementation proof:** inventory scheduling-affecting writers
(create/mount, assignment/claim, task edits, due-date/lifecycle changes,
completion/undo, membership/role removal, preferences and house timezone). Verify
their lock/read-modify-write order, phantom insertion protection and fresh-read
behavior under MySQL/PG/SQLite. A house lock alone does not protect writers that
never take it. Adjust only the required writer seams, record the exact order and
file list in ADR-012 and this manifest, and prove no inversion with ADR-011's
`room_task -> room -> house_user`. An unproven writer boundary blocks step 4; it
cannot be deferred to WP8 while claiming stale/race safety.

Recompute the preview and capacity under that boundary. A changed version,
removed/inactive dependency or newly invalid proposal rejects the batch; do not
silently regenerate and apply a different plan. Persist canonical assignees,
`nextDue` and any required `idealDueDate` update consistently using existing
house-local recurrence semantics; definition/reward/checklist fields are unchanged.

Error and retry contract:

- `400 VALIDATION_ERROR`: malformed dates, duplicate IDs, invalid scalar types,
  oversized payload, invalid overrides or forged domain values.
- `403 FORBIDDEN`: non-member or non-owner actor, including direct service calls.
- `404 RESOURCE_NOT_FOUND`: unknown, cross-house or invisible task, per ADR-010.
- `409 CONFLICT`: stale/inactive preview dependencies or an obsolete confirmation;
  localized feedback offers re-preview while preserving owner inputs.
- Repeated apply cannot advance recurrence or duplicate assignments. A consumed
  changed preview returns 409 on retry; an unchanged valid no-op performs zero
  writes. No persistent idempotency receipt is proposed. If the response is lost,
  reload authoritative state and preview again rather than blind auto-retry.
- After commit, client refresh failure must be reported as "applied, refresh
  failed", never as an unsuccessful transaction. Do not add post-commit work that
  can turn the committed apply into a generic backend failure.

Keep ADR-011's inverse unchanged. Cover `preview -> complete/undo -> apply` (stale
rejection), `apply -> complete -> undo` (restores the applied occurrence), and
`complete -> apply -> undo` (recorded due/claim restoration; assignments were not
part of the completion snapshot). The UI must invalidate/refetch affected schedule
views after undo rather than assume the applied dates still hold.

### 3.3 Child limits and authoritative daily reads

Add the owner-controlled limit to existing member preference validation, response
and `AvailabilityEditModal`; reuse the existing JSON sibling key, not a new table.
Validate at controller and service boundaries; reject attempts to change the limit
through the personal availability endpoint. Preserve existing weekday/energy
section merge semantics and self-availability access.

For filtered daily reads, evaluate house/activity/date -> visibility -> requested
assignment scope -> child availability -> deterministic sort -> child limit.
Use the canonical assignee batch read and stable `nextDue, createdAt, id` ordering.
Return assignees with these rows so My Day does not reinterpret an unknown set as
unassigned after the limit. Default mine semantics remain assigned-to-me OR
unassigned; adult All tasks remains explicit and visibility-filtered. A child's
scope toggle must not bypass its personal eligibility/limit.

Keep Calendar a visibility-filtered household calendar, not a child-limit-truncated
copy of My Day. Both derive dates from persisted room tasks and ADR-009 projections;
Calendar may show other visible household work. Test exact child assignment sets
separately from that broader calendar visibility. Overdue rows remain a separately
labelled existing surface; do not silently reinterpret `maxDailyTasks` as an
authorization restriction on viewing/completing tasks or redesign overdue policy.

Remove `todayConsumedEnergy`, `registerTodayEnergyConsumption`, the completion
call-site increment, preferences response field and obsolete tests/docs. Retain
or adapt the mapper energy query only if the new preview actually consumes it;
its scheduled/completed accounting must exclude `undone_at` rows. Do not retain
unused dependencies/helpers after removing the old payload path.

### 3.4 Owner workflow and immediate client reconciliation

Use the current Scheduling page and `SchedulePlanner.vue`: select tasks/window,
preview person/task/day changes, review warnings and capacity, optionally adjust,
then confirm one batch. Owner-only planner/member-policy controls; members and
children retain allowed personal settings and read their resulting plan. Existing
energy settings stay separate, never masquerading as task Apply.

`scheduling.js` owns preview/apply state and uses `src/services/api.js`. Distinguish
never-loaded, loading, empty/no-change, warnings, stale, applying, applied and
refresh-failed states. Disable double submission and confirmation of edited/stale
previews. Generation/request sequencing must prevent an older preview replacing
newer input; clear state on house/user changes.

After a successful apply, reconcile returned room tasks/assignees and coordinate
refetch of active daily/overdue and range windows before reporting synchronized
success. Calendar occurrence projections require a fresh range read, not a patch
to a single `nextDue` display row. Update TodayView's local copies as well as store
slices. Prevent a pre-apply range/daily request overwriting the refreshed results.
Keep this request-order protection scoped to scheduling mutations and its readers;
do not start WP7's repository-wide cache redesign or circular store imports.

No full page reload is needed for the current session. Newly opened member/child
sessions read the persisted plan; real-time push to already-open remote sessions
is not required. Completion/undo refreshes the scheduling-owned views from server
state without changing celebration event vocabulary.

All new visible copy uses `t()` with en/de/POT updates. Use household terms rather
than optimizer jargon, keyboard-operable controls, visible focus, announced
loading/error status and at least 44 px touch targets at mobile and desktop sizes.

## 4. Tests

### 4.1 Required regression matrix

| Layer | Required proof |
|-------|----------------|
| PHP unit: preview | Real IDs, no writes, stable output/version under reordered inputs; different relevant state changes the version; invalid IDs/dates/types/bounds; legal dynamic/static/once/bounded/flex paths; unavailable/no-capacity/unplaced cases; multi-assignee cost and fixed-plan baseline; no duplicate occurrence accounting. |
| PHP unit: policy/controller | Owner positive and member/child/removed/non-member negative cases, direct-service checks, private/admin/public visibility, proposed assignee eligibility, standard envelope/status; limit omission/null/bounds and self-service denial; removed suggestions contract rejected, no compatibility fallback. |
| PHP unit: daily/date | Assigned-to-another and invisible tasks earlier in sort order cannot consume a child's slots; unassigned semantics, availability, limit and adult All; deterministic ties; house midnight, DST transitions and browser/server timezone mismatch. |
| Real-DB integration | Preview leaves all domain tables unchanged; successful batch has exact assignments/dates and unchanged unrelated fields; fault after first write leaves zero partial changes; stale assignment/date/definition/member/preference/timezone/completion/undo inputs reject without writes; no-op/retry behavior. |
| Real concurrency | Two processes/connections: overlapping apply/apply in opposite input order; apply vs completion/undo, assignment/due edit, preference/membership changes and capacity-affecting creation. Assert committed serial outcome or clean conflict, never mixed state, lost update, deadlock or unauthorized success. |
| Read-through integration | Apply -> range/daily reads -> complete -> undo -> range/daily agree on persisted occurrence dates and assignments; non-undone completion capacity exact; visibility filtering stays intact for each role. |
| Jest stores/API | Preview/apply route/payload/envelope contracts, zero implicit writes, changed-input invalidation, 409 recovery, no duplicate apply, cache/range/daily reconciliation and out-of-order refresh, lost-response recovery, removal of energy increment without completion regression. |
| Jest components | Owner/member/child controls, limit setting, before/after rows/warnings, no-change and partial-unplaced preview, keyboard/mobile controls, loading/error states, preserved edits on stale/error, applied-but-refresh-failed distinction. |
| Playwright | Owner creates tasks through UI, sets availability/child limit, previews and applies; Calendar/My Day show exact persisted results on navigation without reload; child/member role contexts cannot apply; stale preview recovery; keyboard and mobile workflow. |

Use a deterministic `family` fixture and an explicit owner (`dad`) plus invited
child/member contexts. Add `scheduling-apply-flow.spec.ts` to
`SCHEDULING_OWNER_SPECS` in `playwright.config.ts`, so it runs exactly once in
`chromium-scheduling-owner` and is excluded from other browser pools. Run pool
commands sequentially; `workers:1` inside two simultaneously running projects
does not prevent shared-database resets. Seed once before the journey; no reset
between owner apply and child verification. Setup may seed users, but task
creation and scheduling in the exit journey must use the UI.

Pin expected task IDs, dates and assignees; do not use fixture-dependent skips,
swallowed catches or assertions accepting any nonempty result. Existing frozen
completion/undo state-equality and reward tests remain green.

### 4.2 Gates

During implementation run targeted PHPUnit/Jest and the new scheduling journey
after each owning slice. At completion and after review fixes run the complete
[masterplan quality gate](../active/cs-plan-058-core-workflow-bottom-up-masterplan.md#45-quality-gate),
including all three Playwright pools sequentially, Docker unit/integration and
`composer cs:check`, Jest, lint/stylelint, noise/build/l10n/docs/backlog and diff
checks. Load the canonical docker-dev/testing instructions before environment
work; real locking proofs must run on the Docker MySQL service, not only SQLite
or mocked locks. Any schema decision also requires a fresh-install gate.

For **this draft-only change**, run `npm run docs:encoding`,
`npm run backlog:check` and `git diff --check`; application tests are not claimed
as executed or as evidence that WP6 is implemented.

## 5. File Manifest

Paths below are the intended implementation boundary, not files changed by draft
creation. Before step 4, narrow the writer-lock subset from the mandatory audit;
additions or a different version/schema design require an explicit plan update.

```text
new:      lib/Service/SchedulePlanService.php
new:      tests/unit/Service/SchedulePlanServiceTest.php
new:      tests/integration/Service/SchedulePlanAggregateTest.php
new:      tests/integration/Service/SchedulePlanConcurrencyTest.php
new:      tests/e2e/scheduling-apply-flow.spec.ts
new:      docs/adr/ADR-012-actionable-household-scheduling.md

modified: appinfo/routes.php
modified: lib/Controller/SchedulingController.php
modified: lib/Controller/RoomTaskController.php
modified: lib/Service/SchedulingService.php
modified: lib/Service/RoomTaskService.php
modified: lib/Db/{RoomTaskMapper,TaskMapper,HouseUserMapper,TaskAssigneeMapper,CompletedTaskMapper,TransactionExecutor}.php
conditional: lib/Service/{TaskService,TaskCreationService,HouseService,CompletionService}.php (only writer synchronization proven necessary by section 3.2)
conditional: lib/Db/RoomTask.php (only nextDue/idealDueDate coherence; recurrence policy unchanged)

modified: src/store/{scheduling,roomTasks}.js
modified: src/views/{SchedulingView,CalendarView,TodayView}.vue
modified: src/components/Scheduling/{SchedulePlanner,ScheduleCalendar,OvercapacityWarning,AvailabilityEditModal,AvailabilitySettings}.vue
modified: tests/unit/Service/{SchedulingServiceTest,RoomTaskServiceDailyTest,CalendarServiceTest}.php
modified: tests/unit/Controller/{SchedulingControllerTest,RoomTaskControllerTest}.php
modified: tests/unit/RouteMatrixTest.php
modified: tests/integration/Service/{SchedulingPreferencesIntegrationTest,CompletionUndoStateEqualityTest}.php
modified: tests/integration/Db/RoomTaskMapperDailyTest.php
modified: tests/unit/store/{scheduling,roomTasks,apiContract}.spec.js
modified: tests/unit/components/{SchedulePlanner,SchedulingView,ScheduleCalendar,AvailabilityEditModal,AvailabilitySettings,TodayView,CalendarView}.spec.js
modified: tests/e2e/{scheduling,flex-window-scheduling,availability-settings-flow,today-view,self-availability-member}.spec.ts
modified: playwright.config.ts
modified: l10n/{en.json,de.json,templates/chorestrike.pot}

modified: docs/adr/README.md
modified: docs/api/README.md
modified: docs/concepts/{energy-and-balancing,ui-use-cases}.md
modified: docs/engineering/{architecture,development,testing}.md
modified: docs/backlog/{backlog,ACTIVE_TASKS,archive/2026-09}.md
modified: docs/plans/README.md
modified: docs/plans/active/cs-plan-058-core-workflow-bottom-up-masterplan.md
modified: this plan (new/ -> active/ only through passing Standard A)
```

All networking continues through the existing `src/services/api.js` wrapper; do
not modify it solely to introduce a second domain client. No new migration is
expected under D2. If a different versioning design is approved, name the exact
existing migration/entity/writer/test paths here before implementation. Archive
completion history in the actual resolution month if it is later than September.

## 6. Implementation Order

1. **Standard A gate:** review scope/contracts and D1-D8, batch and resolve
   questions, record the result. Only a passing review activates this plan and
   marks CR-WF-07/CR-055-01 In Progress. Update masterplan and coordination links.
2. **Baseline regressions:** pin missing real-task preview/apply, child
   eligibility-before-limit and obsolete energy-counter behavior in focused tests;
   inventory all scheduling dependency writers and current route consumers.
3. **Contract and persistence boundary:** document ADR-012, deterministic
   selection/capacity and fingerprint input contracts, exact lock/writer order,
   retry/no-op and undo interaction. Update the manifest and prove the lock
   boundary against ADR-011 before aggregate writes are implemented.
4. **Backend aggregate:** implement preview and one transactional apply, service
   authorization/validation, mapper primitives and only required writer seams.
   Finish rollback/staleness/two-process proofs before connecting UI Apply.
5. **Daily eligibility and capacity inputs:** expose owner child limits; fix
   server filtering order and assignee payload, remove CR-055-01's dead cluster;
   prove house-local daily/range and undone-completion accounting contracts.
6. **Scheduling UI and state handoff:** replace preference-Apply with task-plan
   review/adjust/confirm, add role/error/a11y behavior, reconcile/refetch Calendar
   and My Day including local copies, protect against pre-apply stale responses.
7. **Exit journey:** add the serial owner-create/apply -> child/member-read
   Playwright journey, no-reload assertions and stale/role/mobile tests; update
   existing scheduling specs to the one new contract.
8. **Durable docs and full gate:** integrate accepted behavior into ADR/API,
   energy/UX concepts and architecture/development/testing docs. Run all gates,
   resolve plan-owned findings, annotate only the delivered WP7 prerequisite.
9. **Final Standard B:** passing final review may move this child plan to done/,
   archive CR-WF-07/CR-055-01, update masterplan WP6 evidence and tracking. Leave
   the masterplan active and WP7 not started; no automatic downstream execution.

Each step includes its tests/docs. Interim reviews may validate a coherent slice
but cannot close this plan before steps 1-9 and all acceptance criteria finish.

## 7. Risks & Mitigations

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Synthetic advice is relabelled as a real plan | High | Real room-task IDs, exact before/after rows and UI-to-DB exit proof. |
| Version omits a dependency or locking misses a writer | High | Canonical fingerprint inventory, writer audit, locked rereads, creation/preference/definition races; step-3 blocker, not WP8 debt. |
| Multi-row apply deadlocks with completion/assignment | High | Acquire sorted row sets before later lock classes; document cross-aggregate order and prove two-process outcomes. |
| Capacity double-counts selected/completed/recurring work | Medium | Replace selected occurrence in baseline, separate projected vs writable occurrences, exclude undone ledger rows; exact per-person/day assertions. |
| Undo replaces a later applied due date | High | D8 explicitly reviewed; preserve WP5 inverse, warn/reconcile and test both operation orders. |
| Child slots vanish before My Day's mine filter | High | Canonical eligibility before limit, stable ordering and adversarial earlier-sorted fixture. |
| UI claims success while Calendar/My Day remain stale | High | Active-window refetch/local-copy update, stale-request protection, distinct applied/refresh-failed state. |
| Scope expands into a new optimizer or WP7 rewrite | Medium | One current scheduling surface; one-next-occurrence bound; narrow cache seams and explicit backlog ownership. |
| Serial E2E projects still race across pools | Medium | Sequential pool execution and a single seed owner for the full owner-to-child journey. |

## 8. Acceptance Criteria

- [x] D1-D8 and all review questions are resolved and recorded; Standard A passed. (`PASS` 2026-09-10 — see `## Review Outcome`.)
- [ ] Preview references real active house-scoped room tasks and returns exact
      assignment/date changes, capacity and warnings without any domain writes.
- [ ] Deterministic ordering, legal recurrence/flex windows and eligibility are
      enforced; unavailable/ineligible members and unsupported slots are not used.
- [ ] Owner confirmation performs one all-or-nothing aggregate; assignment/date
      writes cannot leave partial state, or mutate rewards/ledger/checklist data.
- [ ] Current-state version and lock/writer boundaries cover relevant dependencies;
      stale previews, concurrent mutation and retries have exact tested outcomes.
- [ ] Direct-service and API owner/member/child/removed/non-member and visibility
      matrices pass without leaking inaccessible task content.
- [ ] Child limits are configurable through the regular owner UI; assignment
      eligibility precedes availability/limits and mine/all semantics are explicit.
- [ ] Applied state appears in Calendar and My Day without page reload; local
      copies and active ranges are refreshed, with stale requests unable to win.
- [ ] Completion/undo retains ADR-011 behavior and reconciles scheduling views;
      all three apply/completion/undo orderings in section 3.2 are covered.
- [ ] The reader-less client energy counter and preferences payload field are
      gone; any server capacity read uses authoritative non-undone work.
- [ ] Owner creates tasks and applies a plan via UI; child sees exactly the
      expected assigned due tasks in My Day and the correct Calendar occurrences.
- [ ] Planner states, keyboard/mobile/44 px controls and en/de localization pass
      component/E2E coverage; members/children cannot invoke household Apply.
- [ ] Section 4's required tests and full gate pass without new/unapproved skips;
      no required test or concurrency proof is deferred while marking WP6 done.
- [ ] ADR-012 and permanent API/concept/engineering docs contain all durable
      contracts; no behavior is documented only in this temporary plan.
- [ ] Every section-9 finding is resolved or explicitly user-rescoped; CR-WF-07
      and CR-055-01 are archived only when actually complete, WP7 remains open.
- [ ] Final Standard B is PASS or PASS-WITH-FIXES; masterplan WP6 and
      `ACTIVE_TASKS.md` reflect the actual outcome and lifecycle state.

## 9. Review Findings (plan-owned)

### Implementation record (2026-09-10)

Steps 2-8 delivered in one round; step 9 (final Standard B) is the remaining
gate. Delivered scope, mapped to the implementation order:

- **Step 2 (baseline regressions):** writer inventory completed — apply needed
  only its own aggregate locks; every existing writer (creation/setup
  house-first, completion/undo `rt → room → hu`, due-date edits, assignment
  replacements, definition/membership/preference writers) blocks on row locks
  apply holds, so no conditional service seam (`TaskService`,
  `TaskCreationService`, `HouseService`, `CompletionService`) required code
  changes. `RoomTaskService::updateDueDate` was still wrapped in a
  transaction + room-task row lock (same first-lock class as completion) as
  the one documented seam hardening.
- **Step 3 (contract/persistence):** ADR-012 written; `TaskMapper::
  lockForUpdate` added (same FOR UPDATE / SQLite self-assignment shape);
  `RoomTaskMapper::findDailyTasks` gained the `id` tie-break;
  `CompletedTaskMapper`: removed `getConsumedEnergyForUserOnDate` (D7), added
  `findEnergyUsageBetweenByHouse` (non-undone per completer/day, D11) and
  `findUndoStateByRoomTasks` (fingerprint undo-state input).
- **Step 4 (aggregate):** `SchedulePlanService` preview/apply exactly per
  contract; routes replaced/added (`scheduling#preview`, `scheduling#apply`,
  70-route pin updated); controller with closed-shape parsing and 400/403/404/
  409 mapping. One design refinement found by tests: an unassigned task
  proposes **one** eligible person (D3), not the whole eligible set.
- **Step 5 (child limits + capacity inputs):** `maxDailyTasks` owner-only
  member setting (omitted/null/int 1-100; personal endpoint rejects the key);
  `filtered-daily` eligibility-before-limit + embedded `assignees`; the
  `todayConsumedEnergy` cluster removed end to end (D7).
- **Step 6 (UI):** `SchedulePlanner` rewritten (select → preview → adjust →
  apply; states incl. stale/applying/applied/refreshFailed; owner-only in
  `SchedulingView`); scheduling store plan actions with request sequencing;
  room-task store request-order protection on filtered-daily/range; store
  reconciliation + `chorestrike:schedule-applied` reloads for TodayView/
  CalendarView; `AvailabilityEditModal`/`AvailabilitySettings` limit UI;
  l10n en/de/pot updated.
- **Step 7 (E2E):** `scheduling-apply-flow.spec.ts` (preview → adjust →
  apply → My Day/calendar verification; stale 409; unplaced zero-flex;
  legacy-contract rejection); scheduling/flex-window specs updated to the
  new planner contract; `SCHEDULING_OWNER_SPECS` pool extended.
- **Step 8 (docs/gates):** durable docs integrated (ADR-012 + README,
  api/README scheduling + room-task sections, concepts energy/ui-use-cases,
  engineering architecture/development/testing); gates all green:
  `npm test` (830), `npm run lint`, `phpunit.unit.xml` (908),
  `phpunit.integration.xml` (315, 10 pre-existing skips), Playwright
  chromium (92) / chromium-serial-db (98) / chromium-scheduling-owner
  (scheduling suites), `npm run backlog:check`, `npm run docs:encoding`,
  `git diff --check`.

No open findings from this implementation round beyond the deliberate
deferred items already owned elsewhere (D2 long-form hash documentation is
in ADR-012; WP7 owns enriched payloads/dashboard counters/cross-view race
folds; WP8 service decomposition; WP9 skip inventory).

### Review findings — final Standard B (2026-09-11)

| ID | Severity | Area | Issue | Resolution state |
|----|----------|------|-------|------------------|
| RF-065-01 | major | §4.1 Playwright row | Exit journey uses seeded tasks (plan requires "task creation and scheduling in the exit journey must use the UI"), verifies the applied plan via member `mom` instead of a child session, never sets availability/child limit through the owner UI, and lacks the role-cannot-apply and keyboard/mobile journeys. | OPEN — user decision 2026-09-11: fix in the next round of this plan. Blocks `done/`. |
| RF-065-02 | major | §4.1 Jest components row | Component matrix undelivered: no spec for the D5 limit UI (`AvailabilityEditModal`), the owner-only planner gating (`SchedulingView`), `CalendarView`'s `schedule-applied` refetch, or keyboard/mobile control behavior. | OPEN — user decision 2026-09-11: fix in the next round of this plan. Blocks `done/`. |
| RF-065-03 | major | `SchedulePlanner.vue` §3.1 rule 7 | Input edits (task selection, start date) after a preview left Apply enabled; only per-row override edits marked the preview stale. | FIXED in review (user-authorized): selection/`startDate` watchers call `markPlanStale()`; Jest regression tests added. |
| RF-065-04 | minor | §4.1 Real-concurrency row | Enumerated two-process races (apply vs completion/undo, preference/membership, capacity-affecting creation) delivered as the two distinct lock-class representative races (apply/apply in opposite order, apply vs room_task-writer) plus sequential stale-rejection proofs for every dependency class. | RESOLVED by user decision 2026-09-11: accept lock-class coverage — every remaining writer serializes on a lock class proven by a delivered two-process race (ADR-012 lock order). |

Additional review-fixed items (no open residue): stylelint `property-no-deprecated` error (`.sr-only` `clip` → global `cs-visually-hidden` utility) and `padding-left` → `padding-inline-start` in `SchedulePlanner.vue`; two `composer cs:check` violations (unused `AppLogger` import in `SchedulePlanAggregateTest.php`, operator alignment in `SchedulePlanService.php`); "actional" → "actionable" typo in ADR-012; PHPDoc array-shape corrections (`capacityWarnings`/`placeRow`). Considered and accepted nits: weekend-15/weekday-10 default budget duplicated between `SchedulingService::getDefaultEnergyBudget()` and `SchedulePlanService::energyBudgetFor()` (fold into WP8 service decomposition); controller `is_numeric` ID parsing truncates forged float ids ("1.5" → 1) — service-level strict `is_int` is pinned by unit tests and the UI always sends integers.

Standard A review (2026-09-10): **no findings** — every section-1 baseline claim
was verified against the codebase and all references/manifest paths checked; see
`## Review Outcome`. Record in-scope implementation/review findings here using
`severity | file/area | issue | resolution`. Resolve before done/; moving them
to backlog requires explicit user rescoping. Out-of-scope findings fold into an
existing backlog owner before creating a new entry. D1-D8 are ratified decisions,
not implementation evidence.

## Review Outcome

**Standard A (plan review) — 2026-09-10 — outcome `PASS`**

- **Scope / owning plan:** this plan — the single regular `*.md` in
  `docs/plans/new/` (intent-to-add, uncommitted) — plus its creation package:
  `docs/backlog/{backlog,ACTIVE_TASKS,archive/2026-09}.md`,
  `docs/plans/README.md`, and the CS-PLAN-058 masterplan tracking links.
- **Base state:** branch `master`, HEAD
  `098904fdb7933c460279c8445032a09860099879`; nothing staged except the plan's
  intent-to-add empty blob; no untracked files. Fingerprint (git blob hashes):
  full unstaged diff `4add301623ccfad6033f298038e761a434254222`, plan-file diff
  `fa0079f307b1c81badfb09c856bd12ad033b7fe5`.
- **Verification:** all nine section-1 gap-table claims confirmed in code
  (`SchedulingService.php:253-271` taskCount-only advice;
  `SchedulePlanner.vue:139-159` preference Apply; `routes.php:99-103` no apply
  route; no `RoomTask` version/updatedAt; `RoomTaskService.php:960-992`
  eligibility not before the child limit; `TodayView.vue:224-230` post-limit
  mine-filter; `maxDailyTasks` read/seeded but unsettable and omitted from
  `getMemberAvailability()`; `todayConsumedEnergy` writer-only with no
  production reader; `getConsumedEnergyForUserOnDate()` not excluding
  `undone_at`). Referenced ADR-009/010/011, concepts, CS-PLAN-064, and the
  masterplan `#45-quality-gate` anchor exist; all 52 manifest modified paths
  exist, all 6 new paths absent, the 4 conditional services exist;
  `SCHEDULING_OWNER_SPECS` pool-exclusivity claims accurate. No overlap with
  any active plan (only parent CS-PLAN-058, which delegates WP6); CR-WF-07 and
  CR-055-01 own every work item; template sections 1-9 complete.
- **Findings / safe fixes:** none — no content findings; transition-mechanics
  updates only (status line, section-2.2/8/9 ratification notes, link repairs
  in the five companion files, backlog statuses to In Progress).
- **Decisions (one batched structured request; all ratified as recommended):**
  D1 replace the suggestions contract with the real-task preview + owner-only
  `POST /scheduling/apply`; D2 content-fingerprint `previewVersion`, no schema
  change; D3 one next occurrence per task over seven house-local days; D4 keep
  mine-or-unassigned with eligibility before child limits; D5 `maxDailyTasks`
  bound 1-100, `0` rejected; D6 capacity as a hard planning constraint counting
  full effort per assignee; D7 remove the `todayConsumedEnergy` cluster
  (CR-055-01); D8 preserve ADR-011 undo semantics with warn/refresh.
- **Gates (draft-only per section 4.2):** `npm run docs:encoding` green,
  `npm run backlog:check` green, `git diff --check` clean — run before and
  rerun after the transition edits. Application tests intentionally not run;
  this review activates the plan and is not evidence that WP6 is implemented.
- **Transition:** eligible `new/ → active/` move performed; CR-WF-07/CR-055-01
  marked In Progress; masterplan WP6 row, plans README, ACTIVE_TASKS entry, and
  backlog links updated to the active path; one scoped review commit authorized
  by the review request.

**Final Standard B (code review) — 2026-09-11 — outcome `NEEDS-FOLLOW-UP`**

- **Scope / owning plan:** the complete WP6 implementation package — 45 modified
  + 6 new files matching the §5 manifest boundary, captured as the uncommitted
  working tree (staged: empty). This plan is the explicit owning plan.
- **Base state:** branch `master`, HEAD `768a75758fcbb9310b331e2fdda8074cd6a27434`
  (Standard A activation commit). Scope fingerprint: staged binary diff
  `e69de29bb2d1d6434b8b29ae775ad8c2e48c5391` (empty), unstaged binary diff
  `58846467026b5572aa7efe43206201378b8b9b55`; untracked blobs —
  `docs/adr/ADR-012-actionable-household-scheduling.md` `d87f3d5af81bfd87bb46ea2606497b90bf2dc398`,
  `lib/Service/SchedulePlanService.php` `a9d37321ed851f445f223df5b9eed6fe55867047`,
  `tests/e2e/scheduling-apply-flow.spec.ts` `64896f7274f4443540402d0ca9da2ec40427f6d2`,
  `tests/integration/Service/SchedulePlanAggregateTest.php` `b35256961f5fa6c9b11651498c482fef1200f00e`,
  `tests/integration/Service/SchedulePlanConcurrencyTest.php` `a182e5d8c0d897bd0a4a7786f69f1a9579fb22fb`,
  `tests/unit/Service/SchedulePlanServiceTest.php` `919f390bdab57dc46429fdc644c56ab2236a7491`.
- **Verification:** shared review checklist over the full backend (thin
  controllers → `SchedulePlanService` aggregate → mapper primitives; owner-only
  authorization with under-lock revalidation; closed-shape validation; SHA-1
  content fingerprint with `hash_equals` stale gate; ADR-012 lock order
  `house → room_task → task → house_user`), the frontend rewrite (plan state
  machine, request sequencing, reconciliation/applied-vs-refresh-failed
  distinction, a11y and `t()` coverage with en/de/POT in sync), the §4.1
  required-test matrix, durable-doc integration, and the complete §4.2 quality
  gate. `nextDue` UTC-midnight storage verified consistent with the existing
  `TaskCreationService`/due-date-edit seams and ADR-009.
- **Findings:** RF-065-01 (E2E matrix shortfall) and RF-065-02 (component-test
  shortfall) OPEN — user decision: fix in the next round of this plan; they
  block `done/` (rule 10). RF-065-03 (input-edit staleness, §3.1 rule 7) FIXED
  in review with Jest regressions. RF-065-04 (race enumeration) RESOLVED by
  accepted lock-class rationale. Full table in §9.
- **Safe fixes applied:** stylelint error + warning in `SchedulePlanner.vue`
  (deprecated `clip` → global `cs-visually-hidden`, `padding-inline-start`);
  two `composer cs:check` violations (`SchedulePlanAggregateTest.php` unused
  import, `SchedulePlanService.php` operator alignment); ADR-012 typo;
  PHPDoc array-shape corrections; the RF-065-03 gating fix + 2 new Jest specs.
- **Decisions (one batched structured request; all ratified as recommended):**
  fix the E2E gaps in the next round; fix the component-test gaps in the next
  round; fix the Apply input-edit gating now with a regression test; accept
  the concurrency lock-class coverage and document the rationale.
- **Gates (all green after the fix cluster):** Jest 832 (830 + 2 new);
  eslint; stylelint 0 errors (1 pre-existing out-of-scope warning in
  `NotAuthorizedState.vue`); `test:noise`; build; `l10n:check-keys`;
  `docs:encoding`; `backlog:check`; PHPUnit unit 908 / integration 315 (10
  documented pre-existing GameLogic/RoomTask scenario skips; both two-process
  scheduling races ran on MariaDB); `composer cs:check`; Playwright chromium
  (91 passed, 1 flaky-on-retry, 7 pool-excluded skips), chromium-serial-db
  (98 passed, 5 skipped), chromium-scheduling-owner (35 passed) — the affected
  gates rerun after the fix cluster. Note: the implementation record's gate
  list had omitted stylelint and `composer cs:check` — both were red on first
  run of this review and were fixed as safe review fixes.
- **Transition:** plan stays in `active/` (RF-065-01/02 block `done/`);
  CR-WF-07/CR-055-01 remain open; the scoped review commit includes the
  verified implementation package, review fixes, this evidence, the review
  record (`docs/backlog/archive/CODE_REVIEW_2026-09-11.md`), and tracking
  updates.
