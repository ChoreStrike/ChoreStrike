# Project Backlog

The backlog tracks outstanding work for ChoreStrike. Shipped phase plans live in
[`../plans/done/`](../plans/README.md); this directory holds only what is still
**open or live**.

## Structure

```
backlog/
├── README.md                              ← this file
├── ACTIVE_TASKS.md                        ← LIVE coordination (update constantly)
├── bugs.md                                ← bugs + tech debt (incl. review findings)
├── features.md                            ← new features & UX improvements
├── CODE_REVIEW_2026-07-28.md              ← latest project review record (CS-016)
├── CODE_REVIEW_2026-07-24.md              ← project review record (project-wide)
├── phase-8-release-preparation.md         ← active: staged release (alpha → beta → stable)
└── phase-9-code-quality.md                ← open: code-quality / technical-debt items
```

> Completed phase backlogs (phases 1–4, 7, the reward-system redesign, and the
> reward gap-analysis) were archived to `archive/2026-07/backlog/` once the work
> shipped — see that folder's `README.md`. Do not re-add done work here; record it
> in [`../plans/done/`](../plans/README.md) and the `CHANGELOG.md` instead.

## Workflow

### For coding agents
1. **Before starting:** check `ACTIVE_TASKS.md` for conflicts.
2. **When starting:** add the task to `ACTIVE_TASKS.md` as "In Progress".
3. **During work:** keep `ACTIVE_TASKS.md` current.
4. **When complete:** move the entry to "Recently Completed", list modified files.
5. **Track follow-ups:** log bugs/tech-debt in `bugs.md` or the relevant phase file.

### For review agents
See `AGENTS.md` → "Agent Roster & Slash Commands" and
[`../engineering/review-playbook.md`](../engineering/review-playbook.md).
The slash commands `/review-chorestrike`, `/review-chorestrike-full`, and
`/plan-review` load the canonical review process.

## Item format

```markdown
## [P0–P4] Title

**Status**: [Planned | In Progress | Blocked | Done]
**Assignee**: [name or unassigned]
**Estimated Effort**: [S | M | L | XL]
**Source**: [where this came from — e.g. "Review of <module>"]

### Description
### Acceptance Criteria
### Dependencies / Notes
```

## Priority levels

- **P0 (Critical)** — blocking, fix immediately
- **P1 (High)** — important for the current effort
- **P2 (Medium)** — should have
- **P3 (Low)** — nice to have
- **P4 (Future)** — deferred

## Current status

See the [roadmap](../release/roadmap.md) for the project phases. The app is in
the **staged release effort** (Phase 8): `v0.8.x` alpha → `v0.9.x` private beta →
`v1.0.0` stable manual-install.
