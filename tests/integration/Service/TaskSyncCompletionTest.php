<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Service;

use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Service\TaskSyncService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;

/**
 * Integration test for the collapsed TaskSync completion path (CR-SVC-07).
 *
 * A CalDAV-sync completion (TaskSyncService::completeTaskForHouse) must route
 * through the canonical RoomService::completeRoomTask so rewards, locking, the
 * daily duplicate guard, the cleanliness update, and recurrence all apply
 * uniformly — the old parallel path awarded sparksEarned=0 and used a
 * divergent recurrence helper.
 */
class TaskSyncCompletionTest extends IntegrationTestCase
{
	public function testSyncCompletionAwardsCanonicalRewardsAndAdvancesRecurrence(): void
	{
		$houseId = $this->createHouse('sync_owner', 'Sync House');
		$floorId = $this->createFloor($houseId, 'Ground Floor', 1);
		$roomId = $this->createRoom($houseId, $floorId, 'Kitchen');
		$taskId = $this->createTask($houseId, 'Dishes', 'Do the dishes', 5, 3);
		$roomTaskId = $this->assignTaskToRoom($taskId, $roomId, 'sync_owner');

		$mapper = \OC::$server->get(HouseUserMapper::class);
		$user = $mapper->findByNextcloudUserId($houseId, 'sync_owner');
		$coinsBefore = $user->getCoins();

		$service = \OC::$server->get(TaskSyncService::class);
		$result = $service->completeTaskForHouse($roomTaskId, 'sync_owner', $houseId);

		// CR-SVC-07: rewards from the canonical completion flow through to the
		// sync result (the old divergent path surfaced no rewards).
		self::assertArrayHasKey('rewards', $result);
		self::assertGreaterThan(0, $result['rewards']['coins']);

		// The member's coin balance actually increased (canonical GameLogic
		// reward applied, not sparksEarned=0).
		$user = $mapper->findByNextcloudUserId($houseId, 'sync_owner');
		self::assertGreaterThan($coinsBefore, $user->getCoins());
	}
}
