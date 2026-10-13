# Agent Roster & Spawning — Roles, Tool Mapping, When to Fan Out

> Tool-agnostic roles and spawning rules. Per-project narrowing comes from
> [`AGENTS.md`](../../../AGENTS.md), [`project-rules.md`](../ai/rules/project-rules.md)
> and the skills — not from agent prompts. The canonical read-only fan-out is the
> [`review` skill](../ai/skills/review/SKILL.md) Full Review.

---

## 1. Models are a setting, not a doc

Model names are volatile and user/tool-specific; configure them in the active
tool's settings. Durable principle — **escalate, don't start high**:

| Work class | Examples | Model class |
| ---------- | -------- | ----------- |
| Read-only / I/O-bound | review tracks, explore, "find where X is" | cheap / fast |
| Routine coding | bug fixes, tests, refactors, triage | mid |
| Synthesis / hard problems | architecture, hard bugs, consolidation | frontier |

## 2. Use `explore` for read-only lookups

"Search + report locations" → `explore` ("where is `houseId` validated?", "who
calls `CompletionService::complete()`?"). "Read + reason + decide" → main agent.
A single known file or symbol needs no subagent — search it directly.

## 3. Roles and tool mapping

| Role | Purpose | Kilo Code | Claude Code |
| ---- | ------- | --------- | ----------- |
| main agent | implements, synthesizes, owns **all writes** | `code` (or `orchestrator`) | main session |
| `explore` | read-only codebase search | `explore` subagent | `Explore` agent |
| `plan` | implementation plan synthesis | `plan` | `Plan` agent / plan mode |
| `general` | multi-step research | `general` | `general-purpose` agent |
| `reviewer` | read-only findings ([contract](../ai/agents/reviewer.md)) | user-configured `reviewer` agent | `.claude/agents/reviewer.md` (generated) |
| spawn mechanism | parallel helper in the same tree | `task` tool | `Agent` tool |
| isolated worktree | independent parallel implementation | Agent Manager (`agent_manager`, mode `worktree`) | `Agent` with `isolation: "worktree"` |
| structured question | batched decisions with a recommendation | `question` tool | `AskUserQuestion` |

Plan Markdown in `docs/plans/` stays the source of truth; worktree tools operate
independently of it.

## 4. When to spawn at all

A single coherent change by the main agent is the default. Spawn only when:

- **parallelism saves real wall-clock time** (independent domains, not fake sub-steps);
- **isolation is required** (work must not touch the main tree until stable);
- **a different role fits** (read-only review/explore while the main agent implements);
- **the task exceeds one context window** (rare — split the plan first).

Every subagent is its own prompt stream and multiplies cost.

## 5. Read-only fan-out pattern

1. Main agent sets scope and loads shared context once (prep lookups via `explore`).
2. N subagents run **in parallel**, one focus each, under an explicit read-only
   contract, returning a structured deliverable.
3. Main agent validates, consolidates, deduplicates, and owns all writes.

Reuse it for domain audits, migration impact scans, multi-axis exploration.

## 6. Worktrees

Use a worktree only for **independent, substantial** work (parallel domain
features, alternative implementations, separate build/run). **Freeze shared
contracts first** — interfaces, DB schema, routes, file layout — on the base
branch, or merges dominate the round.

## 7. Anti-patterns

- Spawn-by-default or a frontier model on read-only work.
- Main agent grepping broadly for trivial lookups instead of `explore` — or spawning for a single known file.
- Read-only subagents that edit (breaks the main-agent-owns-state contract).
- Worktrees without frozen contracts.
