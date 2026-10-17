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

- **`master` is always releasable.** Work happens on a short-lived branch per plan or topic — `feat/...`, `fix/...`, `docs/...`, `refactor/...` or `chore/...`, one logical change each — and reaches `master` by fast-forward or merge after its review. Long-running feature branches (e.g. a P3 add-on) develop independently and rebase onto `master` deliberately.
- Stay on the branch the task was given. Do not create, switch or merge branches unless the user explicitly requests it or the task names the branch.
- **One session, one worktree, one branch.** A parallel session gets its own worktree (`git worktree add <path> -b <branch> master`); never commit in a checkout another session works in. Record branch and worktree in the task's `ACTIVE_TASKS.md` entry.
- **Pin the worktree** at task start with `npm run branch:pin` (status: `npm run branch:status`). The guard then refuses commits on any other branch in that worktree.
- **Release freeze:** between a candidate's qualification and its deployment, nothing lands on `master`; a later fix goes to a branch and needs its own requalification.

### Branch guard (`.githooks/pre-commit`)

Tool-agnostic — it applies to Claude Code, Kilo and humans alike. `npm ci` / `npm install` activate it (`prepare` → `scripts/git-guard.js install` sets `core.hooksPath=.githooks`; it never replaces a different configured hooks path).

- Refuses direct commits on `master`/`main`. Fast-forwards and merges are not affected. For a commit the user explicitly asked to make on `master`, run that one command with `CS_ALLOW_MASTER=1` (PowerShell: `$env:CS_ALLOW_MASTER='1'; git commit ...; Remove-Item Env:CS_ALLOW_MASTER`). Agents never set it on their own initiative.
- Refuses commits on a branch other than the worktree's pin; detached HEAD (rebase, bisect, agent pools) is left alone.
- Never bypass it (`--no-verify`, changing `core.hooksPath`); a refusal means: stop, check `npm run branch:status`, and ask.

## Commits

- Follow the conventional-commit format and types in [`coding-conventions.md`](../../../guidelines/coding-conventions.md).
- One logical unit per commit. Reference the issue/plan where relevant (`Closes #123`).
- Lint/format first per [`project-rules.md`](../../rules/project-rules.md) §3.

## Never Commit

- Secrets, tokens, credentials.
- Private content (belongs in `/private/`, gitignored).
- Build artifacts and coverage (`js/`, `css/`, `build/`, `coverage/`, `*.log`).
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
