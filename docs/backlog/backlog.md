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

> The CR-WF umbrellas owned the core-journey acceptance criteria
> ([CS-PLAN-058](../plans/active/cs-plan-058-core-workflow-bottom-up-masterplan.md);
> review record: [`archive/CODE_REVIEW_2026-09-02.md`](archive/CODE_REVIEW_2026-09-02.md)).
> **Program closure 2026-09-14:** CR-WF-01..08 and every plan-owned item are
> archived; the final Full review (`PASS-WITH-FIXES`, D-FR-03 disposition)
> folded the remaining service-level pointers into their concrete owners
> (CR-053-11, CR-053-19) and re-sectioned the non-blocking remainders. This
> section is intentionally empty — no open item blocks or extends the core
> program.

*(No open items — the program's acceptance lives in CS-PLAN-058 §8; follow-up
findings of the final review are routed to Core Hardening / Deferred Add-ons
below.)*

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

### [P3] CR-SVC-63 — Manual cleanliness adjust stays an unlocked RMW against the WP5 room-row lock
**Status**: Backlog (re-sectioned from the Core Workflow Program section 2026-09-14, D-FR-03) · **Effort**: XS · **Layer**: backend · **Journey stage**: completion/undo
**Owning**: — (unscheduled standalone fix) · **Depends on**: — · **Blocks**: — · **Folded-in**: —
**Source**: Review of CS-PLAN-064 (Standard A, 2026-09-06)

After WP5, completions/undos write room cleanliness on a locked room row
(`room_task → room → house_user` order), but the manual adjust endpoint
(`RoomController#updateCleanliness` → `RoomService::updateRoomCleanliness`,
find → `updateCleanliness` → update) remains an unlocked read-modify-write:
a concurrent manual adjust can still lose (or clobber) a completion's
cleanliness delta. Deferred by user decision to keep the WP5 scope guard
(completion/undo critical path only). The authz defense-in-depth half was
closed by the CS-PLAN-058 final Full review (RF-058-06: service-side
room→floor→house chain validation). Fix: route the manual adjust through
`RoomMapper::lockForUpdate` inside a `TransactionExecutor` run; add a
two-connection adjust-vs-completion race regression.

---

### [P3] CR-SVC-64 — Midnight-crossing schedule apply answers 400 instead of 409 stale-preview
**Status**: Backlog · **Effort**: XS · **Layer**: backend · **Journey stage**: scheduling
**Owning**: — · **Depends on**: — · **Blocks**: — · **Folded-in**: —
**Source**: Review of CS-PLAN-058 (final Full review, 2026-09-14; RF-058-17)

When a preview is generated just before house-local midnight and applied just
after, the apply's `startDate >= today` validation fires first and answers
`400 VALIDATION_ERROR` ("startDate must be today or later") instead of the
stale-preview `409 CONFLICT` — the fingerprint revalidation would reject the
same request one code later. UX nit at the day boundary; fix: validate the
fingerprint/staleness ordering before the startDate window check, or map a
midnight-crossed startDate to the stale-preview conflict.

---

### [P4] CR-SEC-05 — Caller-gates-owner contract on removeHouseMember/updateUserRole
**Status**: Backlog · **Effort**: S · **Layer**: backend · **Journey stage**: authorization
**Owning**: — · **Depends on**: — · **Blocks**: — · **Folded-in**: —
**Source**: Review of CS-PLAN-058 (final Full review, 2026-09-14; RF-058-15)

`HouseService::removeHouseMember`/`updateUserRole` rely on a documented
"caller is responsible for requireHouseOwner" contract (controller-only gate,
tested), while sibling mutations (updateHouse, resetHouse since RF-058-02,
invite paths, structure services) enforce owner-only service-side per
ADR-006's defense-in-depth standard. Documented and not exploitable through
current callers; align the two methods with the service-side pattern when
next touched.

---

### [P4] CR-PERF-01 — SchedulePlanService apply O(n²) row scans
**Status**: Backlog · **Effort**: XS · **Layer**: backend · **Journey stage**: scheduling
**Owning**: — · **Depends on**: — · **Blocks**: — · **Folded-in**: —
**Source**: Review of CS-PLAN-058 (final Full review, 2026-09-14; RF-058-14)

`SchedulePlanService::apply` calls `findRowById` (linear scan of
`plan['response']['changes']`) inside the loop over changed room-task ids for
both the due-date and assignee phases — O(n²) scans, though in-memory only and
bounded by ≤100 changed rows (no queries, no frozen-budget impact). Fix: key
the changes array by roomTaskId once before the loops.

---

## Deferred Add-ons

> Explicitly outside the core critical path (CS-PLAN-058 §1.3). These cannot
> enter the critical path without a plan update.

### [P3] FEAT-UX-015 — Leave house (self-service membership exit)
**Status**: Open (semantics decided, endpoint/UI open; re-sectioned from the Core Workflow Program section 2026-09-14, D-FR-03) · **Effort**: M · **Layer**: backend+frontend · **Journey stage**: authorization
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

### [P4] FEAT-UX-032 — SetupWizard silently drops the basement at the floor cap
**Status**: Backlog · **Effort**: XS · **Layer**: frontend · **Journey stage**: setup
**Owning**: — · **Depends on**: — · **Blocks**: — · **Folded-in**: —
**Source**: Review of CS-PLAN-058 (final Full review, 2026-09-14; RF-058-16)

`SetupWizard` clamps `floorTotal = min(6, floorCount)` and appends the basement
only when `floors.length < 6`: with `floorCount = 6` and the basement checkbox
checked, the basement is silently NOT added and the checkbox stays checked
without effect. Cap edge only. Fix options: clamp floorCount to 5 when a
basement is requested, or show an inline hint that the basement replaces the
sixth floor.

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
