<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Migration;

use Closure;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * CS-PLAN-091 WP-B1 (ADR-021 decision 12): the cutover to derived due
 * windows for a populated installation.
 *
 * Data only. It fills the columns Version1035 added and rewrites nothing
 * else: occurrences, agreements, schedule revisions, completions, streaks
 * and claimed weeks stay as stored (D-091-04).
 *
 * - Every house starts the derived model on the house-local day of the
 *   update (`cycles_from`, D-091-41): stored occurrences can no longer
 *   be fulfilled, so a window that ends on the update day belongs to
 *   the derived model, or work done after the update would count as
 *   missed.
 * - Every member's current and longest streak are copied as the base the
 *   rhythm continues from.
 * - Every room task with a live schedule gets its anchor: a fixed rule
 *   keeps the anchor of its current revision; an after-completion or
 *   one-time task that was never validly completed starts from the
 *   planned day of its earliest open occurrence, else from that anchor.
 *   One with a valid completion anchors on the latest of them and gets
 *   no stored anchor.
 * - A fixed monthly definition gets the day of the month it runs on.
 *
 * Rows that already carry a value are left alone, so the step is safe to
 * repeat. A fresh install applies the schema steps only; new houses and
 * room tasks get these values when they are created.
 */
class Version1036Date20261117000000 extends SimpleMigrationStep {
	public function __construct(
		private IDBConnection $db,
		private ITimeFactory $time,
	) {
	}

	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$houses = $this->startCycles();
		$members = $this->copyStreakBases();
		[$roomTasks, $definitions] = $this->anchorRoomTasks();
		$output->info(sprintf(
			'Derived windows start in %d houses; %d streak bases, %d room-task anchors and %d monthly days set',
			$houses, $members, $roomTasks, $definitions
		));
	}

	/** `cycles_from`: the house-local day of the update (D-091-41). */
	private function startCycles(): int {
		$now = $this->time->getDateTime();
		$updated = 0;
		foreach ($this->rows('chorestrike_houses', ['id', 'timezone'], 'cycles_from') as $house) {
			try {
				$zone = new \DateTimeZone((string)$house['timezone']);
			} catch (\Exception $e) {
				$zone = new \DateTimeZone('UTC');
			}
			$today = \DateTime::createFromInterface($now)->setTimezone($zone)->format('Y-m-d');
			$updated += $this->setWhereNull('chorestrike_houses', (int)$house['id'], 'cycles_from', $today);
		}
		return $updated;
	}

	private function copyStreakBases(): int {
		$qb = $this->db->getQueryBuilder();
		return $qb->update('chorestrike_house_users')
			->set('streak_base', 'current_streak')
			->set('longest_streak_base', 'longest_streak')
			->where($qb->expr()->isNull('streak_base'))
			->executeStatement();
	}

	/**
	 * @return array{0: int, 1: int} Room tasks anchored, monthly definitions set
	 */
	private function anchorRoomTasks(): array {
		$completed = $this->roomTasksWithValidCompletion();
		$earliestOpen = $this->earliestOpenPlannedDay();
		$taskOf = [];
		foreach ($this->rows('chorestrike_room_tasks', ['id', 'task_id']) as $row) {
			$taskOf[(int)$row['id']] = (int)$row['task_id'];
		}

		$anchored = 0;
		$monthDays = [];
		foreach ($this->currentRevisions() as $revision) {
			$roomTaskId = (int)$revision['room_task_id'];
			$anchor = substr((string)$revision['anchor_date'], 0, 10);
			$fixedSequence = $revision['cadence_mode'] === 'fixed' && $revision['lifetime'] !== 'once';
			if ($fixedSequence && $revision['cadence_unit'] === 'month' && isset($taskOf[$roomTaskId])) {
				// The lowest room task of a shared definition decides.
				$monthDays[$taskOf[$roomTaskId]] ??= (int)substr($anchor, 8, 2);
			}
			if (!$fixedSequence && isset($completed[$roomTaskId])) {
				continue;
			}
			if (!$fixedSequence && isset($earliestOpen[$roomTaskId])) {
				$anchor = $earliestOpen[$roomTaskId];
			}
			$anchored += $this->setWhereNull('chorestrike_room_tasks', $roomTaskId, 'anchor_on', $anchor);
		}

		$definitions = 0;
		foreach ($monthDays as $taskId => $day) {
			$definitions += $this->setWhereNull('chorestrike_tasks', $taskId, 'fixed_month_day', $day);
		}
		return [$anchored, $definitions];
	}

	/**
	 * @return list<array<string, mixed>> Live revisions, lowest room task first
	 */
	private function currentRevisions(): array {
		$qb = $this->db->getQueryBuilder();
		$result = $qb->select('room_task_id', 'anchor_date', 'cadence_mode', 'cadence_unit', 'lifetime')
			->from('chorestrike_schedule_revs')
			->where($qb->expr()->isNull('closed_at'))
			->orderBy('room_task_id', 'ASC')
			->addOrderBy('revision', 'DESC')
			->executeQuery();
		$rows = [];
		foreach ($result->fetchAll() as $row) {
			$rows[(int)$row['room_task_id']] ??= $row;
		}
		$result->closeCursor();
		return array_values($rows);
	}

	/**
	 * @return array<int, true> Room task ids with a completion that was not undone
	 */
	private function roomTasksWithValidCompletion(): array {
		$qb = $this->db->getQueryBuilder();
		$result = $qb->selectDistinct('room_task_id')
			->from('chorestrike_completed_tasks')
			->where($qb->expr()->isNull('undone_at'))
			->executeQuery();
		$ids = [];
		foreach ($result->fetchAll() as $row) {
			$ids[(int)$row['room_task_id']] = true;
		}
		$result->closeCursor();
		return $ids;
	}

	/**
	 * An open appointment that a restart replaced is no longer the work
	 * that is due: its successor's day is (the old model's restart).
	 *
	 * @return array<int, string> Room task id to the planned day of its earliest open occurrence
	 */
	private function earliestOpenPlannedDay(): array {
		$replaced = [];
		$qb = $this->db->getQueryBuilder();
		$result = $qb->select('restart_of_id')
			->from('chorestrike_occurrences')
			->where($qb->expr()->isNotNull('restart_of_id'))
			->executeQuery();
		foreach ($result->fetchAll() as $row) {
			$replaced[(int)$row['restart_of_id']] = true;
		}
		$result->closeCursor();

		$qb = $this->db->getQueryBuilder();
		$result = $qb->select('id', 'room_task_id', 'planned_date')
			->from('chorestrike_occurrences')
			->where($qb->expr()->isNull('completion_id'))
			->andWhere($qb->expr()->isNull('covered_by_completion_id'))
			->andWhere($qb->expr()->eq('kind', $qb->createNamedParameter('scheduled')))
			->executeQuery();
		$days = [];
		foreach ($result->fetchAll() as $row) {
			if (isset($replaced[(int)$row['id']])) {
				continue;
			}
			$roomTaskId = (int)$row['room_task_id'];
			$days[$roomTaskId] = min($days[$roomTaskId] ?? '9999-12-31', substr((string)$row['planned_date'], 0, 10));
		}
		$result->closeCursor();
		return $days;
	}

	/**
	 * @param list<string> $columns
	 * @return list<array<string, mixed>>
	 */
	private function rows(string $table, array $columns, ?string $nullColumn = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select(...$columns)->from($table)->orderBy('id', 'ASC');
		if ($nullColumn !== null) {
			$qb->where($qb->expr()->isNull($nullColumn));
		}
		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();
		return $rows;
	}

	private function setWhereNull(string $table, int $id, string $column, string|int $value): int {
		$qb = $this->db->getQueryBuilder();
		$type = is_int($value) ? IQueryBuilder::PARAM_INT : IQueryBuilder::PARAM_STR;
		return $qb->update($table)
			->set($column, $qb->createNamedParameter($value, $type))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull($column))
			->executeStatement();
	}
}
