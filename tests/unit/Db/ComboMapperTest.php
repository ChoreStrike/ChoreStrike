<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Db;

use OCA\ChoreStrike\Db\Combo;
use OCA\ChoreStrike\Db\ComboMapper;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ComboMapper
 */
class ComboMapperTest extends TestCase
{
    private ComboMapper $mapper;
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

        $this->mapper = new ComboMapper($this->db);
    }

    public function testFindActiveByUser_ReturnsCombo(): void
    {
        $this->qb->method('select')->willReturnSelf();
        $this->qb->method('from')->willReturnSelf();
        $this->qb->method('where')->willReturnSelf();
        $this->qb->method('andWhere')->willReturnSelf();
        $this->qb->method('orderBy')->willReturnSelf();
        $this->qb->method('setMaxResults')->willReturnSelf();

        $result = $this->createMock(IResult::class);
        $result->method('fetch')->willReturnOnConsecutiveCalls(
            ['id' => 1, 'house_id' => 2, 'user_id' => 'alice', 'task_count' => 5, 'is_active' => 1, 'combo_bonus_points' => 50],
            false
        );
        $this->qb->method('executeQuery')->willReturn($result);

        $combo = $this->mapper->findActiveByUser(2, 'alice');

        $this->assertInstanceOf(Combo::class, $combo);
        $this->assertEquals('alice', $combo->getUserId());
    }

    public function testFindActiveByUser_NoCombo_ReturnsNull(): void
    {
        $this->qb->method('select')->willReturnSelf();
        $this->qb->method('from')->willReturnSelf();
        $this->qb->method('where')->willReturnSelf();
        $this->qb->method('andWhere')->willReturnSelf();
        $this->qb->method('orderBy')->willReturnSelf();
        $this->qb->method('setMaxResults')->willReturnSelf();

        $result = $this->createMock(IResult::class);
        $result->method('fetch')->willReturn(false);
        $this->qb->method('executeQuery')->willReturn($result);

        $this->assertNull($this->mapper->findActiveByUser(2, 'alice'));
    }

    public function testFindActiveByHouse_ReturnsCombos(): void
    {
        $this->qb->method('select')->willReturnSelf();
        $this->qb->method('from')->willReturnSelf();
        $this->qb->method('where')->willReturnSelf();
        $this->qb->method('andWhere')->willReturnSelf();
        $this->qb->method('orderBy')->willReturnSelf();

        $result = $this->createMock(IResult::class);
        $result->method('fetch')->willReturnOnConsecutiveCalls(
            ['id' => 1, 'house_id' => 2, 'user_id' => 'alice', 'task_count' => 10, 'is_active' => 1],
            ['id' => 2, 'house_id' => 2, 'user_id' => 'bob', 'task_count' => 3, 'is_active' => 1],
            false
        );
        $this->qb->method('executeQuery')->willReturn($result);

        $combos = $this->mapper->findActiveByHouse(2);

        $this->assertCount(2, $combos);
    }
}
