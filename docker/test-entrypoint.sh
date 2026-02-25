#!/bin/bash
set -e

echo "=== ChoreStrike Docker Test Environment ==="
echo "Setting up test environment..."

# Wait for database to be ready
echo "Waiting for database..."
for i in {1..30}; do
    if nc -z "$DB_HOST" 3306 2>/dev/null; then
        echo "Database port is open!"
        break
    fi
    echo "Database not ready yet... waiting"
    sleep 2
done

# Verify we can actually connect with credentials
echo "Verifying database connection..."
for i in {1..10}; do
    if mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" -e "SELECT 1" >/dev/null 2>&1; then
        echo "Database connection verified!"
        break
    fi
    echo "Database authentication pending... waiting"
    sleep 2
done

# Install dependencies
echo "Installing Composer dependencies..."
composer install --no-interaction --prefer-dist

# Setup Nextcloud configuration if not exists
if [ ! -f /var/www/html/config/config.php ]; then
    echo "Setting up Nextcloud configuration..."
    mkdir -p /var/www/html/config
    cat > /var/www/html/config/config.php << 'EOF'
<?php
$CONFIG = array (
  'instanceid' => 'oc1234567890',
  'passwordsalt' => 'testsalt1234567890',
  'secret' => 'testsecret12345678901234567890',
  'trusted_domains' => 
  array (
    0 => 'localhost',
  ),
  'datadirectory' => '/var/www/html/data',
  'dbtype' => 'mysql',
  'version' => '29.0.0.1',
  'overwrite.cli.url' => 'http://localhost',
  'dbname' => 'nextcloud',
  'dbhost' => 'db',
  'dbport' => '',
  'dbtableprefix' => 'oc_',
  'dbuser' => 'nextcloud',
  'dbpassword' => 'nextcloud',
  'installed' => true,
  'maintenance' => false,
  'theme' => '',
  'loglevel' => 0,
  'debug' => true,
);
EOF
fi

# Run PHPUnit tests
echo ""
echo "=== Running PHPUnit Tests ==="
vendor/bin/phpunit --configuration phpunit.xml.dist

echo ""
echo "=== Tests Complete ==="
