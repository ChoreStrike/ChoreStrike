<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Db;

use OCA\ChoreStrike\Db\UserStats;
use OCA\ChoreStrike\Db\UserStatsMapper;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for UserStatsMapper
 */
class UserStatsMapperTest extends TestCase
{
    private UserStatsMapper $mapper;
    private IDBConnection $db;
    private IQueryBuilder $qb;
    private IExpressionBuilder $expr;

    protected function setUp(): void
    {
        $this->db = $this->createMock(IDBConnection::class);
        $this->qb = $this->createMock(IQueryBuilder::class);
        $this->expr = $this->createMock(IExpressionBuilder::class);

        $this->db->method('getQueryBuilder')->willReturn($this->qb);
        $this->qb->method('expr')->willReturn($this->expr);

        $this->mapper = new UserStatsMapper($this->db);
    }

    public function testFindByHouseAndUser_ReturnsStats(): void
    {
        $this->qb->method('select')->willReturnSelf();
        $this->qb->method('from')->willReturnSelf();
        $this->qb->method('where')->willReturnSelf();
        $this->qb->method('andWhere')->willReturnSelf();

        $result = $this->createMock(IResult::class);
        $result->method('fetch')->willReturnOnConsecutiveCalls(
            ['id' => 1, 'house_id' => 2, 'user_id' => 'alice', 'total_points' => 500, 'current_streak' => 7],
            false
        );
        $this->qb->method('executeQuery')->willReturn($result);

        $stats = $this->mapper->findByHouseAndUser(2, 'alice');

        $this->assertInstanceOf(UserStats::class, $stats);
        $this->assertEquals('alice', $stats->getUserId());
    }

    public function testFindByHouse_ReturnsAllUsers(): void
    {
        $this->qb->method('select')->willReturnSelf();
        $this->qb->method('from')->willReturnSelf();
        $this->qb->method('where')->willReturnSelf();
        $this->qb->method('orderBy')->willReturnSelf();

        $result = $this->createMock(IResult::class);
        $result->method('fetch')->willReturnOnConsecutiveCalls(
            ['id' => 1, 'house_id' => 2, 'user_id' => 'alice', 'total_points' => 500],
            ['id' => 2, 'house_id' => 2, 'user_id' => 'bob', 'total_points' => 300],
            false
        );
        $this->qb->method('executeQuery')->willReturn($result);

        $stats = $this->mapper->findByHouse(2);

        $this->assertCount(2, $stats);
    }

    public function testGetLeaderboard_LimitsResults(): void
    {
        $this->qb->method('select')->willReturnSelf();
        $this->qb->method('from')->willReturnSelf();
        $this->qb->method('where')->willReturnSelf();
        $this->qb->method('orderBy')->willReturnSelf();
        $this->qb->method('setMaxResults')->willReturnSelf();

        $result = $this->createMock(IResult::class);
        $result->method('fetch')->willReturn(false);
        $this->qb->method('executeQuery')->willReturn($result);

        $stats = $this->mapper->getLeaderboard(2, 5);

        $this->assertEmpty($stats);
    }
}
