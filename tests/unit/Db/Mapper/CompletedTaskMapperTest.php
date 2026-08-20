<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Db\Mapper;

use OCA\ChoreStrike\Db\CompletedTask;
use OCA\ChoreStrike\Db\CompletedTaskMapper;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CompletedTaskMapper
 */
class CompletedTaskMapperTest extends TestCase {
	private CompletedTaskMapper $mapper;
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

		$this->mapper = new CompletedTaskMapper($this->db);
	}

	/**
	 * FindByUserId_ReturnsCompletedTasksForUser
	 */
	public function testFindByUserId_ReturnsCompletedTasksForUser(): void {
		$userId = 1;

		$this->qb->expects($this->once())
			->method('select')
			->with('*')
			->willReturn($this->qb);
		$this->qb->expects($this->once())
			->method('from')
			->with('chorestrike_completed_tasks')
			->willReturn($this->qb);
		$this->qb->expects($this->once())
			->method('where')
			->willReturn($this->qb);
		$this->qb->expects($this->once())
			->method('orderBy')
			->with('completed_at', 'DESC')
			->willReturn($this->qb);

		$mockResult = $this->createMock(IResult::class);
		$mockResult->method('fetch')
			->willReturnOnConsecutiveCalls([
				'id' => 1,
				'user_id' => $userId,
				'room_task_id' => 5,
				'coins_earned' => 15,
				'was_combo' => true,
				'completed_at' => '2026-02-24 15:30:00'
			], [
				'id' => 2,
				'user_id' => $userId,
				'room_task_id' => 6,
				'coins_earned' => 10,
				'was_combo' => false,
				'completed_at' => '2026-02-24 12:00:00'
			], false);

		$this->qb->expects($this->once())
			->method('executeQuery')
			->willReturn($mockResult);

		$results = $this->mapper->findByUserId($userId);

		$this->assertCount(2, $results);
		$this->assertInstanceOf(CompletedTask::class, $results[0]);
		$this->assertEquals(15, $results[0]->getCoinsEarned());
		$this->assertTrue($results[0]->getWasCombo());
		$this->assertEquals(10, $results[1]->getCoinsEarned());
		$this->assertFalse($results[1]->getWasCombo());
	}

	/**
	 * GetUserStats_ReturnsCorrectStats
	 */
	public function testGetUserStats_ReturnsCorrectStats(): void {
		$userId = 1;
		$startDate = new \DateTime('2026-02-01');
		$endDate = new \DateTime('2026-02-28');

		// Aggregate expressions pass through createFunction (CS-PLAN-036:
		// raw strings would be quoted as column names on the real DB).
		$this->qb->method('createFunction')->willReturnCallback(
			static fn (string $sql) => $sql
		);

		$this->qb->expects($this->once())
			->method('select')
			->with('COUNT(*) AS task_count')
			->willReturn($this->qb);
		// getUserStats now issues three addSelect calls (coins/combo/days).
		$this->qb->method('addSelect')->willReturn($this->qb);
		$this->qb->expects($this->once())
			->method('from')
			->with('chorestrike_completed_tasks')
			->willReturn($this->qb);
		$this->qb->method('where')->willReturn($this->qb);
		$this->qb->method('andWhere')->willReturn($this->qb);

		$mockResult = $this->createMock(IResult::class);
		$mockResult->method('fetch')
			->willReturn([
				'task_count' => 15,
				'total_coins' => 200,
				'combo_count' => 5,
				'active_days' => 4
			]);
		$mockResult->expects($this->once())
			->method('closeCursor');

		$this->qb->expects($this->once())
			->method('executeQuery')
			->willReturn($mockResult);

		$result = $this->mapper->getUserStats($userId, $startDate, $endDate);

		$this->assertEquals(15, $result['taskCount']);
		$this->assertEquals(200, $result['totalCoins']);
		$this->assertEquals(5, $result['comboCount']);
		$this->assertEquals(4, $result['activeDays']);
	}

	/**
	 * GetUserStats_WithZeroStats_ReturnsZeros
	 */
	public function testGetUserStats_WithZeroStats_ReturnsZeros(): void {
		$userId = 1;
		$startDate = new \DateTime('2026-02-01');
		$endDate = new \DateTime('2026-02-28');

		$this->qb->expects($this->once())
			->method('select')
			->willReturn($this->qb);
		$this->qb->method('addSelect')->willReturn($this->qb);
		$this->qb->expects($this->once())
			->method('from')
			->willReturn($this->qb);
		$this->qb->method('where')->willReturn($this->qb);
		$this->qb->method('andWhere')->willReturn($this->qb);

		$mockResult = $this->createMock(IResult::class);
		$mockResult->method('fetch')
			->willReturn([
				'task_count' => null,
				'total_coins' => null,
				'combo_count' => null,
				'active_days' => null
			]);
		$mockResult->expects($this->once())
			->method('closeCursor');

		$this->qb->expects($this->once())
			->method('executeQuery')
			->willReturn($mockResult);

		$result = $this->mapper->getUserStats($userId, $startDate, $endDate);

		$this->assertEquals(0, $result['taskCount']);
		$this->assertEquals(0, $result['totalCoins']);
		$this->assertEquals(0, $result['comboCount']);
		$this->assertEquals(0, $result['activeDays']);
	}

	/**
	 * GetMaxRoomCompletionCountByUser_ReturnsHighestRoomCount
	 */
	public function testGetMaxRoomCompletionCountByUser_ReturnsHighestRoomCount(): void {
		$userId = 1;
		$start = new \DateTime('2026-07-21');
		$end = new \DateTime('2026-07-28');

		$this->qb->method('createFunction')->willReturnCallback(
			static fn (string $sql) => $sql
		);

		$this->qb->expects($this->once())
			->method('select')
			->with('rt.room_id')
			->willReturn($this->qb);
		$this->qb->expects($this->once())
			->method('addSelect')
			->with('COUNT(*) AS room_count')
			->willReturn($this->qb);
		$this->qb->expects($this->once())
			->method('from')
			->with('chorestrike_completed_tasks', 'ct')
			->willReturn($this->qb);
		$this->qb->method('innerJoin')->willReturn($this->qb);
		$this->qb->method('where')->willReturn($this->qb);
		$this->qb->method('andWhere')->willReturn($this->qb);
		$this->qb->method('groupBy')->willReturn($this->qb);
		$this->qb->method('orderBy')->willReturn($this->qb);
		$this->qb->method('setMaxResults')->willReturn($this->qb);

		$mockResult = $this->createMock(IResult::class);
		$mockResult->method('fetch')
			->willReturn(['room_id' => 3, 'room_count' => 7]);
		$mockResult->expects($this->once())
			->method('closeCursor');

		$this->qb->expects($this->once())
			->method('executeQuery')
			->willReturn($mockResult);

		$result = $this->mapper->getMaxRoomCompletionCountByUser($userId, $start, $end);

		$this->assertEquals(7, $result);
	}

	/**
	 * GetMaxRoomCompletionCountByUser_NoCompletions_ReturnsZero
	 */
	public function testGetMaxRoomCompletionCountByUser_NoCompletions_ReturnsZero(): void {
		$userId = 1;
		$start = new \DateTime('2026-07-21');
		$end = new \DateTime('2026-07-28');

		$this->qb->method('select')->willReturn($this->qb);
		$this->qb->method('addSelect')->willReturn($this->qb);
		$this->qb->method('from')->willReturn($this->qb);
		$this->qb->method('innerJoin')->willReturn($this->qb);
		$this->qb->method('where')->willReturn($this->qb);
		$this->qb->method('andWhere')->willReturn($this->qb);
		$this->qb->method('groupBy')->willReturn($this->qb);
		$this->qb->method('orderBy')->willReturn($this->qb);
		$this->qb->method('setMaxResults')->willReturn($this->qb);

		$mockResult = $this->createMock(IResult::class);
		$mockResult->method('fetch')
			->willReturn(false);
		$mockResult->expects($this->once())
			->method('closeCursor');

		$this->qb->expects($this->once())
			->method('executeQuery')
			->willReturn($mockResult);

		$result = $this->mapper->getMaxRoomCompletionCountByUser($userId, $start, $end);

		$this->assertEquals(0, $result);
	}

	/**
	 * FindPendingSyncsForHouse_BuildsHouseScopedJoinQuery
	 *
	 * CS-PLAN-036: the house scope rides on the floors JOIN (pattern
	 * findCompletionsBetweenByHouse); ordering is completed_at ASC with a
	 * limit.
	 */
	public function testFindPendingSyncsForHouse_BuildsHouseScopedJoinQuery(): void {
		$this->qb->expects($this->once())
			->method('select')
			->with('ct.*')
			->willReturn($this->qb);
		$this->qb->expects($this->once())
			->method('from')
			->with('chorestrike_completed_tasks', 'ct')
			->willReturn($this->qb);
		$this->qb->expects($this->exactly(3))
			->method('innerJoin')
			->willReturn($this->qb);
		$this->qb->method('where')->willReturn($this->qb);
		$this->qb->method('andWhere')->willReturn($this->qb);
		$this->qb->expects($this->once())
			->method('orderBy')
			->with('ct.completed_at', 'ASC')
			->willReturn($this->qb);
		$this->qb->expects($this->once())
			->method('setMaxResults')
			->with(25)
			->willReturn($this->qb);

		$mockResult = $this->createMock(IResult::class);
		$mockResult->method('fetch')->willReturnOnConsecutiveCalls(
			['id' => 1, 'room_task_id' => 10, 'user_id' => 2, 'completed_at' => '2026-08-01 10:00:00', 'coins_earned' => 5, 'was_combo' => 0],
			false
		);
		$this->qb->method('executeQuery')->willReturn($mockResult);

		$result = $this->mapper->findPendingSyncsForHouse(7, 25);

		$this->assertCount(1, $result);
		$this->assertInstanceOf(CompletedTask::class, $result[0]);
		$this->assertEquals(10, $result[0]->getRoomTaskId());
	}

	/**
	 * GetUserStatsByHouse_GroupsRowsIntoPerUserMap
	 */
	public function testGetUserStatsByHouse_GroupsRowsIntoPerUserMap(): void {
		$this->qb->expects($this->once())
			->method('select')
			->with('ct.user_id')
			->willReturn($this->qb);
		$this->qb->method('addSelect')->willReturn($this->qb);
		$this->qb->expects($this->once())
			->method('from')
			->with('chorestrike_completed_tasks', 'ct')
			->willReturn($this->qb);
		$this->qb->method('innerJoin')->willReturn($this->qb);
		$this->qb->method('where')->willReturn($this->qb);
		$this->qb->method('andWhere')->willReturn($this->qb);
		$this->qb->expects($this->once())
			->method('groupBy')
			->with('ct.user_id')
			->willReturn($this->qb);

		$mockResult = $this->createMock(IResult::class);
		$mockResult->method('fetch')->willReturnOnConsecutiveCalls(
			['user_id' => 1, 'task_count' => 15, 'total_coins' => 200, 'combo_count' => 5, 'active_days' => 4],
			['user_id' => 2, 'task_count' => 3, 'total_coins' => 30, 'combo_count' => 0, 'active_days' => 2],
			false
		);
		$mockResult->expects($this->once())->method('closeCursor');

		$this->qb->expects($this->once())
			->method('executeQuery')
			->willReturn($mockResult);

		$map = $this->mapper->getUserStatsByHouse(
			1,
			new \DateTime('2026-08-01'),
			new \DateTime('2026-08-08')
		);

		$this->assertSame([1, 2], array_keys($map));
		$this->assertSame(15, $map[1]['taskCount']);
		$this->assertSame(4, $map[1]['activeDays']);
		$this->assertSame(3, $map[2]['taskCount']);
	}

	/**
	 * GetUserStatsByHouse_NoCompletions_ReturnsEmptyMap
	 */
	public function testGetUserStatsByHouse_NoCompletions_ReturnsEmptyMap(): void {
		$this->qb->method('select')->willReturn($this->qb);
		$this->qb->method('addSelect')->willReturn($this->qb);
		$this->qb->method('from')->willReturn($this->qb);
		$this->qb->method('innerJoin')->willReturn($this->qb);
		$this->qb->method('where')->willReturn($this->qb);
		$this->qb->method('andWhere')->willReturn($this->qb);
		$this->qb->method('groupBy')->willReturn($this->qb);

		$mockResult = $this->createMock(IResult::class);
		$mockResult->method('fetch')->willReturn(false);
		$mockResult->expects($this->once())->method('closeCursor');

		$this->qb->method('executeQuery')->willReturn($mockResult);

		$this->assertSame([], $this->mapper->getUserStatsByHouse(
			1,
			new \DateTime('2026-08-01'),
			new \DateTime('2026-08-08')
		));
	}

	/**
	 * GetMaxRoomCompletionCountByHouse_ReducesPerUserMaxInPhp
	 */
	public function testGetMaxRoomCompletionCountByHouse_ReducesPerUserMaxInPhp(): void {
		$this->qb->expects($this->once())
			->method('select')
			->with('ct.user_id')
			->willReturn($this->qb);
		$this->qb->method('addSelect')->willReturn($this->qb);
		$this->qb->expects($this->once())
			->method('from')
			->with('chorestrike_completed_tasks', 'ct')
			->willReturn($this->qb);
		$this->qb->method('innerJoin')->willReturn($this->qb);
		$this->qb->method('where')->willReturn($this->qb);
		$this->qb->method('andWhere')->willReturn($this->qb);
		$this->qb->method('groupBy')->willReturn($this->qb);
		$this->qb->method('addGroupBy')->willReturn($this->qb);

		// user 1: rooms A(2) B(7) → max 7; user 2: room C(4) → max 4.
		$mockResult = $this->createMock(IResult::class);
		$mockResult->method('fetch')->willReturnOnConsecutiveCalls(
			['user_id' => 1, 'room_id' => 10, 'room_count' => 2],
			['user_id' => 1, 'room_id' => 11, 'room_count' => 7],
			['user_id' => 2, 'room_id' => 12, 'room_count' => 4],
			false
		);
		$mockResult->expects($this->once())->method('closeCursor');

		$this->qb->expects($this->once())
			->method('executeQuery')
			->willReturn($mockResult);

		$map = $this->mapper->getMaxRoomCompletionCountByHouse(
			1,
			new \DateTime('2026-08-01'),
			new \DateTime('2026-08-08')
		);

		$this->assertSame([1 => 7, 2 => 4], $map);
	}
}
