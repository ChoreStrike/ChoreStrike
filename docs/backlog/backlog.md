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

### [P3] BUG-ROOM-001 — Re-evaluate the `RoomController::index` POST→GET rewrite workaround
**Status**: Open · **Effort**: S · **Source**: CS-020 (deferred AC); updated by CS-PLAN-053 full review 2026-08-27

The "workaround" is not dead code: `RoomController::index` (:55-88) and
`FloorController::index` (:51-71) sniff body/query params and call `create()` — a
live `GET /api/v1/rooms?name=X` (or floor equivalent) creates structure. Nextcloud's
CSRF middleware only guards POST/PUT/DELETE, so this is a cross-site-forgable
state-changing GET (review finding CR-P3-08/CR-P3-12). Resolution rides on the
CS-PLAN-053 route-disposition decision (CR-053-14): **decided 2026-08-29 — prune**;
remove the GET-create branches when the 4 legacy alias/create routes are pruned; the 4
alias routes themselves have zero consumers (incl. tests).

- [ ] Remove the GET-create branches from both `index()` methods (keep POST create routes).
- [ ] Prune or keep the 4 documented-dead alias routes per the route-disposition decision.

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
> [`CODE_REVIEW_2026-08-11.md`](CODE_REVIEW_2026-08-11.md)). Gate re-verified green on
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
**Effort**: M — re-scoped by CS-PLAN-053 full review (2026-08-27): the
`createDefaultTemplates` and `extractHouseIdFromSchedule`/`ScheduleOptimizer`
sub-claims are **obsolete** (clusters deleted by CS-PLAN-027 D7 / CR-SVC-08).
Still live: `StreakFreeze`/`StreakRestock` per-user UPDATEs (unlocked RMW —
see CR-053-11 for the correctness half); `assignTask`/`unassignTask`/`deactivate`/
`reactivate`/`updateDueDate` re-fetch pattern in `RoomTaskService`; `hasFloors`
hydrates all floors for a boolean (`HouseService:303`). `TaskService::getAllTasks`
is now **caller-less** — belongs to the CR-053-15 dead-code sweep, not perf.

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
**Status**: Open · **Effort**: M · **Source**: Review of docs/concepts/ui-use-cases.md (2026-08-21)

Six `task-sync` routes are backend-only (task-model-and-sync.md). Blocked on
the cluster's expose-or-prune decision.

- [ ] Record the expose-or-prune decision for the 6 `task-sync` routes first.
- [ ] If exposed: link/unlink + pending/conflict list UI (RoomDetail or
      TaskManager) + tests; if pruned: close this item referencing the ADR.

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
> [`CODE_REVIEW_2026-08-27.md`](CODE_REVIEW_2026-08-27.md) (CR-P1-*…CR-P5-* IDs).
> Security items are **never** auto-fixed; they are decision points for the user.

### [P2] CR-053-05 — `RewardConfigController::update` is `@AdminRequired` + no owner gate + no production consumer
**Effort**: S · **Source**: CS-PLAN-053 (plan-mandated explicit security item; CR-P3/L2)
Instance admins are not necessarily house owners; reward economics should be owner-gated
(mirror `HouseController::update`) or the divergence documented. Both reward-config routes are
currently **test-only** (E2E) — include in the route-disposition decision (CR-053-14).

### [P2] CR-053-06 — Task lifecycle endpoints skip the visibility matrix (uncomplete/skip/reschedule)
**Effort**: S · **Source**: CS-PLAN-053 (CR-P3-03)
`complete` enforces private→assignee / admin-only→owner **in-service** (`RoomService:371-378`);
`uncomplete`/`skip`/`reschedule` enforce neither (grep: zero visibility checks in
`RoomTaskService`) — any member can uncomplete/skip/reschedule another member's private task.

### [P2] CR-053-07 — Bootstrap races: duplicate houses per instance + two-owners race
**Effort**: M · **Source**: CS-PLAN-053 (CR-P2-04)
`houses.nextcloud_instance_id` has only a non-unique index (`Version1000:39`) → concurrent
first requests can create two houses; `ensureHouseMember` assigns `role='owner'` when
`count==0` → two distinct users first-touching concurrently both become owner. Fix: unique
index + race-safe owner assignment (first insert wins).

### [P2] CR-053-08 — Task-sync completion replay: no idempotency, no transaction
**Effort**: M · **Source**: CS-PLAN-053 (CR-P2-05)
`TaskSyncService::syncCompletionFromNextcloud:140-188` re-runs `markCompleted` + cleanliness
on every replay with a marginally newer timestamp (next_due fast-forward, permanent 100
cleanliness); 3 dependent writes without a transaction. Bundle with the FEAT-UX-021
expose-or-prune decision (all 6 task-sync routes are production-unconsumed).

### [P2] CR-053-09 — Recurrence vocabulary divergence: `'once'` vs `'one_time'`
**Effort**: S · **Source**: CS-PLAN-053 (CR-P1-07)
`RoomTask::markCompleted:255`/`projectOccurrences:609` branch on `'one_time'`;
`TaskService:346` validates `['recurring','once','bounded']`. A validation-accepted `'once'`
task never deactivates on completion (silent infinite recurrence). Pick one vocabulary
(pre-release: data is disposable) and align both layers.

### [P2] CR-053-10 — Entity fields without schema columns: reminderType/reminderMinutesBefore
**Effort**: S · **Source**: CS-PLAN-053 (CR-P1-09)
`Task.php:138-141` declares reminder fields no migration creates; `TaskSyncService:238,248`
always reads null. Any set+flush path 500s on all engines. Decide: add columns or delete the
fields (feature is dead today). Related schema drift: `Room::$description` phantom API field (CR-P1-20).

### [P2] CR-053-11 — Streak & leaderboard correctness cluster
**Effort**: M · **Source**: CS-PLAN-053 (CR-P2-11/12/13/15/20)
(a) Weekly leaderboard breaks all Sunday (`'monday this week'` → next Monday, `MinigameService:163`);
(b) completion-path freeze consumption never stamps the covered day → one missed day can cost
two freezes (`GameLogicService:131` + `HouseUser:294`); (c) at-risk pass vs freeze pass have
undefined execution order → core notification suppressed; (d) weekly restock SETs
`min(perWeek,cap)` instead of topping up — punishes earned bonus freezes (`HouseUser:308`);
(e) leaderboard queries lack deterministic tiebreakers. Each fix is small + testable.

### [P2] CR-053-12 — Calendar-day semantics run on server TZ (UTC), not the household's
**Effort**: L (design decision first) · **Source**: CS-PLAN-053 (CR-P2-14 + CR-P1 TZ note + CR-P4-07)
**Decision (user, 2026-08-29)**: **per-house timezone** — `houses.timezone` column,
backend buckets day boundaries in house TZ (`DateTimeZone` injection at the call sites),
frontend's two day-key conventions unified on house TZ.
All day-boundary logic (streak days, freezes, at-risk, due-today, energy windows) buckets at
server-TZ midnight; frontend has two coexisting day-key conventions (`rangeTasksByDay` UTC slice
vs `localDateKey`). Needs a per-house timezone design decision before any code change.

### [P2] CR-053-13 — l10n artifact gap: 141 t() keys used in src/ missing from en+de
**Effort**: M · **Source**: CS-PLAN-053 (CR-P4-13/14/15)
German users get English fallback across TaskManager filters, Settings floor management,
Arcade, minigames; 8 keys are case-variant mismatches; ~340 dead artifact entries (keep
`titles.*` — server-driven). Root cause: `check-l10n-keys.js` only diffs de↔en. Fix: regenerate
artifacts + extend the checker with a src-usage diff; document in i18n.md.

### [P2] CR-053-14 — Route disposition decision (user sign-off required)
**Effort**: M · **Source**: CS-PLAN-053 (L3-0 matrix)
**Decision (user, 2026-08-29)**: **prune now** — delete the no-consumer + test-only
routes (incl. task-sync ×6, reward-config ×2, the 4 dead alias routes + their
GET-create branches = BUG-ROOM-001) together with the mirroring dead-store layer
(CR-053-15/20); re-add surfaces only when a consumer exists. Moots CR-053-05 while
the reward-config routes are gone.
110 routes = 67 production / 30 no-consumer / 12 test-only. Decision matrix in the review
record covers: 4 documented-dead alias routes (zero consumers incl. tests), REST-completeness
reads ×6, dead room-task variants ×4, dead task/checklist/user wrappers, the unconsumed
task-sync surface ×6, test-only reward-config + lifecycle routes. Deleting routes is
behavior-changing → needs explicit user approval; the frontend dead-store layer (CR-053-15)
mirrors these routes and should be decided together.

### [P3] CR-053-15 — Dead-code deletion sweep (proofs recorded, ready to execute)
**Effort**: M · **Source**: CS-PLAN-053 (L1-4/L2-4/L4-3)
Grep-proven no-caller code: `AvailabilitySettings` entity+mapper+table cluster (+TestDataService
injection + fixture + RESET_TABLES + Version1013); ~40 store getters/actions incl. the whole
`store/index.js` barrel; service methods (`TaskService::{getAllTasks,skipTask,rescheduleTask}`,
`RoomTaskService::{getTasksAssignedToUser,getUnassignedTasks}`, `RoomService::getRoom`,
`FloorService::getFloor`, `RewardConfigService::getTierMultiplier`, `AuthorizationService::{isAuthenticated,isHouseMember}` — check tests first);
entity helpers (`HouseUser` ×4, `CompletedTask::isRecent`, `MedalMapper` ×3 + `Medal` ×2,
`MinigameScoreMapper::findByHouseAndGame`, `validate()` ×2); dead migration steps (1017-1020,
backfills in 1024/1025, blocks in 1002/1003/1010/1015); orphaned tables (`task_scopes`,
`task_parts`, `claims`). Execute as one plan with per-deletion gates.

### [P3] CR-053-16 — NotFound→404 mapping cluster + unvalidated request bodies
**Effort**: M · **Source**: CS-PLAN-053 (CR-P3-11/12)
Systemic: nonexistent/foreign ids return 500 instead of 404 across TaskController (×3),
RoomTaskController (×12), Floor/Room/Checklist controllers; untyped JSON-body branches can
raise uncaught TypeError (Floor/Room `index()`); `limit` params unclamped (Minigame/TaskSync).
One shared pattern: wrap DoesNotExistException in service validators + per-controller catch.

### [P3] CR-053-17 — Silent exception swallowing in 10/15 controllers
**Effort**: M · **Source**: CS-PLAN-053 (L3-3)
Generic catch-alls return 500s with zero logging in RoomTask/Checklist/Floor/Minigame/Page/
Room/TaskSync/Test/User (+ Setup's weaker hand-rolled variant) — violates CS-PLAN-052's
AppLogger contract. Adopt `ControllerLoggingTrait` across the remainder.

### [P3] CR-053-18 — API hardening small-items cluster
**Effort**: M · **Source**: CS-PLAN-053 (CR-P3-04..10, CR-P2-09/10/17/18/19)
Test-mode routes registered with `@NoCSRFRequired` (CSRF-free destructive API when
NEXTCLOUD_TEST_MODE is set); saveScore accepts unwhitelisted gameIds with no rate cap (coin
farming); `ensureHouseMember` (auto-join) used on read paths with target-derived ids;
`ownerUserId` transfer without membership validation; `user#update` persists unvalidated
schedulingPreferences; anti-enumeration 403-vs-404 inconsistency; RewardConfig bare
DataResponse envelope; `needingCleaning` unscoped without floorId; no route `requirements`;
weekday case-handling divergence; notifier claimer display-name-as-uid; medal-notification
dedup collapse; reminder pass aborts on one orphaned task.

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
**Effort**: M · **Source**: CS-PLAN-053 (CR-P4-01..04, 06, 08, 09)
False "Weekly goal reached!" celebration on every Dashboard mount while goal achieved;
minigame `initialize()` leaves loading stuck on catalog failure; `completeRoomTask` leaves
rooms/house/user caches stale (**decision needed: wire the uncalled user-store reward actions
or delete them** — CR-P4-03 vs CR-053-15); undo/reactivate slice drift; `new Date(null)` →
epoch counted as overdue + UTC date parsing; out-of-order range/activity fetches; invite
duplicate row.

### [P3] CR-053-21 — Docs truth-repair batch
**Effort**: M · **Source**: CS-PLAN-053 (L5-1)
**Decision (user, 2026-08-29)**: root `README.md` gets a **full rewrite** (accurate
features, stack, install, v0.8.x alpha status) — not a historical banner.
Root `README.md` rewrite (tower-defense marketing, Sparks currency, removed features, "v1.0.0 RC"
status — decision: rewrite vs historical banner); ADR-003 scope enum contradicts code; architecture.md
fictional examples (`fetchHouseStats`, `TaskForm`/`TemplateSelector`, console.error example, stale
availability table, forbidden backlog-tree line); development.md `TaskItem.vue` + migration-conventions
subsection (version-then-date order, pre/post hooks never run on fresh install); testing.md
`TaskForm.vue`; security.md pruned-endpoint example; ui-use-cases.md header counts; energy-and-balancing/
task-model-and-sync/gamification unimplemented-feature markers.

### [P3] CR-053-22 — Test-gap closures deferred (deferral approved 2026-08-29)
**Effort**: L · **Source**: CS-PLAN-053 §4
**Decision (user, 2026-08-29)**: **deferral approved** with backlog tracking (risk
accepted: Minigame/RewardConfig/TaskSync controllers ship without integration-level
coverage until a catch-up plan). ~~The Checklist controller suite closes via
CS-PLAN-054 WP-4.~~ (closed there 2026-08-29: `ChecklistApiTest`, 24 real-DB tests)
Closed inline: `MinigameScoreTest` + `TaskAssigneeTest` entity suites (7 tests). Deferred with
reason (session scope of the XL review; each is mechanical but sizeable): integration suites
for ~~Checklist~~/Minigame/RewardConfig/TaskSync controllers (~~the Checklist one is the CR-053-01
companion~~ — closed via CS-PLAN-054), `TestDataSeedTest`, and Jest specs for the 8 uncovered components
(ActivityFeed, HouseStatsCards, MemberFairnessTable, WeekBudgetGrid, RoomPicker, BoostShop,
SnakeGame, SnakeLeaderboard). Deferral risk: the 3 remaining controllers ship without
integration-level coverage; regression protection relies on unit + E2E.

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
