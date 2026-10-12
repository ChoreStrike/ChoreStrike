# ChoreStrike — Core Rules

> Canonical rule set for AI agents and contributors. `AGENTS.md` is the bootstrap; this file is the rules.
> **Project:** ChoreStrike — gamified household task management as a Nextcloud app. **License:** MIT (public).

---

## 1. Core Principles

### Integrity & Privacy
- User data privacy overrides convenience. Never log tokens, passwords, cookies, or full sensitive payloads.
- Stay within the **Nextcloud app ecosystem**. Don't introduce external services unless explicitly approved.
- Store personal/secret material only in `/private/` (gitignored). Never commit private content.

### Public Project Language
- Code, comments, documentation, and commit messages are **English only**. Chat with the user may use their language.

### Architecture (never violate)
```
Controllers (thin) → Services (business logic) → Mappers/Entities (persistence)
                              ↓
                        Nextcloud DB abstraction (IDBConnection / IQueryBuilder)
```
- Routes/controllers only validate input, call a service, and shape the response. **No business logic in controllers.**
- Services hold business logic. Mappers/entities handle persistence.
- Use prepared statements with named parameters. **Never concatenate SQL.**
- Inject dependencies via Nextcloud DI. Keep methods ~30 lines, files ~300 lines.

### Software Quality > Avoiding Findings
- Fix root causes, not symptoms. **Never suppress warnings** or swallow exceptions silently.
- Every lint warning, failing test, or build break is a defect — fix it now or log a backlog item with a deferral reason.
- CI / build must be green before considering work complete.

### KISS
- No premature abstraction (rule of three). Prefer existing patterns.
- Keep diffs minimal — no unrelated renames or formatting churn in feature work.

### Data-Retention Boundary (approved, not yet adopted)
- **Current state: fresh-install-only.** The approved future contract starts only
  when the operator explicitly adopts one baseline after WP9's technical gates,
  before entering real retained data. Plan approval, a green rehearsal, a version
  number or a release tag alone does not adopt it. No retroactive support for
  disposable development installs.
- Until adoption, adjust the existing migration chain for fresh installs; no
  speculative upgrade paths, backfills, no-op migrations or legacy shims. Only
  explicitly owned disposable test databases may be recreated (`down -v`).
- From adoption, preserve persisted data by default: setup/structure, task and
  checklist definitions/assignments, recurrence/occurrences/agreements/pauses,
  completions/work shares/corrections/journal, membership/preferences and
  financial/reward facts. Scores/awards are separable but their reset still
  requires an explicit future scoped decision; derived streak/title rows are
  not independent history. This does not prevent authorized domain corrections,
  deletion or privacy erasure under their documented contracts.
- Already-applied migration files become immutable. Future schema changes use
  new ordered steps, populated-baseline upgrade tests and fresh/upgrade parity
  on all supported providers. Do not silently reset facts or add hypothetical
  older-version compatibility wrappers.
- Record explicit adoption and exact build/runtime/migration identity using
  [`deployment.md`](../../../engineering/deployment.md#first-retained-baseline).
  Whole-instance recovery and other-app rollback impact require operator
  approval; a code downgrade is not a schema rollback.
- Operator-designated production facts are not disposable while that inventory
  is being completed. The 2026-10-11 preservation request forbids resetting or
  reseeding the existing household and changing its applied migrations; resolve
  the installed identity and qualify populated updates instead. For ordinary
  deployment/adoption, a current completed backup and operator confirmation of
  the established tested recovery procedure suffice; no repeated operator restore
  test is required. Keep app upgrade-preservation tests separate from backup tests.

## 2. Technical Requirements

### Nomenclature (consistent, lowercase camelCase in JS, PascalCase in PHP)
| Concept | Use |
|---|---|
| House / Floor / Room / Task / RoomTask | `house`, `floor`, `room`, `task`, `roomTask` |
| Identifiers | `houseId`, `floorId`, `roomId`, `taskId`, `userId` |
| Gamification | `coins`, `combo`, `medal`, `houseHealth` |

### Authorization & Ownership
- All endpoints use `@NoAdminRequired` (authenticated user). **Never `@PublicPage`.**
- Every mutation validates **house membership**; owner-only operations use `requireHouseOwner()`.
- Validate all client input at the controller boundary. **Never trust client input.**
- See [`../../guidelines/security.md`](../../guidelines/security.md).

### Logging
- Backend: `AppLogger`. Frontend: `src/utils/logger.js`. **No raw `console.*`** in production code.
- Structured, short, stable, English messages with stable context keys (`event`, `controller`, `houseId`, …).
- See [`../../guidelines/logging.md`](../../guidelines/logging.md).

### Internationalization
- Every user-visible domain/UI string uses `t()`. Update `l10n/` artifacts in the same change. The sanitized generic `ControllerErrorFormatter::safeMessage()` fallback is the sole documented exception (see `i18n.md` D2); do not introduce additional exceptions.
- See [`../../guidelines/i18n.md`](../../guidelines/i18n.md).

### Testing
- Every feature → test. Every bug fix → regression test.
- **No completion without tests:** a task or plan must never be marked done while tests required by the plan, the backlog item, or these rules are missing, newly/unapproved skipped, or failing. A deferral may pause or explicitly rescope a plan with user approval, but a still-required deferred test always blocks `done/`.
- PHPUnit (unit/integration), Jest (frontend), Playwright (E2E). BDD-style naming.
- See [`../../guidelines/testing.md`](../../guidelines/testing.md) and [`testing.md`](../../../engineering/testing.md).

### Test Environment (Docker)
- `docker/compose.test.yml` is the canonical backend/E2E environment. Docker Desktop **is installed**; if a `docker` probe fails, diagnose (`docker info`, start Docker Desktop) — never conclude Docker is absent, never use the "Without Docker" fallback on this machine.
- First image builds take ~3–5 min — raise the shell timeout (≥ 300 s) or use a background process; a tool timeout is not a Docker failure.
- Shell is PowerShell 5.1: `$env:TEST_DB_PROFILE='family'; docker compose ...`, not bash `VAR=value cmd`.

## 3. Error & Quality Management

- **Build gate:** a non-building tree must not be considered done. `npm test` + lint + backend tests green.
- **Lint/format before commit:** run `npm run lint:fix` only when its write scope contains no unrelated dirty source files; otherwise use scoped fixers and repository-wide check-only lint. Never ignore lint output.
- **No silent workarounds.** A temporary workaround is acceptable only when: (a) a backlog item exists with a deferral reason, (b) an inline `// HACK: [reason]` comment is present, (c) the root cause stays traceable.
- **Parameter validation** at all public boundaries.
- **No stack traces or internal details** in production error responses — only error codes and messages.

## 4. Prioritization & Backlog

### Priority Order
1. User privacy and data integrity
2. Security (authorization, ownership, input validation)
3. Correctness and architecture boundaries
4. KISS, minimal diffs, reuse of existing patterns
5. Documentation clarity and multi-agent handoff quality

### Backlog Structure & Discipline
`docs/backlog/` has a fixed layout — do **not** re-introduce ad-hoc splits like
`bugs.md` / `features.md` / `phase-*.md`. See [`docs/backlog/README.md`](../../../backlog/README.md).

- **`backlog.md`** — the **single source of truth for everything open that is
  not owned by an active plan**: bugs, features, tech-debt, and review
  follow-ups (themed sections). An open item lives here and **only** here —
  except plan-owned findings, which live in their owning plan's
  `## Review Findings` checklist until resolved (see Finding Routing below).
- **`ACTIVE_TASKS.md`** — **live coordination only**: work *in progress right
  now* + a rolling *Recently Completed* window. It is **not** a history log.
- **`archive/YYYY-MM.md`** — **tracked-in-Git** completion history, one file per
  month of resolution. (Distinct from the repo-root gitignored `archive/`.)
- **`archive/CODE_REVIEW_YYYY-MM-DD.md`** — review records kept verbatim.

### Finding Routing (scope-first, enforced)

Every finding from an implementation round or a review is routed in this order —
**before** any new backlog item is created:

1. **Fix now** — safely fixable in the current round (review skill §6 auto-fix
   rules) → fix directly; no item anywhere.
2. **Plan-owned** — the finding lies **within the scope/domain of the owning
   plan** (or the current implementation task) but cannot be fixed safely right
   now → record it in the plan's `## Review Findings` checklist and resolve it
   in the current or next round of that plan. It blocks the plan's `done/`
   move; moving it to the backlog instead requires explicit user rescoping.
   **No new backlog item.**
3. **Fold-in** — the finding duplicates or extends an **existing open backlog
   item** → update that item (record the merge in its `Folded-in` field);
   never create a parallel item.
4. **Backlog** — only findings **outside** the owning plan's scope (other
   modules/domains, cross-cutting work needing its own effort) or findings
   without an owning plan → a themed section in `backlog.md`.

Goal: plans and their findings converge and close; `backlog.md` grows only from
genuinely out-of-scope discoveries, not from the plan's own working surface.
Exception: `// HACK:` workarounds always need a `backlog.md` follow-up (durable
debt that must outlive the plan).

### Backlog Rules (enforced)
- Non-trivial follow-ups, deferred fixes, and improvement ideas → routed per Finding Routing above; anything that reaches step 4 goes to a themed section in `backlog.md` (never a new loose file).
- Potentially exploitable, unremediated security details stay in `/private/` and are never automatically written or committed to public review/backlog files. Add only a sanitized public placeholder after explicit disclosure approval.
- On **completion**, move the item from `backlog.md` to `archive/YYYY-MM.md` (the month it was resolved) **immediately**.
- `ACTIVE_TASKS.md` is rolling: entries older than **~2 weeks** or beyond the **~10-entry cap** move to `archive/`; *Recently Completed* entries are **one line**. Update it when starting, pausing, and finishing implementation or review work.
- A code-review's **open** findings are routed per Finding Routing (plan-owned / fold-in / `backlog.md`); the **review record** goes to `archive/`.
- Ambiguities → log them and ask; **don't guess** on irreversible decisions. Don't declare work done while blockers or open questions remain.

## 5. Archive Policy (`archive/`)

### Purpose
- `archive/` keeps obsolete/deprecated content for **local historical reference**. It is **gitignored** — nothing here is tracked or shipped.
- Prefer archiving over deleting: when you remove a doc/script/config from the repo, move it to `archive/` first.

### Rules
- **Preserve** archived content — don't delete it. It exists for reference.
- **No** test data, build artifacts, coverage, or logs in `archive/` — remove those entirely (`.gitignore` / `git rm`).
- **Subdirectory format:** `archive/YYYY-MM/<descriptive-name>/` with an optional `README.md`.
- **Never commit** files from `archive/`. If a tracked file is being archived, `git rm --cached` it (the local copy stays).

## 6. Private Content (`private/`)

- Personal, business, or secret material lives **only** in `/private/` (gitignored).
- Never reference private content from tracked docs in a way that leaks it.
- For a public MIT project, assume everything outside `/private/` and `archive/` will be public.

## 7. Plan Management Workflow (`docs/plans/`)

### Folder Structure
```
docs/plans/
├── new/       # Drafted plans, not yet started (tracked in Git)
├── active/    # Plans currently being implemented (tracked in Git)
├── onhold/    # Plans paused but still relevant (tracked in Git)
└── done/      # Completed plans kept for reference (tracked in Git)
```

### Lifecycle & Review-Driven Moves
- Plans are created in `docs/plans/new/`. Successful lifecycle reviews own the normal forward transitions; unrelated transitions remain explicit user actions.

| Move | When |
|------|------|
| `new/ → active/` | A Standard A plan review is `PASS` or `PASS-WITH-FIXES`; the agent updates status/tracking, moves the plan, and commits the review package |
| `active/ → done/` | A final Standard B or Full review passes **and** the whole plan, required tests, acceptance criteria, plan-owned `## Review Findings`, and durable-doc integration are complete; the agent moves and commits it |
| `active/ → onhold/` | The user explicitly pauses blocked work |
| `done/ → archive/YYYY-MM/` (untracked) | The user explicitly retires a historical plan that no longer needs to remain public; repair tracked links first |

- A passing **interim** code review commits its reviewed package but leaves an incomplete plan in `active/`.
- A Standard A review with open findings leaves the plan in `new/`. A code/full review with open findings commits only its review evidence, plan finding (`## Review Findings`) and backlog updates, and verified safe fixes; an affected plan stays `active/`.
- Any unresolved non-sensitive finding yields `NEEDS-FOLLOW-UP` and blocks a plan transition. An unanswered question, ambiguous owning plan, failed/missing required gate, newly skipped required test, or potentially exploitable unremediated security detail yields `BLOCKED` and stops review finalization/commit. Unresolved `block`, `major`, and security findings must remain explicit; only sanitized review evidence may commit under the `NEEDS-FOLLOW-UP` rules. Ask all review questions together through the available structured question mechanism, provide 2–3 concrete options with one recommendation, record the answers, then resume finalization.
- Requesting a plan/code review authorizes exactly one scoped conventional commit on the currently checked-out branch. An explicit read-only or no-commit instruction overrides this authorization and disables lifecycle moves. Do not create or switch branches, amend, push, or open a PR unless the user separately requests it. Never create an empty commit or include unrelated working-tree/index changes.

- Environment-specific worktree tools (e.g. Kilo Agent Manager) operate **independently** of `docs/plans/` — the plan Markdown files here are the canonical, tool-agnostic source of truth.

### Plan Review
- After plan creation, the AI **proposes a review** (mandatory for complex/multi-phase plans, optional for trivial fixes).
- The review checks: feasibility, risk coverage, acceptance criteria completeness, test-plan adequacy.
- A passing review records its outcome and resolved decisions in the plan before the automatic `new/ → active/` transition and commit.
- See the [`plan-management`](../skills/plan-management/SKILL.md) skill and the plan [`template.md`](../../../plans/template.md).

### Plans Are Temporary — Knowledge Integration
- Plans are **temporary working documents**: they coordinate implementation and are **not living documentation**. A plan must never remain the only place where a concept, decision, or behavior is described.
- On completion (before the final review may perform the `active/ → done/` move), integrate everything durable into the permanent docs:
  - Concepts, product & design intent → [`docs/concepts/`](../../../concepts/)
  - Architecture decisions → [`docs/adr/`](../../../adr/) (one ADR per decision)
  - System / layer behavior → [`docs/engineering/`](../../../engineering/)
  - API contracts → [`docs/api/`](../../../api/)
  - Rules & conventions → `docs/standards/`
- The plan file in `done/` is kept only as a historical implementation record. The canonical description of *how things work* always lives in the permanent docs above.
- Missing integration is treated like missing docs: the plan is **not fully done** until its durable outcomes are integrated.
