# Backlog — Open Items

> **Single source of truth for everything not yet done.** Entries are organized
> into the five dependency-oriented sections fixed by
> [CS-PLAN-058 §2.2](../plans/active/cs-plan-058-core-workflow-bottom-up-masterplan.md):
> **Core Workflow Program**, **Core Hardening**, **Deferred Add-ons**,
> **Infrastructure and Documentation**, and **Release**. Every open entry carries
> the standardized metadata block (see [`README.md`](README.md) for the format).
> **Done work does not live here** — completed items move to
> [`archive/YYYY-MM.md`](archive/) at completion. `npm run backlog:check`
> machine-verifies open-only entries, unique IDs, metadata, dependency
> references, and tracked links.
>
> Priority: **P0** critical · **P1** high · **P2** should-have · **P3** nice-to-have · **P4** future.

---

## Core Workflow Program

> The CR-WF umbrellas own the core-journey acceptance criteria
> ([CS-PLAN-058](../plans/active/cs-plan-058-core-workflow-bottom-up-masterplan.md);
> review record: [`archive/CODE_REVIEW_2026-09-02.md`](archive/CODE_REVIEW_2026-09-02.md)).
> Older findings below are annotated `Owning: CS-PLAN-058 WPx` (portions) or
> `Folded-in` instead of duplicating umbrella criteria. Public security entries
> are intentionally sanitized; sensitive technical evidence is excluded from the
> public repository under the security-disclosure policy.

### [P1] CR-WF-02 — Make completion and undo transactionally complete
**Status**: Backlog · **Effort**: XL · **Layer**: backend+frontend · **Journey stage**: completion/undo
**Owning**: CS-PLAN-058 WP5 · **Depends on**: — · **Blocks**: CR-WF-07 · **Folded-in**: —
**Source**: Full Review of the core task workflow (2026-09-02)

The completion aggregate has state, concurrency, and post-commit ambiguity gaps;
the current split undo restores only part of the completed occurrence. The user
selected full transactional undo on 2026-09-02.

- [ ] Validate and lock the current active occurrence and all reward-driving inputs before mutation.
- [ ] Preserve concurrent room-state updates and reset occurrence-scoped checklist progress.
- [ ] Keep fallible post-commit effects from changing an already-committed success into an ambiguous failure.
- [ ] Undo room-task recurrence, completion ledger, rewards/streak, cleanliness, and derived state in one inverse transaction.
- [ ] Add real-DB concurrency, retry, inactive-occurrence, and full-undo regressions.

---

### [P1] CR-WF-03 — Make setup, creation, and assignment atomic and canonical
**Status**: In Progress (setup/structure half delivered by CS-PLAN-062; task-creation half in progress via CS-PLAN-063/WP4, activated 2026-09-05) · **Effort**: L · **Layer**: backend+frontend · **Journey stage**: setup/creation
**Owning**: CS-PLAN-058 WP3+WP4 · **Depends on**: — · **Blocks**: CR-WF-02 · **Folded-in**: —
**Source**: Full Review of the core task workflow (2026-09-02)

Setup and both task-creation paths span validation and persistence boundaries;
assignment is split between two persisted representations with divergent write
semantics. CR-WF-03 is the only umbrella implemented by two sequential
packages: the setup/structure half is owned by **WP3**, the task-creation and
canonical-assignment half by **WP4**.

- [x] Lock and recheck the first-run setup invariant inside the setup transaction, including the requested house name. *(Delivered 2026-09-04 by CS-PLAN-062/WP3: house lock + in-transaction floor recheck, optional `houseName` in the setup command, membership revalidation under the lock, zero-write rollback proofs, `emptyhouse` E2E journey.)*
- [ ] Create a task definition, room link, and initial assignees as one rollback-safe aggregate. *(WP4)*
- [ ] Use one authoritative assignee representation for setup, single replacement, bulk assignment, reads, and completion.
- [ ] Persist every supported scheduling field, including flex windows and an explicit creation due date.
- [ ] Cover rollback, replacement, bulk, and setup-assignment behavior with integration tests.

---

### [P2] CR-WF-04 — Reconcile daily workflow payloads and client state
**Status**: Backlog · **Effort**: L · **Layer**: backend+frontend · **Journey stage**: daily workflow
**Owning**: CS-PLAN-058 WP7 · **Depends on**: CR-WF-03, CR-WF-07 · **Blocks**: CR-WF-06 · **Folded-in**: —
**Source**: Full Review of the core task workflow (2026-09-02)

Several live workflow responses and Pinia consumers disagree about authoritative
state, causing incorrect limits/counts, stale completion feedback, and lost UI
events.

- [ ] Apply viewer eligibility before child daily limits and use house-local parsing for date-only due dates.
- [ ] Define canonical enriched payloads for daily tasks, assignees, completion rewards, titles, and invitations.
- [ ] Reconcile room, house, user, game, and task caches after completion and undo.
- [ ] Populate Dashboard due/overdue counters from the slices their fetch actions actually update.
- [ ] Add contract tests for each backend response, store mutation, and visible result.

---

### [P1] CR-WF-05 — Replace weak or skipped core-workflow coverage
**Status**: Backlog · **Effort**: L · **Layer**: tests · **Journey stage**: test coverage
**Owning**: CS-PLAN-058 WP9 · **Depends on**: CR-WF-06, CR-WF-08 · **Blocks**: RELEASE-002 · **Folded-in**: —
**Source**: Full Review of the core task workflow (2026-09-02)

Core authorization scenarios remain unconditionally skipped, some completion E2E
assertions are tautological or data-dependent, and the first-run wizard happy
path has no deterministic browser profile.

- [ ] Replace skipped live authorization cases with current controller/API tests; delete only obsolete-route cases.
- [ ] Make completion/reward assertions deterministic and remove swallowed assertions and fixture-dependent skips.
- [x] Add an empty-house user profile that exercises the complete first-run wizard in Playwright. *(Delivered 2026-09-04 by CS-PLAN-062/WP3 as the WP3 exit-gate artifact: `emptyhouse` profile + `setup-wizard-journey.spec.ts` in the serial pool, no conditional skips.)*
- [ ] Keep seed-mutating and non-seed project groups isolated so database resets cannot invalidate active sessions.
- [ ] Require zero newly skipped core-workflow tests in the release gate.

---

### [P2] CR-WF-06 — Batch workflow reads and reduce orchestration hotspots
**Status**: Backlog · **Effort**: L · **Layer**: backend+frontend · **Journey stage**: performance
**Owning**: CS-PLAN-058 WP8 · **Depends on**: CR-WF-04 · **Blocks**: CR-WF-05 · **Folded-in**: —
**Source**: Full Review of the core task workflow (2026-09-02)

TaskManager and Today perform request-per-room/task fan-out, while the main task
list and completion aggregate remain oversized orchestration hotspots. This item
extends, rather than duplicates, **CR-SVC-15**, **CR-SVC-40**, **CR-SVC-41**, and
**CR-SVC-44**.

- [ ] Return house-scoped room tasks and batched assignees without frontend N+1 requests.
- [ ] Split `TaskList` into focused row, assignment, bulk, checklist, and due-date units.
- [ ] Decompose completion into cohesive phases without weakening its single transaction boundary.
- [ ] Remove the global service-locator lookup and preserve one-way store dependencies.
- [ ] Add query/request-count regression bounds for the reviewed journey.

---

### [P1] CR-WF-07 — Make scheduling produce an actionable household plan
**Status**: Planned · **Effort**: L · **Layer**: backend+frontend · **Journey stage**: scheduling
**Owning**: CS-PLAN-058 WP6 · **Depends on**: CR-WF-02 · **Blocks**: CR-WF-04 · **Folded-in**: —
**Source**: CS-PLAN-058 core workflow masterplan (2026-09-02)

The existing scheduling surface can preview capacity, but the core journey needs
an explicit, owner-confirmed bridge from suggestions to real room-task assignees
and due dates consumed by Calendar and My Day.

- [ ] Return a versioned preview tied to current room-task IDs, proposed assignees, house-local due dates, capacity, and warnings.
- [ ] Apply an accepted preview transactionally after revalidating membership, visibility, activity, and current task versions.
- [ ] Never mutate scheduling state implicitly; stale previews are rejected with recoverable feedback.
- [ ] Make applied changes immediately authoritative for Calendar and filtered-daily/My Day.
- [ ] Cover owner apply plus member/child read-only results with integration and E2E tests.

---

### [P1] CR-WF-08 — Deliver a family-friendly core journey
**Status**: Planned · **Effort**: L · **Layer**: backend+frontend · **Journey stage**: family UX
**Owning**: CS-PLAN-058 WP7 · **Depends on**: CR-WF-03 · **Blocks**: CR-WF-05 · **Folded-in**: —
**Source**: CS-PLAN-058 core workflow masterplan (2026-09-02)

The complete setup-to-execution flow must be comfortable for owners and
understandable for members and children without requiring knowledge of rewards or
scheduling internals.

- [ ] Keep setup and task creation progressive, recoverable, localized, and usable on desktop/mobile with keyboard and screen readers.
- [ ] Match visible controls to owner/member/child permissions; do not expose actions that predictably fail.
- [ ] Make My Day the clear child execution surface with room, optional checklist, large Done action, and reversible feedback.
- [ ] Use short, neutral, encouraging language and explicit loading, empty, error, and recovery states.
- [ ] Prove the owner/member/child journeys at desktop and mobile viewports without conditional skips.

---

### [P3] CR-053-19 — Persistence correctness minors (service-level remainder)
**Status**: Backlog · **Effort**: M · **Layer**: backend · **Journey stage**: persistence
**Owning**: CS-PLAN-058 WP5+WP8 · **Depends on**: — · **Blocks**: — · **Folded-in**: —
**Source**: CS-PLAN-053 full review (CR-P1-11..17, 19, 21, 22, 2026-08-27)

The persistence/schema parts (PostgreSQL boolean compares, inclusive week-window
end bound, checklist `toggle()` unlocked RMW, `createOrGet` check-then-insert,
`FloorMapper::create` re-select, `getUserRank` unbounded scan, inverted
estimatedMinutes range, dead `findActiveByHouse`, dead `$houseId` on
`findVisibleByHouse`, is_private divergence) were closed by CS-PLAN-060 WP1 —
see [`archive/2026-09.md`](archive/2026-09.md). Remainder: the service-level
read-modify-write races follow the work packages that touch their workflows.

---

### [P2] CR-SVC-15 — Decompose God-methods / God-services
**Status**: Backlog · **Effort**: L · **Layer**: backend · **Journey stage**: performance
**Owning**: CS-PLAN-058 WP5+WP8 · **Depends on**: — · **Blocks**: — · **Folded-in**: —
**Source**: Review of `lib/Service` (2026-08-04)

Owned in portions: WP5 enforces the completion-path constraints while
decomposing completion; WP8 executes the remaining structural decomposition
after the contract freeze. Over the ~300-line/~30-line guidelines:
`RoomService::completeRoomTask` (~200 lines),
`GameLogicService` (517, 7 concerns), `handleTaskCompletion` (~113) /
`checkAndAwardMedals` (~62, 5 near-identical award blocks), `RoomTaskService` (833),
`RewardConfigService` (689 → 538 via CS-PLAN-049 registry, 2026-08-26 — still over
the guideline, accepted), `TaskService::createTask` (24 positional params).
- [ ] Extract cohesive steps (overdue-takeover, post-commit notification dispatch,
      medal-rules table) and split GameLogicService along domain lines.

---

### [P3] CR-SVC-40 — Services reach below their layer (`IDBConnection` injection)
**Status**: Backlog · **Effort**: M · **Layer**: backend · **Journey stage**: persistence
**Owning**: CS-PLAN-058 WP5+WP8 · **Depends on**: — · **Blocks**: — · **Folded-in**: —
**Source**: Review of `lib/Service` (2026-08-04); scope updated by CS-PLAN-053 full review (2026-08-27)

The WP1 foundation portion landed (CS-PLAN-060 WP1, see
[`archive/2026-09.md`](archive/2026-09.md)): `lib/Db/TransactionExecutor` is
the single transaction owner, the six transactional services route through it
and no longer inject `IDBConnection`, and the deterministic lock order
(house → room_task → dependents) is documented in
[`../engineering/development.md`](../engineering/development.md).
Remainder: WP5 owns the completion-path lock/constraint enforcement while
decomposing completion; WP8 owns the remaining structural moves.
`TestDataService` keeps its documented D6 exemption (non-production test
tooling, query-builder only).

---

### [P3] CR-SVC-41 — Service DI inconsistencies (remainder)
**Status**: In Progress (WP4 slice — ownership-validation dedup — via CS-PLAN-063, activated 2026-09-05; structural remainder stays WP8) · **Effort**: M · **Layer**: backend · **Journey stage**: persistence
**Owning**: CS-PLAN-058 WP4+WP8 · **Depends on**: — · **Blocks**: — · **Folded-in**: —
**Source**: Review of `lib/Service` (2026-08-04)

Owned in portions: WP4 owns the ownership-validation/DI remainder relevant to
canonical assignment; WP8 the structural remainder after the contract freeze.
`getFilteredDailyTasksForUser` takes `HouseUserMapper` as a param despite ctor injection;
duplicated stat helpers; duplicated `validate*Ownership` across Floor/Room/RoomTask services.
The nullable-`?AppLogger` half (all 9 sites + trait + `RewardConfigController` wiring defect)
was **resolved 2026-08-27 by CS-PLAN-052** (record: [`archive/2026-08.md`](archive/2026-08.md)).

---

### [P3] CR-SVC-44 — Minor performance cluster
**Status**: Backlog · **Effort**: M · **Layer**: backend · **Journey stage**: performance
**Owning**: CS-PLAN-058 WP8 · **Depends on**: — · **Blocks**: — · **Folded-in**: —
**Source**: Review of `lib/Service` (2026-08-04); re-scoped by CS-PLAN-053 full review (2026-08-27) and CS-PLAN-055

Re-scoped: the `createDefaultTemplates` and
`extractHouseIdFromSchedule`/`ScheduleOptimizer` sub-claims are **obsolete**
(clusters deleted by CS-PLAN-027 D7 / CR-SVC-08);
`TaskService::getAllTasks` was deleted with the CS-PLAN-055 dead-code sweep.
Still live: `StreakFreeze`/`StreakRestock` per-user UPDATEs (unlocked RMW —
see CR-053-11 for the correctness half); ~~`assignTask`/`unassignTask`/`deactivate`/
`reactivate`/`updateDueDate` re-fetch pattern in `RoomTaskService`~~ (the
`assignTask`/`unassignTask`/`deactivateTask` mutators were deleted outright by
CS-PLAN-061 §3.4 — 2026-09-03 — removing three of the five re-fetch sites);
~~`hasFloors` hydrates all floors for a boolean (`HouseService:303`)~~ (resolved
2026-09-04 by CS-PLAN-062 §3.1: `hasFloors` delegates to the prepared
`FloorMapper::countByHouseId` COUNT primitive).

---

### [P3] CR-053-18-WP9 — Test-mode routes keep `@NoCSRFRequired` (release-hardening remainder)
**Status**: Open · **Effort**: S · **Layer**: backend · **Journey stage**: release
**Owning**: CS-PLAN-058 WP9 · **Depends on**: — · **Blocks**: — · **Folded-in**: —
**Source**: CR-053-18 remainder, re-scoped by CS-PLAN-061 decision D8 (user-ratified 2026-09-03)

The test-mode routes (`test#profiles`, `test#seed`, `test#reset`) keep
`@NoCSRFRequired` + the runtime `NEXTCLOUD_TEST_MODE` gate (D8): the E2E
infrastructure depends on plain API seeding, and CSRF tokens in test tooling
add churn without a production threat. The boundary is the env gate; the
"never set `NEXTCLOUD_TEST_MODE` in production" rule is documented in
`docs/standards/guidelines/security.md`. WP9 decides whether the release
build strips the routes entirely (build-time exclusion) or keeps the
documented contract.

---

### [P3] FEAT-UX-015 — Leave house (self-service membership exit)
**Status**: Open (semantics decided, endpoint/UI open) · **Effort**: M · **Layer**: backend+frontend · **Journey stage**: authorization
**Owning**: unassigned · **Depends on**: — · **Blocks**: — · **Folded-in**: —
**Source**: UX-003 remainder (CS-PLAN-038); owner-transfer/last-owner semantics decided by CS-PLAN-061 D6 (ADR-010, user-ratified 2026-09-03)

Members cannot leave a house on their own; removal is owner-only. The
owner-transfer semantics are decided (D6): the **last owner cannot leave** —
ownership must be transferred first (consistent with `CANNOT_REMOVE_OWNER` /
`CANNOT_DEMOTE_LAST_OWNER`; an ownerless house would allow stranger
re-bootstrap). Remaining: the backend leave endpoint + Settings action.

- [x] Decide owner-transfer/last-owner semantics for leaving. (D6, ADR-010, 2026-09-03)
- [ ] Backend leave endpoint (+ guards) + Settings Leave button + tests.

---

### [P3] CR-053-20 — Frontend correctness cluster
**Status**: Backlog · **Effort**: M · **Layer**: frontend · **Journey stage**: daily workflow
**Owning**: CS-PLAN-058 WP5+WP7 · **Depends on**: — · **Blocks**: — · **Folded-in**: —
**Source**: CS-PLAN-053 full review (CR-P4-01..04, 06, 08, 09, 2026-08-27); re-scoped by CS-PLAN-055

Owned in portions: WP5 owns the undo/cache-reconciliation slices, WP7 the
remaining canonical client-state parts.
(**wire-or-delete decided: delete** — the uncalled user-store reward actions
`addCoins`/`addContributionScore`/`updateStreak` were pruned with the dead-store layer,
CR-P4-03's deletion half executed)
False "Weekly goal reached!" celebration on every Dashboard mount while goal achieved;
minigame `initialize()` leaves loading stuck on catalog failure; `completeRoomTask` leaves
rooms/house/user caches stale (the reward-action wire option is gone — reconcile caches on
fetch instead); undo/reactivate slice drift; ~~`new Date(null)` →
epoch counted as overdue + UTC date parsing~~ **partially closed 2026-08-30**
(CS-PLAN-057 CR-P4-06 half: `totalDueTodayTasks`/`totalOverdueTasks` guard a
missing `nextDue` and all day keys unify on the house timezone via
`dateKeys.js` — pinned in `roomTasks.spec.js` and `dateKeys.spec.js`);
out-of-order range/activity fetches; invite duplicate row.

**Decision (user, 2026-09-02):** the former task-state-only undo limitation is
revoked. Undo must be one full inverse transaction; implementation and coverage
are owned by **CR-WF-02** above.

---

### [P3] UX-015 — Undo snackbar holds only the latest completion
**Status**: Backlog · **Effort**: S · **Layer**: frontend · **Journey stage**: completion/undo
**Owning**: CS-PLAN-058 WP5 · **Depends on**: — · **Blocks**: — · **Folded-in**: —
**Source**: Review of CS-PLAN-041 (2026-08-22)

The global `UndoSnackbar` (CS-PLAN-041) keeps a single undo slot: completing a
second task within the ~5 s window silently replaces the first task as the undo
target, so a misclick on the first task can no longer be reverted. Decide
between queueing undo targets (stacked/snackbar list) or explicitly accepting
the single-slot limitation in the design docs. Superseded in intent by the
2026-09-02 full-transactional-undo decision (CR-WF-02): the undo entry model is
owned with that umbrella.

---

### [P3] CR-055-01 — scheduling store: reader-less `todayConsumedEnergy` write cluster
**Status**: Backlog · **Effort**: S · **Layer**: frontend · **Journey stage**: scheduling
**Owning**: CS-PLAN-058 WP6 · **Depends on**: — · **Blocks**: — · **Folded-in**: —
**Source**: CS-PLAN-055 (record-and-delete boundary)

After the 9 superseded scheduling getters (incl. `todayRemainingEnergy`) were
pruned, `registerTodayEnergyConsumption` (called by `roomTasks.completeRoomTask`)
+ the `fetchUserPreferences` assignment for `todayConsumedEnergy` still write
state that **nothing reads** (the server's preferences payload carries the
field, so the fetch line survives). Decide: drop the client accumulation +
payload field (server is authoritative; also trim the `SchedulingService`
response) or surface remaining-today energy in the UI (feature decision).

---

### [P3] CR-062-01 — `setup#apply` coerces a non-string `houseName` to "absent"
**Status**: In Progress (folded into CS-PLAN-063 §3.1, activated 2026-09-05) · **Effort**: XS · **Layer**: backend · **Journey stage**: setup/creation
**Owning**: CS-PLAN-058 WP4 · **Depends on**: — · **Blocks**: — · **Folded-in**: —
**Source**: Review of CS-PLAN-062 WP3 final Standard B (2026-09-05)

`HouseSetupService::validateHouseName` keeps null for any non-string
`houseName` (e.g. a numeric/array value), so such a payload answers 200 and
keeps the current name instead of the 400 every other malformed payload field
yields ("present → validate" in decision D1). No security impact (nothing is
written; the wizard always sends a string). Align type strictness with the
WP4 task-creation validation-boundary hardening.

---

## Core Hardening

> Standalone correctness, API, persistence, i18n, and security remainders. Each
> entry states which work package it blocks (CS-PLAN-058 §2.2).

### [P2] CR-053-11 — Streak & leaderboard correctness cluster
**Status**: Backlog · **Effort**: M · **Layer**: backend · **Journey stage**: gamification
**Owning**: unassigned · **Depends on**: — · **Blocks**: — · **Folded-in**: —
**Source**: CS-PLAN-053 full review (CR-P2-11/12/13/15/20, 2026-08-27)

Referenced by no CS-PLAN-058 work package; independently schedulable.
(a) ~~Weekly leaderboard breaks all Sunday (`'monday this week'` → next Monday, `MinigameService:163`)~~
**Partially closed 2026-08-30** (CS-PLAN-057 D3 fold-in: the weekly
leaderboard and medal week run through `CalendarService::weekStart`, which
has correct Sunday semantics; pinned by `CalendarServiceTest` unit pins and
the `TimezoneDayBucketingTest` real-DB leaderboard window pin);
(b) completion-path freeze consumption never stamps the covered day → one missed day can cost
two freezes (`GameLogicService:131` + `HouseUser:294`); (c) at-risk pass vs freeze pass have
undefined execution order → core notification suppressed; (d) weekly restock SETs
`min(perWeek,cap)` instead of topping up — punishes earned bonus freezes (`HouseUser:308`);
(e) leaderboard queries lack deterministic tiebreakers. Each fix is small + testable.

---

### [P2] CR-053-13 — l10n artifact gap: 141 t() keys used in src/ missing from en+de
**Status**: Backlog · **Effort**: M · **Layer**: backend+frontend · **Journey stage**: i18n
**Owning**: CS-PLAN-058 WP9 · **Depends on**: — · **Blocks**: CS-PLAN-058 WP7 · **Folded-in**: CR-055-02
**Source**: CS-PLAN-053 full review (CR-P4-13/14/15, 2026-08-27)

German users get English fallback across TaskManager filters, Settings floor management,
Arcade, minigames; 8 keys are case-variant mismatches; ~340 dead artifact entries (keep
`titles.*` — server-driven). Root cause: `check-l10n-keys.js` only diffs de↔en. Fix: regenerate
artifacts + extend the checker with a src-usage diff; document in i18n.md.
**Folded-in CR-055-02** (archived 2026-09 — [`archive/2026-09.md`](archive/2026-09.md)):
dead artifact keys from the CS-PLAN-055-pruned surfaces (e.g. `Search query is
required`) drop out in the same regeneration pass — do not hand-prune entries.

---

### [P3] BUG-E2E-005 — `insights-flow.spec.ts` overcapacity-marker test structurally fails on Sundays
**Status**: Backlog · **Effort**: S · **Layer**: tests · **Journey stage**: test coverage
**Owning**: CS-PLAN-058 WP9 · **Depends on**: — · **Blocks**: CS-PLAN-058 WP9 · **Folded-in**: —
**Source**: CS-PLAN-055 Standard-B review E2E re-run 2026-08-30 (pre-existing; passed on the 08-29 Saturday run by date luck, NOT a CS-055 regression). Renumbered from BUG-E2E-003 by CS-PLAN-059 WP0 (BUG-E2E-004 was already consumed by the CS-PLAN-050/051 archive history).

The spec schedules 4 probe tasks to `tomorrow` and then expects `overcapacity-marker` inside `ScheduleCalendar`, but the calendar week starts Monday (`goToToday()`); on a Sunday "tomorrow" is the next Monday — outside the displayed week — while `overcapacity-days` (warning strip) scans today+0..6 and still shows the day. Fix: create the probes relative to the **visible** week (a due day ≥ today strictly inside the Monday-start week, e.g. Monday after Sunday), or navigate the calendar to tomorrow's week before asserting the marker. Also observed: Nextcloud stores two `weekly_goal` rows with identical (user, app, object_type, object_id) despite the documented dedup key — the per-house `claimWeeklyGoalNotification` guard is the dedup that actually holds; worth a note in `NotificationService` docs.

**Update 2026-09-05 (CS-PLAN-062 WP3 gate run):** the sibling assertion
`overcapacity-day` `toHaveCount(1)` now also fails on a **Saturday** run
(expected 1 day, received 2) — the probe-day capacity arithmetic is
date-dependent in the same way (seeded member availability differs per
weekday). Verified pre-existing: fails identically on a clean `master`
working tree (WP3 changes stashed). The same gate runs also saw one-off
full-matrix-load flakes in `dashboard.spec.ts:49`, `error-handling.spec.ts:78`,
and `room-management.spec.ts:179` — each passes in isolation; the 2026-09-05
Standard B full-pool re-run on a fresh disposable env reproduced only the
`room-management:179` one (10/10 green in isolation) while the insights-flow
assertion passed. All are pre-existing WP9/CR-WF-05 dispositions, not WP3
regressions.

---

### [P3] CR-053-22 — Test-gap closures deferred (deferral approved 2026-08-29)
**Status**: Backlog · **Effort**: L · **Layer**: tests · **Journey stage**: test coverage
**Owning**: CS-PLAN-058 WP9 · **Depends on**: — · **Blocks**: CS-PLAN-058 WP9 · **Folded-in**: —
**Source**: CS-PLAN-053 §4 (deferral approved by user 2026-08-29; risk accepted)

**Decision (user, 2026-08-29)**: **deferral approved** with backlog tracking (risk
accepted: the Minigame controller ships without integration-level
coverage until a catch-up plan). ~~The Checklist controller suite closes via
CS-PLAN-054 WP-4.~~ (closed there 2026-08-29: `ChecklistApiTest`, 24 real-DB tests)
Closed inline: `MinigameScoreTest` + `TaskAssigneeTest` entity suites (7 tests). Deferred with
reason (session scope of the XL review; each is mechanical but sizeable): integration suites
for ~~Checklist~~/Minigame controllers (~~the Checklist one is the CR-053-01
companion~~ — closed via CS-PLAN-054; ~~RewardConfig / TaskSync~~ mooted by the
CS-PLAN-055 prune — both controllers deleted), `TestDataSeedTest`, and Jest specs for the 8 uncovered components
(ActivityFeed, HouseStatsCards, MemberFairnessTable, WeekBudgetGrid, RoomPicker, BoostShop,
SnakeGame, SnakeLeaderboard). Deferral risk: the 1 remaining controller (Minigame) ships without
integration-level coverage; regression protection relies on unit + E2E.

---

## Deferred Add-ons

> Explicitly outside the core critical path (CS-PLAN-058 §1.3). These cannot
> enter the critical path without a plan update.

### [P3] FEAT-UX-018 — Task-approval workflow for children
**Status**: Backlog · **Effort**: L · **Layer**: backend+frontend · **Journey stage**: task approval
**Owning**: unassigned · **Depends on**: — · **Blocks**: — · **Folded-in**: —
**Source**: Review of docs/concepts/ui-use-cases.md (2026-08-21)

design.md §5 lists "task approval workflow for children"; gamification open
decisions touch it too. Today every completion is final immediately.

- [ ] Product decision recorded: approval flow vs. current auto-complete
      (which roles, which tasks).
- [ ] Backend: needs-review state + owner approve/reject endpoints with
      membership/owner gating + tests.
- [ ] Owner approval queue UI + child-facing "waiting for approval" feedback.

---

### [P4] FEAT-UX-019 — Task Pass & support actions (gag mechanic)
**Status**: Backlog · **Effort**: M · **Layer**: backend+frontend · **Journey stage**: gamification
**Owning**: unassigned · **Depends on**: — · **Blocks**: — · **Folded-in**: —
**Source**: Review of docs/concepts/ui-use-cases.md (2026-08-21)

gamification-and-rewards.md sketches a post-MVP "Task Pass" gag mechanic;
explicitly future / needs decision.

- [ ] Product decision whether the gag mechanic fits the target audience.
- [ ] Only after decision: concept extension + endpoint design sketch.

---

### [P3] FEAT-UX-023 — Onboarding & help layer
**Status**: Backlog · **Effort**: M · **Layer**: frontend · **Journey stage**: onboarding
**Owning**: unassigned · **Depends on**: — · **Blocks**: — · **Folded-in**: —
**Source**: Review of docs/concepts/ui-use-cases.md (2026-08-21)

No tour, glossary, or contextual hints; vocabulary drift (UX-007) makes
self-service discovery harder.

- [ ] Help entry point per major view (Dashboard, TodayView, SchedulingView).
- [ ] Glossary unifying the UX-007 vocabulary drift (priority labels,
      Admin-Only, Scope).
- [ ] Contextual hints for gamification terms (combo, medal, streak freeze).

---

### [P3] FEAT-UX-024 — Notification settings
**Status**: Backlog · **Effort**: M · **Layer**: backend+frontend · **Journey stage**: notifications
**Owning**: unassigned · **Depends on**: — · **Blocks**: — · **Folded-in**: —
**Source**: Review of docs/concepts/ui-use-cases.md (2026-08-21)

UC-14 reminders are passive server jobs with no settings surface.

- [ ] Backend: per-user notification preferences (storage + membership-gated
      endpoints).
- [ ] Settings surface: digest toggle, quiet hours; background jobs respect
      them.
- [ ] Service + settings tests.

---

### [P4] FEAT-UX-025 — Vacation / house-pause mode
**Status**: Backlog · **Effort**: M · **Layer**: backend+frontend · **Journey stage**: vacation mode
**Owning**: unassigned · **Depends on**: — · **Blocks**: — · **Folded-in**: —
**Source**: Review of docs/concepts/ui-use-cases.md (2026-08-21)

Future / needs decision: pause semantics undefined (streaks? due dates?
notifications? per-member vs whole-house).

- [ ] Product decision on pause semantics + scope recorded.
- [ ] Concept extension before any implementation.

---

### [P4] FEAT-UX-026 — Real-world reward shop
**Status**: Backlog · **Effort**: L · **Layer**: backend+frontend · **Journey stage**: rewards
**Owning**: unassigned · **Depends on**: — · **Blocks**: — · **Folded-in**: —
**Source**: Review of docs/concepts/ui-use-cases.md (2026-08-21)

Future / needs decision: coins → owner-defined real-world rewards beyond the
coin/boost model.

- [ ] Product decision + gamification-doc concept extension (reward model,
      redemption flow).
- [ ] Implementation only after the concept lands.

---

### [P4] FEAT-UX-027 — Print view (weekly plan / calendar)
**Status**: Backlog · **Effort**: S · **Layer**: frontend · **Journey stage**: print view
**Owning**: unassigned · **Depends on**: — · **Blocks**: — · **Folded-in**: —
**Source**: Review of docs/concepts/ui-use-cases.md (2026-08-21)

Future: paper-friendly weekly plan for fridge-door households.

- [ ] Print styles or dedicated print route reusing CalendarView range data.
- [ ] No new endpoints unless a gap is proven.

---

### [P3] FEAT-UX-028 — Global search
**Status**: Backlog · **Effort**: M · **Layer**: backend+frontend · **Journey stage**: search
**Owning**: unassigned · **Depends on**: — · **Blocks**: — · **Folded-in**: —
**Source**: Review of docs/concepts/ui-use-cases.md (2026-08-21)

`GET /tasks/search` exists with a dead store wrapper (`searchTasks`); no UI.

- [ ] Search entry point covering task definitions + room tasks (NC search
      integration or in-app field).
- [ ] Decide fate of the dead `searchTasks` wrapper with the implementation.
- [ ] Tests.

---

### [P4] FEAT-UX-029 — Bulk maintenance ops
**Status**: Backlog · **Effort**: M · **Layer**: backend+frontend · **Journey stage**: bulk maintenance
**Owning**: unassigned · **Depends on**: — · **Blocks**: — · **Folded-in**: —
**Source**: Review of docs/concepts/ui-use-cases.md (2026-08-21)

Future: owner tools to reschedule/clear overdue tasks in bulk and seasonally
deactivate/reactivate by filter (cf. the CS-PLAN-042 bulk-endpoint pattern).

- [ ] Server-side bulk reschedule endpoint + owner action on the overdue list.
- [ ] Seasonal deactivate/reactivate by filter (room/category/idle).
- [ ] Styled confirmations per cross-cutting conventions + tests.

---

### [P4] UX-017 — AvailabilitySettings day-item border hexes bypass tokens
**Status**: Backlog · **Effort**: XS · **Layer**: frontend · **Journey stage**: theming
**Owning**: unassigned · **Depends on**: — · **Blocks**: — · **Folded-in**: —
**Source**: CS-PLAN-044 known remaining, logged by Standard-B review (2026-08-23)

`AvailabilitySettings.vue` day-item borders hardcode `#4caf50`/`#f44336`
(~lines 449/454). Outside the CS-PLAN-044 swept-component list; replace with
`var(--color-success)`/`var(--color-error)` when a dark-mode pass touches the
component.

---

## Infrastructure and Documentation

> Backlog hygiene, lint/dependency, docs truth, and operational security.
> Independently schedulable unless a gate depends on it (CS-PLAN-058 §2.2).

### [P3] CR-053-21 — Docs truth-repair batch (remainder)
**Status**: Backlog · **Effort**: M · **Layer**: docs · **Journey stage**: documentation
**Owning**: unassigned · **Depends on**: — · **Blocks**: — · **Folded-in**: —
**Source**: CS-PLAN-053 full review (L5-1, 2026-08-27)

**Decision (user, 2026-08-29)**: root `README.md` gets a **full rewrite** (accurate
features, stack, install, v0.8.x alpha status) — not a historical banner.
Remainder: root `README.md` rewrite (tower-defense marketing, Sparks currency,
removed features, "v1.0.0 RC" status — decision: rewrite vs historical banner);
ADR-003 scope enum contradicts code; architecture.md fictional examples
(`fetchHouseStats`, `TaskForm`/`TemplateSelector`, console.error example,
forbidden backlog-tree line); development.md `TaskItem.vue` +
migration-conventions subsection (version-then-date order, pre/post hooks never
run on fresh install); gamification unimplemented-feature markers;
`docs/api/README.md` room-tasks example block still shows pre-CS-PLAN-057
snake_case payload keys (`room_id`, `task_name`, `next_due`, …) instead of the
actual camelCase entity serialization (Source: Review of CS-PLAN-060 WP1,
2026-09-03 — assignee lines were corrected there, the key-style drift predates it).
Closed slices: the metadata/normalization slice was executed by
CS-PLAN-059 WP0 (2026-09-02 — [`archive/2026-09.md`](archive/2026-09.md));
the testing.md truth slice is owned by **CS-PLAN-058 WP9**; the
architecture/security/ui-use-cases/energy/task-model/api-README slices closed
with CS-PLAN-055 (record: [`archive/2026-08.md`](archive/2026-08.md)).

---

### [P3] TECH-LINT-006 — npm peer-dep ERESOLVE requires `--legacy-peer-deps`
**Status**: Backlog · **Effort**: S · **Layer**: tooling · **Journey stage**: dependency management
**Owning**: unassigned · **Depends on**: — · **Blocks**: — · **Folded-in**: —
**Source**: CS-026 dependency reinstall (2026-08-12)

`npm install` fails with `ERESOLVE`: `eslint-config-standard@17.1.0` wants
`eslint-plugin-n@^15 || ^16` but `package.json` pins `eslint-plugin-n@^17.24.0`
(via `@nextcloud/eslint-config@8.3.0`). The repo therefore installs with
`--legacy-peer-deps`. Pre-existing (not introduced by CS-026), but now documented.
Resolving it cleanly likely needs an `eslint-plugin-n`/`eslint-config-standard`
bump coordinated with the Nextcloud eslint config.

---

### [P4] TECH-LINT-007 — stylelint `use-logical` warning in NotAuthorizedState.vue
**Status**: Backlog · **Effort**: XS · **Layer**: frontend · **Journey stage**: code quality
**Owning**: unassigned · **Depends on**: — · **Blocks**: — · **Folded-in**: —
**Source**: Review of CS-PLAN-062 WP3 final Standard B (2026-09-05)

`npm run stylelint` reports a pre-existing warning (0 errors, exit 0):
`src/components/Common/NotAuthorizedState.vue:73` uses `margin-left` where the
`csstools/use-logical` rule wants `margin-inline`. Outside the CS-PLAN-062
reviewed scope; fix risk-free the next time the component is touched.

---

### [P3] BUG-SEC-001 — Dedicated security reporting address + GPG key
**Status**: Backlog · **Effort**: S · **Layer**: docs · **Journey stage**: security reporting
**Owning**: unassigned · **Depends on**: — · **Blocks**: RELEASE-002 · **Folded-in**: —
**Source**: CS-001 security review

`SECURITY.md` points reporters to the Codeberg user profile (no private advisory
feature). A dedicated `security@` address + published GPG key would make private,
encrypted reporting frictionless.

- [ ] Dedicated security contact published in `SECURITY.md` + Codeberg profile.
- [ ] GPG public key published; fingerprint referenced in `SECURITY.md`.
- [ ] `SECURITY.md` "maintainer note" removed once the channel exists.

---

### [P3] SEC-AUDIT-001 — Security hardening follow-ups from P8-SECURITY-001 audit
**Status**: Backlog · **Effort**: M · **Layer**: backend · **Journey stage**: security hardening
**Owning**: unassigned · **Depends on**: — · **Blocks**: RELEASE-002 · **Folded-in**: —
**Source**: security audit 2026-03-05 (record archived 2026-08-20, DOC-STD-001); re-verified by CS-PLAN-053 full review 2026-08-27

- [ ] Rate limiting for sensitive endpoints (user management).
- [ ] Audit logging for owner-level operations.
- [x] Document security assumptions in the API documentation (`docs/api/README.md:836-838` documents "no rate limiting" explicitly; a full assumptions section is still to do — keep this bullet open at half-done).

Re-verification 2026-08-27: no rate-limit or audit-log implementation exists in `lib/`;
review findings CR-053-17 (controller logging gaps in 10/15 controllers) and CR-053-18
are adjacent hardening items.

---

## Release

> Stage path: `v0.8.x` alpha → `v0.9.x` beta → `v1.0.0` stable (manual install).
> App Store submission is explicitly deferred until stable quality is proven.

### [P2] RELEASE-002 — Beta deployment + real-world alpha usage
**Status**: Backlog · **Effort**: M · **Layer**: — · **Journey stage**: release
**Owning**: unassigned · **Depends on**: CS-PLAN-058 WP1..WP9 exit gates + final Full review PASS · **Blocks**: RELEASE-003 · **Folded-in**: —
**Source**: staged release effort (roadmap); blocking-gate list fixed by CS-PLAN-058 §2.2/§3

Deploy to a personal Nextcloud instance; verify no impact on other apps;
real-world `v0.8.x` alpha usage; (optional) private tester onboarding for
`v0.9.x` beta with structured feedback intake.

Exact blocking gates (not yet satisfied):
- CS-PLAN-058 WP1..WP9 exit gates, each passed with its Standard B review.
- Final Full review of the complete owner/member/child journey: `PASS` or
  `PASS-WITH-FIXES` (CS-PLAN-058 §3 WP9).

---

### [P2] RELEASE-003 — v1.0.0 stable artifacts
**Status**: Backlog · **Effort**: M · **Layer**: — · **Journey stage**: release
**Owning**: unassigned · **Depends on**: RELEASE-002 · **Blocks**: — · **Folded-in**: —
**Source**: staged release effort (roadmap)

Release notes, app screenshots, `v1.0.0` stable manual-install release,
App Store readiness evaluation (post-stable only).

---

### Milestone exit criteria (not yet satisfied)
- **`v0.9.x` Beta**: private tester onboarding; reproducible core flows across
  setups; documented install/update/rollback for testers; known-issues list;
  feedback intake operational; no open P0 / no unaccepted P1.
- **`v1.0.0` Stable**: documented supported workflows; release notes + known
  issues + install/upgrade guides; lint clean for supported paths; targeted
  automated + manual validation; release decision independent of App Store.

> Done release-prep work (security audit, CI release gate, integration/E2E
> stabilization, deployment checklist) is recorded in [`archive/`](archive/).
