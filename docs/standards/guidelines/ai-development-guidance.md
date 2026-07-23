# AI Development Guidance

> Tool-agnostic operational notes for any AI coding assistant (Kilo, Claude Code, Cursor, Windsurf, …).
> Canonical rules: [`../ai/rules/project-rules.md`](../ai/rules/project-rules.md) and [`AGENTS.md`](../../../AGENTS.md).
> This file supplements — it does not duplicate — those documents.

---

## First-Read Pack

1. [`AGENTS.md`](../../../AGENTS.md)
2. [`../ai/rules/project-rules.md`](../ai/rules/project-rules.md)
3. [`llm-do-dont-checklist.md`](./llm-do-dont-checklist.md) — final gate

## Skill Routing

Skills live in [`../ai/skills/`](../ai/skills/) and are environment-agnostic. Each tool maps them differently:
- **Kilo:** auto-discovers skills via YAML frontmatter (`name:` / `description:` in `SKILL.md`).
- **Claude Code / Cursor / others:** load the referenced `SKILL.md` manually when the task matches.

**Load skills on demand — never all at once.**

| Task | Load |
|---|---|
| PHP backend | `php-backend` skill + [`coding-conventions.md`](./coding-conventions.md) |
| Vue frontend | `vue-frontend` skill + [`coding-conventions.md`](./coding-conventions.md) |
| Tests | `testing` skill + [`testing.md`](./testing.md) |
| Architecture / layers | `architecture` skill + relevant [ADRs](../../adr/) |
| Documentation | `documentation` skill |
| Localization | `i18n` skill + [`i18n.md`](./i18n.md) |
| Plan creation/review | `plan-management` skill |
| Code review | `review` skill |
| Git ops | `git-workflow` skill |

## Sub-Agent / Task Delegation

Use the available tool's delegation mechanism for complex sub-tasks (codebase search, multi-step research). Tool names vary (Kilo: `explore`/`general`/`reviewer` agents; others: sub-agents).

For Kilo specifically, see [`agent-spawning.md`](./agent-spawning.md) for the full roster and routing rules. Defaults worth remembering:

- **Quick read-only lookups** ("where is X / who calls Y / list files matching Z") → the **`explore`** subagent (cheap/fast). Orchestrators prefer it over grepping inline.
- **Read-only sub-reviews of non-complex slices** → the **`reviewer`** subagent (mid model), run in parallel for a Full Review — see the [`review`](../ai/skills/review/SKILL.md) skill §4.
- **Synthesis / consolidation / all writes** stay with the main agent (frontier).

Review slash commands: `/review-chorestrike`, `/review-chorestrike-full`, `/plan-review`.

## Session Notes

- Keep sessions task-focused. Load only task-relevant docs after the first-read pack.
- Fix root causes, not symptoms. **Never suppress warnings or swallow exceptions silently.**
- Lint/format before committing (`npm run lint:fix`, `vendor/bin/phpcs` where configured).
- Update `docs/backlog/ACTIVE_TASKS.md` when starting and finishing implementation work.
- After every implementation, run the review workflow (see [`review`](../ai/skills/review/SKILL.md) skill).
- After plan creation (complex/multi-phase), propose a plan review (see `project-rules.md` §7).
- Never declare work done while blockers or open questions remain.
