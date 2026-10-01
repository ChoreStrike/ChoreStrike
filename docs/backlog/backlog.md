# Backlog — Open Items

> **Single source of truth for everything not yet done.** Entries are organized
> into the five dependency-oriented sections fixed by
> [CS-PLAN-058 §2.2](../plans/done/cs-plan-058-core-workflow-bottom-up-masterplan.md):
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
> ([CS-PLAN-058](../plans/done/cs-plan-058-core-workflow-bottom-up-masterplan.md);
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
**Owning**: unassigned · **Depends on**: — · **Blocks**: — · **Folded-in**: CONCEPT-001
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

**Family-rhythm review (2026-09-16):** also cover a freeze consumed by the job
followed by completion: `HouseUser::calculateStreakWithAbsence` ignores the
covered date, allowing another consumption/reset. The existing idempotency test
covers repeated jobs, not this cross-path sequence. D4 in the
[clarification plan](../plans/active/cs-plan-070-family-rhythm-and-reward-clarification.md)
now selects dated pauses instead of freezes (user decision 2026-09-17). Resolve
the freeze/restock portion through the reviewed removal and replacement coverage,
not new freeze mechanics. Existing code is unchanged, and the independent
leaderboard remainder keeps its ownership here; no whole-item closure is implied.

**WP3/WP4 disposition (2026-09-27):** the freeze/restock services and jobs were
retired with the WP3 legacy-effect cutover
([CS-PLAN-074](../plans/done/cs-plan-074-wp3-work-accounting-and-replay.md),
final Standard B `PASS-WITH-FIXES` 2026-09-27); the remaining consumers,
columns and the availability-streak helper were removed by the implemented WP4
child [CS-PLAN-075](../plans/done/cs-plan-075-wp4-rhythm-goals-and-ranking.md)
(implementation round 2026-09-27, delivered 2026-09-29: `calculateStreakWithAbsence`, the freeze
columns/handlers and the unconditional contribution leaderboard are gone —
member comparison is the consent-filtered exact-ratio ranking,
[ADR-015](../adr/ADR-015-ranking-consent-and-privacy.md)). The independent
Snake leaderboard ordering remainder stays open here; no whole-item closure is
implied.

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

The section reflects CS-PLAN-058's historical grouping. The CS-PLAN-070 draft
now explicitly selects notification settings and dated pauses for its new core,
followed by the optional family catalog; those choices do not activate code work.

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

---

### [P3] FEAT-UX-018 — Task-approval workflow for children
**Status**: Backlog · **Effort**: L · **Layer**: backend+frontend · **Journey stage**: task approval
**Owning**: unassigned · **Depends on**: — · **Blocks**: — · **Folded-in**: CONCEPT-001
**Source**: Review of docs/concepts/ui-use-cases.md (2026-08-21)

design.md §5 lists "task approval workflow for children"; gamification open
decisions touch it too. Today every completion is final immediately.

**2026-09-17 decision (CS-PLAN-070 D8/D11):** routine chore completion remains
immediate; child reward redemption has separate owner approval. A general chore
approval queue is explicitly outside that delivery slice, not a prerequisite for
the new rhythm model. This item remains a future option requiring a new decision.

- [x] First-slice decision: keep immediate completion; defer general chore approval.
- [ ] Revisit optional quality review only after a separate product decision.
- [ ] If selected, backend: needs-review state + owner approve/reject endpoints with
      membership/owner gating + tests.
- [ ] Owner approval queue UI + child-facing "waiting for approval" feedback.

---

### [P4] FEAT-UX-019 — Task Pass & support actions (gag mechanic)
**Status**: Backlog · **Effort**: M · **Layer**: backend+frontend · **Journey stage**: gamification
**Owning**: unassigned · **Depends on**: — · **Blocks**: — · **Folded-in**: CONCEPT-001
**Source**: Review of docs/concepts/ui-use-cases.md (2026-08-21)

gamification-and-rewards.md sketches a post-MVP "Task Pass" gag mechanic;
explicitly future / needs decision.

CS-PLAN-070 D11 explicitly defers this feature outside the first delivery slice
(2026-09-17). Transferring chores without agreement would conflict with the
approved responsibility model; a future product decision is still required.

- [ ] Product decision whether the gag mechanic fits the target audience.
- [ ] Only after decision: concept extension + endpoint design sketch.

---

### [P3] FEAT-UX-023 — Onboarding & help layer
**Status**: In Progress — WP6 essential help slice DELIVERED (2026-09-30): navigation-level glossary entry with the plain-language term glossary ([calm-core-ux](../concepts/calm-core-ux.md)); tour/catalog remainder WP8 · **Effort**: M · **Layer**: frontend · **Journey stage**: onboarding
**Owning**: CS-PLAN-077 WP6 (essential help slice, delivered); tour/catalog remainder WP8 · **Depends on**: — · **Blocks**: — · **Folded-in**: CONCEPT-001
**Source**: Review of docs/concepts/ui-use-cases.md (2026-08-21)

**WP6 slice delivered (2026-09-30):** [CS-PLAN-077](../plans/done/cs-plan-077-wp6-calm-core-ui.md)
delivered the essential slice as ONE navigation-level entry
(`HelpEntry.vue` + `HelpGlossary.vue`, always reachable from the nav,
modal glossary) instead of per-view entry points — one always-visible
entry beat three scattered ones (scope consolidation recorded in the
plan's Review Findings). Tour/catalog help and whole-item closure stay
with WP8.

No tour, glossary, or contextual hints; vocabulary drift (UX-007) makes
self-service discovery harder.

Family-rhythm clarification (CS-PLAN-070 D10): explain existing Nextcloud
accounts for children, wizard member invites versus child-role setup, shared
device sign-in and optional work versus personal commitments. No local child
profile or guardian-consent model should be implied by onboarding copy.

- [x] Help entry point reachable from the navigation (WP6 delivered one
      nav-level entry; per-view contextual entry points remain WP8).
- [x] Glossary unifying the core household vocabulary (Agreement,
      Catch-up, Pause, Streak, Quiet time, Ranking, Arcade, Coins).
- [ ] Contextual hints for the selected model (rhythm, completion window, pause,
      contribution, Coins); do not teach removed combo/freeze mechanics as target behavior.

---

### [P2] FEAT-UX-026 — Real-world reward shop
**Status**: Planned (selected after the reliable core; implementation not started) · **Effort**: L · **Layer**: backend+frontend · **Journey stage**: rewards
**Owning**: unassigned · **Depends on**: — · **Blocks**: — · **Folded-in**: CONCEPT-001
**Source**: Review of docs/concepts/ui-use-cases.md (2026-08-21)

The [reward options](../concepts/gamification-and-rewards.md#reward-options-for-a-family-first-app)
record the 2026-09-17 selection: recognition first, optional family catalog with
effort-only chore Coins, no arcade funding, retained awards. Children request with
owner approval; adults redeem directly; owners administer the catalog. Reserve
at request, settle at fulfillment, release on rejection/cancellation; existing
requests keep their price. Genuine correction can create a negative balance,
without rewriting unrelated events or reclaiming fulfilled rewards.

- [x] Core model/redemption decisions and concept extension recorded (D2/D7/D8/D11).
- [x] C3 rate/share examples and aggregate-underfunding hold policy recorded (2026-09-17).
- [x] Eight C4 actor, lifecycle, membership, visibility and refund decisions recorded (2026-09-17).
- [ ] Map C3/C4 and approved C5 UI/delivery rules to exact APIs, financial identity, erasure integration, transactions and race tests.
- [ ] Implement only after the reliable core and reviewed technical plan.

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

### [P1] CONCEPT-001 — Family-first rhythm and reward contracts
**Status**: In Progress — CS-PLAN-070 activated via Standard A `PASS-WITH-FIXES` (2026-09-18) · **Effort**: XL implementation (clarification was M) · **Layer**: docs+backend+frontend · **Journey stage**: product definition
**Owning**: unassigned · **Depends on**: — · **Blocks**: — · **Folded-in**: —
**Source**: User-requested family-first concept review (2026-09-16)

[CS-PLAN-070](../plans/active/cs-plan-070-family-rhythm-and-reward-clarification.md)
is the decision register and owning review-finding record. The user approved
24 core choices across D1-D11 and 47 C1-C5 answers (6/11/9/8/13) on 2026-09-17;
the technical draft added on 2026-09-18 maps schema/APIs, transactions/races,
exact files/tests and ten work packages (48-76 engineering days before contingency,
plus two weeks of voluntary family use). Q-070-01/02 on structural-deletion history
and true account erasure were answered in the 2026-09-18 Standard A review
(tombstoned minimum-history retention; account erasure/anonymization), which
passed with `PASS-WITH-FIXES` and activated the plan; the earlier dismissed
question batch was never counted as consent.
Runtime findings stay in that plan, not parallel backlog entries. Existing
add-on owners remain; notification/pause work belongs
to the selected core and the optional catalog follows it. Chore approval/Task Pass,
external sync, local child profiles, cosmetics and non-Snake games are outside
this delivery slice. No item is completed by the WP0 delivery alone.
WP0 (write/identity foundation) was sub-planned and delivered by the child
[CS-PLAN-071](../plans/done/cs-plan-071-wp0-write-identity-foundation.md)
(Standard A `PASS-WITH-FIXES` 2026-09-18; **final Standard B
`PASS-WITH-FIXES` 2026-09-19, plan in `done/`**): house-first lock order,
Version1030 commands/wallets/wallet_entries, wallet identity at every
membership path, `CommandService`, tombstone deletion with retained
evidence, account erasure + `UserDeletedListener`, T0 suites. WP1
(occurrence timeline) was sub-planned and delivered by the child
[CS-PLAN-072](../plans/done/cs-plan-072-wp1-occurrence-timeline.md)
(Standard A `PASS` 2026-09-20; **final Standard B `PASS-WITH-FIXES`
2026-09-23, plan in `done/`**): immutable rule revisions, stable occurrence
keys, saved windows/time basis, deterministic target selection, write-free
projections, `TimelineJob`, completion identity via `CommandService`
receipts, occurrence-scoped checklist/undo, current-client integration
and — per Q-072-01 Option A — the minimal common agreement writer with
`agreements` persistence and the necessary C2
deadline/authorization/capacity checks; ADR-013 recorded. The WP3+
behavior packages remain open in the parent; CONCEPT-001 remains open.
WP2 was sub-planned as [CS-PLAN-073](../plans/done/cs-plan-073-wp2-agreements-capacity-pauses.md)
(Standard A `PASS-WITH-FIXES` 2026-09-23; final Standard B `PASS-WITH-FIXES`
2026-09-25 — delivered; Q-073-01/02/03 resolved with the
recommended options, including the frozen cross-zone capacity/pause comparison):
agreements, exact planned shares, whole-day capacity, pauses, catch-up/restart
and midnight-stale 409, with concrete test/file ownership and explicit
WP3/WP4/WP5/WP6 handoffs. WP2 is delivered; later packages remain open.
WP3 was sub-planned and delivered by the child
[CS-PLAN-074](../plans/done/cs-plan-074-wp3-work-accounting-and-replay.md)
(activated 2026-09-25, Standard A `PASS`; **final Standard B re-review
`PASS-WITH-FIXES` 2026-09-27, plan in `done/`**): actual shares, exact
minor-unit wallet accounting (sole ledger authority, ADR-014), targeted
undo/attribution with departed-wallet conservation, room journal/replay
(CR-SVC-63 closed), the Q-074-01A rhythm/weekly/retained-recognition
correction closure, retention/erasure sweeps and the minimal client/no-Coin
arcade cutover; RF-074-WP3-01..10 resolved. Later packages (WP4+) remain
open in the parent; CONCEPT-001 remains open.
WP4 (rhythm/goals/ranking) was sub-planned as
[CS-PLAN-075](../plans/done/cs-plan-075-wp4-rhythm-goals-and-ranking.md)
(activated 2026-09-27 via Standard A `PASS-WITH-FIXES`; Q-075-01/02 Option A
selected) and **delivered** (final Standard B `PASS-WITH-FIXES` 2026-09-29):
rhythm/goal read contract, consent-filtered ranking with real
consent persistence in WP4,
single-series retained recognition and retired-mechanic removal (ADR-015).
WP5 (preferences/digest delivery) was sub-planned as
[CS-PLAN-076](../plans/done/cs-plan-076-wp5-preferences-and-digest-delivery.md)
(final Standard B `PASS-WITH-FIXES` 2026-09-30; delivered with ADR-016,
self-only preferences, quiet shared digest and complete T7 evidence).

WP6 (calm core UI) was sub-planned as
[CS-PLAN-077](../plans/done/cs-plan-077-wp6-calm-core-ui.md) and activated
2026-09-30 via Standard A `PASS-WITH-FIXES` (Q-077-01..05 all Option A);
**delivered** (final Standard B `PASS-WITH-FIXES` 2026-10-01): all-age My Day
landing/content, preference-gated calm feedback, full
`NotificationPreferences.vue` + optional-area consumers, pause UI, occurrence
agreement entry points, shared-device store reset, no-Coin closure and the
essential help slice; FEAT-UX-024/025 closed with it.
CONCEPT-001 remains In Progress for WP7-WP9 and the family evidence gate.

- [x] Core D1-D11 choices recorded through 24 explicit answers (2026-09-17).
- [x] Six C1 and eleven C2 choices recorded with future acceptance scenarios (2026-09-17).
- [x] Nine C3 choices recorded with accounting/replay design consequences and future scenarios (2026-09-17).
- [x] Eight C4 choices recorded with a durable actor/state matrix and future scenarios (2026-09-17).
- [x] Thirteen C5 choices recorded with delivery/UI/consent contracts, future scenarios and a family-test protocol (2026-09-17).
- [x] Specify technical cross-contract draft, exact file/test manifest, work-package owners/dependencies and estimates (2026-09-18; not implementation approval).
- [x] Resolve Q-070-01/02 explicitly and have Standard A accept the integrated design before activation (2026-09-18: tombstone retention + account erasure; Standard A `PASS-WITH-FIXES`).
- [ ] Resolve the plan's findings or explicitly rescope to named owners before completion.

---

### [P3] TECH-JEST-001 — Exclude local worktree mocks from Jest discovery
**Status**: Backlog · **Effort**: S · **Layer**: tooling · **Journey stage**: testing
**Owning**: unassigned · **Depends on**: — · **Blocks**: — · **Folded-in**: CS-PLAN-073 Standard B re-review (2026-09-24): reproduced duplicate worktree mocks with 1070 passing Jest tests; repeated 2026-09-25 with 1073 passing tests; no suppression or worktree changes
**Source**: Baseline verification during family-first concept review (2026-09-16)

`npm test -- --runInBand` passes all 1056 tests but reports duplicate manual
mocks from `.kilo/worktrees/`. `jest.config.js:4` excludes worktree test paths,
not the haste/module mock inventory. Defer the configuration change because
this round is a concept/documentation review, not a test-discovery fix. Use a
discovery-level exclusion and add a regression proving only the canonical mocks
are indexed; do not delete the user's worktree, suppress the warnings or skip
tests. The synthetic failure logs from `browserGate.spec.js` are expected test
output and are not this warning.

---

### [P3] TECH-TEST-003 — Integration-suite first-run-after-reset flake: shared instance house missing during ChecklistApiTest fixtures
**Status**: Backlog · **Effort**: M · **Layer**: tooling · **Journey stage**: testing
**Owning**: unassigned · **Depends on**: — · **Blocks**: — · **Folded-in**: —
**Source**: Review of CS-PLAN-072 WP1 final Standard B (2026-09-23; the same class of flake was noted in the RF-072-WP1-18 resolving round, 2026-09-22)

The Docker integration suite intermittently fails ONE
controller-validation test on the first run after a disposable-volume
reset — observed twice, both times
`ChecklistApiTest::testDeleteTaskItem_Member_Returns403` with
`DoesNotExistException` on the shared instance house during
`IntegrationTestCase::initializeScheduleForFixture` →
`RoomTaskReadService::timeZoneForHouse`. Immediate reruns on the same
volume are green (two consecutive full runs 403 tests / 1831 assertions,
2026-09-23). `ChecklistApiTest::setUp` resolves the shared house through
the process-memoized `HouseService` DI singleton (`getOrCreateHouse`);
investigation start: a preceding structural-deletion/tombstone test
removing the shared house row on a fresh volume combined with the
memoized resolution, first surfaced by the CS-PLAN-072 WP1 fixture seam
(schedule initialization per created room task) being the first reader of
the house row. Production behavior is correct — the house lookup SHOULD
fail for a deleted house; the defect is the shared-house lifecycle in the
integration harness. Root-cause and fix the harness (per-test house
isolation or a non-memoized house resolution in fixtures) instead of
relying on the rerun protocol; do not weaken the WP1 fixture seam.
Deferral reason: intermittent, harness-only, needs dedicated root-cause
effort; the two-green-rerun protocol keeps gate evidence trustworthy in
the meantime.

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

Exact blocking gates:
- [x] CS-PLAN-058 WP1..WP9 exit gates, each passed with its Standard B review
      (program closed; plan in `done/` 2026-09-16).
- [x] Final Full review of the complete owner/member/child journey: `PASS` or
  `PASS-WITH-FIXES` (CS-PLAN-058 §3 WP9) — Full review `PASS-WITH-FIXES`
  2026-09-14 incl. the D-FR-04 browser-gate pair.

Still blocking: the independent obligations above (BUG-SEC-001, SEC-AUDIT-001)
and the deployment/field-usage work itself.

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
