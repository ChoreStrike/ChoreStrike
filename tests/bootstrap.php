<?php

/**
 * PHPUnit Test Bootstrap for ChoreStrike
 *
 * This bootstrap file sets up the Nextcloud environment for testing.
 * It loads the necessary autoloaders and Nextcloud core classes.
 */

// Determine environment (Docker vs local)
$isDocker = getenv('NEXTCLOUD_TEST_ENV') === 'true' || file_exists('/.dockerenv');

if ($isDocker) {
    // Docker environment - Nextcloud core is cloned in /var/www/html
    $nextcloudBase = '/var/www/html';

    // Load Nextcloud's public API autoloader
    // The OCP classes are in /var/www/html/lib/public/
    spl_autoload_register(function ($class) {
        if (strpos($class, 'OCP\\') === 0) {
            $file = '/var/www/html/lib/public/' . str_replace('\\', '/', substr($class, 4)) . '.php';
            if (file_exists($file)) {
                require_once $file;
                return true;
            }
        }
        return false;
    });

    // Register ChoreStrike autoloader
    require_once __DIR__ . '/../vendor/autoload.php';
} else {
    // Local environment - only vendor autoloader available
    // Note: Tests requiring OCP\* classes will fail in this mode
    require_once __DIR__ . '/../vendor/autoload.php';
}

// Define test constants
define('CHORESTRIKE_TEST_MODE', true);

// Set error reporting for tests
error_reporting(E_ALL);
ini_set('display_errors', '1');
