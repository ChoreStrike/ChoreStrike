<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Db;

use OCA\ChoreStrike\Db\RoomMapper;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * FEAT-UX-044 (CS-PLAN-088 D-088-19): the names unique room naming
 * compares against — live rooms of one house across its floors.
 */
class RoomMapperLiveNamesTest extends IntegrationTestCase {
	private RoomMapper $roomMapper;

	protected function setUp(): void {
		parent::setUp();
		$this->roomMapper = \OC::$server->get(RoomMapper::class);
	}

	public function testFindLiveNamesByHouseId_ScopesToTheHouseAndLiveRooms(): void {
		$houseId = $this->createHouse('names_owner_a', 'Names House A');
		$otherHouseId = $this->createHouse('names_owner_b', 'Names House B');
		$bathId = $this->createRoom($houseId, $this->createFloor($houseId, 'Ground Floor', 1), 'Bath');
		$this->createRoom($houseId, $this->createFloor($houseId, 'Upstairs', 2), 'Kitchen');
		$atticId = $this->createRoom($houseId, $this->createFloor($houseId, 'Attic Floor', 3), 'Attic');
		$this->createRoom($otherHouseId, $this->createFloor($otherHouseId, 'Ground Floor', 1), 'Garage');
		$this->tombstoneRoom($atticId);

		$names = $this->roomMapper->findLiveNamesByHouseId($houseId);
		sort($names);
		$this->assertSame(['Bath', 'Kitchen'], $names);
		$this->assertSame(['Kitchen'], $this->roomMapper->findLiveNamesByHouseId($houseId, $bathId));
	}

	private function tombstoneRoom(int $roomId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('chorestrike_rooms')
			->set('deleted_at', $qb->createNamedParameter(new \DateTime(), IQueryBuilder::PARAM_DATETIME_MUTABLE))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($roomId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}
}
