---
name: plan-management
description: Creating, reviewing, and maintaining implementation plans in docs/plans/
---

# Skill: Plan Management

**Load when:** Creating, reviewing, or maintaining plans in `docs/plans/`.

**Canonical references:**
- [`project-rules.md`](../../rules/project-rules.md) §7 — plan lifecycle & manual moves
- Plan [`template.md`](../../../../plans/template.md)

---

## Folder Structure & Lifecycle

Plans live in `docs/plans/{new,active,onhold,done}/`. Folder moves (`new/ → active/ → done/`, etc.) are **manual** — the AI **must not** auto-move plans between folders unless explicitly instructed. Environment-specific worktree tools (e.g. Kilo Agent Manager) operate independently of these Markdown files.

➡️ See [`project-rules.md`](../../rules/project-rules.md) §7 for the canonical folder tree, the full lifecycle/move table, and the archive policy.

## Plan Template (required sections)

See [`template.md`](../../../../plans/template.md):

```markdown
# [ID]: [Title]
> Status: Plan · Effort: · Priority: P[1-3] · Depends on:
1. Problem Summary
2. Strategy
3. Backend / Frontend Changes
4. Tests
5. File Manifest (new + modified)
6. Implementation Order
7. Risks & Mitigations
8. Acceptance Criteria
```

## Plan Review (after creation)

- **Mandatory** for complex/multi-phase plans; **optional** for trivial fixes.
- Checks: feasibility, risk coverage, acceptance criteria completeness, test-plan adequacy.
- After review, revise in `new/` until ready. The user moves it to `active/`.

## Plans Are Temporary — Knowledge Integration

Plans are **temporary working documents** — they coordinate implementation, they are not living documentation. Before recommending the `active/ → done/` move, integrate everything durable into the permanent docs: concepts/design intent → `docs/concepts/`, architecture decisions → `docs/adr/`, system behavior → `docs/engineering/`, API contracts → `docs/api/`, rules & conventions → `docs/standards/`. The plan file in `done/` is only a historical record; the canonical description of how things work lives in the permanent docs.

➡️ Canonical rule: [`project-rules.md`](../../rules/project-rules.md) §7 ("Plans Are Temporary — Knowledge Integration").

## Completion Gate

A plan/task is **not done** while tests required by its `4. Tests` section, its acceptance criteria, or project-rules §2 are missing, skipped, or failing ("No completion without tests"). Any deferral needs an explicit backlog entry (reason + remaining risk) and user approval before the `active/ → done/` move is recommended.

A plan is also **not fully done** while its durable concepts/decisions exist only in the plan file — missing knowledge integration is treated like missing docs.
