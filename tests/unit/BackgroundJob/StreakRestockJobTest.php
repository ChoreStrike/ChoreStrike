<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\BackgroundJob;

use OCA\ChoreStrike\BackgroundJob\StreakRestockJob;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Service\RewardConfigService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for StreakRestockJob
 */
class StreakRestockJobTest extends TestCase
{
    private HouseUserMapper $houseUserMapper;
    private RewardConfigService $configService;
    private IDBConnection $db;
    private IQueryBuilder $qb;
    private LoggerInterface $logger;
    private StreakRestockJob $job;

    protected function setUp(): void
    {
        $timeFactory = $this->createMock(ITimeFactory::class);
        $this->houseUserMapper = $this->createMock(HouseUserMapper::class);
        $this->configService = $this->createMock(RewardConfigService::class);
        $this->db = $this->createMock(IDBConnection::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->qb = $this->createMock(IQueryBuilder::class);

        $expr = $this->createMock(IExpressionBuilder::class);
        $this->qb->method('expr')->willReturn($expr);
        $this->qb->method('select')->willReturnSelf();
        $this->qb->method('from')->willReturnSelf();
        $this->db->method('getQueryBuilder')->willReturn($this->qb);

        $this->configService->method('getStreakFreezesPerWeek')->willReturn(1);
        $this->configService->method('getStreakFreezeCap')->willReturn(2);

        $this->job = new StreakRestockJob(
            $timeFactory,
            $this->houseUserMapper,
            $this->configService,
            $this->db,
            $this->logger,
        );
    }

    /**
     * Run_RestocksAllUsers
     */
    public function testRun_RestocksAllUsers(): void
    {
        $user1 = new HouseUser();
        $user1->setId(1);
        $user1->setStreakFreezes(0);

        $user2 = new HouseUser();
        $user2->setId(2);
        $user2->setStreakFreezes(2);

        $this->houseUserMapper->method('findAllForJobs')->willReturn([$user1, $user2]);
        $this->houseUserMapper->expects($this->exactly(2))->method('update');

        $this->invokeRun($this->job, null);
    }

    /**
     * Run_RespectsConfiguredValue
     */
    public function testRun_RespectsConfiguredValue(): void
    {
        $user = new HouseUser();
        $user->setId(1);
        $user->setStreakFreezes(0);

        $this->houseUserMapper->method('findAllForJobs')->willReturn([$user]);
        $this->houseUserMapper->expects($this->once())->method('update')
            ->with($this->callback(function (HouseUser $u) {
                return $u->getStreakFreezes() === 1;
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
