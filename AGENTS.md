# ChoreStrike — Agent Bootstrap

> **Single required entry point for AI agents.** Read this file first, then load only the deeper docs your task needs.
> **Core rules:** [`docs/standards/ai/rules/project-rules.md`](docs/standards/ai/rules/project-rules.md).
> **Tool-agnostic:** works with any AI coding assistant that reads `AGENTS.md` (Kilo, Claude Code, Cursor, Windsurf, …). Skills live in `docs/standards/ai/skills/`. Never put project rules in tool-specific folders (`.kilo/`, `.cursor/`); keep everything in `docs/`.

## Project Identity

- **ChoreStrike** — gamified household task management as a **Nextcloud app** (Vue 3 + PHP).
- **License:** MIT. Everything outside `/private/` and `archive/` is public.
- **Stack:** PHP 8.1+ (Nextcloud App API), Vue 3 (Composition API) + Pinia, Webpack 5. DB via Nextcloud abstraction (SQLite/MySQL/PostgreSQL). Tests: PHPUnit + Jest + Playwright.

## Quick Start

1. Read this file.
2. Read [`docs/standards/ai/rules/project-rules.md`](docs/standards/ai/rules/project-rules.md).
3. If you will change files, review code, or update backlog items, check [`docs/backlog/ACTIVE_TASKS.md`](docs/backlog/ACTIVE_TASKS.md) first.
4. Load only the deeper docs / skills your task needs (see routing below).
5. Commit each logical unit of completed work (when asked).

---

## Non-Negotiable Rules

- **Language:** Code, comments, docs, and commit messages must be **English**. Chat with the user may be German.
- **Privacy:** Never log tokens, cookies, secrets, or full sensitive payloads. Keep personal/secret material only in `/private/` (gitignored). Never commit private content.
- **Nextcloud Boundary:** Stay within the Nextcloud app ecosystem. No external services without explicit approval.
- **Architecture:** Thin controllers → services hold business logic → mappers/entities handle persistence. Never concatenate SQL.
- **Authorization:** `@NoAdminRequired` only (never `@PublicPage`). Validate house membership / ownership on every mutation. Never trust client input.
- **i18n:** Every user-visible string uses `t()`. Update `l10n/` artifacts in the same change.
- **Logging:** Backend `AppLogger`, frontend `src/utils/logger.js`. No raw `console.*` in production code.
- **Pre-release:** no backward compatibility until the user declares productive use (~V0.9): no upgrade migrations/backfills, legacy shims, or old-version fallbacks — only fresh installs must work (project-rules §1).
- **KISS & DRY:** Simple, readable, minimal diffs. ~30 lines/method, ~300 lines/file. Extract after the rule of three.

## Quality Rules

- Every new feature needs a unit/integration/E2E test. Every bug fix needs a regression test.
- **No completion without tests:** a task/plan is not done while tests required by the plan or by these rules are missing, skipped, or red (project-rules §2).
- Document every public member with PHPDoc / JSDoc.
- Fix root causes — never suppress warnings or swallow exceptions silently.
- No stack traces or internal details in production error responses.

## Decision Rules

- Fix obvious small issues directly.
- Ask before breaking changes, security-critical decisions, unclear requirements, or large refactors.
- Batch open questions at the end with two or three concrete options. Don't guess on irreversible decisions.

## Workflow

1. **Implement + test + docs** in one logical package.
2. **Self-review** against [`llm-do-dont-checklist.md`](docs/standards/guidelines/llm-do-dont-checklist.md).
3. **Build gate:** `npm test` + `npm run lint` + backend tests (Docker) must be green. Docker Desktop **is installed** on this machine — if a `docker` probe fails, diagnose per project-rules §2 ("Test Environment (Docker)"); never conclude Docker is absent.
4. **Lint/format** before committing (`npm run lint:fix`).
5. **Commit** with a conventional message (only when the user asks).
6. **Update** [`docs/backlog/ACTIVE_TASKS.md`](docs/backlog/ACTIVE_TASKS.md) (live coordination) and move completed items out of [`docs/backlog/backlog.md`](docs/backlog/backlog.md) into [`docs/backlog/archive/`](docs/backlog/archive/) (monthly) — never leave "Done" items in `backlog.md` or `ACTIVE_TASKS.md`.
7. **Propose** the next prioritized tasks. Don't declare done while blockers or open questions remain.

## Task Tracking & Plans

- **Open items** (bugs / features / tech-debt / review follow-ups) have a **single source of truth**: [`docs/backlog/backlog.md`](docs/backlog/backlog.md). Log new findings there as a themed section — never in loose `bugs.md` / `features.md` / `phase-*.md` files.
- **Live coordination:** [`docs/backlog/ACTIVE_TASKS.md`](docs/backlog/ACTIVE_TASKS.md) — in-progress work + a ~2-week rolling "Recently Completed" window. It is **not** a history log. Check it before implementation or review work.
- **Done items** move to [`docs/backlog/archive/`](docs/backlog/archive/) (one file per month, tracked in Git) at completion.
- Implementation plans live in [`docs/plans/`](docs/plans/) (`new/`, `active/`, `onhold/`, `done/`). **Moves between folders are manual** (by the user). After plan creation, propose a review (mandatory for complex/multi-phase plans).
- **Plans are temporary** working documents, not living documentation. On completion, integrate durable concepts/decisions into the permanent docs (`docs/concepts/`, `docs/adr/`, `docs/engineering/`, `docs/api/`, `docs/standards/`) — see project-rules §7.

## Skill Routing

Skills live in [`docs/standards/ai/skills/`](docs/standards/ai/skills/) and are loaded on demand — never all at once. Kilo auto-discovers them via YAML frontmatter; other tools load them manually.

| Task | Load skill (+ reference) |
|------|--------------------------|
| PHP backend | `php-backend` + [`security.md`](docs/standards/guidelines/security.md) |
| Vue frontend | `vue-frontend` + [`design.md`](docs/standards/guidelines/design.md) |
| Tests | `testing` + [`testing.md`](docs/standards/guidelines/testing.md) |
| Docker test env | `docker-dev` + [`testing.md`](docs/engineering/testing.md) |
| Gamification / rewards | `gamification` + [ADR-004](docs/adr/ADR-004-multi-user-gamification.md) |
| Review | `review` + [`llm-do-dont-checklist.md`](docs/standards/guidelines/llm-do-dont-checklist.md) |
| Architecture / refactor | `architecture` + [ADRs](docs/adr/) |
| Documentation | `documentation` |
| Localization | `i18n` + [`i18n.md`](docs/standards/guidelines/i18n.md) |
| Plan creation/review | `plan-management` |
| Git ops | `git-workflow` |

## Agent Roster & Slash Commands

- **Roster & spawning rules:** [`docs/standards/guidelines/agent-spawning.md`](docs/standards/guidelines/agent-spawning.md). Quick read-only lookups → `explore` (cheap/fast); read-only sub-reviews of non-complex slices → `reviewer` (mid); synthesis & all writes stay with the main agent.
- **Review commands:** `/review-chorestrike` (changed files), `/review-chorestrike-full` (whole module/domain, 4 parallel `reviewer` subagents), `/plan-review` (plan before `new/` → `active/`). They load the [`review`](docs/standards/ai/skills/review/SKILL.md) skill.

## Archive & Private

- `archive/` (gitignored) keeps obsolete docs/scripts for **local** reference. When removing something from the repo, move it to `archive/YYYY-MM/<name>/` instead of deleting.
- `/private/` (gitignored) is the only place for personal/secret content.

## Load More Context Only If Needed

- [`docs/standards/ai/rules/project-rules.md`](docs/standards/ai/rules/project-rules.md) — canonical rules
- [`docs/standards/guidelines/README.md`](docs/standards/guidelines/README.md) — standards index
- [`docs/README.md`](docs/README.md) — documentation map
- [`docs/engineering/architecture.md`](docs/engineering/architecture.md), [`docs/engineering/development.md`](docs/engineering/development.md), [`docs/engineering/testing.md`](docs/engineering/testing.md), [`docs/engineering/review-playbook.md`](docs/engineering/review-playbook.md)
- [`docs/backlog/README.md`](docs/backlog/README.md), [`docs/adr/README.md`](docs/adr/README.md)

---

**Last Updated:** 2026-07-30 · **Version:** 5.2
