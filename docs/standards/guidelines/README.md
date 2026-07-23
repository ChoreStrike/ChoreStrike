# Standards & Guidelines

> Coding conventions, testing, security, i18n, logging, and AI guidance for ChoreStrike.
> **Tool-agnostic:** these files work with any AI coding assistant that reads [`AGENTS.md`](../../../AGENTS.md).

---

## Guidelines

| File | Description |
|------|-------------|
| [coding-conventions.md](./coding-conventions.md) | PHP (PSR-12), Vue 3, CSS, and commit-message conventions |
| [testing.md](./testing.md) | Test strategy, naming, and coverage expectations |
| [security.md](./security.md) | Authorization, ownership, input validation, XSS/SQLi prevention |
| [i18n.md](./i18n.md) | Localization rules and user-facing content policy |
| [logging.md](./logging.md) | Structured backend/frontend logging, privacy guardrails |
| [design.md](./design.md) | UI theme, color palette, and brand-asset guidance |
| [external-asset-licensing.md](./external-asset-licensing.md) | Third-party icon/font/illustration acceptance rules |
| [ai-development-guidance.md](./ai-development-guidance.md) | Tool-agnostic AI agent operational notes |
| [agent-spawning.md](./agent-spawning.md) | Global agent roster, when to fan out, and `explore`/`reviewer` routing |
| [llm-do-dont-checklist.md](./llm-do-dont-checklist.md) | One-page final gate before submitting AI-generated code |

## AI Resources

- [AI Rules](../ai/rules/project-rules.md) — canonical rule set
- [AI Skills](../ai/skills/) — load-on-demand skills (one `SKILL.md` each)

## Related

- [Architecture Decision Records](../../adr/)
- [Concept Docs](../../concepts/)
- [Plans](../../plans/) — implementation plans (`new/`, `active/`, `onhold/`, `done/`)
