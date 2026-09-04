<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Db\TaskAssigneeMapper;
use OCA\ChoreStrike\Exception\NotFoundException;
use OCA\ChoreStrike\Service\TaskAccessPolicy;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the ONE task-visibility matrix (CS-PLAN-061 §3.2, D2).
 *
 * The matrix is intentionally dumb and side-effect-light — its subtlety
 * lives in the SQL paths (findVisibleByHouse, filterVisibleRoomTasks),
 * covered by integration tests. Here we pin the decision table:
 *
 * | visibility  | viewer role    | assignee | creator | visible |
 * |-------------|----------------|----------|---------|---------|
 * | public      | any            | –        | –       | yes     |
 * | private     | any            | no       | no      | NO      |
 * | private     | any            | yes      | –       | yes     |
 * | private     | any            | –        | yes     | yes     |
 * | admin-only  | owner          | –        | –       | yes     |
 * | admin-only  | member/child   | –        | –       | NO      |
 *
 * Owners do NOT implicitly see private tasks (D2). Denials throw the app
 * NotFoundException (404 anti-enumeration, D3).
 */
class TaskAccessPolicyTest extends TestCase {
	private TaskAssigneeMapper&MockObject $taskAssigneeMapper;
	private IL10N&MockObject $l10n;
	private TaskAccessPolicy $policy;

	protected function setUp(): void {
		parent::setUp();

		$this->taskAssigneeMapper = $this->createMock(TaskAssigneeMapper::class);
		$this->l10n = $this->createMock(IL10N::class);
		$this->l10n->method('t')->willReturnCallback(static fn (string $text) => $text);

		$this->policy = new TaskAccessPolicy($this->taskAssigneeMapper, $this->l10n);
	}

	private function houseUser(string $userId, string $role = 'member'): HouseUser {
		$houseUser = new HouseUser();
		$houseUser->setRole($role);
		$houseUser->setNextcloudUserId($userId);
		return $houseUser;
	}

	private function task(string $visibility, string $ownerUserId = 'creator', int $id = 1): Task {
		$task = new Task();
		$task->setId($id);
		$task->setVisibilityType($visibility);
		$task->setOwnerUserId($ownerUserId);
		return $task;
	}

	public function testPublicTaskVisibleToAnyMember(): void {
		self::assertTrue($this->policy->canView(
			$this->task(Task::VISIBILITY_PUBLIC),
			$this->houseUser('somebody'),
		));
	}

	public function testPrivateTaskHiddenFromUninvolvedMember(): void {
		$this->taskAssigneeMapper->method('findUserIdsByTaskIds')->willReturn([1 => []]);

		self::assertFalse($this->policy->canViewTask(
			$this->task(Task::VISIBILITY_PRIVATE),
			$this->houseUser('somebody'),
		));
	}

	public function testPrivateTaskVisibleToCreator(): void {
		$this->taskAssigneeMapper->method('findUserIdsByTaskIds')->willReturn([1 => ['other']]);

		self::assertTrue($this->policy->canViewTask(
			$this->task(Task::VISIBILITY_PRIVATE, 'creator'),
			$this->houseUser('creator'),
		));
	}

	public function testPrivateTaskVisibleToAssignee(): void {
		$this->taskAssigneeMapper->method('findUserIdsByTaskIds')->willReturn([1 => ['assignee']]);

		self::assertTrue($this->policy->canViewTask(
			$this->task(Task::VISIBILITY_PRIVATE, 'creator'),
			$this->houseUser('assignee'),
		));
	}

	public function testOwnerDoesNotImplicitlySeeForeignPrivateTask(): void {
		// Decision D2: owners have no blanket private-task access.
		$this->taskAssigneeMapper->method('findUserIdsByTaskIds')->willReturn([1 => []]);

		self::assertFalse($this->policy->canViewTask(
			$this->task(Task::VISIBILITY_PRIVATE, 'creator'),
			$this->houseUser('boss', 'owner'),
		));
	}

	public function testAdminOnlyTaskVisibleOnlyToOwner(): void {
		self::assertTrue($this->policy->canView(
			$this->task(Task::VISIBILITY_ADMIN_ONLY),
			$this->houseUser('boss', 'owner'),
		));
		self::assertFalse($this->policy->canView(
			$this->task(Task::VISIBILITY_ADMIN_ONLY),
			$this->houseUser('member'),
		));
		self::assertFalse($this->policy->canView(
			$this->task(Task::VISIBILITY_ADMIN_ONLY),
			$this->houseUser('kid', 'child'),
		));
	}

	public function testAssertCanViewThrowsNotFoundExceptionOnDenial(): void {
		$this->expectException(NotFoundException::class);
		$this->expectExceptionMessage('Task not found');

		$this->policy->assertCanView(
			$this->task(Task::VISIBILITY_PRIVATE, 'creator'),
			$this->houseUser('somebody'),
			[],
		);
	}

	public function testAssertCanViewTaskThrowsNotFoundExceptionOnDenial(): void {
		$this->taskAssigneeMapper->method('findUserIdsByTaskIds')->willReturn([1 => []]);

		$this->expectException(NotFoundException::class);
		$this->expectExceptionMessage('Task not found');

		$this->policy->assertCanViewTask(
			$this->task(Task::VISIBILITY_ADMIN_ONLY),
			$this->houseUser('member'),
		);
	}

	public function testFilterVisibleRoomTasksKeepsOrderAndDropsInvisible(): void {
		$visible = new \OCA\ChoreStrike\Db\RoomTask();
		$visible->setId(10);
		$visible->setTaskId(1);
		$invisible = new \OCA\ChoreStrike\Db\RoomTask();
		$invisible->setId(11);
		$invisible->setTaskId(2);
		$tasksById = [
			1 => $this->task(Task::VISIBILITY_PUBLIC, 'creator', 1),
			2 => $this->task(Task::VISIBILITY_ADMIN_ONLY, 'creator', 2),
		];

		$this->taskAssigneeMapper->method('findUserIdsByRoomTasks')->willReturn([
			10 => ['assignee'],
			11 => ['assignee'],
		]);

		$result = $this->policy->filterVisibleRoomTasks(
			[$visible, $invisible],
			$tasksById,
			$this->houseUser('member'),
		);

		self::assertSame([$visible], $result);
	}

	public function testFilterVisibleRoomTasksDropsOrphanedRows(): void {
		$orphan = new \OCA\ChoreStrike\Db\RoomTask();
		$orphan->setId(12);
		$orphan->setTaskId(99);

		$result = $this->policy->filterVisibleRoomTasks(
			[$orphan],
			[],
			$this->houseUser('boss', 'owner'),
		);

		self::assertSame([], $result, 'orphaned rows (definition gone) must never leak');
	}
}
