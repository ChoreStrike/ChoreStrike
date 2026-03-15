<?php

/**
 * PHPUnit Integration Test Bootstrap for ChoreStrike
 *
 * This bootstrap file sets up the full Nextcloud environment for integration tests.
 * It requires a running Nextcloud instance with database access.
 */

// Only for Docker environment with full Nextcloud
$isDocker = getenv('NEXTCLOUD_TEST_ENV') === 'true' || file_exists('/.dockerenv');

if (!$isDocker) {
    echo "Integration tests require Docker environment with full Nextcloud\n";
    exit(1);
}

// Load Nextcloud core - this initializes OC::$server
$nextcloudBaseDir = '/var/www/html';

// Load Nextcloud base which sets up OC::$server
require_once $nextcloudBaseDir . '/lib/base.php';

// Load ChoreStrike autoloader
require_once __DIR__ . '/../vendor/autoload.php';

// Define test constants
define('CHORESTRIKE_TEST_MODE', true);
define('CHORESTRIKE_INTEGRATION_TEST', true);

// Set error reporting for tests
error_reporting(E_ALL);
ini_set('display_errors', '1');
