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
