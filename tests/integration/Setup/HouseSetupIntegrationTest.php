<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Setup;

use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\HouseSetupService;
use OCA\ChoreStrike\Service\TemplateService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;

/**
 * Integration test for the first-run setup wizard (CS-PLAN-027).
 *
 * Exercises HouseSetupService against the real Docker DB: a fresh house (no
 * floors) is populated transactionally with floors, rooms (with room type),
 * tasks from the leveled catalog, and room-task links.
 *
 * Requires the Docker test environment (bootstrap-integration.php).
 */
class HouseSetupIntegrationTest extends IntegrationTestCase {
	private HouseSetupService $setupService;
	private HouseService $houseService;
	private int $houseId;
	/** @var int[] Houses whose full subtree must be cleaned (service-created rows are not tracked by the base class). */
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
			$this->deleteHouseSubtree($houseId);
		}
		parent::tearDown();
	}

	/**
	 * Delete all floors/rooms/tasks/room_tasks under a house (the service
	 * creates these directly, so they are not registered with the base class).
	 */
	private function deleteHouseSubtree(int $houseId): void {
		$db = $this->db;

		$floorIds = $this->collectIds('chorestrike_floors', 'id', 'house_id', $houseId);
		if ($floorIds === []) {
			return;
		}

		$roomIds = $this->collectIdsIn('chorestrike_rooms', 'id', 'floor_id', $floorIds);
		$taskIds = [];
		if ($roomIds !== []) {
			$taskIds = $this->collectIdsIn('chorestrike_room_tasks', 'task_id', 'room_id', $roomIds);
			$this->deleteIn('chorestrike_room_tasks', 'room_id', $roomIds);
			$this->deleteIn('chorestrike_rooms', 'floor_id', $floorIds);
		}
		$this->deleteIn('chorestrike_floors', 'id', $floorIds);
		if ($taskIds !== []) {
			$this->deleteIn('chorestrike_tasks', 'id', $taskIds);
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

	/** @param int[] $values */
	private function deleteIn(string $table, string $whereCol, array $values): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($table)
			->where($qb->expr()->in($whereCol, $qb->createNamedParameter($values, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)));
		$qb->executeStatement();
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
}
