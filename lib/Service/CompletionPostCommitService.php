<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\HouseUserMapper;

/**
 * Post-commit boundary owner for completion/undo (CS-PLAN-067 §3.2,
 * decision D2 — the D7 log-and-degrade semantics are unchanged).
 *
 * The weekly-goal read and post-completion dispatch run
 * AFTER the transaction has committed and are failure-isolated: a committed
 * completion always answers success, with `degraded = true` when any
 * post-commit effect failed. No notification dispatch ever moves under a
 * lock. Closed-week bell claims are handled by the timeline job, not by a
 * provisional current-week completion.
 */
class CompletionPostCommitService {
	private HouseService $houseService;
	private HouseUserMapper $houseUserMapper;
	private NotificationService $notificationService;
	private AppLogger $appLogger;

	public function __construct(
		HouseService $houseService,
		HouseUserMapper $houseUserMapper,
		NotificationService $notificationService,
		AppLogger $appLogger,
	) {
		$this->houseService = $houseService;
		$this->houseUserMapper = $houseUserMapper;
		$this->notificationService = $notificationService;
		$this->appLogger = $appLogger;
	}

	/**
	 * Run the completion post-commit phase: provisional weekly-goal read,
	 * deferred overdue-takeover dispatch and title-unlock notification.
	 *
	 * @param array<string, mixed>|null $takeoverNotification Captured in-transaction, dispatched here
	 * @param array<string, mixed> $gameResult Reward phase output (newTitle)
	 * @return array{weeklyGoal: ?array<string, mixed>, degraded: bool}
	 */
	public function runForCompletion(
		int $houseId,
		int $roomTaskId,
		string $userId,
		array $gameResult,
		?array $takeoverNotification,
	): array {
		$degraded = false;
		$weeklyGoal = null;
		try {
			$weeklyGoal = $this->houseService->getWeeklyGoalProgress($houseId);

			$this->dispatchTakeoverNotification($takeoverNotification, $houseId);

			$newTitle = $gameResult['newTitle'] ?? null;
			if ($newTitle !== null && $userId !== '') {
				$this->notificationService->notifyTitleUnlocked(
					$userId,
					$newTitle['pack'],
					$newTitle['labelKey'],
					$newTitle['packLabelKey'],
					$newTitle['tierIndex']
				);
			}
		} catch (\Throwable $e) {
			$degraded = true;
			$weeklyGoal = null;
			$this->appLogger->warning('CompletionService: post-commit effects degraded', [
				'event' => 'completion_post_commit_degraded',
				'service' => 'CompletionService',
				'houseId' => $houseId,
				'roomTaskId' => $roomTaskId,
				'userId' => $userId,
				'exception' => get_class($e),
			]);
		}

		return ['weeklyGoal' => $weeklyGoal, 'degraded' => $degraded];
	}

	/**
	 * Run the undo post-commit phase: the weekly-goal refresh in the same
	 * log-and-degrade boundary (D7); closed-week bell receipts are not
	 * retracted by a correction (D8).
	 *
	 * @return array{weeklyGoal: ?array<string, mixed>, degraded: bool}
	 */
	public function runForUndo(int $houseId, int $roomTaskId, string $actorUserId): array {
		$weeklyGoal = null;
		$degraded = false;
		try {
			$weeklyGoal = $this->houseService->getWeeklyGoalProgress($houseId);
		} catch (\Throwable $e) {
			$degraded = true;
			$this->appLogger->warning('CompletionService: post-undo effects degraded', [
				'event' => 'undo_post_commit_degraded',
				'service' => 'CompletionService',
				'houseId' => $houseId,
				'roomTaskId' => $roomTaskId,
				'userId' => $actorUserId,
				'exception' => get_class($e),
			]);
		}

		return ['weeklyGoal' => $weeklyGoal, 'degraded' => $degraded];
	}

	/**
	 * Dispatch the deferred overdue-takeover notification with the
	 * membership re-check at dispatch time (documented defense-in-depth).
	 *
	 * @param array<string, mixed>|null $takeoverNotification
	 */
	private function dispatchTakeoverNotification(?array $takeoverNotification, int $houseId): void {
		if ($takeoverNotification === null) {
			return;
		}

		$stillMember = $this->houseUserMapper->findByHouseAndUser(
			$houseId,
			(string)$takeoverNotification['recipient']
		);
		if ($stillMember === null) {
			return;
		}

		$this->notificationService->notifyOverdueTakenOver(
			(string)$takeoverNotification['recipient'],
			(string)$takeoverNotification['taskName'],
			(string)$takeoverNotification['claimerUserId'],
			(string)$takeoverNotification['claimerDisplayName'],
			(int)$takeoverNotification['roomTaskId']
		);
	}

}
