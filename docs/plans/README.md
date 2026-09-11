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

Current WP7 plan: [CS-PLAN-066 - Canonical Client State and Family-Friendly Core UX](active/cs-plan-066-wp7-canonical-client-state-family-ux.md),
child of [CS-PLAN-058](active/cs-plan-058-core-workflow-bottom-up-masterplan.md).
Standard A `PASS-WITH-FIXES` 2026-09-11 (D1-D7 ratified); implementation has not started.

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
