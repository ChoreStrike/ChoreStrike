# Engineering Standards

Entry point for coding standards, conventions, and AI resources.

> Everything here is tool-agnostic and public (MIT). See [`../../AGENTS.md`](../../AGENTS.md) for the agent bootstrap.

## AI Resources

| Path | Description |
|------|-------------|
| [`ai/rules/project-rules.md`](ai/rules/project-rules.md) | Canonical rule set (architecture, security, quality, archive, plans) |
| [`ai/skills/`](ai/skills/) | Load-on-demand skills — one `SKILL.md` each (`php-backend`, `vue-frontend`, `testing`, `review`, `documentation`, `architecture`, `plan-management`, `git-workflow`, `i18n`) |

## Guidelines ([`guidelines/`](guidelines/README.md))

| File | Description |
|------|-------------|
| [coding-conventions.md](guidelines/coding-conventions.md) | PHP (PSR-12), Vue 3, CSS, and commit-message conventions |
| [testing.md](guidelines/testing.md) | Test strategy, naming, coverage expectations |
| [security.md](guidelines/security.md) | Authorization, ownership, input validation, XSS/SQLi prevention |
| [i18n.md](guidelines/i18n.md) | Localization rules and user-facing content policy |
| [logging.md](guidelines/logging.md) | Structured logging, privacy guardrails |
| [design.md](guidelines/design.md) | UI theme, color palette, brand-asset guidance |
| [external-asset-licensing.md](guidelines/external-asset-licensing.md) | Third-party icon/font/illustration acceptance rules |
| [ai-development-guidance.md](guidelines/ai-development-guidance.md) | Tool-agnostic AI agent operational notes |
| [llm-do-dont-checklist.md](guidelines/llm-do-dont-checklist.md) | One-page final gate before submitting code |

## Related

- [Architecture Decision Records](../adr/)
- [Concept Docs](../concepts/)
- [Implementation Plans](../plans/)
