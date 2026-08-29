<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Db\Entity;

use OCA\ChoreStrike\Db\TaskAssignee;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for TaskAssignee entity
 */
class TaskAssigneeTest extends TestCase {
	/**
	 * JsonSerialize_ReturnsCorrectArray
	 */
	public function testJsonSerialize_ReturnsCorrectArray(): void {
		$assignee = new TaskAssignee();
		$assignee->setId(4);
		$assignee->setRoomTaskId(21);
		$assignee->setUserId('dad');
		$assignedAt = new \DateTime('2026-08-27 08:15:00');
		$assignee->setAssignedAt($assignedAt);
		$assignee->setAssignedBy('mom');

		$result = $assignee->jsonSerialize();

		self::assertEquals(4, $result['id']);
		self::assertEquals(21, $result['roomTaskId']);
		self::assertEquals('dad', $result['userId']);
		self::assertEquals($assignedAt->format('c'), $result['assignedAt']);
		self::assertEquals('mom', $result['assignedBy']);
	}

	/**
	 * JsonSerialize_WithNullOptionals_ReturnsNullFields
	 */
	public function testJsonSerialize_WithNullOptionals_ReturnsNullFields(): void {
		$assignee = new TaskAssignee();

		$result = $assignee->jsonSerialize();

		self::assertNull($result['id']);
		self::assertNull($result['assignedAt']);
		self::assertNull($result['assignedBy']);
	}

	/**
	 * SetAndGetProperties_WorksCorrectly
	 */
	public function testSetAndGetProperties_WorksCorrectly(): void {
		$assignee = new TaskAssignee();
		$assignee->setRoomTaskId(9);
		$assignee->setUserId('teen');
		$assignedAt = new \DateTime('2026-08-26 14:00:00');
		$assignee->setAssignedAt($assignedAt);
		$assignee->setAssignedBy('mom');

		self::assertEquals(9, $assignee->getRoomTaskId());
		self::assertEquals('teen', $assignee->getUserId());
		self::assertSame($assignedAt, $assignee->getAssignedAt());
		self::assertEquals('mom', $assignee->getAssignedBy());
	}
}
