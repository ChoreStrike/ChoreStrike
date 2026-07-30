<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Db;

use OCA\ChoreStrike\Db\UserStats;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for UserStats entity logic
 */
class UserStatsTest extends TestCase
{
    private UserStats $stats;

    protected function setUp(): void
    {
        $this->stats = new UserStats();
        $this->stats->setTotalPoints(100);
        $this->stats->setCurrentStreak(3);
        $this->stats->setLongestStreak(5);
        $this->stats->setTasksCompleted(20);
    }

    public function testAddPoints_IncreasesTotal(): void
    {
        $this->stats->addPoints(50);

        $this->assertEquals(150, $this->stats->getTotalPoints());
        $this->assertInstanceOf(\DateTime::class, $this->stats->getUpdatedAt());
    }

    public function testIncrementTasksCompleted_IncreasesCount(): void
    {
        $this->stats->incrementTasksCompleted();

        $this->assertEquals(21, $this->stats->getTasksCompleted());
        $this->assertInstanceOf(\DateTime::class, $this->stats->getLastCompletionDate());
    }

    public function testUpdateStreak_UpdatesLongestWhenHigher(): void
    {
        $this->stats->updateStreak(7);

        $this->assertEquals(7, $this->stats->getCurrentStreak());
        $this->assertEquals(7, $this->stats->getLongestStreak());
    }

    public function testUpdateStreak_DoesNotLowerLongest(): void
    {
        $this->stats->updateStreak(2);

        $this->assertEquals(2, $this->stats->getCurrentStreak());
        $this->assertEquals(5, $this->stats->getLongestStreak());
    }

    public function testResetStreak_ZeroesCurrent(): void
    {
        $this->stats->resetStreak();

        $this->assertEquals(0, $this->stats->getCurrentStreak());
        $this->assertEquals(5, $this->stats->getLongestStreak());
    }

    public function testIncrementStreak_AddsOne(): void
    {
        $this->stats->incrementStreak();

        $this->assertEquals(4, $this->stats->getCurrentStreak());
    }

    public function testJsonSerialize_IncludesAllFields(): void
    {
        $this->stats->setId(1);
        $this->stats->setHouseId(2);
        $this->stats->setUserId('alice');

        $json = $this->stats->jsonSerialize();

        $this->assertEquals(1, $json['id']);
        $this->assertEquals('alice', $json['userId']);
        $this->assertEquals(100, $json['totalPoints']);
        $this->assertEquals(3, $json['currentStreak']);
        $this->assertEquals(5, $json['longestStreak']);
        $this->assertEquals(20, $json['tasksCompleted']);
    }
}
