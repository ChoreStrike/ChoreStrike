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
class RouteMatrixTest extends TestCase {
	/**
	 * Overrides for snake_case route names that don't PascalCase-convert to
	 * their controller class name. `user_stats` → `UserStatsController` was
	 * removed when that dead controller was archived (CS-PLAN-010); no route
	 * uses it. Keep this map for future non-conforming snake_case names.
	 *
	 * @var array<string, string>
	 */
	private const CONTROLLER_NAME_MAP = [];

	/** @var list<array{name: string, url: string, verb: string}> */
	private array $routes;

	protected function setUp(): void {
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
	public function testEveryRouteResolvesToExistingControllerMethod(): void {
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
	public function testNoDuplicateVerbUrlPairs(): void {
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
	public function testAllRoutesUseValidHttpVerbs(): void {
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
	 * A parameterized route (e.g. /api/v1/tasks/{id}) must not be declared
	 * BEFORE a static route it would swallow (e.g. /api/v1/tasks/daily):
	 * Nextcloud's router matches in registration order, so the later static
	 * route would 500 through the parameterized one (the CS-PLAN-023
	 * shadowing bug that broke /room-tasks/overdue, /tasks/daily, ...).
	 */
	public function testParameterizedRoutesDoNotShadowLaterStaticRoutes(): void {
		$shadowed = [];
		$routes = $this->routes['routes'];
		$count = count($routes);

		for ($i = 0; $i < $count; $i++) {
			$parameterized = $routes[$i];
			if (!str_contains($parameterized['url'], '{')) {
				continue;
			}

			// Compile the parameterized URL to a pattern: each {param}
			// matches exactly one static path segment.
			$pattern = '#^' . preg_replace('/\{[^}]+\}/', '[^/]+', $parameterized['url']) . '$#';

			for ($j = $i + 1; $j < $count; $j++) {
				$candidate = $routes[$j];
				if ($candidate['verb'] !== $parameterized['verb']) {
					continue;
				}
				if (str_contains($candidate['url'], '{')) {
					continue;
				}
				if (preg_match($pattern, $candidate['url']) === 1) {
					$shadowed[] = sprintf(
						'%s %s (%s) is shadowed by the earlier %s %s (%s)',
						$candidate['verb'],
						$candidate['url'],
						$candidate['name'],
						$parameterized['verb'],
						$parameterized['url'],
						$parameterized['name']
					);
				}
			}
		}

		$this->assertSame(
			[],
			$shadowed,
			"Route shadowing detected — move the static routes before the parameterized ones:\n"
				. implode("\n", $shadowed)
		);
	}

	/**
	 * Routes pruned in CS-PLAN-040 must stay gone: the legacy single-assignee
	 * `userTasks` read, the CR-SVC-31-flawed `userStats`, and the fully dead
	 * `user#stats`. Re-adding any of them requires a new expose decision.
	 */
	public function testPrunedStatsRoutesAreNotReintroduced(): void {
		$pruned = [
			'GET /api/v1/room-tasks/user/{userId}',
			'GET /api/v1/room-tasks/user/{userId}/stats',
			'GET /api/v1/users/{id}/stats',
		];
		$declared = array_map(
			static fn (array $route): string => $route['verb'] . ' ' . $route['url'],
			$this->routes['routes']
		);

		$reintroduced = array_intersect($pruned, $declared);
		$this->assertSame(
			[],
			array_values($reintroduced),
			'Pruned stats endpoints reappeared in routes.php — see CS-PLAN-040 §2'
		);
	}

	/**
	 * The 39 routes pruned in CS-PLAN-055 must stay gone: dead create
	 * aliases, no-consumer reads/writes, dead room-task variants, the whole
	 * task-sync and reward-config HTTP surfaces, and the test-only
	 * lifecycle/leaderboard routes. Re-adding one requires a consumer and a
	 * new expose decision.
	 */
	public function testPrunedCS055RoutesAreNotReintroduced(): void {
		$pruned = [
			'POST /api/v1/floor-create',
			'POST /api/v1/room-create',
			'POST /api/v1/floors/create',
			'POST /api/v1/rooms/create',
			'GET /api/v1/floors',
			'GET /api/v1/floors/{id}',
			'GET /api/v1/rooms',
			'GET /api/v1/rooms/{id}',
			'GET /api/v1/tasks/{id}',
			'GET /api/v1/room-tasks/{id}',
			'GET /api/v1/room-tasks/due-within-days',
			'POST /api/v1/room-tasks/{id}/assign',
			'POST /api/v1/room-tasks/{id}/unassign',
			'POST /api/v1/room-tasks/{id}/assignees',
			'DELETE /api/v1/room-tasks/{id}/assignees',
			'GET /api/v1/room-tasks/overdue-claims',
			'GET /api/v1/tasks/category/{category}',
			'GET /api/v1/tasks/search',
			'GET /api/v1/tasks/categories',
			'GET /api/v1/tasks/my-private',
			'DELETE /api/v1/tasks/{id}',
			'PUT /api/v1/users/{id}',
			'GET /api/v1/rooms/needing-cleaning',
			'POST /api/v1/checklists/initialize/{roomTaskId}',
			'GET /api/v1/checklists/complete/{roomTaskId}',
			'POST /api/v1/task-sync/complete',
			'POST /api/v1/task-sync/sync-from-nextcloud',
			'GET /api/v1/task-sync/{roomTaskId}/reminders',
			'GET /api/v1/task-sync/pending',
			'POST /api/v1/task-sync/{completedTaskId}/mark-synced',
			'POST /api/v1/task-sync/link',
			'PUT /api/v1/tasks/{id}/complete',
			'PUT /api/v1/tasks/{id}/skip',
			'PUT /api/v1/tasks/{id}/reschedule',
			'GET /api/v1/tasks/daily',
			'POST /api/v1/room-tasks/{id}/deactivate',
			'GET /api/v1/game/leaderboard',
			'GET /api/v1/reward-config',
			'PUT /api/v1/reward-config',
		];
		$declared = array_map(
			static fn (array $route): string => $route['verb'] . ' ' . $route['url'],
			$this->routes['routes']
		);

		$reintroduced = array_intersect($pruned, $declared);
		$this->assertCount(39, $pruned, 'CS-PLAN-055 disposition list must carry exactly 39 routes');
		$this->assertSame(
			[],
			array_values($reintroduced),
			'CS-PLAN-055 pruned routes reappeared in routes.php — re-adding requires a consumer'
		);
	}

	/**
	 * Route count pin (CS-PLAN-055): 71 declared routes = 67 production +
	 * 3 `/test/*` harness + `page#index`. CS-PLAN-063 pruned `POST /tasks`
	 * (task#create) → 70. CS-PLAN-064 pruned `PUT /tasks/{id}/uncomplete`
	 * and `POST /room-tasks/{id}/reactivate`, added the undo aggregate
	 * `POST /room-tasks/{id}/undo` → 69. CS-PLAN-065 replaced the advice
	 * contract on `POST /scheduling/suggestions` (preview) and added the
	 * atomic `POST /scheduling/apply` → 70. CS-PLAN-073 WP2 added the
	 * occurrence agreement commands (amend/catch-up/restart) and the
	 * revisioned pause lifecycle → 79. CS-PLAN-070 WP3 retired the
	 * latest-by-room-task undo (`POST /room-tasks/{id}/undo`) and the
	 * arcade coin/boost economy routes in favor of the targeted
	 * completion correction trio (GET /completions/{id}, POST
	 * /completions/{id}/undo, PUT /completions/{id}/attribution) → 78.
	 * CS-PLAN-070 WP4 retired `PUT /users/{id}/titles/select` and added
	 * the consent-filtered ranking trio (GET /ranking, POST
	 * /ranking/consent, POST /ranking/withdraw) → 80.
	 * New routes must land with a consumer (see the prune guards above) —
	 * the count only moves when an expose decision updates this pin
	 * deliberately.
	 */
	public function testRouteCountIsPinned(): void {
		$this->assertCount(
			80,
			$this->routes['routes'],
			'Route count drifted from the pin (80 after CS-PLAN-070 WP4) — add only with a consumer, then update deliberately'
		);
	}

	/**
	 * The split-undo chain pruned by CS-PLAN-064 (decision D5) must stay
	 * gone, and the WP3 cutover replaced the latest-by-room-task undo
	 * aggregate with the targeted completion correction: the one idempotent
	 * undo operation is POST /completions/{id}/undo (exact completion id).
	 * Re-adding either legacy route requires a new expose decision.
	 */
	public function testPrunedUndoChainRoutesAreNotReintroduced(): void {
		$declared = array_map(
			static fn (array $route): string => $route['verb'] . ' ' . $route['url'],
			$this->routes['routes']
		);

		$this->assertContains('GET /api/v1/completions/{id}', $declared, 'The completion read route must exist');
		$this->assertContains('POST /api/v1/completions/{id}/undo', $declared, 'The targeted undo route must exist');
		$this->assertContains('PUT /api/v1/completions/{id}/attribution', $declared, 'The attribution route must exist');
		$this->assertNotContains(
			'POST /api/v1/room-tasks/{id}/undo',
			$declared,
			'CS-PLAN-070 WP3 retired the latest-by-room-task undo — use POST /completions/{id}/undo'
		);
		$this->assertNotContains(
			'PUT /api/v1/tasks/{id}/uncomplete',
			$declared,
			'CS-PLAN-064 pruned PUT /tasks/{id}/uncomplete — use POST /completions/{id}/undo'
		);
		$this->assertNotContains(
			'POST /api/v1/room-tasks/{id}/reactivate',
			$declared,
			'CS-PLAN-064 pruned POST /room-tasks/{id}/reactivate — undo is correction, not reactivation'
		);
	}

	/**
	 * The definition-only create endpoint pruned by CS-PLAN-063 must stay
	 * gone: creation runs exclusively through POST /room-tasks (custom /
	 * mount variants) and POST /room-tasks/from-template. Re-adding it
	 * requires a consumer and a new expose decision.
	 */
	public function testPrunedDefinitionCreateRouteIsNotReintroduced(): void {
		$declared = array_map(
			static fn (array $route): string => $route['verb'] . ' ' . $route['url'],
			$this->routes['routes']
		);

		$this->assertNotContains(
			'POST /api/v1/tasks',
			$declared,
			'CS-PLAN-063 pruned POST /tasks (task#create) — use POST /room-tasks (creation aggregate)'
		);
	}

	/**
	 * CS-PLAN-070 WP4 (Q-075-02 A / Q-075-01 A): the multi-pack title
	 * selection route is retired — `currentTitle` is always the highest
	 * qualifying retained tier — and the consent-filtered ranking trio is
	 * the only ranking surface (no client-side leaderboard route returns).
	 */
	public function testRetiredTitleSelectRouteStaysGoneAndRankingTrioExists(): void {
		$declared = array_map(
			static fn (array $route): string => $route['verb'] . ' ' . $route['url'],
			$this->routes['routes']
		);

		$this->assertNotContains(
			'PUT /api/v1/users/{id}/titles/select',
			$declared,
			'CS-PLAN-070 WP4 retired the title pack selection — currentTitle derives from the valid-history maximum'
		);
		$this->assertContains('GET /api/v1/ranking', $declared, 'The consent-filtered ranking read must exist');
		$this->assertContains('POST /api/v1/ranking/consent', $declared, 'The self-only consent mutation must exist');
		$this->assertContains('POST /api/v1/ranking/withdraw', $declared, 'The self-only withdrawal mutation must exist');
		$this->assertNotContains(
			'GET /api/v1/game/leaderboard',
			$declared,
			'The unconditional game leaderboard route stays pruned'
		);
	}

	/**
	 * Resolve a short controller name (e.g. `room_task`) to the fully
	 * qualified ChoreStrike controller class name.
	 */
	private function controllerClassName(string $shortName): string {
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
