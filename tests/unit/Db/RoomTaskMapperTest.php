<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Db;

use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\DB\IResult;
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
	 * FindOverdue_WithRoomId_ReturnsOverdueTasks
	 */
	public function testFindOverdue_WithRoomId_ReturnsOverdueTasks(): void {
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
			->willReturn($this->qb);

		$mockResult = $this->createMock(IResult::class);
		$mockResult->method('fetch')
			->willReturnOnConsecutiveCalls([
				'id' => 1,
				'room_id' => $roomId,
				'task_id' => 5,
				'is_active' => true,
				'next_due' => '2026-02-20 10:00:00', // Overdue
			], false);

		$this->qb->expects($this->once())
			->method('executeQuery')
			->willReturn($mockResult);

		$results = $this->mapper->findOverdue($roomId);

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
	 * MarkCompleted_WithOneTimeRecurrence_DeactivatesTask
	 */
	public function testMarkCompleted_WithOneTimeRecurrence_DeactivatesTask(): void {
		$roomTask = new RoomTask();
		$roomTask->setIsActive(true);
		$roomTask->setNextDue(new \DateTime('2026-02-24 12:00:00'));

		$completedAt = new \DateTime('2026-02-24 15:00:00');
		$roomTask->markCompleted($completedAt, 'weekly', 1, 'one_time');

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
		$roomTask->markCompleted($completedAt, 'weekly', 1, 'recurring', $activeUntil);

		$this->assertFalse($roomTask->getIsActive());
		$this->assertSame($activeUntil->format('c'), $roomTask->getNextDue()->format('c'));
	}
}
