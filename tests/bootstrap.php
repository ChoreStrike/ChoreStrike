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
    // Docker environment - load mocks FIRST to override Nextcloud classes
    require_once __DIR__ . '/__mocks__/Doctrine/DBAL/ParameterType.php';
    require_once __DIR__ . '/__mocks__/Doctrine/DBAL/ArrayParameterType.php';
    require_once __DIR__ . '/__mocks__/Doctrine/DBAL/Query/Expression/ExpressionBuilder.php';
    require_once __DIR__ . '/__mocks__/OCP/AppFramework/Controller.php';
    require_once __DIR__ . '/__mocks__/OCP/AppFramework/OCSController.php';
    require_once __DIR__ . '/__mocks__/OCP/AppFramework/Http.php';
    require_once __DIR__ . '/__mocks__/OCP/AppFramework/Http/NotFoundException.php';
    require_once __DIR__ . '/__mocks__/OCP/AppFramework/Http/ForbiddenException.php';
    require_once __DIR__ . '/__mocks__/OCP/AppFramework/Http/JSONResponse.php';
    require_once __DIR__ . '/__mocks__/OCP/AppFramework/Http/DataResponse.php';
    require_once __DIR__ . '/__mocks__/OCP/AppFramework/Db/DoesNotExistException.php';
    require_once __DIR__ . '/__mocks__/OCP/AppFramework/Db/Entity.php';
    require_once __DIR__ . '/__mocks__/OCP/AppFramework/Db/QBMapper.php';
    require_once __DIR__ . '/__mocks__/OCP/ILogger.php';
    require_once __DIR__ . '/__mocks__/OCP/IL10N.php';
    require_once __DIR__ . '/__mocks__/OCP/IUserManager.php';
    require_once __DIR__ . '/__mocks__/OCP/DB/ISchemaWrapper.php';
    require_once __DIR__ . '/__mocks__/OCP/Migration/IOutput.php';
    require_once __DIR__ . '/__mocks__/OCP/Migration/SimpleMigrationStep.php';
    
    // Now load remaining OCP classes from Nextcloud
    $ocpClasses = [
        'OCP/DB/Exception.php',
        'OCP/DB/QueryBuilder/IQueryBuilder.php',
        'OCP/DB/QueryBuilder/IFunctionBuilder.php',
        'OCP/DB/QueryBuilder/ILiteral.php',
        'OCP/DB/QueryBuilder/IParameter.php',
        'OCP/DB/QueryBuilder/IFunction.php',
        'OCP/IDBConnection.php',
        'OCP/AppFramework/Db/DoesNotExistException.php',
        'OCP/AppFramework/Db/MultipleObjectsReturnedException.php',
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
    require_once __DIR__ . '/__mocks__/OCP/AppFramework/Controller.php';
    require_once __DIR__ . '/__mocks__/OCP/AppFramework/OCSController.php';
    require_once __DIR__ . '/__mocks__/OCP/AppFramework/Http.php';
    require_once __DIR__ . '/__mocks__/OCP/AppFramework/Http/NotFoundException.php';
    require_once __DIR__ . '/__mocks__/OCP/AppFramework/Http/ForbiddenException.php';
    require_once __DIR__ . '/__mocks__/OCP/AppFramework/Http/JSONResponse.php';
    require_once __DIR__ . '/__mocks__/OCP/AppFramework/Http/DataResponse.php';
    require_once __DIR__ . '/__mocks__/OCP/AppFramework/Db/DoesNotExistException.php';
    require_once __DIR__ . '/__mocks__/OCP/AppFramework/Db/Entity.php';
    require_once __DIR__ . '/__mocks__/OCP/AppFramework/Db/QBMapper.php';
    require_once __DIR__ . '/__mocks__/OCP/ILogger.php';
    require_once __DIR__ . '/__mocks__/OCP/IL10N.php';
    require_once __DIR__ . '/__mocks__/OCP/IUserManager.php';
    require_once __DIR__ . '/__mocks__/OCP/DB/ISchemaWrapper.php';
    require_once __DIR__ . '/__mocks__/OCP/Migration/IOutput.php';
    require_once __DIR__ . '/__mocks__/OCP/Migration/SimpleMigrationStep.php';
    require_once __DIR__ . '/__mocks__/OCP/IRequest.php';
    require_once __DIR__ . '/__mocks__/OCP/IUser.php';
    require_once __DIR__ . '/__mocks__/OCP/IUserSession.php';
    require_once __DIR__ . '/__mocks__/OCP/IDBConnection.php';
    require_once __DIR__ . '/__mocks__/OCP/ITimeFactory.php';
    
    // Load vendor autoloader
    require_once __DIR__ . '/../vendor/autoload.php';
}

// Define test constants
define('CHORESTRIKE_TEST_MODE', true);

// Set error reporting for tests
error_reporting(E_ALL);
ini_set('display_errors', '1');
