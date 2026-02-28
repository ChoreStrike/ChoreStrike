#!/bin/bash
set -euo pipefail

echo "=== ChoreStrike Docker Test Environment ==="
echo "Starting Apache immediately..."

# Start Apache in background first
apache2-foreground &
APACHE_PID=$!

# Wait for database in background
echo "Waiting for database..."
DB_REACHABLE=false
for i in {1..60}; do
    if mysqladmin ping -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" --skip-ssl --silent >/dev/null 2>&1; then
        echo "Database is reachable!"
        DB_REACHABLE=true
        break
    fi
    echo "Database not ready yet... waiting ($i/60)"
    sleep 1
done

if [ "$DB_REACHABLE" != true ]; then
    echo "Database is not reachable after 60 seconds. Aborting setup."
    exit 1
fi

# Verify database connection
echo "Verifying database connection..."
DB_AUTH_OK=false
for i in {1..30}; do
    if mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" --skip-ssl -e "SELECT 1" >/dev/null 2>&1; then
        echo "Database connection verified!"
        DB_AUTH_OK=true
        break
    fi
    echo "Database auth pending... ($i/30)"
    sleep 1
done

if [ "$DB_AUTH_OK" != true ]; then
    echo "Database authentication failed after 30 seconds. Aborting setup."
    exit 1
fi

# Install dependencies
echo "Installing Composer dependencies..."
composer install --no-interaction --prefer-dist

# Install Nextcloud (if needed) and enable app
echo "Preparing Nextcloud installation..."
cd /var/www/html

if ! su -s /bin/bash www-data -c "php occ status" 2>/dev/null | grep -q "installed: true"; then
    echo "Running initial Nextcloud installation..."
    su -s /bin/bash www-data -c "php occ maintenance:install \
        --database='mysql' \
        --database-host='${DB_HOST}' \
        --database-port='${DB_PORT}' \
        --database-name='${DB_NAME}' \
        --database-user='${DB_USER}' \
        --database-pass='${DB_PASS}' \
        --admin-user='admin' \
        --admin-pass='admin'"
fi

if ! su -s /bin/bash www-data -c "php occ status" 2>/dev/null | grep -q "installed: true"; then
    echo "Nextcloud installation failed"
    exit 1
fi

echo "Configuring trusted domains and URL..."
su -s /bin/bash www-data -c "php occ config:system:set trusted_domains 1 --value=localhost:8080"
su -s /bin/bash www-data -c "php occ config:system:set overwrite.cli.url --value=http://localhost:8080"

echo "Linking local chorestrike app into Nextcloud apps path..."
ln -sfn /var/www/html/custom_apps/chorestrike /var/www/html/apps/chorestrike
chown -h www-data:www-data /var/www/html/apps/chorestrike

echo "Enabling chorestrike app..."
su -s /bin/bash www-data -c "php occ app:enable chorestrike"

if ! su -s /bin/bash www-data -c "php occ app:list" | grep -A999 '^Enabled:' | grep -q '^  - chorestrike:'; then
    echo "ChoreStrike app is not enabled. Aborting setup."
    exit 1
fi

echo "=== Setup Complete ==="
echo "ChoreStrike is ready at http://localhost:8080"

# Keep Apache running
wait $APACHE_PID
