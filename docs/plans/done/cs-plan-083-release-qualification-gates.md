# CS-PLAN-083: Release Qualification Gates — Alias-Free Runtime, Cold Start, Package Identity

> **Status:** Done (final Full PASS-WITH-FIXES, 2026-10-10; WP1-WP3 and
> RF-083-01..17 complete, renewed candidate `853f661` qualified on both rows;
> deployment, human evidence and retained-baseline adoption remain separate)
> **Effort:** L
> **Priority:** P2
> **Depends on:** delivered CS-PLAN-082 runtime inventory; separately authorized clean candidate checkpoint before package qualification (CS-PLAN-080/WP9 human evidence stays independent)
> **Related backlog items:** RELEASE-002

## 1. Problem Summary (Before Implementation)

The operator acceptance loop of 2026-10-07..10 (sanitized operations record,
handed back per finding) caught five defect classes that our own gates let
through. Each maps to a structural blind spot, not a missing assertion:

1. **Lazy-asset path** (`ChunkLoadError` on production AIO): the runtime
   chunk resolver used the hardcoded `/apps/chorestrike/js/` base while the
   app physically lives in `custom_apps`. Our canonical browser gate never
   caught it because `docker/test-entrypoint.sh:138` creates an
   `/apps/chorestrike → custom_apps/chorestrike` symlink — every gate run is
   aliased, so a wrong static-asset base is served successfully. Fixed at
   source (`src/public-path.js`), but the masking symlink still exists and a
   regression would pass again.
2. **Cold first bootstrap (first-start 403 on `room-tasks/filtered-daily` /
   `overdue`)**: the canonical gate always runs with `NEXTCLOUD_TEST_MODE=true`,
   seeds, and harness-created users. A true first install (zero houses, first
   owner via normal bootstrap, wizard, first My Day load) was never executed
   by any in-repo gate. Fixed at source; coverage now exists only in the
   manually orchestrated `tests/package/owner-smoke.cjs`.
3. **Settings hydration race** (intermittent default house name): harness
   navigation/reload raced in-flight app reads. Fixed at source; browser
   coverage exists in `settings.spec.ts` gate scenarios.
4. **Owner layout overflow at 390px**: previously only document width was
   asserted (`overflow-x: hidden` hides overflow); no positive owner-with-
   floors precondition. Covered since in `settings.spec.ts` gate scenarios.
5. **Package/source identity drift** (built bundle missing the nullish guard
   that the pushed commit contained; 1477 vs. 1479/1518 test-count mismatch):
   there is **no in-repo packaging gate**. Archive assembly, file hashing,
   source-vs-commit comparison, and asset-contract verification are performed
   manually by the operator, per handoff, on their side.

Defect classes 1–4 are fixed at source (commit `e21643a` line). What remains
is closing the gate blind spots so the next regression is caught here, not in
an external acceptance round. Class 5 has no in-repo equivalent at all.

Existing pieces to build on, not rebuild: `docker/package-smoke-entrypoint.sh`
(alias-free fixture, refuses an existing alias, test markers false),
`tests/package/owner-smoke.cjs` (six owner cases de-DE/de/en ×
390x844/1440x900, exact `custom_apps` URL/MIME/byte assertions, cold-start
`setup` action), the alias-free documentation section in
`docs/engineering/testing.md`, and the NC34 compatibility row in
`docker/test-runtime.json` + `scripts/test-runtime.js`.

## 2. Strategy

Three work packages, each independently reviewable. The implementing agent owns
WP1-WP3, tests and documentation under RELEASE-002's bounded qualification slice;
an independent final Full review checks the new safety/identity contracts.
Deployment, retained-baseline adoption, external publication and WP9 human
evidence remain outside this plan. RELEASE-002 is not closed by this delivery.

- **WP1 — Alias-free canonical gate.** Remove the deployment masking from the
  default test environment: remove symlink creation without a legacy toggle.
  Configure the complete `apps_paths` array atomically before app-dependent
  OCC/version/upgrade calls, retaining the core read-only `/apps` path and
  writable `/custom_apps` path. Validate existing configuration before mutation;
  refuse unexpected paths or an existing alias rather than deleting data or
  overwriting unrelated app roots. Document recreation of the owned container
  to remove its old filesystem alias while retaining config/data/DB volumes.
  Fresh start and populated restart must both prove alias absence, working
  app discovery and unchanged existing facts. Audit specs for static-asset URL
  assumptions under
  `/apps/` and tighten them to the exact `custom_apps` base; page/API URLs
  `/apps/chorestrike/...` stay untouched (index.php routing is independent of
  the physical app root — confirmed by the production repro where API routes
  worked while assets 404'd). From then on, every ordinary gate run proves
  the production layout.
- **WP2 — Package identity gate.** A `scripts/build-package.js` gate that
  packages only from a clean, HEAD-pinned checkout (a dirty or detached tree
  is a hard failure, matching how the drift happened), builds the production
  bundle **inside a digest/platform-pinned Docker builder image** (explicit
  Node/npm/PHP/Composer versions satisfying the repository engines/lockfiles;
  no host-OS path-separator leakage — the Windows-built `\\apps\\` base was
  part of finding 1), assembles the runtime archive (no source maps, complete
  licence texts, `0:0` ownership, `0644/0755`, safe paths), and emits:
  package manifest (per-file SHA-256), source manifest, source-vs-git-blob
  comparison with documented CRLF/LF provenance, and an asset-contract check
  (every template/controller-referenced asset exists in the build output;
  every emitted lazy chunk has a registered load site or an explicit
  exception). Machine-readable handoff record in `build/` (gitignored),
  reproducible by the operator byte-for-byte. Export tracked build inputs from
  the captured Git blobs, not ignored workspace `js/`, `vendor/` or
  `node_modules/`. Record CRLF/LF provenance separately; non-line-ending drift
  fails. Use locked `npm ci` and production Composer dependencies, a runtime
  file allowlist, complete licenses, and fail on traversal, links or forbidden
  files (including private data, source maps, dev tooling and reports).
  Add an explicit commit-derived `SOURCE_DATE_EPOCH` packaging input to
  `webpack.config.js` instead of `Date.now()` for qualified builds; normal dev
  builds keep their current behavior. Normalize archive ordering, times and
  compression metadata. Two independent clean builds of the same commit and
  pinned tools must produce equal archive and per-file hashes. Volatile run
  IDs/times live outside the reproducible artifact/manifests. Use Webpack stats
  and a reviewed explicit inventory of template/controller references to check
  reachability, including shared/vendor chunks; no basename-only heuristics or
  arbitrary dynamic-PHP analysis. Exceptions are named and reviewed.
- **WP3 — Wired package smoke.** Replace the manual target-JSON orchestration
  with one command: `docker/compose.package-smoke.yml` (unique owned project
  per run, loopback-only, both test markers false, read-only package mount,
  `org.chorestrike.package-smoke=true` label, a separate fail-closed package
  validator using existing `owner-smoke.cjs` checks without weakening
  `scripts/test-target.js`'s true-marker requirements) plus `scripts/run-package-smoke.js`
  driving build-package → environment up → `owner-smoke.cjs setup` →
  `verify` (all six cases) → evidence record → teardown without deleting
  failed fixtures. Runs on the NC35 canonical image and the NC34
  compatibility row before any handoff; it supplements, never replaces, the
  full browser gate. Build once and safely extract the resulting archive;
  mount those manifest-verified bytes for both rows, not a staging directory
  that could differ from the archive. Bind evidence to commit/input hashes,
  archive hash, runtime selection/image identity and unique run/row. Before
  mutation verify owned project/service/volumes, numeric loopback HTTP/OCC
  coherence, frozen runtime/image, both false markers and exact read-only mount.
  Reuse the current `cs082-*`/`nextcloud-pgsql` target contract rather than
  inventing an incompatible namespace. Never reuse a failed first-install
  fixture as fresh evidence. Missing/stale reports, failed or interrupted
  subprocesses and package/input drift fail qualification. Rehash after both
  runs; cleanup may affect only resources created and verified for that run,
  and failed fixtures/evidence are retained, without volume resets or retries
  that turn a failed cold start into a pass.

No app runtime behaviour change is intended. If WP1 exposes real static-asset
assumptions in app code, they are fixed as plan-owned findings (§9).

### WP1 Static-Asset Audit (2026-10-10, before entrypoint changes)

Inventory of every static-asset URL assumption in `tests/` and `src/`
(`/apps/chorestrike/js|css` literals, basename/hash-only acceptance):

- `src/`: **none.** `src/public-path.js` derives the `js/` base at runtime from
  `window._oc_appswebroots` via `generateFilePath` (fixed at source in `e873fa8`);
  unit resolver coverage in `tests/unit/scripts/assetContract.spec.js` exercises
  genuine `/apps` installs, `custom_apps` and subdirectory deployments — these
  are distinct physical roots, not aliases.
- `templates/main.php` / `lib/Controller/PageController.php`: register only
  `chorestrike-main` through the app-id script API; no URL literals.
- `tests/e2e/minimal-smoke-tests.spec.ts`: **found assumption** — the lazy-asset
  assertions accepted any basename (`endsWith('/chorestrike-today.js')`), so a
  regression serving from the legacy `/apps/chorestrike/js/` base would still
  pass. Tightened to the exact `/custom_apps/chorestrike/js/` base with HTTP 200
  and JavaScript MIME for every fetched app script.
- `tests/package/owner-smoke.cjs`: already asserts the exact `custom_apps` URL,
  HTTP 200, JavaScript MIME and byte equality with the mounted package.
- No other `/apps/chorestrike/js|css` literal exists in `tests/` or `src/`
  (repository-wide search). Result: one test-code tightening, no app runtime
  change, no plan-owned finding required.

Qualification requires a separately authorized implementation candidate commit
or an eligible interim review commit before the clean-HEAD gate can execute.
This Standard A authorizes only its own review commit. No auto-commit, stash or
dirty-tree bypass belongs in the package runner. Final review evidence may be a
later docs-only commit: record the exact candidate SHA and prove its runtime,
build and test inputs unchanged; otherwise rebuild and rerun affected gates.

## 3. Backend / Frontend Changes

- **Backend** (`lib/`): none intended.
- **Frontend** (`src/`): none intended. `tests/e2e/minimal-smoke-tests.spec.ts`
  asset assertions tightened to the exact `custom_apps` base (test code).
- **Build tooling:** deterministic packaging input in `webpack.config.js`,
  pinned builder definition and explicit package/asset inventories.
- **Cross-layer contracts:** the static-asset base contract
  (`window._oc_appswebroots` → `src/public-path.js` → emitted chunks) becomes
  a mandatory canonical-gate contract, supplementing existing package coverage;
  documented in `docs/engineering/testing.md` and the deployment docs.

## 4. Tests

- **Unit (Jest):**
  - `build-package.js` pure checks, self-test style after
    `scripts/run-e2e-gate.js`: dirty-tree refusal, detached-HEAD refusal,
    manifest hash stability, CRLF/LF-only classification vs. content drift,
    archive metadata rules (no maps, safe paths, modes), asset-contract
    dangling-reference and orphan-chunk detection (invalid fixtures must be
    rejected; valid shared/vendor chunk graphs must pass). Assert deterministic
    epoch handling, runtime allowlist, locked dependency inputs and rejection
    of symlinks/traversal/forbidden archive members.
  - `run-package-smoke.js` target/environment validation: rejects missing
    label, non-loopback binding, test markers true, writable package mount,
    absent alias-refusal — no browser launched in self-test. Exercise the real
    orchestration with injected subprocesses: mismatched HTTP/OCC/image/row,
    archive-vs-extraction drift, post-run drift, missing/stale/wrong-run reports,
    failed/interrupted setup or verify, resource collisions and foreign cleanup
    targets must fail before unsafe mutation or a success report. Existing
    true-marker target guards must retain their negative coverage.
- **Tooling integration (Docker):** two independent WP2 builds must have equal
  archive/per-file hashes; both runtime rows consume the same extracted archive.
  WP1 fresh start and populated container recreation retain config/data/DB facts,
  refuse unexpected existing paths/aliases without deletion, and prove app
  discovery/static assets without the alias. Extend the existing PHPUnit
  `TestEnvironmentProvisioningTest` and shell command fixture for setup ordering,
  refusal and preservation behavior; replace its explicit symlink assertion
  with the safe app-discovery assertion. Browser success alone is insufficient.
- **Integration (PHPUnit, Docker):** none (no backend change); existing
  backend gates must stay green.
- **E2E (Playwright):**
  - Full canonical browser gate (`npm run test:e2e:gate`) green on the
    alias-free default environment; `required-scenarios.json` updated if any
    scenario title changes.
  - The same gate executed once against the NC34 compatibility row
    (`scripts/test-runtime.js` selection) to prove both deployment lines.
  - Package smoke `setup` + `verify` green via WP3 on both runtime rows,
    including the cold-start first-bootstrap assertions and all six
    locale/viewport owner cases.

## 5. File Manifest

```
new:      scripts/build-package.js
new:      scripts/run-package-smoke.js
new:      scripts/package-target.js (shared package-only safety policy)
new:      scripts/package-licenses.js (bundled dependency license closure)
new:      docker/package-license-overrides.json
new:      docker/package-license-texts/ (version-bound upstream MIT texts)
new:      docker/compose.package-smoke.yml
new:      docker/Dockerfile.package (pinned builder/toolchain)
new:      docker/package-build.json (tool pins, runtime allowlist, asset inventory)
new:      tests/unit/scripts/buildPackage.spec.js
new:      tests/unit/scripts/runPackageSmoke.spec.js
new:      tests/package/fixtures/ (self-test fixtures: dirty-tree, drift, contract)
modified: docker/test-entrypoint.sh (alias removal, safe complete apps_paths setup)
modified: tests/unit/Command/TestEnvironmentProvisioningTest.php (setup/order/refusal regressions)
modified: tests/fixtures/provision-command.sh (complete app-path and refusal fixtures)
modified: webpack.config.js (explicit deterministic packaging epoch)
modified: tests/unit/scripts/assetContract.spec.js (deterministic build contract; preserve resolver coverage)
modified: tests/package/owner-smoke.cjs (only if shared package validation needs changes)
modified: docker/package-smoke-entrypoint.sh (only if runtime identity/readiness needs changes)
modified: docker/README.md
modified: tests/e2e/minimal-smoke-tests.spec.ts (exact custom_apps asset base)
modified: tests/e2e/required-scenarios.json (only if titles change)
modified: package.json (test:package:* scripts)
modified: docs/engineering/testing.md (wire WP2/WP3 into the gate docs)
modified: docs/engineering/deployment.md (handoff produced by the gate)
modified: docs/backlog/backlog.md (RELEASE-002 fold-in note)
modified: docs/backlog/ACTIVE_TASKS.md
```

## 6. Implementation Order

1. **WP1 audit:** inventory every static-asset URL assumption in `tests/` and
   `src/` (`/apps/chorestrike/js|css`, basename/hash-only acceptance); record
   the list in the plan before touching the entrypoint.
2. **WP1 switch:** alias removal and safe `apps_paths` setup in `test-entrypoint.sh`, tighten
   `minimal-smoke-tests.spec.ts`, run the full canonical gate alias-free
   (NC35), then once on the NC34 row; fix any exposed assumption in-round.
3. **WP2:** builder definition, deterministic Webpack input, explicit inventories,
   `build-package.js` and unit tests. Obtain a separately authorized candidate
   checkpoint, then produce two real clean-HEAD packages and prove reproducibility
   and manifest/git-blob identity. Do not run an older committed runner as proof
   of uncommitted changes.
4. **WP3:** compose lane + `run-package-smoke.js`; execute setup+verify on
   NC35 and NC34 against the WP2 package; keep failed fixtures on red.
5. **Docs:** integrate the asset-base contract, the packaging contract and
   the one-command qualification flow into `docs/engineering/testing.md` /
   `deployment.md`; note the fold-in on RELEASE-002.
6. Final gates: `npm test`, lint, backend Docker tests, canonical browser
   gates on both rows and package qualification with exact candidate identity;
   final Full review for the provisioning/data-preservation and target-safety
   boundaries (canonical review tier rules), not Standard B alone.

## 7. Risks & Mitigations

| Risk | Likelihood | Mitigation |
|------|-----------|------------|
| Hidden static-asset `/apps/` assumptions or missing app discovery surface when the symlink is dropped | Medium | WP1 audit first; explicit apps_paths, fresh/populated restart checks, no alias fallback or volume reset |
| Windows-host builds leak path separators / nondeterministic tar metadata | Medium | WP2 builds and packages inside the pinned Docker builder image; host builds remain dev-only and are rejected by the identity check |
| NC34 row image build time inflates the loop | Low | Reuse `docker/test-runtime.json` compatibility selection and existing image cache; raised timeouts, background execution |
| Package lane adds minutes to a per-change workflow | Low | WP3 is a pre-handoff qualification gate, not part of the per-change canonical gate; documented as such |
| Private evidence paths leak into tracked files | Low | All reports/manifests under gitignored `build/`; docs record commands and expectations, not operator paths |
| Timestamp/toolchain drift defeats byte reproducibility | Medium | Commit-derived epoch, immutable builder/tool versions, locked installs and two independent builds; fail rather than downgrade the guarantee |
| Clean-HEAD gate cannot qualify uncommitted implementation | Medium | Separately authorized candidate checkpoint; exact candidate identity survives docs-only review commits, otherwise rerun |
| Package validator weakens test guards or cleanup affects another fixture | Medium | Separate false-marker policy, ownership/identity checks before mutation, orchestration negative tests and retained failed fixtures |

## 8. Acceptance Criteria

- [x] Canonical browser gate passes with **no** `/apps/chorestrike` symlink
      present (entrypoint asserts alias absence by default), on NC35
      canonical and once on the NC34 compatibility row.
- [x] Safe complete `apps_paths` setup is verified on fresh start and populated
      recreation without losing config/data/DB facts; unexpected configuration
      or aliases fail closed, and no legacy alias toggle exists.
- [x] `minimal-smoke-tests.spec.ts` asserts the exact
      `/custom_apps/chorestrike/js/` base for every fetched app script
      (HTTP 200, JavaScript MIME); no canonical alias-free browser assertion
      accepts the legacy asset base. Preserve unit resolver coverage for genuine
      `/apps` installations and subdirectory deployments; these are not aliases.
- [x] `npm run test:package:build` from a clean HEAD produces archive +
      package/source manifests + git-blob identity proof, and refuses a dirty
      or detached tree (unit-proven).
- [x] Two independent builds of the same clean candidate and pinned tools have
      identical archive/per-file hashes; runtime allowlist, production dependencies,
      licenses, safe archive metadata and deterministic Webpack epoch are verified.
- [x] Asset-contract check fails on a dangling referenced asset and on an
      orphaned chunk (negative unit fixtures), and passes on the real build.
- [x] `npm run test:package:smoke` runs build → alias-free environment →
      `owner-smoke.cjs setup` + `verify` (six cases) green on NC35 and NC34
      without manual target JSON.
- [x] Both package rows use the same extracted, hash-verified archive; evidence
      binds candidate/input/archive/runtime/run identities before and after.
      Target, report, failure/interruption and cleanup negative controls pass
      without weakening existing test guards or deleting failed fixtures.
- [x] No app runtime code changed unless recorded as a resolved §9 finding.
- [x] Build gate green (`npm test` + lint + backend tests)
- [x] Required tests ran without new or unapproved skips
- [x] All implementation decisions and review questions resolved and recorded
- [x] All plan-owned review findings (§9) resolved or user-rescoped to `backlog.md`
- [x] Owning backlog items updated or archived according to their actual
      completion state (RELEASE-002 fold-in recorded)
- [x] Durable concepts/decisions integrated into the permanent docs
      (`docs/engineering/testing.md`, `docs/engineering/deployment.md`)
- [x] Final Full review is `PASS` or `PASS-WITH-FIXES`
- [x] `docs/backlog/ACTIVE_TASKS.md` updated

## 9. Review Findings (plan-owned)

Findings **within this plan's scope** from implementation rounds or reviews
(scope-first routing per `project-rules.md` §4). Resolve before `done/`;
rescoping to `backlog.md` needs explicit user approval. Out-of-scope findings
go directly to `backlog.md` (or fold into an existing item) instead.

- [x] RF-083-01 (major) - WP1 omitted app discovery and populated lifecycle safety while proposing an unjustified alias fallback. Q-083-01 approved removal without fallback, explicit safe apps_paths and fresh/populated proofs; corrected in sections 2, 4-8.
- [x] RF-083-02 (major) - WP2 promised byte reproducibility despite Date.now() and lacked pinned builder/production dependency ownership and a clean candidate checkpoint. Q-083-02 approved deterministic build inputs, two-build proof and explicit checkpoint; corrected in sections 2-8.
- [x] RF-083-03 (major) - WP3 did not define the false-marker validation boundary, archive-to-mount chain, evidence identity and safe failure/cleanup contract. Q-083-03 approved separate package safety checks without weakening test guards; corrected in sections 2, 4-8.
- [x] RF-083-04 (minor) - File/test manifest omitted provisioning/build prerequisites and final review allowed Standard B despite data-preservation/target-safety scope. Manifest and negative tests expanded; final Full required. Existing package asset coverage is no longer described as absent.

These four were resolved **plan-design findings** at Standard A, not claims of
implementation/runtime acceptance. Completion is established by the final Full
outcome and renewed evidence below.

### Full Review Implementation Findings (2026-10-10)

- [x] RF-083-05 (major) - `scripts/run-package-smoke.js:165` (review base): orphan volumes/networks and cleanup ownership. Authorized resource collision/label/stable-identity fix; negative regressions and both six-resource smoke/teardown proofs pass.
- [x] RF-083-06 (major) - `scripts/run-package-smoke.js:124`: subset extraction hashing/link following. Authorized new-directory/exact-inventory fix; extra-file/directory/link and existing-directory regressions plus both rehash proofs pass.
- [x] RF-083-07 (major) - `scripts/run-package-smoke.js:275`: OCC-only coherence. Authorized direct HTTP/OCC readiness/version comparator; real mismatch/unready regressions and both live preflights pass.
- [x] RF-083-08 (major) - `scripts/build-package.js:83`: failed Git status accepted as clean. All identity probes now require success; negative tests and clean-candidate builds pass.
- [x] RF-083-09 (major) - `docker/test-entrypoint.sh:149`: failed apps_paths read treated as absence. Explicit JSON default sentinel distinguishes absence/failure; no-import/no-seed refusal test and fresh/populated NC35/NC34 proofs pass.
- [x] RF-083-10 (major) - `tests/package/owner-smoke.cjs:12`: divergent target policy. Authorized shared `scripts/package-target.js` enforces both entry paths; negative regressions and live setup/verify pass.
- [x] RF-083-11 (major) - `scripts/build-package.js:571`: mutable source/candidate identity. Archive/tree/epoch/config/Dockerfile use captured SHA; final drift/manifest comparisons, injected mismatch tests and renewed identity records pass.
- [x] RF-083-12 (major) - `scripts/build-package.js:478`: notices without complete third-party texts. Actual-module collection emits 145 dependency records and 293 license/attribution files with verified hashes; missing/notice-only negatives pass. No legal license-compatibility conclusion.
- [x] RF-083-13 (major) - `scripts/build-package.js:187`: host-dependent gzip. Final compression runs in pinned Linux Node/zlib and records identity; two builds and independent Node24-host recompression match the Node20-host qualification bytes (Linux gzip OS=3).
- [x] RF-083-14 (minor) - `scripts/build-package.js:485`: client timeout could leave builder alive. Token/name-owned lookup and exact-ID cleanup regression passes; live builds/compression leave no owned builder.
- [x] RF-083-15 (minor) - `docker/README.md:34`: Bash continuations in PowerShell examples. Fixed to single-line commands; documentation gates pass.
- [x] RF-083-16 (major) - post-fix Architecture track: case-insensitive POSIX mount comparison. Normalize case only for Windows drive paths; regression and independent actual-POSIX refusal probe pass.
- [x] RF-083-17 (major) - post-fix Correctness track: two-build candidate drift. Compare complete candidate/source/tool manifests as well as bytes/files; equal-archive/different-SHA regression, independent recheck and real pair pass.

### WP1 Gate Evidence (2026-10-10)
- Populated recreation of the owned canonical container (config/data/DB volumes
  retained from the pre-WP1 stack): entrypoint imported the complete
  `apps_paths` array into the existing config volume, verified discovery
  (`/apps` read-only + `/custom_apps` writable), completed setup without
  reseeding; `/var/www/html/apps/chorestrike` absent (file and symlink);
  app enabled; `/custom_apps/chorestrike/js/chorestrike-main.js` served HTTP
  200 `text/javascript`; the legacy `/apps/chorestrike/js/...` path answers
  404 — no alias fallback exists.
- Fresh start (`down -v`, family profile): same alias-free provisioning path;
  canonical browser gate `run-11fa0O` PASSED on NC35 (all three pools, zero
  skipped/failed/flaky; the tightened `minimal-smoke-tests.spec.ts` exact
  `custom_apps` base assertions ran green in the `chromium` pool).
- NC34 compatibility row: `cs082-runtime-34.0.4-local` staged via
  `scripts/test-runtime-stage.js` (installed identity 34.0.4.1 verified),
  browser gate PASSED against `http://127.0.0.1:18081`; owned staging torn
  down with its volumes.
- Backend: PHPUnit unit 1371 and integration 604 green in the alias-free
  container (includes the new provisioning setup/order/refusal regressions);
  full Jest 116 suites / 1571 tests green on Node 20.19.0/npm 10.8.2; lint,
  docs encoding and backlog checks green.
- Environment note: two earlier gate attempts failed for environment reasons,
  not code defects — once on 3-day-old seeded dates after volume-retained
  recreation, once on the `minimal` profile when the gate requires `family`;
  both are operator environment variables, recorded here for reruns
  (`$env:TEST_DB_PROFILE='family'` before `up`).

## WP2/WP3 Qualification Evidence (2026-10-10, Historical)

The following original run remains immutable historical evidence. The final
Full review found additional gate-contract gaps; the renewed evidence below
supersedes it for this plan's completion.

- Candidate: `325f23b7e051ec49c82846d87e6dace4f70fb52d` (master, clean tree;
  implementation commits `130e192`..`325f23b` including the in-qualification
  hardening fixes the first real runs exposed: pax long-name support, git
  blob-ID comparison, cache-bust query normalization, map-free package
  builds, multi-stage PHP 8.4 builder, read-only archive mount).
- Two-build reproducibility proof at the candidate
  (`node scripts/build-package.js --verify-reproducible`, build/package/repro-325f23b):
  two independent builds inside the pinned digest-verified builder produced
  the identical archive SHA-256 `36aa2c5f60d32de4c70f48ae7e25dac78664d1555e386f26369e197dd362a498`
  (229 runtime files, per-file manifest identical, no source maps, 0:0 /
  0644-0755 metadata, commit-derived `SOURCE_DATE_EPOCH`).
- Wired package smoke (`npm run test:package:smoke`, run-mv2d82rq): the same
  extracted, manifest-verified archive bytes qualified on the NC35 canonical
  row (35.0.1, image `cs082-runtime-35.0.1-local`) and the NC34 compatibility
  row (34.0.4, image `cs082-runtime-34.0.4-local`); owner-smoke `setup` (cold
  first bootstrap) plus all six owner cases (de-DE/de/en × 390x844/1440x900)
  passed on both rows; evidence binds candidate, archive hash, frozen image
  IDs and unique run/row identities before and after; owned teardown removed
  both projects' containers/networks/volumes.
- Qualification record: `build/package-smoke/run-mv2d82rq/qualification.json`
  (gitignored). RELEASE-002 remains open (deployment/adoption/field use).

## Renewed Full Review Qualification (2026-10-10)

- Clean candidate: `853f661f71e7916ad02e52426e5346fff4eb72c7`, `master`,
  commit epoch `1791642259`; user-authorized implementation checkpoint.
  Two independent package builds (`build/package/run-mv2hk5df`) and the
  separately built smoke archive have identical SHA-256
  `885f57e0306d8dae3d53190cc0aa605c998a07b8609751b7d11cfccf6584edd7`
  (1,657,643 bytes, 522 files). Complete source/tool/file manifests match,
  source-vs-blob and asset-contract proofs pass; no source maps.
- Final compressor: Linux/amd64 Node v20.19.0, zlib
  `1.3.0.1-motley-82a5fec`, gzip OS byte 3. Independent recompression called
  from host Node 24.13.1 produces the same bytes; host zlib is not a package input.
- The actual archive contains 145 dependency license records and 293
  license/attribution files; every text hash matches its inventory, including
  full AGPL/GPL and DOMPurify Apache/MPL texts. The source records explain
  locked-package standard-text fallbacks and version-bound upstream MIT omissions.
- Wired smoke `build/package-smoke/run-mv2hs127/qualification.json`: both
  NC35 35.0.1.1 and NC34 34.0.4.1 pass cold setup plus six owner cases;
  exact extraction, direct HTTP/OCC parity, false markers, frozen image
  identity, six owned resource identities per row and owned teardown verified.
- Fresh family provisioning and populated same-build recreation pass on newly
  owned `cs082-review083-nc35-20261010` and `cs082-review083-nc34-20261010`.
  Before/after ChoreStrike data-only dump hashes are unchanged:
  NC35 `e3a7838ded5a4d3ae62683b1bf0e23e4387760490307a2ca65bd1a7a37ea5f83`,
  NC34 `265a7108833cd8787410d452887ca3c41a7981ab798f89985ad62bad5db612d0`.
  Before/after config hashes also match: NC35
  `99f9c931cfdb7d25ec3b26f1b8144745568f698437a7a2a16a077c936c74173d`,
  NC34 `29f84c31df00828e8720b93c505c269ea20eb8693e219f64b7ed0d4f62a4a089`.
  Config/data/DB named volumes survive; aliases
  stay absent and complete app-root discovery/installed identity is reverified.
- Full browser pair: NC35 `run-IDVdFy`, NC34 `run-EpjkYH`. Each has
  88/168/39 first-attempt passes across the three pools (295 executions,
  including setup); 49 required specs uniquely owned, no failed/flaky/skipped
  required scenario and no request/test retries. Exact alias-free asset-base
  assertions remain mandatory.
- Supported host Node 20.19.0/npm 10.8.2: Jest 116 suites / 1,606 tests;
  lint and precommit lint fixer green. PHPUnit 1,372 unit + 604 integration
  tests pass with `--fail-on-skipped` on each runtime row. Script self-tests,
  docs encoding and backlog/link checks pass. Already frozen upstream NC35
  notifications-lock and NC34 platform limitations remain unchanged, not suppressed.
- Raw reports/builds remain gitignored. The final review commit is documentation,
  tracking and plan-transition only; it is not a rebuild or a claim that the
  archive came from the later evidence commit. No deployment/adoption/human-gate closure.
  After all tests, both newly owned review fixtures were identity/volume-verified
  again and removed with their volumes. The canonical stack was not torn down.

## Review Decisions

Full-review remediation batch answered on 2026-10-10:

- Q-083-04: **Authorize safety fixes** - explicitly authorized the six local tooling safety/validation fixes (RF-083-05..10), shared package-only policy and regressions. No demonstrated remote app vulnerability; no safety change was auto-fixed.
- Q-083-05: **Authorize packaging fixes** - authorized immutable candidate binding, complete dependency-license packaging, pinned final compression and owned-builder cleanup (RF-083-11..14), tests and renewed qualification. No candidate checkpoint is implied by this decision; that remains separately authorized.
- Q-083-06: **Authorize checkpoints (Recommended)** - explicitly authorized scoped implementation candidate checkpoint commits for this remediation and any qualification-exposed fixes, followed by the single eligible final review commit. No push, deployment or baseline adoption.

Structured batch answered on 2026-10-10; all recommended options selected:

- Q-083-01: **Remove alias (recommended; English translation)** - remove the alias without a
  legacy toggle, configure app roots safely and preserve existing volumes/facts.
  Alternative: leave the plan in new/ pending a concrete legacy consumer/expiry.
- Q-083-02: **Build deterministically (recommended; English translation)** - immutable builder inputs,
  commit-derived epoch, locked production dependencies, runtime allowlist and
  independent two-build byte proof, with a separately authorized clean candidate.
  Alternative: narrow the promise to single-artifact identity, not reproducibility.
- Q-083-03: **Separate safety validation (recommended; English translation)** - separate false-marker package
  validation, same-archive qualification on both rows, identity-bound evidence and
  ownership-safe failure/cleanup tests; existing true-marker guards unchanged.
  Alternative: keep WP3 open and the plan unactivated pending its safety contract.

## Review Outcome

- **2026-10-10 - Final Full: PASS-WITH-FIXES.** Whole WP1-WP3 domain, plan
  manifest, history, acceptance criteria, durable docs and required gates reviewed.
  Four independent read-only tracks completed, then independently rechecked;
  all 13 implementation findings (RF-083-05..17) resolved under explicit
  Q-083-04..06 authorizations. No outstanding finding/question or deferred test.
- Initial clean baseline: `master`, HEAD
  `52423a077beab8bb91a900e1d0e06d2eff2ae844`; empty index/untracked set,
  staged and unstaged binary-diff SHA-256 both
  `e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855`.
  Sorted initial Git-tree fingerprint:
  `c42727ad3e5c444120b4594c00944d4e887a8f905f06f1e1edb34f9ee99473d7`.
  Candidate Git-tree fingerprint:
  `2646655313fd5d96decdcb743af446299676838d901690bced1059a43caf4ef1`.
  Complete reviewed `e21643a..853f661` binary-diff fingerprint:
  `3c38b6e0aea76f1f0f30c54b859082a1566f5202159b937cd7b286744056b497`.
  Exact 32-path manifest and final gate evidence are in the dated review record.
- Fixes: fail-closed identity/configuration probes, resource ownership/exact
  extraction, shared target/HTTP-OCC validation, immutable candidate binding,
  complete bundled licenses, pinned compression, builder cleanup and safe docs.
  Gates: renewed qualification above; final docs/link/whitespace checks rerun
  on the scoped transition before the final review commit.
- Transition: `active/` to `done/`; durable contracts integrated in engineering
  testing/deployment docs. RELEASE-002 receives this completed bounded slice
  only and stays open for deployment/adoption/field use. CS-PLAN-080's human
  evidence and the retained-baseline adoption boundary are unchanged.

- **2026-10-10 - Standard A: PASS-WITH-FIXES.** Scope: this plan only, with
  implementation contracts inspected read-only; no subagents. RF-083-01..04
  resolved in the plan; Q-083-01..03 answered in one structured batch above.
- Base: `master`, HEAD `e21643a27b7a0963cd2a4b5cd7484f2e7cf09769`.
  Initial reviewed manifest: `docs/plans/new/cs-plan-083-release-qualification-gates.md`
  (untracked); related existing drafting change: `docs/backlog/ACTIVE_TASKS.md`.
  Index empty; staged binary-diff SHA-256
  `e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855`;
  unstaged binary-diff SHA-256
  `b8d4a7287a835da9e926d14c1e0fb3938cb1c38bf19b23d779898dafe8ef7e18`;
  original plan byte SHA-256
  `c117a48d190261f413fcf0db3431e108652dbefe478eef73fcbd762fb18febed`.
  Complete captured fingerprint:
  `e69348cd4b13eecee8ed66ff0de1fe1e0911bb6c97dcaa245065e5e00e25ef91`.
- Gates: full Jest `npm test -- --runInBand` (114 suites, 1,518 tests, no skips)
  and check-only `npm run lint` pass on supported Node 20.19.0/npm 10.8.2.
  Documentation encoding (920 tracked files) and backlog/link validation (33
  scoped files) pass on the staged transition; rerun these and both diff
  whitespace checks after the final evidence edits and before commit.
  Docker/backend/browser execution is not part of this docs-only Standard A;
  all implementation/runtime gates in sections 4 and 8 remain mandatory.
- Transition: `new/` to `active/`; RELEASE-002 becomes In Progress for this
  bounded qualification slice, with deployment/adoption/field use still open.
  No runtime implementation, package qualification, deployment or adoption is
  claimed. Review record: [2026-10-10](../../backlog/archive/CODE_REVIEW_2026-10-10.md).
