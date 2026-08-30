<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\Floor;
use OCA\ChoreStrike\Db\FloorMapper;
use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\Room;
use OCA\ChoreStrike\Db\RoomMapper;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Service\RewardConfigService;
use OCA\ChoreStrike\Service\TestDataService;
use OCP\IConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for TestDataService
 */
class TestDataServiceTest extends TestCase {
	private IDBConnection&MockObject $db;
	private IConfig&MockObject $config;
	private HouseMapper&MockObject $houseMapper;
	private HouseUserMapper&MockObject $houseUserMapper;
	private FloorMapper&MockObject $floorMapper;
	private RoomMapper&MockObject $roomMapper;
	private TaskMapper&MockObject $taskMapper;
	private RoomTaskMapper&MockObject $roomTaskMapper;
	private TestDataService $service;

	protected function setUp(): void {
		parent::setUp();

		// CR-SVC-02: destructive methods self-gate on isTestMode(). Default the
		// suite to test mode so the destructive-path tests pass the gate; tests
		// that assert non-test-mode behaviour (or the gate itself) override it.
		putenv('NEXTCLOUD_TEST_MODE=true');

		$this->db = $this->createMock(IDBConnection::class);
		$this->config = $this->createMock(IConfig::class);
		$this->houseMapper = $this->createMock(HouseMapper::class);
		$this->houseUserMapper = $this->createMock(HouseUserMapper::class);
		$this->floorMapper = $this->createMock(FloorMapper::class);
		$this->roomMapper = $this->createMock(RoomMapper::class);
		$this->taskMapper = $this->createMock(TaskMapper::class);
		$this->roomTaskMapper = $this->createMock(RoomTaskMapper::class);

		// Default config mock returns test instance id
		$this->config->method('getSystemValue')->willReturn('test-instance');

		$this->service = new TestDataService(
			$this->db,
			$this->config,
			$this->houseMapper,
			$this->houseUserMapper,
			$this->floorMapper,
			$this->roomMapper,
			$this->taskMapper,
			$this->roomTaskMapper
		);
	}

	protected function tearDown(): void {
		// Restore a clean process environment between env-mutating tests
		putenv('CI');
		putenv('NEXTCLOUD_TEST_MODE');
		unset($_ENV['NEXTCLOUD_TEST_MODE']);

		parent::tearDown();
	}

	/**
	 * Test seedEmpty resets database and returns empty profile
	 */
	public function testSeedEmptyReturnsCorrectProfile(): void {
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
	public function testSeedMinimalCreatesCorrectData(): void {
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
	public function testSeedFamilyCreatesCompleteStructure(): void {
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

		// CS-PLAN-055: the family profile seeds the weekly-goal test knob so
		// `release-notifications` stays deterministic without the pruned
		// reward-config HTTP surface.
		$this->config->expects($this->once())
			->method('setAppValue')
			->with('chorestrike', RewardConfigService::KEY_WEEKLY_GOAL_TARGET_PERCENT, '1');

		$result = $this->service->seedFamily();

		$this->assertEquals('family', $result['profile']);
		$this->assertCount(3, $result['data']['floors']);
		$this->assertCount(6, $result['data']['rooms']);
		$this->assertCount(6, $result['data']['users']);
		$this->assertCount(12, $result['data']['tasks']);
		$this->assertCount(12, $result['data']['roomTasks']);
	}

	/**
	 * Test seedFamily includes the time-travel seeds (CS-PLAN-023):
	 * at least one room task with a past due-date (overdue flow) and mom
	 * with a live streak but zero freezes (streak/at-risk flow).
	 */
	public function testSeedFamilyIncludesTimeTravelSeeds(): void {
		// Mock reset
		$qb = $this->createConfiguredMock(\OCP\DB\QueryBuilder\IQueryBuilder::class, [
			'delete' => $this->createConfiguredMock(\OCP\DB\QueryBuilder\IQueryBuilder::class, [
				'executeStatement' => 0
			])
		]);
		$this->db->method('getQueryBuilder')->willReturn($qb);

		$house = new House();
		$house->setId(1);
		$this->houseMapper->method('insert')->willReturn($house);

		$floor = new Floor();
		$floor->setId(1);
		$this->floorMapper->method('create')->willReturn($floor);

		$room = new Room();
		$room->setId(1);
		$this->roomMapper->method('insert')->willReturn($room);

		$task = new Task();
		$task->setId(1);
		$this->taskMapper->method('create')->willReturn($task);

		// Capture the seeded house users and room-task due-dates.
		$seededUsers = [];
		$this->houseUserMapper->method('insert')
			->willReturnCallback(function (HouseUser $user) use (&$seededUsers) {
				if ($user->getId() === null) {
					$user->setId(count($seededUsers) + 1);
				}
				$seededUsers[$user->getNextcloudUserId()] = $user;
				return $user;
			});

		$dueDates = [];
		$roomTask = new RoomTask();
		$roomTask->setId(1);
		$this->roomTaskMapper->method('create')
			->willReturnCallback(function ($roomId, $taskId, \DateTime $nextDue) use (&$dueDates, $roomTask) {
				$dueDates[] = $nextDue;
				return $roomTask;
			});

		$this->service->seedFamily();

		// Overdue seed: at least one due-date must already be in the past.
		$pastDueDates = array_filter($dueDates, fn (\DateTime $due) => $due < new \DateTime());
		$this->assertNotEmpty(
			$pastDueDates,
			'family seed must include at least one room task with a past due-date'
		);

		// Streak seed: mom has something to lose and no freeze to spend.
		$this->assertArrayHasKey('mom', $seededUsers);
		$this->assertSame(5, $seededUsers['mom']->getCurrentStreak());
		$this->assertSame(0, $seededUsers['mom']->getStreakFreezes());
	}

	/**
	 * Test seed method with invalid profile throws exception
	 */
	public function testSeedWithInvalidProfileThrowsException(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Unknown profile: invalid');

		$this->service->seed('invalid');
	}

	/**
	 * Test seed method routes to correct profile method
	 */
	public function testSeedRoutesToCorrectMethod(): void {
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
	 * Test isTestMode returns false when only CI env var is set (CS-PLAN-019)
	 */
	public function testIsTestModeIgnoresCIEnvVar(): void {
		putenv('CI=true');
		putenv('NEXTCLOUD_TEST_MODE');
		unset($_ENV['NEXTCLOUD_TEST_MODE']);

		$this->assertFalse($this->service->isTestMode());
	}

	/**
	 * Test isTestMode detects NEXTCLOUD_TEST_MODE
	 */
	public function testIsTestModeDetectsTestMode(): void {
		putenv('NEXTCLOUD_TEST_MODE=true');

		$this->assertTrue($this->service->isTestMode());
	}

	/**
	 * Test isTestMode detects NEXTCLOUD_TEST_MODE via $_ENV (CS-PLAN-019)
	 */
	public function testIsTestModeDetectsTestModeViaEnvSuperglobal(): void {
		putenv('NEXTCLOUD_TEST_MODE');
		$_ENV['NEXTCLOUD_TEST_MODE'] = 'true';

		$this->assertTrue($this->service->isTestMode());
	}

	/**
	 * Test isTestMode ignores a non-true $_ENV value (CS-PLAN-019)
	 */
	public function testIsTestModeIgnoresNonTrueEnvSuperglobalValue(): void {
		putenv('NEXTCLOUD_TEST_MODE');
		$_ENV['NEXTCLOUD_TEST_MODE'] = 'false';

		$this->assertFalse($this->service->isTestMode());
	}

	/**
	 * Test isTestMode returns false in production
	 */
	public function testIsTestModeReturnsFalseInProduction(): void {
		// Defensive: ensure no test-mode indicators even if the process env is polluted
		putenv('CI');
		putenv('NEXTCLOUD_TEST_MODE');
		unset($_ENV['NEXTCLOUD_TEST_MODE']);

		$this->assertFalse($this->service->isTestMode());
	}

	/**
	 * Test reset clears exactly the canonical tables (CR-SVC-42)
	 */
	public function testResetShouldClearCanonicalTablesOnly(): void {
		$resetTables = (new \ReflectionClass(TestDataService::class))->getConstant('RESET_TABLES');
		$this->assertIsArray($resetTables);

		$deletedTables = [];
		$qb = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
		$qb->expects($this->exactly(count($resetTables)))
			->method('delete')
			->willReturnCallback(function (string $table) use (&$deletedTables, &$qb) {
				$deletedTables[] = $table;
				return $qb;
			});

		$qb->expects($this->exactly(count($resetTables)))
			->method('executeStatement')
			->willReturn(0);

		$this->db->expects($this->exactly(count($resetTables)))
			->method('getQueryBuilder')
			->willReturn($qb);

		$this->service->reset();

		$this->assertContains('chorestrike_task_assignees', $deletedTables);
		// Checklist tables under their migration-chain names (Version1005;
		// re-verified against the real schema by CS-PLAN-037).
		$this->assertContains('chorestrike_chk_progress', $deletedTables);
		$this->assertContains('chorestrike_chk_items', $deletedTables);

		// Names that never existed in the schema are gone.
		$this->assertNotContains('chorestrike_room_task_checklist_progress', $deletedTables);
		$this->assertNotContains('chorestrike_checklist_items', $deletedTables);
		$this->assertNotContains('chorestrike_task_parts', $deletedTables);
		$this->assertNotContains('chorestrike_task_participants', $deletedTables);
		$this->assertNotContains('chorestrike_task_claims', $deletedTables);
		$this->assertNotContains('chorestrike_claims', $deletedTables);
	}

	/**
	 * Test reset ignores missing table errors from mixed migration states
	 */
	public function testResetShouldIgnoreMissingTableErrors(): void {
		$resetTables = (new \ReflectionClass(TestDataService::class))->getConstant('RESET_TABLES');
		$this->assertIsArray($resetTables);

		$qb = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
		$qb->expects($this->exactly(count($resetTables)))
			->method('delete')
			->willReturnSelf();

		$qb->expects($this->exactly(count($resetTables)))
			->method('executeStatement')
			->willThrowException(new \RuntimeException('no such table: chorestrike_task_scopes'));

		$this->db->expects($this->exactly(count($resetTables)))
			->method('getQueryBuilder')
			->willReturn($qb);

		$this->service->reset();
		$this->assertTrue(true);
	}

	/**
	 * Test reset rethrows non-missing-table errors
	 */
	public function testResetShouldRethrowUnexpectedErrors(): void {
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('syntax error');

		$qb = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
		$qb->method('delete')->willReturnSelf();
		$qb->method('executeStatement')->willThrowException(new \RuntimeException('syntax error'));
		$this->db->method('getQueryBuilder')->willReturn($qb);

		$this->service->reset();
	}

	/**
	 * CR-SVC-02: reset() must refuse to run outside test mode (prod-DB wipe guard).
	 */
	public function testResetThrowsWhenNotInTestMode(): void {
		putenv('NEXTCLOUD_TEST_MODE');
		unset($_ENV['NEXTCLOUD_TEST_MODE']);

		$this->db->expects($this->never())->method('getQueryBuilder');

		$this->expectException(\RuntimeException::class);
		$this->service->reset();
	}

	/**
	 * CR-SVC-02: seedEmpty() must refuse to run outside test mode.
	 */
	public function testSeedEmptyThrowsWhenNotInTestMode(): void {
		putenv('NEXTCLOUD_TEST_MODE');
		unset($_ENV['NEXTCLOUD_TEST_MODE']);

		$this->db->expects($this->never())->method('getQueryBuilder');

		$this->expectException(\RuntimeException::class);
		$this->service->seedEmpty();
	}

	/**
	 * CR-SVC-02: seedMinimal() must refuse to run outside test mode.
	 */
	public function testSeedMinimalThrowsWhenNotInTestMode(): void {
		putenv('NEXTCLOUD_TEST_MODE');
		unset($_ENV['NEXTCLOUD_TEST_MODE']);

		$this->houseMapper->expects($this->never())->method('insert');

		$this->expectException(\RuntimeException::class);
		$this->service->seedMinimal();
	}

	/**
	 * CR-SVC-02: seedFamily() must refuse to run outside test mode.
	 */
	public function testSeedFamilyThrowsWhenNotInTestMode(): void {
		putenv('NEXTCLOUD_TEST_MODE');
		unset($_ENV['NEXTCLOUD_TEST_MODE']);

		$this->houseMapper->expects($this->never())->method('insert');

		$this->expectException(\RuntimeException::class);
		$this->service->seedFamily();
	}

	/**
	 * CR-SVC-02: seed() must refuse to run outside test mode, before profile routing.
	 */
	public function testSeedThrowsWhenNotInTestMode(): void {
		putenv('NEXTCLOUD_TEST_MODE');
		unset($_ENV['NEXTCLOUD_TEST_MODE']);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('test mode');

		$this->service->seed('empty');
	}
}
