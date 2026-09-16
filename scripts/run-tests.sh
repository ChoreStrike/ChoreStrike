#!/bin/bash

# PHPUnit Test Runner for ChoreStrike
# Works on both Linux/macOS and Windows (via Git Bash/WSL)

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_NAME="chorestrike"
CONTAINER_NAME="${APP_NAME}-test"

echo "=========================================="
echo "ChoreStrike PHPUnit Test Runner"
echo "=========================================="
echo ""

# Check if Docker is available
if ! command -v docker &> /dev/null; then
    echo "Error: Docker is not installed or not in PATH"
    echo "Please install Docker: https://docs.docker.com/get-docker/"
    exit 1
fi

# Check if docker-compose is available (either v1 or v2)
if command -v docker-compose &> /dev/null; then
    COMPOSE_CMD="docker-compose"
elif docker compose version &> /dev/null; then
    COMPOSE_CMD="docker compose"
else
    echo "Error: docker-compose is not available"
    echo "Please install Docker Compose: https://docs.docker.com/compose/install/"
    exit 1
fi

echo "Using: $COMPOSE_CMD"
echo ""

# Change to script directory
cd "$SCRIPT_DIR"

# Function to clean up containers
cleanup() {
    echo ""
    echo "Cleaning up..."
    $COMPOSE_CMD -f "$SCRIPT_DIR/../docker/compose.test.yml" down --remove-orphans 2>/dev/null || true
}

# Set trap to clean up on exit
trap cleanup EXIT

# Build and run tests
echo "Building test environment..."
$COMPOSE_CMD -f "$SCRIPT_DIR/../docker/compose.test.yml" build --no-cache

echo ""
echo "Running PHPUnit tests..."
echo "------------------------------------------"
$COMPOSE_CMD -f "$SCRIPT_DIR/../docker/compose.test.yml" run --rm nextcloud-test

EXIT_CODE=$?

echo ""
echo "------------------------------------------"
if [ $EXIT_CODE -eq 0 ]; then
    echo "All tests passed!"
else
    echo "Tests failed with exit code: $EXIT_CODE"
fi

exit $EXIT_CODE
