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
