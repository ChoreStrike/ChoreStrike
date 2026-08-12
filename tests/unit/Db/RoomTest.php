<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Db;

use OCA\ChoreStrike\Db\Room;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Room entity
 */
class RoomTest extends TestCase {
	/**
	 * JsonSerialize_ReturnsCorrectArray
	 */
	public function testJsonSerialize_ReturnsCorrectArray(): void {
		$room = new Room();
		$room->setId(1);
		$room->setFloorId(2);
		$room->setName('Kitchen');
		$room->setCleanliness(75);
		$room->setMonsterCount(2);
		$room->setDescription('Main kitchen area');
		$createdAt = new \DateTime('2026-02-01 10:00:00');
		$room->setCreatedAt($createdAt);
		$lastCleaned = new \DateTime('2026-02-23 15:30:00');
		$room->setLastCleaned($lastCleaned);

		$result = $room->jsonSerialize();

		self::assertEquals(1, $result['id']);
		self::assertEquals(2, $result['floorId']);
		self::assertEquals('Kitchen', $result['name']);
		self::assertEquals(75, $result['cleanliness']);
		self::assertEquals(2, $result['monsterCount']);
		self::assertEquals('Main kitchen area', $result['description']);
		self::assertEquals($createdAt->format('c'), $result['createdAt']);
		self::assertEquals($lastCleaned->format('c'), $result['lastCleaned']);
	}

	/**
	 * GetCleanlinessLabel_WithHighCleanliness_ReturnsClean
	 */
	public function testGetCleanlinessLabel_WithHighCleanliness_ReturnsClean(): void {
		$room = new Room();
		$room->setCleanliness(80);

		self::assertEquals('clean', $room->getCleanlinessLabel());
	}

	/**
	 * GetCleanlinessLabel_WithMediumCleanliness_ReturnsModerate
	 */
	public function testGetCleanlinessLabel_WithMediumCleanliness_ReturnsModerate(): void {
		$room = new Room();
		$room->setCleanliness(50);

		self::assertEquals('moderate', $room->getCleanlinessLabel());
	}

	/**
	 * GetCleanlinessLabel_WithLowCleanliness_ReturnsDirty
	 */
	public function testGetCleanlinessLabel_WithLowCleanliness_ReturnsDirty(): void {
		$room = new Room();
		$room->setCleanliness(30);

		self::assertEquals('dirty', $room->getCleanlinessLabel());
	}

	/**
	 * GetCleanlinessLabel_WithVeryLowCleanliness_ReturnsFilthy
	 */
	public function testGetCleanlinessLabel_WithVeryLowCleanliness_ReturnsFilthy(): void {
		$room = new Room();
		$room->setCleanliness(10);

		self::assertEquals('filthy', $room->getCleanlinessLabel());
	}

	/**
	 * NeedsCleaning_WithHighCleanliness_ReturnsFalse
	 */
	public function testNeedsCleaning_WithHighCleanliness_ReturnsFalse(): void {
		$room = new Room();
		$room->setCleanliness(80);

		self::assertFalse($room->needsCleaning());
	}

	/**
	 * NeedsCleaning_WithLowCleanliness_ReturnsTrue
	 */
	public function testNeedsCleaning_WithLowCleanliness_ReturnsTrue(): void {
		$room = new Room();
		$room->setCleanliness(40);

		self::assertTrue($room->needsCleaning());
	}

	/**
	 * UpdateCleanliness_UpdatesCleanlinessAndLastCleaned
	 */
	public function testUpdateCleanliness_UpdatesCleanlinessAndLastCleaned(): void {
		$room = new Room();
		$room->setCleanliness(40);
		$room->setLastCleaned(new \DateTime('2026-02-01'));

		$beforeUpdate = new \DateTime();
		$room->updateCleanliness(50);
		$afterUpdate = new \DateTime();

		self::assertEquals(90, $room->getCleanliness());
		$lastCleaned = $room->getLastCleaned();
		self::assertGreaterThanOrEqual($beforeUpdate->getTimestamp(), $lastCleaned->getTimestamp());
		self::assertLessThanOrEqual($afterUpdate->getTimestamp(), $lastCleaned->getTimestamp());
	}
}
