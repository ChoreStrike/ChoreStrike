<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Db\Entity;

use OCA\ChoreStrike\Db\HouseUser;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for HouseUser entity
 */
class HouseUserTest extends TestCase {
	/**
	 * JsonSerialize_ReturnsCorrectArray
	 */
	public function testJsonSerialize_ReturnsCorrectArray(): void {
		$houseUser = new HouseUser();
		$houseUser->setId(1);
		$houseUser->setHouseId(2);
		$houseUser->setNextcloudUserId('user123');
		$houseUser->setDisplayName('Test User');
		$houseUser->setRole('admin');
		$houseUser->setContributionScore(150);
		$houseUser->setCurrentStreak(5);
		$houseUser->setLongestStreak(10);
		$joinedAt = new \DateTime('2026-02-01 10:00:00');
		$houseUser->setJoinedAt($joinedAt);
		$lastActivity = new \DateTime('2026-02-24 15:30:00');
		$houseUser->setLastActivity($lastActivity);
		$lastFreezeDate = new \DateTime('2026-02-24 00:00:00');
		$houseUser->setLastFreezeDate($lastFreezeDate);

		$result = $houseUser->jsonSerialize();

		self::assertEquals(1, $result['id']);
		self::assertEquals(2, $result['houseId']);
		self::assertEquals('user123', $result['nextcloudUserId']);
		self::assertEquals('Test User', $result['displayName']);
		self::assertEquals('admin', $result['role']);
		self::assertEquals(150, $result['contributionScore']);
		self::assertEquals(5, $result['currentStreak']);
		self::assertEquals(10, $result['longestStreak']);
		self::assertEquals($joinedAt->format('c'), $result['joinedAt']);
		self::assertEquals($lastActivity->format('c'), $result['lastActivity']);
		// CS-PLAN-040 (FEAT-UX-020): freeze visibility data.
		self::assertEquals($lastFreezeDate->format('c'), $result['lastFreezeDate']);
	}

	/**
	 * JsonSerialize_WithoutFreeze_LastFreezeDateIsNull
	 *
	 * CS-PLAN-040 (FEAT-UX-020): users whose streak was never frozen must
	 * serialize lastFreezeDate as null (not an epoch or empty string) so the
	 * UI can distinguish "no active freeze".
	 */
	public function testJsonSerialize_WithoutFreeze_LastFreezeDateIsNull(): void {
		$houseUser = new HouseUser();

		self::assertNull($houseUser->jsonSerialize()['lastFreezeDate']);
	}

	/**
	 * ResetStreak_ResetsCurrentStreak
	 */
	public function testResetStreak_ResetsCurrentStreak(): void {
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
	public function testConsumeFreeze_WhenAvailable_ReturnsTrueAndDecrements(): void {
		$houseUser = new HouseUser();
		$houseUser->setStreakFreezes(2);

		self::assertTrue($houseUser->consumeFreeze());
		self::assertEquals(1, $houseUser->getStreakFreezes());
	}

	/**
	 * ConsumeFreeze_WhenNoneAvailable_ReturnsFalse
	 */
	public function testConsumeFreeze_WhenNoneAvailable_ReturnsFalse(): void {
		$houseUser = new HouseUser();
		$houseUser->setStreakFreezes(0);

		self::assertFalse($houseUser->consumeFreeze());
		self::assertEquals(0, $houseUser->getStreakFreezes());
	}

	/**
	 * RestockFreezes_SetsToConfiguredValue
	 */
	public function testRestockFreezes_SetsToConfiguredValue(): void {
		$houseUser = new HouseUser();
		$houseUser->setStreakFreezes(0);

		$houseUser->restockFreezes(1, 2);

		self::assertEquals(1, $houseUser->getStreakFreezes());
	}

	/**
	 * RestockFreezes_RespectsCap
	 */
	public function testRestockFreezes_RespectsCap(): void {
		$houseUser = new HouseUser();
		$houseUser->setStreakFreezes(0);

		$houseUser->restockFreezes(5, 2);

		self::assertEquals(2, $houseUser->getStreakFreezes());
	}

	/**
	 * AwardBonusFreeze_IncrementsWhenBelowCap
	 */
	public function testAwardBonusFreeze_IncrementsWhenBelowCap(): void {
		$houseUser = new HouseUser();
		$houseUser->setStreakFreezes(1);

		$houseUser->awardBonusFreeze(2);

		self::assertEquals(2, $houseUser->getStreakFreezes());
	}

	/**
	 * AwardBonusFreeze_NoOpWhenAtCap
	 */
	public function testAwardBonusFreeze_NoOpWhenAtCap(): void {
		$houseUser = new HouseUser();
		$houseUser->setStreakFreezes(2);

		$houseUser->awardBonusFreeze(2);

		self::assertEquals(2, $houseUser->getStreakFreezes());
	}

	/**
	 * JsonSerialize_IncludesStreakFreezes
	 */
	public function testJsonSerialize_IncludesStreakFreezes(): void {
		$houseUser = new HouseUser();
		$houseUser->setStreakFreezes(2);

		$result = $houseUser->jsonSerialize();

		self::assertArrayHasKey('streakFreezes', $result);
		self::assertEquals(2, $result['streakFreezes']);
	}

	/**
	 * DefaultStreakFreezes_IsOne
	 */
	public function testDefaultStreakFreezes_IsOne(): void {
		$houseUser = new HouseUser();

		self::assertEquals(1, $houseUser->getStreakFreezes());
	}
}
