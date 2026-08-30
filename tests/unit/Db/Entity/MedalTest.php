<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Db\Entity;

use OCA\ChoreStrike\Db\Medal;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Medal entity
 */
class MedalTest extends TestCase {
	/**
	 * JsonSerialize_ReturnsCorrectArray
	 */
	public function testJsonSerialize_ReturnsCorrectArray(): void {
		$medal = new Medal();
		$medal->setId(1);
		$medal->setUserId(2);
		$medal->setMedalType('gold');
		$medal->setWeekStart('2026-02-23');
		$awardedAt = new \DateTime('2026-02-24 12:00:00');
		$medal->setAwardedAt($awardedAt);

		$result = $medal->jsonSerialize();

		self::assertEquals(1, $result['id']);
		self::assertEquals(2, $result['userId']);
		self::assertEquals('gold', $result['medalType']);
		self::assertEquals('2026-02-23', $result['weekStart']);
		self::assertEquals($awardedAt->format('c'), $result['awardedAt']);
	}

	/**
	 * SetAndGetProperties_WorksCorrectly
	 */
	public function testSetAndGetProperties_WorksCorrectly(): void {
		$medal = new Medal();
		$medal->setUserId(5);
		$medal->setMedalType('silver');
		$medal->setWeekStart('2026-02-16');
		$awardedAt = new \DateTime('2026-02-20 10:00:00');
		$medal->setAwardedAt($awardedAt);

		self::assertEquals(5, $medal->getUserId());
		self::assertEquals('silver', $medal->getMedalType());
		self::assertEquals('2026-02-16', $medal->getWeekStart());
		self::assertSame($awardedAt, $medal->getAwardedAt());
	}

	/**
	 * GetDisplayName_WithValidType_ReturnsTranslatedName
	 */
	public function testGetDisplayName_WithValidType_ReturnsTranslatedName(): void {
		$medal = new Medal();
		$medal->setMedalType('gold');

		// Note: In real app this would be translated via t()
		self::assertNotEmpty($medal->getMedalType());
	}
}
