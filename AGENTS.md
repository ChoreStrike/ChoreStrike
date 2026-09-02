# ChoreStrike — Agent Bootstrap

> **Single required entry point for AI agents.** Read this file, then
> [`docs/standards/ai/rules/project-rules.md`](docs/standards/ai/rules/project-rules.md) — the canonical rule set. Everything else loads on demand.
> **Tool-agnostic:** works with any AI coding assistant that reads `AGENTS.md` (Kilo, Claude Code, Cursor, Windsurf, …). Skills live in `docs/standards/ai/skills/` — load on demand, never all at once. Never put project rules in tool-specific folders (`.kilo/`, `.cursor/`); thin command adapters may live there but must delegate to the canonical rules in `docs/`.

## Project Identity

- **ChoreStrike** — gamified household task management as a **Nextcloud app**.
- **Stack:** PHP 8.2+ (Nextcloud App API), Vue 3 (Composition API) + Pinia, Webpack 5. DB via Nextcloud abstraction (SQLite/MySQL/PostgreSQL). Tests: PHPUnit + Jest + Playwright (Docker).
- **License:** MIT. Everything outside `/private/` and `archive/` is public.

## Non-Negotiable Rules (canonical detail: project-rules.md)

1. **Language:** code, comments, docs, commits in **English** (chat with the user may be German).
2. **Privacy:** never log tokens/secrets/sensitive payloads; personal/secret material only in `/private/` (gitignored).
3. **Nextcloud boundary:** stay inside the Nextcloud app ecosystem — no external services without explicit approval.
4. **Architecture:** thin controllers → services (business logic) → mappers/entities (persistence). Never concatenate SQL.
5. **Authorization:** `@NoAdminRequired` only (never `@PublicPage`); validate house membership/ownership on every mutation; never trust client input.
6. **i18n:** every user-visible domain/UI string via `t()`; update `l10n/` artifacts in the same change. The sanitized generic formatter fallback is the sole documented exception (`i18n.md` D2).
7. **Logging:** backend `AppLogger`, frontend `src/utils/logger.js`; no raw `console.*`.
8. **Pre-release:** no backward compatibility until the user declares productive use (~V0.9) — fresh installs only, no upgrade paths/shims.
9. **KISS & DRY:** simple, readable, minimal diffs; ~30 lines/method, ~300 lines/file; extract after the rule of three.
10. **No completion without tests:** every feature/bug fix has a test; never done while required tests are missing, newly/unapproved skipped, or red.
11. **Backlog discipline:** open items live only in [`docs/backlog/backlog.md`](docs/backlog/backlog.md); live coordination in [`docs/backlog/ACTIVE_TASKS.md`](docs/backlog/ACTIVE_TASKS.md); done items move to `docs/backlog/archive/YYYY-MM.md` immediately.

## Workflow

1. Check [`docs/backlog/ACTIVE_TASKS.md`](docs/backlog/ACTIVE_TASKS.md) before changing files, reviewing, or touching backlog items.
2. Implement + test + docs in one logical unit. Self-review against [`docs/standards/guidelines/llm-do-dont-checklist.md`](docs/standards/guidelines/llm-do-dont-checklist.md).
3. **Build gate:** `npm test` + `npm run lint` + backend tests (Docker) must be green. Docker Desktop **is installed** — if a `docker` probe fails, diagnose per project-rules §2; never conclude Docker is absent.
4. `npm run lint:fix` before committing when its write scope contains no unrelated dirty source files; otherwise use scoped fixers/check-only lint. Conventional commits — **only when the user asks**, except that requesting a plan/code review authorizes its one scoped automatic review commit on the current branch. An explicit read-only/no-commit request overrides that authorization.
5. Update `ACTIVE_TASKS.md`; move completed backlog items to the archive. Batch open review questions with recommended options and resolve them before a plan transition. Don't declare done while blockers or open questions remain.

## Skill Routing (`docs/standards/ai/skills/`)

| Task | Skill (+ guideline) |
|------|---------------------|
| PHP backend | `php-backend` + [`security.md`](docs/standards/guidelines/security.md) |
| Vue frontend | `vue-frontend` + [`design.md`](docs/standards/guidelines/design.md) |
| Tests | `testing` + [`testing.md`](docs/standards/guidelines/testing.md) |
| Docker test env | `docker-dev` |
| Gamification / rewards | `gamification` + [ADR-004](docs/adr/ADR-004-multi-user-gamification.md) |
| Review | `review` + checklist |
| Architecture / refactor | `architecture` + [ADRs](docs/adr/) |
| Documentation | `documentation` |
| Localization | `i18n` + [`i18n.md`](docs/standards/guidelines/i18n.md) |
| Plan creation/review | `plan-management` |
| Git ops | `git-workflow` |

## Review Commands & Subagents

- `/review-chorestrike` (changed files), `/review-chorestrike-full` (whole module/domain, 4 parallel `reviewer` subagents), `/plan-review` (plan before `new/` → `active/`) — all load the [`review`](docs/standards/ai/skills/review/SKILL.md) skill, batch decisions, update an owning plan when one exists, and automatically commit an eligible non-empty review package. A final passing code review moves a complete plan to `done/`; an interim review leaves it in `active/`.
- Agent roster + when to fan out: [`docs/standards/guidelines/agent-spawning.md`](docs/standards/guidelines/agent-spawning.md). Quick read-only lookups → `explore`; read-only sub-reviews → `reviewer`; synthesis and all writes stay with the main agent.

## Deeper Context (only if needed)

- [`docs/standards/ai/rules/project-rules.md`](docs/standards/ai/rules/project-rules.md) — canonical rules
- [`docs/standards/README.md`](docs/standards/README.md) — standards index
- [`docs/README.md`](docs/README.md) — documentation map
- [`docs/engineering/`](docs/engineering/) — architecture, development, testing, review playbook
- [`docs/backlog/README.md`](docs/backlog/README.md) · [`docs/adr/README.md`](docs/adr/README.md)

---

**Last Updated:** 2026-09-02 · **Version:** 6.1
