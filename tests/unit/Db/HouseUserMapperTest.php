<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Db;

use OCA\ChoreStrike\Db\CompletedTask;
use OCA\ChoreStrike\Db\CompletedTaskMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\DB\IResult;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for HouseUserMapper
 */
class HouseUserMapperTest extends TestCase
{
    private HouseUserMapper $mapper;
    private IDBConnection $db;
    private IQueryBuilder $qb;
    private IExpressionBuilder $expr;

    protected function setUp(): void
    {
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
    public function testFindByHouseId_ReturnsUsersForHouse(): void
    {
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
                'current_sparks' => 200,
                'total_sparks_earned' => 300,
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
                'current_sparks' => 100,
                'total_sparks_earned' => 150,
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
     * GetLeaderboard_ReturnsOrderedUsers
     */
    public function testGetLeaderboard_ReturnsOrderedUsers(): void
    {
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
        $this->qb->expects($this->once())
            ->method('setMaxResults')
            ->with(10)
            ->willReturn($this->qb);

        $mockResult = $this->createMock(IResult::class);
        $mockResult->method('fetch')
            ->willReturnOnConsecutiveCalls([
                'id' => 1,
                'display_name' => 'Top User',
                'contribution_score' => 1000
            ], [
                'id' => 2,
                'display_name' => 'Second User',
                'contribution_score' => 500
            ], false);

        $this->qb->expects($this->once())
            ->method('executeQuery')
            ->willReturn($mockResult);

        $results = $this->mapper->getLeaderboard($houseId, 10);

        $this->assertCount(2, $results);
        $this->assertGreaterThanOrEqual($results[1]->getContributionScore(), $results[0]->getContributionScore());
    }

    /**
     * HouseUser_GetRank_ReturnsCorrectRank
     */
    public function testHouseUser_GetRank_ReturnsCorrectRank(): void
    {
        $novice = new HouseUser();
        $novice->setContributionScore(0);
        $this->assertEquals('Novice', $novice->getRank());

        $beginner = new HouseUser();
        $beginner->setContributionScore(50);
        $this->assertEquals('Beginner', $beginner->getRank());

        $helper = new HouseUser();
        $helper->setContributionScore(200);
        $this->assertEquals('Helper', $helper->getRank());

        $keeper = new HouseUser();
        $keeper->setContributionScore(500);
        $this->assertEquals('Keeper', $keeper->getRank());

        $guardian = new HouseUser();
        $guardian->setContributionScore(1000);
        $this->assertEquals('Guardian', $guardian->getRank());

        $champion = new HouseUser();
        $champion->setContributionScore(2500);
        $this->assertEquals('Champion', $champion->getRank());

        $legend = new HouseUser();
        $legend->setContributionScore(5000);
        $this->assertEquals('Legend', $legend->getRank());
    }

    /**
     * HouseUser_AddSparks_IncreasesSparks
     */
    public function testHouseUser_AddSparks_IncreasesSparks(): void
    {
        $user = new HouseUser();
        $user->setCurrentSparks(100);
        $user->setTotalSparksEarned(200);

        $user->addSparks(50);

        $this->assertEquals(150, $user->getCurrentSparks());
        $this->assertEquals(250, $user->getTotalSparksEarned());
    }

    /**
     * HouseUser_IncrementStreak_UpdatesStreaks
     */
    public function testHouseUser_IncrementStreak_UpdatesStreaks(): void
    {
        $user = new HouseUser();
        $user->setCurrentStreak(5);
        $user->setLongestStreak(5);

        $user->incrementStreak();

        $this->assertEquals(6, $user->getCurrentStreak());
        $this->assertEquals(6, $user->getLongestStreak());
    }

    /**
     * HouseUser_SpendSparks_ReducesCurrentSparks
     */
    public function testHouseUser_SpendSparks_ReducesCurrentSparks(): void
    {
        $user = new HouseUser();
        $user->setCurrentSparks(100);

        $user->spendSparks(30);

        $this->assertEquals(70, $user->getCurrentSparks());
    }
}
