<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

/**
 * Post-commit boundary owner for completion/undo (CS-PLAN-067 §3.2,
 * decision D2 — the D7 log-and-degrade semantics are unchanged).
 *
 * The weekly-goal read runs AFTER the transaction has committed and is
 * failure-isolated: a committed completion always answers success, with
 * `degraded = true` when the post-commit effect failed. No notification
 * dispatch ever moves under a lock.
 *
 * CS-PLAN-070 WP5 (Q-076-02/RF-076-02): the former title-unlock and
 * overdue-takeover dispatches are gone — awards stay quietly in-app and
 * the takeover change is queued INSIDE the completion transaction by
 * {@see CompletionService} (organizational digest queue), so this
 * boundary no longer dispatches anything external.
 */
class CompletionPostCommitService {
	private HouseService $houseService;
	private AppLogger $appLogger;

	public function __construct(
		HouseService $houseService,
		AppLogger $appLogger,
	) {
		$this->houseService = $houseService;
		$this->appLogger = $appLogger;
	}

	/**
	 * Run the completion post-commit phase: the provisional weekly-goal
	 * read.
	 *
	 * @return array{weeklyGoal: ?array<string, mixed>, degraded: bool}
	 */
	public function runForCompletion(
		int $houseId,
		int $roomTaskId,
		string $userId,
	): array {
		$degraded = false;
		$weeklyGoal = null;
		try {
			$weeklyGoal = $this->houseService->getWeeklyGoalProgress($houseId);
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
	 * Merge a post-commit result into a command's answer: the refreshed
	 * weekly goal and whether the refresh degraded. Without a result (a
	 * replay, a no-op) the answer stays as it is.
	 *
	 * @param array<string, mixed> $response
	 * @param array{weeklyGoal: ?array<string, mixed>, degraded: bool}|null $post
	 * @return array<string, mixed>
	 */
	public static function merge(array $response, ?array $post): array {
		if ($post === null) {
			return $response;
		}
		$response['rewards']['weeklyGoal'] = $post['weeklyGoal'];
		if ($post['degraded']) {
			$response['rewards']['degraded'] = true;
		}
		return $response;
	}

	/**
	 * Run the post-commit phase of a correction that moves what a week
	 * counts (an undo, a date correction): the weekly-goal refresh in the
	 * same log-and-degrade boundary (D7); closed-week bell receipts are
	 * not retracted by a correction (D8).
	 *
	 * @return array{weeklyGoal: ?array<string, mixed>, degraded: bool}
	 */
	public function runForCorrection(int $houseId, int $roomTaskId, string $actorUserId): array {
		$weeklyGoal = null;
		$degraded = false;
		try {
			$weeklyGoal = $this->houseService->getWeeklyGoalProgress($houseId);
		} catch (\Throwable $e) {
			$degraded = true;
			// The stable key of ADR-011 §5: the undo and the date correction share it.
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
}
