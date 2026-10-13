---
name: review
description: ChoreStrike review process — Standard (plan/code) and Full tiers, findings, questions, lifecycle transitions, and automatic review commits
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
- **Standard B (Code)** — changed files for an interim or final implementation review. `/review-chorestrike`.
- **Full** — whole module/domain, or anything touching authorization, data integrity, migrations, or routing. 4 parallel subagents. `/review-chorestrike-full`.
- **Unsure** → Full if >1 domain touched, security/auth-relevant, or >5 files changed; else Standard B.

Tier and lifecycle state are separate: a passing Standard B can be interim. Only a
review that verifies the **whole owning plan** may move it to `done/`.

## 3. Standard Review (Standard A: Plan, Standard B: Code)

Scope: the target plan for Standard A; the captured changed-file set for Standard B.
No subagents. A final Standard B additionally reviews the owning plan's full file
manifest, acceptance criteria, required test evidence, durable-doc integration, and
relevant implementation history. An empty or narrow working-tree diff alone can
never prove plan completion.

### Shared checklist (ChoreStrike)

- **Architecture** — Business logic only in services? Controllers thin (validate, call service, shape response)? Persistence in mappers/entities? No SQL concatenation (`createNamedParameter()` / prepared statements)?
- **Authorization** — `@NoAdminRequired` only (never `@PublicPage`)? Every mutation validates **house membership**; owner-only ops use `requireHouseOwner()`? Client input never trusted (IDs/fields resolved server-side)?
- **Security** — Input validated at the controller boundary? No `v-html` with user content? No secrets/PII/tokens/cookies logged? No stack traces in error responses?
- **Duplication / KISS** — reuse-first followed; no cloned behavior an existing module covers; minimal diff (no unrelated renames/formatting churn); ~30 lines/method, ~300 lines/file.
- **Workarounds** — every `// HACK:` has a root cause + removal condition **and** an open follow-up in `backlog.md`; a linked plan is optional, never a substitute. No stacked workarounds; no suppressed warnings or silently swallowed exceptions.
- **Logging** — `AppLogger` (backend) / `src/utils/logger.js` (frontend); no raw `console.*`; stable structured keys; nothing sensitive logged.
- **i18n** — every user-visible string uses `t()`; `l10n/` artifacts updated in the same change (`npm run l10n:check-keys`).
- **Validation** — test scope matches risk (unit/integration/E2E); every bug fix has a regression test; failure paths covered.
- **Docs/Plan** — public API / workflow / rules changed? → docs updated. An owning plan exists? → its checklist, `## Review Findings` (scope-first routing per §6), and `## Review Outcome` note are updated.

### Plan-only (Type A) extra checks

- Clear, stable ownership for every step; steps individually validatable.
- No overlap with an existing active plan; scope is coherent (no mixed concerns).
- Risks and dependencies are named; no workaround-stacking pre-baked into the plan.
- Acceptance criteria complete; test plan adequate.

## 4. Full Review (subagent strategy)

The main agent loads context and orchestrates; **four read-only `reviewer`
subagents run in parallel** (role and output contract:
[`agents/reviewer.md`](../../agents/reviewer.md); tool mapping:
[`agent-spawning.md`](../../../guidelines/agent-spawning.md) §3). The main agent
owns all synthesis, fixes, questions, transitions, and commits.

Flow: delegate **prep lookups** ("where is X", inventories) to `explore` → set
scope → spawn the 4 reviewers in parallel → validate all four deliverables →
consolidate + deduplicate → auto-fix/question/route. A failed, missing, or
malformed reviewer result blocks a Full-review verdict; never silently omit a
track. Escalate genuinely complex slices (authorization trace, cross-service
invariant) to the main agent instead of a `reviewer`.

| # | Track | Checks | Deliverable |
| - | ----- | ------ | ----------- |
| 1 | **Correctness & Functionality** | Logic errors, edge cases, missing/wrong validation, tests present & green, determinism | Findings |
| 2 | **Architecture & Ownership** | Layering (controller/service/mapper), thin routes, reuse, file/method size, duplicates, circular deps | Findings + refactor notes |
| 3 | **Security, Auth & Data-Flow** | `@NoAdminRequired` only, house membership/ownership, client-input validation, `createNamedParameter()`, no `v-html` user content, no secrets/PII in logs, exploit vectors ([`security.md`](../../../guidelines/security.md)) | Findings (security = never auto-fix) |
| 4 | **Performance & Code Quality** | Hot loops/N+1 queries, allocations, `AppLogger`/`logger.js` usage & levels, PHPDoc/JSDoc, coding standards, i18n `t()` coverage | Findings + perf notes |

Task prompt per reviewer (only focus + criteria differ):

```
Review <module/path> for ChoreStrike. Follow the read-only output contract in
docs/standards/ai/agents/reviewer.md.
Scope files: <list>
Focus: <one track above>
Criteria: <section of this skill / security.md / coding-conventions.md>
```

## 5. Outcomes, Questions, and Plan Ownership

Use exactly these outcomes:

| Outcome | Meaning | Automatic action |
|---------|---------|------------------|
| `PASS` | No findings remain | Eligible plan transition; commit reviewed package |
| `PASS-WITH-FIXES` | All findings were safely fixed and gates rerun | Eligible plan transition; commit reviewed package |
| `NEEDS-FOLLOW-UP` | Questions are answered and gates are green, but findings remain | Keep plan in place; commit only review evidence, backlog updates, and verified safe fixes |
| `BLOCKED` | Scope/plan is ambiguous, a question is unanswered, a required track/gate is missing or failing, or concurrent changes invalidate the review | No transition and no automatic commit |

### Owning plan

- Standard A accepts exactly one regular, non-symlink `*.md` plan in
  `docs/plans/new/`, whether tracked or untracked; dotfiles such as `.gitkeep`
  are never candidates. The destination must not already exist.
- For code/full reviews, an explicit plan path wins. Otherwise resolve ownership
  from `docs/plans/active/` and the reviewed scope. Zero matching plans is valid
  (`plan: N/A`); more than one plausible plan is a blocking question.
- A matching plan stays active after an interim review. Move it to `done/` only
  when every plan item, acceptance criterion, required test, durable-doc update,
  final-review requirement, and every `## Review Findings` entry (resolved or
  user-rescoped to the backlog) is complete.

### Batched questions

- Collect uncertainties during review and ask them together near the end through
  the structured question tool (Kilo `question`, Claude Code `AskUserQuestion`).
- Give 2–3 concrete, mutually exclusive options. Put the recommended option first,
  mark it **Recommended**, and explain the trade-off; never treat the recommendation
  as the user's consent.
- Ask behavior-changing, architectural, migration, and security authorizations
  explicitly. Do not hide them inside a general classification question.
- Record every answer in the owning plan or review record, apply it, rerun affected
  checks, and then resume finalization. No outcome, plan move, or commit while an
  answer is pending.
- An explicit read-only or `--no-commit` request runs a findings-only review: do
  not edit tracking/plans, perform lifecycle moves, stage, or commit.

## 6. Auto-fix Rule

Apply fixes **directly** for items in the left column. For the right column, ask
when a decision is needed, then route every remaining open finding per
[`project-rules.md`](../../rules/project-rules.md) §4 "Finding Routing"
(owning plan's `## Review Findings` → fold into an existing backlog item →
`backlog.md` with `Source: Review of <scope>` only when out of scope or
`plan: N/A`; `// HACK:` always gets a `backlog.md` follow-up).

| Auto-fix inline | Discuss or log only |
| --------------- | --------------------------- |
| Typos, broken links, objectively stale documentation | Any security/auth or validation logic |
| Missing/wrong PHPDoc/JSDoc | Logic changes (comparisons, boundaries, algorithms) |
| Behavior-neutral logger API replacement | Architecture changes or new abstractions |
| Hardcoded UI string → `t()` (+ `l10n/`) | New behavior, public API, or migration |
| Behavior-neutral format/naming violations | Performance or cross-domain refactors |
| Clearly orphaned imports/comments | Workarounds and deferred required tests |

After each fix cluster, run the checks required by the changed files, reviewed risk,
and owning plan. Run write-capable fixers only against captured review files; if a
repository-wide fixer could touch unrelated dirty files, use its scoped executable
or check-only command and block before broad rewrites. Run `npm test`, backend
unit/integration tests in Docker when PHP changed, and applicable E2E/build/docs
gates. A timeout, missing required suite, or newly skipped required test is not
green. Security/auth findings are **never** auto-fixed. Potentially exploitable,
unremediated details force `BLOCKED`: keep details in `/private/` and never
auto-commit them to tracked review/backlog files. A sanitized public placeholder
requires explicit disclosure approval.

## 7. Finalization Pipeline

1. **Capture:** record scope, HEAD, current branch, index state, complete reviewed-scope fingerprint, and owning-plan candidate before review. Include staged/unstaged binary diffs plus sorted untracked paths and per-file content hashes. Preserve unrelated user/agent changes.
2. **Review/fix:** complete every required track, consolidate findings, apply only safe fixes, and run all required gates.
3. **Questions:** ask one batched structured request, record the answers, apply them, and rerun affected checks.
4. **Docs:** update only where public API, workflow, rules, or durable plan outcomes changed.
5. **Plan:** append a concise `## Review Outcome` (scope, base HEAD/diff fingerprint, outcome, fixes, findings, decisions, gates). On eligible Standard A, update status and owning backlog items, then move `new/ → active/`. On an eligible final Standard B/Full review, update status, archive completed owning backlog items, and move `active/ → done/`. Interim or `NEEDS-FOLLOW-UP` reviews leave the plan in place.
6. **Findings routing/record:** route every non-sensitive open finding per §6; update `ACTIVE_TASKS.md`. A plan-owned review always updates the plan; a substantial review additionally creates/updates `docs/backlog/archive/CODE_REVIEW_YYYY-MM-DD.md` from the playbook template. Any `NEEDS-FOLLOW-UP` evidence-only commit without an owning plan also requires that tracked review record, even when the review is otherwise small.
7. **Commit:** one scoped conventional commit on the current branch, following the [`git-workflow`](../git-workflow/SKILL.md#automatic-review-commit) procedure exactly (drift check, exact allowed set, no empty commit, no branch/amend/push/PR). Unexpected staged paths or concurrent scope drift → `BLOCKED`.

For `NEEDS-FOLLOW-UP`, commit only the owning plan's evidence-only Review Outcome
and Review Findings updates, the review record, backlog updates, and verified safe fixes; leave affected
implementation files and every plan move out. Record the base HEAD, exact reviewed
path manifest, and a cryptographic fingerprint of the uncommitted reviewed diff so
the evidence identifies its source state. For `BLOCKED`, make no automatic commit.

## 8. Output to the User

End the review with a compact, skimmable report:

1. **Scope** and owning plan (`N/A` when none). 2. **Tier** and outcome.
3. **Findings** table: severity, file:line, issue, status (fixed / open).
4. **Safe fixes** and gate results. 5. **Questions/decisions** recorded.
6. **Backlog/review record** updates. 7. **Plan transition** (or why it stayed).
8. **Commit** hash/message, `no changes to commit`, or the exact blocker.

## 9. Related Documents

- [`review-playbook.md`](../../../../engineering/review-playbook.md) — deep modes, packages, report templates.
- [`security.md`](../../../guidelines/security.md) · [`llm-do-dont-checklist.md`](../../../guidelines/llm-do-dont-checklist.md) · [`agent-spawning.md`](../../../guidelines/agent-spawning.md).
