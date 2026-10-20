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
 * Owner of the scheduling preview/apply aggregate (CS-PLAN-065, ADR-012;
 * WP2 cutover CS-PLAN-073 §3.2/§3.5).
 *
 * Preview builds one deterministic plan over REAL active room tasks for a
 * seven-day house-local window: legal recurrence/flex slots first, then
 * member eligibility, then whole-day capacity (exact planned shares and
 * frozen completion effort, minor units) and child limits as hard
 * placement constraints. It performs zero writes and returns an opaque
 * content fingerprint (`previewVersion`) over every authoritative
 * dependency — occurrence/rule/member/pause inputs, saved zones,
 * availability/limits, completed-work inputs, the proposal and the
 * evaluation local day (CR-SVC-64: a valid preview crossing local
 * midnight is stale and yields a write-free 409 on apply).
 *
 * Apply runs as ONE `CommandService` command (`scheduling.apply`): the
 * house lock enters first, the identical build re-runs from the locked
 * rows, the receipt replays idempotently per command UUID, and the
 * confirmed preview version must still match before any relative-date
 * rejection — domain facts, versions, evidence and receipt commit or roll
 * back together:
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

	/** Stable action identifier of the apply command (CommandService). */
	public const APPLY_ACTION = 'scheduling.apply';

	/**
	 * Injected evaluation clock (§4 "use injected clocks"): null = real
	 * time. The CR-SVC-64 midnight proof sets a fixed pre-midnight instant
	 * for the preview and a post-midnight one for the apply — production
	 * callers never touch this seam.
	 */
	private ?\DateTime $clockOverride = null;

	private RoomTaskMapper $roomTaskMapper;
	private TaskMapper $taskMapper;
	private RoomMapper $roomMapper;
	private HouseMapper $houseMapper;
	private HouseUserMapper $houseUserMapper;
	private TaskAssigneeMapper $taskAssigneeMapper;
	private CompletedTaskMapper $completedTaskMapper;
	private OccurrenceService $occurrenceService;
	private CommandService $commands;
	private DailyCapacityService $dailyCapacity;
	private PauseProjectionService $pauseProjection;
	private TransactionExecutor $transactions;
	private CalendarService $calendarService;
	private TaskAccessPolicy $taskAccessPolicy;
	private RoomTaskReadService $readService;
	private IL10N $l10n;

	public function __construct(
		RoomTaskMapper $roomTaskMapper,
		TaskMapper $taskMapper,
		RoomMapper $roomMapper,
		HouseMapper $houseMapper,
		HouseUserMapper $houseUserMapper,
		TaskAssigneeMapper $taskAssigneeMapper,
		CompletedTaskMapper $completedTaskMapper,
		OccurrenceService $occurrenceService,
		CommandService $commands,
		DailyCapacityService $dailyCapacity,
		PauseProjectionService $pauseProjection,
		TransactionExecutor $transactions,
		CalendarService $calendarService,
		TaskAccessPolicy $taskAccessPolicy,
		RoomTaskReadService $readService,
		IL10N $l10n,
	) {
		$this->roomTaskMapper = $roomTaskMapper;
		$this->taskMapper = $taskMapper;
		$this->roomMapper = $roomMapper;
		$this->houseMapper = $houseMapper;
		$this->houseUserMapper = $houseUserMapper;
		$this->taskAssigneeMapper = $taskAssigneeMapper;
		$this->completedTaskMapper = $completedTaskMapper;
		$this->occurrenceService = $occurrenceService;
		$this->commands = $commands;
		$this->dailyCapacity = $dailyCapacity;
		$this->pauseProjection = $pauseProjection;
		$this->transactions = $transactions;
		$this->calendarService = $calendarService;
		$this->taskAccessPolicy = $taskAccessPolicy;
		$this->readService = $readService;
		$this->l10n = $l10n;
	}

	/**
	 * Set/clear the injected evaluation clock (test seam; see
	 * `$clockOverride`).
	 */
	public function setClock(?\DateTime $now): void {
		$this->clockOverride = $now;
	}

	private function now(): \DateTime {
		return $this->clockOverride !== null ? clone $this->clockOverride : new \DateTime();
	}

	/**
	 * The recorded owner consent attestation of one planner apply row
	 * (RF-073-WP2-06, plan §3.3.2): the attesting owner plus the affected
	 * proposed participants.
	 *
	 * @param list<string> $proposedAssignees
	 * @return array{attestedBy: int, subjects: list<string>}
	 */
	private function applyConsent(HouseUser $actor, array $proposedAssignees): array {
		return ['attestedBy' => (int)$actor->getId(), 'subjects' => array_values($proposedAssignees)];
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
	 * Apply the confirmed plan as one all-or-nothing command.
	 *
	 * The exact preview input plus its `previewVersion` and the client's
	 * `commandId` run as one `CommandService` command (plan §3.5):
	 * authorize BEFORE receipt replay, return the original identities on
	 * an identical replay, conflict on a changed payload. Under the
	 * receipt transaction the identical build re-runs from freshly locked
	 * rows; any drift rejects the batch with a ConflictException (409)
	 * and zero writes. A preview that crossed local midnight is stale by
	 * its fingerprint and yields a write-free 409 BEFORE any
	 * relative-date validation (CR-SVC-64); a newly malformed request
	 * stays a 400. A confirmed no-change plan performs zero writes.
	 *
	 * @return array{appliedCount: int, windowStart: string, windowEndExclusive: string, roomTasks: list}
	 */
	public function apply(
		int $houseId,
		HouseUser $actor,
		string $startDate,
		array $roomTaskIds,
		array $overrides,
		string $previewVersion,
		string $commandId,
		bool $consent = false,
	): array {
		$this->assertOwnerActor($houseId, $actor);
		$this->assertValidRequest($startDate, $roomTaskIds, $overrides);
		if (!is_string($previewVersion) || $previewVersion === '') {
			throw new \InvalidArgumentException($this->l10n->t('previewVersion is required'));
		}
		// Command UUID validation runs AFTER the owner gate: authorization
		// failures must not leak as 400s for unauthorized actors.
		if (!is_string($commandId) || trim($commandId) === '' || strlen($commandId) > 36) {
			throw new \InvalidArgumentException($this->l10n->t('A valid commandId (UUID) is required'));
		}
		// RF-073-WP2-06 (plan §3.3.2/§3.5): the owner records the agreement
		// with the affected people as an EXPLICIT attestation on the apply
		// command — it rides the receipt identity and the evidence rows.
		if (!$consent) {
			throw new \InvalidArgumentException($this->l10n->t('An explicit consent attestation is required'));
		}
		if ((int)$actor->getId() <= 0) {
			throw new ForbiddenException($this->l10n->t('Not a member of this house'));
		}

		$payload = [
			'startDate' => $startDate,
			'roomTaskIds' => $roomTaskIds,
			'overrides' => $overrides,
			'previewVersion' => $previewVersion,
			'consent' => true,
		];

		['result' => $refs] = $this->commands->runCommand(
			$houseId,
			(int)$actor->getId(),
			$commandId,
			self::APPLY_ACTION,
			$payload,
			function () use ($houseId, &$actor, $startDate, $roomTaskIds, $overrides, $previewVersion): array {
				return $this->applyAggregate($houseId, $actor, $startDate, $roomTaskIds, $overrides, $previewVersion);
			},
			function (int $lockedHouseId, int $actorId) use (&$actor, $roomTaskIds): void {
				$actor = $this->authorizeApplyUnderLock($lockedHouseId, $actorId, $actor->getNextcloudUserId(), $roomTaskIds);
			}
		);

		// Both the fresh and the replay path answer with the ORIGINAL
		// stored identities plus the CURRENT safe state — the receipt
		// never carries full response bodies (CommandService size bound),
		// so the changed rows are re-read authorized after the commit.
		$changedIds = array_map('intval', $refs['roomTaskIds'] ?? []);
		return [
			'appliedCount' => (int)($refs['appliedCount'] ?? 0),
			'windowStart' => (string)($refs['windowStart'] ?? $startDate),
			'windowEndExclusive' => (string)($refs['windowEndExclusive'] ?? $startDate),
			'roomTasks' => $this->authoritativeRoomTasks($houseId, $changedIds),
		];
	}

	/** Authorization only: receipt replay must not recheck planning/version rules. */
	private function authorizeApplyUnderLock(int $houseId, int $actorId, string $actorUserId, array $roomTaskIds): HouseUser {
		$actor = $this->houseUserMapper->findByHouseAndUser($houseId, $actorUserId);
		if ($actor === null || (int)$actor->getId() !== $actorId) {
			throw new ForbiddenException($this->l10n->t('Not a member of this house'));
		}
		$rows = $this->roomTaskMapper->findByIdsScopedToHouse($roomTaskIds, $houseId);
		$tasks = $this->taskMapper->findByIds(array_values(array_unique(array_map(static fn (RoomTask $row): int => $row->getTaskId(), $rows))));
		$assignees = $this->taskAssigneeMapper->findUserIdsByRoomTasks($roomTaskIds);
		$visibleIds = [];
		foreach ($rows as $row) {
			$task = $tasks[$row->getTaskId()] ?? null;
			if ($task !== null && $this->taskAccessPolicy->canView($task, $actor, $assignees[$row->getId()] ?? [])) {
				$visibleIds[] = (int)$row->getId();
			}
		}
		if (array_diff($roomTaskIds, $visibleIds) !== []) {
			throw new NotFoundException($this->l10n->t('Room task not found'));
		}
		$this->assertOwnerActor($houseId, $actor);
		return $actor;
	}

	/**
	 * The apply aggregate inside the receipt transaction (the house lock
	 * is ALREADY held by CommandService — house-first is preserved).
	 *
	 * @return array<string, mixed> The stored result identity (refs)
	 */
	private function applyAggregate(
		int $houseId,
		HouseUser $actor,
		string $startDate,
		array $roomTaskIds,
		array $overrides,
		string $previewVersion,
	): array {
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

		// Recompute the identical plan from the locked state. The
		// fingerprint FIRST: a preview that crossed local midnight (or any
		// drifted dependency) is a write-free 409 BEFORE the
		// relative-date rejection — CR-SVC-64 (plan §3.5). A newly
		// malformed request never reaches here (shape validation stays
		// 400 in apply()).
		$plan = $this->buildPlan($houseId, $freshActor, $startDate, $roomTaskIds, $overrides, false);
		if (!hash_equals($previewVersion, $plan['response']['previewVersion'])) {
			throw new ConflictException($this->l10n->t('The schedule preview is no longer current. Generate a new preview.'));
		}

		$changedIds = array_map(
			static fn (array $change): int => (int)$change['roomTaskId'],
			$plan['response']['changes']
		);
		// Keyed once (CR-PERF-01): both write phases look the row up by id.
		$changesById = [];
		foreach ($plan['response']['changes'] as $change) {
			$changesById[(int)$change['roomTaskId']] ??= $change;
		}

		if ($changedIds !== []) {
			// CS-PLAN-072 WP1 (Q-072-01): the planner persists through
			// the common agreement writers — due dates amend the target
			// occurrence (identity retained, deadline/C2h checks
			// enforced, agreement evidence recorded), assignees become
			// the revision's default participant snapshot plus amended
			// occurrence members. No path writes planning state outside
			// an agreement anymore. RF-073-WP2-06: the owner's explicit
			// consent attestation rides every planner agreement row.
			$now = $this->now();
			$house = $this->houseMapper->find($houseId);
			sort($changedIds);
			foreach ($changedIds as $roomTaskId) {
				$change = $changesById[$roomTaskId];
				$roomTask = $this->roomTaskMapper->lockForUpdate($roomTaskId);
				if ($change['before']['dueDate'] !== $change['proposed']['dueDate']) {
					$this->occurrenceService->amendOccurrenceDateUnderLock(
						$roomTask,
						(int)$freshActor->getId(),
						null,
						$change['proposed']['dueDate'],
						$now,
						$this->applyConsent($freshActor, $change['proposed']['assignees'])
					);
				}
			}
			foreach ($changedIds as $roomTaskId) {
				$change = $changesById[$roomTaskId];
				$roomTask = $this->roomTaskMapper->lockForUpdate($roomTaskId);
				$this->taskAssigneeMapper->deleteByRoomTask($roomTaskId);
				foreach ($change['proposed']['assignees'] as $assigneeUserId) {
					$this->taskAssigneeMapper->addAssignee($roomTaskId, $assigneeUserId, $freshActor->getNextcloudUserId());
				}
				try {
					$task = $this->taskMapper->find($roomTask->getTaskId());
					$this->occurrenceService->amendParticipantsUnderLock(
						$houseId,
						$roomTask,
						$task,
						$house,
						(int)$freshActor->getId(),
						$change['proposed']['assignees'],
						$now,
						$this->applyConsent($freshActor, $change['proposed']['assignees'])
					);
				} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
					// Orphaned definition — the join-table write above
					// remains the authority for this tombstone-bound row.
				}
			}
		}

		// Compact stored identity — the receipt never carries full
		// response bodies (CommandService size bound); apply() re-reads
		// the changed rows authorized after the commit for BOTH the fresh
		// and the replay answer.
		return [
			'appliedCount' => count($changedIds),
			'windowStart' => $plan['response']['startDate'],
			'windowEndExclusive' => $plan['response']['endDateExclusive'],
			'roomTaskIds' => array_map('intval', $changedIds),
		];
	}

	/**
	 * Freshly read, authorized room-task rows for the response (or replay
	 * state). Empty selection/replay of a no-change plan yields []. The
	 * `status` follows the occurrence selection like every list row
	 * (CR-084-03).
	 *
	 * @param int[] $roomTaskIds
	 * @return list<array<string, mixed>>
	 */
	private function authoritativeRoomTasks(int $houseId, array $roomTaskIds): array {
		if ($roomTaskIds === []) {
			return [];
		}
		$tz = $this->calendarService->getTimeZone($this->houseMapper->find($houseId));
		$roomTasks = [];
		$assigneesByRoomTask = $this->taskAssigneeMapper->findUserIdsByRoomTasks($roomTaskIds);
		$rows = $this->roomTaskMapper->findByIdsScopedToHouse($roomTaskIds, $houseId);
		$selections = $this->readService->occurrenceSelectionsForHouse($houseId, $rows);
		foreach ($rows as $row) {
			$data = $this->readService->roomTaskPayload($row, $tz, $selections[$row->getId()]['classification'] ?? null);
			$data['assignees'] = $assigneesByRoomTask[$row->getId()] ?? [];
			$roomTasks[] = $data;
		}
		return $roomTasks;
	}

	/**
	 * Shared pure build: validation of the domain, deterministic proposal,
	 * capacity accounting and the content fingerprint. Never writes.
	 *
	 * `$enforceToday = false` (apply path) defers the relative-date
	 * rejection: the fingerprint comparison happens FIRST (CR-SVC-64), so
	 * a valid preview that crossed local midnight is a write-free 409.
	 *
	 * @return array{response: array, material: array}
	 */
	private function buildPlan(int $houseId, HouseUser $actor, string $startDate, array $roomTaskIds, array $overrides, bool $enforceToday = true): array {
		$house = $this->houseMapper->find($houseId);
		$tz = $this->calendarService->getTimeZone($house);

		$todayKey = $this->calendarService->dayKey($this->now(), $tz);
		if ($enforceToday && $startDate < $todayKey) {
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

		// Capacity accounting base (WP2 cutover, plan §3.2): whole-day
		// exact planned SHARES (agreed occurrence participants — never the
		// definition assignees) of every active house task inside the
		// window, occurrence-identity deduplicated against the completion
		// ledger, in integer hundredths. The whole-day evaluation report
		// supplies the completed effort (frozen occurrence energies) and
		// the per-member limits the placement constraints consume.
		// RF-073-WP2-16: BOTH reads share ONE batched collection pass.
		[$baseline, $capacityByDay] = $this->dailyCapacity->withSharedCollection(
			fn (): array => [
				$this->dailyCapacity->plannedBaseline($houseId, $window),
				$this->dailyCapacity->evaluateDays($houseId, $window),
			]
		);
		[$planned, $counts, $plannedByTask] = $baseline;

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
			$energyMinor = $energy * DailyCapacityService::ENERGY_MINOR;
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
				$warnings = $this->capacityWarnings($proposedAssignees, $currentDueKey, $capacityByDay, $window);
				$unchanged[] = ['roomTaskId' => $roomTaskId, 'reason' => 'UP_TO_DATE', 'warnings' => $warnings];
				$rowStates[$roomTaskId] = ['outcome' => 'unchanged', 'material' => $rowMaterial];
				continue;
			}

			// RF-073-WP2-21: a same-day replacement must be tested AFTER
			// removing exactly the old allocation this row currently holds
			// — never against its own existing load. The adjusted copies
			// feed the placement check; the real baseline is only mutated
			// once a placement actually committed below.
			$rowPlanned = $planned;
			$rowCounts = $counts;
			$this->removeRowAllocation($roomTaskId, $currentDueKey, $windowSet, $plannedByTask, $rowPlanned, $rowCounts);

			$placement = $this->placeRow(
				$override,
				$proposedAssignees,
				$eligibleMemberIds,
				$legalDates,
				$currentDueKey,
				$window,
				$energyMinor,
				$rowPlanned,
				$rowCounts,
				$capacityByDay,
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

			// Exact shares of the proposed set: stable WP0 wallet order for
			// the remainders (34/33/33 — never role/name/request order).
			$shares = $this->exactShares($placement['assignees'], $membersByUserId);

			// Replace (not add again) the selected row's current in-window
			// allocation before booking the proposed one (plan §3.2):
			// subtract exactly the per-member shares the baseline charged,
			// then add the proposed exact-share vector (integer hundredths).
			$this->removeRowAllocation($roomTaskId, $currentDueKey, $windowSet, $plannedByTask, $planned, $counts);
			foreach ($placement['assignees'] as $userId) {
				$shareMinor = (int)round($energyMinor * ($shares[$userId] ?? 0) / 100);
				$planned[$userId][$placement['date']] = ($planned[$userId][$placement['date']] ?? 0) + $shareMinor;
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
				'shares' => $shares,
				'priority' => (int)$task->getPriority(),
				'flexDays' => (int)$task->getFlexDays(),
				'legalDates' => $legalDates,
				'before' => ['assignees' => $currentAssignees, 'dueDate' => $currentDueKey],
				'proposed' => ['assignees' => $placement['assignees'], 'dueDate' => $placement['date'], 'shares' => $shares],
				'warnings' => $warnings,
			];
			$rowStates[$roomTaskId] = [
				'outcome' => 'change',
				'material' => $rowMaterial,
				'proposed' => $placement,
			];
		}

		$capacity = $this->capacityReport($window, $planned, $counts, $capacityByDay);
		$response = [
			'previewVersion' => $this->fingerprint($startDate, $roomTaskIds, $overrides, $tz, $rowStates, $membersByUserId, $planned, $counts, $todayKey, $houseId),
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
	 * The exact equal share split (whole percent, total exactly 100) of a
	 * participant set in stable WP0 wallet order — remainders go to the
	 * lowest wallet ids (34/33/33), never by role/name/request order.
	 *
	 * @param list<string> $assignees
	 * @param array<string, HouseUser> $membersByUserId
	 * @return array<string, int>
	 */
	private function exactShares(array $assignees, array $membersByUserId): array {
		$entries = [];
		foreach ($assignees as $userId) {
			$walletId = $membersByUserId[$userId]?->getWalletId();
			$entries[] = ['userId' => $userId, 'walletId' => $walletId !== null ? (int)$walletId : PHP_INT_MAX];
		}
		usort($entries, static fn (array $a, array $b): int => $a['walletId'] <=> $b['walletId']);
		$count = count($entries);
		if ($count === 0) {
			return [];
		}
		$base = intdiv(100, $count);
		$remainder = 100 - $base * $count;
		$shares = [];
		foreach ($entries as $index => $entry) {
			$shares[$entry['userId']] = $base + ($index < $remainder ? 1 : 0);
		}
		return $shares;
	}

	/**
	 * Remove exactly the per-member allocation the baseline currently
	 * charges for one room task's in-window due day (plan §3.2: replace,
	 * never add again). Shared by the pre-check adjusted copies and the
	 * committed baseline mutation (RF-073-WP2-21).
	 *
	 * @param array<string, bool> $windowSet
	 * @param array<int, array<string, array<string, int>>> $plannedByTask
	 * @param array<string, array<string, int>> $planned mutated
	 * @param array<string, array<string, int>> $counts mutated
	 */
	private function removeRowAllocation(int $roomTaskId, ?string $currentDueKey, array $windowSet, array $plannedByTask, array &$planned, array &$counts): void {
		if ($currentDueKey === null || !isset($windowSet[$currentDueKey])) {
			return;
		}
		foreach ($plannedByTask[$roomTaskId] ?? [] as $userId => $perDay) {
			$old = $perDay[$currentDueKey] ?? 0;
			if ($old === 0) {
				continue;
			}
			$planned[$userId][$currentDueKey] = max(0, ($planned[$userId][$currentDueKey] ?? 0) - $old);
			$counts[$userId][$currentDueKey] = max(0, ($counts[$userId][$currentDueKey] ?? 0) - 1);
		}
	}

	/**
	 * Deterministic placement: prefer the nearest legal date, then the
	 * eligible member with the most remaining capacity; stable tie-breakers
	 * are the date key and the member's house-user id (plan §3.1 rule 5).
	 * Whole-day minor-unit capacity (WP2): a placement fits only when
	 * completed + planned + the new commitment stays within the member's
	 * budget, and paused/unavailable members and child limits gate the
	 * slot.
	 *
	 * RF-073-WP2-21: a preserved or explicitly overridden JOINT candidate
	 * set is validated as the FULL proposed share vector — every member
	 * with their exact share (never one member with the whole task energy,
	 * never one fitting member dragging the rest along). Only the
	 * single-pick path (unassigned row, no assignee override) tests one
	 * person with the whole energy. `$planned`/`$counts` arrive with the
	 * row's own replaced allocation already removed.
	 *
	 * @param array<string, array<string, int>> $planned member userId → dayKey → planned minor (adjusted, read here)
	 * @param array<string, array<string, int>> $counts member userId → dayKey → planned task count (adjusted)
	 * @param array<string, array<string, array<string, mixed>>> $capacityByDay evaluation report (dayKey → userId → member-day)
	 * @param array<string, HouseUser> $membersByUserId
	 * @return array{date: string, assignees: list<string>}|null
	 */
	private function placeRow(
		?array $override,
		array $proposedAssignees,
		array $eligibleMemberIds,
		array $legalDates,
		?string $currentDueKey,
		array $window,
		int $energyMinor,
		array $planned,
		array $counts,
		array $capacityByDay,
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
		$setShares = $singlePick ? null : $this->exactShares($candidateMemberIds, $membersByUserId);

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
			$dayMembers = $capacityByDay[$date] ?? [];

			if (!$singlePick) {
				// The WHOLE proposed set must fit with its exact shares —
				// a paused, unavailable or over-budget member rejects the
				// day for the entire joint arrangement (plan §3.2: no
				// successful placement based on only one member).
				$fits = true;
				foreach ($candidateMemberIds as $userId) {
					$memberDay = $dayMembers[$userId] ?? null;
					if ($memberDay === null || $memberDay['paused'] || !$memberDay['available']) {
						$fits = false;
						break;
					}
					$shareMinor = (int)round($energyMinor * ($setShares[$userId] ?? 0) / 100);
					$committed = ($planned[$userId][$date] ?? 0);
					if (($memberDay['completedEnergyMinor'] ?? 0) + $committed + $shareMinor > $memberDay['budgetMinor']) {
						$fits = false;
						break;
					}
					$maxDaily = $memberDay['maxDailyTasks'];
					if ($maxDaily !== null && ($counts[$userId][$date] ?? 0) + 1 > $maxDaily) {
						$fits = false;
						break;
					}
				}
				if ($fits) {
					return ['date' => $date, 'assignees' => $candidateMemberIds];
				}
				continue;
			}

			$orderedMembers = $candidateMemberIds;
			usort($orderedMembers, function (string $a, string $b) use ($date, $dayMembers): int {
				$remainingA = $dayMembers[$a]['remainingEnergyMinor'] ?? 0;
				$remainingB = $dayMembers[$b]['remainingEnergyMinor'] ?? 0;
				return $remainingB <=> $remainingA
					?: strcmp($a, $b);
			});

			foreach ($orderedMembers as $userId) {
				$memberDay = $dayMembers[$userId] ?? null;
				if ($memberDay === null) {
					continue;
				}
				if ($memberDay['paused'] || !$memberDay['available']) {
					continue;
				}
				$committed = ($planned[$userId][$date] ?? 0);
				if (($memberDay['completedEnergyMinor'] ?? 0) + $committed + $energyMinor > $memberDay['budgetMinor']) {
					continue;
				}
				$maxDaily = $memberDay['maxDailyTasks'];
				if ($maxDaily !== null && ($counts[$userId][$date] ?? 0) >= $maxDaily) {
					continue;
				}
				return ['date' => $date, 'assignees' => [$userId]];
			}
		}

		return null;
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

	/**
	 * Existing-overload warning for unchanged rows (plan §3.2): completed
	 * + outstanding minor vs the member's budget, from the whole-day
	 * evaluation — completed effort included, not just planned load.
	 *
	 * @param list<string> $assigneeIds
	 * @param array<string, array<string, array<string, mixed>>> $capacityByDay
	 * @return list<string>
	 */
	private function capacityWarnings(array $assigneeIds, string $dayKey, array $capacityByDay, array $window): array {
		if (!in_array($dayKey, $window, true)) {
			return [];
		}
		$dayMembers = $capacityByDay[$dayKey] ?? [];
		foreach ($assigneeIds as $userId) {
			$memberDay = $dayMembers[$userId] ?? null;
			if ($memberDay === null) {
				continue;
			}
			if (($memberDay['completedEnergyMinor'] ?? 0) + ($memberDay['plannedEnergyMinor'] ?? 0) > $memberDay['budgetMinor']) {
				return ['EXISTING_OVERLOAD'];
			}
		}
		return [];
	}

	/**
	 * Per-day capacity report for the whole window (after proposals).
	 * WP2 exact amounts (integer hundredths: completedEnergyMinor,
	 * plannedEnergyMinor, remainingEnergyMinor) sit beside the historical
	 * whole-number view the current clients render; overload keeps the
	 * clarification flag with completed/outstanding/remaining amounts.
	 *
	 * @param array<string, int> $planned
	 * @param array<string, array<string, int>> $counts
	 * @param array<string, array<string, array<string, mixed>>> $capacityByDay
	 * @return list<array{date: string, members: array<string, array{houseUserId: int, available: bool, budget: int, planned: int, remaining: int, taskCount: int, completedEnergyMinor: int, plannedEnergyMinor: int, remainingEnergyMinor: int, overloaded: bool, paused: bool, reasons: list<string>}>}>
	 */
	private function capacityReport(array $window, array $planned, array $counts, array $capacityByDay): array {
		$report = [];
		foreach ($window as $dayKey) {
			$members = [];
			foreach ($capacityByDay[$dayKey] ?? [] as $userId => $memberDay) {
				$budget = (int)$memberDay['budget'];
				$committedMinor = ($planned[$userId][$dayKey] ?? 0);
				$completedMinor = (int)$memberDay['completedEnergyMinor'];
				$plannedMinor = $completedMinor + $committedMinor;
				$members[$userId] = [
					'houseUserId' => (int)$memberDay['houseUserId'],
					'available' => (bool)$memberDay['available'],
					'budget' => $budget,
					'planned' => (int)round($plannedMinor / DailyCapacityService::ENERGY_MINOR),
					'remaining' => max(0, $budget - (int)round($plannedMinor / DailyCapacityService::ENERGY_MINOR)),
					'taskCount' => $counts[$userId][$dayKey] ?? 0,
					'completedEnergyMinor' => $completedMinor,
					'plannedEnergyMinor' => $plannedMinor,
					'remainingEnergyMinor' => max(0, (int)$memberDay['budgetMinor'] - $plannedMinor),
					'overloaded' => $plannedMinor > (int)$memberDay['budgetMinor'],
					'paused' => (bool)$memberDay['paused'],
					'reasons' => $memberDay['reasons'],
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
	 * and preferences, the house timezone, the pause revisions, the
	 * evaluation local day, the capacity-source rows and the proposal
	 * result (WP2 plan §3.5: pause/completion/role/zone/limit drift and a
	 * crossed local midnight all invalidate the confirmation). Canonical
	 * sorting is part of the contract.
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
		string $evaluationDayKey,
		int $houseId,
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
			'version' => 2,
			'request' => [
				'startDate' => $startDate,
				'roomTaskIds' => self::sortedInts($roomTaskIds),
				'overrides' => $overrides,
			],
			'timezone' => $tz->getName(),
			'evaluationDay' => $evaluationDayKey,
			'pauseFingerprint' => $this->pauseProjection->housePauseFingerprint($houseId),
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
