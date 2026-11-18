<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\CompletedTask;
use OCA\ChoreStrike\Db\CompletedTaskMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\RoomMapper;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\TaskAssigneeMapper;
use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Db\WorkShareMapper;
use OCA\ChoreStrike\Exception\NotFoundException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;

/**
 * House activity feed ("who did what when") — CS-PLAN-040 / FEAT-UX-016.
 *
 * Single data source for the Insights history list, the history of one
 * task AND per-member completed-energy aggregation. What happened besides
 * completions comes from {@see ActivityEventService}. Enrichment is batch-mapped via the
 * CS-PLAN-036 IN-map idioms (no N+1) and null-safe: room deletion cleans
 * completions but single-task deletion does not and there is no FK cascade,
 * so missing task/room references are returned as null and rendered as a
 * fallback label by the UI instead of erroring.
 */
class ActivityService {
	public const DEFAULT_DAYS = 30;
	public const MAX_DAYS = 90;
	public const DEFAULT_LIMIT = 50;
	public const MAX_LIMIT = 200;
	public const HISTORY_LIMIT = 10;
	public const MAX_HISTORY_LIMIT = 50;

	private CompletedTaskMapper $completedTaskMapper;
	private RoomTaskMapper $roomTaskMapper;
	private TaskMapper $taskMapper;
	private RoomMapper $roomMapper;
	private HouseUserMapper $houseUserMapper;
	private TaskAssigneeMapper $taskAssigneeMapper;
	private TaskAccessPolicy $taskAccessPolicy;
	private WorkShareMapper $workShareMapper;
	private CompletionAuthority $authority;
	private ActivityEventService $events;
	private IL10N $l10n;

	public function __construct(
		CompletedTaskMapper $completedTaskMapper,
		RoomTaskMapper $roomTaskMapper,
		TaskMapper $taskMapper,
		RoomMapper $roomMapper,
		HouseUserMapper $houseUserMapper,
		TaskAssigneeMapper $taskAssigneeMapper,
		TaskAccessPolicy $taskAccessPolicy,
		WorkShareMapper $workShareMapper,
		CompletionAuthority $authority,
		ActivityEventService $events,
		IL10N $l10n,
	) {
		$this->completedTaskMapper = $completedTaskMapper;
		$this->roomTaskMapper = $roomTaskMapper;
		$this->taskMapper = $taskMapper;
		$this->roomMapper = $roomMapper;
		$this->houseUserMapper = $houseUserMapper;
		$this->taskAssigneeMapper = $taskAssigneeMapper;
		$this->taskAccessPolicy = $taskAccessPolicy;
		$this->workShareMapper = $workShareMapper;
		$this->authority = $authority;
		$this->events = $events;
		$this->l10n = $l10n;
	}

	/**
	 * Get the house's completion activity for a period.
	 *
	 * Rows stay house-scoped (decision D9), but task CONTENT of tasks the
	 * viewer cannot see is redacted: task title, energy and selected work
	 * allocation of invisible private/admin-only tasks are withheld; the
	 * row itself (who completed when) remains as the household aggregate.
	 *
	 * @param int $houseId The house ID
	 * @param int $days Period length in days, clamped to [1, 90] (default 30)
	 * @param int $limit Maximum completions, clamped to [1, 200] (default 50)
	 * @param HouseUser $viewer The acting user's house membership
	 * @param string|null $targetUserId Optional Nextcloud-user filter; must be
	 *                                  a member of the house (validated here against the house scope)
	 * @return array{completions: array<int, array<string, mixed>>, total: int, events: list<array<string, mixed>>}
	 */
	public function getActivity(int $houseId, int $days, int $limit, ?string $targetUserId, HouseUser $viewer): array {
		$days = max(1, min($days, self::MAX_DAYS));
		$limit = max(1, min($limit, self::MAX_LIMIT));

		$end = new \DateTimeImmutable();
		$start = $end->modify("-{$days} days");

		$houseUserId = null;
		if ($targetUserId !== null && trim($targetUserId) !== '') {
			try {
				$member = $this->houseUserMapper->findByNextcloudUserId($houseId, $targetUserId);
			} catch (DoesNotExistException $e) {
				throw new NotFoundException($this->l10n->t('User is not a member of this house'));
			}
			$houseUserId = $member->getId();
		}

		$completions = $this->completedTaskMapper->findActivityBetweenByHouse($houseId, $start, $end, $limit, $houseUserId);
		$total = $this->completedTaskMapper->countActivityByHouse($houseId, $start, $end, $houseUserId);

		return [
			'completions' => $this->enrich($houseId, $completions, $viewer),
			'total' => $total,
			// Postponements, date changes, deletions and absences of the
			// same period: this visibility replaces approval.
			'events' => $this->events->eventsSince($houseId, $start, $limit, $houseUserId, $viewer),
		];
	}

	/**
	 * The history of one task: its valid entries, newest first, in the
	 * activity item shape with the viewer's correction rights.
	 *
	 * @return array{completions: array<int, array<string, mixed>>}
	 * @throws NotFoundException a foreign, deleted or invisible room task
	 */
	public function getRoomTaskHistory(int $houseId, int $roomTaskId, int $limit, HouseUser $viewer): array {
		$roomTask = $this->roomTaskMapper->findByIdsScopedToHouse([$roomTaskId], $houseId)[0] ?? null;
		// A deleted room task has no row to open a history from.
		$roomTask = $roomTask?->getDeletedAt() === null ? $roomTask : null;
		$task = $roomTask !== null ? ($this->taskMapper->findByIds([$roomTask->getTaskId()])[$roomTask->getTaskId()] ?? null) : null;
		if ($task === null) {
			throw new NotFoundException($this->l10n->t('Room task not found'));
		}
		$this->taskAccessPolicy->assertCanView($task, $viewer, $this->taskAssigneeMapper->findUserIdsByRoomTask($roomTaskId));

		$limit = max(1, min($limit, self::MAX_HISTORY_LIMIT));
		return ['completions' => $this->enrich($houseId, $this->completedTaskMapper->findValidByRoomTask($roomTaskId, $limit), $viewer)];
	}

	/**
	 * Batch-enrich completions with actor names and task/room details.
	 *
	 * One IN-map fetch per referenced table (CS-PLAN-036 idioms); dangling
	 * references simply miss the map and serialize as null. Task content of
	 * tasks invisible to the viewer is redacted via the ONE visibility
	 * matrix (TaskAccessPolicy, one batched assignee fetch — no N+1).
	 *
	 * @param int $houseId The house ID
	 * @param CompletedTask[] $completions Completions of the house
	 * @param HouseUser $viewer The acting user's house membership
	 * @return array<int, array<string, mixed>>
	 */
	private function enrich(int $houseId, array $completions, HouseUser $viewer): array {
		if ($completions === []) {
			return [];
		}

		$roomTaskIds = [];
		foreach ($completions as $completion) {
			$roomTaskIds[$completion->getRoomTaskId()] = true;
		}

		// findByIdsScopedToHouse is skip-on-missing — dangling room task ids
		// are absent from the map and their completions fall back to nulls.
		$roomTasksById = [];
		foreach ($this->roomTaskMapper->findByIdsScopedToHouse(array_keys($roomTaskIds), $houseId) as $roomTask) {
			$roomTasksById[$roomTask->getId()] = $roomTask;
		}

		$taskIds = [];
		$roomIds = [];
		foreach ($roomTasksById as $roomTask) {
			$taskIds[$roomTask->getTaskId()] = true;
			$roomIds[$roomTask->getRoomId()] = true;
		}
		$tasksById = $this->taskMapper->findByIds(array_keys($taskIds));
		$roomsById = $this->roomMapper->findByIds(array_keys($roomIds));

		// Visibility redaction set: the room tasks of this batch the viewer
		// may see (D2 matrix, ADR-010: a private task per room task, its
		// creator and THAT room task's assignees). Content of the other rows
		// serializes as null.
		$visibleRoomTaskIds = [];
		foreach ($this->taskAccessPolicy->filterVisibleRoomTasks(array_values($roomTasksById), $tasksById, $viewer) as $roomTask) {
			$visibleRoomTaskIds[(int)$roomTask->getId()] = true;
		}

		// Houses are small — one membership fetch covers every completer.
		$membersById = [];
		$membersByWalletId = [];
		foreach ($this->houseUserMapper->findByHouseId($houseId) as $member) {
			$membersById[$member->getId()] = $member;
			if ($member->getWalletId() !== null) {
				$membersByWalletId[$member->getWalletId()] = $member;
			}
		}
		$sharesByCompletion = $this->workShareMapper->findSelectedByCompletionIds(array_map(
			static fn (CompletedTask $completion): int => (int)$completion->getId(),
			$completions
		));
		$latestValidIds = $this->completedTaskMapper->findLatestValidIdsByRoomTasks(array_keys($roomTaskIds));

		$items = [];
		foreach ($completions as $completion) {
			$item = $this->serializeCompletion($completion, $roomTasksById, $tasksById, $roomsById, $membersById, $visibleRoomTaskIds);
			$visible = $item['totalEnergy'] !== null;
			// Rights per ADR-021 decision 9: the owner corrects any entry,
			// everyone else only their own latest entry of the task.
			$item['canCorrect'] = $visible && $this->authority->canCorrect($viewer, $completion, $latestValidIds[$completion->getRoomTaskId()] ?? null);
			$item['canAttribute'] = $visible && $this->authority->canAttribute($viewer, $completion);
			$item['allocation'] = [];
			if ($visible) {
				foreach ($sharesByCompletion[$completion->getId()] ?? [] as $share) {
					$shareMember = $membersByWalletId[$share->getWalletId()] ?? null;
					$item['allocation'][] = [
						'userId' => $shareMember?->getNextcloudUserId(),
						'displayName' => $shareMember?->getDisplayName() ?? $this->l10n->t('Former member'),
						'sharePct' => $share->getSharePct(),
						'energyMinor' => $share->getEnergyMinor(),
						// What the share paid, frozen at completion; withheld
						// with the energy for a task the viewer cannot see.
						'coinMinor' => $share->getCoinMinor(),
					];
				}
			}
			// Someone recorded the work for other people (decision 10).
			$item['recordedForOthers'] = $item['allocation'] !== [] && $completion->getUserId() !== null
				&& !in_array(($membersById[$completion->getUserId()] ?? null)?->getNextcloudUserId(), array_column($item['allocation'], 'userId'), true);
			$items[] = $item;
		}

		return $items;
	}

	/**
	 * Project one completion into its API item shape. Task content (title,
	 * energy) is only serialized when the task is visible to the viewer;
	 * invisible tasks redact to null (the UI renders its "Unknown task"
	 * fallback), so the house-wide feed cannot leak private task names.
	 *
	 * WP3 shape: stable completion id + occurrence key, frozen total
	 * effort, versioned correction state; `wasCombo` and live-definition
	 * effort are gone.
	 *
	 * @param array<int, bool> $visibleRoomTaskIds Room task ids visible to the viewer
	 * @return array<string, mixed>
	 */
	private function serializeCompletion(
		CompletedTask $completion,
		array $roomTasksById,
		array $tasksById,
		array $roomsById,
		array $membersById,
		array $visibleRoomTaskIds,
	): array {
		$roomTask = $roomTasksById[$completion->getRoomTaskId()] ?? null;
		$task = $roomTask !== null ? ($tasksById[$roomTask->getTaskId()] ?? null) : null;
		$room = $roomTask !== null ? ($roomsById[$roomTask->getRoomId()] ?? null) : null;
		$member = $membersById[$completion->getUserId()] ?? null;
		$taskVisible = $task !== null && isset($visibleRoomTaskIds[(int)$roomTask->getId()]);

		// CS-PLAN-071 WP0 (Q-070-02 / decision D3): an erased (or departed)
		// actor renders through the localized placeholder — the activity
		// row keeps its DTO shape, the personal identity stays gone.
		$actorDisplayName = $member?->getDisplayName() ?? $this->l10n->t('Former member');

		return [
			'id' => $completion->getId(),
			'occurrenceId' => $completion->getScheduleRevId() !== null && $completion->getOccurrenceOrdinal() !== null
				? $completion->getScheduleRevId() . ':' . $completion->getOccurrenceOrdinal()
				: null,
			'completedAt' => $completion->getCompletedAt()?->format('c'),
			// Frozen original work (server-owned); never the mutable
			// live-definition energy.
			'totalEnergy' => $taskVisible ? $completion->getTotalEnergy() : null,
			'version' => $completion->getCompletionVersion(),
			'allocationRevision' => $completion->getAllocationRevision(),
			'undoneAt' => $completion->getUndoneAt()?->format('c'),
			'houseUserId' => $completion->getUserId(),
			'displayName' => $actorDisplayName,
			'taskId' => $task?->getId(),
			'taskTitle' => $taskVisible ? $task->getName() : null,
			'energyCost' => $taskVisible ? $task->getEnergyCost() : null,
			'roomId' => $room?->getId(),
			'roomName' => $room?->getName(),
			'roomTaskId' => $roomTask?->getId(),
			'workDate' => $completion->getWorkDate()?->format('Y-m-d'),
			'completionClass' => $taskVisible ? $completion->getCompletionClass() : null,
		];
	}
}
