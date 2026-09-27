# Implementation Plans

Tool-agnostic source of truth for planned work. The Markdown files here are canonical; any environment-specific worktree tooling (e.g. Kilo Agent Manager) operates independently of them.

> **Plans are temporary working documents.** They coordinate implementation — they are not living documentation. On completion, durable concepts and decisions must be integrated into the permanent docs (`docs/concepts/`, `docs/adr/`, `docs/engineering/`, `docs/api/`, `docs/standards/`); see [`project-rules.md`](../standards/ai/rules/project-rules.md) §7. The file in `done/` remains only as a historical implementation record.

```
docs/plans/
├── new/       # Drafted, not yet started
├── active/    # Currently being implemented
├── onhold/    # Paused (blocked by a missing dependency)
└── done/      # Completed, kept for reference
```

## Lifecycle

Current active plan: [CS-PLAN-070: Family Rhythm and Reward Clarification](active/cs-plan-070-family-rhythm-and-reward-clarification.md).
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
closed. The next package, WP4 rhythm/goals/ranking, is activated as
[CS-PLAN-075: WP4 - Rhythm, Goals and Ranking](active/cs-plan-075-wp4-rhythm-goals-and-ranking.md)
(activated 2026-09-27 via Standard A `PASS-WITH-FIXES`, Q-075-01/02 Option A;
implementation pending). Implementation history, the
blocked first final-review attempt and the original manifest are recorded in the
[review record](../backlog/archive/CODE_REVIEW_2026-09-25.md); the WP3 records
live in [CODE_REVIEW_2026-09-27](../backlog/archive/CODE_REVIEW_2026-09-27.md).

Active: [CS-PLAN-070: Family Rhythm and Reward Clarification](active/cs-plan-070-family-rhythm-and-reward-clarification.md)
(parent plan; WP0-WP3 delivered, WP4 rhythm/goals/ranking activated as
[CS-PLAN-075](active/cs-plan-075-wp4-rhythm-goals-and-ranking.md)).

Latest completed: [CS-PLAN-074 — WP3: Work Accounting and Replay](done/cs-plan-074-wp3-work-accounting-and-replay.md)
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
[CS-PLAN-058 — Core Workflow (Bottom-Up) Masterplan](done/cs-plan-058-core-workflow-bottom-up-masterplan.md)
(WP0–WP9 delivered; program final Full review `PASS-WITH-FIXES` 2026-09-14
with the D-FR-04 delegated browser-gate pair completed the same night — two
consecutive retry-free passes; the plan moved to `done/` 2026-09-16)
and its child [CS-PLAN-068 — WP9: Deterministic Coverage and Release-Quality
Closure](done/cs-plan-068-wp9-deterministic-coverage-release-closure.md)
(final Standard B `PASS-WITH-FIXES` 2026-09-14; clean-install gate and two
retry-free browser passes verified). RELEASE-002's program prerequisite is
met; its independent security obligations remain.

Successful lifecycle reviews perform the normal forward transitions automatically:

- A passing plan review records its outcome, moves `new/ → active/`, updates `ACTIVE_TASKS.md`, and commits the package.
- A passing final code/full review moves `active/ → done/` only after the whole plan, required tests, acceptance criteria, and durable documentation are complete.
- Passing interim reviews commit their reviewed package while the plan remains in `active/`.
- Only `PASS` and `PASS-WITH-FIXES` are transition-eligible. `NEEDS-FOLLOW-UP` and `BLOCKED` leave the plan in place; the review batches questions with options and a recommendation before it finalizes.

Moves to `onhold/` or the later local `archive/` remain explicit user decisions.

➡️ See [`project-rules.md`](../standards/ai/rules/project-rules.md) §7 for the full lifecycle/move table and the archive policy.

## Creating a Plan

1. Copy [`template.md`](./template.md) into `new/<descriptive-name>.md`.
2. Create/link the owning `backlog.md` items and fill in all required sections.
3. For complex/multi-phase work, run a review; a passing review activates and commits the plan automatically.

See the [`plan-management`](../standards/ai/skills/plan-management/SKILL.md) skill and [`project-rules.md`](../standards/ai/rules/project-rules.md) §7 for the full workflow.
