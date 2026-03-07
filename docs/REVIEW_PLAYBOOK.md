# Review Playbook

## Purpose

This playbook defines a reusable, time-boxed review workflow for ChoreStrike.

It is designed to:
- catch high-impact issues quickly
- separate fast automated checks from deeper reasoning work
- fix clear issues immediately
- batch unclear questions for the end of the session
- keep code, tests, backlog, and documentation aligned

Use this document for:
- full-project reviews
- review of changes since a base commit
- pre-release quality sweeps
- module-focused audit sessions
- review handoff between different LLMs or humans

---

## Review Principles

- Keep each pass focused and time-boxed.
- Prefer several small review packages over one unbounded session.
- Fix clear, local issues immediately.
- Add or update regression tests for every bug fix.
- Do not derail the session with major refactors.
- Batch unclear questions and design decisions.
- Update `docs/backlog/ACTIVE_TASKS.md` at start, during progress, and on completion.
- Update the related backlog document when review findings change task status.
- Commit after each logical review package.

---

## Recommended Review Modes

| Mode | Duration | Use When | Output |
|------|----------|----------|--------|
| Rapid Delta Review | 15-25 min | Small change set, hotfix, doc sync, final sanity check | Short finding list, immediate clear fixes |
| Standard Batch Review | 45-75 min | Normal feature review, end-of-day review, release prep batch | Findings, fixes, targeted reruns, backlog updates |
| Deep Slice Review | 75-110 min | Security, architecture, inter-module drift, suspicious regressions | Root-cause analysis, focused fixes, backlog decisions |

### Mode Selection Rules

Use `Rapid Delta Review` when:
- the diff is narrow
- only one module is affected
- you mainly expect lint, test, or doc drift issues

Use `Standard Batch Review` when:
- frontend and backend both changed
- tests and docs must be checked together
- you want meaningful coverage without spending hours

Use `Deep Slice Review` when:
- the change touches authorization, data integrity, migrations, or routing
- module boundaries may be drifting
- orphaned or unwired functionality is suspected
- multiple documents disagree about what is current

Do not default to deep review for every task.
Reserve deep reasoning for ambiguity, security, architecture, and cross-module behavior.

---

## Review Surfaces

### Module Groups

| Module Group | Primary Goal | Typical Risks | Automation Level |
|--------------|--------------|---------------|------------------|
| Frontend Views and Components | UI correctness and user flows | stale wiring, untranslated text, style drift, broken affordances | Medium |
| Frontend Stores and Utils | state consistency and API contracts | payload drift, fallback bugs, deprecated APIs, logging violations | High |
| Backend Controllers | secure request handling | missing authorization, validation gaps, wrong status codes | High |
| Backend Services | business correctness | hidden coupling, duplicated logic, missing edge cases | Medium |
| Database and Migrations | data safety and upgrade stability | broken queries, missing indexes, migration incompatibility | Medium |
| Tests | coverage realism and execution health | orphaned tests, outdated assumptions, flaky selectors | High |
| Documentation | truthfulness and maintainability | stale architecture docs, outdated test instructions, backlog drift | High |
| Release and Ops | deployment readiness | missing checklists, environment drift, test infra mismatch | Medium |

### Document Types

| Document Type | Review Goal | Typical Review Questions |
|---------------|-------------|--------------------------|
| `AGENTS.md` | workflow compliance | Are current review steps aligned with agent rules? |
| `docs/backlog/ACTIVE_TASKS.md` | task coordination | Is the task status current and conflict-free? |
| `docs/backlog/phase-*.md` and `bugs.md` | backlog accuracy | Are findings reflected as tasks with correct priority? |
| `docs/ARCHITECTURE.md` | architecture truth | Does the implementation still match the documented boundaries? |
| `docs/API.md` and `docs/api/*` | contract truth | Do endpoints, payloads, and error semantics match reality? |
| `docs/TESTING.md` | test truth | Does it describe the current active suites and realistic coverage? |
| `docs/standards/*` | policy compliance | Are logging, security, assets, and coding standards respected? |
| ADRs in `docs/adr/*` | architectural history | Were important design changes documented or superseded? |
| release docs and checklists | release readiness | Are deployment and validation steps still valid? |

---

## Mandatory Focus Areas

Every substantial review should explicitly cover these areas.

### 1. Functional Correctness

Check:
- real user flows still work
- frontend actions call the correct backend routes
- data transformations match the live payload shape
- error states are handled consistently

Strong signals:
- broken API prefixing
- state initialization bugs
- TODO stubs on active paths
- view logic that assumes fields no longer returned by the backend

### 2. Style and UX Consistency

Check:
- visible labels and icons are understandable
- UI patterns match the current design direction
- no broken affordances or dead buttons remain in active screens
- translations are complete for user-facing text

Strong signals:
- active button with no handler
- inconsistent empty states or dialogs
- mixed iconography quality
- hardcoded strings instead of `t()`

### 3. Orphaned, Unwired, or Dead Items

Check:
- components referenced in docs are still wired into routes or views
- tests cover active UI, not legacy screens only
- old routes, stores, or endpoints are not silently abandoned
- legacy exceptions are documented when intentionally kept

Strong signals:
- components that exist but are not imported by any active view
- E2E suites targeting selectors not present in the live app
- documentation describing screens that no longer ship
- logging or lint exceptions limited to legacy folders

### 4. Architecture Boundaries

Check:
- controllers remain thin
- services hold business logic
- stores stay focused on client state and orchestration
- duplicate logic is not spreading across layers

Strong signals:
- validation duplicated in multiple layers without reason
- controller methods doing domain decisions
- frontend components containing complex API mapping logic
- stores becoming mixed UI and domain layers

### 5. Security and Authorization

Check:
- all new or changed endpoints enforce access control
- ownership and house membership checks are present
- client input is validated server-side
- no unsafe rendering or sensitive logging is introduced

Strong signals:
- controller methods accepting IDs without ownership validation
- payload fields trusted from the client when they should be resolved server-side
- raw `v-html`
- sensitive data leaking into logs or error responses

### 6. Inter-Module Collaboration

Check:
- routes match frontend consumers
- stores and components agree on field names
- controllers and services agree on invariants
- docs describe the live system, not a previous iteration

Strong signals:
- docs say one view is active while routing says another
- frontend expects endpoint names not present in `appinfo/routes.php`
- tests assert outdated selector names
- backlog tasks reference already-solved or no-longer-existing gaps

### 7. Tests and Coverage Realism

Check:
- every bug fix has a regression test
- test names stay behavior-oriented
- active user journeys have meaningful E2E or integration coverage
- test fixtures still match current data contracts

Strong signals:
- unit suites containing E2E-style syntax
- tests passing only because they target mocks instead of realistic integration points
- orphaned E2E files for removed UI
- unstable selectors without `data-testid` discipline

### 8. Documentation Sync

Check:
- architecture, testing, and backlog docs match implementation
- review findings are reflected in backlog state
- obsolete statements are corrected or clearly marked legacy
- newly introduced rules are written down where future reviewers will see them

### 9. Release Readiness

Check:
- build, lint, and core tests can run as documented
- migrations and deployment docs are current
- high-priority findings are separated from deferrable polish
- known limitations are explicit before release decisions

---

## Project-Specific ChoreStrike Risk Hotspots

Prioritize these because they have repeatedly produced drift or review value in this repository.

- Nextcloud-specific authorization and house membership checks in controllers and services
- frontend route-to-view wiring versus legacy or unwired component folders
- frontend store payload assumptions versus `appinfo/routes.php` and backend controller responses
- outdated testing assumptions compared with the currently shipped views and flows
- local-only asset policy and removal of remote runtime dependencies
- structured logging usage instead of raw production `console.*`
- i18n completeness through `t()` and synchronized l10n files
- migration compatibility and Docker-based integration environment drift

---

## Review Packages

Run these in order. Stop early only if a critical blocker appears.

### Package 0: Scope and Diff Mapping

Goal:
- define the review slice and avoid wandering

Actions:
- identify the base commit or task scope
- list touched modules and documents
- classify the review mode
- note likely high-risk areas before reading deeply

Outputs:
- review scope statement
- initial risk list

### Package 1: Automated Static Quality Sweep

Goal:
- catch cheap, high-signal issues first

Typical automation:
- ESLint for frontend files
- stylelint for styles
- PHP lint and coding standards
- targeted searches for `TODO`, `FIXME`, deprecated APIs, raw console usage, `v-html`, orphan selectors, or suspicious legacy paths

Best for:
- syntax errors
- logging violations
- style regressions
- deprecated API usage
- active-path TODOs

### Package 2: Wiring, Contracts, and Orphan Scan

Goal:
- verify that modules still connect correctly

Typical automation:
- search active routes, views, imports, and endpoint references
- compare docs versus live routing and component usage
- compare frontend API consumers with backend route definitions
- search tests for selectors or flows no longer present

Best for:
- orphaned components
- unwired views
- dead tests
- contract drift

### Package 3: Focused Test Validation

Goal:
- validate the highest-risk behavior with the smallest meaningful test set

Typical automation:
- changed unit tests first
- targeted regression suites for touched areas
- backend controller or service tests for security-sensitive changes
- E2E only for core flow validation or UI wiring uncertainty

Best for:
- preventing regressions from clear fixes
- separating local breakage from broad instability

### Package 4: Documentation and Backlog Sync

Goal:
- ensure the repo tells the truth after the review

Actions:
- update `ACTIVE_TASKS.md`
- update the related backlog file
- create or update `docs/backlog/CODE_REVIEW_YYYY-MM-DD.md` when the review is substantial
- correct stale documentation in the same package when the fix is obvious

### Package 5: Final Triage and Handoff

Goal:
- leave a clean, reusable result

Actions:
- mark fixed issues clearly
- separate deferred items from completed fixes
- batch unresolved questions
- provide the next recommended review package
- commit the package

---

## Automation Matrix

| Check | Default | Escalate to Deep Reasoning When |
|-------|---------|----------------------------------|
| Lint and formatting | Automated | a warning implies architectural or security drift |
| Search for TODO, FIXME, deprecated APIs, raw logging | Automated | the match is on an active production path |
| Route and endpoint mapping | Automated first | multiple sources disagree about the live contract |
| Orphaned component detection | Automated first | the component may still be intentionally staged for future use |
| Test selection | Semi-automated | realistic coverage is unclear or current suites look stale |
| Security review | Automated plus manual | authorization logic is indirect or spans several services |
| Documentation truth check | Semi-automated | docs disagree with each other and code |
| Architecture review | Manual with search support | boundary ownership is unclear or duplicated |

---

## LLM Role Split

### Fast Model or Standard Agent

Use for:
- repo mapping
- grep-driven inventories
- finding obvious mismatches
- drafting short review summaries
- suggesting low-risk clear fixes

Do not use as the only reviewer for:
- security-sensitive controller analysis
- cross-module architectural drift
- deciding whether something is truly orphaned or intentionally staged

### Deep Thinking Model

Use for:
- authorization and ownership analysis
- root-cause reasoning across controller, service, store, and tests
- reconciling conflicting documentation
- determining whether a feature is active, legacy, or partially migrated
- deciding whether to fix now or backlog for refactor

Do not spend deep-thinking budget on:
- routine lint cleanup
- obvious syntax issues
- straightforward test expectation updates
- simple documentation sync

### Coding Agent

Use for:
- implementing clear fixes immediately
- adding regression tests
- updating docs and backlog state
- packaging one logical unit into a commit

### Reviewer or Finalizer Agent

Use for:
- confirming that fixes address root cause
- checking that docs and backlog were updated
- ensuring open questions are batched and not lost
- deciding whether the package is done, review-ready, or blocked

---

## Immediate Fix Policy

Fix immediately when all of the following are true:
- the root cause is clear
- the change is local and low-risk
- expected behavior is already defined by existing code, tests, or standards
- a targeted test can confirm the fix

Examples:
- syntax errors
- wrong import or deprecated API replacement
- missing route prefix
- obvious stale test expectation
- missing authorization guard with known project pattern
- outdated docs that clearly contradict current implementation

Do not fix immediately when one of these is true:
- the correct UX is unclear
- the architecture choice is ambiguous
- the change would trigger a broad refactor
- a security fix changes semantics that need approval
- the issue may belong to intentionally unwired legacy scope

In those cases:
- log the finding
- add or update the backlog task
- batch the question for the end of the session

---

## Question Batching Policy

Do not interrupt the review for every uncertainty.
Accumulate questions and ask them together at the end.

Use this format:

1. What is the current intended status of `X`?
   - Option A: active and should be fixed now
   - Option B: legacy and should be removed or marked
   - Option C: staged for future work and should stay isolated

2. Which behavior is expected for `Y`?
   - Option A: preserve current behavior
   - Option B: align with documented behavior
   - Option C: create follow-up backlog task

Prefer 2-3 options per question.
Do not guess when the design intent is unclear.

---

## Time-Boxed Templates

### 30-Minute Quick Review

- 5 min: scope and touched files
- 10 min: static sweep and targeted searches
- 10 min: fix clear local issues and rerun smallest relevant checks
- 5 min: summarize, batch questions, update backlog state

### 60-Minute Standard Review

- 10 min: scope, risk map, active docs
- 15 min: static sweep
- 15 min: wiring and orphan scan
- 10 min: targeted tests
- 10 min: fixes, docs sync, final summary

### 90-Minute Deep Review

- 10 min: scope and risk framing
- 15 min: static sweep
- 20 min: security and architecture trace
- 15 min: inter-module contract trace
- 15 min: tests and documentation truth check
- 15 min: fix clear findings, backlog unresolved items, final report

If the review would exceed the time box, split it into separate packages instead of extending indefinitely.

---

## Deliverables Per Review Package

Every review package should leave behind the following artifacts.

### Required

- updated `docs/backlog/ACTIVE_TASKS.md`
- code or docs fixes for clear issues
- tests for bug fixes when code changed
- concise summary with completed work, remaining risks, and next steps

### Required for Substantial Reviews

- `docs/backlog/CODE_REVIEW_YYYY-MM-DD.md` or updated existing review document
- backlog update in the relevant `phase-*.md` or `bugs.md`
- commit for the logical package

### Optional

- ADR proposal when architecture intent changed
- standards update when a recurring review rule becomes explicit policy

---

## Reusable Review Checklist

Copy this section into a review document when needed.

### Scope

- [ ] Review mode selected
- [ ] Base commit or package scope defined
- [ ] High-risk modules listed
- [ ] Relevant docs identified

### Functional Review

- [ ] Active user flow still works
- [ ] Frontend and backend payloads align
- [ ] Error handling remains consistent
- [ ] No active-path TODO stubs remain unnoticed

### Style and UX Review

- [ ] UI labels and icons are understandable
- [ ] No dead buttons or broken affordances
- [ ] User-facing strings use `t()`
- [ ] Visual patterns remain consistent

### Orphan and Wiring Review

- [ ] Active views map to current routes
- [ ] No stale tests target removed UI
- [ ] Legacy components are clearly isolated or documented
- [ ] Frontend consumers match registered routes

### Architecture Review

- [ ] Controllers stay thin
- [ ] Services contain business logic
- [ ] Duplication is not spreading
- [ ] File and method sizes remain reasonable

### Security Review

- [ ] Authorization checks exist for changed endpoints
- [ ] Ownership is validated server-side
- [ ] Input validation is present
- [ ] No sensitive logging or unsafe rendering added

### Test Review

- [ ] Relevant unit tests pass
- [ ] Regression tests were added for bug fixes
- [ ] E2E or integration checks are realistic for active flows
- [ ] Fixtures and mocks still match the live contract

### Documentation Review

- [ ] `ACTIVE_TASKS.md` updated
- [ ] Related backlog file updated
- [ ] Review document created or updated when warranted
- [ ] Docs still describe the live system

### Completion

- [ ] Clear issues fixed
- [ ] Deferred issues moved to backlog
- [ ] Questions batched
- [ ] Logical package committed

---

## Recommended Default Sequence For ChoreStrike

For a balanced review that does not take hours, use this default sequence:

1. Run a `Standard Batch Review`.
2. Start with static and orphan scans.
3. Escalate only the suspicious areas to deep reasoning.
4. Fix obvious issues immediately.
5. Rerun only the smallest relevant test set.
6. Update task tracking and backlog.
7. End with batched questions and the next recommended package.

This gives the best trade-off between speed, coverage, and repeatability for the current project.
