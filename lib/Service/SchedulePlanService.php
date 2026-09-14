<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\CompletedTaskMapper;
use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\RoomMapper;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Db\TaskAssigneeMapper;
use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Db\TransactionExecutor;
use OCA\ChoreStrike\Exception\ConflictException;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Exception\NotFoundException;
use OCP\IL10N;

/**
 * Owner of the scheduling preview/apply aggregate (CS-PLAN-065, ADR-012).
 *
 * Preview builds one deterministic plan over REAL active room tasks for a
 * seven-day house-local window: legal recurrence/flex slots first, then
 * member eligibility, then availability/energy capacity and child limits as
 * hard placement constraints (decision D6). It performs zero writes and
 * returns an opaque content fingerprint (`previewVersion`) over every
 * authoritative dependency, so a stale confirmation is detectable.
 *
 * Apply re-runs the identical build inside one transaction under the
 * documented lock order and rejects anything that no longer matches:
 *
 *     house → room_task (ascending) → task definition (ascending)
 *           → house_user (ascending) → assignee writes
 *
 * This refines — never inverts — ADR-011's `room_task → room → house_user`
 * completion order (apply takes no room lock; both orders agree that
 * room_task precedes house_user). Creation/setup writers take the house
 * lock first and therefore serialize; completion/undo and due-date edits
 * block on the room-task row locks; definition, membership, role and
 * preference writers block on the row locks apply holds.
 *
 * Undo interaction (decision D8): a later undo restores its recorded
 * recurrence/claim state regardless of scheduling; claimed rows are never
 * rescheduled, so apply cannot erase a claim.
 */
class SchedulePlanService {
	/** Maximum selected room tasks per plan request (plan §3.1 rule 1). */
	public const MAX_BATCH_SIZE = 100;

	/** Owner-only child daily-task limit bounds (decision D5). */
	public const MAX_DAILY_TASKS_MIN = 1;
	public const MAX_DAILY_TASKS_MAX = 100;

	/** Planning window length in days (decision D3). */
	public const WINDOW_DAYS = 7;

	private RoomTaskMapper $roomTaskMapper;
	private TaskMapper $taskMapper;
	private RoomMapper $roomMapper;
	private HouseMapper $houseMapper;
	private HouseUserMapper $houseUserMapper;
	private TaskAssigneeMapper $taskAssigneeMapper;
	private CompletedTaskMapper $completedTaskMapper;
	private TransactionExecutor $transactions;
	private CalendarService $calendarService;
	private TaskAccessPolicy $taskAccessPolicy;
	private IL10N $l10n;

	public function __construct(
		RoomTaskMapper $roomTaskMapper,
		TaskMapper $taskMapper,
		RoomMapper $roomMapper,
		HouseMapper $houseMapper,
		HouseUserMapper $houseUserMapper,
		TaskAssigneeMapper $taskAssigneeMapper,
		CompletedTaskMapper $completedTaskMapper,
		TransactionExecutor $transactions,
		CalendarService $calendarService,
		TaskAccessPolicy $taskAccessPolicy,
		IL10N $l10n,
	) {
		$this->roomTaskMapper = $roomTaskMapper;
		$this->taskMapper = $taskMapper;
		$this->roomMapper = $roomMapper;
		$this->houseMapper = $houseMapper;
		$this->houseUserMapper = $houseUserMapper;
		$this->taskAssigneeMapper = $taskAssigneeMapper;
		$this->completedTaskMapper = $completedTaskMapper;
		$this->transactions = $transactions;
		$this->calendarService = $calendarService;
		$this->taskAccessPolicy = $taskAccessPolicy;
		$this->l10n = $l10n;
	}

	/**
	 * Build the deterministic preview. Read-only: no domain table is written.
	 *
	 * @return array{previewVersion: string, startDate: string, endDateExclusive: string, timezone: string, changes: list, unchanged: list, unplaced: list, capacity: list, warnings: list}
	 */
	public function preview(int $houseId, HouseUser $actor, string $startDate, array $roomTaskIds, array $overrides): array {
		$this->assertOwnerActor($houseId, $actor);
		$this->assertValidRequest($startDate, $roomTaskIds, $overrides);

		return $this->buildPlan($houseId, $actor, $startDate, $roomTaskIds, $overrides)['response'];
	}

	/**
	 * Apply the confirmed plan as one all-or-nothing aggregate.
	 *
	 * The exact preview input plus its `previewVersion` is re-validated from
	 * freshly locked rows; any drift rejects the batch with a ConflictException
	 * (409) and zero writes. A confirmed no-change plan performs zero writes.
	 *
	 * @return array{appliedCount: int, windowStart: string, windowEndExclusive: string, roomTasks: list}
	 */
	public function apply(int $houseId, HouseUser $actor, string $startDate, array $roomTaskIds, array $overrides, string $previewVersion): array {
		$this->assertOwnerActor($houseId, $actor);
		$this->assertValidRequest($startDate, $roomTaskIds, $overrides);
		if (!is_string($previewVersion) || $previewVersion === '') {
			throw new \InvalidArgumentException($this->l10n->t('previewVersion is required'));
		}

		$result = $this->transactions->run(function () use ($houseId, $actor, $startDate, $roomTaskIds, $overrides, $previewVersion) {
			// FIRST lock class: the house row — coordinates creation/setup
			// writers and pins the timezone inputs for this transaction.
			$this->houseMapper->findForUpdate($houseId);

			// Under-lock actor re-validation (membership + owner role).
			$freshActor = $this->houseUserMapper->findByHouseAndUser($houseId, $actor->getNextcloudUserId());
			if ($freshActor === null) {
				throw new ForbiddenException($this->l10n->t('Not a member of this house'));
			}
			if (!$freshActor->isOwner()) {
				throw new ForbiddenException($this->l10n->t('Owner role required'));
			}

			// Lock all capacity-relevant room tasks ascending: the selected
			// rows plus every active task that could recur into the window
			// (phantom protection against concurrent completions/edits).
			$endBound = $this->windowEndBound($houseId, $startDate);
			$baseIds = [];
			foreach ($this->roomTaskMapper->findActiveDueUpToByHouse($houseId, $endBound) as $baseRow) {
				$baseIds[(int)$baseRow->getId()] = true;
			}
			foreach ($roomTaskIds as $id) {
				$baseIds[$id] = true;
			}
			$lockIds = array_keys($baseIds);
			sort($lockIds);
			foreach ($lockIds as $id) {
				try {
					$this->roomTaskMapper->lockForUpdate($id);
				} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
					// A base-set row vanished between the read and the lock —
					// it no longer contributes capacity; a SELECTED row is
					// re-resolved (and 404ed) inside buildPlan below.
					if (in_array($id, $roomTaskIds, true)) {
						throw new NotFoundException($this->l10n->t('Room task not found'));
					}
				}
			}

			// Re-resolve the selected rows under the locks: cross-house or
			// vanished ids are absent from the scoped fetch (→ 404).
			$scopedIds = array_map(
				static fn (RoomTask $row): int => (int)$row->getId(),
				$this->roomTaskMapper->findByIdsScopedToHouse($roomTaskIds, $houseId)
			);
			foreach ($roomTaskIds as $id) {
				if (!in_array($id, $scopedIds, true)) {
					throw new NotFoundException($this->l10n->t('Room task not found'));
				}
			}

			// SECOND lock class: task definitions ascending (blocks
			// concurrent definition edits for the whole plan window).
			$taskIds = [];
			foreach ($this->roomTaskMapper->findByIdsScopedToHouse($lockIds, $houseId) as $lockedRow) {
				$taskIds[(int)$lockedRow->getTaskId()] = true;
			}
			$definitionIds = array_keys($taskIds);
			sort($definitionIds);
			foreach ($definitionIds as $taskId) {
				$this->taskMapper->lockForUpdate($taskId);
			}

			// THIRD lock class: house_user rows ascending (membership,
			// role, preference and reward-row writers block here).
			$memberRowIds = array_map(
				static fn (HouseUser $member): int => (int)$member->getId(),
				$this->houseUserMapper->findByHouseId($houseId)
			);
			sort($memberRowIds);
			foreach ($memberRowIds as $memberRowId) {
				$this->houseUserMapper->findForUpdate($memberRowId);
			}

			// Recompute the identical plan from the locked state; any drift
			// from the confirmed preview rejects the batch.
			$plan = $this->buildPlan($houseId, $freshActor, $startDate, $roomTaskIds, $overrides);
			if (!hash_equals($previewVersion, $plan['response']['previewVersion'])) {
				throw new ConflictException($this->l10n->t('The schedule preview is no longer current. Generate a new preview.'));
			}

			$changedIds = array_map(
				static fn (array $change): int => (int)$change['roomTaskId'],
				$plan['response']['changes']
			);

			if ($changedIds !== []) {
				// Persist due dates first (ascending), then the assignee
				// replacements — the documented assignee-writes tail.
				$tz = $this->timeZoneOf($houseId);
				sort($changedIds);
				foreach ($changedIds as $roomTaskId) {
					$change = $this->findRowById($plan['response']['changes'], $roomTaskId);
					$roomTask = $this->roomTaskMapper->lockForUpdate($roomTaskId);
					// House-local day label → its true UTC midnight instant
					// (RF-058-01): tagging the label at 00:00 UTC would land
					// one local day early for houses west of UTC.
					$roomTask->setNextDue($this->calendarService->dayStartInstant($change['proposed']['dueDate'], $tz));
					// idealDueDate stays the recurrence's own ideal; the
					// scheduled move lives within its legal window (ADR-012).
					$this->roomTaskMapper->update($roomTask);
				}
				foreach ($changedIds as $roomTaskId) {
					$change = $this->findRowById($plan['response']['changes'], $roomTaskId);
					$this->taskAssigneeMapper->deleteByRoomTask($roomTaskId);
					foreach ($change['proposed']['assignees'] as $assigneeUserId) {
						$this->taskAssigneeMapper->addAssignee($roomTaskId, $assigneeUserId, $freshActor->getNextcloudUserId());
					}
				}
			}

			// Authoritative response rows (fresh entities + canonical
			// assignees) for the client reconciliation.
			$roomTasks = [];
			if ($changedIds !== []) {
				$assigneesByRoomTask = $this->taskAssigneeMapper->findUserIdsByRoomTasks($changedIds);
				foreach ($this->roomTaskMapper->findByIdsScopedToHouse($changedIds, $houseId) as $row) {
					$data = $row->jsonSerialize($tz);
					$data['assignees'] = $assigneesByRoomTask[$row->getId()] ?? [];
					$roomTasks[] = $data;
				}
			}

			return [
				'appliedCount' => count($changedIds),
				'windowStart' => $plan['response']['startDate'],
				'windowEndExclusive' => $plan['response']['endDateExclusive'],
				'roomTasks' => $roomTasks,
			];
		});

		return $result;
	}

	/**
	 * Shared pure build: validation of the domain, deterministic proposal,
	 * capacity accounting and the content fingerprint. Never writes.
	 *
	 * @return array{response: array, material: array}
	 */
	private function buildPlan(int $houseId, HouseUser $actor, string $startDate, array $roomTaskIds, array $overrides): array {
		$house = $this->houseMapper->find($houseId);
		$tz = $this->calendarService->getTimeZone($house);

		$todayKey = $this->calendarService->dayKey(new \DateTime(), $tz);
		if ($startDate < $todayKey) {
			throw new \InvalidArgumentException($this->l10n->t('startDate must be today or later'));
		}

		$window = $this->windowDayKeys($startDate, $tz);
		$windowSet = array_fill_keys($window, true);

		// Resolve the selected rows through the house ownership chain, then
		// apply the actor visibility matrix (ADR-010). Inactive rows are a
		// 409 (stale dependency), not a 404 — the row exists but cannot be
		// scheduled anymore.
		$selectedById = [];
		foreach ($this->roomTaskMapper->findByIdsScopedToHouse($roomTaskIds, $houseId) as $row) {
			$selectedById[(int)$row->getId()] = $row;
		}
		foreach ($roomTaskIds as $id) {
			if (!isset($selectedById[$id])) {
				throw new NotFoundException($this->l10n->t('Room task not found'));
			}
			if (!$selectedById[$id]->getIsActive()) {
				throw new ConflictException($this->l10n->t('Task is not active'));
			}
		}

		$tasksById = $this->taskMapper->findByIds(array_values(array_unique(array_map(
			static fn (RoomTask $row): int => $row->getTaskId(),
			array_values($selectedById)
		))));
		$roomsById = $this->roomMapper->findByIds(array_values(array_unique(array_map(
			static fn (RoomTask $row): int => $row->getRoomId(),
			array_values($selectedById)
		))));
		$assigneesByRoomTask = $this->taskAssigneeMapper->findUserIdsByRoomTasks(array_keys($selectedById));

		foreach ($roomTaskIds as $id) {
			$row = $selectedById[$id];
			$task = $tasksById[$row->getTaskId()] ?? null;
			if ($task === null) {
				throw new NotFoundException($this->l10n->t('Room task not found'));
			}
			if (!$this->taskAccessPolicy->canView($task, $actor, $assigneesByRoomTask[$id] ?? [])) {
				throw new NotFoundException($this->l10n->t('Room task not found'));
			}
		}

		// Canonical member set (roles + preferences drive eligibility).
		$membersByUserId = [];
		foreach ($this->houseUserMapper->findByHouseId($houseId) as $member) {
			$membersByUserId[$member->getNextcloudUserId()] = $member;
		}

		// Capacity accounting base: planned occurrences of every active
		// house task inside the window (including rows outside the
		// selection) plus non-undone completion effort (decision D11).
		[$usage, $taskCounts] = $this->baselineCapacity($houseId, $house, $window);
		$planned = $usage;
		$counts = $taskCounts;

		// Deterministic order: urgency (house-local due day), higher
		// priority, then room-task id. Input and row order cannot leak.
		$orderedIds = $roomTaskIds;
		usort($orderedIds, function (int $a, int $b) use ($selectedById, $tasksById, $tz): int {
			$dueA = $this->dueDayKey($selectedById[$a], $tz) ?? '9999-12-31';
			$dueB = $this->dueDayKey($selectedById[$b], $tz) ?? '9999-12-31';
			if ($dueA !== $dueB) {
				return strcmp($dueA, $dueB);
			}
			$prioA = $tasksById[$selectedById[$a]->getTaskId()]->getPriority() ?? 2;
			$prioB = $tasksById[$selectedById[$b]->getTaskId()]->getPriority() ?? 2;
			if ($prioA !== $prioB) {
				return $prioB <=> $prioA;
			}
			return $a <=> $b;
		});

		// Occurrence/undo ledger state for the selected rows (ADR-012
		// fingerprint input): a completion or undo behind the preview's
		// back must invalidate the confirmation even when the net row
		// state looks restored.
		$undoState = $this->completedTaskMapper->findUndoStateByRoomTasks(
			array_keys($selectedById),
			$this->windowStartBound($house, $window[0]),
			$this->windowEndBound($houseId, $startDate),
		);

		$changes = [];
		$unchanged = [];
		$unplaced = [];
		$rowStates = [];

		foreach ($orderedIds as $roomTaskId) {
			$row = $selectedById[$roomTaskId];
			$task = $tasksById[$row->getTaskId()];
			$energy = (int)$task->getEnergyCost();
			$currentAssignees = self::sortedStrings($assigneesByRoomTask[$roomTaskId] ?? []);
			$currentDueKey = $this->dueDayKey($row, $tz);
			$override = $overrides[(string)$roomTaskId] ?? $overrides[$roomTaskId] ?? null;

			$rowMaterial = $this->rowMaterial($row, $task, $currentAssignees);
			$undoCounts = $undoState[$roomTaskId] ?? ['total' => 0, 'undone' => 0];
			$rowMaterial['completions'] = $undoCounts;

			// Claimed rows keep their claim state (D8) — unchanged until the
			// claim is resolved.
			if ($row->getClaimedByUser() !== null) {
				$unchanged[] = ['roomTaskId' => $roomTaskId, 'reason' => 'CLAIMED', 'warnings' => []];
				$rowStates[$roomTaskId] = ['outcome' => 'unchanged', 'material' => $rowMaterial];
				continue;
			}

			$legalDates = $this->legalDates($task, $row, $tz, $window);
			$eligibleMemberIds = $this->eligibleMemberIds($task, $membersByUserId);

			// Explicit assignee overrides: validated member + eligibility.
			$proposedAssignees = $currentAssignees;
			if (isset($override['assigneeUserIds'])) {
				foreach ($override['assigneeUserIds'] as $candidateUserId) {
					if (!isset($membersByUserId[$candidateUserId])) {
						throw new \InvalidArgumentException($this->l10n->t('User is not a member of this house: %1$s', [$candidateUserId]));
					}
					if (!in_array($candidateUserId, $eligibleMemberIds, true)) {
						throw new \InvalidArgumentException($this->l10n->t('User is not eligible for this task: %1$s', [$candidateUserId]));
					}
				}
				$proposedAssignees = self::sortedStrings($override['assigneeUserIds']);
			} elseif ($proposedAssignees !== []) {
				// Preserved sets must still be members; dropped memberships
				// shrink the preserved set.
				$proposedAssignees = self::sortedStrings(array_values(array_filter(
					$proposedAssignees,
					static fn (string $userId): bool => isset($membersByUserId[$userId])
				)));
			}

			$needsPlacement = $override !== null
				|| $proposedAssignees === []
				|| $currentDueKey === null
				|| !isset($windowSet[$currentDueKey])
				|| !in_array($currentDueKey, $legalDates, true);

			if (!$needsPlacement) {
				$warnings = $this->capacityWarnings($proposedAssignees, $currentDueKey, $energy, $planned, $counts, $membersByUserId, $window);
				$unchanged[] = ['roomTaskId' => $roomTaskId, 'reason' => 'UP_TO_DATE', 'warnings' => $warnings];
				$rowStates[$roomTaskId] = ['outcome' => 'unchanged', 'material' => $rowMaterial];
				continue;
			}

			$placement = $this->placeRow(
				$override,
				$proposedAssignees,
				$eligibleMemberIds,
				$legalDates,
				$currentDueKey,
				$window,
				$energy,
				$planned,
				$counts,
				$membersByUserId,
			);

			if ($placement === null) {
				$reason = 'NO_CAPACITY';
				if ($proposedAssignees === [] && $eligibleMemberIds === []) {
					$reason = 'NO_ELIGIBLE_MEMBER';
				} elseif (isset($override['date']) && !in_array($override['date'], $legalDates, true)) {
					$reason = 'ILLEGAL_OVERRIDE';
				} elseif ($legalDates === [] && $eligibleMemberIds === []) {
					$reason = 'NO_ELIGIBLE_MEMBER';
				} elseif ($legalDates === []) {
					$reason = 'NO_LEGAL_SLOT';
				}
				$unplaced[] = ['roomTaskId' => $roomTaskId, 'reason' => $reason, 'warnings' => []];
				$rowStates[$roomTaskId] = ['outcome' => 'unplaced', 'material' => $rowMaterial];
				continue;
			}

			// Replace (not add again) the selected row's current in-window
			// occurrence before booking the proposed one.
			if ($currentDueKey !== null && isset($windowSet[$currentDueKey])) {
				foreach ($currentAssignees as $userId) {
					$planned[$userId][$currentDueKey] = max(0, ($planned[$userId][$currentDueKey] ?? 0) - $energy);
					$counts[$userId][$currentDueKey] = max(0, ($counts[$userId][$currentDueKey] ?? 0) - 1);
				}
			}
			foreach ($placement['assignees'] as $userId) {
				$planned[$userId][$placement['date']] = ($planned[$userId][$placement['date']] ?? 0) + $energy;
				$counts[$userId][$placement['date']] = ($counts[$userId][$placement['date']] ?? 0) + 1;
			}

			$warnings = [];
			if ($placement['date'] !== $currentDueKey) {
				$warnings[] = 'DATE_MOVED';
			}
			if ($placement['assignees'] !== $currentAssignees) {
				$warnings[] = 'ASSIGNEES_CHANGED';
			}
			$changes[] = [
				'roomTaskId' => $roomTaskId,
				'taskId' => (int)$task->getId(),
				'taskName' => (string)$task->getName(),
				'roomName' => $roomsById[$row->getRoomId()]->getName() ?? null,
				'energyCost' => $energy,
				'priority' => (int)$task->getPriority(),
				'flexDays' => (int)$task->getFlexDays(),
				'legalDates' => $legalDates,
				'before' => ['assignees' => $currentAssignees, 'dueDate' => $currentDueKey],
				'proposed' => ['assignees' => $placement['assignees'], 'dueDate' => $placement['date']],
				'warnings' => $warnings,
			];
			$rowStates[$roomTaskId] = [
				'outcome' => 'change',
				'material' => $rowMaterial,
				'proposed' => $placement,
			];
		}

		$capacity = $this->capacityReport($window, $planned, $counts, $membersByUserId);
		$response = [
			'previewVersion' => $this->fingerprint($startDate, $roomTaskIds, $overrides, $tz, $rowStates, $membersByUserId, $planned, $counts),
			'startDate' => $startDate,
			'endDateExclusive' => $this->dayKeyOffset($startDate, self::WINDOW_DAYS),
			'timezone' => $tz->getName(),
			'changes' => $changes,
			'unchanged' => $unchanged,
			'unplaced' => $unplaced,
			'capacity' => $capacity,
			'warnings' => $this->globalWarnings($unchanged),
		];

		return ['response' => $response, 'material' => $rowStates];
	}

	/**
	 * Deterministic placement: prefer the nearest legal date, then the
	 * eligible member with the most remaining capacity; stable tie-breakers
	 * are the date key and the member's house-user id (plan §3.1 rule 5).
	 *
	 * @param array<string, array<string, int>> $planned member userId → dayKey → planned energy (mutated by callers, read here)
	 * @param array<string, array<string, int>> $counts member userId → dayKey → planned task count
	 * @return array{date: string, assignees: list<string>}|null
	 */
	private function placeRow(
		?array $override,
		array $proposedAssignees,
		array $eligibleMemberIds,
		array $legalDates,
		?string $currentDueKey,
		array $window,
		int $energy,
		array $planned,
		array $counts,
		array $membersByUserId,
	): ?array {
		if ($legalDates === []) {
			return null;
		}

		$candidateMemberIds = $proposedAssignees !== []
			? $proposedAssignees
			: (isset($override['assigneeUserIds'])
				? self::sortedStrings($override['assigneeUserIds'])
				: $eligibleMemberIds);
		if ($candidateMemberIds === []) {
			return null;
		}

		// Decision D3: unassigned tasks get ONE eligible person proposed —
		// the candidate set only stays whole when it comes from the
		// preserved or explicitly overridden assignment.
		$singlePick = $proposedAssignees === [] && !isset($override['assigneeUserIds']);

		if (isset($override['date'])) {
			$dates = [$override['date']];
		} else {
			$baseKey = $currentDueKey !== null && $currentDueKey >= $window[0] ? $currentDueKey : $window[0];
			$dates = $legalDates;
			usort($dates, function (string $a, string $b) use ($baseKey): int {
				$diffA = abs($this->dayDiff($baseKey, $a));
				$diffB = abs($this->dayDiff($baseKey, $b));
				return $diffA <=> $diffB ?: strcmp($a, $b);
			});
		}

		foreach ($dates as $date) {
			$orderedMembers = $candidateMemberIds;
			usort($orderedMembers, function (string $a, string $b) use ($date, $planned, $membersByUserId): int {
				$remainingA = $this->remainingFor($a, $date, $planned, $membersByUserId);
				$remainingB = $this->remainingFor($b, $date, $planned, $membersByUserId);
				return $remainingB <=> $remainingA
					?: (int)($membersByUserId[$a]?->getId() ?? 0) <=> (int)($membersByUserId[$b]?->getId() ?? 0)
					?: strcmp($a, $b);
			});

			foreach ($orderedMembers as $userId) {
				$member = $membersByUserId[$userId] ?? null;
				if ($member === null || !$this->isMemberAvailableOn($member, $date)) {
					continue;
				}
				if ($this->remainingFor($userId, $date, $planned, $membersByUserId) < $energy) {
					continue;
				}
				if (!$this->childLimitAllows($member, $date, $counts)) {
					continue;
				}
				return ['date' => $date, 'assignees' => $singlePick ? [$userId] : $candidateMemberIds];
			}
		}

		return null;
	}

	/**
	 * Baseline per-member/day planned effort and task counts: projected
	 * occurrences of every active house task in the window (full effort per
	 * assignee, D6; unassigned work tracked at house level) plus non-undone
	 * completion effort.
	 *
	 * @return array{0: array<string, array<string, int>>, 1: array<string, array<string, int>>}
	 */
	private function baselineCapacity(int $houseId, House $house, array $window): array {
		$tz = $this->calendarService->getTimeZone($house);
		$windowSet = array_fill_keys($window, true);
		$fromBound = (new \DateTime($window[0] . ' 00:00:00', $tz))->setTimezone(new \DateTimeZone('UTC'));
		$toDay = $this->dayKeyOffset($window[0], self::WINDOW_DAYS - 1);
		$toBound = (new \DateTime($toDay . ' 23:59:59', $tz))->setTimezone(new \DateTimeZone('UTC'));

		$planned = [];
		$counts = [];

		$baseRows = $this->roomTaskMapper->findActiveDueUpToByHouse($houseId, $toBound);
		$baseIds = array_map(static fn (RoomTask $row): int => (int)$row->getId(), $baseRows);
		$baseTasks = $this->taskMapper->findByIds(array_values(array_unique(array_map(
			static fn (RoomTask $row): int => $row->getTaskId(),
			$baseRows
		))));
		$baseAssignees = $this->taskAssigneeMapper->findUserIdsByRoomTasks($baseIds);

		foreach ($baseRows as $row) {
			$task = $baseTasks[$row->getTaskId()] ?? null;
			if ($task === null) {
				continue;
			}
			$energy = (int)$task->getEnergyCost();
			$occurrences = $row->projectOccurrences(
				$fromBound,
				$toBound,
				$task->getFrequencyType(),
				$task->getFrequencyValue(),
				$task->getRecurrenceMode(),
				$task->getSchedulingMode(),
				$task->getFixedScheduleDays(),
				$task->getActiveUntil(),
				$tz,
			);
			foreach ($occurrences as $occurrence) {
				$dayKey = $this->calendarService->dayKey($occurrence, $tz);
				if (!isset($windowSet[$dayKey])) {
					continue;
				}
				$assignees = self::sortedStrings($baseAssignees[$row->getId()] ?? []);
				if ($assignees === []) {
					continue;
				}
				foreach ($assignees as $userId) {
					$planned[$userId][$dayKey] = ($planned[$userId][$dayKey] ?? 0) + $energy;
					$counts[$userId][$dayKey] = ($counts[$userId][$dayKey] ?? 0) + 1;
				}
			}
		}

		$membersByRowId = [];
		foreach ($this->houseUserMapper->findByHouseId($houseId) as $member) {
			$membersByRowId[(int)$member->getId()] = $member;
		}

		foreach ($this->completedTaskMapper->findEnergyUsageBetweenByHouse($houseId, $fromBound, $toBound) as $usageRow) {
			$memberRow = $membersByRowId[$usageRow['houseUserId']] ?? null;
			if ($memberRow === null) {
				continue;
			}
			$dayKey = $this->calendarService->dayKey($usageRow['completedAt'], $tz);
			if (!isset($windowSet[$dayKey])) {
				continue;
			}
			$planned[$memberRow->getNextcloudUserId()][$dayKey]
				= ($planned[$memberRow->getNextcloudUserId()][$dayKey] ?? 0) + $usageRow['energy'];
		}

		return [$planned, $counts];
	}

	// ------------------------------------------------------------------
	// Eligibility, availability and capacity primitives
	// ------------------------------------------------------------------

	/**
	 * Members eligible to receive the task (visibility relation, plan
	 * §3.1 rule 4): admin-only definitions for owners only; public and
	 * private definitions for any member (an explicit assignee gains
	 * visibility under the existing rules).
	 *
	 * @param array<string, HouseUser> $membersByUserId
	 * @return list<string> Eligible Nextcloud user IDs, sorted
	 */
	private function eligibleMemberIds(Task $task, array $membersByUserId): array {
		$eligible = [];
		foreach ($membersByUserId as $userId => $member) {
			if ($task->getVisibilityType() === Task::VISIBILITY_ADMIN_ONLY && !$member->isOwner()) {
				continue;
			}
			$eligible[] = $userId;
		}
		return self::sortedStrings($eligible);
	}

	private function isMemberAvailableOn(HouseUser $member, string $dayKey): bool {
		$preferences = $member->getSchedulingPreferences() ?? [];
		$availability = $preferences['weekdayAvailability'] ?? [];
		return $availability[strtolower($this->dayName($dayKey))] ?? true;
	}

	private function energyBudgetFor(HouseUser $member, string $dayKey): int {
		$preferences = $member->getSchedulingPreferences() ?? [];
		$budget = $preferences['energyBudget'] ?? [];
		$day = strtolower($this->dayName($dayKey));
		if (isset($budget[$day])) {
			return (int)$budget[$day];
		}
		return in_array($day, ['saturday', 'sunday'], true) ? 15 : 10;
	}

	/**
	 * Whether placing another task for a member with a finite child limit
	 * stays within the bound (decision D6).
	 *
	 * @param array<string, array<string, int>> $counts
	 */
	private function childLimitAllows(HouseUser $member, string $dayKey, array $counts): bool {
		$preferences = $member->getSchedulingPreferences() ?? [];
		$limit = $preferences['maxDailyTasks'] ?? null;
		if (!is_int($limit)) {
			return true;
		}
		return ($counts[$member->getNextcloudUserId()][$dayKey] ?? 0) < $limit;
	}

	/**
	 * @param array<string, int> $planned
	 * @param array<string, HouseUser> $membersByUserId
	 */
	private function remainingFor(string $userId, string $dayKey, array $planned, array $membersByUserId): int {
		$member = $membersByUserId[$userId] ?? null;
		if ($member === null) {
			return 0;
		}
		return $this->energyBudgetFor($member, $dayKey) - ($planned[$userId][$dayKey] ?? 0);
	}

	/**
	 * @param array<string> $assigneeIds
	 * @param array<string, array<string, int>> $planned
	 * @param array<string, array<string, int>> $counts
	 * @param array<string, HouseUser> $membersByUserId
	 * @return list<string>
	 */
	private function capacityWarnings(array $assigneeIds, string $dayKey, int $energy, array $planned, array $counts, array $membersByUserId, array $window): array {
		if (!in_array($dayKey, $window, true)) {
			return [];
		}
		foreach ($assigneeIds as $userId) {
			$member = $membersByUserId[$userId] ?? null;
			if ($member === null) {
				continue;
			}
			if (($planned[$userId][$dayKey] ?? 0) > $this->energyBudgetFor($member, $dayKey)) {
				return ['EXISTING_OVERLOAD'];
			}
		}
		return [];
	}

	/**
	 * Per-day capacity report for the whole window (after proposals).
	 *
	 * @param array<string, int> $planned
	 * @param array<string, array<string, int>> $counts
	 * @param array<string, HouseUser> $membersByUserId
	 * @return list<array{date: string, members: array<string, array{houseUserId: int, available: bool, budget: int, planned: int, remaining: int, taskCount: int}>}>
	 */
	private function capacityReport(array $window, array $planned, array $counts, array $membersByUserId): array {
		$report = [];
		foreach ($window as $dayKey) {
			$members = [];
			foreach ($membersByUserId as $userId => $member) {
				$budget = $this->energyBudgetFor($member, $dayKey);
				$memberPlanned = $planned[$userId][$dayKey] ?? 0;
				$members[$userId] = [
					'houseUserId' => (int)$member->getId(),
					'available' => $this->isMemberAvailableOn($member, $dayKey),
					'budget' => $budget,
					'planned' => $memberPlanned,
					'remaining' => max(0, $budget - $memberPlanned),
					'taskCount' => $counts[$userId][$dayKey] ?? 0,
				];
			}
			$report[] = ['date' => $dayKey, 'members' => $members];
		}
		return $report;
	}

	/**
	 * @return list<string>
	 */
	private function globalWarnings(array $unchanged): array {
		foreach ($unchanged as $row) {
			if (in_array('EXISTING_OVERLOAD', $row['warnings'] ?? [], true)) {
				return ['EXISTING_OVERLOAD'];
			}
		}
		return [];
	}

	// ------------------------------------------------------------------
	// Legal-slot, date and request-shape helpers
	// ------------------------------------------------------------------

	/**
	 * The house-local day keys inside the window where the task's next
	 * occurrence may legally live (plan §3.1 rule 3): static weekly
	 * definitions may only move onto their configured weekdays; every other
	 * recurrence is bounded by its flex tolerance around the stored ideal
	 * (or next) due day. Active bounds and the window clamp the result.
	 *
	 * @param list<string> $window
	 * @return list<string>
	 */
	private function legalDates(Task $task, RoomTask $row, \DateTimeZone $tz, array $window): array {
		$anchor = $row->getIdealDueDate() ?? $row->getNextDue();
		if ($anchor === null) {
			return [];
		}
		$anchorKey = $this->calendarService->dayKey($anchor, $tz);
		$flex = max(0, (int)$task->getFlexDays());

		$staticWeekdays = [];
		if ($task->getSchedulingMode() === Task::SCHEDULING_STATIC
			&& $task->getFrequencyType() === 'weekly'
			&& is_string($task->getFixedScheduleDays())
			&& $task->getFixedScheduleDays() !== '') {
			$decoded = json_decode($task->getFixedScheduleDays(), true);
			if (is_array($decoded)) {
				$staticWeekdays = array_values(array_filter(
					array_map(static fn ($day) => strtolower(trim((string)$day)), $decoded),
					static fn (string $day): bool => in_array($day, ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'], true)
				));
			}
		}

		$activeFromKey = $task->getActiveFrom() !== null ? $this->calendarService->dayKey($task->getActiveFrom(), $tz) : null;
		$activeUntilKey = $task->getActiveUntil() !== null ? $this->calendarService->dayKey($task->getActiveUntil(), $tz) : null;

		$legal = [];
		foreach ($window as $dayKey) {
			if ($staticWeekdays !== []) {
				if (!in_array(strtolower($this->dayName($dayKey)), $staticWeekdays, true)) {
					continue;
				}
			} elseif (abs($this->dayDiff($anchorKey, $dayKey)) > $flex) {
				continue;
			}
			if ($activeFromKey !== null && $dayKey < $activeFromKey) {
				continue;
			}
			if ($activeUntilKey !== null && $dayKey > $activeUntilKey) {
				continue;
			}
			$legal[] = $dayKey;
		}
		return $legal;
	}

	/**
	 * Closed request-shape validation (plan §3.1 rule 1): strict real dates,
	 * non-empty unique positive ids, bounded batch, closed override keys.
	 */
	private function assertValidRequest(string $startDate, array $roomTaskIds, array $overrides): void {
		if ($this->dayKeyOffset($startDate, 0) === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
			throw new \InvalidArgumentException($this->l10n->t('startDate must be a valid Y-m-d date'));
		}

		if ($roomTaskIds === []) {
			throw new \InvalidArgumentException($this->l10n->t('roomTaskIds must not be empty'));
		}
		if (count($roomTaskIds) > self::MAX_BATCH_SIZE) {
			throw new \InvalidArgumentException($this->l10n->t('A plan may contain at most %1$s tasks', [(string)self::MAX_BATCH_SIZE]));
		}
		$seen = [];
		foreach ($roomTaskIds as $id) {
			if (!is_int($id) || $id < 1) {
				throw new \InvalidArgumentException($this->l10n->t('roomTaskIds must contain positive integers'));
			}
			if (isset($seen[$id])) {
				throw new \InvalidArgumentException($this->l10n->t('roomTaskIds must not contain duplicates'));
			}
			$seen[$id] = true;
		}

		foreach ($overrides as $overrideId => $override) {
			if (!isset($seen[(int)$overrideId])) {
				throw new \InvalidArgumentException($this->l10n->t('Overrides must reference a selected room task'));
			}
			if (!is_array($override)) {
				throw new \InvalidArgumentException($this->l10n->t('Each override must be an object'));
			}
			$keys = array_keys($override);
			foreach ($keys as $key) {
				if (!in_array($key, ['assigneeUserIds', 'date'], true)) {
					throw new \InvalidArgumentException($this->l10n->t('Unknown override field: %1$s', [(string)$key]));
				}
			}
			if ($override === []) {
				throw new \InvalidArgumentException($this->l10n->t('Each override must set assigneeUserIds or date'));
			}
			if (array_key_exists('assigneeUserIds', $override)) {
				$ids = $override['assigneeUserIds'];
				if (!is_array($ids) || $ids === []) {
					throw new \InvalidArgumentException($this->l10n->t('assigneeUserIds must be a non-empty array of user IDs'));
				}
				if (count($ids) !== count(array_unique($ids))) {
					throw new \InvalidArgumentException($this->l10n->t('assigneeUserIds must not contain duplicates'));
				}
				foreach ($ids as $userId) {
					if (!is_string($userId) || trim($userId) === '') {
						throw new \InvalidArgumentException($this->l10n->t('assigneeUserIds must be a non-empty array of user IDs'));
					}
				}
			}
			if (array_key_exists('date', $override)) {
				$date = $override['date'];
				if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $this->dayKeyOffset($date, 0) === null) {
					throw new \InvalidArgumentException($this->l10n->t('Override date must be a valid Y-m-d date'));
				}
			}
		}
	}

	private function assertOwnerActor(int $houseId, HouseUser $actor): void {
		if ((int)$actor->getHouseId() !== $houseId) {
			throw new ForbiddenException($this->l10n->t('Not a member of this house'));
		}
		if (!$actor->isOwner()) {
			throw new ForbiddenException($this->l10n->t('Owner role required'));
		}
	}

	/**
	 * @return list<string> Seven house-local day keys starting at $startDate
	 */
	private function windowDayKeys(string $startDate, \DateTimeZone $tz): array {
		$keys = [];
		$cursor = new \DateTime($startDate . ' 00:00:00', $tz);
		for ($i = 0; $i < self::WINDOW_DAYS; $i++) {
			$keys[] = $this->calendarService->dayKey($cursor, $tz);
			$cursor->modify('+1 day');
		}
		return $keys;
	}

	private function windowEndBound(int $houseId, string $startDate): \DateTime {
		$house = $this->houseMapper->find($houseId);
		$tz = $this->calendarService->getTimeZone($house);
		$lastDay = $this->dayKeyOffset($startDate, self::WINDOW_DAYS - 1);
		return (new \DateTime($lastDay . ' 23:59:59', $tz))->setTimezone(new \DateTimeZone('UTC'));
	}

	private function windowStartBound(House $house, string $startDate): \DateTime {
		$tz = $this->calendarService->getTimeZone($house);
		return (new \DateTime($startDate . ' 00:00:00', $tz))->setTimezone(new \DateTimeZone('UTC'));
	}

	private function timeZoneOf(int $houseId): \DateTimeZone {
		return $this->calendarService->getTimeZone($this->houseMapper->find($houseId));
	}

	private function dueDayKey(RoomTask $row, \DateTimeZone $tz): ?string {
		return $row->getNextDue() !== null ? $this->calendarService->dayKey($row->getNextDue(), $tz) : null;
	}

	private function dayName(string $dayKey): string {
		return \DateTime::createFromFormat('Y-m-d', $dayKey)->format('l');
	}

	/**
	 * Offset a Y-m-d key by $days (null when the key is not a real date).
	 */
	private function dayKeyOffset(string $dayKey, int $days): ?string {
		$date = \DateTime::createFromFormat('Y-m-d', $dayKey);
		if ($date === false || $date->format('Y-m-d') !== $dayKey) {
			return null;
		}
		if ($days === 0) {
			return $dayKey;
		}
		return $date->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
	}

	/**
	 * Signed day difference $to - $from in calendar days.
	 */
	private function dayDiff(string $from, string $to): int {
		$fromDate = new \DateTime($from . ' 00:00:00');
		$toDate = new \DateTime($to . ' 00:00:00');
		return (int)$fromDate->diff($toDate)->format('%r%a');
	}

	/**
	 * @param array<int|string, mixed> $rows
	 */
	private function findRowById(array $rows, int $roomTaskId): array {
		foreach ($rows as $row) {
			if ((int)$row['roomTaskId'] === $roomTaskId) {
				return $row;
			}
		}
		throw new \RuntimeException('Plan row missing for room task ' . $roomTaskId);
	}

	/**
	 * @return list<string>
	 */
	private static function sortedStrings(array $values): array {
		$clean = array_values(array_unique(array_map(static fn ($value): string => (string)$value, $values)));
		sort($clean, SORT_STRING);
		return $clean;
	}

	// ------------------------------------------------------------------
	// Content fingerprint (decision D2)
	// ------------------------------------------------------------------

	/**
	 * Opaque content fingerprint over the normalized request, the selected
	 * rows' authoritative state, task scheduling inputs, the member roles
	 * and preferences, the house timezone, the capacity-source rows and the
	 * proposal result. Canonical sorting is part of the contract.
	 *
	 * @param array<int|string, mixed> $rowStates
	 * @param array<string, HouseUser> $membersByUserId
	 * @param array<string, array<string, int>> $planned
	 * @param array<string, array<string, int>> $counts
	 */
	private function fingerprint(
		string $startDate,
		array $roomTaskIds,
		array $overrides,
		\DateTimeZone $tz,
		array $rowStates,
		array $membersByUserId,
		array $planned,
		array $counts,
	): string {
		$members = [];
		foreach ($membersByUserId as $userId => $member) {
			$members[$userId] = [
				'houseUserId' => (int)$member->getId(),
				'role' => (string)$member->getRole(),
				'preferences' => $member->getSchedulingPreferences() ?? [],
			];
		}

		$material = [
			'version' => 1,
			'request' => [
				'startDate' => $startDate,
				'roomTaskIds' => self::sortedInts($roomTaskIds),
				'overrides' => $overrides,
			],
			'timezone' => $tz->getName(),
			'rows' => $rowStates,
			'members' => $members,
			'planned' => $planned,
			'counts' => $counts,
		];
		self::canonicalize($material);

		return sha1((string)json_encode($material, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
	}

	/**
	 * @param array<int|string, mixed> $row
	 * @return array<string, mixed>
	 */
	private function rowMaterial(RoomTask $row, Task $task, array $assignees): array {
		return [
			'roomTask' => [
				'id' => (int)$row->getId(),
				'taskId' => (int)$row->getTaskId(),
				'roomId' => (int)$row->getRoomId(),
				'nextDue' => $row->getNextDue()?->getTimestamp(),
				'idealDueDate' => $row->getIdealDueDate()?->getTimestamp(),
				'isActive' => $row->getIsActive(),
				'claimedByUser' => $row->getClaimedByUser(),
				'wasClaimed' => $row->getWasClaimed(),
			],
			'task' => [
				'energyCost' => (int)$task->getEnergyCost(),
				'priority' => (int)$task->getPriority(),
				'recurrenceMode' => (string)$task->getRecurrenceMode(),
				'schedulingMode' => (string)$task->getSchedulingMode(),
				'frequencyType' => (string)$task->getFrequencyType(),
				'frequencyValue' => (int)$task->getFrequencyValue(),
				'fixedScheduleDays' => $task->getFixedScheduleDays(),
				'fixedScheduleTime' => $task->getFixedScheduleTime()?->getTimestamp(),
				'activeFrom' => $task->getActiveFrom()?->getTimestamp(),
				'activeUntil' => $task->getActiveUntil()?->getTimestamp(),
				'flexDays' => (int)$task->getFlexDays(),
				'visibilityType' => (string)$task->getVisibilityType(),
				'ownerUserId' => $task->getOwnerUserId(),
			],
			'assignees' => $assignees,
		];
	}

	/**
	 * @param array<int> $values
	 * @return list<int>
	 */
	private static function sortedInts(array $values): array {
		$clean = array_map('intval', $values);
		sort($clean);
		return $clean;
	}

	/**
	 * Recursively sort string-keyed arrays by key and drop integer-key noise
	 * so identical content always serializes identically.
	 *
	 * @param array<int|string, mixed> $array
	 */
	private static function canonicalize(array &$array): void {
		foreach ($array as &$value) {
			if (is_array($value)) {
				self::canonicalize($value);
			}
		}
		unset($value);

		$keys = array_keys($array);
		$hasStringKeys = array_reduce($keys, static fn (bool $carry, $key): bool => $carry || is_string($key), false);
		if ($hasStringKeys) {
			ksort($array, SORT_STRING);
		}
	}
}
