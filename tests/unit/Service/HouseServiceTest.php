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
use OCA\ChoreStrike\Exception\ConflictException;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\CalendarService;
use OCA\ChoreStrike\Service\CommandService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\RewardConfigService;
use OCA\ChoreStrike\Service\RoomReplayService;
use OCA\ChoreStrike\Service\WeeklyGoalService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserSession;
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
	private RoomReplayService $roomReplayService;
	private CommandService $commandService;
	private WeeklyGoalService $weeklyGoalService;
	private IConfig $config;
	private IDBConnection $db;
	private IUserSession $userSession;
	private AuthorizationService $authorization;
	private \OCP\IUserManager $userManager;
	private \OCA\ChoreStrike\Service\RewardService $rewardService;
	private \OCA\ChoreStrike\Service\RewardRequestService $rewardRequestService;
	/** House returned by houseMapper->find (swappable per test). */
	private House $defaultHouse;

	protected function setUp(): void {
		$this->houseMapper = $this->createMock(HouseMapper::class);
		$this->floorMapper = $this->createMock(FloorMapper::class);
		$this->roomMapper = $this->createMock(RoomMapper::class);
		$this->roomTaskMapper = $this->createMock(RoomTaskMapper::class);
		$this->completedTaskMapper = $this->createMock(CompletedTaskMapper::class);
		$this->houseUserMapper = $this->createMock(HouseUserMapper::class);
		$this->taskAssigneeMapper = $this->createMock(TaskAssigneeMapper::class);
		$this->rewardConfigService = $this->createMock(RewardConfigService::class);
		$this->roomReplayService = $this->createMock(RoomReplayService::class);
		$this->commandService = $this->createMock(CommandService::class);
		$this->commandService->method('runCommand')->willReturnCallback(static function (int $houseId, int $actorId, string $uuid, string $action, array $payload, callable $fn): array {
			return ['result' => $fn(), 'replay' => false];
		});
		$this->weeklyGoalService = $this->createMock(WeeklyGoalService::class);
		$this->config = $this->createMock(IConfig::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->authorization = $this->createMock(AuthorizationService::class);
		$this->userManager = $this->createMock(\OCP\IUserManager::class);
		$this->rewardService = $this->createMock(\OCA\ChoreStrike\Service\RewardService::class);
		$this->rewardRequestService = $this->createMock(\OCA\ChoreStrike\Service\RewardRequestService::class);
		// Identity mock: message assertions match the wrapped source text.
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text) => $text);

		// CS-PLAN-057: day/week lookups resolve the house timezone — default
		// to a UTC house; timezone-specific tests swap the property value.
		$this->defaultHouse = new House();
		$this->defaultHouse->setTimezone('UTC');
		$this->houseMapper->method('find')->willReturnCallback(fn (int $id) => $this->defaultHouse);
		// CS-PLAN-071 WP0: house-first writers enter through findForUpdate.
		$this->houseMapper->method('findForUpdate')->willReturnCallback(fn (int $id) => $this->defaultHouse);

		// CS-PLAN-071 WP0: membership writes ensure the wallet binding via
		// the shared WalletService — identity on update.
		$walletService = $this->createMock(\OCA\ChoreStrike\Service\WalletService::class);
		$walletService->method('ensureBinding')->willReturnCallback(static fn (int $houseId, $member) => $member);
		$this->houseUserMapper->method('update')->willReturnCallback(static fn ($u) => $u);

		$this->service = new HouseService(
			$this->houseMapper,
			$this->floorMapper,
			$this->roomMapper,
			$this->roomTaskMapper,
			$this->completedTaskMapper,
			$this->houseUserMapper,
			$this->taskAssigneeMapper,
			$walletService,
			$this->rewardConfigService,
			$this->createMock(\OCA\ChoreStrike\Service\OccurrenceService::class),
			$this->createMock(\OCA\ChoreStrike\Service\PauseService::class),
			$this->roomReplayService,
			$this->commandService,
			$this->weeklyGoalService,
			$this->createMock(\OCA\ChoreStrike\Db\MemberPreferenceMapper::class),
			$this->createMock(\OCA\ChoreStrike\Db\DigestSlotMapper::class),
			$this->createMock(\OCA\ChoreStrike\Db\DigestChangeMapper::class),
			$this->createMock(\OCA\ChoreStrike\Service\DigestScheduleService::class),
			$this->config,
			new \OCA\ChoreStrike\Db\TransactionExecutor($this->db),
			$l10n,
			$this->createMock(AppLogger::class),
			$this->userSession,
			new CalendarService(),
			$this->authorization,
			$this->userManager,
			$this->rewardService,
			$this->rewardRequestService
		);
	}

	/**
	 * Build the service with a custom l10n (localized-default test).
	 */
	private function serviceWithL10n(IL10N $l10n): HouseService {
		return new HouseService(
			$this->houseMapper,
			$this->floorMapper,
			$this->roomMapper,
			$this->roomTaskMapper,
			$this->completedTaskMapper,
			$this->houseUserMapper,
			$this->taskAssigneeMapper,
			$this->createMock(\OCA\ChoreStrike\Service\WalletService::class),
			$this->rewardConfigService,
			$this->createMock(\OCA\ChoreStrike\Service\OccurrenceService::class),
			$this->createMock(\OCA\ChoreStrike\Service\PauseService::class),
			$this->roomReplayService,
			$this->commandService,
			$this->weeklyGoalService,
			$this->createMock(\OCA\ChoreStrike\Db\MemberPreferenceMapper::class),
			$this->createMock(\OCA\ChoreStrike\Db\DigestSlotMapper::class),
			$this->createMock(\OCA\ChoreStrike\Db\DigestChangeMapper::class),
			$this->createMock(\OCA\ChoreStrike\Service\DigestScheduleService::class),
			$this->config,
			new \OCA\ChoreStrike\Db\TransactionExecutor($this->db),
			$l10n,
			$this->createMock(AppLogger::class),
			$this->userSession,
			new CalendarService(),
			$this->authorization,
			$this->userManager,
			$this->createMock(\OCA\ChoreStrike\Service\RewardService::class),
			$this->createMock(\OCA\ChoreStrike\Service\RewardRequestService::class)
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
			->with($instanceId, $houseName, $this->isType('string'))
			->willReturn($house);

		$result = $this->service->getOrCreateHouse($instanceId, $houseName);

		$this->assertSame($house, $result);
		$this->assertEquals($houseName, $result->getName());
	}

	/**
	 * GetOrCreateHouse_NewHouseWithoutName_UsesLocalizedDefault
	 */
	public function testGetOrCreateHouse_NewHouseWithoutName_UsesLocalizedDefault(): void {
		$instanceId = 'instance123';
		$localizedName = 'Mein Zuhause';
		$house = new House();
		$house->setId(1);
		$house->setName($localizedName);

		$l10n = $this->createMock(IL10N::class);
		$l10n->expects($this->once())
			->method('t')
			->with('My House')
			->willReturn($localizedName);

		$this->houseMapper
			->expects($this->once())
			->method('findByInstanceId')
			->with($instanceId)
			->willThrowException(new DoesNotExistException('House not found'));

		$this->houseMapper
			->expects($this->once())
			->method('createForInstance')
			->with($instanceId, $localizedName, $this->isType('string'))
			->willReturn($house);

		$service = $this->serviceWithL10n($l10n);

		$result = $service->getOrCreateHouse($instanceId);

		$this->assertSame($house, $result);
		$this->assertSame($localizedName, $result->getName());
	}

	/**
	 * GetOrCreateHouse_NewHouse_CapturesCreatorTimezone
	 *
	 * CS-PLAN-057 D1: a fresh house captures the creator's Nextcloud
	 * timezone (`core.timezone` user value) instead of a bare UTC default.
	 */
	public function testGetOrCreateHouse_NewHouse_CapturesCreatorTimezone(): void {
		$instanceId = 'instance123';
		$houseName = 'My House';

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);
		$this->config->method('getUserValue')
			->with('alice', 'core', 'timezone', '')
			->willReturn('Europe/Berlin');

		$house = new House();
		$house->setId(1);
		$house->setName($houseName);

		$this->houseMapper
			->expects($this->once())
			->method('findByInstanceId')
			->willThrowException(new DoesNotExistException('House not found'));

		$this->houseMapper
			->expects($this->once())
			->method('createForInstance')
			->with($instanceId, $houseName, 'Europe/Berlin')
			->willReturn($house);

		$result = $this->service->getOrCreateHouse($instanceId, $houseName);

		$this->assertSame($house, $result);
	}

	/**
	 * UpdateHouse_ValidName_ReturnsUpdatedHouse
	 */
	public function testUpdateHouse_ValidName_ReturnsUpdatedHouse(): void {
		$houseId = 1;
		$newName = 'Updated House';

		$house = new House();
		$house->setId($houseId);
		$house->setName('Old Name');
		$house->setTimezone('UTC');
		$this->defaultHouse = $house;

		$updatedHouse = new House();
		$updatedHouse->setId($houseId);
		$updatedHouse->setName($newName);
		$updatedHouse->setTimezone('UTC');

		$this->houseMapper
			->expects($this->once())
			->method('update')
			->with($this->callback(function ($h) use ($newName) {
				return $h->getName() === $newName;
			}))
			->willReturn($updatedHouse);

		$result = $this->service->updateHouse($houseId, $newName);

		$this->assertEquals($newName, $result->getName());
		$this->assertSame('UTC', $result->getTimezone());
	}

	/**
	 * UpdateHouse_WithTimezone_PersistsTimezone
	 *
	 * CS-PLAN-057: the owner can change the house timezone through the
	 * update path.
	 */
	public function testUpdateHouse_WithTimezone_PersistsTimezone(): void {
		$houseId = 1;

		$house = new House();
		$house->setId($houseId);
		$house->setName('My House');
		$house->setTimezone('UTC');
		$this->defaultHouse = $house;

		// CS-PLAN-071 (D7): an actual timezone change bumps timezone_version.
		$this->houseMapper
			->expects($this->once())
			->method('update')
			->with($this->callback(function ($h) {
				return $h->getTimezone() === 'Pacific/Auckland'
					&& $h->getTimezoneVersion() === 1;
			}))
			->willReturn($house);

		$result = $this->service->updateHouse($houseId, 'My House', 'Pacific/Auckland');

		$this->assertSame('Pacific/Auckland', $result->getTimezone());
	}

	/**
	 * UpdateHouse_NonOwner_ThrowsForbiddenWithoutFinding
	 *
	 * CS-PLAN-061: updateHouse is owner-only in the service; the
	 * authorization gate runs before any house fetch or write.
	 */
	public function testUpdateHouse_NonOwner_ThrowsForbiddenWithoutFinding(): void {
		$this->authorization->expects($this->once())
			->method('requireHouseOwner')
			->with(1)
			->willThrowException(new ForbiddenException('Owner role required'));

		$this->houseMapper->expects($this->never())->method('find');
		$this->houseMapper->expects($this->never())->method('update');

		$this->expectException(ForbiddenException::class);
		$this->expectExceptionMessage('Owner role required');

		$this->service->updateHouse(1, 'New Name');
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
		$this->defaultHouse = $house;

		$floor = new Floor();
		$floor->setId(1);

		$cleanRoom = new Room();
		$cleanRoom->setId(1);
		$cleanRoom->setCleanliness(70);

		$dirtyRoom = new Room();
		$dirtyRoom->setId(2);
		$dirtyRoom->setCleanliness(40);

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
	 * ResetHouse_JournalsOneResetPerLiveRoomAndRebuildsCaches
	 *
	 * CS-PLAN-070 WP3 (§3.5): the production reset journals ONE absolute
	 * reset event per LIVE room (idempotent through the command UUID) and
	 * rebuilds each room cache by the chronological replay; the house
	 * health returns to 100 and the state version bumps. Tombstoned rooms
	 * are not reset; no bulk `resetByHouseId` write exists anymore.
	 */
	public function testResetHouse_JournalsOneResetPerLiveRoomAndRebuildsCaches(): void {
		$houseId = 1;

		$house = new House();
		$house->setId($houseId);
		$house->setHouseHealth(50);
		$house->setStateVersion(7);
		$this->defaultHouse = $house;
		$owner = new HouseUser();
		$owner->setId(3);

		$liveRoom = new Room();
		$liveRoom->setId(11);
		$liveRoom->setCleanliness(40);

		$tombstoned = new Room();
		$tombstoned->setId(12);
		$tombstoned->setDeletedAt(new \DateTime());

		// RF-058-02: the owner gate runs service-side before any write.
		$this->authorization
			->expects($this->exactly(2))
			->method('requireHouseOwner')
			->with($houseId)
			->willReturn($owner);
		$this->commandService->expects($this->once())
			->method('runCommand')
			->with($houseId, 3, 'cmd-reset-1', 'house.reset', ['expectedVersion' => null], $this->isType('callable'));

		$this->roomMapper
			->expects($this->once())
			->method('findByHouseId')
			->with($houseId)
			->willReturn([$liveRoom, $tombstoned]);

		$this->roomReplayService
			->expects($this->once())
			->method('recordReset')
			->with(11, 'cmd-reset-1', $this->isInstanceOf(\DateTime::class));
		$this->roomReplayService
			->expects($this->once())
			->method('resyncRoomCache')
			->with($liveRoom)
			->willReturn($liveRoom);

		// The tombstoned room is never journaled or resynced.
		$this->roomReplayService->expects($this->never())->method('recordBaseline');
		$this->roomReplayService->expects($this->never())->method('recordCompletion');

		$this->houseMapper
			->expects($this->once())
			->method('update')
			->with($this->callback(function ($h) {
				return $h->getHouseHealth() === 100 && $h->getStateVersion() === 8;
			}))
			->willReturnCallback(static fn ($h) => $h);

		$this->roomMapper->expects($this->never())->method('resetByHouseId');
		$this->roomMapper->expects($this->never())->method('update');

		$this->service->resetHouse($houseId, 'cmd-reset-1');
	}

	/**
	 * ResetHouse_StaleExpectedVersion_ThrowsConflictWithoutAnyWrite
	 *
	 * §3.6: `expectedVersion` guards against resetting over a state the
	 * caller has not seen (stale → 409, nothing journaled).
	 */
	public function testResetHouse_StaleExpectedVersion_ThrowsConflictWithoutAnyWrite(): void {
		$house = new House();
		$house->setId(1);
		$house->setStateVersion(9);
		$this->defaultHouse = $house;
		$owner = new HouseUser();
		$owner->setId(3);

		$this->authorization->method('requireHouseOwner')->willReturn($owner);
		$this->roomReplayService->expects($this->never())->method('recordReset');
		$this->houseMapper->expects($this->never())->method('update');

		$this->expectException(ConflictException::class);

		$this->service->resetHouse(1, 'cmd-reset-stale', 8);
	}

	/**
	 * ResetHouse_NonOwner_ThrowsForbiddenWithoutAnyWrite (RF-058-02)
	 */
	public function testResetHouse_NonOwner_ThrowsForbiddenWithoutAnyWrite(): void {
		$houseId = 1;
		$this->authorization
			->method('requireHouseOwner')
			->willThrowException(new \OCA\ChoreStrike\Exception\ForbiddenException('Owner role required'));

		$this->houseMapper->expects($this->never())->method('update');
		$this->roomReplayService->expects($this->never())->method('recordReset');

		$this->expectException(\OCA\ChoreStrike\Exception\ForbiddenException::class);
		$this->service->resetHouse($houseId, 'cmd-reset-1');
	}

	public function testResetHouse_OwnerDemotedBeforeHouseLock_DeniedWithoutJournal(): void {
		$owner = new HouseUser();
		$owner->setId(3);
		$checks = 0;
		$this->authorization->method('requireHouseOwner')->willReturnCallback(
			static function () use ($owner, &$checks): HouseUser {
				if (++$checks > 1) {
					throw new ForbiddenException('Owner role required');
				}
				return $owner;
			}
		);
		$this->roomMapper->expects($this->never())->method('findByHouseId');
		$this->houseMapper->expects($this->never())->method('update');

		$this->expectException(ForbiddenException::class);
		$this->service->resetHouse(1, 'cmd-demoted');
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
	 * GetWeeklyGoalProgress_DelegatesToWeeklyGoalService
	 *
	 * WP3 (Q-074-01A): the evaluation moved to WeeklyGoalService
	 * (correction-safe by construction — valid completion input). The
	 * delegating method stays for the controller/post-commit consumers;
	 * the goal semantics themselves are pinned in WeeklyGoalServiceTest.
	 */
	public function testGetWeeklyGoalProgress_DelegatesToWeeklyGoalService(): void {
		$payload = [
			'dueCount' => 5,
			'completedCount' => 4,
			'percent' => 80,
			'targetPercent' => 80,
			'achieved' => true,
			'weekStart' => '2026-09-21T00:00:00+00:00',
			'weekEnd' => '2026-09-28T00:00:00+00:00',
		];

		$this->weeklyGoalService
			->expects($this->once())
			->method('getWeeklyGoalProgress')
			->with(1)
			->willReturn($payload);

		$this->assertSame($payload, $this->service->getWeeklyGoalProgress(1));
	}

	/**
	 * RemoveHouseMember_CleansUpAssignmentsAndDeletesRow_InOneTransaction
	 *
	 * CR-SVC-26: the removal is an aggregate transaction — delete the
	 * member's join-table assignee rows (scoped to the member's house,
	 * CS-PLAN-060 WP1 D2), then delete the house_users row.
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

		// The invariant is re-checked on the LOCKED row inside the
		// transaction — the same instance comes back.
		$this->houseUserMapper->expects($this->once())
			->method('findForUpdate')
			->with(11)
			->willReturn($member);

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
		$this->taskAssigneeMapper->method('deleteByUserForHouse')
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

	/**
	 * Build a HouseUser for the role-guard tests (BUG-SEC-002). Also stubs
	 * the under-lock re-read (CS-PLAN-071: updateUserRole re-resolves the
	 * membership via findForUpdate inside the house-first transaction).
	 */
	private function houseUserForRoleGuard(int $id, string $ncUserId, string $role): HouseUser {
		$houseUser = new HouseUser();
		$houseUser->setId($id);
		$houseUser->setHouseId(3);
		$houseUser->setNextcloudUserId($ncUserId);
		$houseUser->setRole($role);
		$this->houseUserMapper->method('findForUpdate')->willReturn($houseUser);
		return $houseUser;
	}

	/**
	 * UpdateUserRole_InvalidRole_ThrowsInvalidArgument
	 *
	 * The whitelist check moved from the controller (BUG-SEC-002); the API
	 * maps this to its VALIDATION_ERROR 400.
	 */
	public function testUpdateUserRole_InvalidRole_ThrowsInvalidArgument(): void {
		$this->houseUserMapper->expects($this->never())->method('find');
		$this->houseUserMapper->expects($this->never())->method('update');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Invalid role. Must be owner, member, or child');

		$this->service->updateUserRole(11, 'superadmin');
	}

	/**
	 * UpdateUserRole_SelfDemotion_ThrowsForbidden
	 *
	 * The API maps this to its CANNOT_DEMOTE_SELF 403; the service guard
	 * keeps the invariant even for direct (non-controller) callers.
	 */
	public function testUpdateUserRole_SelfDemotion_ThrowsForbidden(): void {
		$owner = $this->houseUserForRoleGuard(11, 'owner-nc', 'owner');

		$this->houseUserMapper->expects($this->once())
			->method('find')
			->with(11)
			->willReturn($owner);

		$this->houseUserMapper->expects($this->never())->method('update');

		// CS-PLAN-081 H3: the actor identity is the authoritative session
		// user from the authorization service, not a caller parameter.
		$this->authorization->method('requireCurrentUserId')->willReturn('owner-nc');

		$this->expectException(ForbiddenException::class);
		$this->expectExceptionMessage('Cannot demote yourself');

		$this->service->updateUserRole(11, 'member');
	}

	/**
	 * UpdateUserRole_LastOwnerDemotion_ThrowsConflict
	 *
	 * Demoting the only owner would orphan the house. The API maps this to
	 * its CANNOT_DEMOTE_LAST_OWNER 409.
	 */
	public function testUpdateUserRole_LastOwnerDemotion_ThrowsConflict(): void {
		$owner = $this->houseUserForRoleGuard(11, 'owner-nc', 'owner');

		$this->houseUserMapper->expects($this->once())
			->method('find')
			->with(11)
			->willReturn($owner);

		$this->houseUserMapper->expects($this->once())
			->method('countOwnersForHouse')
			->with(3)
			->willReturn(1);

		$this->houseUserMapper->expects($this->never())->method('update');

		$this->expectException(ConflictException::class);
		$this->expectExceptionMessage('Cannot demote the last house owner');

		$this->service->updateUserRole(11, 'member');
	}

	/**
	 * UpdateUserRole_CoOwnerDemotionWhileAnotherOwnerRemains_Allowed
	 */
	public function testUpdateUserRole_CoOwnerDemotionWhileAnotherOwnerRemains_Allowed(): void {
		$coOwner = $this->houseUserForRoleGuard(11, 'coowner-nc', 'owner');

		$this->houseUserMapper->expects($this->once())
			->method('find')
			->with(11)
			->willReturn($coOwner);

		$this->houseUserMapper->expects($this->once())
			->method('countOwnersForHouse')
			->with(3)
			->willReturn(2);

		$this->houseUserMapper->expects($this->once())
			->method('update')
			->with($this->callback(static fn (HouseUser $u): bool => $u->getRole() === 'member'))
			->willReturnCallback(static fn (HouseUser $u): HouseUser => $u);

		$result = $this->service->updateUserRole(11, 'member');

		$this->assertSame('member', $result->getRole());
	}

	/**
	 * UpdateUserRole_ValidPromotion_Allowed
	 */
	public function testUpdateUserRole_ValidPromotion_Allowed(): void {
		$member = $this->houseUserForRoleGuard(12, 'member-nc', 'member');

		$this->houseUserMapper->expects($this->once())
			->method('find')
			->with(12)
			->willReturn($member);

		// Promotion to owner never touches the owner-count guard.
		$this->houseUserMapper->expects($this->never())->method('countOwnersForHouse');

		$this->houseUserMapper->expects($this->once())
			->method('update')
			->with($this->callback(static fn (HouseUser $u): bool => $u->getRole() === 'owner'))
			->willReturnCallback(static fn (HouseUser $u): HouseUser => $u);

		$result = $this->service->updateUserRole(12, 'owner');

		$this->assertSame('owner', $result->getRole());
	}

	/**
	 * UpdateUserRole_RealChange_AdvancesRoleRevisionAndCallsTheWP7Hook
	 *
	 * CS-PLAN-070 WP7 (CS-PLAN-078 §3.4): only a REAL role change advances
	 * the membership's role-revision provenance and rebinds the reward
	 * requests.
	 */
	public function testUpdateUserRole_RealChange_AdvancesRoleRevisionAndCallsTheWP7Hook(): void {
		$member = $this->houseUserForRoleGuard(12, 'member-nc', 'member');
		$member->setRoleRevision(2);

		$this->houseUserMapper->method('find')->willReturn($member);
		$this->houseUserMapper->method('update')->willReturnCallback(static fn (HouseUser $u): HouseUser => $u);

		// CS-PLAN-081 H3: the hook actor is the authoritative owner from the
		// service-side gate, not a findByHouseAndUser lookup on a caller id.
		$actor = new HouseUser();
		$actor->setId(7);
		$this->authorization->method('requireHouseOwner')->willReturn($actor);

		$this->rewardRequestService->expects($this->once())
			->method('handleRequesterRoleChangedUnderLock')
			->with(3, $this->identicalTo($member), 'member', 2, $actor);

		$result = $this->service->updateUserRole(12, 'child');

		$this->assertSame('child', $result->getRole());
		$this->assertSame(3, $result->getRoleRevision(), 'a real change advances the provenance counter');
	}

	/**
	 * UpdateUserRole_NoOpWrite_DoesNotAdvanceRevisionOrCallTheHook
	 *
	 * A no-op child write must never invalidate a fresh owner approval:
	 * the revision stays and the request-rebind hook never fires.
	 */
	public function testUpdateUserRole_NoOpWrite_DoesNotAdvanceRevisionOrCallTheHook(): void {
		$child = $this->houseUserForRoleGuard(12, 'child-nc', 'child');
		$child->setRoleRevision(4);

		$this->houseUserMapper->method('find')->willReturn($child);
		$this->houseUserMapper->method('update')->willReturnCallback(static fn (HouseUser $u): HouseUser => $u);

		$this->rewardRequestService->expects($this->never())->method('handleRequesterRoleChangedUnderLock');

		$result = $this->service->updateUserRole(12, 'child');

		$this->assertSame('child', $result->getRole());
		$this->assertSame(4, $result->getRoleRevision(), 'a no-op write never advances the revision');
	}

	/**
	 * RemoveHouseMember_CallsTheRewardDepartureHookBeforeAccessEnds
	 */
	public function testRemoveHouseMember_CallsTheRewardDepartureHookBeforeAccessEnds(): void {
		$membership = new HouseUser();
		$membership->setId(12);
		$membership->setHouseId(3);
		$membership->setNextcloudUserId('rm-member');
		$membership->setWalletId(44);
		$this->houseUserMapper->method('find')->willReturn($membership);
		$this->houseUserMapper->method('delete');
		$this->taskAssigneeMapper->method('deleteByUserForHouse');

		$order = [];
		// CS-PLAN-081 H3: the departure hook actor is the authoritative owner
		// from the service-side gate, never a caller parameter.
		$actor = new HouseUser();
		$actor->setId(7);
		$this->authorization->method('requireHouseOwner')->willReturn($actor);
		$this->rewardRequestService->expects($this->once())
			->method('handleMembershipRemovedUnderLock')
			->with(3, 44, $actor)
			->willReturnCallback(function () use (&$order): void {
				$order[] = 'reward';
			});
		$this->houseUserMapper->expects($this->once())
			->method('delete')
			->willReturnCallback(function () use (&$order): void {
				$order[] = 'delete';
			});

		$this->service->removeHouseMember(12);

		$this->assertSame(['reward', 'delete'], $order, 'holds are released BEFORE the membership row is removed');
	}

	/**
	 * SetCatalogEnabled_DelegatesToTheRewardService
	 */
	public function testSetCatalogEnabled_DelegatesToTheRewardService(): void {
		$this->rewardService->expects($this->once())
			->method('setCatalogEnabled')
			->with(1, true, 5, '11111111-1111-4111-8111-111111111111')
			->willReturn(['catalogEnabled' => true, 'stateVersion' => 6]);

		$result = $this->service->setCatalogEnabled(1, true, 5, '11111111-1111-4111-8111-111111111111');

		$this->assertSame(['catalogEnabled' => true, 'stateVersion' => 6], $result);
	}

	/**
	 * UpdateUserRole_SelfAssignedOwner_Allowed
	 *
	 * Assigning oneself 'owner' is a no-op promotion, not a demotion — the
	 * self-guard must not fire.
	 */
	public function testUpdateUserRole_SelfAssignedOwner_Allowed(): void {
		$member = $this->houseUserForRoleGuard(12, 'member-nc', 'member');

		$this->houseUserMapper->expects($this->once())
			->method('find')
			->with(12)
			->willReturn($member);

		$this->houseUserMapper->expects($this->once())
			->method('update')
			->willReturnCallback(static fn (HouseUser $u): HouseUser => $u);

		$result = $this->service->updateUserRole(12, 'owner');

		$this->assertSame('owner', $result->getRole());
	}

	/**
	 * UpdateUserRole_UnknownTarget_ThrowsDoesNotExist
	 */
	public function testUpdateUserRole_UnknownTarget_ThrowsDoesNotExist(): void {
		$this->houseUserMapper->expects($this->once())
			->method('find')
			->with(999)
			->willThrowException(new DoesNotExistException('not found'));

		$this->houseUserMapper->expects($this->never())->method('update');

		$this->expectException(DoesNotExistException::class);

		$this->service->updateUserRole(999, 'member');
	}

	/**
	 * InviteMember_NoAccount_ThrowsNotFoundWithoutWrite (RF-058-04)
	 */
	public function testInviteMember_NoAccount_ThrowsNotFoundWithoutWrite(): void {
		$this->userManager->method('getByEmail')->with('ghost@example.com')->willReturn([]);

		$this->houseUserMapper->expects($this->never())->method('createOrGet');

		$this->expectException(\OCA\ChoreStrike\Exception\NotFoundException::class);
		$this->service->inviteMember(1, 'ghost@example.com', 'member');
	}

	/**
	 * InviteMember_AccountFound_CreatesMembershipWithResolvedAccount (RF-058-04)
	 */
	public function testInviteMember_AccountFound_CreatesMembershipWithResolvedAccount(): void {
		$ncUser = $this->createMock(\OCP\IUser::class);
		$ncUser->method('getUID')->willReturn('invited-nc');
		$ncUser->method('getDisplayName')->willReturn('Invited Member');
		$this->userManager->method('getByEmail')->with('invited@example.com')->willReturn([$ncUser]);

		$houseUser = new HouseUser();
		$houseUser->setNextcloudUserId('invited-nc');
		$this->houseUserMapper->expects($this->once())
			->method('createOrGet')
			->with(1, 'invited-nc', 'Invited Member', 'member')
			->willReturn($houseUser);

		$result = $this->service->inviteMember(1, 'invited@example.com', 'member');

		$this->assertSame('invited-nc', $result->getNextcloudUserId());
	}

	/**
	 * InviteMember_NonOwner_ThrowsForbiddenBeforeAccountLookup (CS-PLAN-081 H3)
	 *
	 * The service-side owner gate runs BEFORE the email/account lookup, so
	 * a direct non-owner caller cannot probe which accounts exist.
	 */
	public function testInviteMember_NonOwner_ThrowsForbiddenBeforeAccountLookup(): void {
		$this->authorization->expects($this->once())
			->method('requireHouseOwner')
			->with(1)
			->willThrowException(new ForbiddenException('Owner role required'));

		$this->userManager->expects($this->never())->method('getByEmail');
		$this->houseUserMapper->expects($this->never())->method('createOrGet');

		$this->expectException(ForbiddenException::class);
		$this->expectExceptionMessage('Owner role required');

		$this->service->inviteMember(1, 'probe@example.com', 'member');
	}

	/**
	 * RemoveMember_NonOwner_ThrowsForbiddenWithoutAnyWrite (CS-PLAN-081 H3)
	 */
	public function testRemoveHouseMember_NonOwner_ThrowsForbiddenWithoutAnyWrite(): void {
		$member = new HouseUser();
		$member->setId(11);
		$member->setHouseId(3);
		$member->setNextcloudUserId('member-nc');
		$member->setRole('member');
		$this->houseUserMapper->method('find')->willReturn($member);

		$this->authorization->expects($this->once())
			->method('requireHouseOwner')
			->with(3)
			->willThrowException(new ForbiddenException('Owner role required'));

		$this->db->expects($this->never())->method('beginTransaction');
		$this->houseUserMapper->expects($this->never())->method('delete');

		$this->expectException(ForbiddenException::class);
		$this->expectExceptionMessage('Owner role required');

		$this->service->removeHouseMember(11);
	}

	/**
	 * UpdateUserRole_NonOwner_ThrowsForbiddenWithoutAnyWrite (CS-PLAN-081 H3)
	 */
	public function testUpdateUserRole_NonOwner_ThrowsForbiddenWithoutAnyWrite(): void {
		$member = new HouseUser();
		$member->setId(11);
		$member->setHouseId(3);
		$member->setNextcloudUserId('member-nc');
		$member->setRole('member');
		$this->houseUserMapper->method('find')->willReturn($member);

		$this->authorization->expects($this->once())
			->method('requireHouseOwner')
			->with(3)
			->willThrowException(new ForbiddenException('Owner role required'));

		$this->db->expects($this->never())->method('beginTransaction');
		$this->houseUserMapper->expects($this->never())->method('update');

		$this->expectException(ForbiddenException::class);
		$this->expectExceptionMessage('Owner role required');

		$this->service->updateUserRole(11, 'owner');
	}

	/**
	 * UpdateUserRole_OwnerDemotedBeforeHouseLock_Denied (CS-PLAN-081 H3)
	 *
	 * The pre-transaction owner check passes, but the re-check on the
	 * LOCKED house row must deny the write when a demotion raced in between.
	 */
	public function testUpdateUserRole_OwnerDemotedBeforeHouseLock_Denied(): void {
		$member = new HouseUser();
		$member->setId(11);
		$member->setHouseId(3);
		$member->setNextcloudUserId('member-nc');
		$member->setRole('member');
		$this->houseUserMapper->method('find')->willReturn($member);

		$this->authorization->method('requireCurrentUserId')->willReturn('owner-nc');

		$checks = 0;
		$this->authorization->method('requireHouseOwner')->willReturnCallback(
			static function () use (&$checks): HouseUser {
				if (++$checks > 1) {
					throw new ForbiddenException('Owner role required');
				}
				$actor = new HouseUser();
				$actor->setId(7);
				return $actor;
			}
		);

		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('rollBack');
		$this->houseUserMapper->expects($this->never())->method('update');

		$this->expectException(ForbiddenException::class);
		$this->expectExceptionMessage('Owner role required');

		$this->service->updateUserRole(11, 'owner');
	}

	/**
	 * FindHouseUserScopedToHouse_ForeignHouseYieldsNull (RF-058-04)
	 */
	public function testFindHouseUserScopedToHouse_ForeignHouseYieldsNull(): void {
		$foreign = new HouseUser();
		$foreign->setId(7);
		$foreign->setHouseId(2);
		$this->houseUserMapper->method('find')->with(7)->willReturn($foreign);

		$this->assertNull($this->service->findHouseUserScopedToHouse(7, 1));
	}

	/**
	 * FindHouseUserScopedToHouse_SameHouseYieldsRow (RF-058-04)
	 */
	public function testFindHouseUserScopedToHouse_SameHouseYieldsRow(): void {
		$member = new HouseUser();
		$member->setId(7);
		$member->setHouseId(1);
		$this->houseUserMapper->method('find')->with(7)->willReturn($member);

		$this->assertSame($member, $this->service->findHouseUserScopedToHouse(7, 1));
	}
}
