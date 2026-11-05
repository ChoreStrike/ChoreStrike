# Code Review Record — 2026-11-05

## Full - BUG-UX-014 energy edits and open work

**Outcome:** `PASS-WITH-FIXES`. No owning plan. The item stays open in the backlog until the next
planned release carries it: the operator decided against a release of its own.

### Scope and capture

- Tier: Full, because the change touches data integrity (payout values, a job writing retained
  rows), more than one domain and more than five files. Four parallel read-only reviewers
  (correctness, architecture, security, performance and quality), each given the same diff.
- Base HEAD `70cbfd2` on `fix/energy-edit-open-occurrences`, nothing staged, 29 changed files and
  one untracked test. Captured diff plus untracked hash: SHA-256 prefix `c2744e8d968f4fd4`.
- Owning plan: N/A. The operator's report of 2026-11-05 and its decisions are recorded in the
  [backlog item](../backlog.md#p2-bug-ux-014--a-changed-energy-does-not-reach-open-tasks).
- Every finding below was checked against the code. R-01 and R-02 were reproduced with a throwaway
  probe on the canonical stack before the fix.

### Findings

| ID | Severity | Area | Finding | Resolution |
|----|----------|------|---------|------------|
| R-01 | major | Timeline | A one-time task edited before completion stayed active, because end of life read the rows of the current revision only, and the interval rule then created a new open occurrence on the next job pass. Pre-dates this change | Fixed: end of life reads all rows of the room task, `ensureIntervalPendingUnderLock()` respects the once rule |
| R-02 | minor | Timeline | An edit created an open occurrence on a finished room task of a shared definition. Pre-dates this change | Fixed: no pending occurrence for an inactive room task |
| R-03 | major | Agreements | The restart gate charged the energy of the closed revision while the row carried the current one. Introduced by this change | Fixed: one `energyBasis()` for both gates, over-budget regression test |
| R-04 | major | Store | After another session's edit, a completion conflicted repeatedly until a page reload, because a 409 reread neither My Day nor the row list | Fixed: `refreshDerivedProjections({ rows: true })` on a 409 and after a saved edit |
| R-05 | major | Undo | The undo deleted agreement rows, against the append-only contract and the only record of who changed a value. Introduced by this change | Fixed: definition evidence stays when the derived successor goes |
| R-06 | major | Undo | Reopened work takes the current energy, so an undo followed by another completion pays the current value | Operator decision, see below |
| R-07 | major | Deployment | The alignment changes retained rows after the verified maintenance window, invisible to the deployment contract | Fixed: expected delta and a before/after count in `deployment.md` |
| R-08 | minor | Job | The alignment was framed as a one-time repair but runs on every pass, without a log line | Fixed: ADR-013 states the invariant, an info event reports each change |
| R-09 | minor | Frontend | The reread bypassed the store's refresh contract and logged a second error | Fixed: it runs through `refreshDerivedProjections()` |
| R-10 | minor | Docs | Stale wording in the job, service and ADR text, *frozen* for open work, the fairness row cap | Fixed |
| R-11 | minor | Tests | Inactive and deleted room tasks uncovered, a midnight dependency, a browser race on the My Day reread, shared mock state | Fixed |
| R-12 | minor | Digest | An evidence-only version bump drops queued agreement digest lines | Routed: [BUG-UX-015](../backlog.md#p3-bug-ux-015--an-energy-or-flex-edit-drops-queued-agreement-digest-lines) |
| R-13 | nit | Comments | Stale docblocks and the name of the provenance probe | Fixed, renamed to `hasExplicitAgreementFor()` |

### Refuted and accepted

- A job pass instead of an ordered migration is compatible with the data-retention rules: ordered
  steps are required for schema changes, the operator authorized the correction, the contract is
  documented, and the rows carry evidence. R-07 closes the gap this left.
- A member raising the energy of open work before completing it is no new privilege: the edit has
  the existing visibility and child gates, and the same member could already create such a task.
- A read landing after a completion can restore a completed row. It needs a server slower than
  the hold plus the request, and the next attempt recovers through R-04.
- One read plus three statements per diverged row on an edit: same shape as the flex widening, at
  household scale. The added job read is served by the room-task index.
- Every saved edit rereads the rows, descriptive edits included: edits are rare, and the store
  reads only what is loaded.
- `releaseCompletionUnderLock()` and `OccurrenceReconcileService` were already above the size
  guideline. A behavior-neutral split does not belong in this change.

### Questions and decisions

- **R-06, the energy of reopened work:** the operator chose the current value on 2026-11-05. The
  undo reverses the old payout exactly, and the new completion pays what applies then. The rule
  stays that open work carries the current value, so the row and the dialog cannot disagree again.
  The undo stays visible in the history.

### Gates

PHPUnit unit 1549 and integration 673 on the canonical stack through `scripts/run-tests.js`. Jest
162 suites / 2302 tests, ESLint, stylelint, php-cs-fixer, l10n, backlog, encoding and agent adapter
checks. Browser: `family-core-ux` and `insights-flow` on the primary row, 23 passed, the new journey
four times in a row. Each fix was removed once to confirm that its regression test fails. The full
browser gate and the provider matrix belong to the qualification of the planned release.
