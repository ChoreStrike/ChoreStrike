<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\BackgroundJob;

use OCA\ChoreStrike\Service\StreakRestockService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/**
 * Weekly streak freeze restock job (Monday 00:05).
 *
 * Thin delegate (CS-PLAN-023): hands the pass to
 * {@see StreakRestockService}. The restock is time-independent (it sets
 * freezes to the configured weekly value), so no date is derived or
 * passed — triggering it once is sufficient.
 */
class StreakRestockJob extends TimedJob
{
    private StreakRestockService $restockService;

    public function __construct(
        ITimeFactory $time,
        StreakRestockService $restockService
    ) {
        parent::__construct($time);
        $this->setInterval(7 * 24 * 60 * 60);

        $this->restockService = $restockService;
    }

    /**
     * Run the weekly restock
     */
    protected function run($argument): void
    {
        $this->restockService->process();
    }
}
