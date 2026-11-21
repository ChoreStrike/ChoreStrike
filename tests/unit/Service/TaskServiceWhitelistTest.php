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
 * Unit tests for the closed field set of the one form (CS-PLAN-011 S4,
 * CS-PLAN-091 WP-B6: {@see TaskService::FORM_FIELDS}).
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
		// The gate denies non-viewers before the whitelist loop — the
		// visibility matrix is exercised in TaskServiceTest; admit everyone
		// here so the whitelist behavior stays isolated.
		$this->taskAccessPolicy->method('canViewTask')->willReturn(true);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static function (string $text, array $parameters = []) {
			$pattern = preg_replace('/%[0-9]+\$[ds]/', '%s', $text);
			return $parameters === [] ? $pattern : vsprintf($pattern, array_map('strval', array_values($parameters)));
		});

		// The under-lock task re-read returns the test's prepared entity (stubLockedTask).
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

	/** The definition part of the one form's save: the gate, then the locked write. */
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
		$this->update(1, ['bogusColumn' => 'evil'], $this->createHouseUser());
	}

	public function testUpdateTask_RejectsImmutableInternalColumn_WithInvalidArgument(): void {
		// isTemplate is intentionally NOT whitelisted (template-ness cannot
		// be flipped via update); even though a setter exists, the whitelist
		// must still reject it.
		$task = new Task();
		$this->taskMapper->method('find')->willReturn($task);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Unknown or immutable field: isTemplate');
		$this->update(1, ['isTemplate' => true], $this->createHouseUser());
	}

	public function testUpdateTask_AppliesWhitelistedField(): void {
		$task = new Task();
		$this->taskMapper->expects($this->once())->method('find')->willReturn($task);
		$this->taskMapper->expects($this->once())->method('update')->willReturnArgument(0);
		$this->stubLockedTask($task);

		$result = $this->update(1, ['name' => 'Shiny New Name'], $this->createHouseUser());

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
			$this->update(1, ['name' => null, 'energyCost' => 3], $this->createHouseUser());
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

		$result = $this->update(1, ['activeFrom' => '2026-01-15'], $this->createHouseUser());

		$this->assertInstanceOf(\DateTime::class, $result->getActiveFrom());
		$this->assertSame('2026-01-15', $result->getActiveFrom()->format('Y-m-d'));
	}

	/**
	 * Multi-field atomicity for whitelist members only.
	 */
	public function testUpdateTask_AppliesMultipleWhitelistedFieldsAtomically(): void {
		$task = new Task();
		$this->taskMapper->method('find')->willReturn($task);
		$this->taskMapper->method('update')->willReturnArgument(0);
		$this->stubLockedTask($task);

		$result = $this->update(1, [
			'name' => 'Multi',
			'energyCost' => 3,
			'priority' => 1,
		], $this->createHouseUser());

		$this->assertSame('Multi', $result->getName());
		$this->assertSame(3, $result->getEnergyCost());
	}

	/**
	 * WP4 retirement pin: the retired Coin-era `taskTier` key hits the
	 * generic whitelist rejection (400), not a tier gate.
	 */
	public function testUpdateTask_MemberSendingTaskTier_HitsWhitelistRejection(): void {
		$task = new Task();
		$this->taskMapper->method('find')->willReturn($task);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Unknown or immutable field: taskTier');
		$this->update(1, ['name' => 'Multi', 'taskTier' => 'gold'], $this->createHouseUser());
	}

	public function testUpdateTask_RejectsMassAssignmentOfId(): void {
		$task = new Task();
		$task->setId(42);
		$this->taskMapper->method('find')->willReturn($task);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Unknown or immutable field: id');
		$this->update(42, ['id' => 999], $this->createHouseUser());
	}

	/**
	 * `isPrivate` is a legacy frontend shim that delegates to setVisibilityType.
	 * It is intentionally NOT a field of the form: the canonical
	 * `visibilityType` is the only visibility input, so the service rejects
	 * any `isPrivate` (defense in depth — the shim can never clobber an
	 * admin-only value applied via visibilityType).
	 */
	public function testUpdateTask_RejectsIsPrivateLegacyField(): void {
		$task = new Task();
		$this->taskMapper->method('find')->willReturn($task);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Unknown or immutable field: isPrivate');
		$this->update(1, ['isPrivate' => true], $this->createHouseUser());
	}
}
