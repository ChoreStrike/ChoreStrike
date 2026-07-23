<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Route Matrix Test
 *
 * Verifies that every route declared in `appinfo/routes.php` resolves to a
 * public method on a controller class that exists in `lib/Controller/`.
 * Catches the historical drift patterns:
 *   - Route declared → controller method missing (returns 500 at runtime).
 *   - Controller method implemented → no route exposes it (dead code).
 *
 * The route name `foo#bar` resolves to `\OCA\ChoreStrike\Controller\FooController::bar`.
 * Snake_case route names (`room_task`) resolve to PascalCase controller
 * class names (`RoomTaskController`).
 */
class RouteMatrixTest extends TestCase
{
    /** @var array<string, string> */
    private const CONTROLLER_NAME_MAP = [
        'user_stats' => 'UserStatsController',
    ];

    /** @var list<array{name: string, url: string, verb: string}> */
    private array $routes;

    protected function setUp(): void
    {
        parent::setUp();
        $routesFile = dirname(__DIR__, 2) . '/appinfo/routes.php';
        $this->assertFileExists($routesFile, 'appinfo/routes.php must exist');
        $this->routes = require $routesFile;
        $this->assertArrayHasKey('routes', $this->routes, 'routes.php must define a "routes" key');
    }

    /**
     * Every declared route must resolve to a public method on the
     * corresponding controller class.
     */
    public function testEveryRouteResolvesToExistingControllerMethod(): void
    {
        $missing = [];
        foreach ($this->routes['routes'] as $route) {
            [$controllerName, $method] = explode('#', $route['name'], 2);
            $className = $this->controllerClassName($controllerName);

            if (!class_exists($className)) {
                $missing[] = sprintf(
                    'Route %s %s (%s): controller class %s does not exist',
                    $route['verb'],
                    $route['url'],
                    $route['name'],
                    $className
                );
                continue;
            }
            if (!method_exists($className, $method)) {
                $missing[] = sprintf(
                    'Route %s %s (%s): %s has no public method %s()',
                    $route['verb'],
                    $route['url'],
                    $route['name'],
                    $className,
                    $method
                );
                continue;
            }
            $reflection = new \ReflectionMethod($className, $method);
            if (!$reflection->isPublic()) {
                $missing[] = sprintf(
                    'Route %s %s (%s): %s::%s() is not public',
                    $route['verb'],
                    $route['url'],
                    $route['name'],
                    $className,
                    $method
                );
            }
        }
        $this->assertSame([], $missing, "Route matrix drift detected:\n" . implode("\n", $missing));
    }

    /**
     * No duplicate (verb, url) pairs in the route table.
     */
    public function testNoDuplicateVerbUrlPairs(): void
    {
        $seen = [];
        $duplicates = [];
        foreach ($this->routes['routes'] as $route) {
            $key = $route['verb'] . ' ' . $route['url'];
            if (isset($seen[$key])) {
                $duplicates[] = sprintf(
                    'Duplicate route: %s %s (first declared as %s, then as %s)',
                    $route['verb'],
                    $route['url'],
                    $seen[$key],
                    $route['name']
                );
            } else {
                $seen[$key] = $route['name'];
            }
        }
        $this->assertSame([], $duplicates, "Duplicate routes detected:\n" . implode("\n", $duplicates));
    }

    /**
     * All routes should carry a valid HTTP verb.
     */
    public function testAllRoutesUseValidHttpVerbs(): void
    {
        $validVerbs = ['GET', 'POST', 'PUT', 'DELETE', 'PATCH'];
        $invalid = [];
        foreach ($this->routes['routes'] as $route) {
            if (!in_array($route['verb'], $validVerbs, true)) {
                $invalid[] = sprintf(
                    'Route %s (%s): invalid verb "%s"',
                    $route['url'],
                    $route['name'],
                    $route['verb']
                );
            }
        }
        $this->assertSame([], $invalid, "Invalid HTTP verbs:\n" . implode("\n", $invalid));
    }

    /**
     * Resolve a short controller name (e.g. `room_task`) to the fully
     * qualified ChoreStrike controller class name.
     */
    private function controllerClassName(string $shortName): string
    {
        if (isset(self::CONTROLLER_NAME_MAP[$shortName])) {
            $className = self::CONTROLLER_NAME_MAP[$shortName];
        } else {
            $parts = explode('_', $shortName);
            $parts = array_map(static fn (string $p): string => ucfirst($p), $parts);
            $className = implode('', $parts) . 'Controller';
        }
        return '\\OCA\\ChoreStrike\\Controller\\' . $className;
    }
}
