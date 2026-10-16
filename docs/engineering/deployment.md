# Deployment Guide

> Single source of truth for installing, deploying, and rolling back ChoreStrike.
> Consolidates the former root `DEPLOYMENT.md` and `docs/DEPLOYMENT_CHECKLIST.md`.

> **Pre-release scope:** the commands below are generic examples, not a complete
> AIO runbook. Select and record a verified commit, build a clean production
> artifact, and resolve the actual container/user/persistent app path in the
> server's operations project before executing them. Installation is not
> retained-baseline adoption. Do not run test compose stacks, seeds or resets
> against a real household instance.

## Prerequisites

- Nextcloud 34–35
- PHP 8.4
- Composer in a PHP 8.4 build environment
- MariaDB/MySQL/PostgreSQL/SQLite
- Docker & Docker Compose (for the test environment)
- Node.js 20.19.0 or later within 20.x and npm 10.8.2 or later within 10.x
  (for the frontend build; see the [engine contract](development.md#initial-setup))

## Installation

### 1. Clone the repository

```bash
cd /var/www/html/custom_apps/
git clone https://github.com/ChoreStrike/ChoreStrike.git chorestrike
cd chorestrike
```

### 2. Build the frontend

```bash
npm ci --engine-strict --legacy-peer-deps=false
npm run build
```

The app uses the shared webpack `style-loader` contract. Global CSS and Vue
scoped styles are injected by the main JavaScript bundle and its lazy chunks;
there is no generated `css/chorestrike-main.css` to register or package.
Ship the matching runtime `js/` bundles, all lazy chunks and licence notices
from a clean build, but exclude source maps (`*.map`) from the runtime package.
Keep maps in private build evidence, not the deployed app. Never add an empty
CSS placeholder or copy a stale workspace stylesheet.
Webpack's timestamp-based cache busting means a later rebuild is a different
artifact: hash and test the exact package that will be handed to operations.
Qualified builds pin the cache-bust query to the commit-derived
`SOURCE_DATE_EPOCH` packaging input instead of wall-clock time
(`webpack.config.js`, CS-PLAN-083 WP2), so two independent builds of the same
clean candidate and pinned tools produce byte-identical archives.

The in-repo package identity gate produces the handoff record; do not assemble
or hash archives manually. `npm run test:package:build` (CS-PLAN-083 WP2)
packages only from a clean, HEAD-pinned checkout inside the pinned Docker
builder, emits the archive plus package/source manifests, the source-vs-git-blob
identity proof and the asset-contract report under gitignored `build/package/`,
and `--verify-reproducible` proves the two-build byte-identity. `npm run
test:package:smoke` (WP3) then qualifies the same extracted archive on the NC35
canonical and NC34 compatibility runtime rows and writes the identity-bound
qualification record. See `docs/engineering/testing.md` §Package Identity Gate.
The captured SHA owns archive/tree/configuration inputs; Git failures or
candidate drift refuse qualification. The pinned Linux builder also performs
final gzip compression and records its compressor identity. Complete bundled
dependency licenses and attribution ship under `licenses/npm/`; omitted npm
license texts require a reviewed, version-bound upstream source, never a
runtime network lookup. Existing smoke volumes/networks are not fresh fixtures,
and only run-labelled, identity-verified resources may be removed after success.

Capture the source/build-input manifest **before** compilation and compare it
again after qualification. Keep the final source/test manifest, exact commands,
test counts and package hashes together. A runtime/build-input edit after the
tests requires a new build and qualification; do not label the earlier bundle
as a build of the later commit. A test-harness-only correction must be recorded
separately, with unchanged runtime/build inputs and renewed relevant gates.
Before source publication, compare the qualified files with the exact Git
content, not just the branch name or app version. Working-tree candidates must
be identified by base commit plus patch/source fingerprint, not as clean commits.

`src/public-path.js` initializes Webpack's asset base with Nextcloud's
`generateFilePath()` before app/router imports. Physical assets can live below
`/apps`, `/custom_apps`, or a Nextcloud subwebroot; controller/API routes remain
`/apps/chorestrike` through `generateUrl()`. Do not compensate for incorrect
chunk URLs with an `/apps/chorestrike` symlink or proxy alias. Package acceptance
must exercise alias-free lazy navigation and compare actual requested URLs,
JavaScript MIME types and response bytes with the package.

This static-asset base is a **mandatory canonical-gate contract**
(CS-PLAN-083 WP1): the contract chain is
`window._oc_appswebroots` → `src/public-path.js` → emitted chunks, and the
canonical browser gate (`tests/e2e/minimal-smoke-tests.spec.ts`) asserts the
exact `/custom_apps/chorestrike/js/` base with HTTP 200 and JavaScript MIME
for every fetched app script. No canonical alias-free browser assertion may
accept the legacy `/apps` asset base; page/API `/apps/chorestrike/...` URLs
stay untouched because index.php routing is independent of the physical app
root. Unit resolver coverage for genuine `/apps` installations and
subdirectory deployments remains in `tests/unit/scripts/assetContract.spec.js`.

### 3. Install PHP dependencies

```bash
composer install --no-dev
```

> The `vendor/` directory is **not** tracked in Git — run `composer install` after
> every fresh checkout. `composer.lock` stays committed to pin exact versions.

### 4. Enable the app in Nextcloud

```bash
occ app:enable chorestrike
```

### 5. Localization (German default)

ChoreStrike declares `<default_language>de</default_language>` in `info.xml`, so
users who have not chosen a Nextcloud language default to German. The frontend
registers its bundled German catalog via `@nextcloud/l10n` at startup
(`src/i18n.js`), selected by the user's language, including regional German
codes. It needs neither a static `l10n/*.json` HTTP response nor a webserver
JSON allowlist change. English and unsupported languages use English source
strings; selecting German must be verified by actual translated UI text.

For a server-wide German default (e.g. a family instance with German children),
also set in Nextcloud's `config/config.php`:

```php
'default_language' => 'de',
```

Per-user language selection in Nextcloud's personal settings still overrides
both defaults — individual users can switch to any available language.

## Pre-Deployment Checklist

### Code Quality

- [ ] All unit tests pass (`vendor/bin/phpunit --configuration phpunit.unit.xml tests/unit`)
- [ ] All frontend tests pass (`npm test`)
- [ ] PHP coding standard verified (`composer cs:check` — Nextcloud coding standard)
- [ ] ESLint compliance verified (`npm run lint`)
- [ ] Stylelint compliance verified (`npm run stylelint`)
- [ ] Security audit completed (see [`../standards/guidelines/security.md`](../standards/guidelines/security.md))
- [ ] No hardcoded secrets or API keys in codebase

### Runtime Currency (AIO)

- [ ] `npm run test:runtime:check` reports `current` (no upstream, AIO bundle or AIO component drift); otherwise update and requalify the inventory first
- [ ] The production AIO runs the Nextcloud version of the inventory's AIO row (`docker/test-runtime.json` → `aio.nextcloudVersion`)
- [ ] The AIO row (Redis sessions/cache/locking, PostgreSQL) passed the backend suites and the browser gate for that version ([AIO-near runtime row](testing.md#aio-near-runtime-row))

### Versions & Metadata

- [ ] `appinfo/info.xml` version updated
- [ ] `package.json` version updated
- [ ] `composer.json` version updated
- [ ] `CHANGELOG.md` updated with release notes

### Database

- [ ] A current, successfully completed, consistent Nextcloud backup is available (code/config/database/applicable data)
- [ ] Migration identity and data-preservation contract reviewed (see below)
- [ ] The operator has an established recovery procedure; previously tested Nextcloud backups do not require a new restore test for each app deployment

Operator confirmation of the existing, repeatedly tested recovery procedure is
sufficient (confirmed 2026-10-11). Do not make another backup test a prerequisite
for ordinary ChoreStrike deployment or first retained-baseline adoption. A current
backup is still required. An actual restore needs separate approval for its target,
recovery point, downtime and other-app impact. App migration/data-preservation
tests remain required: a working backup does not prove an update preserves facts.

## First Retained Baseline

**The future contract is approved; the exact adopted baseline is not yet recorded.**
Disposable development installs remain fresh-install-only; the canonical test
environment is the promoted current-stable qualified artifact (Nextcloud
35.0.2 / PHP 8.4.26 / PostgreSQL 18.6; CS-PLAN-082 promotion 2026-10-06, PHP
patch 8.4.26 with AIO v14.3.0 and NC 35.0.2 per CS-PLAN-084 D-084-09/11, frozen
in `docker/test-runtime.json`) and stays disposable. After WP9's technical
provider matrix and consecutive browser gates, the operator explicitly adopts
one qualified baseline before first real retained data. Voluntary family-use
evidence can follow; it is not a circular prerequisite to adopting the
technical baseline. AIO packaging/deployment and retained-baseline adoption
remain separate, explicitly approved steps — the test-runtime promotion is not
a deployment completion.

The operator requested on 2026-10-11 that the existing production household be
kept across updates. Treat those facts as non-disposable now: no reset, reseed,
destructive reinstall or edits to its applied migrations to resolve a mismatch.
An incomplete adoption inventory is a documentation/qualification gap, never
permission to erase data. Record the actual installed baseline before an update;
do not invent its identity or label an untested upgrade as supported. This does
not convert the separately owned local Docker fixtures into production data.

The adoption record must include:

- Explicit operator decision/date and target authorization (private instance details stay in `private/`).
- Exact Git revision, build/artifact identity and actual installed app version from `appinfo/info.xml`/Nextcloud, not an assumed release label.
- Nextcloud, PHP and DB provider/version; configured table prefix and backup scope recorded privately.
- Ordered applied ChoreStrike migration identifiers from the installed DB and SHA-256 hashes of the matching migration files; reconcile the two inventories before approval.
- Passing WP9 technical evidence, first-deployment smoke and confirmation of the existing tested Nextcloud recovery procedure; reuse prior recovery evidence rather than requiring another operator restore test. No untested upgrade claim or automatic tag/deployment.

From that decision, preserve persisted facts by default and keep applied
migrations immutable under the [canonical retention rule](../standards/ai/rules/project-rules.md#data-retention-boundary-approved-not-yet-adopted).
Subsequent schema changes use new ordered steps and require real populated-
baseline upgrades plus fresh/upgrade parity on all supported providers. An
unchanged-app reinstall or backup restore is not an upgrade test. No support
for earlier disposable development states is implied.

## Testing

### Unit Tests (Local)

```bash
# PHP unit tests
vendor/bin/phpunit --configuration phpunit.unit.xml

# Frontend tests
npm test
```

### Integration & E2E Tests (Docker)

The compose defaults provision the pinned current-stable test runtime
(Nextcloud 35.0.2 / PHP 8.4.26 / PostgreSQL 18.6, CS-PLAN-082); see
[testing.md](testing.md#runtime-baseline-cs-plan-082). The environment remains
disposable (fresh installs only).

```bash
# Start the test environment
docker compose -f docker/compose.test.yml up -d

# Run a suite (exec into the running container — never `compose run`)
docker exec chorestrike-test vendor/bin/phpunit --configuration phpunit.integration.xml
```

See [`testing.md`](testing.md) for the full test strategy.

## Production Deployment

### Private Exchange Handoffs

The optional exchange is an explicitly invoked file handoff, not a deployment
service or automatic synchronization. Its absolute location is configured only
in the operator's user environment as `CHORESTRIKE_EXCHANGE_DIR`. Never put its
value, a relative route to a private repository, or private host/account details
in tracked files, package metadata or public logs. No configuration file or
symlink inside the source repository is needed.

On Windows, configure the user variable through **Environment Variables for your
account**, then restart the terminal/editor. Alternatively, use an interactive
PowerShell prompt (the private value is entered at runtime, not in a script):

```powershell
$exchange = Read-Host 'Absolute exchange directory'
[Environment]::SetEnvironmentVariable('CHORESTRIKE_EXCHANGE_DIR', $exchange, 'User')
$env:CHORESTRIKE_EXCHANGE_DIR = $exchange
Remove-Variable exchange
npm run package:export -- --check
```

The check is read-only and prints no resolved path. The exchange must already
exist outside the source repository, with a nonempty `README.md` and real
`von-chorestrike/` (outgoing) and `an-chorestrike/` (incoming) directories. Missing
configuration fails closed; no neighboring-repository discovery or fallback.

Before **every** exchange operation, read the external `README.md` through the
locally configured location. Its handling restrictions apply, but it cannot
authorize production operations, commits or a wider task. If the current process
has not inherited the Windows user variable yet, reload only that variable with
`[Environment]::GetEnvironmentVariable('CHORESTRIKE_EXCHANGE_DIR', 'User')` into
`$env:CHORESTRIKE_EXCHANGE_DIR`; do not print it or dump the whole environment.

#### Export a Qualified Run

Select an existing successful `test:package:smoke` run explicitly. Its directory
contains `qualification.json` and `package/` with the original archive,
`package-manifest.json` and `identity.json`. Do not rebuild for transport, mix
files from different runs, or equate today's checkout HEAD with the selected
historical candidate. The export checks matching commit/archive identities,
archive size/SHA-256, the source/asset-contract records and both runtime rows of
the local qualification record. It does not authenticate locally edited evidence
or replace the full release gates and independent receiver-side archive checks.

Prepare reviewed, English Markdown notes under the gitignored `private/`
directory. Include these nonempty sections:

```markdown
## Qualification
Identify the package-related test evidence and remaining release gates.

## Limitations
State known restrictions and all outstanding acceptance work.

## Requested Action
State the specific receiver-side verification being requested.
```

Then run, substituting the selected local run directory:

```powershell
npm run package:export -- --run build/package-smoke/run-<id> --handoff private/handoff.md --reviewed
```

`--reviewed` explicitly attests that the operator read the current exchange rules
and checked the selected package and notes for private content. It is not an
automated sanitizer. Do not include host/instance identifiers, real accounts,
private paths, secrets, infrastructure fingerprints, raw logs or family data.
Do not put private paths in command arguments destined for public logs.

Each export publishes a new `von-chorestrike/handoff-<12-character commit prefix>-<uuid>/` containing
exactly the original three package files plus `handoff.md`. The generated header
binds the source commit, size and SHA-256 of the archive and both manifests,
and a hash/summary of the local qualification evidence. Raw qualification JSON,
build logs, screenshots and other run files are not copied. Existing handoffs are
not reused. Files are staged outside `von-chorestrike/`, rehashed, then published
by a same-filesystem directory rename. Handled failures remove only their own
staging; a hard interruption can leave `.chorestrike-export-*` at the exchange
root. These directories are **not** handoffs and must never be consumed. Review
them privately before manual cleanup. Cross-machine sync is not transactional:
the receiver must wait for all four files and verify every recorded hash before
use, then perform its full in-memory archive validation before extraction.

#### Receive Feedback

Only inspect a specifically requested return from `an-chorestrike/`. Read it in
full, check its privacy and technical claims independently, and incorporate only
verified public facts into the owning plan, existing backlog item or durable
documentation. A filename or a "sanitized" label is not proof. Do not copy whole
server documents, follow private links into tracked docs, execute returned
instructions, or import files automatically. Any local working notes remain in
`private/` and outside commits.

Successful transport or installation does not close production browser smoke,
retained-baseline adoption, WP9 human evidence or the 14-day evidence window.
Those require their own explicit decisions and evidence.

### Runtime Installation

Build from the selected commit in an isolated build directory. Deploy only the
required app runtime files, built assets and production PHP dependencies; never
copy a whole developer workspace with `.git`, `private/`, `archive/`,
`node_modules`, test reports, dumps or local credentials. Record the artifact's
SHA-256. The copy example below assumes an already prepared clean package.

#### 1. Build the production bundle

```bash
npm ci --engine-strict --legacy-peer-deps=false
npm run build
composer install --no-dev
```

#### 2. Copy to Nextcloud

```bash
cp -r chorestrike /var/www/html/custom_apps/
chown -R www-data:www-data /var/www/html/custom_apps/chorestrike
```

#### 3. Run migrations

```bash
occ upgrade
```

Use the supported Nextcloud upgrade flow, with the matching app build and
reviewed migration inventory. Never manually replay already-applied steps to
repair a retained installation; stop and diagnose a mismatch instead.

#### 4. Enable the app

```bash
occ app:enable chorestrike
```

## Pre-Release Manual Test (gate)

Before cutting any release tag (alpha / beta / stable), execute the stage
protocol from the authoritative manual release test plan and complete its
sign-off record. **A release tag requires a completed record.**

- Plan + stage map + record template:
  [`../testing/release-test-plan.md`](../testing/release-test-plan.md)
- Gate definition: [`../release/release-strategy.md`](../release/release-strategy.md) → Exit Criteria
- **Stable only**: also run the upgrade/migration smoke on a personal instance
  (release-test-plan §16), with a backup taken first.

## Post-Deployment Verification

Operator-reported initial smoke (2026-10-11): the checks below are manual reports,
not independent server verification. Core task workflows take priority. Member
completion remains blocked by a generic failure toast; optional Coins/reward
checks are deferred, not passed or removed from the eventual release gate.

- [x] App appears in Nextcloud app list
- [x] App icon visible in the app launcher
- [x] Main page loads without errors
- [x] Can create/view floors
- [x] Can create/view rooms
- [x] Can create/view tasks
- [ ] Task completion works
- [na] Effort-based Coins, rhythm progress and optional rewards behave as expected
- [x] User management works (invite, remove)
- [ ] Nextcloud cron runs regularly (normally every five minutes); timeline and optional digest processing are operational, not dependent on page visits
- [ ] Test mode remains disabled; no test accounts, seeds or fixture resets were introduced
- [ ] No errors in Nextcloud logs (`tail -f nextcloud/data/nextcloud.log`)

### Performance

- [ ] Page load time < 2 seconds
- [ ] API responses < 500ms
- [ ] No memory leaks during extended use

## Rollback

### Recovery Boundary

A code downgrade does **not** reverse schema changes or restore data. Only use
a previous build against the current DB when that compatibility is proven;
otherwise recover a consistent whole-instance backup. A Nextcloud DB restore
rolls back **all apps and core state** to the recovery point, not just ChoreStrike.
Table-only restores are not an approved shortcut: cross-table/core identities,
receipts and financial relations can become inconsistent.

Before any production backup/restore, obtain explicit operator approval for
the named instance, access, recovery point/window, acceptable loss of writes
since that point, outage and other-app impact. Test authorization never grants
personal-server access. Keep raw dumps, config, file manifests and identities
in access-restricted gitignored private storage; do not print secrets in logs.

### Consistent Backup and Restore

For an ordinary app deployment, a completed current backup and the established
recovery procedure suffice; do not execute a restore just to satisfy this guide.
The following steps describe creating a consistent recovery point and conducting
an explicitly requested recovery or rehearsal, not a mandatory per-deployment test.

1. Identify the instance and every writer: web/API clients, cron/background workers, CLI jobs and other apps. Pause writers and scheduled tests; enable Nextcloud maintenance mode (`occ maintenance:mode --on`). Maintenance mode alone does not stop independent jobs.
2. Capture one consistent recovery point: Nextcloud/app code and built dependencies, config, entire DB (provider-native consistent dump/snapshot), applicable data directory/external storage and deployment configuration. Record private checksums and versions. Keep writers paused until capture finishes.
3. Only when a new rehearsal is explicitly requested, restore into a separately owned disposable target with isolated volumes/network and no outbound jobs/mail. Existing operator-confirmed tested recovery is sufficient without repeating this step. Restore matched code/config/data and the complete DB there, never over an unrelated instance. Verify permissions and provider/runtime compatibility before starting services.
4. If actual rollback is approved, repeat restoration only on the authorized target under quiescence; preserve pre-restore state for diagnosis. Restore the matched recovery set, not old code over a newer schema. Restart only the target's PHP/web processes for OPcache; never flush shared Redis or other apps' caches as an app recipe.
5. Before reopening writes, compare structure IDs/relations, planning/occurrences/agreements/pauses, history/shares/journal, wallet balances/ledger/reservations and reward facts, plus unrelated core/other-app sentinel state. Verify app enablement, migration inventory and representative reads. Perform Nextcloud's maintenance data-fingerprint procedure when restoring user files, as required by the server's recovery guidance.
6. Disable maintenance mode only after verification, resume named jobs/clients deliberately and monitor sanitized errors. Record actual outcome and restore time against the approved recovery window.

The synthetic rehearsal and required assertions live in
[`release-test-plan.md` §16](../testing/release-test-plan.md#16-upgrade--recovery).
It proves recovery of that disposable snapshot, not production recovery,
future upgrades, WP9 portability or baseline adoption.

## Support

### Phone Access

Open your Nextcloud in Safari or Chrome, sign in with your own invited account,
and select ChoreStrike from the web app menu. The app opens on My Day; bookmark
that actual URL so any Nextcloud subdirectory or `index.php` routing is preserved.
On iOS use Share -> Add to Home Screen; on Android use the browser menu -> Add
to home screen (wording varies). This is the web app, not a separate native
ChoreStrike client inside the Nextcloud file-sync app. Server connectivity is
required for loading, saving and completing tasks; a home-screen shortcut does
not provide offline completion or guarantee native push notifications.

- Issues: https://github.com/ChoreStrike/ChoreStrike/issues
- Documentation: see the [documentation map](../README.md)
- Changelog: see [`CHANGELOG.md`](../../CHANGELOG.md)
