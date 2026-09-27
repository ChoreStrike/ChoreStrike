<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Db\Entity;

use OCA\ChoreStrike\Db\MinigameScore;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for MinigameScore entity (CS-PLAN-070 WP3: scores-only —
 * the boost metadata retired with the disappearing economy).
 */
class MinigameScoreTest extends TestCase {
	/**
	 * JsonSerialize_ReturnsCorrectArray
	 */
	public function testJsonSerialize_ReturnsCorrectArray(): void {
		$score = new MinigameScore();
		$score->setId(7);
		$score->setHouseId(3);
		$score->setUserId('mom');
		$score->setGameId('snake');
		$score->setScore(420);
		$createdAt = new \DateTime('2026-08-27 18:30:00');
		$score->setCreatedAt($createdAt);

		$result = $score->jsonSerialize();

		self::assertEquals(7, $result['id']);
		self::assertEquals(3, $result['houseId']);
		self::assertEquals('mom', $result['userId']);
		self::assertEquals('snake', $result['gameId']);
		self::assertEquals(420, $result['score']);
		self::assertEquals($createdAt->format('c'), $result['createdAt']);
		// WP3 cutover: no boost metadata on the score-only contract.
		self::assertArrayNotHasKey('boostsUsed', $result);
		self::assertArrayNotHasKey('coinsEarned', $result);
	}

	/**
	 * JsonSerialize_WithNullOptionals_ReturnsNullFields
	 */
	public function testJsonSerialize_WithNullOptionals_ReturnsNullFields(): void {
		$score = new MinigameScore();

		$result = $score->jsonSerialize();

		self::assertNull($result['id']);
		self::assertNull($result['createdAt']);
	}

	/**
	 * SetAndGetProperties_WorksCorrectly
	 */
	public function testSetAndGetProperties_WorksCorrectly(): void {
		$score = new MinigameScore();
		$score->setHouseId(12);
		$score->setUserId('kid');
		$score->setGameId('snake');
		$score->setScore(1337);
		$createdAt = new \DateTime('2026-08-27 09:00:00');
		$score->setCreatedAt($createdAt);

		self::assertEquals(12, $score->getHouseId());
		self::assertEquals('kid', $score->getUserId());
		self::assertEquals('snake', $score->getGameId());
		self::assertEquals(1337, $score->getScore());
		self::assertSame($createdAt, $score->getCreatedAt());
	}

	/**
	 * Defaults_NewEntity_HasSnakeGameAndZeroScore
	 */
	public function testDefaults_NewEntity_HasSnakeGameAndZeroScore(): void {
		$score = new MinigameScore();

		self::assertEquals('snake', $score->getGameId());
		self::assertEquals(0, $score->getScore());
	}
}
