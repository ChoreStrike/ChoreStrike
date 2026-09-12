# CS-PLAN-067: WP8 - Batching, Decomposition, and Performance Hardening

> **Status:** Active (drafted 2026-09-12; Standard A `PASS` 2026-09-12, D1-D6 ratified as recommended; implementation not started)
> **Effort:** XL (one sequential package with reviewable slices)
> **Priority:** P2
> **Depends on:** [CS-PLAN-058](../active/cs-plan-058-core-workflow-bottom-up-masterplan.md) WP0-WP7 complete; [CS-PLAN-066](../done/cs-plan-066-wp7-canonical-client-state-family-ux.md) final Standard B `PASS-WITH-FIXES`
> **Related backlog items:** CR-WF-06 (umbrella); CR-SVC-15/40/41/44 (structural remainders); CR-053-19 (remainder classification only)
> **Parent:** CS-PLAN-058 WP8; WP9 consumes the behavior-identical, budget-pinned workflow.

## 1. Problem Summary

WP1-WP7 established canonical persistence, authorization, atomic mutations,
scheduling, and client-state reconciliation. WP8 must reduce orchestration size
and redundant reads without reopening those contracts. Moving an N+1 loop from
Vue to PHP, or splitting a transaction into smaller transactions, is not success.

The planning baseline is the post-WP7 tree at `1dc06a5` (2026-09-12). Historical
backlog descriptions are inputs to verify, not proof that every named method or
defect still exists. In particular, WP5 already extracted completion/undo into
`CompletionService`, WP6/WP7 enriched execution rows with canonical assignees,
WP1 introduced `TransactionExecutor`, and WP3 replaced `hasFloors` hydration with
a count query. Those fixes must be retained rather than implemented again.

Verified post-WP7 inventory:

| Surface | Current evidence and WP8 delta |
|---------|-------------------------------|
| `RoomTaskController::index` | Requires `roomId` or `scopeId`; a house-wide list is not yet supported. Serialization already adds batched assignee arrays. Extend the scope, not the row format. |
| `RoomTaskService` | Daily and due/overdue row assembly duplicates batched task/room enrichment. `updateDueDate` retains an after-transaction re-fetch. Extract the cohesive read/enrichment cluster and reuse it. |
| `CompletionService` | Complete and undo still contain long inline phases, although helpers for snapshots and notification dispatch already exist. Preserve the WP5 aggregate; extract from this current shape, not the deleted `RoomService::completeRoomTask`. |
| `GameLogicService` | Five repeated medal-award blocks; completion still supports a self-inserting ledger branch used only by tests. The production caller always supplies the WP5 ledger row. |
| DI and transaction debt | No backend global service-locator site remains; only `TestDataService` retains the documented service-level DB exemption. `getFilteredDailyTasksForUser` no longer takes a mapper parameter and WP4's ownership delegation already exists. Verify-and-close these historical claims rather than invent replacement abstractions. |
| `StreakFreezeService` / `StreakRestockService` | Reads are already batched; per-user writes preserve failure isolation. A wholesale bulk UPDATE is not behavior-equivalent. D5 resolves the ownership seam before implementation. |
| `TaskManager::initializeTasks` / `SchedulingView` | Both still loop over rooms and call `fetchRoomTasks(room.id)`. Replace both consumers with the same house-wide action. |
| `TodayView::load` | Already uses filtered-daily, overdue and due-today enriched reads, with zero per-task assignee calls. `roomTasks.fetchAssignees` is unused by production consumers. |
| Pinia dependencies | Already a DAG: `roomTasks -> house/game/rooms/tasks/user/minigame/titles`; `scheduling -> house/roomTasks/tasks`; no back-edges. No live global service-locator site was found in the frontend either. |
| `TaskList` / shared components | Large composition root; existing `AssigneeChips`, `TaskChecklist`, `FlexWindowDisplay` and dialogs are reuse candidates. Component extraction must preserve optimistic rollback and member-read deduplication. |
| Create/edit refresh paths | TaskManager's `refreshAfterCreate` and RoomDetail's add/template/save handlers re-fetch after store mutations. Aggregate-versus-list field equivalence still needs a test before deleting those reads. |

No request or SQL counts have been measured for this draft. Section 4 defines
the measurement boundary and regression targets; implementation slice 1 must
record actual baseline counts before any performance change.

## 2. Strategy

Characterize behavior and cost first, replace redundant reads second, and extract
cohesive responsibilities third. Keep existing Nextcloud APIs, Vue surfaces,
Pinia stores, and mapper primitives wherever possible. Every slice keeps a green
targeted behavior gate; the final gate includes the whole WP7 journey.

### 2.1 Ownership and non-goals

| Work | Owner / disposition |
|------|---------------------|
| House-scoped workflow reads, TaskList decomposition, request/query budgets | CR-WF-06 / this plan |
| Completion and GameLogic structural decomposition | CR-SVC-15 / this plan; reward calculations and effect-record semantics remain frozen |
| Transaction/lock persistence boundary | CR-SVC-40 / this plan; retain `TransactionExecutor`, no competing transaction manager |
| Service-locator removal, redundant DI parameters and ownership/stat helper duplication | CR-SVC-41 / this plan; only currently live sites |
| Redundant workflow re-fetches | CR-SVC-44 / this plan; record removed historical sites as evidence |
| Streak job per-user updates and broad service RMW remainder | D5 ratified 2026-09-12: cron freeze/restock batching is rescoped into the existing CR-053-11 effort; CR-053-19 stays remainder-classification-only here; do not silently close CR-SVC-44 or CR-053-19 |
| Global E2E/skip cleanup, canonical long journey replacement, release execution | WP9 / RELEASE-002; not moved into WP8, but WP8's own required tests cannot wait for WP9 |

No new optimizer, reward policy, task visibility rule, membership behavior,
schema migration, compatibility shim, cache service, external profiler, or
generic repository/state framework is proposed. Manual cleanliness adjustment
(CR-SVC-63) and independent streak correctness (CR-053-11) are not implicitly
adopted by moving adjacent code. Any adoption needs a recorded scope decision,
its existing backlog ID, and tests before that slice starts.

### 2.2 Proposed Standard A decisions

All decisions below were **proposals at draft time**. The Standard A review
(2026-09-12) batched them through the structured question mechanism and the
user **ratified D1-D6 as recommended**; the table below is the ratified
decision record (see §11 for the review outcome).

| ID | Recommended decision | Alternative / boundary |
|----|----------------------|------------------------|
| D1 | Extend `GET /room-tasks` so omitted `roomId` and `scopeId` select the current house, retaining `activeOnly=true` by default and the existing flat instance-plus-assignees response; consume WP7 execution-row assignees elsewhere. | Add a separate route only if the dispatcher/consumer inventory proves reuse unsafe. Supplied invalid selectors must never fall back to a broader house read. |
| D2 | Keep `CompletionService` as the visible complete/undo aggregate owner; extract effect-state, reward/medal, and post-commit responsibilities without moving the transaction boundary. | Keep a cohesive larger orchestrator with a specific documented size exception rather than inventing a generic phase engine. Never split into independently committing services. |
| D3 | Preserve and regression-pin the existing store DAG and `roomTasks` reconciliation owner; remove only proven redundant view-owned refreshes. No new workflow store/composable is needed for the current tree. | A newly demonstrated cycle or missing responsibility must be named and reviewed before changing ownership; do not create a coordinator to satisfy a historical claim with no live target. |
| D4 | Use deterministic request counts and real executed-query counts; freeze numeric SQL ceilings from a baseline-only slice before optimizing. | Wall-clock/latency measurements may supplement counts but cannot replace deterministic acceptance gates or require an external service. |
| D5 | Explicitly rescope cron freeze/restock batching from WP8 into the existing CR-053-11 effort, preserving per-user failure isolation. Inventory CR-053-19's remaining writers and fold any duplicate manual-cleanliness/streak claims into existing CR-SVC-63/CR-053-11, with provenance. | Adopt a precisely enumerated correctness prerequisite into WP8, retaining existing IDs and adding locking/idempotency/concurrency tests. An unclassified remainder or a still-required deferred WP8 criterion blocks completion. |
| D6 | Apply the size guideline through cohesive extraction plus a named, justified exception inventory, not a repository-wide line-count rewrite. | Split further where cohesion permits; every unexplained production file above the guideline blocks the parent WP8 exit gate. |

### 2.3 Frozen boundaries

- [ADR-009](../../adr/ADR-009-per-house-timezone.md): house-local dates,
  deterministic ordering and half-open date windows.
- [ADR-010](../../adr/ADR-010-invite-only-membership-visibility-matrix.md):
  invite-only membership; private creator plus explicit assignees; owners do not
  automatically see private instances; visibility 404 versus role 403.
- [ADR-011](../../adr/ADR-011-transactional-completion-and-undo.md): one complete
  or undo transaction, fresh locked entities, `room_task -> room -> house_user`,
  no house/task-definition lock added to completion, exact recorded inverse,
  actor-versus-completer identity, post-commit degradation and non-retractions.
- [ADR-012](../../adr/ADR-012-actionable-household-scheduling.md): preview is
  read-only; apply revalidates the fingerprint under the existing lock order;
  capacity-relevant rows cannot be omitted to meet a budget; child eligibility
  precedes availability and limits.
- WP7 API envelopes, assignee user-ID arrays, definition/instance ownership,
  request generations, reset/denial safety, hydration silence, event ordering,
  read-only refresh retry and owner/member/child accessibility remain unchanged.

## 3. Backend / Frontend Changes

### 3.1 House-scoped reads and enrichment

Use the existing list/read stack, with service-owned membership and per-instance
visibility checks before serialization. Resolve the current house server-side;
never trust a browser-supplied house ID or use a definition-level permission to
expose all instances of a private task.

Load visible room tasks, definitions, room metadata and canonical assignee sets
in bounded batches. Reuse the current mapper batch methods and WP7 execution-row
enrichment; keep list and execution-row envelopes distinct where their existing
consumers differ. Avoid one definition, ownership-chain or assignee lookup per
row, including duplicate enrichment inside policy filtering. Empty ID sets issue
no enrichment query; use portable named array parameters and bounded chunks.

Add a live-consumer-backed `RoomTaskMapper::findByHouse(houseId, activeOnly)`
using the existing house-scope join; keep inactive rows available when explicitly
requested. Put house/room/execution read assembly in a focused
`RoomTaskReadService`, reusing `TaskMapper::findByIds`, `RoomMapper::findByIds`,
`TaskAssigneeMapper::findUserIdsByRoomTasks` and `TaskAccessPolicy`. Fold duplicated
`getDailyTasksWithDetails` / `rowsForRoomTasks` assembly into that owner. Update
internal callers directly; do not leave unused forwarding methods as shims.

The house mode returns the existing flat `RoomTask::jsonSerialize(timezone)`
fields plus `assignees: string[]`, not `{roomTask, task, room, assignees}` wrappers.
Daily/due/overdue keep those existing enriched wrappers. Retain selector
precedence and distinguish the existing modes: a foreign `roomId` is a 404;
`scopeId` intersects its rows with the current house and visibility, returning
200 with the local subset or an empty list. Neither supplied selector may fall
back to the house-wide mode. Any newly required malformed-selector validation
is an explicit D1 contract addition, not claimed as already implemented.

TaskManager loads one complete house-scoped instance projection rather than
requesting every room in a loop. SchedulingView uses the same batch when loading
planning candidates; its preview/apply algorithm is unchanged. RoomDetail retains
its scoped room load; a room load must not replace a household projection or
supply global counts. My Day
consumes authoritative enriched assignees and the server-filtered task order.
Do not add an assignee batch endpoint when the rows already contain the data.

Add `roomTasks.fetchHouseRoomTasks` with explicit house-wide loaded/stale and
generation metadata over the existing instance collection, not a duplicate
cache. A full-house result replaces that house's instance set; a room result
updates only its room. Older overlapping house/room reads cannot undo a newer
accepted result, while independent sibling room reads remain valid. Preserve
mutation/reset invalidation and the shared pending-read/loading counter.

Preserve active/inactive and filter semantics. Do not impose an undisclosed page
limit or drop rows to satisfy budgets; pagination needs a separate reviewed UI
contract if the measured household workload actually requires it.

### 3.2 Completion, undo and reward decomposition

`CompletionService::complete` and `undo` retain their public service entry points
and one `TransactionExecutor`-managed transaction each, including the current
begin/commit/rollback and rethrow behavior. Make the aggregate's order readable:
resolve/authorize, acquire fresh locks, revalidate occurrence/actor, capture or
load effects, apply/restore domain state, persist ledger/checklist state, commit,
then run the guarded post-commit phase and shape the existing response.

Extract only cohesive private responsibilities with explicit inputs/outputs:
effect-record snapshot/restore and serialization; reward/medal evaluation;
post-commit weekly-goal/notification result enrichment. Prefer existing helpers
before adding services. Extracted participants share the same connection and
never open, commit or roll back their own nested transaction.

Proposed collaborators are `CompletionEffectService` for the snapshot/restore
pair and `CompletionPostCommitService` for the already separated dispatch and
degradation work. Keep lock acquisition, occurrence/actor gates and transaction
lifetime visibly in `CompletionService`; payload builders may stay private.

Preserve the exact `before_state`/`deltas` keys, all recurrence/claim/checklist
effects, LIFO/idempotent undo and the recorded `titlesAdded` inverse. Medals,
the weekly-goal notification claim and dispatched notifications remain the
documented non-retractions; titles are not a blanket exception. Undo restores
recorded state rather than recalculating current policy. Post-commit failure
still returns committed success with the same degradation metadata and stable
sanitized log event; no notification dispatch moves under a lock.

For `GameLogicService`, separate cohesive completion-reward orchestration and
weekly medal evaluation. Replace repeated award blocks with one explicit rule
table only if the existing predicate/value/order and race-safe award behavior
remain identical. Do not revive deleted service classes or reimplement formulas
from historical concept examples. Pin current executable outputs before moving
code, including rounding, combo, freeze, teamwork and title edge cases.

Extract the coherent weekly medal cluster into `MedalService` and update
`GameController`/`GameLogicService` callers; do not move its current read-triggered
award/notification timing into completion. Keep pure coin helpers together unless
there is an actual reusable boundary. Make the caller-owned ledger row required
in `handleTaskCompletion`, delete the test-only self-inserting branch, and update
unit/FamilyIntegration fixtures to the real aggregate contract. There must be
exactly one ledger insert with a complete effect record per completion.

### 3.3 DI, mapper boundaries and redundant reads

Replace any live global service lookup with constructor injection through the
existing Nextcloud container. Remove redundant method-injected mappers already
owned by the constructor, updating controllers, service callers and test builders
together. Reuse the existing ownership walk/stat calculation where it is genuinely
the same policy; do not conflate visibility, ownership and role checks.

Keep SQL, engine-specific locking and transaction execution in `lib/Db`.
Services decide *which* aggregate and locks are needed and in what order; a new
repository must not conceal that order. Preserve the documented `TestDataService`
test-tooling exemption. Remove an after-write re-fetch only when the returned
entity is authoritative and tests prove response equality; a fresh under-lock
re-read is not redundant.

The concrete remaining workflow candidate is `RoomTaskService::updateDueDate`:
return the entity produced by the transaction rather than fetching it again
after commit. Keep the pre-lock ownership resolution and authoritative lock read
unless a separate proof shows that one can be removed without weakening policy.

Inventory historical deleted mutators and already-fixed sites in the owning
backlog evidence. Do not resurrect them to satisfy an old checklist. D5's cron
and RMW disposition is recorded separately from this behavior-preserving cleanup.

### 3.4 Focused task components and one-way stores

Keep `TaskList` as list/selection, expansion and claim-dialog orchestration;
retain its one owner for lazy member loading. The proposed extraction is:

| Unit | Responsibility |
|------|----------------|
| `TaskListItem.vue` | Stable row identity, status/content, permitted controls, complete/claim/edit/delete intents and row accessibility |
| `TaskListBulkBar.vue` | Selection summary and permitted bulk intents; retain partial-success feedback through the existing mutation owner |
| `TaskListDetails.vue` | Expanded assignment/checklist composition; reuse `TaskChecklist` and emit definition/progress intents without duplicating policy |
| Existing `AssigneeChips.vue` | Reusable assignment control, after pinning existing optimistic rollback and interaction equivalence; no new eager member fetch per expanded row |
| `src/utils/taskDisplay.js` | Pure display helpers for priority/frequency/due/flex labels with explicit timezone input; reuse `dateKeys` and `FlexWindowDisplay`, never recalculate recurrence or eligibility |

Presentational children receive values and emit intents; domain writes and
response reconciliation remain in the established stores. For AssigneeChips
reuse, preserve TaskList's label/hint surface and parent-owned member loading;
test existing form/modal consumers as well as rollback on a failed assignment.
Do not replace the inline picker solely on visual similarity. If extraction
reveals missing checklist request invalidation on reset/disposal, fix only that
scoped lifecycle seam with a regression rather than adding a generic loader.

Preserve row identity, filters, selection clearing, partial bulk success,
overdue-claim confirmation, edit/delete/due controls, instructions, lazy checklist
discovery and completion/undo feedback. Moving styles must preserve scoped CSS,
accessible names, keyboard/focus behavior, live announcements, mobile hit areas,
test selectors and translated copy. Checklist expansion may load its own data;
closed rows must not introduce another eager request-per-task pattern.

The existing store graph is acyclic and stays that way. Pin static/lazy imports
with a focused dependency regression; do not hide a future back-edge behind
`require()` or dynamic imports. Keep definitions in `tasks`, instances and their
projections/reconciliation in `roomTasks`, and role/member state in `user`. No
new coordinator, duplicate cache, or browser-side arithmetic for rewards,
health, capacity or daily limits is required.

Characterize aggregate/list field equality before removing TaskManager's
`refreshAfterCreate` or RoomDetail's add/template/save re-fetches. Remove a read
only when response reconciliation already supplies every required field;
otherwise retain one authoritative refresh for the missing projection. Remove
the unused frontend `fetchAssignees` action and any now-unused request-sequence
state; pruning its documented backend GET route is not part of default D1.

One accepted mutation reconciles response-owned fields before emitting its
existing events, invalidates older generations and refreshes each loaded derived
projection at most once. A failed refresh preserves the committed receipt and
undo entry; retry repeats reads, never completion/undo/create/apply. Scoped and
house-wide requests must coexist without stale success/error/finally overwrites.

### 3.5 Size and durable documentation

Before extraction, inventory production files in `lib/` and `src/` above roughly
300 lines and touched methods above roughly 30 lines. Reduce the named hotspots;
record remaining exceptions individually with their cohesive responsibility,
reason, and owning backlog/plan if more work is needed. A blanket "pre-existing"
exemption does not satisfy the parent exit gate. Static registries and test-only
tooling are classified explicitly; do not split data tables merely to meet a
physical-line target. Any required out-of-scope rewrite needs D6 rescoping, not
an unreviewed expansion.

Initial named exception candidates are `SchedulePlanService` (WP6's cohesive
fingerprint/capacity aggregate; budgets only, no optimizer rewrite),
`RewardConfigService` (the already accepted static configuration registry), and
`RoomTaskController` (thin route/envelope methods, assessed independently from
service orchestration). These are review candidates, not automatic exceptions;
the baseline inventory must identify every other oversized production file.

Integrate batch contracts into `docs/api/README.md`, aggregate/component/store
boundaries and justified size exceptions into `docs/engineering/architecture.md`,
lock plumbing into `docs/engineering/development.md`, and budgets/fixtures/count
methodology into `docs/engineering/testing.md`. Correct stale descriptions of
the touched paths instead of appending contradictory new paragraphs. Existing
ADRs remain authoritative; an actual decision change needs an explicit ADR and
manifest update before proceeding.

## 4. Tests

### 4.1 Deterministic performance budgets

The first implementation slice adds baseline measurement without changing
production behavior. Use fixed house-local time, dedicated fixtures and the
canonical Docker database. Compare a small workload (2 rooms / 10 instances) to
a larger household (10 rooms / 100 instances), with mixed visibility and known
assignees; scale room, instance and member cardinalities separately when isolating
a fan-out. Include empty, single-row and batch-chunk-boundary cases.

Use uncapped owner/member reads for cardinality-slope assertions and verify that
the visible result really grows; test child limits separately against exact
expected IDs. A constant-size capped result is not proof of batched full reads.

Count actual ChoreStrike `/api/v1` requests, grouped by endpoint/method/purpose,
not static resources or unrelated Nextcloud bootstrap traffic. Install counters
before navigation/action; count until the asserted UI result and all owned
refreshes settle. Record cold navigation separately from warm refresh and user
expansion. An endpoint allowlist must enumerate all app reads for the scenario,
not hide regressions by counting only the optimized route.

| Workflow | Required request/query bound |
|----------|------------------------------|
| Setup | One structural apply request; invitations retain their separate per-recipient platform contract. Queries may scale with created rows/assignees, never repeated discovery of the same catalog or ownership chain. |
| TaskManager cold load | One house-wide room-task list request; zero per-room room-task requests and zero per-instance assignee requests. Definition/structure/member bootstrap requests have separate fixed ceilings. Batched read-query count is independent of row/room count within one chunk. |
| My Day load/filter/refresh | Preserve its three reads on a cold view load: filtered-daily, due-today and overdue, excluding separately counted shell bootstrap. At most one read per required projection/generation on refresh; zero per-instance assignee requests. Batched read-query count is independent of returned row count within one chunk. |
| Scheduling candidate load | One house-wide room-task read instead of N room reads; existing preference/definition/structure reads have separately pinned ceilings. No preview or apply is triggered implicitly. |
| Scheduling preview | One preview request, zero writes; retain all visibility, capacity and fingerprint inputs. Pin fixed/batched reads plus any justified per-member cost. |
| Scheduling apply | One apply request; one refresh per affected loaded projection. SQL may scale with every capacity-relevant locked row, definitions, members and changed assignee set, not just selected tasks. Preserve no-change zero domain writes and stale-409 rollback. |
| Completion | One completion request, one read per loaded derived projection; no row-assignee fan-out or duplicate listener refresh. Pin transactional queries separately from post-commit reads and the bounded reward/medal branches. |
| Undo | One undo request, no split uncomplete/reactivate chain, one read per loaded derived projection. Pin exact inverse queries including checklist restore rows; no-op undo performs zero domain writes. |

For real SQL, instrument the actual connection/query execution used by the
integration fixture, including raw prepared lock statements as well as query
builder execution. Prove the counter observes a known query through each path.
Use the supported Nextcloud/DBAL test seam discovered in slice 1; do not add a
production debug endpoint, change business logging or persist SQL/parameters.
Mapper-call mocks complement but do not replace executed-query evidence.
The read-only draft probe found bundled DBAL `3.10.4` and configured provider
`mysql`; verify the concrete supported counting hook against that stack rather
than assuming an arbitrary SQLLogger API is available or non-deprecated. Do not
suppress deprecations to make the instrumentation gate pass.

Pin provider/version and fixture setup outside the measurement window. Include
the controller's normal membership/ownership preamble in endpoint budgets;
separately labeled service-only measurements must not be presented as full HTTP
cost. Count lock SELECTs/self-assigning UPDATEs, but report transaction boundaries
separately from SQL statements. Do not assume SQLite and MariaDB/PostgreSQL have
identical lock-statement counts, and detach the counter after each dedicated test
so it cannot affect two-connection race tests.

Record literal baseline and target ceilings per workload in tests and durable
testing docs before optimizing. For batch reads require `Q(100) = Q(10)` within
one chunk; for writes record explicit fixed plus per-row bounds with named
dimensions (created rows, locked rows, assignment rows, restored checklist rows).
Separate lock statements, domain writes and post-commit queries so necessary
safety work is visible, not removed. Empty/no-op/denied/stale cases have their own
bounds. Count evidence must also assert exact result IDs/state, so dropping data
cannot make a performance test pass.

Never set a threshold from the current observed count at test runtime, use an
unexplained generous ceiling, or claim measured values in advance. Baseline
measurement tests pass unchanged; new anti-fan-out regressions must demonstrate
failure on the original code where the defect remains. Missing instrumentation
or unfrozen numeric ceilings blocks optimization and later completion.

### 4.2 Backend behavior and architecture matrix

- Batch reads: exact owner/member/child/private-creator/private-assignee sets,
  sibling private instances excluded, removed/non-member and foreign-house
  denial for room reads, scopeId intersection/empty success, deterministic
  ordering, empty sets, canonical user-ID arrays and no
  leaked relation metadata. Exercise the real HTTP dispatcher as well as service
  entry points; controller-only authorization is insufficient.
- DI: production container resolves changed services/controllers; unit builders
  use the same constructor contracts; no production service-locator fallback or
  additional transaction executor appears.
- Completion/undo: existing effect-record serialization, full real-DB state
  equality, recurrence permutations, checklist/claim restore, actor/completer
  differences, duplicate/inactive rejection, idempotent undo/recomplete and
  post-commit failure retain exact outcomes.
- Concurrency: rerun same-task, same-room, same-user, assignment/due/edit versus
  scheduling and simultaneous apply/undo coverage on real connections/processes.
  Inject failure after each extracted write phase and assert full rollback;
  verify notifications occur only after successful commit.
- Rewards: exact current rounding, tier/personal/early/combo/teamwork totals,
  freeze/title transitions and all five medal predicates, duplicate-award races,
  disabled configuration and timezone/week edges. No balance/policy redesign.
- Setup/scheduling: zero partial setup, under-lock validation, fingerprint drift,
  child eligibility before limits and apply-to-Calendar/My-Day remain green.

Existing required anchors include `CompletionServiceTest`, `GameLogicServiceTest`,
`RoomTaskServiceDailyTest`, `TaskAccessPolicyTest`, real-DB
`CompletionUndoStateEqualityTest`, `CompletionConcurrencyTest`,
`ConcurrentCompletionTest`, `SchedulePlanAggregateTest`,
`SchedulePlanConcurrencyTest`, `SetupApplyConcurrencyTest`, and
`Db/BatchQueryRegressionTest`. The last test proves batch semantics today, not
executed-query counts; the new `WorkflowQueryBudgetTest` supplies that evidence.

### 4.3 Jest and Playwright matrix

Jest pins API envelopes and request counts, household versus room projections,
canonical assignees, late-read/reset/denial/error/finally races, reverse-import
cycle absence, exact mutation/event ordering, partial bulk success and read-only
refresh retry. Extend existing TaskList/TaskManager/TodayView/store tests and add
focused tests only for new extracted units. The source-localization coverage
test must follow moved `t()` calls, not silently stop scanning extracted files.

Use real Pinia stores with the mocked API boundary in
`tests/unit/views/requestCounts.spec.js`; retain the shared axios mock for tests
that exercise `api.js` itself. A view mocked entirely at its store actions is not
proof of network count or reconciliation. Update TaskManager's existing
redundant-refetch expectation only after the field-equivalence regression passes.

Playwright preserves the WP7 owner/member/child desktop and mobile matrix:
TaskManager and RoomDetail filtering/assignment/bulk controls, My Day instructions
and checklist, completion/full undo/reload, calendar and schedule apply, and the
emptyhouse setup. Add deterministic request-budget cases at both household sizes
with real backend responses. Use interception only for explicit error/race tests,
not for successful batch/visibility proof. Assert loaded row IDs and UI outcome
alongside counts, including focused keyboard and accessible-name regressions
after component extraction.

Required existing E2E anchors are `family-core-ux.spec.ts`,
`workflow-state-reconciliation.spec.ts`, `today-view.spec.ts`,
`task-completion-full-flow.spec.ts`, `setup-wizard-journey.spec.ts`,
`scheduling.spec.ts`, `scheduling-apply-flow.spec.ts` and
`multi-user-interactions.spec.ts`. Preserve the existing role/viewport fixtures
and request-counter patterns rather than replacing them with a second long
household journey; WP9 still owns that journey's consolidation.

The seed-owning request-budget spec runs in `chromium-serial-db`; exclude it from
the regular pool. Keep scheduling-owner tests in their existing owner pool and
run all three pools sequentially against the shared database. Missing fixtures
fail, never conditionally skip. Preserve the WP7 manual accessibility record and
repeat a focused child-persona keyboard/screen-reader smoke for extracted task
controls; record actual evidence rather than inferring it from DOM attributes.

### 4.4 Quality gate

Run targeted tests after each slice, the full gate before final Standard B, and
the full gate again after review fixes. Use the running canonical
Docker environment (`exec`, not a fresh `compose run`) and separate DB-owning
suites; no concurrent PHPUnit/E2E resets.

```text
npm run lint:fix          # only when its write scope contains no unrelated changes
npm run lint
npm run stylelint
npm test
npm run test:noise
npm run build
npm run l10n:check-keys
npm run docs:encoding
npm run backlog:check
docker exec chorestrike-test vendor/bin/phpunit --configuration phpunit.unit.xml
docker exec chorestrike-test vendor/bin/phpunit --configuration phpunit.integration.xml
docker exec chorestrike-test composer cs:check
npx playwright test --project=chromium
npx playwright test --project=chromium-serial-db --workers=1
npx playwright test --project=chromium-scheduling-owner --workers=1
git diff --check
```

Inventory pre-existing skips/warnings by test/item and reason; they do not allow
new required cases to skip. In particular, TECH-LINT-007 remains separately
tracked unless its component is actually touched. Tests required by this plan
may not be deferred to WP9 while declaring WP8 complete.

For this **draft-only change**, run docs encoding, backlog/link validation and
`git diff --check`, including the untracked new plan in validation. Application
gates above are implementation requirements, not claimed execution evidence.

## 5. File Manifest

Expected implementation surface; brace groups enumerate files, not permission to
edit whole directories. New extraction names are proposals to ratify at Standard
A. Update this section before adding/removing implementation files; keep only
actually changed files in the final review manifest. Existing regression suites
listed in section 4 also run when no source edit is needed in them.

```text
new:      lib/Service/{RoomTaskReadService,CompletionEffectService,CompletionPostCommitService,MedalService}.php
new:      tests/unit/Service/{RoomTaskReadServiceTest,CompletionEffectServiceTest,CompletionPostCommitServiceTest,MedalServiceTest}.php
new:      tests/integration/Db/WorkflowQueryBudgetTest.php
new:      tests/integration/Support/QueryCounter.php
new:      tests/e2e/workflow-request-budgets.spec.ts
new:      src/components/Tasks/{TaskListItem,TaskListBulkBar,TaskListDetails}.vue
new:      src/utils/taskDisplay.js
new:      tests/unit/components/{TaskListItem,TaskListBulkBar,TaskListDetails}.spec.js
new:      tests/unit/utils/taskDisplay.spec.js
new:      tests/unit/views/requestCounts.spec.js
new:      tests/unit/store/dependencies.spec.js
new:      docs/plans/active/cs-plan-067-wp8-batching-decomposition-performance.md (this plan, activated 2026-09-12; path follows review lifecycle)
modified: lib/Controller/{RoomTaskController,GameController}.php
modified: lib/Db/RoomTaskMapper.php
modified: lib/Service/{RoomTaskService,CompletionService,GameLogicService,TaskAccessPolicy}.php
modified: tests/unit/Controller/{RoomTaskControllerTest,GameControllerTest}.php
modified: tests/unit/Service/{RoomTaskServiceTest,RoomTaskServiceDailyTest,RoomTaskServiceLoggingTest,CompletionServiceTest,GameLogicServiceTest,GameLogicServiceTeamworkTest,TaskAccessPolicyTest}.php
modified: tests/integration/Db/BatchQueryRegressionTest.php
modified: tests/integration/Controller/{RoomTaskControllerIntegrationTest,RoomTaskIndexAuthorizationTest,GameControllerIntegrationTest,BulkAssignIntegrationTest,TaskAuthorizationTest,WireStatusAuthDenialTest}.php
modified: tests/integration/Service/{CompletionUndoStateEqualityTest,CompletionConcurrencyTest,ConcurrentCompletionTest,TaskCreationAggregateTest,RoomTaskServiceExtendedFeaturesTest,SchedulePlanAggregateTest,SchedulePlanConcurrencyTest}.php
modified: tests/integration/FamilyIntegrationTest.php
modified: src/components/Tasks/{TaskList,AssigneeChips}.vue
modified: src/views/{TaskManager,SchedulingView,RoomDetail}.vue
modified: src/store/roomTasks.js
modified: tests/unit/store/roomTasks.spec.js
modified: tests/unit/views/{TaskManager,RoomDetail}.spec.js
modified: tests/unit/components/{TaskList,AssigneeChips,SchedulingView,TodayView}.spec.js
modified: tests/unit/services/coreWorkflowLocalization.spec.js
modified: tests/e2e/{family-core-ux,workflow-state-reconciliation,scheduling-apply-flow}.spec.ts
conditional: src/store/checklist.js and tests/unit/store/checklist.spec.js (only scoped invalidation proven missing by extraction lifecycle tests)
conditional: l10n/{en.json,de.json,templates/chorestrike.pot} (changed keys/placeholders/source references only; no copy redesign)
modified: playwright.config.ts (register the new seed-owning spec in the serial pool)
modified: docs/api/README.md
modified: docs/engineering/{architecture,development,testing}.md
modified: docs/plans/README.md
modified: docs/plans/active/cs-plan-058-core-workflow-bottom-up-masterplan.md
modified: docs/backlog/{backlog.md,ACTIVE_TASKS.md,archive/2026-09.md}
```

Constructor changes must update all manually constructed controller/service
fixtures in the listed suites as well as container-resolved paths. Query counting
is test-only; schema and Docker configuration remain unchanged. The default D1
choice changes no route registration/count; any later route deletion requires
explicit `appinfo/routes.php`, `tests/unit/RouteMatrixTest.php`, API and caller
manifest additions. D5's alternative adoption requires enumerating the specific
streak/cleanliness files and regression tests before that implementation begins.

Draft-only maintenance also repairs the moved WP7 plan's parent link in
`docs/plans/done/cs-plan-066-wp7-canonical-client-state-family-ux.md` and rolls the
oldest coordination entries into the monthly archive; no completed plan is
reopened and no application code changes in this drafting round.

## 6. Implementation Order

1. Standard A review: resolve D1-D6, ratify the remainder ownership split and
   narrow the manifest. Only a passing review activates this plan; keep one
   active WP and no parallel edits to shared stores/services.
2. Baseline-only slice: verify live versus obsolete backlog sites, pin exact
   current payloads/reward/effect outcomes, implement and validate counting,
   record literal budgets and the size/cycle inventory. No optimization until
   the baseline and target gates are frozen.
3. Implement house-scoped batch reads for TaskManager/SchedulingView and pin the
   existing My Day enrichment; preserve service authorization, scoped generations
   and canonical assignees.
   Pass batch visibility, cardinality and request/query-bound regressions.
4. Verify and disposition historical service-locator/DI claims, remove proven
   redundant reads and preserve authoritative under-lock reads. Re-run
   service/controller/container gates.
5. Extract completion/undo effects and post-commit phases, then reward/medal
   concerns, sequentially. Re-run state equality, fault injection, concurrency,
   reward contracts and query ceilings after each extraction.
6. Extract TaskList units, preserve the existing store DAG and remove proven
   duplicate refresh ownership. Keep behavior and selectors stable; pass Jest
   race/event tests and focused role/viewport/a11y journeys. An interim Standard B
   may checkpoint a complete slice, but does not complete this plan.
7. Finish full cardinality/budget and WP7 E2E evidence; integrate durable docs and
   named size exceptions. Reconcile every owned remainder with exact evidence;
   archive only genuinely completed items and retain approved out-of-scope work.
8. Run the full quality gate and final Standard B review. Only a passing final
   review with every required test, criterion, finding and durable-doc update
   complete moves this plan to `done/` and marks parent WP8 complete. WP9 becomes
   eligible, not implemented; RELEASE-002 remains blocked by WP9.

## 7. Risks & Mitigations

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Frontend batching hides PHP N+1 or incomplete results | High | Executed-query cardinality tests plus exact result IDs and no per-row enrichment fallback |
| House batch leaks private sibling instances | High | Per-instance ADR-010 policy before serialization; role/creator/assignee/foreign-house matrix |
| Refactor weakens transaction or lock order | High | One aggregate owner, explicit phase inputs, unchanged lock primitives and real-DB equality/race/fault-injection proofs |
| Reward extraction changes rounding, award timing or undo | High | Characterization before moving code; exact current outputs, ledger contract and post-commit degradation tests |
| Refactoring introduces store cycles, stale responses or duplicate events | High | Preserve the existing one-way graph, per-scope generations, deferred-promise races, event-time state assertions and refresh-count bounds |
| SQL budgets reward missing checks or depend on fixture noise | Medium | Count known execution boundary, fixed fixtures/clock, explain per-row locks/writes, assert state and counts together |
| TaskList split changes scoped CSS, focus, roles or localization coverage | Medium | Reuse existing units, preserve selectors/props/events, update localization scan, desktop/mobile and assistive-tech smoke |
| Historical backlog or file-size rule expands scope indefinitely | High | Live-site evidence, explicit D5/D6 decisions, named exceptions and no automatic adoption of separate correctness items |
| Shared database resets make E2E counts unreliable | High | One seed owner, serial project registration, sequential pools and stable assertions rather than sleeps |

## 8. Acceptance Criteria

- [ ] D1-D6 and all subsequent implementation/review questions are resolved and
  recorded; every work item has exactly one backlog owner.
- [ ] TaskManager uses one house-scoped instance read with canonical assignees;
  My Day and task rows issue no eager per-instance assignee requests.
- [ ] House/room/daily projections preserve exact visibility, ordering, role,
  date and child-limit behavior, including empty and mixed-private fixtures.
- [ ] Request and real executed-query ceilings exist for setup, TaskManager,
  My Day, preview/apply, completion and undo; scaled workload tests pass with
  literal, documented thresholds and no result loss.
- [ ] TaskList has cohesive row/assignment/bulk/checklist/due-date boundaries
  without copied domain logic or accessibility/localization regressions.
- [ ] Complete/undo retain one transaction and ADR-011 lock order, exact recorded
  inverse, idempotency and post-commit success/degradation semantics.
- [ ] Reward/medal outputs and event vocabulary/timing are unchanged and pinned.
- [ ] Historical service-locator/DI claims have verified dispositions and live
  redundant refetches are removed; the store DAG remains acyclic, generations
  remain reset-safe, and each loaded derived projection refreshes at most once
  per transition.
- [ ] No production file exceeds the size guideline without a specific documented
  reason; named hotspot extractions and all approved exception dispositions are
  complete, with no blanket pre-existing exception.
- [ ] D5 remainder routing is actually applied; CR-SVC-44/CR-053-19 are not marked
  closed from partial or historical evidence. Required in-scope work is not
  merely deferred to allow this plan to move to `done/`.
- [ ] All required unit, integration, concurrency, component, budget and role/
  viewport E2E tests run green without missing, new or unapproved skipped cases;
  focused manual accessibility evidence is recorded.
- [ ] Full section 4.4 gate is green after the final change.
- [ ] Durable API, architecture, locking and testing docs describe the delivered
  behavior and budgets outside this temporary plan.
- [ ] Plan-owned findings in section 9 are resolved or explicitly user-rescoped;
  completed backlog items are archived and parent/tracking references are current.
- [ ] Final Standard B is `PASS` or `PASS-WITH-FIXES`; WP8 is marked complete only
  by that review, with WP9 and release ownership unchanged.

## 9. Review Findings (plan-owned)

Standard A (2026-09-12): outcome `PASS`, no findings (§11). In-scope findings
discovered during implementation or later reviews are recorded here as
severity, file/area, issue and resolution state; unresolved findings block
completion. Out-of-scope findings follow the canonical fix-now / fold-in /
backlog routing.

## 10. Draft Verification and Review Handoff

Standard A was required before activation; it was performed 2026-09-12 with
outcome `PASS` and D1-D6 ratified (§11). No implementation, lifecycle move or
commit beyond that review was authorized by the draft-creation task alone.
Review must confirm the post-WP7 inventory, D1-D6, the test/counter feasibility,
all owning backlog portions and the exact manifest before passing the plan.

Draft verification on 2026-09-12:

- `npm run backlog:check` and `npm run docs:encoding` passed; both existing
  checkers also passed with their Git file enumeration expanded to include the
  untracked draft. Neither checker nor the Git index was changed.
- `git diff --check` and the new-file `git diff --no-index --check` passed.
- Read-only backend/frontend inventory and an internal backend consistency pass
  informed the draft. The latter clarified scopeId intersection versus roomId
  denial and the recorded title inverse versus medal/notification non-retractions.
  This was not a formal Standard A review and did not ratify D1-D6.
- Application test/build gates and performance measurements were not run for
  this documentation-only change; section 4 remains required implementation work.

## 11. Review Outcome

**Standard A — 2026-09-12 · outcome: `PASS`** (no findings; D1-D6 ratified as
recommended and recorded in §2.1/§2.2).

- **Scope:** this plan file (sole regular `*.md` in `docs/plans/new/`;
  destination `active/` free) plus its draft maintenance package
  (`backlog.md` CR-WF-06 detail, `ACTIVE_TASKS.md` roll, monthly archive,
  `docs/plans/README.md`, masterplan WP8 child-plan row, WP7 parent-link
  repair).
- **Base state:** branch `master`, HEAD `1dc06a5e07280c911525c5328ba6f5ff3fccdd22`,
  empty index; reviewed scope = the 6 modified tracked files (unstaged) + this
  untracked plan; no unrelated changes present.
- **Verification:** spot-checked the code-grounded inventory against the tree —
  `RoomTaskController::index` selector contract, TaskManager/SchedulingView
  per-room fan-out, unused production `fetchAssignees`, absent
  `RoomTaskMapper::findByHouse`, five `awardMedalIfNew` blocks, legacy
  self-inserting ledger branch, duplicated enrichment clusters,
  `updateDueDate` transaction shape, acyclic store DAG exactly as claimed, all
  named unit/integration/E2E anchors exist (incl.
  `tests/integration/Setup/SetupApplyConcurrencyTest.php`), ADR-009..012
  present, every §4.4 gate script exists in `package.json`, every cited
  backlog ID resolves. No claim falsified; no block/major finding.
- **Decisions:** D1-D6 ratified as recommended via one batched structured
  question (user answers 2026-09-12). D5 effect: cron freeze/restock batching
  is rescoped from WP8 into CR-053-11; CR-053-19 stays
  remainder-classification-only; no backlog item closed.
- **Gates (draft-applicable per §4.4):** `npm run docs:encoding`,
  `npm run backlog:check`, `git diff --check` — rerun green after the
  activation edits.
- **Transition:** `new/ → active/` performed by this review; CR-WF-06 and
  CR-SVC-44 set In Progress (CR-SVC-15/40/41 already were); tracking links
  repointed; review record appended to
  `docs/backlog/archive/CODE_REVIEW_2026-09-12.md`.
