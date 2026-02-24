<?php

/**
 * Bootstrap for Entity Unit Tests
 * Uses mocks for OCP classes to avoid Nextcloud dependencies
 */

// Load OCP mocks first
require_once __DIR__ . '/__mocks__/OCP/AppFramework/Db/Entity.php';

// Load vendor autoloader
require_once __DIR__ . '/../vendor/autoload.php';

// Define test constant
define('CHORESTRIKE_TEST_MODE', true);

// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', '1');
