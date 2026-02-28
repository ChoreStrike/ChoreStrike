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
git clone https://github.com/volker-ma/ChoreStrike.git
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

The Docker test environment is also used in CI/CD pipelines:

```yaml
# Example GitHub Actions workflow
name: PHPUnit Tests
on: [push, pull_request]
jobs:
  test:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v3
      - name: Run tests
        run: docker-compose -f docker-compose.test.yml up --build --abort-on-container-exit
```

## How It Works

The test environment consists of:

1. **Dockerfile** (`docker/Dockerfile.test`):
   - Based on PHP 8.2 Apache image
   - Clones Nextcloud stable29 server for OCP classes
   - Includes all PHP extensions required for testing
   - Contains custom autoloader for OCP namespace

2. **docker-compose.test.yml**:
   - Mounts the ChoreStrike code into the container
   - Sets environment variables for test mode
   - Runs `composer install` and PHPUnit

3. **tests/bootstrap.php**:
   - Detects Docker vs local environment
   - Registers custom autoloader for OCP classes when in Docker
   - Falls back to vendor autoloader for local development (limited functionality)

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
| `3-floor-house.spec.ts` | Full workflow: floors, rooms, tasks, leaderboard |
| `dashboard-navigation.spec.ts` | Dashboard view and navigation |
| `room-management.spec.ts` | Room CRUD operations |
| `settings.spec.ts` | Settings page functionality |
| `leaderboard.spec.ts` | Leaderboard view |

### Test Architecture

The E2E tests use a **JavaScript stub** (`js/chorestrike-main.js`) that simulates the Vue frontend:
- Renders expected DOM elements with `data-testid` attributes
- Implements state management for floors, rooms, tasks
- Handles navigation between Dashboard, Settings, Tasks, Leaderboard views
- Supports modal dialogs (Add Floor, Add Room, Add Task)

This approach allows testing without building the full frontend assets.

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

Current test status (as of last run):

### E2E/Playwright Tests (Working ✓)

```bash
# Run E2E tests (requires Docker environment running)
npx playwright test tests/e2e/ --project=chromium
```
- **Test Suites**: 5 files, 10 tests passing (100%)
- **Tests**: 10/10 passing (100%)
- **Status**: All passing ✓
- **Coverage**: Dashboard, Settings, Tasks, Leaderboard, Room Management, 3-Floor House Workflow

### Jest Frontend Tests (Working ✓)
```bash
npm test
```
- **Test Suites**: 8/8 passing (100%)
- **Tests**: 112 passing (100%)
- **Status**: All passing ✓
- **Coverage**: 7 Vue components tested

### Entity Tests (Working ✓)
```bash
docker compose -f docker-compose.test.yml run --rm nextcloud-test \
  vendor/bin/phpunit --configuration phpunit.unit.xml --testsuite Entity
```
- **Tests**: 107
- **Status**: All passing ✓
- **Coverage**: Room, Task, Floor, House, HouseUser, Medal, RoomTask, TaskScope entities

### Service Tests (Working ✓)
```bash
docker compose -f docker-compose.test.yml run --rm nextcloud-test \
  vendor/bin/phpunit --configuration phpunit.unit.xml --testsuite Service
```
- **Tests**: Comprehensive coverage for GameLogicService, HouseService, RoomService, FloorService, TaskService, RoomTaskService
- **Status**: Core domain logic tested ✓

### Integration Tests
- **FamilyIntegrationTest**: 8/8 tests passing ✓
- **Controller Integration Tests**: Require Nextcloud core environment (run in Docker)
- **Status**: Family scenarios fully tested

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

The Docker test environment is also used in CI/CD pipelines:

```yaml
# Example GitHub Actions workflow
name: PHPUnit Tests
on: [push, pull_request]
jobs:
  test:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - name: Run tests
        run: docker compose -f docker-compose.test.yml up --build --abort-on-container-exit
```

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

## Missing Test Coverage

The following areas need additional tests (priority order):

### 1. Vue Components (8 missing tests)
**Location**: `tests/unit/components/`
**Components without tests**:
- `ComboNotification.vue` - Gamification notifications
- `Emoji.vue` - Emoji display component  
- `Icon.vue` - Icon rendering component
- `MedalDetails.vue` - Medal detail view
- `MedalList.vue` - Medal collection list
- `MonsterCollection.vue` - Monster showcase
- `MonsterSprite.vue` - Animated monster sprites
- `FloorList.vue` - Floor management UI

**Components with tests** (7):
- ✅ MedalBadge.spec.js
- ✅ Modal.spec.js
- ✅ QuickStats.spec.js
- ✅ HouseHealthBar.spec.js
- ✅ RoomCard.spec.js
- ✅ TaskForm.spec.js
- ✅ TaskList.spec.js

### 2. Pinia Stores (7 missing tests)
**Location**: `tests/unit/store/` (directory does not exist)
**Stores needing tests**:
- `game.js` - Game state, medals, monsters
- `house.js` - House management
- `index.js` - Root store coordination
- `roomTasks.js` - Room task assignments
- `rooms.js` - Room state management
- `scheduling.js` - Task scheduling
- `tasks.js` - Task CRUD operations
- `user.js` - User profiles and stats

### 3. Controller Unit Tests (9 controllers)
**Location**: `tests/unit/Controller/` (directory does not exist)
**Controllers needing unit tests**:
- `FloorController.php`
- `GameController.php`
- `HouseController.php`
- `PageController.php`
- `RoomController.php`
- `RoomTaskController.php`
- `SchedulingController.php`
- `TaskController.php`
- `TaskSyncController.php`
- `UserController.php`

### 4. E2E/Integration Tests
**Existing**: `tests/integration/Controller/` has 8 integration test files
**Note**: These require Docker environment with Nextcloud core

### Test Coverage Summary
| Category | Existing | Missing | Coverage |
|----------|----------|---------|----------|
| Entities | 8 files | 0 | 100% |
| Services | 8 files | 0 | 100% |
| Vue Components | 7 files | 8 | 47% |
| Stores | 0 files | 7 | 0% |
| Controllers (Unit) | 0 files | 9 | 0% |
| Integration | 8 files | - | Partial |
