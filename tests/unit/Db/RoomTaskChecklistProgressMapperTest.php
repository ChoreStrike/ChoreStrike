<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Db;

use OCA\ChoreStrike\Db\RoomTaskChecklistProgress;
use OCA\ChoreStrike\Db\RoomTaskChecklistProgressMapper;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for RoomTaskChecklistProgressMapper
 */
class RoomTaskChecklistProgressMapperTest extends TestCase
{
    private RoomTaskChecklistProgressMapper $mapper;
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

        $this->mapper = new RoomTaskChecklistProgressMapper($this->db);
    }

    public function testFindByRoomTaskId_ReturnsProgress(): void
    {
        $this->qb->method('select')->willReturnSelf();
        $this->qb->method('from')->willReturnSelf();
        $this->qb->method('where')->willReturnSelf();

        $result = $this->createMock(IResult::class);
        $result->method('fetch')->willReturnOnConsecutiveCalls(
            ['id' => 1, 'room_task_id' => 10, 'checklist_item_id' => 5, 'is_checked' => 1],
            ['id' => 2, 'room_task_id' => 10, 'checklist_item_id' => 6, 'is_checked' => 0],
            false
        );
        $this->qb->method('executeQuery')->willReturn($result);

        $progress = $this->mapper->findByRoomTaskId(10);

        $this->assertCount(2, $progress);
        $this->assertInstanceOf(RoomTaskChecklistProgress::class, $progress[0]);
    }

    public function testFindByRoomTaskId_NoProgress_ReturnsEmpty(): void
    {
        $this->qb->method('select')->willReturnSelf();
        $this->qb->method('from')->willReturnSelf();
        $this->qb->method('where')->willReturnSelf();

        $result = $this->createMock(IResult::class);
        $result->method('fetch')->willReturn(false);
        $this->qb->method('executeQuery')->willReturn($result);

        $this->assertEmpty($this->mapper->findByRoomTaskId(999));
    }
}
