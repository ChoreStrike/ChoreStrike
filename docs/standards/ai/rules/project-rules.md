# ChoreStrike — Core Rules

> Canonical rule set for AI agents and contributors. `AGENTS.md` is the bootstrap; this file is the rules.
> Skills and guidelines elaborate these rules; on conflict this file wins and the other document is a defect to fix.

---

## 1. Core Principles

### Integrity & Privacy
- User data privacy overrides convenience. Never log tokens, passwords, cookies, or full sensitive payloads.
- Stay within the **Nextcloud app ecosystem**. No external services unless explicitly approved.
- Personal/secret material lives only in `/private/` (gitignored). Never commit it or reference it in a way that leaks it; assume everything outside `/private/` and `archive/` is public (MIT).

### Public Project Language
- Code, comments, documentation, and commit messages are **English only**. Chat with the user may use their language.

### Architecture (never violate)
```
Controllers (thin) → Services (business logic) → Mappers/Entities (persistence)
                              ↓
                        Nextcloud DB abstraction (IDBConnection / IQueryBuilder)
```
- Controllers only validate input, call a service, and shape the response. **No business logic in controllers.**
- Prepared statements with named parameters. **Never concatenate SQL.**
- Nextcloud DI for dependencies. Methods ~30 lines, files ~300 lines.

### Software Quality > Avoiding Findings
- Fix root causes, not symptoms. **Never suppress warnings** or swallow exceptions silently.
- Every lint warning, failing test, or build break is a defect — fix it now or route it per §4.

### KISS
- No premature abstraction (rule of three). Prefer existing patterns.
- Minimal diffs — no unrelated renames or formatting churn.

### Data-Retention Boundary (approved, not yet adopted)
- **Current state: fresh-install-only.** The approved future contract starts only when the operator explicitly adopts one baseline after WP9's technical gates, before real retained data. Plan approval, a green rehearsal, a version number or a release tag alone does not adopt it. No retroactive support for disposable development installs.
- **Until adoption:** adjust the existing migration chain for fresh installs; no speculative upgrade paths, backfills, no-op migrations or legacy shims. Only explicitly owned disposable test databases may be recreated (`down -v`).
- **From adoption:** preserve persisted data by default — setup/structure, task and checklist definitions/assignments, recurrence/occurrences/agreements/pauses, completions/work shares/corrections/journal, membership/preferences, financial/reward facts. Scores/awards are separable, but resetting them still needs an explicit scoped decision; derived streak/title rows are not independent history. Authorized domain corrections, deletion and privacy erasure follow their documented contracts.
- Applied migration files become immutable. Schema changes use new ordered steps with populated-baseline upgrade tests and fresh/upgrade parity on all supported providers. No silent fact resets, no hypothetical older-version wrappers.
- Adoption record, exact build/runtime/migration identity, recovery and rollback approval: [`deployment.md`](../../../engineering/deployment.md#first-retained-baseline). A code downgrade is not a schema rollback.
- **Existing production facts are protected now** (operator request 2026-10-11): never reset or reseed the existing household or change its applied migrations; resolve the installed identity and qualify populated updates instead. Ordinary deployment/adoption needs a current completed backup plus operator confirmation of the established recovery procedure — no repeated operator restore test. Keep app upgrade-preservation tests separate from backup tests.

## 2. Technical Requirements

### Nomenclature (camelCase in JS, PascalCase in PHP)
| Concept | Use |
|---|---|
| House / Floor / Room / Task / RoomTask | `house`, `floor`, `room`, `task`, `roomTask` |
| Identifiers | `houseId`, `floorId`, `roomId`, `taskId`, `userId` |
| Rewards | `wallet`, exact minor units (`energyMinor`, `coinMinor`), `medal`, `houseHealth` — combos/multipliers are retired (WP3, ADR-014) |

### Authorization & Ownership
- All endpoints use `@NoAdminRequired`. **Never `@PublicPage`.**
- Every mutation validates **house membership** (enforced in the service too); owner-only operations use `requireHouseOwner()`.
- Validate all client input at the controller boundary. **Never trust client input.** Detail: [`security.md`](../../guidelines/security.md).

### Logging
- Backend `AppLogger`, frontend `src/utils/logger.js`. **No raw `console.*`** in production code.
- Short, stable, English messages with stable context keys. Detail: [`logging.md`](../../guidelines/logging.md).

### Internationalization
- Every user-visible domain/UI string uses `t()`; update `l10n/` artifacts in the same change. The sanitized generic `ControllerErrorFormatter::safeMessage()` fallback is the sole documented exception (`i18n.md` D2). Detail: [`i18n.md`](../../guidelines/i18n.md).

### Testing
- Every feature → test. Every bug fix → regression test. PHPUnit (unit/integration), Jest (frontend), Playwright (E2E).
- **No completion without tests:** never mark a task or plan done while required tests are missing, newly/unapproved skipped, or failing. A deferral may pause or (with user approval) rescope a plan, but a still-required deferred test always blocks `done/`.
- Detail: [`testing.md`](../../guidelines/testing.md).

### Test Environment (Docker)
- `docker/compose.test.yml` is the canonical backend/E2E environment. Docker Desktop **is installed**: if a probe fails, run `docker info` and start Docker Desktop — never conclude Docker is absent or use the "Without Docker" fallback.
- First image builds take ~3–5 min: use a timeout ≥ 300 s or a background process; a tool timeout is not a Docker failure.
- Env vars: PowerShell `$env:TEST_DB_PROFILE='family'; docker compose ...`; Bash `TEST_DB_PROFILE=family docker compose ...`. Commands: [`docker-dev`](../skills/docker-dev/SKILL.md).

## 3. Error & Quality Management

- **Build gate:** `npm test` + `npm run lint` + backend tests (Docker) green; a non-building tree is never done.
- **Lint/format before commit:** `npm run lint:fix` only when its write scope has no unrelated dirty source files; otherwise scoped fixers plus check-only lint. Never ignore lint output.
- **No silent workarounds.** A temporary workaround needs (a) a `backlog.md` item with a deferral reason, (b) an inline `// HACK: [reason]`, (c) a traceable root cause.
- Validate parameters at all public boundaries. **No stack traces or internal details** in error responses.

## 4. Prioritization & Backlog

**Priority order:** 1. privacy & data integrity · 2. security · 3. correctness & architecture boundaries · 4. KISS/minimal diffs/reuse · 5. documentation & handoff quality.

### Backlog Structure
Fixed layout ([`docs/backlog/README.md`](../../../backlog/README.md)); never re-introduce `bugs.md` / `features.md` / `phase-*.md` splits.
- **`backlog.md`** — single source of truth for everything open that no plan owns (themed sections).
- **`ACTIVE_TASKS.md`** — live coordination only: in progress now + a rolling *Recently Completed* window (one line each, ~2 weeks / ~10 entries, then to the archive). Update on start, pause, finish.
- **`archive/YYYY-MM.md`** — tracked completion history; move resolved items there **immediately**. `archive/CODE_REVIEW_YYYY-MM-DD.md` keeps review records.

### Finding Routing (scope-first, enforced)
Route every implementation or review finding in this order — before creating any backlog item:
1. **Fix now** — safely fixable this round (review skill §6) → fix; no item anywhere.
2. **Plan-owned** — inside the owning plan's (or current task's) scope but not safely fixable now → the plan's `## Review Findings` checklist; it blocks `done/`; moving it to the backlog needs explicit user rescoping.
3. **Fold-in** — duplicates/extends an open backlog item → update that item (`Folded-in` field); never a parallel item.
4. **Backlog** — only out-of-scope findings or findings without an owning plan → themed section in `backlog.md`.

Exception: `// HACK:` workarounds always get a `backlog.md` follow-up.

### Backlog Rules
- Potentially exploitable, unremediated security details stay in `/private/`; never write or commit them to public files. A sanitized public placeholder needs explicit disclosure approval.
- Ambiguities → ask; **don't guess** irreversible decisions. Never declare done while blockers or open questions remain.

## 5. Archive Policy (`archive/`)

- Repo-root `archive/` is **gitignored** local reference for obsolete content. Prefer archiving over deleting: move removed docs/scripts/configs to `archive/YYYY-MM/<descriptive-name>/` (optional `README.md`); `git rm --cached` tracked files first.
- Preserve archived content. No test data, build artifacts, coverage, or logs there. Never commit from `archive/` (the tracked `docs/backlog/archive/` is different).

## 6. Private Content (`private/`)

See §1 Integrity & Privacy. Never reference private content from tracked docs in a way that leaks it.

## 7. Plan Management Workflow (`docs/plans/`)

Folders: `new/` (drafted) → `active/` (implementing) → `done/` (completed record); `onhold/` (paused). All tracked. Procedure: [`plan-management`](../skills/plan-management/SKILL.md); template: [`template.md`](../../../plans/template.md).

| Move | When |
|------|------|
| `new/ → active/` | Standard A plan review `PASS` / `PASS-WITH-FIXES`; the agent updates status/tracking, moves, and commits the review package |
| `active/ → done/` | Final Standard B or Full review passes **and** the whole plan, required tests, acceptance criteria, `## Review Findings`, and durable-doc integration are complete |
| `active/ → onhold/` | User explicitly pauses blocked work |
| `done/ → archive/YYYY-MM/` (untracked) | User explicitly retires a historical plan; repair tracked links first |

- Interim passing reviews commit their package but leave the plan in `active/`; reviews with open findings never move a plan. Outcomes (`PASS`, `PASS-WITH-FIXES`, `NEEDS-FOLLOW-UP`, `BLOCKED`) and finalization: [`review`](../skills/review/SKILL.md) skill.
- **Commit authorization:** requesting a plan/code review authorizes exactly one scoped conventional commit on the current branch ([`git-workflow`](../skills/git-workflow/SKILL.md#automatic-review-commit)). An explicit read-only/no-commit instruction disables edits, moves, and commits. All other commits, branches, amends, pushes, and PRs need an explicit user request.
- After creating a plan, propose a Standard A review (mandatory for complex/multi-phase plans).

### Plans Are Temporary — Knowledge Integration
A plan is a temporary working document, never the only home of a concept, decision, or behavior. Before `active/ → done/`, integrate durable outcomes: concepts → `docs/concepts/`, decisions → `docs/adr/`, system behavior → `docs/engineering/`, API contracts → `docs/api/`, rules → `docs/standards/`. Missing integration means the plan is not done.
