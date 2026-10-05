# CS-PLAN-082: Current-Stable Test Baseline and Data Preservation

> **Status:** Active (activated via Standard A `PASS` 2026-10-05; implementation not started); drafted 2026-10-05
> **Drafted:** 2026-10-05
> **Effort:** L, 5-8 engineering days; re-estimate after the Nextcloud API/dependency audit
> **Priority:** P1
> **Depends on:** preserved CS-PLAN-080 technical candidate; Standard A and support-scope decision before implementation
> **Related backlog items:** TECH-RUNTIME-001 (primary); RELEASE-002 (bounded readiness contribution); CONCEPT-001 (WP9 evidence consumer, not transferred)
> **Related plans:** [CS-PLAN-080](../active/cs-plan-080-wp9-cross-db-and-family-evidence.md), [CS-PLAN-081](../done/cs-plan-081-pre-wp9-hardening-and-retained-data-readiness.md)

## 1. Problem Summary

The regular development/test stack is explicitly pinned to the old **major**
line, but not to reproducible artifacts: `docker/Dockerfile.test` uses
`php:8.2-apache`, clones `stable31` and its notifications companion, and copies
Composer from `latest`. The canonical compose uses MariaDB 10.6; the WP9 matrix
adds PostgreSQL 16 and SQLite. App metadata declares Nextcloud 27-31 and PHP
8.2-8.3. Successful WP9 runs therefore do not establish newer-runtime support.

The operator supplied this target on 2026-10-05:

| Component | Initial current-stable target |
|---|---|
| Nextcloud | 34.0.4, internal version 34.0.4.1 |
| PHP | 8.4.25 |
| PostgreSQL | 18.6 |
| AIO distribution | Stable 14.2.0 (deployment reference, not the Nextcloud version) |

Read-only preflight found the Nextcloud `v34.0.4` source tag and the exact
`php:8.4.25-apache` / `postgres:18.6-alpine` image manifests. This proves artifact
availability only, not installation, compatibility or AIO equivalence. Resolve
and verify exact source/image identities again before implementation.

The user requires the primary local environment to follow current stable,
not remain on an obsolete version while production moves forward. They also
require entered house/task setup to survive future updates. No retained baseline
has yet been adopted; this draft must not silently adopt one or promise support
for old disposable development databases.

Drafting baseline: `bd7ba8c` plus extensive existing uncommitted WP9 changes.
All are preserved, including unrelated `.gitkeep` deletions. This round changes
documentation only; it neither implements nor commits nor upgrades any server.

## 2. Strategy

Make **current stable the regular default**, with reproducible pinned snapshots
and a repeatable update process. Stage each candidate in an owned isolated
environment; promote it into the canonical local/CI configuration only after
the required tests pass. A staging environment is a transition mechanism, not
a permanent excuse to leave the primary stack old.

Use PostgreSQL as the reference browser/development provider to align with AIO;
keep full MariaDB and SQLite backend verification. AIO and non-AIO use the same
Nextcloud application, but PHP/web-server/proxy/cache packaging differs. Matching
three version numbers is not a full AIO installation/backup proof.

### Ownership and Boundaries

- TECH-RUNTIME-001 owns stable tracking, the default stack, CI alignment,
  compatibility repairs, target-provider evidence and synthetic preservation.
- CS-PLAN-080 keeps WP9/C5j and all human evidence. Its old-runtime evidence
  remains valid historical evidence, never relabeled as Nextcloud 34 evidence.
  This plan supplies replacement technical evidence for the newer candidate.
- RELEASE-002 keeps actual AIO deployment, target access, backup authorization,
  installation smoke and explicit retained-baseline adoption. This plan prepares
  the validated artifact and handoff, not production operations.
- No recruitment, personal data collection, telemetry, release tag, App Store
  publication, forge migration or unrelated feature work.
- No speculative backfills/upgrade adapters for abandoned development installs;
  no fake migration merely to make an upgrade test execute.
- Main owns changes and synthesis. Internal read-only audits/rechecks may be
  delegated; no Agent Manager worktrees are requested.

### Decision for Standard A

**Q-082-01: Which Nextcloud/PHP versions will be advertised after promotion?**
Resolved 2026-10-05 in Standard A through the structured question batch:
**current stable major only** (recommended option approved) — advertise exactly
the newly verified baseline (initial target Nextcloud 34 / PHP 8.4) in
`appinfo/info.xml`, Composer and CI; expand later only with named passing
matrix rows. The 27-31 declaration is neither silently widened to an untested
27-34 range nor kept untested; metadata changes remain a candidate for testing
until the gates pass.

## 3. Backend / Frontend Changes

### 3.1 One Runtime Inventory, Stable Discovery, Frozen Execution

Add one machine-readable `docker/test-runtime.json` inventory consumed by a
small `scripts/test-runtime.js` resolver/checker and the local/CI provisioning
paths. Store exact Nextcloud release/internal version/source commit, notification
companion revision, PHP base image digest, Composer/tool identities, database
image digests, architecture and verification date. Record the bundled SQLite
version observed at runtime. Do not put credentials or server addresses there.

Separate two operations:

1. **Check current stable:** query official release/distribution metadata, reject
   prereleases and compare with the inventory. A new upstream release, moved tag
   or unsupported PHP/DB combination yields an explicit update-needed result;
   unavailable/malformed metadata yields unknown/error, never a false green.
2. **Run a candidate:** use the frozen inventory only. Resolve mutable upstream
   aliases before a run, not during installation or between browser pools.
   Assert actual installed versions/digests against that inventory. Cached or
   merely healthy older containers cannot satisfy the gate.

Provide a weekly check and a pre-release check through the existing forge/local
tooling. The check reports drift; it never deploys, commits, rewrites the lock,
updates a running environment or enrolls an external service automatically.
The maintainer owns prompt qualification/promotion of newer stable releases.
If a new release appears during a frozen pair, finish that pair honestly; report
the newer release as pending rather than changing the candidate underneath it.

Latest stable does not mean maximum PHP/PostgreSQL versions independently: use
an upstream-supported combination and track the AIO stable bundle separately.
When upstream stable and AIO stable differ, report both; verify the newer primary
candidate and retain the actual deployment target as a named compatibility row
until production catches up. Do not silently equate AIO `latest` with another
repository's tag or with the upstream development branch.

### 3.2 Staging and Canonical Promotion

Parameterize the shared Dockerfile/provisioning using the inventory, preserving
fail-closed selector/provider/app/seed readiness, APCu and native rate-limit
tests. Audit required PHP extensions, dependency installers, core submodules,
notification app compatibility and PHPUnit/CS tool support under PHP 8.4.
Prefer existing patterns; no warning suppression or forced app enablement.

Stage with unique `cs082-<run-id>` projects and project-owned networks/volumes.
Keep existing canonical and WP9 containers intact during candidate qualification.
Add a loopback-only HTTP endpoint where browser tests need it. Before any
seed/reset/job, prove HTTP and CLI point to the same owned test installation;
all helpers must use a coherent configured target, not a new URL with the old
hard-coded `chorestrike-test` OCC container. Missing/mismatched/test-mode-off
targets fail before mutation. Saved browser authentication is target-scoped.

PostgreSQL 18 requires reviewing its image-specific `PGDATA`/volume layout;
do not mount a PostgreSQL 16 data directory into 18 or mistake an unused volume
for persistence. Use fresh owned database volumes for this pre-adoption runtime
transition. A real PostgreSQL major upgrade would need its own native supported
procedure and is not authorized on the operator's server.

After qualification, update `docker/compose.test.yml`, default commands and CI
to the pinned current-stable PostgreSQL reference. Preserve
`docker/compose.test-matrix.yml` for all three providers, with exact compatible
MariaDB and SQLite versions verified in the inventory step. The canonical
service target must be unambiguous across docs, fixture helpers and CI. Retire
only explicitly owned disposable old test resources after recording evidence;
never use unqualified shared `down -v` or delete someone else's environment.

### 3.3 Compatibility Repairs and Support Metadata

Audit Nextcloud 32-34 API changes relevant to installed app code: DI/bootstrap,
controller authentication/CSRF/rate-limit dispatch, notifications/jobs, schema
and DBAL interfaces, transactions/savepoints, entity typing and PHP 8.4 behavior.
Audit actual browser integration with the new core (auth, CSP/assets, EN/DE,
Nextcloud Vue components), not only mocked unit contracts.

Repair evidenced defects with regressions in their existing owning suites.
Keep controllers thin, house-first serialization, strict permission checks and
exact financial accounting. Never remove a failing scenario, expand a query
budget without proof, add blanket provider skips or hide deprecation warnings.
If a platform/tool change requires a supported dependency update, update its
lock and test it; do not bypass platform requirements.

Metadata changes are a candidate for testing until gates pass, not support
evidence by themselves. Reconcile `appinfo/info.xml`, Composer, documented
requirements and CI with Q-082-01. No version/tag alone adopts retained data.

### 3.4 Populated Data: Three Distinct Proofs

Use only synthetic, separately owned fixtures. Extend/reuse the existing
recovery fixture/snapshot harness after auditing its runtime assumptions.
Create a dedicated `scripts/test-runtime-preservation.ps1` orchestrator if the
existing runner cannot express the runtime-upgrade sequence; keep snapshots
in the existing PHP helpers rather than inventing duplicate domain fixtures.
The existing `scripts/run-recovery-rehearsal.ps1` assumes canonical-image lookup,
MariaDB TCP/dump/restore commands and a `cs081-recovery-*` ownership prefix;
`tests/recovery/fixture.php` checks that prefix plus the evidence ownership file.
Adapt provider/runtime/owned-target parameters and their refusal tests together,
never weaken the fixture guard to accept arbitrary databases. Preserve password
redaction, failure propagation and cleanup failure-path self-tests. Run the
PowerShell orchestration on the supported Windows host; any CI execution must
explicitly provision its shell instead of pretending it is a POSIX script.

The fixture must contain stable IDs/relations and meaningful non-empty data:
house/floors/rooms; custom and template task definitions, recurrence, assignments
and checklists; occurrences/agreements/pauses; completions and a correction;
work shares and room journal; membership/preferences; wallet/ledger and an
active reward reservation/request. Add an unrelated Nextcloud sentinel to
detect whole-instance recovery loss. Compare facts and semantic invariants,
not only row counts or API success codes.

**P1 - persistence:** stop/recreate containers using the same named volumes and
same frozen build. Verify configuration/instance identity, migration inventory,
all setup IDs/values and stored facts survive. This is not an upgrade test.

**P2 - real runtime update:** install a concrete previous stable patch in the
same target major (proposed NC 34.0.3 -> 34.0.4, subject to artifact/support
verification), populate it through production services, capture state, replace
the runtime and run the official Nextcloud upgrade path with the same app
candidate. Test all three supported DB providers, pin source and target
artifacts, and compare app facts plus schema parity with fresh target installs.
No reset/seed is permitted between capture and comparison. Normal integration
and E2E reseeding suites run on separate fresh environments, never this fixture.
Do not simulate an upgrade by editing version rows or marking migrations applied.
If that source cannot be supported, resolve a concrete alternate real source
before execution; an unchanged-build reinstall cannot substitute for P2.

P2 proves that specific **Nextcloud runtime** update, not hypothetical future
ChoreStrike schema updates. After explicit baseline adoption, every actual app
schema change additionally requires a distinct real source/target app artifact,
new ordered migration steps, populated-baseline upgrade tests and fresh/upgrade
parity on all supported providers. Record this future gate durably; do not add
a speculative migration or claim it ran before an actual app change exists.

**P3 - recovery:** rerun the existing whole-instance backup/restore rehearsal on
the target PostgreSQL runtime into a separate owned target. Verify matched
code/config/DB/applicable-data recovery and the unrelated sentinel. Include
wrong-version/manifest and deliberate data-divergence negative controls; a
code downgrade or ChoreStrike-table-only restore is not recovery.

### 3.5 WP9 and Retained-Baseline Handoff

Record sanitized candidate hashes, app/runtime/provider versions, migration
identifiers/file hashes, commands, totals, exit codes and limitations. Renew the
full target-provider matrix and consecutive browser pair on the final build;
link them from WP9 without rewriting its historical NC31 passes. Distinguish
the regular PostgreSQL browser result from prior MariaDB browser evidence.

Update permanent testing/deployment/release guidance: where ChoreStrike facts
live in the Nextcloud DB; whole-instance backup scope; update/persistence/recovery
distinctions; how current-stable discovery becomes the verified default; what
operators do before first retained use and subsequent upgrades.

No productive AIO access is required to complete this technical plan. Actual
AIO proxy/cache/backup configuration, restore capability and installation smoke
remain explicit RELEASE-002 checks on an authorized target; publish no private
hostnames, credentials or dumps. The operator must then expressly adopt the
exact baseline before entering real house/task data. CS-PLAN-080 human evidence
may follow adoption; it is not a circular prerequisite to that decision.

## 4. Tests

| Gate | Required proof |
|---|---|
| R1 inventory/discovery | Unit fixtures: stable vs prerelease, newer release, different AIO bundle, registry failure, moved digest, malformed inventory, installed-version mismatch; ordinary tests use frozen fixtures, not live release APIs |
| R2 provisioning/target safety | Extend `TestEnvironmentProvisioningTest`, existing fixture/runner unit tests and schema tests: three fresh target-provider installs, actual runtime identity, fail-closed readiness, HTTP/OCC mismatch refusal before seed, no old-volume fallback |
| R3 full backend | Complete unit + integration suites with `--fail-on-skipped --do-not-cache-result` on every supported target provider, including independent-connection races/rollback, schema/seed/erasure and fixture cleanup; PHP coding standards and dependency platform checks |
| R4 browser | Production build, mandatory discovery, all three browser pools on the PostgreSQL reference twice consecutively; zero retries/skips/failures/flakes/interruption, target-specific auth/OCC routing, unchanged candidate between passes |
| R5 persistence/update | P1 retained-volume recreate + P2 real populated patch update on MariaDB/PostgreSQL/SQLite; setup/fact preservation, migration identity, fresh/updated schema parity and post-update reads/mutations on separate restored copies |
| R6 recovery | P3 target PostgreSQL whole-instance restore with meaningful app/core sentinels and failing negative controls; private raw evidence, sanitized public result |
| R7 default/CI | Clean canonical provisioning from documented default commands actually selects the new target; CI consumes the same inventory; weekly/pre-release check has tested drift/failure behavior and is wired, not documentation-only |
| R8 support/docs | Metadata matches approved support scope and passing rows; all durable docs consistent; WP9 evidence updated, human/deployment/adoption claims remain separate |

Additional gates: `npm test -- --runInBand`, `npm run lint`,
`npm run stylelint`, `npm run test:noise`, `npm run build`,
`npm run l10n:check-keys`, `node scripts/run-e2e-gate.js --self-test`,
`npm run docs:encoding`, `npm run backlog:check`, `git diff --check`.
Keep supported Node 20/npm 10 minimum-engine coverage; this plan does not silently
upgrade the frontend toolchain. Scoped formatters only over owned changes.

Before execution, capture discovered counts and exact source identities. Missing
or newly skipped required tests block delivery; filtered diagnosis never replaces
full gates. Any runtime/build/fixture/discovery change restarts the browser pair.

## 5. File Manifest

This drafting round: this file, backlog/registry/index linkage, a WP9 sequencing
note and preservation of its old-runtime evidence. No runtime file changes now.

| Kind | Paths | Purpose |
|---|---|---|
| N | `docker/test-runtime.json`, `scripts/test-runtime.js`, `tests/unit/scripts/testRuntime.spec.js` | Single runtime inventory, stable checks/validation and negative controls |
| M | `docker/Dockerfile.test`, `docker/compose.test.yml`, `docker/compose.test-matrix.yml`, `docker/test-entrypoint.sh` | Pinned target build, PostgreSQL canonical stack, portable matrix, readiness/preservation modes |
| M | `tests/unit/Command/TestEnvironmentProvisioningTest.php`, `tests/fixtures/provision-command.sh`, `tests/integration/Db/SupportedDatabaseSchemaTest.php` | Target/runtime/seed/provider regression proofs |
| M | `playwright.config.ts`, `scripts/run-e2e-gate.js`, `tests/e2e/fixtures/{occ-runner,auth-contexts,database-fixtures}.ts`, `tests/e2e/auth.setup.ts`, `tests/unit/scripts/{databaseFixtures,browserGate}.spec.js` | Coherent owned HTTP/OCC/auth target, strict discovery and execution |
| M | `.forgejo/workflows/{release-gate,test,full-matrix}.yml`, `scripts/run-tests.sh`, `package.json` | Canonical stack/inventory propagation, weekly/manual stable-check trigger and local commands; no forge migration |
| M / conditional N | `scripts/run-recovery-rehearsal.ps1`, `tests/recovery/fixture.php`; new `scripts/test-runtime-preservation.ps1` only if needed | P1/P2/P3 orchestration, owned-target guards, provider-aware dump/restore and stored-fact comparisons |
| M | `appinfo/info.xml`, `composer.json`, `composer.lock` | Approved compatibility bounds and only necessary verified tooling dependency updates |
| Conditional M | Existing `lib/`, `src/`, unit/integration/E2E tests and `l10n/` owning evidenced incompatibilities | Bounded repairs/regressions; enumerate exact files in findings before editing |
| M | `docs/engineering/{testing,development,deployment}.md`, `docs/testing/release-test-plan.md`, `docs/standards/guidelines/testing.md`, `docs/standards/ai/skills/docker-dev/SKILL.md`, root `README.md` | Durable default/update/support/preservation rules |
| M | This plan, CS-PLAN-080, `docs/plans/README.md`, `docs/README.md`, `docs/backlog/{backlog,ACTIVE_TASKS}.md` | Ownership, evidence and truthful status |
| Completion only | `docs/backlog/archive/YYYY-MM.md`, formal review record | Archive TECH-RUNTIME-001 only after final passing review; partial RELEASE-002/CONCEPT-001 stay open |

No retirement of tracked files is planned. If implementation proves a runner is
obsolete, record its replacement and archive it under the project archive policy.

## 6. Implementation Order

1. Standard A: validate scope, artifacts, R1-R8 and the retention boundary;
   resolve Q-082-01 and any other decisions together; only then activate.
2. Freeze the inherited WP9 candidate; inventory all runtime literals/CI/OCC and
   recovery paths, inspect Nextcloud/PHP changes, pin source/target images and
   the MariaDB/SQLite rows. Re-estimate if repairs exceed the bounded allowance.
3. Implement inventory/checker/target-safety tests and the staged target build;
   provision fresh isolated provider environments without touching the old stack.
4. Repair evidenced compatibility failures and run complete provider gates.
5. Execute P1/P2 on all providers and P3 on target PostgreSQL; keep the populated
   fixtures out of the ordinary reseeding runners.
6. Promote the verified target to canonical defaults and CI, run default-command
   checks and all quality gates, then freeze/build and run the final browser pair.
7. Integrate durable docs and link target-specific evidence into WP9 and
   RELEASE-002. Leave actual deployment/adoption and human evidence unclaimed.
8. Request final Standard B or Full review; only a passing complete review moves
   this plan to `done/` and archives its primary owner.

## 7. Risks & Mitigations

| Risk | Likelihood | Mitigation |
|---|---|---|
| Nextcloud/DBAL or PHP changes exceed metadata edits | High | Real installation and full suites first; bounded repairs with regressions and effort re-estimate |
| Mutable tags or build cache test a different candidate | High | Exact inventory, installed-version/digest checks, frozen pair and explicit stable-drift reporting |
| New browser URL but old OCC/reset target | High | One coherent target, identity preflight and refusal-before-mutation tests |
| PostgreSQL 18 mount layout loses or ignores data | High | Fresh owned volumes, explicit PGDATA audit, P1 retained-volume proof; no PG16 directory reuse |
| Green fresh install hides setup loss during updates | High | Separate P1/P2/P3; exact facts/IDs, no reseed, real source/target update |
| Local staging cleanup damages another environment | High | Unique project ownership; qualified cleanup only; preserve unrelated canonical/WP9 resources |
| Expanded compatibility range advertises untested combinations | High | Q-082-01 and metadata/CI/support matrix consistency gate |
| AIO parity overclaimed from Apache-only tests | Medium | Document packaging differences; leave authorized AIO installation/backup smoke with RELEASE-002 |
| Latest tracking drifts into automatic production changes | Medium | Read/check-only scheduled job; promotion reviewed, deployment separately authorized |
| Synthetic evidence mistaken for real-data adoption | High | Explicit operator decision remains separate; no personal data in fixtures/public evidence |

## 8. Acceptance Criteria

- [x] Standard A passed; Q-082-01 and all review decisions recorded.
- [ ] The regular local and CI defaults run the current-stable target, not NC31
  with an optional newer side instance; PostgreSQL is the reference provider.
- [ ] Frozen runtime inventory and actual installed identities match; newer
  stable releases are detected by the wired weekly/pre-release check.
- [ ] Full target-provider suites and two consecutive complete PostgreSQL browser
  gates pass with no missing/unapproved-skipped tests or reduced assertions.
- [ ] P1 and genuine P2 preserve non-empty setup/facts on all supported providers;
  P3 target PostgreSQL whole-instance recovery and negative controls pass.
- [ ] No old development-install upgrade, future app migration or full AIO
  equivalence is claimed without corresponding executed evidence.
- [ ] Support metadata matches the approved matrix; required quality gates pass.
- [ ] Durable test/update/backup/preservation rules are integrated; target evidence
  is linked from WP9 without rewriting historical runs.
- [ ] All plan-owned findings are resolved or explicitly user-rescoped; required
  deferred evidence still blocks completion.
- [ ] Final Standard B/Full review passes; primary backlog owner archived and
  registry updated without closing RELEASE-002, CONCEPT-001 or human gates.
- [ ] No productive deployment, retained-baseline adoption or access to the
  operator's server occurred without separate explicit authorization.

## 9. Review Findings (Plan-Owned)

Standard A `PASS` with zero findings on 2026-10-05 (see `## Review Outcome`);
no plan-owned findings are open. Q-082-01 was resolved as a review decision,
not an implementation approval. Record in-scope compatibility/test/tooling
findings here; do not export blockers to unrelated backlog items to declare
completion.

## Draft Validation

Documentation checks passed on 2026-10-05: `npm run docs:encoding` (894 files),
`npm run backlog:check` (35 scoped files) and `git diff --check`. The first two
used a temporary Git index containing the new draft because the stock commands
scan Git-known files only; the real staging area was not changed. Read-only
inventory checked the existing Docker/CI/OCC/recovery seams. No runtime gates,
formal review, lifecycle transition, commit, deployment or baseline adoption
were performed. Next step: Standard A with the Q-082-01 support-scope decision.

## Review Outcome

**Standard A — 2026-10-05 — `PASS` (zero findings, transition-eligible)**

- **Scope:** this plan file plus its declared uncommitted drafting package
  (TECH-RUNTIME-001 backlog entry, plans/docs registry linkage, ACTIVE_TASKS
  entry, CS-PLAN-080 sequencing note); runtime tree untouched.
- **Baseline:** branch `master`, HEAD `bd7ba8c6642fcbaa82d4cee04b8493c8ec506e2f`,
  index empty at capture. Untracked plan SHA256
  `7A58B6EBE32AF216C859E5E892F8620C85C9172DE5C340CE36EAC389B5B86AA2`; full
  unstaged-diff SHA256
  `FBAF8E559FDA60930BC633C8BC8108D781C63B1A4DE42CBBADE641028D7FDBAC`
  covering the preserved uncommitted WP9 implementation round, this plan's
  drafting package and the unrelated pre-existing `.gitkeep` deletions — all
  preserved unstaged by the activation commit.
- **Verification:** every §1 citation exact against the runtime
  (`docker/Dockerfile.test` `php:8.2-apache`/`composer:latest`/`stable31`
  server+notifications clones; `docker/compose.test.yml` MariaDB 10.6;
  `docker/compose.test-matrix.yml` PostgreSQL 16-alpine + SQLite profiles;
  `appinfo/info.xml` NC 27-31 / PHP 8.2-8.3); external artifact preflight
  re-verified (Nextcloud tag `v34.0.4`, `php:8.4.25-apache`,
  `postgres:18.6-alpine` all resolvable); §3.4 recovery-tooling claims exact
  (`tests/recovery/fixture.php:30` `cs081-recovery-` prefix guard,
  `scripts/run-recovery-rehearsal.ps1` canonical sha256-image check,
  `mariadb:10.6`/`mysqldump` assumptions); every §4 gate command exists in
  `package.json`; §5 manifest all-present (including `.forgejo/workflows/`
  trio and `scripts/run-tests.sh`); backlog ownership complete and open
  (TECH-RUNTIME-001 primary, RELEASE-002 bounded, CONCEPT-001 consumer);
  no overlap with active CS-PLAN-070/080 (bidirectional boundary notes
  verified); nine template sections complete; risks named with mitigations;
  R1-R8 test plan adequate with negative controls; no pre-baked workaround
  stacking; data-retention boundary respected (no adoption claimed).
- **Findings:** none.
- **Decisions:** Q-082-01 resolved via one structured question batch —
  **current stable major only** (recommended option approved); recorded in §2.
- **Gates:** `npm run docs:encoding`, `npm run backlog:check`,
  `git diff --check` / `git diff --cached --check` green after the activation
  edits; runtime gates belong to implementation steps 2-8 and were
  intentionally not run (no runtime file changed).
- **Transition:** plan moved `new/ → active/` with this outcome recorded;
  TECH-RUNTIME-001 marked In Progress (owner CS-PLAN-082); RELEASE-002 and
  CONCEPT-001 keep their existing owners/boundaries; registry linkage,
  CS-PLAN-080 sequencing note and CODE_REVIEW_2026-10-05 activation record
  updated; unrelated WP9-round changes and `.gitkeep` deletions left unstaged.
