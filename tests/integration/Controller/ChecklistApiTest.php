<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Controller;

use OCA\ChoreStrike\Controller\ChecklistController;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Db\TaskChecklistItemMapper;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\ChecklistService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Real-DB integration tests for the checklist endpoints (CR-053-01 /
 * CR-053-22 partial): the ChecklistService guard must reject cross-house
 * task/item access and visibility-matrix denials with 404 on every
 * endpoint, reject foreign items in toggle/reorder, and keep the happy
 * paths working.
 *
 * The controller resolves the shared instance house, so the tests run
 * against it (BulkAssignIntegrationTest pattern: controlled IRequest
 * mock, real service/DB stack below); strict cross-house isolation is
 * proven with an isolated foreign fixture house. Cross-house assertions
 * verify persisted rows directly — never via a foreign-owner controller
 * call, which would auto-join the foreign user into the instance house.
 */
class ChecklistApiTest extends IntegrationTestCase {
	private const OWNER = 'chk_owner';
	private const MEMBER = 'chk_member';
	private const FOREIGN_OWNER = 'chk_foreign_owner';

	private int $instanceHouseId = 0;
	/** @var array<int> */
	private array $taskIds = [];
	/** @var array<int> */
	private array $roomTaskIds = [];

	protected function setUp(): void {
		parent::setUp();

		$houseService = \OC::$server->get(HouseService::class);
		$this->instanceHouseId = $houseService
			->getOrCreateHouse($houseService->getInstanceId())
			->getId();
		$this->addHouseMember($this->instanceHouseId, self::OWNER, 'Chk Owner', 'owner');
		$this->addHouseMember($this->instanceHouseId, self::MEMBER, 'Chk Member', 'member');
	}

	protected function tearDown(): void {
		// Checklist rows have no FK constraints and are not tracked by the
		// base cleanup — remove them before their tasks/room tasks go.
		if ($this->taskIds !== []) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('chorestrike_chk_items')
				->where($qb->expr()->in('task_id', $qb->createNamedParameter($this->taskIds, IQueryBuilder::PARAM_INT_ARRAY)));
			$qb->executeStatement();
		}
		if ($this->roomTaskIds !== []) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('chorestrike_chk_progress')
				->where($qb->expr()->in('room_task_id', $qb->createNamedParameter($this->roomTaskIds, IQueryBuilder::PARAM_INT_ARRAY)));
			$qb->executeStatement();
		}

		parent::tearDown();
	}

	// ========== Cross-house denials (all endpoints) ==========

	public function testGetTaskItems_CrossHouseTask_Returns404(): void {
		[$taskId] = $this->seedForeignTaskWithItems();

		$response = $this->makeController(self::MEMBER)->getTaskItems($taskId);

		self::assertSame(404, $response->getStatus());
		self::assertSame('RESOURCE_NOT_FOUND', $response->getData()['error']['code']);
	}

	public function testAddTaskItem_CrossHouseTask_Returns404AndCreatesNothing(): void {
		[$taskId] = $this->seedForeignTaskWithItems();

		$response = $this->makeController(self::MEMBER)->addTaskItem($taskId, 'Injected');

		self::assertSame(404, $response->getStatus());
		self::assertSame(1, $this->countItems($taskId), 'no item may be created for a foreign task');
	}

	public function testUpdateTaskItem_CrossHouseItem_Returns404AndKeepsLabel(): void {
		[$taskId, $itemId] = $this->seedForeignTaskWithItems();

		$response = $this->makeController(self::MEMBER)->updateTaskItem($itemId, 'Hijacked');

		self::assertSame(404, $response->getStatus());
		self::assertSame('Foreign item', $this->fetchItemColumn($itemId, 'label'), 'the label must be unchanged');
	}

	public function testDeleteTaskItem_CrossHouseItem_Returns404AndKeepsRow(): void {
		[$taskId, $itemId] = $this->seedForeignTaskWithItems();

		$response = $this->makeController(self::MEMBER)->deleteTaskItem($itemId);

		self::assertSame(404, $response->getStatus());
		self::assertSame(1, $this->countItems($taskId), 'the foreign item must remain');
	}

	public function testReorderTaskItems_CrossHouseItem_Returns404AndKeepsOrder(): void {
		[, $itemId] = $this->seedForeignTaskWithItems();

		$response = $this->makeController(self::MEMBER, ['itemOrders' => [(string)$itemId => 99]])
			->reorderTaskItems();

		self::assertSame(404, $response->getStatus());
		self::assertSame('0', (string)$this->fetchItemColumn($itemId, 'sort_order'), 'the foreign sort order must remain');
	}

	public function testGetRoomTaskProgress_CrossHouseRoomTask_Returns404(): void {
		[, , $foreignRoomTaskId] = $this->seedForeignRoomTask();

		$response = $this->makeController(self::MEMBER)->getRoomTaskProgress($foreignRoomTaskId);

		self::assertSame(404, $response->getStatus());
		self::assertSame('RESOURCE_NOT_FOUND', $response->getData()['error']['code']);
	}

	public function testToggleItem_CrossHouseRoomTask_Returns404(): void {
		[, , $foreignRoomTaskId] = $this->seedForeignRoomTask();

		$response = $this->makeController(self::MEMBER)->toggleItem($foreignRoomTaskId, 1);

		self::assertSame(404, $response->getStatus());
	}

	public function testInitializeProgress_CrossHouseRoomTask_Returns404(): void {
		[$foreignTaskId, , $foreignRoomTaskId] = $this->seedForeignRoomTask();

		$response = $this->makeController(self::MEMBER)->initializeProgress($foreignRoomTaskId, $foreignTaskId);

		self::assertSame(404, $response->getStatus());
	}

	public function testGetCompleteChecklist_CrossHouseRoomTask_Returns404(): void {
		[$foreignTaskId, , $foreignRoomTaskId] = $this->seedForeignRoomTask();

		$response = $this->makeController(self::MEMBER)->getCompleteChecklist($foreignRoomTaskId, $foreignTaskId);

		self::assertSame(404, $response->getStatus());
		self::assertSame('RESOURCE_NOT_FOUND', $response->getData()['error']['code']);
	}

	// ========== Visibility matrix ==========

	public function testGetTaskItems_PrivateTask_MemberGets404OwnerGets200(): void {
		[$privateTaskId] = $this->seedVisibilityTaskWithItems(Task::VISIBILITY_PRIVATE, self::OWNER);
		['taskId' => $publicTaskId] = $this->seedOwnPublicTaskWithItems(1);

		$memberResponse = $this->makeController(self::MEMBER)->getTaskItems($privateTaskId);
		$ownerResponse = $this->makeController(self::OWNER)->getTaskItems($privateTaskId);
		$memberPublic = $this->makeController(self::MEMBER)->getTaskItems($publicTaskId);

		self::assertSame(404, $memberResponse->getStatus(), 'a member must not see a foreign private task\'s items');
		self::assertSame(200, $ownerResponse->getStatus(), 'the task owner must see their private task\'s items');
		self::assertSame(200, $memberPublic->getStatus(), 'public task items stay visible to members');
	}

	public function testGetTaskItems_AdminOnlyTask_MemberGets404OwnerGets200(): void {
		[$adminTaskId] = $this->seedVisibilityTaskWithItems(Task::VISIBILITY_ADMIN_ONLY, null);

		$memberResponse = $this->makeController(self::MEMBER)->getTaskItems($adminTaskId);
		$ownerResponse = $this->makeController(self::OWNER)->getTaskItems($adminTaskId);

		self::assertSame(404, $memberResponse->getStatus());
		self::assertSame(200, $ownerResponse->getStatus());
	}

	public function testAddTaskItem_PrivateTask_MemberGets404(): void {
		[$privateTaskId] = $this->seedVisibilityTaskWithItems(Task::VISIBILITY_PRIVATE, self::OWNER);

		$response = $this->makeController(self::MEMBER)->addTaskItem($privateTaskId, 'Nope');

		self::assertSame(404, $response->getStatus());
		self::assertSame(1, $this->countItems($privateTaskId));
	}

	// ========== Item <-> task linkage ==========

	public function testToggleItem_ItemFromAnotherTask_Returns404(): void {
		$fixture = $this->seedOwnPublicTaskWithItems(1);
		['itemIds' => [$otherItemId]] = $this->seedOwnPublicTaskWithItems(1, 'Other');

		$response = $this->makeController(self::MEMBER)->toggleItem($fixture['roomTaskId'], $otherItemId);

		self::assertSame(404, $response->getStatus(), 'an item of another task must not toggle this room task\'s progress');
	}

	public function testReorderTaskItems_ItemsFromMultipleTasks_Returns400(): void {
		$fixture = $this->seedOwnPublicTaskWithItems(1);
		['itemIds' => [$otherItemId]] = $this->seedOwnPublicTaskWithItems(1, 'Other');

		$response = $this->makeController(self::OWNER, ['itemOrders' => [
			(string)$fixture['itemIds'][0] => 1,
			(string)$otherItemId => 0,
		]])->reorderTaskItems();

		self::assertSame(400, $response->getStatus());
		self::assertSame('VALIDATION_ERROR', $response->getData()['error']['code']);
	}

	public function testReorderTaskItems_MalformedPayload_Returns400(): void {
		$fixture = $this->seedOwnPublicTaskWithItems(1);

		$response = $this->makeController(self::OWNER, ['itemOrders' => ['bogus' => 1]])
			->reorderTaskItems();

		self::assertSame(400, $response->getStatus());
		self::assertSame('VALIDATION_ERROR', $response->getData()['error']['code']);
	}

	// ========== Happy paths ==========

	public function testGetTaskItems_HappyPath(): void {
		['taskId' => $taskId] = $this->seedOwnPublicTaskWithItems(2);

		$response = $this->makeController(self::MEMBER)->getTaskItems($taskId);

		self::assertSame(200, $response->getStatus());
		$data = $response->getData();
		self::assertTrue($data['success']);
		self::assertCount(2, $data['data']);
		self::assertSame('Item 0', $data['data'][0]['label']);
	}

	public function testGetTaskItems_UnlinkedTask_TreatedAsHouseLinked(): void {
		// A task without any room-task linkage yet falls back to
		// house-linked (single-house-per-instance today).
		$taskId = $this->createTask($this->instanceHouseId, 'Chk Unlinked Task');
		$this->taskIds[] = $taskId;
		$this->createItem($taskId, 'Unlinked item', 0);

		$response = $this->makeController(self::MEMBER)->getTaskItems($taskId);

		self::assertSame(200, $response->getStatus());
		self::assertCount(1, $response->getData()['data']);
	}

	public function testAddTaskItem_HappyPath_AppendsSortOrder(): void {
		['taskId' => $taskId] = $this->seedOwnPublicTaskWithItems(2);

		$response = $this->makeController(self::MEMBER)->addTaskItem($taskId, 'Third');

		self::assertSame(200, $response->getStatus());
		$data = $response->getData();
		self::assertTrue($data['success']);
		self::assertSame(2, $data['data']['sortOrder'], 'a missing sortOrder appends after the existing items');
	}

	public function testUpdateTaskItem_HappyPath(): void {
		['itemIds' => [$itemId]] = $this->seedOwnPublicTaskWithItems(1);

		$response = $this->makeController(self::MEMBER)->updateTaskItem($itemId, 'Relabeled');

		self::assertSame(200, $response->getStatus());
		self::assertSame('Relabeled', $this->fetchItemColumn($itemId, 'label'));
	}

	public function testDeleteTaskItem_HappyPath(): void {
		['taskId' => $taskId, 'itemIds' => [$itemId]] = $this->seedOwnPublicTaskWithItems(1);

		$response = $this->makeController(self::MEMBER)->deleteTaskItem($itemId);

		self::assertSame(200, $response->getStatus());
		self::assertSame(0, $this->countItems($taskId));
	}

	public function testReorderTaskItems_HappyPath_SwapsOrder(): void {
		['taskId' => $taskId] = $this->seedOwnPublicTaskWithItems(2);
		$items = \OC::$server->get(TaskChecklistItemMapper::class)->findByTaskId($taskId);
		[$firstItemId, $secondItemId] = array_map(static fn ($item): int => $item->getId(), $items);

		$response = $this->makeController(self::MEMBER, ['itemOrders' => [
			(string)$firstItemId => 1,
			(string)$secondItemId => 0,
		]])->reorderTaskItems();

		self::assertSame(200, $response->getStatus());
		$sorted = $this->makeController(self::OWNER)->getTaskItems($taskId)->getData()['data'];
		self::assertSame($secondItemId, $sorted[0]['id']);
		self::assertSame($firstItemId, $sorted[1]['id']);
	}

	public function testInitializeAndGetProgress_HappyPath(): void {
		$fixture = $this->seedOwnPublicTaskWithItems(2);

		$initResponse = $this->makeController(self::MEMBER)
			->initializeProgress($fixture['roomTaskId'], $fixture['taskId']);
		$progressResponse = $this->makeController(self::MEMBER)
			->getRoomTaskProgress($fixture['roomTaskId']);

		self::assertSame(200, $initResponse->getStatus());
		self::assertSame(200, $progressResponse->getStatus());
		$data = $progressResponse->getData()['data'];
		self::assertCount(2, $data['items']);
		self::assertSame(2, $data['stats']['total']);
		self::assertSame(0, $data['stats']['checked']);
	}

	public function testToggleItem_HappyPath_TogglesAndReportsStats(): void {
		$fixture = $this->seedOwnPublicTaskWithItems(1);

		$response = $this->makeController(self::MEMBER)
			->toggleItem($fixture['roomTaskId'], $fixture['itemIds'][0]);

		self::assertSame(200, $response->getStatus());
		$data = $response->getData()['data'];
		self::assertTrue($data['progress']['isChecked']);
		self::assertSame(1, $data['stats']['checked']);
	}

	public function testGetCompleteChecklist_HappyPath(): void {
		$fixture = $this->seedOwnPublicTaskWithItems(2);

		$this->makeController(self::MEMBER)->initializeProgress($fixture['roomTaskId'], $fixture['taskId']);
		$this->makeController(self::MEMBER)->toggleItem($fixture['roomTaskId'], $fixture['itemIds'][0]);

		$response = $this->makeController(self::MEMBER)
			->getCompleteChecklist($fixture['roomTaskId'], $fixture['taskId']);

		self::assertSame(200, $response->getStatus());
		$data = $response->getData()['data'];
		self::assertCount(2, $data['items']);
		self::assertTrue($data['items'][0]['isChecked']);
		self::assertFalse($data['items'][1]['isChecked']);
		self::assertSame(1, $data['stats']['checked']);
	}

	// ========== Fixtures ==========

	/**
	 * Seed a public task in the shared instance house with $count items.
	 *
	 * @return array{taskId: int, roomTaskId: int, itemIds: list<int>}
	 */
	private function seedOwnPublicTaskWithItems(int $count, string $labelPrefix = 'Item'): array {
		$floorId = $this->createFloor($this->instanceHouseId, 'Chk Floor');
		$roomId = $this->createRoom($this->instanceHouseId, $floorId, 'Chk Room');

		$taskId = $this->createTask($this->instanceHouseId, 'Chk Public Task ' . uniqid());
		$this->taskIds[] = $taskId;
		$roomTaskId = $this->trackRoomTask($this->assignTaskToRoom($taskId, $roomId));

		$itemIds = [];
		for ($i = 0; $i < $count; $i++) {
			$itemIds[] = $this->createItem($taskId, "{$labelPrefix} {$i}", $i);
		}

		return [
			'taskId' => $taskId,
			'roomTaskId' => $roomTaskId,
			'itemIds' => $itemIds,
		];
	}

	/**
	 * @return array{0: int, 1: int} [taskId, itemId]
	 */
	private function seedVisibilityTaskWithItems(string $visibility, ?string $ownerUserId): array {
		$floorId = $this->createFloor($this->instanceHouseId, 'Chk Vis Floor');
		$roomId = $this->createRoom($this->instanceHouseId, $floorId, 'Chk Vis Room');

		$taskId = $this->createTask($this->instanceHouseId, 'Chk Task ' . uniqid());
		$this->taskIds[] = $taskId;
		$this->trackRoomTask($this->assignTaskToRoom($taskId, $roomId));

		$qb = $this->db->getQueryBuilder();
		$qb->update('chorestrike_tasks')
			->set('visibility_type', $qb->createNamedParameter($visibility))
			->set('owner_user_id', $qb->createNamedParameter($ownerUserId))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($taskId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();

		return [$taskId, $this->createItem($taskId, 'Vis item', 0)];
	}

	/**
	 * Seed one foreign-house task with one item.
	 *
	 * @return array{0: int, 1: int} [taskId, itemId]
	 */
	private function seedForeignTaskWithItems(): array {
		[$taskId, , , $itemId] = $this->seedForeignRoomTask();

		return [$taskId, $itemId];
	}

	/**
	 * Seed one foreign-house room task (with a checklist item).
	 *
	 * @return array{0: int, 1: int, 2: int, 3: int} [taskId, roomId, roomTaskId, itemId]
	 */
	private function seedForeignRoomTask(): array {
		$foreignHouseId = $this->createHouse(self::FOREIGN_OWNER, 'Chk Foreign House ' . uniqid());
		$floorId = $this->createFloor($foreignHouseId, 'Chk Foreign Floor');
		$roomId = $this->createRoom($foreignHouseId, $floorId, 'Chk Foreign Room');

		$taskId = $this->createTask($foreignHouseId, 'Chk Foreign Task ' . uniqid());
		$this->taskIds[] = $taskId;
		$roomTaskId = $this->trackRoomTask($this->assignTaskToRoom($taskId, $roomId));

		return [$taskId, $roomId, $roomTaskId, $this->createItem($taskId, 'Foreign item', 0)];
	}

	private function createItem(int $taskId, string $label, int $sortOrder): int {
		$item = \OC::$server->get(TaskChecklistItemMapper::class)->create($taskId, $label, $sortOrder);
		return $item->getId();
	}

	private function countItems(int $taskId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'count'))
			->from('chorestrike_chk_items')
			->where($qb->expr()->eq('task_id', $qb->createNamedParameter($taskId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		return $count;
	}

	private function fetchItemColumn(int $itemId, string $column): string {
		$qb = $this->db->getQueryBuilder();
		$qb->select($column)
			->from('chorestrike_chk_items')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($itemId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$value = $result->fetchOne();
		$result->closeCursor();

		return (string)$value;
	}

	private function trackRoomTask(int $roomTaskId): int {
		$this->roomTaskIds[] = $roomTaskId;
		return $roomTaskId;
	}

	private function makeController(string $userId, array $params = []): ChecklistController {
		$this->actingAs($userId);
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')
			->willReturnCallback(fn (string $key, mixed $default = null): mixed => $params[$key] ?? $default);

		// Identity mock: message assertions match the wrapped source text.
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text) => $text);

		return new ChecklistController(
			$request,
			\OC::$server->get(ChecklistService::class),
			\OC::$server->get(HouseService::class),
			\OC::$server->get(AuthorizationService::class),
			$l10n,
		);
	}
}
