---
name: plan-management
description: Creating, reviewing, and maintaining implementation plans in docs/plans/
---

# Skill: Plan Management

**Load when:** Creating, reviewing, or maintaining plans in `docs/plans/`.

**Canonical references:**
- [`project-rules.md`](../../rules/project-rules.md) §7 — plan lifecycle and transition gates
- Plan [`template.md`](../../../../plans/template.md)

---

## Folder Structure & Lifecycle

Plans live in `docs/plans/{new,active,onhold,done}/`. Create a missing lifecycle directory when needed. Environment-specific worktree tools (e.g. Kilo Agent Manager) operate independently of these Markdown files.

The review orchestrator owns normal forward transitions:

- Standard A `PASS` / `PASS-WITH-FIXES`: update the status and review outcome, move `new/ → active/`, update `ACTIVE_TASKS.md`, and commit.
- Final Standard B / Full `PASS` / `PASS-WITH-FIXES`: after every completion gate passes, update the outcome, move `active/ → done/`, update tracking, and commit.
- Interim passing review: commit the reviewed package and keep the plan in `active/`.
- Standard A findings leave the plan in `new/`. Code/full-review findings commit only review evidence, backlog updates, and verified safe fixes; keep the plan active.

Moves to `onhold/` or the later local `archive/` require an explicit user decision.

➡️ See [`project-rules.md`](../../rules/project-rules.md) §7 for the canonical folder tree, the full lifecycle/move table, and the archive policy.

## Plan Template (required sections)

See [`template.md`](../../../../plans/template.md):

```markdown
# [ID]: [Title]
> Status: Plan · Effort: · Priority: P[1-3] · Depends on:
> Related backlog items: [IDs]
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
- Validate that every work item has an owning `backlog.md` ID. A passing activation marks those entries `In Progress`; the plan supplements rather than replaces the backlog.
- Resolve all review questions in one structured batch. Offer 2–3 solution options, put the recommendation first, and record the answers in the plan.
- A passing review activates and commits the plan automatically. A blocked review leaves it in `new/`.

## Plans Are Temporary — Knowledge Integration

Plans are **temporary working documents** — they coordinate implementation, they are not living documentation. Before the final review performs the `active/ → done/` move, integrate everything durable into the permanent docs: concepts/design intent → `docs/concepts/`, architecture decisions → `docs/adr/`, system behavior → `docs/engineering/`, API contracts → `docs/api/`, rules & conventions → `docs/standards/`. The plan file in `done/` is only a historical record; the canonical description of how things work lives in the permanent docs.

➡️ Canonical rule: [`project-rules.md`](../../rules/project-rules.md) §7 ("Plans Are Temporary — Knowledge Integration").

## Completion Gate

A plan/task is **not done** while tests required by its `4. Tests` section, its acceptance criteria, or project-rules §2 are missing, newly/unapproved skipped, or failing ("No completion without tests"). A still-required deferred test keeps the plan in `active/` or `onhold/`; user-approved rescoping must update the plan, acceptance criteria, and backlog before a later final review.

A plan is also **not fully done** while its durable concepts/decisions exist only in the plan file — missing knowledge integration is treated like missing docs.

An ambiguous owning plan or any unanswered review question blocks both transition and finalization. Never guess from filenames alone when multiple active plans overlap.

On a final passing review, move each completed owning backlog item to the monthly archive immediately. Leave partially completed items in `backlog.md` with their remaining scope.
