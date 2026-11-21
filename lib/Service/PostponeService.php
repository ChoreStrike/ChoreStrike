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
use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Exception\ConflictException;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Exception\NotFoundException;

/**
 * The postpone command (CS-PLAN-091 WP-B3, ADR-021 decision 6).
 *
 * Every member who may see a task, children included, moves its current
 * window by half the flex days, rounded up, up to three times per cycle.
 * Nobody is asked: the recent activity shows the postponement, read from
 * this command's receipt. {@see CycleService::postponeUnderLock()} owns the
 * rule; this service owns the receipt, the lock order and the access gate.
 */
class PostponeService {
	/** The receipt action; the recent activity reads postponements by it. */
	public const ACTION = 'room_task.postpone';

	public function __construct(
		private RoomTaskCommand $command,
		private CycleService $cycleService,
		private TaskMapper $taskMapper,
		private HouseMapper $houseMapper,
		private CalendarService $calendar,
	) {
	}

	/**
	 * @return array{roomTask: RoomTask, task: \OCA\ChoreStrike\Db\Task, assignees: string[], cycle: ?array<string, mixed>, timeZone: \DateTimeZone, replay: bool}
	 * @throws NotFoundException unknown or invisible room task
	 * @throws ForbiddenException the actor left the house
	 * @throws ConflictException `STALE_TARGET`, `WINDOW_ENDED`, `NOT_POSTPONABLE`, or an inactive task
	 */
	public function postpone(int $houseId, HouseUser $actor, int $roomTaskId, string $commandId, string $cycleKey, int $expectedVersion): array {
		return $this->command->run(
			$houseId,
			$actor,
			$roomTaskId,
			$commandId,
			self::ACTION,
			['cycleKey' => $cycleKey, 'expectedVersion' => $expectedVersion],
			function () use ($houseId, $roomTaskId, $cycleKey, $expectedVersion): array {
				$roomTask = $this->command->lockTarget($roomTaskId, null);
				$cycle = $this->cycleService->postponeUnderLock(
					$roomTask,
					$this->taskMapper->find($roomTask->getTaskId()),
					$cycleKey,
					$expectedVersion,
					$this->calendar->getTimeZone($this->houseMapper->find($houseId)),
					new \DateTime()
				);
				return ['roomTaskId' => $roomTaskId, 'cycleKey' => $cycle->key, 'postponeCount' => $cycle->postponeCount];
			},
			false,
		);
	}
}
