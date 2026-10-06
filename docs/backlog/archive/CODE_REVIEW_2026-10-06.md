# Code Review - 2026-10-06

## Final Full Review - CS-PLAN-082 (Repair and Verification Round)

**Tier:** Full, all four read-only reviewer tracks completed; bounded repair
rechecks requested. **Owner:** CS-PLAN-082 only; CS-PLAN-080 receives technical
evidence, not human-gate closure. **Branch/base:** `master`,
`a902f7f1c1cb5687d9950371b0e4151332d88b4b`. No `--no-commit` override.

### Capture and Scope

Index empty; no nonignored untracked files at capture. SHA-256 of the empty
staged diff and untracked manifest:
`e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855`.
Unstaged `git diff --binary --no-ext-diff` (PowerShell line output joined with LF)
SHA-256: `eeb30692fdb363dec233060f2089bb2dd222d4eccfd931e2b42bfd468d69dcf0`.
All tracked paths plus their `git hash-object` content hashes SHA-256:
`cd04ada2cd4eab331123a0a0208da416fe89b694684f683d04ec4998ad320d07`.
Combined staged/unstaged/paths/content/untracked fingerprint:
`783062002aa8740b054e411b75969ceb114e92fe85b7d69bf91fd26a9d6bcab5`.
The content hashes cover unchanged implementation as well as the dirty promotion
slice; the review is not inferred from the narrow working-tree diff.

Exact implementation/test/doc manifest (the owning plan's full domain, including
earlier committed slices and the newly authorized repairs):

```text
.forgejo/workflows/runtime-check.yml
.forgejo/workflows/release-gate.yml
.forgejo/workflows/test.yml
.forgejo/workflows/full-matrix.yml
docker/test-runtime.json
docker/Dockerfile.test
docker/compose.test.yml
docker/compose.test-matrix.yml
docker/test-entrypoint.sh
scripts/test-runtime.js
scripts/test-runtime-stage.js
scripts/test-runtime-preservation.ps1
scripts/test-target.js
scripts/run-tests.sh
scripts/run-tests.js
scripts/run-recovery-rehearsal.ps1
scripts/run-e2e-gate.js
scripts/playwright-summary-reporter.js
tests/unit/scripts/testRuntime.spec.js
tests/unit/scripts/testRuntimeStage.spec.js
tests/unit/scripts/testTarget.spec.js
tests/unit/scripts/databaseFixtures.spec.js
tests/unit/scripts/browserGate.spec.js
tests/unit/scripts/runtimeWorkflows.spec.js
tests/unit/Command/TestEnvironmentProvisioningTest.php
tests/unit/Command/RecoveryOwnershipTest.php
tests/fixtures/provision-command.sh
tests/integration/Db/SupportedDatabaseSchemaTest.php
tests/recovery/fixture.php
tests/recovery/owned-target.php
playwright.config.ts
tests/e2e/auth.setup.ts
tests/e2e/fixtures/occ-runner.ts
tests/e2e/fixtures/auth-contexts.ts
tests/e2e/fixtures/database-fixtures.ts
appinfo/info.xml
composer.json
composer.lock
package.json
README.md
docs/README.md
docs/engineering/testing.md
docs/engineering/development.md
docs/engineering/deployment.md
docs/testing/release-test-plan.md
docs/standards/guidelines/testing.md
docs/standards/guidelines/e2e-testid-conventions.md
docs/standards/ai/skills/docker-dev/SKILL.md
docs/plans/active/cs-plan-082-current-stable-test-baseline-and-data-preservation.md
docs/plans/active/cs-plan-080-wp9-cross-db-and-family-evidence.md
docs/plans/README.md
docs/backlog/backlog.md
docs/backlog/ACTIVE_TASKS.md
docs/backlog/archive/2026-10.md
docs/backlog/archive/CODE_REVIEW_2026-10-06.md
```

### Findings and Decisions

Four tracks: correctness/functionality, architecture/ownership, security/auth/data
flow, performance/code quality. Consolidated findings are RF-082-12..20 in the
owning plan; no out-of-scope backlog item was created. Initial seven major and
one minor findings were supplemented by a major canonical persistence defect
observed during authorized container recreation. No production app logic,
migrations, test skips or required-scenario inventory were changed.

The structured batch explicitly authorized all five functional behavior fixes,
both security fixes, and sanitized public placeholders only. Security details
were kept private. The follow-up decision authorized coherent canonical
config/data/database persistence and reset of only the inventoried synthetic
canonical installation. The removed original anonymous PostgreSQL volume was
`bcb4a182e4c22173f80ab39ab92dfea10c041907418e5131f98ff74a511cadce`, owned by the
`docker` compose project / `db` service. Other existing environments were not reset.

A real isolated distribution-smoke experiment found `nextcloud:35-apache` uses
PHP8.5.11, so Composer correctly rejected the approved PHP8.4 app constraint.
The operator explicitly chose the same profile/browser matrix on the frozen
runtime instead of expanding support or bypassing platform checks. The unshipped
distribution setup script was archived locally, its experimental containers
removed with qualified cleanup, and its draft target-selection issue eliminated
with that path. Full and smoke companion uploads now use a counts-only reporter.

### Verification

- Pre-repair fresh Jest 1442/112 suites; unit 1367/4870; PostgreSQL integration
  603/5048, zero skips; uncached PHP CS 0/412; Composer strict/platform gates pass.
- Post-repair default Node runner actually provisions, awaits full health,
  verifies the canonical runtime and executes both full suites: unit 1367/4870,
  integration 603/5071, zero skips, on the final named-volume configuration.
- Post-repair complete Jest/noise gate: 1466/113 suites on Node20.19.0/npm10.8.2.
  Scoped runtime/browser/workflow regression selection: 176 passing tests.
  Lint/fixer, stylelint, l10n 1275 keys, production build, browser-runner and
  preservation self-tests, docs/backlog checks and whitespace checks passed.
  Only the already approved upstream warning fixtures emit their named warnings.
- Canonical preserved recreation **and full down/up** retain an identical config
  hash, real synthetic data-file hash and facts hash over all 30 app tables.
  Three named volumes stay together; preservation mode performs no reseed.
- The actual sanitized Playwright reporter smoke passes 6 tests, zero failures,
  skips or retries. Its output contains only status and counts.
- Live stable discovery remains current (NC35.0.1, AIO14.2.0/NC34.0.4, no drift);
  canonical image/runtime/provider identity passes. Inventory status remains
  hash-coupled `candidate`, not a deployment/adoption decision.
- Inherited, not rerun: eight post-interim P1/P2/P3 summaries all PASS with full
  cleanup, source/target identities and preserved facts; P3 includes fresh roles,
  full DB/file equality and corrupted-file/divergence/downgrade controls. Six
  original NC35/NC34 provider logs end RESULT PASS. The earlier browser pair
  remains historical and is not substituted for the post-repair pair.

Final implementation/test path-and-content fingerprint (tracked source plus new
script/spec files, excluding docs):
`9fb10bd844f6544d7ebf157274192be3e10de8eca2df36506f8dbf070e4da9cc`.
This fingerprint was rechecked unchanged between and after the final consecutive
browser pair `run-29nxkD` + `run-Wy1fPg`. Each executed 85 chromium, 164 serial-db
and 39 scheduling-owner tests (288 total), process exits 0, zero violations,
skips/failures/flakes/interruptions/retries. The earlier pair is historical only.

### Final Outcome

**PASS-WITH-FIXES.** RF-082-12..20 resolved with explicit behavioral/security
authorization, regressions and affected actual runtime/browser gates. No remaining
plan-owned finding or unanswered question. Durable docs now cover all new rules,
including exact upstream dispositions and sanitized artifacts. Forgejo remote
execution was not observed; actual default provisioning/backend commands,
workflow-structure regressions and local browser equivalents were verified.

All acceptance criteria complete: CS-PLAN-082 moved `active/` to `done/`,
TECH-RUNTIME-001 archived, registry/indices and WP9 evidence link updated.
RELEASE-002, CONCEPT-001 and CS-PLAN-080/human evidence remain open. No production
deployment, AIO packaging claim or retained-baseline adoption. The eligible
package receives one scoped conventional commit on the existing `master` branch;
private/raw evidence and the archived unshipped distribution experiment are excluded.

## Interim Standard B — Remaining Uncommitted Round (CS-PLAN-080 + CS-PLAN-082)

### Scope and Candidate

Owning plans: dual attribution resolved by the operator in one structured
question batch — CS-PLAN-080 owns the WP9-domain slice (isolated DB matrix,
portable races, fixture isolation, T12 scenarios, handover projection),
CS-PLAN-082 owns the runtime-baseline slice (frozen inventory, stable
discovery, owned staging, coherent HTTP/OCC targets, support metadata).
Both plans stay `active/`; this is an interim review, not a completion proof.

Scope: the complete remaining uncommitted working tree at capture — 91 tracked
files changed (+2624/−806) plus 15 untracked files, spanning `docker/`,
`scripts/`, `.forgejo/`, `lib/`, `src/`, `tests/` (unit/integration/e2e),
`l10n/`, `appinfo/`, `composer.*`, `package.json`, `README.md` and the
plans/backlog/standards documentation surface. No path, commit range or plan
restriction was given; the full set was reviewed.

Branch `master`, base HEAD
`5dd32a5ea048993f08d7b63caa343c032f21054d`; index empty at capture.

- Staged binary diff SHA-256 (empty):
  `E3B0C44298FC1C149AFBF4C8996FB92427AE41E4649B934CA495991B7852B855`
- Unstaged binary diff SHA-256 (404,597 bytes):
  `4435B8C302140AE7E06A12F5060FF5312BE211D165637646794F6F8676B525BD`
- Sorted untracked path/content-hash manifest SHA-256:
  `23633D5D2FE84E6177AB2E60C07D02562AB19E64994AB776FE684AB5D0C97AE3`
- Runtime/test candidate fingerprint
  (`git diff --binary -- docker lib src tests scripts phpunit.integration.xml phpunit.unit.xml | git hash-object --stdin`)
  at review capture: `d917dc6b4a127a9ea01c3f8bf8550d627372cb43`
  (before the in-round fix below; recomputed at commit time).

### Verification Highlights

- Production backend (WP9 slice): typed Boolean EXISTS comparisons, nullable
  timestamp predicates, deterministic `completed_at DESC, id DESC` activity
  ordering (ORDER BY correctly removed from the shared COUNT builder),
  `occurrenceDue` date typing, savepoint-isolated speculative checklist insert
  with winner re-lock and no exception masking, batched
  `proposal.obligatedUserIds` projection distinct from voluntary shares.
- Frontend: occurrence-identity-bound drafts/consent (reset clears consent),
  409 refresh-then-rethrow preserving drafts, handover through the delivered
  participant-amendment API, TaskManager creation guard.
- Provisioning (082 slice): fail-closed selector/profile/driver validation,
  frozen-runtime version checks, PDO-driver readiness, required-seed abort,
  ready-marker health gating; isolated matrix companion without fixed names or
  shared state; owned-fresh-project staging refusing existing resources;
  credential-free loopback-only target preflight refusing before mutation;
  read-only weekly drift check failing visible (exit 2 drift / 3 unknown).
- Tests: no weakened assertions — removed `markTestSkipped` guards replaced by
  real cross-provider workers with bounded SQL barriers; schema assertions
  ported from `information_schema` raw SQL to Nextcloud schema abstractions
  (equal or stronger); query budgets keep MariaDB ceilings with exactly pinned
  provider overhead (PG lastval 1/8/3, SQLite lock writes 2/7/7); gate-runner
  required minimums only raised; i18n 1275 keys exact across EN/DE/POT.
- Docs: matrix/staging/target coherence in `engineering/testing.md`, savepoint
  semantics in `development.md`, honest NC35-candidate framing in README and
  `appinfo` (metadata remains qualification candidate per Q-082-01/02 with
  gates still open), unfilled human-evidence template in release-test-plan 12.1,
  automated-evidence boundaries in 12.2, parent WP9 evidence map in CS-PLAN-070.

### Gates (run by this review on the reviewed tree)

`npm test -- --runInBand` 1375/1375 (112 suites); ESLint 0; stylelint 0;
`npm run build` compiled successfully; `l10n:check-keys` 1275 exact;
`docs:encoding` 894 files; `backlog:check` 35 files; `git diff --check` /
`--cached --check` clean; `test:runtime:validate` valid (inventory hash
`ac48918ac4b2e0080314507347c3d588cc7d031556279ca96f274a058184bcd8`, matching
CS-PLAN-082's recorded final candidate); `run-e2e-gate.js --self-test` passed;
NC35 candidate container (PHP 8.4.25, MariaDB 11.8.6):
`phpunit.unit.xml --fail-on-skipped` **1341 tests / 4636 assertions OK** and
`phpunit.integration.xml --fail-on-skipped --order-by=default` **603 tests OK,
zero skips** (5048 assertions; the 2-assertion delta versus the recorded 5050
matches the state-dependent GameController/TestModeHttp variance the plan
documents between its own passing runs); `composer cs:check` 0/410.

Recorded plan evidence not rerun here (fingerprint/inventory-matched): complete
three-provider NC35 matrix (1341 unit + 603 integration per provider), NC31
final browser pair `run-7H4sub`/`run-VTfa1r` (superseded for the newer runtime
by CS-PLAN-082's still-open R4), noise gate.

### Findings

| Severity | Location | Issue | Status |
|---|---|---|---|
| minor | `scripts/test-runtime.js:223-226` | CLI catch-all discarded `error.message`; the wired weekly runtime-check job and manual invocations only ever saw "failed (unknown)", including usage errors | **Fixed in-round** (operator-approved): the catch now prints the specific message, mirroring `test-runtime-stage.js`; focused spec 45/45 green, valid path unchanged |

No security, authorization, migration, workaround (`// HACK:`) or skipped-test
findings. No secrets or private content in the reviewed package.

### Decisions (one structured batch, operator-answered)

1. Owning plan: **dual attribution** (080 WP9-domain / 082 runtime-baseline),
   recommended option approved.
2. `scripts/test-runtime.js` finding: **fix in-round**, recommended option
   approved → outcome `PASS-WITH-FIXES`.
3. Pre-existing `docs/plans/{active,new,onhold}/.gitkeep` deletions:
   **include in this commit** (explicit operator decision, overriding the
   preserve-unstaged precedent of earlier review commits).

### Outcome

**PASS-WITH-FIXES** (interim Standard B). Both owning plans stay `active/`:
RF-080-05 (HUMAN-01/02) remains the WP9 completion blocker; CS-PLAN-082's
RF-082-02/04, browser pair, P1/P2/P3, promotion and final review remain open.
No lifecycle move, no deployment, no retained-baseline adoption.

The automatic review commit stages the complete reviewed package plus this
record, both plans' Review Outcome entries and tracking updates.

---

## Interim Standard B — Second Review (CS-PLAN-082 NC34 Disposition Slice)

### Scope and Candidate

Owning plan: explicit —
`docs/plans/active/cs-plan-082-current-stable-test-baseline-and-data-preservation.md`
(sole attribution; no CS-PLAN-080 file changed). Interim review, not a
completion proof: R4/R5/R6/R7/R8 and the final review remain open, so the plan
stays `active/`.

Scope: the current unstaged working tree at capture — 5 tracked files
(+276/−13), no staged files, no untracked files:

- `scripts/test-runtime.js` — `probe`, `UPSTREAM_BUILD_CONDITIONS`
  (RF-082-02/04 dispositions), `assertPlatformGate`, `assertNotificationsBuild`,
  `upstreamLimitations` reporting, row-aware `verify-image`
- `scripts/test-runtime-stage.js` — row threading through `verifyImage`
- `tests/unit/scripts/testRuntime.spec.js` — 16 new regressions
- `docs/plans/active/cs-plan-082-…md`, `docs/backlog/ACTIVE_TASKS.md` —
  slice evidence and tracking

Branch `master`, base HEAD `5b30c53b9596aaaeab96527ec385b4e1d4961fbf`; index
empty at capture.

- Staged binary diff SHA-256 (empty):
  `E3B0C44298FC1C149AFBF4C8996FB92427AE41E4649B934CA495991B7852B855`
- Unstaged binary diff git-hash (pre-fix capture):
  `1307094136765a364cdb05b6fae10ee3d69eb3c5`
- Untracked: none (empty manifest).

### Verification Highlights

- The recorded RF-082-02/04 dispositions are encoded as exact expected
  artifact states, not tolerances: primary expects zero 3rdparty platform
  failures plus exactly the one named stale-root-lock warning; the AIO row
  expects exactly the `lcobucci/clock 3.0.0` (`~8.1.0 || ~8.2.0`) failure and
  no warnings. Extra, different or missing entries all fail closed; probe
  diagnostics and malformed output fail closed; unknown rows are rejected
  rather than defaulted permissive.
- Warnings are forwarded to stderr (verified visible in the Jest output) and
  surfaced as named `upstreamLimitations` — never suppressed, never relabeled
  as a passed dependency gate.
- Probes run `--network none` against the immutable image ID (never the
  mutable tag); row selectors are validated both by `selectRuntime`
  (`--runtime=`/positional) and `rowConditions`.
- Notifications vendor equality is proven against the upstream lock
  (`composer.lock` vs `vendor/composer/installed.json`, name+version sorted);
  the build probe uses `bash -o pipefail` so a failing dry-run install cannot
  hide behind the grep.

### Gates (run by this review on the reviewed tree)

Full Jest `npm test -- --runInBand` **1391/1391 (112 suites)**; focused
runtime specs 105/105 (before and after the fix); canonical `npm run lint` 0;
`npm run docs:encoding` 907 files; `npm run backlog:check` 32 files;
`git diff --check` / `git diff --cached --check` clean;
`test:runtime:validate` valid (inventory hash `ac48918a…`, matching the
recorded primary candidate). Docker-based verify-image/provider evidence
(NC34 `c5e645fd…`, primary `18151889…`, three-provider matrices) inherited
from the round's recorded runs; no runtime, frontend, style or l10n file
changed in this slice.

### Findings

| Severity | Location | Issue | Status |
|---|---|---|---|
| nit | `scripts/test-runtime.js:243` | `JSON.parse(locked.stdout)` unguarded — a status-0/quiet-but-malformed lock probe would surface as a raw SyntaxError instead of an actionable message (still fail-closed) | **Fixed in-round**: now throws `Candidate notifications lock probe malformed`, mirroring the platform gate; focused specs green |

Observation (no action): `scripts/` and `tests/unit` are outside the canonical
`npm run lint` scope; direct eslint reports the files' pre-existing
compact-object style (HEAD versions carry the same class;
`test-runtime-stage.js` identical to HEAD). New lines follow the established
file convention; recorded to avoid silent scope expansion and formatting
churn. No security, authorization, migration, workaround (`// HACK:`) or
skipped-test findings. No secrets or private content.

### Decisions

None required — no open question, security, architecture or behavior-change
authorization arose; the single nit was behavior-neutral and auto-fixed under
the review auto-fix rule.

### Outcome

**PASS-WITH-FIXES** (interim Standard B, second review of the date).
CS-PLAN-082 stays `active/`: P1/P2/P3 (R5/R6), canonical/CI promotion (R7),
the final frozen PostgreSQL browser pair (R4), remaining durable handoff (R8)
and the final review remain open. Inventory status stays **candidate**; no
default promotion, support claim, deployment or retained-baseline adoption.

The automatic review commit stages the five reviewed files, this record
append, and the plan's Review Outcome / ACTIVE_TASKS updates.

---

## Full Interim Review - CS-PLAN-082 Preservation Slice

### Scope and Capture

Explicit owner: `docs/plans/active/cs-plan-082-current-stable-test-baseline-and-data-preservation.md`.
The operator approved escalation from requested Standard B to Full interim.
All four read-only tracks completed; initial findings were consolidated and the
operator explicitly approved all six fixes, including safety/behavior changes.
Each track rechecked the repaired implementation; no unresolved finding remains.

Base branch `master`, HEAD `b481e8146441970d9dd3cc26ab62c9a31e03878c`.
The index was empty and its `git ls-files --stage -z` SHA-256 was
`d673ef3ee67a79e0b9d4a8d2d615dc2e0111de6dd73d452776f48cae96092270`.
Initial binary staged diff SHA-256:
`e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855`.
Initial binary unstaged diff SHA-256:
`50c173d69dca5dd16503a7f80f2e934fbdd9ef51f213f1cfe04d39d5710197ff`.
The sole untracked file, `scripts/test-runtime-preservation.ps1`, had SHA-256
`c74b8747b9e6e655af2c4fb8ca8abbc515769fea1cc7a43acd743915bd064875`.
Combined SHA-256 (staged bytes, NUL, unstaged bytes, NUL, compact JSON sorted
untracked `{path,sha256}` array):
`dc66e0204de420c771e547874cf4b318103df809db350dcac1ab42a5691a8758`.

Exact initial reviewed path manifest:

```text
docker/Dockerfile.test
docker/test-entrypoint.sh
docker/test-runtime.json
docs/backlog/ACTIVE_TASKS.md
docs/plans/active/cs-plan-082-current-stable-test-baseline-and-data-preservation.md
scripts/test-runtime-preservation.ps1
scripts/test-runtime-stage.js
scripts/test-runtime.js
scripts/test-target.js
tests/fixtures/provision-command.sh
tests/recovery/fixture.php
tests/unit/Command/TestEnvironmentProvisioningTest.php
tests/unit/scripts/testRuntime.spec.js
tests/unit/scripts/testRuntimeStage.spec.js
tests/unit/scripts/testTarget.spec.js
```

Review-owned additions to the allowed commit set: this record,
`docs/engineering/testing.md`, `tests/recovery/owned-target.php` and
`tests/unit/Command/RecoveryOwnershipTest.php`. No production domain, migration,
frontend behavior, support metadata or canonical runtime promotion was changed
by the review fixes.

### Findings and Resolutions

Locations below refer to the captured pre-fix state.

| ID | Severity | Location | Finding and verified resolution |
|---|---|---|---|
| RF-082-06 | major | `scripts/test-runtime-stage.js:165` | Source installation validation occurred after mutation; complete pinned source preflight now precedes teardown, with seven refusal-before-mutation regressions. Safety recheck passed. |
| RF-082-07 | major | `scripts/test-runtime-preservation.ps1:385` | Same-cluster restore relied on uncaptured roles; private roles backup now restores into a fresh cluster, with source roles absent before restoration and real app authentication/mutation passing. |
| RF-082-08 | major | `scripts/test-runtime-preservation.ps1:378` | Pipeline failure was mislabeled as artifact rejection; real restored-file corruption now fails the actual checksum verifier before an untouched restore passes. Historical evidence corrected. |
| RF-082-09 | minor | `scripts/test-runtime-preservation.ps1:337` | Function-local flag skipped network cleanup; shared lifecycle state and nested success/failure cleanup regressions now pass, and real proof networks are absent. |
| RF-082-10 | major | `scripts/test-runtime-preservation.ps1:94` | Tests copied the PHP predicate in PowerShell; actual shared PHP predicate and executable pre-bootstrap refusal now have 16 regression cases. |
| RF-082-11 | minor | `scripts/test-runtime-preservation.ps1:501` | PS5.1 appended UTF-16LE into UTF-8 logs; uniform UTF-8 append preserves exit code and passes strict whole-log encoding regression. |

No open finding, workaround, weakened assertion or newly skipped test remains in
this slice. The operator also allowed a sanitized public blocker placeholder if
needed; no unresolved security detail is published. Raw recovery artifacts,
database dumps, role authentication state and detailed logs stay private.

### Verification

- Full Jest on Node20.19.0/npm10.8.2: **1425 tests / 112 suites**, zero failures.
  Full noise gate, canonical lint and production build pass on the same engines.
  Host-default lint initially emitted Node's `DEP0180`; rerunning on the supported
  minimum toolchain was clean, without changing dependencies or suppressing warnings.
- Fresh isolated NC35.0.1/PHP8.4.25/PostgreSQL18.6 review target:
  unit **1367 / 4870 assertions**, integration **603 / 5048 assertions**,
  `--fail-on-skipped --do-not-cache-result` (integration `--order-by=default`).
  Before fixes the same fresh target passed 1351/4852 and 603/5069; integration
  assertions vary with persistent test state as documented by earlier runs.
- Uncached PHP CS **0 / 412**; Composer strict validation and actual platform
  requirements pass; stylelint, l10n exact keys (1275), runtime inventory,
  browser-runner self-test, docs encoding, backlog links and whitespace pass.
- Renewed P1 primary PostgreSQL/MariaDB/SQLite: `a1060011`, all PASS.
  P2 primary PostgreSQL `a1060002`, MariaDB/SQLite `a1060012`, NC34/AIO
  PostgreSQL `a1060013`, all PASS. Same named volumes, 26 migrations, 30 table
  snapshots and core/config/file sentinels survive; real patch upgrades and
  post-update mutations/schema parity pass, with no reset between comparisons.
- Renewed P3 `a1060003`: **PASS**, fresh cluster with captured roles,
  whole-database rows/file hashes equal, divergence/corrupted-file/downgrade
  controls fired, post-restore mutation succeeds, cleanup succeeds with zero
  errors. Docker confirms no `cs082-a106*` proof network remains.
- Six-row rebuilt-image R2/R3 matrix is inherited from the implementation
  evidence, not claimed as rerun by this review. The final PostgreSQL browser
  pair is still open; the browser-runner self-test is not browser execution.

Final implementation content manifest SHA-256:
`226aa137aef526eaa990771c09605921a4eb3c7ae82428c40744a0ca5c98aa09`.
Computed from compact JSON of sorted `{path,sha256}` entries for every changed
non-doc path, including all untracked implementation files, before final docs.

### Outcome and Finalization

**PASS-WITH-FIXES**, Full interim. Durable preservation instructions integrated
into `docs/engineering/testing.md`; RF-082-06..11 and the outcome are recorded
in the owning plan, and ACTIVE_TASKS is updated. No new backlog item is needed:
all findings were plan-owned and resolved in-round. R4/R7/R8 remain planned work.
CS-PLAN-082 stays `active/`; TECH-RUNTIME-001, RELEASE-002 and CONCEPT-001 stay
open. No deployment, retained-baseline adoption, branch switch, push or PR.
The eligible scoped commit contains the reviewed slice and these verified fixes
and review-owned records only; no lifecycle move or owner archival is eligible.
