<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\MinigameScore;
use OCA\ChoreStrike\Db\MinigameScoreMapper;
use OCA\ChoreStrike\Service\MinigameService;
use PHPUnit\Framework\TestCase;

class MinigameServiceTest extends TestCase
{
    private MinigameService $service;
    private MinigameScoreMapper $scoreMapper;
    private HouseUserMapper $houseUserMapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scoreMapper = $this->createMock(MinigameScoreMapper::class);
        $this->houseUserMapper = $this->createMock(HouseUserMapper::class);
        $this->service = new MinigameService($this->scoreMapper, $this->houseUserMapper);
    }

    public function testSaveScoreCreatesNewScore(): void
    {
        $score = new MinigameScore();
        $score->setId(1);
        $score->setHouseId(1);
        $score->setUserId('user1');
        $score->setGameId('snake');
        $score->setScore(150);

        $this->scoreMapper->expects($this->once())
            ->method('insert')
            ->willReturn($score);

        $result = $this->service->saveScore(1, 'user1', 'snake', 150);

        $this->assertEquals(150, $result->getScore());
        $this->assertEquals('snake', $result->getGameId());
    }

    public function testSaveScoreWithBoosts(): void
    {
        $score = new MinigameScore();
        $score->setId(1);
        $score->setHouseId(1);
        $score->setUserId('user1');
        $score->setGameId('snake');
        $score->setScore(200);
        $score->setBoostsUsed(['slow_motion', 'ghost_mode']);

        $this->scoreMapper->expects($this->once())
            ->method('insert')
            ->willReturn($score);

        $result = $this->service->saveScore(1, 'user1', 'snake', 200, ['slow_motion', 'ghost_mode']);

        $this->assertEquals(200, $result->getScore());
        $this->assertEquals(['slow_motion', 'ghost_mode'], $result->getBoostsUsed());
    }

    public function testGetLeaderboardReturnsScoresWithDisplayNames(): void
    {
        $score1 = new MinigameScore();
        $score1->setId(1);
        $score1->setUserId('user1');
        $score1->setScore(500);

        $score2 = new MinigameScore();
        $score2->setId(2);
        $score2->setUserId('user2');
        $score2->setScore(300);

        $user1 = new HouseUser();
        $user1->setNextcloudUserId('user1');
        $user1->setDisplayName('Alice');

        $user2 = new HouseUser();
        $user2->setNextcloudUserId('user2');
        $user2->setDisplayName('Bob');

        $this->scoreMapper->expects($this->once())
            ->method('getLeaderboard')
            ->with(1, 'snake', 10)
            ->willReturn([$score1, $score2]);

        $this->houseUserMapper->expects($this->exactly(2))
            ->method('findByHouseIdAndNextcloudUserId')
            ->willReturnMap([
                [1, 'user1', $user1],
                [1, 'user2', $user2],
            ]);

        $result = $this->service->getLeaderboard(1, 'snake', 10);

        $this->assertCount(2, $result);
        $this->assertEquals('Alice', $result[0]['displayName']);
        $this->assertEquals(500, $result[0]['score']);
        $this->assertEquals('Bob', $result[1]['displayName']);
        $this->assertEquals(300, $result[1]['score']);
    }

    public function testGetPersonalBestReturnsHighestScore(): void
    {
        $score = new MinigameScore();
        $score->setId(1);
        $score->setUserId('user1');
        $score->setScore(500);

        $this->scoreMapper->expects($this->once())
            ->method('getPersonalBest')
            ->with(1, 'user1', 'snake')
            ->willReturn($score);

        $result = $this->service->getPersonalBest(1, 'user1', 'snake');

        $this->assertNotNull($result);
        $this->assertEquals(500, $result['score']);
    }

    public function testGetPersonalBestReturnsNullWhenNoScores(): void
    {
        $this->scoreMapper->expects($this->once())
            ->method('getPersonalBest')
            ->with(1, 'user1', 'snake')
            ->willReturn(null);

        $result = $this->service->getPersonalBest(1, 'user1', 'snake');

        $this->assertNull($result);
    }

    public function testGetUserRankReturnsCorrectPosition(): void
    {
        $this->scoreMapper->expects($this->once())
            ->method('getUserRank')
            ->with(1, 'user1', 'snake')
            ->willReturn(3);

        $result = $this->service->getUserRank(1, 'user1', 'snake');

        $this->assertEquals(3, $result);
    }

    public function testPurchaseBoostDeductsSparks(): void
    {
        $user = new HouseUser();
        $user->setNextcloudUserId('user1');
        $user->setCurrentSparks(50);

        $this->houseUserMapper->expects($this->once())
            ->method('findByHouseIdAndNextcloudUserId')
            ->with(1, 'user1')
            ->willReturn($user);

        $this->houseUserMapper->expects($this->once())
            ->method('update')
            ->willReturn($user);

        $result = $this->service->purchaseBoost(1, 'user1', 'slow_motion');

        $this->assertTrue($result['success']);
        $this->assertEquals(10, $result['cost']);
        $this->assertEquals(40, $result['remainingSparks']);
    }

    public function testPurchaseBoostFailsWithInsufficientSparks(): void
    {
        $user = new HouseUser();
        $user->setNextcloudUserId('user1');
        $user->setCurrentSparks(5);

        $this->houseUserMapper->expects($this->once())
            ->method('findByHouseIdAndNextcloudUserId')
            ->with(1, 'user1')
            ->willReturn($user);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Insufficient sparks');

        $this->service->purchaseBoost(1, 'user1', 'slow_motion');
    }

    public function testPurchaseBoostFailsWithUnknownBoost(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown boost');

        $this->service->purchaseBoost(1, 'user1', 'unknown_boost');
    }

    public function testGetAvailableBoostsReturnsAllBoosts(): void
    {
        $boosts = $this->service->getAvailableBoosts();

        $this->assertArrayHasKey('slow_motion', $boosts);
        $this->assertArrayHasKey('ghost_mode', $boosts);
        $this->assertArrayHasKey('magnet', $boosts);
        $this->assertArrayHasKey('shrink', $boosts);
        $this->assertArrayHasKey('no_obstacles', $boosts);
        $this->assertEquals(10, $boosts['slow_motion']);
        $this->assertEquals(20, $boosts['ghost_mode']);
    }

    public function testGetUserSparksReturnsCurrentBalance(): void
    {
        $user = new HouseUser();
        $user->setNextcloudUserId('user1');
        $user->setCurrentSparks(75);

        $this->houseUserMapper->expects($this->once())
            ->method('findByHouseIdAndNextcloudUserId')
            ->with(1, 'user1')
            ->willReturn($user);

        $result = $this->service->getUserSparks(1, 'user1');

        $this->assertEquals(75, $result);
    }

    public function testGetUserSparksReturnsZeroForUnknownUser(): void
    {
        $this->houseUserMapper->expects($this->once())
            ->method('findByHouseIdAndNextcloudUserId')
            ->with(1, 'unknown')
            ->willReturn(null);

        $result = $this->service->getUserSparks(1, 'unknown');

        $this->assertEquals(0, $result);
    }

    public function testGetRecentScoresReturnsUserScores(): void
    {
        $score1 = new MinigameScore();
        $score1->setId(1);
        $score1->setScore(100);

        $score2 = new MinigameScore();
        $score2->setId(2);
        $score2->setScore(80);

        $this->scoreMapper->expects($this->once())
            ->method('findByUserAndGame')
            ->with('user1', 'snake', 5)
            ->willReturn([$score1, $score2]);

        $result = $this->service->getRecentScores('user1', 'snake', 5);

        $this->assertCount(2, $result);
        $this->assertEquals(100, $result[0]['score']);
        $this->assertEquals(80, $result[1]['score']);
    }
}
