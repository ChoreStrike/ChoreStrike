<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\BackgroundJob;

use OCA\ChoreStrike\BackgroundJob\StreakRestockJob;
use OCA\ChoreStrike\Service\StreakRestockService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for StreakRestockJob.
 *
 * Since CS-PLAN-023 the job is a thin delegate to StreakRestockService.
 * The restock is time-independent — the job derives no date and passes
 * none (triggering the pass once is sufficient).
 */
class StreakRestockJobTest extends TestCase
{
    private ITimeFactory&MockObject $timeFactory;
    private StreakRestockService&MockObject $restockService;
    private StreakRestockJob $job;

    protected function setUp(): void
    {
        $this->timeFactory = $this->createMock(ITimeFactory::class);
        $this->restockService = $this->createMock(StreakRestockService::class);

        $this->job = new StreakRestockJob(
            $this->timeFactory,
            $this->restockService,
        );
    }

    /**
     * Run_DelegatesToServiceWithoutDate
     *
     * The restock pass is time-independent: the job must not consult the
     * clock for its logic and must delegate without a date argument.
     */
    public function testRun_DelegatesToServiceWithoutDate(): void
    {
        $this->timeFactory->expects($this->never())->method('getDateTime');

        $this->restockService->expects($this->once())
            ->method('process')
            ->willReturn([
                'processed' => 2,
                'freezesPerWeek' => 1,
                'cap' => 2,
            ]);

        $this->invokeRun($this->job, null);
    }

    private function invokeRun(object $job, $argument): void
    {
        $ref = new \ReflectionMethod($job, 'run');
        $ref->setAccessible(true);
        $ref->invoke($job, $argument);
    }
}
