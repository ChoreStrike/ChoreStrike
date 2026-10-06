# Code Review - 2026-10-06

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
