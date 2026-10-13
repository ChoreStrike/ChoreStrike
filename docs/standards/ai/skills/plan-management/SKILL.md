---
name: plan-management
description: Creating, reviewing, and maintaining implementation plans in docs/plans/
---

# Skill: Plan Management

**Load when:** Creating, reviewing, or maintaining plans in `docs/plans/`.

**Canonical references:** [`project-rules.md`](../../rules/project-rules.md) §7 (lifecycle, move table, knowledge integration) and §4 (finding routing) · plan [`template.md`](../../../../plans/template.md) · [`review`](../review/SKILL.md) skill (outcomes, finalization).

---

## Creating a Plan

- Create it in `docs/plans/new/` from [`template.md`](../../../../plans/template.md) (create a missing lifecycle directory when needed). Required sections: Problem Summary · Strategy · Backend/Frontend Changes · Tests · File Manifest · Implementation Order · Risks & Mitigations · Acceptance Criteria · `## 9. Review Findings`.
- Every work item has an owning `backlog.md` ID; the plan supplements, never replaces, the backlog.
- Check `docs/plans/active/` for overlap first; one coherent concern per plan.
- Afterwards propose a Standard A review (`/plan-review`) — mandatory for complex/multi-phase plans, optional for trivial fixes.

## Plan Review (Standard A)

- Checks: feasibility, risk coverage, acceptance-criteria completeness, test-plan adequacy, clear step ownership.
- Resolve all questions in one structured batch (2–3 options, recommendation first) and record the answers in the plan.
- A passing review marks owning backlog entries `In Progress`, moves `new/ → active/`, updates `ACTIVE_TASKS.md`, and commits. A blocked review or open findings leave the plan in `new/`.

## Plan-Owned Findings (`## 9. Review Findings`)

- Prefer fixing in the current round; park only what cannot be fixed safely now.
- One line per entry: severity · file/area · issue · resolution state. Resolve in the current or next round.
- Rescoping an entry to `backlog.md` needs explicit user approval; the new item cites the plan as `Source` and the plan notes the rescoping.
- Out-of-scope findings never enter the plan — they fold into an existing backlog item or go to `backlog.md`.

## Completion Gate (before `active/ → done/`)

A plan is **not done** while any of these holds:

- a test required by its `4. Tests` section, acceptance criteria, or project-rules §2 is missing, newly/unapproved skipped, or failing (a still-required deferred test keeps it in `active/` or `onhold/`);
- a `## 9. Review Findings` entry is unresolved and not user-rescoped;
- durable concepts/decisions/behavior exist only in the plan file (knowledge integration, project-rules §7);
- the owning plan is ambiguous or a review question is unanswered — never guess from filenames when active plans overlap.

On the final passing review, move each completed owning backlog item to the monthly archive immediately; partially completed items stay in `backlog.md` with their remaining scope. `onhold/` and local-`archive/` moves need an explicit user decision.
