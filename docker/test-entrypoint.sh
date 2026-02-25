#!/bin/bash
set -e

echo "=== ChoreStrike Docker Test Environment ==="
echo "Setting up test environment..."

# Wait for database to be ready
echo "Waiting for database..."
until mysqladmin ping -h"$DB_HOST" --silent; do
    echo "Database not ready yet... waiting"
    sleep 2
done
echo "Database is ready!"

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
