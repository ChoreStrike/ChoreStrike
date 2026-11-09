# Code Review Record — 2026-11-09

## Final Standard B - CS-PLAN-089 alpha4 trial defects and everyday fixes

**Outcome:** `PASS-WITH-FIXES`. Final review; the plan moves to `done/`.

### Scope and capture

- Tier: Standard B. The implementation had its Full review on 2026-11-04 (four reviewers,
  `PASS-WITH-FIXES`), which is the Full review the plan's criteria ask for. Owning plan given
  explicitly: `docs/plans/active/cs-plan-089-alpha4-trial-defects-and-everyday-fixes.md`.
- Base HEAD `32a479cf4f41f560182cf68234240da5f20a2ec9` on branch
  `docs/alpha4-deployment-record`, in its pinned worktree, clean index and worktree. A clean
  tree proves nothing about completion, so the review covered the whole plan: manifest, tests,
  criteria, findings, register, owning backlog items, durable docs and history.
- Code delta after the Full review's fixes: `9b36a53..1a4988f` holds the recaptured screenshots
  and three browser-test files (`3-floor-house.spec.ts`, `calm-core-ui.spec.ts`,
  `fixtures/database-fixtures.ts`), nothing under `lib/`, `src/`, `appinfo/`, `templates/` or
  `l10n/`. The later commits on `master` (BUG-UX-014, the dependency updates, the reviewer
  adapter) are not this plan's deliverable and carry their own reviews.
- The private exchange was read for the requested deployment report only; no production access.

### Findings

| ID | Severity | Area | Issue | Resolution |
|---|---|---|---|---|
| RF-089-21 | minor | Tracking | Three acceptance boxes of delivered items were still open in `backlog.md`: the browser pin and the screenshots of BUG-UX-009, and both boxes of TECH-RUNTIME-003 | Verified against the candidate's full gates on both rows, `fed3bd0` and `e6039b0`; archived with the other owning items |

No architecture, authorization, input, logging or i18n finding in the delta: it changes tests
and images only.

### Verification

- Manifest: all 77 entries exist at the candidate, and both removed files are gone. Three listed
  files needed no change: `testTarget.spec.js` and the two help components, which hold nothing
  stale. All 33 named test files are present. RF-089-01..21 resolved.
- Register: all 36 entries routed, no open P0 or P1.
- No migration file changed since `0142a38`; the 26 applied migrations are pinned and match the
  package, Git and `tests/migrations/applied-migrations.json`.
- Deployment: the operator's sanitized report was verified before its import, see the plan's
  deployment record. The installation, the table digests, the smoke and the server log are
  operator-reported.
- Durable docs: the energy and task-model concepts, the API reference (409 refusals, last-done
  on every cadence, the child limit, `obligatedUserIds`), the user guide, the UI use cases, the
  logging guideline, the architecture overview and the deployment guide carry the delivered
  behavior.

### Questions and decisions

None new. D-089-00..15 are recorded in the plan; no question is open.

### Gates

Jest 162 suites / 2302 tests, `npm run lint`, `backlog:check`, `docs:encoding`, `agents:check`,
`git diff --check`. The review changed documentation only, so PHPUnit (unit 1549 and integration
655 on four rows, matrix `a4q2`), the browser gates `run-OYBZLq` and `run-oOIqOq` and package
smoke `mw2s5ai2` of the candidate stay the evidence and were not rerun.

### Transition

`active/` to `done/`; twelve owning backlog items archived (BUG-UX-008..013, FEAT-UX-046..049,
TECH-LOG-001, TECH-RUNTIME-003); RELEASE-002 keeps its later stages with CS-PLAN-080. The
records of run `mw2s5ai2` are kept in the local `archive/2026-11/package-run-mw2s5ai2/`. One
scoped review commit; no push, tag or deployment.
