<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Db\Mapper;

use OCA\ChoreStrike\Db\Task;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Task visibility type logic (CS-PLAN-005) and the canonical
 * recurrence vocabulary (CS-PLAN-060 WP1 D3).
 * The findVisibleByHouse() query is covered by integration tests.
 */
class TaskMapperVisibilityTest extends TestCase {
	public function testDefaultVisibilityIsPublic(): void {
		$task = new Task();
		$this->assertSame(Task::VISIBILITY_PUBLIC, $task->getVisibilityType());
	}

	public function testSetVisibilityPrivate_IsStored(): void {
		$task = new Task();
		$task->setVisibilityType(Task::VISIBILITY_PRIVATE);
		$this->assertSame(Task::VISIBILITY_PRIVATE, $task->getVisibilityType());
	}

	public function testSetVisibilityAdminOnly_IsStored(): void {
		$task = new Task();
		$task->setVisibilityType(Task::VISIBILITY_ADMIN_ONLY);
		$this->assertSame(Task::VISIBILITY_ADMIN_ONLY, $task->getVisibilityType());
	}

	public function testVisibilityConstants(): void {
		$this->assertSame('public', Task::VISIBILITY_PUBLIC);
		$this->assertSame('private', Task::VISIBILITY_PRIVATE);
		$this->assertSame('admin-only', Task::VISIBILITY_ADMIN_ONLY);
		$this->assertCount(3, Task::VISIBILITY_TYPES);
	}

	/**
	 * Canonical recurrence vocabulary (CS-PLAN-060 WP1, D3): the entity
	 * constants are the single vocabulary; validation and the scheduler
	 * branch on them.
	 */
	public function testRecurrenceConstants(): void {
		$this->assertSame('recurring', Task::RECURRENCE_RECURRING);
		$this->assertSame('once', Task::RECURRENCE_ONCE);
		$this->assertSame('bounded', Task::RECURRENCE_BOUNDED);
		$this->assertSame(['recurring', 'once', 'bounded'], Task::RECURRENCE_MODES);
	}

	public function testJsonSerialize_IncludesVisibilityFields(): void {
		$task = new Task();
		$task->setName('Test');
		$task->setVisibilityType(Task::VISIBILITY_PRIVATE);
		$task->setOwnerUserId('user1');

		$json = $task->jsonSerialize();
		$this->assertSame(Task::VISIBILITY_PRIVATE, $json['visibilityType']);
		$this->assertSame('user1', $json['ownerUserId']);
	}

	public function testJsonSerialize_PublicTask(): void {
		$task = new Task();
		$task->setName('Test');

		$json = $task->jsonSerialize();
		$this->assertSame(Task::VISIBILITY_PUBLIC, $json['visibilityType']);
		$this->assertNull($json['ownerUserId']);
	}

	/**
	 * The legacy isPrivate shim is gone (CS-PLAN-060 WP1, D1): the payload
	 * carries visibilityType only.
	 */
	public function testJsonSerialize_DoesNotEmitLegacyIsPrivateKey(): void {
		$task = new Task();
		$json = $task->jsonSerialize();
		$this->assertArrayNotHasKey('isPrivate', $json);
	}

	public function testOwnerUserId_DefaultNull(): void {
		$task = new Task();
		$this->assertNull($task->getOwnerUserId());
	}

	public function testOwnerUserId_SetAndGet(): void {
		$task = new Task();
		$task->setOwnerUserId('admin');
		$this->assertSame('admin', $task->getOwnerUserId());
	}
}
