<?php

declare(strict_types=1);

namespace OCA\ChoreWars\Tests\Unit\Db;

use OCA\ChoreWars\Db\Floor;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Floor entity
 */
class FloorTest extends TestCase {

	/**
	 * JsonSerialize_ReturnsCorrectArray
	 */
	public function testJsonSerialize_ReturnsCorrectArray(): void {
		$floor = new Floor();
		$floor->setId(1);
		$floor->setHouseId(2);
		$floor->setName('Ground Floor');
		$floor->setSortOrder(1);
		$createdAt = new \DateTime('2026-02-01 10:00:00');
		$floor->setCreatedAt($createdAt);

		$result = $floor->jsonSerialize();

		self::assertEquals(1, $result['id']);
		self::assertEquals(2, $result['houseId']);
		self::assertEquals('Ground Floor', $result['name']);
		self::assertEquals(1, $result['sortOrder']);
		self::assertEquals($createdAt->format('c'), $result['createdAt']);
	}

	/**
	 * SetAndGetProperties_WorksCorrectly
	 */
	public function testSetAndGetProperties_WorksCorrectly(): void {
		$floor = new Floor();
		$floor->setHouseId(5);
		$floor->setName('First Floor');
		$floor->setSortOrder(2);
		$createdAt = new \DateTime('2026-02-24 12:00:00');
		$floor->setCreatedAt($createdAt);

		self::assertEquals(5, $floor->getHouseId());
		self::assertEquals('First Floor', $floor->getName());
		self::assertEquals(2, $floor->getSortOrder());
		self::assertSame($createdAt, $floor->getCreatedAt());
	}
}
