<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\BackgroundJob;

use OCA\ChoreStrike\BackgroundJob\StreakFreezeJob;
use OCA\ChoreStrike\Db\CompletedTaskMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for StreakFreezeJob
 */
class StreakFreezeJobTest extends TestCase
{
    private HouseUserMapper $houseUserMapper;
    private CompletedTaskMapper $completedTaskMapper;
    private IDBConnection $db;
    private IQueryBuilder $qb;
    private LoggerInterface $logger;
    private StreakFreezeJob $job;

    protected function setUp(): void
    {
        $timeFactory = $this->createMock(ITimeFactory::class);
        $this->houseUserMapper = $this->createMock(HouseUserMapper::class);
        $this->completedTaskMapper = $this->createMock(CompletedTaskMapper::class);
        $this->db = $this->createMock(IDBConnection::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->qb = $this->createMock(IQueryBuilder::class);

        $expr = $this->createMock(IExpressionBuilder::class);
        $this->qb->method('expr')->willReturn($expr);
        $this->qb->method('select')->willReturnSelf();
        $this->qb->method('from')->willReturnSelf();
        $this->db->method('getQueryBuilder')->willReturn($this->qb);

        $this->job = new StreakFreezeJob(
            $timeFactory,
            $this->houseUserMapper,
            $this->completedTaskMapper,
            $this->db,
            $this->logger,
        );
    }

    /**
     * Run_UserWithNoActivityYesterday_ResetsStreak
     */
    public function testRun_UserWithNoActivityYesterday_ResetsStreak(): void
    {
        $user = new HouseUser();
        $user->setId(1);
        $user->setCurrentStreak(5);
        $user->setStreakFreezes(0);

        $this->houseUserMapper->method('findEntities')->willReturn([$user]);
        $this->completedTaskMapper->method('countByUserOnDate')->willReturn(0);
        $this->houseUserMapper->expects($this->once())->method('update')
            ->with($this->callback(function (HouseUser $u) {
                return $u->getCurrentStreak() === 0;
            }));

        $this->invokeRun($this->job, null);
    }

    /**
     * Run_UserWithNoActivityYesterday_ConsumesFreeze
     */
    public function testRun_UserWithNoActivityYesterday_ConsumesFreeze(): void
    {
        $user = new HouseUser();
        $user->setId(1);
        $user->setCurrentStreak(5);
        $user->setStreakFreezes(1);

        $this->houseUserMapper->method('findEntities')->willReturn([$user]);
        $this->completedTaskMapper->method('countByUserOnDate')->willReturn(0);
        $this->houseUserMapper->expects($this->once())->method('update')
            ->with($this->callback(function (HouseUser $u) {
                return $u->getCurrentStreak() === 5 && $u->getStreakFreezes() === 0;
            }));

        $this->invokeRun($this->job, null);
    }

    /**
     * Run_UserWithActivityYesterday_Skips
     */
    public function testRun_UserWithActivityYesterday_Skips(): void
    {
        $user = new HouseUser();
        $user->setId(1);
        $user->setCurrentStreak(5);
        $user->setStreakFreezes(1);

        $this->houseUserMapper->method('findEntities')->willReturn([$user]);
        $this->completedTaskMapper->method('countByUserOnDate')->willReturn(3);
        $this->houseUserMapper->expects($this->never())->method('update');

        $this->invokeRun($this->job, null);
    }

    /**
     * Run_UserWithZeroStreak_Skips
     */
    public function testRun_UserWithZeroStreak_Skips(): void
    {
        $user = new HouseUser();
        $user->setId(1);
        $user->setCurrentStreak(0);
        $user->setStreakFreezes(1);

        $this->houseUserMapper->method('findEntities')->willReturn([$user]);
        $this->houseUserMapper->expects($this->never())->method('update');

        $this->invokeRun($this->job, null);
    }

    private function invokeRun(object $job, $argument): void
    {
        $ref = new \ReflectionMethod($job, 'run');
        $ref->setAccessible(true);
        $ref->invoke($job, $argument);
    }
}
