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
- Plan: [CS-PLAN-083](../../plans/done/cs-plan-083-release-qualification-gates.md),
  active; implementation not started.
- Final review type after implementation: Full.
- Scoped commit message: `docs(plans): activate CS-PLAN-083 after Standard A review`.
- Next owner/package: implementing agent, RELEASE-002 / WP1 audit and provisioning.
- No deployment, external publication, real household mutation or retained-data
  adoption is authorized by this plan review.

---

# CS-PLAN-083 Final Full Review (2026-10-10)

## Scope And Capture

- Tier/outcome: **Full / PASS-WITH-FIXES**. Explicit owning plan:
  [CS-PLAN-083](../../plans/done/cs-plan-083-release-qualification-gates.md).
  Whole implemented WP1-WP3 domain, plan/history/acceptance criteria, durable
  contracts and required tests reviewed, not merely the initially empty diff.
- Initial baseline: `master`, HEAD
  `52423a077beab8bb91a900e1d0e06d2eff2ae844`, clean index and working tree,
  no untracked files. Both binary-diff hashes:
  `e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855`.
  Sorted initial Git-tree SHA-256:
  `c42727ad3e5c444120b4594c00944d4e887a8f905f06f1e1edb34f9ee99473d7`.
- Qualified remediation candidate:
  `853f661f71e7916ad02e52426e5346fff4eb72c7` (epoch `1791642259`), clean
  tree/index throughout the runtime gates. Candidate Git-tree SHA-256:
  `2646655313fd5d96decdcb743af446299676838d901690bced1059a43caf4ef1`.
  Reviewed `e21643a..853f661` complete binary-diff SHA-256:
  `3c38b6e0aea76f1f0f30c54b859082a1566f5202159b937cd7b286744056b497`.
- Reviewed implementation commits include WP1-WP3 history through `325f23b`,
  the documentation-only `52423a0` and the explicitly authorized `853f661`
  safety/identity remediation checkpoint. Final review commit scope is evidence,
  links/tracking and the eligible plan transition only; it does not rebuild or
  relabel the qualified candidate's archive.

Exact reviewed 32-path manifest (paths at the qualified candidate):

```text
docker/Dockerfile.package
docker/README.md
docker/compose.package-smoke.yml
docker/package-build.json
docker/package-license-overrides.json
docker/package-license-texts/blurhash.txt
docker/package-license-texts/fluentui-svg-icons.txt
docker/package-license-texts/vue-devtools-api.txt
docker/test-entrypoint.sh
docs/backlog/ACTIVE_TASKS.md
docs/backlog/archive/CODE_REVIEW_2026-10-10.md
docs/backlog/backlog.md
docs/engineering/deployment.md
docs/engineering/testing.md
docs/plans/README.md
docs/plans/active/cs-plan-083-release-qualification-gates.md
package.json
scripts/build-package.js
scripts/package-licenses.js
scripts/package-target.js
scripts/run-package-smoke.js
tests/e2e/minimal-smoke-tests.spec.ts
tests/fixtures/provision-command.sh
tests/package/fixtures/contract-dangling-stats.json
tests/package/fixtures/contract-orphan-stats.json
tests/package/fixtures/contract-valid-stats.json
tests/package/owner-smoke.cjs
tests/unit/Command/TestEnvironmentProvisioningTest.php
tests/unit/scripts/assetContract.spec.js
tests/unit/scripts/buildPackage.spec.js
tests/unit/scripts/runPackageSmoke.spec.js
webpack.config.js
```

Read-only dependency boundaries also traced: `scripts/test-target.js`,
`scripts/test-runtime.js`, runtime inventory and staging/matrix contracts.

## Tracks And Findings

All four read-only reviewer tracks completed: Correctness & Functionality;
Architecture & Ownership; Security/Auth/Data-flow; Performance & Code Quality.
Post-fix checks independently verified the implemented remedies. Two further
identity/mount issues were fixed and independently rechecked. All four final
deliverables are valid empty JSON finding arrays. No track was omitted.

Line references below identify the reviewed source, not relocated final lines.

| ID | Severity | File:Line | Issue | Resolution |
|---|---|---|---|---|
| RF-083-05 | major | scripts/run-package-smoke.js:165 | Container-only freshness/cleanup ownership | Fixed: container/volume/network collisions, unique labels and stable inspected resource identities; tests and live owned cleanup pass. |
| RF-083-06 | major | scripts/run-package-smoke.js:124 | Subset extraction hashing and link following | Fixed: new directory, safe members, exact file/directory inventory, no links; negative tests and pre/post rehash pass. |
| RF-083-07 | major | scripts/run-package-smoke.js:275 | OCC-only HTTP/OCC coherence | Fixed: direct no-proxy/no-redirect HTTP plus OCC readiness/version comparator; mismatch/unready tests and both live rows pass. |
| RF-083-08 | major | scripts/build-package.js:83 | Failed Git status accepted as clean | Fixed: all identity probes require successful status; negative tests pass. |
| RF-083-09 | major | docker/test-entrypoint.sh:149 | Failed apps_paths read treated as absent | Fixed: explicit JSON default sentinel and fail-closed query; no-import/no-seed regression and fresh/populated proofs pass. |
| RF-083-10 | major | tests/package/owner-smoke.cjs:12 | Divergent standalone target validator | Fixed: shared package-only policy in both entrypoints; negative tests and setup/verify pass. |
| RF-083-11 | major | scripts/build-package.js:571 | Mutable HEAD/source and independent candidate identity | Fixed: captured-SHA archive/tree/epoch/config/Dockerfile, final drift and manifest parity checks; tests and qualification pass. |
| RF-083-12 | major | scripts/build-package.js:478 | Missing complete dependency license texts | Fixed: actual-module collection, attribution/hash inventory and version-bound omissions; 145 dependency records, 293 license files and completeness negatives pass. No legal compatibility conclusion. |
| RF-083-13 | major | scripts/build-package.js:187 | Host-dependent final gzip | Fixed: pinned Linux Node/zlib, recorded compressor identity; two builds plus alternative-host-Node recompression match exactly. |
| RF-083-14 | minor | scripts/build-package.js:485 | Builder survives Docker client timeout | Fixed: token/name-owned lookup and exact-ID finally cleanup; negative regression and real builds pass. |
| RF-083-15 | minor | docker/README.md:34 | Bash continuation in PowerShell examples | Safely fixed to single-line commands; documentation checks pass. |
| RF-083-16 | major | scripts/package-target.js:24 (post-fix review) | POSIX mount paths compared case-insensitively | Fixed: case normalization limited to Windows drives; independent actual-POSIX refusal probe and regression pass. |
| RF-083-17 | major | scripts/build-package.js:691 (post-fix review) | Equal archives could conceal different between-build candidates | Fixed: full candidate/source/tool manifest comparison; injected same-byte/different-SHA regression and real pair pass. |

No unresolved finding, security exception or workaround remains. Tooling
safety findings were not auto-fixed: user authorization preceded those changes.
The pre-existing frozen NC35 notifications-lock/NC34 third-party platform
limitations remain separately bounded by the runtime inventory/RELEASE-002;
this review neither suppresses nor expands their disposition.

## Decisions

- Q-083-04: **Authorize safety fixes**; explicit authorization for all six
  local safety/validation remedies and shared policy/regressions. No demonstrated
  remote app exploit or app authorization mutation was identified.
- Q-083-05: **Authorize packaging fixes**; candidate binding, complete license
  packaging, pinned compression and builder cleanup with renewed evidence.
- Q-083-06: **Authorize checkpoints (Recommended)**; scoped implementation
  checkpoints before clean-HEAD qualification, plus the single eligible final
  review commit. No push/deployment/adoption authorization.
- All answers recorded in the owning plan; no unanswered question.

## Gate Evidence

| Gate | Result |
|---|---|
| Supported Node/npm | Node 20.19.0 / npm 10.8.2 for mandatory local gates; pinned builder uses the same versions. |
| Full Jest | 116 suites / 1,606 tests pass, no new skips. |
| Lint / precommit lint fixer | Pass; clean source scope, no unrelated rewrite. |
| Backend per runtime row | NC35 and NC34 each: 1,372 unit + 604 integration tests, `--fail-on-skipped`, green. |
| Package self-tests and syntax | Build/smoke negatives and all remediated JS scripts pass. |
| Two independent builds | `build/package/run-mv2hk5df`, identical 522-file/full-identity manifests and archive; source-vs-blob/asset contract pass. |
| Final gzip | Linux/amd64 Node v20.19.0, zlib `1.3.0.1-motley-82a5fec`, OS byte 3. Independent host Node24 recompression produces identical bytes. |
| Complete license payloads | 145 bundled dependency records, 293 license/attribution files; zero missing inventory hashes, full GPL/AGPL/Apache/MPL texts verified. |
| Wired strict package smoke | `run-mv2hs127`, same archive on NC35 and NC34; one cold setup plus six owner cases per row, exact mount/hashes, HTTP/OCC parity, false markers, six owned resources per row and verified teardown. |
| Fresh/populated provisioning | Newly owned NC35/NC34 family fixtures, safe app roots, alias absence, installed identity; data-only ChoreStrike dumps/config hashes unchanged and config/data/DB volumes retained across recreation. |
| Complete browser pair | NC35 `run-IDVdFy`, NC34 `run-EpjkYH`: each 88 + 168 + 39 = 295 first-attempt passes, including setup, 49 uniquely owned required specs; zero gate violations/failures/flakes/skipped required scenarios. |
| Documentation/links/whitespace | Encoding, backlog/link validation and staged/unstaged whitespace checks rerun for the final transition. |

Qualified archive: SHA-256
`885f57e0306d8dae3d53190cc0aa605c998a07b8609751b7d11cfccf6584edd7`,
1,657,643 bytes. Exact raw reports, traces, screenshots and archives remain
gitignored/private; only sanitized evidence/hashes are tracked.

## Finalization

- Plan moved `active/` to `done/` after all criteria, required tests and
  RF-083-01..17 completion; durable contracts integrated in engineering testing
  and deployment docs. Links/tracking updated and completed bounded contribution
  archived in [October history](2026-10.md).
- RELEASE-002 remains open for operator deployment/adoption/field use;
  CS-PLAN-080's human evidence and baseline-adoption gates are untouched.
- Implementation checkpoint: `853f661`,
  `fix(gates): close CS-PLAN-083 qualification safety and identity gaps`.
- Both newly created review staging fixtures were reverified for frozen image,
  project/service and config/data/DB ownership before owned `down --volumes`;
  the canonical stack and other sessions' resources were untouched.
- Final scoped review commit:
  `docs(review): finalize CS-PLAN-083 full qualification review`.
  No branch switch, amend, push or production change.
