<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Db;

use OCA\ChoreStrike\Db\TaskScope;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for TaskScope entity
 */
class TaskScopeTest extends TestCase {

	/**
	 * JsonSerialize_ReturnsCorrectArray
	 */
	public function testJsonSerialize_ReturnsCorrectArray(): void {
		$scope = new TaskScope();
		$scope->setId(1);
		$scope->setHouseId(2);
		$scope->setType('garden');
		$scope->setName('Backyard Garden');
		$scope->setRoomId(null);
		$createdAt = new \DateTime('2026-02-01 10:00:00');
		$scope->setCreatedAt($createdAt);

		$result = $scope->jsonSerialize();

		self::assertEquals(1, $result['id']);
		self::assertEquals(2, $result['houseId']);
		self::assertEquals('garden', $result['type']);
		self::assertEquals('Backyard Garden', $result['name']);
		self::assertNull($result['roomId']);
		self::assertEquals($createdAt->format('c'), $result['createdAt']);
	}

	/**
	 * JsonSerialize_WithRoomId_ReturnsCorrectArray
	 */
	public function testJsonSerialize_WithRoomId_ReturnsCorrectArray(): void {
		$scope = new TaskScope();
		$scope->setId(2);
		$scope->setHouseId(3);
		$scope->setType('room');
		$scope->setName('Kitchen Scope');
		$scope->setRoomId(5);
		$createdAt = new \DateTime('2026-02-24 12:00:00');
		$scope->setCreatedAt($createdAt);

		$result = $scope->jsonSerialize();

		self::assertEquals(2, $result['id']);
		self::assertEquals(5, $result['roomId']);
	}

	/**
	 * SetAndGetProperties_WorksCorrectly
	 */
	public function testSetAndGetProperties_WorksCorrectly(): void {
		$scope = new TaskScope();
		$scope->setHouseId(10);
		$scope->setType('home');
		$scope->setName('Whole House');
		$scope->setRoomId(15);
		$createdAt = new \DateTime('2026-02-24 15:00:00');
		$scope->setCreatedAt($createdAt);

		self::assertEquals(10, $scope->getHouseId());
		self::assertEquals('home', $scope->getType());
		self::assertEquals('Whole House', $scope->getName());
		self::assertEquals(15, $scope->getRoomId());
		self::assertSame($createdAt, $scope->getCreatedAt());
	}
}
