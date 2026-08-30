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

> Always `docker exec` into the **running** `chorestrike-test` container — never `docker compose run` (a fresh container skips the entrypoint, so Nextcloud is not installed and PHPUnit dies with `Exception: Not installed`).

```bash
# Make sure the environment is up
docker compose -f docker-compose.test.yml up -d

# Run all unit tests
docker exec chorestrike-test vendor/bin/phpunit --configuration phpunit.unit.xml

# Run all integration tests
docker exec chorestrike-test vendor/bin/phpunit --configuration phpunit.integration.xml

# Run a specific test file
docker exec chorestrike-test vendor/bin/phpunit tests/unit/Service/TaskServiceTest.php

# Run a specific test method
docker exec chorestrike-test vendor/bin/phpunit --filter testCreate_ValidData_ReturnsCreatedTask
```

### 4. Interactive Development

For interactive debugging or running commands inside the container:

```bash
# Start a shell in the test container
docker exec -it chorestrike-test bash

# Then inside the container:
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
| `npm run stylelint` | Stylelint, 0 errors required (`src/` Vue/CSS/SCSS) — CS-026 |
| `npm test` | Jest (Vue components, stores, API contract) |
| `npm run build` | Webpack production build |
| `composer validate --strict` | `composer.json` sanity |
| `composer cs:check` | PHP coding standard (Nextcloud CS), 0 diff required — CS-026 |
| `vendor/bin/phpunit --configuration phpunit.unit.xml` | Backend unit tests — Entity/Service/Controller/BackgroundJob/Command/Contract/Notification/Migration/l10n suites (via Docker) |
| `vendor/bin/phpunit --configuration phpunit.integration.xml` | Backend integration tests (via Docker) |
| `npx playwright test --project=chromium --project=chromium-serial-db` | Playwright E2E — chromium, `family` seed profile (via Docker) |

### Non-blocking (advisory)

| Command | Reason |
|---------|--------|
| _(none — stylelint + PHP CS were promoted to blocking in CS-026, 2026-08-12; stylelint still emits ~26 `use-logical` warnings, deferred → backlog TECH-LINT-004)_ |

> **Unit-gate scope (CS-PLAN-020, 2026-07-29):** `phpunit.unit.xml` now runs the
> full green unit set — Controller + Service + Entity(Db) + BackgroundJob +
> Command + Contract + Notification + Migration + l10n suites. The migration mock deps
> (`ISchemaWrapper`/`IOutput`/`SimpleMigrationStep`) were added to
> `bootstrap-entity.php` so the Migration suite runs in the CI unit gate, and a
> `FreshInstallMigrationSmokeTest` guards the recurring `changeSchema() return
> null` defect class. The 4 stale `phpunit.xml` controller-test
> excludes are resolved: `GameControllerTest`/`RoomControllerTest`/
> `RoomTaskControllerTest` re-included after drift repair,
> `PageControllerTest` archived (framework wiring covered by E2E).
> CS-PLAN-023 (2026-08-04) added the **Command** suite (OCC commands;
> Symfony Console surface mocked under `tests/__mocks__/Symfony/`) and the
> **Contract** suite (`RouteMatrixTest`, which previously lived in no suite;
> now incl. the route-shadowing guard).

### E2E in CI (blocking, CS-PLAN-020)

E2E is a **blocking job** in `.forgejo/workflows/release-gate.yml` (chromium
only, `family` seed profile, runs the `chromium` + `chromium-serial-db`
projects, fails the run on red, wired into `summary.needs`). It mirrors the
proven `test.yml` service setup (NC pinned to `31-apache`, app mounted,
authenticated `occ` seed). The full browser matrix (firefox/webkit) stays in
`.forgejo/workflows/test.yml` and is a documented follow-up once the
chromium-only gate proves stable in CI. The authorisation-denial wire contract
(plain HTTP 403, not a 200 OCS envelope) is verified by the E2E specs
`admin-only-tasks.spec.ts` and `overdue-task-claiming.spec.ts`.

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

### Timezone / day-boundary tests (CS-PLAN-057)

Calendar-day semantics bucket in the house timezone (ADR-009), so a fresh
schema (`down -v` + `up -d`) is required when a timezone migration is added
— an existing test DB has no `migrations:execute` command on Nextcloud 31.

- `tests/unit/Service/CalendarServiceTest.php` — pins the
  `dayBounds`/`weekStart`/`dayKey` primitives around DST transitions in
  UTC, Europe/Berlin and Pacific/Auckland (spring-forward 23h /
  fall-back 25h windows, Sunday week-start semantics).
- `tests/integration/Service/TimezoneDayBucketingTest.php` — real-DB pins:
  a completion at 22:30 UTC (00:30 Berlin) counts for the NEXT Berlin day
  in the duplicate guard and the streak/freeze window; the Berlin weekly
  window differs from UTC's; the minigame weekly leaderboard follows the
  house week (D3 fix).
- `tests/unit/utils/dateKeys.spec.js` — frontend `dateKeyInZone` pins
  (midnight crossings, epoch/invalid guards, DST overlap).

The E2E container runs UTC; timezone behavior is covered at the
unit/integration level above.

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

> The full, current list of spec files is the source of truth — run
> `git ls-files 'tests/e2e/*.spec.ts'` (or `ls tests/e2e/*.spec.ts`) for it.
> The groupings below are a navigational aid, not an exhaustive contract.

| Test File | Coverage |
|-----------|----------|
| `minimal-smoke-tests.spec.ts`, `fast-core-tests.spec.ts` | Fast smoke coverage for boot, shell, hash deep-links, and key pages (lean auto-waiting smoke layer, CS-PLAN-050) |
| `validation.spec.ts`, `error-handling.spec.ts`, `today-view.spec.ts` | Data validation, error handling, and daily-task flows (Today view) |
| `dashboard.spec.ts`, `dashboard-navigation.spec.ts`, `core-functionality.spec.ts` | Dashboard and cross-view navigation coverage |
| `room-management.spec.ts`, `room-detail.spec.ts`, `3-floor-house.spec.ts` | Room/floor CRUD, room-detail flows, full-house scenarios |
| `task-manager.spec.ts`, `task-completion.spec.ts`, `task-completion-full-flow.spec.ts`, `task-checklist-and-features.spec.ts` | Task management, creation, checklist, and completion flows |
| `settings.spec.ts`, `gui-scratch-flow.spec.ts` | Full-house creation and realistic GUI-first flows |
| `leaderboard.spec.ts`, `title-selection.spec.ts` | Gamification, leaderboard, role titles |
| `private-tasks.spec.ts`, `admin-only-tasks.spec.ts`, `teamwork-rewards.spec.ts` | Visibility (private/admin-only) and teamwork-bonus rewards (CS-PLAN-005) |
| `overdue-task-claiming.spec.ts`, `multi-user-interactions.spec.ts` | Overdue takeover and multi-user/family interactions |
| `time-travel-overdue.spec.ts`, `time-travel-streak-notification.spec.ts` | Time-travel flows (CS-PLAN-023): overdue via seeded past due-date, streak-freeze + at-risk notification via `trigger-jobs --as-of` |
| `snake-game.spec.ts` | Minigame (Snake arcade) coverage |
| `scheduling.spec.ts`, `flex-window-scheduling.spec.ts`, `availability-settings-flow.spec.ts` | Smart Scheduling UI — availability grid, energy budget, calendar, suggestions (revived by CS-PLAN-016) |
| `self-availability-member.spec.ts` | Non-owner scheduling surface: "My Availability" self-service toggles, hidden owner grid, persist/reload round-trip (CS-PLAN-032; runs in default `chromium` as `admin`, ignored by firefox/webkit) |

> **Archived (CS-PLAN-015, 2026-07-26):** `phase-3-user-management.spec.ts`
> (retired group-assignment UI) and `phase-4-advanced-mechanics.spec.ts` (phantom
> components) were moved to `archive/2026-07/e2e-legacy/`. The scheduling trio
> (`scheduling`, `flex-window-scheduling`, `availability-settings-flow`) was
> also archived but has been **revived by CS-PLAN-016** (2026-07-28) and is
> active again — see the table above.

### E2E Auth & Stable Selectors (CS-PLAN-015 / 015b)

- **Auth via per-user `storageState`:** the Playwright `setup` project
  ([`tests/e2e/auth.setup.ts`](../../tests/e2e/auth.setup.ts)) logs in the admin
  plus every **`family`** user once and saves the browser contexts to
  `.playwright/.auth/{userId}.json` (gitignored). Auth is **decoupled from
  `TEST_DB_PROFILE`**: the setup always seeds + authenticates the `family`
  identities every spec consumes (mom/dad/teen/kid/grandma/friend), while
  `TEST_DB_PROFILE` only controls the per-test seed DATA (re-seeded by the
  `seededData` fixture). This keeps family-pinning specs green under every CI
  matrix row (`empty`/`minimal`/`family`). Accounts survive re-seeding
  (`TestDataService::reset()` touches only `chorestrike_*` tables, never
  `oc_users`). The browser projects depend on setup and start authenticated as
  admin, so specs that run as admin need **no login call** — just navigate.
- **Multi-user auth patterns** (see
  [`e2e-testid-conventions.md`](../standards/guidelines/e2e-testid-conventions.md) §6):
  - Single-user describe → `test.use({ storageState: userStatePath('mom') })`.
  - Per-test / multi-user → `createUserContext(browser, 'teen')` from
    [`tests/e2e/fixtures/auth-contexts.ts`](../../tests/e2e/fixtures/auth-contexts.ts).
  - **No-logout rule:** no spec may navigate to or fetch `/logout` — it
    invalidates the shared server-side session for every parallel worker.
- **Seed-race containment (CS-PLAN-015b Phase 2):** the 7 seed-dependent specs
  (`error-handling`, `validation`, `multi-user-interactions`,
  `task-completion-full-flow`, `3-floor-house`, plus the two CS-PLAN-023
  time-travel specs `time-travel-overdue` and
  `time-travel-streak-notification`) run in a dedicated
  `chromium-serial-db` project (`fullyParallel: false`, `workers: 1`, chromium
  only). The parallel pool (chromium / firefox / webkit) excludes them via
  `testIgnore` and never touches the seed/reset endpoints concurrently.
- **Seed/test-endpoint auth (CS-PLAN-019 + CS-PLAN-023):** the test endpoints
  require an authenticated session (no `@PublicPage`). The E2E fixtures
  (`database-fixtures.ts`) and `auth.setup.ts` therefore authenticate with the
  admin account via Basic auth and use POST for seed/reset. The time-travel
  specs additionally shell out to `occ` via `tests/e2e/fixtures/occ-runner.ts`.
- **Scheduling owner-context (CS-PLAN-018 Phase 3b):** `scheduling.spec.ts` and
  `availability-settings-flow.spec.ts` run in a dedicated
  `chromium-scheduling-owner` project as `dad`
  (`storageState: '.playwright/.auth/dad.json'`, `fullyParallel: false`,
  `workers: 1`). Root cause: admin is a lone `member` (house 418) with no
  owner-only availability data; owners are `dad`/`mom` (house 420). The
  parallel pool excludes these specs via `testIgnore`.
- **Stable selectors:** prefer `getByRole`/`getByLabel`, fall back to
  `getByTestId`. Never use `text=` or positional CSS selectors. No
  `waitForTimeout` — use auto-waiting locators/assertions. See
  [`docs/standards/guidelines/e2e-testid-conventions.md`](../standards/guidelines/e2e-testid-conventions.md).
- **Flake acceptance run:** the live config retries twice, which masks flakes —
  verify with `npx playwright test --retries=0` across the full matrix
  (chromium + firefox + webkit + chromium-serial-db + chromium-scheduling-owner).
  CS-PLAN-018 Phase 7 (2026-07-29) proved the suite flake-free: 3× full-matrix
  `--retries=0`, 0 failed.

### Full-Matrix Verification (CS-PLAN-051, BUG-E2E-004)

The blocking release gate stays chromium-shaped (`chromium` +
`chromium-serial-db`, see `.forgejo/workflows/release-gate.yml`). The full
browser matrix is verified two ways:

1. **Manually (acceptance):** bring up the Docker env, reseed the family
   profile, then run `npx playwright test --retries=0 --reporter=line` with
   `TEST_DB_PROFILE=family` and `NEXTCLOUD_TEST_MODE=true`. Acceptance = two
   **consecutive** green runs. A full matrix run takes ~10–13 min locally.
2. **Scheduled CI (non-blocking):**
   [`.forgejo/workflows/full-matrix.yml`](../../.forgejo/workflows/full-matrix.yml)
   runs the whole matrix weekly (Mondays 04:00 UTC) and on `workflow_dispatch`
   with `--retries=0`, uploads the Playwright report as an artifact, and never
   blocks a release. Treat red scheduled runs as flake leads, not gate
   failures: reproduce the failing spec standalone (3× `--retries=0` in the
   failing browser), then under load (run it alongside fast-core + release
   specs) before diagnosing.

Full-matrix stabilization history (CS-PLAN-051, 2026-08-26):

- `InsightsView.vue` renders the shared `ErrorState` with retry for **both**
  failed fetches (stats → `insights-error`, members → fairness section); a
  transient fetch failure never surfaces as a false "No members yet" hint.
- `TaskList.vue` carries `data-testid="visibility-lock-icon"` on private rows
  (mirror of the crown testid); the icon specs assert API-created fixture rows
  (`tests/e2e/fixtures/visibility-fixtures.ts`) instead of seed-content
  regexes, and no longer use `waitForLoadState('networkidle')`.
- `icons.js` fluent components render via `h()` (the runtime `template:`
  string needed the compiler the esm-bundler build does not ship), and the
  webpack asset rule no longer double-handles the Fluent SVGs — before this
  fix every fluent icon rendered empty under production bundling.
- `snake-game.spec.ts` pause test starts and pauses in one renderer task
  (`evaluate`: click + synchronous `keydown`), with a bounded retry that
  clicks whichever start/play-again button is present.
- `task-checklist-and-features.spec.ts` moved to `SEED_DEPENDENT_SPECS` —
  it mutates seed state and uses seed-coupled row locators.

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

- For the current spec count, run `git ls-files 'tests/e2e/*.spec.ts' | wc -l` (the suite grows over time).
- Not every spec has the same maturity level.
- The most relevant release-oriented UI coverage currently lives in the dashboard, room, task, settings, daily-task, scheduling, and GUI-scratch-flow suites.
- `phase-3-user-management.spec.ts` should be considered **legacy** until it is rewritten against currently wired views and routes.

### Jest Frontend Tests

```bash
npm test
```

Repository status:

- For the current Jest spec count, run `git ls-files 'tests/unit/**/*.spec.js' | wc -l` (the count grows as coverage expands).
- Coverage includes:
  - Vue components
  - Pinia stores
  - routed views
  - localization consistency checks

### PHPUnit Unit and Integration Tests

```bash
docker compose -f docker-compose.test.yml exec nextcloud-test \
  vendor/bin/phpunit --configuration phpunit.unit.xml

docker compose -f docker-compose.test.yml exec nextcloud-test \
  vendor/bin/phpunit --configuration phpunit.integration.xml
```

> `phpunit.unit.xml` runs unit tests only; `phpunit.integration.xml` runs
> integration tests only (real Nextcloud + DB bootstrap). The umbrella
> `phpunit.xml` is aligned with the unit suite (`bootstrap-entity.php`,
> CS-PLAN-020) — the dedicated configs are the canonical per-suite commands.
> Run via `exec` against the running container: `docker compose run --rm`
> overrides the entrypoint, so its fresh container has no installed
> Nextcloud and the integration bootstrap fails with `Not installed`.

Repository status:

- For the current integration test count, run `git ls-files 'tests/integration/**/*Test.php' | wc -l` (the count grows as coverage expands).
- Coverage includes controller, API, family, and extended room-task service scenarios.
- Controller integration tests require the Docker/Nextcloud environment.

## Without Docker (Limited)

> **Not applicable on the main development machine** — Docker Desktop is installed there and is the canonical test path (see project-rules §2 "Test Environment (Docker)"). This fallback exists **only for machines without any Docker installation**. AI agents must not treat a failed `docker` probe as a reason to use this section; diagnose the daemon first.

Running tests without Docker is possible but **limited**:

```bash
# Install PHP 8.2+ locally (winget on Windows, apt on Linux, brew on macOS)
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
docker exec -it chorestrike-test bash

# inside container
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

```powershell
# PowerShell 5.1 equivalent (the dev machine's shell) — VAR=value prefix syntax does not work there:
$env:TEST_DB_PROFILE='family'; docker compose -f docker-compose.test.yml up
```

#### OCC Command (canonical seed path)

```bash
# Seed database with specific profile
docker exec chorestrike-test php occ chorestrike:test-data:seed --profile=family

# Reset database
docker exec chorestrike-test php occ chorestrike:test-data:seed --reset
```

#### API Endpoint

> **CS-PLAN-019 (security hardening):** the HTTP test endpoints require an
> authenticated Nextcloud session (`@NoAdminRequired`, no `@PublicPage`) and
> are gated by `isTestMode()`. Test mode is active **only** when the explicit
> `NEXTCLOUD_TEST_MODE=true` environment variable is set on the PHP process —
> a generic `CI` env var no longer activates it. The canonical seed path is
> the `occ` command above; the HTTP endpoints are a secondary convenience for
> authenticated test tooling.

```bash
# Seed via HTTP (requires authenticated session + NEXTCLOUD_TEST_MODE=true)
curl -X POST "http://localhost:8080/apps/chorestrike/api/v1/test/seed/family"

# Reset database
curl -X POST "http://localhost:8080/apps/chorestrike/api/v1/test/reset"

# Get available profiles
curl "http://localhost:8080/apps/chorestrike/api/v1/test/profiles"
```

### Playwright Integration

Use the database and auth fixtures in your E2E tests:

```typescript
import { test, expect } from './fixtures/database-fixtures'
import { createUserContext, userStatePath } from './fixtures/auth-contexts'

// Single-user describe: all tests run as mom
test.describe('Mom tests', () => {
  test.use({ databaseProfile: 'family' })
  test.use({ storageState: userStatePath('mom') })

  test('mom sees tasks', async ({ page }) => {
    await page.goto('/apps/chorestrike/')
    // ... test code
  })
})

// Multi-user flow: create owned contexts
test('two users see same house', async ({ browser }) => {
  const mom = await createUserContext(browser, 'mom')
  const teen = await createUserContext(browser, 'teen')
  try {
    // ... test code with mom.page and teen.page
  } finally {
    await mom.context.close()
    await teen.context.close()
  }
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

**Time-travel seeds (CS-PLAN-023):**
- `mom` carries the streak state: `currentStreak=5`, `streakFreezes=0` (no
  completions exist in the seed) — the at-risk/freeze flow target.
- "Take out trash" (assigned to `dad`) is seeded with a due-date three days
  in the past — immediately overdue against the real clock.

### CI/CD Integration

The Forgejo/Codeberg workflow runs tests with all profiles:

```yaml
strategy:
  matrix:
    profile: [empty, minimal, family]
    browser: [chromium, firefox]
```

---

## Mapper-Level Real-DB Regression Tests

Mock-based mapper/service tests can only pin the *query shape* — they stub
`IDBConnection`/mappers and by construction cannot catch real SQL errors
(wrongly quoted aggregate expressions → SQLSTATE 1054/42S22, broken joins,
day-boundary drift across SQLite/MariaDB/PostgreSQL). Whenever a mapper query
is fixed or its semantics are behavior-critical, add a **real-DB regression
test** in `tests/integration/Db/` extending `IntegrationTestCase` (Docker,
family profile):

- Seed the full FK chain via the base-class helpers (`createHouse` →
  `createFloor` → `createRoom` → `createTask` → `assignTaskToRoom` →
  `addHouseMember`); insert auxiliary rows (e.g. completions) with an explicit
  tracked-ID helper and clean them up in `tearDown` (the base cleanup does not
  know them). Precedents: `BatchQueryRegressionTest`, `WriteBatchRegressionTest`,
  `RoomTaskMapperDailyTest`, `CompletedTaskMapperEnergyTest`.
- Pin the semantics that mocks cannot see: empty-result defaults, sums/aggregates,
  half-open day boundaries (`[00:00, +1d)`), cross-user/cross-house isolation.
- Extend this suite for new mapper fixes; do not re-cover a method that already
  has a real-DB regression test.

---

## Time-Travel Testing (CS-PLAN-023)

Time-dependent flows — "task overdue after N days", streak-freeze
consumption, and the streak-at-risk notification — are testable in Docker
**without waiting real time**. The three BackgroundJobs
(`DailyNotificationJob`, `StreakFreezeJob`, `StreakRestockJob`) are thin
delegates: they derive "yesterday" from the injected `ITimeFactory` and
hand the pass to services (`StreakAtRiskService`, `StreakFreezeService`,
`StreakRestockService`). Time is injectable at the logic level, and the
`occ` trigger below calls the same services directly.

### `occ chorestrike:trigger-jobs` (test mode only)

```bash
# Run one job type now (yesterday = the real yesterday)
docker exec chorestrike-test su -s /bin/bash www-data -c \
  "cd /var/www/html && php occ chorestrike:trigger-jobs streak-freeze"

# Simulate "a day passed": --as-of sets the reference day, and
# "yesterday" is derived as as-of minus one day (as-of=tomorrow ⇒
# yesterday=today). Zero clock trickery.
docker exec chorestrike-test su -s /bin/bash www-data -c \
  "cd /var/www/html && php occ chorestrike:trigger-jobs streak-freeze --as-of=$(date -d '+1 day' +%Y-%m-%d)"

# All three passes. Order is deterministic: daily-notification runs
# BEFORE streak-freeze so the at-risk reminder fires while the streak is
# still positive (resolves the 06:00 ordering race noted in info.xml).
docker exec chorestrike-test su -s /bin/bash www-data -c \
  "cd /var/www/html && php occ chorestrike:trigger-jobs all"
```

- `<type>` ∈ `daily-notification | streak-freeze | streak-restock | all`.
- Guarded by `TestDataService::isTestMode()` (`NEXTCLOUD_TEST_MODE=true`),
  mirroring `TestDataSeed` — it refuses to run in production.
- `[--user=UID]` is accepted but not yet scoped in the services; the
  command logs a notice and runs the full pass.
- `streak-restock` is **time-independent** (it sets freezes to the
  configured weekly value) — triggering it once is sufficient and it
  ignores `--as-of`.

### Seeded time-travel state (`family` profile)

- **Overdue:** one room task ("Take out trash", assigned to `dad`) is
  seeded with a due-date three days in the past, so the overdue flow is
  live against the real clock — no shift needed.
- **Streak/at-risk:** `mom` is seeded with a 5-day streak and zero
  freezes (and no completions exist in the seed), so
  `daily-notification` + `streak-freeze` with `--as-of=tomorrow`
  exercise the reminder + reset within seconds.

E2E coverage: `tests/e2e/time-travel-overdue.spec.ts` and
`tests/e2e/time-travel-streak-notification.spec.ts` (both in the
`chromium-serial-db` project). The specs trigger the jobs via
`tests/e2e/fixtures/occ-runner.ts` (`docker exec`, container
auto-detected; override with `NEXTCLOUD_CONTAINER`).

### Optional: `libfaketime` (not wired, demoted)

A whole-container clock shift (`LD_PRELOAD` libfaketime) is only needed
for global multi-day transitions that cannot be encoded as `--as-of` or
seeded past dates. It is **off by default and not wired** into
`docker/Dockerfile.test`. Rule if it is ever introduced: never combine
`libfaketime` with time-relative seeded dates in one assertion —
"yesterday" and seeded due-dates drift inconsistently under a shift.
Prefer seed + `--as-of` (no clock shift) as the default.

---

## Coverage Measurement (CS-PLAN-022 Phase 1)

Coverage is generated with built-in tooling only (no nyc/c8/istanbul). CI emits
artifacts non-blocking; thresholds are added in Phase 6 once the baseline is stable.

### PHPUnit coverage (PCOV)

The Docker test image (`docker/Dockerfile.test`) includes the PCOV extension.
Generate reports locally:

```bash
docker exec chorestrike-test bash -c "composer test:coverage"
```

Artifacts: `build/coverage/phpunit-clover.xml` + `build/coverage/phpunit-html/`.

### Jest coverage

```bash
npm run test:coverage
```

Artifacts: `build/coverage/jest/` (HTML + lcov).

### Baseline (2026-07-30, CS-PLAN-022 Phase 1+4)

| Metric | PHPUnit (unit gate) | Jest |
|--------|-------------------|------|
| Statements | 54.3% (5310/9787) | 90.3% |
| Branches | — | 80.4% |
| Methods | 47.2% (395/837) | 68.3% |
| Lines | 54.3% | 90.3% |
| Tests | 760 | 667 |

PHPUnit coverage uses `processUncoveredFiles="false"` (only files loaded during
tests are measured; loading all `lib/` files fails on unmocked OCP dependencies).

---

## Remaining Coverage Gaps

The biggest remaining gaps are no longer basic component or store tests. Those areas now have meaningful automated coverage. The highest-value remaining work is here:

### 1. E2E Pipeline & Coverage Gaps (updated 2026-07-30, CS-PLAN-022)

- **E2E in CI repaired** — `test.yml` now triggers on `master`, pins
  `nextcloud:31-apache`, mounts the app, and runs `chromium-serial-db` as a
  separate step on the chromium matrix row only (CS-PLAN-015b D-CI). Folding
  into `release-gate.yml` as blocking is follow-up.
- **Jest gaps closed (CS-PLAN-022)** — `TaskEditForm`, `OverdueClaimDialog`,
  `TemplateSelector`, `FlexWindowDisplay`, `CalendarDayCell`, `SvgIcon`
  component specs + `checklist` store + 4 util specs added. `SnakeGame`,
  `BoostShop`, `SnakeLeaderboard` deferred (deep `storeToRefs` integration;
  E2E `snake-game.spec.ts` covers the integration path).
- **Backend unit gaps closed (CS-PLAN-022)** — `TemplateService`,
  `RewardConfigController`, `TemplateController`, `TaskSyncController`,
  `SmartSchedulerController` behavior tests + 7 mapper tests + 5 entity tests.
  P9-TEST-003 closed.
- **Remaining E2E gaps** — reward configuration, task templates, notifications,
  BoostShop purchase flow, and mobile/responsive layouts still have no E2E
  coverage (deferred to follow-up plan).
- **Stabilization done** — CS-PLAN-015b shipped session-safe multi-user auth +
  seed-race containment; 3× full-matrix acceptance green (2026-07-28). CS-016
  re-adds the 3 archived scheduling specs.

### 2. Legacy E2E Drift

- The known dead specs were archived by CS-PLAN-015 (`archive/2026-07/e2e-legacy/`):
  `phase-3-user-management`, the scheduling trio (revive via CS-016), and
  `phase-4-advanced-mechanics`.
- The ~13-spec long tail still carries ~27 `waitForTimeout` calls plus residual
  text/positional selectors (`phase-9-code-quality.md` P9-E2E-002b, starts once
  the CS-015/015b foundation is proven flake-free).

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
docker compose -f docker-compose.test.yml exec nextcloud-test vendor/bin/phpunit --configuration phpunit.unit.xml
docker compose -f docker-compose.test.yml exec nextcloud-test vendor/bin/phpunit --configuration phpunit.integration.xml

# 3. Targeted release-gate GUI smoke coverage
npx playwright test tests/e2e/task-manager.spec.ts tests/e2e/room-detail.spec.ts tests/e2e/task-completion.spec.ts tests/e2e/gui-scratch-flow.spec.ts --project=chromium
```
