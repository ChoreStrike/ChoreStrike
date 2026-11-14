<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\CompletedTaskMapper;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\TransactionExecutor;
use OCA\ChoreStrike\Db\WorkShareMapper;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Exception\GameLockedException;
use OCA\ChoreStrike\Exception\ValidationException;
use OCP\IL10N;

/**
 * Arcade access as milestones of valid chore history (CS-PLAN-086 §3.2,
 * D-086-01/06, ADR-019).
 *
 * A game opens once the member's count of finished chores reaches its
 * threshold. No unlock state is stored: every read derives the count from
 * the work ledger, so there is nothing to farm or migrate, and an undone
 * completion can re-lock a game until the count is reached again. There is
 * no daily coupling.
 *
 * CS-PLAN-090 WP-J (D-090-63/66): an owner overrides the {@see ArcadeCatalog}
 * default thresholds and resets one member's progress; the count then takes
 * only completions above the member's start point
 * ({@see ArcadeStartPointStore}). Highscores are never touched.
 *
 * The arcade never reads or writes Coins, balances, contribution or
 * ranking; the wallet id is only the share-attribution key of the ledger.
 */
class ArcadeAccessService {
	/** Highest threshold an owner can set (D-090-66). */
	public const THRESHOLD_MAX = 500;

	public function __construct(
		private WorkShareMapper $workShareMapper,
		private CompletedTaskMapper $completedTaskMapper,
		private HouseMapper $houseMapper,
		private HouseUserMapper $houseUserMapper,
		private TransactionExecutor $transactions,
		private ArcadeStartPointStore $startPoints,
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
		foreach ($this->thresholds() as $gameId => $threshold) {
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
	 * crafted request cannot fill a locked game's board. The caller holds
	 * the house-row lock, so a reset never interleaves (D-090-66).
	 *
	 * @param string $gameId A known game id (the caller checked the catalog)
	 * @throws GameLockedException while the gate is on and the threshold is not reached
	 */
	public function assertUnlocked(HouseUser $member, string $gameId): void {
		$threshold = $this->thresholds()[$gameId];
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
	 * The effective threshold of every game in catalog order: the owner's
	 * stored value, or the catalog default where none is stored or a stored
	 * value is out of range.
	 *
	 * @return array<string, int>
	 */
	public function thresholds(): array {
		$stored = $this->rewardConfig->getArcadeThresholds();
		$thresholds = [];
		foreach (ArcadeCatalog::ids() as $gameId) {
			$value = $stored[$gameId] ?? null;
			$thresholds[$gameId] = self::inRange($value) ? $value : ArcadeCatalog::rules($gameId)['unlockThreshold'];
		}
		return $thresholds;
	}

	/**
	 * Owner-only save of every game's threshold (D-090-66); the last write
	 * wins.
	 *
	 * @param array<mixed> $thresholds game id => whole number from 0 to THRESHOLD_MAX, for every game
	 * @return array{gateEnabled: bool, finishedChores: int, games: list<array{gameId: string, threshold: int, unlocked: bool}>} The owner's catalog afterwards
	 * @throws ForbiddenException for a non-owner
	 * @throws ValidationException for an unknown or missing game or a value out of range
	 */
	public function setThresholds(int $houseId, array $thresholds): array {
		$owner = $this->authService->requireHouseOwner($houseId);
		$valid = $this->validatedThresholds($thresholds);
		$this->rewardConfig->setArcadeThresholds($valid);

		$this->appLogger->audit('Arcade thresholds saved', [
			'event' => 'arcade.thresholds.updated',
			'service' => 'ArcadeAccessService',
			'houseId' => $houseId,
			'actorId' => (int)$owner->getId(),
			'thresholds' => $valid,
		]);
		return $this->getCatalog($owner);
	}

	/**
	 * Owner-only reset of one member for all games (D-090-63): the start
	 * point becomes the highest committed completion id, under the house-row
	 * lock like a score save. Highscores stay.
	 *
	 * @return list<array{userId: string, displayName: string, finishedChores: int, resetAt: ?string}> The progress list afterwards
	 * @throws ForbiddenException for a non-owner or a target outside the house
	 */
	public function resetMember(int $houseId, string $userId): array {
		$owner = $this->authService->requireHouseOwner($houseId);
		$target = $this->transactions->run(function () use ($houseId, $userId): HouseUser {
			$this->houseMapper->findForUpdate($houseId);
			$target = $this->houseUserMapper->findByHouseAndUser($houseId, $userId);
			if ($target === null) {
				throw new ForbiddenException($this->l10n->t('User is not a member of this house'));
			}
			$this->startPoints->set($userId, $this->completedTaskMapper->maxId(), time());
			return $target;
		});

		$this->appLogger->audit('Arcade progress reset', [
			'event' => 'arcade.progress.reset',
			'service' => 'ArcadeAccessService',
			'houseId' => $houseId,
			'actorId' => (int)$owner->getId(),
			'targetMemberId' => (int)$target->getId(),
		]);
		return $this->progressList($houseId);
	}

	/**
	 * Owner-only read: each member's finished chores since their last reset
	 * and its time, in name order — never ranked by contribution (ADR-019
	 * decision 7).
	 *
	 * @return list<array{userId: string, displayName: string, finishedChores: int, resetAt: ?string}>
	 * @throws ForbiddenException for a non-owner
	 */
	public function getProgress(int $houseId): array {
		$this->authService->requireHouseOwner($houseId);
		return $this->progressList($houseId);
	}

	/**
	 * @return list<array{userId: string, displayName: string, finishedChores: int, resetAt: ?string}>
	 */
	private function progressList(int $houseId): array {
		$members = $this->houseUserMapper->findByHouseId($houseId);
		usort($members, static fn (HouseUser $a, HouseUser $b): int => strnatcasecmp((string)$a->getDisplayName(), (string)$b->getDisplayName()));
		$points = $this->startPoints->forUsers(array_map(static fn (HouseUser $m): string => (string)$m->getNextcloudUserId(), $members));
		$counts = $this->countSince($members, $points);

		return array_map(static function (HouseUser $member) use ($points, $counts): array {
			$point = $points[(string)$member->getNextcloudUserId()] ?? null;
			return [
				'userId' => (string)$member->getNextcloudUserId(),
				'displayName' => (string)$member->getDisplayName(),
				'finishedChores' => $counts[(int)$member->getWalletId()] ?? 0,
				'resetAt' => $point === null ? null : (new \DateTimeImmutable('@' . $point['at']))->format(\DateTimeInterface::ATOM),
			];
		}, $members);
	}

	/**
	 * Finished chores of the member since their start point (valid,
	 * selected shares), read past every cache.
	 */
	private function finishedChores(HouseUser $member): int {
		$userId = (string)$member->getNextcloudUserId();
		$counts = $this->countSince([$member], $this->startPoints->forUsers([$userId]));
		return $counts[(int)$member->getWalletId()] ?? 0;
	}

	/**
	 * One grouped count for the members' wallets, each above its own start
	 * point (RF-090-26). Members without a wallet have no count.
	 *
	 * @param HouseUser[] $members
	 * @param array<string, array{after: int, at: int}> $points
	 * @return array<int, int> walletId => finished chores
	 */
	private function countSince(array $members, array $points): array {
		$walletIds = [];
		$after = [];
		foreach ($members as $member) {
			$walletId = $member->getWalletId();
			if ($walletId === null) {
				continue;
			}
			$walletIds[] = (int)$walletId;
			$point = $points[(string)$member->getNextcloudUserId()] ?? null;
			if ($point !== null) {
				$after[(int)$walletId] = $point['after'];
			}
		}
		return $walletIds === [] ? [] : $this->workShareMapper->countValidSelectedByWallet($walletIds, $after);
	}

	/**
	 * @param array<mixed> $thresholds
	 * @return array<string, int> Every game in catalog order
	 */
	private function validatedThresholds(array $thresholds): array {
		if (array_diff(array_map('strval', array_keys($thresholds)), ArcadeCatalog::ids()) !== []) {
			throw new ValidationException($this->l10n->t('Unknown game'));
		}
		$valid = [];
		foreach (ArcadeCatalog::ids() as $gameId) {
			$value = $thresholds[$gameId] ?? null;
			if (!self::inRange($value)) {
				throw new ValidationException($this->l10n->t('Each game needs a threshold from 0 to %1$s finished chores.', [self::THRESHOLD_MAX]));
			}
			$valid[$gameId] = $value;
		}
		return $valid;
	}

	private static function inRange(mixed $value): bool {
		return is_int($value) && $value >= 0 && $value <= self::THRESHOLD_MAX;
	}
}
