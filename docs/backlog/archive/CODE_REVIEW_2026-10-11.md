# Code Review: Private Exchange Handoff Tooling

- **Review Date**: 2026-10-11
- **Reviewer**: Kilo
- **Mode**: Full (4 parallel read-only reviewer tracks: correctness, architecture, security/privacy, performance/quality)
- **Scope**: Uncommitted working tree — package export script and tests, smoke summary contract, Jest discovery exclusion, deployment/AGENTS/backlog documentation
- **Owning plan**: N/A (no `docs/plans/active/` plan covers operator exchange tooling; CS-PLAN-080/070 verified unrelated)
- **Base Commit**: `281a2e175ac3b1899c019237ef4df531e7a479cb` (branch `master`, index empty)

## Executive Summary

**Outcome: PASS-WITH-FIXES.** Four reviewer tracks returned 13 consolidated
findings (1 security minor, 2 documentation minors, 10 nits). Eleven were
resolved in-round as verified safe fixes or user-approved changes, one was
routed to the backlog ([TECH-PKG-001](../backlog.md)), and one applied nit was
reverted after its gate run failed (see RF-EXP-11). Three decisions were
answered in one structured batch. The complete reviewed package (implementation
plus review fixes and this record) was committed together. No runtime app
code, PHP backend, production installation, actual package export, feedback
import or retained-baseline adoption was changed.

## Captured Fingerprint

Per-file content hashes (`git hash-object`, SHA-1) of the complete captured
changed set before any review edit; branch `master`, index empty, HEAD as
above. Untracked paths sorted lexicographically.

| Path | Content SHA-1 |
|---|---|
| AGENTS.md | `019c02cb809e5d84dbd9cbcf9b7b4b6d0ea8276d` |
| docs/backlog/ACTIVE_TASKS.md | `c5131701f09540cbdba16493bb77e8c65be4be01` |
| docs/backlog/archive/2026-09.md | `8365014db0ceb3c364cd6c0e5a62036a830aac11` |
| docs/backlog/archive/2026-10.md | `56d639b412f616e932d13b62bc41bce7c181f2f7` |
| docs/engineering/deployment.md | `837a2302ecc45c6bfafc9ef48770982d69323be4` |
| jest.config.js | `054665b368f19ff05d3dc6a8832a45f3c0e1dda2` |
| package.json | `6e292aad6d14e487372ddacaa4e56c1608293db3` |
| scripts/run-package-smoke.js | `dcba8d8409f6892f3f387eb8cf73ce81e649e32f` |
| tests/unit/scripts/jestDiscovery.spec.js | `0cfe26a46e8b9a46d1852e6f344203e196f5e503` |
| scripts/export-package.js (untracked) | `32188a2aff0c96944a19d4ee1edb0844c4d72057` |
| tests/unit/scripts/exportPackage.spec.js (untracked) | `46e4a13a1fb4e82f63a4e566b1c0f08df39c7cd4` |

Note: `git diff` index lines confirm the tracked mappings
(`6d0a064..dcba8d8` for run-package-smoke.js, `97e2981..0cfe26a` for
jestDiscovery.spec.js); the last two paths were untracked new files at capture.

## Findings

| ID | Severity | Location | Issue | Status |
|---|---|---|---|---|
| RF-EXP-01 | minor (security) | scripts/export-package.js publish | TOCTOU: `von-chorestrike/` validated only at start; a link swapped in before `renameSync` could redirect publication and bypass the overwrite refusal. | **Fixed (user-approved)**: immediate pre-rename `directory()` revalidation (lstat + realpath self-check) plus junction-swap regression test. |
| RF-EXP-02 | minor | deployment.md export section | Doc claimed `handoff-<commit>-<uuid>/` but the exporter publishes a 12-character commit prefix. | **Fixed**: doc states the 12-character prefix. |
| RF-EXP-03 | minor | deployment.md Runtime Installation | New `### Runtime Installation` heading left the numbered `### 1..4` steps as siblings instead of children. | **Fixed**: demoted to `####` (anchor text unchanged; no inbound links existed). |
| RF-EXP-04 | nit | scripts/export-package.js validateQualification | `passed !== false` clauses are vacuous against genuine owner-smoke reports (no `passed` field; failed runs never qualify). | **Kept + documented (user-approved)**: clauses only trip on hand-edited records with an explicit failure flag; one clarifying comment added. |
| RF-EXP-05 | nit | scripts/export-package.js | Fourth local `sha256` copy across scripts (rule of three exceeded; `sha256Hex` already exported by build-package.js). | **Fixed**: imports `sha256Hex` from `./build-package`. |
| RF-EXP-06 | nit | scripts/export-package.js:1 | Missing shebang vs sibling scripts. | **Rejected after application**: eslint `n/shebang` errors on shebangs in non-bin scripts (sibling shebangs are pre-existing lint debt outside the lint gate). Reverted; no new lint errors introduced. |
| RF-EXP-07 | nit | export-package.js / exportPackage.spec.js | `prepareFiles` and the spec `beforeEach` exceeded the ~30-line guideline with mixed responsibilities. | **Fixed**: extracted `composeHandoffHeader()` and `makeQualification()`. |
| RF-EXP-08 | nit | export-package.js / run-package-smoke.js / owner-smoke.cjs | Owner-smoke case counts (setup=1, verify=6) hardcoded in three places. | **Backloged (user-approved)**: [TECH-PKG-001](../backlog.md); all three validators currently agree and are regression-covered. |
| RF-EXP-09 | nit | exportPackage.spec.js | CLI success path had no non-leakage proof (error paths only). | **Fixed**: new spawnSync success test asserts stdout is exactly the JSON result and free of private paths. |
| RF-EXP-10 | nit | jestDiscovery.spec.js | Synthetic fixture omitted the real `licenses/npm/<name>/<version>` layout segment. | **Fixed**: fixture mirrors the extracted archive shape. |
| RF-EXP-11 | nit | jest.config.js | Regex-dot inconsistency vs the `[.]kilo.worktrees.` entry. | **Applied, then reverted**: `[.]` requires a literal dot, but on Windows the path separator is `\`, so the exclusion silently stopped matching and the full Jest run failed with real haste collisions (`vue-router` license metadata). The kilo entry's `[.]` matches the literal dot in the directory name `.kilo`; the boundary dots are separator-matching any-chars. Finding void; explanatory comment added to jest.config.js. The breakage was caught by the review gates and never left the working tree. |
| RF-EXP-12 | nit | package.json | `package:export` split the contiguous `test:*` script group. | **Fixed**: moved after the last `test:runtime:*` entry. |
| RF-EXP-13 | nit | scripts/run-package-smoke.js exports | `composeEnvironment`, `defaultImageName`, `ownerSmoke`, `runRow` dead on the edited exports line (verified: only two spec files require the module). | **Fixed**: removed from `module.exports`. |

A CRLF observation in `docs/backlog/archive/2026-09.md` was dropped by its
track as pre-existing tail content, not a finding.

## Questions and Decisions

One structured batch, answered 2026-10-11:

- **Q-EXP-01 (RF-EXP-01)** — TOCTOU hardening: **Fix now** (recommended).
  Pre-rename revalidation plus regression test applied.
- **Q-EXP-02 (RF-EXP-04)** — vacuous `passed` clauses: **Keep + comment**
  (recommended). Clauses retained as a hand-edit tripwire; role documented.
- **Q-EXP-03 (RF-EXP-08)** — case-count triplication: **Backlog item**
  (recommended). TECH-PKG-001 created in `docs/backlog/backlog.md`.

## Gates

| Gate | Result |
|---|---|
| `npm test` (Jest) | 117 suites / 1657 tests passed (1655 delivered + 2 new review tests) |
| `npm run lint` (src, tests/e2e) | green |
| Scoped eslint on changed tooling files | 0 errors (1 `import/extensions` warning consistent with sibling script style) |
| `npm run docs:encoding` / `npm run backlog:check` | green |
| Backend Docker PHPUnit | not rerun: no PHP changed; canonical 1372 unit / 604 integration evidence stands |

The first full `npm test` rerun failed (RF-EXP-11 haste collisions); after the
revert the complete suite passed. No timeout, skip or missing suite remains.

## Commit

Single scoped conventional commit on `master` staging exactly the reviewed
implementation files, review fixes, review-owned documentation (procedure,
registry, archives, backlog item) and this record. No secrets, private
values, exchange paths or gitignored content are included.
