<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Db\TaskMapper;
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

	protected function setUp(): void {
		$this->taskMapper = $this->createMock(TaskMapper::class);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text) => $text);
		$this->service = new TaskService($this->taskMapper, $l10n);
	}

	public function testUpdateTask_RejectsUnknownField_WithInvalidArgument(): void {
		$task = new Task();
		$this->taskMapper->method('find')->willReturn($task);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Unknown or immutable field: bogusColumn');
		$this->service->updateTask(1, ['bogusColumn' => 'evil'], 'caller-user', false);
	}

	public function testUpdateTask_RejectsImmutableInternalColumn_WithInvalidArgument(): void {
		// isTemplate is intentionally NOT whitelisted (template-ness cannot
		// be flipped via update); even though a setter exists, the whitelist
		// must still reject it.
		$task = new Task();
		$this->taskMapper->method('find')->willReturn($task);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Unknown or immutable field: isTemplate');
		$this->service->updateTask(1, ['isTemplate' => true], 'caller-user', false);
	}

	public function testUpdateTask_AppliesWhitelistedField(): void {
		$task = new Task();
		$this->taskMapper->expects($this->once())->method('find')->willReturn($task);
		$this->taskMapper->expects($this->once())->method('update')->willReturnArgument(0);

		$result = $this->service->updateTask(1, ['name' => 'Shiny New Name'], 'caller-user', false);

		$this->assertSame('Shiny New Name', $result->getName());
	}

	public function testUpdateTask_ParsesDateFieldFromString(): void {
		$task = new Task();
		$this->taskMapper->method('find')->willReturn($task);
		$this->taskMapper->method('update')->willReturnArgument(0);

		$result = $this->service->updateTask(1, ['activeFrom' => '2026-01-15'], 'caller-user', false);

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

		$result = $this->service->updateTask(1, [
			'name' => 'Multi',
			'energyCost' => 3,
			'priority' => 1,
		], 'caller-user', false);

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
		$this->service->updateTask(1, ['name' => 'Multi', 'taskTier' => 'gold'], 'caller-user', false);
	}

	public function testUpdateTask_RejectsMassAssignmentOfId(): void {
		$task = new Task();
		$task->setId(42);
		$this->taskMapper->method('find')->willReturn($task);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Unknown or immutable field: id');
		$this->service->updateTask(42, ['id' => 999], 'caller-user', false);
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
		$this->service->updateTask(1, ['isPrivate' => true], 'caller-user', false);
	}
}
