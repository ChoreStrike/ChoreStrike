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
	 * /ranking/consent, POST /ranking/withdraw) → 80. CS-PLAN-070 WP5
	 * added the self-only preferences pair (GET/PUT
	 * /preferences/me) → 82. CS-PLAN-070 WP7 (CS-PLAN-078) added the
	 * owner-only catalog gate writer (PUT /house/catalog), the catalog
	 * trio (GET/POST /rewards, PUT /rewards/{id}) and the reward-request
	 * lifecycle octet (GET/POST /reward-requests, GET
	 * /reward-requests/{id}, POST /reward-requests/{id}/approve|reject|
	 * cancel|fulfill|correct) → 94. CS-PLAN-086 WP-B added the arcade
	 * catalog read (GET /minigames/catalog) and the owner-only arcade
	 * gate writer (PUT /house/arcade-gate) → 96. CS-PLAN-088 D-088-20
	 * added the "last done" writer (PUT /room-tasks/{id}/last-done,
	 * consumed by the room-task edit dialog) → 97. CS-PLAN-090 WP-A added
	 * the absence deletion (DELETE /pauses/{id}, ADR-020) → 98. CS-PLAN-090
	 * WP-I added the owner-only task-category writer (PUT
	 * /house/task-categories, consumed by the household settings) → 99.
	 * CS-PLAN-090 WP-J added the owner's arcade controls (PUT
	 * /house/arcade-thresholds, POST /house/members/{userId}/arcade-reset,
	 * GET /house/arcade-progress, consumed by the arcade settings) → 102.
	 * CS-PLAN-090 WP-K added the caller's own colour scheme (PUT
	 * /preferences/me/color-scheme, consumed by the appearance settings) → 103.
	 * CS-PLAN-091 WP-B1 (ADR-021, ADR-022) retired seven routes with the
	 * stored occurrences: the three agreement commands, the due-date and
	 * "last done" writers and the owner's preview and apply → 96.
	 * CS-PLAN-091 WP-B3 added the postpone command, the date correction and
	 * the history of one task, each consumed by the task row → 99.
	 * CS-PLAN-091 WP-B4 added the sleep command (the task row) and the bonus
	 * rows of Today, WP-B5 the owner's coin factor and the two bonus-period
	 * commands (the reward settings) → 104.
	 * CS-PLAN-091 WP-B6 added the one form's save (PUT /room-tasks/{id})
	 * and retired the editors it replaces: the definition update, the
	 * template create, the assignee read and write, bulk assignment and the
	 * four sub-task writers → 96.
	 * CS-PLAN-091 WP-B7 retired the two routes that had lost their client
	 * (D-089-09, D-090-10): the manual cleanliness delta and the task
	 * statistics of a room → 94, and the two others of those decisions
	 * (RF-091-11, D-090-10, D-090-38): shortening an absence and the house
	 * health refresh → 92.
	 * CS-PLAN-091 WP-C added the live suggestion (GET
	 * /scheduling/suggestion, consumed by the calendar; ADR-022) → 93.
	 * New routes must land with a consumer (see the prune guards above) —
	 * the count only moves when an expose decision updates this pin
	 * deliberately.
	 */
	public function testRouteCountIsPinned(): void {
		$this->assertCount(
			93,
			$this->routes['routes'],
			'Route count drifted from the pin (93 after CS-PLAN-091 WP-C) — add only with a consumer, then update deliberately'
		);
	}

	/**
	 * CS-PLAN-091 WP-B3 (ADR-021 decisions 6 and 9): the three routes of
	 * postponing and of the task history, as the API reference names them.
	 */
	public function testCycleCommandRoutesMatchTheContract(): void {
		$declared = [];
		foreach ($this->routes['routes'] as $route) {
			$declared[$route['name']] = $route['verb'] . ' ' . $route['url'];
		}
		$this->assertSame('POST /api/v1/room-tasks/{id}/postpone', $declared['room_task_cycle#postpone'] ?? null);
		$this->assertSame('GET /api/v1/room-tasks/{id}/history', $declared['room_task_cycle#history'] ?? null);
		$this->assertSame('PUT /api/v1/completions/{id}/date', $declared['completion#changeDate'] ?? null);
	}

	/**
	 * CS-PLAN-091 WP-B4 and WP-B5 (ADR-021 decisions 7, 8 and 11): the
	 * routes of sleeping, bonus tasks and the coin multipliers, as the API
	 * reference names them.
	 */
	public function testSleepBonusAndMultiplierRoutesMatchTheContract(): void {
		$declared = [];
		foreach ($this->routes['routes'] as $route) {
			$declared[$route['name']] = $route['verb'] . ' ' . $route['url'];
		}
		$this->assertSame('PUT /api/v1/room-tasks/{id}/sleep', $declared['room_task_cycle#sleep'] ?? null);
		$this->assertSame('GET /api/v1/room-tasks/bonus', $declared['room_task#bonus'] ?? null);
		$this->assertSame('PUT /api/v1/house/members/{userId}/coin-factor', $declared['coinMultiplier#setFactor'] ?? null);
		$this->assertSame('POST /api/v1/house/bonus-periods', $declared['coinMultiplier#startPeriod'] ?? null);
		$this->assertSame('DELETE /api/v1/house/bonus-periods/{id}', $declared['coinMultiplier#cancelPeriod'] ?? null);
	}

	/**
	 * CS-PLAN-091 WP-C (ADR-022 decision 6): the live suggestion is one
	 * read, and the preferred days ride the two existing preference writes.
	 */
	public function testSuggestionRouteMatchesTheContract(): void {
		$declared = [];
		foreach ($this->routes['routes'] as $route) {
			$declared[$route['name']] = $route['verb'] . ' ' . $route['url'];
		}
		$this->assertSame('GET /api/v1/scheduling/suggestion', $declared['scheduling#suggestion'] ?? null);
		$this->assertSame('PUT /api/v1/scheduling/preferences', $declared['scheduling#updatePreferences'] ?? null);
		$this->assertSame('PUT /api/v1/scheduling/members/{userId}', $declared['scheduling#updateMemberAvailability'] ?? null);
	}

	/**
	 * C5h and CS-PLAN-090 WP-K (D-090-61): the personal preferences stay
	 * self-only — every preference route is under `/preferences/me`, none
	 * names another person, and the colour scheme is the one addition.
	 */
	public function testPreferenceSurfaceStaysSelfOnly(): void {
		$preferenceRoutes = array_values(array_filter(
			array_map(static fn (array $route): string => $route['verb'] . ' ' . $route['url'] . ' ' . $route['name'], $this->routes['routes']),
			static fn (string $route): bool => str_contains($route, 'preference#') || str_contains($route, ' /api/v1/preferences'),
		));
		$this->assertSame([
			'GET /api/v1/preferences/me preference#show',
			'PUT /api/v1/preferences/me preference#update',
			'PUT /api/v1/preferences/me/color-scheme preference#updateColorScheme',
		], $preferenceRoutes);
	}

	/**
	 * CS-PLAN-090 WP-I (D-090-65): the categories have exactly one writer,
	 * and members read them with the house.
	 */
	public function testTaskCategoryWriterIsTheOnlyCategoryRoute(): void {
		$categoryRoutes = array_values(array_filter(
			array_map(static fn (array $route): string => $route['verb'] . ' ' . $route['url'] . ' ' . $route['name'], $this->routes['routes']),
			static fn (string $route): bool => str_contains($route, 'categor'),
		));
		$this->assertSame(['PUT /api/v1/house/task-categories taskCategory#update'], $categoryRoutes);
	}

	/**
	 * CS-PLAN-086 WP-B: the arcade catalog is a literal segment no
	 * `{gameId}` route can swallow (in either registration order), and the
	 * owner gate is the only arcade writer besides the score save.
	 * CS-PLAN-090 WP-J adds exactly the owner's thresholds and reset.
	 */
	public function testArcadeCatalogAndGateRoutes(): void {
		$declared = array_map(
			static fn (array $route): string => $route['verb'] . ' ' . $route['url'],
			$this->routes['routes']
		);
		$this->assertContains('GET /api/v1/minigames/catalog', $declared);
		$this->assertContains('PUT /api/v1/house/arcade-gate', $declared);

		foreach ($this->routes['routes'] as $route) {
			if ($route['verb'] !== 'GET' || !str_contains($route['url'], '{gameId}')) {
				continue;
			}
			$pattern = '#^' . str_replace('{gameId}', '[a-z0-9-]+', $route['url']) . '$#';
			$this->assertDoesNotMatchRegularExpression($pattern, '/api/v1/minigames/catalog', $route['name'] . ' must not read the catalog as a game id');
		}

		$arcadeWriters = array_values(array_filter($declared, static fn (string $route): bool
			=> !str_starts_with($route, 'GET ') && (str_contains($route, '/minigames') || str_contains($route, 'arcade'))));
		$this->assertSame([
			'PUT /api/v1/house/arcade-gate',
			'PUT /api/v1/house/arcade-thresholds',
			'POST /api/v1/house/members/{userId}/arcade-reset',
			'POST /api/v1/minigames/{gameId}/scores',
		], $arcadeWriters);
		$this->assertContains('GET /api/v1/house/arcade-progress', $declared);
	}

	/**
	 * CS-PLAN-070 WP7 (CS-PLAN-078 §3.3): the reward surface is exactly
	 * the planned trio/octet plus the narrow house-catalog gate — no
	 * wallet/ledger dump, house-reset or item-delete route exists (items
	 * are archived, never deleted) and no second catalog writer competes
	 * with PUT /house/catalog.
	 */
	public function testRewardSurfaceMatchesTheWP7Contract(): void {
		$declared = array_map(
			static fn (array $route): string => $route['verb'] . ' ' . $route['url'],
			$this->routes['routes']
		);

		$expected = [
			'PUT /api/v1/house/catalog',
			'GET /api/v1/rewards',
			'POST /api/v1/rewards',
			'PUT /api/v1/rewards/{id}',
			'GET /api/v1/reward-requests',
			'POST /api/v1/reward-requests',
			'GET /api/v1/reward-requests/{id}',
			'POST /api/v1/reward-requests/{id}/approve',
			'POST /api/v1/reward-requests/{id}/reject',
			'POST /api/v1/reward-requests/{id}/cancel',
			'POST /api/v1/reward-requests/{id}/fulfill',
			'POST /api/v1/reward-requests/{id}/correct',
		];
		foreach ($expected as $route) {
			$this->assertContains($route, $declared, "The WP7 route $route must exist");
		}
		// No destructive/leaky surface: items are archived, never deleted;
		// no generic house-wide wallet or ledger read exists.
		$this->assertNotContains('DELETE /api/v1/rewards/{id}', $declared, 'Items are archived, never deleted');
		$this->assertNotContains('GET /api/v1/rewards/{id}', $declared, 'No single-item read exists (catalog list covers it)');
		foreach ($declared as $route) {
			$this->assertDoesNotMatchRegularExpression('#/(wallet|ledger|wallet-entries)#', $route, 'No wallet/ledger dump route exists');
		}
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
	 * CS-PLAN-091 WP-B1 (ADR-021 decision 13, ADR-022): windows are derived
	 * and nothing is applied as an agreement, so the writers of stored
	 * occurrences stay gone — a request to any of them finds no route and
	 * answers 404. Re-adding one requires a new decision.
	 *
	 * @dataProvider retiredOccurrenceWriterProvider
	 */
	public function testRetiredOccurrenceWritersAreNotReintroduced(string $verbAndUrl, string $name): void {
		$this->assertRouteStaysRetired($verbAndUrl, $name, 'CS-PLAN-091 WP-B1');
	}

	public static function retiredOccurrenceWriterProvider(): array {
		return [
			'amend' => ['POST /api/v1/occurrences/{id}/amend', 'occurrence#amend'],
			'catch-up' => ['POST /api/v1/occurrences/{id}/catch-up', 'occurrence#catchUp'],
			'restart' => ['POST /api/v1/occurrences/{id}/restart', 'occurrence#restart'],
			'due date' => ['PUT /api/v1/room-tasks/{id}/due-date', 'room_task#updateDueDate'],
			'last done' => ['PUT /api/v1/room-tasks/{id}/last-done', 'room_task_last_done#update'],
			'preview' => ['POST /api/v1/scheduling/suggestions', 'scheduling#preview'],
			'apply' => ['POST /api/v1/scheduling/apply', 'scheduling#apply'],
		];
	}

	/**
	 * CS-PLAN-091 WP-B6 (FEAT-UX-040, D-091-87): the one form saves a task
	 * in one request, so the editors it replaces stay gone — a request to
	 * any of them finds no route and answers 404.
	 *
	 * @dataProvider retiredEditorProvider
	 */
	public function testRetiredEditorsAreNotReintroduced(string $verbAndUrl, string $name): void {
		$this->assertRouteStaysRetired($verbAndUrl, $name, 'CS-PLAN-091 WP-B6');
	}

	public static function retiredEditorProvider(): array {
		return [
			'definition update' => ['PUT /api/v1/tasks/{id}', 'task#update'],
			'template create' => ['POST /api/v1/room-tasks/from-template', 'room_task#fromTemplate'],
			'bulk assignment' => ['POST /api/v1/room-tasks/bulk-assign', 'room_task#bulkAssign'],
			'assignee read' => ['GET /api/v1/room-tasks/{id}/assignees', 'room_task#assignees'],
			'assignee write' => ['PUT /api/v1/room-tasks/{id}/assignees', 'room_task#setAssignees'],
			'sub-task add' => ['POST /api/v1/checklists/task/{taskId}', 'checklist#addTaskItem'],
			'sub-task rename' => ['PUT /api/v1/checklists/item/{itemId}', 'checklist#updateTaskItem'],
			'sub-task delete' => ['DELETE /api/v1/checklists/item/{itemId}', 'checklist#deleteTaskItem'],
			'sub-task reorder' => ['POST /api/v1/checklists/reorder', 'checklist#reorderTaskItems'],
		];
	}

	/**
	 * CS-PLAN-091 WP-B7 (D-089-09, D-090-10, D-090-38, RF-091-11): the
	 * routes without a client stay gone — a request to any of them finds no
	 * route (404, or 405 where another verb keeps the path, as DELETE does
	 * for /pauses/{id}), and no controller method is left behind them.
	 * The journal entries of the manual delta keep replaying
	 * (`RoomReplayConcurrencyTest`), stored shortened absences keep governing
	 * (`PauseAbsenceTest`), and the statistics compute the house health.
	 *
	 * @dataProvider retiredClientlessRouteProvider
	 */
	public function testRetiredClientlessRoutesAreNotReintroduced(string $verbAndUrl, string $name, string $controller, string $method): void {
		$this->assertRouteStaysRetired($verbAndUrl, $name, 'CS-PLAN-091 WP-B7');
		$this->assertFalse(method_exists($controller, $method), $controller . '::' . $method . '() went with its route');
	}

	public static function retiredClientlessRouteProvider(): array {
		return [
			'manual cleanliness delta' => ['PUT /api/v1/rooms/{id}/cleanliness', 'room#updateCleanliness', \OCA\ChoreStrike\Controller\RoomController::class, 'updateCleanliness'],
			'room task statistics' => ['GET /api/v1/room-tasks/room/{roomId}/stats', 'room_task#roomStats', \OCA\ChoreStrike\Controller\RoomTaskController::class, 'roomStats'],
			'absence shortening' => ['PUT /api/v1/pauses/{id}', 'pause#update', \OCA\ChoreStrike\Controller\PauseController::class, 'update'],
			'house health refresh' => ['GET /api/v1/house/health', 'house#health', \OCA\ChoreStrike\Controller\HouseController::class, 'health'],
		];
	}

	/** CS-PLAN-091 WP-B6: the one form's save, as the API reference names it. */
	public function testFormSaveRouteMatchesTheContract(): void {
		$declared = [];
		foreach ($this->routes['routes'] as $route) {
			$declared[$route['name']] = $route['verb'] . ' ' . $route['url'];
		}
		$this->assertSame('PUT /api/v1/room-tasks/{id}', $declared['room_task_edit#update'] ?? null);
		$this->assertSame('POST /api/v1/room-tasks', $declared['room_task#create'] ?? null);
	}

	/**
	 * The definition-only create endpoint pruned by CS-PLAN-063 must stay
	 * gone: creation runs exclusively through POST /room-tasks, the one
	 * form's create. Re-adding it requires a consumer and a new expose
	 * decision.
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

	/** A retired route is declared neither by its verb and URL nor by its name. */
	private function assertRouteStaysRetired(string $verbAndUrl, string $name, string $package): void {
		$declared = array_map(
			static fn (array $route): string => $route['verb'] . ' ' . $route['url'],
			$this->routes['routes']
		);
		$names = array_column($this->routes['routes'], 'name');

		$this->assertNotContains($verbAndUrl, $declared, $package . ' retired ' . $verbAndUrl);
		$this->assertNotContains($name, $names, $package . ' retired ' . $name);
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
