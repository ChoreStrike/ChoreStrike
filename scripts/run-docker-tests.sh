#!/bin/bash

# Docker Test Runner for ChoreStrike
# Usage: ./scripts/run-docker-tests.sh [unit|integration|all]

set -e

TEST_TYPE=${1:-all}

echo "=== ChoreStrike Docker Test Runner ==="
echo "Test type: $TEST_TYPE"
echo ""

# Check if Docker is available
if ! command -v docker &> /dev/null; then
    echo "ERROR: Docker is not installed or not in PATH"
    exit 1
fi

if ! command -v docker-compose &> /dev/null; then
    echo "ERROR: docker-compose is not installed or not in PATH"
    exit 1
fi

# Build and run tests
echo "Building Docker images..."
docker-compose -f docker-compose.test.yml build --no-cache

echo ""
echo "Starting test environment..."
docker-compose -f docker-compose.test.yml up -d db

# Wait for DB
echo "Waiting for database to be ready..."
sleep 5

echo ""
echo "Running tests..."

if [ "$TEST_TYPE" == "unit" ]; then
    docker-compose -f docker-compose.test.yml run --rm nextcloud-test \
        sh -c "composer install --no-interaction && vendor/bin/phpunit --testsuite Unit"
elif [ "$TEST_TYPE" == "integration" ]; then
    docker-compose -f docker-compose.test.yml run --rm nextcloud-test \
        sh -c "composer install --no-interaction && vendor/bin/phpunit --testsuite Integration"
else
    docker-compose -f docker-compose.test.yml run --rm nextcloud-test
fi

# Cleanup
echo ""
echo "Cleaning up..."
docker-compose -f docker-compose.test.yml down -v

echo ""
echo "=== Tests Complete ==="
