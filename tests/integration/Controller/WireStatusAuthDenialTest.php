<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Controller;

use OCA\ChoreStrike\Controller\RoomTaskController;
use OCA\ChoreStrike\Controller\TaskController;
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
	 */
	public function testMemberCreatingAdminOnlyTaskReturns403(): void {
		$controller = $this->getController(TaskController::class, self::MEMBER);

		$response = $controller->create(
			'Wire Status Test Task',
			visibilityType: Task::VISIBILITY_ADMIN_ONLY
		);

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
