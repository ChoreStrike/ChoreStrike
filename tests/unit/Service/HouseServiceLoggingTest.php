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
			$config,
			new \OCA\ChoreStrike\Db\TransactionExecutor($db),
			$this->createIdentityL10n(),
			$appLogger,
			$this->createMock(IUserSession::class),
			new CalendarService(),
			$this->createMock(AuthorizationService::class),
			$this->createMock(\OCP\IUserManager::class)
		);

		$result = $service->getOrCreateHouse('instance-1', 'Family Home');

		$this->assertSame($house, $result);
	}

	private function createIdentityL10n(): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text) => $text);
		return $l10n;
	}
}
