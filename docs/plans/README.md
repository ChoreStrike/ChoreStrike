# Implementation Plans

Tool-agnostic source of truth for planned work. The Markdown files here are canonical; any environment-specific worktree tooling (e.g. Kilo Agent Manager) operates independently of them.

> **Plans are temporary working documents.** They coordinate implementation — they are not living documentation. On completion, durable concepts and decisions must be integrated into the permanent docs (`docs/concepts/`, `docs/adr/`, `docs/engineering/`, `docs/api/`, `docs/standards/`); see [`project-rules.md`](../standards/ai/rules/project-rules.md) §7. The file in `done/` remains only as a historical implementation record.

```
docs/plans/
├── new/       # Drafted, not yet started
├── active/    # Currently being implemented
├── onhold/    # Paused (blocked by a missing dependency)
└── done/      # Completed; retired in batches to the local archive/ (see below)
```

## Lifecycle

Delivered: [CS-PLAN-083: Release Qualification Gates](done/cs-plan-083-release-qualification-gates.md)
(final Full `PASS-WITH-FIXES`, 2026-10-10). RELEASE-002's bounded WP1-WP3 slice
owns alias-free canonical provisioning, reproducible clean-candidate packaging
and same-archive NC35/NC34 smoke. All findings, required gates and durable docs
are complete at candidate `853f661`; deployment/adoption and WP9 human evidence remain separate.

Delivered: [CS-PLAN-082: Current-Stable Test Baseline and Data Preservation](done/cs-plan-082-current-stable-test-baseline-and-data-preservation.md)
(final Full `PASS-WITH-FIXES` 2026-10-06; NC35/PHP8.4 primary plus named NC34 AIO
row, all-provider backend and preservation/recovery proofs, canonical/CI promotion,
post-repair browser pair and durable guidance complete; TECH-RUNTIME-001 archived).
[CS-PLAN-080: WP9 - Cross-Database Verification and Family Evidence](active/cs-plan-080-wp9-cross-db-and-family-evidence.md)
has passed its NC31 technical gates and receives the qualified target-runtime
evidence; authorized human evidence and its final review remain open. Since
2026-10-18 it is the single open plan of the CS-PLAN-070 program.
[CS-PLAN-081: Pre-WP9 Hardening and Retained-Data Readiness](done/cs-plan-081-pre-wp9-hardening-and-retained-data-readiness.md)
is delivered (final Full `PASS-WITH-FIXES` 2026-10-05): H1-H6 complete, H6-05
explicit evidence-bound disposition accepted; H7/H-T11 user-rescoped for
operator-only self-testing, not passed. Seven standalone owners are archived;
later email reporting (BUG-SEC-001) and possible GitHub move (TECH-REPO-001) stay
in the [backlog](../backlog/backlog.md). The future retention boundary is approved
but not adopted; no WP9 provider/pair/human gate or production deployment is
claimed by this preparation delivery.

[CS-PLAN-084: Family-Trial UX Baseline](done/cs-plan-084-family-trial-ux-baseline.md)
is delivered (final Full `PASS-WITH-FIXES` 2026-10-17, deployment candidate
`0.8.0-alpha.1`); the operator's real-device run moved to CS-PLAN-080 with the
family evidence it precedes.

Active: [CS-PLAN-086: Household Arcade](active/cs-plan-086-household-arcade-and-chore-unlocks.md)
(activated 2026-10-17, Standard A `PASS-WITH-FIXES`) is an optional add-on developed on branch
`feat/arcade-minigames`; it merges only after the CS-PLAN-084 final review and the close of
the CS-PLAN-080 family evidence window.

Current active plan of the family-rhythm program: [CS-PLAN-080: WP9 - Cross-Database Verification and Family Evidence](active/cs-plan-080-wp9-cross-db-and-family-evidence.md)
(activated 2026-10-03, Standard A `PASS`). Its parent
[CS-PLAN-070: Family Rhythm and Reward Clarification](done/cs-plan-070-family-rhythm-and-reward-clarification.md)
closed on 2026-10-18 (final Standard B `PASS-WITH-FIXES`) and consolidated its G3
family evidence and the CONCEPT-001 closure into CS-PLAN-080. Independent
[CS-PLAN-083 release qualification gates](done/cs-plan-083-release-qualification-gates.md) are complete.
Independent runtime qualification
[CS-PLAN-082](done/cs-plan-082-current-stable-test-baseline-and-data-preservation.md)
and CS-PLAN-081 preparation are complete in `done/`; neither closes CS-PLAN-080.
Core product direction was approved through 24 explicit answers on 2026-09-17.
The same day, 47 additional C1-C5 answers (6/11/9/8/13) resolved the presented
product questions. The technical draft added on 2026-09-18 specifies persistence,
APIs, transactions/races, exact files/tests and ten work packages (48-76 engineering
days before contingency, plus a two-week family observation gate). Q-070-01/02 were
resolved (tombstoned minimum-history deletion retention; account erasure) and
Standard A passed with `PASS-WITH-FIXES` on 2026-09-18. Its WP0 child
[CS-PLAN-071: Write/Identity Foundation](done/cs-plan-071-wp0-write-identity-foundation.md)
(shared schema/lock/identity/receipt foundation, Q-070-01 tombstone deletion,
Q-070-02 account-erasure plumbing) is **delivered** — final Standard B
`PASS-WITH-FIXES` 2026-09-19, plan in `done/`. Its WP1 child
[CS-PLAN-072: WP1 - Occurrence Timeline](done/cs-plan-072-wp1-occurrence-timeline.md)
(Standard A `PASS` 2026-09-20 with the Q-072-01 Option A agreement-writer
slice moved into WP1; immutable rule revisions, stable occurrence keys,
saved windows/time basis, deterministic target selection, write-free
projections, `TimelineJob`, completion receipts via `CommandService`,
occurrence-scoped checklist/undo, `agreements` persistence with the C2
deadline/authorization/capacity checks, minimal client transport, ADR-013)
is **delivered** — final Standard B `PASS-WITH-FIXES` 2026-09-23, plan in
`done/`. The next behavior package,
[CS-PLAN-073: WP2 - Agreements, Capacity and Pauses](done/cs-plan-073-wp2-agreements-capacity-pauses.md)
(activated 2026-09-23 via Standard A `PASS-WITH-FIXES`; Q-073-01/02/03 resolved
with the recommended options), is **delivered** — final Standard B
`PASS-WITH-FIXES` 2026-09-25 after the R0-R8 fix round resolved RF-073-WP2-23..28
with maintained regressions; plan in `done/`. Its WP3 child
[CS-PLAN-074: WP3 - Work Accounting and Replay](done/cs-plan-074-wp3-work-accounting-and-replay.md)
(Standard A `PASS` 2026-09-25 with the Q-074-01 Option A correction-closure
slice and Q-074-02 Option A false-attribution authority; actual shares, exact
minor-unit wallet ledger, targeted undo/attribution, room journal/replay,
recognition correction closure, no-Coin arcade, ADR-014)
is **delivered** — final Standard B re-review `PASS-WITH-FIXES` 2026-09-27
after the fix round resolved RF-074-WP3-01..10; plan in `done/`, CR-SVC-63
closed. The next package, WP4 rhythm/goals/ranking,
[CS-PLAN-075: WP4 - Rhythm, Goals and Ranking](done/cs-plan-075-wp4-rhythm-goals-and-ranking.md)
(activated 2026-09-27 via Standard A `PASS-WITH-FIXES`, Q-075-01/02 Option A),
is **delivered** — final Standard B `PASS-WITH-FIXES` 2026-09-29 after the
in-review fix round resolved RF-075-01..05 (owner ranking toggle, erasure
purge + ranking store pins, predicate dedup, stale skill note); plan in
`done/`, review record in
[CODE_REVIEW_2026-09-29](../backlog/archive/CODE_REVIEW_2026-09-29.md).
Implementation history, the
blocked first final-review attempt and the original manifest are recorded in the
[review record](../backlog/archive/CODE_REVIEW_2026-09-25.md); the WP3 records
live in [CODE_REVIEW_2026-09-27](../backlog/archive/CODE_REVIEW_2026-09-27.md).

Delivered: [CS-PLAN-070: Family Rhythm and Reward Clarification](done/cs-plan-070-family-rhythm-and-reward-clarification.md)
(parent plan; WP0-WP8 delivered; closed 2026-10-18 by final Standard B
`PASS-WITH-FIXES`, its remaining G3 consolidated into CS-PLAN-080). Its WP9 child,
[CS-PLAN-080: WP9 - Cross-Database Verification and Family Evidence](active/cs-plan-080-wp9-cross-db-and-family-evidence.md),
is **active** (drafted 2026-10-02, activated 2026-10-03 via Standard A `PASS`,
zero findings): isolated MariaDB/PostgreSQL/SQLite verification and two
consecutive combined browser gates passed on NC31 on 2026-10-05. Voluntary
adult/child comprehension plus 14-day everyday-use evidence remains uncollected;
CS-PLAN-082 owns qualification for the newer deployment runtime.
WP8 reward UI and onboarding,
[CS-PLAN-079: WP8 - Reward UI and Onboarding](done/cs-plan-079-wp8-reward-ui-and-onboarding.md),
passed final Standard B `PASS-WITH-FIXES` on 2026-10-02 (two independent
catalog gates, persistent own/owner request surfaces with all C4
attestation forms, exact `parseCoinMinor` prices, uncertain-command
same-key replay, 409 conflict-safe drafts, shared-device reset, essential
onboarding/help; RF-079-01..04 resolved in-round; FEAT-UX-026 closed).
The parent's remaining WP9 family evidence gate is owned by the active CS-PLAN-080.

Latest completed: [CS-PLAN-079: WP8 - Reward UI and Onboarding](done/cs-plan-079-wp8-reward-ui-and-onboarding.md)
(final Standard B `PASS-WITH-FIXES` 2026-10-02): the reward Pinia client
(`src/store/rewards.js`), `/rewards` + `/reward-requests` surfaces, owner
catalog editor, strict decimal price parser, essential contextual help,
EN/DE/POT (1269 keys) and the T11 browser slice
(`family-rewards.spec.ts`, 9 required scenarios, gate-registered).
Review record: [CODE_REVIEW_2026-10-02](../backlog/archive/CODE_REVIEW_2026-10-02.md).
Preceded by [CS-PLAN-078: WP7 - Catalog and Request Backend](done/cs-plan-078-wp7-catalog-and-request-backend.md)
(final Standard B `PASS-WITH-FIXES` 2026-10-01): Version1033 schema +
role-revision provenance, RewardService/RewardRequestService with the WP7
under-lock receipt guard, exact reserve/approve/settle/refund money,
role-rebase/departure/erasure/digest integration, `docs/api/rewards.md` and
the browser-contract E2E slice. Review record:
[CODE_REVIEW_2026-10-01](../backlog/archive/CODE_REVIEW_2026-10-01.md).
Preceded by [CS-PLAN-077: WP6 - Calm Core UI](done/cs-plan-077-wp6-calm-core-ui.md)
(final Standard B `PASS-WITH-FIXES` 2026-10-01): all-age My Day landing/content,
preference-gated calm feedback with the reduced-motion hard floor, full
`NotificationPreferences.vue` + optional-area consumers, pause UI on the WP2
contract, occurrence agreement entry points, shared-device store reset, no-Coin
UI closure and the essential help slice. Review record:
[CODE_REVIEW_2026-10-01](../backlog/archive/CODE_REVIEW_2026-10-01.md).
Preceded by [CS-PLAN-076: WP5 - Preferences and Digest Delivery](done/cs-plan-076-wp5-preferences-and-digest-delivery.md)
(final Standard B `PASS-WITH-FIXES` 2026-09-30): self-only settings, bounded
at-most-once combined digest, transactional source queue, full regression/
concurrency evidence and ADR-016. Review record:
[CODE_REVIEW_2026-09-30](../backlog/archive/CODE_REVIEW_2026-09-30.md).
Preceded by
[CS-PLAN-075 — WP4: Rhythm, Goals and Ranking](done/cs-plan-075-wp4-rhythm-goals-and-ranking.md)
(final Standard B `PASS-WITH-FIXES` 2026-09-29 after the in-review fix round
resolved RF-075-01..05; ADR-015 recorded, CR-053-11 freeze/tiebreaker
remainders resolved by removal-with-replacement). Covers the rhythm/goal read
contract, consent-filtered exact-ratio ranking on persisted consent
(Version1032 + generation watermark), the single-series recognition cutover
and the retired-mechanic removal. Review record:
[CODE_REVIEW_2026-09-29](../backlog/archive/CODE_REVIEW_2026-09-29.md).
Preceded by
[CS-PLAN-074 — WP3: Work Accounting and Replay](done/cs-plan-074-wp3-work-accounting-and-replay.md)
(final Standard B re-review `PASS-WITH-FIXES` 2026-09-27 after the
NEEDS-FOLLOW-UP fix round resolved RF-074-WP3-01..10; ADR-014 recorded,
CR-SVC-63 closed). Covers actual work shares, the exact minor-unit wallet
ledger, targeted undo/attribution, room journal/replay, the Q-074-01A
recognition correction closure and the client/no-Coin arcade cutover.
Review record: [CODE_REVIEW_2026-09-27](../backlog/archive/CODE_REVIEW_2026-09-27.md). Preceded by
[CS-PLAN-073 — WP2: Agreements, Capacity and Pauses](done/cs-plan-073-wp2-agreements-capacity-pauses.md)
(final Standard B `PASS-WITH-FIXES` 2026-09-25), preceded by
[CS-PLAN-072 — WP1: Occurrence Timeline](done/cs-plan-072-wp1-occurrence-timeline.md)
(final Standard B `PASS-WITH-FIXES` 2026-09-23),
[CS-PLAN-071 — WP0: Write/Identity Foundation](done/cs-plan-071-wp0-write-identity-foundation.md)
(final Standard B `PASS-WITH-FIXES` 2026-09-19) and
[CS-PLAN-058 — Core Workflow (Bottom-Up) Masterplan](https://github.com/ChoreStrike/ChoreStrike/blob/c03e940d071251ed62cedbe9ef4a7a2640d0f294/docs/plans/done/cs-plan-058-core-workflow-bottom-up-masterplan.md)
(WP0–WP9 delivered; program final Full review `PASS-WITH-FIXES` 2026-09-14
with the D-FR-04 delegated browser-gate pair completed the same night — two
consecutive retry-free passes; the plan moved to `done/` 2026-09-16)
and its child [CS-PLAN-068 — WP9: Deterministic Coverage and Release-Quality
Closure](https://github.com/ChoreStrike/ChoreStrike/blob/c03e940d071251ed62cedbe9ef4a7a2640d0f294/docs/plans/done/cs-plan-068-wp9-deterministic-coverage-release-closure.md)
(final Standard B `PASS-WITH-FIXES` 2026-09-14; clean-install gate and two
retry-free browser passes verified). RELEASE-002's program prerequisite is
met; its independent security obligations remain.

Successful lifecycle reviews perform the normal forward transitions automatically:

- A passing plan review records its outcome, moves `new/ → active/`, updates `ACTIVE_TASKS.md`, and commits the package.
- A passing final code/full review moves `active/ → done/` only after the whole plan, required tests, acceptance criteria, and durable documentation are complete.
- Passing interim reviews commit their reviewed package while the plan remains in `active/`.
- Only `PASS` and `PASS-WITH-FIXES` are transition-eligible. `NEEDS-FOLLOW-UP` and `BLOCKED` leave the plan in place; the review batches questions with options and a recommendation before it finalizes.

Moves to `onhold/` or the later local `archive/` remain explicit user decisions.
Retirement from `done/` runs in batches, normally at each minor release for plans
older than about three months whose program is closed. Links to a retired plan
become GitHub permalinks; see
[Retiring Done Plans](../standards/ai/skills/plan-management/SKILL.md#retiring-done-plans).
Retired so far: CS-PLAN-001..055 (2026-08) and CS-PLAN-056..069
([2026-10-18](../backlog/archive/2026-10.md#retired-plans-cs-plan-056069-10-18)).

➡️ See [`project-rules.md`](../standards/ai/rules/project-rules.md) §7 for the full lifecycle/move table and the archive policy.

## Creating a Plan

1. Copy [`template.md`](./template.md) into `new/<descriptive-name>.md`.
2. Create/link the owning `backlog.md` items and fill in all required sections.
3. For complex/multi-phase work, run a review; a passing review activates and commits the plan automatically.

See the [`plan-management`](../standards/ai/skills/plan-management/SKILL.md) skill and [`project-rules.md`](../standards/ai/rules/project-rules.md) §7 for the full workflow.
