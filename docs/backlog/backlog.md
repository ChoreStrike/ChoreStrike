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

### [P3] UX-018 — Insights fairness table scrolls the whole view sideways on phones
**Status**: Backlog · **Effort**: XS · **Layer**: frontend · **Journey stage**: insights
**Owning**: unassigned · **Depends on**: — · **Blocks**: — · **Folded-in**: —
**Source**: TECH-DOCS-005 screenshot capture (2026-10-17)

At 390 px the `member-fairness` table (`MemberFairnessTable.vue`, the table is
the component root) is about 430 px wide, so the whole Insights content scrolls
horizontally; measured on NC 35.0.2. It already overflowed with the old 1rem
phone padding; the `--cs-view-inline-start` view inset (2026-10-17) adds 34 px.
Give the table its own horizontal scroll container (or a stacked phone layout)
so only the table scrolls, and extend `tests/e2e/layout-clearance.spec.ts` with
a no-horizontal-overflow check per view at phone width.

- [ ] Contain the table's overflow at phone width.
- [ ] Add the phone-width overflow check for every routed view.

---

### [P3] CR-084-01 — Calendar marks a past planned day with an open flex window overdue
**Status**: Backlog · **Effort**: S · **Layer**: frontend · **Journey stage**: calendar
**Owning**: unassigned · **Depends on**: — · **Blocks**: — · **Folded-in**: —
**Source**: Review of CS-PLAN-084 (final Full review, 2026-10-17; user-rescoped)

`CalendarView.isOverdue` (`src/views/CalendarView.vue`) compares each day
entry's planned date key with today. A projected entry whose planned day has
passed but whose flex window is still open is therefore red in the calendar,
while the task lists, filters, badges, claim button and server status treat it
as due (D-084-08). The shared `taskDisplay.isOverdue` cannot be applied as is:
the calendar lists several days per room task, so the rule must be evaluated
per day entry (planned day plus the occurrence's flex-window end in the house
time zone), with a regression for the open-window case.

---

### [P3] CR-084-02 — A joined in-flight completion drops the second call's allocation
**Status**: Backlog · **Effort**: S · **Layer**: frontend · **Journey stage**: completion
**Owning**: unassigned · **Depends on**: — · **Blocks**: — · **Folded-in**: —
**Source**: Review of CS-PLAN-084 (final Full review, 2026-10-17; user-rescoped)

`roomTasksStore.completeRoomTask` returns the pending promise when the same
room task is already completing and ignores the second call's options. If a
plain completion is in flight and a participation-dialog submission
(`participants`, `allocationReviewed`) joins it, the reviewed split is silently
dropped and the caller reports success. The hold guard makes this rare. Reject
a join whose options differ materially (or keep the dialog open), and pin
identical-payload joining with a test.

---

### [P3] CR-084-03 — Single-row room-task payloads use the entity overdue rule
**Status**: Backlog · **Effort**: S · **Layer**: backend · **Journey stage**: task status
**Owning**: unassigned · **Depends on**: — · **Blocks**: — · **Folded-in**: —
**Source**: Review of CS-PLAN-084 (final Full review, 2026-10-17; user-rescoped)

The house list, room detail rows and room statistics derive `status` from the
occurrence classification (`RoomTaskReadService::statusFor`), but create,
update and show serialize `RoomTask::getStatus($tz)`, a house-local day-bucket
rule. Inside an open flex window after the planned day, the entity says
`overdue` while the list says `due_today`; the client falls back to `status`
for a row without an `occurrence` block, so such a row can turn red until the
next list read. Make `statusFor` the single source for every payload that
carries `status` (or document the entity rule as the occurrence-free
fallback), with integration coverage.

---

### [P4] CR-084-04 — Split the HoldButton hold state machine from its view
**Status**: Backlog · **Effort**: S · **Layer**: frontend · **Journey stage**: completion
**Owning**: unassigned · **Depends on**: — · **Blocks**: — · **Folded-in**: —
**Source**: Review of CS-PLAN-084 (final Full review, 2026-10-17; user-rescoped)

`src/components/Common/HoldButton.vue` (CS-PLAN-084 WP-E) is above 400 lines,
over the ~300-line guidance: roughly 150 lines of scoped ring/hint styles and
the press/hold/settle timer logic. Extract the timers into a `useHoldPress`
composable (or the styles into a shared sheet) without changing behavior; the
existing HoldButton spec and the browser `holdToConfirm` paths are the
regression net.

---

### [P3] CR-PWA-01 — PWA manifest hardcodes the `/apps/chorestrike/` path
**Status**: Backlog · **Effort**: S · **Layer**: frontend · **Journey stage**: phone access
**Owning**: unassigned · **Depends on**: — · **Blocks**: — · **Folded-in**: —
**Source**: requalification of `master` for `0.8.0-alpha.1` (2026-10-17; access-log review)

`img/manifest.webmanifest` (linked by `PageController` through `imagePath`)
names `/apps/chorestrike/img/app.svg` as its icon and `/apps/chorestrike/` as
`start_url` and `scope`. The test runtimes and the AIO install the app under
`custom_apps`, so every page load requests the icon from a path that answers
404 (82 times in one full browser gate), and a home-screen shortcut gets no
icon. Already present in the qualified `0.1.1`/`0.8.0-alpha.1` builds; it
does not affect the app's function. Serve the manifest with paths from
`IURLGenerator` (icon via `imagePath`, start URL via `linkToRoute`), or make
them relative to the manifest, with a test against a `custom_apps` install.

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
**Status**: In Progress - WP6 essential help slice DELIVERED 2026-09-30; WP8 essential onboarding/catalog/contextual-help slice DELIVERED 2026-10-02 via CS-PLAN-079 (five contextual topics on the shared named Modal plus reward glossary terms); wider tour scope remains open · **Effort**: M · **Layer**: frontend · **Journey stage**: onboarding
**Owning**: CS-PLAN-077 WP6 (delivered); CS-PLAN-070 WP8 (essential slice); wider guided tour/help unassigned · **Depends on**: — · **Blocks**: — · **Folded-in**: CONCEPT-001
**Source**: Review of docs/concepts/ui-use-cases.md (2026-08-21)

**WP6 slice delivered (2026-09-30):** [CS-PLAN-077](../plans/done/cs-plan-077-wp6-calm-core-ui.md)
delivered the essential slice as ONE navigation-level entry
(`HelpEntry.vue` + `HelpGlossary.vue`, always reachable from the nav,
modal glossary) instead of per-view entry points — one always-visible
entry beat three scattered ones (scope consolidation recorded in the
plan's Review Findings). WP8 continues the parent's essential account/role,
share/rhythm/pause and catalog/contextual-help slice; whole-item closure still
requires any broader guided tour/help scope to be completed or explicitly scoped.

The navigation glossary is delivered. Contextual hints and onboarding/catalog
guidance remain; a wider guided tour is not implied by the essential WP8 slice.

Family-rhythm clarification (CS-PLAN-070 D10): explain existing Nextcloud
accounts for children, wizard member invites versus child-role setup, shared
device sign-in and optional work versus personal commitments. No local child
profile or guardian-consent model should be implied by onboarding copy.

- [x] Help entry point reachable from the navigation (WP6 delivered one
      nav-level entry; per-view contextual entry points remain WP8).
- [x] Glossary unifying the core household vocabulary (Agreement,
      Catch-up, Pause, Streak, Quiet time, Ranking, Arcade, Coins).
- [x] Contextual hints for the selected model (accounts/roles, agreements/shares,
      progress/windows, catalog gates, owner pricing) as manually triggered
      context topics — delivered with the WP8 slice 2026-10-02; do not teach
      removed combo/freeze mechanics as target behavior.
- [x] Detail the essential WP8 slice and its tests in [CS-PLAN-079](../plans/done/cs-plan-079-wp8-reward-ui-and-onboarding.md) (2026-10-02;
      documentation only, no activation or runtime implementation).
- [ ] Complete wider guided tour/help scope separately before whole-item closure.

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

Global search has no UI. **Status correction (2026-10-03):** the old
`GET /tasks/search` route and `searchTasks` wrapper were already pruned; do not
plan against an existing endpoint/wrapper. A future implementation must define
the actual search contract, not revive a dead API by assumption.

- [ ] Search entry point covering task definitions + room tasks (NC search
      integration or in-app field).
- [ ] Define the API or Nextcloud integration needed for the selected search scope.
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

### [P4] FEAT-UX-037 — Derive the Nextcloud app icon from the brand mark
**Status**: Backlog · **Effort**: S · **Layer**: frontend · **Journey stage**: branding
**Owning**: unassigned · **Depends on**: — · **Blocks**: — · **Folded-in**: —
**Source**: TECH-DOCS-005 brand mark (2026-10-17)

`img/app.svg` still shows the pre-brand green house, broom and shield, so the
app navigation and the repository avatar no longer match. The full mark is too
detailed for 64 px: derive a simplified symbol (broom mascot or monogram)
per [`design.md`](../standards/guidelines/design.md#4-brand-assets) §4 and check
it against the Nextcloud icon conventions for the header and app settings on
both runtime rows (monochrome `app.svg`, optional `app-dark.svg`).
`img/` ships in the app package, so this changes the package file set.

- [ ] Derive the 64 px symbol and update `img/app.svg` (plus `app-dark.svg` if the conventions require it).
- [ ] Verify it in the header, app settings and PWA manifest in light and dark themes on NC34 and NC35.

---

## Infrastructure and Documentation

> Backlog hygiene, lint/dependency, docs truth, and operational security.
> Independently schedulable unless a gate depends on it (CS-PLAN-058 §2.2).

### [P1] TECH-TEST-005 — Successor ownership and mobile lifecycle coverage
**Status**: In Progress (automated scope delivered by CS-PLAN-084; operator real-device run open) · **Effort**: M · **Layer**: tests · **Journey stage**: completion / handover
**Owning**: CS-PLAN-080 (real-device run; user-rescoped from CS-PLAN-084 WP-G on 2026-10-17) · **Depends on**: — · **Blocks**: — · **Folded-in**: —
**Source**: lifecycle test inventory, 2026-10-13

Delivered by [CS-PLAN-084](../plans/done/cs-plan-084-family-trial-ux-baseline.md)
WP-G: successor-ownership integration proofs after takeover, one-occurrence
handover and reassignment, the claim-confirm E2E, and the `mobile-chromium` /
`mobile-webkit` projects with the seven-scenario `mobile-core.spec.ts` in every
full browser gate. **Open:** the operator's recorded real-device run (Android +
iPhone, child and owner, light and dark) against the deployed instance before
the family trial starts
([release-test-plan §14.1](../testing/release-test-plan.md#141-real-device-checklist-cs-plan-084-operator)),
owned by [CS-PLAN-080](../plans/active/cs-plan-080-wp9-cross-db-and-family-evidence.md).
Emulation proves layout and request flow, not a real finger, screen reader or
vibration motor.

---

### [P3] TECH-TRANSPORT-001 — Attribute intermittent browser API connection termination
**Status**: Backlog · **Effort**: S · **Layer**: tooling · **Journey stage**: test-runtime reliability
**Owning**: unassigned · **Depends on**: — · **Blocks**: — · **Folded-in**: 2026-10-11 core-feedback gate fixture-seed connection closures; 2026-10-14 CS-PLAN-084 WP-B/WP-E/WP-F/WP-C focused-run closures; 2026-10-15 CS-PLAN-084 full-gate closures with access-log correlation
**Source**: installation follow-up qualification, 2026-10-09 (`run-J0ndFy`; sanitized summary in [the handoff](../testing/install-preparation-2026-10-08.md#diagnostic-boundaries))

An NC35 browser API GET terminated before any HTTP response during parallel
local runtime qualification. Retained traces exclude an early context close or
fixture teardown, but do not identify the terminating layer. This is distinct
from the reproduced application races, which were repaired. Both subsequent
complete sequential v4 gates pass without retries or skipped required tests.

The initial v5 NC35 full gate later also recorded fixture seed POST failures
(`fetch failed`, cause `SocketError: other side closed`) before application
assertions, followed by an outer runner timeout. No restart/OOM or Apache fatal
event was found. A controlled keep-alive hypothesis did not reproduce failure;
no request-retry or speculative header change was made. The affected suite
passes in isolation. Retain this additional evidence; do not narrow the item
to parallel-runtime load or claim that the transport cause has been established.
The complete unchanged-source NC35 requalification `run-RPFVu9` subsequently
passes all 295 executions without retries; that does not close this attribution
item or erase the failed run.

Core-feedback gate `run-t4pJYd` (2026-10-11) records two further seed POST
`fetch failed` / `SocketError: other side closed` failures before the
family-rhythm date-amendment and house-pause assertions. This is recurrence of
the observed symptom, not new root-cause evidence. No backend source changed in
that round. Raw reports stay local; RF-080-15 retains the required full-gate
requalification blocker rather than treating these tests as passed or skipped.

CS-PLAN-084 WP-B focused runs (2026-10-14, canonical NC35, CSS-only round)
record one seed POST `fetch failed` / `SocketError: other side closed`
(scheduling-apply-flow, scheduling-owner pool started concurrently with the
chromium pool) and one authenticated GET `socket hang up`
(insights-flow, serial pool alone) before assertions. Both specs pass isolated
in their pools (11/11, 10/10). Recurrence evidence only; no cause established.
WP-E/WP-F focused runs (2026-10-14, serial pool alone) add one seed `fetch
failed` (calm-core-ui, round 4) and one seed-fixture target preflight failure
(calm-core-ui free-day test, round 5), both before any assertion; calm-core-ui
passes isolated (9/9). WP-C (round 6, serial pool alone) adds one seed POST
`fetch failed` / `SocketError: other side closed` (room-management rename,
before any assertion); room-management passes isolated (10/10).

**Attribution evidence (CS-PLAN-084 rounds 8–11, 2026-10-14/15, full sequential
gates):** `run-fVpMiR` 3, `run-02sTE9` 8 and `run-Tiu5Sv` 2 seed POST
`fetch failed` closures (all `DatabaseManager.seed`, Node fetch from the
fixture), against six complete gates on both rows with none. Correlated with
the canonical container's Apache access log, the failed seed POSTs **never
reached Apache** (every one of 328 logged seed POSTs in the window answered
200; no restart, OOM, fatal or Apache error). The terminating layer is
therefore before Apache — the client's pooled connection or the Docker Desktop
loopback port proxy — not the app or Nextcloud. The two failing runs were the
latest in a long local session; the same session also produced load-timing
assertion failures (5 s expectations) that pass isolated. The next AIO-row gate
`run-jbuGNm` (NC 34.0.4, other container and port) failed once with a
Playwright `apiRequestContext` GET `socket hang up` — a different client — and
that GET is likewise absent from its Apache access log. The shared layer of
both rows and both clients is the Docker Desktop loopback port forwarding /
host networking of the long-running session. After an operator-approved Docker
Desktop restart (no other change) both full gates passed cleanly (`run-L6HInO`
canonical, `run-Vq2pkA` AIO) — consistent with, not proof of, a degraded
long-running port forwarder.

**Recurrence (2026-10-17, requalification of `master` `0d27d38`, staged rows):**
earlier full gates in the same Docker Desktop session passed on both rows
(`run-nKi6vJ`, `run-p5CXyQ`, `run-6jDStt`). Later in that session the
primary row failed twice: `run-BQqw1G` on one `DatabaseManager.seed` POST
`fetch failed` absent from Apache's access log (23 logged seed POSTs in the
window, all 200), and `run-8f3fhe` on one more such closure plus three views
that stayed empty past their 5 s expectations and one dark-theme contrast
sample, each in a minute where Apache logged 13–33 requests instead of the
usual 400–1300; no restart, OOM or fatal. After an operator-approved Docker
Desktop restart the fresh row passed first time (`run-I3xSLN`). Same signature
as above — the stall sits before Apache and grows with session age; still not
proof of the terminating layer.

**Deferral reason / scope:** attribution needs separate test-runtime/transport
instrumentation and load comparison; it is not an outstanding bootstrap or
Settings implementation criterion. Passing sequential gates are not proof of
parallel-runtime reliability. No request-error filter or retry workaround was
introduced, and raw traces/headers remain private.

- [ ] Correlate sanitized client, server and Docker lifecycle evidence to identify the terminating layer.
- [ ] Compare isolated and parallel runtime execution without changing required assertions or mutation retry semantics.
- [ ] Fix a reproducible cause and add a targeted regression; otherwise record the bounded attribution result and remaining uncertainty.

---

### [P1] CONCEPT-001 — Family-first rhythm and reward contracts
**Status**: In Progress — CS-PLAN-070 activated via Standard A `PASS-WITH-FIXES` (2026-09-18) · **Effort**: XL implementation (clarification was M) · **Layer**: docs+backend+frontend · **Journey stage**: product definition
**Owning**: CS-PLAN-070 · **Depends on**: — · **Blocks**: — · **Folded-in**: —
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
WP7 was delivered via [CS-PLAN-078](../plans/done/cs-plan-078-wp7-catalog-and-request-backend.md)
(final Standard B `PASS-WITH-FIXES` 2026-10-01; catalog/C4 backend, ADR-017).
WP8 was delivered via [CS-PLAN-079](../plans/done/cs-plan-079-wp8-reward-ui-and-onboarding.md)
(final Standard B `PASS-WITH-FIXES` 2026-10-02: reward UI/onboarding, two catalog gates,
persistent request surfaces, exact prices, uncertain-command replay, essential help).
CONCEPT-001 remains In Progress for WP9 (cross-DB/family evidence) — the closing gate.
WP9 is planned as [CS-PLAN-080](../plans/active/cs-plan-080-wp9-cross-db-and-family-evidence.md)
(drafted 2026-10-02, activated 2026-10-03 via Standard A `PASS`, zero findings;
WP9 owner): isolated MariaDB/PostgreSQL/SQLite verification,
two consecutive combined browser gates and voluntary adult/child comprehension
plus 14-day everyday-use evidence. Its NC31 technical matrix and consecutive
browser pair passed on 2026-10-05; human coordination, guardian consent and
minimum voluntary participation were confirmed on 2026-10-07, but comprehension
and 14-day evidence remain uncollected.
The separate TECH-RUNTIME-001 qualification is delivered by CS-PLAN-082
(final Full `PASS-WITH-FIXES` 2026-10-06, archived); the old-runtime passes remain
historical and do not replace its NC35/NC34 evidence or authorize retained use.

**Pre-WP9 preparation delivered (2026-10-05):**
[CS-PLAN-081](../plans/done/cs-plan-081-pre-wp9-hardening-and-retained-data-readiness.md)
passed its final Full review (`PASS-WITH-FIXES`).
Its bounded H4 reset-copy clarification contributes to this existing parent
owner; H6 retained-data/deployment preparation belongs to RELEASE-002. It does
not close parent findings or replace WP9 evidence.

- [x] Core D1-D11 choices recorded through 24 explicit answers (2026-09-17).
- [x] Six C1 and eleven C2 choices recorded with future acceptance scenarios (2026-09-17).
- [x] Nine C3 choices recorded with accounting/replay design consequences and future scenarios (2026-09-17).
- [x] Eight C4 choices recorded with a durable actor/state matrix and future scenarios (2026-09-17).
- [x] Thirteen C5 choices recorded with delivery/UI/consent contracts, future scenarios and a family-test protocol (2026-09-17).
- [x] Specify technical cross-contract draft, exact file/test manifest, work-package owners/dependencies and estimates (2026-09-18; not implementation approval).
- [x] Resolve Q-070-01/02 explicitly and have Standard A accept the integrated design before activation (2026-09-18: tombstone retention + account erasure; Standard A `PASS-WITH-FIXES`).
- [ ] Resolve the plan's findings or explicitly rescope to named owners before completion.

---

### [P4] TECH-DIGEST-001 — `DigestChangeMapper::expireQueuedBefore` uses `setMaxResults()` on an UPDATE
**Status**: Backlog · **Effort**: S · **Layer**: backend · **Journey stage**: digest maintenance
**Owning**: unassigned · **Depends on**: — · **Blocks**: — · **Folded-in**: —
**Source**: Review of CS-PLAN-078 WP7 final Standard B (2026-10-01)

Doctrine DBAL's `getSQLForUpdate()` never emits LIMIT (verified on the NC31
container), so the documented 500-row batch bound in
`DigestChangeMapper::expireQueuedBefore()` never applies — the expiry sweep
updates ALL matching queued rows in one statement. The expiry path is a
maintenance sweep (not the hot digest pass), so the current behavior is
functionally correct and merely unbounded; either drop the limit parameter
and document unbounded expiry, or implement chunked expiry via a bounded
SELECT of ids followed by UPDATE ... WHERE id IN (...). Not a WP7 regression
(pre-existing WP5 defect).

---

### [P3] BUG-SEC-001 — Security reporting contact for later external use
**Status**: In Progress (contact documented, delivery verified and published; authority feedback on the deferred legal/privacy notice pending) · **Effort**: S · **Layer**: docs · **Journey stage**: security reporting
**Owning**: unassigned · **Depends on**: — · **Blocks**: RELEASE-002 (external-use stage only) · **Folded-in**: H7/H-T11 user rescoping (2026-10-04); operator-approved project mailbox and public-contact guidance (2026-10-12); reporting-policy refinements and legal-notice deferral (2026-10-13)
**Source**: CS-001 security review; CS-PLAN-081 H7 Post-Review Scope Decision; user-provided project address 2026-10-12; explicit policy and scoped-commit request 2026-10-13

The operator initially tests alone and explicitly removed H7/H-T11 from
[CS-PLAN-081](../plans/done/cs-plan-081-pre-wp9-hardening-and-retained-data-readiness.md)
on 2026-10-04. No new reporting address or channel test is required for that
self-test stage or preparation-plan completion. This item remains open for
later external tester onboarding / public release, not a completed H7 claim.

The operator supplied `chorestrike@gallero.de` on 2026-10-12 for public project
contact. README and `docs/SECURITY.md` now document that mailbox; contributor
and conduct guidance distinguish public issues from private reports. This
ordinary-email route was independent of the repository migration (TECH-REPO-001,
completed 2026-10-16) and does not require GPG, a separate `security@` alias or
a GitHub-associated address. GitHub private vulnerability reporting is now
offered alongside it as the preferred channel. The operator
authorized a scoped local documentation commit on 2026-10-13, not a push,
mailbox login, test email or account/profile change.

The refined policy requires explicit permission before publicly crediting a
reporter, explains that ordinary email is not end-to-end encrypted, requests
notification when upstream issues affect the app, and distinguishes fixes on
`master` / subsequent releases from unsupported older-release backports.

**Operator decision (2026-10-13):** do not add a legal notice or separate
privacy information while awaiting feedback from the responsible media
authority. This is a temporary deferral, not a finding of compliance or an
exemption. Incoming report emails and sender data are distinct from household
data processed in other operators' Nextcloud installations; `docs/SECURITY.md`
is an operational reporting policy, not complete data-protection information.

Mailbox creation, the receipt of a test email and replying from the project
address are operator-confirmed (2026-10-17). The contact is public in README and
`docs/SECURITY.md` on GitHub since the approved CS-PLAN-085 push (2026-10-16).
The item remains open only for the authority's feedback on the deferred legal
notice and privacy information; the mailbox must stay monitored, not
commit-only `noreply`. No public security issues or sensitive real-user payloads.

- [x] Record the operator-approved reporting mailbox (2026-10-12).
- [x] Add the contact to local README/security guidance and replace the deferred-contact note; GPG remains optional.
- [x] Refine public-credit consent, email limitations, affected-dependency reporting and release/backport wording (2026-10-13).
- [x] Confirm receipt of a test email (operator, 2026-10-17).
- [x] Confirm a reply sent from the project address without exposing a private sender address (operator, 2026-10-17).
- [ ] Reassess the deferred legal notice and privacy information after the authority's response, including the handling of incoming report emails.
- [x] Publish the verified contact with an explicitly approved source update before external-user onboarding; retain correct links for the selected hosting platform (public on GitHub since the CS-PLAN-085 push, 2026-10-16; delivery verified 2026-10-17).

---

### [P3] TECH-DOCS-005 — README screenshots for the public landing page
**Status**: In Progress · **Effort**: XS · **Layer**: docs · **Journey stage**: repository hosting
**Owning**: unassigned · **Depends on**: — · **Blocks**: — · **Folded-in**: repository topics and avatar (CS-PLAN-085, 2026-10-16)
**Source**: CS-PLAN-085 WP5-2 (droppable item, deferred 2026-10-16)

The migrated README carries a badge row but no screenshots, so the GitHub
landing page shows no interface. CS-PLAN-085 explicitly allowed dropping this
without blocking the migration.

Capture 3-4 views (My Day, weekly planner preview, dashboard, reward catalog)
from the canonical Docker runtime with the existing Playwright setup — seeded
profile data only, never real household content — store them under
`docs/screenshots/`, and reference them from `README.md`.

- [x] Capture the views and store them under `docs/screenshots/` (My Day, weekly planner preview and reward catalog from an isolated staged NC 35.0.2 row with the `family` seed, 2026-10-17; the canonical stack was busy with another branch).
- [x] Add the dashboard view once its house-health bar renders again (fill, icons, header gap and the view inset fixed before the `0.8.0-alpha.1` deployment at the operator's request; all four views recaptured on the fixed build, 2026-10-17).
- [x] Reference them from `README.md` (2026-10-17).
- [ ] Confirm they render on GitHub after the next push.
- [x] Set the repository topics in the About dialog (folded in from CS-PLAN-085; set by the operator and verified with `gh repo view`, 2026-10-16).
- [x] Create the repository avatar as the brand mark per [`design.md`](../standards/guidelines/design.md#4-brand-assets) §4 (operator chose the "Night Ops" CS monogram with a broom mascot; source and 512 px export under [`docs/brand/`](../brand/chorestrike-avatar.svg), 2026-10-17).
- [ ] Upload the avatar in the GitHub organization settings (operator; web UI only).

---

## Release

> Stage path: `v0.8.x` alpha → `v0.9.x` beta → `v1.0.0` stable (manual install).
> App Store submission is explicitly deferred until stable quality is proven.

### [P2] RELEASE-002 — Beta deployment + real-world alpha usage
**Status**: Backlog (qualification slice delivered; deployment/field use remain open) · **Effort**: M · **Layer**: — · **Journey stage**: release
**Owning**: unassigned (CS-PLAN-083 WP1-WP3 delivered; remaining deployment/adoption is operator-owned) · **Depends on**: CS-PLAN-058 WP1..WP9 exit gates + final Full review PASS · **Blocks**: RELEASE-003 · **Folded-in**: retained-data trial readiness and safe recovery boundary (2026-10-03 user request); concrete AIO installation handoff/runbook gaps (2026-10-07); bounded remaining build/test dependency disposition (2026-10-08 user decision); alias-free and deterministic package qualification gates (CS-PLAN-083, 2026-10-10)
**Source**: staged release effort (roadmap); blocking-gate list fixed by CS-PLAN-058 §2.2/§3

Deploy to a personal Nextcloud instance; verify no impact on other apps;
real-world `v0.8.x` alpha usage; (optional) private tester onboarding for
`v0.9.x` beta with structured feedback intake.

[CS-PLAN-083](../plans/done/cs-plan-083-release-qualification-gates.md) completed
its bounded alias-free provisioning, deterministic clean-candidate identity
and wired same-archive NC35/NC34 qualification slice on 2026-10-10 (final Full
`PASS-WITH-FIXES`, all RF-083-01..17 resolved). Renewed candidate `853f661`
produces the same 522-file archive in two builds and the strict package smoke;
full browser pair `run-IDVdFy`/`run-EpjkYH` and fresh/populated preservation
proofs pass. The initial `325f23b` qualification remains historical, superseded
by the review-hardened candidate. This contribution does not close RELEASE-002,
perform deployment/adoption or replace CS-PLAN-080's independent human evidence.
See the [completion/review record](archive/CODE_REVIEW_2026-10-10.md).

The generic deployment guide still needs a verified production package manifest,
target-specific AIO commands and a concrete adoption record before independent
execution. Resolve these in the deployment work, not by guessing container paths
or treating the disposable test stack as a family installation. The 2026-10-07
entry-document refresh adds safety boundaries, not a completed AIO runbook.

The [2026-10-08 app qualification](../testing/install-preparation-2026-10-08.md)
owns the corrected asset contract, exact candidate artifact and renewed runtime
evidence. Six remaining build/test advisory roots are explicitly deferred here
under bounded trusted-input/no-production-tooling conditions. Recheck stable
fixes and review any necessary peer/engine migration before later builds; this
is not operator risk acceptance, production approval or resolution of NC34's
separately recorded native third-party platform failure.

Exact blocking gates:
- [x] CS-PLAN-058 WP1..WP9 exit gates, each passed with its Standard B review
      (program closed; plan in `done/` 2026-09-16).
- [x] Final Full review of the complete owner/member/child journey: `PASS` or
  `PASS-WITH-FIXES` (CS-PLAN-058 §3 WP9) — Full review `PASS-WITH-FIXES`
  2026-09-14 incl. the D-FR-04 browser-gate pair.

The existing-runtime WP9 technical/provider and browser-pair gates passed on
2026-10-05. TECH-RUNTIME-001's current-stable qualification is delivered by
[CS-PLAN-082](../plans/done/cs-plan-082-current-stable-test-baseline-and-data-preservation.md)
(final Full `PASS-WITH-FIXES` 2026-10-06): NC35 primary/NC34 AIO row, all providers,
populated preservation/recovery, canonical/CI promotion and the post-repair browser
pair pass. Still blocking: explicit safe deployment/adoption decisions and the
deployment/field-usage work itself. This is not a deployment or retained-baseline sign-off.
SEC-AUDIT-001 is completed by CS-PLAN-081 (2026-10-05), not a deployment sign-off.
BUG-SEC-001 remains required before later external tester onboarding / public
release, but is explicitly not a blocker for operator-only self-testing or
CS-PLAN-081 completion (user decision 2026-10-04). This limited exception does
not waive deployment safety, technical readiness or retained-baseline adoption.

**Retained-data preparation delivered (2026-10-05):**
[CS-PLAN-081](../plans/done/cs-plan-081-pre-wp9-hardening-and-retained-data-readiness.md)
H6 (final Full `PASS-WITH-FIXES`; Q-081-01 resolved to post-WP9 baseline
adoption) documents the first protected build/adoption boundary, future
data-preserving migration rules, synthetic recovery rehearsal and truthful
whole-Nextcloud rollback impact. This is a partial contribution, not deployment
or field-usage completion. The intended family candidate must pass
CS-PLAN-080's technical matrix/browser gates before real retained data; human
evidence then remains with CS-PLAN-080/CONCEPT-001. No premature
productive-use declaration or circular prerequisite on a completed human trial
is introduced.

**Deployment candidate `0.8.0-alpha.1` (2026-10-17):** qualified by
[CS-PLAN-084](../plans/done/cs-plan-084-family-trial-ux-baseline.md) round 12
(both runtime rows, MariaDB/SQLite provider suites; the package smoke runs on
the committed candidate and is recorded in the plan). Operator decision: this deployment is also the first
retained-baseline adoption, recorded in
[CS-PLAN-080](../plans/active/cs-plan-080-wp9-cross-db-and-family-evidence.md#pre-deployment-candidate-080-alpha1-2026-10-17).
Still open here: the deployment itself (current backup, operator confirmation of
the recovery procedure), the adoption record and field use.

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
