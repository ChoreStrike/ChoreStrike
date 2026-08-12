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

## Lifecycle (manual moves)

Plan transitions between folders (`new/ → active/ → done/`, etc.) are **manual** — the AI must not auto-move plans unless explicitly instructed.

➡️ See [`project-rules.md`](../standards/ai/rules/project-rules.md) §7 for the full lifecycle/move table and the archive policy.

## Creating a Plan

1. Copy [`template.md`](./template.md) into `new/<descriptive-name>.md`.
2. Fill in the required sections.
3. For complex/multi-phase work, the AI proposes a review before you move it to `active/`.

See the [`plan-management`](../standards/ai/skills/plan-management/SKILL.md) skill and [`project-rules.md`](../standards/ai/rules/project-rules.md) §7 for the full workflow.
