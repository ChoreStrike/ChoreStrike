# ChoreStrike – Agent Bootstrap

> **Single required entry point for AI agents.** Read this file first. Load deeper documents only when your task needs them.

## Purpose

ChoreStrike is a Nextcloud app for gamified household task management.

Use this document as the mandatory bootstrap for any agent or coding assistant. Keep it as the stable, system-neutral entry point. Load only the additional documents that are relevant to the current task.

## Quick Start

1. Read this file.
2. If you will change files, review code, or update backlog items, check `docs/backlog/ACTIVE_TASKS.md` first.
3. Load only the deeper documents that are relevant to your task.
4. Update tracking docs when you start and finish implementation or review work.
5. Commit each logical unit of completed work.

---

## Non-Negotiable Rules

- **Language:** Code, comments, documentation, and commit messages must be in English. Chat with the user may be in German.
- **Private Content:** Store personal, business, or secret material only in `/private/`. Never commit private content into public project areas.
- **Nextcloud Boundary:** Stay within the Nextcloud app ecosystem. Do not introduce external services unless explicitly approved.
- **KISS and DRY:** Prefer simple, readable solutions. Keep methods and files small. Extract duplication after the rule of three.
- **Architecture:** Keep controllers and routes thin. Put business logic in services. Keep persistence in mappers and entities.
- **Logging:** Use shared backend and frontend logging helpers. Do not add raw production `console.*` calls or log sensitive payloads.

## When Task Tracking Is Required

Read `docs/backlog/ACTIVE_TASKS.md` before:

- Implementing or changing files
- Reviewing another task
- Updating backlog state
- Starting work that could conflict with another agent

If you are only answering a question or doing a read-only analysis, checking `ACTIVE_TASKS.md` is optional.

When you start implementation or review work:

- Add or update the active task entry
- Keep progress notes current when the task meaningfully changes
- Mark completed work and list modified files
- Update the related backlog document when the task status changes

## Quality Rules

- Document every public member with PHPDoc or JSDoc.
- Every new feature needs unit tests.
- Every bug fix needs a regression test.
- All user-facing UI text must use localization via `t()`.
- Validate authorization and ownership checks. Never trust client input.
- Use structured, privacy-safe logging. Never log tokens, cookies, secrets, or full sensitive payloads.

## Decision Rules

- Fix obvious small issues directly.
- Ask the user before breaking changes, security-critical decisions, unclear requirements, or large refactors.
- Batch open questions at the end with two or three concrete options.

## Completion Rules

Before you finish a logical unit of work:

- Run the relevant tests for the touched area
- Update `docs/backlog/ACTIVE_TASKS.md`
- Update the related backlog file
- Commit the completed unit of work
- Report the next prioritized tasks and a concrete next-step proposal

## Load More Context Only If Needed

- `docs/README.md` - documentation map and entry points
- `docs/DEVELOPMENT.md` - local setup, commands, and contributor workflow
- `docs/ARCHITECTURE.md` - architecture boundaries and responsibilities
- `docs/TESTING.md` - active suites and testing strategy
- `docs/REVIEW_PLAYBOOK.md` - review workflow and checklist
- `docs/standards/README.md` - detailed engineering standards index
- `docs/backlog/README.md` - backlog structure and workflow
- `docs/backlog/phase-*.md` and `docs/backlog/bugs.md` - task-specific backlog context

---

**Last Updated:** 2026-03-22
**Version:** 4.0
