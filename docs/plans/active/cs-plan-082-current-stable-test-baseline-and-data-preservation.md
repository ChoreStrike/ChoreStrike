# CS-PLAN-082: Current-Stable Test Baseline and Data Preservation

> **Status:** Active (implementation started 2026-10-05; NC35 primary qualification in progress); drafted 2026-10-05
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

**Q-082-02: Upstream stable versus the AIO deployment reference.** Resolved
2026-10-05 in the next implementation round through the structured question:
**NC35 primary** (recommended option approved). Qualify NC35.0.1 as the primary
candidate and retain NC34.0.4 as an explicitly named AIO compatibility row.
Keep the NC34 evidence unchanged; it is not NC35 evidence. Pin a real NC35.0.0
source for the primary P2 patch update in addition to NC34.0.3 for the AIO row.
Metadata remains a qualification candidate, not a support claim; canonical
promotion still requires the plan's technical gates. No deployment or retained
baseline is authorized by this answer.

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
same target major (primary NC35.0.0 -> 35.0.1; named AIO row NC34.0.3 -> 34.0.4,
subject to row qualification), populate it through production services, capture state, replace
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
| N (next implementation slice) | `scripts/test-runtime-stage.js`, `tests/unit/scripts/testRuntimeStage.spec.js` | Fresh owned inventory-driven provider staging and actual installed/image/volume identity regressions |
| N | `docker/test-runtime.json`, `scripts/test-runtime.js`, `tests/unit/scripts/testRuntime.spec.js` | Single runtime inventory, stable checks/validation and negative controls |
| N (implementation audit) | `scripts/test-target.js`, `tests/unit/scripts/testTarget.spec.js`, `.forgejo/workflows/runtime-check.yml` | Shared HTTP/OCC guard for three consumers; scoped-auth regression; isolated read-only weekly/manual drift job |
| M | `docker/Dockerfile.test`, `docker/compose.test.yml`, `docker/compose.test-matrix.yml`, `docker/test-entrypoint.sh` | Pinned target build, PostgreSQL canonical stack, portable matrix, readiness/preservation modes |
| M | `tests/unit/Command/TestEnvironmentProvisioningTest.php`, `tests/fixtures/provision-command.sh`, `tests/integration/Db/SupportedDatabaseSchemaTest.php` | Target/runtime/seed/provider regression proofs |
| M | `playwright.config.ts`, `scripts/run-e2e-gate.js`, `tests/e2e/fixtures/{occ-runner,auth-contexts,database-fixtures}.ts`, `tests/e2e/auth.setup.ts`, `tests/unit/scripts/{databaseFixtures,browserGate}.spec.js` | Coherent owned HTTP/OCC/auth target, strict discovery and execution |
| M | `.forgejo/workflows/{release-gate,test,full-matrix}.yml`, `scripts/run-tests.sh`, `package.json` | Canonical stack/inventory propagation, weekly/manual stable-check trigger and local commands; no forge migration |
| M / conditional N | `scripts/run-recovery-rehearsal.ps1`, `tests/recovery/fixture.php`; new `scripts/test-runtime-preservation.ps1` only if needed | P1/P2/P3 orchestration, owned-target guards, provider-aware dump/restore and stored-fact comparisons |
| M | `appinfo/info.xml`, `composer.json`, `composer.lock` | Approved compatibility bounds and only necessary verified tooling dependency updates |
| Conditional M | Existing `lib/`, `src/`, unit/integration/E2E tests and `l10n/` owning evidenced incompatibilities | Bounded repairs/regressions; enumerate exact files in findings before editing |
| M | `docs/engineering/{testing,development,deployment}.md`, `docs/testing/release-test-plan.md`, `docs/standards/guidelines/testing.md`, `docs/standards/ai/skills/docker-dev/SKILL.md`, root `README.md` | Durable default/update/support/preservation rules |
| M (implementation audit) | `docs/standards/guidelines/e2e-testid-conventions.md` | Repair previously literal auth-state consumption guidance together with the scoped helper |
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

### Next-Slice Progress (2026-10-05)

- [x] Resolve Q-082-02 and freeze NC35.0.1/35.0.0 plus the separate NC34 AIO row.
- [x] Implement inventory-driven fresh staging and installed-runtime/provider,
  actual configured DB endpoint and owned-volume refusal regressions.
- [x] Build the NC35 production Notifications prerequisites without forced app
  enablement and provision fresh PostgreSQL18/MariaDB11.8/SQLite installations.
- [x] Execute the complete NC35 primary provider unit/integration matrix with
  no skips; repair the evidenced schema-wrapper test assumption without reducing
  schema/default/index or concurrency assertions.
- [x] Resolve RF-082-02/04 before qualifying the named NC34 row/final dependency build.
  (Resolved 2026-10-06 by documented dispositions; NC34 row rebuilt, verified and
  backend-qualified on all three providers — see the 2026-10-06 slice below.)
- [ ] Complete populated P1/P2/P3, canonical/CI promotion, final PostgreSQL browser
  pair, remaining durable handoff and final lifecycle review.

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

Standard A `PASS` with zero findings on 2026-10-05 (see `## Review Outcome`).
Implementation findings are tracked below. Q-082-01 was resolved as a review decision,
not an implementation approval. Record in-scope compatibility/test/tooling
findings here; do not export blockers to unrelated backlog items to declare
completion.

- [x] **RF-082-01 / block / current-stable scope:** implementation preflight on
  2026-10-05 found upstream `v35.0.1` (official server latest-release API and
  stable administration manual), while AIO `v14.2.0` release notes name NC34.0.4.
  **Q-082-02** asked whether to qualify NC35 as primary plus an explicit NC34
  AIO compatibility row (recommended), or freeze NC34 first with NC35 pending.
   The first structured question was dismissed. In the next implementation
   round the operator selected **NC35 primary** through Q-082-02; the decision
   is recorded in section 2. This resolves the scope blocker, not the still-open
   runtime/preservation/promotion gates. No NC34 evidence will be described as
   NC35 current-upstream-stable evidence.

- [x] **RF-082-02 / major / NC34 AIO core platform gate:** the authentic NC34
   source image bundles `lcobucci/clock` 3.0.0 with PHP `~8.1.0 || ~8.2.0`.
   Both lock and actual-installed core Composer platform checks fail on PHP8.4.25,
   although core autoload and `SystemClock::now()` smoke pass. NC35 bundles 3.5.0
   and its actual core platform check passes. Keep the named NC34 row unqualified
   pending an upstream-supported artifact/disposition; never patch core vendor,
   bypass requirements or relabel the successful smoke as a passed dependency gate.
   **Resolved 2026-10-06 by documented disposition (operator-approved through the
   structured question batch):** upstream re-verification showed NC34.0.4 is the
   latest NC34 patch (no newer artifact), the NC34 root platform check passes on
   PHP8.4.25, the 3rdparty check fails on **exactly** `lcobucci/clock` 3.0.0
   (`~8.1.0 || ~8.2.0`, JSON-verified), and **AIO v14.2.0 itself deploys
   NC34.0.4 on `php:8.4.25-fpm-alpine3.24`** from the prebuilt release tarball —
   upstream production carries this exact combination without Composer platform
   enforcement. The row keeps PHP8.4.25 (identical to the deployment target);
   `verify-image` now asserts fail-closed that the 3rdparty failure set is exactly
   this one named package/constraint and reports it as `upstreamLimitations`
   (`v34.0.4-3rdparty-lcobucci/clock-~8.1.0 || ~8.2.0-php-8.4.25`) — visible,
   never relabeled as a passed gate. Any extra/different/missing failure fails
   verification and forces re-review.

- [x] **RF-082-03 / major / Notifications build prerequisite:** the first fresh
   NC35/PostgreSQL18 install stopped because the pinned notifications Git source
   requires generated `vendor/autoload.php`, absent from a raw clone. Resolved
   with the upstream production Composer hooks, actual platform check and an
   image-level autoload regression. Rebuilt immutable image `18151889c738...`
   installs/enables/seeds successfully on all three fresh `cs082-20261005c-*`
   projects. Failed `cs082-20261005b-pgsql` resources are retained; no
   canonical/WP9 resource was altered.

- [x] **RF-082-04 / minor / upstream Notifications lock freshness:** the pinned
   NC35 notifications source emits Composer's stale-root-lock warning during its
   authentic production install. Exact locked dependencies install, actual PHP
   platform checks and generated autoload pass; the warning was not suppressed.
   Preserve the upstream source/lock unchanged and resolve the upstream artifact
   or disposition before final qualification. A passing app installation is not
   a warning-free dependency build. Bamarni's notices about future 2.x defaults
   likewise remain visible under its locked 1.9.1 production hooks.
   **Resolved 2026-10-06 by documented disposition (operator-approved through the
   same structured question batch):** the pinned v35.0.1 revision is
   byte-identical in `composer.json` and lock content-hash to stable35 HEAD
   (`8093a36ee220a4a72fa466084856ae76baf66c29`) — no upstream-fixed artifact
   exists, so refreshing the hash in-build (`composer update --lock`) was
   rejected to keep the upstream artifact unchanged. `verify-image` now asserts
   the installed vendor package set equals the upstream lock exactly (19 packages
   primary, 17 AIO row) and that a root production dry-run install emits exactly
   the one named stale-lock warning on the primary row (none on the AIO row);
   warnings stay visible on stderr, any deviation fails closed. The NC34 pin
   additionally emits a stale `vendor-bin/rector` dev-tool sub-lock warning
   through the upstream bamarni hooks; it does not affect the `--no-dev` runtime
   dependency set and remains visible in build logs.

## Implementation Evidence

- **Initial capture (2026-10-05):** HEAD
  `5dd32a5ea048993f08d7b63caa343c032f21054d`; inherited tracked binary diff
  SHA256 `9834da855a7452fa03354bb8c08b2e07ad44255a38f34dea3050b3ef8abaa76a`.
  Inherited untracked WP9 fixtures/matrix remain present and unstaged. Existing
  `chorestrike-test`, `chorestrike-db`, `cs-wp9-20261005a-*` and unrelated
  containers were inventoried read-only, not stopped/recreated/reset.
- **Artifact preflight:** NC34.0.4 source
  `77c7284fed1f5c7b4bce1a94299e229f3281b606`, internal `34.0.4.1`;
  previous patch NC34.0.3 source
  `b11c68e25d6030187e02477d3d82013405f62965`, internal `34.0.3.2`.
  PHP8.4.25/apache, PostgreSQL18.6/alpine, MariaDB11.8.6 and Composer2.9.5
  registry manifests resolve on linux/amd64. Artifact availability is not a
  passed runtime or preservation gate.
- **First implementation slice:** candidate inventory/checker, official
  upstream-versus-AIO discovery, pinned source/image/tool build arguments,
  image-identity verification, weekly/manual read-only Forgejo check and shared
  owned loopback HTTP/CLI preflight added. All five browser pools now consume
  target-scoped auth paths; seed/reset/auth-setup/job entry points refuse an
  invalid target before mutation. No scenario inventory was reduced.
- **Build diagnosis resolved in-round:** pin verification initially ran before
  Git's safe-directory configuration and rejected the Nextcloud checkout.
  Both server/notifications checks now configure the exact repository first.
  Missing PCNTL and redundant compilation of already bundled PHP extensions
  were repaired; the final candidate reports PCNTL and all PDO providers.
- **Frozen image proof:** `node scripts/test-runtime.js build
  cs082-runtime-34.0.4-local` and `verify-image` pass. Image ID
  `sha256:900a242700d35fbfea5d720b4f330a95906ebf8ae5e50c748ccfb19ede61e161`;
  inventory hash `9a3e384c673473610ade6ca774dfd31d9b60c132dee4d05611e987fc729fb3c9`.
  Actual NC34.0.4/internal34.0.4.1, both source revisions, PHP8.4.25,
  SQLite3.46.1, PCOV1.0.12, APCu5.1.24 and Composer2.9.5 match. This was an
  ephemeral network-disabled image probe, not an installed app/provider pass.
- **Discovery proof:** `npm run test:runtime:validate` exits 0;
  `npm run test:runtime:check` exits **2 (`update-needed`)** with upstream35.0.1,
  AIO14.2.0/bundled34.0.4 and only `upstream-nextcloud` drift. Registry/source
  checks pass. Unavailable/malformed responses and moved artifacts have frozen
  unit fixtures; ordinary tests do not depend on live metadata.
- **Quality evidence (2026-10-05):** Node20.19.0/npm10.8.2 verified via ephemeral
  `npx --yes --package=node@20.19.0 --package=npm@10.8.2 --call ...` tooling;
  full Jest `npm test -- --runInBand` **1325/1325** in **111 suites**, lint,
  production build and full noise gate pass on those supported minimum engines.
  Stylelint, l10n exact-key check (1275 keys), browser-runner self-test,
  docs encoding (894 Git-known files), backlog check (35 scoped files) and
  `git diff --check` pass. Serial-pool discovery finds 164 tests in 29 files,
  including setup; this is discovery only, not browser execution.
- **Inherited-runtime regression proof:** canonical NC31/PHP8.2.34 full unit
  suite passes `--fail-on-skipped --do-not-cache-result`: **1334 tests / 4505
  assertions**. A read-only preflight against its real HTTP/CLI installation
  passes. No backend integration/reseeding/browser mutation suite was run on
  the existing environments in this slice. These unit results do not satisfy
  R3's newer-runtime provider matrix.
- **Focused internal recheck:** five reported target/identity/CI-routing issues
  were repaired with regressions, including the follow-up bare URL-delimiter
  edge case. Custom publications are exclusively numeric loopback; legacy
  localhost proves both address families; CI has an explicit owned-container
  marker and verified HTTP peer address; every image probe uses the captured
  immutable image ID; mutation callers use the validated normalized origin.
  Native-probe diagnostics fail closed; Composer's two exact informational
  version lines are validated and forwarded, not silently discarded. This is
  an internal partial-slice recheck, not the plan's final lifecycle review.
- **Remaining mandatory work:** isolated fresh installed-provider provisioning
  and identity assertions (R2), actual app/core/dependency compatibility (R3),
  P1/P2/P3 orchestration/execution (R5/R6), canonical/CI promotion (R7), final
  frozen PostgreSQL browser pair (R4), full durable deployment/support guidance
  (R8) and final review are still required. The weekly checker is wired but its
  forge execution has not been observed locally. Q-082-02 is unresolved; no
  acceptance checkbox, primary owner, deployment or retention boundary was
  closed/adopted. Index remains untouched; no commit or plan move.

## Next Implementation Slice (2026-10-05)

- **Approved target:** Q-082-02 selects NC35 primary and a distinct NC34 AIO
  compatibility row. Primary source NC35.0.1
  `3c012e618ab46ac73c61a96f5bb7168a693ca1fb`, internal `35.0.1.1`; real previous
  patch NC35.0.0 `da02f41d1cc73607084cd675ee1d849fc8cd2de1`, internal `35.0.0.10`;
  notifications `0389f40a52d07663efa09de8750a2e26cb528144`. PHP8.4/PG18/MariaDB11.8
  are upstream-supported NC35 combinations. Registry/source read-only discovery
  exits **0/current**, upstream35.0.1 versus AIO14.2.0/bundled34.0.4, with no
  drift. Inventory status remains **candidate**, not qualified.
- **Frozen final image:** `cs082-runtime-35.0.1-local`, immutable
  `sha256:18151889c7382d5c2af07760e41f44ba155604bff5de397da06320675a6848e5`,
  selected inventory hash
  `ac48918ac4b2e0080314507347c3d588cc7d031556279ca96f274a058184bcd8`.
  Image/runtime/source/tool identity and generated Notifications autoload pass.
  NC34 row selection preserves its original hash and historical image evidence;
  the old raw-source image does not satisfy the newly added autoload gate.
- **Failed attempt retained:** initial NC35 image `5f6577cb1940...` omitted the
  Notifications production Composer hooks; fresh `cs082-20261005b-pgsql` stopped
  during core installation before seed. Its owned resources remain diagnostic
  evidence. The rebuilt image uses exact source locks and upstream hooks, no
  forced enablement, ignored requirements or patched core vendor. RF-082-03 is
  resolved; upstream dependency/build warning dispositions RF-082-02/04 remain.
- **R2 primary installed providers:** sequential fresh projects
  `cs082-20261005c-pgsql` (loopback8185, PG18.6),
  `cs082-20261005c-mysql` (loopback8186, MariaDB11.8.6),
  `cs082-20261005c-sqlite` (loopback8187, on-disk SQLite3.46.1) all install,
  enable, family-seed and become healthy. Actual HTTP/CLI/config/source/PHP,
  app image, provider version and owned config/data/database volumes match.
  PG18 uses `/var/lib/postgresql/18/docker` below the owned root-volume mount.
  No existing canonical/WP9 container or volume was stopped/recreated/reset.
- **Scoped recheck repair:** actual DB version now comes from Nextcloud's real
  configured `IDBConnection`, bound to the fixed matrix host/port/database,
  inspected companion network peer and fixed SQLite path under its owned data
  volume, not mutable environment selectors or a separate PDO. All three positive
  installed checks pass; PostgreSQL/SQLite read-only negative probes inject
  foreign connection-parameter copies (with a coherent foreign `DB_HOST` for
  PostgreSQL) and refuse them before certification, without changing config/data.
  A final bounded internal source recheck confirms this finding resolved.
  Labelled and unlabelled old-resource, wrong-image/version, foreign volume,
  HTTP/runtime and provider refusals have regressions.
- **Complete primary backend matrix:** every provider discovers the same **603**
  integration tests, discovery SHA256
  `118c8a2301f10f2aacb6aa00ceff91c01389a506553ea79837ddd5931a06ee0e`.
  Unit command `vendor/bin/phpunit --configuration phpunit.unit.xml
  --fail-on-skipped --do-not-cache-result`: **1341 tests / 4636 assertions** per
  provider. Integration command `vendor/bin/phpunit --configuration
  phpunit.integration.xml --fail-on-skipped --order-by=default
  --do-not-cache-result`: PostgreSQL **603 / 5050**, MariaDB **603 / 5050**,
  SQLite **603 / 5052**, all exit 0 with no skips. The first complete attempts
  each had one schema-parity test error (NC35 `OC\DB\Schema\Table` wrapper versus
  the expected DBAL type); the test now compares the wrapper's underlying DBAL
  schema, retaining every original column/type/default/index assertion. No app
  domain fix, query-budget expansion or provider exclusion was required.
- **Dependency/tool gates:** app `composer validate --strict` and lock platform
  checks pass; every installed primary provider's app platform check is green.
  Authentic NC35 core and built Notifications actual runtime platform checks
  pass. Locked PHPUnit9.6.34/coding-standard1.4.0 remain sufficient; only app PHP
  constraint and Composer lock content hash changed, no tooling packages updated.
- **Final quality gates:** supported Node20.19.0/npm10.8.2 full Jest
  **1375 / 112 suites**, full noise gate, lint, stylelint, production build, l10n exact-key coverage
  (1275), browser-runner self-test, docs encoding, backlog check and
  `git diff --check` pass. PHP coding standards report **0 / 410** required fixes
  with cache disabled. This is primary backend/tooling evidence, not actual browser
  execution or a final lifecycle review.
- **Still open:** NC34 compatibility qualification and RF-082-02/04,
  populated P1/P2/P3 (R5/R6), canonical/CI promotion and default-command checks
  (R7), actual NC35 browser integration and the final frozen PostgreSQL pair
  (R4), remaining durable release/deployment/WP9 handoff (R8) and final review.
  This slice does not prove a populated update, preservation, recovery, AIO
  installation, production deployment or retained-baseline adoption. No commit,
  index change or plan move was performed.

## Next Implementation Slice (2026-10-06)

- **RF-082-02/04 dispositions (operator-approved):** both resolved as documented
  dispositions through one structured question batch after upstream
  re-verification — NC34.0.4 is the latest NC34 patch (no newer artifact), its
  3rdparty platform failure is exactly `lcobucci/clock` 3.0.0
  (`~8.1.0 || ~8.2.0`, JSON-verified), and AIO v14.2.0 itself deploys NC34.0.4 on
  `php:8.4.25-fpm-alpine3.24`; the NC35 notifications pin is byte-identical
  (`composer.json` and lock content-hash) to stable35 HEAD `8093a36e`, so no
  upstream-fixed artifact exists and `composer update --lock` was rejected to
  preserve the upstream artifact. Details in `## Review Findings`.
- **Fail-closed tooling assertions:** `verify-image` now runs row-aware upstream
  condition gates — exact 3rdparty/core platform failure sets (aio: exactly the
  named lcobucci/clock entry; primary: clean), notifications installed-vendor
  versus upstream lock equality (19 packages primary, 17 AIO) and exact
  production-install warning sets (primary: the one stale-root-lock line; AIO:
  none). Deviations — extra, different or missing failures/warnings — fail
  verification; warnings are forwarded visibly, never suppressed, and reported as
  named `upstreamLimitations`, never relabeled as passed gates. 16 new unit
  regressions cover both rows, all mismatch shapes and unknown-row refusal;
  staging threads the row selector through.
- **NC34 AIO row rebuilt and image-verified:** `cs082-runtime-34.0.4-local`
  immutable `sha256:c5e645fdbd776e027b6f82ad83fbd7cd788a8d25d008c7e4185d08db8dedca2e`,
  inventory hash `9a3e384c673473610ade6ca774dfd31d9b60c132dee4d05611e987fc729fb3c9`
  (unchanged). Actual NC34.0.4/internal34.0.4.1, both pinned source revisions,
  PHP8.4.25, SQLite3.46.1, PCOV1.0.12, APCu5.1.24, Composer2.9.5 and the generated
  Notifications autoload (17 locked packages) match; the named lcobucci/clock
  limitation is recorded. The primary image `18151889c738...` re-verified with the
  new gates: 19 locked packages, named `v35.0.1-notifications-stale-root-lock`
  limitation. The NC34 build log shows the additional stale
  `vendor-bin/rector` dev-tool sub-lock warning (visible, runtime-irrelevant).
- **NC34 row backend matrix qualified:** fresh owned sequential projects
  `cs082-20261006a-pgsql` (loopback8188, PG18.6), `cs082-20261006a-mysql`
  (loopback8189, MariaDB11.8.6-ubu2404), `cs082-20261006a-sqlite`
  (loopback8190, on-disk SQLite3.46.1) all install, enable, family-seed, become
  healthy and pass the installed runtime/provider/DB-endpoint/owned-volume
  identity assertions on image `c5e645fd...`. Per provider:
  unit **1341 tests / 4636 assertions**, integration PostgreSQL **603 / 5069**,
  MariaDB **603 / 5069**, SQLite **603 / 5072**, all with
  `--fail-on-skipped --do-not-cache-result` (integration also
  `--order-by=default`), exit 0, zero skips. App `composer validate --strict`
  and app platform check (`php 8.4.25 success`) pass on the NC34 row. No
  canonical/WP9 resource was altered; the retained failed `cs082-20261005b-*`
  and the three new `cs082-20261006a-*` projects remain as evidence.
- **Quality gates:** full Jest **1391 / 112 suites** (`--runInBand`), noise gate,
  lint, stylelint, production build, l10n exact-key (1275), browser-runner
  self-test, docs encoding (907 files), backlog check (32 scoped files),
  `git diff --check` all pass; `test:runtime:validate` valid and
  `test:runtime:check` now exits **0/current** (upstream 35.0.1 equals the
  primary candidate, AIO 14.2.0/34.0.4 unchanged, zero drift).
- **Still open:** populated P1/P2/P3 (R5/R6 — P2's named AIO row 34.0.3→34.0.4
  can now execute on the qualified row), canonical/CI promotion and
  default-command checks (R7), actual NC35 browser integration and the final
  frozen PostgreSQL pair (R4), remaining durable release/deployment/WP9 handoff
  (R8) and final review. Inventory status stays **candidate**; no support claim,
  deployment or retained-baseline adoption. No commit, index change or plan move
  was performed.

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

### Interim Standard B — 2026-10-06 — `PASS-WITH-FIXES` (plan stays active)

- **Scope:** the runtime-baseline slice of the remaining uncommitted round at
  `master` `5dd32a5` (dual attribution with CS-PLAN-080, operator-resolved):
  `docker/test-runtime.json`, `scripts/{test-runtime,test-runtime-stage,test-target}.js`,
  `.forgejo/workflows/{runtime-check.yml,test.yml}`, pinned-parameter
  `Dockerfile.test`/entrypoint/compose changes, `appinfo/info.xml` +
  `composer.json/lock` candidate bounds, target-scoped browser auth/OCC
  routing and their unit specs, plus this plan's evidence sections.
- **Baseline:** index empty at capture; unstaged-diff SHA256
  `4435B8C302140AE7E06A12F5060FF5312BE211D165637646794F6F8676B525BD`;
  inventory hash `ac48918a…` verified unchanged against the recorded final
  candidate.
- **Findings fixed in-round (operator-approved):** the `test-runtime.js` CLI
  catch-all discarded `error.message`, so the wired weekly drift job and
  manual invocations only reported "failed (unknown)". The catch now prints
  the specific message (mirroring `test-runtime-stage.js`); focused spec
  45/45, `test:runtime:validate` unchanged.
- **Decisions recorded:** dual-plan attribution; in-round fix; the three
  pre-existing `docs/plans/{active,new,onhold}/.gitkeep` deletions are
  included in the review commit by explicit operator decision.
- **Verification:** fail-closed provisioning/staging/target preflight held up
  under review (loopback-only, credential-free, owned-project, refusal before
  mutation); metadata remains the Q-082-01/02 qualification candidate with
  R8 still open; gates green per `CODE_REVIEW_2026-10-06.md`, including a
  fresh NC35 MariaDB run of the full unit (1341) and integration (603, zero
  skips) suites on the reviewed tree.
- **Boundary:** RF-082-02/04 and R2-R8 remainder (browser pair, P1/P2/P3,
  promotion, durable handoff, final review) stay open; no default promotion,
  deployment or baseline adoption.

### Interim Standard B — 2026-10-06 (second) — `PASS-WITH-FIXES` (plan stays active)

- **Scope:** the uncommitted NC34-row/disposition slice at `master` `5b30c53`:
  `scripts/test-runtime.js` (fail-tolerant `probe`, `UPSTREAM_BUILD_CONDITIONS`
  with the operator-approved RF-082-02/04 dispositions, row-aware
  `assertPlatformGate`/`assertNotificationsBuild`, `upstreamLimitations`
  reporting), the `test-runtime-stage.js` row threading, 16 new
  `tests/unit/scripts/testRuntime.spec.js` regressions, plus this plan's
  2026-10-06 slice evidence and ACTIVE_TASKS. Attribution unambiguous —
  CS-PLAN-082 only (no WP9/080 file touched).
- **Baseline:** index empty at capture; untracked: none; pre-fix unstaged
  binary-diff `1307094136765a364cdb05b6fae10ee3d69eb3c5` over the five files.
- **Findings fixed in-round:** unguarded `JSON.parse` of the notifications lock
  probe output in `assertNotificationsBuild` now throws the descriptive
  `lock probe malformed` error instead of a raw SyntaxError, mirroring the
  sibling platform gate (error path only, behavior-neutral). Focused specs
  105/105 green after the fix; `test:runtime:validate` unchanged
  (`ac48918a…`).
- **Verification:** the condition gates exact-match recorded upstream artifact
  states and fail closed on every deviation shape (extra, different or missing
  failures/warnings, unexpectedly clean AIO gate, probe diagnostics, malformed
  output, vendor/lock divergence, unknown rows); warnings stay visible on
  stderr and are reported as named `upstreamLimitations`, never relabeled as
  passed gates; probes are network-disabled and pinned to the immutable image
  ID; row selectors are validated end-to-end (`selectRuntime` plus
  `rowConditions`).
- **Observation (no action):** `scripts/` and `tests/unit` sit outside the
  canonical `npm run lint` scope; a direct eslint run shows the files'
  pre-existing compact-object style (HEAD already carries the same violation
  class, e.g. `test-runtime-stage.js` identical). New lines follow the
  established file convention; fixing would be formatting churn outside the
  canonical gate. Recorded here for honesty, not churned.
- **Gates (run by this review on the reviewed tree):** full Jest
  `npm test -- --runInBand` **1391/1391 in 112 suites**; focused runtime specs
  105/105; canonical `npm run lint` 0; `npm run docs:encoding` 907 files;
  `npm run backlog:check` 32 files; `git diff --check` and
  `git diff --cached --check` clean; `test:runtime:validate` valid. Docker
  image/build evidence (NC34 row `c5e645fd…`, primary `18151889…`,
  provider matrices) inherited from the round's recorded runs — no runtime or
  frontend/style/l10n file changed in this slice.
- **Boundary:** P1/P2/P3 (R5/R6), canonical/CI promotion (R7), the final
  frozen PostgreSQL browser pair (R4), remaining durable handoff (R8) and the
  final review stay open; inventory status remains **candidate**; no default
  promotion, support claim, deployment or retained-baseline adoption.
