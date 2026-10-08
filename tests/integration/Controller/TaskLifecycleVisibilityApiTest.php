<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Controller;

use OCA\ChoreStrike\Controller\RoomTaskController;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Service\CompletionService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;

/**
 * Integration tests for the task lifecycle visibility matrix (CS-PLAN-056,
 * CR-053-06; unified via TaskAccessPolicy in CS-PLAN-061 §3.2).
 *
 * The lifecycle mutations (undo, updateDueDate) enforce the ONE visibility
 * matrix (decision D2):
 * - private tasks: creator ∪ explicit assignees may mutate
 * - admin-only tasks: only house owners may mutate
 * - public tasks: any house member may mutate
 *
 * Non-viewers are denied with the 404 anti-enumeration shape (decision D3):
 * envelope code `RESOURCE_NOT_FOUND`, never a 403 that would confirm the id
 * exists.
 *
 * CS-PLAN-064 (decision D5): the former `uncomplete`/`reactivate` surface is
 * pruned; the one idempotent undo aggregate `POST /room-tasks/{id}/undo`
 * inherits its visibility matrix (plus the completer-or-owner actor gate).
 */
class TaskLifecycleVisibilityApiTest extends IntegrationTestCase {
	private int $houseId;
	private int $floorId;
	private int $roomId;

	/** Private room task: created by lcv_creator, assigned to lcv_assignee */
	private int $privateRoomTaskId;

	/** Second private room task of the same definition, assigned to nobody */
	private int $creatorOnlyRoomTaskId;

	/** Admin-only room task (no assignee) */
	private int $adminOnlyRoomTaskId;

	/** Definition behind the private room tasks (visibility: private) */
	private int $privateTaskId;

	protected function setUp(): void {
		parent::setUp();

		// Controllers resolve the shared instance house, so the fixtures run
		// against it (ChecklistApiTest / BulkAssignIntegrationTest pattern).
		$this->actingAs('lcv_owner');
		$houseService = \OC::$server->get(HouseService::class);
		$this->houseId = $houseService->getOrCreateHouse($houseService->getInstanceId())->getId();
		$this->purgeInstanceHouseMemberships();
		$this->addHouseMember($this->houseId, 'lcv_owner', 'Owner', 'owner');
		$this->addHouseMember($this->houseId, 'lcv_creator', 'Creator', 'member');
		$this->addHouseMember($this->houseId, 'lcv_assignee', 'Assignee', 'child');
		$this->addHouseMember($this->houseId, 'lcv_member', 'Member', 'member');

		$this->floorId = $this->createFloor($this->houseId, 'Ground Floor', 1);
		$this->roomId = $this->createRoom($this->houseId, $this->floorId, 'Kitchen');

		$this->privateTaskId = $this->createTask($this->houseId, 'Private Lifecycle', 'Creator + assignee', 3, 5);
		$this->setTaskVisibility($this->privateTaskId, Task::VISIBILITY_PRIVATE, 'lcv_creator');
		$this->privateRoomTaskId = $this->assignTaskToRoom($this->privateTaskId, $this->roomId, 'lcv_assignee');
		// (task_id, room_id) is unique — the creator's room task lives in a
		// second room.
		$secondRoomId = $this->createRoom($this->houseId, $this->floorId, 'Bathroom');
		$this->creatorOnlyRoomTaskId = $this->assignTaskToRoom($this->privateTaskId, $secondRoomId, null);

		$adminOnlyTaskId = $this->createTask($this->houseId, 'Admin Only Lifecycle', 'Owner only', 3, 5);
		$this->setTaskVisibility($adminOnlyTaskId, Task::VISIBILITY_ADMIN_ONLY, 'lcv_owner');
		$this->adminOnlyRoomTaskId = $this->assignTaskToRoom($adminOnlyTaskId, $this->roomId, null);
	}

	// ========== Targeted completion correction (WP3 §3.6) ==========

	/**
	 * Undo_PrivateTask_ByNonAssigneeMember_Returns404
	 *
	 * Decision D3: a non-viewer gets the 404 anti-enumeration denial — the
	 * correction surface must not confirm that an invisible id exists, and
	 * the denied undo leaves the completed row untouched.
	 */
	public function testUndo_PrivateTask_ByNonAssigneeMember_Returns404(): void {
		$completionId = $this->completeAs('lcv_assignee', $this->privateRoomTaskId);

		$response = $this->undoViaController('lcv_member', $completionId);

		self::assertSame(404, $response->getStatus());
		$data = $response->getData();
		self::assertFalse($data['success']);
		self::assertSame('RESOURCE_NOT_FOUND', $data['error']['code']);
		self::assertNotNull($this->fetchLastCompleted($this->privateRoomTaskId), 'the row must be unchanged');
	}

	/**
	 * Undo_PrivateTask_ByAssignee_Returns200 — the completer may undo their
	 * own completion (actor gate D3) and assignees may execute (matrix D2).
	 */
	public function testUndo_PrivateTask_ByAssignee_Returns200(): void {
		$completionId = $this->completeAs('lcv_assignee', $this->privateRoomTaskId);

		$response = $this->undoViaController('lcv_assignee', $completionId);

		self::assertSame(200, $response->getStatus());
		$data = $response->getData();
		self::assertTrue($data['success']);
		self::assertTrue($data['data']['undone']);
		self::assertNull($this->fetchLastCompleted($this->privateRoomTaskId));
	}

	/**
	 * Undo_PrivateTask_ByCreatorWithoutAssignment_Returns200 — the creator
	 * is part of the matrix even when the room task is unassigned (D2:
	 * creator ∪ assignees) and may undo their own completion.
	 */
	public function testUndo_PrivateTask_ByCreatorWithoutAssignment_Returns200(): void {
		$completionId = $this->completeAs('lcv_creator', $this->creatorOnlyRoomTaskId);

		$response = $this->undoViaController('lcv_creator', $completionId);

		self::assertSame(200, $response->getStatus());
		self::assertTrue($response->getData()['data']['undone']);
		self::assertNull($this->fetchLastCompleted($this->creatorOnlyRoomTaskId));
	}

	// ========== UpdateDueDate ==========

	/**
	 * UpdateDueDate_AdminOnlyTask_ByNonOwnerMember_Returns404
	 */
	public function testUpdateDueDate_AdminOnlyTask_ByNonOwnerMember_Returns404(): void {
		$nextDueBefore = $this->fetchNextDue($this->adminOnlyRoomTaskId);
		$controller = $this->getController(RoomTaskController::class, 'lcv_member');

		$response = $controller->updateDueDate($this->adminOnlyRoomTaskId, '+3 days');

		self::assertSame(404, $response->getStatus());
		$data = $response->getData();
		self::assertFalse($data['success']);
		self::assertSame('RESOURCE_NOT_FOUND', $data['error']['code']);
		self::assertSame($nextDueBefore, $this->fetchNextDue($this->adminOnlyRoomTaskId), 'the row must be unchanged');
	}

	/**
	 * UpdateDueDate_AdminOnlyTask_ByOwner_Returns200
	 * @dataProvider houseTimezones
	 */
	public function testUpdateDueDate_AdminOnlyTask_ByOwner_Returns200(string $timezone): void {
		$mapper = \OC::$server->get(HouseMapper::class);
		$house = $mapper->find($this->houseId);
		$originalTimezone = $house->getTimezone();
		try {
			$house->setTimezone($timezone);
			$mapper->update($house);
			$controller = $this->getController(RoomTaskController::class, 'lcv_owner');
			$response = $controller->updateDueDate($this->adminOnlyRoomTaskId, '+3 days');

			self::assertSame(200, $response->getStatus());
			self::assertTrue($response->getData()['success']);
			self::assertSame(
				(new \DateTime('+3 days'))->setTimezone(new \DateTimeZone($timezone))->format('Y-m-d'),
				(new \DateTime((string)$this->fetchNextDue($this->adminOnlyRoomTaskId), new \DateTimeZone('UTC')))
					->setTimezone(new \DateTimeZone($timezone))->format('Y-m-d')
			);
		} finally {
			$house->setTimezone($originalTimezone);
			$mapper->update($house);
		}
	}

	public static function houseTimezones(): array {
		return [['UTC'], ['Europe/Berlin']];
	}

	/**
	 * UpdateDueDate_PrivateTask_ByCreator_Returns200
	 */
	public function testUpdateDueDate_PrivateTask_ByCreator_Returns200(): void {
		$controller = $this->getController(RoomTaskController::class, 'lcv_creator');

		$response = $controller->updateDueDate($this->privateRoomTaskId, '+5 days');

		self::assertSame(200, $response->getStatus());
		self::assertTrue($response->getData()['success']);
	}

	// ========== Undo of admin-only + once tasks (reactivate replacement) ==========

	/**
	 * Undo_AdminOnlyTask_ByNonOwnerMember_Returns404
	 */
	public function testUndo_AdminOnlyTask_ByNonOwnerMember_Returns404(): void {
		$completionId = $this->completeAs('lcv_owner', $this->adminOnlyRoomTaskId);

		$response = $this->undoViaController('lcv_member', $completionId);

		self::assertSame(404, $response->getStatus());
		$data = $response->getData();
		self::assertFalse($data['success']);
		self::assertSame('RESOURCE_NOT_FOUND', $data['error']['code']);
		self::assertNotNull($this->fetchLastCompleted($this->adminOnlyRoomTaskId), 'the row must be unchanged');
	}

	/**
	 * Undo_AdminOnlyTask_ByOwner_Returns200 — the owner completed it, so the
	 * actor gate (completer-or-owner) and the matrix (owner-only) both pass.
	 */
	public function testUndo_AdminOnlyTask_ByOwner_Returns200(): void {
		$completionId = $this->completeAs('lcv_owner', $this->adminOnlyRoomTaskId);

		$response = $this->undoViaController('lcv_owner', $completionId);

		self::assertSame(200, $response->getStatus());
		self::assertTrue($response->getData()['data']['undone']);
		self::assertNull($this->fetchLastCompleted($this->adminOnlyRoomTaskId));
	}

	/**
	 * Undo_OnceTask_ByAssigneeChild_RestoresIsActive — children keep the
	 * execution surface (undoing one's OWN completion is child-friendly
	 * self-recovery, masterplan D3). The `once` completion deactivates the
	 * row; the undo restores `is_active` from the recorded before-state —
	 * the reactivate endpoint's job is now the correction aggregate's.
	 */
	public function testUndo_OnceTask_ByAssigneeChild_RestoresIsActive(): void {
		// CS-PLAN-072 WP1: 'once' must be part of the INITIAL schedule
		// inputs — converting a live recurring schedule closes+appends a
		// revision while the already-agreed old occurrences stay completable
		// (parent §3.3 rule 2), so the lifecycle fixture seeds the
		// definition BEFORE the mount.
		$onceTaskId = $this->createTask($this->houseId, 'Once Lifecycle', 'Assignee once', 3, 5);
		$this->setTaskVisibility($onceTaskId, Task::VISIBILITY_PRIVATE, 'lcv_creator');
		$this->setRecurrenceOnce($onceTaskId);
		$onceRoomId = $this->createRoom($this->houseId, $this->floorId, 'Once Room');
		$onceRoomTaskId = $this->assignTaskToRoom($onceTaskId, $onceRoomId, 'lcv_assignee');

		$completionId = $this->completeAs('lcv_assignee', $onceRoomTaskId);
		self::assertSame(0, $this->fetchIsActive($onceRoomTaskId), 'the once completion deactivates the row');

		$response = $this->undoViaController('lcv_assignee', $completionId);

		self::assertSame(200, $response->getStatus());
		self::assertTrue($response->getData()['data']['undone']);
		self::assertSame(1, $this->fetchIsActive($onceRoomTaskId));
	}

	// ========== Helpers ==========

	/**
	 * Remove leftover memberships of the fixture users in the instance house
	 * (earlier runs may have left rows behind).
	 */
	private function purgeInstanceHouseMemberships(): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('chorestrike_house_users')
			->where($qb->expr()->in(
				'nextcloud_user_id',
				$qb->createNamedParameter(['lcv_owner', 'lcv_creator', 'lcv_assignee', 'lcv_member'], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)
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

	/**
	 * Complete a room task through the real aggregate (CS-PLAN-064) so the
	 * correction surface operates on a genuine work-evidence record.
	 * CS-PLAN-072 WP1: the completion carries the occurrence receipt of
	 * the current canonical target. Returns the numeric completion id.
	 */
	private function completeAs(string $userId, int $roomTaskId): int {
		$result = $this->completeOccurrence(
			\OC::$server->get(CompletionService::class),
			$this->houseId,
			$userId,
			$roomTaskId
		);
		return (int)$result['completionId'];
	}

	/**
	 * Undo one exact completion through the targeted correction endpoint
	 * (WP3 §3.6): a fresh command receipt against the recorded version.
	 */
	private function undoViaController(string $actor, int $completionId): object {
		$controller = $this->getController(\OCA\ChoreStrike\Controller\CompletionController::class, $actor);
		$this->injectRequestParams($controller, [
			'commandId' => sprintf('5f6e7a8b-9c0d-4e1f-8a2b-%012d', random_int(0, 999999999999)),
			'expectedVersion' => 0,
		]);
		return $controller->undo($completionId);
	}

	private function setRecurrenceOnce(int $taskId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('chorestrike_tasks')
			->set('recurrence_mode', $qb->createNamedParameter('once'))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($taskId)));
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
