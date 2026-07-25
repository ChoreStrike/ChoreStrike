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

        $this->houseUserMapper->method('findAllForJobs')->willReturn([$user]);
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

        $this->houseUserMapper->method('findAllForJobs')->willReturn([$user]);
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

        $this->houseUserMapper->method('findAllForJobs')->willReturn([$user]);
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

        $this->houseUserMapper->method('findAllForJobs')->willReturn([$user]);
        $this->houseUserMapper->expects($this->never())->method('update');

        $this->invokeRun($this->job, null);
    }

    /**
     * Run_UserOnUnavailableYesterday_SkipsFreezeAndReset
     *
     * When yesterday was an unavailable weekday for the user, the job must
     * skip them — no freeze consumed, no streak reset (fairness rule).
     */
    public function testRun_UserOnUnavailableYesterday_SkipsFreezeAndReset(): void
    {
        $yesterday = strtolower((new \DateTime('yesterday'))->format('l'));

        $user = new HouseUser();
        $user->setId(1);
        $user->setCurrentStreak(5);
        $user->setStreakFreezes(0);
        $user->setSchedulingPreferences([
            'weekdayAvailability' => [$yesterday => false],
        ]);

        $this->houseUserMapper->method('findAllForJobs')->willReturn([$user]);
        $this->completedTaskMapper->expects($this->never())->method('countByUserOnDate');
        $this->houseUserMapper->expects($this->never())->method('update');

        $this->invokeRun($this->job, null);
    }

    /**
     * Run_PerUserErrorIsolation_ContinuesAfterFailure
     *
     * A single user failure must not abort the loop — the second user
     * should still be processed normally.
     */
    public function testRun_PerUserErrorIsolation_ContinuesAfterFailure(): void
    {
        $user1 = new HouseUser();
        $user1->setId(1);
        $user1->setCurrentStreak(5);
        $user1->setStreakFreezes(0);

        $user2 = new HouseUser();
        $user2->setId(2);
        $user2->setCurrentStreak(3);
        $user2->setStreakFreezes(1);

        $this->houseUserMapper->method('findAllForJobs')->willReturn([$user1, $user2]);

        $callCount = 0;
        $this->completedTaskMapper->method('countByUserOnDate')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    throw new \RuntimeException('DB connection lost');
                }
                return 0;
            });

        // user1 fails (exception caught), user2 succeeds (freeze consumed)
        $this->houseUserMapper->expects($this->once())->method('update')
            ->with($this->callback(function (HouseUser $u) {
                return $u->getId() === 2 && $u->getCurrentStreak() === 3 && $u->getStreakFreezes() === 0;
            }));

        $this->invokeRun($this->job, null);
    }

    private function invokeRun(object $job, $argument): void
    {
        $ref = new \ReflectionMethod($job, 'run');
        $ref->setAccessible(true);
        $ref->invoke($job, $argument);
    }
}
