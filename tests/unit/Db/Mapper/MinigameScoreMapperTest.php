<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Db\Mapper;

use OCA\ChoreStrike\Db\MinigameScore;
use OCA\ChoreStrike\Db\MinigameScoreMapper;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for MinigameScoreMapper
 */
class MinigameScoreMapperTest extends TestCase {
	private MinigameScoreMapper $mapper;
	private IDBConnection $db;
	private IQueryBuilder $qb;
	private IExpressionBuilder $expr;

	protected function setUp(): void {
		$this->db = $this->createMock(IDBConnection::class);
		$this->qb = $this->createMock(IQueryBuilder::class);
		$this->expr = $this->createMock(IExpressionBuilder::class);

		$this->db->method('getQueryBuilder')->willReturn($this->qb);
		$this->qb->method('expr')->willReturn($this->expr);

		$this->mapper = new MinigameScoreMapper($this->db);
	}

	public function testGetLeaderboard_LimitsResults(): void {
		$this->qb->method('select')->willReturnSelf();
		$this->qb->method('from')->willReturnSelf();
		$this->qb->method('where')->willReturnSelf();
		$this->qb->method('andWhere')->willReturnSelf();
		$this->qb->method('orderBy')->willReturnSelf();
		$this->qb->method('setMaxResults')->willReturnSelf();

		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturnOnConsecutiveCalls(
			['id' => 1, 'house_id' => 1, 'user_id' => 'alice', 'game_id' => 'snake', 'score' => 200],
			false
		);
		$this->qb->method('executeQuery')->willReturn($result);

		$scores = $this->mapper->getLeaderboard(1, 'snake', 5);

		$this->assertCount(1, $scores);
	}

	public function testGetPersonalBest_ReturnsNullWhenNoScores(): void {
		$this->qb->method('select')->willReturnSelf();
		$this->qb->method('from')->willReturnSelf();
		$this->qb->method('where')->willReturnSelf();
		$this->qb->method('andWhere')->willReturnSelf();
		$this->qb->method('orderBy')->willReturnSelf();
		$this->qb->method('setMaxResults')->willReturnSelf();

		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturn(false);
		$this->qb->method('executeQuery')->willReturn($result);

		$this->assertNull($this->mapper->getPersonalBest(1, 'alice', 'snake'));
	}

	public function testMinigameScoreEntity_DefaultGameId(): void {
		$score = new MinigameScore();

		$this->assertEquals('snake', $score->getGameId());
		$this->assertEquals(0, $score->getScore());
	}

	public function testMinigameScoreEntity_JsonSerialize(): void {
		$score = new MinigameScore();
		$score->setId(1);
		$score->setHouseId(2);
		$score->setUserId('alice');
		$score->setGameId('snake');
		$score->setScore(150);
		$score->setCreatedAt(new \DateTime('2026-01-15'));

		$json = $score->jsonSerialize();

		$this->assertEquals(1, $json['id']);
		$this->assertEquals(150, $json['score']);
		// WP3 cutover: scores-only — no boost metadata is serialized.
		$this->assertArrayNotHasKey('boostsUsed', $json);
	}
}
