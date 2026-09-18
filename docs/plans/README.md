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
Standard A passed with `PASS-WITH-FIXES` on 2026-09-18; no runtime behavior is
implemented yet.

Latest completed: [CS-PLAN-058 — Core Workflow (Bottom-Up) Masterplan](done/cs-plan-058-core-workflow-bottom-up-masterplan.md)
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
