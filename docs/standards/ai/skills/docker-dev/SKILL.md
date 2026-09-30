---
name: docker-dev
description: Docker test environment setup, container workflow, and debugging for ChoreStrike
---

# Skill: Docker Development Environment

**Load when:** Setting up, running, or debugging the Docker-based test environment; working with `docker/compose.test.yml`; or troubleshooting container issues.

**Canonical references:**
- [`testing.md`](../../../../engineering/testing.md) — full environment setup guide

---

## Architecture

```
docker/compose.test.yml
├── db (MariaDB 10.6)        — container: chorestrike-db
└── nextcloud-test (custom)  — container: chorestrike-test
    ├── Dockerfile: docker/Dockerfile.test
    ├── Entrypoint: docker/test-entrypoint.sh
    ├── Seed script: docker/seed-test-data.sh
    └── App mounted at: /var/www/html/custom_apps/chorestrike
```

The app source is **bind-mounted** into the container — code changes on the host are immediately visible inside.

## Availability

Docker Desktop **is installed** on the development machine and the daemon is expected to be running. If a `docker` command fails:

1. Check the daemon with `docker info`. If unreachable, start Docker Desktop (`& "C:\Program Files\Docker\Docker\Docker Desktop.exe"`) and wait until `docker info` succeeds.
2. First builds take ~3–5 minutes — set the shell timeout to ≥ 300 s or run as a background process. A tool timeout is not a Docker failure.
3. Never conclude "Docker is not available" from a single failed probe, and never switch to the "Without Docker" fallback path (`docs/engineering/testing.md`) on this machine.

## Essential Commands

```bash
# Start environment (background)
docker compose -f docker/compose.test.yml up -d

# Stop environment
docker compose -f docker/compose.test.yml down

# Full rebuild (after Dockerfile/composer changes)
docker compose -f docker/compose.test.yml build --no-cache

# Unit tests only
docker exec chorestrike-test vendor/bin/phpunit --configuration phpunit.unit.xml

# Integration tests only (real Nextcloud + DB bootstrap)
docker exec chorestrike-test vendor/bin/phpunit --configuration phpunit.integration.xml

# Single test file
docker exec chorestrike-test vendor/bin/phpunit tests/unit/Service/TaskServiceTest.php

# Single test method
docker exec chorestrike-test vendor/bin/phpunit --filter testCreate_ValidData_ReturnsCreatedTask

# Interactive shell in container
docker exec -it chorestrike-test sh

# Check container health
docker inspect --format='{{.State.Health.Status}}' chorestrike-test
```

> **Always `exec` into the running container — never `docker compose run`.**
> Nextcloud is installed by the entrypoint of the long-running
> `chorestrike-test` container (the image only clones the server source);
> `run --rm` creates a fresh container whose command override skips the
> entrypoint, so PHPUnit dies in the bootstrap with `Exception: Not installed`.

## DB Profiles

Set via `TEST_DB_PROFILE` environment variable (default: `minimal`):

| Profile | Use case |
|---------|----------|
| `empty` | Schema only, no seed data |
| `minimal` | One house, one user, basic rooms |
| `family` | Multi-user household with gamification data. Seeds a deterministic CLOSED weekly-goal week (realistic on-time completions crossing the fixed 80% target, `seedClosedWeeklyGoal`) — the closed-week receipt is now only an in-app celebration marker (WP5 digest cutover, CS-PLAN-076/ADR-016); the reward-config appconfig override of the CS-PLAN-055 era was retired with the WP4 fixed-target cutover (CS-PLAN-075). **E2E note (WP5):** the profile users' fixed passwords are provisioned by the container ENTRYPOINT at creation time — recreate the container with `TEST_DB_PROFILE='family'` (PowerShell: `$env:TEST_DB_PROFILE='family'; docker compose -f docker/compose.test.yml up -d`), not just a re-seed, or the profile logins fail. |

```powershell
# PowerShell 5.1 (this repo's shell) — bash VAR=value syntax does NOT work here:
$env:TEST_DB_PROFILE='family'; docker compose -f docker/compose.test.yml up -d
```

## Interactive Browser Automation

If the active agent environment provides a Docker-backed browser, it may be used
for interactive E2E verification against the running test environment on port
8080. Do not make project standards depend on a machine-specific MCP name or
configuration path.

## Troubleshooting

| Symptom | Fix |
|---------|-----|
| `docker` command fails / daemon unreachable | Start Docker Desktop, wait until `docker info` succeeds — do not conclude Docker is absent |
| First build or test run hits a tool timeout | Expected (~3–5 min): raise shell timeout ≥ 300 s or run as background process, then retry |
| `chorestrike-db` unhealthy | `docker compose -f docker/compose.test.yml down -v` then restart |
| Tests fail with "connection refused" | Wait for DB healthcheck: `docker inspect --format='{{.State.Health.Status}}' chorestrike-db` |
| Stale vendor/ in container | Rebuild: `docker compose -f docker/compose.test.yml build --no-cache` |
| Port 8080 conflict | Change port mapping in `docker/compose.test.yml` or stop conflicting service |
| Permission errors on mounted files | Ensure Docker Desktop file sharing includes the project directory |

## Rules

- Never modify the container filesystem directly — all changes go through the bind mount.
- Keep `docker/Dockerfile.test` and `docker/test-entrypoint.sh` deterministic and idempotent.
- The test environment is disposable: `down -v` + `up -d` must always produce a clean state.
