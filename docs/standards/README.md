# Engineering Standards

Entry point for coding standards, conventions, and AI resources.

> Everything here is tool-agnostic and public (MIT). See [`../../AGENTS.md`](../../AGENTS.md) for the agent bootstrap.

## AI Resources

| Path | Description |
|------|-------------|
| [`ai/rules/project-rules.md`](ai/rules/project-rules.md) | Canonical rule set (architecture, security, quality, archive, plans) |
| [`ai/skills/`](ai/skills/) | Load-on-demand skills — one `SKILL.md` each (`php-backend`, `vue-frontend`, `testing`, `docker-dev`, `gamification`, `review`, `documentation`, `architecture`, `plan-management`, `git-workflow`, `i18n`, `release`) |
| [`ai/commands/`](ai/commands/) | Slash-command bodies (`/review-chorestrike`, `/review-chorestrike-full`, `/plan-review`) |
| [`ai/agents/`](ai/agents/) | Subagent roles (`reviewer`) |

**Tool adapters.** `docs/standards/ai/` is the only place to edit. Kilo reads the
skills directly (`kilo.jsonc` → `skills.paths`) and loads the rules via
`instructions`; Claude Code loads `AGENTS.md` + the rules through `CLAUDE.md`
imports. `npm run agents:sync` generates `.claude/skills|commands|agents/` and
`.kilo/command/`; `npm run agents:check` (also run by `npm test`) fails on drift.
Frontmatter: skills need `name` (= directory) and `description`; agents may set
`read-only: true` (mapped to read-only tools for Claude).

## Guidelines ([`guidelines/`](guidelines/))

| File | Description |
|------|-------------|
| [coding-conventions.md](guidelines/coding-conventions.md) | PHP (Nextcloud coding standard), Vue 3, CSS, and commit-message conventions |
| [testing.md](guidelines/testing.md) | Test strategy, naming, coverage expectations |
| [e2e-testid-conventions.md](guidelines/e2e-testid-conventions.md) | Stable Playwright selectors, `data-testid` naming, and auth (`storageState`) |
| [security.md](guidelines/security.md) | Authorization, ownership, input validation, XSS/SQLi prevention |
| [i18n.md](guidelines/i18n.md) | Localization rules, content policy, and the POT/JSON artifact workflow |
| [logging.md](guidelines/logging.md) | Structured logging, privacy guardrails |
| [design.md](guidelines/design.md) | UI theme, color palette, and brand-asset guidance |
| [external-asset-licensing.md](guidelines/external-asset-licensing.md) | Third-party icon/font/illustration acceptance rules + decision log |
| [agent-spawning.md](guidelines/agent-spawning.md) | Global agent roster, when to fan out, and `explore`/`reviewer` routing |
| [llm-do-dont-checklist.md](guidelines/llm-do-dont-checklist.md) | One-page final gate before submitting code |

## Related

- [Architecture Decision Records](../adr/)
- [Concept Docs](../concepts/)
- [Implementation Plans](../plans/)
