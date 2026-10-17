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
 * CS-PLAN-086 §3.2 on the real stack: Crumb Snake opens with the tenth
 * finished chore, an undo re-locks it, a locked game's board stays
 * readable, and the owner gate opens every game without any chore.
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

	public function testTenthFinishedChoreUnlocksSnake_AndAnUndoReLocksIt(): void {
		$f = $this->seedFixture(10);
		$completions = \OC::$server->get(CompletionService::class);
		$scores = \OC::$server->get(MinigameService::class);
		foreach (array_slice($f['roomTaskIds'], 0, 9) as $roomTaskId) {
			$this->completeOccurrence($completions, $f['houseId'], 'au_kid', $roomTaskId);
		}

		self::assertSame(['finished' => 9, 'unlocked' => false], $this->snakeAccess($f['houseId']));
		$this->assertSaveLocked($scores, $f['houseId']);

		$this->completeOccurrence($completions, $f['houseId'], 'au_kid', $f['roomTaskIds'][9]);
		self::assertSame(['finished' => 10, 'unlocked' => true], $this->snakeAccess($f['houseId']));
		$scores->saveScore($f['houseId'], 'au_kid', 'snake', 120, self::ROUND_MS);

		$this->undoLatestForRoomTask($completions, $f['houseId'], 'au_kid', $f['roomTaskIds'][9]);
		self::assertSame(['finished' => 9, 'unlocked' => false], $this->snakeAccess($f['houseId']), 'the derived state re-locks');
		$this->assertSaveLocked($scores, $f['houseId']);

		$board = $scores->getLeaderboard($f['houseId'], 'snake');
		self::assertSame([120], array_column($board, 'score'), 'the locked game keeps its readable board');
	}

	public function testOwnerGateOff_OpensSnakeWithoutChores(): void {
		$f = $this->seedFixture(0);
		$this->actingAs('au_owner');

		\OC::$server->get(ArcadeAccessService::class)->setGateEnabled($f['houseId'], false);

		self::assertSame(['finished' => 0, 'unlocked' => true], $this->snakeAccess($f['houseId']));
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
	private function snakeAccess(int $houseId): array {
		$kid = \OC::$server->get(HouseUserMapper::class)->findByNextcloudUserId($houseId, 'au_kid');
		$catalog = \OC::$server->get(ArcadeAccessService::class)->getCatalog($kid);
		$snake = array_column($catalog['games'], null, 'gameId')['snake'];
		return ['finished' => $catalog['finishedChores'], 'unlocked' => $snake['unlocked']];
	}

	private function assertSaveLocked(MinigameService $scores, int $houseId): void {
		try {
			$scores->saveScore($houseId, 'au_kid', 'snake', 120, self::ROUND_MS);
			self::fail('a locked game must not accept a score');
		} catch (GameLockedException $e) {
			self::assertSame('This game is still locked.', $e->getMessage());
		}
	}
}
