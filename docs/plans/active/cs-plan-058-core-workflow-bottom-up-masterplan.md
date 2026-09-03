# CS-PLAN-058: Core Workflow Bottom-Up Masterplan

> **Status:** Plan (Standard A review passed 2026-09-02; activation pending)
> **Effort:** XL (program; implemented as sequential reviewable work packages)
> **Priority:** P1
> **Depends on:** Core Task Workflow Full Review (2026-09-02) and its recorded user decisions
> **Related backlog items:** CR-WF-01..08; CR-053-07/09/13/16/17/18/19/20/21; CR-055-01/03/04; CR-SVC-15/40/41/44; TECH-DOCS-003; UX-015; FEAT-UX-015 (owner-transfer semantics only); RELEASE-002/003

## 1. Problem Summary

ChoreStrike has most individual screens, endpoints, services, and tests required
for a household task application, but the complete user journey is not yet one
reliable system. The 2026-09-02 Full Review found blocking gaps across first-owner
bootstrap, invitation-only membership, task visibility, setup/task/assignment
atomicity, completion/undo integrity, client-state reconciliation, and realistic
test coverage. The review is recorded in
[`docs/backlog/archive/CODE_REVIEW_2026-09-02.md`](../../backlog/archive/CODE_REVIEW_2026-09-02.md).

The backlog is also difficult to execute as a program. It currently mixes open
work with resolved stubs and groups items by the review or historical plan that
found them rather than by dependency and owning layer. One defect may appear in
several entries (for example completion, undo, frontend cache drift, and service
decomposition), while the release-critical order is not explicit.

This master plan establishes one bottom-up critical path:

1. Make persistence and ownership invariants unambiguous.
2. Make authorization authoritative inside mutation services.
3. Build atomic setup, task-creation, and assignment aggregates.
4. Make completion and undo transactionally correct.
5. Turn scheduling into an explicit, applicable plan for real room tasks.
6. Reconcile API payloads and client state.
7. Deliver a comfortable owner/member/child journey.
8. Optimize only after contracts are stable.
9. Prove the whole journey with deterministic tests and E2E profiles.

### 1.1 Recorded decisions that this plan does not reopen

- After the first owner is bootstrapped, membership is **invite-only**. Removal
  is durable and ordinary access never auto-enrolls a user.
- Private tasks are accessible to their creator plus explicit assignees.
- Undo is one full transactional inverse, not a task-state-only convenience.
- House-local calendar semantics remain defined by ADR-009 and
  `CalendarService` / `src/utils/dateKeys.js`.
- Completion remains one lock-based transaction as defined by ADR-007; this plan
  strengthens that boundary rather than replacing it.
- Controllers stay thin; services own business rules and authorization; mappers
  and entities own persistence.
- Structure editing remains a single owner-only surface in Settings (with the
  existing RoomDetail header actions); Dashboard structure remains read-only.

### 1.2 Core journey in scope

The program is complete only when a household can execute this journey without
manual database edits, direct API calls, test-only shortcuts, or knowledge of
gamification internals:

1. A first authenticated user opens ChoreStrike and becomes the sole owner of
   exactly one house.
2. The owner completes the setup wizard: house name, floors, rooms, template
   level, optional initial assignees, preview, apply, and family invitations.
3. Invited adults and children open the app with the correct role and no hidden
   owner-only controls.
4. The owner creates custom or template-based tasks, selects a room, recurrence,
   due/flex dates, visibility, instructions/checklist, and assignees.
5. The owner previews a schedule based on availability and capacity, confirms
   the proposed assignment/due-date changes, and sees the applied plan in the
   calendar and daily views.
6. Each member, including a child, opens My Day and sees an understandable,
   correctly limited list of their own tasks.
7. A task can be completed with immediate, consistent room/task/member state.
8. An accidental completion can be undone completely and idempotently.
9. Reloading, changing users, or retrying a request never creates duplicate,
   orphaned, unauthorized, or stale state.

### 1.3 Explicit non-goals

The following remain implemented and regression-protected, but their product
details are not redesigned in this program:

- Coin formulas, tiers, combo multipliers, streak/freeze policy, weekly goals,
  medals, and reward balancing.
- Minigame rules, boost catalog, and reward-shop concepts.
- Title catalog and title progression design.
- Notification wording, reminder preferences, and background-job product rules.
- Task approval, vacation mode, global search, print views, bulk maintenance,
  and other add-on features in FEAT-UX-018/019/023..029.

Where completion or undo touches rewards, titles, or notifications, those modules
are treated as invariant-preserving side-effect participants. Existing formulas
and event names stay stable; only the technical delta needed for atomic completion
and full undo is in scope.

## 2. Strategy

### 2.1 Bottom-up dependency model

```text
WP0  Backlog ownership and baseline
  -> WP1  Schema, entities, mapper invariants
    -> WP2  Membership, roles, visibility, service authorization
      -> WP3  Atomic house setup and structure
        -> WP4  Atomic task creation and canonical assignment
          -> WP5  Completion ledger and full transactional undo
            -> WP6  Actionable scheduling -> Calendar -> My Day
              -> WP7  Canonical client state and family-friendly UX
                -> WP8  Batching, decomposition, and performance
                  -> WP9  Deterministic E2E and release-quality closure
```

Each work package has one semantic owner and a green gate. Shared hot paths are
not modified by parallel packages. A package may be split into a child plan after
plan review if it cannot remain one coherent review unit, but child plans must
retain this order and cannot duplicate backlog ownership.

### 2.2 Backlog as the execution source of truth

`docs/backlog/backlog.md` remains the only open-work list. WP0 restructures it
from historical review sections into the following dependency-oriented sections:

| Backlog section | Contents | Ownership rule |
|-----------------|----------|----------------|
| Core Workflow Program | CR-WF-01..08 and explicit dependencies | One umbrella owns each acceptance criterion |
| Core Hardening | Standalone correctness, API, persistence, i18n, and security remainder | Must state which work package it blocks |
| Deferred Add-ons | Rewards/detail work, minigames, notifications, optional UX/features | Cannot enter the core critical path without a plan update |
| Infrastructure and Documentation | Backlog hygiene, lint/dependency, docs truth, operational security | Independently schedulable unless a gate depends on it |
| Release | RELEASE-002/003 | Lists exact blocking work packages and gates |

Every open entry receives standardized metadata:

```text
Status | Priority | Effort | Layer | Journey stage | Owning plan/work package
Depends on | Blocks | Folded-in items | Source
```

Resolved stubs move to the monthly archive before resectioning. Historical
provenance remains in `Source` and review records, not as duplicate open bodies.
Pointers such as CR-053-20 may remain only when they identify a live remainder;
otherwise their acceptance criteria move to the owning CR-WF item.

### 2.3 Program governance

- Exactly one work package is `In Progress` at a time unless a reviewed file and
  contract boundary proves two packages independent.
- Start each package with a failing or missing test that demonstrates its owned
  invariant; end it with the full applicable quality gate.
- Update this plan's progress table, `ACTIVE_TASKS.md`, owning backlog entries,
  and durable docs in the same package.
- Use a Standard B review after each package. Use a final Full review across the
  complete owner/member/child journey before moving this plan to `done/`.
- Do not hide unfinished required tests behind skips or approved deferrals. A
  still-required deferred core test blocks the program.
- Security/auth findings are implemented only in their reviewed package, never
  opportunistically in an unrelated package.

## 3. Backend / Frontend Changes

### WP0 - Backlog structure and executable baseline

**Goal:** turn the existing backlog into an ordered program without losing open
work or history.

**Changes:**

- Complete TECH-DOCS-003: move all resolved/closed stubs to the tracked monthly
  archive, repair stale plan links, and fix duplicate IDs.
- Reorganize open entries into the sections in 2.2 and add standardized metadata.
- Make CR-WF-01..08 the core umbrellas; annotate older findings with `Owned by`
  rather than duplicating acceptance criteria.
- Record the exact baseline route count, migration chain, unit/integration skip
  inventory, E2E project split, and core journey specs.
- Add a lightweight validation script or existing-docs check extension that
  rejects duplicate open IDs, missing required metadata, broken tracked links,
  and open entries marked resolved.

**Backlog ownership:** TECH-DOCS-003, metadata portion of CR-053-21.

**Exit gate:** every genuinely open item appears exactly once, every resolved
item is archived, and the dependency path from CR-WF-01..08 to RELEASE-002 is
machine-checkable or deterministically reviewable.

### WP1 - Schema, entity, and mapper invariants

**Goal:** create one authoritative persisted representation for every core
workflow concept before changing services or UI.

**Backend changes:**

- Fold fresh-install schema fixes into the existing migration chain; do not add
  upgrade shims or production-data backfills.
- Guarantee one instance house and race-safe first-owner creation.
- Give every workflow entity an authoritative house ownership chain usable by
  authorization queries.
- Replace legacy/canonical dual fields with one task visibility representation,
  one recurrence vocabulary, and one assignee representation.
- Remove writer-less sync remnants unless a separately approved ADR gives them
  an active owner.
- Add mapper primitives for deterministic lock order, atomic replacement, and
  completion-effect lookup/inversion. Transaction plumbing must have one owner;
  do not spread new `IDBConnection` calls across services.
- Preserve ADR-009 house-timezone storage and half-open date windows.

**Frontend changes:** none beyond contract fixtures; UI work waits for stable
service contracts.

**Backlog ownership:** CR-053-07, CR-053-09, persistence parts of CR-053-19,
CR-055-03/04, foundation portion of CR-SVC-40.

**Exit gate:** a clean Docker volume installs the final schema, duplicate/race
regressions pass, and no core read merges competing legacy representations.

### WP2 - Membership, role, visibility, and privacy boundaries

**Goal:** make service-layer authorization match the recorded household model.

**Backend changes:**

- Separate the one-time first-owner bootstrap from ordinary membership checks.
- Require an existing invited membership for every subsequent read or mutation;
  removing a member must remain effective after reload and direct API access.
- Centralize creator-plus-assignees private-task access and admin-only/owner
  access in reusable service policy methods.
- Enforce owner-only house/structure/checklist-definition mutations inside
  services, retaining controller checks as defense in depth.
- Validate the acting user and target principals for assignment, role, ownership,
  and lifecycle changes inside the mutation aggregate.
- Standardize 403 vs anti-enumeration 404 responses and safe controller logging.
- Remove user-authored names, raw response bodies, and sensitive payloads from
  backend/frontend logs.
- Record invite-only membership in a new ADR that narrows ADR-006's bootstrap
  description without weakening its service-authorization decision.

**Frontend changes:**

- Hide owner-only controls from members/children based on the authoritative
  current-user payload, while still treating backend checks as mandatory.
- Provide explicit invited/removed/not-authorized states instead of generic 500s.

**Backlog ownership:** CR-WF-01; core remainders of CR-053-16/17/18;
FEAT-UX-015 owner-transfer/last-owner semantics only.

**Exit gate:** the rewritten authorization integration matrix has no live skips;
owner, member, child, invited, removed, and non-member paths all have positive
and negative controller/service coverage.

### WP3 - Atomic house setup and structure

**Goal:** make first-run setup one reliable owner operation with recoverable
invitation feedback.

**Backend changes:**

- Move the empty-house assertion and house lock into the setup transaction.
- Include the validated house name in the setup command and transaction.
- Create floors, rooms, automatic hallway/household rooms, materialized tasks,
  and initial room-task links through one rollback-safe aggregate.
- Validate all names, room types, template levels, capacity limits, and initial
  assignees before the first write, then revalidate relevant membership under
  the transaction boundary.
- Keep Nextcloud account invitation outside the app-data transaction when
  required by the platform, but return per-recipient success/failure and never
  misreport the structural setup outcome.
- Keep all setup strings localized and all errors in the standard API envelope.

**Frontend changes:**

- Keep one progressive wizard: house basics -> rooms/people -> preview -> apply
  -> invite family.
- Preserve user input after recoverable validation failures.
- Show exactly what was created and which invitations succeeded or failed.
- Make the wizard usable on mobile and keyboard-only, with clear labels and
  touch targets at least 44 px.

**Backlog ownership:** setup/structure half of CR-WF-03. CR-WF-04/08 remain
owned by WP7 and consume this package's stable contracts.

**Exit gate:** the empty-house E2E profile completes the wizard entirely through
the UI; a forced failure proves zero partial structure/task writes.

### WP4 - Atomic task creation and canonical assignment

**Goal:** every user-facing create path produces one visible, valid room task or
no persisted task at all.

**Backend changes:**

- Replace the client-owned multi-request aggregate with one service-owned
  transaction for task definition, room-task materialization, and initial
  assignees. Reuse it for custom and template paths.
- Validate the fully materialized task payload against one closed set of task
  constraints before persistence.
- Persist recurrence, scheduling mode, due date, flex window, visibility,
  instructions, checklist definition, and assignees consistently.
- Make set/bulk/setup assignment use the same authoritative assignee store,
  actor policy, lock order, and response shape.
- Keep room -> floor -> house ownership checks inside the service aggregate.

**Frontend changes:**

- Provide one creation form with optional template prefill, cascading room
  picker, assignee chips, recurrence/due/flex controls, visibility explanation,
  and a concise preview.
- Remove or repair controls that submit fields not consumed by the backend.
- Do not close the form or report success until the aggregate succeeds.
- Make owner/member permissions explicit; children receive a read/execute view,
  not hidden editing controls that fail after click.

**Backlog ownership:** task/assignment half of CR-WF-03 and the relevant
CR-SVC-41 ownership/DI remainder. CR-WF-03 is the only umbrella intentionally
implemented by two sequential packages (WP3 then WP4).

**Exit gate:** custom/template, single/multi/bulk assignment, private/admin/public,
rollback, and date/recurrence permutations pass unit, real-DB integration, and
GUI tests with no orphan task definitions.

### WP5 - Completion ledger and full transactional undo

**Goal:** completion and undo become exact, idempotent state transitions.

**Backend changes:**

- Define a completion-effect record that identifies the occurrence and stores
  the authoritative before-state plus every applied domain delta needed for an
  exact inverse. Record the design in a completion/undo ADR.
- Lock the room task, task definition, room, acting membership, and reward state
  in one documented deterministic order before checking or mutating values.
- Reject inactive, stale, unauthorized, or duplicate occurrences before rewards,
  cleanliness, checklist, or recurrence writes.
- Advance recurrence and reset occurrence-scoped checklist progress atomically.
- Keep notifications and other external-facing effects post-commit; a post-commit
  failure cannot turn a committed completion into an ambiguous API failure.
- Replace split uncomplete/reactivate behavior with one idempotent undo service
  operation that restores recurrence, ledger, rewards/streak, cleanliness,
  checklist progress, and derived state from the completion-effect record.
- Preserve existing reward formulas and event vocabulary. Undo reverses recorded
  deltas; it does not recalculate policy with today's configuration.

**Frontend changes:**

- Consume one completion payload and reconcile every owning store before emitting
  celebration events.
- Queue independent undo entries so a second completion does not overwrite the
  first eligible undo action.
- Show simple completed/undo states without exposing reward implementation details.

**Backlog ownership:** CR-WF-02; undo/cache portions of CR-053-20; UX-015;
completion-related CR-SVC-15/40 constraints (implementation remains owned here,
structural cleanup waits for WP8).

**Exit gate:** real two-connection tests cover same-task and same-room concurrency,
retry after post-commit failure, inactive occurrence rejection, and complete
completion -> undo state equality across all persisted domain fields.

### WP6 - Actionable scheduling through My Day

**Goal:** scheduling produces a confirmed plan for real room tasks rather than a
detached advisory view.

**Backend changes:**

- Keep the existing scheduling surface and algorithms; do not introduce a second
  optimizer.
- Make preview return an authoritative change set tied to current room-task IDs,
  proposed assignees, house-local due dates, capacity impact, and validation
  warnings.
- Add one owner-confirmed apply aggregate that revalidates membership, visibility,
  task activity, and current versions, then writes assignments/due dates in one
  transaction. Suggestions never mutate state implicitly.
- Apply child availability and daily limits after visibility and assignment
  eligibility, with deterministic ordering.
- Ensure Calendar and filtered-daily/My Day derive from the applied room-task
  state and the house timezone.
- Resolve the reader-less `todayConsumedEnergy` path by either deriving it from
  authoritative scheduled/completed tasks or removing it; no duplicate client
  counter remains.
- Record the preview/confirm/apply contract in an ADR or the scheduling
  engineering documentation before implementation finishes.

**Frontend changes:**

- Present schedule generation as Preview -> Review warnings/capacity -> Apply.
- Explain proposed changes in household language (person, task, day), not optimizer
  terminology.
- Owners can adjust/confirm; members and children see their resulting plan but
  cannot mutate household scheduling policy.
- Applied changes appear immediately in Calendar and My Day without reload.

**Backlog ownership:** CR-WF-07 and CR-055-01. CR-WF-04 remains owned by WP7
and consumes this package's applied-schedule contract.

**Exit gate:** an owner creates tasks, applies a schedule, switches to a child,
and the child sees exactly the assigned due tasks in My Day and Calendar.

### WP7 - Canonical client state and family-friendly core UX

**Goal:** make the complete journey comfortable, understandable, accessible, and
consistent across owner, member, and child roles.

**Cross-layer changes:**

- Define canonical enriched payloads for house/current user, rooms, room tasks,
  assignees, daily tasks, completion/undo, scheduling changes, and invitations.
- Keep `useTasksStore` responsible for definitions and `useRoomTasksStore`
  responsible for instances; route all network calls through `src/services/api.js`.
- Reconcile room, house, user, game, task, scheduling, and dashboard count caches
  from authoritative responses or coordinated refetches.
- Sequence or cancel overlapping fetches so stale responses cannot replace newer
  navigation/state.

**Family-friendly UX requirements:**

- The owner journey uses progressive disclosure and one primary action per step.
- A member/child never sees owner controls that will predictably fail.
- My Day is the child's default operational surface: assigned/unassigned tasks,
  room, optional checklist, clear Done action, and reversible feedback.
- Core setup/task/execution can be understood without knowing coins, tiers,
  streaks, energy formulas, or scheduling algorithm names.
- Copy is short, neutral, encouraging, localized through `t()`, and never blames
  a child for overdue or failed tasks.
- All core actions support keyboard use, visible focus, screen-reader labels,
  mobile layouts, 44 px touch targets, explicit loading/empty/error states, and
  recoverable confirmations.
- Private/admin visibility and role limitations are explained before submission,
  not only after a denied request.

**Backlog ownership:** CR-WF-04, CR-WF-08, remaining core parts of CR-053-20.

**Exit gate:** owner, member, and child Playwright journeys pass at desktop and
mobile viewports; accessibility and role-specific UI assertions have no
conditional skips.

### WP8 - Batching, decomposition, and performance hardening

**Goal:** reduce complexity and request/query fan-out after behavior and contracts
are frozen.

**Changes:**

- Replace TaskManager request-per-room and Today request-per-task assignee loads
  with house-scoped batched reads and enriched payloads from WP7.
- Split `TaskList` into focused row, assignment, bulk toolbar, checklist, and
  due-date units without duplicating domain logic.
- Decompose completion into cohesive internal phases while preserving WP5's one
  transaction and lock order.
- Remove service-locator access, DI inconsistencies, circular store dependencies,
  and obsolete re-fetch patterns.
- Move transaction/lock plumbing behind the agreed mapper/repository boundary
  where doing so does not hide aggregate ownership.
- Add request/query-count regression bounds for setup, TaskManager, My Day,
  scheduling preview/apply, completion, and undo.

**Backlog ownership:** CR-WF-06, CR-SVC-15/40/41/44 (structural remainders after
the WP4/WP5-owned portions).

**Exit gate:** the full E2E journey is behavior-identical to WP7, while request
and query budgets are pinned and no production file exceeds the size guideline
without a documented reason.

### WP9 - Deterministic coverage and release-quality closure

**Goal:** prove the program from a clean installation and remove test gaps that
can make a broken core workflow appear green.

**Changes:**

- Replace skipped live authorization scenarios with current controller/API tests;
  delete only tests for routes that no longer exist.
- Replace tautological, swallowed, or fixture-dependent completion assertions
  with exact state and payload deltas.
- Add an authenticated empty-house E2E profile and isolate seed-mutating projects
  from non-seed projects.
- Replace/rename `gui-scratch-flow.spec.ts` with one deterministic household
  journey that covers setup -> invitations -> task creation -> assignment ->
  scheduling -> child My Day -> completion -> full undo.
- Keep focused specs for each failure/authorization boundary; the long journey is
  not a substitute for unit and integration tests.
- Close core l10n/doc/API truth gaps and integrate every durable decision into
  ADRs, concepts, engineering docs, API docs, and testing docs.
- Run a final Full Review of the whole journey. Only a passing review may move
  this master plan to `done/` or unblock RELEASE-002.

**Backlog ownership:** CR-WF-05; core test portions of CR-053-13/16/17/18/21;
RELEASE-002 is unblocked but not automatically executed.

**Exit gate:** all mandatory gates pass with zero new or unapproved skips and no
open P1 core-workflow item.

## 4. Tests

### 4.1 Test policy per work package

- Write or update the narrow regression first.
- Run targeted PHPUnit/Jest tests during implementation.
- Run Docker integration tests for every persistence, authorization, transaction,
  scheduling, completion, or undo change.
- Run the affected Playwright project before review.
- Run the full build gate after every completed work package and again after any
  review auto-fix.
- No required test may pass by conditional fixture skip, broad `catch`, weak
  inequality, or assertion that accepts every result.

### 4.2 Backend test matrix

| Layer | Required coverage |
|-------|-------------------|
| Migration/entity | Clean install, unique/foreign-key/index constraints, canonical enum/assignment fields, serialization |
| Mapper | House-scoped reads, atomic replacement, lock order, race handling, query counts |
| Service | Every invariant and rollback path for setup, creation, assignment, scheduling, completion, undo |
| Controller | Validation, envelope/status, membership/role/visibility denial, anti-enumeration behavior |
| Concurrency | Bootstrap, membership/assignment changes, same-task completion, same-room completion, completion/undo retry |

### 4.3 Frontend test matrix

| Layer | Required coverage |
|-------|-------------------|
| API client | Route, method, payload, response-envelope, error normalization |
| Pinia | Authoritative cache reconciliation, request ordering, role changes, completion/undo, schedule apply |
| Components | Owner/member/child rendering, accessible controls, form validation, loading/error/empty states |
| Utilities | House-timezone date keys, scheduling labels, deterministic sorting |

### 4.4 Required E2E journeys

| Journey | Profile | Required proof |
|---------|---------|----------------|
| Fresh owner setup | New authenticated empty-house profile | Exactly one house/owner; complete wizard; atomic persisted structure |
| Invite and roles | Family | Invited users join; removed/non-invited users are denied; owner/member/child UI differs correctly |
| Task creation | Family | Custom/template creation, room, recurrence/due/flex, visibility, checklist, assignment; no partial state |
| Scheduling | Family | Preview, warnings, owner-confirmed apply, Calendar/My Day update |
| Child My Day | Family child | Only eligible tasks, understandable content, checklist, Done, mobile/touch/a11y |
| Completion and undo | Family | Exact completion deltas and exact full inverse after undo/reload |
| Failure recovery | Empty + family | Validation, rollback, duplicate/retry, stale preview, post-commit failure, offline/error UI |
| Privacy/authorization | Family roles | Direct API and UI denial for every forbidden cross-role/cross-house path |

The canonical long journey runs serially with one database owner. Seed/reset
fixtures must never execute concurrently with a live journey.

### 4.5 Quality gate

```text
npm run lint:fix          # only when its write scope is safe
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

## 5. File Manifest

This is the expected program manifest. Each work package must narrow it to an
exact reviewed list before implementation; additions require a plan update.

```text
new:      docs/adr/ADR-010-invite-only-house-membership.md
new:      docs/adr/ADR-011-transactional-completion-and-undo.md
new:      docs/adr/ADR-012-actionable-household-scheduling.md
new:      tests/e2e/core-household-journey.spec.ts (replace/rename gui-scratch-flow.spec.ts)
new:      focused PHPUnit/Jest regression files where no current owner exists

modified: docs/backlog/{backlog.md,ACTIVE_TASKS.md,archive/YYYY-MM.md}
modified: docs/plans/active/cs-plan-058-core-workflow-bottom-up-masterplan.md (new/ -> active/ on review pass)
modified: docs/{concepts,engineering,api,testing,standards}/**/*.md as durable outcomes require
modified: appinfo/routes.php only for reviewed aggregate contract changes
modified: lib/Migration/Version*.php (fold fresh-install schema; no compatibility migrations)
modified: lib/Db/{House,HouseMapper,HouseUser,HouseUserMapper,Task,TaskMapper,RoomTask,RoomTaskMapper,TaskAssignee,TaskAssigneeMapper,TaskChecklistItem,TaskChecklistItemMapper,CompletedTask,CompletedTaskMapper,RoomTaskChecklistProgress,RoomTaskChecklistProgressMapper}.php
modified: lib/Service/{AuthorizationService,HouseService,HouseSetupService,FloorService,RoomService,TaskService,RoomTaskService,ChecklistService,TemplateService,CalendarService,SchedulingService,GameLogicService,NotificationService}.php
modified: lib/Controller/{HouseController,SetupController,FloorController,RoomController,TaskController,RoomTaskController,ChecklistController,UserController,SchedulingController}.php
modified: src/services/api.js | src/utils/{dateKeys,logger}.js
modified: src/store/{house,user,setup,rooms,tasks,roomTasks,checklist,scheduling,game}.js
modified: src/views/{Dashboard,Settings,TaskManager,RoomDetail,TodayView,SchedulingView,CalendarView}.vue
modified: src/components/{Setup,Members,Tasks,Scheduling,Dashboard,Common}/**/*.{vue,js}
modified: tests/unit/**/* | tests/integration/**/* | tests/e2e/**/* | playwright.config.ts
modified: l10n/{en.json,de.json,templates/chorestrike.pot}
```

Reward/minigame/title/notification files may change only when mechanically needed
to preserve completion/undo side-effect contracts; any product-rule change moves
to a separate reviewed plan.

## 6. Implementation Order

1. Run the mandatory Standard A plan review; resolve all questions, update this
   plan, and let a passing review activate it.
2. Execute WP0 and freeze the dependency-oriented backlog structure.
3. Execute WP1; reset the disposable Docker database and prove a clean install.
4. Execute WP2; record ADR-010 and close the security-blocked review boundary.
5. Execute WP3 and WP4 sequentially; do not build frontend shortcuts around
   incomplete aggregate endpoints.
6. Execute WP5; record ADR-011 and preserve all reward side-effect tests.
7. Execute WP6; record ADR-012 and prove schedule apply -> Calendar/My Day.
8. Execute WP7; complete the owner/member/child UX and canonical store contracts.
9. Execute WP8 only after WP7 payloads and behavior are frozen.
10. Execute WP9; run the full gate and final Full Review.
11. Archive every completed backlog item immediately, integrate durable knowledge,
    and move this plan to `done/` only through a passing final review.

## 7. Risks & Mitigations

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Program scope becomes one unreviewable rewrite | High | Sequential work packages, one semantic owner, Standard B review per package, split child plans only at reviewed boundaries |
| Backlog restructuring loses history or open work | Medium | Archive resolved stubs first, inventory before/after, duplicate-ID/link/metadata checker |
| Invite-only change locks out legitimate users | Medium | Empty-owner, invited, removed, non-invited, member, child integration/E2E matrix before UI rollout |
| Fresh-install schema changes drift across databases | Medium | Modify the existing chain only, run migration smoke and real-DB integration from a clean volume |
| Completion/undo deadlocks or reverses the wrong effects | High | ADR lock order, immutable effect record, two-connection tests, idempotency keys, inverse uses recorded deltas |
| Reward details expand the core scope | High | Freeze formulas/events as contracts; only mechanical completion/undo integration is allowed |
| Scheduling silently surprises the household | Medium | Preview plus explicit owner confirmation; stale previews rejected; no implicit writes |
| Child-friendly UX remains subjective | Medium | Concrete role/copy/touch/a11y criteria, mobile Playwright assertions, child-persona manual acceptance pass |
| E2E remains green through skips or shared-seed races | High | Dedicated empty/family profiles, serial journey owner, zero required skips, exact assertions |
| Performance refactor changes semantics | Medium | WP8 runs only after contract freeze; before/after contract and E2E tests plus query budgets |
| Concurrent work modifies the same hot services | Medium | Exactly one active work package for RoomTaskService/RoomService/SchedulingService; rebase/review before package start |
| Nextcloud platform capabilities constrain invitation/setup integration | Medium | Keep platform invitations outside the app-data transaction; report per-recipient success/failure; never misreport the structural setup outcome (WP3) |

## 8. Acceptance Criteria

### Backlog and program control

- [ ] `backlog.md` contains only genuinely open items, structured by dependency
      and program ownership; resolved history is in the monthly archive.
- [ ] Every open item has standardized metadata and exactly one owning umbrella
      or standalone owner; there are no duplicate IDs or duplicated acceptance criteria.
- [ ] CR-WF-01..08 each have one explicit work-package owner; CR-WF-03 alone is
      the documented two-package aggregate (WP3 + WP4), and RELEASE-002 lists
      this program's blocking gates.

### Persistence and authorization

- [ ] A clean install creates exactly one instance house and one first owner even
      under concurrent first requests.
- [ ] Membership after first-owner bootstrap is invitation-only; removal remains
      effective after reload and direct API access.
- [ ] Task definitions, room tasks, assignments, checklists, and lifecycle actions
      have one authoritative house/visibility/assignee ownership model.
- [ ] Owner-only and private/admin policies are enforced inside mutation services
      and covered by positive/negative integration tests.

### Setup, tasks, and scheduling

- [ ] House name, structure, automatic rooms, templates, and initial assignments
      are applied atomically; invitation outcomes are explicit.
- [ ] Custom and template task creation either produces a complete visible room
      task with canonical assignees or rolls back every write.
- [ ] Recurrence, due date, flex window, visibility, checklist, and assignment
      fields persist and round-trip through API, stores, and UI.
- [ ] Scheduling preview references real room tasks; owner-confirmed apply updates
      assignees/due dates atomically and immediately feeds Calendar and My Day.
- [ ] Child availability, visibility, assignment, and daily limits are applied in
      the correct server-side order.

### Completion and undo

- [ ] Completion validates and locks one active occurrence and every reward-driving
      input before mutation; concurrent completions cannot lose room/member state.
- [ ] Completion success remains unambiguous when post-commit effects fail.
- [ ] Full undo is idempotent and restores recurrence, completion ledger,
      rewards/streak, cleanliness, checklist progress, and derived client state.
- [ ] Existing reward formulas, title/medal rules, notification contracts,
      minigames, and celebration event names remain regression-green.

### Family-friendly UX

- [ ] The full owner setup -> task -> scheduling journey is executable without
      manual API/DB steps and without understanding gamification details.
- [ ] An invited child reaches My Day, understands each task, uses optional
      checklist instructions, completes it, and can recover an accidental action.
- [ ] Owner/member/child controls match backend permissions; no predictable 403
      action is presented as available.
- [ ] Core views satisfy localization, keyboard, screen-reader, focus, mobile,
      44 px target, loading, empty, error, and recovery requirements.

### Tests, docs, and lifecycle

- [ ] Unit, integration, concurrency, contract, component, and E2E coverage from
      Section 4 exists and passes.
- [ ] The canonical household E2E journey passes from a clean authenticated
      empty-house profile with no conditional skips.
- [ ] Required tests run without new or unapproved skips; weak/swallowed
      assertions are removed from the core journey.
- [ ] Full quality gate in 4.5 is green after the final change.
- [ ] ADR-010/011/012 and all durable concepts/API/testing/architecture outcomes
      are integrated outside this temporary plan.
- [ ] Owning backlog items are archived as they complete; deferred add-on items
      remain outside the core program with explicit ownership.
- [ ] Final Full Review is `PASS` or `PASS-WITH-FIXES`; `ACTIVE_TASKS.md` is current.

## 9. Program Progress

| Work package | Status | Review | Gate | Backlog disposition |
|--------------|--------|--------|------|---------------------|
| WP0 Backlog/baseline | Done (2026-09-02) | Standard B PASS-WITH-FIXES (2026-09-02) | Applicable docs gate green | TECH-DOCS-003 + CR-053-21 metadata slice closed; CR-055-02 folded into CR-053-13; BUG-E2E-003 open item renumbered BUG-E2E-005; five-section restructure + metadata + `backlog:check` landed |
| WP1 Persistence invariants | Done (2026-09-03) | Standard B PASS-WITH-FIXES (2026-09-03) | Full gate green (unit 781, integration 229, Jest 780, Playwright chromium+serial-db 181, `cs:check`, clean-volume install; rerun by review) | CR-053-07, CR-053-09, CR-055-03, CR-055-04 archived; CR-053-19 re-scoped to its service-level remainder; CR-SVC-40 re-scoped to WP5+WP8 portions. Note: WP1's frontend line was superseded for mechanical contract-key removals (ratified decision D10 of CS-PLAN-060) — payload-key removals in `TaskEditForm`/`TaskList`/`TaskManager`/`TodayView` were in scope |
| WP2 Authorization boundaries | Not started | Pending | Pending | Pending |
| WP3 Atomic setup | Not started | Pending | Pending | Pending |
| WP4 Atomic tasks/assignment | Not started | Pending | Pending | Pending |
| WP5 Completion/full undo | Not started | Pending | Pending | Pending |
| WP6 Actionable scheduling | Not started | Pending | Pending | Pending |
| WP7 Family UX/client state | Not started | Pending | Pending | Pending |
| WP8 Performance/architecture | Not started | Pending | Pending | Pending |
| WP9 Coverage/release closure | Not started | Pending | Pending | Pending |

The Standard A plan review passed on 2026-09-02 and this plan is active in
`docs/plans/active/`; work packages execute in the order above, one `In
Progress` package at a time.

## 10. Plan Review Decision Register

The Standard A review must ratify or revise these proposed implementation
decisions before activating the plan:

| ID | Proposed decision | Rationale | Status |
|----|-------------------|-----------|--------|
| D1 | Archive resolved stubs, then restructure open backlog by dependency and umbrella ownership | Preserves history while making the critical path executable | Ratified 2026-09-02 |
| D2 | Store an immutable completion before-state plus applied deltas and use those deltas for undo | Avoids recalculating an inverse with changed reward/scheduling policy | Ratified 2026-09-02 |
| D3 | Permit full undo for the original completer or a house owner; keep the short UI window while enforcing idempotency server-side | Preserves child-friendly recovery without making arbitrary users mutation principals | Ratified 2026-09-02 |
| D4 | Scheduling remains preview-only until an owner explicitly applies a versioned room-task change set | Prevents surprising implicit assignment or due-date changes | Ratified 2026-09-02 |
| D5 | Replace, rather than duplicate, `gui-scratch-flow.spec.ts` with the canonical serial household journey | One authoritative E2E journey reduces drift and shared-seed flakiness | Ratified 2026-09-02 |

Any rejected decision requires this plan, its affected backlog acceptance
criteria, and the corresponding risk/test sections to be updated before the
review can pass.

## 11. Review Outcome

**Standard A plan review — 2026-09-02: PASS (GO).**

- Reviewed against the review skill §3 shared checklist plus plan-only checks.
- All referenced backlog IDs (CR-WF-01..08, CR-053-*, CR-055-*, CR-SVC-*,
  TECH-DOCS-003, UX-015, FEAT-UX-015, RELEASE-002/003), the 2026-09-02 Full
  Review record, ADR numbers 010-012, manifest paths, quality-gate commands,
  Playwright projects, and Docker invocations were verified against the live
  tree. The recorded review decisions match §1.1.
- Decisions D1-D5 ratified without revision.
- Inline fixes: clarified the CR-SVC-15/40/41/44 ownership split in WP8, added
  the Nextcloud platform-invitation risk row, and corrected this plan's
  manifest path to `active/`.
- No open findings. Recommended next state: `active/`; the move remains a
  manual user action per project-rules §7.

**Standard A verification pass — 2026-09-02: PASS (GO) confirmed.**

- Independent re-review re-verified every referenced backlog ID, review record,
  ADR number, manifest path, quality-gate command, Playwright project, and
  Docker invocation against the live tree; D1-D5 remain ratified.
- Additional inline fixes: added CR-053-13 and RELEASE-002/003 to the
  related-items header; added the `chromium-scheduling-owner` project to the
  4.5 quality gate (the three scheduling specs are ignored by the `chromium`
  project, so the gate previously never ran them); completed the section 5
  manifest with the checklist entities and the TemplateService/CalendarService
  implied by WP3/WP4/WP6.
- No open findings. Recommended next state remains `active/`; the move remains
  a manual user action per project-rules §7.
