# Project Backlog

Tracks outstanding and recently-completed work for ChoreStrike. Shipped phase
plans live in [`../plans/done/`](../plans/README.md); this directory holds only
what is **open or live**.

## Structure

```
backlog/
├── README.md          ← this file (workflow + rules)
├── ACTIVE_TASKS.md    ← LIVE coordination: in-progress + rolling "Recently Completed"
├── backlog.md         ← single source of truth for ALL open items
└── archive/           ← completed history, TRACKED in Git (one file per month)
    ├── README.md      ← archive convention
    ├── 2026-03.md
    ├── 2026-07.md
    └── CODE_REVIEW_YYYY-MM-DD.md   ← review records (kept verbatim)
```

> **Distinction from the repo-root [`../../archive/`](../../archive/):** that
> top-level `archive/` is gitignored local reference for dead code/sensitive
> material. `docs/backlog/archive/` is **tracked in Git** so the completion
> history is publicly visible.

## What goes where

| Content | Location |
|---|---|
| Work **in progress right now** | `ACTIVE_TASKS.md` → *In Progress* |
| A bug / feature / tech-debt item that is **not done** | `backlog.md` |
| A code-review **open** follow-up | `backlog.md` (cite the review record) |
| A **completed** plan / bug / debt item | `archive/YYYY-MM.md` |
| A raw **review record** (evidence) | `archive/CODE_REVIEW_YYYY-MM-DD.md` |
| A **plan draft** (not started) | `../plans/new/` (registered in `ACTIVE_TASKS.md` → *Planned*) |

## Workflow

### For coding agents
1. **Before starting:** check `ACTIVE_TASKS.md` for conflicts.
2. **When starting:** add the task under *In Progress* (move a `backlog.md`
   entry to *In Progress*, or link the plan).
3. **During work:** keep `ACTIVE_TASKS.md` current.
4. **On completion:** add a one-line entry under *Recently Completed*, and
   **move the item out of `backlog.md` into `archive/YYYY-MM.md`** (the month
   it was completed).
5. **Track follow-ups:** log new bugs/debt/findings in `backlog.md`.

### Housekeeping (enforced)
- `ACTIVE_TASKS.md` is **not a history log**. An entry older than **~2 weeks**
  or beyond the **~10-entry rolling cap** must be moved to `archive/YYYY-MM.md`.
- `backlog.md` holds **only open items**. Never leave a "Done" item here — move
  it to the monthly archive at completion.
- `archive/` holds **only completed/rejected items**. Never put open work there.
- One consolidated open list — do **not** re-introduce `bugs.md` / `features.md`
  / `phase-*.md` splits; add a themed section to `backlog.md` instead.

### For review agents
See `AGENTS.md` → "Agent Roster & Slash Commands" and
[`../engineering/review-playbook.md`](../engineering/review-playbook.md). The
slash commands `/review-chorestrike`, `/review-chorestrike-full`, and
`/plan-review` load the canonical review process. **Open** findings →
`backlog.md`; the review record → `archive/`.

## Item format (`backlog.md`)

```markdown
### [P0–P4] ID — Title
**Status**: [Planned | In Progress | Blocked | Backlog | Deferred]
**Effort**: [XS | S | M | L | XL] · **Source**: [where this came from]

Short description.

- [ ] Acceptance criterion 1
- [ ] Acceptance criterion 2
```

## Priority levels

- **P0 (Critical)** — blocking, fix immediately
- **P1 (High)** — important for the current effort
- **P2 (Medium)** — should have
- **P3 (Low)** — nice to have
- **P4 (Future)** — deferred

## Current status

The app is in the **staged release effort**: `v0.8.x` alpha → `v0.9.x` private
beta → `v1.0.0` stable manual-install. See the
[roadmap](../release/roadmap.md) and [`backlog.md`](backlog.md) → *Release / Rollout*.
