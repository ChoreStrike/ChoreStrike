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

- Stay on the currently checked-out branch (normally `master`). Do not create or switch branches unless the user explicitly requests it.
- When a branch is requested, use `feat/...`, `fix/...`, `docs/...`, `refactor/...`, or `chore/...` and keep one logical change per branch/PR.

## Commits

- Follow the conventional-commit format and types in [`coding-conventions.md`](../../../guidelines/coding-conventions.md).
- One logical unit per commit. Reference the issue/plan where relevant (`Closes #123`).
- Run `npm run lint:fix` only when its write scope contains no unrelated dirty source files; otherwise use scoped fixers and repository-wide check-only lint.

## Never Commit

- Secrets, tokens, credentials.
- Private content (belongs in `/private/`, gitignored).
- Build artifacts and coverage (`js/`, `coverage/`, `line/`, `*.log`).
- Anything from the repository-root `/archive/` (gitignored local reference). Tracked `docs/backlog/archive/` records are allowed.

## Automatic Review Commit

Requesting a plan/code review authorizes exactly one automatic commit for that review on the current branch.

1. Capture the review scope, branch, HEAD SHA, index file set/fingerprint, and complete scope fingerprint before edits. The fingerprint covers the staged binary diff, unstaged binary diff, and sorted untracked relative paths with a content hash for every untracked file. Never reset, stash, or overwrite unrelated changes.
2. Resolve every batched question before finalization. Immediately before staging and before committing, compare branch, HEAD, index, and the complete scope fingerprint (including untracked paths/content hashes) with the expected review state; block on unexpected drift.
3. Define the outcome-specific allowed commit set, then require the existing staged set to be an exact subset. Stage only reviewed implementation, eligible plan transition/evidence, review record, backlog/tracking updates, and verified safe fixes. Any staged path outside that set, including an in-scope file excluded because of unresolved findings, blocks the automatic commit without changing the index.
4. Inspect `git status`, the unstaged diff, the staged diff, and recent log; reject secrets, private content, sensitive unremediated vulnerability details, build artifacts, or an empty commit.
5. Commit once with a conventional message. A failed hook/commit leaves the reviewed changes visible for diagnosis; do not amend, roll back destructively, push, or open a PR automatically.

For a review with unresolved findings but no unanswered questions, commit only the owning plan's evidence-only `## Review Outcome`, review record, backlog updates, and verified safe fixes. Do not include implementation files affected by unresolved findings or move their plan to `done/`.

An explicit read-only or `--no-commit` instruction overrides review authorization and disables edits, lifecycle moves, staging, and commits.

## Before a PR

1. Rebase on the target branch; resolve conflicts cleanly.
2. Build + tests + lint green.
3. Review the full diff (all commits), not just the latest.
4. Self-review against the [`review`](../review/SKILL.md) skill and [`llm-do-dont-checklist.md`](../../../guidelines/llm-do-dont-checklist.md).

> Outside the automatic review-commit rule above, only commit, amend, push, or open a PR when the user explicitly asks.
