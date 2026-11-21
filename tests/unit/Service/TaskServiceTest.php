<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Exception\NotFoundException;
use OCA\ChoreStrike\Exception\ValidationException;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\TaskAccessPolicy;
use OCA\ChoreStrike\Service\TaskService;
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
	/** CS-PLAN-071: the under-lock task re-read (lockForUpdate) returns this. */
	private ?Task $lockedTask = null;

	protected function setUp(): void {
		$this->taskMapper = $this->createMock(TaskMapper::class);
		$this->authorization = $this->createMock(AuthorizationService::class);
		$this->taskAccessPolicy = $this->createMock(TaskAccessPolicy::class);
		$l10n = $this->createMock(IL10N::class);
		// Identity mock: assertions below match the wrapped source text
		// (no parameter substitution), mirroring TemplateServiceTest.
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []) => $parameters === [] ? $text : vsprintf($text, $parameters));

		// The one form's save locks the definition row; the re-read returns
		// the test's prepared entity (stubLockedTask).
		$this->taskMapper->method('lockForUpdate')->willReturnCallback(function (): Task {
			if ($this->lockedTask === null) {
				throw new \LogicException('call stubLockedTask() for updateTask success-path tests');
			}
			return $this->lockedTask;
		});

		$taskCategories = $this->createMock(\OCA\ChoreStrike\Service\TaskCategoryService::class);
		$taskCategories->method('isKnown')->willReturnCallback(static fn (string $key): bool => in_array($key, \OCA\ChoreStrike\Service\TaskCategoryService::DEFAULT_KEYS, true));
		$taskCategories->method('keyOrOther')->willReturnCallback(static fn (string $key): string => in_array($key, \OCA\ChoreStrike\Service\TaskCategoryService::DEFAULT_KEYS, true) ? $key : 'other');
		$taskCategories->method('getList')->willReturn(['revision' => 0, 'items' => array_map(static fn (string $key): array => ['key' => $key, 'label' => null], \OCA\ChoreStrike\Service\TaskCategoryService::DEFAULT_KEYS)]);
		$this->service = new TaskService(
			$this->taskMapper,
			$this->createMock(\OCA\ChoreStrike\Db\RoomTaskMapper::class),
			$l10n,
			$this->authorization,
			$this->taskAccessPolicy,
			$this->createMock(\OCA\ChoreStrike\Service\CycleService::class),
			$taskCategories,
		);
	}

	/**
	 * The definition part of the one form's save (CS-PLAN-091 WP-B6): the
	 * gate before the lock, then the write on the locked row.
	 */
	private function update(int $taskId, array $updates, HouseUser $actor): Task {
		$accepted = $this->service->prepareUpdate($this->taskMapper->find($taskId), $updates, $actor);
		$locked = $this->taskMapper->lockForUpdate($taskId);
		return $this->service->applyUnderLock($locked, clone $locked, $accepted);
	}

	/**
	 * Register the entity the under-lock task re-read returns.
	 */
	private function stubLockedTask(Task $task): void {
		$this->lockedTask = $task;
	}

	/**
	 * HouseUser actor for the actor-policy gates (CS-PLAN-061 §3.4).
	 */
	private function createHouseUser(string $userId = 'user123', string $role = 'member'): HouseUser {
		$houseUser = new HouseUser();
		$houseUser->setHouseId(1);
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

	// CS-PLAN-063: the former createTask tests moved to
	// TaskCreationServiceTest — definition creation runs through the atomic
	// TaskCreationService aggregate, never through TaskService directly.

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

		$this->stubLockedTask($task);

		$this->taskMapper
			->expects($this->once())
			->method('update')
			->willReturn($updatedTask);

		$result = $this->update($taskId, $updates, $actor);

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
		$this->stubLockedTask($task);

		$this->taskMapper
			->expects($this->once())
			->method('update')
			->willReturn($task);

		$result = $this->update($taskId, $updates, $this->createHouseUser());

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

		$this->update(1, ['ownerUserId' => 'attacker'], $this->createHouseUser('some-other-user'));
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
		$this->stubLockedTask($task);

		$result = $this->update(1, ['name' => 'Renamed'], $this->createHouseUser('task-owner'));

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

		$this->update(1, ['name' => 'Nope'], $this->createHouseUser('member-user'));
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
		$this->stubLockedTask($task);

		$result = $this->update(1, ['name' => 'Renamed'], $this->createHouseUser('house-owner', 'owner'));

		$this->assertSame('Renamed', $result->getName());
	}

	/**
	 * UpdateTask_MemberSetsAdminOnlyVisibility_ThrowsForbidden
	 *
	 * RF-058-03: the admin-only visibility owner gate is enforced
	 * service-side (mirroring TaskCreationService), not only in the
	 * controller — a direct service caller cannot bypass it.
	 */
	public function testUpdateTask_MemberSetsAdminOnlyVisibility_ThrowsForbidden(): void {
		$task = new Task();
		$task->setId(1);
		$task->setVisibilityType(Task::VISIBILITY_PUBLIC);

		$this->taskMapper->expects($this->once())->method('find')->willReturn($task);
		$this->stubTaskVisible();
		$this->taskMapper->expects($this->never())->method('update');

		$this->expectException(ForbiddenException::class);
		$this->expectExceptionMessage('Only house owners can set admin-only visibility');

		$this->update(1, ['visibilityType' => Task::VISIBILITY_ADMIN_ONLY], $this->createHouseUser('member-user'));
	}

	/**
	 * UpdateTask_MemberUnsetsAdminOnlyVisibility_ThrowsForbidden (RF-058-03)
	 */
	public function testUpdateTask_MemberUnsetsAdminOnlyVisibility_ThrowsForbidden(): void {
		$task = new Task();
		$task->setId(1);
		$task->setVisibilityType(Task::VISIBILITY_ADMIN_ONLY);

		// The member can VIEW their own admin-only task only if they are
		// the creator/assignee — stub visible for the gate-under-test.
		$this->taskMapper->expects($this->once())->method('find')->willReturn($task);
		$this->stubTaskVisible();
		$this->taskMapper->expects($this->never())->method('update');

		$this->expectException(ForbiddenException::class);
		$this->expectExceptionMessage('Only house owners can change admin-only task visibility');

		$this->update(1, ['visibilityType' => Task::VISIBILITY_PUBLIC], $this->createHouseUser('member-user'));
	}

	/**
	 * UpdateTask_OwnerSetsAdminOnlyVisibility_Succeeds (RF-058-03)
	 */
	public function testUpdateTask_OwnerSetsAdminOnlyVisibility_Succeeds(): void {
		$task = new Task();
		$task->setId(1);
		$task->setVisibilityType(Task::VISIBILITY_PUBLIC);

		$this->taskMapper->expects($this->once())->method('find')->willReturn($task);
		$this->stubTaskVisible();
		$this->taskMapper->expects($this->once())->method('update')->willReturnArgument(0);
		$this->stubLockedTask($task);

		$result = $this->update(1, ['visibilityType' => Task::VISIBILITY_ADMIN_ONLY], $this->createHouseUser('house-owner', 'owner'));

		$this->assertSame(Task::VISIBILITY_ADMIN_ONLY, $result->getVisibilityType());
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
		$this->stubLockedTask($task);

		$result = $this->update(1, ['name' => 'Renamed'], $this->createHouseUser('any-member'));

		$this->assertSame('Renamed', $result->getName());
	}

	/**
	 * UpdateTask_TaskTierFields_Retired
	 *
	 * WP4 retirement pin (CS-PLAN-070 §3.4): the Coin-era taskTier/multiplier
	 * authority is gone — the field is no longer a mutable or settable
	 * surface anywhere.
	 */
	public function testUpdateTask_TaskTierFields_Retired(): void {
		$this->assertFalse(defined(TaskService::class . '::TASK_TIERS'), 'TASK_TIERS must be removed');
		$this->assertFalse(method_exists(Task::class, 'setTaskTier'), 'Task::setTaskTier must be removed');
		$this->assertFalse(method_exists(Task::class, 'setTeamSparkMultiplier'), 'Task::setTeamSparkMultiplier must be removed');

		// A stale client payload hits the generic whitelist rejection (400).
		$task = new Task();
		$task->setId(1);
		$task->setVisibilityType(Task::VISIBILITY_PUBLIC);
		$this->taskMapper->expects($this->once())->method('find')->willReturn($task);
		$this->stubTaskVisible();
		$this->taskMapper->expects($this->never())->method('update');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Unknown or immutable field: taskTier');

		$this->update(1, ['taskTier' => 'gold'], $this->createHouseUser('house-owner', 'owner'));
	}

	/**
	 * The owner of a private task is no field of the form (CS-PLAN-091
	 * WP-B6): a body that names one is refused before any write.
	 */
	public function testPrepareUpdate_OwnerIsNoFormField(): void {
		$task = new Task();
		$task->setId(1);
		$task->setOwnerUserId('old-owner');
		$this->taskMapper->method('find')->willReturn($task);
		$this->stubTaskVisible();
		$this->taskMapper->expects($this->never())->method('update');

		$this->expectException(ValidationException::class);
		$this->expectExceptionMessage('Unknown or immutable field: ownerUserId');

		$this->update(1, ['ownerUserId' => 'new-owner'], $this->createHouseUser('house-owner', 'owner'));
	}

	/** A task that becomes private is claimed by whoever made it private, so somebody always sees it. */
	public function testPrepareUpdate_TaskBecomingPrivate_ClaimsTheActor(): void {
		$task = new Task();
		$task->setId(1);
		$task->setVisibilityType(Task::VISIBILITY_PUBLIC);
		$this->stubTaskVisible();

		$accepted = $this->service->prepareUpdate($task, ['visibilityType' => Task::VISIBILITY_PRIVATE], $this->createHouseUser('adult'));

		$this->assertSame('adult', $accepted['ownerUserId']);
	}

	/** An assignee who edits a private task does not take it over from its owner. */
	public function testPrepareUpdate_AssigneeEditOfAPrivateTask_KeepsItsOwner(): void {
		$task = new Task();
		$task->setId(1);
		$task->setVisibilityType(Task::VISIBILITY_PRIVATE);
		$task->setOwnerUserId('creator');
		$this->stubTaskVisible();

		$accepted = $this->service->prepareUpdate($task, ['name' => 'Renamed', 'visibilityType' => Task::VISIBILITY_PRIVATE], $this->createHouseUser('assignee'));

		$this->assertArrayNotHasKey('ownerUserId', $accepted);
	}

	/**
	 * Only the owner of a private task changes its visibility (D-091-109):
	 * an assignee neither opens it to the house nor claims it by a round
	 * trip through public; the house owner's role is no exception.
	 *
	 * @dataProvider privateVisibilityChangers
	 */
	public function testPrepareUpdate_VisibilityOfAPrivateTask_IsItsOwners(string $actorId, string $role, string $to, bool $allowed): void {
		$task = new Task();
		$task->setId(1);
		$task->setVisibilityType(Task::VISIBILITY_PRIVATE);
		$task->setOwnerUserId('creator');
		$this->stubTaskVisible();
		if (!$allowed) {
			$this->expectException(ForbiddenException::class);
			$this->expectExceptionMessage('Only the owner of a private task can change its visibility');
		}

		$accepted = $this->service->prepareUpdate($task, ['visibilityType' => $to], $this->createHouseUser($actorId, $role));

		$this->assertSame($to, $accepted['visibilityType']);
		$this->assertArrayNotHasKey('ownerUserId', $accepted, 'no claim: the task has its owner');
	}

	/** @return array<string, array{string, string, string, bool}> */
	public static function privateVisibilityChangers(): array {
		return [
			'an assignee opens it' => ['assignee', 'member', Task::VISIBILITY_PUBLIC, false],
			'an assigned house owner opens it' => ['house-owner', 'owner', Task::VISIBILITY_PUBLIC, false],
			'an assigned house owner makes it admin-only' => ['house-owner', 'owner', Task::VISIBILITY_ADMIN_ONLY, false],
			'its owner opens it' => ['creator', 'member', Task::VISIBILITY_PUBLIC, true],
			'an assignee sends the unchanged value' => ['assignee', 'member', Task::VISIBILITY_PRIVATE, true],
		];
	}

	/** A private task nobody owns is claimed by whoever saves it, also with a new visibility. */
	public function testPrepareUpdate_PrivateTaskWithoutAnOwner_KeepsTheClaimRule(): void {
		$task = new Task();
		$task->setId(1);
		$task->setVisibilityType(Task::VISIBILITY_PRIVATE);
		$this->stubTaskVisible();

		$kept = $this->service->prepareUpdate($task, ['visibilityType' => Task::VISIBILITY_PRIVATE], $this->createHouseUser('adult'));
		$opened = $this->service->prepareUpdate($task, ['visibilityType' => Task::VISIBILITY_PUBLIC], $this->createHouseUser('adult'));

		$this->assertSame('adult', $kept['ownerUserId']);
		$this->assertSame(Task::VISIBILITY_PUBLIC, $opened['visibilityType']);
	}

	/** One stored form for creation and edit (D-091-100). */
	public function testStoredValue_DatesTextsAndWeekdays(): void {
		$this->assertNull(TaskService::storedValue('activeFrom', ''));
		$this->assertSame('2026-03-01', TaskService::storedValue('activeFrom', '2026-03-01')->format('Y-m-d'));
		$this->assertNull(TaskService::storedValue('description', ''));
		$this->assertNull(TaskService::storedValue('tips', null));
		$this->assertSame('Wipe it', TaskService::storedValue('instructions', 'Wipe it'));
		$this->assertSame('["monday","friday"]', TaskService::storedValue('fixedScheduleDays', [3 => 'monday', 7 => 'friday']));
		$this->assertNull(TaskService::storedValue('fixedScheduleDays', ''));
		$this->assertSame('', TaskService::storedValue('name', ''), 'only optional texts turn empty into none');
	}

	/**
	 * The name fits its column and the description has the cap of the
	 * other free texts (D-091-111): a 400 with a message, never a
	 * database error.
	 *
	 * @dataProvider textLimits
	 */
	public function testValidateTaskData_TextLimits(array $data, ?string $error): void {
		$errors = $this->service->validateTaskData($data + ['name' => 'Dishes']);

		$this->assertSame($error === null ? [] : [$error], $errors);
	}

	/** @return array<string, array{array<string, mixed>, ?string}> */
	public static function textLimits(): array {
		return [
			'a name at the limit' => [['name' => str_repeat('ä', 255)], null],
			'a name beyond the limit' => [['name' => str_repeat('ä', 256)], 'Task name must be at most 255 characters'],
			'a description at the limit' => [['description' => str_repeat('ä', 2000)], null],
			'a description beyond the limit' => [['description' => str_repeat('x', 2001)], 'Description must not exceed 2000 characters'],
			'a description that is no text' => [['description' => ['Wipe']], 'Invalid value type for field: description'],
			'no description' => [['description' => null], null],
		];
	}

	/** The limits hold on the edit path as on creation. */
	public function testPrepareUpdate_ANameBeyondItsColumn_IsRefused(): void {
		$task = new Task();
		$task->setId(1);
		$this->stubTaskVisible();

		$this->expectException(ValidationException::class);
		$this->expectExceptionMessage('Task name must be at most 255 characters');

		$this->service->prepareUpdate($task, ['name' => str_repeat('x', 256)], $this->createHouseUser());
	}

	/** The form sends every field; only the ones that differ change a shared definition. */
	public function testChangedFields_KeepsOnlyRealChanges(): void {
		$task = $this->ruleTask('weekly', 1, 1);
		$task->setActiveFrom(new \DateTime('2026-03-01'));
		$task->setFixedScheduleDays('["monday"]');

		$changed = $this->service->changedFields($task, [
			'name' => 'Clean the gutters',
			'frequencyType' => 'weekly',
			'flexDays' => 2,
			'activeFrom' => '2026-03-01',
			'fixedScheduleDays' => ['monday'],
		]);

		$this->assertSame(['flexDays' => 2], $changed);
	}

	/** The form sends an empty text field as '': no change of a definition without that text. */
	public function testChangedFields_AnEmptyTextIsNoText(): void {
		$task = $this->ruleTask('weekly', 1, 1);
		$task->setDescription(null);
		$task->setInstructions('');

		$changed = $this->service->changedFields($task, ['description' => '', 'instructions' => '', 'tips' => '']);

		$this->assertSame([], $changed);
		$this->assertSame(['description' => 'Wipe it'], $this->service->changedFields($task, ['description' => 'Wipe it']));
	}

	// ------------------------------------------------------------------
	// FEAT-UX-039: the flex cap follows the merged rule (D-088-12)
	// ------------------------------------------------------------------

	private function ruleTask(string $frequencyType, int $frequencyValue, int $flexDays): Task {
		$task = new Task();
		$task->setId(1);
		$task->setName('Clean the gutters');
		$task->setFrequencyType($frequencyType);
		$task->setFrequencyValue($frequencyValue);
		$task->setRecurrenceMode(Task::RECURRENCE_RECURRING);
		$task->setSchedulingMode(Task::SCHEDULING_DYNAMIC);
		$task->setFlexDays($flexDays);
		return $task;
	}

	private function stubEditableTask(Task $task): void {
		$this->taskMapper->method('find')->willReturn($task);
		$this->stubTaskVisible();
		$this->stubLockedTask($task);
	}

	public function testUpdateTask_FlexAtTheCapOfAHalfYearRule_Saves(): void {
		$this->stubEditableTask($this->ruleTask('monthly', 6, 7));
		$this->taskMapper->method('update')->willReturnArgument(0);

		$result = $this->update(1, ['flexDays' => 90], $this->createHouseUser());

		$this->assertSame(90, $result->getFlexDays());
	}

	public function testUpdateTask_FlexOneAboveTheCap_RejectedWithoutWrite(): void {
		$this->stubEditableTask($this->ruleTask('monthly', 6, 7));
		$this->taskMapper->expects($this->never())->method('update');

		$this->expectException(ValidationException::class);
		$this->update(1, ['flexDays' => 91], $this->createHouseUser());
	}

	public function testUpdateTask_UnrelatedEditOfALegacyValueAboveTheCap_Saves(): void {
		// A daily task kept its former 7 flex days (cap now 1); the edit form
		// re-sends the unchanged rule fields with the new name.
		$this->stubEditableTask($this->ruleTask('daily', 1, 7));
		$this->taskMapper->method('update')->willReturnArgument(0);

		$result = $this->update(1, [
			'name' => 'Wipe the table',
			'frequencyType' => 'daily',
			'frequencyValue' => 1,
			'flexDays' => 7,
		], $this->createHouseUser());

		$this->assertSame('Wipe the table', $result->getName());
		$this->assertSame(7, $result->getFlexDays(), 'a stored legacy value is never rewritten');
	}

	public function testUpdateTask_ChangedRuleRevalidatesALegacyValue(): void {
		$this->stubEditableTask($this->ruleTask('daily', 1, 7));
		$this->taskMapper->expects($this->never())->method('update');

		$this->expectException(ValidationException::class);
		// Every 2 days → cap 1, the stored 7 no longer fits the changed rule.
		$this->update(1, ['frequencyValue' => 2], $this->createHouseUser());
	}

	public function testUpdateTask_FlexAboveTheSanityBound_RejectedBeforeTheLock(): void {
		$this->taskMapper->method('find')->willReturn($this->ruleTask('monthly', 24, 0));
		$this->stubTaskVisible();

		// No stubLockedTask(): reaching the lock would fail the test.
		$this->expectException(ValidationException::class);
		$this->update(1, ['flexDays' => 400], $this->createHouseUser());
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
		$taskData = ['name' => 'Test', 'energyCost' => 21];

		$result = $this->service->validateTaskData($taskData);

		$this->assertContains('Energy cost must be between 1 and 20', $result);
	}

	/**
	 * @dataProvider energyCostBoundsProvider
	 */
	public function testValidateTaskData_EnergyCostBounds(int $energyCost, bool $accepted): void {
		$errors = $this->service->validateTaskData(['name' => 'Test', 'energyCost' => $energyCost]);

		$this->assertSame($accepted, !in_array('Energy cost must be between 1 and 20', $errors, true));
	}

	public static function energyCostBoundsProvider(): array {
		return [
			'below' => [0, false],
			'lowest' => [1, true],
			'highest' => [20, true],
			'above' => [21, false],
		];
	}

	public function testUpdateTask_EnergyAboveTheLimit_RefusesWithoutWriting(): void {
		$task = new Task();
		$task->setId(1);
		$task->setName('Old Name');
		$task->setEnergyCost(2);
		$this->taskMapper->method('find')->with(1)->willReturn($task);
		$this->stubTaskVisible();
		$this->stubLockedTask($task);
		$this->taskMapper->expects($this->never())->method('update');

		try {
			$this->update(1, ['energyCost' => 21], $this->createHouseUser());
			$this->fail('Expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertSame(['Energy cost must be between 1 and 20'], $e->getErrors());
		}
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
	 * CS-PLAN-090 WP-I (D-090-65): a task takes only a key of the household's
	 * current list; a deleted key and a non-string value are refused.
	 *
	 * @dataProvider categoryProvider
	 */
	public function testValidateTaskData_Category(mixed $category, bool $accepted): void {
		$errors = $this->service->validateTaskData(['name' => 'Test', 'category' => $category]);

		$this->assertSame($accepted, !in_array('Unknown task category', $errors, true));
	}

	public static function categoryProvider(): array {
		return [
			'a listed built-in key' => ['kitchen', true],
			'other' => ['other', true],
			'a key the household deleted or never had' => ['garden', false],
			'not a string' => [3, false],
			'null' => [null, false],
		];
	}

	/**
	 * CS-PLAN-091 WP-B4: the bonus flag decides whether a task has cycles at
	 * all, so only a real boolean is taken.
	 *
	 * @dataProvider bonusFlagProvider
	 */
	public function testValidateTaskData_BonusFlag(mixed $flag, bool $accepted): void {
		$errors = $this->service->validateTaskData(['name' => 'Test', 'isVoluntary' => $flag]);

		$this->assertSame($accepted, !in_array('Invalid value type for field: isVoluntary', $errors, true));
	}

	public static function bonusFlagProvider(): array {
		return [
			'true' => [true, true],
			'false' => [false, true],
			'the number 1' => [1, false],
			'the text true' => ['true', false],
			'null' => [null, false],
		];
	}

	public function testValidateTaskData_WithoutABonusFlag_IsValid(): void {
		$this->assertSame([], $this->service->validateTaskData(['name' => 'Test']));
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
