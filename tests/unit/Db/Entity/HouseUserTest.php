<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Db\Entity;

use OCA\ChoreStrike\Db\HouseUser;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for HouseUser entity (CS-PLAN-070 WP3 shape: exact
 * contribution_minor derived from valid work shares; freeze inventory
 * retired with the freeze lifecycle).
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
		$houseUser->setRole('owner');
		$houseUser->setContributionMinor(1500);
		$houseUser->setCurrentStreak(5);
		$houseUser->setLongestStreak(10);
		$joinedAt = new \DateTime('2026-02-01 10:00:00');
		$houseUser->setJoinedAt($joinedAt);
		$lastActivity = new \DateTime('2026-02-24 15:30:00');
		$houseUser->setLastActivity($lastActivity);
		$houseUser->setWalletId(42);

		$result = $houseUser->jsonSerialize();

		self::assertEquals(1, $result['id']);
		self::assertEquals(2, $result['houseId']);
		self::assertEquals('user123', $result['nextcloudUserId']);
		self::assertEquals('Test User', $result['displayName']);
		self::assertEquals('owner', $result['role']);
		self::assertEquals(1500, $result['contributionMinor']);
		self::assertEquals(5, $result['currentStreak']);
		self::assertEquals(10, $result['longestStreak']);
		self::assertEquals($joinedAt->format('c'), $result['joinedAt']);
		self::assertEquals($lastActivity->format('c'), $result['lastActivity']);
		// The wallet binding is financial identity, never serialized.
		self::assertArrayNotHasKey('walletId', $result);
		// WP3 cutover: freeze inventory and whole-unit contribution are
		// gone; the lastFreezeDate placeholder left with its consumers.
		self::assertArrayNotHasKey('streakFreezes', $result);
		self::assertArrayNotHasKey('contributionScore', $result);
		self::assertArrayNotHasKey('lastFreezeDate', $result);
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
	 * AddContributionMinor_AccumulatesExactMinorUnits
	 *
	 * WP3 §3.1: contribution is exact energy minor units derived from the
	 * member's valid selected work shares (share energy_minor deltas).
	 */
	public function testAddContributionMinor_AccumulatesExactMinorUnits(): void {
		$houseUser = new HouseUser();
		$houseUser->setContributionMinor(100);

		$houseUser->addContributionMinor(34);
		$houseUser->addContributionMinor(-33);

		self::assertEquals(101, $houseUser->getContributionMinor());
	}

	/**
	 * DefaultContributionMinor_IsZero
	 */
	public function testDefaultContributionMinor_IsZero(): void {
		$houseUser = new HouseUser();

		self::assertEquals(0, $houseUser->getContributionMinor());
		self::assertNull($houseUser->getWalletId());
	}

	/**
	 * IsOwnerAndIsChild_RolePredicates
	 */
	public function testIsOwnerAndIsChild_RolePredicates(): void {
		$owner = new HouseUser();
		$owner->setRole('owner');
		$child = new HouseUser();
		$child->setRole('child');
		$member = new HouseUser();
		$member->setRole('member');

		self::assertTrue($owner->isOwner());
		self::assertFalse($owner->isChild());
		self::assertTrue($child->isChild());
		self::assertFalse($child->isOwner());
		self::assertFalse($member->isOwner());
		self::assertFalse($member->isChild());
	}

	/**
	 * AddUnlockedTitle_IdempotentAndMutatesCache
	 */
	public function testAddUnlockedTitle_IdempotentAndMutatesCache(): void {
		$houseUser = new HouseUser();

		self::assertTrue($houseUser->addUnlockedTitle('family_rhythm.helper'));
		self::assertFalse($houseUser->addUnlockedTitle('family_rhythm.helper'));
		self::assertEquals(['family_rhythm.helper'], $houseUser->getUnlockedTitles());
	}
}
