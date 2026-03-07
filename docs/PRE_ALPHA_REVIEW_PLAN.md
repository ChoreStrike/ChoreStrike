# Pre-Alpha Complete Review Plan

## Purpose

This document prepares the complete review pass that should happen shortly before tagging the first `v0.8.x` alpha release.

It turns the general review process from `docs/REVIEW_PLAYBOOK.md` into a concrete pre-alpha execution plan for ChoreStrike.

Use this review to answer one question:

**Is the current build safe and coherent enough for personal real-world alpha usage on a private Nextcloud instance?**

---

## Review Outcome Target

The pre-alpha complete review should produce one of these outcomes:

- **Promote to Alpha Candidate**
  - No open `P0` issue remains.
  - No known data-loss risk remains.
  - Any open `P1` issue is explicitly accepted and documented.
- **Hold and Fix**
  - At least one blocking issue remains in core workflows.
- **Split and Continue**
  - The codebase is close, but one or more review packages must be completed before alpha tagging.

---

## Scope

This is a full-project review focused on the code and documentation that matter for `v0.8 Alpha`.

### In Scope

- active frontend views, components, stores, and utilities
- active backend controllers, services, mappers, and entities
- migrations and installation-sensitive code paths
- core automated tests and realistic release-gate coverage
- alpha-critical documentation
- release-readiness gaps that would affect internal real-world use

### Out of Scope

- future App Store packaging requirements
- broad ecosystem compatibility guarantees beyond the personal alpha setup
- speculative refactors that are not needed to make alpha safe
- unfinished future-facing features that are clearly not part of the alpha core flow

---

## Alpha Core Flows That Must Be Reviewed

The complete review must explicitly confirm these flows.

### Core Household Setup

- house access and initial landing experience
- floor creation
- room creation
- room navigation

### Task Management

- task creation
- task editing where supported
- task assignment and room-task linking
- task completion and state updates
- checklist-related flows on active task paths

### Scheduling

- availability settings
- energy budget handling
- daily task display and actions
- core scheduling views and API flows

### Multi-User And Shared Household Behavior

- current active multi-user user interface paths
- membership-sensitive data loading and mutations
- role-sensitive backend behavior where applicable

### Stability And Operations

- app boot and frontend mount
- route wiring
- install and migration safety assumptions
- logging behavior in supported paths
- documentation truth for active workflows

---

## Known Project-Specific Review Risks

Prioritize these risks because they have already shown review value in ChoreStrike.

- active-path authorization and house membership validation
- drift between frontend consumers and backend routes
- outdated tests or docs describing unwired legacy UI
- orphaned or partially migrated multi-user components
- migration or Docker environment drift hiding real integration risk
- regression in local-only asset handling
- release docs that overstate current readiness
- stale assumptions in testing documentation and legacy E2E suites

### Important Current Assumption

Do not treat unwired `UserManagement` components or their legacy E2E tests as release-gate coverage unless they are first revalidated against the live app.

For pre-alpha, focus on currently wired views and the active room, task, scheduling, and realistic GUI flows.

---

## Recommended Review Mode

Use a **split complete review** instead of one giant unbounded session.

### Recommended Structure

- **Session A**: Static, wiring, and orphan scan
- **Session B**: Security, architecture, and inter-module review
- **Session C**: Focused validation, documentation sync, and alpha decision

### Recommended Time Box

- `Session A`: 60-90 minutes
- `Session B`: 60-90 minutes
- `Session C`: 45-75 minutes

Total target: **approximately 3 to 4 hours split across packages**, not a single exhausting run.

---

## Execution Sequence

## Session A: Static Quality, Wiring, and Orphan Scan

### Goal

Find cheap but high-signal issues before spending time on deep reasoning.

### Packages

#### Package A1: Scope Mapping

- define the base commit or candidate build
- list active modules likely to matter for alpha
- list release-relevant docs
- identify likely blocker areas before touching code

#### Package A2: Static Quality Sweep

Focus:
- frontend lint status
- backend lint and PSR-12 status
- active-path TODOs or FIXME markers
- deprecated APIs
- raw production logging
- unsafe rendering

Expected outputs:
- list of cheap clear fixes
- list of suspicious files needing deeper review

#### Package A3: Wiring And Orphan Scan

Focus:
- routes versus active views
- backend routes versus frontend consumers
- current UI versus stale docs
- current UI versus stale E2E suites
- legacy components that should not be treated as alpha-critical

Expected outputs:
- confirmed active release-gate flows
- explicit list of excluded legacy or orphaned items
- backlog items for ambiguous legacy paths

### Alpha Blockers For Session A

Block alpha if any of the following are found and confirmed:
- app-breaking syntax or boot issue
- active core flow wired to a missing backend route
- major docs-to-code mismatch that invalidates the planned validation path
- critical stale test assumption hiding lack of real coverage for a core flow

---

## Session B: Security, Architecture, and Inter-Module Review

### Goal

Verify that the active alpha surface is safe, coherent, and maintainable enough for personal real-world usage.

### Packages

#### Package B1: Security And Authorization Trace

Focus:
- ownership checks on active mutations
- house membership validation
- server-side validation of IDs and payloads
- sensitive data handling in logs and responses
- checklist and task mutation paths

Use deep reasoning here.

#### Package B2: Architecture Boundary Review

Focus:
- thin controllers
- business logic in services
- duplication between stores, components, controllers, and services
- unsupported complexity growth in core files

#### Package B3: Inter-Module Contract Review

Focus:
- field naming consistency between backend and frontend
- error semantics alignment
- task and room payload invariants
- scheduling-related contract consistency
- documentation truth for active paths

### Alpha Blockers For Session B

Block alpha if any of the following are found and confirmed:
- active unauthorized mutation path
- server trusting client input that must be resolved server-side
- core workflow broken by cross-module contract drift
- architecture drift causing high regression risk in a core supported flow

---

## Session C: Validation, Documentation Sync, and Release Decision

### Goal

Confirm the smallest meaningful set of automated and manual evidence needed for a pre-alpha go or hold decision.

### Packages

#### Package C1: Focused Automated Validation

Minimum validation targets should include:
- targeted frontend unit tests for touched or suspicious core flows
- targeted backend unit tests for security-sensitive or contract-sensitive paths
- realistic active-flow E2E coverage where available
- integration coverage if the environment is working

#### Package C2: Documentation Truth Pass

Review and update:
- `docs/backlog/ACTIVE_TASKS.md`
- `docs/backlog/phase-8-release-preparation.md`
- `docs/backlog/CODE_REVIEW_YYYY-MM-DD.md`
- `docs/TESTING.md` when live coverage assumptions changed
- any alpha validation note or known-issues note

#### Package C3: Alpha Decision

Create a short decision summary:
- candidate build or branch
- validated workflows
- blockers found
- accepted known risks
- promote, hold, or defer decision

### Alpha Blockers For Session C

Block alpha if any of the following remain unresolved:
- open `P0`
- known data-loss risk
- unvalidated core flow required by the alpha gate
- no clear validation note for the candidate build

---

## Automation Package Recommendations

These checks should be prepared before the review begins.

### Automated First Pass

- frontend lint for active supported code paths
- PHP lint and PSR-12 checks for backend and tests
- targeted searches for:
  - `TODO`
  - `FIXME`
  - deprecated APIs
  - raw `console.` usage outside approved exceptions
  - `v-html`
  - suspicious legacy component references
  - stale endpoint references

### Semi-Automated Mapping

- route-to-view mapping
- frontend API consumer to backend route mapping
- selector usage in active E2E files versus wired UI
- docs mentioning views or modules no longer wired

### Deep Review Focus

Reserve deep reasoning for:
- authorization and ownership analysis
- ambiguous orphaned items
- inter-module contract drift
- stale documentation with unclear source of truth

---

## Immediate Fix Policy For Pre-Alpha Review

Fix immediately when:
- the issue affects a core alpha flow
- the root cause is clear
- the change is local and low-risk
- a targeted regression test can validate the change

Examples:
- missing route prefix
- stale test expectation on an active flow
- deprecated API replacement
- missing authorization guard with an established project pattern
- documentation contradiction that would mislead alpha validation

Defer when:
- the item is legacy or unwired and not alpha-critical
- the correct UX is unclear
- the fix would expand into a major refactor
- the change belongs to post-alpha polish rather than alpha safety

---

## Output Artifacts

The pre-alpha complete review should leave behind all of the following.

### Required

- updated `docs/backlog/ACTIVE_TASKS.md`
- updated `docs/backlog/phase-8-release-preparation.md`
- a dedicated `docs/backlog/CODE_REVIEW_YYYY-MM-DD.md`
- fixes and regression tests for clear issues
- a short alpha validation note
- a final promote/hold/defer decision

### Recommended Alpha Validation Note Format

```markdown
# Alpha Validation Note

- Candidate: [version or branch]
- Review date: YYYY-MM-DD
- Validated workflows:
  - [workflow]
  - [workflow]
- Blocking issues:
  - [issue or none]
- Accepted risks:
  - [risk or none]
- Decision:
  - [Promote | Hold | Defer]
- Next required action:
  - [action]
```

---

## Review Session Start Template

```markdown
# Pre-Alpha Review Session Start

- Review mode: Deep Slice Review
- Review target: Pre-alpha complete review
- Candidate build: [tag, branch, or commit]
- Session: [A | B | C]
- Primary focus:
  - [focus]
  - [focus]
- Intended alpha gate checks:
  - [gate]
  - [gate]
- Known open risks before start:
  - [risk]
  - [risk]
```

---

## Final Pre-Alpha Decision Checklist

Do not promote the alpha candidate until all of these are true.

- [ ] No open `P0` issue exists
- [ ] No known data-loss issue exists
- [ ] Core flows were reviewed and validated
- [ ] Active release-gate tests are realistic for currently wired functionality
- [ ] Legacy or orphaned paths are excluded or documented explicitly
- [ ] Security-sensitive active paths were reviewed
- [ ] Documentation reflects the live app and current alpha scope
- [ ] Open `P1` issues are explicitly accepted and documented if the candidate is still promoted
- [ ] A short alpha validation note exists
- [ ] The final decision is recorded as promote, hold, or defer

---

## Suggested Order Of Work Right Before Alpha

1. Run `Session A` and fix clear active-path issues.
2. Run `Session B` and resolve or explicitly document any blocker-level security or contract issue.
3. Run `Session C` with the smallest meaningful validation set.
4. Document accepted risks.
5. Record the alpha decision.
6. Tag only after the validation note exists.

This sequence is intended to keep the pre-alpha review comprehensive but still realistically finishable.
