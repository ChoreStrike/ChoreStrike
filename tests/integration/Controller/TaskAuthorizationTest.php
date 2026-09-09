<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Controller;

use OCA\ChoreStrike\Controller\RoomTaskController;
use OCA\ChoreStrike\Controller\TaskController;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;

/**
 * Task Authorization Integration Tests (CS-PLAN-061 §4, step 1).
 *
 * Exercises the REAL controllers end-to-end against the shared instance
 * house (the resolution path production uses). Covers:
 * - cross-house isolation: foreign ids are 404s on read and write, never
 *   leaks or 500s
 * - the 403/404 standard: foreign/nonexistent ids → 404 anti-enumeration,
 *   role denials → 403 (decision D3, D5)
 * - actor matrix: owner/member complete; children execute (complete,
 *   reactivate) but cannot manage (delete, assign)
 * - invite-only membership: a non-member gets 403 on every house read
 *
 * Full rewrite of the previously fully-skipped suite (the old scenarios
 * were skipped pending a rewrite to the instance-house API — see the old
 * file header). Service-level isolation stays covered by
 * AuthorizationIntegrationTest / TaskMapperHouseScopeTest.
 */
class TaskAuthorizationTest extends IntegrationTestCase {
	private int $houseId;
	private int $floorId;
	private int $roomId;
	private int $taskId;
	private int $roomTaskId;
	private int $privateTaskId;
	private int $privateRoomTaskId;

	/** Foreign house owned by another household (cross-house probes). */
	private int $foreignHouseId;
	private int $foreignRoomId;
	private int $foreignTaskId;
	private int $foreignRoomTaskId;

	protected function setUp(): void {
		parent::setUp();

		// Instance house: the house the controllers resolve via
		// getOrCreateHouse(instanceId).
		$this->actingAs('ta_owner');
		$houseService = \OC::$server->get(HouseService::class);
		$this->houseId = $houseService->getOrCreateHouse($houseService->getInstanceId())->getId();
		$this->purgeInstanceHouseMemberships();
		$this->addHouseMember($this->houseId, 'ta_owner', 'Owner', 'owner');
		$this->addHouseMember($this->houseId, 'ta_member', 'Member', 'member');
		$this->addHouseMember($this->houseId, 'ta_other_member', 'Other Member', 'member');
		$this->addHouseMember($this->houseId, 'ta_child', 'Child', 'child');

		$this->floorId = $this->createFloor($this->houseId, 'Ground Floor', 1);
		$this->roomId = $this->createRoom($this->houseId, $this->floorId, 'Kitchen');

		$this->taskId = $this->createTask($this->houseId, 'Dishes', 'Do the dishes', 3, 30);
		$this->roomTaskId = $this->assignTaskToRoom($this->taskId, $this->roomId, 'ta_member');

		// Private task of the owner in the same room — the residual-surface
		// fixtures (assignees read, room stats) of the review fixes.
		$this->privateTaskId = $this->createTask($this->houseId, 'Private Note', 'Hidden from the house', 2, 5);
		$this->setTaskVisibility($this->privateTaskId, Task::VISIBILITY_PRIVATE, 'ta_owner');
		$this->privateRoomTaskId = $this->assignTaskToRoom($this->privateTaskId, $this->roomId, 'ta_owner');

		// Foreign house (a different household on the same instance).
		$this->foreignHouseId = $this->createHouse('ta_foreign_owner', 'Foreign House');
		$this->foreignRoomId = $this->createRoom(
			$this->foreignHouseId,
			$this->createFloor($this->foreignHouseId, 'Ground Floor', 1),
			'Kitchen',
		);
		$this->foreignTaskId = $this->createTask($this->foreignHouseId, 'Vacuum', 'Vacuum the floor', 4, 20);
		$this->foreignRoomTaskId = $this->assignTaskToRoom($this->foreignTaskId, $this->foreignRoomId, null);
	}

	// ========== Cross-house isolation ==========

	public function testUserCannotCompleteTaskFromDifferentHouse(): void {
		$controller = $this->getController(RoomTaskController::class, 'ta_member');

		$response = $controller->complete($this->foreignRoomTaskId);

		self::assertSame(404, $response->getStatus());
		$data = $response->getData();
		self::assertFalse($data['success']);
		self::assertSame('RESOURCE_NOT_FOUND', $data['error']['code']);
		self::assertSame(0, $this->countCompletions($this->foreignRoomTaskId), 'no completion may be recorded');
	}

	public function testUserCannotViewTasksFromDifferentHouse(): void {
		$controller = $this->getController(TaskController::class, 'ta_owner');

		$response = $controller->index();

		self::assertSame(200, $response->getStatus());
		$taskIds = array_map(static fn (array $t) => $t['id'], $response->getData()['data']);
		self::assertContains($this->taskId, $taskIds);
		self::assertNotContains($this->foreignTaskId, $taskIds, 'foreign definitions must not leak into the index');
	}

	public function testIndexWithForeignRoomId_Returns404AndEmpty(): void {
		$controller = $this->getController(RoomTaskController::class, 'ta_owner');

		$response = $controller->index($this->foreignRoomId);

		self::assertSame(404, $response->getStatus());
	}

	public function testUserCannotCreateRoomTaskInForeignRoom(): void {
		// CS-PLAN-063: creation runs through the atomic aggregate; the
		// request-style controller build carries the new payload shape.
		$controller = $this->getBulkController('ta_member', [
			'roomId' => $this->foreignRoomId,
			'taskId' => $this->foreignTaskId,
		]);

		$response = $controller->create();

		self::assertSame(404, $response->getStatus());
		$data = $response->getData();
		self::assertSame('RESOURCE_NOT_FOUND', $data['error']['code']);
	}

	public function testUserCannotDeleteTaskFromDifferentHouse(): void {
		$controller = $this->getController(RoomTaskController::class, 'ta_owner');

		$response = $controller->delete($this->foreignRoomTaskId);

		self::assertSame(404, $response->getStatus());
		self::assertSame(1, $this->fetchRoomTaskActive($this->foreignRoomTaskId), 'the foreign row must be untouched');
	}

	// ========== Invite-only membership at the controller ==========
	// (the deep bootstrap matrix lives in InviteOnlyMembershipTest)

	public function testNonMemberCannotAccessHouseTasks(): void {
		$controller = $this->getController(TaskController::class, 'ta_outsider');

		$response = $controller->index();

		self::assertSame(403, $response->getStatus());
		$data = $response->getData();
		self::assertSame('FORBIDDEN', $data['error']['code']);
	}

	// ========== Role / actor matrix ==========

	public function testOwnerCanCompleteAnyTaskInTheirHouse(): void {
		$controller = $this->getController(RoomTaskController::class, 'ta_owner');

		$response = $controller->complete($this->roomTaskId);

		self::assertSame(200, $response->getStatus());
		self::assertTrue($response->getData()['success']);
		self::assertSame(1, $this->countCompletions($this->roomTaskId));
	}

	public function testUserCanCompleteTaskAssignedToThem(): void {
		$controller = $this->getController(RoomTaskController::class, 'ta_member');

		$response = $controller->complete($this->roomTaskId);

		self::assertSame(200, $response->getStatus());
		self::assertSame(1, $this->countCompletions($this->roomTaskId));
	}

	public function testMemberCanCompletePublicTaskAssignedToOtherUser(): void {
		// Decision D2: PUBLIC tasks are completable by any house member —
		// the household-collaboration contract.
		$controller = $this->getController(RoomTaskController::class, 'ta_other_member');

		$response = $controller->complete($this->roomTaskId);

		self::assertSame(200, $response->getStatus());
		self::assertSame(1, $this->countCompletions($this->roomTaskId));
	}

	public function testChildCanCompleteAssignedTask(): void {
		// Children keep the execution surface (decision D5): the OWNER
		// assigns the task to the child, the child completes it.
		$this->actingAs('ta_owner');
		$roomTaskService = \OC::$server->get(\OCA\ChoreStrike\Service\RoomTaskService::class);
		$owner = \OC::$server->get(\OCA\ChoreStrike\Db\HouseUserMapper::class)
			->findByNextcloudUserId($this->houseId, 'ta_owner');
		$roomTaskService->setAssignees($this->roomTaskId, ['ta_child'], $this->houseId, $owner);

		$controller = $this->getController(RoomTaskController::class, 'ta_child');
		$response = $controller->complete($this->roomTaskId);

		self::assertSame(200, $response->getStatus());
		self::assertSame(1, $this->countCompletions($this->roomTaskId));
	}

	public function testChildCannotDeleteOwnersTask(): void {
		$controller = $this->getController(RoomTaskController::class, 'ta_child');

		$response = $controller->delete($this->roomTaskId);

		self::assertSame(403, $response->getStatus());
		$data = $response->getData();
		self::assertSame('FORBIDDEN', $data['error']['code']);
		self::assertSame(1, $this->fetchRoomTaskActive($this->roomTaskId), 'the row must be unchanged');
	}

	public function testChildCannotCreateRoomTask(): void {
		// CS-PLAN-063: creation runs through the atomic aggregate; children
		// are denied by the actor policy (403) before any mapper call.
		$controller = $this->getBulkController('ta_child', [
			'roomId' => $this->roomId,
			'taskId' => $this->taskId,
		]);

		$response = $controller->create();

		self::assertSame(403, $response->getStatus());
		self::assertSame(1, $this->countRoomTasksForTask($this->taskId), 'no second room task may be created');
	}

	public function testChildCannotAssignTasks(): void {
		$controller = $this->getController(RoomTaskController::class, 'ta_child');

		$response = $controller->setAssignees($this->roomTaskId, ['ta_member']);

		self::assertSame(403, $response->getStatus());
		$data = $response->getData();
		self::assertSame('FORBIDDEN', $data['error']['code']);
	}

	public function testChildCannotBulkAssignTasks(): void {
		$bulkController = $this->getBulkController('ta_child', ['roomTaskIds' => [$this->roomTaskId], 'userId' => 'ta_child']);
		$response = $bulkController->bulkAssign();

		self::assertSame(403, $response->getStatus());
	}

	public function testMemberCanBulkAssignWithinHouse(): void {
		$bulkController = $this->getBulkController('ta_owner', ['roomTaskIds' => [$this->roomTaskId], 'userId' => 'ta_other_member']);
		$response = $bulkController->bulkAssign();

		self::assertSame(200, $response->getStatus());
		$assignees = \OC::$server->get(\OCA\ChoreStrike\Db\TaskAssigneeMapper::class)
			->findUserIdsByRoomTask($this->roomTaskId);
		self::assertSame(['ta_other_member'], $assignees);
	}

	public function testOwnerCanDeleteCompletedTask(): void {
		$controller = $this->getController(RoomTaskController::class, 'ta_member');
		$response = $controller->complete($this->roomTaskId);
		self::assertSame(200, $response->getStatus());

		$ownerController = $this->getController(RoomTaskController::class, 'ta_owner');
		$deleteResponse = $ownerController->delete($this->roomTaskId);

		self::assertSame(200, $deleteResponse->getStatus(), 'an in-house owner may delete a completed task');
	}

	public function testDeleteNonexistentRoomTask_Returns404(): void {
		$controller = $this->getController(RoomTaskController::class, 'ta_owner');

		$response = $controller->delete(999999);

		self::assertSame(404, $response->getStatus());
		self::assertSame('RESOURCE_NOT_FOUND', $response->getData()['error']['code']);
	}

	// ========== Residual surface visibility (CS-PLAN-061 review fixes) ==========

	public function testAssigneesReadOfInvisibleTask_Returns404(): void {
		// The assignee list of an invisible task is a 404 — reading it
		// would leak the assignment graph of a private task.
		$controller = $this->getController(RoomTaskController::class, 'ta_child');

		$response = $controller->assignees($this->privateRoomTaskId);

		self::assertSame(404, $response->getStatus());
		self::assertSame('RESOURCE_NOT_FOUND', $response->getData()['error']['code']);
	}

	public function testAssigneesReadOfVisibleTask_ReturnsAssignees(): void {
		$controller = $this->getController(RoomTaskController::class, 'ta_owner');

		$response = $controller->assignees($this->privateRoomTaskId);

		self::assertSame(200, $response->getStatus());
		self::assertSame(['ta_owner'], $response->getData()['data']);
	}

	public function testRoomStats_ExcludeInvisibleTasks(): void {
		// The kitchen holds the public room task and the private one: the
		// child sees only the public task in the counts; the creator sees
		// both.
		$childController = $this->getController(RoomTaskController::class, 'ta_child');
		$childResponse = $childController->roomStats($this->roomId);
		self::assertSame(200, $childResponse->getStatus());
		self::assertSame(1, $childResponse->getData()['data']['totalTasks']);

		$ownerController = $this->getController(RoomTaskController::class, 'ta_owner');
		$ownerResponse = $ownerController->roomStats($this->roomId);
		self::assertSame(200, $ownerResponse->getStatus());
		self::assertSame(2, $ownerResponse->getData()['data']['totalTasks']);
	}

	// ========== Helpers ==========

	private function setTaskVisibility(int $taskId, string $visibilityType, ?string $ownerUserId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('chorestrike_tasks')
			->set('visibility_type', $qb->createNamedParameter($visibilityType))
			->set('owner_user_id', $qb->createNamedParameter($ownerUserId))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($taskId)));
		$qb->executeStatement();
	}

	private function purgeInstanceHouseMemberships(): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('chorestrike_house_users')
			->where($qb->expr()->eq('house_id', $qb->createNamedParameter($this->houseId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	private function getBulkController(string $userId, array $params): RoomTaskController {
		$request = $this->createMock(\OCP\IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null) => $params[$key] ?? $default
		);
		// Identity mock: IL10N is not resolvable from the root container.
		$l10n = $this->createMock(\OCP\IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text) => $text);
		$this->actingAs($userId);
		return new RoomTaskController(
			$request,
			\OC::$server->get(\OCA\ChoreStrike\Service\RoomTaskService::class),
			\OC::$server->get(\OCA\ChoreStrike\Service\CompletionService::class),
			\OC::$server->get(\OCA\ChoreStrike\Service\TaskCreationService::class),
			\OC::$server->get(\OCA\ChoreStrike\Service\HouseService::class),
			\OC::$server->get(\OCA\ChoreStrike\Service\AuthorizationService::class),
			$l10n,
			\OC::$server->get(\OCA\ChoreStrike\Service\CalendarService::class),
			\OC::$server->get(\OCA\ChoreStrike\Service\AppLogger::class),
		);
	}

	private function countCompletions(int $roomTaskId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))
			->from('chorestrike_completed_tasks')
			->where($qb->expr()->eq('room_task_id', $qb->createNamedParameter($roomTaskId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
		return (int)$qb->executeQuery()->fetchOne();
	}

	private function countRoomTasksForTask(int $taskId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))
			->from('chorestrike_room_tasks')
			->where($qb->expr()->eq('task_id', $qb->createNamedParameter($taskId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
		return (int)$qb->executeQuery()->fetchOne();
	}

	private function fetchRoomTaskActive(int $roomTaskId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select('is_active')
			->from('chorestrike_room_tasks')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($roomTaskId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
		return (int)$qb->executeQuery()->fetchOne();
	}
}
