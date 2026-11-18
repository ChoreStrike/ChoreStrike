<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\CompletedTaskMapper;
use OCA\ChoreStrike\Db\CompletionRevision;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\WorkShareMapper;
use OCA\ChoreStrike\Exception\ConflictException;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Exception\NotFoundException;
use OCP\IL10N;

/**
 * The date correction of one completion (ADR-021 decision 9): the one
 * audited change of a work fact. The entry takes a house-local day that is
 * not in the future and lies between the days of the room task's
 * neighbouring valid entries ({@see CompletionDateCorrection}); what hangs
 * on the day follows — the classification, the room journal event, the
 * closed cycle and, under an after-completion rule, the cycle that
 * followed. Shares, energy and coins stay as recorded, also the percent
 * of a bonus period (D-091-49).
 *
 * The owner re-dates any entry, every other member only the latest valid
 * entry of the task that they recorded. One CommandService transaction
 * under the house-first lock, in the lock order of the undo.
 */
class CompletionDateCommand {
	public function __construct(
		private CommandService $commandService,
		private CompletionAccess $access,
		private CompletionLocks $locks,
		private CompletionEvidence $evidence,
		private CompletionRecognition $recognition,
		private CompletionReadModel $readModel,
		private CompletionDateCorrection $dateCorrection,
		private CompletionPostCommitService $postCommit,
		private CompletedTaskMapper $completedTaskMapper,
		private WorkShareMapper $workShareMapper,
		private HouseMapper $houseMapper,
		private RoomReplayService $roomReplay,
		private CycleService $cycles,
		private TaskResponsibility $responsibility,
		private CalendarService $calendar,
		private IL10N $l10n,
		private AppLogger $appLogger,
	) {
	}

	/**
	 * @param string $dateKey The corrected house-local day (`Y-m-d`)
	 * @return array<string, mixed> The completion response with the fresh `cycle` block
	 * @throws NotFoundException invisible/foreign completion
	 * @throws ForbiddenException no correction authority
	 * @throws ConflictException stale version, or a deleted entry
	 * @throws \InvalidArgumentException a day the entry cannot take
	 */
	public function changeDate(int $houseId, HouseUser $actor, int $completionId, string $commandId, int $expectedVersion, string $dateKey): array {
		// Whether the entry is still the task's latest is a state the
		// aggregate decides under the lock; a replay never re-tests it.
		[$completion, $roomTask] = $this->access->target($completionId, $houseId);
		$this->access->authorize($houseId, $actor, $completion, $roomTask, CompletionAccess::RIGHTS_ENTRY);
		$house = $this->houseMapper->find($houseId);

		try {
			$command = $this->commandService->runCommand(
				$houseId,
				(int)$actor->getId(),
				$commandId,
				'completion.date',
				['completionId' => $completionId, 'expectedVersion' => $expectedVersion, 'date' => $dateKey],
				fn (): array => $this->aggregate($houseId, $actor, $completionId, $commandId, $expectedVersion, $dateKey),
				// Execution and replay alike, under the house lock: the actor
				// is still a member who may see the task and correct the entry.
				function () use ($houseId, $actor, $completionId): void {
					[$locked, $lockedRoomTask] = $this->access->target($completionId, $houseId);
					$this->access->authorize($houseId, $actor, $locked, $lockedRoomTask, CompletionAccess::RIGHTS_ENTRY);
				},
			);
		} catch (\Throwable $e) {
			// A refused day is the caller's input, not a failure of the aggregate.
			if (!$e instanceof \InvalidArgumentException) {
				$this->appLogger->logException('CompletionService: date correction rolled back', $e, [
					'event' => 'date_correction_rollback',
					'service' => 'CompletionService',
					'houseId' => $houseId,
					'completionId' => $completionId,
					'userId' => $actor->getNextcloudUserId(),
				]);
			}
			throw $e;
		}

		[$fresh, $freshRoomTask] = $this->access->target($completionId, $houseId);
		$task = $this->access->authorize($houseId, $actor, $fresh, $freshRoomTask, CompletionAccess::RIGHTS_NONE);
		$response = $this->readModel->response(
			$house, $actor->getNextcloudUserId(), $fresh, $freshRoomTask, $this->access->room($freshRoomTask->getRoomId()), $command['replay'], $task
		);
		$response['cycle'] = $this->readModel->cycleBlock($freshRoomTask, $house, $task);
		if (!$command['replay']) {
			$post = $this->postCommit->runForCorrection($houseId, $fresh->getRoomTaskId(), $actor->getNextcloudUserId());
			$response['rewards']['weeklyGoal'] = $post['weeklyGoal'];
			if ($post['degraded']) {
				$response['rewards']['degraded'] = true;
			}
		}
		return $response;
	}

	/**
	 * The aggregate inside the command transaction.
	 *
	 * @return array<string, mixed> The stored receipt identity
	 */
	private function aggregate(int $houseId, HouseUser $actor, int $completionId, string $commandId, int $expectedVersion, string $dateKey): array {
		$target = $this->access->lockTarget($houseId, $actor, $completionId, CompletionAccess::RIGHTS_LATEST);
		$completion = $target->completion;
		if ($completion->getUndoneAt() !== null) {
			throw new ConflictException($this->l10n->t('This entry was deleted'));
		}
		$this->access->assertVersion($completion, $expectedVersion);

		// The memberships of the people credited and of the assignees the
		// cycle counts for.
		$walletIds = [];
		foreach ($this->workShareMapper->findByCompletionAndRevision($completionId, $completion->getAllocationRevision()) as $share) {
			if ($share->getWalletId() !== null) {
				$walletIds[] = (int)$share->getWalletId();
			}
		}
		$roomTaskId = (int)$target->roomTask->getId();
		$members = $this->locks->membersOfWallets($houseId, $walletIds, $this->responsibility->membersOf($houseId, $roomTaskId));

		$now = new \DateTime();
		$task = $target->task;
		// Read under the house lock: a zone edit is a house command too.
		$timeZone = $this->calendar->getTimeZone($this->houseMapper->find($houseId));
		$previousDay = $this->dateCorrection->apply($completion, $dateKey, $timeZone, $now);
		$newVersion = $completion->getCompletionVersion() + 1;
		$completion->setCompletionVersion($newVersion);
		$this->completedTaskMapper->update($completion);
		$this->evidence->writeRevision(
			$completion, $newVersion, CompletionRevision::ACTION_DATE, $completion->getAllocationRevision(), (int)$actor->getId(), null, $commandId
		);
		// Under an after-completion rule the entry that followed hangs on this day.
		$follower = $this->dateCorrection->moveFollower($completion, $task, $previousDay);

		// Room journal: the completion's event moves to the corrected
		// instant and the replay rebuilds the room.
		$this->roomReplay->moveCompletion($completionId, $completion->getCompletedAt());
		$this->roomReplay->resyncRoomCache($target->room);

		$this->cycles->redatedUnderLock($target->roomTask, $task, $completion, $follower, $timeZone, $now);
		$this->recognition->recompute($houseId, $members, $now);

		return ['completionId' => $completionId, 'version' => $newVersion];
	}
}
