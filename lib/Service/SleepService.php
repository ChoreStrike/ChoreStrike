<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Exception\ConflictException;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Exception\NotFoundException;
use OCA\ChoreStrike\Exception\StaleTargetException;
use OCP\IL10N;

/**
 * The sleep command (CS-PLAN-091 WP-B4, ADR-021 decision 7): adults and
 * the owner put a room task to sleep, optionally until a wake date, and
 * wake it again. A sleeping task has no cycle — no window, never overdue,
 * nothing to suggest. {@see CycleService} owns the rule; this service owns
 * the receipt, the lock order and the access gate.
 */
class SleepService {
	public const ACTION = 'room_task.sleep';

	/** How far ahead a wake date may lie. */
	private const WAKE_RANGE_DAYS = 366;

	public function __construct(
		private CommandService $commandService,
		private CycleService $cycleService,
		private RoomTaskAccess $access,
		private AuthorizationService $authorization,
		private RoomTaskReadService $roomTaskReads,
		private RoomTaskMapper $roomTaskMapper,
		private TaskMapper $taskMapper,
		private HouseMapper $houseMapper,
		private CalendarService $calendar,
		private IL10N $l10n,
	) {
	}

	/**
	 * @param string|null $wakeOn House-local day after today (`Y-m-d`); only with `$sleeping`
	 * @return array{roomTask: RoomTask, cycle: ?array<string, mixed>, replay: bool}
	 * @throws NotFoundException unknown or invisible room task
	 * @throws ForbiddenException a child, or a member who left the house
	 * @throws ConflictException `STALE_TARGET`, or an inactive task
	 * @throws \InvalidArgumentException a wake date the task cannot take
	 */
	public function setSleeping(int $houseId, HouseUser $actor, int $roomTaskId, string $commandId, int $expectedVersion, bool $sleeping, ?string $wakeOn): array {
		if (!$sleeping && $wakeOn !== null) {
			throw new \InvalidArgumentException($this->l10n->t('A wake date needs a sleeping task'));
		}
		$command = $this->commandService->runCommand(
			$houseId,
			(int)$actor->getId(),
			$commandId,
			self::ACTION,
			['roomTaskId' => $roomTaskId, 'expectedVersion' => $expectedVersion, 'sleeping' => $sleeping, 'wakeOn' => $wakeOn],
			fn (): array => $this->aggregate($houseId, $roomTaskId, $expectedVersion, $sleeping, $wakeOn),
			// Execution and replay alike: an adult member who may see the task.
			function () use ($houseId, $actor, $roomTaskId): void {
				$this->authorized($houseId, $actor, $roomTaskId);
			},
		);

		// The answer is read fresh, for the member as they are now.
		$fresh = $this->authorized($houseId, $actor, $roomTaskId);
		$tasksById = [(int)$fresh['task']->getId() => $fresh['task']];
		return [
			'roomTask' => $fresh['roomTask'],
			'cycle' => $this->roomTaskReads->cycleFacts($houseId, [$fresh['roomTask']], $tasksById)[$roomTaskId]['block'] ?? null,
			'replay' => $command['replay'],
		];
	}

	/** @return array<string, mixed> The stored receipt identity */
	private function aggregate(int $houseId, int $roomTaskId, int $expectedVersion, bool $sleeping, ?string $wakeOn): array {
		$roomTask = $this->roomTaskMapper->lockForUpdate($roomTaskId);
		if (!$roomTask->getIsActive()) {
			throw new ConflictException($this->l10n->t('Task is not active'));
		}
		if ($roomTask->getCycleVersion() !== $expectedVersion) {
			throw new StaleTargetException($this->l10n->t('The task changed since it was displayed. Reload and try again.'));
		}
		$house = $this->houseMapper->find($houseId);
		$timeZone = $this->calendar->getTimeZone($house);
		$now = new \DateTime();
		$today = WindowService::todayKey($now, $timeZone);
		$task = $this->taskMapper->find($roomTask->getTaskId());

		if ($sleeping) {
			$this->assertWakeDate($wakeOn, $today);
			$this->cycleService->sleepUnderLock($house, $roomTask, $task, $wakeOn, $timeZone, $now);
		} elseif ($roomTask->getSleepingSince() !== null) {
			$this->cycleService->wakeUnderLock($roomTask, $task, $today, $timeZone, $now);
		}
		return ['roomTaskId' => $roomTaskId, 'sleeping' => $sleeping];
	}

	/** @throws \InvalidArgumentException */
	private function assertWakeDate(?string $wakeOn, string $today): void {
		if ($wakeOn === null) {
			return;
		}
		$day = $this->calendar->isDayKey($wakeOn) ? \DateTime::createFromFormat('!Y-m-d', $wakeOn, new \DateTimeZone('UTC')) : false;
		if ($day === false || $day->format('Y-m-d') !== $wakeOn) {
			throw new \InvalidArgumentException($this->l10n->t('A valid date (Y-m-d) is required'));
		}
		if ($wakeOn <= $today || RecurrenceCalculator::daysBetween($today, $wakeOn) > self::WAKE_RANGE_DAYS) {
			throw new \InvalidArgumentException($this->l10n->t('The wake date must lie after today and within one year'));
		}
	}

	/**
	 * The room task for an adult member who may see it, read fresh.
	 *
	 * @return array{roomTask: RoomTask, task: \OCA\ChoreStrike\Db\Task, assignees: string[]}
	 * @throws NotFoundException
	 * @throws ForbiddenException
	 */
	private function authorized(int $houseId, HouseUser $actor, int $roomTaskId): array {
		$live = $this->access->liveMember($houseId, $actor);
		$this->authorization->assertCanManageTasks($live);
		return $this->access->visible($houseId, $live, $roomTaskId);
	}
}
