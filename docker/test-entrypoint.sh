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

# Check if Nextcloud is already installed
if su -s /bin/bash www-data -c "php occ status" 2>/dev/null | grep -q "installed: true"; then
    echo "Nextcloud is already installed, skipping installation..."
elif [ -f /var/www/html/config/config.php ] && grep -q "'installed' => true" /var/www/html/config/config.php 2>/dev/null; then
    echo "Nextcloud config indicates installed, skipping installation..."
else
    echo "Running initial Nextcloud installation..."
    # Try installation with error handling
    if ! su -s /bin/bash www-data -c "php occ maintenance:install \
        --database='mysql' \
        --database-host='${DB_HOST}' \
        --database-port='${DB_PORT}' \
        --database-name='${DB_NAME}' \
        --database-user='${DB_USER}' \
        --database-pass='${DB_PASS}' \
        --admin-user='admin' \
        --admin-pass='admin'" 2>&1; then
        echo "Installation command failed, checking if already installed..."
        if ! su -s /bin/bash www-data -c "php occ status" 2>/dev/null | grep -q "installed: true"; then
            echo "Nextcloud installation failed and not already installed"
            exit 1
        fi
    fi
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

echo "Ensuring realistic GUI test users..."
ensure_user() {
    local user_id="$1"
    local user_pass="$2"
    local display_name="$3"

    if su -s /bin/bash www-data -c "php occ user:info '${user_id}'" >/dev/null 2>&1; then
        echo "User '${user_id}' already exists."
        return 0
    fi

    su -s /bin/bash www-data -c "OC_PASS='${user_pass}' php occ user:add --password-from-env --display-name='${display_name}' '${user_id}'"
    echo "Created user '${user_id}' (${display_name})."
}

ensure_user "adult" "Adult123!" "Adult User"
ensure_user "kid" "Kid123!" "Kid User"

# Wait for Nextcloud to be fully ready via HTTP
echo "Waiting for Nextcloud HTTP interface..."
for i in {1..60}; do
    if curl -s http://localhost:80/status.php | grep -q '"installed":true'; then
        echo "Nextcloud HTTP interface is ready!"
        break
    fi
    echo "Nextcloud HTTP not ready yet... ($i/60)"
    sleep 2
done

# Seed test data for E2E tests
echo "Seeding test data..."
if [ -f /seed-test-data.sh ]; then
    chmod +x /seed-test-data.sh
    /seed-test-data.sh || echo "Seed script failed, continuing anyway"
fi

echo "=== Setup Complete ==="
echo "ChoreStrike is ready at http://localhost:8080"
echo "Login: admin / admin"
echo "Additional realistic GUI users: adult / Adult123!, kid / Kid123!"

# Keep Apache running
wait $APACHE_PID
