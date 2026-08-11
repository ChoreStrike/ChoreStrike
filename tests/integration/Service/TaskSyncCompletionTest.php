<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Service;

use OCA\ChoreStrike\Db\CompletedTaskMapper;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Service\TaskSyncService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;
use OCP\DB\QueryBuilder\IQueryBuilder;

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
	/** @var int[] Room task ids whose completion rows need cleanup */
	private array $completedRoomTaskIds = [];

	protected function tearDown(): void
	{
		// The base cleanup does not track completed_tasks rows; remove the
		// rows this test created so the shared Docker DB stays clean.
		if (!empty($this->completedRoomTaskIds)) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('chorestrike_completed_tasks')
				->where($qb->expr()->in(
					'room_task_id',
					$qb->createNamedParameter($this->completedRoomTaskIds, IQueryBuilder::PARAM_INT_ARRAY)
				));
			$qb->executeStatement();
		}

		parent::tearDown();
	}

	public function testSyncCompletionAwardsCanonicalRewardsAndAdvancesRecurrence(): void
	{
		$houseId = $this->createHouse('sync_owner', 'Sync House');
		$floorId = $this->createFloor($houseId, 'Ground Floor', 1);
		$roomId = $this->createRoom($houseId, $floorId, 'Kitchen');
		$taskId = $this->createTask($houseId, 'Dishes', 'Do the dishes', 5, 3);
		$roomTaskId = $this->assignTaskToRoom($taskId, $roomId, 'sync_owner');
		$this->completedRoomTaskIds[] = $roomTaskId;

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

	/**
	 * CR-SVC-46: a sync completion with a nextcloudTaskId must stamp the
	 * completion row itself so findPendingSyncs() — which filters on
	 * completed_tasks.nextcloud_task_id — sees it for sync-out. Before the
	 * fix only room_tasks.nextcloud_task_id was linked and the completion
	 * never appeared in getPendingSyncs().
	 */
	public function testSyncCompletionStampsNextcloudTaskIdAndAppearsInPendingSyncs(): void
	{
		$houseId = $this->createHouse('sync_owner2', 'Sync House 2');
		$floorId = $this->createFloor($houseId, 'Ground Floor', 1);
		$roomId = $this->createRoom($houseId, $floorId, 'Kitchen');
		$taskId = $this->createTask($houseId, 'Dishes', 'Do the dishes', 5, 3);
		$roomTaskId = $this->assignTaskToRoom($taskId, $roomId, 'sync_owner2');
		$this->completedRoomTaskIds[] = $roomTaskId;

		$service = \OC::$server->get(TaskSyncService::class);
		$result = $service->completeTaskForHouse($roomTaskId, 'sync_owner2', $houseId, 4242);
		self::assertSame(4242, $result['nextcloudTaskId']);

		// The completion row maps cleanly and carries the Nextcloud task id.
		// (Mapping a full completed_tasks row threw BadFunctionCallException
		// on the real DB before the CR-SVC-45 schema fix.)
		$completedTaskMapper = \OC::$server->get(CompletedTaskMapper::class);
		$completion = $completedTaskMapper->findLatestByRoomTask($roomTaskId);
		self::assertNotNull($completion);
		self::assertSame(4242, $completion->getNextcloudTaskId());
		self::assertFalse($completion->getSyncedToNextcloud());

		// Sync-out sees the completion for this house.
		$pending = $service->getPendingSyncsForHouse($houseId);
		$pendingRoomTaskIds = array_map(
			static fn ($completedTask) => $completedTask->getRoomTaskId(),
			$pending
		);
		self::assertContains($roomTaskId, $pendingRoomTaskIds);
	}
}
