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
		$this->qb->method('addOrderBy')->willReturnSelf();
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
		$this->qb->method('addOrderBy')->willReturnSelf();
		$this->qb->method('setMaxResults')->willReturnSelf();

		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturn(false);
		$this->qb->method('executeQuery')->willReturn($result);

		$this->assertNull($this->mapper->getPersonalBest(1, 'alice', 'snake'));
	}

	/**
	 * CR-053-11 Snake remainder (CS-PLAN-081 H5): every ordered score read
	 * must tiebreak equal scores by ascending id so row selection is
	 * deterministic; getUserRank stays tie-tolerant by contract.
	 *
	 * @dataProvider orderedReadProvider
	 */
	public function testOrderedReads_TiebreakEqualScoresByIdAsc(string $method, array $args): void {
		$callOrder = [];
		$this->qb->method('select')->willReturnSelf();
		$this->qb->method('from')->willReturnSelf();
		$this->qb->method('where')->willReturnSelf();
		$this->qb->method('andWhere')->willReturnSelf();
		$this->qb->method('orderBy')->willReturnCallback(function ($field, $dir) use (&$callOrder) {
			$callOrder[] = ['orderBy', $field, $dir];
			return $this->qb;
		});
		$this->qb->method('addOrderBy')->willReturnCallback(function ($field, $dir) use (&$callOrder) {
			$callOrder[] = ['addOrderBy', $field, $dir];
			return $this->qb;
		});
		$this->qb->method('setMaxResults')->willReturnSelf();

		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturn(false);
		$this->qb->method('executeQuery')->willReturn($result);

		$this->mapper->{$method}(...$args);

		$this->assertSame(
			[['orderBy', 'score', 'DESC'], ['addOrderBy', 'id', 'ASC']],
			$callOrder,
			"$method must order score DESC with an id ASC tiebreak"
		);
	}

	/**
	 * @return array<string, array{0: string, 1: array}>
	 */
	public static function orderedReadProvider(): array {
		return [
			'global leaderboard' => ['getLeaderboard', [1, 'snake', 10]],
			'personal best row' => ['getPersonalBest', [1, 'alice', 'snake']],
			'personal history' => ['findByUserAndGame', [1, 'alice', 'snake', 10]],
			'period leaderboard' => ['getLeaderboardForPeriod', [1, 'snake', new \DateTime('2026-01-01'), 10]],
		];
	}

	public function testGetUserRank_KeepsEqualScoresTied(): void {
		// Rank counting uses a strict greater-than comparison, so equal
		// best scores stay tied — no id tiebreak is applied here.
		$this->qb->method('select')->willReturnSelf();
		$this->qb->method('from')->willReturnSelf();
		$this->qb->method('where')->willReturnSelf();
		$this->qb->method('andWhere')->willReturnSelf();
		$func = $this->createMock(\OCP\DB\QueryBuilder\IFunctionBuilder::class);
		$func->method('max')->willReturn('MAX(score)');
		$func->method('count')->willReturn('COUNT(id)');
		$this->qb->method('func')->willReturn($func);
		$this->qb->method('createFunction')->willReturn('COUNT(DISTINCT user_id)');

		$result = $this->createMock(IResult::class);
		$result->method('fetchOne')->willReturnOnConsecutiveCalls(200, 2);
		$this->qb->method('executeQuery')->willReturn($result);

		$this->assertSame(3, $this->mapper->getUserRank(1, 'alice', 'snake'));
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
