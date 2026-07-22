---
name: docker-dev
description: Docker test environment setup, container workflow, and debugging for ChoreStrike
---

# Skill: Docker Development Environment

**Load when:** Setting up, running, or debugging the Docker-based test environment; working with `docker-compose.test.yml`; or troubleshooting container issues.

**Canonical references:**
- [`../../TESTING.md`](../../../../TESTING.md) — full environment setup guide
- [`testing`](../testing/SKILL.md) — test writing conventions & coverage rules

---

## Architecture

```
docker-compose.test.yml
├── db (MariaDB 10.6)        — container: chorestrike-db
└── nextcloud-test (custom)  — container: chorestrike-test
    ├── Dockerfile: docker/Dockerfile.test
    ├── Entrypoint: docker/test-entrypoint.sh
    ├── Seed script: docker/seed-test-data.sh
    └── App mounted at: /var/www/html/custom_apps/chorestrike
```

The app source is **bind-mounted** into the container — code changes on the host are immediately visible inside.

## Essential Commands

```bash
# Start environment (background)
docker compose -f docker-compose.test.yml up -d

# Stop environment
docker compose -f docker-compose.test.yml down

# Full rebuild (after Dockerfile/composer changes)
docker compose -f docker-compose.test.yml build --no-cache

# Run all PHPUnit tests
docker compose -f docker-compose.test.yml run --rm nextcloud-test

# Unit tests only
docker compose -f docker-compose.test.yml run --rm nextcloud-test \
  vendor/bin/phpunit --configuration phpunit.unit.xml

# Integration tests only
docker compose -f docker-compose.test.yml run --rm nextcloud-test \
  vendor/bin/phpunit --configuration phpunit.xml tests/integration/

# Single test file
docker compose -f docker-compose.test.yml run --rm nextcloud-test \
  vendor/bin/phpunit tests/unit/Service/TaskServiceTest.php

# Single test method
docker compose -f docker-compose.test.yml run --rm nextcloud-test \
  vendor/bin/phpunit --filter testCreate_ValidData_ReturnsCreatedTask

# Interactive shell in container
docker compose -f docker-compose.test.yml run --rm nextcloud-test sh

# Check container health
docker inspect --format='{{.State.Health.Status}}' chorestrike-test
```

## DB Profiles

Set via `TEST_DB_PROFILE` environment variable (default: `minimal`):

| Profile | Use case |
|---------|----------|
| `empty` | Schema only, no seed data |
| `minimal` | One house, one user, basic rooms |
| `family` | Multi-user household with gamification data |

```bash
TEST_DB_PROFILE=family docker compose -f docker-compose.test.yml up -d
```

## Docker MCP Gateway

The Kilo project config (`.kilo/kilo.jsonc`) connects to the Docker MCP gateway which provides a containerized Playwright browser. Use `MCP_DOCKER_browser_*` tools for interactive E2E verification against the running test environment (port 8080).

## Troubleshooting

| Symptom | Fix |
|---------|-----|
| `chorestrike-db` unhealthy | `docker compose -f docker-compose.test.yml down -v` then restart |
| Tests fail with "connection refused" | Wait for DB healthcheck: `docker inspect --format='{{.State.Health.Status}}' chorestrike-db` |
| Stale vendor/ in container | Rebuild: `docker compose -f docker-compose.test.yml build --no-cache` |
| Port 8080 conflict | Change port mapping in `docker-compose.test.yml` or stop conflicting service |
| Permission errors on mounted files | Ensure Docker Desktop file sharing includes the project directory |

## Rules

- Never modify the container filesystem directly — all changes go through the bind mount.
- Keep `docker/Dockerfile.test` and `docker/test-entrypoint.sh` deterministic and idempotent.
- The test environment is disposable: `down -v` + `up -d` must always produce a clean state.
