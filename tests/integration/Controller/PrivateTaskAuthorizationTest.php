<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Controller;

use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Service\RoomService;
use OCA\ChoreStrike\Service\TaskService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;

/**
 * Integration tests for private-task visibility and completion gates.
 *
 * Verifies the CS-PLAN-005 contract:
 * - Private tasks are visible only to their creator (owner_user_id)
 * - Other members/children cannot see or complete private tasks
 * - Owners see only their OWN private tasks (strict privacy default)
 * - GET /tasks/my-private returns only the caller's private tasks
 * - Completion of a private task is restricted to assigned users
 *
 * @see docs/plans/active/cs-plan-005-task-visibility-teamwork-overdue.md Phase 2
 */
class PrivateTaskAuthorizationTest extends IntegrationTestCase {
	private int $houseId;
	private int $floorId;
	private int $roomId;
	private int $publicTaskId;
	private int $privateTaskId;
	private int $otherPrivateTaskId;
	private int $privateRoomTaskId;

	protected function setUp(): void {
		parent::setUp();

		$this->houseId = $this->createHouse('priv_owner', 'Privacy House');
		$this->addHouseMember($this->houseId, 'priv_member', 'Member', 'member');
		$this->addHouseMember($this->houseId, 'priv_child', 'Child', 'child');

		$this->floorId = $this->createFloor($this->houseId, 'Ground Floor', 1);
		$this->roomId = $this->createRoom($this->houseId, $this->floorId, 'Kitchen');

		$this->publicTaskId = $this->createTask($this->houseId, 'Public Dishes', 'Wash dishes', 5, 10);
		$this->privateTaskId = $this->createTask($this->houseId, 'Owner Reminder', 'Personal note', 3, 5);
		$this->otherPrivateTaskId = $this->createTask($this->houseId, 'Member Reminder', 'Member note', 2, 5);

		$this->setTaskVisibility($this->privateTaskId, Task::VISIBILITY_PRIVATE, 'priv_owner');
		$this->setTaskVisibility($this->otherPrivateTaskId, Task::VISIBILITY_PRIVATE, 'priv_member');

		$this->privateRoomTaskId = $this->assignTaskToRoom($this->privateTaskId, $this->roomId, 'priv_owner');
	}

	// ========== Visibility Matrix ==========

	public function testOwnerSeesPublicAndOwnPrivateTasks(): void {
		$this->actingAs('priv_owner');
		$taskMapper = \OC::$server->get(TaskMapper::class);
		$houseUserMapper = \OC::$server->get(HouseUserMapper::class);
		$viewer = $houseUserMapper->findByNextcloudUserId($this->houseId, 'priv_owner');

		$visible = $taskMapper->findVisibleByHouse($this->houseId, $viewer);
		$visibleIds = array_map(fn (Task $t) => $t->getId(), $visible);

		self::assertContains($this->publicTaskId, $visibleIds);
		self::assertContains($this->privateTaskId, $visibleIds);
		self::assertNotContains($this->otherPrivateTaskId, $visibleIds);
	}

	public function testMemberSeesPublicAndOwnPrivateOnly(): void {
		$this->actingAs('priv_member');
		$taskMapper = \OC::$server->get(TaskMapper::class);
		$houseUserMapper = \OC::$server->get(HouseUserMapper::class);
		$viewer = $houseUserMapper->findByNextcloudUserId($this->houseId, 'priv_member');

		$visible = $taskMapper->findVisibleByHouse($this->houseId, $viewer);
		$visibleIds = array_map(fn (Task $t) => $t->getId(), $visible);

		self::assertContains($this->publicTaskId, $visibleIds);
		self::assertContains($this->otherPrivateTaskId, $visibleIds);
		self::assertNotContains($this->privateTaskId, $visibleIds);
	}

	public function testChildSeesPublicAndOwnPrivateOnly(): void {
		$this->actingAs('priv_child');
		$taskMapper = \OC::$server->get(TaskMapper::class);
		$houseUserMapper = \OC::$server->get(HouseUserMapper::class);
		$viewer = $houseUserMapper->findByNextcloudUserId($this->houseId, 'priv_child');

		$visible = $taskMapper->findVisibleByHouse($this->houseId, $viewer);
		$visibleIds = array_map(fn (Task $t) => $t->getId(), $visible);

		self::assertContains($this->publicTaskId, $visibleIds);
		self::assertNotContains($this->privateTaskId, $visibleIds);
		self::assertNotContains($this->otherPrivateTaskId, $visibleIds);
	}

	public function testOwnerDoesNotSeeOtherUsersPrivateTasks(): void {
		$this->actingAs('priv_owner');
		$taskMapper = \OC::$server->get(TaskMapper::class);
		$houseUserMapper = \OC::$server->get(HouseUserMapper::class);
		$viewer = $houseUserMapper->findByNextcloudUserId($this->houseId, 'priv_owner');

		$visible = $taskMapper->findVisibleByHouse($this->houseId, $viewer);
		$visibleIds = array_map(fn (Task $t) => $t->getId(), $visible);

		self::assertNotContains($this->otherPrivateTaskId, $visibleIds);
	}

	// ========== My-Private Endpoint ==========

	public function testGetMyPrivateTasksReturnsOnlyOwnPrivate(): void {
		$this->actingAs('priv_owner');
		$taskService = \OC::$server->get(TaskService::class);

		$myPrivate = $taskService->getMyPrivateTasks('priv_owner');
		$myPrivateIds = array_map(fn (Task $t) => $t->getId(), $myPrivate);

		self::assertContains($this->privateTaskId, $myPrivateIds);
		self::assertNotContains($this->otherPrivateTaskId, $myPrivateIds);
		self::assertNotContains($this->publicTaskId, $myPrivateIds);
	}

	public function testGetMyPrivateTasksReturnsEmptyForUserWithNoPrivateTasks(): void {
		$this->actingAs('priv_child');
		$taskService = \OC::$server->get(TaskService::class);

		$myPrivate = $taskService->getMyPrivateTasks('priv_child');

		self::assertEmpty($myPrivate);
	}

	// ========== Completion Gate ==========

	public function testAssignedUserCanCompletePrivateTask(): void {
		$this->actingAs('priv_owner');
		$roomService = \OC::$server->get(RoomService::class);

		$result = $roomService->completeRoomTask($this->privateRoomTaskId, 'priv_owner');

		self::assertArrayHasKey('rewards', $result);
		self::assertArrayHasKey('coins', $result['rewards']);
	}

	public function testNonAssigneeCannotCompletePrivateTask(): void {
		$this->actingAs('priv_member');
		$roomService = \OC::$server->get(RoomService::class);

		$this->expectException(ForbiddenException::class);
		$this->expectExceptionMessage('Private tasks may only be completed by assigned users');
		$roomService->completeRoomTask($this->privateRoomTaskId, 'priv_member');
	}

	public function testChildCannotCompletePrivateTaskAssignedToOwner(): void {
		$this->actingAs('priv_child');
		$roomService = \OC::$server->get(RoomService::class);

		$this->expectException(ForbiddenException::class);
		$this->expectExceptionMessage('Private tasks may only be completed by assigned users');
		$roomService->completeRoomTask($this->privateRoomTaskId, 'priv_child');
	}

	// ========== Task Entity ==========

	public function testTaskVisibilityTypeIsCanonical(): void {
		$this->actingAs('priv_owner');
		$taskMapper = \OC::$server->get(TaskMapper::class);

		$task = $taskMapper->find($this->privateTaskId);

		self::assertSame(Task::VISIBILITY_PRIVATE, $task->getVisibilityType());
		self::assertSame('priv_owner', $task->getOwnerUserId());
	}

	public function testPublicTaskVisibilityType(): void {
		$this->actingAs('priv_owner');
		$taskMapper = \OC::$server->get(TaskMapper::class);

		$task = $taskMapper->find($this->publicTaskId);

		self::assertSame(Task::VISIBILITY_PUBLIC, $task->getVisibilityType());
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
}
