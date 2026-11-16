# Code Review Record — 2026-11-16

## Standard B (closing) - CS-PLAN-090 Simplified Core Release A

**Outcome:** `PASS-WITH-FIXES`. Closing review; the plan moves to `done/` without a deployment
of its own (D-090-81).

### Scope and capture

- Tier: Standard B. The whole plan had its final Full review on 2026-11-15 (four reviewers,
  `PASS-WITH-FIXES`). This review covers what changed after it and the plan's completion.
  Owning plan given explicitly: `docs/plans/active/cs-plan-090-simplified-core-release-a.md`.
- Base HEAD `0638216a` on branch `docs/release-a-plan`, in its pinned worktree, equal to
  `master` and `origin/master`; clean index and tree at capture.
- Code delta after the final Full review: `3031d686` (half-open day bounds in
  `OccurrenceMapper`, RF-090-57), `63ec9164` (build toolchain Node 24.15.0, RF-090-58),
  `d189ce6a` (licence override of the Fluent icons, RF-090-59) and `0638216a` (the npm
  assertions of the workflows, RF-090-60). `784c179d` records the qualification only.
- Decisions of the operator (2026-11-15): Release A is not deployed on its own and reaches the
  family instance with Releases B and C as `0.8.0-alpha.5`; B and C share one plan; this plan
  closes now with the deployment criteria user-rescoped to that joint plan.

### Findings

| ID | Severity | Area | Issue | Resolution |
|---|---|---|---|---|
| — | nit | `docs/engineering/architecture.md` | The size inventory named 316 lines for `Db/OccurrenceMapper.php`, which RF-090-57 took to 333 | Fixed in the review |
| — | nit | `CHANGELOG.md` | The heading still called `0.8.0-alpha.5` a candidate of its own | Fixed in the review: qualified, not deployed, ships with Releases B and C |
| — | nit | `lib/Db/OccurrenceMapper.php` | `dayAfter()` repeats `RecurrenceCalculator::dayAfter()` | Accepted: the mapper should not depend on a service class |

No architecture, authorization, input, logging or i18n finding in the delta: the mapper binds
named parameters only, the other commits change pins, inventories, workflows, a spec and docs.

### Verification

- RF-090-57: every day bound is half-open; `OccurrenceDayBoundsTest` covers the inclusive
  window, the one-day window, the work-date lookup, the paging and the exclusive delete, failed
  4 of 4 on SQLite without the fix and passed on MySQL, PostgreSQL, SQLite and the AIO row in
  matrix `a5q2`.
- RF-090-58 to RF-090-60: builder inventory, `Dockerfile.package`, the five workflows,
  `engines.node` and the docs name Node 24.15.0 with npm 11.12.1; every locked engine range holds
  there; `toolchainEngines.spec.js` now requires each npm assertion to equal the builder's npm.
  Remote `checks` and CodeQL green on `0638216a`.
- Plan completion: all §9 findings RF-090-01..60 resolved or user-rescoped (RF-090-56 to
  BUG-API-002); every acceptance criterion closed, three of them user-rescoped to the joint plan
  (applied-migration record, deployment, dated public presentation); D-090-01..81 recorded.
- Durable docs: ADR-020, the ADR-019 amendment, the concepts, the API reference, the user guide,
  the architecture and testing guides carry the delivered behaviour; the concept, CONCEPT-002
  and the release strategy carry D-090-81.
- Backlog: FEAT-UX-053, FEAT-UX-054, FEAT-UX-056, FEAT-UX-057 and FEAT-UX-062 to FEAT-UX-067
  archived; FEAT-UX-058 no longer waits on FEAT-UX-053; TECH-DEPS-001 keeps its major rows and
  RELEASE-002 the release slice for the joint deployment.

### Gates

- Jest 179 suites / 2500 tests and ESLint on `0638216a`; remote `checks` and CodeQL green.
- Backend and browser evidence: the qualification of 2026-11-15 (matrix `a5q2`, browser gates
  `run-f60Ggq` and `run-XJlr3a`, package `run-mwic4r9k` / `run-mwicbw0h`).
- Closing package: `backlog:check`, `docs:encoding`, `git diff --check`.

### Transition

`active/ → done/`. One scoped review commit on `docs/release-a-plan`; no merge, push, tag or
deployment.
