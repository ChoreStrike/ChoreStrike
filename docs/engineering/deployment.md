# Deployment Guide

> Single source of truth for installing, deploying, and rolling back ChoreStrike.
> Consolidates the former root `DEPLOYMENT.md` and `docs/DEPLOYMENT_CHECKLIST.md`.

## Prerequisites

- Nextcloud 27–31
- PHP 8.2–8.3
- MariaDB/MySQL/PostgreSQL/SQLite
- Docker & Docker Compose (for the test environment)
- Node.js 20+ & npm 10+ (for the frontend build)

## Installation

### 1. Clone the repository

```bash
cd /var/www/html/custom_apps/
git clone https://github.com/ChoreStrike/ChoreStrike.git chorestrike
cd chorestrike
```

### 2. Build the frontend

```bash
npm ci
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

- [ ] Database backup created
- [ ] Migration files reviewed for breaking changes
- [ ] Rollback migration prepared (if needed)

## Testing

### Unit Tests (Local)

```bash
# PHP unit tests
vendor/bin/phpunit --configuration phpunit.unit.xml

# Frontend tests
npm test
```

### Integration & E2E Tests (Docker)

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
occ migrations:execute chorestrike
```

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

### Quick Rollback

```bash
# Disable the app
occ app:disable chorestrike

# Restore the previous version
rm -rf /var/www/html/custom_apps/chorestrike
cp -r /backup/chorestrike_YYYYMMDD /var/www/html/custom_apps/chorestrike
chown -R www-data:www-data /var/www/html/custom_apps/chorestrike

# Restore the database (if needed)
mysql -u nextcloud_user -p nextcloud_db < backup_YYYYMMDD.sql

# Re-enable the app
occ app:enable chorestrike
```

### Full Rollback

1. Disable the ChoreStrike app
2. Restore the database from backup
3. Restore the previous app version
4. Clear caches — restart the web/PHP-FPM process (clears OPcache) and flush your
   configured cache backend (e.g. `redis-cli flushall` for Redis). Nextcloud has no
   single `occ cache:clear` command.
5. Re-enable the app
6. Verify functionality

## Support

- Issues: https://github.com/ChoreStrike/ChoreStrike/issues
- Documentation: see the [`docs/`](.) folder
- Changelog: see [`CHANGELOG.md`](../../CHANGELOG.md)
