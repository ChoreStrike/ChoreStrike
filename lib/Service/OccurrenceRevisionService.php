<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\OccurrenceMember;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\ScheduleRevision;
use OCA\ChoreStrike\Db\ScheduleRevisionMapper;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Db\TransactionExecutor;
use OCA\ChoreStrike\Db\WalletMapper;

/**
 * Schedule revision lifecycle of the occurrence timeline (CS-PLAN-072
 * WP1; parent CS-PLAN-070 §3.2 rule 4; RF-072-WP1-18 split of the
 * former OccurrenceService monolith).
 *
 * Maps task definitions onto immutable rule inputs, creates revision 1
 * with its initial agreed occurrences, and owns the revision boundary:
 * before a rule/energy/zone/participant edit the elapsed old agreements
 * are reconciled under the old inputs, the old revision is closed and
 * the new revision appended — all inside the caller's house-first
 * transaction. A failed edit rolls back the entire boundary.
 */
class OccurrenceRevisionService {
	private TransactionExecutor $transactions;
	private ScheduleRevisionMapper $revisionMapper;
	private RoomTaskMapper $roomTaskMapper;
	private HouseMapper $houseMapper;
	private TaskMapper $taskMapper;
	private WalletMapper $walletMapper;
	private RecurrenceCalculator $calculator;
	private CalendarService $calendar;
	private OccurrenceReconcileService $reconcile;

	public function __construct(
		TransactionExecutor $transactions,
		ScheduleRevisionMapper $revisionMapper,
		RoomTaskMapper $roomTaskMapper,
		HouseMapper $houseMapper,
		TaskMapper $taskMapper,
		WalletMapper $walletMapper,
		RecurrenceCalculator $calculator,
		CalendarService $calendar,
		OccurrenceReconcileService $reconcile,
	) {
		$this->transactions = $transactions;
		$this->revisionMapper = $revisionMapper;
		$this->roomTaskMapper = $roomTaskMapper;
		$this->houseMapper = $houseMapper;
		$this->taskMapper = $taskMapper;
		$this->walletMapper = $walletMapper;
		$this->calculator = $calculator;
		$this->calendar = $calendar;
		$this->reconcile = $reconcile;
	}

	/**
	 * Map a task definition onto rule inputs for a new schedule revision.
	 *
	 * Mapping (legacy vocabulary → timeline rule):
	 * - schedulingMode static → fixed cadence; dynamic → completion-relative
	 *   interval (daily/weekly/monthly frequency unit, `custom` = weekly).
	 * - static weekly + fixedScheduleDays → fixed weekly on those weekdays
	 *   (fortnightly = frequencyValue 2 anchored on the chosen first date).
	 * - flexDays → symmetric early/late window days (the legacy flex window).
	 *
	 * @return array{cadenceMode: string, cadenceUnit: string, cadenceInterval: int, weekdays: ?string, lifetime: string, activeFrom: ?\DateTime, activeUntil: ?\DateTime, early: int, late: int, energy: int, isPublic: bool, isVoluntary: bool}
	 */
	public function ruleInputsFromTask(Task $task): array {
		$unit = match ($task->getFrequencyType()) {
			'daily' => ScheduleRevision::UNIT_DAY,
			'monthly' => ScheduleRevision::UNIT_MONTH,
			'weekly', 'custom' => ScheduleRevision::UNIT_WEEK,
			default => ScheduleRevision::UNIT_WEEK,
		};
		$interval = max(1, (int)$task->getFrequencyValue());
		if ($task->getFrequencyType() === 'custom') {
			$interval = 1;
		}

		$weekdays = null;
		$cadenceMode = ScheduleRevision::CADENCE_INTERVAL;
		if ($task->getSchedulingMode() === Task::SCHEDULING_STATIC) {
			$cadenceMode = ScheduleRevision::CADENCE_FIXED;
			if ($unit === ScheduleRevision::UNIT_WEEK && is_string($task->getFixedScheduleDays()) && $task->getFixedScheduleDays() !== '') {
				$decoded = json_decode($task->getFixedScheduleDays(), true);
				if (is_array($decoded) && $decoded !== []) {
					$weekdays = json_encode(array_values(array_map(
						static fn ($day): string => strtolower(trim((string)$day)),
						$decoded
					)));
				}
			}
		}

		// FEAT-UX-039: validation owns the rule's cap; this read side only
		// guards the sanity bound and never shrinks a stored legacy value.
		$flex = min(FlexDaysPolicy::SANITY_MAX, max(0, (int)$task->getFlexDays()));

		return [
			'cadenceMode' => $cadenceMode,
			'cadenceUnit' => $unit,
			'cadenceInterval' => $interval,
			'weekdays' => $weekdays,
			'lifetime' => $task->getRecurrenceMode(),
			'activeFrom' => $task->getActiveFrom(),
			'activeUntil' => $task->getActiveUntil(),
			'early' => $flex,
			'late' => $flex,
			'energy' => max(1, (int)$task->getEnergyCost()),
			'isPublic' => $task->getVisibilityType() === Task::VISIBILITY_PUBLIC,
			'isVoluntary' => (bool)$task->getIsVoluntary(),
		];
	}

	/**
	 * The default member snapshot from the canonical assignee set: equal
	 * integer shares (total exactly 100), remainder distributed in stable
	 * wallet-id order (parent §3.1; exact C3 precision stays WP3).
	 *
	 * @param string[] $assigneeUserIds
	 * @return list<array{walletId: int, userId: string, role: string, obligated: bool, sharePct: int}>|null
	 */
	public function buildDefaultMembers(int $houseId, array $assigneeUserIds): ?array {
		$unique = array_values(array_unique(array_filter(
			$assigneeUserIds,
			static fn ($id): bool => is_string($id) && trim($id) !== ''
		)));
		if ($unique === []) {
			return null;
		}

		$entries = [];
		foreach ($unique as $userId) {
			$wallet = $this->walletMapper->findByHouseAndUser($houseId, $userId);
			if ($wallet === null) {
				continue;
			}
			$entries[] = [
				'walletId' => (int)$wallet->getId(),
				'userId' => $userId,
				'role' => OccurrenceMember::ROLE_RESPONSIBLE,
				'obligated' => true,
				'sharePct' => 0,
			];
		}
		if ($entries === []) {
			return null;
		}

		usort($entries, static fn (array $a, array $b): int => $a['walletId'] <=> $b['walletId']);
		$count = count($entries);
		$base = intdiv(100, $count);
		$remainder = 100 - $base * $count;
		foreach ($entries as $index => &$entry) {
			$entry['sharePct'] = $base + ($index < $remainder ? 1 : 0);
		}
		unset($entry);
		return $entries;
	}

	/**
	 * Create revision 1 plus its initial agreed occurrences for a freshly
	 * created room task. UNDER LOCK: the caller (TaskCreationService /
	 * HouseSetupService / seed) holds the house-first transaction.
	 *
	 * The anchor is the explicit creation due date (house-local) or today
	 * in the house zone; the frontier opens at the seven-day horizon for
	 * fixed rules and at the anchor for interval rules (one pending
	 * occurrence, never a fictitious chain).
	 */
	public function initializeScheduleUnderLock(
		int $houseId,
		RoomTask $roomTask,
		Task $task,
		string $timezoneId,
		?\DateTime $explicitNextDue,
		array $assigneeUserIds,
		\DateTime $now,
	): ScheduleRevision {
		$tz = new \DateTimeZone($timezoneId);
		$anchorKey = $explicitNextDue !== null
			? $this->calendar->dayKey($explicitNextDue, $tz)
			: $this->calendar->dayKey($now, $tz);

		$inputs = $this->ruleInputsFromTask($task);
		$horizonKey = $this->reconcile->horizonKey($now, $tz);
		$intervalMode = $inputs['cadenceMode'] === ScheduleRevision::CADENCE_INTERVAL;

		$revision = $this->revisionMapper->createRevision(
			$roomTask->getId(),
			1,
			$now,
			RecurrenceCalculator::dayKeyToDate($anchorKey),
			$inputs['cadenceMode'],
			$inputs['cadenceUnit'],
			$inputs['cadenceInterval'],
			RecurrenceCalculator::dayKeyToDate($anchorKey),
			$inputs['weekdays'],
			$inputs['lifetime'],
			$inputs['activeFrom'],
			$inputs['activeUntil'],
			$inputs['early'],
			$inputs['late'],
			$timezoneId,
			$inputs['energy'],
			$inputs['isPublic'],
			$inputs['isVoluntary'],
			$this->buildDefaultMembers($houseId, $assigneeUserIds),
		);

		if ($intervalMode) {
			// One pending occurrence at the anchor; the frontier is its own
			// planned date (nothing farther is agreed).
			$this->reconcile->materializeEntriesUnderLock($houseId, $roomTask->getId(), $revision, [['ordinal' => 1, 'date' => $anchorKey]], $now);
			$this->reconcile->updateNextDueProjectionUnderLock($roomTask, $now);
			return $revision;
		}

		$targetKey = $horizonKey;
		if ($inputs['activeUntil'] !== null) {
			$untilKey = $this->calendar->dayKey($inputs['activeUntil'], $tz);
			$targetKey = min($targetKey, $untilKey);
		}
		if ($targetKey >= $anchorKey) {
			$entries = $this->calculator->enumerate($revision, $anchorKey, $targetKey);
			$this->reconcile->materializeEntriesUnderLock($houseId, $roomTask->getId(), $revision, $entries, $now);
			$this->revisionMapper->advanceFrontier($revision, RecurrenceCalculator::dayKeyToDate($targetKey));
		}
		$this->reconcile->updateNextDueProjectionUnderLock($roomTask, $now);
		return $revision;
	}

	/**
	 * Transaction-owning wrapper for seed/setup paths that create room
	 * tasks outside a creation aggregate: opens the house-first
	 * transaction (house row lock first), then initializes revision 1
	 * under the lock.
	 */
	public function initializeSchedule(
		int $houseId,
		RoomTask $roomTask,
		Task $task,
		string $timezoneId,
		?\DateTime $explicitNextDue,
		array $assigneeUserIds,
		\DateTime $now,
	): ScheduleRevision {
		return $this->transactions->run(function () use ($houseId, $roomTask, $task, $timezoneId, $explicitNextDue, $assigneeUserIds, $now): ScheduleRevision {
			$this->houseMapper->findForUpdate($houseId);
			return $this->initializeScheduleUnderLock($houseId, $roomTask, $task, $timezoneId, $explicitNextDue, $assigneeUserIds, $now);
		});
	}

	/**
	 * Revise one room task UNDER LOCK: reconcile elapsed agreements, close
	 * the old revision, append the new one. `$forceParticipants` replaces
	 * the default-member snapshot (agreement-writer participant path).
	 */
	public function reviseUnderLock(
		int $houseId,
		RoomTask $roomTask,
		Task $task,
		string $timezoneId,
		?array $forceParticipants,
		\DateTime $now,
	): ScheduleRevision {
		$todayKey = $this->calendar->dayKey($now, new \DateTimeZone($timezoneId));
		$oldRevision = null;
		try {
			$oldRevision = $this->revisionMapper->findCurrentByRoomTask($roomTask->getId());
			// Reconcile elapsed old agreements under the old inputs.
			if ($oldRevision->getCadenceMode() === ScheduleRevision::CADENCE_FIXED) {
				$this->reconcile->reconcileThroughUnderLock($houseId, $roomTask, $todayKey, $now);
			} else {
				$this->reconcile->ensureIntervalPendingUnderLock($houseId, $roomTask, $oldRevision, $now);
			}
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			// No live revision (never initialized) — the new one is revision 1.
		}

		$inputs = $this->ruleInputsFromTask($task);
		$participants = $forceParticipants ?? $this->currentDefaultMembers($oldRevision);

		$nextRevisionNumber = $oldRevision !== null ? $oldRevision->getRevision() + 1 : 1;
		$oldFrontierKey = $oldRevision !== null
			? $oldRevision->getAgreedThrough()->format('Y-m-d')
			: RecurrenceCalculator::dayBefore($todayKey);

		$keepAnchor = $oldRevision !== null
			&& $this->cadenceShapeUnchanged($oldRevision, $inputs)
			&& ($inputs['cadenceMode'] !== ScheduleRevision::CADENCE_FIXED
				|| $inputs['weekdays'] === null
				|| in_array(strtolower($this->dayName($oldRevision->getAnchorDate()->format('Y-m-d'))), $this->weekdayNames($inputs['weekdays']), true));

		$anchorKey = $keepAnchor && $oldRevision !== null
			? $oldRevision->getAnchorDate()->format('Y-m-d')
			: RecurrenceCalculator::dayAfter($oldFrontierKey);

		// Interval rules anchor their pending at the first generatable
		// date; an existing unfulfilled occurrence stays under the old
		// revision until completed.
		$newFrontierKey = $oldFrontierKey;

		$revision = $this->revisionMapper->createRevision(
			$roomTask->getId(),
			$nextRevisionNumber,
			$now,
			RecurrenceCalculator::dayKeyToDate($newFrontierKey),
			$inputs['cadenceMode'],
			$inputs['cadenceUnit'],
			$inputs['cadenceInterval'],
			RecurrenceCalculator::dayKeyToDate($anchorKey),
			$inputs['weekdays'],
			$inputs['lifetime'],
			$inputs['activeFrom'],
			$inputs['activeUntil'],
			$inputs['early'],
			$inputs['late'],
			$timezoneId,
			$inputs['energy'],
			$inputs['isPublic'],
			$inputs['isVoluntary'],
			$participants,
		);

		if ($oldRevision !== null && $oldRevision->getClosedAt() === null) {
			$this->revisionMapper->close($oldRevision, $now);
		}

		// An interval revision needs its pending occurrence now when no
		// unfulfilled one survives under the old revision.
		if ($inputs['cadenceMode'] === ScheduleRevision::CADENCE_INTERVAL) {
			$this->reconcile->ensureIntervalPendingUnderLock($houseId, $roomTask, $revision, $now);
		}

		$this->reconcile->updateNextDueProjectionUnderLock($roomTask, $now);
		return $revision;
	}

	/**
	 * Zone-change revision pass UNDER LOCK (the caller — e.g.
	 * HouseService::updateHouse — already holds the house-first
	 * transaction).
	 */
	public function reviseForZoneChangeUnderLock(int $houseId, string $newTimezoneId, \DateTime $now): void {
		foreach ($this->roomTaskMapper->findByHouse($houseId, true) as $roomTask) {
			if ($roomTask->getDeletedAt() !== null) {
				continue;
			}
			$task = $this->taskOf($roomTask);
			if ($task === null) {
				continue;
			}
			$locked = $this->roomTaskMapper->lockForUpdate($roomTask->getId());
			$this->reviseUnderLock($houseId, $locked, $task, $newTimezoneId, null, $now);
		}
	}

	/**
	 * Stop scheduling for a tombstoned room task: close the live revision.
	 * Occurrence/completion evidence stays (minimum history retention).
	 */
	public function stopSchedulingUnderLock(int $roomTaskId, \DateTime $now): void {
		try {
			$revision = $this->revisionMapper->findCurrentByRoomTask($roomTaskId);
			$this->revisionMapper->close($revision, $now);
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			// Never initialized — nothing to close.
		}
	}

	/**
	 * @return list<string>
	 */
	private function weekdayNames(?string $weekdayJson): array {
		if ($weekdayJson === null || $weekdayJson === '') {
			return [];
		}
		$decoded = json_decode($weekdayJson, true);
		return is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
	}

	private function cadenceShapeUnchanged(ScheduleRevision $old, array $newInputs): bool {
		return $old->getCadenceMode() === $newInputs['cadenceMode']
			&& $old->getCadenceUnit() === $newInputs['cadenceUnit']
			&& $old->getCadenceInterval() === $newInputs['cadenceInterval']
			&& $old->getWeekdays() === $newInputs['weekdays']
			&& $old->getLifetime() === $newInputs['lifetime']
			&& $old->getActiveFrom()?->format('Y-m-d') === ($newInputs['activeFrom']?->format('Y-m-d'))
			&& $old->getActiveUntil()?->format('Y-m-d') === ($newInputs['activeUntil']?->format('Y-m-d'));
	}

	private function currentDefaultMembers(?ScheduleRevision $revision): ?array {
		if ($revision === null) {
			return null;
		}
		return $revision->getDefaultMembers();
	}

	private function taskOf(RoomTask $roomTask): ?Task {
		try {
			return $this->taskMapper->find($roomTask->getTaskId());
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			return null;
		}
	}

	private function dayName(string $dayKey): string {
		return strtolower(\DateTime::createFromFormat('!Y-m-d', $dayKey)->format('l'));
	}
}
