<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Service\RewardConfigService;
use OCA\ChoreStrike\Service\StreakRestockService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for StreakRestockService (CS-PLAN-023).
 *
 * The restock pass is time-independent — it takes no date and sets each
 * user's freezes to the configured weekly value capped at the configured
 * maximum.
 */
class StreakRestockServiceTest extends TestCase
{
    private HouseUserMapper&MockObject $houseUserMapper;
    private RewardConfigService&MockObject $configService;
    private LoggerInterface&MockObject $logger;
    private StreakRestockService $service;

    protected function setUp(): void
    {
        $this->houseUserMapper = $this->createMock(HouseUserMapper::class);
        $this->configService = $this->createMock(RewardConfigService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->configService->method('getStreakFreezesPerWeek')->willReturn(1);
        $this->configService->method('getStreakFreezeCap')->willReturn(2);

        $this->service = new StreakRestockService(
            $this->houseUserMapper,
            $this->configService,
            $this->logger,
        );
    }

    /**
     * Process_RestocksAllUsers
     */
    public function testProcess_RestocksAllUsers(): void
    {
        $user1 = new HouseUser();
        $user1->setId(1);
        $user1->setStreakFreezes(0);

        $user2 = new HouseUser();
        $user2->setId(2);
        $user2->setStreakFreezes(2);

        $this->houseUserMapper->method('findAllForJobs')->willReturn([$user1, $user2]);
        $this->houseUserMapper->expects($this->exactly(2))->method('update');

        $stats = $this->service->process();

        $this->assertSame(2, $stats['processed']);
    }

    /**
     * Process_RespectsConfiguredValue
     */
    public function testProcess_RespectsConfiguredValue(): void
    {
        $user = new HouseUser();
        $user->setId(1);
        $user->setStreakFreezes(0);

        $this->houseUserMapper->method('findAllForJobs')->willReturn([$user]);
        $this->houseUserMapper->expects($this->once())->method('update')
            ->with($this->callback(function (HouseUser $u) {
                return $u->getStreakFreezes() === 1;
            }));

        $this->service->process();
    }

    /**
     * Process_ReturnsStatsWithConfigValues
     */
    public function testProcess_ReturnsStatsWithConfigValues(): void
    {
        $this->houseUserMapper->method('findAllForJobs')->willReturn([]);

        $stats = $this->service->process();

        $this->assertSame([
            'processed' => 0,
            'freezesPerWeek' => 1,
            'cap' => 2,
        ], $stats);
    }
}
