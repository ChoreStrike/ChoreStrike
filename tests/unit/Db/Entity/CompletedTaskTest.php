<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Db\Entity;

use OCA\ChoreStrike\Db\CompletedTask;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CompletedTask entity (CS-PLAN-070 WP3 work-evidence
 * shape: frozen effort/rate + versioning pair; no monetary outputs).
 */
class CompletedTaskTest extends TestCase {
	/**
	 * JsonSerialize_ReturnsCorrectArray
	 */
	public function testJsonSerialize_ReturnsCorrectArray(): void {
		$completedTask = new CompletedTask();
		$completedTask->setId(1);
		$completedTask->setUserId(2);
		$completedTask->setRoomTaskId(3);
		$completedTask->setTotalEnergy(4);
		$completedTask->setCompletionVersion(2);
		$completedTask->setAllocationRevision(3);
		$completedTask->setScheduleRevId(7);
		$completedTask->setOccurrenceOrdinal(11);
		$completedTask->setWorkDate(new \DateTime('2026-09-21 00:00:00', new \DateTimeZone('UTC')));
		$completedTask->setCompletionClass(CompletedTask::CLASS_AGREED);
		$completedAt = new \DateTime('2026-09-21 15:30:00');
		$completedTask->setCompletedAt($completedAt);

		$result = $completedTask->jsonSerialize();

		self::assertEquals(1, $result['id']);
		self::assertEquals(2, $result['userId']);
		self::assertEquals(3, $result['roomTaskId']);
		self::assertEquals(4, $result['totalEnergy']);
		self::assertEquals(2, $result['completionVersion']);
		self::assertEquals(3, $result['allocationRevision']);
		self::assertEquals('7:11', $result['occurrenceId']);
		self::assertEquals('2026-09-21', $result['workDate']);
		self::assertEquals(CompletedTask::CLASS_AGREED, $result['completionClass']);
		self::assertNull($result['undoneAt']);
		self::assertEquals($completedAt->format('c'), $result['completedAt']);
		// WP3 cutover: no monetary/combo outputs on the work evidence.
		self::assertArrayNotHasKey('coinsEarned', $result);
		self::assertArrayNotHasKey('wasCombo', $result);
	}

	/**
	 * SetAndGetProperties_WorksCorrectly
	 */
	public function testSetAndGetProperties_WorksCorrectly(): void {
		$completedTask = new CompletedTask();
		$completedTask->setUserId(5);
		$completedTask->setRoomTaskId(10);
		$completedTask->setTotalEnergy(3);
		$completedTask->setRateMinor(1000);
		$completedTask->setCommandUuid('cmd-1');
		$completedAt = new \DateTime('2026-09-21 10:00:00');
		$completedTask->setCompletedAt($completedAt);

		self::assertEquals(5, $completedTask->getUserId());
		self::assertEquals(10, $completedTask->getRoomTaskId());
		self::assertEquals(3, $completedTask->getTotalEnergy());
		self::assertEquals(1000, $completedTask->getRateMinor());
		self::assertSame('cmd-1', $completedTask->getCommandUuid());
		self::assertSame($completedAt, $completedTask->getCompletedAt());
		self::assertSame('date', $completedTask->getFieldTypes()['occurrenceDue'], 'the occurrence due value is a saved local date, not an instant');
	}

	/**
	 * FreshEntity_HasNoWriterValueDefaults
	 *
	 * WP3: the frozen-fact columns carry NO property defaults — the OCP
	 * Entity setter would silently drop any value equal to a default
	 * from the INSERT (the 1-energy/1000-rate/0-version/1-revision
	 * completions are the common case, not edge cases). The schema holds
	 * the safety-net defaults; the writer sets every field explicitly.
	 */
	public function testDefaultValues_AreCorrect(): void {
		$completedTask = new CompletedTask();

		self::assertNull($completedTask->getTotalEnergy());
		self::assertNull($completedTask->getRateMinor());
		self::assertNull($completedTask->getCompletionVersion());
		self::assertNull($completedTask->getAllocationRevision());
		self::assertNull($completedTask->getUndoneAt());
		self::assertNull($completedTask->getCommandUuid());
	}
}
