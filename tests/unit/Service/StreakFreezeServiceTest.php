<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\CompletedTaskMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Service\StreakFreezeService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for StreakFreezeService (CS-PLAN-023).
 *
 * The service receives "yesterday" as a parameter, so every test pins a
 * fixed calendar day (a Wednesday) and asserts the behaviour as a pure
 * function of that injected date — the regression that makes the
 * StreakFreezeJob refactor safe. Before the refactor these tests were not
 * possible: the job hardcoded `new \DateTime('yesterday')`.
 */
class StreakFreezeServiceTest extends TestCase
{
    /** Fixed injected "yesterday": a Wednesday, independent of the real clock. */
    private const YESTERDAY = '2026-07-29 06:00:00';
    private const YESTERDAY_WEEKDAY = 'wednesday';

    private HouseUserMapper&MockObject $houseUserMapper;
    private CompletedTaskMapper&MockObject $completedTaskMapper;
    private LoggerInterface&MockObject $logger;
    private StreakFreezeService $service;
    private \DateTime $yesterday;

    protected function setUp(): void
    {
        $this->houseUserMapper = $this->createMock(HouseUserMapper::class);
        $this->completedTaskMapper = $this->createMock(CompletedTaskMapper::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->yesterday = new \DateTime(self::YESTERDAY);

        $this->service = new StreakFreezeService(
            $this->houseUserMapper,
            $this->completedTaskMapper,
            $this->logger,
        );
    }

    /**
     * Process_NoCompletionsAndNoFreezes_ResetsStreak
     */
    public function testProcess_NoCompletionsAndNoFreezes_ResetsStreak(): void
    {
        $user = $this->buildUser(1, streak: 5, freezes: 0);

        $this->houseUserMapper->method('findAllForJobs')->willReturn([$user]);
        $this->completedTaskMapper->method('countByUserOnDate')->willReturn(0);
        $this->houseUserMapper->expects($this->once())->method('update')
            ->with($this->callback(function (HouseUser $u) {
                return $u->getCurrentStreak() === 0;
            }));

        $stats = $this->service->process($this->yesterday);

        $this->assertSame(1, $stats['reset']);
        $this->assertSame(0, $stats['frozen']);
    }

    /**
     * Process_NoCompletionsWithFreeze_ConsumesFreezeAndKeepsStreak
     */
    public function testProcess_NoCompletionsWithFreeze_ConsumesFreezeAndKeepsStreak(): void
    {
        $user = $this->buildUser(1, streak: 5, freezes: 1);

        $this->houseUserMapper->method('findAllForJobs')->willReturn([$user]);
        $this->completedTaskMapper->method('countByUserOnDate')->willReturn(0);
        $this->houseUserMapper->expects($this->once())->method('update')
            ->with($this->callback(function (HouseUser $u) {
                return $u->getCurrentStreak() === 5 && $u->getStreakFreezes() === 0;
            }));

        $stats = $this->service->process($this->yesterday);

        $this->assertSame(1, $stats['frozen']);
        $this->assertSame(0, $stats['reset']);
    }

    /**
     * Process_CompletionsOnInjectedDay_SkipsAndPassesDateThrough
     *
     * The completion count must be queried for the injected date — the
     * actual time seam under test.
     */
    public function testProcess_CompletionsOnInjectedDay_SkipsAndPassesDateThrough(): void
    {
        $user = $this->buildUser(1, streak: 5, freezes: 1);

        $this->houseUserMapper->method('findAllForJobs')->willReturn([$user]);
        $this->completedTaskMapper->expects($this->once())
            ->method('countByUserOnDate')
            ->with(1, $this->callback(function (\DateTimeInterface $date) {
                return $date->format('Y-m-d') === '2026-07-29';
            }))
            ->willReturn(3);
        $this->houseUserMapper->expects($this->never())->method('update');

        $stats = $this->service->process($this->yesterday);

        $this->assertSame(0, $stats['frozen']);
        $this->assertSame(0, $stats['reset']);
    }

    /**
     * Process_ZeroStreak_Skips
     */
    public function testProcess_ZeroStreak_Skips(): void
    {
        $user = $this->buildUser(1, streak: 0, freezes: 1);

        $this->houseUserMapper->method('findAllForJobs')->willReturn([$user]);
        $this->completedTaskMapper->expects($this->never())->method('countByUserOnDate');
        $this->houseUserMapper->expects($this->never())->method('update');

        $this->service->process($this->yesterday);
    }

    /**
     * Process_UnavailableOnInjectedWeekday_SkipsFreezeAndReset
     *
     * Fairness rule, now deterministic: the injected day is a Wednesday,
     * so marking wednesday unavailable must skip the user regardless of
     * the real clock.
     */
    public function testProcess_UnavailableOnInjectedWeekday_SkipsFreezeAndReset(): void
    {
        $user = $this->buildUser(1, streak: 5, freezes: 0);
        $user->setSchedulingPreferences([
            'weekdayAvailability' => [self::YESTERDAY_WEEKDAY => false],
        ]);

        $this->houseUserMapper->method('findAllForJobs')->willReturn([$user]);
        $this->completedTaskMapper->expects($this->never())->method('countByUserOnDate');
        $this->houseUserMapper->expects($this->never())->method('update');

        $stats = $this->service->process($this->yesterday);

        $this->assertSame(1, $stats['skippedUnavailable']);
    }

    /**
     * Process_AvailableOnInjectedWeekday_ProcessesNormally
     *
     * Complement to the fairness test: an explicitly available Wednesday
     * (the injected weekday) is processed.
     */
    public function testProcess_AvailableOnInjectedWeekday_ProcessesNormally(): void
    {
        $user = $this->buildUser(1, streak: 5, freezes: 1);
        $user->setSchedulingPreferences([
            'weekdayAvailability' => [self::YESTERDAY_WEEKDAY => true],
        ]);

        $this->houseUserMapper->method('findAllForJobs')->willReturn([$user]);
        $this->completedTaskMapper->method('countByUserOnDate')->willReturn(0);
        $this->houseUserMapper->expects($this->once())->method('update');

        $stats = $this->service->process($this->yesterday);

        $this->assertSame(1, $stats['frozen']);
        $this->assertSame(0, $stats['skippedUnavailable']);
    }

    /**
     * Process_PerUserErrorIsolation_ContinuesAfterFailure
     *
     * A single user failure must not abort the loop — the second user
     * should still be processed normally.
     */
    public function testProcess_PerUserErrorIsolation_ContinuesAfterFailure(): void
    {
        $user1 = $this->buildUser(1, streak: 5, freezes: 0);
        $user2 = $this->buildUser(2, streak: 3, freezes: 1);

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

        $stats = $this->service->process($this->yesterday);

        $this->assertSame(2, $stats['processed']);
        $this->assertSame(1, $stats['errors']);
        $this->assertSame(1, $stats['frozen']);
    }

    /**
     * Process_ReturnsCompleteStats
     */
    public function testProcess_ReturnsCompleteStats(): void
    {
        $this->houseUserMapper->method('findAllForJobs')->willReturn([]);

        $stats = $this->service->process($this->yesterday);

        $this->assertSame([
            'processed' => 0,
            'frozen' => 0,
            'reset' => 0,
            'skippedUnavailable' => 0,
            'errors' => 0,
        ], $stats);
    }

    private function buildUser(int $id, int $streak, int $freezes): HouseUser
    {
        $user = new HouseUser();
        $user->setId($id);
        $user->setCurrentStreak($streak);
        $user->setStreakFreezes($freezes);
        return $user;
    }
}
