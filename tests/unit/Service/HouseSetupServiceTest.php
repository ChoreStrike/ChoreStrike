<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\Floor;
use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\Room;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Exception\ConflictException;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\HouseSetupService;
use OCA\ChoreStrike\Service\TemplateService;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for HouseSetupService (wizard apply).
 */
class HouseSetupServiceTest extends TestCase {
	private HouseSetupService $service;
	private AuthorizationService $authorization;
	private $floorMapper;
	private $houseMapper;
	private $roomMapper;
	private $taskMapper;
	private $roomTaskMapper;
	private $taskAssigneeMapper;
	private $houseUserMapper;
	private TemplateService $templateService;
	private $db;
	private int $nextId = 1000;

	protected function setUp(): void {
		parent::setUp();

		$this->floorMapper = $this->createMock(\OCA\ChoreStrike\Db\FloorMapper::class);
		$this->houseMapper = $this->createMock(\OCA\ChoreStrike\Db\HouseMapper::class);
		$this->roomMapper = $this->createMock(\OCA\ChoreStrike\Db\RoomMapper::class);
		$this->taskMapper = $this->createMock(\OCA\ChoreStrike\Db\TaskMapper::class);
		$this->roomTaskMapper = $this->createMock(\OCA\ChoreStrike\Db\RoomTaskMapper::class);
		$this->taskAssigneeMapper = $this->createMock(\OCA\ChoreStrike\Db\TaskAssigneeMapper::class);
		$this->houseUserMapper = $this->createMock(\OCA\ChoreStrike\Db\HouseUserMapper::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->authorization = $this->createMock(AuthorizationService::class);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(fn ($t) => $t);
		$this->templateService = new TemplateService($l10n);
		$this->injectTemplateFixtures();

		$this->service = new HouseSetupService(
			$this->floorMapper,
			$this->houseMapper,
			$this->roomMapper,
			$this->taskMapper,
			$this->roomTaskMapper,
			$this->taskAssigneeMapper,
			$this->houseUserMapper,
			$this->templateService,
			new \OCA\ChoreStrike\Db\TransactionExecutor($this->db),
			$l10n,
			$this->createMock(AppLogger::class),
			$this->authorization
		);
	}

	private function injectTemplateFixtures(): void {
		$templates = [
			[
				'id' => 'kitchen_counters',
				'name' => 'Wipe kitchen counters',
				'description' => 'desc',
				'instructions' => 'ins',
				'category' => 'cleaning',
				'energyCost' => 1,
				'priority' => 3,
				'roomTypes' => ['kitchen'],
				'levels' => [
					'putzteufel' => ['frequencyType' => 'daily', 'frequencyValue' => 1, 'estimatedMinutes' => 10],
					'putzmuffel' => null,
				],
				'tags' => ['daily'],
			],
			[
				'id' => 'kitchen_floor',
				'name' => 'Mop kitchen floor',
				'description' => 'desc',
				'instructions' => 'ins',
				'category' => 'cleaning',
				'energyCost' => 3,
				'priority' => 2,
				'roomTypes' => ['kitchen'],
				'levels' => [
					'putzteufel' => ['frequencyType' => 'weekly', 'frequencyValue' => 1, 'estimatedMinutes' => 25],
					'putzmuffel' => ['frequencyType' => 'weekly', 'frequencyValue' => 2, 'estimatedMinutes' => 20],
				],
				'tags' => ['weekly'],
			],
			[
				'id' => 'household_trash',
				'name' => 'Take out all trash',
				'description' => 'desc',
				'instructions' => 'ins',
				'category' => 'cleaning',
				'energyCost' => 2,
				'priority' => 3,
				'roomTypes' => ['household'],
				'levels' => [
					'putzteufel' => ['frequencyType' => 'weekly', 'frequencyValue' => 1, 'estimatedMinutes' => 15],
					'putzmuffel' => ['frequencyType' => 'weekly', 'frequencyValue' => 1, 'estimatedMinutes' => 15],
				],
				'tags' => ['weekly'],
			],
			[
				'id' => 'hallway_vacuum',
				'name' => 'Vacuum hallway',
				'description' => 'desc',
				'instructions' => 'ins',
				'category' => 'cleaning',
				'energyCost' => 3,
				'priority' => 2,
				'roomTypes' => ['hallway'],
				'levels' => [
					'putzteufel' => ['frequencyType' => 'weekly', 'frequencyValue' => 1, 'estimatedMinutes' => 20],
					'putzmuffel' => ['frequencyType' => 'weekly', 'frequencyValue' => 2, 'estimatedMinutes' => 15],
				],
				'tags' => ['weekly'],
			],
		];

		$roomTypes = [
			['id' => 'kitchen', 'defaultName' => 'Kitchen', 'icon' => 'Utensils', 'templateIds' => ['kitchen_counters', 'kitchen_floor']],
			['id' => 'household', 'defaultName' => 'Household & Co.', 'icon' => 'WashingMachine', 'templateIds' => ['household_trash']],
			['id' => 'hallway', 'defaultName' => 'Hallway & Stairs', 'icon' => 'DoorOpen', 'templateIds' => ['hallway_vacuum']],
		];

		foreach (['templatesCache' => $templates, 'roomTypesCache' => $roomTypes] as $name => $value) {
			$ref = new \ReflectionProperty(TemplateService::class, $name);
			$ref->setAccessible(true);
			$ref->setValue($this->templateService, $value);
		}
	}

	private function stubPersistence(): void {
		$idRef = new \ReflectionMethod($this, 'freshId');
		$this->floorMapper->method('create')->willReturnCallback(function () {
			$floor = new Floor();
			$this->setEntityId($floor, $this->freshId());
			$floor->setName('Ground Floor');
			return $floor;
		});
		$this->roomMapper->method('create')->willReturnCallback(function () {
			$room = new Room();
			$this->setEntityId($room, $this->freshId());
			return $room;
		});
		$this->taskMapper->method('create')->willReturnCallback(function () {
			$task = new Task();
			$this->setEntityId($task, $this->freshId());
			return $task;
		});
		$this->roomTaskMapper->method('create')->willReturnCallback(function () {
			return $this->createMock(RoomTask::class);
		});
		// Fresh house: the transactional first-run gate passes and the house
		// row is lockable (CS-PLAN-062 §3.1).
		$this->floorMapper->method('countByHouseId')->willReturn(0);
		$this->houseMapper->method('findForUpdate')->willReturnCallback(function () {
			$house = new House();
			$this->setEntityId($house, 1);
			$house->setName('My House');
			return $house;
		});
		$this->houseMapper->method('update')->willReturnArgument(0);
	}

	private function freshId(): int {
		return $this->nextId++;
	}

	private function setEntityId($entity, int $id): void {
		$ref = new \ReflectionProperty($entity, 'id');
		$ref->setAccessible(true);
		$ref->setValue($entity, $id);
	}

	private function basePayload(): array {
		return [
			'intensityLevel' => TemplateService::LEVEL_PUTZTEUFEL,
			'options' => ['createHouseholdRoom' => false, 'createHallways' => false],
			'floors' => [
				[
					'name' => 'Ground Floor',
					'rooms' => [
						['name' => 'Kitchen', 'roomType' => 'kitchen'],
					],
				],
			],
		];
	}

	public function testApplySetup_Putzteufel_CreatesAllApplicableTasks(): void {
		$this->stubPersistence();
		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('commit');

		$result = $this->service->applySetup(1, $this->basePayload());

		// Kitchen has 2 templates, both available at putzteufel.
		$this->assertSame(1, $result['floors']);
		$this->assertSame(1, $result['rooms']);
		$this->assertSame(2, $result['tasks']);
		$this->assertSame(2, $result['roomTasks']);
		$this->assertSame([], $result['skipped']);
	}

	public function testApplySetup_Putmuffel_FewerTasksThanPutzteufel(): void {
		$this->stubPersistence();

		$putzteufel = $this->service->applySetup(1, $this->basePayload());
		$this->nextId = 1000; // reset id counter for the second run
		$this->stubPersistence();
		$payload = $this->basePayload();
		$payload['intensityLevel'] = TemplateService::LEVEL_PUTZMUFFEL;
		$putzmuffel = $this->service->applySetup(1, $payload);

		// kitchen_counters is putzmuffel-exclusive (null), so putzmuffel skips it.
		$this->assertGreaterThan($putzmuffel['tasks'], $putzteufel['tasks']);
		$this->assertSame(1, $putzmuffel['tasks']);
		$this->assertSame(['kitchen_counters'], $putzmuffel['skipped']);
	}

	public function testApplySetup_AutoHouseholdAndHallwayRoomsCreated(): void {
		$this->stubPersistence();
		$payload = $this->basePayload();
		$payload['options'] = ['createHouseholdRoom' => true, 'createHallways' => true];

		$createdRooms = [];
		$this->roomMapper = $this->createMock(\OCA\ChoreStrike\Db\RoomMapper::class);
		$this->roomMapper->method('create')->willReturnCallback(function ($floorId, $name, $icon, $roomType) use (&$createdRooms) {
			$createdRooms[] = ['name' => $name, 'icon' => $icon, 'roomType' => $roomType];
			$room = new Room();
			$this->setEntityId($room, $this->freshId());
			return $room;
		});
		// Re-bind service with the spying room mapper.
		$this->rebuildService();

		$this->service->applySetup(1, $payload);

		$createdRoomTypes = array_column($createdRooms, 'roomType');
		$this->assertContains('kitchen', $createdRoomTypes);
		$this->assertContains('hallway', $createdRoomTypes);
		$this->assertContains('household', $createdRoomTypes);

		// Auto rooms carry the localized room-type default name and the
		// room-type icon from the registry (not the generic 'Home' fallback).
		$byType = [];
		foreach ($createdRooms as $created) {
			$byType[$created['roomType']] = $created;
		}
		$this->assertSame('Hallway & Stairs', $byType['hallway']['name']);
		$this->assertSame('DoorOpen', $byType['hallway']['icon']);
		$this->assertSame('Household & Co.', $byType['household']['name']);
		$this->assertSame('WashingMachine', $byType['household']['icon']);
	}

	public function testApplySetup_FloorWithoutRooms_Throws(): void {
		$this->stubPersistence();
		$payload = $this->basePayload();
		$payload['floors'][] = ['name' => 'Empty Floor', 'rooms' => []];

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('At least one room is required per floor');
		$this->service->applySetup(1, $payload);
	}

	public function testApplySetup_InvalidLevel_Throws(): void {
		$this->stubPersistence();
		$payload = $this->basePayload();
		$payload['intensityLevel'] = 'gammypuss';

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('intensity level');
		$this->service->applySetup(1, $payload);
	}

	public function testApplySetup_TooManyFloors_Throws(): void {
		$this->stubPersistence();
		$payload = $this->basePayload();
		for ($i = 0; $i < HouseSetupService::MAX_FLOORS + 1; $i++) {
			$payload['floors'][] = ['name' => "F$i", 'rooms' => []];
		}

		$this->expectException(\InvalidArgumentException::class);
		$this->service->applySetup(1, $payload);
	}

	public function testApplySetup_InvalidRoomType_Throws(): void {
		$this->stubPersistence();
		$payload = $this->basePayload();
		$payload['floors'][0]['rooms'][0]['roomType'] = 'dungeon';

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Invalid room type');
		$this->service->applySetup(1, $payload);
	}

	public function testApplySetup_NonMemberAssignee_Throws(): void {
		$this->stubPersistence();
		$this->houseUserMapper->method('findByHouseAndUser')->willReturn(null);

		$payload = $this->basePayload();
		$payload['floors'][0]['rooms'][0]['assigneeUserId'] = 'stranger';

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Assignee is not a member');
		$this->service->applySetup(1, $payload);
	}

	public function testApplySetup_NonOwner_ThrowsForbiddenBeforeValidation(): void {
		$this->authorization->expects($this->once())
			->method('requireHouseOwner')
			->with(1)
			->willThrowException(new ForbiddenException('Owner role required'));

		$this->expectException(ForbiddenException::class);
		// Even an invalid payload must fail with the 403 first — the owner
		// gate runs before validateBasics (CS-PLAN-061 §3.4).
		$this->service->applySetup(1, ['intensityLevel' => 'gammypuss']);
	}

	public function testApplySetup_RollsBackOnPersistenceFailure(): void {
		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('rollBack');
		$this->db->expects($this->never())->method('commit');

		$this->floorMapper->method('create')->willThrowException(new \RuntimeException('db down'));

		$this->expectException(\RuntimeException::class);
		$this->service->applySetup(1, $this->basePayload());
	}

	// --- CS-PLAN-062 §3.1: transactional first-run gate + house lock ---

	public function testApplySetup_LocksHouseBeforeFloorCount(): void {
		$this->stubPersistence();
		$calls = [];
		$this->houseMapper->method('findForUpdate')->willReturnCallback(function () use (&$calls) {
			$calls[] = 'lock';
			$house = new House();
			$this->setEntityId($house, 1);
			$house->setName('My House');
			return $house;
		});
		$this->floorMapper->method('countByHouseId')->willReturnCallback(function () use (&$calls) {
			$calls[] = 'count';
			return 0;
		});

		$this->service->applySetup(1, $this->basePayload());

		// The house row lock (first of the documented lock order) must be
		// acquired before the floor-existence recheck inside the transaction.
		$this->assertSame(['lock', 'count'], $calls);
	}

	public function testApplySetup_HouseHasFloors_ThrowsConflictAndRollsBack(): void {
		$this->stubPersistence();
		$this->db->expects($this->once())->method('rollBack');
		$this->db->expects($this->never())->method('commit');

		// Swap in a floor mapper whose in-transaction count sees floors —
		// a fresh mock so the gate stub is the only configuration (PHPUnit
		// applies the first matching stub).
		$this->floorMapper = $this->createMock(\OCA\ChoreStrike\Db\FloorMapper::class);
		$this->floorMapper->expects($this->never())->method('create');
		$this->floorMapper->method('countByHouseId')->willReturn(2);
		$this->rebuildService();

		$this->expectException(ConflictException::class);
		$this->expectExceptionMessage('already set up');
		$this->service->applySetup(1, $this->basePayload());
	}

	// --- CS-PLAN-062 §3.2: house name in the setup command ---

	public function testApplySetup_BlankHouseName_KeepsCurrentName(): void {
		$this->stubPersistence();
		$this->houseMapper->expects($this->never())->method('update');

		$payload = $this->basePayload();
		$payload['houseName'] = '   ';

		$result = $this->service->applySetup(1, $payload);

		$this->assertSame('My House', $result['houseName']);
	}

	public function testApplySetup_MissingHouseName_KeepsCurrentName(): void {
		$this->stubPersistence();
		$this->houseMapper->expects($this->never())->method('update');

		$result = $this->service->applySetup(1, $this->basePayload());

		$this->assertSame('My House', $result['houseName']);
	}

	public function testApplySetup_TooLongHouseName_ThrowsBeforeAnyWrite(): void {
		$this->stubPersistence();
		$this->db->expects($this->never())->method('beginTransaction');
		$this->houseMapper->expects($this->never())->method('update');

		$payload = $this->basePayload();
		$payload['houseName'] = str_repeat('x', HouseSetupService::MAX_NAME_LENGTH + 1);

		$this->expectException(\InvalidArgumentException::class);
		// The identity l10n mock keeps the raw placeholder string.
		$this->expectExceptionMessage('name must not exceed');
		$this->service->applySetup(1, $payload);
	}

	/**
	 * CR-062-01 (CS-PLAN-063 §3.1): a present but non-string houseName is a
	 * hard 400 — never silently treated as absent (which answered 200 and
	 * kept the old name).
	 */
	public function testApplySetup_NonStringHouseName_ThrowsBeforeAnyWrite(): void {
		$this->stubPersistence();
		$this->db->expects($this->never())->method('beginTransaction');
		$this->houseMapper->expects($this->never())->method('update');

		$payload = $this->basePayload();
		$payload['houseName'] = ['Family Home'];

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('A house name must be a string');
		$this->service->applySetup(1, $payload);
	}

	public function testApplySetup_HouseName_TrimmedAndPersistedInsideTransaction(): void {
		$this->stubPersistence();
		$this->db->expects($this->once())->method('commit');

		$updated = null;
		$this->houseMapper->method('update')->willReturnCallback(function ($house) use (&$updated) {
			$updated = $house;
			return $house;
		});

		$payload = $this->basePayload();
		$payload['houseName'] = '  Family Home  ';

		$result = $this->service->applySetup(1, $payload);

		$this->assertNotNull($updated);
		$this->assertSame('Family Home', $updated->getName());
		$this->assertSame('Family Home', $result['houseName']);
	}

	// --- CS-PLAN-062 §3.3: membership revalidation under the transaction ---

	public function testApplySetup_AssigneeRemovedUnderTransaction_ThrowsAndRollsBack(): void {
		$this->stubPersistence();
		$this->db->expects($this->once())->method('rollBack');
		$this->db->expects($this->never())->method('commit');
		$this->floorMapper->expects($this->never())->method('create');

		$membership = $this->createMock(\OCA\ChoreStrike\Db\HouseUser::class);
		// First lookup = pre-transaction fast-fail check (member present),
		// second lookup = revalidation under the lock (membership is gone).
		$this->houseUserMapper->expects($this->exactly(2))
			->method('findByHouseAndUser')
			->willReturnOnConsecutiveCalls($membership, null);

		$payload = $this->basePayload();
		$payload['floors'][0]['rooms'][0]['assigneeUserId'] = 'u1';

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Assignee is not a member');
		$this->service->applySetup(1, $payload);
	}

	// --- CS-PLAN-062 D7: forced mid-transaction failure proof ---

	public function testApplySetup_MidTransactionFailureAfterRoomCreate_RollsBackNeverCommits(): void {
		$this->stubPersistence();
		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('rollBack');
		$this->db->expects($this->never())->method('commit');

		// Sabotage AFTER the floor and room creates: floor/room mappers run,
		// then the task materialization explodes — every write must roll back.
		$this->taskMapper->method('create')->willThrowException(new \RuntimeException('boom'));

		$this->expectException(\RuntimeException::class);
		$this->service->applySetup(1, $this->basePayload());
	}

	private function rebuildService(): void {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(fn ($t) => $t);
		$this->service = new HouseSetupService(
			$this->floorMapper,
			$this->houseMapper,
			$this->roomMapper,
			$this->taskMapper,
			$this->roomTaskMapper,
			$this->taskAssigneeMapper,
			$this->houseUserMapper,
			$this->templateService,
			new \OCA\ChoreStrike\Db\TransactionExecutor($this->db),
			$l10n,
			$this->createMock(AppLogger::class),
			$this->authorization
		);
	}
}
