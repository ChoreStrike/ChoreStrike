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
    $ocpBase = '/var/www/html/lib/public';
    $privateBase = '/var/www/html/lib/private';

    // Load all OCP\DB classes first (dependencies for QBMapper)
    $dbClasses = [
        'OCP/DB/Exception.php',
        'OCP/DB/QueryBuilder/IQueryBuilder.php',
        'OCP/DB/QueryBuilder/IFunctionBuilder.php',
        'OCP/DB/QueryBuilder/ILiteral.php',
        'OCP/DB/QueryBuilder/IParameter.php',
        'OCP/DB/QueryBuilder/IFunction.php',
        'OCP/IDBConnection.php',
        'OCP/AppFramework/Db/Entity.php',
        'OCP/AppFramework/Db/DoesNotExistException.php',
        'OCP/AppFramework/Db/MultipleObjectsReturnedException.php',
        'OCP/AppFramework/Db/QBMapper.php',
        'OCP/IRequest.php',
        'OCP/IUserSession.php',
        'OCP/IUser.php',
    ];

    foreach ($dbClasses as $file) {
        $fullPath = "/var/www/html/lib/public/$file";
        if (file_exists($fullPath)) {
            require_once $fullPath;
        }
    }

    // Register ChoreStrike autoloader
    require_once __DIR__ . '/../vendor/autoload.php';

    // Register fallback autoloader for remaining OCP classes
    spl_autoload_register(function ($class) {
        if (strpos($class, 'OCP\\') === 0) {
            $file = '/var/www/html/lib/public/' . str_replace('\\', '/', substr($class, 4)) . '.php';
            if (file_exists($file)) {
                require_once $file;
                return true;
            }
        }
        return false;
    }, true, true);
} else {
    // Local environment - load mocks first
    require_once __DIR__ . '/__mocks__/OCP/AppFramework/Db/Entity.php';
    require_once __DIR__ . '/__mocks__/OCP/AppFramework/Db/QBMapper.php';
    require_once __DIR__ . '/__mocks__/OCP/IRequest.php';
    require_once __DIR__ . '/__mocks__/OCP/IDBConnection.php';
    
    // Load vendor autoloader
    require_once __DIR__ . '/../vendor/autoload.php';
}

// Define test constants
define('CHORESTRIKE_TEST_MODE', true);

// Set error reporting for tests
error_reporting(E_ALL);
ini_set('display_errors', '1');
