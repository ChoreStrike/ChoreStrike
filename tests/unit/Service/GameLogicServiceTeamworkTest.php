<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\CompletedTaskMapper;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\MedalMapper;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Service\GameLogicService;
use OCA\ChoreStrike\Service\NotificationService;
use OCA\ChoreStrike\Service\RewardConfigService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for GameLogicService::calculateTeamworkBonus()
 */
class GameLogicServiceTeamworkTest extends TestCase {
	private GameLogicService $service;
	private RewardConfigService $configService;

	protected function setUp(): void {
		$houseUserMapper = $this->createMock(HouseUserMapper::class);
		$completedTaskMapper = $this->createMock(CompletedTaskMapper::class);
		$medalMapper = $this->createMock(MedalMapper::class);
		$this->configService = $this->createMock(RewardConfigService::class);
		$notificationService = $this->createMock(NotificationService::class);

		$this->configService->method('getCoinBasePerEnergy')->willReturn(10);

		$this->service = new GameLogicService(
			$houseUserMapper,
			$completedTaskMapper,
			$medalMapper,
			$this->configService,
			$notificationService
		);
	}

	private function makeTask(int $energyCost = 2, string $tier = 'silver'): Task {
		$task = new Task();
		$task->setEnergyCost($energyCost);
		$task->setTaskTier($tier);
		return $task;
	}

	public function testSingleParticipant_ReturnsZero(): void {
		$this->configService->method('getTeamworkBonusEnabled')->willReturn(true);
		$this->configService->method('getTeamworkBonusMaxPct')->willReturn(50);

		$task = $this->makeTask();
		$result = $this->service->calculateTeamworkBonus($task, ['user1']);
		$this->assertSame(0, $result);
	}

	public function testTwoParticipants_ReturnsBonus(): void {
		$this->configService->method('getTeamworkBonusEnabled')->willReturn(true);
		$this->configService->method('getTeamworkBonusMaxPct')->willReturn(50);

		$task = $this->makeTask(2, 'silver');
		$result = $this->service->calculateTeamworkBonus($task, ['user1', 'user2']);
		$this->assertGreaterThan(0, $result);
	}

	public function testThreeParticipants_ReturnsBonus(): void {
		$this->configService->method('getTeamworkBonusEnabled')->willReturn(true);
		$this->configService->method('getTeamworkBonusMaxPct')->willReturn(50);

		$task = $this->makeTask(3, 'gold');
		$result = $this->service->calculateTeamworkBonus($task, ['u1', 'u2', 'u3']);
		$this->assertGreaterThan(0, $result);
	}

	public function testDisabled_ReturnsZero(): void {
		$this->configService->method('getTeamworkBonusEnabled')->willReturn(false);

		$task = $this->makeTask();
		$result = $this->service->calculateTeamworkBonus($task, ['u1', 'u2']);
		$this->assertSame(0, $result);
	}

	public function testBonusCappedAtMaxPct(): void {
		$this->configService->method('getTeamworkBonusEnabled')->willReturn(true);
		$this->configService->method('getTeamworkBonusMaxPct')->willReturn(10);

		$task = $this->makeTask(5, 'gold');
		$result = $this->service->calculateTeamworkBonus($task, ['u1', 'u2']);
		$baseReward = (int)round(5 * 10 * 2.0);
		$cap = (int)floor($baseReward * 10 / 100);
		$this->assertLessThanOrEqual($cap, $result);
	}

	public function testZeroMaxPct_ReturnsZero(): void {
		$this->configService->method('getTeamworkBonusEnabled')->willReturn(true);
		$this->configService->method('getTeamworkBonusMaxPct')->willReturn(0);

		$task = $this->makeTask();
		$result = $this->service->calculateTeamworkBonus($task, ['u1', 'u2']);
		$this->assertSame(0, $result);
	}

	public function testFormulaCorrectness(): void {
		$this->configService->method('getTeamworkBonusEnabled')->willReturn(true);
		$this->configService->method('getTeamworkBonusMaxPct')->willReturn(100);

		$task = $this->makeTask(2, 'silver');
		$result = $this->service->calculateTeamworkBonus($task, ['u1', 'u2']);
		$expected = (int)ceil((2 * 10 * 0.5) / 2);
		$this->assertSame($expected, $result);
	}
}
