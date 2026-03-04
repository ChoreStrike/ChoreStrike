<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\UserStats;
use OCA\ChoreStrike\Db\UserStatsMapper;
use OCA\ChoreStrike\Service\UserStatsService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;

class UserStatsServiceTest extends TestCase
{
    private UserStatsService $service;
    private UserStatsMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = $this->createMock(UserStatsMapper::class);
        $this->service = new UserStatsService($this->mapper);
    }

    public function testGetOrCreateStatsReturnsExistingStats(): void
    {
        $stats = new UserStats();
        $stats->setId(1);
        $stats->setHouseId(1);
        $stats->setUserId('user1');
        $stats->setTotalPoints(100);

        $this->mapper->expects($this->once())
            ->method('findByHouseAndUser')
            ->with(1, 'user1')
            ->willReturn($stats);

        $result = $this->service->getOrCreateStats(1, 'user1');

        $this->assertEquals(1, $result->getId());
        $this->assertEquals(100, $result->getTotalPoints());
    }

    public function testGetOrCreateStatsCreatesNewStats(): void
    {
        $this->mapper->expects($this->once())
            ->method('findByHouseAndUser')
            ->with(1, 'user1')
            ->willThrowException(new DoesNotExistException(''));

        $newStats = new UserStats();
        $newStats->setId(2);
        $newStats->setHouseId(1);
        $newStats->setUserId('user1');
        $newStats->setTotalPoints(0);

        $this->mapper->expects($this->once())
            ->method('insert')
            ->willReturn($newStats);

        $result = $this->service->getOrCreateStats(1, 'user1');

        $this->assertEquals(0, $result->getTotalPoints());
    }

    public function testAddPointsIncrementsTotal(): void
    {
        $stats = new UserStats();
        $stats->setId(1);
        $stats->setHouseId(1);
        $stats->setUserId('user1');
        $stats->setTotalPoints(100);

        $this->mapper->expects($this->once())
            ->method('findByHouseAndUser')
            ->with(1, 'user1')
            ->willReturn($stats);

        $this->mapper->expects($this->once())
            ->method('update')
            ->willReturn($stats);

        $result = $this->service->addPoints(1, 'user1', 50);

        $this->assertEquals(150, $result->getTotalPoints());
    }

    public function testRecordTaskCompletionIncrementsCount(): void
    {
        $stats = new UserStats();
        $stats->setId(1);
        $stats->setHouseId(1);
        $stats->setUserId('user1');
        $stats->setTasksCompleted(5);

        $this->mapper->expects($this->once())
            ->method('findByHouseAndUser')
            ->with(1, 'user1')
            ->willReturn($stats);

        $this->mapper->expects($this->once())
            ->method('update')
            ->willReturn($stats);

        $result = $this->service->recordTaskCompletion(1, 'user1');

        $this->assertEquals(6, $result->getTasksCompleted());
    }

    public function testUpdateStreakAfterCompletion(): void
    {
        $stats = new UserStats();
        $stats->setId(1);
        $stats->setHouseId(1);
        $stats->setUserId('user1');
        $stats->setCurrentStreak(5);
        $stats->setLongestStreak(5);

        $this->mapper->expects($this->once())
            ->method('findByHouseAndUser')
            ->with(1, 'user1')
            ->willReturn($stats);

        $this->mapper->expects($this->once())
            ->method('update')
            ->willReturn($stats);

        $result = $this->service->updateStreakAfterCompletion(1, 'user1', 6);

        $this->assertEquals(6, $result->getCurrentStreak());
        $this->assertEquals(6, $result->getLongestStreak());
    }

    public function testResetStreakSetsToZero(): void
    {
        $stats = new UserStats();
        $stats->setId(1);
        $stats->setHouseId(1);
        $stats->setUserId('user1');
        $stats->setCurrentStreak(5);

        $this->mapper->expects($this->once())
            ->method('findByHouseAndUser')
            ->with(1, 'user1')
            ->willReturn($stats);

        $this->mapper->expects($this->once())
            ->method('update')
            ->willReturn($stats);

        $result = $this->service->resetStreak(1, 'user1');

        $this->assertEquals(0, $result->getCurrentStreak());
    }

    public function testGetLeaderboardReturnsTopUsers(): void
    {
        $stats1 = new UserStats();
        $stats1->setUserId('user1');
        $stats1->setTotalPoints(500);

        $stats2 = new UserStats();
        $stats2->setUserId('user2');
        $stats2->setTotalPoints(300);

        $this->mapper->expects($this->once())
            ->method('getLeaderboard')
            ->with(1, 10)
            ->willReturn([$stats1, $stats2]);

        $result = $this->service->getLeaderboard(1, 10);

        $this->assertCount(2, $result);
        $this->assertEquals(500, $result[0]->getTotalPoints());
        $this->assertEquals(300, $result[1]->getTotalPoints());
    }
}
