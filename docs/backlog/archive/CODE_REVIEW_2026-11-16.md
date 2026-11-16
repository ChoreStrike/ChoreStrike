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

## Standard A - CS-PLAN-091 Simplified Core Releases B and C

**Outcome:** `PASS-WITH-FIXES`. The plan moves `new/ → active/`.

### Scope and capture

- Tier: Standard A, one plan: `docs/plans/new/cs-plan-091-simplified-core-releases-b-and-c.md`
  with its backlog items (FEAT-UX-068 to FEAT-UX-070 new; FEAT-UX-040, -041, -043, -050, -051,
  -052, -059, BUG-UX-015, RELEASE-002, CONCEPT-002), `ACTIVE_TASKS.md` and the plans index.
- Base HEAD `723a94f6` on branch `docs/release-bc-plan` in its pinned worktree, equal to the
  local `master`; empty index, the draft and three tracking files uncommitted (fingerprint
  `6254c02c`).
- Checked against the code: `appinfo/routes.php`, the occurrence, agreement and
  checklist-progress schema (`Version1031`), `AgreementService`, `DailyCapacityService`,
  `RoomTaskService`, `RoomTaskLastDoneService`, `ChecklistService`, `DigestService`, the readers
  of `room_tasks.next_due`, ADR-010, ADR-016 and the size inventory.

### Findings

| ID | Severity | Area | Issue | Resolution |
|---|---|---|---|---|
| A1 | high | D-091-07 | Closed cycles were to live in `chorestrike_occurrences`, whose `schedule_rev_id` and `ordinal` are not null under a unique key; sub-task progress is keyed by them | D-091-17 (operator): their own table; D-091-07, schema steps and tests rewritten |
| A2 | high | Implementation order | WP-B1 stopped materialization while the agreement, due-date and last-done writers, preview and apply, and the capacity refusals read stored future rows until WP-B7 and WP-C | They go in WP-B1; WP-B7 and WP-C trimmed; every review commit passes the build gate |
| A3 | medium | D-091-11 | Start-of-day stability cannot be computed without stored state | D-091-18 (operator): a daily snapshot |
| A4 | medium | WP-B1, WP-B7 | `room_task_last_done#update` (FEAT-UX-045) re-anchors through the agreement writer and was not covered | D-091-19 (operator): the first date replaces it |
| A5 | medium | D-091-16, WP-B2 | Recording for others on private tasks (ADR-010) unspecified | D-091-20 (operator): only members who may see the task |
| A6 | medium | D-091-07 | "When a window closes unfulfilled" contradicted D-091-02 | Only fixed-day cycles are missed; after-completion and one-time cycles stay red until done late |
| A7 | medium | WP-B1 | The `next_due` projection, filtered by the list queries, was kept only by the job | Every window-changing command updates it in its transaction; tested against `WindowService` |
| A8 | medium | WP-B1, WP-B2 | Partly ticked sub-tasks (D-089-02) had no key without occurrence rows | Progress keyed by the cycle; tested |
| A9 | medium | WP-B3 | A date correction can invalidate closed cycles after the corrected entry | It re-derives them; tested |
| A10 | low | WP-0 | The cutover's first window per room task and existing voluntary definitions unspecified | ADR-021 content and cutover tests |
| A11 | low | D-091-12, WP-0 | ADR-016 names agreement receipts in the digest queue | ADR-016 status note; the pause-request lines went with ADR-020 |
| A12 | low | §6 | Interim reviews named Standard B although the packages touch migrations, routes and authorization | The tier follows the review skill |
| A13 | low | §7 | No risk row for the oversized aggregates (`CompletionService` 1,451 lines) | Risk row; size inventory re-measured at every review commit |
| A14 | low | §5 | Manifest missed `RoomController`, the last-done service and controller, the checklist files, `TestDataService` and eight frontend files | Added |
| A15 | low | `backlog.md` | FEAT-UX-050 still planned a handover and a restart home (D-091-10), FEAT-UX-059 called the confirmed capacity rule proposed, RELEASE-002 called CS-PLAN-090 unqualified | Updated |

No open finding; nothing routed to the backlog.

### Decisions

D-091-17 to D-091-20, each the recommended option, recorded in the plan. The positions
D-091-07 to D-091-16 bind as amended.

### Gates

Docs-only change: `backlog:check` (including its link check of the scoped files),
`docs:encoding`, `git diff --check` and LF endings.

### Transition

`new/ → active/`; owning items *In Progress*; one scoped review commit on
`docs/release-bc-plan`. No implementation branch, merge or push yet.
