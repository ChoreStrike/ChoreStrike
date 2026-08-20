---
name: review
description: ChoreStrike review process — Standard (plan/code) and Full (module/domain, 4 parallel reviewer subagents) tiers, checklist, auto-fix rules, follow-up. Load for any review, self-review, or /review-* / /plan-review command.
---

# Review — Standard & Full Review Process

> Canonical review process for ChoreStrike. This skill is the single source of
> truth for *how* to review; deep modes, packages, and report templates live in
> [`review-playbook.md`](../../../../engineering/review-playbook.md).

## 1. When to load

- A review is requested (self-review, post-implementation, plan review, domain review).
- `/review-chorestrike`, `/review-chorestrike-full`, or `/plan-review` is invoked.
- An implementation round finished and the plan's checklist asks for a review.

## 2. Choose the tier

- **Standard A (Plan)** — before `new/` → `active/`, one plan file. `/plan-review`.
- **Standard B (Code)** — after a round / partial impl / docs, changed files only. `/review-chorestrike`.
- **Full** — whole module/domain, or anything touching authorization, data integrity, migrations, or routing. 4 parallel subagents. `/review-chorestrike-full`.
- **Unsure** → Full if >1 domain touched, security/auth-relevant, or >5 files changed; else Standard B.

## 3. Standard Review (Type A: Plan, Type B: Code)

Scope: only the changed files / the plan / the docs. No subagents. Run the shared
checklist; for **Type A (plan)** add the *Plan-only* checks.

### Shared checklist (ChoreStrike)

- **Architecture** — Business logic only in services? Controllers thin (validate, call service, shape response)? Persistence in mappers/entities? No SQL concatenation (`createNamedParameter()` / prepared statements)?
- **Authorization** — `@NoAdminRequired` only (never `@PublicPage`)? Every mutation validates **house membership**; owner-only ops use `requireHouseOwner()`? Client input never trusted (IDs/fields resolved server-side)?
- **Security** — Input validated at the controller boundary? No `v-html` with user content? No secrets/PII/tokens/cookies logged? No stack traces in error responses?
- **Duplication / KISS** — reuse-first followed; no cloned behavior an existing module covers; minimal diff (no unrelated renames/formatting churn); ~30 lines/method, ~300 lines/file.
- **Workarounds** — every `// HACK:` has a root cause + removal condition **and** a follow-up in [`docs/plans/new/`](../../../../plans/) or `backlog.md`. No stacked workarounds; no suppressed warnings or silently swallowed exceptions.
- **Logging** — `AppLogger` (backend) / `src/utils/logger.js` (frontend); no raw `console.*`; stable structured keys; nothing sensitive logged.
- **i18n** — every user-visible string uses `t()`; `l10n/` artifacts updated in the same change (`npm run l10n:check-keys`).
- **Validation** — test scope matches risk (unit/integration/E2E); every bug fix has a regression test; failure paths covered.
- **Docs/Plan** — public API / workflow / rules changed? → docs updated. Active plan owns this work? → its checklist + a `## Review Outcome` note updated.

### Plan-only (Type A) extra checks

- Clear, stable ownership for every step; steps individually validatable.
- No overlap with an existing active plan; scope is coherent (no mixed concerns).
- Risks and dependencies are named; no workaround-stacking pre-baked into the plan.
- Acceptance criteria complete; test plan adequate.

## 4. Full Review (subagent strategy)

The main agent loads context and orchestrates; **four `reviewer` subagents run in
parallel** (via the `task` tool with `subagent_type: "reviewer"`). This named
agent is defined **globally** inline in `~/.config/kilo/kilo.jsonc` under
`agent.reviewer`: read-only by contract (`edit`/`write` denied), pinned to a
**mid** model (I/O-bound find-and-flag work — a frontier model is wasted).
Project-specific criteria come from this skill (§3/§4), not from the agent prompt.
The main agent owns all fixes (§5) and the follow-up (§6).

Flow: delegate the **prep lookups** ("where is X", inventories) to the cheap
**`explore`** subagent first (see
[`agent-spawning.md`](../../../guidelines/agent-spawning.md) §2) → set scope →
spawn the 4 reviewers in parallel → consolidate + deduplicate findings →
auto-fix/backlog (§5) → follow-up (§6). Escalate any genuinely complex slice
(authorization trace, cross-service invariant) to the main agent instead of a
`reviewer`.

| # | Subagent | Checks | Deliverable |
| - | -------- | ------ | ----------- |
| 1 | **Correctness & Functionality** | Logic errors, edge cases, missing/wrong validation, tests present & green, determinism | Findings |
| 2 | **Architecture & Ownership** | Layering (controller/service/mapper), thin routes, reuse, file/method size, duplicates, circular deps | Findings + refactor notes |
| 3 | **Security, Auth & Data-Flow** | `@NoAdminRequired` only, house membership/ownership, client-input validation, `createNamedParameter()`, no `v-html` user content, no secrets/PII in logs, exploit vectors (see [`security.md`](../../../guidelines/security.md)) | Findings (security = never auto-fix) |
| 4 | **Performance & Code Quality** | Hot loops/N+1 queries, allocations, `AppLogger`/`logger.js` usage & levels, PHPDoc/JSDoc, coding standards, i18n `t()` coverage | Findings + perf notes |

### Subagent prompt frame

Give every subagent the same skeleton, only the focus + criteria differ:

```
You are reviewing <module/path> for ChoreStrike. READ-ONLY: return findings, do not edit.

Scope files: <list>
Focus: <one of the 4 areas above>
Criteria: <section from this skill / security.md / coding-conventions.md>

Return ONLY findings as a fenced JSON array, one object per issue:
{ "severity": "block|major|minor|nit",
  "file": "<path>:<line>",
  "issue": "<what is wrong>",
  "suggested_fix": "<one-line fix idea>",
  "auto_fixable": <true|false> }

- severity "block" = must not ship; "major" = should fix before done;
  "minor" = small bug/auto-fixable; "nit" = style/preference.
- Mark auto_fixable=false for anything security/auth-relevant, architectural,
  migration-related, or behavior-changing.
- Do not propose workarounds. Do not restate code that is already correct.
```

## 5. Auto-fix rule — what "fix small bugs inline" means

Apply fixes **directly** in the review for items in the left column. For items in
the right column, log a backlog item (see [`backlog/README.md`](../../../../backlog/README.md))
and/or a `docs/plans/new/` follow-up instead of fixing inline.

| Auto-fix inline | Log only (→ backlog / plan) |
| --------------- | --------------------------- |
| Typos, off-by-one, wrong comparison | Architecture changes, new abstractions |
| Missing/wrong PHPDoc/JSDoc | Security/auth-relevant logic (always discuss) |
| `console.*` → `AppLogger`/`logger.js` | Performance refactor with behavior risk |
| Hardcoded UI string → `t()` (+ `l10n/`) | New behavior / new public API / migration |
| Missing validation when the pattern is clear and local | Workarounds (keep marked + follow-up) |
| Coding-standard violations (format, naming) | Cross-domain refactors |

After each fix cluster, run the **quality gate**: `npm run lint:fix`,
`npm run stylelint:fix` (if styles touched), then `npm test`; run the **backend**
tests in Docker when PHP changed (see the `docker-dev` skill). If a gate goes red,
fix the fix or roll back — never leave the tree broken. Security/auth findings are
**never** auto-fixed: they become a backlog item and are surfaced explicitly to the user.

## 6. Follow-up pipeline (docs → plan → backlog → commit)

1. **Quality gate** green (lint:fix + test, backend in Docker if PHP changed) — otherwise fix/roll back first.
2. **Docs:** update only where the public API, workflow, or rules actually changed. No drive-by doc edits.
3. **Plan:** if an active plan owns this work, tick its checklist and append a short `## Review Outcome` note (what was reviewed, fixed, deferred). This is the review record — no separate review file.
4. **Backlog:** any non-trivial leftover becomes a backlog item with `Source: Review of <module>` (format in [`backlog/README.md`](../../../../backlog/README.md)); update `ACTIVE_TASKS.md`. For substantial reviews, also create/update `docs/backlog/CODE_REVIEW_YYYY-MM-DD.md` (template in `review-playbook.md`).
5. **Commit:** ask the user (or honor a `--commit` flag). Conventional-commit format, green gate only. Never commit secrets.

## 7. Output to the user

End the review with a compact, skimmable report:

1. **Scope** reviewed (files / module / plan). 2. **Tier** used and why.
3. **Findings** table: severity, file:line, issue, status (fixed / deferred).
4. **Auto-fixes** applied + quality-gate result. 5. **Backlog items** added.
6. **Docs/Plan** updated. 7. **Commit** made (hash) or "not committed — awaiting approval".

## 8. Related documents

- [`review-playbook.md`](../../../../engineering/review-playbook.md) — deep modes, packages, report templates.
- [`security.md`](../../../guidelines/security.md) · [`llm-do-dont-checklist.md`](../../../guidelines/llm-do-dont-checklist.md) · [`agent-spawning.md`](../../../guidelines/agent-spawning.md).
