# CS-PLAN-066: WP7 - Canonical Client State and Family-Friendly Core UX

> **Status:** Done (drafted 2026-09-11; Standard A `PASS-WITH-FIXES` 2026-09-11, D1-D7 ratified as recommended; implemented 2026-09-12 steps 2-8; final Standard B `PASS-WITH-FIXES` 2026-09-12 — all findings fixed in round, full §4.4 gate rerun green, plan moved to `done/`)
> **Effort:** XL (one sequential package with reviewable implementation slices)
> **Priority:** P1
> **Depends on:** [CS-PLAN-058](cs-plan-058-core-workflow-bottom-up-masterplan.md) WP0-WP6 complete; [CS-PLAN-065](../done/cs-plan-065-wp6-actionable-scheduling.md) final Standard B `PASS`; scope decisions D6/D7 ratified 2026-09-11
> **Related backlog items:** CR-WF-04, CR-WF-08 (owned umbrellas); CR-053-20 (remaining client-state slice); BUG-API-001 and CR-053-13 (ratified prerequisite slices per D6/D7)
> **Parent:** CS-PLAN-058 WP7; WP8 consumes the frozen payloads and behavior.

## 1. Problem Summary

WP3-WP6 delivered atomic setup, task creation/assignment, full completion/undo,
and owner-confirmed scheduling. WP7 connects these contracts into one reliable
owner/member/child experience. It does not rebuild the aggregates.

Baseline inspected on 2026-09-11:

| Surface | Remaining gap or existing foundation |
|---------|--------------------------------------|
| `roomTasks.js`, `QuickStats.vue` | Due/overdue actions return data without storing it; Dashboard counters read `roomTasks`, which a Dashboard-only visit does not populate. |
| `RoomTaskController` | Filtered daily reads are enriched; due/overdue reads are bare instances. Assignees are user-ID arrays in some responses, relation objects or absent in others. |
| `CompletionService`, `roomTasks.js` | Completion computes `newTitle` but omits it from rewards; the existing title-unlocked client event cannot fire. WP5 already reconciles room/task-instance/progression/wallet/weekly-goal slices, but house health and other loaded projections remain incomplete. |
| `house.js`, `rooms.js`, `tasks.js`, `user.js` | Ordinary reads/activity/member initialization lack the daily/range/scheduling request-generation protection; stale reads can overwrite navigation, reset, or mutation results. |
| `game.js`, `minigame.js` | Weekly-goal hydration calls the event-producing setter before dismissing the local celebration. Minigame initialization lacks aggregate `finally` handling and shares loading state with child fetches. |
| `router.js`, `App.vue`, `TodayView.vue` | Root opens Dashboard for every role. My Day has room/name/Done, but no instructions/checklist; reward-oriented row details compete with the execution action. |
| Task and calendar surfaces | Child TaskList selection has no available bulk action. TaskManager/RoomDetail have logger-only failure paths; Calendar/Scheduling lack consistent retry states. |
| Existing setup/task/scheduling UX | Progressive setup, visibility explanations, role gates, 44 px Done controls, queued undo, and a WP6 mobile/keyboard scheduling journey already exist. Extend rather than replace them. |
| Backlog prerequisites | BUG-API-001 breaks real HTTP definition editing. CR-053-13 is owned by WP9 but explicitly blocks WP7 localization; the dependency must be resolved, not silently ignored. |

Invitation duplicate rows and range-response races are historical CR-053-20
claims, not assumed current defects: invitation now refetches members and range
already has sequence guards. Retain and strengthen regression evidence before
closing those slices; do not reimplement fixes already delivered.

## 2. Strategy

Freeze the minimum cross-layer contract, make each loaded projection truthful,
then improve the existing shared screens. Use server responses for domain values
and coordinated reads for derived state; never reconstruct rewards, permissions,
recurrence, capacity, or house health in the browser.

### 2.1 Ownership and scope

| Work | Owner and boundary |
|------|--------------------|
| Payload shapes, count sources, completion/title handoff, stale-fetch safety | CR-WF-04 / this plan |
| Shared owner/member/child navigation, task understanding, accessibility and recovery | CR-WF-08 / this plan |
| Weekly-goal hydration, activity/range races, invite uniqueness, minigame initialization cleanup | CR-053-20 remainder / this plan; technical fixes only, no add-on redesign |
| Definition-edit HTTP failure | BUG-API-001; recommended narrow adoption under D6, not a second finding |
| Translation coverage of the WP7 journey | CR-053-13; recommended WP7 prerequisite slice under D7, global remainder stays WP9 |
| House-scoped batching, request/query budgets, broad TaskList/service decomposition | WP8, unchanged; consume enriched fields when needed for correctness without starting a performance project |
| Global skip cleanup, replacement of `gui-scratch-flow.spec.ts`, final program Full review/release | WP9, unchanged; WP7 still owns all tests required for its own exit gate |

Preserve ADR-009 dates, ADR-010 permissions, ADR-011 completion/undo and ADR-012
scheduling. Reward formulas, title-retention policy, event names, daily-limit
policy, invitation semantics, and membership rules are frozen. No new schema,
external service, separate child app, generic state framework, compatibility
adapter, or second scheduler is proposed.

### 2.2 Ratified decisions (Standard A, 2026-09-11)

All seven decisions were batched, asked, and **ratified as recommended** in the
Standard A review of 2026-09-11 (answers recorded in `## Review Outcome`). The
recommended column below is binding; the alternative/review-boundary column
remains the documented scope guard.

| ID | Ratified decision (as recommended) | Alternative / review boundary |
|----|----------------------|-------------------------------|
| D1 | Use unique, deterministically ordered Nextcloud user-ID arrays for public `assignees` fields; keep enriched daily rows `{roomTask, task, room, assignees}` and operation-specific envelopes. Update all affected callers together. | Keep relation-object responses only if the consumer inventory proves required relation metadata; then explicitly document that operation-specific contract. Never accept both formats through a compatibility shim. |
| D2 | Keep dedicated due-today/overdue projections in `useRoomTasksStore`; counters count complete viewer-visible endpoint results, not a partially loaded room or a child's limited daily list. | A dedicated server summary is possible but adds an endpoint; only adopt with a reviewed need. |
| D3 | Retain synchronous WP5 response reconciliation, then coordinate authoritative derived-state refreshes. A refresh failure leaves the committed action successful, marks affected projections stale, and offers refresh-only retry. | Embedding house health into the mutation response needs a reviewed service/locking/degradation contract; do not widen the completion transaction implicitly. |
| D4 | After membership resolves, redirect only an initial unqualified root visit by a child to existing My Day. Keep explicit deep links and access to permitted shared screens; keep owner/member defaults. | Redirect every child Dashboard visit only with explicit product approval. No separate child surface or automatic removal of read-only navigation. |
| D5 | Suppress weekly-goal/title celebrations on hydration, refetch and undo; emit on a confirmed new completion transition after response-owned state is reconciled. Track weekly-goal initialization/week identity, not a new persisted reward policy. | Remote/background transition celebrations require a separate delivery/deduplication design; not inferred from a fetch. |
| D6 | Adopt BUG-API-001's narrow request-binding fix plus real-HTTP edit regression as an early WP7 prerequisite. Keep its existing ID and archive it when verified. | Resolve it independently before WP7's task-edit slice. Leaving the visible edit action broken cannot satisfy the journey gate. |
| D7 | Ratify an explicit WP7 core-surface translation slice of CR-053-13 (all reachable WP7 copy, not just newly added keys); keep global extraction/checker strengthening and unrelated catalog cleanup in WP9. | Finish CR-053-13 independently before WP7. Do not leave a WP9-only predecessor in this sequential dependency chain or claim localization from the parity checker alone. |

## 3. Backend / Frontend Changes

### 3.1 Canonical payload and identity contract

Keep the existing `/api/v1` routes and `{success, data, error}` transport envelope.
`src/services/api.js` remains the sole application request wrapper (already true
in production). Controllers shape responses; service policy owns eligibility and
enrichment, mappers own persistence. Document the following in durable API docs:

| Data | Canonical representation and consumer |
|------|---------------------------------------|
| House / current user / members | Existing house fields and authoritative role/title/progression fields. Numeric membership `id` is not `nextcloudUserId`; use the latter for assignees and completer identity. Member lists replace/upsert by stable identity, never append blindly after invite. |
| Rooms | Existing room entities and `{floor, rooms}` structure; `useRoomsStore` owns shared room values. View-local room stats are projections that must refresh/invalidate after relevant writes. |
| Definitions / instances | `useTasksStore` owns definitions; `useRoomTasksStore` owns instances. Merge authoritative definition and instance updates by their separate IDs, never put an enriched daily wrapper into the definition store. |
| Assignees | D1 user-ID set on read/create/single/bulk-assignment/scheduling responses. Preserve bulk partial-success semantics; reconcile only returned successes, not the requested set. |
| Daily / due / overdue | Reuse `{roomTask, task, room, assignees}` for execution rows. Preserve server eligibility, visibility and ordering; add enrichment without broadening access. Distinguish uncapped due/overdue projections from filtered/limited My Day. |
| Calendar / scheduling | Preserve range occurrences/flex windows and preview/apply envelopes. Reconcile changed instances and invalidate loaded date-window projections; never turn projected occurrences into independent writable tasks. |
| Completion / undo | Preserve `{roomTask, room, task, rewards}` plus undo's `undone` and nullable rewards. Add explicit nullable `rewards.newTitle` from the already computed result, independent of notification success. Keep `degraded` and completer identity semantics. |
| Invitation | Preserve the existing joined house-user response and per-recipient wizard outcome. A member-list refresh failure after joining is not an invitation failure; retry the read, not the invitation. |

Inventory affected producers and consumers before the first payload edit and pin
exact JSON fields in tests. No dual-format normalization. Assignee enrichment
must not leak inaccessible task content, other households, or internal relation
metadata. Preserve the existing anti-enumeration/redaction contracts.

Under D6, repair `TaskController::update` at the actual Nextcloud request binding
boundary using the reviewed closed update fields and existing validation. Retain
service authorization and distinguish route parameters from editable fields.
Do not expand this into generic dispatcher cleanup.

### 3.2 Canonical projections and mutation reconciliation

Retain existing Pinia stores and extend their current actions, not an additional
cache/store layer. Each projection records scope (viewer, house, room/date/filter
where relevant), loaded/loading/error/stale state and request generation as needed.
A scoped room load must never masquerade as a complete household count source.

| Trigger | Required response reconciliation / refresh |
|---------|---------------------------------------------|
| Setup, room/structure change | Patch/refetch house and room structure; invalidate affected definition/instance lists, counts and relevant room stats. Preserve the wizard's committed-result and invite outcomes. |
| Create/edit/delete, assignment/claim, due/lifecycle changes | Reconcile returned definitions/instances/assignees, then refresh loaded daily/due/overdue/range/room-stat projections and invalidate scheduling previews that depended on changed state. No client-assumed bulk successes. |
| Complete / undo | Preserve WP5's immediate instance/daily/room/game/user/wallet reconciliation and actor-vs-completer guard; also reconcile the returned definition. Mark counts, range, activity, insights and house health stale and refresh loaded consumers. Refresh checklist progress after its server-side reset/restore. |
| Schedule apply | Reuse WP6's changed-instance/window handoff and `applied` versus `refreshFailed` state. Include due/overdue count sources and active view filters; retain stale-preview 409 recovery. |
| Invite / member or role change | Refresh/upsert authoritative membership lists without duplicates, reconcile current-user permissions where applicable, and invalidate affected assignment/visibility/scheduling projections. |

House health uses the existing server calculation, not a client average of loaded
rooms or a plain house fetch of an old persisted value. Coordinate house reads and
health refresh so one cannot overwrite fresher health/name/timezone fields. Do not
introduce a new house-lock acquisition inside the room-task-first completion flow.

Response-owned fields are coherent **before** celebration/undo events. Derived
reads may settle afterward, but stale values must not be presented as fresh. Show
an explicit partial-refresh state with retry; a failed optional read must not
reject the already committed mutation, drop its undo entry, or replay the write.
Share one refresh owner per transition instead of duplicating event-listener and
action refetches. No optimistic arithmetic for rewards, counts or house health.

### 3.3 Request ordering and reset safety

Extend the existing daily/range/scheduling generation pattern to the core request
surfaces, including house/activity/insights, rooms, definitions, room-task and
assignee reads, current-user/members, game state and wallet reads. Scope generations
per resource/query; a global counter must not discard valid sibling room loads.

Every accepted mutation invalidates pre-mutation reads of affected slices. Reset,
membership denial, viewer/house changes and component disposal invalidate pending
results; a late success, error or `finally` cannot repopulate old-user data or
clear a newer request's loading state. Correctly release the shared current-user
in-flight promise without an older request clearing a newer one. View-local
projections in TodayView/RoomDetail must follow the same rule or become selectors
of their owning store.

Keep this bounded to the workflow inventory. Physical cancellation is optional;
generation checks are mandatory even if AbortController is used. General circular
dependency removal remains WP8, but WP7 must not introduce new store cycles.

### 3.4 Event and initialization correctness

Pass the computed `newTitle` through to the existing client event exactly once
per successful completion response; reconcile/invalidate the titles store before
consumers read it. Unlocking a title does not automatically equip it. Undo retains
ADR-011 title policy and emits no new unlock event. A post-commit notification
failure must not erase an already computed unlock from the successful response.

Make weekly-goal hydration silent rather than emitting and then dismissing its
local celebration. A confirmed same-week false-to-true completion transition emits
once; first load, Dashboard remount, unchanged refetch, week rollover and undo do
not manufacture an achievement. Preserve all existing event names and formulas.

Close the CR-053-20 minigame initialization error/loading slice with an aggregate
`try/finally` owner and recoverable error propagation; child fetches must not clear
aggregate loading early. Leave game rules, boost prices and optional UX unchanged.

### 3.5 Shared family-friendly journey

Use the existing Nextcloud design language, shared Modal/ErrorState/EmptyState and
one vocabulary for all ages, per [design.md](../../standards/guidelines/design.md).

| Surface | Required behavior |
|---------|-------------------|
| Shell / navigation | Resolve membership before role-dependent landing or controls. Child root opens My Day under D4; explicit links remain usable. Unknown role shows loading, never owner controls or an unfiltered task list. Denied membership retains NotAuthorizedState. |
| Owner setup / invites | Preserve progressive steps, one primary action, input on recoverable failure, structural result and per-recipient feedback. Extend keyboard/mobile coverage instead of rebuilding WP3. |
| Task create/edit | Preserve WP4 custom/template prefill, preview, room/assignee/date/visibility explanation. Core fields first; optional details progressively disclosed. Save failures retain input/modal and offer a truthful recovery state. |
| My Day | Shared task name, room, short instructions and optional expandable checklist; use existing checklist component/service with execute-only permissions. Keep a clear 44 px Done action and queued undo. Plain household information must suffice without understanding energy/reward formulas. |
| Role controls | Owners retain structure/invites/scheduling policy and checklist-definition editing; owners/members retain task management subject to existing policy; children execute visible eligible tasks. Hide child row-selection checkboxes and other non-actionable manage controls. Do not hide permissible read-only screens wholesale. |
| Core loading/error/empty states | TaskManager, RoomDetail, Calendar, Scheduling and My Day use explicit localized states and retry. Mutation failures are visible, not logger-only; empty is shown only after a successful empty response. |

Keep WP6's meaning of My Day: assigned-to-viewer **or unassigned**, with visibility
and assignment eligibility before availability and child limits. Do not bypass a
child limit through the All filter, local slicing, overdue merging, or a deep link.
Pin actual due/overdue behavior against ADR-012; if the existing server boundary
is insufficient, resolve it within this package before its gate, not by guessing
policy in Vue. Do not add a new claim flow or completion prerequisite merely to
display a checklist; retain existing claim and checklist semantics.

For every core action, verify accessible name, keyboard operation, visible focus,
44 x 44 px hit area, mobile layout without horizontal overflow, reduced motion,
and focus restoration after dialogs/row completion. Announce loading errors and
completion/undo status without duplicate live announcements. Instructions are
plain escaped user content, never `v-html`.

Under D7, inventory all keys reachable in the WP7 journey (including errors, aria
labels and server-driven title labels), verify DE/EN/POT presence and placeholders,
and render representative EN/DE states. `l10n:check-keys` is a parity check, not
proof of source coverage. No untranslated core string may be deferred to WP9.

## 4. Tests

### 4.1 Backend and contract tests

Extend existing controller/service suites and real-DB integration coverage:

- Pin enriched due/daily/overdue and canonical assignee shapes across read, create,
  assignment and apply, including empty sets and bulk partial success.
- Owner/member/child/denied/private/admin-only cases must return exactly the
  authorized IDs/content; retain viewer eligibility before child limits and
  house-local dates at midnight/DST boundaries.
- Completion returns nullable/new `newTitle`, including a successful unlock when
  post-commit notification dispatch fails; no unlock on undo/no-op undo. Preserve
  full state-equality/concurrency suites, reward totals and actor/completer rules.
- Under D6, exercise real HTTP `PUT /tasks/{id}` with the production client shape:
  valid roundtrip, invalid fields/values and child denial. Direct method invocation
  alone does not cover the dispatcher regression.
- Invitation retry returns one membership; enrichment/refresh must preserve
  visibility redaction and joined-versus-refresh-failed outcomes.

### 4.2 Jest matrix

| Area | Required deterministic assertions |
|------|-----------------------------------|
| API/store contracts | Endpoint/method/body/envelope, assignee identity, separate definition/instance updates, exact visible results for all changed response shapes. |
| Counts and projections | Cold Dashboard with no room visit, uncapped visible counts versus limited My Day, null due dates, timezone change, create/assign/complete/undo/apply transitions and scoped room loads. |
| Races | Deferred promises resolved B-before-A; read-before-write resolving last; reset/denial/role/viewer change during fetch; stale error/finally; valid parallel sibling loads; house fetch versus health refresh. |
| Reconciliation | Observe every response-owned store in event callbacks; exact totals for owner undoing a child's action; derived refresh failure retains success/undo and refresh-only retry. |
| Events/init | Silent achieved hydration/remount/week rollover/undo, one real weekly transition, title unlock/null/degraded cases, minigame rejection and retry with loading settled, duplicate invitation response. |
| Components/router | Root child landing versus explicit deep link, unresolved/denied roles, owner/member/child controls, instructions/checklist execution, focus/labels, retained input, loading/empty/error/offline/retry and partial-refresh state. |
| Localization | All WP7 reachable static keys and known server-driven label keys exist with matching placeholders; representative German and English rendered copy. |

### 4.3 Playwright exit matrix

Use authenticated contexts from `auth-contexts.ts` and deterministic `family` /
`emptyhouse` profiles. Parameterize desktop (1280 x 800) and touch mobile
(390 x 844, `hasTouch: true`) inside the relevant specs, following WP6's existing
mobile context pattern. A separate mobile project is not required.

| Journey | Profile / actor | Required evidence at both viewports |
|---------|-----------------|------------------------------------|
| Setup -> invite | `emptyhouse`, bootstrapped owner -> invited member | UI-only setup, one primary step action, recovered validation error, truthful invite result, member has no owner-only controls. Extend existing setup spec. |
| Create/edit -> plan | `family`, owner | Custom/template input, instructions/checklist, assignment and visibility explanation, real edit HTTP request (D6), scheduling preview/apply and cross-view consistency. Extend WP4/WP6 specs. |
| Shared member journey | `family`, `friend` or `grandma` | Permitted task management/execution works, owner-only controls absent, private visibility respected, failure and retry usable. |
| Child execution | `family`, `kid` | Root opens My Day, exact assigned/unassigned eligible set under limits, instructions/checklist, Done and queued Undo, navigate to Calendar/room then reload with matching state. |
| State recovery | `family`, owner/member/child as applicable | Cold Dashboard counts, complete/undo/apply refresh, delayed old read cannot restore stale state, committed-action refresh failure retries only reads. |

New focused specs: `family-core-ux.spec.ts` (member/child role and execution matrix)
and `workflow-state-reconciliation.spec.ts` (cross-view state/recovery). Register
both in `SEED_DEPENDENT_SPECS` for `chromium-serial-db`, workers=1. Reuse setup,
`3-floor-house.spec.ts` (WP4 atomic creation) and scheduling specs rather than duplicating a new long household
journey. Run all DB-owning projects sequentially, never in parallel against the
same database. Each viewport/role case establishes its own deterministic fixture.

Seed/reset and identity setup are fixtures; task actions under test use the UI.
Exact fixture assertions fail if data is missing, never `test.skip`. Use network
interception only for explicit race/failure cases, not the successful contract
proof. Assert accessible names, keyboard/focus behavior, actual hit-area bounds
and overflow; supplement automation with a recorded child-persona copy pass and
screen-reader smoke through the core flow. DOM labels alone are not full assistive
technology proof.

### 4.4 Quality gate

Targeted tests run after each slice. Before final review, run the full masterplan
gate against the final source/build and again after review fixes:

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

Use the canonical Docker test environment and family seed; isolated setup tests
own their emptyhouse reset. Required WP7 tests have zero conditional skips. Record
pre-existing skips separately; they are not permission to skip new matrix cases.
For **this draft-only change**, run docs encoding, backlog/link validation and
`git diff --check`; application gates above are implementation requirements, not
claimed execution evidence.

## 5. File Manifest

Expected implementation surface below; brace groups enumerate files, not a license
to edit whole directories. Prefer extending existing tests. The draft surface
was narrowed to the ACTUAL final surface during implementation (2026-09-12
final-review update): optional entries that stayed untouched were dropped and
every additional file the implementation touched was added, so this manifest
now matches the reviewed diff exactly.

```text
new:      tests/e2e/{family-core-ux,workflow-state-reconciliation}.spec.ts
new:      tests/unit/{router.spec.js,services/coreWorkflowLocalization.spec.js}
modified: lib/Controller/{RoomTaskController,TaskController}.php (TaskController: D6 only)
modified: lib/Db/TaskAssigneeMapper.php (D1 deterministic ordering)
modified: lib/Service/{RoomTaskService,CompletionService}.php (SchedulePlanService narrowed out)
modified: src/{App.vue,router.js}
modified: src/store/{house,user,rooms,tasks,roomTasks,titles,game,minigame}.js
modified: src/views/{Dashboard,TaskManager,RoomDetail,TodayView}.vue
modified: src/components/{Minigames/SnakeGame,Setup/SetupWizard,Tasks/TaskList}.vue
modified: playwright.config.ts
modified: tests/e2e/{setup-wizard-journey,scheduling-apply-flow,task-completion-full-flow,error-handling,validation,multi-user-interactions,room-management,time-travel-overdue}.spec.ts
modified: tests/unit/Controller/{RoomTaskControllerTest,TaskControllerTest}.php
modified: tests/unit/Service/{CompletionServiceTest,SchedulePlanServiceTest}.php (SchedulePlanServiceTest: WP6 date-bomb gate fix)
modified: tests/integration/Controller/TaskControllerIntegrationTest.php (D6 binding)
modified: tests/unit/{setup.js,__mocks__/nextcloud-router.js} (router factory mocks for D4)
modified: tests/unit/store/{roomTasks,user,house,game,minigame}.spec.js
modified: tests/unit/components/{App,QuickStats,TaskList,TodayView}.spec.js
modified: tests/unit/views/TaskManager.spec.js
modified: l10n/{en.json,de.json,templates/chorestrike.pot}
modified: docs/api/README.md
modified: docs/engineering/architecture.md
modified: docs/concepts/ui-use-cases.md
modified: docs/plans/active/cs-plan-058-core-workflow-bottom-up-masterplan.md
modified: docs/plans/active/cs-plan-066-wp7-canonical-client-state-family-ux.md
modified: docs/backlog/{backlog.md,ACTIVE_TASKS.md,archive/2026-09.md}
```

Durable documentation owners: API payload/identity matrix in `docs/api/README.md`;
store projection/generation/event semantics in engineering architecture; family
journeys in use cases/design; test profiles, viewport matrix and gate evidence in
engineering testing. Existing ADRs remain authoritative; a newly identified
architecture decision requires an explicit ADR/manifest addition before closure.

## 6. Implementation Order

1. Standard A review: resolve D1-D7, confirm D6/D7 dependency ownership, narrow the
   manifest, record decisions, activate only on a passing outcome. Keep one active
   WP and no parallel edits to shared stores/services.
2. Pin failing/missing contract regressions; resolve BUG-API-001 under D6 and
   inventory core localization under D7. Update backlog ownership on ratification,
   not by silently treating proposed scope as approved.
3. Implement canonical assignee/daily payloads and the title response field with
   backend contract/visibility tests; update all affected clients together.
4. Implement count projections, request-generation/reset safety and coordinated
   mutation reconciliation. Prove cold-load, race and post-commit recovery cases.
5. Close weekly-goal hydration, title event and minigame initialization behavior;
   pin historical invite/range fixes. An interim Standard B may review steps 2-5;
   the plan stays active until the whole package passes.
6. Deliver child landing, My Day instructions/checklist and role-aligned controls;
   repair core form/view feedback, mobile/focus targets and translated copy.
7. Complete desktop/mobile owner/member/child E2E and the manual acceptance pass.
   Fix missing matrix cases here; do not hand required WP7 tests to WP9.
8. Integrate durable docs, run the full gate, reconcile each owning backlog
   criterion with evidence, and obtain final Standard B review. Only a passing
   final review with no open required work moves this plan to `done/`, archives
   completed items and marks masterplan WP7 complete; WP8 remains not started.

## 7. Risks & Mitigations

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Payload work becomes a performance/framework rewrite | High | Explicit operation contracts and exact inventory; no new cache framework, batching campaign or TaskList decomposition before WP8. |
| Client generations protect fetch order but not read-versus-write races | High | Invalidate before applying mutations; test late reads/errors/finally and shared-resource field updates, not just navigation A/B. |
| Partial room/daily data produces plausible but wrong counts | High | Dedicated scoped projections and cold-Dashboard tests; uncapped visible counts are distinct from child-limited execution. |
| Refresh failures disguise committed actions as failures | High | Preserve successful receipt/undo, expose stale projections and retry reads only; test complete/apply/invite separately. |
| Enrichment leaks private task or principal data | Medium | Reuse service visibility policy, distinguish identity types, assert exact redacted responses for every role. |
| Checklist/All/overdue UI circumvents daily policy | Medium | Preserve server ordering and limits, include mixed assigned/unassigned/overdue and deep-link cases; no client policy recreation. |
| Event changes alter reward/undo policy | Medium | Event-source versus hydration distinction, no formula changes, preserve title retention and WP5 state-equality gates. |
| WP9 localization dependency blocks WP7 closure | High | D7 explicitly ratifies a core slice or a separately completed prerequisite; never infer source coverage from locale parity. |
| Existing edit 500 invalidates family task-management proof | High | D6 requires real-HTTP regression/fix before the edit slice and final gate. |
| Mobile/accessibility claims exceed evidence | Medium | Required viewport/role cases, real hit areas and focus paths, recorded screen-reader/copy smoke; no conditional fixture skips. |
| Shared DB resets or unknown roles corrupt E2E evidence | High | Serial seed-owning pool, isolated contexts, assert seeded identities before actions, wait for membership resolution. |

## 8. Acceptance Criteria

- [x] D1-D7 answered and recorded; BUG-API-001 and CR-053-13 prerequisite ownership resolved without duplicate backlog items or a circular WP7/WP9 dependency. (Ratified in the Standard A review 2026-09-11: D6 adopts BUG-API-001 under its existing ID; D7 ratifies the WP7 core-surface slice of CR-053-13 with the global remainder in WP9.)
- [x] Every changed response has one documented shape; definitions/instances, membership IDs and Nextcloud user IDs remain distinct; all requests use `api.js`. (Enriched due/overdue rows, user-ID assignee arrays, explicit `rewards.newTitle`, bulk-assign embedded sets — documented in `docs/api/README.md`.)
- [x] Due/overdue counts are correct on a cold Dashboard and after every relevant mutation; limited daily and scoped-room lists cannot overwrite complete count sources. (D2 dedicated projections + per-room generation guards; pinned in `roomTasks.spec.js`, `QuickStats.spec.js`, `family-core-ux.spec.ts`.)
- [x] Complete/undo/apply reconcile response-owned state before events and refresh/invalidate all affected loaded projections without reload; refresh failure never replays or misreports a committed write. (D10 reconcile-before-events + D3 coordinated derived refresh; pinned by `roomTasks.spec.js` D3 block and `workflow-state-reconciliation.spec.ts`.)
- [x] Old successes, errors and finalizers cannot overwrite newer navigation/mutation/reset/identity state; valid parallel scoped loads still succeed. (Scoped request generations + reset invalidation + refcounted shared loading across house/user/tasks/rooms/roomTasks; sibling-load and reset-during-fetch tests.)
- [x] Title unlock reaches the existing event without equipping a title or changing undo policy; hydration/remount/undo does not invent weekly-goal achievements. (`rewards.newTitle` passthrough + titles-store re-sync before the once-per-response event; D5 week-identity celebration suppression.)
- [x] Invitation retries/refetches cannot duplicate member rows; minigame initialization settles loading and supports recovery on failure; historical range fixes are regression-proven. (Stable-id member upserts; aggregate try/finally initialize; daily/range/counts generation tests carried forward from WP6.)
- [x] Owner/member/child controls match service permissions; initial child root opens shared My Day while explicit permitted links remain usable. (TaskList child checkboxes hidden; `landingRouteForRole` + App.vue one-shot redirect; deep-link and owner/member-default tests.)
- [x] My Day provides room, instructions, optional executable checklist, clear Done and reversible feedback; server visibility/availability/child limits remain authoritative. (TodayView selector rework; instructions fallback chain; execute-only checklist; queued undo.)
- [x] Core setup/create/edit/schedule/execution is usable without understanding reward formulas, with preserved form input and explicit loading/empty/error/offline/recovery states. (TaskManager/RoomDetail error+retry states, visible mutation-failure toasts, edit-modal input retention; `docs/concepts/ui-use-cases.md` UC-08 updated.)
- [x] Core actions meet keyboard, accessible-name, visible-focus, focus-restoration, 44 x 44 px target, mobile overflow and reduced-motion requirements; manual copy/screen-reader checks recorded. *(Reduced-motion/labels/focus largely delivered by WP5/WP6 and re-verified by the existing specs; the mobile/44px gaps found in WP7 surfaces (wizard buttons) were fixed and the My Day checklist/tap journeys pinned. Manual child-persona copy/screen-reader pass performed and recorded in the final Standard B review round 2026-09-12 — user report verbatim: "Funktioniert, was auffällt: 'Checklist' hat 0/0, lässt sich aber anklicken (und verschwindet gleich wieder). Der Button sollte inaktiv sein." — the one finding was fixed in that review round.)*
- [x] WP7 reachable copy and accessible labels are localized in EN/DE with matching POT/JSON keys/placeholders; the unresolved global CR-053-13 remainder does not hide a core gap. (99-key core slice added to en/de/POT; `coreWorkflowLocalization.spec.js` source-coverage guard; CR-053-13 remainder owned by WP9.)
- [x] Section 4 backend/Jest/real-HTTP/E2E matrix passes, including all role/viewport cases, with zero newly skipped or deferred required tests; full quality gate green after final changes. (PHPUnit unit 916 green + integration green; Jest 895+ green; eslint/stylelint/build/l10n checks green; chromium-serial-db 108 passed + chromium-scheduling-owner 10 passed; the 5 skips are the pre-existing conditional GameController-integration skips.)
- [x] Durable API, architecture, use-case/design and testing documentation integrated outside this temporary plan. (`docs/api/README.md` payload matrix + D6 body shape; `docs/engineering/architecture.md` client-state contract; `docs/concepts/ui-use-cases.md` UC-08.)
- [x] CR-WF-04/08 and CR-053-20 remaining criteria resolved with evidence; completed items archived immediately, partial CR-053-13 remainder retained with exact ownership. (All three backlog entries updated with per-criterion evidence; CR-053-13 remainder explicitly WP9-owned; archive moves land with the WP7 review transition.)
- [x] All plan-owned findings resolved or explicitly user-rescoped; final Standard B `PASS` or `PASS-WITH-FIXES`, tracking updated, only then WP7 complete and WP8 eligible. *(Final Standard B `PASS-WITH-FIXES` 2026-09-12: four review findings fixed in round, one resolved observation, full §4.4 gate rerun green — see `## Review Outcome`.)*

## 9. Review Findings (plan-owned)

- [x] nit · §5 File Manifest · manifest self-path referenced `docs/plans/new/` and would go stale on the `new/ → active/` move · **fixed in Standard A review 2026-09-11** (path updated to `docs/plans/active/`).
- [x] minor · `SchedulePlanServiceTest::testPreview_StaticWeeklyTask_MovesOnlyOntoConfiguredWeekday` · date-bomb: expected "next saturday" hardcoded, failing on any Saturday run (found in the 2026-09-12 gate run; pre-existing, scheduling domain = WP6 scope but blocks the shared build gate) · **fixed in this round**: the expectation now resolves to today when today IS Saturday (date-independent, same legal-slot intent); scope-first fix-now per Finding Routing step 1.
- [x] minor · `tests/e2e/task-completion-full-flow.spec.ts` + `error-handling/validation/multi-user-interactions/room-management/scheduling-apply-flow` specs · child bare-root visits asserted `dashboard-view` · **updated in this round**: D4 landing made the redirect the expected behavior; specs now assert today-view first (explicit Dashboard navigation preserved where room cards are needed).
- [x] minor · `src/views/TodayView.vue` · the WP7 edit dropped the `@license MIT` line from the copyright header · **fixed in final review 2026-09-12** (line restored).
- [x] minor · `tests/unit/Controller/TaskControllerTest.php` · 62 mixed CRLF line endings on the added lines made `composer cs:check` red (1 of 284 files) · **fixed in final review 2026-09-12** (LF normalized; `cs:check` + unit suite rerun green).
- [x] minor · plan §5 File Manifest · drifted from the actual surface: 10 additional changed files unlisted (`TaskAssigneeMapper.php`, `SnakeGame.vue`, 5 D4-affected e2e specs, `TaskControllerIntegrationTest.php`, `nextcloud-router.js` mock, `tests/unit/setup.js`), `router.spec.js` path wrong, optional entries not narrowed · **fixed in final review 2026-09-12** (manifest rewritten to the reviewed diff).
- [x] minor · TodayView checklist toggle (manual a11y pass, user 2026-09-12) · on checklist-less rows the toggle was clickable, flashed open, and collapsed again — "Der Button sollte inaktiv sein" · **fixed in final review 2026-09-12**: the toggle is hidden after the one-shot empty discovery (a disabled state would need a server-side presence flag the frozen payloads do not carry); regression test added (`TodayView.spec.js`, Jest now 896).
- [x] minor (observation, resolved) · `tests/e2e/room-management.spec.ts` in the extended `chromium-serial-db` pool · two different tests flaked-and-passed-on-retry across two full runs (owner room-delete, member structure controls); isolated re-runs green; full-pool re-run 108 passed / 0 flaky · **load-sensitive timing, no deterministic defect identified; no code change** — evidence recorded in the Review Outcome.

Findings discovered during implementation/review belong here when in WP7 scope;
out-of-scope issues fold into an existing backlog item or receive their own item
under the scope-first rule.

## Review Outcome

**Standard A (plan review) — `PASS-WITH-FIXES` — 2026-09-11**

- **Scope:** this draft plan (untracked, SHA-256 `AD3723B4…6BEF` at capture) plus the draft-package tracking diffs in `docs/backlog/{ACTIVE_TASKS.md,archive/2026-09.md,backlog.md}`, `docs/plans/README.md`, and `docs/plans/active/cs-plan-058-core-workflow-bottom-up-masterplan.md`. Base HEAD `fb8e72e` on `master`, empty index, no unrelated staged paths.
- **Checks:** template sections 1-9 complete; ownership table and individually validatable implementation steps; no overlap with active plans (only the parent masterplan CS-PLAN-058 is active); risks/dependencies named; acceptance criteria complete; test plan (backend/Jest/real-HTTP/Playwright matrix + full gate) adequate; all referenced backlog IDs (CR-WF-04, CR-WF-08, CR-053-20, BUG-API-001, CR-053-13) exist; baseline claims spot-verified against code (`roomTasks.js` due/overdue fetch-without-store, `CompletionService.php` computed-but-unreturned `newTitle`); manifest file references verified to exist (`auth-contexts.ts` lives at `tests/e2e/fixtures/auth-contexts.ts`); no workaround stacking pre-baked.
- **Gates (draft-only per §4.4):** `npm run docs:encoding` green (679 files), `npm run backlog:check` green (18 files link-checked), `git diff --check` clean.
- **Decisions:** D1-D7 batched in one structured request and ratified as recommended (user answers 2026-09-11): D1 user-ID arrays; D2 store projections; D3 retain WP5 + coordinated refresh; D4 redirect initial child root only; D5 confirmed transitions only; D6 adopt BUG-API-001 as WP7 prerequisite; D7 ratify WP7 core-surface translation slice of CR-053-13.
- **Fixes:** one nit — manifest self-path updated for the `active/` location (§9). Status/header, §2.2 ratification, and the first acceptance criterion updated accordingly.
- **Transition:** `new/ → active/`; CR-WF-04/CR-WF-08 marked In Progress; BUG-API-001 owning updated to the WP7 prerequisite; CR-053-13 annotated with the ratified WP7 core slice; tracking links repointed to `active/`.

## Implementation Record

**2026-09-12 — steps 2-8 implemented (main agent, single session; final review pending):**

- **Step 2 (D6/D7 prerequisites):** `TaskController::update(int $id)` reads the closed payload from `getParams()` minus `id`/`_route` (unit + integration tests adapted; route-internals exclusion pinned); D7 core-slice inventory audited (99 keys) and closed (see step 5).
- **Step 3 (canonical payloads):** `rewards.newTitle` explicit+nullable in `CompletionService::complete`; due/overdue endpoints return enriched `{roomTask, task, room, assignees}` rows (`RoomTaskService::getDueTodayTaskRows`/`getOverdueTaskRows`, batched enrichment); all public `assignees` fields are unique ordered user-ID arrays (aggregate, set-assignees, bulk-assign embedded sets); `TaskAssigneeMapper` reads carry deterministic `ORDER BY`.
- **Step 4 (projections/ordering):** dedicated `dueTodayTasks`/`overdueTasks` store projections (D2) with `totalDueTodayTasks`/`totalOverdueTasks` getters; scoped request generations (per-room map), refcounted shared loading, reset invalidation across house/user/tasks/rooms/roomTasks/titles; coordinated derived refresh (D3) with stale flags + read-only retry; definition reconciliation into `useTasksStore`; member-list stable-id upserts.
- **Step 5 (events/init):** weekly-goal week-identity celebration suppression (D5, hydration silent); title-unlocked event once per response with titles-store re-sync (failure-tolerant); minigame aggregate try/finally initialize with recoverable error propagation; SnakeGame mount guards the failure.
- **Step 6 (family UX):** child landing via `landingRouteForRole` (router.js) + one-shot App.vue redirect (D4); TodayView rebuilt as store selectors with instructions + execute-only checklist; TaskManager/RoomDetail explicit error/retry states + visible mutation-failure toasts; child row-selection checkboxes hidden; QuickStats counters read the projections; Dashboard loads them on cold mount; wizard buttons ≥44px.
- **Step 7 (E2E):** new `family-core-ux.spec.ts` (cold Dashboard counts, real-HTTP PUT /tasks/{id} D6 roundtrip, complete→undo reconcile without reload, child landing/deep links, child checklist execution, member mobile 44px) and `workflow-state-reconciliation.spec.ts` (navigation races, superseded slow read, refresh-failure → read-only retry without write replay) registered in the serial pool; scheduling-apply-flow/mobile, wizard mobile journey and the affected role-matrix specs updated for the D4 landing.
- **Step 8 (docs/gates):** API payload matrix + D6 body shape + newTitle documented; architecture client-state contract section; UC-08 refreshed; backlog CR-WF-04/08 + CR-053-20 resolved with evidence, BUG-API-001 resolved, CR-053-13 slice delivered (remainder WP9); l10n artifacts +99 keys (en/de/POT) with `coreWorkflowLocalization.spec.js` guard.
- **Gate (2026-09-12):** PHPUnit unit 916 green, integration 315 green (10 pre-existing conditional skips); Jest 895+ green; eslint/stylelint clean (one pre-existing stylelint warning in `NotAuthorizedState.vue`); webpack build green; `l10n:check-keys`, `docs:encoding` green; Playwright chromium-serial-db 108 passed + chromium-scheduling-owner 10 passed (5 pre-existing conditional skips).

## Review Outcome (final)

**Standard B (final code review) — `PASS-WITH-FIXES` — 2026-09-12**

- **Scope:** the complete WP7 implementation package — 59 modified + 4 new files, captured as the uncommitted working tree on `master` at base HEAD `bd085bc` (the Standard A activation commit). Scope fingerprint at capture: empty index (staged diff SHA-256 `E3B0C442…B855`), unstaged binary diff SHA-256 `766AF3CDBEF768A54D593125551028DF4610FA7C2E1B0EC75F422EB5EED55DF8`, untracked files `tests/e2e/family-core-ux.spec.ts` (`47AC05B6…B976`), `tests/e2e/workflow-state-reconciliation.spec.ts` (`51C48861…DAA0F`… full hashes in the review record), `tests/unit/router.spec.js` (`B24B1C2F…6984`), `tests/unit/services/coreWorkflowLocalization.spec.js` (`D35A28A6…D66B`). Owning plan: this file (explicit; unambiguous).
- **Checks (final Standard B depth):** full §5 manifest ↔ diff reconciliation; acceptance criteria 1-16 verified against code and tests; shared checklist review of backend (D6 request binding, enriched-row enrichment without access broadening, batched reads, D1 deterministic ordering, `rewards.newTitle`), stores (scoped generations, refcounted loading, reset invalidation, D3 refresh owner, D5 week identity, D10 reconcile-before-events, titles invalidate-before-event), views/components (D4 landing one-shot, §3.5 role controls, error/retry states, escaped instructions), l10n artifacts + source-coverage guard, durable docs (api/README payload matrix, architecture client-state contract, UC-08), backlog routing (CR-WF-04/08/CR-053-20 archived with evidence, BUG-API-001 resolved, CR-053-13 partial with WP9 remainder), implementation history (§ Implementation Record) vs the actual diff.
- **Gates (rerun by this review, after its fixes):** eslint clean; stylelint 0 errors (1 pre-existing documented warning, `NotAuthorizedState.vue`); Jest **896/896** (83 suites; +1 review regression test); `test:noise` green; webpack build green; `l10n:check-keys`, `docs:encoding` (680 files), `backlog:check` (19 files), `git diff --check` green; Docker PHPUnit unit **916 OK / 2715 assertions**, integration **315 OK / 10 pre-existing skips** (baseline unchanged — no new skips); `composer cs:check` **0** (after the review's LF fix); Playwright `chromium` **92 passed / 7 pool-excluded**, `chromium-serial-db --workers=1` **108 passed / 5 pre-existing conditional skips / 0 flaky on the full re-run** (one passing flake observed in `room-management.spec.ts` on the first run — different tests across two runs, all retries green, isolated re-runs green; recorded as a resolved observation in §9), `chromium-scheduling-owner --workers=1` **38 passed**.
- **Fixes (all in round):** ① `TodayView.vue` `@license MIT` header restored; ② `TaskControllerTest.php` line endings normalized to LF (unblocked `cs:check`); ③ §5 manifest realigned to the actual reviewed surface; ④ manual-a11y finding fixed — the My Day checklist toggle is hidden after the one-shot empty discovery (+ regression test, Jest 896).
- **Findings:** four minors fixed in round + one resolved observation (serial-pool flake, no deterministic defect) — all recorded resolved in §9; no `block`/`major`/security findings; no workarounds, no suppressed warnings, no newly skipped required tests.
- **Decisions:** one batched question — the §4.3/criterion-11 manual child-persona copy/screen-reader pass. User chose to perform it now (2026-09-12); report recorded verbatim in criterion 11; its single finding was fixed in round (④). No rescoping was needed.
- **Transition:** `active/ → done/`; masterplan WP7 marked complete (final review evidence); CR-WF-04/CR-WF-08/CR-053-20/BUG-API-001 archive entries finalized (review-pending notes removed); `ACTIVE_TASKS.md` updated; review record at `docs/backlog/archive/CODE_REVIEW_2026-09-12.md`. WP8 is the next masterplan work package (not started).
