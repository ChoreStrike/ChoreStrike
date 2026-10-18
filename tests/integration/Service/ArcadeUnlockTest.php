<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Service;

use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Exception\GameLockedException;
use OCA\ChoreStrike\Service\ArcadeAccessService;
use OCA\ChoreStrike\Service\CompletionService;
use OCA\ChoreStrike\Service\MinigameService;
use OCA\ChoreStrike\Service\RewardConfigService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;
use OCP\IAppConfig;

/**
 * CS-PLAN-086 §3.2 on the real stack: Sock Match opens with the third and
 * Crumb Snake with the tenth finished chore, an undo re-locks a game, a
 * locked game's board stays readable, and the owner gate opens every game
 * without any chore.
 */
class ArcadeUnlockTest extends IntegrationTestCase {
	private const ROUND_MS = 60000;

	protected function setUp(): void {
		parent::setUp();
		$this->resetGate();
	}

	protected function tearDown(): void {
		$this->resetGate();
		parent::tearDown();
	}

	private function resetGate(): void {
		\OC::$server->get(IAppConfig::class)->deleteKey('chorestrike', RewardConfigService::KEY_ARCADE_GATE_ENABLED);
	}

	public function testThirdFinishedChoreUnlocksSockMatch(): void {
		$f = $this->seedFixture(3);
		$completions = \OC::$server->get(CompletionService::class);
		$this->completeOccurrence($completions, $f['houseId'], 'au_kid', $f['roomTaskIds'][0]);
		$this->completeOccurrence($completions, $f['houseId'], 'au_kid', $f['roomTaskIds'][1]);

		self::assertSame(['finished' => 2, 'unlocked' => false], $this->access($f['houseId'], 'socks'));
		$this->assertSaveLocked(\OC::$server->get(MinigameService::class), $f['houseId'], 'socks');

		$this->completeOccurrence($completions, $f['houseId'], 'au_kid', $f['roomTaskIds'][2]);
		self::assertSame(['finished' => 3, 'unlocked' => true], $this->access($f['houseId'], 'socks'));
		self::assertSame(['finished' => 3, 'unlocked' => false], $this->access($f['houseId'], 'snake'));
		// Untimed: a round that ran for two hours still saves (R086D-02).
		$saved = \OC::$server->get(MinigameService::class)->saveScore($f['houseId'], 'au_kid', 'socks', 640, 2 * 3600 * 1000);
		self::assertSame(640, $saved->getScore());
	}

	public function testTenthFinishedChoreUnlocksSnake_AndAnUndoReLocksIt(): void {
		$f = $this->seedFixture(10);
		$completions = \OC::$server->get(CompletionService::class);
		$scores = \OC::$server->get(MinigameService::class);
		foreach (array_slice($f['roomTaskIds'], 0, 9) as $roomTaskId) {
			$this->completeOccurrence($completions, $f['houseId'], 'au_kid', $roomTaskId);
		}

		self::assertSame(['finished' => 9, 'unlocked' => false], $this->access($f['houseId'], 'snake'));
		$this->assertSaveLocked($scores, $f['houseId'], 'snake');

		$this->completeOccurrence($completions, $f['houseId'], 'au_kid', $f['roomTaskIds'][9]);
		self::assertSame(['finished' => 10, 'unlocked' => true], $this->access($f['houseId'], 'snake'));
		$scores->saveScore($f['houseId'], 'au_kid', 'snake', 120, self::ROUND_MS);

		$this->undoLatestForRoomTask($completions, $f['houseId'], 'au_kid', $f['roomTaskIds'][9]);
		self::assertSame(['finished' => 9, 'unlocked' => false], $this->access($f['houseId'], 'snake'), 'the derived state re-locks');
		$this->assertSaveLocked($scores, $f['houseId'], 'snake');

		$board = $scores->getLeaderboard($f['houseId'], 'snake');
		self::assertSame([120], array_column($board, 'score'), 'the locked game keeps its readable board');
	}

	public function testOwnerGateOff_OpensSnakeWithoutChores(): void {
		$f = $this->seedFixture(0);
		$this->actingAs('au_owner');

		\OC::$server->get(ArcadeAccessService::class)->setGateEnabled($f['houseId'], false);

		self::assertSame(['finished' => 0, 'unlocked' => true], $this->access($f['houseId'], 'snake'));
		$saved = \OC::$server->get(MinigameService::class)->saveScore($f['houseId'], 'au_kid', 'snake', 50, self::ROUND_MS);
		self::assertSame(50, $saved->getScore());
	}

	// ========== Helpers ==========

	/**
	 * @return array{houseId: int, roomTaskIds: int[]}
	 */
	private function seedFixture(int $roomTasks): array {
		$houseId = $this->createHouse('au_owner', 'Arcade Unlock House');
		$this->addHouseMember($houseId, 'au_kid', 'Kid', 'member');
		$roomId = $this->createRoom($houseId, $this->createFloor($houseId, 'Ground Floor', 1), 'Kitchen');
		$roomTaskIds = [];
		for ($i = 0; $i < $roomTasks; $i++) {
			$roomTaskIds[] = $this->assignTaskToRoom($this->createTask($houseId, 'Unlock task ' . $i, '', 5, 1), $roomId, 'au_kid');
		}
		return ['houseId' => $houseId, 'roomTaskIds' => $roomTaskIds];
	}

	/**
	 * @return array{finished: int, unlocked: bool}
	 */
	private function access(int $houseId, string $gameId): array {
		$kid = \OC::$server->get(HouseUserMapper::class)->findByNextcloudUserId($houseId, 'au_kid');
		$catalog = \OC::$server->get(ArcadeAccessService::class)->getCatalog($kid);
		$game = array_column($catalog['games'], null, 'gameId')[$gameId];
		return ['finished' => $catalog['finishedChores'], 'unlocked' => $game['unlocked']];
	}

	private function assertSaveLocked(MinigameService $scores, int $houseId, string $gameId): void {
		try {
			$scores->saveScore($houseId, 'au_kid', $gameId, 120, self::ROUND_MS);
			self::fail('a locked game must not accept a score');
		} catch (GameLockedException $e) {
			self::assertSame('This game is still locked.', $e->getMessage());
		}
	}
}
