<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Exception\NotFoundException;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\TaskAccessPolicy;
use OCA\ChoreStrike\Service\TaskService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for TaskService
 */
class TaskServiceTest extends TestCase {
	private TaskService $service;
	private TaskMapper $taskMapper;
	private AuthorizationService $authorization;
	private TaskAccessPolicy $taskAccessPolicy;

	protected function setUp(): void {
		$this->taskMapper = $this->createMock(TaskMapper::class);
		$this->authorization = $this->createMock(AuthorizationService::class);
		$this->taskAccessPolicy = $this->createMock(TaskAccessPolicy::class);
		$l10n = $this->createMock(IL10N::class);
		// Identity mock: assertions below match the wrapped source text
		// (no parameter substitution), mirroring TemplateServiceTest.
		$l10n->method('t')->willReturnCallback(static fn (string $text) => $text);
		$this->service = new TaskService($this->taskMapper, $l10n, $this->authorization, $this->taskAccessPolicy);
	}

	/**
	 * HouseUser actor for the actor-policy gates (CS-PLAN-061 §3.4).
	 */
	private function createHouseUser(string $userId = 'user123', string $role = 'member'): HouseUser {
		$houseUser = new HouseUser();
		$houseUser->setNextcloudUserId($userId);
		$houseUser->setRole($role);
		return $houseUser;
	}

	/**
	 * Stub the visibility matrix for updateTask's read gate.
	 */
	private function stubTaskVisible(bool $visible = true): void {
		$this->taskAccessPolicy->method('canViewTask')->willReturn($visible);
	}

	/**
	 * GetTask_ExistingId_ReturnsTask
	 */
	public function testGetTask_ExistingId_ReturnsTask(): void {
		$taskId = 1;

		$task = new Task();
		$task->setId($taskId);
		$task->setName('Test Task');

		$this->taskMapper
			->expects($this->once())
			->method('find')
			->with($taskId)
			->willReturn($task);

		$result = $this->service->getTask($taskId);

		$this->assertSame($task, $result);
	}

	/**
	 * GetTask_NonExistingId_ThrowsNotFound
	 *
	 * CS-PLAN-061 §3.5: the mapper's DoesNotExistException is wrapped into
	 * the app NotFoundException — a raw framework exception would 500.
	 */
	public function testGetTask_NonExistingId_ThrowsNotFound(): void {
		$taskId = 999;

		$this->taskMapper
			->expects($this->once())
			->method('find')
			->with($taskId)
			->willThrowException(new DoesNotExistException('Task not found'));

		$this->expectException(NotFoundException::class);
		$this->expectExceptionMessage('Task not found');

		$this->service->getTask($taskId);
	}

	/**
	 * GetVisibleTask_InvisibleTask_ThrowsNotFound
	 *
	 * CS-PLAN-061 §3.2: the shared controller-preamble gate denies a caller
	 * who cannot see the task with the 404 anti-enumeration NotFoundException.
	 */
	public function testGetVisibleTask_InvisibleTask_ThrowsNotFound(): void {
		$task = new Task();
		$task->setId(1);
		$task->setVisibilityType(Task::VISIBILITY_PRIVATE);
		$task->setOwnerUserId('someone-else');

		$this->taskMapper->method('find')->willReturn($task);
		$this->stubTaskVisible(false);

		$this->expectException(NotFoundException::class);
		$this->expectExceptionMessage('Task not found');

		$this->service->getVisibleTask(1, $this->createHouseUser());
	}

	/**
	 * CreateTask_ValidData_ReturnsCreatedTask
	 */
	public function testCreateTask_ValidData_ReturnsCreatedTask(): void {
		$actor = $this->createHouseUser('creator', 'owner');

		$task = new Task();
		$task->setId(1);
		$task->setName('New Task');

		$this->authorization->expects($this->once())
			->method('assertCanManageTasks')
			->with($actor);

		$this->taskMapper
			->expects($this->once())
			->method('create')
			->willReturn($task);

		$result = $this->service->createTask(
			$actor,
			'New Task',
			'Description',
			2,
			1,
			'weekly',
			1,
			30,
			'cleaning'
		);

		$this->assertSame($task, $result);
	}

	/**
	 * CreateTask_ChildActor_ThrowsForbidden
	 *
	 * CS-PLAN-061 §3.4 (D5): children execute tasks, they do not create
	 * task definitions.
	 */
	public function testCreateTask_ChildActor_ThrowsForbidden(): void {
		$this->authorization->method('assertCanManageTasks')
			->willThrowException(new ForbiddenException('Children may view and complete tasks, but not manage them'));

		$this->taskMapper->expects($this->never())->method('create');

		$this->expectException(ForbiddenException::class);

		$this->service->createTask(
			$this->createHouseUser('kid', 'child'),
			'New Task',
			'Description',
			2,
			1,
			'weekly',
			1,
			30,
			'cleaning'
		);
	}

	/**
	 * UpdateTask_ValidUpdates_ReturnsUpdatedTask
	 */
	public function testUpdateTask_ValidUpdates_ReturnsUpdatedTask(): void {
		$taskId = 1;
		$updates = ['name' => 'Updated Name', 'energyCost' => 4];
		$actor = $this->createHouseUser();

		$task = new Task();
		$task->setId($taskId);
		$task->setName('Old Name');
		$task->setEnergyCost(2);

		$updatedTask = new Task();
		$updatedTask->setId($taskId);
		$updatedTask->setName('Updated Name');
		$updatedTask->setEnergyCost(4);

		$this->taskMapper
			->expects($this->once())
			->method('find')
			->with($taskId)
			->willReturn($task);

		$this->stubTaskVisible();
		$this->authorization->expects($this->once())
			->method('assertCanManageTasks')
			->with($actor);

		$this->taskMapper
			->expects($this->once())
			->method('update')
			->willReturn($updatedTask);

		$result = $this->service->updateTask($taskId, $updates, $actor);

		$this->assertEquals('Updated Name', $result->getName());
		$this->assertEquals(4, $result->getEnergyCost());
	}

	/**
	 * UpdateTask_DateFields_ParsesDates
	 */
	public function testUpdateTask_DateFields_ParsesDates(): void {
		$taskId = 1;
		$updates = ['activeFrom' => '2026-03-01', 'activeUntil' => '2026-12-31'];

		$task = new Task();
		$task->setId($taskId);

		$this->taskMapper
			->expects($this->once())
			->method('find')
			->with($taskId)
			->willReturn($task);

		$this->stubTaskVisible();

		$this->taskMapper
			->expects($this->once())
			->method('update')
			->willReturn($task);

		$result = $this->service->updateTask($taskId, $updates, $this->createHouseUser());

		$this->assertSame($task, $result);
	}

	/**
	 * UpdateTask_InvisibleTask_NonViewer_ThrowsNotFound
	 *
	 * CS-PLAN-061 §3.2 (D2/D3): one visibility matrix everywhere — a caller
	 * who cannot see the task (e.g. a non-assignee on a private task) gets
	 * the 404 anti-enumeration denial, and the old
	 * "Only the task owner may update a private task" 403 strings are gone
	 * (policy drives it).
	 */
	public function testUpdateTask_InvisibleTask_NonViewer_ThrowsNotFound(): void {
		$task = new Task();
		$task->setId(1);
		$task->setVisibilityType(Task::VISIBILITY_PRIVATE);
		$task->setOwnerUserId('task-owner');

		$this->taskMapper->expects($this->once())->method('find')->willReturn($task);
		$this->stubTaskVisible(false);
		$this->taskMapper->expects($this->never())->method('update');

		$this->expectException(NotFoundException::class);
		$this->expectExceptionMessage('Task not found');

		$this->service->updateTask(1, ['ownerUserId' => 'attacker'], $this->createHouseUser('some-other-user'));
	}

	/**
	 * UpdateTask_PrivateTask_OwnerCaller_Succeeds
	 */
	public function testUpdateTask_PrivateTask_OwnerCaller_Succeeds(): void {
		$task = new Task();
		$task->setId(1);
		$task->setVisibilityType(Task::VISIBILITY_PRIVATE);
		$task->setOwnerUserId('task-owner');

		$this->taskMapper->expects($this->once())->method('find')->willReturn($task);
		$this->stubTaskVisible();
		$this->taskMapper->expects($this->once())->method('update')->willReturnArgument(0);

		$result = $this->service->updateTask(1, ['name' => 'Renamed'], $this->createHouseUser('task-owner'));

		$this->assertSame('Renamed', $result->getName());
	}

	/**
	 * UpdateTask_AdminOnlyTask_MemberCaller_ThrowsNotFound
	 */
	public function testUpdateTask_AdminOnlyTask_MemberCaller_ThrowsNotFound(): void {
		$task = new Task();
		$task->setId(1);
		$task->setVisibilityType(Task::VISIBILITY_ADMIN_ONLY);

		$this->taskMapper->expects($this->once())->method('find')->willReturn($task);
		$this->stubTaskVisible(false);
		$this->taskMapper->expects($this->never())->method('update');

		$this->expectException(NotFoundException::class);
		$this->expectExceptionMessage('Task not found');

		$this->service->updateTask(1, ['name' => 'Nope'], $this->createHouseUser('member-user'));
	}

	/**
	 * UpdateTask_AdminOnlyTask_HouseOwner_Succeeds
	 */
	public function testUpdateTask_AdminOnlyTask_HouseOwner_Succeeds(): void {
		$task = new Task();
		$task->setId(1);
		$task->setVisibilityType(Task::VISIBILITY_ADMIN_ONLY);

		$this->taskMapper->expects($this->once())->method('find')->willReturn($task);
		$this->stubTaskVisible();
		$this->taskMapper->expects($this->once())->method('update')->willReturnArgument(0);

		$result = $this->service->updateTask(1, ['name' => 'Renamed'], $this->createHouseUser('house-owner', 'owner'));

		$this->assertSame('Renamed', $result->getName());
	}

	/**
	 * UpdateTask_PublicTask_AnyCaller_Succeeds
	 */
	public function testUpdateTask_PublicTask_AnyCaller_Succeeds(): void {
		$task = new Task();
		$task->setId(1);
		$task->setVisibilityType(Task::VISIBILITY_PUBLIC);

		$this->taskMapper->expects($this->once())->method('find')->willReturn($task);
		$this->stubTaskVisible();
		$this->taskMapper->expects($this->once())->method('update')->willReturnArgument(0);

		$result = $this->service->updateTask(1, ['name' => 'Renamed'], $this->createHouseUser('any-member'));

		$this->assertSame('Renamed', $result->getName());
	}

	/**
	 * UpdateTask_MemberTaskTier_ThrowsForbidden
	 *
	 * CR-053-04: taskTier drives the coin multiplier — a member must not
	 * self-serve the 2x gold tier. (The plain whitelist loop regression —
	 * member + name → ok — is pinned by testUpdateTask_PublicTask_AnyCaller_Succeeds.)
	 */
	public function testUpdateTask_MemberTaskTier_ThrowsForbidden(): void {
		$task = new Task();
		$task->setId(1);
		$task->setVisibilityType(Task::VISIBILITY_PUBLIC);

		$this->taskMapper->expects($this->once())->method('find')->willReturn($task);
		$this->stubTaskVisible();
		$this->taskMapper->expects($this->never())->method('update');

		$this->expectException(ForbiddenException::class);
		$this->expectExceptionMessage('Only house owners may change the task tier');

		$this->service->updateTask(1, ['taskTier' => 'gold'], $this->createHouseUser('member-user'));
	}

	/**
	 * UpdateTask_OwnerInvalidTier_ThrowsInvalidArgument
	 */
	public function testUpdateTask_OwnerInvalidTier_ThrowsInvalidArgument(): void {
		$task = new Task();
		$task->setId(1);
		$task->setVisibilityType(Task::VISIBILITY_PUBLIC);

		$this->taskMapper->expects($this->once())->method('find')->willReturn($task);
		$this->stubTaskVisible();
		$this->taskMapper->expects($this->never())->method('update');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Invalid task tier');

		$this->service->updateTask(1, ['taskTier' => 'platinum'], $this->createHouseUser('house-owner', 'owner'));
	}

	/**
	 * UpdateTask_OwnerValidTier_PersistsTier
	 */
	public function testUpdateTask_OwnerValidTier_PersistsTier(): void {
		$task = new Task();
		$task->setId(1);
		$task->setVisibilityType(Task::VISIBILITY_PUBLIC);

		$this->taskMapper->expects($this->once())->method('find')->willReturn($task);
		$this->stubTaskVisible();
		$this->taskMapper->expects($this->once())->method('update')->willReturnArgument(0);

		$result = $this->service->updateTask(1, ['taskTier' => 'gold'], $this->createHouseUser('house-owner', 'owner'));

		$this->assertSame('gold', $result->getTaskTier());
	}

	/**
	 * UpdateTask_OwnerTransferToNonMember_ThrowsForbidden
	 *
	 * CS-PLAN-061 §3.4: a changed ownerUserId target is validated as a
	 * house member via AuthorizationService::assertUserInHouse — the
	 * previously unvalidated transfer is closed (closes CR-SVC-17's
	 * re-assignment-by-whitelist gap on the membership side).
	 */
	public function testUpdateTask_OwnerTransferToNonMember_ThrowsForbidden(): void {
		$task = new Task();
		$task->setId(1);
		$task->setOwnerUserId('old-owner');

		$actor = $this->createHouseUser('house-owner', 'owner');
		$actor->setHouseId(1);

		$this->taskMapper->expects($this->once())->method('find')->willReturn($task);
		$this->stubTaskVisible();
		$this->authorization->method('assertUserInHouse')
			->willThrowException(new ForbiddenException('User is not a member of this house'));
		$this->taskMapper->expects($this->never())->method('update');

		$this->expectException(ForbiddenException::class);

		$this->service->updateTask(1, ['ownerUserId' => 'outsider'], $actor);
	}

	/**
	 * UpdateTask_OwnerTransferToMember_Succeeds
	 */
	public function testUpdateTask_OwnerTransferToMember_Succeeds(): void {
		$task = new Task();
		$task->setId(1);
		$task->setOwnerUserId('old-owner');

		$actor = $this->createHouseUser('house-owner', 'owner');
		$actor->setHouseId(1);

		$this->taskMapper->expects($this->once())->method('find')->willReturn($task);
		$this->stubTaskVisible();
		$this->authorization->expects($this->once())
			->method('assertUserInHouse')
			->with(1, 'new-owner');
		$this->taskMapper->expects($this->once())->method('update')->willReturnArgument(0);

		$result = $this->service->updateTask(1, ['ownerUserId' => 'new-owner'], $actor);

		$this->assertSame('new-owner', $result->getOwnerUserId());
	}

	/**
	 * ValidateTaskData_EmptyName_ReturnsError
	 */
	public function testValidateTaskData_EmptyName_ReturnsError(): void {
		$taskData = ['name' => ''];

		$result = $this->service->validateTaskData($taskData);

		$this->assertContains('Task name is required', $result);
	}

	/**
	 * ValidateTaskData_InvalidEnergyCost_ReturnsError
	 */
	public function testValidateTaskData_InvalidEnergyCost_ReturnsError(): void {
		$taskData = ['name' => 'Test', 'energyCost' => 10];

		$result = $this->service->validateTaskData($taskData);

		$this->assertContains('Energy cost must be between 1 and 5', $result);
	}

	/**
	 * ValidateTaskData_InvalidPriority_ReturnsError
	 */
	public function testValidateTaskData_InvalidPriority_ReturnsError(): void {
		$taskData = ['name' => 'Test', 'priority' => 5];

		$result = $this->service->validateTaskData($taskData);

		$this->assertContains('Priority must be between 1 and 3', $result);
	}

	/**
	 * ValidateTaskData_InvalidFrequencyType_ReturnsError
	 */
	public function testValidateTaskData_InvalidFrequencyType_ReturnsError(): void {
		$taskData = ['name' => 'Test', 'frequencyType' => 'yearly'];

		$result = $this->service->validateTaskData($taskData);

		$this->assertContains('Invalid frequency type', $result);
	}

	/**
	 * ValidateTaskData_InvalidScopeType_ReturnsError
	 */
	public function testValidateTaskData_InvalidScopeType_ReturnsError(): void {
		$taskData = ['name' => 'Test', 'scopeType' => 'invalid'];

		$result = $this->service->validateTaskData($taskData);

		$this->assertContains('Invalid scope type', $result);
	}

	/**
	 * ValidateTaskData_InvalidRecurrenceMode_ReturnsError
	 */
	public function testValidateTaskData_InvalidRecurrenceMode_ReturnsError(): void {
		$taskData = ['name' => 'Test', 'recurrenceMode' => 'monthly'];

		$result = $this->service->validateTaskData($taskData);

		$this->assertContains('Invalid recurrence mode', $result);
	}

	/**
	 * ValidateTaskData_InvalidDateRange_ReturnsError
	 */
	public function testValidateTaskData_InvalidDateRange_ReturnsError(): void {
		$taskData = [
			'name' => 'Test',
			'activeFrom' => '2026-12-31',
			'activeUntil' => '2026-01-01'
		];

		$result = $this->service->validateTaskData($taskData);

		$this->assertContains('Active until must be equal or after active from', $result);
	}

	/**
	 * ValidateTaskData_ValidData_ReturnsEmptyArray
	 */
	public function testValidateTaskData_ValidData_ReturnsEmptyArray(): void {
		$taskData = [
			'name' => 'Test Task',
			'energyCost' => 3,
			'priority' => 2,
			'frequencyType' => 'weekly',
			'scopeType' => 'room',
			'recurrenceMode' => 'recurring',
			'frequencyValue' => 1
		];

		$result = $this->service->validateTaskData($taskData);

		$this->assertEmpty($result);
	}
}
