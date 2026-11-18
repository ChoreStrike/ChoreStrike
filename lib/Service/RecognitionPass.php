<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\Pause;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\Task;

/**
 * What one pass over the recognition facts reads once and then reuses for
 * every week and member it evaluates (CS-PLAN-091 RF-091-03): the active
 * room tasks with their definitions, completion inputs and responsible
 * wallets, and the recognized absences.
 *
 * A pass belongs to one evaluation — a job run, one request's reads. It is
 * a snapshot: code that writes work, rules or absences evaluates without
 * one, or begins a new one afterwards.
 */
class RecognitionPass {
	/**
	 * @param Pause[] $absences Recognized absences of the house
	 * @param RoomTask[] $roomTasks Active room tasks of the house
	 * @param array<int, Task> $tasks Their definitions by id
	 * @param array<int, CycleHistory> $histories Their completion inputs by room task id
	 * @param array<int, list<int>> $responsible Wallet ids of their assignees by room task id
	 */
	public function __construct(
		public readonly array $absences,
		public readonly array $roomTasks,
		public readonly array $tasks,
		public readonly array $histories,
		public readonly array $responsible,
	) {
	}
}
