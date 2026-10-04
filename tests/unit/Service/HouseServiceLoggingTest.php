<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\CompletedTaskMapper;
use OCA\ChoreStrike\Db\FloorMapper;
use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\RoomMapper;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\TaskAssigneeMapper;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\CalendarService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\RewardConfigService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Logging-focused tests for HouseService.
 */
class HouseServiceLoggingTest extends TestCase {
	/**
	 * GetOrCreateHouse_NewHouse_LogsCreation.
	 */
	public function testGetOrCreateHouse_NewHouse_LogsCreation(): void {
		$houseMapper = $this->createMock(HouseMapper::class);
		$floorMapper = $this->createMock(FloorMapper::class);
		$roomMapper = $this->createMock(RoomMapper::class);
		$roomTaskMapper = $this->createMock(RoomTaskMapper::class);
		$completedTaskMapper = $this->createMock(CompletedTaskMapper::class);
		$houseUserMapper = $this->createMock(HouseUserMapper::class);
		$taskAssigneeMapper = $this->createMock(TaskAssigneeMapper::class);
		$rewardConfigService = $this->createMock(RewardConfigService::class);
		$config = $this->createMock(IConfig::class);
		$db = $this->createMock(IDBConnection::class);
		$appLogger = $this->createMock(AppLogger::class);

		$house = new House();
		$house->setId(5);
		$house->setName('Family Home');

		$houseMapper->expects($this->once())
			->method('findByInstanceId')
			->with('instance-1')
			->willThrowException(new DoesNotExistException('Not found'));

		$appLogger->expects($this->once())
			->method('info')
			->with(
				'Creating house for instance',
				$this->callback(function (array $context): bool {
					// Log hygiene (CS-PLAN-061 §3.6): the user-authored house
					// name no longer enters the log context — stable ids only.
					return $context['service'] === 'HouseService'
						&& $context['instanceId'] === 'instance-1'
						&& !array_key_exists('houseName', $context);
				})
			);

		$houseMapper->expects($this->once())
			->method('createForInstance')
			->with('instance-1', 'Family Home', $this->isType('string'))
			->willReturn($house);

		$service = new HouseService(
			$houseMapper,
			$floorMapper,
			$roomMapper,
			$roomTaskMapper,
			$completedTaskMapper,
			$houseUserMapper,
			$taskAssigneeMapper,
			$this->createMock(\OCA\ChoreStrike\Service\WalletService::class),
			$rewardConfigService,
			$this->createMock(\OCA\ChoreStrike\Service\OccurrenceService::class),
			$this->createMock(\OCA\ChoreStrike\Service\PauseService::class),
			$this->createMock(\OCA\ChoreStrike\Service\RoomReplayService::class),
			$this->createMock(\OCA\ChoreStrike\Service\CommandService::class),
			$this->createMock(\OCA\ChoreStrike\Service\WeeklyGoalService::class),
			$this->createMock(\OCA\ChoreStrike\Db\MemberPreferenceMapper::class),
			$this->createMock(\OCA\ChoreStrike\Db\DigestSlotMapper::class),
			$this->createMock(\OCA\ChoreStrike\Db\DigestChangeMapper::class),
			$this->createMock(\OCA\ChoreStrike\Service\DigestScheduleService::class),
			$config,
			new \OCA\ChoreStrike\Db\TransactionExecutor($db),
			$this->createIdentityL10n(),
			$appLogger,
			$this->createMock(IUserSession::class),
			new CalendarService(),
			$this->createMock(AuthorizationService::class),
			$this->createMock(\OCP\IUserManager::class),
			$this->createMock(\OCA\ChoreStrike\Service\RewardService::class),
			$this->createMock(\OCA\ChoreStrike\Service\RewardRequestService::class)
		);

		$result = $service->getOrCreateHouse('instance-1', 'Family Home');

		$this->assertSame($house, $result);
	}

	private function createIdentityL10n(): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text) => $text);
		return $l10n;
	}

	/**
	 * CS-PLAN-081 H3/Q-081-03: the membership audit events log success only
	 * AFTER the transaction committed, carry the authoritative numeric
	 * actor/member context and distinguish no-op re-invites/replays from new
	 * mutations. Denials and rollbacks must not produce the success event.
	 *
	 * @dataProvider membershipAuditProvider
	 */
	public function testMembershipAuditEvents_PostCommitMinimalContext(string $method, array $args, string $event, string $expectedOutcome, ?string $denialMessage): void {
		$db = $this->createMock(IDBConnection::class);
		$appLogger = $this->createMock(AppLogger::class);
		$houseUserMapper = $this->createMock(HouseUserMapper::class);
		$authorization = $this->createMock(AuthorizationService::class);
		$userManager = $this->createMock(\OCP\IUserManager::class);
		$committed = false;
		$db->method('commit')->willReturnCallback(static function () use (&$committed): void {
			$committed = true;
		});

		$actor = new \OCA\ChoreStrike\Db\HouseUser();
		$actor->setId(7);
		if ($denialMessage !== null) {
			// Denial path: the success event must never fire.
			$appLogger->expects($this->never())->method('audit');
			if ($method === 'inviteMember') {
				// Non-owner denial happens before the account lookup.
				$authorization->method('requireHouseOwner')->willThrowException(
					new \OCA\ChoreStrike\Exception\ForbiddenException('Owner role required')
				);
			} elseif ($method === 'removeHouseMember') {
				$houseUserMapper->method('find')->willThrowException(new DoesNotExistException('not found'));
			}
		} else {
			$authorization->method('requireHouseOwner')->willReturn($actor);
			$authorization->method('requireCurrentUserId')->willReturn('owner-nc');
			$appLogger->expects($this->once())
				->method('audit')
				->with(
					$this->anything(),
					$this->callback(static function (array $context) use ($event, $expectedOutcome, &$committed): bool {
						// Minimal numeric context: no email, uid, display name
						// or payload — only stable ids, the role and outcome.
						return $committed && ($context['event'] ?? null) === $event
							&& ($context['outcome'] ?? null) === $expectedOutcome
							&& ($context['actorId'] ?? null) === 7
							&& is_int($context['memberId'] ?? null)
							&& !array_key_exists('email', $context)
							&& !array_key_exists('userId', $context)
							&& !array_key_exists('displayName', $context);
					})
				);

			if ($method === 'inviteMember') {
				$ncUser = $this->createMock(\OCP\IUser::class);
				$ncUser->method('getUID')->willReturn('invited-nc');
				$ncUser->method('getDisplayName')->willReturn('Invited Member');
				$userManager->method('getByEmail')->willReturn([$ncUser]);
				$houseUserMapper->method('findByHouseAndUser')->willReturn(
					$expectedOutcome === 'already-member' ? new \OCA\ChoreStrike\Db\HouseUser() : null
				);
				$member = new \OCA\ChoreStrike\Db\HouseUser();
				$member->setId(21);
				$member->setRole('member');
				$houseUserMapper->method('createOrGet')->willReturn($member);
			} else {
				$member = new \OCA\ChoreStrike\Db\HouseUser();
				$member->setId(21);
				$member->setHouseId(3);
				$member->setNextcloudUserId('member-nc');
				$member->setRole('member');
				$houseUserMapper->method('find')->willReturn($member);
				$houseUserMapper->method('findForUpdate')->willReturn($member);
				$houseUserMapper->method('update')->willReturnCallback(static fn ($u) => $u);
			}
		}

		$service = $this->buildHouseService($db, $appLogger, $houseUserMapper, $authorization, $userManager);

		if ($denialMessage !== null) {
			$this->expectException(\Exception::class);
		}
		$service->{$method}(...$args);
	}

	/**
	 * @return array<string, array{0: string, 1: array, 2: string, 3: string, 4: ?string}>
	 */
	public static function membershipAuditProvider(): array {
		return [
			'invite new member' => ['inviteMember', [3, 'invited@example.com', 'member'], 'house.member.invited', 'invited', null],
			're-invite no-op' => ['inviteMember', [3, 'invited@example.com', 'member'], 'house.member.invited', 'already-member', null],
			'remove member' => ['removeHouseMember', [21], 'house.member.removed', 'removed', null],
			'role changed' => ['updateUserRole', [21, 'child'], 'house.member.role_changed', 'changed', null],
			'invite denied, no success log' => ['inviteMember', [3, 'probe@example.com', 'member'], 'house.member.invited', 'invited', 'Owner role required'],
			'remove unknown target, no success log' => ['removeHouseMember', [999], 'house.member.removed', 'removed', 'not found'],
		];
	}

	/**
	 * UpdateHouse_LogsPostCommitSettingsEvent (CS-PLAN-081 H3/H-T6).
	 */
	public function testUpdateHouse_LogsPostCommitSettingsEvent(): void {
		$db = $this->createMock(IDBConnection::class);
		$appLogger = $this->createMock(AppLogger::class);
		$houseUserMapper = $this->createMock(HouseUserMapper::class);
		$authorization = $this->createMock(AuthorizationService::class);

		$actor = new \OCA\ChoreStrike\Db\HouseUser();
		$actor->setId(9);
		$authorization->method('requireHouseOwner')->willReturn($actor);

		$appLogger->expects($this->once())
			->method('audit')
			->with(
				'House settings update completed',
				$this->callback(static fn (array $context): bool => ($context['event'] ?? null) === 'house.settings.updated'
					&& ($context['houseId'] ?? null) === 4
					&& ($context['actorId'] ?? null) === 9
					&& ($context['outcome'] ?? null) === 'changed'
					&& !array_key_exists('houseName', $context))
			);

		$service = $this->buildHouseService($db, $appLogger, $houseUserMapper, $authorization, $this->createMock(\OCP\IUserManager::class));

		$service->updateHouse(4, 'Renamed');
	}
	public function testUpdateHouse_DemotedUnderLock_DeniesWithoutCommitOrAudit(): void {
		$db = $this->createMock(IDBConnection::class);
		$logger = $this->createMock(AppLogger::class);
		$authorization = $this->createMock(AuthorizationService::class);
		$checks = 0;
		$authorization->expects($this->exactly(2))->method('requireHouseOwner')->willReturnCallback(
			static function () use (&$checks): \OCA\ChoreStrike\Db\HouseUser {
				if (++$checks === 2) {
					throw new \OCA\ChoreStrike\Exception\ForbiddenException('Owner role required');
				}
				return new \OCA\ChoreStrike\Db\HouseUser();
			}
		);
		$logger->expects($this->never())->method('audit');
		$db->expects($this->never())->method('commit');
		$db->expects($this->once())->method('rollBack');
		$service = $this->buildHouseService($db, $logger, $this->createMock(HouseUserMapper::class), $authorization, $this->createMock(\OCP\IUserManager::class));
		$this->expectException(\OCA\ChoreStrike\Exception\ForbiddenException::class);
		$service->updateHouse(4, 'Renamed');
	}

	public function testUpdateHouse_UnchangedValues_ReportsNoChange(): void {
		$db = $this->createMock(IDBConnection::class);
		$logger = $this->createMock(AppLogger::class);
		$authorization = $this->createMock(AuthorizationService::class);
		$authorization->method('requireHouseOwner')->willReturn(new \OCA\ChoreStrike\Db\HouseUser());
		$logger->expects($this->once())->method('audit')->with($this->anything(),
			$this->callback(static fn (array $context): bool => $context['outcome'] === 'no-change'));
		$service = $this->buildHouseService($db, $logger, $this->createMock(HouseUserMapper::class), $authorization, $this->createMock(\OCP\IUserManager::class));
		$service->updateHouse(4, 'Existing house');
	}

	private function buildHouseService(IDBConnection $db, AppLogger $appLogger, HouseUserMapper $houseUserMapper, AuthorizationService $authorization, \OCP\IUserManager $userManager): HouseService {
		$houseMapper = $this->createMock(HouseMapper::class);
		$houseMapper->method('findForUpdate')->willReturnCallback(static function (int $id): House {
			$house = new House();
			$house->setId($id);
			$house->setName('Existing house');
			$house->setTimezone('UTC');
			return $house;
		});
		$houseMapper->method('update')->willReturnCallback(static fn (House $house): House => $house);
		return new HouseService(
			$houseMapper,
			$this->createMock(FloorMapper::class),
			$this->createMock(RoomMapper::class),
			$this->createMock(RoomTaskMapper::class),
			$this->createMock(CompletedTaskMapper::class),
			$houseUserMapper,
			$this->createMock(TaskAssigneeMapper::class),
			$this->createMock(\OCA\ChoreStrike\Service\WalletService::class),
			$this->createMock(RewardConfigService::class),
			$this->createMock(\OCA\ChoreStrike\Service\OccurrenceService::class),
			$this->createMock(\OCA\ChoreStrike\Service\PauseService::class),
			$this->createMock(\OCA\ChoreStrike\Service\RoomReplayService::class),
			$this->createMock(\OCA\ChoreStrike\Service\CommandService::class),
			$this->createMock(\OCA\ChoreStrike\Service\WeeklyGoalService::class),
			$this->createMock(\OCA\ChoreStrike\Db\MemberPreferenceMapper::class),
			$this->createMock(\OCA\ChoreStrike\Db\DigestSlotMapper::class),
			$this->createMock(\OCA\ChoreStrike\Db\DigestChangeMapper::class),
			$this->createMock(\OCA\ChoreStrike\Service\DigestScheduleService::class),
			$this->createMock(IConfig::class),
			new \OCA\ChoreStrike\Db\TransactionExecutor($db),
			$this->createIdentityL10n(),
			$appLogger,
			$this->createMock(IUserSession::class),
			new CalendarService(),
			$authorization,
			$userManager,
			$this->createMock(\OCA\ChoreStrike\Service\RewardService::class),
			$this->createMock(\OCA\ChoreStrike\Service\RewardRequestService::class)
		);
	}
}
