<?php

/**
 * Bootstrap for Entity Unit Tests
 * Uses mocks for OCP classes to avoid Nextcloud dependencies
 */

// Load OCP mocks first
require_once __DIR__ . '/__mocks__/Doctrine/DBAL/ParameterType.php';
require_once __DIR__ . '/__mocks__/Doctrine/DBAL/ArrayParameterType.php';
require_once __DIR__ . '/__mocks__/Doctrine/DBAL/Query/Expression/ExpressionBuilder.php';
require_once __DIR__ . '/__mocks__/OCP/AppFramework/Db/Entity.php';
require_once __DIR__ . '/__mocks__/OCP/AppFramework/Db/QBMapper.php';
require_once __DIR__ . '/__mocks__/OCP/AppFramework/Db/DoesNotExistException.php';
require_once __DIR__ . '/__mocks__/OCP/IDBConnection.php';
require_once __DIR__ . '/__mocks__/OCP/DB/QueryBuilder/IQueryBuilder.php';
require_once __DIR__ . '/__mocks__/OCP/ILogger.php';
require_once __DIR__ . '/__mocks__/OCP/IL10N.php';
require_once __DIR__ . '/__mocks__/OCP/IUserManager.php';
require_once __DIR__ . '/__mocks__/OCP/IRequest.php';
require_once __DIR__ . '/__mocks__/OCP/IUser.php';
require_once __DIR__ . '/__mocks__/OCP/IUserSession.php';
require_once __DIR__ . '/__mocks__/OCP/ITimeFactory.php';

// Load vendor autoloader
require_once __DIR__ . '/../vendor/autoload.php';

// Define test constant
define('CHORESTRIKE_TEST_MODE', true);

// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', '1');
