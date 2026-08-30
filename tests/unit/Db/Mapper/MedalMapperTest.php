<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Db\Mapper;

use OCA\ChoreStrike\Db\MedalMapper;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for MedalMapper
 */
class MedalMapperTest extends TestCase {
	private MedalMapper $mapper;
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

		$this->mapper = new MedalMapper($this->db);
	}

	/**
	 * FindByUserIds_EmptyInput_ReturnsEmptyMapWithoutQuery
	 *
	 * CS-PLAN-036: the empty-array early return must not build an IN ().
	 */
	public function testFindByUserIds_EmptyInput_ReturnsEmptyMapWithoutQuery(): void {
		$this->qb->expects($this->never())->method('select');

		$this->assertSame([], $this->mapper->findByUserIds([]));
	}

	/**
	 * FindByUserIds_BuildsInQueryAndGroupsByUserId
	 */
	public function testFindByUserIds_BuildsInQueryAndGroupsByUserId(): void {
		$capturedTypes = [];
		$this->qb->method('createNamedParameter')->willReturnCallback(
			function ($value, $type) use (&$capturedTypes) {
				$capturedTypes[] = $type;
				return 'param_' . count($capturedTypes);
			}
		);
		$this->qb->method('select')->willReturn($this->qb);
		$this->qb->method('from')->willReturn($this->qb);
		$this->qb->method('where')->willReturn($this->qb);
		$this->qb->method('orderBy')->willReturn($this->qb);

		$mockResult = $this->createMock(IResult::class);
		$mockResult->method('fetch')->willReturnOnConsecutiveCalls(
			['id' => 1, 'user_id' => 1, 'medal_type' => 'gold', 'week_start' => '2026-02-23', 'awarded_at' => '2026-02-24 12:00:00'],
			['id' => 2, 'user_id' => 1, 'medal_type' => 'silver', 'week_start' => '2026-02-16', 'awarded_at' => '2026-02-17 15:00:00'],
			['id' => 3, 'user_id' => 5, 'medal_type' => 'gold', 'week_start' => '2026-02-23', 'awarded_at' => '2026-02-25 09:00:00'],
			false
		);
		$this->qb->method('executeQuery')->willReturn($mockResult);

		$map = $this->mapper->findByUserIds([1, 5, 9]);

		// user 9 has no medals → absent from the map (treated as empty list).
		$this->assertSame([1, 5], array_keys($map));
		$this->assertCount(2, $map[1]);
		$this->assertSame('gold', $map[1][0]->getMedalType());
		$this->assertCount(1, $map[5]);
		$this->assertContains(IQueryBuilder::PARAM_INT_ARRAY, $capturedTypes);
	}

}
