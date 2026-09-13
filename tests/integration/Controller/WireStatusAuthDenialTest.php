<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Controller;

use OCA\ChoreStrike\Controller\RoomTaskController;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * Authorization-denial status regression (CS-PLAN-020).
 *
 * Locks the controller-level contract that authorization denials return a
 * `JSONResponse` with the correct HTTP status (403) and the
 * `{ success: false, error: { code, message } }` envelope, exercised against
 * the real service / DB stack via the DI container (no mocks).
 *
 * Note on "wire-level": PHPUnit integration tests call controller methods
 * directly, so the Nextcloud HTTP middleware pipeline (notably
 * `OCSMiddleware`, which previously re-wrapped these responses into a
 * 200 envelope) does **not** run here. The on-the-wire HTTP status is
 * therefore additionally verified end-to-end by the Playwright specs
 * `admin-only-tasks.spec.ts` and `overdue-task-claiming.spec.ts` and by the
 * Docker HTTP probe described in CS-PLAN-020 Phase 1. Now that the API
 * controllers extend `Controller` (not `OCSController`), the middleware no
 * longer touches their responses, so the in-process 403 IS what reaches the
 * client.
 *
 * @see docs/plans/active/cs-plan-020-release-quality-gate-cluster.md Phase 1
 */
class WireStatusAuthDenialTest extends IntegrationTestCase {
	/** Test-only Nextcloud user IDs; auto-joined as members of the instance house. */
	private const MEMBER = 'wire_status_member';
	private const FOREIGN = 'wire_status_foreign';

	protected function tearDown(): void {
		// The controllers resolve the shared instance house (getInstanceId);
		// ensureHouseMember() auto-creates a house_users row for the caller.
		// Remove only our test-user memberships — never the shared house.
		$qb = $this->db->getQueryBuilder();
		$qb->delete('chorestrike_house_users')
			->where($qb->expr()->in(
				'nextcloud_user_id',
				$qb->createNamedParameter([self::MEMBER, self::FOREIGN], IQueryBuilder::PARAM_STR_ARRAY)
			));
		$qb->executeStatement();

		parent::tearDown();
	}

	/**
	 * A non-owner member creating an admin-only task must be denied with 403
	 * (not 500, and on the wire not a 200 OCS envelope).
	 *
	 * CS-PLAN-063: creation routes through the atomic aggregate on
	 * POST /room-tasks (POST /tasks was pruned); the admin-only gate lives
	 * in TaskCreationService::resolveCreationVisibility.
	 */
	public function testMemberCreatingAdminOnlyTaskReturns403(): void {
		// The admin-only gate fires after room ownership, so the denial needs
		// a real room (CS-PLAN-063 §3.2 gate order).
		$instanceId = \OC::$server->get(\OCA\ChoreStrike\Service\HouseService::class)->getInstanceId();
		$house = \OC::$server->get(\OCA\ChoreStrike\Service\HouseService::class)->getOrCreateHouse($instanceId);
		$floorId = $this->createFloor($house->getId(), 'Wire Status Floor');
		$roomId = $this->createRoom($house->getId(), $floorId, 'Wire Status Room');

		$this->actingAs(self::MEMBER);
		$request = $this->createMock(\OCP\IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key) => ['roomId' => $roomId, 'task' => [
				'name' => 'Wire Status Test Task',
				'energyCost' => 3,
				'priority' => 2,
				'frequencyType' => 'weekly',
				'frequencyValue' => 1,
				'estimatedMinutes' => 15,
				'category' => 'cleaning',
				'visibilityType' => Task::VISIBILITY_ADMIN_ONLY,
			]][$key] ?? null
		);
		$l10n = $this->createMock(\OCP\IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text) => $text);

		$controller = new RoomTaskController(
			$request,
			\OC::$server->get(\OCA\ChoreStrike\Service\RoomTaskService::class),
			\OC::$server->get(\OCA\ChoreStrike\Service\RoomTaskReadService::class),
			\OC::$server->get(\OCA\ChoreStrike\Service\CompletionService::class),
			\OC::$server->get(\OCA\ChoreStrike\Service\TaskCreationService::class),
			\OC::$server->get(\OCA\ChoreStrike\Service\HouseService::class),
			\OC::$server->get(\OCA\ChoreStrike\Service\AuthorizationService::class),
			$l10n,
			\OC::$server->get(\OCA\ChoreStrike\Service\CalendarService::class),
			\OC::$server->get(\OCA\ChoreStrike\Service\AppLogger::class),
		);

		$response = $controller->create();

		self::assertSame(403, $response->getStatus());
		$data = $response->getData();
		self::assertFalse($data['success']);
		self::assertSame('FORBIDDEN', $data['error']['code']);
	}

	/**
	 * The pruned per-user room-task stats endpoint (removed CS-PLAN-040,
	 * CR-SVC-31-flawed metric, no consumer) must be gone — its former
	 * cross-house 403 gate is superseded by the route's absence (404).
	 */
	public function testPrunedUserStatsActionNoLongerExists(): void {
		self::assertFalse(
			method_exists(RoomTaskController::class, 'userStats'),
			'room_task#userStats was pruned in CS-PLAN-040 — re-adding it requires a new expose decision'
		);
	}
}
