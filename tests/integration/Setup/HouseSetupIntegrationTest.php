<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Setup;

use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Exception\ConflictException;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\HouseSetupService;
use OCA\ChoreStrike\Service\TemplateService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;

/**
 * Integration test for the first-run setup wizard (CS-PLAN-027).
 *
 * Exercises HouseSetupService against the real Docker DB: a fresh house (no
 * floors) is populated transactionally with floors, rooms (with room type),
 * tasks from the leveled catalog, and room-task links. CS-PLAN-062 extends
 * the aggregate with the house name and the in-transaction first-run gate.
 *
 * Requires the Docker test environment (bootstrap-integration.php).
 */
class HouseSetupIntegrationTest extends IntegrationTestCase {
	private HouseSetupService $setupService;
	private HouseService $houseService;
	private int $houseId;
	/** @var int[] Owned setup houses whose new task definitions must be registered. */
	private array $setupHouseIds = [];

	protected function setUp(): void {
		parent::setUp();

		$this->setupService = \OC::$server->get(HouseSetupService::class);
		$this->houseService = \OC::$server->get(HouseService::class);

		$owner = 'setup-owner-' . uniqid();
		$this->houseId = $this->createHouse($owner);
		$this->setupHouseIds[] = $this->houseId;
	}

	protected function tearDown(): void {
		foreach ($this->setupHouseIds as $houseId) {
			$this->registerSetupTasks($houseId);
		}
		parent::tearDown();
	}

	/**
	 * Setup creates new definitions; register them before the base discovers
	 * and clears their structure/timeline in dependency order.
	 */
	private function registerSetupTasks(int $houseId): void {
		$floorIds = $this->collectIds('chorestrike_floors', 'id', 'house_id', $houseId);
		if ($floorIds === []) {
			return;
		}

		$roomIds = $this->collectIdsIn('chorestrike_rooms', 'id', 'floor_id', $floorIds);
		if ($roomIds !== []) {
			$taskIds = $this->collectIdsIn('chorestrike_room_tasks', 'task_id', 'room_id', $roomIds);
			foreach ($taskIds as $taskId) {
				$this->registerCreatedTask($taskId);
			}
		}
	}

	/** @return int[] */
	private function collectIds(string $table, string $selectCol, string $whereCol, int $value): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select($selectCol)->from($table)
			->where($qb->expr()->eq($whereCol, $qb->createNamedParameter($value)));
		$res = $qb->executeQuery();
		$ids = [];
		while ($row = $res->fetch()) {
			$ids[] = (int)$row[$selectCol];
		}
		$res->closeCursor();
		return $ids;
	}

	/** @param int[] $values */
	private function collectIdsIn(string $table, string $selectCol, string $whereCol, array $values): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select($selectCol)->from($table)
			->where($qb->expr()->in($whereCol, $qb->createNamedParameter($values, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)));
		$res = $qb->executeQuery();
		$ids = [];
		while ($row = $res->fetch()) {
			$ids[] = (int)$row[$selectCol];
		}
		$res->closeCursor();
		return $ids;
	}

	public function testApplySetupCreatesFloorsRoomsTasksAndRoomTasks(): void {
		$payload = [
			'intensityLevel' => TemplateService::LEVEL_PUTZTEUFEL,
			'options' => ['createHouseholdRoom' => true, 'createHallways' => true],
			'floors' => [
				[
					'name' => 'Ground Floor',
					'rooms' => [
						['name' => 'Kitchen', 'roomType' => 'kitchen'],
						['name' => 'Bathroom', 'roomType' => 'bathroom'],
					],
				],
			],
		];

		$result = $this->setupService->applySetup($this->houseId, $payload);

		$this->assertSame(1, $result['floors']);
		// 2 user rooms + 1 hallway (auto) + 1 household (auto) = 4
		$this->assertSame(4, $result['rooms']);
		$this->assertGreaterThan(0, $result['tasks']);
		$this->assertGreaterThan(0, $result['roomTasks']);
		$this->assertSame([], $result['skipped']);

		// The house is now "set up": it has floors.
		$this->assertTrue($this->houseService->hasFloors($this->houseId));

		// Rooms carry the room_type column.
		$qb = $this->db->getQueryBuilder();
		$qb->select('room_type')
			->from('chorestrike_rooms', 'r')
			->innerJoin('r', 'chorestrike_floors', 'f', $qb->expr()->eq('r.floor_id', 'f.id'))
			->where($qb->expr()->eq('f.house_id', $qb->createNamedParameter($this->houseId)));
		$types = [];
		$res = $qb->executeQuery();
		while ($row = $res->fetch()) {
			$types[] = $row['room_type'];
		}
		$res->closeCursor();

		$this->assertContains('kitchen', $types);
		$this->assertContains('bathroom', $types);
		$this->assertContains('hallway', $types);
		$this->assertContains('household', $types);
	}

	public function testPutzmuffelProducesFewerTasksThanPutzteufel(): void {
		$payload = [
			'intensityLevel' => TemplateService::LEVEL_PUTZTEUFEL,
			'options' => ['createHouseholdRoom' => false, 'createHallways' => false],
			'floors' => [
				['name' => 'Floor', 'rooms' => [['name' => 'Kitchen', 'roomType' => 'kitchen']]],
			],
		];
		$putzteufel = $this->setupService->applySetup($this->houseId, $payload);

		// A second house for the putzmuffel comparison (the first now has floors).
		$secondHouseId = $this->createHouse('setup-owner-' . uniqid());
		$this->setupHouseIds[] = $secondHouseId;
		$payload['intensityLevel'] = TemplateService::LEVEL_PUTZMUFFEL;
		$putzmuffel = $this->setupService->applySetup($secondHouseId, $payload);

		$this->assertGreaterThan($putzmuffel['tasks'], $putzteufel['tasks']);
	}

	public function testApplySetupRejectsInvalidRoomType(): void {
		$payload = [
			'intensityLevel' => TemplateService::LEVEL_PUTZTEUFEL,
			'options' => ['createHouseholdRoom' => false, 'createHallways' => false],
			'floors' => [
				['name' => 'Floor', 'rooms' => [['name' => 'X', 'roomType' => 'dungeon']]],
			],
		];

		$this->expectException(\InvalidArgumentException::class);
		$this->setupService->applySetup($this->houseId, $payload);
	}

	// --- CS-PLAN-062 §3.2: house name in the setup command ---

	public function testApplySetupWithHouseNamePersistsNameAndStructureAtomically(): void {
		$payload = [
			'houseName' => '  Family Home  ',
			'intensityLevel' => TemplateService::LEVEL_PUTZTEUFEL,
			'options' => ['createHouseholdRoom' => false, 'createHallways' => false],
			'floors' => [
				['name' => 'Floor', 'rooms' => [['name' => 'Kitchen', 'roomType' => 'kitchen']]],
			],
		];

		$result = $this->setupService->applySetup($this->houseId, $payload);

		// The response echoes the effective (trimmed) name...
		$this->assertSame('Family Home', $result['houseName']);
		// ...and the house row carries it, committed with the structure.
		$house = \OC::$server->get(HouseMapper::class)->find($this->houseId);
		$this->assertSame('Family Home', $house->getName());
		$this->assertCount(1, $this->collectIds('chorestrike_floors', 'id', 'house_id', $this->houseId));
	}

	public function testApplySetupWithoutHouseNameKeepsCurrentNameAndEchoesIt(): void {
		$payload = [
			'intensityLevel' => TemplateService::LEVEL_PUTZTEUFEL,
			'options' => ['createHouseholdRoom' => false, 'createHallways' => false],
			'floors' => [
				['name' => 'Floor', 'rooms' => [['name' => 'Kitchen', 'roomType' => 'kitchen']]],
			],
		];

		$result = $this->setupService->applySetup($this->houseId, $payload);

		$this->assertSame('Test House', $result['houseName']);
	}

	// --- CS-PLAN-062 §3.1: the first-run gate inside the transaction ---

	public function testSecondApplyIsRejectedWithConflictAndZeroAdditionalRows(): void {
		$payload = [
			'intensityLevel' => TemplateService::LEVEL_PUTZTEUFEL,
			'options' => ['createHouseholdRoom' => false, 'createHallways' => false],
			'floors' => [
				['name' => 'Floor', 'rooms' => [['name' => 'Kitchen', 'roomType' => 'kitchen']]],
			],
		];
		$first = $this->setupService->applySetup($this->houseId, $payload);

		$floorCountBefore = count($this->collectIds('chorestrike_floors', 'id', 'house_id', $this->houseId));
		$taskCountBefore = count($this->collectIdsIn(
			'chorestrike_room_tasks',
			'task_id',
			'room_id',
			$this->collectIdsIn('chorestrike_rooms', 'id', 'floor_id', $this->collectIds('chorestrike_floors', 'id', 'house_id', $this->houseId))
		));

		try {
			$this->setupService->applySetup($this->houseId, $payload);
			$this->fail('The second apply must be rejected with a conflict');
		} catch (ConflictException $e) {
			$this->assertStringContainsString('already set up', $e->getMessage());
		}

		$this->assertSame($floorCountBefore, count($this->collectIds('chorestrike_floors', 'id', 'house_id', $this->houseId)), 'no additional floor may be created by the losing apply');
		$taskCountAfter = count($this->collectIdsIn(
			'chorestrike_room_tasks',
			'task_id',
			'room_id',
			$this->collectIdsIn('chorestrike_rooms', 'id', 'floor_id', $this->collectIds('chorestrike_floors', 'id', 'house_id', $this->houseId))
		));
		$this->assertSame($first['roomTasks'], $taskCountBefore, 'sanity: the first apply created the room tasks');
		$this->assertSame($taskCountBefore, $taskCountAfter, 'the losing apply must not add room tasks');
	}

	public function testApplySetupWithInvalidHouseNamePersistsNothing(): void {
		$payload = [
			'houseName' => str_repeat('x', HouseSetupService::MAX_NAME_LENGTH + 1),
			'intensityLevel' => TemplateService::LEVEL_PUTZTEUFEL,
			'options' => ['createHouseholdRoom' => false, 'createHallways' => false],
			'floors' => [
				['name' => 'Floor', 'rooms' => [['name' => 'Kitchen', 'roomType' => 'kitchen']]],
			],
		];

		try {
			$this->setupService->applySetup($this->houseId, $payload);
			$this->fail('An over-length house name must be rejected');
		} catch (\InvalidArgumentException $e) {
			$this->assertStringContainsString('house name must not exceed', $e->getMessage());
		}

		// Validation fails before the transaction — zero structure persisted.
		$this->assertFalse($this->houseService->hasFloors($this->houseId));
	}
}
