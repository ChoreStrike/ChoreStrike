<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Db;

use OCA\ChoreStrike\Db\Task;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Task visibility type logic (CS-PLAN-005).
 * The findVisibleByHouse() query is covered by integration tests.
 */
class TaskMapperVisibilityTest extends TestCase
{
    public function testDefaultVisibilityIsPublic(): void
    {
        $task = new Task();
        $this->assertSame(Task::VISIBILITY_PUBLIC, $task->getVisibilityType());
        $this->assertFalse($task->isPrivate());
    }

    public function testSetIsPrivateTrue_SetsVisibilityPrivate(): void
    {
        $task = new Task();
        $task->setIsPrivate(true);
        $this->assertSame(Task::VISIBILITY_PRIVATE, $task->getVisibilityType());
        $this->assertTrue($task->isPrivate());
    }

    public function testSetIsPrivateFalse_SetsVisibilityPublic(): void
    {
        $task = new Task();
        $task->setIsPrivate(true);
        $task->setIsPrivate(false);
        $this->assertSame(Task::VISIBILITY_PUBLIC, $task->getVisibilityType());
        $this->assertFalse($task->isPrivate());
    }

    public function testSetVisibilityPrivate_IsPrivateReturnsTrue(): void
    {
        $task = new Task();
        $task->setVisibilityType(Task::VISIBILITY_PRIVATE);
        $this->assertTrue($task->isPrivate());
    }

    public function testSetVisibilityAdminOnly_IsPrivateReturnsFalse(): void
    {
        $task = new Task();
        $task->setVisibilityType(Task::VISIBILITY_ADMIN_ONLY);
        $this->assertFalse($task->isPrivate());
    }

    public function testVisibilityConstants(): void
    {
        $this->assertSame('public', Task::VISIBILITY_PUBLIC);
        $this->assertSame('private', Task::VISIBILITY_PRIVATE);
        $this->assertSame('admin-only', Task::VISIBILITY_ADMIN_ONLY);
        $this->assertCount(3, Task::VISIBILITY_TYPES);
    }

    public function testJsonSerialize_IncludesVisibilityFields(): void
    {
        $task = new Task();
        $task->setName('Test');
        $task->setVisibilityType(Task::VISIBILITY_PRIVATE);
        $task->setOwnerUserId('user1');

        $json = $task->jsonSerialize();
        $this->assertSame(Task::VISIBILITY_PRIVATE, $json['visibilityType']);
        $this->assertSame('user1', $json['ownerUserId']);
        $this->assertTrue($json['isPrivate']);
    }

    public function testJsonSerialize_PublicTask(): void
    {
        $task = new Task();
        $task->setName('Test');

        $json = $task->jsonSerialize();
        $this->assertSame(Task::VISIBILITY_PUBLIC, $json['visibilityType']);
        $this->assertNull($json['ownerUserId']);
        $this->assertFalse($json['isPrivate']);
    }

    public function testValidate_InvalidVisibilityType(): void
    {
        $task = new Task();
        $task->setName('Test');
        $task->setVisibilityType('invalid');

        $errors = $task->validate();
        $this->assertContains('Invalid visibility type', $errors);
    }

    public function testValidate_ValidVisibilityTypes(): void
    {
        foreach (Task::VISIBILITY_TYPES as $type) {
            $task = new Task();
            $task->setName('Test');
            $task->setVisibilityType($type);

            $errors = $task->validate();
            $this->assertNotContains('Invalid visibility type', $errors, "Failed for type: {$type}");
        }
    }

    public function testOwnerUserId_DefaultNull(): void
    {
        $task = new Task();
        $this->assertNull($task->getOwnerUserId());
    }

    public function testOwnerUserId_SetAndGet(): void
    {
        $task = new Task();
        $task->setOwnerUserId('admin');
        $this->assertSame('admin', $task->getOwnerUserId());
    }
}
