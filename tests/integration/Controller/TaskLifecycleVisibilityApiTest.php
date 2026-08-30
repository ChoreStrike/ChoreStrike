<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Controller;

use OCA\ChoreStrike\Controller\RoomTaskController;
use OCA\ChoreStrike\Controller\TaskController;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;

/**
 * Integration tests for the task lifecycle visibility matrix (CS-PLAN-056, CR-053-06).
 *
 * The lifecycle mutations (uncomplete, updateDueDate, reactivate) must enforce
 * the same visibility matrix as the completion path:
 * - private tasks: only assigned users may mutate
 * - admin-only tasks: only house owners may mutate
 * - public tasks: any house member may mutate
 *
 * Exercise the real controllers (403 envelope `FORBIDDEN`, translated
 * messages, identical to `complete`).
 */
class TaskLifecycleVisibilityApiTest extends IntegrationTestCase {
	private int $houseId;
	private int $floorId;
	private int $roomId;

	/** Private room task assigned to lcv_assignee */
	private int $privateRoomTaskId;

	/** Admin-only room task (no assignee) */
	private int $adminOnlyRoomTaskId;

	protected function setUp(): void {
		parent::setUp();

		// Controllers resolve the shared instance house, so the fixtures run
		// against it (ChecklistApiTest / BulkAssignIntegrationTest pattern).
		$this->actingAs('lcv_owner');
		$houseService = \OC::$server->get(HouseService::class);
		$this->houseId = $houseService->getOrCreateHouse($houseService->getInstanceId())->getId();
		$this->purgeInstanceHouseMemberships();
		$this->addHouseMember($this->houseId, 'lcv_owner', 'Owner', 'owner');
		$this->addHouseMember($this->houseId, 'lcv_assignee', 'Assignee', 'member');
		$this->addHouseMember($this->houseId, 'lcv_member', 'Member', 'member');

		$this->floorId = $this->createFloor($this->houseId, 'Ground Floor', 1);
		$this->roomId = $this->createRoom($this->houseId, $this->floorId, 'Kitchen');

		$privateTaskId = $this->createTask($this->houseId, 'Private Lifecycle', 'Assignee only', 3, 5);
		$this->setTaskVisibility($privateTaskId, Task::VISIBILITY_PRIVATE, 'lcv_assignee');
		$this->privateRoomTaskId = $this->assignTaskToRoom($privateTaskId, $this->roomId, 'lcv_assignee');

		$adminOnlyTaskId = $this->createTask($this->houseId, 'Admin Only Lifecycle', 'Owner only', 3, 5);
		$this->setTaskVisibility($adminOnlyTaskId, Task::VISIBILITY_ADMIN_ONLY, 'lcv_owner');
		$this->adminOnlyRoomTaskId = $this->assignTaskToRoom($adminOnlyTaskId, $this->roomId, null);

		// Seed a completion marker so the denied uncomplete can be asserted as a no-op.
		$this->setLastCompleted($this->privateRoomTaskId, date('Y-m-d H:i:s'));
	}

	// ========== Uncomplete ==========

	/**
	 * Uncomplete_PrivateTask_ByNonAssigneeMember_Returns403
	 */
	public function testUncomplete_PrivateTask_ByNonAssigneeMember_Returns403(): void {
		$controller = $this->getController(TaskController::class, 'lcv_member');

		$response = $controller->uncomplete($this->privateRoomTaskId);

		self::assertSame(403, $response->getStatus());
		$data = $response->getData();
		self::assertFalse($data['success']);
		self::assertSame('FORBIDDEN', $data['error']['code']);
		self::assertSame('Private tasks may only be modified by assigned users', $data['error']['message']);
		self::assertNotNull($this->fetchLastCompleted($this->privateRoomTaskId), 'the row must be unchanged');
	}

	/**
	 * Uncomplete_PrivateTask_ByAssignee_Returns200
	 */
	public function testUncomplete_PrivateTask_ByAssignee_Returns200(): void {
		$controller = $this->getController(TaskController::class, 'lcv_assignee');

		$response = $controller->uncomplete($this->privateRoomTaskId);

		self::assertSame(200, $response->getStatus());
		self::assertTrue($response->getData()['success']);
		self::assertNull($this->fetchLastCompleted($this->privateRoomTaskId));
	}

	// ========== UpdateDueDate ==========

	/**
	 * UpdateDueDate_AdminOnlyTask_ByNonOwnerMember_Returns403
	 */
	public function testUpdateDueDate_AdminOnlyTask_ByNonOwnerMember_Returns403(): void {
		$nextDueBefore = $this->fetchNextDue($this->adminOnlyRoomTaskId);
		$controller = $this->getController(RoomTaskController::class, 'lcv_member');

		$response = $controller->updateDueDate($this->adminOnlyRoomTaskId, '+3 days');

		self::assertSame(403, $response->getStatus());
		$data = $response->getData();
		self::assertFalse($data['success']);
		self::assertSame('FORBIDDEN', $data['error']['code']);
		self::assertSame('Admin-only tasks may only be modified by house owners', $data['error']['message']);
		self::assertSame($nextDueBefore, $this->fetchNextDue($this->adminOnlyRoomTaskId), 'the row must be unchanged');
	}

	/**
	 * UpdateDueDate_AdminOnlyTask_ByOwner_Returns200
	 */
	public function testUpdateDueDate_AdminOnlyTask_ByOwner_Returns200(): void {
		$controller = $this->getController(RoomTaskController::class, 'lcv_owner');

		$response = $controller->updateDueDate($this->adminOnlyRoomTaskId, '+3 days');

		self::assertSame(200, $response->getStatus());
		self::assertTrue($response->getData()['success']);
		self::assertSame(
			(new \DateTime('+3 days'))->format('Y-m-d'),
			substr((string)$this->fetchNextDue($this->adminOnlyRoomTaskId), 0, 10)
		);
	}

	// ========== Reactivate ==========

	/**
	 * Reactivate_AdminOnlyTask_ByNonOwnerMember_Returns403
	 */
	public function testReactivate_AdminOnlyTask_ByNonOwnerMember_Returns403(): void {
		$this->deactivateRoomTask($this->adminOnlyRoomTaskId);
		$controller = $this->getController(RoomTaskController::class, 'lcv_member');

		$response = $controller->reactivate($this->adminOnlyRoomTaskId);

		self::assertSame(403, $response->getStatus());
		$data = $response->getData();
		self::assertFalse($data['success']);
		self::assertSame('FORBIDDEN', $data['error']['code']);
		self::assertSame(0, $this->fetchIsActive($this->adminOnlyRoomTaskId), 'the row must be unchanged');
	}

	/**
	 * Reactivate_AdminOnlyTask_ByOwner_Returns200
	 */
	public function testReactivate_AdminOnlyTask_ByOwner_Returns200(): void {
		$this->deactivateRoomTask($this->adminOnlyRoomTaskId);
		$controller = $this->getController(RoomTaskController::class, 'lcv_owner');

		$response = $controller->reactivate($this->adminOnlyRoomTaskId);

		self::assertSame(200, $response->getStatus());
		self::assertTrue($response->getData()['success']);
		self::assertSame(1, $this->fetchIsActive($this->adminOnlyRoomTaskId));
	}

	// ========== Helpers ==========

	/**
	 * Remove leftover memberships of the fixture users in the instance house
	 * (earlier runs auto-join via ensureHouseMember and leave rows behind).
	 */
	private function purgeInstanceHouseMemberships(): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('chorestrike_house_users')
			->where($qb->expr()->in(
				'nextcloud_user_id',
				$qb->createNamedParameter(['lcv_owner', 'lcv_assignee', 'lcv_member'], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)
			));
		$qb->executeStatement();
	}

	private function setTaskVisibility(int $taskId, string $visibilityType, ?string $ownerUserId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('chorestrike_tasks')
			->set('visibility_type', $qb->createNamedParameter($visibilityType))
			->set('owner_user_id', $qb->createNamedParameter($ownerUserId))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($taskId)));
		$qb->executeStatement();
	}

	private function deactivateRoomTask(int $roomTaskId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('chorestrike_room_tasks')
			->set('is_active', $qb->createNamedParameter(0))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($roomTaskId)));
		$qb->executeStatement();
	}

	private function setLastCompleted(int $roomTaskId, string $lastCompleted): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('chorestrike_room_tasks')
			->set('last_completed', $qb->createNamedParameter($lastCompleted))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($roomTaskId)));
		$qb->executeStatement();
	}

	private function fetchLastCompleted(int $roomTaskId): ?string {
		$qb = $this->db->getQueryBuilder();
		$qb->select('last_completed')
			->from('chorestrike_room_tasks')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($roomTaskId)));
		$row = $qb->executeQuery()->fetchOne();
		return $row === false ? null : $row;
	}

	private function fetchNextDue(int $roomTaskId): ?string {
		$qb = $this->db->getQueryBuilder();
		$qb->select('next_due')
			->from('chorestrike_room_tasks')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($roomTaskId)));
		$row = $qb->executeQuery()->fetchOne();
		return $row === false ? null : $row;
	}

	private function fetchIsActive(int $roomTaskId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select('is_active')
			->from('chorestrike_room_tasks')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($roomTaskId)));
		return (int)$qb->executeQuery()->fetchOne();
	}
}
