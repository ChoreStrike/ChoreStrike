# Test Environment Setup

This guide explains how to set up a Docker-based test environment for ChoreStrike that includes all Nextcloud dependencies required for PHPUnit tests.

## Prerequisites

### All Platforms (Windows, Linux, macOS)

- **Docker**: Version 20.10 or higher
  - Windows: [Docker Desktop](https://docs.docker.com/desktop/install/windows-install/)
  - Linux: [Docker Engine](https://docs.docker.com/engine/install/)
  - macOS: [Docker Desktop](https://docs.docker.com/desktop/install/mac-install/)

- **Git**: For cloning the repository

## Quick Start

### Run Tests with Docker Compose

```bash
# Build and run all tests
docker compose -f docker-compose.test.yml up --build

# Or with legacy docker-compose:
docker-compose -f docker-compose.test.yml up --build
```

### Using the Test Script (Linux/macOS/WSL)

```bash
# Make the script executable
chmod +x scripts/run-tests.sh

# Run tests
./scripts/run-tests.sh
```

**Note for Windows users**: Use Git Bash, WSL, or PowerShell to run the shell script.

## Manual Setup (Detailed)

### 1. Clone Repository

```bash
git clone https://github.com/ChoreStrike/ChoreStrike.git
cd ChoreStrike
```

### 2. Build Test Environment

```bash
# Build the Docker image (first time takes ~3-4 minutes)
docker compose -f docker-compose.test.yml build --no-cache
```

### 3. Run Tests

```bash
# Run all tests
docker compose -f docker-compose.test.yml run --rm nextcloud-test

# Run only unit tests
docker compose -f docker-compose.test.yml run --rm nextcloud-test \
  sh -c "composer install && vendor/bin/phpunit --testsuite Unit"

# Run only integration tests
docker compose -f docker-compose.test.yml run --rm nextcloud-test \
  sh -c "composer install && vendor/bin/phpunit --testsuite Integration"

# Run specific test file
docker compose -f docker-compose.test.yml run --rm nextcloud-test \
  sh -c "composer install && vendor/bin/phpunit tests/unit/Service/TaskServiceTest.php"

# Run specific test method
docker compose -f docker-compose.test.yml run --rm nextcloud-test \
  sh -c "composer install && vendor/bin/phpunit --filter testCreate_ValidData_ReturnsCreatedTask"
```

### 4. Interactive Development

For interactive debugging or running commands inside the container:

```bash
# Start a shell in the test container
docker-compose -f docker-compose.test.yml run --rm nextcloud-test bash

# Then inside the container:
composer install
vendor/bin/phpunit --filter testCreate_ValidData_ReturnsCreatedTask
```

## Platform-Specific Notes

### Windows

#### Docker Desktop + WSL2 (Recommended)

1. Install [Docker Desktop](https://docs.docker.com/desktop/install/windows-install/)
2. Enable WSL2 backend in Docker Desktop settings (Settings → General → Use WSL 2)
3. Enable Virtualization in BIOS (if not already enabled)
4. Install [WSL2](https://docs.microsoft.com/en-us/windows/wsl/install):
   ```powershell
   wsl --install
   ```
5. Clone and run tests from WSL:
   ```bash
   wsl
   cd /mnt/c/path/to/ChoreStrike
   docker compose -f docker-compose.test.yml up --build
   ```

#### Docker Desktop + PowerShell (Alternative)

1. Install Docker Desktop
2. Open PowerShell
3. Run:
   ```powershell
   docker compose -f docker-compose.test.yml up --build
   ```

### Linux

```bash
# Install Docker (example for Ubuntu/Debian)
sudo apt update
sudo apt install docker.io docker-compose-plugin

# Add user to docker group (requires logout/login)
sudo usermod -aG docker $USER

# Run tests
docker compose -f docker-compose.test.yml up --build
```

### macOS

```bash
# Install Docker Desktop
brew install --cask docker

# Run tests
docker compose -f docker-compose.test.yml up --build
```

## Troubleshooting

### "Cannot connect to Docker daemon"

- **Linux**: Ensure your user is in the `docker` group and Docker service is running:
  ```bash
  sudo systemctl start docker
  ```
- **Windows/macOS**: Ensure Docker Desktop is running

### "Class OCP\* not found"

This means the test is running outside the Docker environment or Nextcloud core is not properly loaded. Always use `docker compose -f docker-compose.test.yml` to run tests.

### "Failed to open stream: No such file or directory"

The Nextcloud server clone might have failed. Rebuild the image:
```bash
docker compose -f docker-compose.test.yml down --rmi all
docker compose -f docker-compose.test.yml build --no-cache
```

### Permission Denied Errors

```bash
# Fix permissions on Linux/WSL
sudo chown -R $USER:$USER .
```

### Slow Test Execution

On Windows without WSL2, file operations can be slow. Enable WSL2 backend in Docker Desktop for better performance.

## Continuous Integration

The authoritative CI workflow is `.forgejo/workflows/release-gate.yml`
(GHA-compatible, runs on Forgejo Actions / Codeberg CI and GitHub Actions).
It is the single source of truth for blocking gate commands.

### Blocking gate commands

| Command | Purpose |
|---------|---------|
| `npm run lint` | ESLint, 0 errors required |
| `npm test` | Jest (Vue components, stores, API contract) |
| `npm run build` | Webpack production build |
| `composer validate --strict` | `composer.json` sanity |
| `vendor/bin/phpunit --configuration phpunit.unit.xml` | Backend unit tests (via Docker) |
| `vendor/bin/phpunit --configuration phpunit.integration.xml` | Backend integration tests (via Docker) |

### Non-blocking (advisory)

| Command | Reason |
|---------|--------|
| `npm run stylelint` | `@nextcloud/stylelint-config` ↔ `stylelint` upstream version mismatch produces 201 "Unknown rule" errors (see `docs/backlog/bugs.md`). Stylelint output is uploaded as an artifact for review. Promoted to blocking once the upstream toolchain is pinned. |

### Running the gate locally

```bash
# Frontend
npm ci
npm run lint
npm test -- --ci
npm run build

# Backend (Docker)
docker compose -f docker-compose.test.yml up -d
docker compose -f docker-compose.test.yml exec nextcloud-test \
  bash -c "cd /var/www/html/custom_apps/chorestrike && \
    vendor/bin/phpunit --configuration phpunit.unit.xml && \
    vendor/bin/phpunit --configuration phpunit.integration.xml"
docker compose -f docker-compose.test.yml down
```

### Contract tests (Phase 2 of CS-PLAN-007)

- `tests/unit/RouteMatrixTest.php` — every route in `appinfo/routes.php`
  resolves to a public method on the corresponding controller class.
  Catches the "missing controller method" and "unrouted implemented method"
  drift patterns.
- `tests/unit/store/apiContract.spec.js` — every API endpoint called by
  the Pinia stores (`src/store/*.js`) via the shared `api` client matches at
  least one declared route. Derived from the same `appinfo/routes.php` source.
  Also asserts no store uses the deprecated `/index.php/apps/chorestrike`
  prefix form (single canonical prefix `/apps/chorestrike` only).
- `tests/unit/services/api.spec.js` — shared API client (`src/services/api.js`)
  contract: URL prefix construction, `{ success, data, error }` envelope
  unwrap, `!success` / HTTP-error → `ApiError`, and method-verb mapping.

These tests are part of the blocking gate.

## E2E Tests with Playwright

End-to-end tests run against a full Docker-based Nextcloud instance with the ChoreStrike app enabled.

### Prerequisites

- Docker and Docker Compose
- Node.js 18+ with npm
- Playwright browsers installed: `npx playwright install chromium`

### Setting up the Test Environment

#### 1. Start Docker Test Environment

```bash
# Start the test environment (Nextcloud + MariaDB)
docker compose -f docker-compose.test.yml up -d

# Wait for services to be ready (~30 seconds)
docker compose -f docker-compose.test.yml logs -f nextcloud-test
```

The test environment includes:
- **MariaDB** (`chorestrike-db`): Database with healthchecks
- **Nextcloud Test** (`chorestrike-test`): Apache + PHP with Nextcloud stable29
- **Auto-setup**: Nextcloud installs automatically, ChoreStrike app is linked and enabled

#### 2. Verify Environment is Ready

```bash
# Check containers are running
docker ps

# Check Nextcloud is accessible
curl http://localhost:8080/apps/chorestrike/

# Check logs if issues occur
docker compose -f docker-compose.test.yml logs nextcloud-test
docker compose -f docker-compose.test.yml logs chorestrike-db
```

#### 3. Run E2E Tests

```bash
# Run all E2E tests
npx playwright test tests/e2e/ --project=chromium

# Run specific test file
npx playwright test tests/e2e/3-floor-house.spec.ts --project=chromium

# Run with UI mode for debugging
npx playwright test tests/e2e/ --ui

# Run in headed mode (visible browser)
npx playwright test tests/e2e/ --project=chromium --headed
```

### E2E Test Structure

| Test File | Coverage |
|-----------|----------|
| `minimal-smoke-tests.spec.ts`, `fast-core-tests.spec.ts`, `smoke-tests.spec.ts` | Fast smoke coverage for boot, routing, and key pages |
| `dashboard.spec.ts`, `dashboard-navigation.spec.ts`, `core-functionality.spec.ts` | Dashboard and cross-view navigation coverage |
| `room-management.spec.ts`, `room-detail.spec.ts` | Room CRUD and room-detail flows |
| `task-manager.spec.ts`, `task-completion.spec.ts` | Task management, task creation, assignment, and completion flows |
| `settings.spec.ts`, `3-floor-house.spec.ts`, `gui-scratch-flow.spec.ts` | Full-house creation and realistic GUI-first flows |
| `daily-tasks-flow.spec.ts`, `availability-settings-flow.spec.ts`, `scheduling.spec.ts` | Scheduling and daily-task flows |
| `leaderboard.spec.ts`, `phase-4-advanced-mechanics.spec.ts`, `task-checklist-and-features.spec.ts` | Gamification, leaderboard, and advanced task features |
| `phase-3-user-management.spec.ts` | Legacy coverage that requires rework before it should be treated as a release gate |

### Test Architecture

The Playwright suite runs against the real Vue frontend inside the Docker-based Nextcloud test environment.

Important implications:

- **Selectors must match the current Vue components** (`src/views/*`, `src/components/*`).
- **Form flows matter**. For example, current task creation uses `TaskEditForm.vue`, while room assignment uses `TaskForm.vue`.
- **Release confidence comes from wired views**, not from orphaned or legacy-only components.

When changing task or room flows, update these layers together:

- `src/views/TaskManager.vue`
- `src/views/RoomDetail.vue`
- `src/components/Tasks/TaskList.vue`
- `src/components/Tasks/TaskForm.vue`
- `src/components/Tasks/TaskEditForm.vue`
- the affected Playwright specs

### Troubleshooting E2E Tests

**Timeout waiting for dashboard-view:**
- Ensure Docker containers are running: `docker ps`
- Check Nextcloud logs: `docker logs chorestrike-test`
- Verify app is enabled: `docker exec chorestrike-test occ app:list | grep chorestrike`

**Database connection errors:**
- Check MariaDB is healthy: `docker compose -f docker-compose.test.yml ps`
- Restart environment: `docker compose -f docker-compose.test.yml restart`

**Test flakiness:**
- Increase timeouts in `playwright.config.ts`
- Run with `--workers=1` for sequential execution

### Stopping the Test Environment

```bash
# Stop and remove containers
docker compose -f docker-compose.test.yml down

# Stop and remove containers + volumes (clean slate)
docker compose -f docker-compose.test.yml down -v
```

---

## Test Results

Do not treat hardcoded suite counts in this document as authoritative. The repository evolves quickly. Use the commands below as the source of truth and update this document whenever the release-gate interpretation changes.

### Current Automated Test Inventory

### Playwright E2E

```bash
# Run all E2E tests (requires Docker environment running)
npx playwright test tests/e2e/ --project=chromium
```

Repository status:

- **20 Playwright spec files** currently exist under `tests/e2e/`.
- Not every spec has the same maturity level.
- The most relevant release-oriented UI coverage currently lives in the dashboard, room, task, settings, daily-task, scheduling, and GUI-scratch-flow suites.
- `phase-3-user-management.spec.ts` should be considered **legacy** until it is rewritten against currently wired views and routes.

### Jest Frontend Tests

```bash
npm test
```

Repository status:

- **29 Jest spec files** currently exist under `tests/unit/`.
- Coverage includes:
  - Vue components
  - Pinia stores
  - routed views
  - localization consistency checks

### PHPUnit Unit and Integration Tests

```bash
docker compose -f docker-compose.test.yml run --rm nextcloud-test \
  vendor/bin/phpunit --configuration phpunit.unit.xml

docker compose -f docker-compose.test.yml run --rm nextcloud-test \
  vendor/bin/phpunit --configuration phpunit.xml tests/integration/
```

Repository status:

- **12 integration test files** currently exist under `tests/integration/`.
- Coverage includes controller, API, family, and extended room-task service scenarios.
- Controller integration tests require the Docker/Nextcloud environment.

## Without Docker (Limited)

Running tests without Docker is possible but **limited**:

```bash
# Install PHP 8.0+ locally (winget on Windows, apt on Linux, brew on macOS)
winget install PHP.PHP.8.3  # Windows example

# Install Composer (see https://getcomposer.org/download/)
php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
php composer-setup.php

# Install dependencies
php composer.phar install

# Run tests (many will fail due to missing OCP classes)
vendor/bin/phpunit
```

**Note**: Tests requiring Nextcloud core classes (OCP\*) will fail. Use Docker for full test coverage.

## Continuous Integration

The Docker test environment is also used in CI/CD pipelines. See the
**Continuous Integration** section above for the authoritative
`.forgejo/workflows/release-gate.yml` workflow and blocking gate commands.

## Security Regression Test Pack (Recommended)

After security-related backend changes, run these tests first:

```bash
# House-scoped scheduling authorization checks
php vendor/bin/phpunit tests/unit/Service/SchedulingServiceTest.php

# House-scoped task sync authorization checks
php vendor/bin/phpunit tests/unit/Service/TaskSyncServiceTest.php
```

Then run the core domain confidence suite:

```bash
php vendor/bin/phpunit tests/unit/Db/TaskTest.php
php vendor/bin/phpunit tests/unit/Db/RoomTaskTest.php
php vendor/bin/phpunit tests/unit/Service/GameLogicServiceTest.php
php vendor/bin/phpunit tests/unit/Service/HouseServiceTest.php
php vendor/bin/phpunit tests/unit/Service/RoomServiceTest.php
php vendor/bin/phpunit tests/unit/Service/FloorServiceTest.php
```

## Docker Cleanup and Manual Validation

Yes, you can test manually in Docker. If old test containers are still running, stop and clean them before a fresh run.

### 1) Check running containers

```bash
docker ps -a
```

### 2) Stop stale test containers

```bash
docker stop <container_id_1> <container_id_2>
```

### 3) Optional: remove stopped containers

```bash
docker rm <container_id_1> <container_id_2>
```

### 4) Optional: remove outdated test image

```bash
docker images
docker rmi <image_id>
```

### 5) Run manual test session in container

```bash
docker compose -f docker-compose.test.yml run --rm nextcloud-test bash

# inside container
composer install
vendor/bin/phpunit tests/unit/Service/SchedulingServiceTest.php
vendor/bin/phpunit tests/unit/Service/TaskSyncServiceTest.php
```

## Test Database Profiles

ChoreStrike supports three test database profiles for different testing scenarios:

### Available Profiles

| Profile | Users | Floors | Rooms | Tasks | Use Case |
|---------|-------|--------|-------|-------|----------|
| `empty` | 0 | 0 | 0 | 0 | Clean slate, scratch flows |
| `minimal` | 2 (adult, kid) | 1 | 1 | 0 | Basic smoke tests |
| `family` | 6 (mom, dad, teen, kid, grandma, friend) | 3 | 6 | 12 | Multi-user interactions |

### Using Profiles

#### Docker Compose

```bash
# Run with minimal profile (default)
docker compose -f docker-compose.test.yml up

# Run with family profile
TEST_DB_PROFILE=family docker compose -f docker-compose.test.yml up

# Run with empty profile
TEST_DB_PROFILE=empty docker compose -f docker-compose.test.yml up
```

#### OCC Command

```bash
# Seed database with specific profile
docker exec chorestrike-test php occ chorestrike:test-data:seed --profile=family

# Reset database
docker exec chorestrike-test php occ chorestrike:test-data:seed --reset
```

#### API Endpoint

```bash
# Seed via HTTP (only available in test mode)
curl -X POST "http://localhost:8080/apps/chorestrike/api/v1/test/seed?profile=family"

# Reset database
curl -X POST "http://localhost:8080/apps/chorestrike/api/v1/test/reset"

# Get available profiles
curl "http://localhost:8080/apps/chorestrike/api/v1/test/profiles"
```

### Playwright Integration

Use the `database-fixtures.ts` in your E2E tests:

```typescript
import { test, expect, loginAsUser, getUserCredentials } from './fixtures/database-fixtures'

// Use family profile for this test file
test.use({ databaseProfile: 'family' })

test('mom can see assigned tasks', async ({ page, seededData }) => {
  const creds = getUserCredentials('family', 'mom')
  await loginAsUser(page, creds.userId, creds.password)
  // ... test code
})
```

### Family Profile Details

The `family` profile creates a realistic family scenario:

**Users:**
- `mom` (owner) - Full availability, kitchen/laundry preferences
- `dad` (owner) - Full availability, garage/yard preferences
- `teen` (child) - Weekend only, bedroom/bathroom preferences
- `kid` (child) - Weekend only, toys/pets preferences, max 2 daily tasks
- `grandma` (member) - Limited availability, kitchen/living room
- `friend` (member) - Saturday only, max 1 daily task

**Rooms:**
- Ground Floor: Kitchen (75% clean), Living Room (60%), Master Bathroom (70%)
- First Floor: Master Bedroom (80%), Kids Bedroom (40%), Kids Bathroom (50%)
- Second Floor: (empty, for expansion)

**Tasks (12 total):**
- Daily: Dishes, counters, trash, toys, beds
- Weekly: Vacuum, dust, laundry, toilets, sinks

### CI/CD Integration

The Forgejo/Codeberg workflow runs tests with all profiles:

```yaml
strategy:
  matrix:
    profile: [empty, minimal, family]
    browser: [chromium, firefox]
```

---

## Remaining Coverage Gaps

The biggest remaining gaps are no longer basic component or store tests. Those areas now have meaningful automated coverage. The highest-value remaining work is here:

### 1. Real Multi-User GUI Coverage

- The current realistic shared-state smoke coverage is centered on `tests/e2e/gui-scratch-flow.spec.ts`.
- There is still no broad release-gate Playwright pack for:
  - two-user task claiming
  - teamwork participation joins/leaves
  - reassignment or overdue-claim flows across separate sessions

### 2. Legacy E2E Drift

- Some older Playwright specs still drift when selectors or form flows change.
- `phase-3-user-management.spec.ts` is the clearest example and should be rewritten or excluded from release-gate expectations until it targets currently wired screens.

### 3. Cross-Layer Contract Changes

- Task and room flows are sensitive to payload-shape drift between:
  - backend room-task payloads
  - Pinia stores
  - `TaskList.vue`
  - `RoomDetail.vue`
  - `TaskManager.vue`
  - Playwright selectors
- Whenever these contracts change, add or refresh both unit regressions and E2E smoke coverage in the same change.

### 4. Release Validation Order

Before manual server testing or production rollout, prefer this order:

```bash
# 1. Frontend unit coverage
npm test

# 2. Backend unit and integration coverage in Docker
docker compose -f docker-compose.test.yml run --rm nextcloud-test vendor/bin/phpunit --configuration phpunit.unit.xml
docker compose -f docker-compose.test.yml run --rm nextcloud-test vendor/bin/phpunit --configuration phpunit.xml tests/integration/

# 3. Targeted release-gate GUI smoke coverage
npx playwright test tests/e2e/task-manager.spec.ts tests/e2e/room-detail.spec.ts tests/e2e/task-completion.spec.ts tests/e2e/gui-scratch-flow.spec.ts --project=chromium
```
