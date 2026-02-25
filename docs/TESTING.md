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

## Test Results

Current test status (as of last run):

### Entity Tests (Working ✓)
```bash
docker compose -f docker-compose.test.yml run --rm nextcloud-test \
  vendor/bin/phpunit --configuration phpunit.unit.xml --testsuite Entity
```
- **Tests**: 107
- **Status**: All passing ✓
- **Coverage**: Room, Task, Floor, House, HouseUser, Medal, RoomTask, TaskScope entities

### Service Tests (Requires QBMapper mock)
- Status: Needs `OCP\AppFramework\Db\QBMapper` mock implementation
- Affected: All *ServiceTest classes that extend Mapper-dependent services

### Integration Tests
- Status: Controller constructor mocks need type alignment
- Affected: All *ControllerIntegrationTest classes

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
