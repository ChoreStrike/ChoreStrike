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
    // Manually load required OCP classes in correct order
    
    // Load Doctrine DBAL mocks first (required by IQueryBuilder)
    require_once __DIR__ . '/__mocks__/Doctrine/DBAL/ParameterType.php';
    require_once __DIR__ . '/__mocks__/Doctrine/DBAL/ArrayParameterType.php';
    require_once __DIR__ . '/__mocks__/Doctrine/DBAL/Query/Expression/ExpressionBuilder.php';
    
    $ocpClasses = [
        // DB interfaces first
        'OCP/DB/Exception.php',
        'OCP/DB/QueryBuilder/IQueryBuilder.php',
        'OCP/DB/QueryBuilder/IFunctionBuilder.php',
        'OCP/DB/QueryBuilder/ILiteral.php',
        'OCP/DB/QueryBuilder/IParameter.php',
        'OCP/DB/QueryBuilder/IFunction.php',
        'OCP/IDBConnection.php',
        // AppFramework Db exceptions (not Entity/QBMapper - we use mocks)
        'OCP/AppFramework/Db/DoesNotExistException.php',
        'OCP/AppFramework/Db/MultipleObjectsReturnedException.php',
        // Other OCP interfaces
        'OCP/IRequest.php',
        'OCP/IUserSession.php',
        'OCP/IUser.php',
        'OCP/IServerContainer.php',
        'OCP/ITimeFactory.php',
    ];
    
    foreach ($ocpClasses as $class) {
        $file = "/var/www/html/lib/public/$class";
        if (file_exists($file)) {
            require_once $file;
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
    require_once __DIR__ . '/__mocks__/Doctrine/DBAL/ParameterType.php';
    require_once __DIR__ . '/__mocks__/Doctrine/DBAL/ArrayParameterType.php';
    require_once __DIR__ . '/__mocks__/Doctrine/DBAL/Query/Expression/ExpressionBuilder.php';
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
