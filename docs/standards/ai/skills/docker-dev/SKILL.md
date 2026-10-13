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
├── db (PostgreSQL 18.6)     — container: chorestrike-db
└── nextcloud-test (custom)  — container: chorestrike-test
    ├── Dockerfile: docker/Dockerfile.test (pinned to docker/test-runtime.json)
    ├── Entrypoint: docker/test-entrypoint.sh
    ├── Seed script: docker/seed-test-data.sh
    └── App mounted at: /var/www/html/custom_apps/chorestrike
```

The app source is **bind-mounted** into the container — code changes on the host are immediately visible inside. The canonical runtime is the promoted CS-PLAN-082 baseline: Nextcloud 35.0.1 / PHP 8.4.25 / PostgreSQL 18.6 (was NC31 / PHP 8.2 / MariaDB 10.6, retired 2026-10-06).

Canonical HTTP is published only on IPv4/IPv6 loopback. Named config, data and
database volumes must travel together across recreation or `down`/`up`.
Use `TEST_RUNTIME_PRESERVATION=true` for a same-build recreate without reseeding;
ordinary disposable test provisioning deliberately seeds its selected profile.
`node scripts/run-tests.js` provisions, verifies and runs both complete backend
suites; it leaves the canonical stack running and never deletes volumes.

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

# Unit tests (services, mappers, entities)
docker compose -f docker/compose.test.yml exec -T nextcloud-test vendor/bin/phpunit --configuration phpunit.unit.xml

# Integration tests (controllers, API; real Nextcloud + DB bootstrap)
docker compose -f docker/compose.test.yml exec -T nextcloud-test vendor/bin/phpunit --configuration phpunit.integration.xml

# Single file / single method (append to the exec prefix above)
... vendor/bin/phpunit tests/unit/Service/TaskServiceTest.php
... vendor/bin/phpunit --configuration phpunit.unit.xml --filter testCreate_ValidData_ReturnsCreatedTask

# Provision + verify + run both complete backend suites (leaves the stack running)
node scripts/run-tests.js

# Interactive shell in container
docker exec -it chorestrike-test sh

# Check container health
docker inspect --format='{{.State.Health.Status}}' chorestrike-test

# Runtime inventory checks (read-only; frozen inventory in docker/test-runtime.json)
npm run test:runtime:validate    # offline inventory validation
npm run test:runtime:check       # upstream + AIO bundle/component drift, exit 2 = update needed
npm run test:runtime:canonical   # asserts the running canonical stack matches the inventory
```

> **Always `exec` into the running container — never `docker compose run`.**
> Nextcloud is installed by the entrypoint of the long-running
> `chorestrike-test` container (the image only clones the server source);
> `run --rm` creates a fresh container whose command override skips the
> entrypoint, so PHPUnit dies in the bootstrap with `Exception: Not installed`.

## DB Profiles

For isolated MySQL/MariaDB/PostgreSQL/SQLite fresh-install verification, use the
standalone `docker/compose.test-matrix.yml` companion and the
[matrix procedure](../../../../engineering/testing.md#isolated-supported-database-matrix).
All three providers (mysql/pgsql/sqlite) are kept; the default DB images mirror
the frozen inventory (`mariadb:11.8.6`, `postgres:18.6-alpine`), and explicit
`WP9_*` env overrides still take precedence.
Never merge it with the canonical compose file or reuse another run's volumes.
Start providers sequentially (shared Composer mount); run full suites with
`--fail-on-skipped` and keep each database exclusive to its current test job.

Set via `TEST_DB_PROFILE` environment variable (default: `minimal`):

| Profile | Use case |
|---------|----------|
| `empty` | Schema only, no seed data |
| `emptyhouse` | Clean ChoreStrike tables, reusing the seeded family Nextcloud accounts (canonical journey) |
| `minimal` | One house, one user, basic rooms |
| `family` | Multi-user household with gamification data. Seeds a deterministic CLOSED weekly-goal week (realistic on-time completions crossing the fixed 80% target, `seedClosedWeeklyGoal`) — the closed-week receipt is now only an in-app celebration marker (WP5 digest cutover, CS-PLAN-076/ADR-016); the reward-config appconfig override of the CS-PLAN-055 era was retired with the WP4 fixed-target cutover (CS-PLAN-075). **E2E note (WP5):** the profile users' fixed passwords are provisioned by the container ENTRYPOINT at creation time — recreate the container with `TEST_DB_PROFILE='family'` (PowerShell: `$env:TEST_DB_PROFILE='family'; docker compose -f docker/compose.test.yml up -d`), not just a re-seed, or the profile logins fail. |

```powershell
# PowerShell 5.1 — bash VAR=value syntax does NOT work here (in Git Bash it does):
$env:TEST_DB_PROFILE='family'; docker compose -f docker/compose.test.yml up -d
```

## AIO-Near Row (Redis)

The canonical stack and the primary row are APCu-only. The AIO row's staged
project (`test-runtime-stage.js ... aio`) adds a pinned Redis companion and runs
sessions, distributed cache and file locking on Redis like the production AIO.
Run it before every deployment: [AIO-near runtime row](../../../../engineering/testing.md#aio-near-runtime-row).

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
| `chorestrike-db` unhealthy | Inspect logs/health first; recreate volumes only with explicit ownership of that disposable environment, never as an unqualified shared-environment recovery step |
| Tests fail with "connection refused" | Wait for DB healthcheck: `docker inspect --format='{{.State.Health.Status}}' chorestrike-db` |
| Stale vendor/ in container | Rebuild: `docker compose -f docker/compose.test.yml build --no-cache` |
| Port 8080 conflict | Change port mapping in `docker/compose.test.yml` or stop conflicting service |
| Permission errors on mounted files | Ensure Docker Desktop file sharing includes the project directory |

## Rules

- Never modify the container filesystem directly — all changes go through the bind mount.
- Keep `docker/Dockerfile.test` and `docker/test-entrypoint.sh` deterministic and idempotent.
- Only an explicitly owned disposable project's volumes may be removed. Its qualified `down -v` + `up -d` must produce a clean state; other sessions' and canonical environments stay untouched.
