# Plan Activation: CS-PLAN-080 WP9 Cross-Database Verification and Family Evidence — Standard A

**Review Date**: 2026-10-03
**Reviewer**: Kilo (main agent, no subagents — Standard A tier)
**Mode**: Standard A (plan review before `new/` → `active/`)
**Scope**: `docs/plans/new/cs-plan-080-wp9-cross-db-and-family-evidence.md` only (documentation round; runtime tree untouched)
**Base Commit**: `821195d853c43a2fc32e22b4bf9ea4f0fe2f0f7b` (`master`; index empty at capture — staged-diff sha1 `e69de29bb2d1d6434b8b29ae775ad8c2e48c5391`; unstaged-diff sha1 `fc62c603a31d55e4de8b006ff9c0a43012219852` covering the draft-support tracking package plus the unrelated pre-existing `.gitkeep` deletions, preserved unstaged; untracked plan SHA256 `10C41B27785AB2EEFA5A6E3B8E2751B6259F804B8855BEAC2B2B3E45F61EE9F1`)

---

## Executive Summary

### Outcome
- PASS

### What Was Reviewed
- The complete WP9 child plan: problem summary with verified drafting baseline, strategy and scope/non-goals, isolated database matrix, portable integration/concurrency proofs, fixture and browser-gate reconciliation, family evidence and durable knowledge, required coverage matrix (DB-01..05, UI-01..04, HUMAN-01/02), runtime gate procedure, human protocol/evaluation, file manifest, implementation order, risks, acceptance criteria.
- Eligibility: sole regular non-symlink `*.md` in `docs/plans/new/` (`LinkType` empty — regular file); destination `active/` did not exist; no ambiguity.

### What Was Fixed Immediately
- Nothing — zero findings.

### Main Risks Remaining
1. None blocking. Implementation-phase risks (shared-environment reset, non-MySQL skips masquerading as portability, SQLite toy-lock tests, stale candidates, family-evidence privacy, silent parent closure, inferred RF-070 completion, obsolete-build observation) are named in plan §7 with mitigations.

---

## Verification Performed

- **Baseline citations exact against the runtime:** `docker/compose.test.yml` uses MariaDB 10.6 with fixed `container_name`s and host port 8080; `docker/test-entrypoint.sh` uses `mysqladmin`/`mysql` readiness probes and hardcodes `maintenance:install --database='mysql'` (a compose DB substitution alone cannot produce a valid PostgreSQL or SQLite installation); `docker/Dockerfile.test` installs `pdo_mysql` and `pdo_pgsql` but not `pdo_sqlite` (the plan correctly requires verifying actual SQLite driver availability); `docker/compose.test-matrix.yml` does not exist; `HouseMutationConcurrencyTest.php:448-497` holds only an isolated toy-schema SQLite write-acquisition proof (not a full migration/service proof); `RewardConcurrencyTest.php:30-34` calls `markTestSkipped` on non-MySQL providers; `scripts/run-e2e-gate.js` runs exactly `chromium`, `chromium-serial-db` and `chromium-scheduling-owner` with `--workers=1 --retries=0 --forbid-only` and rejects missing scenarios, skips, failures, flaky and interrupted results; `docs/concepts/ui-use-cases.md` `### Family Acceptance Protocol` (anchor `#family-acceptance-protocol` matches the plan link) contains the C5j rules; parent CS-PLAN-070 RF-070-01..17 include open and partially delivered items.
- **Manifest:** every `N` path absent (`docker/compose.test-matrix.yml`, `tests/integration/Db/SupportedDatabaseSchemaTest.php`); every `M` path present (entrypoint/Dockerfile, `IntegrationTestCase.php`, `TestDataService.php`, `TriggerJobs.php`, fixture files, all nine named E2E specs, `required-scenarios.json`, `playwright.config.ts`, `run-e2e-gate.js`, the four durable-doc paths); `phpunit.integration.xml` exists and PHPUnit `^9.5` supports `--fail-on-skipped`; `occ chorestrike:test-data:seed --profile=family` exists (`lib/Command/TestDataSeed.php`); `npm run stylelint|test:noise|docs:encoding|backlog:check|l10n:check-keys` and `composer cs:check` all exist; ADR-013..017 and `docs/testing/release-test-plan.md` exist.
- **Parent alignment:** CS-PLAN-070 authorizes exactly this WP9 slice (WP9 row with the 4-6-engineering-day + 14-calendar-day allowance matching the plan header, T12 mapping row, G3 final-evidence gate); the plan's UI-01..04 rows map the same delivered specs and correctly treat the parent's 2026-09-18 N/M labels as historical; no overlap with any other active plan (the parent is the only one).
- **Plan-only checks:** nine template sections complete; implementation order with per-step ownership and estimates; coherent single-slice scope with explicit non-goals; risks with likelihoods and mitigations; no pre-baked workaround stacking (provider-specific production workarounds and blanket non-MySQL exclusions are explicitly forbidden); twelve complete acceptance criteria; concrete test plan (coverage matrix with primary ownership, runtime gate procedure with exact compose/phpunit/E2E commands, human protocol with consent, anonymity and synthetic-case rules).

## Findings

| Severity | File:line | Issue | Status |
|---|---|---|---|
| — | — | none | — |

No open findings to route (plan-owned, fold-in, or backlog).

## Decisions

None required — the parent authorizes exactly this WP9 slice and the C5j consent/anonymity rules were user-approved 2026-09-17; no behavior, architecture, migration or security question arose. Family participation remains gated on separate authorization and guardian consent, as the plan itself records.

## Gates (documentation-only round)

- `npm run backlog:check` — green after the activation edits
- `npm run docs:encoding` — green after the activation edits
- `git diff --check` — clean
- Runtime gates (Jest, lint, build, Docker PHPUnit, browser gate) belong to implementation steps 2-6 of the plan and were intentionally not run (no runtime file changed).

## Plan Lifecycle

- Plan moved `new/` → `active/` with header status (`Active`, Standard A `PASS` 2026-10-03, implementation not started) and an appended `## Review Outcome` (baseline, fingerprint, verification, gates).
- CONCEPT-001: stays In Progress for WP9; CS-PLAN-080 recorded as the WP9 owner; no whole-item closure.
- Parent CS-PLAN-070 header (Next child) and WP9 row updated; `docs/README.md`, `docs/plans/README.md`, `docs/backlog/backlog.md` and `ACTIVE_TASKS.md` updated.
- Unrelated pre-existing `.gitkeep` deletions preserved unstaged, per the prior reviews' handling.

---

# Code Review: CS-PLAN-081 Engine Repair - Full Interim Continuation

**Review Date:** 2026-10-04
**Reviewer:** Kilo main; four read-only reviewer tracks
**Tier:** Full interim, complete uncommitted candidate, not whole-plan completion
**Owner:** `docs/plans/active/cs-plan-081-pre-wp9-hardening-and-retained-data-readiness.md`
**Base:** `master` / `605cd8349baa458ea907794005ad15b6c11a56f5`; index empty
**Outcome:** `BLOCKED` - newly confirmed plan-owned findings, including private
security clearance; RF-081-B02 itself is resolved

## Scope And Identity

The original repaired manifest and all ten untracked content hashes in the
preserved 2026-10-03 Full interim entry below were revalidated before edits.
The final implementation checkpoint matched exactly:
`346c493491ccde4960fce99c443e5cdff0e35ae0e8680da9768d1154bc11cd48`.
No earlier runtime repairs were repeated. The prior record is now an explicit
evidence path, and six engine-support paths were approved additions.

- Initial index-entry SHA-256:
  `8161914fd6b502f0b387fad9fd485b1f7edcaad7217c0ead567eab50326a833f`
- Initial staged binary diff: empty, SHA-256
  `e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855`
- Initial complete staged/unstaged/untracked capture including unrelated deletions:
  `32a9ba2209424517b27370c911f8b3a6b4611939fdcd1b6123a3d7aa1d00a8d9`
- Initial scoped unstaged binary-diff SHA-256:
  `74f19e4dae1e2782770027499bd354ac3662386281da2ff2dc9cb69a1d6fcbe3`
- Initial complete scope SHA-256:
  `db3725dd2eae1af59f28043ece57f172b457f2a851cc60a9930ce30fe87e19d3`
- Frozen candidate scoped unstaged binary-diff SHA-256:
  `1577c833dba1cab74b8450b79ec50a623577a79dcb2b33b2a7bf498a06ea1a51`
- Frozen complete reviewed scope SHA-256:
  `cbed20d0b577d34e5819a6de7894e42cfa5aa2c91dea398be2867d8fb4e1652a`
- Frozen implementation/durable-doc SHA-256 excluding only evidence-only
  plan/registry/record:
  `8804bab1c52192dfac677a8118333fe8ff3fa6cd3189f2d66688622086d691ae`

Hash formula is unchanged: staged binary bytes, NUL, unstaged binary bytes, NUL,
JSON of sorted untracked relative paths with individual content SHA-256 hashes.
The overnight branch/HEAD/index/full-scope check matched the frozen candidate
exactly. This outcome/tracking evidence follows that snapshot; implementation
remains frozen. Unrelated `docs/plans/{active,new,onhold}/.gitkeep` deletions are
preserved and excluded from review/write/commit scope. Private evidence and
generated build/test artifacts are never eligible for the review commit.

### Exact Reviewed Manifest (Continuation Checkpoint)

```text
.eslintrc.js
.forgejo/workflows/full-matrix.yml
.forgejo/workflows/release-gate.yml
.forgejo/workflows/test.yml
.npmrc (deleted)
docker/Dockerfile.test
docker/test-entrypoint.sh
docs/backlog/ACTIVE_TASKS.md
docs/backlog/archive/CODE_REVIEW_2026-10-03.md
docs/engineering/deployment.md
docs/engineering/development.md
docs/engineering/testing.md
docs/plans/active/cs-plan-081-pre-wp9-hardening-and-retained-data-readiness.md
docs/standards/guidelines/logging.md
jest.config.js
l10n/de.json
l10n/en.json
l10n/templates/chorestrike.pot
lib/Controller/UserController.php
lib/Db/MinigameScoreMapper.php
lib/Exception/CannotDemoteSelfException.php
lib/Exception/CannotRemoveOwnerException.php
lib/Service/AppLogger.php
lib/Service/ChecklistService.php
lib/Service/CommandService.php
lib/Service/FloorService.php
lib/Service/HouseService.php
lib/Service/HouseSetupService.php
lib/Service/RewardRequestService.php
lib/Service/RewardService.php
lib/Service/RoomService.php
lib/Service/SchedulingService.php
lib/Service/TestDataService.php
package-lock.json
package.json
playwright.config.ts
scripts/run-e2e-gate.js
src/components/Setup/SetupWizard.vue
src/views/Settings.vue
tests/__mocks__/OCP/AppFramework/Http/Attribute/ARateLimit.php
tests/__mocks__/OCP/AppFramework/Http/Attribute/UserRateLimit.php
tests/bootstrap-entity.php
tests/e2e/family-membership-hardening.spec.ts
tests/e2e/fixtures/occ-runner.ts
tests/e2e/required-scenarios.json
tests/e2e/settings.spec.ts
tests/integration/Controller/UserControllerIntegrationTest.php
tests/integration/Db/FixtureHouseLifecycleTest.php
tests/integration/IntegrationTestCase.php
tests/integration/RoleGuardApiTest.php
tests/integration/Service/AccountErasureTest.php
tests/integration/Service/HouseMutationConcurrencyTest.php
tests/integration/Service/RewardConcurrencyTest.php
tests/integration/Service/RewardLifecycleTest.php
tests/integration/Service/SchedulePlanAggregateTest.php
tests/integration/Service/TestDataSeedTest.php
tests/jest.setup.deprecations.js
tests/unit/Controller/UserControllerTest.php
tests/unit/Db/Mapper/MinigameScoreMapperTest.php
tests/unit/Service/AppLoggerTest.php
tests/unit/Service/CatalogAuditTest.php
tests/unit/Service/ChecklistAuditTest.php
tests/unit/Service/CommandAuditTest.php
tests/unit/Service/FloorServiceTest.php
tests/unit/Service/HouseServiceLoggingTest.php
tests/unit/Service/HouseServiceTest.php
tests/unit/Service/HouseSetupServiceTest.php
tests/unit/Service/RewardRequestServiceTest.php
tests/unit/Service/RoomServiceTest.php
tests/unit/Service/SchedulingServiceTest.php
tests/unit/components/SetupWizard.spec.js
tests/unit/scripts/browserGate.spec.js
tests/unit/scripts/jestDiscovery.spec.js
tests/unit/views/Settings.spec.js
```

The ten untracked hashes are exactly those of the earlier final implementation
checkpoint: its historical hash table plus its explicitly recorded final
`jestDiscovery.spec.js` hash. Relevant unchanged dependencies/callers were read
as context, not silently added to the writable/commit manifest.

## Approved Engine Decision And Repair

One structured question batch approved the first, recommended option:
Node `^20.19.0`, npm `^10.8.2`, dialogs `~7.2.0`, strict minimum-version evidence,
durable support-doc alignment and minimum-version CI.

The complete graph diagnosis covered direct/transitive requirements, optional
packages, peer relationships, actual APIs used and documented support:

- Webpack Vue config requires Babel-loader 10 and the already repaired tool peers;
  downgrading Babel-loader alone would not be a compatible graph.
- Stylelint/config, CSS tools, router tooling and optional native tooling also
  impose Node 20.19.0. Browserslist imposes npm >=10.5.0; the explicitly approved
  10.8.2 floor is the bundled, previously exercised version, not a claim that
  every older npm version fails technically.
- Dialogs 7.3 introduces mandatory Node-24-only files 4. The nearest preceding
  minor, dialogs 7.2, uses files 3.12.2 and sharing 0.3 and retains the toast APIs
  used by the app. The lockfile was regenerated through npm, not manually edited.
- A Node-24-only support change would conflict with the existing ESLint,
  initial-state and Webpack Vue engines; no broad major upgrade was approved.
- Existing Vue 3, Pinia 3, ESLint/Vue policy and all earlier authorization,
  logging, throttle, reset-retention and test-safety repairs are preserved.
- Development/deployment build prerequisites now state the exact supported
  major ranges; the stale Jest engine comment is aligned. All three CI workflows
  pin/assert Node 20.19.0/npm 10.8.2 and run strict clean installation as an
  automated regression guard. No engine/peer suppression or compatibility shim.

## Gate Evidence

All Node/npm gates ran through an isolated exact Node 20.19.0/npm 10.8.2 runtime;
the host's Node 24/npm 11 was not substituted as support evidence.

| Required Gate | Result |
| --- | --- |
| `npm ci --engine-strict --legacy-peer-deps=false` | PASS, clean install, no engine or peer bypass |
| `npm ls --all` and `npm ls --all --omit=optional` | PASS, exit 0; complete peers including optional relationships checked |
| Complete locked Node/npm engine-range audit | PASS at both approved floors, including optional/platform packages |
| `npm test -- --runInBand` | PASS, 109 suites / 1228 tests, zero failures/skips |
| `npm run lint` | PASS, preserved ESLint/Vue policy |
| `npm run stylelint` | PASS |
| `npm run test:noise` | PASS, full 109/1228 run, no error-level logger noise; approved warnings unchanged |
| `npm run build` | PASS, production Webpack build |
| `npm run l10n:check-keys` | PASS, 1270 source/catalog keys, exact POT/EN/DE coverage and placeholders |
| `node scripts/run-e2e-gate.js --self-test` | PASS, discovery/ownership/attempt-safety checks |
| Docker PHPUnit unit `--fail-on-skipped` | PASS, 1241 tests / 4035 assertions, zero skips |
| Docker PHPUnit integration `--fail-on-skipped` | PASS, 532 tests / 2980 assertions, zero skips |
| Docker `composer cs:check` | PASS, 0 of 403 files require fixes |
| `npm run test:e2e:gate` | PASS, `run-rCVDzo`, 49 required specs, three sequential pools, zero required skips/failures/flaky/retries |
| `npm run backlog:check` | PASS, ownership/metadata/dependencies and scoped links |
| `npm run docs:encoding` | PASS, no encoding violations |
| `git diff --check` | PASS |

Integration completed before the owned family seed and browser execution; no
integration/E2E/recovery mutation overlap. The existing three-fresh-first-run
evidence remains historical and is not misrepresented as another fresh install.
Raw reports/traces stay ignored and unpublished. Documentation and whitespace
gates are rerun after the review evidence edits.

Installation still reports deprecations and 77 npm audit advisories (8 low,
9 moderate, 60 high). The security track inspected applicability and found no
confirmed app exploit path from those advisories. They remain visible; this is
not an audit-clean claim or approval for unrelated major upgrades.

## Full Review Deliverables And Findings

| Track | Read-Only Deliverable | Main Validation |
| --- | --- | --- |
| Correctness/functionality | Structured findings for checklist disappearance and availability no-op audit | Confirmed against current source; deduplicated |
| Architecture/ownership | Structured availability no-op audit finding | Confirmed; same RF-081-B14 |
| Security/auth/data-flow | Structured findings plus private evidence/refinement; security clearance absent | Main confirmed private evidence, retained its corrected path distinction; no exploit executed |
| Performance/quality | Structured checklist error-path, repeated locked reads and availability audit findings | Confirmed; deduplicated |

Every track returned a finding array; schema fields and source locations were
validated. The security track reached its read-only execution budget and returned
its blocking evidence, not a clearance. Main verified the relevant paths rather
than treating its output or previous approval claims as authorization.

| Finding | Severity | Area | Status |
| --- | --- | --- | --- |
| RF-081-B02 | major | Supported engine contract | RESOLVED with explicit approval and all required gates |
| RF-081-B13 | major | `ChecklistService.php:291`: wrong localization property on fresh missing-item path | OPEN; disappearance/rollback/no-success regression required |
| RF-081-B14 | minor | `SchedulingService.php:275`: no-op falsely audited as changed | OPEN; empty/identical-update regressions required |
| RF-081-B15 | minor | `ChecklistService.php:293-295`: repeated task-access reads under house lock | OPEN; fresh-task deduplication and query-count proof required |
| RF-081-B16 | block | Shared owner-command review surface; private security clearance | OPEN; sanitized placeholder only, repair authorization and fresh evidence required |

All four new findings belong to H3/CS-PLAN-081 and remain in the owning plan's
Review Findings. No duplicate backlog item is created. No new runtime repair was
applied outside the approved dependency/configuration/documentation scope.

## Outcome-Specific Finalization

`BLOCKED`, not `NEEDS-FOLLOW-UP` or `PASS-WITH-FIXES`: confirmed private security
clearance is absent, and broader behavior/security repair approval is not inferred
from the engine decision or an earlier repair round. The active Goal cannot ask
the required explicit decision at that checkpoint. This is an authorization/security blocker,
not a scheduled wait or failing engine gate.

- Owning plan header/findings/acceptance/evidence and `ACTIVE_TASKS.md` updated;
  durable engine docs integrated. The historical 2026-10-03 outcome is retained.
- Branch, HEAD and index unchanged; implementation remains the reviewed frozen
  candidate. No staging or automatic commit. The request's eligible commit
  authorization does not override the canonical `BLOCKED` guard.
- Plan stays in `active/`. H6 recovery/docs and H7 operator evidence remain
  required and unrescoped; no whole-plan completion or premature owner archive.
- No branch change, amend, push, PR, productive-use declaration, retained-baseline
  adoption, deployment, migration-policy change or external publication.

### Subsequent Repair Authorization (2026-10-04)

After the blocked checkpoint was reported, a structured question batch explicitly
approved the recommended "Repair now" option for RF-081-B13..B16. This covers the
four bounded behavioral/security/performance corrections, their affected
shared-command consumers and direct, concurrency, replay and rollback regressions,
followed by required gates and review follow-ups. The owning plan/registry record
the new in-progress round. No fix, fresh clearance or passing verdict is claimed
by this decision alone; the frozen green-gate checkpoint above remains historical.
H6/H7 stay required and the plan stays `active/`. No commit while `BLOCKED`.

# Code Review: CS-PLAN-081 Authorized Repair Round - Full Interim

**Review Date:** 2026-10-03
**Tier:** Full interim, escalated from Standard B through the structured user decision
**Owner:** `docs/plans/active/cs-plan-081-pre-wp9-hardening-and-retained-data-readiness.md`
**Base:** `master`, `605cd8349baa458ea907794005ad15b6c11a56f5`; staged set empty
**Outcome:** `BLOCKED` - required supported-engine verification fails; no automatic commit or lifecycle transition

## Decisions And Boundaries

1. User approved Full interim review for authorization-sensitive implementation;
   all four read-only tracks returned findings before repairs.
2. User approved in-round repairs rather than deferral, including security/behavior
   corrections, with regressions and affected gates rerun.
3. User approved only sanitized public placeholders for unremediated security
   findings. Technical evidence remains private; no exploit details or raw reports
   are published here.
4. No `--no-commit` override was supplied. The canonical `BLOCKED` guard still
   forbids staging, transition and automatic commit when a required gate is red.
5. This is not a final plan-completion review. H6/H7 remain required, and no
   productive-use/retained-baseline adoption or engine-policy change is inferred.

## Candidate Identity

Initial capture included the preserved unrelated `.gitkeep` deletions for drift
detection, but they were excluded from review and every allowed write/commit set.
Initial unstaged binary-diff SHA-256:
`3de562602b470baf4a221145fc29564c2def4962c4b987b9d9b6b10764f85cf6`.
Initial staged/unstaged/sorted-untracked capture SHA-256:
`acbc93818900e1d64ab8729eb383f07ba91bb7553a1baf735361ea05bb2e2496`.

The repaired pre-finalization snapshot below excludes `.gitkeep` deletions and
predates this evidence entry and the final review-generated tracking/outcome
updates. The frozen implementation is not changed by those evidence-only edits.

- Index-entry SHA-256: `8161914fd6b502f0b387fad9fd485b1f7edcaad7217c0ead567eab50326a833f`
- Staged binary diff: empty, SHA-256 `e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855`
- Scoped unstaged binary-diff SHA-256: `532e70926196b3aaafbadc589dad324baa9d729d362ce4d66432fbfdbe49f5ec`
- Complete scope SHA-256 (staged bytes, NUL, unstaged bytes, NUL, sorted untracked path/content-hash JSON): `85e530fa4b43f4cdf230748add4119edb5b09d8cc4341accc6b7f57b7be29949`

### Final Implementation Checkpoint

After the original snapshot, a test-only safety refinement replaces the fixed
Jest fixture path with an atomically created unique directory and makes cleanup
conditional on ownership by that run. An explicit no-owned-fixture cleanup
regression is included. Full Jest and noise gates were rerun: 109 suites / 1228
tests pass. App code, build, backend and browser-test behavior are unchanged, so
the completed three-pool browser evidence is retained, not misrepresented as a
new run.

The final implementation checkpoint excludes only the three review-generated
plan/tracking/record paths and unrelated `.gitkeep` deletions, allowing later
evidence edits without a self-referential hash. It covers 65 implementation/
durable-doc paths and uses the same staged/unstaged/untracked hash formula:
`346c493491ccde4960fce99c443e5cdff0e35ae0e8680da9768d1154bc11cd48`.
The final `jestDiscovery.spec.js` content SHA-256 is
`53abc6f0b237c7bcfdbedd480c46da21aba00b2d63e2ed05144f1dbfbf6d3b3b`;
the other nine untracked content hashes below are unchanged. The older snapshot
and its exact manifest remain historical checkpoint evidence.

### Exact Reviewed Manifest (Original Checkpoint)

```text
.eslintrc.js
.npmrc (deleted)
docker/Dockerfile.test
docker/test-entrypoint.sh
docs/backlog/ACTIVE_TASKS.md
docs/engineering/testing.md
docs/plans/active/cs-plan-081-pre-wp9-hardening-and-retained-data-readiness.md
docs/standards/guidelines/logging.md
jest.config.js
l10n/de.json
l10n/en.json
l10n/templates/chorestrike.pot
lib/Controller/UserController.php
lib/Db/MinigameScoreMapper.php
lib/Exception/CannotDemoteSelfException.php
lib/Exception/CannotRemoveOwnerException.php
lib/Service/AppLogger.php
lib/Service/ChecklistService.php
lib/Service/CommandService.php
lib/Service/FloorService.php
lib/Service/HouseService.php
lib/Service/HouseSetupService.php
lib/Service/RewardRequestService.php
lib/Service/RewardService.php
lib/Service/RoomService.php
lib/Service/SchedulingService.php
lib/Service/TestDataService.php
package-lock.json
package.json
playwright.config.ts
scripts/run-e2e-gate.js
src/components/Setup/SetupWizard.vue
src/views/Settings.vue
tests/__mocks__/OCP/AppFramework/Http/Attribute/ARateLimit.php
tests/__mocks__/OCP/AppFramework/Http/Attribute/UserRateLimit.php
tests/bootstrap-entity.php
tests/e2e/family-membership-hardening.spec.ts
tests/e2e/fixtures/occ-runner.ts
tests/e2e/required-scenarios.json
tests/e2e/settings.spec.ts
tests/integration/Controller/UserControllerIntegrationTest.php
tests/integration/Db/FixtureHouseLifecycleTest.php
tests/integration/IntegrationTestCase.php
tests/integration/RoleGuardApiTest.php
tests/integration/Service/AccountErasureTest.php
tests/integration/Service/HouseMutationConcurrencyTest.php
tests/integration/Service/RewardConcurrencyTest.php
tests/integration/Service/RewardLifecycleTest.php
tests/integration/Service/SchedulePlanAggregateTest.php
tests/integration/Service/TestDataSeedTest.php
tests/unit/Controller/UserControllerTest.php
tests/unit/Db/Mapper/MinigameScoreMapperTest.php
tests/unit/Service/AppLoggerTest.php
tests/unit/Service/CatalogAuditTest.php
tests/unit/Service/ChecklistAuditTest.php
tests/unit/Service/CommandAuditTest.php
tests/unit/Service/FloorServiceTest.php
tests/unit/Service/HouseServiceLoggingTest.php
tests/unit/Service/HouseServiceTest.php
tests/unit/Service/HouseSetupServiceTest.php
tests/unit/Service/RewardRequestServiceTest.php
tests/unit/Service/RoomServiceTest.php
tests/unit/Service/SchedulingServiceTest.php
tests/unit/components/SetupWizard.spec.js
tests/unit/scripts/browserGate.spec.js
tests/unit/scripts/jestDiscovery.spec.js
tests/unit/views/Settings.spec.js
```

The review record itself is a declared evidence-only finalization path, not part
of the pre-finalization source snapshot above.

### Untracked Content Hashes At Capture

| Path | SHA-256 |
| --- | --- |
| `lib/Exception/CannotDemoteSelfException.php` | `859bb8b6e11eb827351eeeb3558b265cacaa2f4708efb6ed43ce84806333e59e` |
| `lib/Exception/CannotRemoveOwnerException.php` | `84bd42a5acb365c7d33ec910d3fe3a4cd1eb5608e3740796c46117eab5982f5c` |
| `tests/__mocks__/OCP/AppFramework/Http/Attribute/ARateLimit.php` | `bdd16033befbc19aa1471656aa854ca9990a0308fed8c5bf9bd8152b491cfa80` |
| `tests/__mocks__/OCP/AppFramework/Http/Attribute/UserRateLimit.php` | `21e595391949de6465718e212fd12a32f9b92643c55a647487d8c2b598751e9d` |
| `tests/e2e/family-membership-hardening.spec.ts` | `6714cfaa92dd984646ec42baaadd866098b4d88ae917695baf0e2d54aea00cb9` |
| `tests/integration/Db/FixtureHouseLifecycleTest.php` | `d0deeac25519d0f8d0d75d9af51c2a48cdfec93ac6ac614d54089981752f25d7` |
| `tests/unit/Service/CatalogAuditTest.php` | `c288cfb71dc13f3cc2ec1a4ad92795a0327fd0c941da25757f3c4c1aaebdd8dc` |
| `tests/unit/Service/ChecklistAuditTest.php` | `26b21d33fc1f9a10a6236d78d527ac7c4ee7a6542a4f0ebad33fdc3f4dd64272` |
| `tests/unit/Service/CommandAuditTest.php` | `76610bd52cdd0ea548980c217898c78a0ec077cbe526086ef199c5e4bd7d6ff4` |
| `tests/unit/scripts/jestDiscovery.spec.js` | `4c0df5a286d22785b73b82f9a0aa5263f9908ed09acb4eda8b673acf1c0ccd75` |

## Findings And Repairs

All non-sensitive findings belong to CS-PLAN-081's `## Review Findings`; no
parallel backlog item or premature completion archive entry is created.

| Severity | Area | Disposition |
| --- | --- | --- |
| major | Router/Pinia peer graph | Fixed with Pinia 3.0.4; clean non-bypassed install and full peer tree verified |
| major | Supported engine contract | Open RF-081-B02: strict Node 20 installation rejects `@nextcloud/files@4.0.0` requiring Node 24; Babel-loader 10 also excludes advertised Node 20.0-20.9 |
| block | Membership browser discovery | Fixed invalid async helper; required discovery pinned |
| major | Throttle proof | Real allowed/rejected mutations, exact responses, complete unchanged state, dedicated identities and endpoint/user isolation for all three endpoints |
| major | Browser pool/manifest ownership | Membership and populated reset pinned exclusively to serial DB pool; mandatory identities/counts and gap regressions |
| major | Reset retention proof | Populated setup/structure/task/occurrence/history/wallet/reservation/reward/recognition snapshots, cancel/failure/retry/replay and correct notice |
| major | Audit privacy/coverage | Explicit audit path without session UID snapshots; exact direct/shared-command inventory, post-commit/replay/no-change/denial/rollback tests including catalog and request lifecycle |
| block | Authorization | User-authorized repairs verified by backend gates and final security follow-up (no findings); technical evidence stays private |
| minor | Role denial mapping/callers | Typed self-demotion vs generic forbidden, stale caller arguments removed |
| minor | Basement hint/Jest discovery | Accurate localized total-floor hint; probe exit/error/signal assertions, uniquely owned temporary fixture and bounded Windows cleanup |

## Gate Evidence

- Jest: 109 suites, 1228 tests, zero failures/skips; warning-noise gate passes
  with the existing approved warning baseline unchanged.
- Docker unit: 1241 tests, 4035 assertions, zero skips; includes real receipt/
  transaction execution for catalog/request audit emission and replay semantics.
- Docker integration: final candidate's first full run on an independently fresh
  family installation, after entrypoint completion: 532 tests, 2979 assertions,
  zero skips. Two earlier fresh post-fix runs and an additional 531-test fresh
  run are recorded in the plan; no rerun-only cold-start claim.
- Canonical lint, stylelint, production build, localization (1270 exact keys),
  browser runner self-test, backend coding standards, backlog/docs/whitespace:
  green before final review-generated evidence edits; docs gates rerun afterward.
- Clean install/peer tree: Node 20.19.0/npm 10.8.2, no peer bypass, green with
  engine warnings tracked as defects rather than suppressed.
- Engine-compatibility probe: `npm install --dry-run --ignore-scripts --engine-strict --legacy-peer-deps=false`
  on Node 20.19.0/npm 10.8.2 fails `EBADENGINE` for `@nextcloud/files@4.0.0`.
- Focused membership/reset browser proofs: passing first-attempt cases. The
  earlier complete run was deliberately stopped before final pool completion
  for a follow-up repair and is NOT counted as a passing gate. Frozen-candidate
  full gate `run-pqNIwe` PASSES discovery and all three sequential pools:
  49 required specs, zero skipped/failed/flaky required scenarios, no retries,
  `--forbid-only`. Executed on Node 20.19.0/npm 10.8.2.

## Finalization Guard

Final follow-up deliverables: correctness `[]`, quality `[]`, security `[]`,
architecture `[]` after the in-round remedies and their regressions. The original
engine-contract finding remains explicitly open rather than disappearing from
the follow-up prompts or verdict.

No staging, commit, branch change, push or lifecycle move has occurred. The
supported-engine requirement remains in scope and required; it is not moved to
the backlog, silently weakened, or treated as proven by execution on a newer
runtime. An engine-policy change or wider dependency/configuration decision
requires explicit approval rather than inference from repair authorization.

Final outcome is `BLOCKED`, not `PASS-WITH-FIXES` or `NEEDS-FOLLOW-UP`: the
supported-engine requirement is not green. RF-081-B02 stays in the owning plan;
no silent deferral/rescoping, support-range change or force-install workaround.
The plan stays active; H6/H7 and final whole-plan completion remain open. No
backlog completion or `done/` move, staged files or automatic commit. Final
documentation/whitespace checks and branch/HEAD/index drift checks complete the
evidence package without altering the index.

---

# Plan Activation: CS-PLAN-081 Pre-WP9 Hardening and Retained-Data Readiness — Standard A

**Review Date**: 2026-10-03
**Reviewer**: Kilo (main agent, no subagents — Standard A tier)
**Mode**: Standard A (plan review before `new/` → `active/`)
**Scope**: `docs/plans/new/cs-plan-081-pre-wp9-hardening-and-retained-data-readiness.md` plus its declared uncommitted drafting package (doc/plan indices, backlog/live registry, monthly archive rollover, parent/WP9 sequencing notes); runtime tree untouched
**Base Commit**: `76e282ef774d63c802a537a489d42c781abe62c6` (`master`; index empty at capture — tree `5b868e3`; unstaged binary-diff SHA256 `E182140A555BD74DFB3095DF853AB9DC0B1D33D3B2DBB9F9EE8077EEEF10E617` covering the drafting package plus the unrelated pre-existing `.gitkeep` deletions, preserved unstaged; untracked plan SHA256 `CC506A10DE4A63AC19813CA1011E5A87AF5AF5146CD226E9318063847FB4C090`)

## Executive Summary

### Outcome
- PASS-WITH-FIXES

### What Was Reviewed
- The complete preparation plan: problem summary with verified baseline, H1-H7 ownership/non-goal slices, membership gates/throttling/owner-operation audit logging, small user-facing corrections (basement cap, truthful reset copy, Snake ordering), first-retained-build/recovery contract, private reporting channel, H-T1..H-T11 test matrix, implementation gates, file manifest, implementation order, risks, acceptance criteria, decision proposals Q-081-01..05.
- Eligibility: sole regular non-symlink `*.md` in `docs/plans/new/`; destination `active/cs-plan-081-…` did not exist; no ambiguity.

### What Was Fixed Immediately
- One minor manifest path: `RoleGuardApiTest.php` cited under `tests/integration/Controller/` but living at `tests/integration/RoleGuardApiTest.php` — corrected in the plan §5 manifest.

### Main Risks Remaining
1. None blocking. Implementation-phase risks (draft-approval/productive-use confusion, harness fix masking production gaps, throttle/fixture interactions, log overpromise, whole-server restore damage, silently deferred operator channel, scope absorption) are named in plan §7 with mitigations.

## Verification Performed

- **Baseline citations exact:** migration head `Version1033`; `HouseService::inviteMember(int $houseId, string $email, string $role)` has no owner parameter (lib/Service/HouseService.php:156), `removeHouseMember`/`updateUserRole` take caller parameters (:227/:303); all four ordered queries in `lib/Db/MinigameScoreMapper.php` sort only by `score DESC` with no `id` tiebreak; `SetupWizard.vue:551-555` clamps `floorTotal = min(6, …)` and appends the basement only when `floors.length < 6` (silent drop at the cap); `.npmrc` enables `legacy-peer-deps` citing TECH-LINT-006; `jest.config.js:4` ignores only `/node_modules/`, `/archive/`, `/\.kilo/` test paths while `moduleNameMapper` maps canonical mocks (duplicate-mock indexing unaddressed, as claimed).
- **Manifest/commands:** every §4 gate command exists in package.json (`test:noise`, `l10n:check-keys`, `stylelint`, `test:e2e:gate`, `docs:encoding`, `backlog:check`); §5 manifest verified against the tree — every M path exists (after the RoleGuardApiTest fix) and no N path exists; all ten related backlog IDs exist and are open in `backlog.md`.
- **Ownership/no overlap:** slices H1-H7 map to existing backlog owners (TECH-JEST-001/TECH-LINT-006 H1, TECH-TEST-003 H2, CR-SEC-05/SEC-AUDIT-001 H3, FEAT-UX-032 H4 + CONCEPT-001 partial, CR-053-11 Snake remainder H5, RELEASE-002 partial H6, BUG-SEC-001 H7); WP9/CONCEPT-001 remainders stay with CS-PLAN-080/070 (cross-referenced sequencing notes in both active plans).
- **Plan-only checks:** nine template sections complete; per-step ownership with estimates; coherent preparation scope with explicit non-goals (no FEAT-UX-015, no chore approval, no Task Pass, no tour/search/print/bulk/perf sweep, no TECH-DIGEST-001); risks with likelihoods/mitigations; thirteen acceptance criteria; H-T1..H-T11 test matrix with primary evidence paths; drafting gates green and re-run by this review.

## Findings

| Severity | File:line | Issue | Status |
|---|---|---|---|
| minor | docs/plans/active/cs-plan-081-pre-wp9-hardening-and-retained-data-readiness.md:268 (§5 manifest) | `RoleGuardApiTest.php` cited under `tests/integration/Controller/` but the file exists at `tests/integration/RoleGuardApiTest.php` | fixed in-round |

No open findings to route (plan-owned, fold-in, or backlog).

## Decisions

Q-081-01..05 presented as one structured question batch; all recommended options approved by the user:
1. **Q-081-01** — post-WP9 baseline adoption for retained-data protection (no retroactive support; the fresh-install rule stands until explicit adoption).
2. **Q-081-02** — native `UserRateLimit`, 60 calls / 300 elapsed seconds / authenticated user, separately on invite/remove/updateRole.
3. **Q-081-03** — AppLogger operational owner-change events with NC-managed retention and explicit durability limits (no dedicated ledger).
4. **Q-081-04** — operator-approved existing dedicated mailbox and usable public key; H7 remains required.
5. **Q-081-05** — visible five-above-ground cap with basement selected (six total), consistent input/preview/payload.

Recorded in the plan §9 decision table and `## Review Outcome`.

## Gates (documentation-only round)

- `npm run backlog:check` — green after the activation edits
- `npm run docs:encoding` — green after the activation edits
- `git diff --check` — clean
- Runtime gates belong to implementation H1-H7 and were intentionally not run (no runtime file changed).

## Plan Lifecycle

- Plan moved `new/` → `active/` with header status (`Active`, Standard A `PASS-WITH-FIXES` 2026-10-03) and an appended `## Review Outcome` (baseline, fingerprints, verification, decisions, fixes, gates, transition).
- Standalone backlog owners marked In Progress under CS-PLAN-081: TECH-JEST-001, TECH-LINT-006 (H1), TECH-TEST-003 (H2), CR-SEC-05, SEC-AUDIT-001 (H3), FEAT-UX-032 (H4), CR-053-11 Snake remainder (H5), BUG-SEC-001 (H7); CONCEPT-001/RELEASE-002 keep parent/partial ownership.
- `docs/README.md`, `docs/plans/README.md`, `docs/backlog/backlog.md`, `ACTIVE_TASKS.md` and the parent/WP9 sequencing notes updated to the active plan path and outcome.
- Unrelated pre-existing `.gitkeep` deletions preserved unstaged, per the prior reviews' handling.

---
