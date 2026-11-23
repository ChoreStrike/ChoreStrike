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
use OCA\ChoreStrike\Service\AppLogger;
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
 * endpoint, reject foreign items in toggle, and keep the happy paths
 * working. The sub-tasks themselves are written by the one form's save
 * (CS-PLAN-091 WP-B6, RoomTaskEditTest); the four item writers are gone.
 *
 * The controller resolves the shared instance house, so the tests run
 * against it (controlled IRequest
 * mock, real service/DB stack below); strict cross-house isolation is
 * proven with an isolated foreign fixture house. Cross-house assertions
 * verify persisted rows directly — never via a foreign-owner controller
 * call, which would be denied by the invite-only membership model.
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
		$this->seedMembership($this->instanceHouseId, self::OWNER, 'Chk Owner', 'owner');
		$this->seedMembership($this->instanceHouseId, self::MEMBER, 'Chk Member', 'member');
	}

	/**
	 * Invite-only membership (ADR-010): seed explicitly after purging any
	 * leftover rows from earlier runs.
	 */
	private function seedMembership(int $houseId, string $userId, string $displayName, string $role): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('chorestrike_house_users')
			->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('nextcloud_user_id', $qb->createNamedParameter($userId)));
		$qb->executeStatement();

		$this->addHouseMember($houseId, $userId, $displayName, $role);
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

	// ========== Item <-> task linkage ==========

	public function testToggleItem_ItemFromAnotherTask_Returns404(): void {
		$fixture = $this->seedOwnPublicTaskWithItems(1);
		['itemIds' => [$otherItemId]] = $this->seedOwnPublicTaskWithItems(1, 'Other');

		$response = $this->makeController(self::MEMBER)->toggleItem($fixture['roomTaskId'], $otherItemId);

		self::assertSame(404, $response->getStatus(), 'an item of another task must not toggle this room task\'s progress');
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

	public function testToggleAndGetProgress_HappyPath(): void {
		$fixture = $this->seedOwnPublicTaskWithItems(2);

		// Toggle auto-creates the progress row (the pruned explicit
		// initialize endpoint is no longer needed — CS-PLAN-055).
		$this->makeController(self::MEMBER)
			->toggleItem($fixture['roomTaskId'], $fixture['itemIds'][0]);
		$this->makeController(self::MEMBER)
			->toggleItem($fixture['roomTaskId'], $fixture['itemIds'][1]);
		$progressResponse = $this->makeController(self::MEMBER)
			->getRoomTaskProgress($fixture['roomTaskId']);

		self::assertSame(200, $progressResponse->getStatus());
		$data = $progressResponse->getData()['data'];
		self::assertCount(2, $data['items']);
		self::assertSame(2, $data['stats']['total']);
		self::assertSame(2, $data['stats']['checked']);
	}

	/**
	 * Final review of CS-PLAN-091: the ticks of one room task follow that
	 * room task's assignees. A private definition shared by two rooms is
	 * visible to the member in the room they are assigned to only — the
	 * other room's progress is neither readable nor writable.
	 */
	public function testRoomTaskProgress_SharedPrivateDefinition_FollowsTheRoomTasksOwnAssignees(): void {
		$floorId = $this->createFloor($this->instanceHouseId, 'Chk Shared Floor');
		$taskId = $this->createTask($this->instanceHouseId, 'Chk Shared Private ' . uniqid());
		$this->taskIds[] = $taskId;
		$mine = $this->trackRoomTask($this->assignTaskToRoom($taskId, $this->createRoom($this->instanceHouseId, $floorId, 'Chk Mine'), self::MEMBER));
		$hidden = $this->trackRoomTask($this->assignTaskToRoom($taskId, $this->createRoom($this->instanceHouseId, $floorId, 'Chk Hidden')));
		$qb = $this->db->getQueryBuilder();
		$qb->update('chorestrike_tasks')
			->set('visibility_type', $qb->createNamedParameter(Task::VISIBILITY_PRIVATE))
			->set('owner_user_id', $qb->createNamedParameter(self::OWNER))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($taskId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
		$itemId = $this->createItem($taskId, 'Shared item', 0);

		self::assertSame(200, $this->makeController(self::MEMBER)->getRoomTaskProgress($mine)->getStatus(), 'the assigned room stays readable');
		self::assertSame(404, $this->makeController(self::MEMBER)->getRoomTaskProgress($hidden)->getStatus(), 'the other room\'s window and ticks stay hidden');
		self::assertSame(404, $this->makeController(self::MEMBER)->toggleItem($hidden, $itemId)->getStatus(), 'the other room\'s ticks stay unwritable');
		self::assertSame(200, $this->makeController(self::OWNER)->getRoomTaskProgress($hidden)->getStatus(), 'the creator still sees both rooms');
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
			\OC::$server->get(AppLogger::class),
		);
	}
}
