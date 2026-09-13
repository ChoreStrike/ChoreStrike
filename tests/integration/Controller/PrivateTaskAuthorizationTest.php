<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Controller;

use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Exception\NotFoundException;
use OCA\ChoreStrike\Service\CompletionService;
use OCA\ChoreStrike\Service\TaskService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;

/**
 * Integration tests for private-task visibility and completion gates.
 *
 * Verifies the ONE visibility matrix (CS-PLAN-061 §3.2, decision D2):
 * - Private tasks are visible to their creator (owner_user_id) UNION the
 *   explicit assignees (canonical join table)
 * - Other members/children cannot see or complete private tasks
 * - Owners see only their OWN private tasks (strict privacy default)
 * - Completion of a private task is restricted to creator ∪ assignees
 * - Visibility denials are 404s (anti-enumeration), never 403s
 *
 * @see docs/plans/active/cs-plan-061-wp2-membership-visibility-privacy.md §3.2/§3.3
 */
class PrivateTaskAuthorizationTest extends IntegrationTestCase {
	private int $houseId;
	private int $floorId;
	private int $roomId;
	private int $publicTaskId;
	private int $privateTaskId;
	private int $otherPrivateTaskId;
	private int $publicRoomTaskId;
	private int $privateRoomTaskId;
	private int $assigneeRoomTaskId;

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

		$this->publicRoomTaskId = $this->assignTaskToRoom($this->publicTaskId, $this->roomId, null);
		$this->privateRoomTaskId = $this->assignTaskToRoom($this->privateTaskId, $this->roomId, 'priv_owner');
		// A second room task of the SAME private definition, in a second room
		// ((task_id, room_id) is unique), assigned to the member — the
		// assignee branch of the matrix (D2).
		$secondRoomId = $this->createRoom($this->houseId, $this->floorId, 'Bathroom');
		$this->assigneeRoomTaskId = $this->assignTaskToRoom($this->privateTaskId, $secondRoomId, 'priv_member');
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
	}

	public function testAssigneeSeesPrivateDefinitionOfAssignedRoomTask(): void {
		// D2: private = creator ∪ explicit assignees. priv_member is not the
		// creator of $this->privateTaskId but is assigned to one of its
		// room tasks — the definition list must include it.
		$this->actingAs('priv_member');
		$taskMapper = \OC::$server->get(TaskMapper::class);
		$houseUserMapper = \OC::$server->get(HouseUserMapper::class);
		$viewer = $houseUserMapper->findByNextcloudUserId($this->houseId, 'priv_member');

		$visible = $taskMapper->findVisibleByHouse($this->houseId, $viewer);
		$visibleIds = array_map(fn (Task $t) => $t->getId(), $visible);

		self::assertContains($this->privateTaskId, $visibleIds);
	}

	public function testChildSeesNeitherPrivateDefinition(): void {
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

	// ========== Room-Task Read Filtering (CS-PLAN-061 §3.3) ==========

	private function visibleRoomTaskIds(string $userId, string $method, array $args = []): array {
		$this->actingAs($userId);
		// CS-PLAN-067 §3.1: the room-task read cluster lives in
		// RoomTaskReadService.
		$service = \OC::$server->get(\OCA\ChoreStrike\Service\RoomTaskReadService::class);
		$houseUserMapper = \OC::$server->get(HouseUserMapper::class);
		$viewer = $houseUserMapper->findByNextcloudUserId($this->houseId, $userId);

		$roomTasks = $service->{$method}(...array_merge([$this->houseId, $viewer], $args));
		return array_map(fn ($rt) => $rt->getId(), $roomTasks);
	}

	public function testOverdueReadHidesPrivateTasksFromNonViewers(): void {
		// Make the private room tasks overdue.
		$qb = $this->db->getQueryBuilder();
		$qb->update('chorestrike_room_tasks')
			->set('next_due', $qb->createNamedParameter('2020-01-01 00:00:00'))
			->where($qb->expr()->in('id', $qb->createNamedParameter([$this->privateRoomTaskId, $this->assigneeRoomTaskId], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)));
		$qb->executeStatement();

		// The child is not creator/assignee: neither private room task leaks.
		$childIds = $this->visibleRoomTaskIds('priv_child', 'getOverdueTasks');
		self::assertNotContains($this->privateRoomTaskId, $childIds);
		self::assertNotContains($this->assigneeRoomTaskId, $childIds);

		// Room-task reads use per-room-task assignee scoping: the creator
		// sees both room tasks of their definition; the assignee sees
		// exactly their own assignment, not another member's chore
		// instance (D2 — the assignee branch is keyed on the room task's
		// own join-table rows).
		$ownerIds = $this->visibleRoomTaskIds('priv_owner', 'getOverdueTasks');
		self::assertContains($this->privateRoomTaskId, $ownerIds);
		self::assertContains($this->assigneeRoomTaskId, $ownerIds);

		$memberIds = $this->visibleRoomTaskIds('priv_member', 'getOverdueTasks');
		self::assertContains($this->assigneeRoomTaskId, $memberIds);
		self::assertNotContains($this->privateRoomTaskId, $memberIds);
	}

	public function testDueTodayReadHidesPrivateTasksFromNonViewers(): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('chorestrike_room_tasks')
			->set('next_due', $qb->createNamedParameter(date('Y-m-d 12:00:00')))
			->where($qb->expr()->in('id', $qb->createNamedParameter([$this->privateRoomTaskId, $this->assigneeRoomTaskId], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)));
		$qb->executeStatement();

		$childIds = $this->visibleRoomTaskIds('priv_child', 'getDueTodayTasks');
		self::assertNotContains($this->privateRoomTaskId, $childIds);
		self::assertNotContains($this->assigneeRoomTaskId, $childIds);

		$memberIds = $this->visibleRoomTaskIds('priv_member', 'getDueTodayTasks');
		self::assertContains($this->assigneeRoomTaskId, $memberIds);
	}

	public function testRangeReadHidesPrivateDefinitionsFromNonViewers(): void {
		$service = \OC::$server->get(\OCA\ChoreStrike\Service\RoomTaskService::class);
		$houseUserMapper = \OC::$server->get(HouseUserMapper::class);

		$this->actingAs('priv_child');
		$childViewer = $houseUserMapper->findByNextcloudUserId($this->houseId, 'priv_child');
		$from = new \DateTime('-1 day');
		$to = new \DateTime('+2 days');
		$childData = $service->getRangeTasks($this->houseId, $from, $to, $childViewer);
		$childTaskIds = array_map(
			static fn (array $item) => $item['roomTask']['taskId'] ?? $item['task']['id'] ?? 0,
			$childData['scheduled'],
		);
		self::assertNotContains($this->privateTaskId, $childTaskIds);

		$this->actingAs('priv_member');
		$memberViewer = $houseUserMapper->findByNextcloudUserId($this->houseId, 'priv_member');
		$memberData = $service->getRangeTasks($this->houseId, $from, $to, $memberViewer);
		$memberTaskIds = array_map(
			static fn (array $item) => $item['task']['id'],
			$memberData['scheduled'],
		);
		// The definition is visible to the member (assignee branch) — the
		// FULL definition may appear, but only for the assignee's own room
		// task, never the creator's.
		foreach ($memberData['scheduled'] as $item) {
			if ($item['task']['id'] === $this->privateTaskId) {
				self::assertSame($this->assigneeRoomTaskId, $item['roomTask']['id']);
			}
		}
		self::assertContains($this->privateTaskId, $memberTaskIds);
	}

	// ========== Residual Surface Visibility (CS-PLAN-061 review fixes) ==========
	// (Controller-level assignees/roomStats coverage lives in
	// TaskAuthorizationTest — controllers resolve the instance house.)

	public function testRangeCompletions_RedactInvisibleTaskNames(): void {
		// Owner completes the private task, the child completes the public
		// one — both rows appear in the child's calendar range, but the
		// private task's name is redacted (D9: rows stay house-scoped).
		$this->actingAs('priv_owner');
		$completionService = \OC::$server->get(CompletionService::class);
		$completionService->complete($this->houseId, 'priv_owner', $this->privateRoomTaskId);
		$completionService->complete($this->houseId, 'priv_child', $this->publicRoomTaskId);

		$service = \OC::$server->get(\OCA\ChoreStrike\Service\RoomTaskService::class);
		$houseUserMapper = \OC::$server->get(HouseUserMapper::class);
		$this->actingAs('priv_child');
		$viewer = $houseUserMapper->findByNextcloudUserId($this->houseId, 'priv_child');

		$data = $service->getRangeTasks(
			$this->houseId,
			new \DateTime('-1 day'),
			new \DateTime('+1 day'),
			$viewer
		);

		$rowsByTaskId = [];
		foreach ($data['completed'] as $row) {
			$rowsByTaskId[(int)$row['taskId']] = $row;
		}

		self::assertArrayHasKey($this->privateTaskId, $rowsByTaskId, 'the completion row itself stays (D9)');
		self::assertNull($rowsByTaskId[$this->privateTaskId]['taskName'], 'invisible task names are redacted');
		self::assertArrayHasKey($this->publicTaskId, $rowsByTaskId);
		self::assertSame('Public Dishes', $rowsByTaskId[$this->publicTaskId]['taskName']);
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
		$completionService = \OC::$server->get(CompletionService::class);

		$result = $completionService->complete($this->houseId, 'priv_owner', $this->privateRoomTaskId);

		self::assertArrayHasKey('rewards', $result);
		self::assertArrayHasKey('coins', $result['rewards']);
	}

	public function testNonAssigneeCannotCompletePrivateTask(): void {
		// 404 anti-enumeration (decision D3): a non-viewer gets the same
		// denial as a nonexistent id, never a 403 enumeration oracle.
		$this->actingAs('priv_child');
		$completionService = \OC::$server->get(CompletionService::class);

		$this->expectException(NotFoundException::class);
		$this->expectExceptionMessage('Task not found');
		$completionService->complete($this->houseId, 'priv_child', $this->privateRoomTaskId);
	}

	public function testAssigneeCanCompleteSomeoneElsesPrivateRoomTask(): void {
		// D2: the explicit assignee of a room task completes it even though
		// the definition belongs to someone else.
		$this->actingAs('priv_member');
		$completionService = \OC::$server->get(CompletionService::class);

		$result = $completionService->complete($this->houseId, 'priv_member', $this->assigneeRoomTaskId);

		self::assertArrayHasKey('rewards', $result);
	}

	public function testCreatorCanCompleteTheirOwnSecondRoomTask(): void {
		// The creator (priv_owner) is not assigned to $assigneeRoomTaskId but
		// IS the definition owner: creator ∪ assignees includes them.
		$this->actingAs('priv_owner');
		$completionService = \OC::$server->get(CompletionService::class);

		$result = $completionService->complete($this->houseId, 'priv_owner', $this->assigneeRoomTaskId);

		self::assertArrayHasKey('rewards', $result);
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
