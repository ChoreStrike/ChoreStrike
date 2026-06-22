---
name: review
description: Reviewing code changes and applying the quality gates before merge
---

# Skill: Review

**Load when:** Reviewing a pull request, a diff, or self-reviewing before commit.

**Canonical references:**
- [`project-rules.md`](../../rules/project-rules.md) §3 — quality & error management
- [`llm-do-dont-checklist.md`](../../../guidelines/llm-do-dont-checklist.md)
- [`../../REVIEW_PLAYBOOK.md`](../../../../REVIEW_PLAYBOOK.md)

---

## Process

1. **Understand intent:** read the linked plan/issue and the acceptance criteria first.
2. **Run the gates** (build, lint, tests). A red gate blocks merge.
3. **Read the diff** against the rules below.
4. **Fix small issues directly** (< ~10 lines, obvious). **Ask** on unclear calls. **Defer** major refactors to `docs/backlog/` with a reason.

## Checklist

**Correctness & architecture**
- Business logic only in services? Controllers thin? Persistence in mappers/entities?
- Minimal diff — no unrelated renames/formatting churn?

**Security**
- `@NoAdminRequired` (no `@PublicPage`)? House membership + ownership checked?
- Input validated at the boundary? SQL uses `createNamedParameter()`? No `v-html` with user content?

**Quality**
- Every feature/fix has a test? Failure paths covered?
- `t()` used for all UI text? `l10n/` artifacts updated?
- `AppLogger` / `logger.js` used (no `console.*`)? No secrets/PII logged?
- No suppressed warnings or silently swallowed exceptions. No `// TODO` hiding defects.

**Hygiene**
- No committed secrets, private content, or build artifacts.
- `docs/backlog/ACTIVE_TASKS.md` updated. Ambiguities logged, not guessed.

## Outcome

- Approve, request changes, or create a follow-up backlog item. State the decision clearly.
- Don't move a task to "done" with open blockers or unanswered questions.
