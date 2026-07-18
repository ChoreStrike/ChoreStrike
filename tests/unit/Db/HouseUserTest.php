<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Db;

use OCA\ChoreStrike\Db\HouseUser;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for HouseUser entity
 */
class HouseUserTest extends TestCase
{
    /**
     * JsonSerialize_ReturnsCorrectArray
     */
    public function testJsonSerialize_ReturnsCorrectArray(): void
    {
        $houseUser = new HouseUser();
        $houseUser->setId(1);
        $houseUser->setHouseId(2);
        $houseUser->setNextcloudUserId('user123');
        $houseUser->setDisplayName('Test User');
        $houseUser->setRole('admin');
        $houseUser->setContributionScore(150);
        $houseUser->setCurrentSparks(75);
        $houseUser->setTotalSparksEarned(200);
        $houseUser->setCurrentStreak(5);
        $houseUser->setLongestStreak(10);
        $joinedAt = new \DateTime('2026-02-01 10:00:00');
        $houseUser->setJoinedAt($joinedAt);
        $lastActivity = new \DateTime('2026-02-24 15:30:00');
        $houseUser->setLastActivity($lastActivity);

        $result = $houseUser->jsonSerialize();

        self::assertEquals(1, $result['id']);
        self::assertEquals(2, $result['houseId']);
        self::assertEquals('user123', $result['nextcloudUserId']);
        self::assertEquals('Test User', $result['displayName']);
        self::assertEquals('admin', $result['role']);
        self::assertEquals(150, $result['contributionScore']);
        self::assertEquals(75, $result['currentSparks']);
        self::assertEquals(200, $result['totalSparksEarned']);
        self::assertEquals(5, $result['currentStreak']);
        self::assertEquals(10, $result['longestStreak']);
        self::assertEquals($joinedAt->format('c'), $result['joinedAt']);
        self::assertEquals($lastActivity->format('c'), $result['lastActivity']);
    }

    /**
     * GetRank_WithScore0_ReturnsNovice
     */
    public function testGetRank_WithScore0_ReturnsNovice(): void
    {
        $houseUser = new HouseUser();
        $houseUser->setContributionScore(0);

        self::assertEquals('Novice', $houseUser->getRank());
    }

    /**
     * GetRank_WithScore50_ReturnsBeginner
     */
    public function testGetRank_WithScore50_ReturnsBeginner(): void
    {
        $houseUser = new HouseUser();
        $houseUser->setContributionScore(50);

        self::assertEquals('Beginner', $houseUser->getRank());
    }

    /**
     * GetRank_WithScore200_ReturnsHelper
     */
    public function testGetRank_WithScore200_ReturnsHelper(): void
    {
        $houseUser = new HouseUser();
        $houseUser->setContributionScore(200);

        self::assertEquals('Helper', $houseUser->getRank());
    }

    /**
     * GetRank_WithScore500_ReturnsKeeper
     */
    public function testGetRank_WithScore500_ReturnsKeeper(): void
    {
        $houseUser = new HouseUser();
        $houseUser->setContributionScore(500);

        self::assertEquals('Keeper', $houseUser->getRank());
    }

    /**
     * GetRank_WithScore1000_ReturnsGuardian
     */
    public function testGetRank_WithScore1000_ReturnsGuardian(): void
    {
        $houseUser = new HouseUser();
        $houseUser->setContributionScore(1000);

        self::assertEquals('Guardian', $houseUser->getRank());
    }

    /**
     * GetRank_WithScore2500_ReturnsChampion
     */
    public function testGetRank_WithScore2500_ReturnsChampion(): void
    {
        $houseUser = new HouseUser();
        $houseUser->setContributionScore(2500);

        self::assertEquals('Champion', $houseUser->getRank());
    }

    /**
     * GetRank_WithScore5000_ReturnsLegend
     */
    public function testGetRank_WithScore5000_ReturnsLegend(): void
    {
        $houseUser = new HouseUser();
        $houseUser->setContributionScore(5000);

        self::assertEquals('Legend', $houseUser->getRank());
    }

    /**
     * AddSparks_UpdatesTotalAndCurrentSparks
     */
    public function testAddSparks_UpdatesTotalAndCurrentSparks(): void
    {
        $houseUser = new HouseUser();
        $houseUser->setCurrentSparks(50);
        $houseUser->setTotalSparksEarned(100);

        $houseUser->addSparks(25);

        self::assertEquals(75, $houseUser->getCurrentSparks());
        self::assertEquals(125, $houseUser->getTotalSparksEarned());
    }

    /**
     * SpendSparks_ReducesCurrentSparks
     */
    public function testSpendSparks_ReducesCurrentSparks(): void
    {
        $houseUser = new HouseUser();
        $houseUser->setCurrentSparks(100);

        $houseUser->spendSparks(30);

        self::assertEquals(70, $houseUser->getCurrentSparks());
    }

    /**
     * IncrementStreak_UpdatesCurrentStreak
     */
    public function testIncrementStreak_UpdatesCurrentStreak(): void
    {
        $houseUser = new HouseUser();
        $houseUser->setCurrentStreak(3);
        $houseUser->setLongestStreak(5);

        $houseUser->incrementStreak();

        self::assertEquals(4, $houseUser->getCurrentStreak());
        self::assertEquals(5, $houseUser->getLongestStreak());
    }

    /**
     * IncrementStreak_WhenNewRecord_UpdatesLongestStreak
     */
    public function testIncrementStreak_WhenNewRecord_UpdatesLongestStreak(): void
    {
        $houseUser = new HouseUser();
        $houseUser->setCurrentStreak(5);
        $houseUser->setLongestStreak(5);

        $houseUser->incrementStreak();

        self::assertEquals(6, $houseUser->getCurrentStreak());
        self::assertEquals(6, $houseUser->getLongestStreak());
    }

    /**
     * ResetStreak_ResetsCurrentStreak
     */
    public function testResetStreak_ResetsCurrentStreak(): void
    {
        $houseUser = new HouseUser();
        $houseUser->setCurrentStreak(10);
        $houseUser->setLongestStreak(10);

        $houseUser->resetStreak();

        self::assertEquals(0, $houseUser->getCurrentStreak());
        self::assertEquals(10, $houseUser->getLongestStreak());
    }

    /**
     * ConsumeFreeze_WhenAvailable_ReturnsTrueAndDecrements
     */
    public function testConsumeFreeze_WhenAvailable_ReturnsTrueAndDecrements(): void
    {
        $houseUser = new HouseUser();
        $houseUser->setStreakFreezes(2);

        self::assertTrue($houseUser->consumeFreeze());
        self::assertEquals(1, $houseUser->getStreakFreezes());
    }

    /**
     * ConsumeFreeze_WhenNoneAvailable_ReturnsFalse
     */
    public function testConsumeFreeze_WhenNoneAvailable_ReturnsFalse(): void
    {
        $houseUser = new HouseUser();
        $houseUser->setStreakFreezes(0);

        self::assertFalse($houseUser->consumeFreeze());
        self::assertEquals(0, $houseUser->getStreakFreezes());
    }

    /**
     * RestockFreezes_SetsToConfiguredValue
     */
    public function testRestockFreezes_SetsToConfiguredValue(): void
    {
        $houseUser = new HouseUser();
        $houseUser->setStreakFreezes(0);

        $houseUser->restockFreezes(1, 2);

        self::assertEquals(1, $houseUser->getStreakFreezes());
    }

    /**
     * RestockFreezes_RespectsCap
     */
    public function testRestockFreezes_RespectsCap(): void
    {
        $houseUser = new HouseUser();
        $houseUser->setStreakFreezes(0);

        $houseUser->restockFreezes(5, 2);

        self::assertEquals(2, $houseUser->getStreakFreezes());
    }

    /**
     * AwardBonusFreeze_IncrementsWhenBelowCap
     */
    public function testAwardBonusFreeze_IncrementsWhenBelowCap(): void
    {
        $houseUser = new HouseUser();
        $houseUser->setStreakFreezes(1);

        $houseUser->awardBonusFreeze(2);

        self::assertEquals(2, $houseUser->getStreakFreezes());
    }

    /**
     * AwardBonusFreeze_NoOpWhenAtCap
     */
    public function testAwardBonusFreeze_NoOpWhenAtCap(): void
    {
        $houseUser = new HouseUser();
        $houseUser->setStreakFreezes(2);

        $houseUser->awardBonusFreeze(2);

        self::assertEquals(2, $houseUser->getStreakFreezes());
    }

    /**
     * JsonSerialize_IncludesStreakFreezes
     */
    public function testJsonSerialize_IncludesStreakFreezes(): void
    {
        $houseUser = new HouseUser();
        $houseUser->setStreakFreezes(2);

        $result = $houseUser->jsonSerialize();

        self::assertArrayHasKey('streakFreezes', $result);
        self::assertEquals(2, $result['streakFreezes']);
    }

    /**
     * DefaultStreakFreezes_IsOne
     */
    public function testDefaultStreakFreezes_IsOne(): void
    {
        $houseUser = new HouseUser();

        self::assertEquals(1, $houseUser->getStreakFreezes());
    }
}
