# Agent Roster & Spawning — Global Roles, When to Fan Out

> Defines the **global agent roster** and decides when to fan out work and which
> mechanism fits. Agents are **generic and project-agnostic**: per-project
> narrowing (layout, framework, conventions) comes from this project's
> [`AGENTS.md`](../../../AGENTS.md) / [`project-rules.md`](../ai/rules/project-rules.md) /
> skills, not from agent prompts — so the same roster works across a Nextcloud
> app, a Roblox/TypeScript repo, or any other project. The canonical read-only
> subagent pattern is the [`review` skill](../ai/skills/review/SKILL.md) Full Review
> (4 parallel `reviewer` subagents) — model new patterns on it.
>
> Related: [`review` skill`](../ai/skills/review/SKILL.md) §4,
> [`project-rules.md`](../ai/rules/project-rules.md).

---

## 1. Models are a setting, not a doc

Specific model names are **volatile** (they change every few days) and **global**
(shared across all projects). This document therefore deliberately names **no**
models. Assign a model per agent in the global Kilo config:

- **Built-in agents** (`plan`, `code`, `ask`, `debug`, `explore`, `general`,
  `orchestrator`): override `model` + `variant` under `agent.<name>` in
  `~/.config/kilo/kilo.jsonc`.
- **Custom agents** (`reviewer`): defined inline under `agent.<name>` in
  `~/.config/kilo/kilo.jsonc` (managed via the Kilo Code "Agent Behaviour" UI);
  a `~/.config/kilo/agent/<name>.md` file is the CLI alternative.
- **Switch at runtime** without editing files: `/agents` then `/models` in the
  TUI, or pass `model` / `variant` per Agent Manager task.

Current project intent (model classes only — names live in the global config):

| Agent | Role | Model class (this project) |
| ----- | ---- | -------------------------- |
| `explore` | read-only "where is X / who calls Y" searches | cheap / fast |
| `reviewer` | read-only sub-reviews of non-complex slices | mid |
| `code` / `general` | implementation, multi-step work | match the task |
| `orchestrator` (main) | synthesis, consolidation, all writes | frontier |

### Model-class guidance (the durable principle)

Models come and go, but the routing principle is stable:

| Work class | Examples | Model class |
| ---------- | -------- | ----------- |
| Read-only / I/O-bound | review, explore, "find where X is" | cheap / fast |
| Routine coding | bug fixes, tests, refactors, lint/test triage | mid |
| Synthesis / hard problems | architecture, system design, hard bugs, cross-domain planning | frontier |
| Long autonomous runs | multi-hour unattended sweeps | frontier, long context |

**Escalate, don't start high** — begin cheap, move up only when output quality or
context demands it. A cheap redo still costs less than one frontier run.

---

## 2. Use `explore` for quick searches (default for read-only lookups)

Orchestrators (the main agent and any coordinating subagent) **must prefer the
`explore` subagent** for cheap, read-only lookups instead of doing the search
themselves or spawning a heavier agent:

- "Where is `houseId` validated?" / "who calls `TaskService::complete()`?"
- "List all `@NoAdminRequired` controllers" / "find every `t('…')` in `src/`".
- "Which files reference `roomTask`?" / grep/glob-driven inventories.

`explore` is pinned to a **cheap/fast** model (see §1) and shares the working
tree, so it is the lowest-cost way to gather context. Reach for `general` or a
frontier model only when the lookup requires synthesis (judging intent,
reconciling contradictions, proposing an approach) rather than retrieval.

> **Rule of thumb:** if the answer is "search + report locations", use `explore`.
> If the answer is "read + reason + decide", keep it in the orchestrator (or use a
> higher-tier agent). Never spend synthesis budget on plain retrieval.

---

## 3. The agent roster

Roles only — models are assigned per §1.

| Agent | Type | Role |
| ----- | ---- | ---- |
| `code` | built-in | Default primary agent; implements features and fixes |
| `ask` | built-in | Read-only Q&A; no edits |
| `plan` | built-in | Synthesizes implementation plans |
| `debug` | built-in | Investigates hard bugs |
| `explore` | built-in | Read-only codebase search ("where is X / who calls Y") |
| `general` | built-in | General-purpose research / multi-step tasks |
| `orchestrator` | built-in | Coordinates spawned work (hidden) |
| `reviewer` | custom (inline `agent.reviewer` in `~/.config/kilo/kilo.jsonc`) | Read-only reviewer; returns structured findings, never edits |

Custom agents stay generic: they rely on this project's `AGENTS.md` and loaded
skills for project-specific behavior.

---

## 4. When to spawn at all

Spawn only when one of these holds. KISS/DRY apply: a single coherent change by
the main agent is the default, not a fallback.

- **Parallelism saves real wall-clock time** (independent domains, not fake
  sub-steps of one task).
- **Isolation is required** (a branch must not touch the main tree until stable).
- **A different role fits the work** (read-only review/explore while the main
  session implements).
- **The task is genuinely too large for one context window** (rare — prefer
  splitting the plan first).

Do **not** spawn to "use more agents" — each subagent is its own prompt stream
and amplifies cost.

---

## 5. The three mechanisms

| Mechanism | Tool | Visibility | Isolation | Best for |
| --------- | ---- | ---------- | --------- | -------- |
| **Internal subagent** | `task` tool (`subagent_type`) | Hidden (result only) | Same tree | Read-only review, exploration, finding work |
| **Named (custom) agent** | inline `agent.<name>` in `~/.config/kilo/kilo.jsonc` (Agent Behaviour UI), or `~/.config/kilo/agent/*.md` (`mode: subagent` or `all`); project-local `.kilo/agent/*.md` | Selectable via Task tool / `@` menu | Same tree | Reusable roles with a pinned system prompt (e.g. `reviewer`) |
| **Agent Manager worktree** | `agent_manager` tool (mode `worktree`) | Visible card in VS Code | Separate git worktree/branch | Parallel independent implementations that need their own checkout |

Internal subagents and named agents share the working tree — they *help* the main
agent. Worktrees are for *independent* pieces of work that could each become a PR.

---

## 6. Read-only subagent pattern (the `review` reference)

The [`review` skill`](../ai/skills/review/SKILL.md) Full Review is the template
for read-only parallel work:

1. Main agent sets scope and loads shared context once (use `explore` for the
   grep/glob inventories — §2).
2. N subagents (here: 4) run **in parallel**, each with one focus and an explicit
   read-only contract ("return findings, do not edit").
3. Each returns a structured deliverable (a JSON findings array).
4. Main agent consolidates, deduplicates, and owns all writes.

Reuse this shape for: domain audits, migration impact scans, multi-axis
exploration. Never let a read-only subagent edit — the orchestrator owns state.

---

## 7. When to use Agent Manager worktrees instead

Prefer a worktree when the work is **independent and substantial**:

- Refactoring one domain (e.g. the gamification engine) while another domain's
  feature lands in parallel.
- Trying 2–3 alternative implementations of the same change (`versions: true`).
- A task that needs its own dependencies/build/run without contaminating the main
  tree (each worktree is a separate checkout).

**Stabilize contracts first.** Worktrees do not auto-orchestrate dependencies —
freeze shared interfaces, DB schemas, routes (`appinfo/routes.php`), file layout,
or test shape on the base branch *before* creating worktrees, or merges become
painful. See the Kilo Agent Manager Workflows docs
(<https://kilo.ai/docs/automate/agent-manager-workflows>) for the canonical
worktree workflow.

---

## 8. Routing per role

| Role | Mechanism | Model class | Why |
| ---- | --------- | ----------- | --- |
| "Find where X is" / quick search | `explore` subagent | cheap / fast | Retrieval, not synthesis (§2) |
| Read-only review / audit | Named `reviewer` subagent | cheap/mid | I/O-bound find-and-flag |
| Test authoring | Named agent or main | mid | Pattern-following |
| Implementation (writes code) | Main agent, or worktree if independent | match task | Inherits complexity |
| Architecture / consolidation | Main agent only | frontier | Only the orchestrator pays for synthesis |
| Long autonomous run | Agent Manager worktree (local) | frontier, long context | Needs isolation |

---

## 9. Anti-patterns

- **Spawn-by-default** — fans out trivial work and burns quota. Start single-agent.
- **Orchestrator doing its own `grep` for trivial lookups** — delegate to `explore`
  (§2) so the main context stays clean and on a cheap model.
- **Editing read-only subagents** — breaks the orchestrator-owns-state contract.
  If a subagent must edit, make it a named agent with explicit write scope in its
  `permission` block.
- **Worktrees without frozen contracts** — merge conflicts dominate the round.
- **Stacked workarounds across worktrees** — each must track its own follow-up in
  [`docs/plans/new/`](../../plans/) (see `project-rules.md` §7).
- **Frontier model on read-only work** — wastes cost; route reviews/explore to a
  cheaper model.

---

## 10. Related documents

- [`review` skill`](../ai/skills/review/SKILL.md) §4 — the canonical read-only
  parallel subagent pattern.
- [`project-rules.md`](../ai/rules/project-rules.md) — canonical rules.
- [`../engineering/review-playbook.md`](../../engineering/review-playbook.md) —
  deep review modes, packages, and report templates.
