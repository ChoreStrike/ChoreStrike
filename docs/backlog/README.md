# Project Backlog

Tracks outstanding and recently-completed work for ChoreStrike. Retained shipped
plans live in [`../plans/done/`](../plans/done/); this directory holds only what
is **open or live** plus tracked completion/review evidence.

## Structure

```
backlog/
├── README.md          ← this file (workflow + rules)
├── ACTIVE_TASKS.md    ← LIVE coordination: in-progress + rolling "Recently Completed"
├── backlog.md         ← single source of truth for ALL open items not owned by an active plan
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
| A bug / feature / tech-debt item that is **not done** and **not owned by an active plan** | `backlog.md` |
| A finding **inside an owning plan's scope** (implementation or review) | the plan's `## Review Findings` checklist — **not** `backlog.md` (scope-first routing, `project-rules.md` §4) |
| A code-review **open** follow-up **outside** any plan's scope | `backlog.md` (cite the review record) |
| A finding that **extends an existing open item** | fold into that item (`Folded-in` field) — never a parallel entry |
| A **completed** plan / bug / debt item | `archive/YYYY-MM.md` |
| A raw **review record** (evidence) | `archive/CODE_REVIEW_YYYY-MM-DD.md` |
| A **plan draft** (not started) | `../plans/new/` (and `backlog.md` when it represents an open item) |

## Workflow

### For coding agents
1. **Before starting:** check `ACTIVE_TASKS.md` for conflicts.
2. **When starting:** add a link under *In Progress*. Keep the open source item
   in `backlog.md`; `ACTIVE_TASKS.md` is coordination, not ownership.
3. **During work:** keep `ACTIVE_TASKS.md` current.
4. **On completion:** add a one-line entry under *Recently Completed*, and
   **move the item out of `backlog.md` into `archive/YYYY-MM.md`** (the month
   it was completed).
5. **Track follow-ups (scope-first):** route new bugs/debt/findings per
   `project-rules.md` §4 "Finding Routing" — fix now → owning plan's
   `## Review Findings` → fold into an existing item → only out-of-scope
   findings as new `backlog.md` entries.

### Housekeeping (enforced)
- `ACTIVE_TASKS.md` is **not a history log**. An entry older than **~2 weeks**
  or beyond the **~10-entry rolling cap** must be moved to `archive/YYYY-MM.md`.
- `backlog.md` holds **only open items**. Never leave a "Done" item here — move
  it to the monthly archive at completion.
- `archive/` holds **only completed/rejected items**. Never put open work there.
- One consolidated open list — do **not** re-introduce `bugs.md` / `features.md`
  / `phase-*.md` splits; add a themed section to `backlog.md` instead.

### For review agents
See `AGENTS.md` → "Review Commands & Subagents" and
[`../engineering/review-playbook.md`](../engineering/review-playbook.md). The
slash commands `/review-chorestrike`, `/review-chorestrike-full`, and
`/plan-review` load the canonical review process, batch questions, perform
eligible plan transitions, and make one scoped commit. **Open** findings are
routed scope-first (plan-owned `## Review Findings` / fold-in / `backlog.md`);
the review record → `archive/`.

## Item format (`backlog.md`)

Since CS-PLAN-059 WP0 (2026-09-02), `backlog.md` is organized into the five
dependency-oriented sections fixed by
[`CS-PLAN-058 §2.2`](../plans/active/cs-plan-058-core-workflow-bottom-up-masterplan.md):

| Section | Contents |
|---|---|
| **Core Workflow Program** | CR-WF-01..08 umbrellas and their explicitly owned findings (`Owning: CS-PLAN-058 WPx`) |
| **Core Hardening** | Standalone correctness, API, persistence, i18n, and security remainders — each states which work package it blocks |
| **Deferred Add-ons** | Optional UX/features outside the core critical path |
| **Infrastructure and Documentation** | Backlog hygiene, lint/dependency, docs truth, operational security |
| **Release** | RELEASE-002/003 with their exact blocking work packages and gates |

Every open entry carries the standardized nine-field metadata block (`—` is the
explicit empty value):

```markdown
### [P1] CR-WF-01 — Enforce approved workflow authorization boundaries
**Status**: Backlog · **Effort**: L · **Layer**: backend+frontend · **Journey stage**: authorization
**Owning**: CS-PLAN-058 WP2 · **Depends on**: — · **Blocks**: CR-WF-03..08 · **Folded-in**: —
**Source**: Full Review of the core task workflow (2026-09-02)

Short description.

- [ ] Acceptance criterion 1
- [ ] Acceptance criterion 2
```

Field rules:

- `Owning` is a work package (`CS-PLAN-058 WPx`, combinable with `+`), a plan ID
  (`CS-PLAN-NNN`), or `unassigned`. Core Hardening / Deferred Add-ons entries
  that block nothing stay `unassigned` with a `Blocks: —` line.
- `Depends on` / `Blocks` / `Folded-in` reference open backlog IDs, work
  packages, or plan IDs. `Folded-in` may also point at an archived ID's monthly
  record. ID ranges (`CR-WF-03..08`, `WP1..WP9`) are allowed.
- Historical provenance stays in `Source` and the review records — never as
  duplicate open bodies.

`npm run backlog:check` (scripts/check-backlog.js) machine-verifies:
open-only entries, unique IDs, the nine metadata fields, resolvable dependency
references, and relative tracked links in `docs/backlog/*.md` +
`docs/plans/**/*.md`. Run it after every backlog edit.

## Priority levels

- **P0 (Critical)** — blocking, fix immediately
- **P1 (High)** — important for the current effort
- **P2 (Medium)** — should have
- **P3 (Low)** — nice to have
- **P4 (Future)** — deferred

## Current status

The app is in the **staged release effort**: `v0.8.x` alpha → `v0.9.x` private
beta → `v1.0.0` stable manual-install. See the
[roadmap](../release/roadmap.md) and [`backlog.md`](backlog.md) → *Release*.
