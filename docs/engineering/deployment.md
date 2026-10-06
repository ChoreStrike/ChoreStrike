# Deployment Guide

> Single source of truth for installing, deploying, and rolling back ChoreStrike.
> Consolidates the former root `DEPLOYMENT.md` and `docs/DEPLOYMENT_CHECKLIST.md`.

## Prerequisites

- Nextcloud 34–35
- PHP 8.4
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
loads its own translation bundle via `@nextcloud/l10n`'s `loadTranslations()` at
startup (`src/i18n.js`), so no server-side l10n injection is required for the UI
to honor the user's language.

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

### Versions & Metadata

- [ ] `appinfo/info.xml` version updated
- [ ] `package.json` version updated
- [ ] `composer.json` version updated
- [ ] `CHANGELOG.md` updated with release notes

### Database

- [ ] Consistent code/config/database/applicable-data backup verified under write quiescence
- [ ] Migration identity and data-preservation contract reviewed (see below)
- [ ] Recovery target, window, acceptable data loss and other-app impact approved by the operator
- [ ] Restore rehearsal passes; code downgrade alone is not the recovery plan

## First Retained Baseline

**The future contract is approved; no baseline has been adopted yet.**
Disposable development installs remain fresh-install-only; the canonical test
environment is the promoted current-stable qualified artifact (Nextcloud
35.0.1 / PHP 8.4.25 / PostgreSQL 18.6; CS-PLAN-082 promotion 2026-10-06, frozen
in `docker/test-runtime.json`) and stays disposable. After WP9's technical
provider matrix and consecutive browser gates, the operator explicitly adopts
one qualified baseline before first real retained data. Voluntary family-use
evidence can follow; it is not a circular prerequisite to adopting the
technical baseline. AIO packaging/deployment and retained-baseline adoption
remain separate, explicitly approved steps — the test-runtime promotion is not
a deployment completion.

The adoption record must include:

- Explicit operator decision/date and target authorization (private instance details stay in `private/`).
- Exact Git revision, build/artifact identity and actual installed app version from `appinfo/info.xml`/Nextcloud, not an assumed release label.
- Nextcloud, PHP and DB provider/version; configured table prefix and backup scope recorded privately.
- Ordered applied ChoreStrike migration identifiers from the installed DB and SHA-256 hashes of the matching migration files; reconcile the two inventories before approval.
- Passing WP9 technical evidence, recovery rehearsal and first-deployment smoke; no untested upgrade claim or automatic tag/deployment.

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
(Nextcloud 35.0.1 / PHP 8.4.25 / PostgreSQL 18.6, CS-PLAN-082); see
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

### 1. Build the production bundle

```bash
npm ci
npm run build
composer install --no-dev
```

### 2. Copy to Nextcloud

```bash
cp -r chorestrike /var/www/html/custom_apps/
chown -R www-data:www-data /var/www/html/custom_apps/chorestrike
```

### 3. Run migrations

```bash
occ upgrade
```

Use the supported Nextcloud upgrade flow, with the matching app build and
reviewed migration inventory. Never manually replay already-applied steps to
repair a retained installation; stop and diagnose a mismatch instead.

### 4. Enable the app

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

- [ ] App appears in Nextcloud app list
- [ ] App icon visible in the app launcher
- [ ] Main page loads without errors
- [ ] Can create/view floors
- [ ] Can create/view rooms
- [ ] Can create/view tasks
- [ ] Task completion works
- [ ] Gamification features work (coins, combos)
- [ ] User management works (invite, remove)
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

1. Identify the instance and every writer: web/API clients, cron/background workers, CLI jobs and other apps. Pause writers and scheduled tests; enable Nextcloud maintenance mode (`occ maintenance:mode --on`). Maintenance mode alone does not stop independent jobs.
2. Capture one consistent recovery point: Nextcloud/app code and built dependencies, config, entire DB (provider-native consistent dump/snapshot), applicable data directory/external storage and deployment configuration. Record private checksums and versions. Keep writers paused until capture finishes.
3. Rehearse first into a separately owned disposable target with isolated volumes/network and no outbound jobs/mail. Restore matched code/config/data and the complete DB there, never over an unrelated instance. Verify permissions and provider/runtime compatibility before starting services.
4. If actual rollback is approved, repeat restoration only on the authorized target under quiescence; preserve pre-restore state for diagnosis. Restore the matched recovery set, not old code over a newer schema. Restart only the target's PHP/web processes for OPcache; never flush shared Redis or other apps' caches as an app recipe.
5. Before reopening writes, compare structure IDs/relations, planning/occurrences/agreements/pauses, history/shares/journal, wallet balances/ledger/reservations and reward facts, plus unrelated core/other-app sentinel state. Verify app enablement, migration inventory and representative reads. Perform Nextcloud's maintenance data-fingerprint procedure when restoring user files, as required by the server's recovery guidance.
6. Disable maintenance mode only after verification, resume named jobs/clients deliberately and monitor sanitized errors. Record actual outcome and restore time against the approved recovery window.

The synthetic rehearsal and required assertions live in
[`release-test-plan.md` §16](../testing/release-test-plan.md#16-upgrade--recovery).
It proves recovery of that disposable snapshot, not production recovery,
future upgrades, WP9 portability or baseline adoption.

## Support

- Issues: https://github.com/ChoreStrike/ChoreStrike/issues
- Documentation: see the [`docs/`](.) folder
- Changelog: see [`CHANGELOG.md`](../../CHANGELOG.md)
