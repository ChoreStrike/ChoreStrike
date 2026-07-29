#!/bin/bash
# Test data seeder for ChoreStrike E2E tests
# Uses OCC command to seed data based on TEST_DB_PROFILE

set -e

echo "=== Seeding test data for ChoreStrike ==="

# Wait for Nextcloud to be fully ready
echo "Waiting for Nextcloud to be ready..."
for i in {1..30}; do
    if curl -s http://localhost:80/status.php | grep -q '"installed":true'; then
        echo "Nextcloud is ready!"
        break
    fi
    echo "Nextcloud not ready yet... ($i/30)"
    sleep 2
done

cd /var/www/html

# Get profile from environment or default to minimal
PROFILE="${TEST_DB_PROFILE:-minimal}"
echo "Seeding test data with profile: $PROFILE"

# Seed using OCC command (canonical path, CS-PLAN-019)
su -s /bin/bash www-data -c "cd /var/www/html && php occ chorestrike:test-data:seed --profile=$PROFILE"

echo "=== Test data seeding complete ==="
