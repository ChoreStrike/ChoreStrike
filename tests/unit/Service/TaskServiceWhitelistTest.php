<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\TaskAccessPolicy;
use OCA\ChoreStrike\Service\TaskService;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the updateTask mutable-field whitelist (CS-PLAN-011 S4).
 *
 * Verifies that only whitelisted columns may be mass-assigned and that any
 * other key is rejected with \InvalidArgumentException (→ 400), preventing
 * mass-assignment of internal columns (id, created_at, is_template, …).
 */
class TaskServiceWhitelistTest extends TestCase {
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
		// updateTask denies non-viewers before the whitelist loop — the
		// visibility matrix is exercised in TaskServiceTest; admit everyone
		// here so the whitelist behavior stays isolated.
		$this->taskAccessPolicy->method('canViewTask')->willReturn(true);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static function (string $text, array $parameters = []) {
			$pattern = preg_replace('/%[0-9]+\$[ds]/', '%s', $text);
			return $parameters === [] ? $pattern : vsprintf($pattern, array_map('strval', array_values($parameters)));
		});

		// CS-PLAN-071 WP0: updateTask writes run house-first; the under-lock
		// task re-read returns the test's prepared entity (stubLockedTask).
		$houseMapper = $this->createMock(\OCA\ChoreStrike\Db\HouseMapper::class);
		$houseMapper->method('findForUpdate')->willReturn(new \OCA\ChoreStrike\Db\House());
		$this->taskMapper->method('lockForUpdate')->willReturnCallback(function (): Task {
			if ($this->lockedTask === null) {
				throw new \LogicException('call stubLockedTask() for updateTask success-path tests');
			}
			return $this->lockedTask;
		});

		$this->service = new TaskService($this->taskMapper, $houseMapper, $l10n, $this->authorization, $this->taskAccessPolicy, new \OCA\ChoreStrike\Db\TransactionExecutor($this->createMock(\OCP\IDBConnection::class)));
	}

	/**
	 * Register the entity the under-lock task re-read returns.
	 */
	private function stubLockedTask(Task $task): void {
		$this->lockedTask = $task;
	}

	/**
	 * Member actor — the default for the whitelist loop tests.
	 */
	private function createHouseUser(string $userId = 'caller-user', string $role = 'member'): HouseUser {
		$houseUser = new HouseUser();
		$houseUser->setHouseId(1);
		$houseUser->setNextcloudUserId($userId);
		$houseUser->setRole($role);
		return $houseUser;
	}

	public function testUpdateTask_RejectsUnknownField_WithInvalidArgument(): void {
		$task = new Task();
		$this->taskMapper->method('find')->willReturn($task);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Unknown or immutable field: bogusColumn');
		$this->service->updateTask(1, ['bogusColumn' => 'evil'], $this->createHouseUser());
	}

	public function testUpdateTask_RejectsImmutableInternalColumn_WithInvalidArgument(): void {
		// isTemplate is intentionally NOT whitelisted (template-ness cannot
		// be flipped via update); even though a setter exists, the whitelist
		// must still reject it.
		$task = new Task();
		$this->taskMapper->method('find')->willReturn($task);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Unknown or immutable field: isTemplate');
		$this->service->updateTask(1, ['isTemplate' => true], $this->createHouseUser());
	}

	public function testUpdateTask_AppliesWhitelistedField(): void {
		$task = new Task();
		$this->taskMapper->expects($this->once())->method('find')->willReturn($task);
		$this->taskMapper->expects($this->once())->method('update')->willReturnArgument(0);
		$this->stubLockedTask($task);

		$result = $this->service->updateTask(1, ['name' => 'Shiny New Name'], $this->createHouseUser());

		$this->assertSame('Shiny New Name', $result->getName());
	}

	/**
	 * UpdateTask_NullName_RejectedAsValidation400
	 *
	 * CS-PLAN-063 Standard B finding F1: a present-but-null `name` is a
	 * validation error (the pre-aggregate controller contract), not a
	 * setter call that would hit the NOT NULL column and answer 500.
	 * Key presence counts — `?? null` cannot distinguish absent from null.
	 */
	public function testUpdateTask_NullName_RejectedAsValidation400(): void {
		$task = new Task();
		$this->taskMapper->method('find')->willReturn($task);
		$this->taskMapper->expects($this->never())->method('update');

		try {
			$this->service->updateTask(1, ['name' => null, 'energyCost' => 3], $this->createHouseUser());
			$this->fail('A null name must be rejected by validation');
		} catch (\OCA\ChoreStrike\Exception\ValidationException $e) {
			$this->assertSame(['Task name is required'], $e->getErrors());
		}
	}

	public function testUpdateTask_ParsesDateFieldFromString(): void {
		$task = new Task();
		$this->taskMapper->method('find')->willReturn($task);
		$this->taskMapper->method('update')->willReturnArgument(0);
		$this->stubLockedTask($task);

		$result = $this->service->updateTask(1, ['activeFrom' => '2026-01-15'], $this->createHouseUser());

		$this->assertInstanceOf(\DateTime::class, $result->getActiveFrom());
		$this->assertSame('2026-01-15', $result->getActiveFrom()->format('Y-m-d'));
	}

	/**
	 * Multi-field atomicity for whitelist members only — `taskTier` left
	 * the whitelist (CR-053-04: owner-only, handled explicitly in
	 * updateTask), so a non-owner multi-field payload must be rejected.
	 */
	public function testUpdateTask_AppliesMultipleWhitelistedFieldsAtomically(): void {
		$task = new Task();
		$this->taskMapper->method('find')->willReturn($task);
		$this->taskMapper->method('update')->willReturnArgument(0);
		$this->stubLockedTask($task);

		$result = $this->service->updateTask(1, [
			'name' => 'Multi',
			'energyCost' => 3,
			'priority' => 1,
		], $this->createHouseUser());

		$this->assertSame('Multi', $result->getName());
		$this->assertSame(3, $result->getEnergyCost());
	}

	/**
	 * CR-053-04: taskTier is no longer a generic whitelist member — a
	 * member sending it hits the explicit owner gate first (403), not the
	 * whitelist loop.
	 */
	public function testUpdateTask_MemberSendingTaskTier_ThrowsForbiddenBeforeWhitelist(): void {
		$task = new Task();
		$this->taskMapper->method('find')->willReturn($task);

		$this->expectException(\OCA\ChoreStrike\Exception\ForbiddenException::class);
		$this->expectExceptionMessage('Only house owners may change the task tier');
		$this->service->updateTask(1, ['name' => 'Multi', 'taskTier' => 'gold'], $this->createHouseUser());
	}

	public function testUpdateTask_RejectsMassAssignmentOfId(): void {
		$task = new Task();
		$task->setId(42);
		$this->taskMapper->method('find')->willReturn($task);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Unknown or immutable field: id');
		$this->service->updateTask(42, ['id' => 999], $this->createHouseUser());
	}

	/**
	 * `isPrivate` is a legacy frontend shim that delegates to setVisibilityType.
	 * It is intentionally NOT whitelisted: the controller normalizes it into
	 * the canonical `visibilityType` and strips it before calling updateTask,
	 * so the service rejects any `isPrivate` that reaches it (defense in depth
	 * — prevents the shim from clobbering an admin-only value applied via
	 * visibilityType).
	 */
	public function testUpdateTask_RejectsIsPrivateLegacyField(): void {
		$task = new Task();
		$this->taskMapper->method('find')->willReturn($task);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Unknown or immutable field: isPrivate');
		$this->service->updateTask(1, ['isPrivate' => true], $this->createHouseUser());
	}
}
