---
name: git-workflow
description: Branching, conventional commits, and clean history for the single ChoreStrike repository
---

# Skill: Git Workflow

**Load when:** Branching, staging, committing, or preparing a pull request.

**Canonical references:**
- [`project-rules.md`](../../rules/project-rules.md) §3–4
- [`coding-conventions.md`](../../../guidelines/coding-conventions.md) — commit format

---

## Repository

- ChoreStrike is a **single** Git repository (one Nextcloud app). Stage, commit, and push from the repo root.

## Branching

- Branch off the default branch: `feat/...`, `fix/...`, `docs/...`, `refactor/...`, `chore/...`.
- One logical change per branch/PR. Keep PRs reviewable.

## Commits

Conventional commits:

```
<type>: <description>

[optional body]

[optional footer]
```

Types: `feat`, `fix`, `docs`, `refactor`, `test`, `i18n`, `perf`, `chore`, `build`, `ci`.

- One logical unit per commit. Reference the issue/plan where relevant (`Closes #123`).
- Lint/format before committing (`npm run lint:fix`).

## Never Commit

- Secrets, tokens, credentials.
- Private content (belongs in `/private/`, gitignored).
- Build artifacts and coverage (`js/`, `coverage/`, `line/`, `*.log`).
- Anything from `archive/` (gitignored — local reference only).

## Before a PR

1. Rebase on the target branch; resolve conflicts cleanly.
2. Build + tests + lint green.
3. Review the full diff (all commits), not just the latest.
4. Self-review against the [`review`](../review/SKILL.md) skill and [`llm-do-dont-checklist.md`](../../../guidelines/llm-do-dont-checklist.md).

> Only commit, amend, push, or open a PR when the user explicitly asks.
