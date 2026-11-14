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
use OCA\ChoreStrike\Service\ArcadeCatalog;
use OCA\ChoreStrike\Service\ArcadeStartPointStore;
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
 *
 * CS-PLAN-090 WP-J (D-090-63/66): an owner's lower threshold opens a game
 * earlier, and a reset re-locks it until new chores count again, while the
 * highscores stay and an undo on either side of the start point changes
 * only what lies above it.
 */
class ArcadeUnlockTest extends IntegrationTestCase {
	private const ROUND_MS = 60000;

	protected function setUp(): void {
		parent::setUp();
		$this->resetArcadeSettings();
	}

	protected function tearDown(): void {
		$this->resetArcadeSettings();
		parent::tearDown();
	}

	private function resetArcadeSettings(): void {
		\OC::$server->get(IAppConfig::class)->deleteKey('chorestrike', RewardConfigService::KEY_ARCADE_GATE_ENABLED);
		\OC::$server->get(RewardConfigService::class)->resetArcadeThresholds();
		\OC::$server->get(ArcadeStartPointStore::class)->forget('au_kid');
	}

	public function testLoweredThreshold_OpensSockMatchWithTheFirstChore(): void {
		$f = $this->seedFixture(1);
		$this->actingAs('au_owner');
		$thresholds = array_map(static fn (array $rules): int => $rules['unlockThreshold'], ArcadeCatalog::GAMES);
		\OC::$server->get(ArcadeAccessService::class)->setThresholds($f['houseId'], ['socks' => 1] + $thresholds);

		self::assertSame(['finished' => 0, 'unlocked' => false], $this->access($f['houseId'], 'socks'));
		$this->completeOccurrence(\OC::$server->get(CompletionService::class), $f['houseId'], 'au_kid', $f['roomTaskIds'][0]);

		self::assertSame(['finished' => 1, 'unlocked' => true], $this->access($f['houseId'], 'socks'));
		$saved = \OC::$server->get(MinigameService::class)->saveScore($f['houseId'], 'au_kid', 'socks', 640, self::ROUND_MS);
		self::assertSame(640, $saved->getScore());
	}

	public function testReset_ReLocks_NewChoresUnlock_AndHighscoresStay(): void {
		$f = $this->seedFixture(6);
		$completions = \OC::$server->get(CompletionService::class);
		$scores = \OC::$server->get(MinigameService::class);
		foreach (array_slice($f['roomTaskIds'], 0, 3) as $roomTaskId) {
			$this->completeOccurrence($completions, $f['houseId'], 'au_kid', $roomTaskId);
		}
		$scores->saveScore($f['houseId'], 'au_kid', 'socks', 640, self::ROUND_MS);

		$this->actingAs('au_owner');
		$progress = \OC::$server->get(ArcadeAccessService::class)->resetMember($f['houseId'], 'au_kid');

		$kid = array_column($progress, null, 'userId')['au_kid'];
		self::assertSame(0, $kid['finishedChores']);
		self::assertNotNull($kid['resetAt']);
		self::assertSame(['finished' => 0, 'unlocked' => false], $this->access($f['houseId'], 'socks'), 'the reset locks Sock Match again');
		$this->assertSaveLocked($scores, $f['houseId'], 'socks');
		self::assertSame([640], array_column($scores->getLeaderboard($f['houseId'], 'socks'), 'score'), 'the highscore stays');

		foreach (array_slice($f['roomTaskIds'], 3, 3) as $roomTaskId) {
			$this->completeOccurrence($completions, $f['houseId'], 'au_kid', $roomTaskId);
		}
		self::assertSame(['finished' => 3, 'unlocked' => true], $this->access($f['houseId'], 'socks'), 'new chores unlock it anew');
	}

	/**
	 * UndoOnEitherSideOfTheStartPoint (D-090-66): an undo of earlier work
	 * changes nothing, an undo of later work lowers the count.
	 */
	public function testUndoOnEitherSideOfTheStartPoint(): void {
		$f = $this->seedFixture(3);
		$completions = \OC::$server->get(CompletionService::class);
		$this->completeOccurrence($completions, $f['houseId'], 'au_kid', $f['roomTaskIds'][0]);
		$this->completeOccurrence($completions, $f['houseId'], 'au_kid', $f['roomTaskIds'][1]);
		$this->actingAs('au_owner');
		\OC::$server->get(ArcadeAccessService::class)->resetMember($f['houseId'], 'au_kid');
		$this->completeOccurrence($completions, $f['houseId'], 'au_kid', $f['roomTaskIds'][2]);
		self::assertSame(1, $this->access($f['houseId'], 'socks')['finished']);

		$this->undoLatestForRoomTask($completions, $f['houseId'], 'au_kid', $f['roomTaskIds'][1]);
		self::assertSame(1, $this->access($f['houseId'], 'socks')['finished'], 'an undo below the start point changes nothing');

		$this->undoLatestForRoomTask($completions, $f['houseId'], 'au_kid', $f['roomTaskIds'][2]);
		self::assertSame(0, $this->access($f['houseId'], 'socks')['finished'], 'an undo above it counts');
	}

	public function testProgress_ListsEveryMemberWithTheirCountSinceTheLastReset(): void {
		$f = $this->seedFixture(2);
		$completions = \OC::$server->get(CompletionService::class);
		$this->completeOccurrence($completions, $f['houseId'], 'au_kid', $f['roomTaskIds'][0]);
		$this->actingAs('au_owner');
		$access = \OC::$server->get(ArcadeAccessService::class);
		$access->resetMember($f['houseId'], 'au_kid');
		$this->completeOccurrence($completions, $f['houseId'], 'au_kid', $f['roomTaskIds'][1]);

		$progress = array_column($access->getProgress($f['houseId']), null, 'userId');

		self::assertEqualsCanonicalizing(['au_kid', 'au_owner'], array_keys($progress));
		self::assertSame(1, $progress['au_kid']['finishedChores']);
		self::assertNotNull($progress['au_kid']['resetAt']);
		self::assertSame(['userId' => 'au_owner', 'displayName' => $progress['au_owner']['displayName'], 'finishedChores' => 0, 'resetAt' => null], $progress['au_owner']);
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
