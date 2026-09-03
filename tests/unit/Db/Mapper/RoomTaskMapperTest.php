<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Db\Mapper;

use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\Task;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for RoomTaskMapper
 */
class RoomTaskMapperTest extends TestCase {
	private RoomTaskMapper $mapper;
	private IDBConnection $db;
	private IQueryBuilder $qb;
	private IExpressionBuilder $expr;

	protected function setUp(): void {
		$this->db = $this->createMock(IDBConnection::class);
		$this->qb = $this->createMock(IQueryBuilder::class);
		$this->expr = $this->createMock(IExpressionBuilder::class);

		$this->db->method('getQueryBuilder')
			->willReturn($this->qb);
		$this->qb->method('expr')
			->willReturn($this->expr);

		$this->mapper = new RoomTaskMapper($this->db);
	}

	/**
	 * FindByRoomId_WithActiveOnly_ReturnsActiveTasks
	 */
	public function testFindByRoomId_WithActiveOnly_ReturnsActiveTasks(): void {
		$roomId = 1;

		$this->qb->expects($this->once())
			->method('select')
			->with('*')
			->willReturn($this->qb);
		$this->qb->expects($this->once())
			->method('from')
			->with('chorestrike_room_tasks')
			->willReturn($this->qb);
		$this->qb->expects($this->any())
			->method('where')
			->willReturn($this->qb);
		$this->qb->expects($this->any())
			->method('andWhere')
			->willReturn($this->qb);
		$this->qb->expects($this->once())
			->method('orderBy')
			->with('next_due', 'ASC')
			->willReturn($this->qb);

		$mockResult = $this->createMock(IResult::class);
		$mockResult->method('fetch')
			->willReturnOnConsecutiveCalls([
				'id' => 1,
				'room_id' => $roomId,
				'task_id' => 5,
				'is_active' => true,
				'next_due' => '2026-02-25 10:00:00',
				'assigned_to_user_id' => 'user1'
			], false);

		$this->qb->expects($this->once())
			->method('executeQuery')
			->willReturn($mockResult);

		$results = $this->mapper->findByRoomId($roomId, true);

		$this->assertCount(1, $results);
		$this->assertInstanceOf(RoomTask::class, $results[0]);
		$this->assertTrue($results[0]->getIsActive());
	}

	/**
	 * FindOverdueByHouse_WithHouseId_ScopesQueryByHouse
	 *
	 * Verifies the house-scoped overdue query delegates to the house JOIN
	 * chain and returns entities. Cross-house isolation is verified by the
	 * integration suite on a real DB (AuthorizationIntegrationTest).
	 */
	public function testFindOverdueByHouse_WithHouseId_ScopesQueryByHouse(): void {
		$houseId = 1;
		$roomId = 2;

		$this->qb->expects($this->once())->method('select')->willReturn($this->qb);
		$this->qb->expects($this->once())->method('from')->willReturn($this->qb);
		$this->qb->expects($this->exactly(2))->method('innerJoin')->willReturn($this->qb);
		$this->qb->expects($this->any())->method('where')->willReturn($this->qb);
		$this->qb->expects($this->any())->method('andWhere')->willReturn($this->qb);
		$this->qb->expects($this->any())->method('orderBy')->willReturn($this->qb);

		$mockResult = $this->createMock(IResult::class);
		$mockResult->method('fetch')
			->willReturnOnConsecutiveCalls([
				'id' => 1,
				'room_id' => $roomId,
				'task_id' => 5,
				'is_active' => true,
				'next_due' => '2026-02-20 10:00:00',
			], false);

		$this->qb->expects($this->once())->method('executeQuery')->willReturn($mockResult);

		$results = $this->mapper->findOverdueByHouse($houseId, $roomId);

		$this->assertCount(1, $results);
	}

	/**
	 * Exists_WithExistingTask_ReturnsTrue
	 */
	public function testExists_WithExistingTask_ReturnsTrue(): void {
		$roomId = 1;
		$taskId = 5;

		$this->qb->expects($this->once())
			->method('select')
			->with('id')
			->willReturn($this->qb);
		$this->qb->expects($this->once())
			->method('from')
			->willReturn($this->qb);
		$this->qb->expects($this->any())
			->method('where')
			->willReturn($this->qb);
		$this->qb->expects($this->any())
			->method('andWhere')
			->willReturn($this->qb);

		$mockResult = $this->createMock(IResult::class);
		$mockResult->method('fetchOne')
			->willReturn(1); // ID exists

		$this->qb->expects($this->once())
			->method('executeQuery')
			->willReturn($mockResult);

		$result = $this->mapper->exists($roomId, $taskId);

		$this->assertTrue($result);
	}

	/**
	 * Exists_WithNonExistingTask_ReturnsFalse
	 */
	public function testExists_WithNonExistingTask_ReturnsFalse(): void {
		$roomId = 1;
		$taskId = 999;

		$this->qb->expects($this->once())
			->method('select')
			->willReturn($this->qb);
		$this->qb->expects($this->once())
			->method('from')
			->willReturn($this->qb);
		$this->qb->expects($this->any())
			->method('where')
			->willReturn($this->qb);

		$mockResult = $this->createMock(IResult::class);
		$mockResult->method('fetchOne')
			->willReturn(false); // No ID found

		$this->qb->expects($this->once())
			->method('executeQuery')
			->willReturn($mockResult);

		$result = $this->mapper->exists($roomId, $taskId);

		$this->assertFalse($result);
	}

	/**
	 * FindIdsByRoomIds_EmptyInput_ReturnsEmptyWithoutQuery
	 *
	 * CS-PLAN-037: the empty-array early return must not build an IN ().
	 */
	public function testFindIdsByRoomIds_EmptyInput_ReturnsEmptyWithoutQuery(): void {
		$this->qb->expects($this->never())->method('select');

		$this->assertSame([], $this->mapper->findIdsByRoomIds([]));
	}

	/**
	 * FindIdsByRoomIds_BuildsInQueryAndCastsIdsToInt
	 */
	public function testFindIdsByRoomIds_BuildsInQueryAndCastsIdsToInt(): void {
		$capturedParams = [];
		$this->qb->method('createNamedParameter')->willReturnCallback(
			function ($value, $type) use (&$capturedParams) {
				$capturedParams[] = ['value' => $value, 'type' => $type];
				return 'param_' . count($capturedParams);
			}
		);
		$this->qb->method('select')->willReturn($this->qb);
		$this->qb->method('from')->willReturn($this->qb);
		$this->qb->method('where')->willReturn($this->qb);

		$mockResult = $this->createMock(IResult::class);
		$mockResult->method('fetch')->willReturnOnConsecutiveCalls(
			['id' => '10'],
			['id' => '11'],
			false
		);
		$this->qb->method('executeQuery')->willReturn($mockResult);

		$this->assertSame([10, 11], $this->mapper->findIdsByRoomIds(['5', 6]));
		$this->assertSame([5, 6], $capturedParams[0]['value']);
		$this->assertSame(IQueryBuilder::PARAM_INT_ARRAY, $capturedParams[0]['type']);
	}

	/**
	 * FindIdsByRoomId_DelegatesToPluralVariant
	 */
	public function testFindIdsByRoomId_DelegatesToPluralVariant(): void {
		$this->qb->method('select')->willReturn($this->qb);
		$this->qb->method('from')->willReturn($this->qb);
		$this->qb->method('where')->willReturn($this->qb);

		$mockResult = $this->createMock(IResult::class);
		$mockResult->method('fetch')->willReturnOnConsecutiveCalls(['id' => '42'], false);
		$this->qb->method('executeQuery')->willReturn($mockResult);

		$this->assertSame([42], $this->mapper->findIdsByRoomId(7));
	}

	/**
	 * DeleteByRoomIds_EmptyInput_SkipsStatement
	 */
	public function testDeleteByRoomIds_EmptyInput_SkipsStatement(): void {
		$this->qb->expects($this->never())->method('delete');

		$this->mapper->deleteByRoomIds([]);
	}

	/**
	 * DeleteByRoomIds_BuildsInDeleteWithIntArray
	 */
	public function testDeleteByRoomIds_BuildsInDeleteWithIntArray(): void {
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

		$this->mapper->deleteByRoomIds([5, 6]);

		$this->assertSame([5, 6], $capturedParams[0]['value']);
		$this->assertSame(IQueryBuilder::PARAM_INT_ARRAY, $capturedParams[0]['type']);
	}

	/**
	 * DeleteByRoomId_DelegatesToPluralVariant
	 */
	public function testDeleteByRoomId_DelegatesToPluralVariant(): void {
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

		$this->mapper->deleteByRoomId(5);

		$this->assertSame([5], $capturedParams[0]['value']);
	}

	/**
	 * FindByIdsScopedToHouse_EmptyInput_ReturnsEmptyWithoutQuery
	 */
	public function testFindByIdsScopedToHouse_EmptyInput_ReturnsEmptyWithoutQuery(): void {
		$this->qb->expects($this->never())->method('select');

		$this->assertSame([], $this->mapper->findByIdsScopedToHouse([], 1));
	}

	/**
	 * FindByIdsScopedToHouse_BuildsHouseScopedJoinWithInClause
	 *
	 * CS-PLAN-037: the batch pre-validation fetch joins rooms → floors
	 * on floors.house_id and filters the requested ids in one query;
	 * ids of other houses simply never come back.
	 */
	public function testFindByIdsScopedToHouse_BuildsHouseScopedJoinWithInClause(): void {
		$capturedParams = [];
		$this->qb->method('createNamedParameter')->willReturnCallback(
			function ($value, $type) use (&$capturedParams) {
				$capturedParams[] = ['value' => $value, 'type' => $type];
				return 'param_' . count($capturedParams);
			}
		);
		$this->qb->method('select')->willReturn($this->qb);
		$this->qb->method('from')->willReturn($this->qb);
		$this->qb->expects($this->exactly(2))->method('innerJoin')->willReturn($this->qb);
		$this->qb->method('where')->willReturn($this->qb);
		$this->qb->method('andWhere')->willReturn($this->qb);

		$mockResult = $this->createMock(IResult::class);
		$mockResult->method('fetch')->willReturnOnConsecutiveCalls(
			['id' => 2, 'room_id' => 5, 'task_id' => 7, 'is_active' => true, 'next_due' => '2026-08-20 10:00:00'],
			false
		);
		$this->qb->method('executeQuery')->willReturn($mockResult);

		$results = $this->mapper->findByIdsScopedToHouse([1, 2, 3], 9);

		$this->assertCount(1, $results);
		$this->assertInstanceOf(RoomTask::class, $results[0]);
		$this->assertSame(2, $results[0]->getId());

		// First param: house scope; second: the IN id list.
		$this->assertSame(9, $capturedParams[0]['value']);
		$this->assertSame([1, 2, 3], $capturedParams[1]['value']);
		$this->assertSame(IQueryBuilder::PARAM_INT_ARRAY, $capturedParams[1]['type']);
	}

	/**
	 * MarkCompleted_WithOnceRecurrence_DeactivatesTask
	 */
	public function testMarkCompleted_WithOnceRecurrence_DeactivatesTask(): void {
		$roomTask = new RoomTask();
		$roomTask->setIsActive(true);
		$roomTask->setNextDue(new \DateTime('2026-02-24 12:00:00'));

		$completedAt = new \DateTime('2026-02-24 15:00:00');
		$roomTask->markCompleted($completedAt, 'weekly', 1, Task::RECURRENCE_ONCE);

		$this->assertFalse($roomTask->getIsActive());
		$this->assertSame($completedAt->format('c'), $roomTask->getNextDue()->format('c'));
	}

	/**
	 * MarkCompleted_WithRecurringPastActiveUntil_DeactivatesTask
	 */
	public function testMarkCompleted_WithRecurringPastActiveUntil_DeactivatesTask(): void {
		$roomTask = new RoomTask();
		$roomTask->setIsActive(true);
		$roomTask->setNextDue(new \DateTime('2026-02-24 12:00:00'));

		$completedAt = new \DateTime('2026-02-24 15:00:00');
		$activeUntil = new \DateTime('2026-02-25 00:00:00');
		$roomTask->markCompleted($completedAt, 'weekly', 1, 'recurring', 'dynamic', $activeUntil);

		$this->assertFalse($roomTask->getIsActive());
		$this->assertSame($activeUntil->format('c'), $roomTask->getNextDue()->format('c'));
	}
}
