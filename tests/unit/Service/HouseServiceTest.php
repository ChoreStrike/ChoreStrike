<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\CompletedTaskMapper;
use OCA\ChoreStrike\Db\Floor;
use OCA\ChoreStrike\Db\FloorMapper;
use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\Room;
use OCA\ChoreStrike\Db\RoomMapper;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\TaskAssigneeMapper;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\RewardConfigService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for HouseService
 */
class HouseServiceTest extends TestCase {
	private HouseService $service;
	private HouseMapper $houseMapper;
	private FloorMapper $floorMapper;
	private RoomMapper $roomMapper;
	private RoomTaskMapper $roomTaskMapper;
	private CompletedTaskMapper $completedTaskMapper;
	private HouseUserMapper $houseUserMapper;
	private TaskAssigneeMapper $taskAssigneeMapper;
	private RewardConfigService $rewardConfigService;
	private IConfig $config;
	private IDBConnection $db;

	protected function setUp(): void {
		$this->houseMapper = $this->createMock(HouseMapper::class);
		$this->floorMapper = $this->createMock(FloorMapper::class);
		$this->roomMapper = $this->createMock(RoomMapper::class);
		$this->roomTaskMapper = $this->createMock(RoomTaskMapper::class);
		$this->completedTaskMapper = $this->createMock(CompletedTaskMapper::class);
		$this->houseUserMapper = $this->createMock(HouseUserMapper::class);
		$this->taskAssigneeMapper = $this->createMock(TaskAssigneeMapper::class);
		$this->rewardConfigService = $this->createMock(RewardConfigService::class);
		$this->config = $this->createMock(IConfig::class);
		$this->db = $this->createMock(IDBConnection::class);

		$this->service = new HouseService(
			$this->houseMapper,
			$this->floorMapper,
			$this->roomMapper,
			$this->roomTaskMapper,
			$this->completedTaskMapper,
			$this->houseUserMapper,
			$this->taskAssigneeMapper,
			$this->rewardConfigService,
			$this->config,
			$this->db
		);
	}

	/**
	 * GetOrCreateHouse_ExistingHouse_ReturnsHouse
	 */
	public function testGetOrCreateHouse_ExistingHouse_ReturnsHouse(): void {
		$instanceId = 'instance123';
		$houseName = 'My House';

		$house = new House();
		$house->setId(1);
		$house->setName('Existing House');

		$this->houseMapper
			->expects($this->once())
			->method('findByInstanceId')
			->with($instanceId)
			->willReturn($house);

		$result = $this->service->getOrCreateHouse($instanceId, $houseName);

		$this->assertSame($house, $result);
	}

	/**
	 * GetOrCreateHouse_NewHouse_CreatesAndReturnsHouse
	 */
	public function testGetOrCreateHouse_NewHouse_CreatesAndReturnsHouse(): void {
		$instanceId = 'instance123';
		$houseName = 'My House';

		$house = new House();
		$house->setId(1);
		$house->setName($houseName);

		$this->houseMapper
			->expects($this->once())
			->method('findByInstanceId')
			->with($instanceId)
			->willThrowException(new DoesNotExistException('House not found'));

		$this->houseMapper
			->expects($this->once())
			->method('createForInstance')
			->with($instanceId, $houseName)
			->willReturn($house);

		$result = $this->service->getOrCreateHouse($instanceId, $houseName);

		$this->assertSame($house, $result);
		$this->assertEquals($houseName, $result->getName());
	}

	/**
	 * UpdateHouseName_ValidName_ReturnsUpdatedHouse
	 */
	public function testUpdateHouseName_ValidName_ReturnsUpdatedHouse(): void {
		$houseId = 1;
		$newName = 'Updated House';

		$house = new House();
		$house->setId($houseId);
		$house->setName('Old Name');

		$updatedHouse = new House();
		$updatedHouse->setId($houseId);
		$updatedHouse->setName($newName);

		$this->houseMapper
			->expects($this->once())
			->method('find')
			->with($houseId)
			->willReturn($house);

		$this->houseMapper
			->expects($this->once())
			->method('update')
			->with($this->callback(function ($h) use ($newName) {
				return $h->getName() === $newName;
			}))
			->willReturn($updatedHouse);

		$result = $this->service->updateHouseName($houseId, $newName);

		$this->assertEquals($newName, $result->getName());
	}

	/**
	 * CalculateHouseHealth_NoRooms_Returns100
	 */
	public function testCalculateHouseHealth_NoRooms_Returns100(): void {
		$houseId = 1;

		$this->roomMapper
			->expects($this->once())
			->method('findByHouseId')
			->with($houseId)
			->willReturn([]);

		$result = $this->service->calculateHouseHealth($houseId);

		$this->assertEquals(100, $result);
	}

	/**
	 * CalculateHouseHealth_WithRooms_ReturnsAverageCleanliness
	 *
	 * CS-PLAN-036: one house-scoped room fetch replaces the
	 * floors → findByFloorId loop.
	 */
	public function testCalculateHouseHealth_WithRooms_ReturnsAverageCleanliness(): void {
		$houseId = 1;

		$room1 = new Room();
		$room1->setId(1);
		$room1->setCleanliness(80);

		$room2 = new Room();
		$room2->setId(2);
		$room2->setCleanliness(60);

		$this->roomMapper
			->expects($this->once())
			->method('findByHouseId')
			->with($houseId)
			->willReturn([$room1, $room2]);

		$this->houseMapper
			->expects($this->once())
			->method('updateHealth')
			->with($houseId, 70);

		$result = $this->service->calculateHouseHealth($houseId);

		$this->assertEquals(70, $result);
	}

	/**
	 * GetHouseStats_WithRooms_ReturnsCompleteStats
	 */
	public function testGetHouseStats_WithRooms_ReturnsCompleteStats(): void {
		$houseId = 1;

		$house = new House();
		$house->setId($houseId);
		$house->setHouseHealth(75);

		$floor = new Floor();
		$floor->setId(1);

		$cleanRoom = new Room();
		$cleanRoom->setId(1);
		$cleanRoom->setCleanliness(70);

		$dirtyRoom = new Room();
		$dirtyRoom->setId(2);
		$dirtyRoom->setCleanliness(40);

		$this->houseMapper
			->expects($this->once())
			->method('find')
			->with($houseId)
			->willReturn($house);

		$this->floorMapper
			->expects($this->once())
			->method('findByHouseId')
			->with($houseId)
			->willReturn([$floor]);

		$this->roomMapper
			->expects($this->once())
			->method('findByHouseId')
			->with($houseId)
			->willReturn([$cleanRoom, $dirtyRoom]);

		$result = $this->service->getHouseStats($houseId);

		$this->assertSame($house, $result['house']);
		$this->assertEquals(2, $result['stats']['totalRooms']);
		$this->assertEquals(1, $result['stats']['cleanRooms']);
		$this->assertEquals(1, $result['stats']['dirtyRooms']);
		$this->assertEquals(75, $result['stats']['houseHealth']);
	}

	/**
	 * ResetHouse_UpdatesHealthAndResetsRoomsInBulk
	 *
	 * CS-PLAN-037: the floors → rooms loop is replaced by ONE
	 * house-scoped subquery UPDATE; no floor fetch, no per-room update.
	 */
	public function testResetHouse_UpdatesHealthAndResetsRoomsInBulk(): void {
		$houseId = 1;

		$house = new House();
		$house->setId($houseId);
		$house->setHouseHealth(50);

		$this->houseMapper
			->expects($this->once())
			->method('find')
			->with($houseId)
			->willReturn($house);

		$this->houseMapper
			->expects($this->once())
			->method('update')
			->with($this->callback(function ($h) {
				return $h->getHouseHealth() === 100;
			}));

		$this->roomMapper
			->expects($this->once())
			->method('resetByHouseId')
			->with($houseId);

		// The bulk path never iterates floors or updates room entities.
		$this->floorMapper->expects($this->never())->method('findByHouseId');
		$this->roomMapper->expects($this->never())->method('findByFloorId');
		$this->roomMapper->expects($this->never())->method('update');

		$this->service->resetHouse($houseId);
	}

	/**
	 * GetInstanceId_ReturnsConfigValue
	 */
	public function testGetInstanceId_ReturnsConfigValue(): void {
		$this->config
			->expects($this->once())
			->method('getSystemValue')
			->with('instanceid', 'default')
			->willReturn('abc123');

		$result = $this->service->getInstanceId();

		$this->assertEquals('abc123', $result);
	}

	/**
	 * GetWeeklyGoalProgress_EmptyWeek_NotAchievedZeroPercent
	 */
	public function testGetWeeklyGoalProgress_EmptyWeek_NotAchievedZeroPercent(): void {
		$houseId = 1;

		$this->roomTaskMapper
			->expects($this->once())
			->method('findActiveDueIdsBetweenByHouse')
			->willReturn([]);
		$this->completedTaskMapper
			->expects($this->once())
			->method('findCompletedRoomTaskIdsBetweenByHouse')
			->willReturn([]);
		$this->rewardConfigService
			->method('getWeeklyGoalTargetPercent')
			->willReturn(80);

		$result = $this->service->getWeeklyGoalProgress($houseId);

		$this->assertSame(0, $result['dueCount']);
		$this->assertSame(0, $result['completedCount']);
		$this->assertSame(0, $result['percent']);
		$this->assertSame(80, $result['targetPercent']);
		$this->assertFalse($result['achieved']);
	}

	/**
	 * GetWeeklyGoalProgress_AllCompleted_AchievedHundredPercent
	 *
	 * 5 tasks active+due this week, the same 5 also completed this week →
	 * dueSet has 5 entries (UNION), completedCount = 5 → 100%.
	 */
	public function testGetWeeklyGoalProgress_AllCompleted_AchievedHundredPercent(): void {
		$this->roomTaskMapper->method('findActiveDueIdsBetweenByHouse')->willReturn([1, 2, 3, 4, 5]);
		$this->completedTaskMapper->method('findCompletedRoomTaskIdsBetweenByHouse')->willReturn([1, 2, 3, 4, 5]);
		$this->rewardConfigService->method('getWeeklyGoalTargetPercent')->willReturn(80);

		$result = $this->service->getWeeklyGoalProgress(1);

		$this->assertSame(5, $result['dueCount']);
		$this->assertSame(5, $result['completedCount']);
		$this->assertSame(100, $result['percent']);
		$this->assertTrue($result['achieved']);
	}

	/**
	 * GetWeeklyGoalProgress_PartialCompletion_BelowTarget
	 *
	 * 5 active+due, 1 completed → 20%, below the 80% target.
	 */
	public function testGetWeeklyGoalProgress_PartialCompletion_BelowTarget(): void {
		$this->roomTaskMapper->method('findActiveDueIdsBetweenByHouse')->willReturn([1, 2, 3, 4, 5]);
		$this->completedTaskMapper->method('findCompletedRoomTaskIdsBetweenByHouse')->willReturn([1]);
		$this->rewardConfigService->method('getWeeklyGoalTargetPercent')->willReturn(80);

		$result = $this->service->getWeeklyGoalProgress(1);

		$this->assertSame(20, $result['percent']);
		$this->assertFalse($result['achieved']);
	}

	/**
	 * GetWeeklyGoalProgress_AtTarget_Achieved
	 *
	 * 5 active+due, 4 completed → 80%, exactly at the 80% target.
	 */
	public function testGetWeeklyGoalProgress_AtTarget_Achieved(): void {
		$this->roomTaskMapper->method('findActiveDueIdsBetweenByHouse')->willReturn([1, 2, 3, 4, 5]);
		$this->completedTaskMapper->method('findCompletedRoomTaskIdsBetweenByHouse')->willReturn([1, 2, 3, 4]);
		$this->rewardConfigService->method('getWeeklyGoalTargetPercent')->willReturn(80);

		$result = $this->service->getWeeklyGoalProgress(1);

		$this->assertSame(80, $result['percent']);
		$this->assertTrue($result['achieved']);
	}

	/**
	 * GetWeeklyGoalProgress_DenominatorStableWhenCompletedShiftsOut
	 *
	 * Critical regression: 5 weekly tasks originally due this week; 4 of them
	 * have been completed, shifting their next_due into next week. Active-due
	 * list now has only the 1 remaining task, but the 4 completed ones must
	 * still count in the denominator (via the UNION) so the percent stays at
	 * 80% rather than inflating to 400%/capped-100%.
	 */
	public function testGetWeeklyGoalProgress_DenominatorStableWhenCompletedShiftsOut(): void {
		// Only task #5 is still active+due this week (the other 4 moved on).
		$this->roomTaskMapper->method('findActiveDueIdsBetweenByHouse')->willReturn([5]);
		// But all of #1..#4 were completed this week.
		$this->completedTaskMapper->method('findCompletedRoomTaskIdsBetweenByHouse')->willReturn([1, 2, 3, 4]);
		$this->rewardConfigService->method('getWeeklyGoalTargetPercent')->willReturn(80);

		$result = $this->service->getWeeklyGoalProgress(1);

		// dueSet = {5} ∪ {1,2,3,4} = {1,2,3,4,5} → 5; completedCount = 4 → 80%.
		$this->assertSame(5, $result['dueCount']);
		$this->assertSame(4, $result['completedCount']);
		$this->assertSame(80, $result['percent']);
		$this->assertTrue($result['achieved']);
	}

	/**
	 * GetWeeklyGoalProgress_DailyTaskDoneMultipleTimes_CountsAsOne
	 *
	 * A daily task completed three times this week (by different members or
	 * on different days) must contribute 1 to the numerator, not 3.
	 */
	public function testGetWeeklyGoalProgress_DailyTaskDoneMultipleTimes_CountsAsOne(): void {
		// Single active due task (its current next_due is in this week).
		$this->roomTaskMapper->method('findActiveDueIdsBetweenByHouse')->willReturn([42]);
		// Same task ID appears once in the distinct-completed list (mapper
		// already dedupes via GROUP BY; HouseService also unions safely).
		$this->completedTaskMapper->method('findCompletedRoomTaskIdsBetweenByHouse')->willReturn([42]);
		$this->rewardConfigService->method('getWeeklyGoalTargetPercent')->willReturn(80);

		$result = $this->service->getWeeklyGoalProgress(1);

		$this->assertSame(1, $result['dueCount']);
		$this->assertSame(1, $result['completedCount']);
		$this->assertSame(100, $result['percent']);
	}

	/**
	 * GetWeeklyGoalProgress_OverdueCompletedThisWeek_CountsAsCompleted
	 *
	 * An overdue task (next_due in a prior week, not active+due this week)
	 * completed today must still be in both numerator and denominator — the
	 * UNION of completedIds ensures it counts as planned work for this week.
	 */
	public function testGetWeeklyGoalProgress_OverdueCompletedThisWeek_CountsAsCompleted(): void {
		$this->roomTaskMapper->method('findActiveDueIdsBetweenByHouse')->willReturn([]);
		$this->completedTaskMapper->method('findCompletedRoomTaskIdsBetweenByHouse')->willReturn([7, 8]);
		$this->rewardConfigService->method('getWeeklyGoalTargetPercent')->willReturn(80);

		$result = $this->service->getWeeklyGoalProgress(1);

		// dueSet = {} ∪ {7,8} = {7,8} → 2; completedCount = 2 → 100%.
		$this->assertSame(2, $result['dueCount']);
		$this->assertSame(2, $result['completedCount']);
		$this->assertSame(100, $result['percent']);
		$this->assertTrue($result['achieved']);
	}

	/**
	 * GetWeeklyGoalProgress_RoundsToNearestPercent
	 *
	 * 3 distinct due, 1 distinct completed ≈ 33.33% → rounds to 33.
	 */
	public function testGetWeeklyGoalProgress_RoundsToNearestPercent(): void {
		$this->roomTaskMapper->method('findActiveDueIdsBetweenByHouse')->willReturn([1, 2, 3]);
		$this->completedTaskMapper->method('findCompletedRoomTaskIdsBetweenByHouse')->willReturn([1]);
		$this->rewardConfigService->method('getWeeklyGoalTargetPercent')->willReturn(80);

		$result = $this->service->getWeeklyGoalProgress(1);

		$this->assertSame(33, $result['percent']);
		$this->assertFalse($result['achieved']);
	}

	/**
	 * GetWeeklyGoalProgress_IncludesWeekBounds
	 */
	public function testGetWeeklyGoalProgress_IncludesWeekBounds(): void {
		$this->roomTaskMapper->method('findActiveDueIdsBetweenByHouse')->willReturn([]);
		$this->completedTaskMapper->method('findCompletedRoomTaskIdsBetweenByHouse')->willReturn([]);
		$this->rewardConfigService->method('getWeeklyGoalTargetPercent')->willReturn(80);

		$result = $this->service->getWeeklyGoalProgress(1);

		$this->assertArrayHasKey('weekStart', $result);
		$this->assertArrayHasKey('weekEnd', $result);
		$this->assertNotEmpty($result['weekStart']);
		$this->assertNotEmpty($result['weekEnd']);

		// weekStart must be a Monday (format 'N' = 1).
		$monday = new \DateTimeImmutable($result['weekStart']);
		$this->assertSame(1, (int)$monday->format('N'));
		// weekEnd must be a Sunday (format 'N' = 7) and 6 days after Monday.
		$sunday = new \DateTimeImmutable($result['weekEnd']);
		$this->assertSame(7, (int)$sunday->format('N'));
		$this->assertSame(6, (int)$monday->diff($sunday)->days);
	}

	/**
	 * RemoveHouseMember_CleansUpAssignmentsAndDeletesRow_InOneTransaction
	 *
	 * CR-SVC-26: the removal is an aggregate transaction — clear the legacy
	 * assignee column, delete join-table assignee rows (both scoped to the
	 * member's house), then delete the house_users row.
	 */
	public function testRemoveHouseMember_CleansUpAssignmentsAndDeletesRow_InOneTransaction(): void {
		$member = new HouseUser();
		$member->setId(11);
		$member->setHouseId(3);
		$member->setNextcloudUserId('member-nc');
		$member->setRole('member');

		$this->houseUserMapper->expects($this->once())
			->method('find')
			->with(11)
			->willReturn($member);

		$this->roomTaskMapper->expects($this->once())
			->method('clearAssignedUserForHouse')
			->with('member-nc', 3);

		$this->taskAssigneeMapper->expects($this->once())
			->method('deleteByUserForHouse')
			->with('member-nc', 3);

		$this->houseUserMapper->expects($this->once())
			->method('delete')
			->with($member);

		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('commit');
		$this->db->expects($this->never())->method('rollBack');

		$this->service->removeHouseMember(11);
	}

	/**
	 * RemoveHouseMember_OwnerTarget_ThrowsForbiddenException
	 *
	 * The API maps this to its CANNOT_REMOVE_OWNER 403; the service guard
	 * keeps the invariant even for direct (non-controller) callers.
	 */
	public function testRemoveHouseMember_OwnerTarget_ThrowsForbiddenException(): void {
		$owner = new HouseUser();
		$owner->setId(11);
		$owner->setHouseId(3);
		$owner->setNextcloudUserId('owner-nc');
		$owner->setRole('owner');

		$this->houseUserMapper->expects($this->once())
			->method('find')
			->with(11)
			->willReturn($owner);

		$this->db->expects($this->never())->method('beginTransaction');
		$this->houseUserMapper->expects($this->never())->method('delete');
		$this->roomTaskMapper->expects($this->never())->method('clearAssignedUserForHouse');
		$this->taskAssigneeMapper->expects($this->never())->method('deleteByUserForHouse');

		$this->expectException(ForbiddenException::class);
		$this->expectExceptionMessage('Cannot remove the house owner');

		$this->service->removeHouseMember(11);
	}

	/**
	 * RemoveHouseMember_CleanupFailure_RollsBackAndReThrows
	 *
	 * A failure between the assignee cleanup and the membership delete must
	 * roll the whole aggregate back — no partially-removed member.
	 */
	public function testRemoveHouseMember_CleanupFailure_RollsBackAndReThrows(): void {
		$member = new HouseUser();
		$member->setId(11);
		$member->setHouseId(3);
		$member->setNextcloudUserId('member-nc');
		$member->setRole('member');

		$this->houseUserMapper->method('find')->willReturn($member);
		$this->roomTaskMapper->method('clearAssignedUserForHouse')
			->willThrowException(new \RuntimeException('db down'));

		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('rollBack');
		$this->db->expects($this->never())->method('commit');
		$this->houseUserMapper->expects($this->never())->method('delete');

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('db down');

		$this->service->removeHouseMember(11);
	}

	/**
	 * RemoveHouseMember_UnknownTarget_ThrowsDoesNotExist
	 */
	public function testRemoveHouseMember_UnknownTarget_ThrowsDoesNotExist(): void {
		$this->houseUserMapper->expects($this->once())
			->method('find')
			->with(999)
			->willThrowException(new DoesNotExistException('not found'));

		$this->db->expects($this->never())->method('beginTransaction');

		$this->expectException(DoesNotExistException::class);

		$this->service->removeHouseMember(999);
	}
}
