<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\TransactionExecutor;

/**
 * Weekly streak freeze restock pass (CS-PLAN-023).
 *
 * Extracted from StreakRestockJob so all three job bodies are uniformly
 * triggerable via occ chorestrike:trigger-jobs. The restock is
 * time-independent (it sets freezes to the configured weekly value), so
 * triggering it once is sufficient — no date parameter needed.
 *
 * CS-PLAN-071 WP0: each restock write runs in one house-first transaction
 * (house row lock → membership row lock) against the FRESH locked entity.
 */
class StreakRestockService {
	public function __construct(
		private HouseUserMapper $houseUserMapper,
		private RewardConfigService $configService,
		private AppLogger $appLogger,
		private HouseMapper $houseMapper,
		private TransactionExecutor $transactions,
	) {
	}

	/**
	 * Run the restock pass.
	 *
	 * Sets each house user's streak_freezes to the configured weekly value,
	 * capped at the configured maximum.
	 *
	 * CR-SVC-30: the try/catch is per user (mirroring StreakFreezeService) so
	 * one user's failure no longer aborts the weekly restock for everyone —
	 * the loop continues and the failure is counted + logged.
	 *
	 * @return array{processed: int, freezesPerWeek: int, cap: int, failed: int}
	 */
	public function process(): array {
		$freezesPerWeek = $this->configService->getStreakFreezesPerWeek();
		$cap = $this->configService->getStreakFreezeCap();
		$stats = [
			'processed' => 0,
			'freezesPerWeek' => $freezesPerWeek,
			'cap' => $cap,
			'failed' => 0,
		];

		foreach ($this->houseUserMapper->findAllForJobs() as $user) {
			try {
				$stats['processed']++;
				$this->transactions->run(function () use ($user, $freezesPerWeek, $cap): void {
					// FIRST lock of the documented order: the house row,
					// then the membership row (fresh entity).
					$this->houseMapper->findForUpdate($user->getHouseId());
					$fresh = $this->houseUserMapper->findForUpdate($user->getId());
					$fresh->restockFreezes($freezesPerWeek, $cap);
					$this->houseUserMapper->update($fresh);
				});
			} catch (\Throwable $e) {
				$stats['failed']++;
				$this->appLogger->logException('StreakRestockService: per-user failure', $e, [
					'event' => 'streak_restock_user_failed',
					'service' => 'StreakRestockService',
					'userId' => $user->getId(),
				]);
			}
		}

		$this->appLogger->info('StreakRestockService completed', $stats + [
			'event' => 'streak_restock_pass_completed',
			'service' => 'StreakRestockService',
		]);

		return $stats;
	}
}
