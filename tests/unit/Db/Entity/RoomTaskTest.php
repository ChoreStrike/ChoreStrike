<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Db\Entity;

use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\Task;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for room task entity behavior. The legacy recurrence engine
 * (markCompleted / projectOccurrences) was retired with CS-PLAN-072 WP1
 * (RF-072-WP1-12/-16) — recurrence enumeration coverage lives in
 * RecurrenceCalculatorTest, WindowServiceTest and the CycleTimelineTest
 * integration suite.
 */
class RoomTaskTest extends TestCase {
	/**
	 * The removed 'one_time' spelling must no longer be part of the
	 * canonical vocabulary (CR-053-09).
	 */
	public function testLegacyOneTimeSpelling_IsNotTheCanonicalVocabulary(): void {
		self::assertNotContains('one_time', Task::RECURRENCE_MODES);
		self::assertNotSame('one_time', Task::RECURRENCE_ONCE);
	}

	// ========== Null-Safety Regression Tests (CS-PLAN-004 Phase 1) ==========

	/**
	 * getDaysUntilDue must return null (not crash) when nextDue is null.
	 */
	public function testGetDaysUntilDueReturnsNullWhenNextDueIsNull(): void {
		$roomTask = new RoomTask();
		$roomTask->setIsActive(true);

		self::assertNull($roomTask->getDaysUntilDue());
	}

	/**
	 * CS-PLAN-084 D-084-08: `next_due` is the planned day's start instant in
	 * the house zone; a task due today is due — never overdue — until the
	 * house-local day is over, in zones east AND west of UTC.
	 *
	 * @dataProvider houseZones
	 */
	public function testIsOverdue_StartsOnlyAfterTheHouseLocalDueDay(string $zone): void {
		$tz = new \DateTimeZone($zone);
		$dayStart = static fn (string $relative): \DateTime => (new \DateTime($relative, $tz))->setTime(0, 0, 0)->setTimezone(new \DateTimeZone('UTC'));
		$roomTask = new RoomTask();
		$roomTask->setIsActive(true);

		$roomTask->setNextDue($dayStart('today'));
		self::assertFalse($roomTask->isOverdue($tz), 'due today after its midnight instant is not overdue');
		self::assertSame('due_today', $roomTask->getStatus($tz));

		$roomTask->setNextDue($dayStart('yesterday'));
		self::assertTrue($roomTask->isOverdue($tz));
		self::assertSame('overdue', $roomTask->getStatus($tz));

		$roomTask->setIsActive(false);
		self::assertFalse($roomTask->isOverdue($tz), 'inactive tasks are never overdue');
	}

	public static function houseZones(): array {
		return [['UTC'], ['Europe/Berlin'], ['America/New_York'], ['Pacific/Auckland']];
	}

	public function testIsOverdue_WithoutDueDate_IsFalse(): void {
		$roomTask = new RoomTask();
		$roomTask->setIsActive(true);
		self::assertFalse($roomTask->isOverdue(new \DateTimeZone('UTC')));
	}

	/**
	 * jsonSerialize must emit daysUntilDue => null when nextDue is null.
	 */
	public function testJsonSerializeEmitsNullDaysUntilDueWhenNextDueIsNull(): void {
		$roomTask = new RoomTask();
		$roomTask->setIsActive(true);
		$roomTask->setRoomId(1);
		$roomTask->setTaskId(1);

		$json = $roomTask->jsonSerialize();

		self::assertNull($json['daysUntilDue']);
		self::assertNull($json['nextDue']);
	}

	/**
	 * The payload contract carries no removed legacy keys (CS-PLAN-060 WP1,
	 * D2/D4/D9): assignees live in the task_assignees join table, the sync
	 * and group-assignment remnants were folded out of the schema.
	 */
	public function testJsonSerialize_DoesNotEmitRemovedLegacyKeys(): void {
		$roomTask = new RoomTask();
		$roomTask->setRoomId(1);
		$roomTask->setTaskId(1);
		$roomTask->setNextDue(new \DateTime('2026-03-10'));

		$json = $roomTask->jsonSerialize();

		foreach (['assignedToUserId', 'nextcloudTaskId', 'assignmentType', 'assignedToGroup', 'assignedByUserId', 'assignedAt'] as $removedKey) {
			self::assertArrayNotHasKey($removedKey, $json, "payload key '$removedKey' must be gone");
		}
		// The claim-feature snapshot surface stays (decision D9).
		self::assertArrayHasKey('claimedByUser', $json);
		self::assertArrayHasKey('wasClaimed', $json);
		self::assertArrayHasKey('originalAssignee', $json);
		self::assertArrayHasKey('claimedAt', $json);
	}

	public function testClaimSourceCompletionId_StaysInternalToTheRoomTask(): void {
		$roomTask = new RoomTask();
		$roomTask->setClaimedSourceCompletionId(42);

		self::assertSame(42, $roomTask->getClaimedSourceCompletionId());
		self::assertArrayNotHasKey('claimedSourceCompletionId', $roomTask->jsonSerialize());

		$roomTask->setClaimedSourceCompletionId(null);
		self::assertNull($roomTask->getClaimedSourceCompletionId());
	}
}
