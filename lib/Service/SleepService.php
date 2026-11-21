<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Exception\ConflictException;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Exception\NotFoundException;
use OCP\IL10N;

/**
 * The sleep command (CS-PLAN-091 WP-B4, ADR-021 decision 7): adults and
 * the owner put a room task to sleep, optionally until a wake date, and
 * wake it again. A sleeping task has no cycle — no window, never overdue,
 * nothing to suggest. {@see CycleSleep} owns the rule; this service owns
 * the receipt and the range of the wake date. {@see SleepSwitch} checks
 * the shape of the switch at the boundary.
 */
class SleepService {
	public const ACTION = 'room_task.sleep';

	/** How far ahead a wake date may lie. */
	private const WAKE_RANGE_DAYS = 366;

	public function __construct(
		private RoomTaskCommand $command,
		private CycleSleep $cycleSleep,
		private TaskMapper $taskMapper,
		private HouseMapper $houseMapper,
		private CalendarService $calendar,
		private IL10N $l10n,
	) {
	}

	/**
	 * @param string|null $wakeOn House-local day after today (`Y-m-d`); only with `$sleeping` ({@see SleepSwitch})
	 * @return array{roomTask: RoomTask, task: Task, assignees: string[], cycle: ?array<string, mixed>, timeZone: \DateTimeZone, replay: bool}
	 * @throws NotFoundException unknown or invisible room task
	 * @throws ForbiddenException a child, or a member who left the house
	 * @throws ConflictException `STALE_TARGET`, or an inactive task
	 * @throws \InvalidArgumentException a wake date the task cannot take
	 */
	public function setSleeping(int $houseId, HouseUser $actor, int $roomTaskId, string $commandId, int $expectedVersion, bool $sleeping, ?string $wakeOn): array {
		return $this->command->run(
			$houseId,
			$actor,
			$roomTaskId,
			$commandId,
			self::ACTION,
			['expectedVersion' => $expectedVersion, 'sleeping' => $sleeping, 'wakeOn' => $wakeOn],
			fn (): array => $this->aggregate($houseId, $roomTaskId, $expectedVersion, $sleeping, $wakeOn),
			true,
		);
	}

	/** @return array<string, mixed> The stored receipt identity */
	private function aggregate(int $houseId, int $roomTaskId, int $expectedVersion, bool $sleeping, ?string $wakeOn): array {
		$roomTask = $this->command->lockTarget($roomTaskId, $expectedVersion);
		$house = $this->houseMapper->find($houseId);
		$task = $this->taskMapper->find($roomTask->getTaskId());
		$this->applyUnderLock($house, $roomTask, $task, $sleeping, $wakeOn, $this->calendar->getTimeZone($house), new \DateTime());
		return ['roomTaskId' => $roomTaskId, 'sleeping' => $sleeping];
	}

	/**
	 * The sleep switch UNDER LOCK, for the sleep command and the one form's
	 * save (CS-PLAN-091 WP-B6): asleep until an optional wake date, or awake
	 * from today. A switch that matches the task's state changes nothing:
	 * a save that sends the stored wake date again is no new choice of that
	 * date, also on the wake day itself, before the job has run.
	 *
	 * @param string|null $wakeOn House-local day after today (`Y-m-d`); only with `$sleeping`
	 * @param array<int, CycleHistory>|null $histories Completion inputs the caller read already
	 * @return bool Whether the sleep state changed
	 * @throws \InvalidArgumentException a wake date the task cannot take
	 */
	public function applyUnderLock(House $house, RoomTask $roomTask, Task $task, bool $sleeping, ?string $wakeOn, \DateTimeZone $timeZone, \DateTime $now, ?array $histories = null): bool {
		$today = WindowService::todayKey($now, $timeZone);
		$asleep = $roomTask->getSleepingSince() !== null;
		if ($sleeping) {
			if ($asleep && $roomTask->getWakeOn()?->format('Y-m-d') === $wakeOn) {
				return false;
			}
			$this->assertWakeDate($wakeOn, $today);
			$this->cycleSleep->sleepUnderLock($house, $roomTask, $task, $wakeOn, $timeZone, $now, $histories);
			return true;
		}
		if ($asleep) {
			$this->cycleSleep->wakeUnderLock($roomTask, $task, $today, $timeZone, $now, $histories);
		}
		return $asleep;
	}

	/**
	 * The range of a wake date: a day after today, within a year. A value
	 * that is no day ({@see SleepSwitch} refuses it at the boundary) lies
	 * in no range.
	 *
	 * @throws \InvalidArgumentException
	 */
	private function assertWakeDate(?string $wakeOn, string $today): void {
		if ($wakeOn === null) {
			return;
		}
		if (!$this->calendar->isRealDay($wakeOn) || $wakeOn <= $today || RecurrenceCalculator::daysBetween($today, $wakeOn) > self::WAKE_RANGE_DAYS) {
			throw new \InvalidArgumentException($this->l10n->t('The wake date must lie after today and within one year'));
		}
	}
}
