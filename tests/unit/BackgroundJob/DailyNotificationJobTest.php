<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\BackgroundJob;

use OCA\ChoreStrike\BackgroundJob\DailyNotificationJob;
use OCA\ChoreStrike\Db\CompletedTaskMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Service\NotificationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for DailyNotificationJob
 */
class DailyNotificationJobTest extends TestCase
{
    private HouseUserMapper&MockObject $houseUserMapper;
    private CompletedTaskMapper&MockObject $completedTaskMapper;
    private NotificationService&MockObject $notificationService;
    private IDBConnection&MockObject $db;
    private IQueryBuilder&MockObject $qb;
    private LoggerInterface&MockObject $logger;
    private DailyNotificationJob $job;

    protected function setUp(): void
    {
        $timeFactory = $this->createMock(ITimeFactory::class);
        $this->houseUserMapper = $this->createMock(HouseUserMapper::class);
        $this->completedTaskMapper = $this->createMock(CompletedTaskMapper::class);
        $this->notificationService = $this->createMock(NotificationService::class);
        $this->db = $this->createMock(IDBConnection::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->qb = $this->createMock(IQueryBuilder::class);

        // The job now delegates to HouseUserMapper::findAllForJobs instead
        // of running its own getQueryBuilder; the db/qb mocks remain for
        // backward compatibility with the constructor signature but are
        // unused in the test paths.

        $this->job = new DailyNotificationJob(
            $timeFactory,
            $this->houseUserMapper,
            $this->completedTaskMapper,
            $this->notificationService,
            $this->db,
            $this->logger,
        );
    }

    /**
     * Run_AtRiskUser_SendsNotification
     */
    public function testRun_AtRiskUser_SendsNotification(): void
    {
        $user = $this->buildUser(1, 'alice', streak: 5, freezes: 0);

        $this->houseUserMapper->expects($this->once())
            ->method('findAllForJobs')
            ->willReturn([$user]);
        $this->completedTaskMapper->expects($this->once())
            ->method('countByUserOnDateGrouped')
            ->willReturn([]); // alice absent → 0 completions
        $this->notificationService->expects($this->once())
            ->method('notifyStreakAtRisk')
            ->with('alice', 5);

        $this->invokeRun($this->job, null);
    }

    /**
     * Run_UserWithFreeze_SkipsNotification
     *
     * The streak would be silently protected by a freeze — no nudge.
     */
    public function testRun_UserWithFreeze_SkipsNotification(): void
    {
        $user = $this->buildUser(1, 'alice', streak: 5, freezes: 1);

        $this->houseUserMapper->method('findAllForJobs')->willReturn([$user]);
        // The batched GROUP BY runs once unconditionally (cheap); the filter
        // happens in PHP per user.
        $this->completedTaskMapper->method('countByUserOnDateGrouped')->willReturn([]);
        $this->notificationService->expects($this->never())->method('notifyStreakAtRisk');

        $this->invokeRun($this->job, null);
    }

    /**
     * Run_UserWithYesterdayCompletion_SkipsNotification
     */
    public function testRun_UserWithYesterdayCompletion_SkipsNotification(): void
    {
        $user = $this->buildUser(1, 'alice', streak: 5, freezes: 0);

        $this->houseUserMapper->method('findAllForJobs')->willReturn([$user]);
        $this->completedTaskMapper->method('countByUserOnDateGrouped')
            ->willReturn([1 => 2]); // alice (id=1) had 2 completions
        $this->notificationService->expects($this->never())->method('notifyStreakAtRisk');

        $this->invokeRun($this->job, null);
    }

    /**
     * Run_UserWithZeroStreak_SkipsNotification
     */
    public function testRun_UserWithZeroStreak_SkipsNotification(): void
    {
        $user = $this->buildUser(1, 'alice', streak: 0, freezes: 0);

        $this->houseUserMapper->method('findAllForJobs')->willReturn([$user]);
        $this->completedTaskMapper->method('countByUserOnDateGrouped')->willReturn([]);
        $this->notificationService->expects($this->never())->method('notifyStreakAtRisk');

        $this->invokeRun($this->job, null);
    }

    /**
     * Run_UserWithEmptyNextcloudId_SkipsNotification
     */
    public function testRun_UserWithEmptyNextcloudId_SkipsNotification(): void
    {
        $user = $this->buildUser(1, '', streak: 5, freezes: 0);

        $this->houseUserMapper->method('findAllForJobs')->willReturn([$user]);
        $this->completedTaskMapper->method('countByUserOnDateGrouped')->willReturn([]);
        $this->notificationService->expects($this->never())->method('notifyStreakAtRisk');

        $this->invokeRun($this->job, null);
    }

    /**
     * Run_MixedUserSet_OnlyNotifiesAtRisk
     *
     * The batched GROUP BY query returns counts for every user who completed
     * anything yesterday; users absent from the map are treated as 0.
     */
    public function testRun_MixedUserSet_OnlyNotifiesAtRisk(): void
    {
        $atRisk = $this->buildUser(1, 'alice', streak: 7, freezes: 0);
        $protected = $this->buildUser(2, 'bob', streak: 10, freezes: 1);
        $inactive = $this->buildUser(3, 'carol', streak: 0, freezes: 0);
        $activeYesterday = $this->buildUser(4, 'dan', streak: 4, freezes: 0);

        $this->houseUserMapper->method('findAllForJobs')
            ->willReturn([$atRisk, $protected, $inactive, $activeYesterday]);
        // Single GROUP BY result: dan (id=4) completed yesterday; everyone else absent.
        $this->completedTaskMapper->expects($this->once())
            ->method('countByUserOnDateGrouped')
            ->willReturn([4 => 1]);

        $this->notificationService->expects($this->once())
            ->method('notifyStreakAtRisk')
            ->with('alice', 7);

        $this->invokeRun($this->job, null);
    }

    private function buildUser(int $id, string $nextcloudUserId, int $streak, int $freezes): HouseUser
    {
        $user = new HouseUser();
        $user->setId($id);
        $user->setNextcloudUserId($nextcloudUserId);
        $user->setCurrentStreak($streak);
        $user->setStreakFreezes($freezes);
        return $user;
    }

    private function invokeRun(object $job, $argument): void
    {
        $ref = new \ReflectionMethod($job, 'run');
        $ref->setAccessible(true);
        $ref->invoke($job, $argument);
    }
}
