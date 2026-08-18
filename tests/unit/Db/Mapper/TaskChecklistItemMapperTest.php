<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Db\Mapper;

use OCA\ChoreStrike\Db\TaskChecklistItem;
use OCA\ChoreStrike\Db\TaskChecklistItemMapper;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for TaskChecklistItemMapper
 */
class TaskChecklistItemMapperTest extends TestCase {
	private TaskChecklistItemMapper $mapper;
	private IDBConnection $db;
	private IQueryBuilder $qb;
	private IExpressionBuilder $expr;

	protected function setUp(): void {
		$this->db = $this->createMock(IDBConnection::class);
		$this->qb = $this->createMock(IQueryBuilder::class);
		$this->expr = $this->createMock(IExpressionBuilder::class);

		$this->db->method('getQueryBuilder')->willReturn($this->qb);
		$this->qb->method('expr')->willReturn($this->expr);

		$this->mapper = new TaskChecklistItemMapper($this->db);
	}

	public function testFindByTaskId_ReturnsItems(): void {
		$this->qb->method('select')->willReturnSelf();
		$this->qb->method('from')->willReturnSelf();
		$this->qb->method('where')->willReturnSelf();
		$this->qb->method('orderBy')->willReturnSelf();

		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturnOnConsecutiveCalls(
			['id' => 1, 'task_id' => 5, 'label' => 'Step 1', 'sort_order' => 0],
			['id' => 2, 'task_id' => 5, 'label' => 'Step 2', 'sort_order' => 1],
			false
		);
		$this->qb->method('executeQuery')->willReturn($result);

		$items = $this->mapper->findByTaskId(5);

		$this->assertCount(2, $items);
		$this->assertInstanceOf(TaskChecklistItem::class, $items[0]);
		$this->assertEquals('Step 1', $items[0]->getLabel());
	}

	public function testFindByTaskId_NoItems_ReturnsEmpty(): void {
		$this->qb->method('select')->willReturnSelf();
		$this->qb->method('from')->willReturnSelf();
		$this->qb->method('where')->willReturnSelf();
		$this->qb->method('orderBy')->willReturnSelf();

		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturn(false);
		$this->qb->method('executeQuery')->willReturn($result);

		$this->assertEmpty($this->mapper->findByTaskId(999));
	}
}
