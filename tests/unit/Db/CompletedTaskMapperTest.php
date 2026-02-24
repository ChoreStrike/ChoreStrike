<?php

declare(strict_types=1);

namespace OCA\ChoreWars\Tests\Unit\Db;

use OCA\ChoreWars\Db\CompletedTask;
use OCA\ChoreWars\Db\CompletedTaskMapper;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\DB\IResult;
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
				'sparks_earned' => 15,
				'was_combo' => true,
				'completed_at' => '2026-02-24 15:30:00'
			], [
				'id' => 2,
				'user_id' => $userId,
				'room_task_id' => 6,
				'sparks_earned' => 10,
				'was_combo' => false,
				'completed_at' => '2026-02-24 12:00:00'
			], false);

		$this->qb->expects($this->once())
			->method('executeQuery')
			->willReturn($mockResult);

		$results = $this->mapper->findByUserId($userId);

		$this->assertCount(2, $results);
		$this->assertInstanceOf(CompletedTask::class, $results[0]);
		$this->assertEquals(15, $results[0]->getSparksEarned());
		$this->assertTrue($results[0]->getWasCombo());
		$this->assertEquals(10, $results[1]->getSparksEarned());
		$this->assertFalse($results[1]->getWasCombo());
	}

	/**
	 * GetUserStats_ReturnsCorrectStats
	 */
	public function testGetUserStats_ReturnsCorrectStats(): void {
		$userId = 1;
		$startDate = new \DateTime('2026-02-01');
		$endDate = new \DateTime('2026-02-28');

		$this->qb->expects($this->once())
			->method('select')
			->with('COUNT(*) as task_count')
			->willReturn($this->qb);
		$this->qb->expects($this->once())
			->method('addSelect')
			->with('SUM(sparks_earned) as total_sparks')
			->willReturn($this->qb);
		$this->qb->expects($this->once())
			->method('from')
			->with('chorestrike_completed_tasks')
			->willReturn($this->qb);

		$mockResult = $this->createMock(IResult::class);
		$mockResult->method('fetch')
			->willReturn([
				'task_count' => 15,
				'total_sparks' => 200,
				'combo_count' => 5
			]);
		$mockResult->expects($this->once())
			->method('closeCursor');

		$this->qb->expects($this->once())
			->method('executeQuery')
			->willReturn($mockResult);

		$result = $this->mapper->getUserStats($userId, $startDate, $endDate);

		$this->assertEquals(15, $result['taskCount']);
		$this->assertEquals(200, $result['totalSparks']);
		$this->assertEquals(5, $result['comboCount']);
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
		$this->qb->expects($this->once())
			->method('addSelect')
			->willReturn($this->qb);
		$this->qb->expects($this->once())
			->method('from')
			->willReturn($this->qb);

		$mockResult = $this->createMock(IResult::class);
		$mockResult->method('fetch')
			->willReturn([
				'task_count' => null,
				'total_sparks' => null,
				'combo_count' => null
			]);
		$mockResult->expects($this->once())
			->method('closeCursor');

		$this->qb->expects($this->once())
			->method('executeQuery')
			->willReturn($mockResult);

		$result = $this->mapper->getUserStats($userId, $startDate, $endDate);

		$this->assertEquals(0, $result['taskCount']);
		$this->assertEquals(0, $result['totalSparks']);
		$this->assertEquals(0, $result['comboCount']);
	}
}
