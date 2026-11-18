<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\CompletedTask;
use OCA\ChoreStrike\Db\Room;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\Task;

/**
 * The locked target of one correction ({@see CompletionAccess::lockTarget()}):
 * the room task and room rows held for the transaction, and the completion
 * and the definition as they read under those locks.
 */
final class CorrectionTarget {
	public function __construct(
		public readonly RoomTask $roomTask,
		public readonly CompletedTask $completion,
		public readonly Room $room,
		public readonly Task $task,
	) {
	}
}
