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
**Status**: In Progress (WP5 slice delivered 2026-09-09; **WP8 slice delivered 2026-09-13 by CS-PLAN-067**: `GameLogicService` 517 → 349 with the medal cluster extracted to `MedalService`, `CompletionService` 772 → 546 via `CompletionEffectService`/`CompletionPostCommitService`, `RoomTaskService` 833 → 643 via `RoomTaskReadService`; remaining structural candidates are inventoried in `docs/engineering/architecture.md` "Size Guideline Exception Inventory") · **Effort**: L · **Layer**: backend · **Journey stage**: performance
**Owning**: CS-PLAN-058 WP5+WP8 · **Depends on**: — · **Blocks**: — · **Folded-in**: —
**Source**: Review of `lib/Service` (2026-08-04)

Owned in portions: WP5 enforced the completion-path constraints while
decomposing completion (delivered — `CompletionService`; overdue-takeover and
post-commit dispatch extraction done); WP8 executes the remaining structural
decomposition after the contract freeze. Over the ~300-line/~30-line
guidelines: ~~`RoomService::completeRoomTask` (~200 lines)~~ (moved into
`CompletionService` by CS-PLAN-064; that service's size itself is WP8
decomposition input),
`GameLogicService` (517, 7 concerns), `handleTaskCompletion` (~113) /
`checkAndAwardMedals` (~62, 5 near-identical award blocks), `RoomTaskService` (833),
`RewardConfigService` (689 → 538 via CS-PLAN-049 registry, 2026-08-26 — still over
the guideline, accepted), `TaskService::createTask` (24 positional params).
- [ ] Extract cohesive steps (medal-rules table) and split GameLogicService
      along domain lines; revisit the `CompletionService` shape (the WP5
      aggregate owner is 772 lines incl. docblocks — phase-split candidate
      for WP8 without weakening its single-transaction boundary).

---

### [P3] CR-SVC-44 — Minor performance cluster
**Status**: In Progress (activated 2026-09-12 by CS-PLAN-067/WP8 Standard A; per-user streak UPDATE batching disposition owned by D5 → CR-053-11; **WP8 slice delivered 2026-09-13**: the `updateDueDate` after-commit re-fetch is gone — the in-transaction entity is the authoritative response, pinned by `WorkflowQueryBudgetTest` statement-order proof; no redundant workflow re-fetch remains in the WP8 manifest surfaces) · **Effort**: M · **Layer**: backend · **Journey stage**: performance
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

### [P3] CR-SVC-63 — Manual cleanliness adjust stays an unlocked RMW against the WP5 room-row lock
**Status**: Backlog · **Effort**: XS · **Layer**: backend · **Journey stage**: completion/undo
**Owning**: — (unscheduled; candidate for WP7/WP8 or a small standalone fix) · **Depends on**: CS-PLAN-064 (creates `RoomMapper::lockForUpdate`) · **Blocks**: — · **Folded-in**: —
**Source**: Review of CS-PLAN-064 (Standard A, 2026-09-06)

After WP5, completions/undos write room cleanliness on a locked room row
(`room_task → room → house_user` order), but the manual adjust endpoint
(`RoomController.php:330` → `RoomService::updateRoomCleanliness`,
find → `updateCleanliness` → update) remains an unlocked read-modify-write:
a concurrent manual adjust can still lose (or clobber) a completion's
cleanliness delta. Deferred by user decision to keep the WP5 scope guard
(completion/undo critical path only). Fix: route the manual adjust through
the new `RoomMapper::lockForUpdate` primitive inside a `TransactionExecutor`
run; add a two-connection adjust-vs-completion race regression.

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

**D5 rescoping (ratified 2026-09-12, CS-PLAN-067 Standard A):** cron
freeze/restock *batching* (performance only, preserving per-user failure
isolation) is owned here rather than in WP8; WP8 retains only the
remainder-classification inventory. The correctness halves above are unchanged
and remain independently schedulable.

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
