<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Db\Mapper;

use OCA\ChoreStrike\Db\TaskAssignee;
use OCA\ChoreStrike\Db\TaskAssigneeMapper;
use OCA\ChoreStrike\Db\TransactionExecutor;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for TaskAssigneeMapper
 */
class TaskAssigneeMapperTest extends TestCase {
	private TaskAssigneeMapper $mapper;
	private IDBConnection $db;
	private IQueryBuilder $qb;
	private IExpressionBuilder $expr;

	protected function setUp(): void {
		$this->db = $this->createMock(IDBConnection::class);
		$this->qb = $this->createMock(IQueryBuilder::class);
		$this->expr = $this->createMock(IExpressionBuilder::class);

		$this->db->method('getQueryBuilder')->willReturn($this->qb);
		$this->qb->method('expr')->willReturn($this->expr);

		$this->mapper = new TaskAssigneeMapper($this->db, new TransactionExecutor($this->db));
	}

	public function testFindByRoomTask_ReturnsAssignees(): void {
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

	public function testFindUserIdsByRoomTask_ReturnsStringIds(): void {
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

	public function testFindUserIdsByRoomTasks_EmptyInput_ReturnsEmptyMap(): void {
		$result = $this->mapper->findUserIdsByRoomTasks([]);

		$this->assertEquals([], $result);
	}

	public function testIsAssignee_ReturnsTrueWhenFound(): void {
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

	public function testIsAssignee_ReturnsFalseWhenNotFound(): void {
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

	/**
	 * DeleteByRoomTasks_EmptyInput_SkipsStatement
	 *
	 * CS-PLAN-037: the empty-array early return must not build an IN ().
	 */
	public function testDeleteByRoomTasks_EmptyInput_SkipsStatement(): void {
		$this->qb->expects($this->never())->method('delete');

		$this->mapper->deleteByRoomTasks([]);
	}

	/**
	 * DeleteByRoomTasks_BuildsInDeleteWithIntArray
	 */
	public function testDeleteByRoomTasks_BuildsInDeleteWithIntArray(): void {
		$capturedParams = [];
		$this->qb->method('createNamedParameter')->willReturnCallback(
			function ($value, $type) use (&$capturedParams) {
				$capturedParams[] = ['value' => $value, 'type' => $type];
				return 'param_' . count($capturedParams);
			}
		);
		$this->qb->method('delete')->willReturn($this->qb);
		$this->qb->method('where')->willReturn($this->qb);
		$this->qb->expects($this->once())->method('executeStatement');

		$this->mapper->deleteByRoomTasks([10, 11]);

		$this->assertSame([10, 11], $capturedParams[0]['value']);
		$this->assertSame(IQueryBuilder::PARAM_INT_ARRAY, $capturedParams[0]['type']);
	}
}
