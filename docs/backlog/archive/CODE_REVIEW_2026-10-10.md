# Code Review: CS-PLAN-083 Release Qualification Gates

- **Review Date**: 2026-10-10
- **Reviewer**: Kilo
- **Mode**: Standard Batch Review / Standard A
- **Scope**: One draft plan; implementation contracts inspected read-only
- **Base Commit**: e21643a27b7a0963cd2a4b5cd7484f2e7cf09769
- **Duration Target**: 60 minutes

## Executive Summary

**Outcome: PASS-WITH-FIXES.** Four plan-design findings resolved; three explicit
decisions answered in one structured batch. No open review finding or question.
The plan moves from `new/` to `active/`. No runtime code, test infrastructure,
package, production installation or retained-baseline adoption was changed.

## Scope and Risk Framing

- Initial reviewed manifest: `docs/plans/new/cs-plan-083-release-qualification-gates.md`.
- Related pre-existing drafting edit: `docs/backlog/ACTIVE_TASKS.md`; no unrelated
  changes at capture, branch `master`, index empty. Target was one regular,
  non-symlink Markdown file; active destination absent. No read-only override.
- Inspected: Docker entrypoints/runtime inventory/canonical Compose/Dockerfile,
  package owner smoke, test-target validator, Webpack, existing provisioning and
  asset-contract tests, canonical browser smoke, CI wiring, deployment/testing
  guidance, RELEASE-002 and active CS-PLAN-070/080 ownership boundaries.
- Initial risk hypotheses: alias removal breaks discovery/preservation; timestamps
  defeat reproducibility; incompatible marker policies or mismatched artifacts
  undermine package qualification.

### Captured Fingerprint

SHA-256 values over exact binary diff bytes and untracked file bytes:

| Component | SHA-256 |
|---|---|
| Staged binary diff (empty) | `e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855` |
| Unstaged binary diff (registry drafting entry) | `b8d4a7287a835da9e926d14c1e0fb3938cb1c38bf19b23d779898dafe8ef7e18` |
| Untracked draft plan | `c117a48d190261f413fcf0db3431e108652dbefe478eef73fcbd762fb18febed` |
| Complete capture | `e69348cd4b13eecee8ed66ff0de1fe1e0911bb6c97dcaa245065e5e00e25ef91` |

Complete capture hashes UTF-8 `JSON.stringify({staged,unstaged,untracked})`,
where the first two values are the diff hashes and `untracked` is the sorted
array of `{path,sha256}` entries (only the draft path above). This includes
tracked staged/unstaged binary changes and every untracked path/content hash.

## Findings

Line references identify the captured original draft, before review edits.

| ID | Severity | Original Location | Issue and Evidence | Status |
|---|---|---|---|---|
| RF-083-01 | major | Plan:57-65, 168 | Alias removal omits complete app-path setup and preserved-volume behavior; entrypoint:135-139 creates the alias, while package entrypoint:29 imports apps_paths. Legacy fallback has no concrete consumer. | Resolved by Q-083-01 and explicit fresh/populated/refusal tests. |
| RF-083-02 | major | Plan:66-78, 154-155 | Byte reproducibility lacks a deterministic Webpack clock (`webpack.config.js:17-25` uses Date.now), pinned builder/production dependency ownership and a clean-candidate checkpoint. | Resolved by Q-083-02, two-build proof, inventories, manifest and checkpoint contract. |
| RF-083-03 | major | Plan:79-88, 111-113 | Test-target validator requires true markers (`scripts/test-target.js:43-46`), whereas package smoke requires false; archive-to-mount identity, row/run evidence, failure and cleanup contracts are insufficiently specified. | Resolved by Q-083-03; separate package validation and adversarial orchestration tests, no weakening of existing guards. |
| RF-083-04 | minor | Plan:98-100, 126-162, 179-182 | Manifest/test ownership misses provisioning and build prerequisites, and final Standard B is insufficient for changed safety/preservation boundaries. Existing asset tests already cover real app roots. | Corrected manifest, reused provisioning/asset tests, final Full required; genuine /apps/subdirectory resolver tests preserved. |

These are design corrections, not executed implementation acceptance criteria.
All future implementation gates remain required and unchecked in the plan.

## Package Results

### Package 0: Scope and Diff Mapping

Confirmed eligible unique target, absent destination, clean index, captured
HEAD/branch and complete fingerprint. Preserved the related drafting package.
No subagents, as required for Standard A.

### Package 1: Static Quality

Checked all nine plan sections, ownership, implementation sequence, risks,
manifest and acceptance criteria. No backend/frontend behavior edits. Public
documentation remains English; decision labels below are translated faithfully.

### Package 2: Wiring and Contracts

Validated the plan against actual runtime/tool contracts. Safe factual corrections
and the three authorized contract decisions resolve RF-083-01..04. Existing
`TestEnvironmentProvisioningTest.php` explicitly asserts symlink-before-upgrade
ordering, so the manifest now owns its replacement and shell fixture changes.
WP9 human evidence and deployment/adoption remain independent.

### Package 3: Validation

- `npm test -- --runInBand`: PASS, 114 suites / 1,518 tests, no skips, on Node
  20.19.0/npm 10.8.2; an initial host-toolchain run also passed.
- `npm run lint`: PASS, no warnings on the supported Node 20.19.0/npm 10.8.2
  rerun. Initial host Node emitted DEP0180; no suppression or dependency edit.
- Jest's three Composer stale-lock messages originate from the intentional
  `STALE_LOCK_WARNING` fixture in `testRuntime.spec.js`, not a live Composer run.
- `npm run docs:encoding`: PASS, 920 tracked files including the transition.
- `npm run backlog:check`: PASS, 33 scoped files and resolvable ownership/links.
- Initial staged whitespace check caught the template's Markdown hard-break
  spaces in this record; removed them. Both diff whitespace checks are rerun
  along with the documentation gates on the final staged package before commit.
- Backend Docker, E2E and package/reproducibility execution: not run for this
  documentation-only Standard A; required during implementation. No runtime pass
  is inferred from plan approval. Write-capable source fixers are outside scope.

### Package 4: Documentation and Backlog

Updated the plan, plans index, ACTIVE_TASKS and RELEASE-002. This review record
and the plan retain all resolved findings and decisions; no new backlog debt.
RELEASE-002 becomes In Progress only for WP1-WP3; other release gates stay open.

### Package 5: Final Triage and Handoff

Outcome PASS-WITH-FIXES; eligible activation. One scoped conventional review
commit on master, only after staged documentation/whitespace gates and drift
checks. No branch switch, implementation commit, push or production operation.

## Questions and Decisions

All answers received in one structured request on 2026-10-10:

1. Q-083-01: remove alias without fallback (recommended) versus leave new/ pending
   a justified legacy consumer and expiry. **Answer: remove alias.** Safe complete
   app roots and preserved-volume lifecycle/refusal tests are mandatory.
2. Q-083-02: deterministic builds (recommended) versus narrow to single-artifact
   identity. **Answer: deterministic builds.** Immutable toolchain, locked inputs,
   commit-derived epoch, production allowlist and independent two-build proof;
   separately authorized clean candidate, never automatic dirty-tree bypass.
3. Q-083-03: separate package safety contract (recommended) versus leave WP3 open
   and plan unactivated. **Answer: separate safety contract.** Same archive on both
   rows, identity-bound evidence, fail-closed guards and ownership-safe cleanup.

## Recommendation

- Final outcome: **PASS-WITH-FIXES**.
- Plan: [CS-PLAN-083](../../plans/active/cs-plan-083-release-qualification-gates.md),
  active; implementation not started.
- Final review type after implementation: Full.
- Scoped commit message: `docs(plans): activate CS-PLAN-083 after Standard A review`.
- Next owner/package: implementing agent, RELEASE-002 / WP1 audit and provisioning.
- No deployment, external publication, real household mutation or retained-data
  adoption is authorized by this plan review.
