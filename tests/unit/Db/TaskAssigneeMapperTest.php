<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Db;

use OCA\ChoreStrike\Db\TaskAssignee;
use OCA\ChoreStrike\Db\TaskAssigneeMapper;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for TaskAssigneeMapper
 */
class TaskAssigneeMapperTest extends TestCase
{
    private TaskAssigneeMapper $mapper;
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

        $this->mapper = new TaskAssigneeMapper($this->db);
    }

    public function testFindByRoomTask_ReturnsAssignees(): void
    {
        $this->qb->method('select')->willReturnSelf();
        $this->qb->method('from')->willReturnSelf();
        $this->qb->method('where')->willReturnSelf();
        $this->qb->method('orderBy')->willReturnSelf();

        $result = $this->createMock(IResult::class);
        $result->method('fetch')->willReturnOnConsecutiveCalls(
            ['id' => 1, 'room_task_id' => 10, 'user_id' => 'alice', 'assigned_at' => '2026-01-01', 'assigned_by' => null],
            ['id' => 2, 'room_task_id' => 10, 'user_id' => 'bob', 'assigned_at' => '2026-01-02', 'assigned_by' => 'alice'],
            false
        );
        $this->qb->method('executeQuery')->willReturn($result);

        $assignees = $this->mapper->findByRoomTask(10);

        $this->assertCount(2, $assignees);
        $this->assertInstanceOf(TaskAssignee::class, $assignees[0]);
        $this->assertEquals('alice', $assignees[0]->getUserId());
    }

    public function testFindUserIdsByRoomTask_ReturnsStringIds(): void
    {
        $this->qb->method('select')->willReturnSelf();
        $this->qb->method('from')->willReturnSelf();
        $this->qb->method('where')->willReturnSelf();
        $this->qb->method('orderBy')->willReturnSelf();

        $result = $this->createMock(IResult::class);
        $result->method('fetch')->willReturnOnConsecutiveCalls(
            ['user_id' => 'alice'],
            ['user_id' => 'bob'],
            false
        );
        $this->qb->method('executeQuery')->willReturn($result);

        $ids = $this->mapper->findUserIdsByRoomTask(10);

        $this->assertEquals(['alice', 'bob'], $ids);
    }

    public function testFindUserIdsByRoomTasks_EmptyInput_ReturnsEmptyMap(): void
    {
        $result = $this->mapper->findUserIdsByRoomTasks([]);

        $this->assertEquals([], $result);
    }

    public function testMergeLegacyAssignee_AppendsNonDuplicate(): void
    {
        $result = TaskAssigneeMapper::mergeLegacyAssignee(['alice'], 'bob');

        $this->assertEquals(['alice', 'bob'], $result);
    }

    public function testMergeLegacyAssignee_SkipsDuplicate(): void
    {
        $result = TaskAssigneeMapper::mergeLegacyAssignee(['alice'], 'alice');

        $this->assertEquals(['alice'], $result);
    }

    public function testMergeLegacyAssignee_SkipsNull(): void
    {
        $result = TaskAssigneeMapper::mergeLegacyAssignee(['alice'], null);

        $this->assertEquals(['alice'], $result);
    }

    public function testMergeLegacyAssignee_SkipsEmpty(): void
    {
        $result = TaskAssigneeMapper::mergeLegacyAssignee(['alice'], '');

        $this->assertEquals(['alice'], $result);
    }

    public function testIsAssignee_ReturnsTrueWhenFound(): void
    {
        $this->qb->method('select')->willReturnSelf();
        $this->qb->method('from')->willReturnSelf();
        $this->qb->method('where')->willReturnSelf();
        $this->qb->method('andWhere')->willReturnSelf();
        $this->qb->method('setMaxResults')->willReturnSelf();

        $result = $this->createMock(IResult::class);
        $result->method('fetchOne')->willReturn('alice');
        $this->qb->method('executeQuery')->willReturn($result);

        $this->assertTrue($this->mapper->isAssignee(10, 'alice'));
    }

    public function testIsAssignee_ReturnsFalseWhenNotFound(): void
    {
        $this->qb->method('select')->willReturnSelf();
        $this->qb->method('from')->willReturnSelf();
        $this->qb->method('where')->willReturnSelf();
        $this->qb->method('andWhere')->willReturnSelf();
        $this->qb->method('setMaxResults')->willReturnSelf();

        $result = $this->createMock(IResult::class);
        $result->method('fetchOne')->willReturn(false);
        $this->qb->method('executeQuery')->willReturn($result);

        $this->assertFalse($this->mapper->isAssignee(10, 'nobody'));
    }
}
