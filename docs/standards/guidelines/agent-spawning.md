# Agent Roster & Spawning — Global Roles, When to Fan Out

> Defines the **global agent roster** and decides when to fan out work. Agents are
> **generic and project-agnostic**: per-project narrowing (layout, framework,
> conventions) comes from this project's [`AGENTS.md`](../../../AGENTS.md) /
> [`project-rules.md`](../ai/rules/project-rules.md) / skills — not from agent
> prompts. The canonical read-only subagent pattern is the
> [`review` skill`](../ai/skills/review/SKILL.md) Full Review.

---

## 1. Models are a setting, not a doc

Model names are **volatile** and user/tool-specific — this document deliberately
names none. Configure model/variant choices in the active tool's user settings,
not in tracked project standards.

Durable routing principle — **escalate, don't start high** (a cheap redo costs
less than one frontier run):

| Work class | Examples | Model class |
| ---------- | -------- | ----------- |
| Read-only / I/O-bound | review, explore, "find where X is" | cheap / fast |
| Routine coding | bug fixes, tests, refactors, triage | mid |
| Synthesis / hard problems | architecture, system design, hard bugs, consolidation | frontier |
| Long autonomous runs | multi-hour unattended sweeps | frontier, long context |

## 2. Use `explore` for quick searches (default for read-only lookups)

Orchestrators **must prefer the `explore` subagent** (pinned cheap/fast) for
read-only lookups instead of grepping inline or spawning a heavier agent:
"where is `houseId` validated?", "who calls `TaskService::complete()`?",
"list all `@NoAdminRequired` controllers".

**Rule of thumb:** "search + report locations" → `explore`. "read + reason +
decide" → orchestrator (or a higher-tier agent). Never spend synthesis budget on
plain retrieval.

## 3. The agent roster

| Agent | Type | Role |
| ----- | ---- | ---- |
| `code` | built-in | Default primary agent; implements features and fixes |
| `ask` | built-in | Read-only Q&A; no edits |
| `plan` | built-in | Synthesizes implementation plans |
| `debug` | built-in | Investigates hard bugs |
| `explore` | built-in | Read-only codebase search ("where is X / who calls Y") |
| `general` | built-in | General-purpose research / multi-step tasks |
| `orchestrator` | built-in | Coordinates spawned work (hidden) |
| `reviewer` | available read-only role | Returns structured findings, never edits |

## 4. When to spawn at all

Spawn only when one of these holds (KISS/DRY: a single coherent change by the
main agent is the default, not a fallback):

- **Parallelism saves real wall-clock time** (independent domains, not fake sub-steps).
- **Isolation is required** (a branch must not touch the main tree until stable).
- **A different role fits** (read-only review/explore while the main session implements).
- **The task is genuinely too large for one context window** (rare — split the plan first).

Do **not** spawn to "use more agents" — each subagent is its own prompt stream and amplifies cost.

## 5. The three mechanisms

| Mechanism | Tool | Visibility | Isolation | Best for |
| --------- | ---- | ---------- | --------- | -------- |
| **Internal subagent** | `task` tool (`subagent_type`) | Hidden (result only) | Same tree | Read-only review, exploration, finding work |
| **Named (custom) agent** | tool/user configuration | Selectable through the active agent runtime | Same tree | Reusable roles with a pinned prompt (e.g. `reviewer`) |
| **Agent Manager worktree** | `agent_manager` tool (mode `worktree`) | Visible card in VS Code | Separate git worktree/branch | Parallel independent implementations, each potentially its own PR |

Subagents and named agents share the working tree — they *help* the main agent.
Worktrees are for *independent* pieces of work.

## 6. Read-only subagent pattern (the `review` reference)

Model new read-only patterns on the [`review` skill`](../ai/skills/review/SKILL.md) Full Review:

1. Main agent sets scope, loads shared context once (prep lookups via `explore`, §2).
2. N subagents run **in parallel**, each with one focus and an explicit read-only
   contract ("return findings, do not edit"), returning a structured deliverable.
3. Main agent consolidates, deduplicates, and owns **all writes**.

Reuse for domain audits, migration impact scans, multi-axis exploration.

## 7. Agent Manager worktrees

Prefer a worktree when the work is **independent and substantial**: parallel
domain features, 2–3 alternative implementations of the same change
(`versions: true`), or tasks needing their own build/run without contaminating
the main tree. **Stabilize contracts first** — freeze shared interfaces, DB
schemas, routes, file layout on the base branch *before* creating worktrees, or
merges become painful. Canonical workflow:
<https://kilo.ai/docs/automate/agent-manager-workflows>.

## 8. Anti-patterns

- **Spawn-by-default** — fans out trivial work and burns quota.
- **Orchestrator grepping for trivial lookups** — delegate to `explore`.
- **Editing read-only subagents** — breaks the orchestrator-owns-state contract.
- **Worktrees without frozen contracts** — merge conflicts dominate the round.
- **Frontier model on read-only work** — wastes cost; route to cheap/mid.

## 9. Related documents

- [`review` skill`](../ai/skills/review/SKILL.md) §4 · [`project-rules.md`](../ai/rules/project-rules.md) · [`review-playbook.md`](../../engineering/review-playbook.md)
