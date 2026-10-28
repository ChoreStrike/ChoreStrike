# Docker Test Environment

Canonical recipe for running the ChoreStrike integration/E2E test stack locally.

## Prerequisites

- Docker Desktop (or Docker Engine + Docker Compose v2)
- Port `8080` (Nextcloud, loopback) available; the database is internal-only

## Quick Start

```powershell
# From the repository root
docker compose -f docker/compose.test.yml up -d --build
```

Wait ~60 seconds for the entrypoint to finish (Nextcloud install + app activation + user seeding).

## Verify

```powershell
# Nextcloud status
docker exec chorestrike-test curl -s http://localhost:80/status.php

# ChoreStrike enabled
docker exec chorestrike-test bash -c "cd /var/www/html && su -s /bin/bash www-data -c 'php occ app:list' | grep chorestrike"
```

Expected: `"installed":true` and `chorestrike: 0.8.0-alpha.3` in the enabled list.

## Run Integration Tests

```powershell
docker compose -f docker/compose.test.yml exec nextcloud-test bash -c "cd /var/www/html/custom_apps/chorestrike && php -d memory_limit=512M vendor/bin/phpunit --configuration phpunit.integration.xml"
```

## Run Unit Tests (inside container)

```powershell
docker compose -f docker/compose.test.yml exec nextcloud-test bash -c "cd /var/www/html/custom_apps/chorestrike && php -d memory_limit=512M vendor/bin/phpunit --configuration phpunit.unit.xml"
```

## Test Users

| Profile | Users |
|---------|-------|
| `minimal` (default) | `admin`/`admin`, `adult`/`Adult123!`, `kid`/`Kid123!` |
| `family` | `admin`/`admin`, `mom`/`Mom123!`, `dad`/`Dad123!`, `teen`/`Teen123!`, `kid`/`Kid123!`, `grandma`/`Grandma123!`, `friend`/`Friend123!` |
| `empty` | `admin`/`admin` only |

Set via `TEST_DB_PROFILE` environment variable before `up`.

## Tear Down

```powershell
docker compose -f docker/compose.test.yml down -v
```

The `-v` flag removes the MariaDB volume for a clean slate on next start.

## Architecture

- **Base image:** frozen runtime built from `docker/Dockerfile.test` — Nextcloud 35.0.2 / PHP 8.4.27, pinned by `docker/test-runtime.json` (CS-PLAN-082)
- **Database:** PostgreSQL 18.6 (service `db`, internal network)
- **App mount:** the repository root is bind-mounted at `/var/www/html/custom_apps/chorestrike`
- **Entrypoint:** `docker/test-entrypoint.sh` handles DB wait → Nextcloud install → **alias-free app discovery** (complete `apps_paths` array, no `/apps/chorestrike` symlink) → app enable → user seeding (CS-PLAN-083 WP1)
- **Package lanes:** `docker/Dockerfile.package` (pinned package builder), `docker/compose.package-smoke.yml` + `scripts/run-package-smoke.js` (one-command package qualification, CS-PLAN-083 WP2/WP3) — see `docs/engineering/testing.md` §Package Identity Gate

## Known Issues & Fixes Applied

- **PK name too long (2026-07):** `Version1021` originally called `setPrimaryKey(['id'])` without an explicit name. Nextcloud's `oc_` prefix made the auto-generated PK name exceed MariaDB's 64-char limit. Fixed by using `'pk_task_assignees'`.
