<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Db\Entity;

use OCA\ChoreStrike\Db\RoomTaskChecklistProgress;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for RoomTaskChecklistProgress entity logic
 */
class RoomTaskChecklistProgressTest extends TestCase {
	public function testJsonSerialize_IncludesAllFields(): void {
		$progress = new RoomTaskChecklistProgress();
		$progress->setId(1);
		$progress->setRoomTaskId(10);
		$progress->setChecklistItemId(5);
		$progress->setIsChecked(true);
		$progress->setCheckedAt(new \DateTime('2026-01-15'));
		$progress->setCheckedByUserId(3);

		$json = $progress->jsonSerialize();

		$this->assertEquals(1, $json['id']);
		$this->assertEquals(10, $json['roomTaskId']);
		$this->assertEquals(5, $json['checklistItemId']);
		$this->assertTrue($json['isChecked']);
		$this->assertEquals(3, $json['checkedByUserId']);
	}
}
