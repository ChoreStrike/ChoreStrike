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
use OCP\IL10N;

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
		private CommandService $commandService,
		private CycleService $cycleService,
		private RoomTaskReadService $roomTaskReads,
		private RoomTaskAccess $access,
		private RoomTaskMapper $roomTaskMapper,
		private TaskMapper $taskMapper,
		private HouseMapper $houseMapper,
		private CalendarService $calendar,
		private IL10N $l10n,
	) {
	}

	/**
	 * @return array{roomTask: RoomTask, cycle: ?array<string, mixed>, replay: bool}
	 * @throws NotFoundException unknown or invisible room task
	 * @throws ForbiddenException the actor left the house
	 * @throws ConflictException `STALE_TARGET`, `WINDOW_ENDED`, `NOT_POSTPONABLE`, or an inactive task
	 */
	public function postpone(int $houseId, HouseUser $actor, int $roomTaskId, string $commandId, string $cycleKey, int $expectedVersion): array {
		$command = $this->commandService->runCommand(
			$houseId,
			(int)$actor->getId(),
			$commandId,
			self::ACTION,
			['roomTaskId' => $roomTaskId, 'cycleKey' => $cycleKey, 'expectedVersion' => $expectedVersion],
			function () use ($houseId, $roomTaskId, $cycleKey, $expectedVersion): array {
				$roomTask = $this->roomTaskMapper->lockForUpdate($roomTaskId);
				if (!$roomTask->getIsActive()) {
					throw new ConflictException($this->l10n->t('Task is not active'));
				}
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
			// Execution and replay alike, before any answer: a member who
			// left, or lost sight of the task, learns nothing about it.
			function () use ($houseId, $actor, $roomTaskId): void {
				$this->access->visible($houseId, $this->access->liveMember($houseId, $actor), $roomTaskId);
			},
		);

		// The answer is read fresh, for the member as they are now.
		$fresh = $this->access->visible($houseId, $this->access->liveMember($houseId, $actor), $roomTaskId);
		$tasksById = [(int)$fresh['task']->getId() => $fresh['task']];
		return [
			'roomTask' => $fresh['roomTask'],
			'cycle' => $this->roomTaskReads->cycleFacts($houseId, [$fresh['roomTask']], $tasksById)[$roomTaskId]['block'] ?? null,
			'replay' => $command['replay'],
		];
	}
}
