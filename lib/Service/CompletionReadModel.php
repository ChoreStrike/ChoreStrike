<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\CompletedTask;
use OCA\ChoreStrike\Db\CompletedTaskMapper;
use OCA\ChoreStrike\Db\CompletionRevisionMapper;
use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\Room;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Db\TaskAssigneeMapper;
use OCA\ChoreStrike\Db\WalletMapper;
use OCA\ChoreStrike\Db\WorkShare;
use OCA\ChoreStrike\Db\WorkShareMapper;
use OCP\IL10N;

/**
 * What the completion routes answer with (WP3 §3.6): the read model of one
 * completion and the shared response shape of the commands. Versioned
 * identity, caller-safe projections, the caller's own wallet in minor
 * units and the people credited — never a foreign wallet total, never a
 * raw audit snapshot. Write-free.
 */
class CompletionReadModel {
	public function __construct(
		private CompletionAccess $access,
		private CompletedTaskMapper $completedTaskMapper,
		private WorkShareMapper $workShareMapper,
		private CompletionRevisionMapper $revisionMapper,
		private HouseUserMapper $houseUserMapper,
		private WalletMapper $walletMapper,
		private TaskAssigneeMapper $taskAssigneeMapper,
		private TaskAccessPolicy $taskAccessPolicy,
		private CompletionAuthority $authority,
		private WalletService $walletService,
		private RoomTaskReadService $roomTaskReads,
		private CalendarService $calendar,
		private IL10N $l10n,
	) {
	}

	/**
	 * Someone recorded the work for other people (ADR-021 decision 10):
	 * the entry has people credited and a recorder who is none of them. An
	 * erased recorder is nobody's; a recorder who left the house still
	 * recorded it.
	 *
	 * @param int|null $recorderHouseUserId `completed_tasks.user_id`
	 * @param HouseUser|null $recorder The recorder while a member of the house
	 * @param list<string|null> $creditedUserIds
	 */
	public static function recordedForOthers(?int $recorderHouseUserId, ?HouseUser $recorder, array $creditedUserIds): bool {
		return $creditedUserIds !== [] && $recorderHouseUserId !== null
			&& !in_array($recorder?->getNextcloudUserId(), $creditedUserIds, true);
	}

	/**
	 * The read model of ONE completion (`GET /completions/{id}`): its
	 * frozen work facts, the selected allocation, the audit history and
	 * what the viewer may correct.
	 *
	 * @return array<string, mixed>
	 */
	public function show(int $houseId, HouseUser $viewer, int $completionId): array {
		[$completion, $roomTask] = $this->access->target($completionId, $houseId);
		$task = $this->access->task($roomTask->getTaskId());
		$this->taskAccessPolicy->assertCanView(
			$task, $viewer, $this->taskAssigneeMapper->findUserIdsByRoomTask($roomTask->getId())
		);

		$membersById = [];
		foreach ($this->houseUserMapper->findByHouseId($houseId) as $member) {
			$membersById[(int)$member->getId()] = $member;
		}
		$allocation = $this->allocation($completion, $membersById, true);

		$history = [];
		foreach ($this->revisionMapper->findByCompletionId($completionId) as $revision) {
			// The audit actor stays immutable; a corrector who left the
			// house renders as the localized placeholder.
			$actor = $membersById[(int)($revision->getActorHouseUserId() ?? 0)] ?? null;
			$history[] = [
				'revision' => $revision->getRevision(),
				'action' => $revision->getAction(),
				'allocationRevision' => $revision->getAllocationRevision(),
				'actor' => $actor?->getNextcloudUserId(),
				'actorDisplayName' => $actor?->getDisplayName() ?? $this->l10n->t('Removed member'),
				'reasonCode' => $revision->getReasonCode(),
				'createdAt' => $revision->getCreatedAt()?->format('c'),
			];
		}

		$roomTaskId = (int)$roomTask->getId();
		$latestValidId = $this->completedTaskMapper->findLatestValidIdsByRoomTasks([$roomTaskId])[$roomTaskId] ?? null;
		$recorder = $membersById[(int)($completion->getUserId() ?? 0)] ?? null;

		return [
			'id' => (int)$completion->getId(),
			'roomTaskId' => $completion->getRoomTaskId(),
			'taskName' => $task->getName(),
			'completedAt' => $completion->getCompletedAt()?->format('c'),
			'totalEnergy' => $completion->getTotalEnergy(),
			'occurrenceId' => $completion->getScheduleRevId() !== null && $completion->getOccurrenceOrdinal() !== null
				? $completion->getScheduleRevId() . ':' . $completion->getOccurrenceOrdinal()
				: null,
			'cycleKey' => $completion->getCycleKey(),
			'windowStartOn' => $completion->getWindowStartOn()?->format('Y-m-d'),
			'windowEndOn' => $completion->getWindowEndOn()?->format('Y-m-d'),
			'workDate' => $completion->getWorkDate()?->format('Y-m-d'),
			'workZone' => $completion->getWorkZone(),
			'completionClass' => $completion->getCompletionClass(),
			// The percent of a bonus period, frozen with the work (decision 11).
			'bonusPercent' => $completion->bonusPercentOrDefault(),
			'version' => $completion->getCompletionVersion(),
			'allocationRevision' => $completion->getAllocationRevision(),
			'undoneAt' => $completion->getUndoneAt()?->format('c'),
			'allocation' => $allocation,
			'recordedBy' => self::recordedForOthers($completion->getUserId(), $recorder, array_column($allocation, 'userId')) ? [
				'userId' => $recorder?->getNextcloudUserId(),
				'displayName' => $recorder?->getDisplayName() ?? $this->l10n->t('Removed member'),
			] : null,
			'history' => $history,
			'canCorrect' => $this->authority->canCorrect($viewer, $completion, $latestValidId),
			'canAttribute' => $this->authority->canAttribute($viewer, $completion),
		];
	}

	/**
	 * The response shape the completion commands share: the versioned
	 * completion, the people credited and the viewer's own wallet.
	 *
	 * @param string $viewerUserId Empty for an answer without the caller's wallet
	 * @param Task|null $task The definition when the caller already read it
	 * @return array<string, mixed>
	 */
	public function response(House $house, string $viewerUserId, CompletedTask $completion, RoomTask $roomTask, ?Room $room, bool $replay, ?Task $task = null): array {
		$houseId = (int)$house->getId();
		$task ??= $this->access->task($roomTask->getTaskId());
		$membersById = [];
		$viewer = null;
		foreach ($this->houseUserMapper->findByHouseId($houseId) as $member) {
			$membersById[(int)$member->getId()] = $member;
			if ($member->getNextcloudUserId() === $viewerUserId) {
				$viewer = $member;
			}
		}
		$wallet = $viewer?->getWalletId() !== null ? $this->walletMapper->findByHouseAndUser($houseId, $viewerUserId) : null;
		$undone = $completion->getUndoneAt() !== null;

		return [
			'roomTask' => $roomTask,
			'room' => $room,
			'task' => $task,
			'rewards' => [
				'completion' => [
					'id' => (int)$completion->getId(),
					'version' => $completion->getCompletionVersion(),
					'allocationRevision' => $completion->getAllocationRevision(),
					'totalEnergy' => $completion->getTotalEnergy(),
					'completedAt' => $completion->getCompletedAt()?->format('c'),
					'undoneAt' => $completion->getUndoneAt()?->format('c'),
					'bonusPercent' => $completion->bonusPercentOrDefault(),
				],
				'participants' => $undone ? [] : $this->allocation($completion, $membersById, false),
				'wallet' => $wallet !== null ? $this->walletService->serializeOwnWallet($wallet) : null,
				'contributionMinor' => $viewer?->getContributionMinor(),
				// The requested journal delta of THIS completion (the client
				// renders the observed cleanliness from the room block).
				'cleanlinessChange' => ($undone ? -1 : 1) * $completion->getTotalEnergy() * 10,
				'energyCost' => $task->getEnergyCost(),
				'priority' => $task->getPriority(),
			],
			'completionId' => (int)$completion->getId(),
			'replay' => $replay,
		];
	}

	/**
	 * The response of an undo: the completion as it reads now, with the
	 * room and the room task's next target.
	 *
	 * @param Task|null $task The definition when the caller already read it
	 * @return array<string, mixed>
	 */
	public function undoResponse(House $house, CompletedTask $completion, RoomTask $roomTask, bool $replay, ?Task $task = null): array {
		$response = $this->response($house, '', $completion, $roomTask, $this->access->room($roomTask->getRoomId()), $replay, $task);
		$response['undone'] = $completion->getUndoneAt() !== null;
		$response['cycle'] = $this->cycleBlock($roomTask, $house, $response['task']);
		return $response;
	}

	/**
	 * The fresh `cycle` block of a room task: its next completion target
	 * after a command.
	 *
	 * @param Task|null $task The definition when the caller already read it
	 */
	public function cycleBlock(RoomTask $roomTask, House $house, ?Task $task = null): ?array {
		return $this->roomTaskReads->cycleFacts(
			(int)$house->getId(), [$roomTask], $task === null ? null : [(int)$task->getId() => $task], null, $this->calendar->getTimeZone($house)
		)[(int)$roomTask->getId()]['block'] ?? null;
	}

	/**
	 * The selected allocation, joined only to live memberships (never to
	 * wallet balances). A share's `coinFactor` is the factor frozen for
	 * its member.
	 *
	 * @param array<int, HouseUser> $membersById
	 * @param bool $withFactors Whether to name each share's frozen coin factor
	 * @return list<array<string, mixed>>
	 */
	private function allocation(CompletedTask $completion, array $membersById, bool $withFactors): array {
		$membersByWallet = [];
		foreach ($membersById as $member) {
			if ($member->getWalletId() !== null) {
				$membersByWallet[(int)$member->getWalletId()] = $member;
			}
		}
		return array_map(function (WorkShare $share) use ($completion, $membersByWallet, $withFactors): array {
			$member = $membersByWallet[(int)($share->getWalletId() ?? 0)] ?? null;
			return [
				'userId' => $member?->getNextcloudUserId(),
				'displayName' => $member?->getDisplayName() ?? $this->l10n->t('Removed member'),
				'sharePct' => $share->getSharePct(),
				'energyMinor' => $share->getEnergyMinor(),
				'coinMinor' => $share->getCoinMinor(),
			] + ($withFactors ? ['coinFactor' => $share->getWalletId() !== null ? $completion->coinFactorOf((int)$share->getWalletId()) : 1] : []);
		}, $this->workShareMapper->findByCompletionAndRevision((int)$completion->getId(), $completion->getAllocationRevision()));
	}
}
