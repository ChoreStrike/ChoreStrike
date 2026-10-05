# Code Review - 2026-10-05

## Full Final — CS-PLAN-081

### Scope and Candidate

Owning plan: CS-PLAN-081, pre-WP9 hardening and retained-data readiness.
Whole-plan Full review, not an empty-diff completion shortcut. Four independent
read-only tracks reviewed delivered H1-H6 history, the full manifest, acceptance,
required test evidence and durable-doc integration; main owns all writes.
H7/H-T11 were explicitly user-rescoped on 2026-10-04, **not passed**.
BUG-SEC-001, TECH-REPO-001, RELEASE-002 and CONCEPT-001 remain open.

Branch `master`, base HEAD `2d0313f08c7be6dbc5a54cccfd620fa1a22f2ca5`;
implementation history starts after `605cd83`. Initial index empty; index
entry fingerprint SHA-256
`753e72c27993737578fcabc0e4206470a1fa7856aa6d64eaecaca481006cdb96`.
Initial review capture: 97 paths, implementation fingerprint
`571c085b37a4be8ce936e260e7b329142b0f20743c7c8744cfdb270fdbcd6bbb`.
Repaired pre-finalization capture: the 100 paths below, no untracked paths,
staged binary diff SHA-256 (empty)
`e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855`,
unstaged scoped binary diff SHA-256
`c3adfe0aabf1bf0d55a6224afad03a7490b424f302aa242c113a08536fe10894`.
Sorted path/content-hash JSON fingerprint
`b0289203c2e0fa06f0e4ae42180803993fb17cda96a806391c2d21e146bd564d`;
non-doc/AGENTS-excluded implementation subset fingerprint
`f3a68b1291c9ec2da9c718acee32168c11f33b8c1a9d460597dcdd8d031e1eb9`.
Deleted `.npmrc` uses a null hash. Complete per-file SHA-256 captures and available
captured diagnostic/gate output are preserved privately; no personal-server material.

Manifest is the sorted union of paths changed from `605cd83` and untracked
paths, excluding foreign `.gitkeep` deletions. Finalization adds this record,
the monthly archive, parent/WP9 sequencing links and the eligible plan move;
it does not change implementation bytes. Foreign deletions of
`docs/plans/{active,new,onhold}/.gitkeep` are preserved, never staged.

```text
.eslintrc.js
.forgejo/workflows/full-matrix.yml
.forgejo/workflows/release-gate.yml
.forgejo/workflows/test.yml
.npmrc
AGENTS.md
docker/Dockerfile.test
docker/test-entrypoint.sh
docs/README.md
docs/SECURITY.md
docs/api/README.md
docs/backlog/ACTIVE_TASKS.md
docs/backlog/archive/CODE_REVIEW_2026-10-03.md
docs/backlog/archive/CODE_REVIEW_2026-10-04.md
docs/backlog/backlog.md
docs/engineering/deployment.md
docs/engineering/development.md
docs/engineering/testing.md
docs/plans/README.md
docs/plans/active/cs-plan-081-pre-wp9-hardening-and-retained-data-readiness.md
docs/release/release-strategy.md
docs/standards/ai/rules/project-rules.md
docs/standards/ai/skills/architecture/SKILL.md
docs/standards/ai/skills/php-backend/SKILL.md
docs/standards/guidelines/logging.md
docs/testing/release-test-plan.md
jest.config.js
l10n/de.json
l10n/en.json
l10n/templates/chorestrike.pot
lib/Controller/UserController.php
lib/Db/MinigameScoreMapper.php
lib/Exception/CannotDemoteSelfException.php
lib/Exception/CannotRemoveOwnerException.php
lib/Service/AgreementService.php
lib/Service/AppLogger.php
lib/Service/ChecklistService.php
lib/Service/CommandService.php
lib/Service/FloorService.php
lib/Service/HouseService.php
lib/Service/HouseSetupService.php
lib/Service/PauseService.php
lib/Service/RewardRequestService.php
lib/Service/RewardService.php
lib/Service/RoomService.php
lib/Service/SchedulePlanService.php
lib/Service/SchedulingService.php
lib/Service/TestDataService.php
package-lock.json
package.json
playwright.config.ts
scripts/run-e2e-gate.js
scripts/run-recovery-rehearsal.ps1
src/components/Setup/SetupWizard.vue
src/views/Settings.vue
tests/__mocks__/OCP/AppFramework/Http/Attribute/ARateLimit.php
tests/__mocks__/OCP/AppFramework/Http/Attribute/UserRateLimit.php
tests/bootstrap-entity.php
tests/e2e/family-membership-hardening.spec.ts
tests/e2e/fixtures/occ-runner.ts
tests/e2e/required-scenarios.json
tests/e2e/settings.spec.ts
tests/e2e/setup-wizard-journey.spec.ts
tests/integration/Controller/MinigameControllerIntegrationTest.php
tests/integration/Controller/TestModeApiTest.php
tests/integration/Controller/UserControllerIntegrationTest.php
tests/integration/Db/FixtureHouseLifecycleTest.php
tests/integration/IntegrationTestCase.php
tests/integration/RoleGuardApiTest.php
tests/integration/Service/AccountErasureTest.php
tests/integration/Service/HouseMutationConcurrencyTest.php
tests/integration/Service/OwnerCommandAuthorizationTest.php
tests/integration/Service/RewardConcurrencyTest.php
tests/integration/Service/RewardLifecycleTest.php
tests/integration/Service/SchedulePlanAggregateTest.php
tests/integration/Service/SchedulePlanConcurrencyTest.php
tests/integration/Service/TestDataSeedTest.php
tests/integration/Service/TimezoneDayBucketingTest.php
tests/jest.setup.deprecations.js
tests/recovery/fixture.php
tests/unit/Controller/UserControllerTest.php
tests/unit/Db/Mapper/MinigameScoreMapperTest.php
tests/unit/Service/AgreementServiceTest.php
tests/unit/Service/AppLoggerTest.php
tests/unit/Service/CatalogAuditTest.php
tests/unit/Service/ChecklistAuditTest.php
tests/unit/Service/CommandAuditTest.php
tests/unit/Service/FloorServiceTest.php
tests/unit/Service/HouseServiceLoggingTest.php
tests/unit/Service/HouseServiceTest.php
tests/unit/Service/HouseSetupServiceTest.php
tests/unit/Service/PauseServiceTest.php
tests/unit/Service/RewardRequestServiceTest.php
tests/unit/Service/RoomServiceTest.php
tests/unit/Service/SchedulePlanServiceTest.php
tests/unit/Service/SchedulingServiceTest.php
tests/unit/components/SetupWizard.spec.js
tests/unit/scripts/browserGate.spec.js
tests/unit/scripts/jestDiscovery.spec.js
tests/unit/views/Settings.spec.js
```

### Independent Findings and Decisions

| Finding | Severity | Resolution |
| --- | --- | --- |
| RF-081-H6-05 | major | User-approved evidence-bound disposition; independent correctness clearance accepts uncertainty, not a demonstrated quartet cause/fix |
| RF-081-H6-10 | minor | Fixed live Sunday/week test assumption with four explicit-clock summer/winter/cross-week datasets; no calendar-rule change |
| RF-081-H6-11 | major | API rate-limit/cache/native-429 and operational audit/retention/durability contract completed |
| RF-081-H6-12 | minor | Release guidance aligned with user-rescoped H7 and later reachable-email requirement; GPG optional |
| RF-081-H6-13 | major | Required cap/basement/apartment toggle browser journeys added with input/preview/payload/persisted proof and discovery inventory |
| RF-081-H6-14 | major | Real-DB equal-score tests pin exact IDs for all four ordered reads, tied ranks and period/house/game isolation |
| RF-081-H6-15 | major | Explicitly authorized bounded reset actor-identity guard repair; execution/replay races preserve domain/house/room/journal/receipt facts and deny extra success events; independent security clearance |

All findings remain owned by this plan, not duplicate backlog items. The user
answered one structured batch: `Disposition freigeben (Recommended)` and
`Jetzt reparieren (Recommended)` (including explicit security repair and
sanitized public tracking). The earlier refusal of rerun-only acceptance is
preserved; the new disposition accepts an irrecoverable evidence gap with
mandatory recurrence investigation, not missing tests or a new cause claim.

### H6-05 Facts and Limits

The historical 568-test run's 1 failure/4 errors is retained. The original four
per-error names/stacks and contemporaneous command/process timestamps are
absent from available raw artifacts; a secondary scheduling/seed reference
cannot reliably map them. The independently repaired holder abort cannot erase
the parent or later house/member fixtures. The DB-free timeout reproduction
proves that container PHPUnit/helpers can survive a client timeout, **not**
that historical writer overlap occurred. Native/DBAL desynchronization,
inherited transactions and historical overlap remain unconfirmed hypotheses.

Source inventory establishes destructive disposable seed resets, retained
Nextcloud accounts, new-account-only provisioning handling, owned app-row
cleanup and cached `depends,defects` ordering. Fresh bounded diagnosis: 21/147
uninstrumented, then the same set with 568 sampled in-process records (105 begin,
103 commit and 2 rollback pairs, 21 cleanup pairs, fixture/account boundaries),
zero sampled native/DBAL mismatch or inherited boundary transaction. Earlier
2,272 and 12,843 sampled records remain separate historical diagnostics. These
probes do not normalize state and are not root-cause proof or committed gates.

Accepted disposition: unattributed historical integration anomaly; no zero-risk
or quartet-fix claim. Exclusive test-owned mutation jobs and container process
exit verification are mandatory after client timeouts. On recurrence retain
exact test names/stacks/ordering/candidate/timestamps and transaction/process
evidence before reruns and reopen diagnosis. Durable practice is in
`docs/engineering/testing.md`; no production fallback, blind commit/rollback,
warning suppression or required skip was added.

### Preserved Red and Interrupted Attempts

- First final integration gate: 571/3202, one failure, zero errors/skips. At
  Sunday 22:00+ UTC Berlin had entered a new week while UTC had not; the test's
  assumed 1-2 hour offset was wrong. Fixed focused 7/29 and subsequent full
  574/3219, then 581/3282 after all repairs. Not an H6-05 fixture recurrence.
- First inline engine audit: PowerShell parser/quoting rejection before Node;
  corrected private script validates all locked engine ranges without bypass.
- Reset-regression scaffolding used unsupported Nextcloud `fetchAssociative`;
  invalid proof, retained. A private in-memory probe produced no test output,
  also not a pass. Corrected `fetch()` helper checks new membership ID; validated
  original-guard negative control fails both replacement cases (2/5), repaired
  matrix 42/260 and complete repair cluster 65/362 pass.
- Pre-repair full browser attempt was deliberately stopped in the third pool
  before runtime edits; no whole-gate validity. Process inspection showed no
  surviving PHPUnit/OCC/helper writer before repair mutation jobs.
- First added wizard run: a test assertion read `entry.name` instead of the
  existing `entry.floor.name` API wrapper; one failure/three later serial cases
  not run. Corrected focused five actual journeys plus three setup projects
  pass 8/8 first attempt.
- First post-repair full browser gate rejected unsorted required-scenario JSON
  at discovery, before browser mutations. Fixed canonical inventory order and
  added a real-inventory unit pin; a complete new browser gate is required.
- The later bounded 600-second client wait expired while the corrected browser
  runner continued; container processes were inspected and no second mutation
  job started. A subsequent wait observed normal runner exit. This wait timeout
  is not a test failure, cancellation or gate completion claim.
- First scoped PHP fixer rejected missing explicit config; corrected four-path
  fixer changes zero files. Supplementary ESLint applied production-only rules
  to the CLI runner (16 pre-existing console errors/9 JSDoc warnings), not the
  canonical lint scope. No unrelated churn or output suppression; intended
  `npm run lint:fix` and `npm run lint` pass.

### Gate Evidence

Final source fingerprint above identifies code/config/tests; later finalization
is documentation-only. After backend gates and recovery, only the browser
inventory ordering and its Jest regression changed; PHP/runtime/build bytes
remain identical. Final Jest/noise and whole browser discovery are rerun on
those last test-inventory bytes. No prior candidate's green full suite is
relabeled as a fresh final gate. Earlier H2 three-fresh-install acceptance and
H1-H5/H6 interim records remain their historical, reviewed delivery evidence.

| Gate | Fresh evidence |
| --- | --- |
| Minimum toolchain | Node 20.19.0 / npm 10.8.2 wrapper, no global configuration change |
| Strict dependencies | `npm ci --engine-strict --legacy-peer-deps=false`, full `npm ls --all`, all 1,018 locked Node/npm engine ranges including optional packages PASS; package/lock bytes unchanged |
| npm advisory boundary | 77 retained advisories: 8 low/9 moderate/60 high; no audit-clean/force-upgrade claim |
| Jest/noise | 109 suites / 1230 tests PASS, approved warning baseline retained |
| Lint/stylelint/build | PASS, canonical lint/fixer scopes, webpack 5.105.2 production build |
| l10n/runner self-test | PASS, 1270 exact keys and selection/failure/skip/flaky rejection |
| Docker unit | 1314/4246 PASS, `--fail-on-skipped`, zero skips |
| Docker full integration | 581/3282 PASS, uninstrumented `--order-by=default --do-not-cache-result --fail-on-skipped`, zero skips |
| Composer standards | canonical `docker exec chorestrike-test composer cs:check`, 0/405 PASS |
| Recovery | all three self-tests; `cs081-recovery-2f8aff4b3ee8` PASS, 30 snapshots/whole DB/files/divergence/negative controls/26 migrations/owned teardown; fresh changed runtime archive |
| Full browser | `run-f45itl` PASS, 49 required specs in three sequential pools, zero required skips/failures/flaky cases/retries; prior interrupted/rejected runs remain invalid as whole-gate evidence |
| Docs | post-transition backlog 34 scoped files/encoding 892 scanned files/whitespace PASS; staged rerun includes new record/moved plan, 893 tracked files PASS; cached whitespace clean |

H-T10 runtime archive SHA-256
`4b1576b5cd0ef6d9b15a93c0abe5c75ebdc1e78d93e24a9a3312995e993d7624`;
ChoreStrike 0.1.1, Nextcloud 31.0.14, PHP 8.2.34, MariaDB 10.6.25;
recovery 32.76s, total 97.58s, zero cleanup failures. Full result is integrated
in `docs/testing/release-test-plan.md`. No browser/integration/recovery overlap.

### Finalization

**Outcome: PASS-WITH-FIXES (Full final).** Four initial independent tracks,
repair follow-ups and final source/evidence consistency checks delivered valid
findings arrays; all four final arrays are empty. The corrected complete browser
gate passes; actual runner exit and container writer state were verified. Main
self-review covers every canonical checklist and scope/identity boundary.

The complete owning plan moves `active/` to `done/`, with H7/H-T11 expressly
rescoped, not passed. Seven complete standalone owners move to October history:
TECH-JEST-001, TECH-LINT-006, TECH-TEST-003, CR-SEC-05, SEC-AUDIT-001, FEAT-UX-032,
CR-053-11. BUG-SEC-001/TECH-REPO-001 remain later open work and RELEASE-002/
CONCEPT-001 keep their honest remaining scopes. Parent and WP9 stay active;
technical provider matrix/consecutive browser pair and human-use evidence are
still subsequent work. No deployment, personal-server access, external
publication, forge migration or retained-baseline adoption.

Exactly one scoped conventional review commit on current `master` is authorized:
the existing H7 rescoping docs, verified bounded repairs/tests, permanent docs,
plan move and tracking/archive/review evidence. No unrelated paths, private raw
artifacts, assets or `.gitkeep` deletions may enter the index. Post-transition
backlog/encoding/whitespace gates and branch/HEAD/index/full source/diff checks
are required before staging/commit; no amend, push, tag or PR.

Post-transition capture (before this evidence-only append): 105 paths,
fingerprint `c66f7f12502be4a33139cc881904e67bd83df901af88169bc2c4a39c26ba368b`,
unstaged scoped binary diff
`18a6babf90f1336b2cd5920bac67321f80fca80a0335240170c325546f9b5055`;
implementation fingerprint remains exactly `f3a68b12...031e1eb9` above.
The only untracked paths are this review record and the moved done-plan, both
explicit commit-owned documentation. Branch/HEAD/index remain unchanged.

Staged finalization: the scoped 23-path set is represented by 22 changed files
with the plan rename detected. A private drift check initially compared the
default rename-collapsed name set against the explicit old/new path set and
rejected it; corrected `--no-renames` comparison checks both paths without
changing the index or broadening scope. This diagnostic rejection is retained;
no commit was attempted while it was unresolved.

---

## Plan Activation: CS-PLAN-082 Current-Stable Test Baseline and Data Preservation — Standard A

**Review Date**: 2026-10-05
**Reviewer**: Kilo (main agent, no subagents — Standard A tier)
**Mode**: Standard A (plan review before `new/` → `active/`)
**Scope**: `docs/plans/new/cs-plan-082-current-stable-test-baseline-and-data-preservation.md` plus its declared uncommitted drafting package (TECH-RUNTIME-001 backlog entry, plans/docs registry linkage, ACTIVE_TASKS entry, CS-PLAN-080 sequencing note); runtime tree untouched
**Base Commit**: `bd7ba8c6642fcbaa82d4cee04b8493c8ec506e2f` (`master`; index empty at capture — full unstaged-diff SHA256 `FBAF8E559FDA60930BC633C8BC8108D781C63B1A4DE42CBBADE641028D7FDBAC` covering the preserved uncommitted WP9 implementation round, the 082 drafting package and the unrelated pre-existing `.gitkeep` deletions, all preserved unstaged; untracked plan SHA256 `7A58B6EBE32AF216C859E5E892F8620C85C9172DE5C340CE36EAC389B5B86AA2`)

### Outcome

**PASS** — zero findings, zero open decisions after the batched question.

### Verification Performed

- **Baseline citations exact against the runtime:** `docker/Dockerfile.test` uses `php:8.2-apache` (line 4), copies `composer:latest` (line 47), clones `stable31` server (line 60) and `stable31` notifications (line 70); `docker/compose.test.yml` uses `mariadb:10.6`; `docker/compose.test-matrix.yml` adds `postgres:16-alpine` and SQLite profiles; `appinfo/info.xml` declares Nextcloud 27-31 / PHP 8.2-8.3 (lines 38-39). External artifact preflight re-verified live: Nextcloud server tag `v34.0.4` exists (tag object `8f37732`), `php:8.4.25-apache` and `postgres:18.6-alpine` manifests are active on Docker Hub — availability only, as the plan itself states.
- **Recovery/tooling claims exact:** `tests/recovery/fixture.php:30` enforces the `cs081-recovery-<hex>-db` ownership prefix; `scripts/run-recovery-rehearsal.ps1` checks the canonical local image digest (line 120), uses `mariadb:10.6` (line 125) and `mysqldump` dump/restore (lines 171, 71) — exactly the runtime assumptions the plan requires adapting.
- **Manifest/commands:** every §4 gate command exists in `package.json` (`test`, `lint`, `stylelint`, `test:noise`, `build`, `l10n:check-keys`, `docs:encoding`, `backlog:check`, `run-e2e-gate.js`); §5 manifest paths all present, including `.forgejo/workflows/{release-gate,test,full-matrix}.yml`, `scripts/run-tests.sh`, `tests/e2e/fixtures/*`, `tests/unit/Command/TestEnvironmentProvisioningTest.php`, `tests/fixtures/provision-command.sh`, `tests/integration/Db/SupportedDatabaseSchemaTest.php`; currently-untracked WP9-round files are correctly treated as existing-to-modify relative to the declared WP9-candidate dependency.
- **Ownership/no overlap:** TECH-RUNTIME-001 (primary), RELEASE-002 (bounded readiness contribution) and CONCEPT-001 (WP9 evidence consumer) exist, are open and consistently bounded in `backlog.md` and the plan; CS-PLAN-080's own "Target-runtime follow-up" note defers runtime qualification to CS-PLAN-082 and preserves NC31 passes as historical — boundaries consistent in both directions with active CS-PLAN-070/080.
- **Plan-only checks:** nine template sections complete; per-step ownership mapped to R1-R8 and TECH-RUNTIME-001 work items; coherent single-slice scope with explicit non-goals (no deployment, no adoption, no AIO access, no speculative migrations); named risks with likelihoods/mitigations; twelve complete acceptance criteria including the retention boundary; R1-R8 test plan adequate with negative controls and fail-closed requirements; no pre-baked workaround stacking (warning suppression, fake upgrades, blanket skips all explicitly forbidden).

### Findings

| Severity | File:line | Issue | Status |
|---|---|---|---|
| — | — | none | — |

No open findings to route (plan-owned, fold-in, or backlog).

### Decisions

Q-082-01 presented as one structured question batch; the recommended option was approved:
**current stable major only** — advertise exactly the newly verified baseline (initial target Nextcloud 34 / PHP 8.4); expand later only with named passing matrix rows. Recorded in plan §2 and `## Review Outcome`.

### Gates (documentation-only round)

- `npm run docs:encoding` — green after the activation edits (includes the staged moved plan)
- `npm run backlog:check` — green after the activation edits
- `git diff --check` / `git diff --cached --check` — clean
- Runtime gates (Jest, lint, build, Docker PHPUnit, browser/recovery rehearsals) belong to implementation steps 2-8 of the plan and were intentionally not run (no runtime file changed).

### Plan Lifecycle

- Plan moved `new/` → `active/` with header status (`Active`, Standard A `PASS` 2026-10-05, implementation not started) and an appended `## Review Outcome` (baseline, fingerprints, verification, decisions, gates, transition).
- TECH-RUNTIME-001 marked In Progress under CS-PLAN-082; its scope-decision sub-item checked with the Q-082-01 answer; RELEASE-002 and CONCEPT-001 keep their existing owners/boundaries.
- `docs/README.md`, `docs/plans/README.md`, `docs/backlog/backlog.md`, `ACTIVE_TASKS.md` and the CS-PLAN-080 sequencing note updated to the active plan path and outcome.
- Unrelated preserved content — the uncommitted WP9 implementation round, WP9-owned registry/backlog hunks, and the `.gitkeep` deletions — left out of the activation commit via hunk-scoped staging; working-tree truthfulness of WP9-owned sentences updated without committing them.
