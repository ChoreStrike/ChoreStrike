<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Controller;

use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Service\RoomService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;

/**
 * Integration tests for admin-only task visibility and completion gates.
 *
 * Verifies the CS-PLAN-005 contract:
 * - Admin-only tasks are visible only to house owners
 * - Members and children cannot see admin-only tasks
 * - Only owners can complete admin-only tasks (403 for non-owners)
 * - Admin-only tasks DO grant rewards (unlike private tasks)
 *
 * @see docs/plans/active/cs-plan-005-task-visibility-teamwork-overdue.md Phase 2
 */
class AdminOnlyTaskTest extends IntegrationTestCase {
	private int $houseId;
	private int $floorId;
	private int $roomId;
	private int $publicTaskId;
	private int $adminOnlyTaskId;
	private int $adminOnlyRoomTaskId;

	protected function setUp(): void {
		parent::setUp();

		$this->houseId = $this->createHouse('admin_owner', 'Admin House');
		$this->addHouseMember($this->houseId, 'admin_member', 'Member', 'member');
		$this->addHouseMember($this->houseId, 'admin_child', 'Child', 'child');

		$this->floorId = $this->createFloor($this->houseId, 'Ground Floor', 1);
		$this->roomId = $this->createRoom($this->houseId, $this->floorId, 'Office');

		$this->publicTaskId = $this->createTask($this->houseId, 'Public Sweep', 'Sweep floor', 4, 10);
		$this->adminOnlyTaskId = $this->createTask($this->houseId, 'Admin Audit', 'Review finances', 8, 20);
		$this->setTaskVisibility($this->adminOnlyTaskId, Task::VISIBILITY_ADMIN_ONLY);

		$this->adminOnlyRoomTaskId = $this->assignTaskToRoom($this->adminOnlyTaskId, $this->roomId, 'admin_owner');
	}

	// ========== Visibility Matrix ==========

	public function testOwnerSeesAdminOnlyTasks(): void {
		$this->actingAs('admin_owner');
		$taskMapper = \OC::$server->get(TaskMapper::class);
		$houseUserMapper = \OC::$server->get(HouseUserMapper::class);
		$viewer = $houseUserMapper->findByNextcloudUserId($this->houseId, 'admin_owner');

		$visible = $taskMapper->findVisibleByHouse($this->houseId, $viewer);
		$visibleIds = array_map(fn (Task $t) => $t->getId(), $visible);

		self::assertContains($this->adminOnlyTaskId, $visibleIds);
		self::assertContains($this->publicTaskId, $visibleIds);
	}

	public function testMemberCannotSeeAdminOnlyTasks(): void {
		$this->actingAs('admin_member');
		$taskMapper = \OC::$server->get(TaskMapper::class);
		$houseUserMapper = \OC::$server->get(HouseUserMapper::class);
		$viewer = $houseUserMapper->findByNextcloudUserId($this->houseId, 'admin_member');

		$visible = $taskMapper->findVisibleByHouse($this->houseId, $viewer);
		$visibleIds = array_map(fn (Task $t) => $t->getId(), $visible);

		self::assertNotContains($this->adminOnlyTaskId, $visibleIds);
		self::assertContains($this->publicTaskId, $visibleIds);
	}

	public function testChildCannotSeeAdminOnlyTasks(): void {
		$this->actingAs('admin_child');
		$taskMapper = \OC::$server->get(TaskMapper::class);
		$houseUserMapper = \OC::$server->get(HouseUserMapper::class);
		$viewer = $houseUserMapper->findByNextcloudUserId($this->houseId, 'admin_child');

		$visible = $taskMapper->findVisibleByHouse($this->houseId, $viewer);
		$visibleIds = array_map(fn (Task $t) => $t->getId(), $visible);

		self::assertNotContains($this->adminOnlyTaskId, $visibleIds);
		self::assertContains($this->publicTaskId, $visibleIds);
	}

	// ========== Completion Gate ==========

	public function testOwnerCanCompleteAdminOnlyTask(): void {
		$this->actingAs('admin_owner');
		$roomService = \OC::$server->get(RoomService::class);

		$result = $roomService->completeRoomTask($this->adminOnlyRoomTaskId, 'admin_owner');

		self::assertArrayHasKey('rewards', $result);
		self::assertGreaterThan(0, $result['rewards']['coins']);
	}

	public function testMemberCannotCompleteAdminOnlyTask(): void {
		$this->actingAs('admin_member');
		$roomService = \OC::$server->get(RoomService::class);

		$this->expectException(ForbiddenException::class);
		$this->expectExceptionMessage('Admin-only tasks may only be completed by house owners');
		$roomService->completeRoomTask($this->adminOnlyRoomTaskId, 'admin_member');
	}

	public function testChildCannotCompleteAdminOnlyTask(): void {
		$this->actingAs('admin_child');
		$roomService = \OC::$server->get(RoomService::class);

		$this->expectException(ForbiddenException::class);
		$this->expectExceptionMessage('Admin-only tasks may only be completed by house owners');
		$roomService->completeRoomTask($this->adminOnlyRoomTaskId, 'admin_child');
	}

	// ========== Admin-Only Tasks Grant Rewards ==========

	public function testAdminOnlyTaskCompletionAwardsCoins(): void {
		$this->actingAs('admin_owner');
		$roomService = \OC::$server->get(RoomService::class);
		$houseUserMapper = \OC::$server->get(HouseUserMapper::class);

		$before = $houseUserMapper->findByNextcloudUserId($this->houseId, 'admin_owner');
		$coinsBefore = $before->getCoins();

		$result = $roomService->completeRoomTask($this->adminOnlyRoomTaskId, 'admin_owner');

		$after = $houseUserMapper->findByNextcloudUserId($this->houseId, 'admin_owner');
		self::assertGreaterThan($coinsBefore, $after->getCoins());
		self::assertGreaterThan(0, $result['rewards']['coins']);
	}

	// ========== Task Entity ==========

	public function testAdminOnlyTaskHasCorrectVisibilityType(): void {
		$this->actingAs('admin_owner');
		$taskMapper = \OC::$server->get(TaskMapper::class);

		$task = $taskMapper->find($this->adminOnlyTaskId);

		self::assertSame(Task::VISIBILITY_ADMIN_ONLY, $task->getVisibilityType());
		self::assertFalse($task->isPrivate());
	}

	// ========== Helpers ==========

	private function setTaskVisibility(int $taskId, string $visibilityType): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('chorestrike_tasks')
			->set('visibility_type', $qb->createNamedParameter($visibilityType))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($taskId)));
		$qb->executeStatement();
	}
}
