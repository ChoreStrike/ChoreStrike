# Backlog — Open Items

> **Single source of truth for everything not yet done.** Bugs, features,
> tech-debt, and review follow-ups are consolidated here (the old `bugs.md` /
> `features.md` / `phase-9-*` / `phase-8-*` files are dissolved).
> **Done work does not live here** — completed items move to
> [`archive/YYYY-MM.md`](archive/) at completion. See [`README.md`](README.md)
> for the workflow and [`docs/standards/ai/rules/project-rules.md` §4](../standards/ai/rules/project-rules.md)
> for the rules.
>
> Priority: **P0** critical · **P1** high · **P2** should-have · **P3** nice-to-have · **P4** future.

---

## Bugs

### [P3] BUG-E2E-003 — `title-selection.spec.ts` "Selecting a pack marks it active" flaky
**Status**: Resolved 2026-08-25 (CS-PLAN-048) → [`archive/2026-08.md`](archive/2026-08.md)

---

### [P3] BUG-SEC-002 — `PUT /users/{id}/role` lacks self-demotion / last-owner guard
**Status**: Resolved 2026-08-24 (CS-PLAN-045) → [`archive/2026-08.md`](archive/2026-08.md)

---

### [P3] BUG-SEC-001 — Dedicated security reporting address + GPG key
**Status**: Backlog · **Effort**: S · **Source**: CS-001 security review

`SECURITY.md` points reporters to the Codeberg user profile (no private advisory
feature). A dedicated `security@` address + published GPG key would make private,
encrypted reporting frictionless.

- [ ] Dedicated security contact published in `SECURITY.md` + Codeberg profile.
- [ ] GPG public key published; fingerprint referenced in `SECURITY.md`.
- [ ] `SECURITY.md` "maintainer note" removed once the channel exists.

---

### [P3] BUG-ROOM-001 — `RoomController`/`FloorController::index` POST→GET rewrite workaround
**Status**: Resolved 2026-08-29 (CS-PLAN-055 — GET-create branches + alias routes pruned) → [`archive/2026-08.md`](archive/2026-08.md)

---

### [P3] BUG-E2E-003 — `insights-flow.spec.ts` overcapacity-marker test structurally fails on Sundays
**Status**: Open · **Effort**: S · **Source**: CS-PLAN-055 Standard-B review E2E re-run 2026-08-30 (pre-existing; passed on the 08-29 Saturday run by date luck, NOT a CS-055 regression)
The spec schedules 4 probe tasks to `tomorrow` and then expects `overcapacity-marker` inside `ScheduleCalendar`, but the calendar week starts Monday (`goToToday()`); on a Sunday "tomorrow" is the next Monday — outside the displayed week — while `overcapacity-days` (warning strip) scans today+0..6 and still shows the day. Fix: create the probes relative to the **visible** week (a due day ≥ today strictly inside the Monday-start week, e.g. Monday after Sunday), or navigate the calendar to tomorrow's week before asserting the marker. Also observed: Nextcloud stores two `weekly_goal` rows with identical (user, app, object_type, object_id) despite the documented dedup key — the per-house `claimWeeklyGoalNotification` guard is the dedup that actually holds; worth a note in `NotificationService` docs.

---

### [P3] BUG-E2E-001 — `3-floor-house.spec.ts`: 2 pre-existing failures in the serial pool
**Status**: Resolved 2026-08-25 (CS-PLAN-048) → [`archive/2026-08.md`](archive/2026-08.md)

---

### [P3] BUG-E2E-002 — Own-card Edit button never visible in E2E → owner modal tests skip vacuously
**Status**: Resolved 2026-08-24 (CS-PLAN-043) → [`archive/2026-08.md`](archive/2026-08.md)

---

### [P4] BUG-UI-001 — Week-total/analysis display garbled while an energy input is empty or invalid
**Status**: Resolved 2026-08-25 (CS-PLAN-048) → [`archive/2026-08.md`](archive/2026-08.md)

---

## Tech Debt / Code Quality

### [P3] SEC-AUDIT-001 — Security hardening follow-ups from P8-SECURITY-001 audit
**Status**: Open · **Effort**: M · **Source**: security audit 2026-03-05 (record archived 2026-08-20, DOC-STD-001); re-verified by CS-PLAN-053 full review 2026-08-27

- [ ] Rate limiting for sensitive endpoints (user management).
- [ ] Audit logging for owner-level operations.
- [x] Document security assumptions in the API documentation (`docs/api/README.md:836-838` documents "no rate limiting" explicitly; a full assumptions section is still to do — keep this bullet open at half-done).

Re-verification 2026-08-27: no rate-limit or audit-log implementation exists in `lib/`;
review findings CR-053-17 (controller logging gaps in 10/15 controllers) and CR-053-18
are adjacent hardening items.

---

### [P2] TECH-LINT-004 — Stylelint `use-logical` warnings (26, physical→logical property sweep)
**Status**: Resolved 2026-08-23 (CS-PLAN-044) → [`archive/2026-08.md`](archive/2026-08.md)

---

### [P3] TECH-LINT-006 — npm peer-dep ERESOLVE requires `--legacy-peer-deps`
**Status**: Open · **Effort**: S · **Source**: CS-026 dependency reinstall (2026-08-12)

`npm install` fails with `ERESOLVE`: `eslint-config-standard@17.1.0` wants
`eslint-plugin-n@^15 || ^16` but `package.json` pins `eslint-plugin-n@^17.24.0`
(via `@nextcloud/eslint-config@8.3.0`). The repo therefore installs with
`--legacy-peer-deps`. Pre-existing (not introduced by CS-026), but now documented.
Resolving it cleanly likely needs an `eslint-plugin-n`/`eslint-config-standard`
bump coordinated with the Nextcloud eslint config.

---

### [P2] P9-DOC-005 — `development.md` example-code audit
**Status**: Resolved 2026-08-26 (CS-PLAN-049) → [`archive/2026-08.md`](archive/2026-08.md)

---

### [P3] TECH-DOCS-002 — `docs:encoding` gate does not flag C0 control bytes
**Status**: Resolved 2026-08-27 (CS-PLAN-052) → [`archive/2026-08.md`](archive/2026-08.md)

---

### [P2] TECH-DOCS-003 — Backlog/archive link and status normalization
**Status**: Backlog · **Effort**: M · **Source**: Full review of agent standards and skills (2026-09-02)

`backlog.md` still contains many resolved/closed entries that already exist in
`archive/2026-08.md`, and historical local plan archival left tracked links to
removed `docs/plans/done/*` files. This broad historical cleanup exceeds the
review-workflow standards package; until fixed, the open-work inventory is noisy
and some historical plan links are not portable to another clone.

- [ ] Remove every resolved/closed item from `backlog.md` after confirming its unchanged history in the monthly archive.
- [ ] Replace links to locally archived plans with tracked monthly/review evidence before removing the stale links.
- [ ] Add a repeatable Markdown-link check for tracked documentation.
- [ ] Verify `backlog.md` contains open items only and all tracked relative links resolve.

---

## Review Follow-ups — CS-016 (Scheduling UI)

> From [`archive/CODE_REVIEW_2026-07-28.md`](archive/CODE_REVIEW_2026-07-28.md).
> CR-016-11 is **resolved** (CS-018); CR-016-12 is **resolved** (CS-032);
> CR-016-02 is **resolved** (CS-033); the open UI-side items
> **CR-016-03/04/05/06/08/13/14** are **resolved 2026-08-24 by CS-PLAN-043**
> (record: [`archive/2026-08.md`](archive/2026-08.md)); **CR-016-15** is
> **resolved 2026-08-24 by CS-PLAN-045**. **CR-016-10** is
> **resolved 2026-08-24 by CS-PLAN-046**
> (record: [`archive/2026-08.md`](archive/2026-08.md)). All CS-016 review
> items are closed.

### [P2] CR-016-10 — Regression test for `getConsumedEnergyForUserOnDate` SQL fix
**Status**: Resolved 2026-08-24 (CS-PLAN-046) → [`archive/2026-08.md`](archive/2026-08.md)

### [P3] CR-016-09 — `DailyTaskList.vue` tech debt (pre-existing)
**Status**: Resolved 2026-08-21 (CS-PLAN-038, component archived) → [`archive/2026-08.md`](archive/2026-08.md)

### [P3] CR-016-15 — Shared backend energy-bounds validation (drift risk)
**Status**: Resolved 2026-08-24 (CS-PLAN-045) → [`archive/2026-08.md`](archive/2026-08.md)

---

## Review Follow-ups — Full Review of `lib/Service` (2026-08-04)

> From [`archive/CODE_REVIEW_2026-08-04.md`](archive/CODE_REVIEW_2026-08-04.md).
> Full Review (4 parallel reviewer subagents) of all 29 `lib/Service` files. Three
> minor findings were **auto-fixed** this review (gate green); the rest are open.

> **Resolved 2026-08-09 by [CS-PLAN-025](../plans/done/cs-plan-025-service-security-concurrency-cleanup.md)**
> (record: [`archive/2026-08.md`](archive/2026-08.md)):
> the 2 P0 + 6 P1 items **CR-SVC-01/02/03/04/05/06/07/12** and the dead-cluster
> delete **CR-SVC-08** landed. **CR-SVC-09/10** were **resolved 2026-08-16** by
> [CS-PLAN-029](../plans/done/cs-plan-029-service-hardening.md)
> (record: [`archive/2026-08.md`](archive/2026-08.md)). The remaining open items
> below (CR-SVC-11/13/…, P2/P3) stay here.
>
> **Reopened 2026-08-09 by the CS-025 implementation review:** **CR-SVC-04**
> plus follow-ups **CR-SVC-45/46/47** — all four **resolved 2026-08-10**
> (record: [`archive/2026-08.md`](archive/2026-08.md)).
> The review's smaller follow-ups **CR-SVC-48/49** were **resolved 2026-08-16**
> by [CS-PLAN-029](../plans/done/cs-plan-029-service-hardening.md)
> (record: [`archive/2026-08.md`](archive/2026-08.md)).
> **CR-SVC-50** was **resolved 2026-08-20** by
> [CS-PLAN-036](../plans/done/cs-plan-036-query-batch-optimization.md)
> (record: [`archive/2026-08.md`](archive/2026-08.md)); **CR-SVC-52** was
> **resolved 2026-08-24 by CS-PLAN-046** (record:
> [`archive/2026-08.md`](archive/2026-08.md)).

### [P3] CR-SVC-52 — CR-SVC-12 unit test does not assert dispatch-after-commit ordering
**Status**: Resolved 2026-08-24 (CS-PLAN-046) → [`archive/2026-08.md`](archive/2026-08.md)

> **2026-08-11 — Full Review of the 4 active plans** (CS-017/022/023/025; 4 parallel
> `reviewer` subagents + gate re-verification; record:
> [`CODE_REVIEW_2026-08-11.md`](archive/CODE_REVIEW_2026-08-11.md)). Gate re-verified green on
> the uncommitted CS-025 reopened-items tree + 1 auto-fix (`TaskSyncService::setSparksEarned`
> dead line → `setCoinsEarned`): PHPUnit unit 690 / integration 150 (24 pre-existing
> skips) / Jest 677 / ConcurrentCompletionTest 2/2 (incl. the 2.1 s real-lock proof) /
> ESLint clean. **One auto-fix applied**, **one new [P1] concurrency finding
> (CR-SVC-53)** surfaced, and the minor perf/quality follow-ups below (CR-SVC-54..58).

> **Resolved 2026-08-20 by
> [CS-PLAN-036](../plans/done/cs-plan-036-query-batch-optimization.md)**
> (record: [`archive/2026-08.md`](archive/2026-08.md)): **CR-SVC-55/56** and the
> **read-path** cluster of **CR-SVC-11** are closed; the write-path remainder
> (resetHouse, deleteRoom, reorderFloors, bulkAssignTasks) is split into
> **CR-SVC-60** below.

### [P3] CR-SVC-59 — Add `AppLogger` to logger-less services
**Status**: Resolved 2026-08-27 (CS-PLAN-052, survey-driven) → [`archive/2026-08.md`](archive/2026-08.md)

> **Resolved 2026-08-20 by
> [CS-PLAN-037](../plans/done/cs-plan-037-bulk-write-batching.md)**
> (record: [`archive/2026-08.md`](archive/2026-08.md)): **CR-SVC-60** closed —
> all four write hot spots converted to set-based writes with explicit,
> transactional, orphan-free delete cascades (D1=YES/D2=DELETE).

### [P3] CR-SVC-61 — `bulkAssignTasks` has no controller/route caller
**Status**: Resolved 2026-08-22 (CS-PLAN-042) → [`archive/2026-08.md`](archive/2026-08.md)

### [P2] CR-SVC-15 — Decompose God-methods / God-services
**Status**: Open · **Effort**: L · **Source**: Review of `lib/Service` (2026-08-04)
Over the ~300-line/~30-line guidelines: `RoomService::completeRoomTask` (~200 lines),
`GameLogicService` (517, 7 concerns), `handleTaskCompletion` (~113) /
`checkAndAwardMedals` (~62, 5 near-identical award blocks), `RoomTaskService` (833),
`RewardConfigService` (689 → 538 via CS-PLAN-049 registry, 2026-08-26 — still over
the guideline, accepted), `TaskService::createTask` (24 positional params).
- [ ] Extract cohesive steps (overdue-takeover, post-commit notification dispatch,
      medal-rules table) and split GameLogicService along domain lines.

### [P2] CR-SVC-16 — `RewardConfigService` registry refactor (DRY)
**Status**: Resolved 2026-08-26 (CS-PLAN-049) → [`archive/2026-08.md`](archive/2026-08.md)

### [P3] CR-SVC-19 — `RewardConfigService` inconsistent clamping
**Status**: Resolved 2026-08-24 (CS-PLAN-045) → [`archive/2026-08.md`](archive/2026-08.md)

### [P3] CR-SVC-32 — `calculateDaysEarly` DST/timezone edge cases
**Status**: Resolved 2026-08-24 (CS-PLAN-045) → [`archive/2026-08.md`](archive/2026-08.md)

### [P3] CR-SVC-33 — `TaskService::completeTask` reward-less Task-level path
**Status**: Resolved 2026-08-24 (CS-PLAN-045, D1=delete) → [`archive/2026-08.md`](archive/2026-08.md)

### [P3] CR-SVC-37 — Service-layer i18n: hardcoded user-facing strings
**Status**: Resolved 2026-08-24 (CS-PLAN-046, incl. the RoomController
VALIDATION_ERROR literals) → [`archive/2026-08.md`](archive/2026-08.md)

### [P3] CR-SVC-62 — Remaining client-visible English literals (CR-SVC-37 remainder)
**Status**: Resolved 2026-08-25 (CS-PLAN-047, incl. the D1 NotFound/Forbidden
sweep and the D2 untranslated-fallback policy) → [`archive/2026-08.md`](archive/2026-08.md)

### [P3] CR-SVC-40 — Services reach below their layer (`IDBConnection` injection)
**Effort**: M — injects `IDBConnection` for `beginTransaction`/`lockForUpdate` (persistence
plumbing in a service). Move transaction+lock into the mapper/repository.
**Scope updated by CS-PLAN-053 full review (2026-08-27)**: not just `RoomService` —
**7 services** inject `IDBConnection` directly: `RoomService`, `FloorService`,
`HouseService`, `HouseSetupService`, `MinigameService`, `RoomTaskService`,
`TestDataService` (see review record L2-3 for line refs).

### [P3] CR-SVC-41 — Service DI inconsistencies (remainder)
**Effort**: M — `getFilteredDailyTasksForUser` takes `HouseUserMapper` as a param despite ctor injection;
duplicated stat helpers; duplicated `validate*Ownership` across Floor/Room/RoomTask services.
The nullable-`?AppLogger` half (all 9 sites + trait + `RewardConfigController` wiring defect)
was **resolved 2026-08-27 by CS-PLAN-052** (record: [`archive/2026-08.md`](archive/2026-08.md)).

### [P3] CR-SVC-44 — Minor performance cluster
**Effort**: M — re-scoped by CS-PLAN-053 full review (2026-08-27) and CS-PLAN-055: the
`createDefaultTemplates` and `extractHouseIdFromSchedule`/`ScheduleOptimizer`
sub-claims are **obsolete** (clusters deleted by CS-PLAN-027 D7 / CR-SVC-08);
`TaskService::getAllTasks` was deleted with the CS-PLAN-055 dead-code sweep.
Still live: `StreakFreeze`/`StreakRestock` per-user UPDATEs (unlocked RMW —
see CR-053-11 for the correctness half); `assignTask`/`unassignTask`/`deactivate`/
`reactivate`/`updateDueDate` re-fetch pattern in `RoomTaskService`; `hasFloors`
hydrates all floors for a boolean (`HouseService:303`).

---

## Review Follow-ups — CS-027 (House Setup Wizard & Cleaning Templates)

> From the Standard-B review of the CS-PLAN-027 implementation (2026-08-13).
> Six smaller findings were auto-fixed inline (plan §13); CR-027-03/04 were
> closed by CS-PLAN-030, **CR-027-01** was closed 2026-08-24 by CS-PLAN-045.
> **CR-027-02** was **resolved 2026-08-24 by CS-PLAN-046 (D1=delete)**
> (record: [`archive/2026-08.md`](archive/2026-08.md)). All CS-027 review
> items are closed.

### [P3] CR-027-01 — Free room create/update persists unvalidated `roomType`
**Status**: Resolved 2026-08-24 (CS-PLAN-045, D2=reject 400) → [`archive/2026-08.md`](archive/2026-08.md)

### [P3] CR-027-02 — `TemplateService::searchTemplates` dead + unlocalized since D7
**Status**: Resolved 2026-08-24 (CS-PLAN-046, D1=delete) → [`archive/2026-08.md`](archive/2026-08.md)

---

## Review Follow-ups — Usability & UI Review (2026-08-20)

> From the usability/UI workflow review (house setup → tasks → assignment →
> completion, adults + children). Full record:
> [`archive/USABILITY_REVIEW_2026-08-20.md`](archive/USABILITY_REVIEW_2026-08-20.md).
> **UX-001/002/003 resolved 2026-08-21 by CS-PLAN-038**; `CR-016-09` closed by
> archiving DailyTaskList. Related existing items: `CR-016-05`/`CR-016-03`
> (scheduling merge/split), `CR-SVC-33` (reward-less Task-level path,
> resolved 2026-08-24 by CS-PLAN-045).
> Implementation status: **CS-PLAN-041 landed 2026-08-22** (UX-005/006/013),
> **CS-PLAN-042 landed 2026-08-22** (UX-004/008/009/011 + CR-SVC-61 endpoint);
> **CS-PLAN-044 landed 2026-08-23** (UX-007/010/012 + TECH-LINT-004 +
> FEAT-UX-030/031); **CS-PLAN-043 landed 2026-08-24** (UX-014).

### [P3] FEAT-UX-015 — Leave house (self-service membership exit)
**Status**: Open · **Effort**: M · **Source**: UX-003 remainder (CS-PLAN-038)

Members cannot leave a house on their own; removal is owner-only. Needs a
product decision on owner-transfer semantics when the *last* owner leaves
(interplay with the BUG-SEC-002 last-owner guard), then a backend endpoint +
Settings action.

- [ ] Decide owner-transfer/last-owner semantics for leaving.
- [ ] Backend leave endpoint (+ guards) + Settings Leave button + tests.

### [P1] UX-001 — Task creation is three inconsistent paths; TaskManager creates invisible tasks
**Status**: Resolved 2026-08-21 (CS-PLAN-038) → [`archive/2026-08.md`](archive/2026-08.md)

### [P1] UX-002 — No "Today / my tasks" surface (child persona gap)
**Status**: Resolved 2026-08-21 (CS-PLAN-038) → [`archive/2026-08.md`](archive/2026-08.md)

### [P1] UX-003 — Member management has zero UI (backend + stores complete)
**Status**: Resolved 2026-08-21 (CS-PLAN-038) → [`archive/2026-08.md`](archive/2026-08.md); leave-house remainder → FEAT-UX-015

### [P2] UX-004 — Structure gaps: room rename/delete + floor reorder UI-less; floor mgmt duplicated
**Status**: Resolved 2026-08-22 (CS-PLAN-042) → [`archive/2026-08.md`](archive/2026-08.md)

### [P2] UX-005 — Dead/misleading controls
**Status**: Resolved 2026-08-22 (CS-PLAN-041) → [`archive/2026-08.md`](archive/2026-08.md)

### [P2] UX-006 — No undo for completion; native confirms; silent Settings saves
**Status**: Resolved 2026-08-22 (CS-PLAN-041) → [`archive/2026-08.md`](archive/2026-08.md)

### [P2] UX-007 — Kid-sized controls & inconsistent vocabulary
**Status**: Resolved 2026-08-23 (CS-PLAN-044) → [`archive/2026-08.md`](archive/2026-08.md)

### [P3] UX-008 — Assignment discoverability
**Status**: Resolved 2026-08-22 (CS-PLAN-042) → [`archive/2026-08.md`](archive/2026-08.md)

### [P3] UX-009 — Checklist editor implemented but permanently locked
**Status**: Resolved 2026-08-22 (CS-PLAN-042) → [`archive/2026-08.md`](archive/2026-08.md)

### [P3] UX-010 — Hardcoded colors bypass tokens; stale mobile.css selectors
**Status**: Resolved 2026-08-23 (CS-PLAN-044) → [`archive/2026-08.md`](archive/2026-08.md)

### [P3] UX-011 — Setup wizard polish
**Status**: Resolved 2026-08-22 (CS-PLAN-042) → [`archive/2026-08.md`](archive/2026-08.md)

### [P3] UX-012 — Modal accessibility
**Status**: Resolved 2026-08-23 (CS-PLAN-044) → [`archive/2026-08.md`](archive/2026-08.md)

### [P3] UX-013 — Boost prices duplicated client-side
**Status**: Resolved 2026-08-22 (CS-PLAN-041) → [`archive/2026-08.md`](archive/2026-08.md)

### [P3] UX-015 — Undo snackbar holds only the latest completion
**Status**: Open · **Effort**: S · **Source**: Review of CS-PLAN-041 (2026-08-22)

The global `UndoSnackbar` (CS-PLAN-041) keeps a single undo slot: completing a
second task within the ~5 s window silently replaces the first task as the undo
target, so a misclick on the first task can no longer be reverted. Decide
between queueing undo targets (stacked/snackbar list) or explicitly accepting
the single-slot limitation in the design docs.

### [P3] UX-016 — Remaining Modal call sites have no accessible name
**Status**: Resolved 2026-08-25 (CS-PLAN-048) → [`archive/2026-08.md`](archive/2026-08.md)

### [P4] UX-017 — AvailabilitySettings day-item border hexes bypass tokens
**Status**: Open · **Effort**: XS · **Source**: CS-PLAN-044 known remaining, logged by Standard-B review (2026-08-23)

`AvailabilitySettings.vue` day-item borders hardcode `#4caf50`/`#f44336`
(~lines 449/454). Outside the CS-PLAN-044 swept-component list; replace with
`var(--color-success)`/`var(--color-error)` when a dark-mode pass touches the
component.

---

## Review Follow-ups — UI Use-Case Review (2026-08-21)

> From the Standard-B review of
> [`../concepts/ui-use-cases.md`](../concepts/ui-use-cases.md) and the endpoint
> audit (both 2026-08-21). These are **whitespace candidates**: documented
> intent without UI surface (Group A) and review ideas (Group B), indexed from
> the *Whitespace* section of `ui-use-cases.md`. No expose-or-prune decision is
> pre-empted by their existence; P4 items are explicitly "future / needs
> decision". **FEAT-UX-016/017/020/022 were resolved by CS-PLAN-040
> (2026-08-21) → [`archive/2026-08.md`](archive/2026-08.md).**

### [P3] FEAT-UX-018 — Task-approval workflow for children
**Status**: Open · **Effort**: L · **Source**: Review of docs/concepts/ui-use-cases.md (2026-08-21)

design.md §5 lists "task approval workflow for children"; gamification open
decisions touch it too. Today every completion is final immediately.

- [ ] Product decision recorded: approval flow vs. current auto-complete
      (which roles, which tasks).
- [ ] Backend: needs-review state + owner approve/reject endpoints with
      membership/owner gating + tests.
- [ ] Owner approval queue UI + child-facing "waiting for approval" feedback.

### [P4] FEAT-UX-019 — Task Pass & support actions (gag mechanic)
**Status**: Open · **Effort**: M · **Source**: Review of docs/concepts/ui-use-cases.md (2026-08-21)

gamification-and-rewards.md sketches a post-MVP "Task Pass" gag mechanic;
explicitly future / needs decision.

- [ ] Product decision whether the gag mechanic fits the target audience.
- [ ] Only after decision: concept extension + endpoint design sketch.

### [P3] FEAT-UX-021 — NC-Tasks sync surface
**Status**: Closed 2026-08-29 (CS-PLAN-055 — pruned; revival needs ADR + consumer) → [`archive/2026-08.md`](archive/2026-08.md)

### [P3] FEAT-UX-023 — Onboarding & help layer
**Status**: Open · **Effort**: M · **Source**: Review of docs/concepts/ui-use-cases.md (2026-08-21)

No tour, glossary, or contextual hints; vocabulary drift (UX-007) makes
self-service discovery harder.

- [ ] Help entry point per major view (Dashboard, TodayView, SchedulingView).
- [ ] Glossary unifying the UX-007 vocabulary drift (priority labels,
      Admin-Only, Scope).
- [ ] Contextual hints for gamification terms (combo, medal, streak freeze).

### [P3] FEAT-UX-024 — Notification settings
**Status**: Open · **Effort**: M · **Source**: Review of docs/concepts/ui-use-cases.md (2026-08-21)

UC-14 reminders are passive server jobs with no settings surface.

- [ ] Backend: per-user notification preferences (storage + membership-gated
      endpoints).
- [ ] Settings surface: digest toggle, quiet hours; background jobs respect
      them.
- [ ] Service + settings tests.

### [P4] FEAT-UX-025 — Vacation / house-pause mode
**Status**: Open · **Effort**: M · **Source**: Review of docs/concepts/ui-use-cases.md (2026-08-21)

Future / needs decision: pause semantics undefined (streaks? due dates?
notifications? per-member vs whole-house).

- [ ] Product decision on pause semantics + scope recorded.
- [ ] Concept extension before any implementation.

### [P4] FEAT-UX-026 — Real-world reward shop
**Status**: Open · **Effort**: L · **Source**: Review of docs/concepts/ui-use-cases.md (2026-08-21)

Future / needs decision: coins → owner-defined real-world rewards beyond the
coin/boost model.

- [ ] Product decision + gamification-doc concept extension (reward model,
      redemption flow).
- [ ] Implementation only after the concept lands.

### [P4] FEAT-UX-027 — Print view (weekly plan / calendar)
**Status**: Open · **Effort**: S · **Source**: Review of docs/concepts/ui-use-cases.md (2026-08-21)

Future: paper-friendly weekly plan for fridge-door households.

- [ ] Print styles or dedicated print route reusing CalendarView range data.
- [ ] No new endpoints unless a gap is proven.

### [P3] FEAT-UX-028 — Global search
**Status**: Open · **Effort**: M · **Source**: Review of docs/concepts/ui-use-cases.md (2026-08-21)

`GET /tasks/search` exists with a dead store wrapper (`searchTasks`); no UI.

- [ ] Search entry point covering task definitions + room tasks (NC search
      integration or in-app field).
- [ ] Decide fate of the dead `searchTasks` wrapper with the implementation.
- [ ] Tests.

### [P4] FEAT-UX-029 — Bulk maintenance ops
**Status**: Open · **Effort**: M · **Source**: Review of docs/concepts/ui-use-cases.md (2026-08-21)

Future: owner tools to reschedule/clear overdue tasks in bulk and seasonally
deactivate/reactivate by filter (cf. CR-SVC-61 bulk-endpoint pattern).

- [ ] Server-side bulk reschedule endpoint + owner action on the overdue list.
- [ ] Seasonal deactivate/reactivate by filter (room/category/idle).
- [ ] Styled confirmations per cross-cutting conventions + tests.

### [P3] FEAT-UX-030 — A11y & kids-mode conventions
**Status**: Resolved 2026-08-23 (CS-PLAN-044) → [`archive/2026-08.md`](archive/2026-08.md)

### [P3] FEAT-UX-031 — Error/empty/offline-state conventions
**Status**: Resolved 2026-08-23 (CS-PLAN-044) → [`archive/2026-08.md`](archive/2026-08.md)

---

## Review Follow-ups — CS-PLAN-053 Full Bottom-to-Top Review (2026-08-27)

> From the full-tree review (persistence → services → API → frontend → docs; 6-phase
> Full-tier with 4 reviewer subagents per layer). Full evidence tables with file:line,
> severity, and per-finding disposition live in
> [`CODE_REVIEW_2026-08-27.md`](archive/CODE_REVIEW_2026-08-27.md) (CR-P1-*…CR-P5-* IDs).
> Security items are **never** auto-fixed; they are decision points for the user.

### [P2] CR-053-05 — `RewardConfigController` admin-gate + no production consumer
**Status**: Closed 2026-08-29 (CS-PLAN-055 — RewardConfig HTTP surface pruned) → [`archive/2026-08.md`](archive/2026-08.md)

### [P2] CR-053-06 — Task lifecycle endpoints skip the visibility matrix (uncomplete)
**Status**: Closed 2026-08-30 (CS-PLAN-056 — `assertLifecycleVisibility` guard wired into the surviving lifecycle mutations) → [`archive/2026-08.md`](archive/2026-08.md)

### [P2] CR-053-07 — Bootstrap races: duplicate houses per instance + two-owners race
**Effort**: M · **Source**: CS-PLAN-053 (CR-P2-04)
`houses.nextcloud_instance_id` has only a non-unique index (`Version1000:39`) → concurrent
first requests can create two houses; `ensureHouseMember` assigns `role='owner'` when
`count==0` → two distinct users first-touching concurrently both become owner. Fix: unique
index + race-safe owner assignment (first insert wins).

### [P2] CR-053-08 — Task-sync completion replay (idempotency/transaction)
**Status**: Closed 2026-08-29 (CS-PLAN-055 — task-sync surface pruned) → [`archive/2026-08.md`](archive/2026-08.md)

### [P2] CR-053-09 — Recurrence vocabulary divergence: `'once'` vs `'one_time'`
**Effort**: S · **Source**: CS-PLAN-053 (CR-P1-07)
`RoomTask::markCompleted:255`/`projectOccurrences:609` branch on `'one_time'`;
`TaskService:346` validates `['recurring','once','bounded']`. A validation-accepted `'once'`
task never deactivates on completion (silent infinite recurrence). Pick one vocabulary
(pre-release: data is disposable) and align both layers.

### [P2] CR-053-10 — Entity fields without schema columns (reminder*/description)
**Status**: Closed 2026-08-29 (CS-PLAN-055 — fields deleted, decision "delete") → [`archive/2026-08.md`](archive/2026-08.md)

### [P2] CR-053-11 — Streak & leaderboard correctness cluster
**Effort**: M · **Source**: CS-PLAN-053 (CR-P2-11/12/13/15/20)
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

### [P2] CR-053-13 — l10n artifact gap: 141 t() keys used in src/ missing from en+de
**Effort**: M · **Source**: CS-PLAN-053 (CR-P4-13/14/15)
German users get English fallback across TaskManager filters, Settings floor management,
Arcade, minigames; 8 keys are case-variant mismatches; ~340 dead artifact entries (keep
`titles.*` — server-driven). Root cause: `check-l10n-keys.js` only diffs de↔en. Fix: regenerate
artifacts + extend the checker with a src-usage diff; document in i18n.md.

### [P2] CR-053-14 — Route disposition decision (prune now)
**Status**: Closed 2026-08-29 (CS-PLAN-055 — 39 routes pruned, 71 remain) → [`archive/2026-08.md`](archive/2026-08.md)

### [P3] CR-053-15 — Dead-code deletion sweep
**Status**: Closed 2026-08-29 (CS-PLAN-055 — recorded + newly-orphaned items deleted) → [`archive/2026-08.md`](archive/2026-08.md)

### [P3] CR-053-16 — NotFound→404 mapping cluster + unvalidated request bodies
**Effort**: M · **Source**: CS-PLAN-053 (CR-P3-11/12); re-scoped by CS-PLAN-055
Systemic: nonexistent/foreign ids return 500 instead of 404 across the **remaining**
TaskController + RoomTaskController (×12) / Floor / Room / Checklist endpoints. (The
`Floor/Room index()` untyped JSON-body branches and the `task_sync` `limit` unclamped
branches left with the pruned surface.) One shared pattern: wrap DoesNotExistException
in service validators + per-controller catch.

### [P3] CR-053-17 — Silent exception swallowing in remaining controllers
**Effort**: M · **Source**: CS-PLAN-053 (L3-3); re-scoped by CS-PLAN-055
(TaskSync + RewardConfig controllers pruned)
Generic catch-alls return 500s with zero logging in RoomTask/Checklist/Floor/Minigame/Page/
Room/Test/User (+ Setup's weaker hand-rolled variant) — violates CS-PLAN-052's
AppLogger contract. Adopt `ControllerLoggingTrait` across the remainder.

### [P3] CR-053-18 — API hardening small-items cluster
**Effort**: M · **Source**: CS-PLAN-053 (CR-P3-04..10, CR-P2-09/10/17/18/19);
re-scoped by CS-PLAN-055
Test-mode routes registered with `@NoCSRFRequired` (CSRF-free destructive API when
NEXTCLOUD_TEST_MODE is set); saveScore accepts unwhitelisted gameIds with no rate cap (coin
farming); `ensureHouseMember` (auto-join) used on read paths with target-derived ids;
`ownerUserId` transfer without membership validation; anti-enumeration 403-vs-404
inconsistency; no route `requirements`; weekday case-handling divergence; notifier claimer
display-name-as-uid; medal-notification dedup collapse; reminder pass aborts on one
orphaned task. (Closed by the CS-PLAN-055 prune: `user#update` unvalidated
schedulingPreferences, bare RewardConfig `DataResponse` envelope, and `needingCleaning`
unscoped-without-floorId all removed with their routes.)

**Decisions (user, 2026-09-02):** after the first-owner bootstrap, membership is
invite-only; private-task access belongs to the creator plus explicit assignees.
The remaining cross-workflow enforcement is owned by **CR-WF-01** below.

### [P3] CR-053-19 — Persistence correctness minors
**Effort**: M · **Source**: CS-PLAN-053 (CR-P1-11..17, 19, 21, 22)
PostgreSQL boolean-vs-integer compares (would fail on PG); inclusive week-window end bound
(completion at next Monday 00:00 counts twice); checklist `toggle()` unlocked RMW + insert
race; `HouseUserMapper::createOrGet` check-then-insert (500 on race, no dup rows);
`FloorMapper::create` re-select instead of `insert()`; `getUserRank` unbounded scan;
inverted estimatedMinutes range passes `validate()`; `findActiveByHouse` queries a column the
final schema never had (dead + latent bug); dead `$houseId` param on `findVisibleByHouse`;
is_private column/field/shim divergence (CR-P1-18 — fold into a migration cleanup).

### [P3] CR-053-20 — Frontend correctness cluster
**Effort**: M · **Source**: CS-PLAN-053 (CR-P4-01..04, 06, 08, 09); re-scoped by CS-PLAN-055
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
are owned by **CR-WF-02** below.

### [P3] CR-053-21 — Docs truth-repair batch
**Effort**: M · **Source**: CS-PLAN-053 (L5-1)
**Decision (user, 2026-08-29)**: root `README.md` gets a **full rewrite** (accurate
features, stack, install, v0.8.x alpha status) — not a historical banner.
Root `README.md` rewrite (tower-defense marketing, Sparks currency, removed features, "v1.0.0 RC"
status — decision: rewrite vs historical banner); ADR-003 scope enum contradicts code; architecture.md
fictional examples (`fetchHouseStats`, `TaskForm`/`TemplateSelector`, console.error example, forbidden
backlog-tree line); development.md `TaskItem.vue` + migration-conventions
subsection (version-then-date order, pre/post hooks never run on fresh install); testing.md
`TaskForm.vue`; gamification unimplemented-feature markers. (Slices closed by
CS-PLAN-055: architecture.md availability table + tree/service notes, security.md
pruned-endpoint example + new GET-mutation rule, ui-use-cases header/appendix/matrix,
energy-and-balancing + task-model-and-sync markers, api/README prune sections + 71-route
count.)

### [P3] CR-053-22 — Test-gap closures deferred (deferral approved 2026-08-29)
**Effort**: L · **Source**: CS-PLAN-053 §4
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

## Review Follow-ups — CS-PLAN-055 Route Pruning (2026-08-29)

> From the CS-PLAN-055 execution (route prune + dead-code sweep); closes
> **CR-053-14/15**, **BUG-ROOM-001**, **CR-053-05/08/10**, **FEAT-UX-021**
> (see [`archive/2026-08.md`](archive/2026-08.md)); re-scopes
> CR-053-06/16/17/18/20/21 + CR-SVC-44 + CR-053-22 in place above.

### [P3] CR-055-01 — scheduling store: reader-less `todayConsumedEnergy` write cluster
**Effort**: S · **Source**: CS-PLAN-055 (record-and-delete boundary)
After the 9 superseded scheduling getters (incl. `todayRemainingEnergy`) were
pruned, `registerTodayEnergyConsumption` (called by `roomTasks.completeRoomTask`)
+ the `fetchUserPreferences` assignment for `todayConsumedEnergy` still write
state that **nothing reads** (the server's preferences payload carries the
field, so the fetch line survives). Decide: drop the client accumulation +
payload field (server is authoritative; also trim the `SchedulingService`
response) or surface remaining-today energy in the UI (feature decision).

### [P3] CR-055-02 — l10n artifacts: dead keys from deleted surfaces
**Effort**: S · **Source**: CS-PLAN-055
Some strings removed with the pruned surfaces still ship in the `l10n/`
artifacts (confirmed example: `Search query is required` — zero `t()` call
sites in `lib/`, one en.json entry; the full inventory is derivable from the
CR-053-13 src-usage-diff tooling). The CR-053-13 regeneration pass should drop
them — do not hand-prune artifact entries here.

### [P3] CR-055-03 — `nextcloud_task_id` remnants: writer-less columns + payload keys
**Effort**: S · **Source**: Review of CS-PLAN-055 (Standard-B, 2026-08-30)
With `TaskSyncService`/`GameLogicService::handleTaskCompletion` stamping gone, the
`room_tasks.nextcloud_task_id` (Version1002) and `completed_tasks.nextcloud_task_id`
(Version1004) columns have **no writer** anymore — rows are always NULL — while
`RoomTask`/`CompletedTask` still carry the entity property and expose a
`nextcloudTaskId` key in `jsonSerialize()` (API payload noise). Decide in the
next migration-chain pass (pre-release: fold into V1002/V1004, no upgrade step):
drop columns + entity fields + payload keys, **or** re-scope as intentional
storage for a future Nextcloud Tasks sync revival (ADR/FEAT decision). The
dead `RoomTaskMapper::findByNextcloudTaskId` was already deleted at review.

### [P4] CR-055-04 — `FloorMapper::findAll` pre-existing dead override
**Effort**: S · **Source**: Review of CS-PLAN-055 (Standard-B, 2026-08-30)
`FloorMapper::findAll:62` (sort_order-ordered override of `QBMapper::findAll`)
has zero callers in `lib/` or `tests/` — already dead at CS-PLAN-055 HEAD, so
outside the prune diff. Fold into the next dead-code sweep (CR-053-15 follow-up
class); delete or convert callers deliberately.

---

## Review Follow-ups — Core Task Workflow Full Review (2026-09-02)

> End-to-end Full review from first authenticated start through setup, task
> creation/assignment, daily execution, completion, and undo. Public security
> entries below are intentionally sanitized; sensitive technical evidence is
> excluded from the public repository. Review record:
> [`archive/CODE_REVIEW_2026-09-02.md`](archive/CODE_REVIEW_2026-09-02.md).
> Owning master plan: [`CS-PLAN-058`](../plans/active/cs-plan-058-core-workflow-bottom-up-masterplan.md).

### [P1] CR-WF-01 — Enforce approved workflow authorization boundaries
**Status**: Backlog
**Effort**: L · **Source**: Full Review of the core task workflow (2026-09-02)

Membership, role, task-visibility, mutation, and privacy boundaries are not
consistently enforced across the reviewed workflow. Detailed evidence is kept
out of the public repository under the security-disclosure policy.

- [ ] Bootstrap only the first owner; require an invitation thereafter and make removal durable.
- [ ] Enforce creator-plus-assignees access for private tasks across reads, assignment, checklists, and lifecycle actions.
- [ ] Enforce the documented owner-only structure and checklist-definition mutations at controller and service boundaries.
- [ ] Remove user-authored values and raw response payloads from workflow logs.
- [ ] Add real controller/service authorization regression coverage for every affected boundary.

### [P1] CR-WF-02 — Make completion and undo transactionally complete
**Status**: Backlog
**Effort**: XL · **Source**: Full Review of the core task workflow (2026-09-02)

The completion aggregate has state, concurrency, and post-commit ambiguity gaps;
the current split undo restores only part of the completed occurrence. The user
selected full transactional undo on 2026-09-02.

- [ ] Validate and lock the current active occurrence and all reward-driving inputs before mutation.
- [ ] Preserve concurrent room-state updates and reset occurrence-scoped checklist progress.
- [ ] Keep fallible post-commit effects from changing an already-committed success into an ambiguous failure.
- [ ] Undo room-task recurrence, completion ledger, rewards/streak, cleanliness, and derived state in one inverse transaction.
- [ ] Add real-DB concurrency, retry, inactive-occurrence, and full-undo regressions.

### [P1] CR-WF-03 — Make setup, creation, and assignment atomic and canonical
**Status**: Backlog
**Effort**: L · **Source**: Full Review of the core task workflow (2026-09-02)

Setup and both task-creation paths span validation and persistence boundaries;
assignment is split between two persisted representations with divergent write
semantics.

- [ ] Lock and recheck the first-run setup invariant inside the setup transaction, including the requested house name.
- [ ] Create a task definition, room link, and initial assignees as one rollback-safe aggregate.
- [ ] Use one authoritative assignee representation for setup, single replacement, bulk assignment, reads, and completion.
- [ ] Persist every supported scheduling field, including flex windows and an explicit creation due date.
- [ ] Cover rollback, replacement, bulk, and setup-assignment behavior with integration tests.

### [P2] CR-WF-04 — Reconcile daily workflow payloads and client state
**Status**: Backlog
**Effort**: L · **Source**: Full Review of the core task workflow (2026-09-02)

Several live workflow responses and Pinia consumers disagree about authoritative
state, causing incorrect limits/counts, stale completion feedback, and lost UI
events.

- [ ] Apply viewer eligibility before child daily limits and use house-local parsing for date-only due dates.
- [ ] Define canonical enriched payloads for daily tasks, assignees, completion rewards, titles, and invitations.
- [ ] Reconcile room, house, user, game, and task caches after completion and undo.
- [ ] Populate Dashboard due/overdue counters from the slices their fetch actions actually update.
- [ ] Add contract tests for each backend response, store mutation, and visible result.

### [P1] CR-WF-05 — Replace weak or skipped core-workflow coverage
**Status**: Backlog
**Effort**: L · **Source**: Full Review of the core task workflow (2026-09-02)

Core authorization scenarios remain unconditionally skipped, some completion E2E
assertions are tautological or data-dependent, and the first-run wizard happy
path has no deterministic browser profile.

- [ ] Replace skipped live authorization cases with current controller/API tests; delete only obsolete-route cases.
- [ ] Make completion/reward assertions deterministic and remove swallowed assertions and fixture-dependent skips.
- [ ] Add an empty-house user profile that exercises the complete first-run wizard in Playwright.
- [ ] Keep seed-mutating and non-seed project groups isolated so database resets cannot invalidate active sessions.
- [ ] Require zero newly skipped core-workflow tests in the release gate.

### [P2] CR-WF-06 — Batch workflow reads and reduce orchestration hotspots
**Status**: Backlog
**Effort**: L · **Source**: Full Review of the core task workflow (2026-09-02)

TaskManager and Today perform request-per-room/task fan-out, while the main task
list and completion aggregate remain oversized orchestration hotspots. This item
extends, rather than duplicates, **CR-SVC-15**, **CR-SVC-40**, **CR-SVC-41**, and
**CR-SVC-44**.

- [ ] Return house-scoped room tasks and batched assignees without frontend N+1 requests.
- [ ] Split `TaskList` into focused row, assignment, bulk, checklist, and due-date units.
- [ ] Decompose completion into cohesive phases without weakening its single transaction boundary.
- [ ] Remove the global service-locator lookup and preserve one-way store dependencies.
- [ ] Add query/request-count regression bounds for the reviewed journey.

### [P1] CR-WF-07 — Make scheduling produce an actionable household plan
**Status**: Planned
**Effort**: L · **Source**: CS-PLAN-058 core workflow masterplan (2026-09-02)

The existing scheduling surface can preview capacity, but the core journey needs
an explicit, owner-confirmed bridge from suggestions to real room-task assignees
and due dates consumed by Calendar and My Day.

- [ ] Return a versioned preview tied to current room-task IDs, proposed assignees, house-local due dates, capacity, and warnings.
- [ ] Apply an accepted preview transactionally after revalidating membership, visibility, activity, and current task versions.
- [ ] Never mutate scheduling state implicitly; stale previews are rejected with recoverable feedback.
- [ ] Make applied changes immediately authoritative for Calendar and filtered-daily/My Day.
- [ ] Cover owner apply plus member/child read-only results with integration and E2E tests.

### [P1] CR-WF-08 — Deliver a family-friendly core journey
**Status**: Planned
**Effort**: L · **Source**: CS-PLAN-058 core workflow masterplan (2026-09-02)

The complete setup-to-execution flow must be comfortable for owners and
understandable for members and children without requiring knowledge of rewards or
scheduling internals.

- [ ] Keep setup and task creation progressive, recoverable, localized, and usable on desktop/mobile with keyboard and screen readers.
- [ ] Match visible controls to owner/member/child permissions; do not expose actions that predictably fail.
- [ ] Make My Day the clear child execution surface with room, optional checklist, large Done action, and reversible feedback.
- [ ] Use short, neutral, encouraging language and explicit loading, empty, error, and recovery states.
- [ ] Prove the owner/member/child journeys at desktop and mobile viewports without conditional skips.

---

## Release / Rollout

> Stage path: `v0.8.x` alpha → `v0.9.x` beta → `v1.0.0` stable (manual install).
> App Store submission is explicitly deferred until stable quality is proven.

### [P2] RELEASE-002 — Beta deployment + real-world alpha usage
**Status**: Pending — deploy to a personal Nextcloud instance; verify no impact
on other apps; real-world `v0.8.x` alpha usage; (optional) private tester
onboarding for `v0.9.x` beta with structured feedback intake.

### [P2] RELEASE-003 — v1.0.0 stable artifacts
**Status**: Pending — release notes, app screenshots, `v1.0.0` stable
manual-install release, App Store readiness evaluation (post-stable only).

### Milestone exit criteria (not yet satisfied)
- **`v0.9.x` Beta**: private tester onboarding; reproducible core flows across
  setups; documented install/update/rollback for testers; known-issues list;
  feedback intake operational; no open P0 / no unaccepted P1.
- **`v1.0.0` Stable**: documented supported workflows; release notes + known
  issues + install/upgrade guides; lint clean for supported paths; targeted
  automated + manual validation; release decision independent of App Store.

> Done release-prep work (security audit, CI release gate, integration/E2E
> stabilization, deployment checklist) is recorded in [`archive/`](archive/).
