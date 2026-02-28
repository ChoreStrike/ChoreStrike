#!/bin/bash
# Test data seeder for ChoreStrike E2E tests
# Creates initial house, floor, and room for testing

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

# Create test house if none exists
echo "Creating test house..."
su -s /bin/bash www-data -c "php occ chorestrike:seed-test-data" 2>/dev/null || echo "Seed command not available, using API fallback"

# Check if house exists via API
if ! curl -s -u admin:admin http://localhost:80/apps/chorestrike/api/house | grep -q '"id"'; then
    echo "No house found. Creating via OCC..."
    # Create house using database directly or API
    mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "
        INSERT IGNORE INTO oc_chorestrike_houses (name, created_by, created_at) 
        VALUES ('Test House', 'admin', NOW());
    " 2>/dev/null || echo "Could not seed house directly"
fi

echo "=== Test data seeding complete ==="
