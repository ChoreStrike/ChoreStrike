<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\Task;

/**
 * The room tasks a viewer may see, with the definitions that were read to
 * decide it ({@see RoomTaskReadService}). A caller that derives the cycles
 * next hands the definitions on instead of reading them again.
 */
final class RoomTaskList {
	/**
	 * @param RoomTask[] $roomTasks Visible room tasks, in the order of the read
	 * @param array<int, Task> $tasksById The definitions of the whole read, by id
	 */
	public function __construct(
		public readonly array $roomTasks,
		public readonly array $tasksById,
	) {
	}
}
