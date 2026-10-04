<?php

/**
 * Bootstrap for Entity Unit Tests
 * Uses mocks for OCP classes to avoid Nextcloud dependencies
 */

// Load OCP mocks first
require_once __DIR__ . '/__mocks__/Doctrine/DBAL/ParameterType.php';
require_once __DIR__ . '/__mocks__/Doctrine/DBAL/ArrayParameterType.php';
require_once __DIR__ . '/__mocks__/Doctrine/DBAL/Query/Expression/ExpressionBuilder.php';
require_once __DIR__ . '/__mocks__/OCP/AppFramework/Controller.php';
require_once __DIR__ . '/__mocks__/OCP/AppFramework/Http.php';
require_once __DIR__ . '/__mocks__/OCP/AppFramework/Http/NotFoundException.php';
require_once __DIR__ . '/__mocks__/OCP/AppFramework/Http/Attribute/ARateLimit.php';
require_once __DIR__ . '/__mocks__/OCP/AppFramework/Http/Attribute/UserRateLimit.php';
require_once __DIR__ . '/__mocks__/OCP/AppFramework/Http/ForbiddenException.php';
require_once __DIR__ . '/__mocks__/OCP/AppFramework/Http/JSONResponse.php';
require_once __DIR__ . '/__mocks__/OCP/AppFramework/Http/DataResponse.php';
require_once __DIR__ . '/__mocks__/OCP/AppFramework/Db/Entity.php';
require_once __DIR__ . '/__mocks__/OCP/AppFramework/Db/QBMapper.php';
require_once __DIR__ . '/__mocks__/OCP/AppFramework/Db/DoesNotExistException.php';
require_once __DIR__ . '/__mocks__/OCP/IDBConnection.php';
require_once __DIR__ . '/__mocks__/OCP/DB/IResult.php';
require_once __DIR__ . '/__mocks__/OCP/DB/Exception.php';
require_once __DIR__ . '/__mocks__/OCP/DB/QueryBuilder/IQueryBuilder.php';
require_once __DIR__ . '/__mocks__/OCP/DB/QueryBuilder/IFunctionBuilder.php';
require_once __DIR__ . '/__mocks__/OCP/DB/QueryBuilder/IExpression.php';
require_once __DIR__ . '/__mocks__/OCP/DB/QueryBuilder/IExpressionBuilder.php';
require_once __DIR__ . '/__mocks__/OCP/DB/ISchemaWrapper.php';
require_once __DIR__ . '/__mocks__/OCP/Migration/IOutput.php';
require_once __DIR__ . '/__mocks__/OCP/Migration/SimpleMigrationStep.php';
require_once __DIR__ . '/__mocks__/OCP/Log/ILogFactory.php';
require_once __DIR__ . '/__mocks__/OCP/IConfig.php';
require_once __DIR__ . '/__mocks__/OCP/IAppConfig.php';
require_once __DIR__ . '/__mocks__/OCP/ILogger.php';
require_once __DIR__ . '/__mocks__/OCP/IL10N.php';
require_once __DIR__ . '/__mocks__/OCP/IUserManager.php';
require_once __DIR__ . '/__mocks__/OCP/IRequest.php';
require_once __DIR__ . '/__mocks__/OCP/IUser.php';
require_once __DIR__ . '/__mocks__/OCP/IUserSession.php';
require_once __DIR__ . '/__mocks__/OCP/AppFramework/Utility/ITimeFactory.php';
require_once __DIR__ . '/__mocks__/OCP/IURLGenerator.php';
require_once __DIR__ . '/__mocks__/OCP/L10N/IFactory.php';
require_once __DIR__ . '/__mocks__/OCP/Notification/IManager.php';
require_once __DIR__ . '/__mocks__/OCP/Notification/INotification.php';
require_once __DIR__ . '/__mocks__/OCP/Notification/INotifier.php';
require_once __DIR__ . '/__mocks__/OCP/Notification/IAction.php';
require_once __DIR__ . '/__mocks__/OCP/BackgroundJob/TimedJob.php';
require_once __DIR__ . '/__mocks__/Psr/Log/LoggerInterface.php';
require_once __DIR__ . '/__mocks__/Symfony/Component/Console/Command/Command.php';
require_once __DIR__ . '/__mocks__/Symfony/Component/Console/Input/InputInterface.php';
require_once __DIR__ . '/__mocks__/Symfony/Component/Console/Input/InputArgument.php';
require_once __DIR__ . '/__mocks__/Symfony/Component/Console/Input/InputOption.php';
require_once __DIR__ . '/__mocks__/Symfony/Component/Console/Output/OutputInterface.php';

// Load vendor autoloader
require_once __DIR__ . '/../vendor/autoload.php';

// Define test constant
define('CHORESTRIKE_TEST_MODE', true);

// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', '1');
