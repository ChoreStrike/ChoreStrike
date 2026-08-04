<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\BackgroundJob;

use OCA\ChoreStrike\BackgroundJob\StreakFreezeJob;
use OCA\ChoreStrike\Service\StreakFreezeService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for StreakFreezeJob.
 *
 * Since CS-PLAN-023 the job is a thin delegate: it derives "yesterday"
 * from the injected ITimeFactory and hands the pass to
 * StreakFreezeService. The freeze/reset behaviour itself is covered by
 * StreakFreezeServiceTest as a function of the injected date.
 */
class StreakFreezeJobTest extends TestCase
{
    private ITimeFactory&MockObject $timeFactory;
    private StreakFreezeService&MockObject $freezeService;
    private StreakFreezeJob $job;

    protected function setUp(): void
    {
        $this->timeFactory = $this->createMock(ITimeFactory::class);
        $this->freezeService = $this->createMock(StreakFreezeService::class);

        $this->job = new StreakFreezeJob(
            $this->timeFactory,
            $this->freezeService,
        );
    }

    /**
     * Run_DerivesYesterdayFromInjectedFactoryAndDelegates
     *
     * The time seam (CS-PLAN-023): "yesterday" must be a pure function of
     * the injected clock — no hardcoded `new \DateTime('yesterday')`.
     */
    public function testRun_DerivesYesterdayFromInjectedFactoryAndDelegates(): void
    {
        $now = new \DateTime('2026-08-04 15:30:00');
        $this->timeFactory->method('getDateTime')->willReturn($now);

        $this->freezeService->expects($this->once())
            ->method('process')
            ->with($this->callback(function (\DateTimeInterface $yesterday) {
                return $yesterday->format('Y-m-d') === '2026-08-03';
            }))
            ->willReturn([
                'processed' => 0,
                'frozen' => 0,
                'reset' => 0,
                'skippedUnavailable' => 0,
                'errors' => 0,
            ]);

        $this->invokeRun($this->job, null);
    }

    /**
     * Run_DoesNotMutateTheFactoryDateTime
     *
     * The job clones before modify(); the factory's instance must survive
     * the run untouched (guards against shared-instance clock drift).
     */
    public function testRun_DoesNotMutateTheFactoryDateTime(): void
    {
        $now = new \DateTime('2026-08-04 15:30:00');
        $this->timeFactory->method('getDateTime')->willReturn($now);
        $this->freezeService->method('process')->willReturn([
            'processed' => 0,
            'frozen' => 0,
            'reset' => 0,
            'skippedUnavailable' => 0,
            'errors' => 0,
        ]);

        $this->invokeRun($this->job, null);

        $this->assertSame('2026-08-04 15:30:00', $now->format('Y-m-d H:i:s'));
    }

    private function invokeRun(object $job, $argument): void
    {
        $ref = new \ReflectionMethod($job, 'run');
        $ref->setAccessible(true);
        $ref->invoke($job, $argument);
    }
}
