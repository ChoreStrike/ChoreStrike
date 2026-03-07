<?php
/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\Floor;
use OCA\ChoreStrike\Db\FloorMapper;
use OCA\ChoreStrike\Db\Room;
use OCA\ChoreStrike\Db\RoomMapper;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\AvailabilitySettingsMapper;
use OCA\ChoreStrike\Service\TestDataService;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for TestDataService
 */
class TestDataServiceTest extends TestCase
{
	private IDBConnection&MockObject $db;
	private HouseMapper&MockObject $houseMapper;
	private HouseUserMapper&MockObject $houseUserMapper;
	private FloorMapper&MockObject $floorMapper;
	private RoomMapper&MockObject $roomMapper;
	private TaskMapper&MockObject $taskMapper;
	private RoomTaskMapper&MockObject $roomTaskMapper;
	private AvailabilitySettingsMapper&MockObject $availabilityMapper;
	private TestDataService $service;

	protected function setUp(): void
	{
		parent::setUp();

		$this->db = $this->createMock(IDBConnection::class);
		$this->houseMapper = $this->createMock(HouseMapper::class);
		$this->houseUserMapper = $this->createMock(HouseUserMapper::class);
		$this->floorMapper = $this->createMock(FloorMapper::class);
		$this->roomMapper = $this->createMock(RoomMapper::class);
		$this->taskMapper = $this->createMock(TaskMapper::class);
		$this->roomTaskMapper = $this->createMock(RoomTaskMapper::class);
		$this->availabilityMapper = $this->createMock(AvailabilitySettingsMapper::class);

		$this->service = new TestDataService(
			$this->db,
			$this->houseMapper,
			$this->houseUserMapper,
			$this->floorMapper,
			$this->roomMapper,
			$this->taskMapper,
			$this->roomTaskMapper,
			$this->availabilityMapper
		);
	}

	/**
	 * Test seedEmpty resets database and returns empty profile
	 */
	public function testSeedEmptyReturnsCorrectProfile(): void
	{
		// Mock the delete query builder chain
		$qb = $this->createConfiguredMock(\OCP\DB\QueryBuilder\IQueryBuilder::class, [
			'delete' => $this->createConfiguredMock(\OCP\DB\QueryBuilder\IQueryBuilder::class, [
				'executeStatement' => 0
			])
		]);
		$this->db->method('getQueryBuilder')->willReturn($qb);

		$result = $this->service->seedEmpty();

		$this->assertEquals('empty', $result['profile']);
		$this->assertEmpty($result['data']);
	}

	/**
	 * Test seedMinimal creates house, floor, room, and 2 users
	 */
	public function testSeedMinimalCreatesCorrectData(): void
	{
		// Mock reset (delete queries)
		$qb = $this->createConfiguredMock(\OCP\DB\QueryBuilder\IQueryBuilder::class, [
			'delete' => $this->createConfiguredMock(\OCP\DB\QueryBuilder\IQueryBuilder::class, [
				'executeStatement' => 0
			])
		]);
		$this->db->method('getQueryBuilder')->willReturn($qb);

		// Mock house creation
		$house = new House();
		$house->setId(1);
		$house->setName('Test House');
		$this->houseMapper->method('insert')->willReturn($house);

		// Mock floor creation
		$floor = new Floor();
		$floor->setId(1);
		$floor->setName('Ground Floor');
		$this->floorMapper->method('create')->willReturn($floor);

		// Mock room creation
		$room = new Room();
		$room->setId(1);
		$room->setName('Kitchen');
		$this->roomMapper->method('insert')->willReturn($room);

		// Mock user creation
		$houseUser = new HouseUser();
		$houseUser->setId(1);
		$this->houseUserMapper->method('insert')->willReturn($houseUser);

		$result = $this->service->seedMinimal();

		$this->assertEquals('minimal', $result['profile']);
		$this->assertArrayHasKey('house', $result['data']);
		$this->assertArrayHasKey('floors', $result['data']);
		$this->assertArrayHasKey('rooms', $result['data']);
		$this->assertArrayHasKey('users', $result['data']);
	}

	/**
	 * Test seedFamily creates complete family structure
	 */
	public function testSeedFamilyCreatesCompleteStructure(): void
	{
		// Mock reset
		$qb = $this->createConfiguredMock(\OCP\DB\QueryBuilder\IQueryBuilder::class, [
			'delete' => $this->createConfiguredMock(\OCP\DB\QueryBuilder\IQueryBuilder::class, [
				'executeStatement' => 0
			])
		]);
		$this->db->method('getQueryBuilder')->willReturn($qb);

		// Mock house
		$house = new House();
		$house->setId(1);
		$this->houseMapper->method('insert')->willReturn($house);

		// Mock floors (3)
		$floor = new Floor();
		$floor->setId(1);
		$this->floorMapper->expects($this->exactly(3))
			->method('create')
			->willReturn($floor);

		// Mock rooms (6)
		$room = new Room();
		$room->setId(1);
		$this->roomMapper->expects($this->exactly(6))
			->method('insert')
			->willReturn($room);

		// Mock users (6)
		$houseUser = new HouseUser();
		$houseUser->setId(1);
		$this->houseUserMapper->expects($this->exactly(6))
			->method('insert')
			->willReturn($houseUser);

		// Mock tasks (12)
		$task = new Task();
		$task->setId(1);
		$this->taskMapper->expects($this->exactly(12))
			->method('create')
			->willReturn($task);

		// Mock room tasks (12)
		$roomTask = new RoomTask();
		$roomTask->setId(1);
		$this->roomTaskMapper->expects($this->exactly(12))
			->method('create')
			->willReturn($roomTask);

		$result = $this->service->seedFamily();

		$this->assertEquals('family', $result['profile']);
		$this->assertCount(3, $result['data']['floors']);
		$this->assertCount(6, $result['data']['rooms']);
		$this->assertCount(6, $result['data']['users']);
		$this->assertCount(12, $result['data']['tasks']);
		$this->assertCount(12, $result['data']['roomTasks']);
	}

	/**
	 * Test seed method with invalid profile throws exception
	 */
	public function testSeedWithInvalidProfileThrowsException(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Unknown profile: invalid');

		$this->service->seed('invalid');
	}

	/**
	 * Test seed method routes to correct profile method
	 */
	public function testSeedRoutesToCorrectMethod(): void
	{
		// Mock reset for empty
		$qb = $this->createConfiguredMock(\OCP\DB\QueryBuilder\IQueryBuilder::class, [
			'delete' => $this->createConfiguredMock(\OCP\DB\QueryBuilder\IQueryBuilder::class, [
				'executeStatement' => 0
			])
		]);
		$this->db->method('getQueryBuilder')->willReturn($qb);

		$result = $this->service->seed('empty');
		$this->assertEquals('empty', $result['profile']);
	}

	/**
	 * Test isTestMode detects CI environment
	 */
	public function testIsTestModeDetectsCI(): void
	{
		// Set CI environment variable
		putenv('CI=true');

		$this->assertTrue($this->service->isTestMode());

		// Cleanup
		putenv('CI');
	}

	/**
	 * Test isTestMode detects NEXTCLOUD_TEST_MODE
	 */
	public function testIsTestModeDetectsTestMode(): void
	{
		putenv('NEXTCLOUD_TEST_MODE=true');

		$this->assertTrue($this->service->isTestMode());

		// Cleanup
		putenv('NEXTCLOUD_TEST_MODE');
	}

	/**
	 * Test isTestMode returns false in production
	 */
	public function testIsTestModeReturnsFalseInProduction(): void
	{
		// Ensure no test mode indicators
		putenv('CI');
		putenv('NEXTCLOUD_TEST_MODE');
		unset($_ENV['NEXTCLOUD_TEST_MODE']);

		$this->assertFalse($this->service->isTestMode());
	}

	/**
	 * Test reset clears all tables
	 */
	public function testResetClearsAllTables(): void
	{
		$qb = $this->createConfiguredMock(\OCP\DB\QueryBuilder\IQueryBuilder::class, [
			'delete' => $this->createConfiguredMock(\OCP\DB\QueryBuilder\IQueryBuilder::class, [
				'executeStatement' => 0
			])
		]);
		$this->db->method('getQueryBuilder')->willReturn($qb);

		$this->service->reset();

		// If no exception thrown, test passes
		$this->assertTrue(true);
	}
}
