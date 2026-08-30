<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Db\Mapper;

use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for HouseUserMapper
 */
class HouseUserMapperTest extends TestCase {
	private HouseUserMapper $mapper;
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

		$this->mapper = new HouseUserMapper($this->db);
	}

	/**
	 * FindByHouseId_ReturnsUsersForHouse
	 */
	public function testFindByHouseId_ReturnsUsersForHouse(): void {
		$houseId = 1;

		$this->qb->expects($this->once())
			->method('select')
			->with('*')
			->willReturn($this->qb);
		$this->qb->expects($this->once())
			->method('from')
			->with('chorestrike_house_users')
			->willReturn($this->qb);
		$this->qb->expects($this->once())
			->method('where')
			->willReturn($this->qb);
		$this->qb->expects($this->once())
			->method('orderBy')
			->with('contribution_score', 'DESC')
			->willReturn($this->qb);

		$mockResult = $this->createMock(IResult::class);
		$mockResult->method('fetch')
			->willReturnOnConsecutiveCalls([
				'id' => 1,
				'house_id' => $houseId,
				'nextcloud_user_id' => 'user1',
				'display_name' => 'User One',
				'contribution_score' => 500,
				'current_streak' => 5,
				'longest_streak' => 10,
				'role' => 'admin',
				'joined_at' => '2026-02-01 10:00:00',
				'last_activity' => '2026-02-24 15:30:00'
			], [
				'id' => 2,
				'house_id' => $houseId,
				'nextcloud_user_id' => 'user2',
				'display_name' => 'User Two',
				'contribution_score' => 300,
				'current_streak' => 3,
				'longest_streak' => 5,
				'role' => 'member',
				'joined_at' => '2026-02-15 10:00:00',
				'last_activity' => '2026-02-24 12:00:00'
			], false);

		$this->qb->expects($this->once())
			->method('executeQuery')
			->willReturn($mockResult);

		$results = $this->mapper->findByHouseId($houseId);

		$this->assertCount(2, $results);
		$this->assertInstanceOf(HouseUser::class, $results[0]);
		$this->assertEquals('User One', $results[0]->getDisplayName());
		$this->assertEquals(500, $results[0]->getContributionScore());
		$this->assertEquals('User Two', $results[1]->getDisplayName());
		$this->assertEquals(300, $results[1]->getContributionScore());
	}

	/**
	 * FindByHouseIdAndNextcloudUserIds_EmptyInput_ReturnsEmptyMapWithoutQuery
	 *
	 * CS-PLAN-036: the empty-array early return must not build an IN () —
	 * that would be a syntax error on MariaDB/SQLite.
	 */
	public function testFindByHouseIdAndNextcloudUserIds_EmptyInput_ReturnsEmptyMapWithoutQuery(): void {
		$this->qb->expects($this->never())->method('select');

		$this->assertSame([], $this->mapper->findByHouseIdAndNextcloudUserIds(1, []));
	}

	/**
	 * FindByHouseIdAndNextcloudUserIds_BuildsInQueryAndMapsByNextcloudUserId
	 */
	public function testFindByHouseIdAndNextcloudUserIds_BuildsInQueryAndMapsByNextcloudUserId(): void {
		$capturedTypes = [];
		$this->qb->method('createNamedParameter')->willReturnCallback(
			function ($value, $type) use (&$capturedTypes) {
				$capturedTypes[] = $type;
				return 'param_' . count($capturedTypes);
			}
		);
		$this->qb->method('select')->willReturnSelf();
		$this->qb->method('from')->willReturnSelf();
		$this->qb->method('where')->willReturnSelf();
		$this->qb->method('andWhere')->willReturnSelf();

		$mockResult = $this->createMock(IResult::class);
		$mockResult->method('fetch')->willReturnOnConsecutiveCalls(
			['id' => 1, 'house_id' => 1, 'nextcloud_user_id' => 'user1', 'display_name' => 'User One', 'role' => 'owner'],
			['id' => 2, 'house_id' => 1, 'nextcloud_user_id' => 'user2', 'display_name' => 'User Two', 'role' => 'member'],
			false
		);
		$this->qb->method('executeQuery')->willReturn($mockResult);

		$map = $this->mapper->findByHouseIdAndNextcloudUserIds(1, ['user1', 'user2', 'absent']);

		$this->assertSame(['user1', 'user2'], array_keys($map));
		$this->assertInstanceOf(HouseUser::class, $map['user1']);
		$this->assertSame('User One', $map['user1']->getDisplayName());
		// The IN list must be bound with the STR array type.
		$this->assertContains(IQueryBuilder::PARAM_STR_ARRAY, $capturedTypes);
	}

	/**
	 * FindByHouseIdAndNextcloudUserIds_NoRows_ReturnsEmptyMap
	 */
	public function testFindByHouseIdAndNextcloudUserIds_NoRows_ReturnsEmptyMap(): void {
		$this->qb->method('createNamedParameter')->willReturn('p');
		$this->qb->method('select')->willReturnSelf();
		$this->qb->method('from')->willReturnSelf();
		$this->qb->method('where')->willReturnSelf();
		$this->qb->method('andWhere')->willReturnSelf();

		$mockResult = $this->createMock(IResult::class);
		$mockResult->method('fetch')->willReturn(false);
		$this->qb->method('executeQuery')->willReturn($mockResult);

		$this->assertSame([], $this->mapper->findByHouseIdAndNextcloudUserIds(7, ['nobody']));
	}

	/**
	 * FindForUpdate_LockingEngines_AcquiresLockViaSelectForUpdate
	 *
	 * CR-SVC-04 (reopened): on MySQL/MariaDB/PostgreSQL the lock must be a
	 * true locking read — never an UPDATE that writes pre-read data back.
	 */
	public function testFindForUpdate_LockingEngines_AcquiresLockViaSelectForUpdate(): void {
		$this->db->method('getDatabaseProvider')
			->willReturn(IDBConnection::PLATFORM_MYSQL);

		$mockResult = $this->createMock(IResult::class);
		$mockResult->method('fetch')->willReturn([
			'id' => 7,
			'house_id' => 1,
			'nextcloud_user_id' => 'user1',
			'display_name' => 'User One',
			'role' => 'owner',
			'contribution_score' => 15,
			'coins' => 10,
		]);
		$mockResult->expects($this->once())->method('closeCursor');

		$this->db->expects($this->once())
			->method('executeQuery')
			->with(
				$this->callback(function (string $sql): bool {
					return str_contains($sql, 'chorestrike_house_users')
						&& str_contains($sql, 'FOR UPDATE');
				}),
				['id' => 7],
				['id' => IQueryBuilder::PARAM_INT]
			)
			->willReturn($mockResult);

		// The stale-write lock path (UPDATE with a pre-read value) must not run.
		$this->qb->expects($this->never())->method('update');

		$user = $this->mapper->findForUpdate(7);

		$this->assertSame(7, $user->getId());
		$this->assertSame(15, $user->getContributionScore());
		$this->assertSame(10, $user->getCoins());
	}

	/**
	 * FindForUpdate_MissingRow_ThrowsDoesNotExist
	 */
	public function testFindForUpdate_MissingRow_ThrowsDoesNotExist(): void {
		$this->db->method('getDatabaseProvider')
			->willReturn(IDBConnection::PLATFORM_MYSQL);

		$mockResult = $this->createMock(IResult::class);
		$mockResult->method('fetch')->willReturn(false);

		$this->db->method('executeQuery')->willReturn($mockResult);

		$this->expectException(DoesNotExistException::class);
		$this->mapper->findForUpdate(999);
	}

	/**
	 * FindForUpdate_Sqlite_NoOpSelfAssignThenFreshRead
	 *
	 * SQLite has no FOR UPDATE; the lock is a no-op UPDATE that self-assigns
	 * the column's own current value (never a pre-read value), then re-reads.
	 */
	public function testFindForUpdate_Sqlite_NoOpSelfAssignThenFreshRead(): void {
		$this->db->method('getDatabaseProvider')
			->willReturn(IDBConnection::PLATFORM_SQLITE);

		$this->qb->method('update')->willReturn($this->qb);
		$this->qb->method('createFunction')->with('contribution_score')->willReturn('contribution_score');
		$this->qb->method('set')->willReturn($this->qb);
		$this->qb->method('where')->willReturn($this->qb);
		$this->qb->method('from')->willReturn($this->qb);
		$this->qb->method('select')->willReturn($this->qb);
		$this->qb->expects($this->once())->method('executeStatement')->willReturn(1);

		$mockResult = $this->createMock(IResult::class);
		$mockResult->method('fetch')->willReturn([
			'id' => 7,
			'house_id' => 1,
			'nextcloud_user_id' => 'user1',
			'display_name' => 'User One',
			'role' => 'owner',
			'contribution_score' => 15,
			'coins' => 10,
		]);
		$this->qb->method('executeQuery')->willReturn($mockResult);

		$user = $this->mapper->findForUpdate(7);

		$this->assertSame(15, $user->getContributionScore());
	}
}
