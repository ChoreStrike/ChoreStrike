<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Db\Mapper;

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
class RoomTaskChecklistProgressMapperTest extends TestCase {
	private RoomTaskChecklistProgressMapper $mapper;
	private IDBConnection $db;
	private IQueryBuilder $qb;
	private IExpressionBuilder $expr;

	protected function setUp(): void {
		$this->db = $this->createMock(IDBConnection::class);
		$this->qb = $this->createMock(IQueryBuilder::class);
		$this->expr = $this->createMock(IExpressionBuilder::class);

		$this->db->method('getQueryBuilder')->willReturn($this->qb);
		$this->qb->method('expr')->willReturn($this->expr);

		// CS-PLAN-071 WP0: the mapper no longer owns a transaction —
		// ChecklistService::toggleItem scopes it house-first.
		$this->mapper = new RoomTaskChecklistProgressMapper($this->db);
	}

	public function testFindByRoomTaskId_ReturnsProgress(): void {
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

	public function testFindByRoomTaskId_NoProgress_ReturnsEmpty(): void {
		$this->qb->method('select')->willReturnSelf();
		$this->qb->method('from')->willReturnSelf();
		$this->qb->method('where')->willReturnSelf();

		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturn(false);
		$this->qb->method('executeQuery')->willReturn($result);

		$this->assertEmpty($this->mapper->findByRoomTaskId(999));
	}

	/**
	 * DeleteByRoomTaskIds_EmptyInput_SkipsStatement
	 *
	 * CS-PLAN-037: the empty-array early return must not build an IN ().
	 */
	public function testDeleteByRoomTaskIds_EmptyInput_SkipsStatement(): void {
		$this->qb->expects($this->never())->method('delete');

		$this->mapper->deleteByRoomTaskIds([]);
	}

	/**
	 * DeleteByRoomTaskIds_BuildsInDeleteWithIntArray
	 */
	public function testDeleteByRoomTaskIds_BuildsInDeleteWithIntArray(): void {
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

		$this->mapper->deleteByRoomTaskIds([10, 11]);

		$this->assertSame([10, 11], $capturedParams[0]['value']);
		$this->assertSame(IQueryBuilder::PARAM_INT_ARRAY, $capturedParams[0]['type']);
	}

	/** @dataProvider unexpectedInsertFailures */
	public function testToggleLocked_UnexpectedInsertFailure_PropagatesWithoutFurtherSql(\Throwable $failure): void {
		$mapper = $this->getMockBuilder(RoomTaskChecklistProgressMapper::class)
			->setConstructorArgs([$this->db])->onlyMethods(['findForUpdate', 'insert'])->getMock();
		$mapper->method('findForUpdate')->willReturn(null);
		$mapper->method('insert')->willThrowException($failure);
		// A deadlock can abort the whole transaction: no savepoint cleanup
		// may mask the original error that the owning executor must handle.
		$this->db->expects($this->once())->method('executeStatement')->with('SAVEPOINT cs_checklist_insert');
		$this->expectExceptionObject($failure);
		$mapper->toggleLocked(1, 2, 3, 4, 5);
	}

	public static function unexpectedInsertFailures(): array {
		return [
			'non-database failure' => [new \RuntimeException('injected insert failure')],
			'non-unique database failure' => [new \OCP\DB\Exception('injected deadlock')],
		];
	}
}
