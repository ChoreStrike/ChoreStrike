<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Service\GameLogicService;
use OCA\ChoreStrike\Service\TitleService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for GameLogicService (CS-PLAN-070 WP3 cutover).
 *
 * The former completion-reward orchestration (coins, combo, streak,
 * freezes, teamwork bonus, title detection) is retired: money is the
 * wallet ledger, streaks re-derive from valid history, titles
 * re-evaluate from valid stats. What remains is the read-side
 * serialization for `GET /game/state` — viewer-safe member progression
 * with NO Coin balances.
 */
class GameLogicServiceTest extends TestCase {
	private HouseUserMapper $houseUserMapper;

	protected function setUp(): void {
		$this->houseUserMapper = $this->createMock(HouseUserMapper::class);
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

		$service = new GameLogicService($this->houseUserMapper);
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

		$service = new GameLogicService($this->houseUserMapper);
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

		$service = new GameLogicService($this->houseUserMapper, $titleService);
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

		$service = new GameLogicService($this->houseUserMapper);
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

		$service = new GameLogicService($this->houseUserMapper);
		$result = $service->getGameState(1);

		$this->assertSame(['users' => []], $result);
	}
}
