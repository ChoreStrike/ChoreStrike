<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\Task;

/**
 * Internal locked-aggregate context of one agreement command
 * (CS-PLAN-073 WP2). Populated by {@see AgreementService} inside the
 * receipt transaction after the room-task/definition rows are locked;
 * the closures of the amend/catch-up/restart commands consume it.
 */
class AgreementCommandContext {
	public int $houseId = 0;
	public RoomTask $roomTask;
	public Task $task;
	public House $house;
	public ?int $actorHouseUserId = null;
	public \DateTime $now;

	/** Receipt fields mirrored into the context by runOccurrenceCommand. */
	public string $occurrenceId = '';
	public int $expectedVersion = 0;
	public string $selectionToken = '';
}
