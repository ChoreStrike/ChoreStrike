# ChoreStrike — Core Rules

> Canonical rule set for AI agents and contributors. `AGENTS.md` is the bootstrap; this file is the rules.
> **Project:** ChoreStrike — gamified household task management as a Nextcloud app. **License:** MIT (public).

---

## 1. Core Principles

### Integrity & Privacy
- User data privacy overrides convenience. Never log tokens, passwords, cookies, or full sensitive payloads.
- Stay within the **Nextcloud app ecosystem**. Don't introduce external services unless explicitly approved.
- Store personal/secret material only in `/private/` (gitignored). Never commit private content.

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

### Pre-Release Compatibility (until explicitly lifted)
- **Until the user declares the app productive (~V0.9), there is NO backward-compatibility requirement** — there is no release and no production database.
- Schema changes: only **fresh installs** must work. Adjust the existing migration chain instead of writing upgrade paths, backfills, or data-preservation code for hypothetical older installs. Test/dev databases are disposable (`down -v` + `up -d`).
- **No legacy shims, fallbacks, or deprecated-API wrappers** for older app versions — update the call sites and delete the old code instead of layering compatibility on top. Compat code this early only bloats the codebase and adds bug surface.
- Backward compatibility starts **only** when the user explicitly declares "ab jetzt abwärtskompatibel" (productive use). Until then, never add compat code "just in case".

## 2. Technical Requirements

### Nomenclature (consistent, lowercase camelCase in JS, PascalCase in PHP)
| Concept | Use |
|---|---|
| House / Floor / Room / Task / RoomTask | `house`, `floor`, `room`, `task`, `roomTask` |
| Identifiers | `houseId`, `floorId`, `roomId`, `taskId`, `userId` |
| Gamification | `sparks`, `combo`, `medal`, `houseHealth` |

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
- Every user-visible string uses `t()`. Update `l10n/` artifacts in the same change.
- See [`../../guidelines/i18n.md`](../../guidelines/i18n.md).

### Testing
- Every feature → test. Every bug fix → regression test.
- **No completion without tests:** a task or plan must never be marked done while tests required by the plan, the backlog item, or these rules are missing, skipped, or failing. Deferring a required test is only acceptable with an explicit backlog entry (reason + remaining risk) and user approval.
- PHPUnit (unit/integration), Jest (frontend), Playwright (E2E). BDD-style naming.
- See [`../../guidelines/testing.md`](../../guidelines/testing.md) and [`testing.md`](../../../engineering/testing.md).

### Test Environment (Docker)
- **Docker Desktop is installed and available on the development machine.** The `docker-compose.test.yml` environment is the canonical way to run backend and E2E tests.
- If a `docker` command fails, **diagnose before concluding Docker is absent**: start Docker Desktop and wait until `docker info` succeeds. Never declare "no Docker" from a single failed probe.
- First image builds take ~3–5 minutes — raise the shell timeout (≥ 300 s) or use a background process. A tool timeout is not a Docker failure.
- Never use the "Without Docker" fallback (`docs/engineering/testing.md`) on this machine — it exists only for machines without a Docker installation.
- The shell is PowerShell 5.1: use `$env:TEST_DB_PROFILE='family'; docker compose ...`, not bash `VAR=value cmd` syntax.

## 3. Error & Quality Management

- **Build gate:** a non-building tree must not be considered done. `npm test` + lint + backend tests green.
- **Lint/format before commit** (`npm run lint:fix`). Never ignore lint output.
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

- **`backlog.md`** — the **single source of truth for everything open**: bugs,
  features, tech-debt, and review follow-ups (themed sections). An item that is
  not done lives here and **only** here.
- **`ACTIVE_TASKS.md`** — **live coordination only**: work *in progress right
  now* + a rolling *Recently Completed* window. It is **not** a history log.
- **`archive/YYYY-MM.md`** — **tracked-in-Git** completion history, one file per
  month of resolution. (Distinct from the repo-root gitignored `archive/`.)
- **`archive/CODE_REVIEW_YYYY-MM-DD.md`** — review records kept verbatim.

### Backlog Rules (enforced)
- Non-trivial follow-ups, deferred fixes, and improvement ideas → a themed
  section in `backlog.md` (never a new loose file).
- On **completion**, move the item from `backlog.md` to `archive/YYYY-MM.md`
  (the month it was resolved) **immediately** — do not leave "Done" items in
  `backlog.md`, and do not let them accumulate in `ACTIVE_TASKS.md`.
- `ACTIVE_TASKS.md` entries older than **~2 weeks** or beyond the **~10-entry
  rolling cap** must be moved to `archive/YYYY-MM.md`. Keep *Recently Completed*
  entries to **one line** (full scope lives in the linked plan).
- A code-review's **open** findings go to `backlog.md`; the **review record**
  goes to `archive/`.
- Update `ACTIVE_TASKS.md` when starting, pausing, and finishing implementation
  or review work.
- Ambiguities → log them and ask; **don't guess** on irreversible decisions.
- Don't declare work done while blockers or open questions remain.

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

### Lifecycle & Manual Moves
- **New plans** are created in `docs/plans/new/`.
- **`new/ → active/`** is a **manual** move by the user when work begins. The AI must **not** auto-move unless explicitly instructed.
- **`active/ → done/`** is a **manual** move by the user when fully implemented and reviewed.
- **`active/ → onhold/`** when paused (e.g. blocked by a missing dependency).
- **`done/ → archive/YYYY-MM/`** (untracked) when a completed plan no longer serves as living documentation.
- Environment-specific worktree tools (e.g. Kilo Agent Manager) operate **independently** of `docs/plans/` — the plan Markdown files here are the canonical, tool-agnostic source of truth.

### Plan Review
- After plan creation, the AI **proposes a review** (mandatory for complex/multi-phase plans, optional for trivial fixes).
- The review checks: feasibility, risk coverage, acceptance criteria completeness, test-plan adequacy.
- See the [`plan-management`](../skills/plan-management/SKILL.md) skill and the plan [`template.md`](../../../plans/template.md).
