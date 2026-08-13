<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\Floor;
use OCA\ChoreStrike\Db\Room;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\Task;
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
	private $floorMapper;
	private $roomMapper;
	private $taskMapper;
	private $roomTaskMapper;
	private $houseUserMapper;
	private TemplateService $templateService;
	private $db;
	private int $nextId = 1000;

	protected function setUp(): void {
		parent::setUp();

		$this->floorMapper = $this->createMock(\OCA\ChoreStrike\Db\FloorMapper::class);
		$this->roomMapper = $this->createMock(\OCA\ChoreStrike\Db\RoomMapper::class);
		$this->taskMapper = $this->createMock(\OCA\ChoreStrike\Db\TaskMapper::class);
		$this->roomTaskMapper = $this->createMock(\OCA\ChoreStrike\Db\RoomTaskMapper::class);
		$this->houseUserMapper = $this->createMock(\OCA\ChoreStrike\Db\HouseUserMapper::class);
		$this->db = $this->createMock(IDBConnection::class);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(fn ($t) => $t);
		$this->templateService = new TemplateService($l10n);
		$this->injectTemplateFixtures();

		$this->service = new HouseSetupService(
			$this->floorMapper,
			$this->roomMapper,
			$this->taskMapper,
			$this->roomTaskMapper,
			$this->houseUserMapper,
			$this->templateService,
			$this->db
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

	public function testApplySetup_RollsBackOnPersistenceFailure(): void {
		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('rollBack');
		$this->db->expects($this->never())->method('commit');

		$this->floorMapper->method('create')->willThrowException(new \RuntimeException('db down'));

		$this->expectException(\RuntimeException::class);
		$this->service->applySetup(1, $this->basePayload());
	}

	private function rebuildService(): void {
		$this->service = new HouseSetupService(
			$this->floorMapper,
			$this->roomMapper,
			$this->taskMapper,
			$this->roomTaskMapper,
			$this->houseUserMapper,
			$this->templateService,
			$this->db
		);
	}
}
