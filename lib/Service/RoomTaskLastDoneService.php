<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\Occurrence;
use OCA\ChoreStrike\Db\OccurrenceMapper;
use OCA\ChoreStrike\Db\RoomMapper;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\ScheduleRevision;
use OCA\ChoreStrike\Db\ScheduleRevisionMapper;
use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Exception\ConflictException;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Exception\NotFoundException;
use OCA\ChoreStrike\Exception\ValidationException;
use OCP\IL10N;

/**
 * "Last done" per room task (FEAT-UX-045, CS-PLAN-088 D-088-20): an owner
 * or adult member records the day a chore was last done, for example right
 * after setting up the house.
 *
 * The day stamps the room's "Last cleaned" through a `last_done` journal
 * event — no cleanliness change, reward, completion fact or rhythm effect.
 * A completion-relative interval re-anchors its pending occurrence to
 * `max(today, last done + period)` through the date-agreement writer
 * (evidence row, version bump, C2h child limit); a fixed cadence keeps its
 * dates (C1). While the current appointment is overdue the command is
 * refused — a miss stays a miss, catch-up applies.
 */
class RoomTaskLastDoneService {
	public function __construct(
		private RoomTaskMapper $roomTaskMapper,
		private TaskMapper $taskMapper,
		private RoomMapper $roomMapper,
		private HouseMapper $houseMapper,
		private ScheduleRevisionMapper $revisionMapper,
		private OccurrenceMapper $occurrenceMapper,
		private OccurrenceService $occurrenceService,
		private RoomReplayService $roomReplayService,
		private RoomTaskService $roomTaskService,
		private RoomTaskReadService $readService,
		private CommandService $commandService,
		private AuthorizationService $authorization,
		private CalendarService $calendarService,
		private RecurrenceCalculator $recurrence,
		private IL10N $l10n,
	) {
	}

	/**
	 * Record that the room task was last done on `$dateKey` (house-local
	 * `Y-m-d`, today or earlier) under the receipt `$commandId`.
	 *
	 * @throws ForbiddenException for a child or a non-member
	 * @throws NotFoundException for a foreign, invisible or deleted room task
	 * @throws ValidationException for a malformed, impossible or future day
	 * @throws ConflictException while the current appointment is overdue
	 */
	public function recordLastDone(int $roomTaskId, int $houseId, string $dateKey, string $commandId): RoomTask {
		$actor = $this->assertAdultMember($houseId);
		$this->visibleRoomTask($roomTaskId, $houseId, $actor, false);
		$tz = $this->readService->timeZoneForHouse($houseId);
		$day = $this->calendarService->isDayKey($dateKey) ? \DateTime::createFromFormat('!Y-m-d', $dateKey) : false;
		if ($day === false || $day->format('Y-m-d') !== $dateKey
			|| $dateKey > $this->calendarService->dayKey(new \DateTime(), $tz)) {
			throw new ValidationException($this->l10n->t('The last done date must be today or earlier'));
		}

		$this->commandService->runCommand($houseId, (int)$actor->getId(), $commandId, 'room_task.last_done', [
			'roomTaskId' => $roomTaskId,
			'date' => $dateKey,
		], function () use ($roomTaskId, $houseId, $dateKey, $commandId, $tz): array {
			// The command holds the house lock: re-check the actor and the
			// room task on the locked row, then room task → room.
			$actor = $this->assertAdultMember($houseId);
			$roomTask = $this->visibleRoomTask($roomTaskId, $houseId, $actor, true);
			$now = new \DateTime();
			$this->reanchorIntervalUnderLock($houseId, $roomTask, $actor, $dateKey, $this->calendarService->dayKey($now, $tz), $now);

			$room = $this->roomMapper->lockForUpdate($roomTask->getRoomId());
			$this->roomReplayService->recordLastDone((int)$room->getId(), $roomTaskId, $commandId, $this->calendarService->dayStartInstant($dateKey, $tz));
			$this->roomReplayService->resyncRoomCache($room);
			$house = $this->houseMapper->find($houseId);
			$house->setStateVersion($house->getStateVersion() + 1);
			$this->houseMapper->update($house);
			return ['roomTaskId' => $roomTaskId];
		});
		return $this->roomTaskMapper->find($roomTaskId);
	}

	private function assertAdultMember(int $houseId): HouseUser {
		$actor = $this->authorization->assertHouseMember($houseId);
		if ($actor->isChild()) {
			throw new ForbiddenException($this->l10n->t('Only adults can record when a task was last done'));
		}
		return $actor;
	}

	private function visibleRoomTask(int $roomTaskId, int $houseId, HouseUser $actor, bool $forUpdate): RoomTask {
		$this->roomTaskService->validateRoomTaskOwnership($roomTaskId, $houseId);
		$roomTask = $forUpdate ? $this->roomTaskMapper->lockForUpdate($roomTaskId) : $this->roomTaskMapper->find($roomTaskId);
		$this->roomTaskService->assertLifecycleVisibility($roomTask, $this->taskMapper->find($roomTask->getTaskId()), $actor);
		return $roomTask;
	}

	/**
	 * Move the pending occurrence of a completion-relative interval to
	 * `max(today, last done + period)`; a fixed cadence keeps its dates.
	 *
	 * @throws ConflictException while the current appointment is overdue
	 */
	private function reanchorIntervalUnderLock(int $houseId, RoomTask $roomTask, HouseUser $actor, string $dateKey, string $todayKey, \DateTime $now): void {
		$revision = $this->revisionMapper->findCurrentByRoomTask((int)$roomTask->getId());
		if ($revision->getCadenceMode() !== ScheduleRevision::CADENCE_INTERVAL) {
			return;
		}
		$pending = null;
		foreach ($this->occurrenceMapper->findOpenByRoomTasks([(int)$roomTask->getId()]) as $occurrence) {
			if ($occurrence->getKind() === Occurrence::KIND_VOLUNTARY
				|| $occurrence->getSuspensionState() === Occurrence::SUSPENSION_SUSPENDED) {
				continue;
			}
			if ($occurrence->getWindowEnd() <= $now) {
				throw new ConflictException($this->l10n->t('This task is overdue. Complete it or catch it up instead.'));
			}
			$pending = $occurrence;
		}
		$targetKey = max($todayKey, $this->recurrence->nextIntervalDate($dateKey, $revision->getCadenceUnit(), (int)$revision->getCadenceInterval()));
		if ($pending === null || $pending->getPlannedDate()->format('Y-m-d') === $targetKey) {
			return;
		}
		$this->roomTaskService->assertChildCapacityForDate($houseId, $roomTask, $this->houseMapper->find($houseId), $targetKey);
		$this->occurrenceService->amendOccurrenceDateUnderLock($roomTask, (int)$actor->getId(), null, $targetKey, $now);
	}
}
