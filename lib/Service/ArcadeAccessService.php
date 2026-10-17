<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\WorkShareMapper;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Exception\GameLockedException;
use OCP\IL10N;

/**
 * Arcade access as permanent milestones of valid chore history
 * (CS-PLAN-086 §3.2, D-086-01/06, ADR-019).
 *
 * A game opens once the member's lifetime count of finished chores reaches
 * its {@see ArcadeCatalog} threshold. Nothing is stored: every read derives
 * the count from the work ledger, so there is nothing to farm, reset or
 * migrate, and an undone completion can re-lock a game until the count is
 * reached again. There is no daily coupling.
 *
 * The arcade never reads or writes Coins, balances, contribution or
 * ranking; the wallet id is only the share-attribution key of the ledger.
 */
class ArcadeAccessService {
	public function __construct(
		private WorkShareMapper $workShareMapper,
		private RewardConfigService $rewardConfig,
		private AuthorizationService $authService,
		private IL10N $l10n,
		private AppLogger $appLogger,
	) {
	}

	/**
	 * The member's view of the catalog: every game in catalog order with
	 * its threshold and lock state. With the owner gate off every game is
	 * open.
	 *
	 * @return array{gateEnabled: bool, finishedChores: int, games: list<array{gameId: string, threshold: int, unlocked: bool}>}
	 */
	public function getCatalog(HouseUser $member): array {
		$gateEnabled = $this->isGateEnabled();
		$finishedChores = $this->finishedChores($member);
		$games = [];
		foreach (ArcadeCatalog::ids() as $gameId) {
			$threshold = ArcadeCatalog::rules($gameId)['unlockThreshold'];
			$games[] = [
				'gameId' => $gameId,
				'threshold' => $threshold,
				'unlocked' => !$gateEnabled || $finishedChores >= $threshold,
			];
		}

		return [
			'gateEnabled' => $gateEnabled,
			'finishedChores' => $finishedChores,
			'games' => $games,
		];
	}

	/**
	 * Reject play results for a game the member has not unlocked, so a
	 * crafted request cannot fill a locked game's board.
	 *
	 * @param string $gameId A known game id (the caller checked the catalog)
	 * @throws GameLockedException while the gate is on and the threshold is not reached
	 */
	public function assertUnlocked(HouseUser $member, string $gameId): void {
		$threshold = ArcadeCatalog::rules($gameId)['unlockThreshold'];
		if ($threshold === 0 || !$this->isGateEnabled() || $this->finishedChores($member) >= $threshold) {
			return;
		}

		$this->appLogger->warning('Minigame score save rejected: game locked', [
			'event' => 'minigame_locked',
			'service' => 'ArcadeAccessService',
			'houseId' => $member->getHouseId(),
			'userId' => $member->getNextcloudUserId(),
			'gameId' => $gameId,
		]);
		throw new GameLockedException($this->l10n->t('This game is still locked.'));
	}

	public function isGateEnabled(): bool {
		return $this->rewardConfig->getArcadeGateEnabled();
	}

	/**
	 * Owner-only, house-wide switch of the unlock gate (D-086-02).
	 * Idempotent by value; the last write wins.
	 *
	 * @throws ForbiddenException when the current user is not a house owner
	 */
	public function setGateEnabled(int $houseId, bool $enabled): void {
		$owner = $this->authService->requireHouseOwner($houseId);
		$changed = $this->isGateEnabled() !== $enabled;
		if ($changed) {
			$this->rewardConfig->setArcadeGateEnabled($enabled);
		}

		$this->appLogger->audit('Arcade gate update completed', [
			'event' => 'arcade.gate.updated',
			'service' => 'ArcadeAccessService',
			'houseId' => $houseId,
			'actorId' => (int)$owner->getId(),
			'gateEnabled' => $enabled,
			'outcome' => $changed ? 'changed' : 'no-change',
		]);
	}

	/**
	 * Lifetime finished chores of the member (valid, selected shares).
	 */
	private function finishedChores(HouseUser $member): int {
		$walletId = $member->getWalletId();
		if ($walletId === null) {
			return 0;
		}
		return $this->workShareMapper->countValidSelectedByWallet([$walletId])[$walletId] ?? 0;
	}
}
