<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Service\GameLogicService;
use OCA\ChoreStrike\Service\RecognitionPass;
use OCA\ChoreStrike\Service\RhythmService;
use OCA\ChoreStrike\Service\TitleService;
use OCA\ChoreStrike\Service\WeeklyGoalService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for GameLogicService (CS-PLAN-070 WP3 cutover).
 *
 * The former completion-reward orchestration (coins, combo, streak,
 * freezes, teamwork bonus, title detection) is retired: money is the
 * wallet ledger, streaks re-derive from valid history, titles
 * re-evaluate from valid stats. What remains is the read-side
 * serialization for `GET /game/state` — viewer-safe member progression
 * with NO Coin balances — and the viewer's recognitions from one pass.
 */
class GameLogicServiceTest extends TestCase {
	private HouseUserMapper $houseUserMapper;
	private WeeklyGoalService $weeklyGoalService;
	private RhythmService $rhythmService;

	protected function setUp(): void {
		$this->houseUserMapper = $this->createMock(HouseUserMapper::class);
		$this->weeklyGoalService = $this->createMock(WeeklyGoalService::class);
		$this->rhythmService = $this->createMock(RhythmService::class);
	}

	/**
	 * Parent §3.4 read contract: one pass feeds the house goal, the
	 * viewer's rhythm and the viewer's slice of the goal.
	 */
	public function testRecognitionsFor_OnePassFeedsTheGoalTheRhythmAndThePersonalSlice(): void {
		$house = new House();
		$house->setId(1);
		$viewer = $this->member(11, 'Alice');
		$now = new \DateTime('2026-07-15 10:00:00');
		$pass = $this->createMock(RecognitionPass::class);
		$this->weeklyGoalService->expects($this->once())->method('beginPass')->with($house)->willReturn($pass);
		$this->weeklyGoalService->expects($this->once())->method('progressForWeek')->with($house, $now, $pass)->willReturn(['percent' => 80]);
		// C1f evaluation is a pure read: a confirmed streak with a held day.
		$this->rhythmService->expects($this->once())->method('evaluateForMembers')->with(1, [$viewer], $now, $pass)
			->willReturn([11 => ['currentStreak' => 4, 'longestStreak' => 9, 'pending' => true]]);
		$this->weeklyGoalService->expects($this->once())->method('progressForMember')->with($house, $viewer, $now, $pass)->willReturn(['eligible' => 3]);

		$service = new GameLogicService($this->houseUserMapper, $this->weeklyGoalService, $this->rhythmService);

		$this->assertSame([
			'weeklyGoal' => ['percent' => 80],
			'rhythm' => ['confirmed' => 4, 'current' => 4, 'best' => 9, 'pending' => true],
			'myWeeklyGoal' => ['eligible' => 3],
		], $service->recognitionsFor($house, $viewer, $now));
	}

	public function testRecognitionsFor_MemberWithoutAnEvaluation_StartsAtZero(): void {
		$house = new House();
		$house->setId(1);
		$this->rhythmService->method('evaluateForMembers')->willReturn([]);

		$service = new GameLogicService($this->houseUserMapper, $this->weeklyGoalService, $this->rhythmService);

		$this->assertSame(
			['confirmed' => 0, 'current' => 0, 'best' => 0, 'pending' => false],
			$service->recognitionsFor($house, $this->member(12, 'Bob'), new \DateTime())['rhythm']
		);
	}

	private function member(int $id, string $name): HouseUser {
		$user = new HouseUser();
		$user->setId($id);
		$user->setHouseId(1);
		$user->setNextcloudUserId('nc-' . $id);
		$user->setDisplayName($name);
		$user->setContributionMinor(3400);
		$user->setCurrentStreak(3);
		return $user;
	}

	/**
	 * GetGameState_ReturnsSerializedMembers
	 */
	public function testGetGameState_ReturnsSerializedMembers(): void {
		$members = [$this->member(11, 'Alice'), $this->member(12, 'Bob')];
		$this->houseUserMapper
			->expects($this->once())
			->method('findByHouseId')
			->with(1)
			->willReturn($members);

		$service = new GameLogicService($this->houseUserMapper, $this->weeklyGoalService, $this->rhythmService);
		$result = $service->getGameState(1);

		$this->assertCount(2, $result['users']);
		$this->assertSame('Alice', $result['users'][0]['displayName']);
		$this->assertSame(3400, $result['users'][0]['contributionMinor']);
		$this->assertSame(3, $result['users'][0]['currentStreak']);
	}

	/**
	 * GetGameState_NoTitleService_SerializesRawEntityShape
	 */
	public function testGetGameState_NoTitleService_SerializesRawEntityShape(): void {
		$this->houseUserMapper->method('findByHouseId')->willReturn([$this->member(11, 'Alice')]);

		$service = new GameLogicService($this->houseUserMapper, $this->weeklyGoalService, $this->rhythmService);
		$result = $service->getGameState(1);

		$this->assertArrayNotHasKey('currentTitle', $result['users'][0]);
	}

	/**
	 * GetGameState_WithTitleService_EnrichesCurrentTitle
	 *
	 * Delegates to TitleService so the shape stays in sync with
	 * UserController.
	 */
	public function testGetGameState_WithTitleService_EnrichesCurrentTitle(): void {
		$this->houseUserMapper->method('findByHouseId')->willReturn([$this->member(11, 'Alice')]);

		$titleService = $this->createMock(TitleService::class);
		$titleService
			->expects($this->once())
			->method('enrichWithCurrentTitle')
			->willReturnCallback(static function (array $data) {
				$data['currentTitle'] = 'family_rhythm.helper';
				return $data;
			});

		$service = new GameLogicService($this->houseUserMapper, $this->weeklyGoalService, $this->rhythmService, $titleService);
		$result = $service->getGameState(1);

		$this->assertSame('family_rhythm.helper', $result['users'][0]['currentTitle']);
	}

	/**
	 * GetGameState_NeverExposesWalletBalances
	 *
	 * The wallet is exposed only as the caller's OWN state; member
	 * progression carries no foreign Coin totals (WP3 §3.6).
	 */
	public function testGetGameState_NeverExposesWalletBalances(): void {
		$this->houseUserMapper->method('findByHouseId')->willReturn([$this->member(11, 'Alice')]);

		$service = new GameLogicService($this->houseUserMapper, $this->weeklyGoalService, $this->rhythmService);
		$result = $service->getGameState(1);

		$this->assertArrayNotHasKey('coins', $result['users'][0]);
		$this->assertArrayNotHasKey('bookedMinor', $result['users'][0]);
		$this->assertArrayNotHasKey('walletId', $result['users'][0]);
	}

	/**
	 * GetGameState_EmptyHouse_ReturnsEmptyUsers
	 */
	public function testGetGameState_EmptyHouse_ReturnsEmptyUsers(): void {
		$this->houseUserMapper->method('findByHouseId')->willReturn([]);

		$service = new GameLogicService($this->houseUserMapper, $this->weeklyGoalService, $this->rhythmService);
		$result = $service->getGameState(1);

		$this->assertSame(['users' => []], $result);
	}
}
