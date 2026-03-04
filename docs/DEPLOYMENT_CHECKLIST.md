# Deployment Checklist

> **Version**: 1.0.0
> **Last Updated**: 2026-03-05

---

## Pre-Deployment Checklist

### Code Quality

- [x] All unit tests pass (`vendor/bin/phpunit --configuration phpunit.xml tests/unit`)
- [x] All frontend tests pass (`npm test`)
- [x] PSR-12 compliance verified (`vendor/bin/php-cs-fixer fix --dry-run`)
- [x] ESLint compliance verified (`npx eslint --ext .js,.vue src`)
- [x] Security audit completed (P8-SECURITY-001)
- [x] No hardcoded secrets or API keys in codebase

### Database

- [ ] Database backup created
- [ ] Migration files reviewed for breaking changes
- [ ] Rollback migration prepared (if needed)

### Configuration

- [ ] `info.xml` version updated
- [ ] `package.json` version updated
- [ ] `composer.json` version updated
- [ ] CHANGELOG.md updated with release notes

---

## Deployment Steps

### 1. Backup

```bash
# Backup Nextcloud database
mysqldump -u nextcloud_user -p nextcloud_db > backup_$(date +%Y%m%d).sql

# Backup Nextcloud data directory
tar -czf nextcloud_data_$(date +%Y%m%d).tar.gz /var/www/nextcloud/data/

# Backup current app version
cp -r /var/www/nextcloud/apps/chorestrike /backup/chorestrike_$(date +%Y%m%d)
```

### 2. Deploy

```bash
# Navigate to Nextcloud apps directory
cd /var/www/nextcloud/apps/

# Remove old version (if present)
rm -rf chorestrike

# Extract new version
unzip chorestrike-v1.0.0.zip -d chorestrike

# Set permissions
chown -R www-data:www-data chorestrike
chmod -R 755 chorestrike
```

### 3. Post-Deployment

```bash
# Run database migrations
cd /var/www/nextcloud
sudo -u www-data php occ migration:migrate chorestrike

# Clear cache
sudo -u www-data php occ cache:clear

# Verify app is enabled
sudo -u www-data php occ app:list | grep chorestrike
```

---

## Rollback Procedure

### Quick Rollback

```bash
# Disable app
sudo -u www-data php occ app:disable chorestrike

# Restore previous version
rm -rf /var/www/nextcloud/apps/chorestrike
cp -r /backup/chorestrike_YYYYMMDD /var/www/nextcloud/apps/chorestrike
chown -R www-data:www-data /var/www/nextcloud/apps/chorestrike

# Restore database (if needed)
mysql -u nextcloud_user -p nextcloud_db < backup_YYYYMMDD.sql

# Re-enable app
sudo -u www-data php occ app:enable chorestrike
```

### Full Rollback

1. Disable ChoreStrike app
2. Restore database from backup
3. Restore previous app version
4. Clear Nextcloud cache
5. Re-enable app
6. Verify functionality

---

## Verification Checklist

### Post-Deployment Verification

- [ ] App appears in Nextcloud app list
- [ ] App icon visible in app launcher
- [ ] Main page loads without errors
- [ ] Can create/view floors
- [ ] Can create/view rooms
- [ ] Can create/view tasks
- [ ] Task completion works
- [ ] Gamification features work (sparks, combos)
- [ ] User management works (invite, remove)
- [ ] No errors in Nextcloud logs (`tail -f /var/www/nextcloud/data/nextcloud.log`)

### Performance Verification

- [ ] Page load time < 2 seconds
- [ ] API responses < 500ms
- [ ] No memory leaks during extended use

---

## Emergency Contacts

| Role | Contact |
|------|---------|
| Developer | [Your contact] |
| Server Admin | [Admin contact] |

---

## Known Issues & Workarounds

Document any known issues discovered during testing:

| Issue | Workaround | Status |
|-------|------------|--------|
| (none yet) | - | - |

---

**Note**: Keep this checklist updated as deployment procedures evolve.
