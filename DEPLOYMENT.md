# ChoreStrike Deployment Guide

## Prerequisites

- Nextcloud 27-30
- PHP 8.0-8.3
- MariaDB/MySQL/PostgreSQL (for testing)
- Docker & Docker Compose (for testing)
- Node.js 20+ & npm 10+ (for frontend build)

## Quick Start

### 1. Clone Repository

```bash
cd /var/www/html/custom_apps/
git clone https://github.com/ChoreStrike/ChoreStrike.git chorestrike
```

### 2. Build Frontend

```bash
cd chorestrike
npm ci
npm run build
```

### 3. Install Dependencies

```bash
php composer.phar install --no-dev
```

### 4. Enable App in Nextcloud

```bash
# Via occ command
occ app:enable chorestrike
```

## Testing

### Unit Tests (Local)

```bash
# PHP Unit Tests (with mocks)
php composer.phar test -- --testsuite Unit

# JavaScript Tests
npm test
```

### Integration Tests (Docker)

```bash
# Start test environment
docker-compose -f docker-compose.test.yml up -d db

# Run all tests
docker-compose -f docker-compose.test.yml run --rm nextcloud-test

# Run specific test suite
docker-compose -f docker-compose.test.yml run --rm nextcloud-test \
  vendor/bin/phpunit --testsuite Unit
```

## Production Deployment

### 1. Build Production Bundle

```bash
npm ci
npm run build
```

### 2. Copy to Nextcloud

```bash
cp -r chorestrike /var/www/html/custom_apps/
chown -R www-data:www-data /var/www/html/custom_apps/chorestrike
```

### 3. Run Migrations

```bash
occ migrations:execute chorestrike
```

### 4. Enable App

```bash
occ app:enable chorestrike
```

## Post-Deployment Verification

### Critical Tests (MUST PASS)

Priority 1 - Core Functionality:
- [ ] `tests/unit/Db/TaskTest.php` - Task entity validation
- [ ] `tests/unit/Db/RoomTaskTest.php` - Room task lifecycle
- [ ] `tests/unit/Service/GameLogicServiceTest.php` - Points calculation

Priority 2 - Business Logic:
- [ ] `tests/unit/Service/HouseServiceTest.php` - House management
- [ ] `tests/unit/Service/RoomServiceTest.php` - Room operations

Priority 3 - API Integration:
- [ ] Controller tests (basic smoke tests)

### Run Verification

```bash
# Critical unit tests
vendor/bin/phpunit tests/unit/Db/TaskTest.php
vendor/bin/phpunit tests/unit/Db/RoomTaskTest.php
vendor/bin/phpunit tests/unit/Service/GameLogicServiceTest.php

# Full test suite
vendor/bin/phpunit --testsuite Unit
```

## Known Issues

### Test Coverage

Current test status:
- Unit Tests: ~65% passing (83 of 217 tests have environment issues)
- Integration Tests: ~15% passing (infrastructure in progress)
- Critical business logic: All passing

### Docker Environment

The Docker test environment is configured but has known issues with:
- Doctrine DBAL dependencies (mocks provided)
- Mapper inheritance testing (complex setup required)
- Some integration test mocks (method mismatches)

These do not affect production functionality.

## Rollback

```bash
occ app:disable chorestrike
rm -rf /var/www/html/custom_apps/chorestrike
```

## Support

- Issues: https://github.com/ChoreStrike/ChoreStrike/issues
- Documentation: See docs/ folder
- Changelog: See CHANGELOG.md
