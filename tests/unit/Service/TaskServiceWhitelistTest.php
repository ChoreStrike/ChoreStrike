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
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the updateTask mutable-field whitelist (CS-PLAN-011 S4).
 *
 * Verifies that only whitelisted columns may be mass-assigned and that any
 * other key is rejected with \InvalidArgumentException (→ 400), preventing
 * mass-assignment of internal columns (id, created_at, is_template, …).
 */
class TaskServiceWhitelistTest extends TestCase
{
    private TaskService $service;
    private TaskMapper $taskMapper;

    protected function setUp(): void
    {
        $this->taskMapper = $this->createMock(TaskMapper::class);
        $this->service = new TaskService($this->taskMapper);
    }

    public function testUpdateTask_RejectsUnknownField_WithInvalidArgument(): void
    {
        $task = new Task();
        $this->taskMapper->method('find')->willReturn($task);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown or immutable field: bogusColumn');
        $this->service->updateTask(1, ['bogusColumn' => 'evil']);
    }

    public function testUpdateTask_RejectsImmutableInternalColumn_WithInvalidArgument(): void
    {
        // isTemplate is intentionally NOT whitelisted (template-ness cannot
        // be flipped via update); even though a setter exists, the whitelist
        // must still reject it.
        $task = new Task();
        $this->taskMapper->method('find')->willReturn($task);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown or immutable field: isTemplate');
        $this->service->updateTask(1, ['isTemplate' => true]);
    }

    public function testUpdateTask_AppliesWhitelistedField(): void
    {
        $task = new Task();
        $this->taskMapper->expects($this->once())->method('find')->willReturn($task);
        $this->taskMapper->expects($this->once())->method('update')->willReturnArgument(0);

        $result = $this->service->updateTask(1, ['name' => 'Shiny New Name']);

        $this->assertSame('Shiny New Name', $result->getName());
    }

    public function testUpdateTask_ParsesDateFieldFromString(): void
    {
        $task = new Task();
        $this->taskMapper->method('find')->willReturn($task);
        $this->taskMapper->method('update')->willReturnArgument(0);

        $result = $this->service->updateTask(1, ['activeFrom' => '2026-01-15']);

        $this->assertInstanceOf(\DateTime::class, $result->getActiveFrom());
        $this->assertSame('2026-01-15', $result->getActiveFrom()->format('Y-m-d'));
    }

    public function testUpdateTask_AppliesMultipleWhitelistedFieldsAtomically(): void
    {
        $task = new Task();
        $this->taskMapper->method('find')->willReturn($task);
        $this->taskMapper->method('update')->willReturnArgument(0);

        $result = $this->service->updateTask(1, [
            'name' => 'Multi',
            'energyCost' => 3,
            'priority' => 1,
            'taskTier' => 'gold',
        ]);

        $this->assertSame('Multi', $result->getName());
        $this->assertSame(3, $result->getEnergyCost());
        $this->assertSame('gold', $result->getTaskTier());
    }

    public function testUpdateTask_RejectsMassAssignmentOfId(): void
    {
        $task = new Task();
        $task->setId(42);
        $this->taskMapper->method('find')->willReturn($task);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown or immutable field: id');
        $this->service->updateTask(42, ['id' => 999]);
    }

    /**
     * `isPrivate` is a legacy frontend shim that delegates to setVisibilityType.
     * It is intentionally NOT whitelisted: the controller normalizes it into
     * the canonical `visibilityType` and strips it before calling updateTask,
     * so the service rejects any `isPrivate` that reaches it (defense in depth
     * — prevents the shim from clobbering an admin-only value applied via
     * visibilityType).
     */
    public function testUpdateTask_RejectsIsPrivateLegacyField(): void
    {
        $task = new Task();
        $this->taskMapper->method('find')->willReturn($task);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown or immutable field: isPrivate');
        $this->service->updateTask(1, ['isPrivate' => true]);
    }
}
