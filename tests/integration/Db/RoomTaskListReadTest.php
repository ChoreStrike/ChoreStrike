<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Db;

use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Service\RoomTaskReadService;
use OCA\ChoreStrike\Service\RoomTaskService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;

/**
 * Room-task list reads never list tombstoned (deleted) rows (CS-PLAN-084
 * D-084-07): the TaskManager "Completed" filter reads `activeOnly=false`
 * and must show finished work only, and the RoomDetail stats count the
 * room's current tasks — a deleted task is neither.
 */
class RoomTaskListReadTest extends IntegrationTestCase {
	private const OWNER = 'list_read_owner';

	private RoomTaskMapper $roomTaskMapper;

	protected function setUp(): void {
		parent::setUp();
		$this->roomTaskMapper = \OC::$server->get(RoomTaskMapper::class);
	}

	public function testInactiveReads_ListFinishedButNeverDeletedRows(): void {
		[$houseId, $roomId, $ids] = $this->fixture();
		$listed = static fn (array $rows): array => array_map(static fn (RoomTask $row): int => $row->getId(), $rows);

		self::assertEqualsCanonicalizing([$ids['active'], $ids['finished']], $listed($this->roomTaskMapper->findByHouse($houseId, false)));
		self::assertEqualsCanonicalizing([$ids['active'], $ids['finished']], $listed($this->roomTaskMapper->findByRoomId($roomId, false)));
		self::assertSame([$ids['active']], $listed($this->roomTaskMapper->findByHouse($houseId, true)));

		$owner = \OC::$server->get(HouseUserMapper::class)->findByHouseAndUser($houseId, self::OWNER);
		$endpointRows = \OC::$server->get(RoomTaskReadService::class)->getHouseRoomTasks($houseId, $owner, false);
		self::assertNotContains($ids['deleted'], $listed($endpointRows));
	}

	public function testRoomStats_IgnoreDeletedTasks(): void {
		[$houseId, $roomId] = $this->fixture();
		$owner = \OC::$server->get(HouseUserMapper::class)->findByHouseAndUser($houseId, self::OWNER);

		$stats = \OC::$server->get(RoomTaskService::class)->getRoomTaskStats($roomId, $owner);

		self::assertSame([2, 1], [$stats['totalTasks'], $stats['activeTasks']]);
	}

	/**
	 * One room with an active, a finished (deactivated) and a deleted
	 * (tombstoned) room task.
	 *
	 * @return array{0: int, 1: int, 2: array{active: int, finished: int, deleted: int}}
	 */
	private function fixture(): array {
		$houseId = $this->createHouse(self::OWNER, 'List Read House');
		$floorId = $this->createFloor($houseId);
		$roomId = $this->createRoom($houseId, $floorId, 'Kitchen');
		$ids = [];
		foreach (['active', 'finished', 'deleted'] as $name) {
			$ids[$name] = $this->assignTaskToRoom($this->createTask($houseId, ucfirst($name) . ' chore'), $roomId, self::OWNER);
		}

		$finished = $this->roomTaskMapper->find($ids['finished']);
		$finished->setIsActive(false);
		$this->roomTaskMapper->update($finished);

		$deleted = $this->roomTaskMapper->find($ids['deleted']);
		$deleted->tombstone(new \DateTime('now', new \DateTimeZone('UTC')));
		$this->roomTaskMapper->update($deleted);

		return [$houseId, $roomId, $ids];
	}
}
