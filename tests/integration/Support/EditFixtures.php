<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Support;

use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\TaskAssigneeMapper;
use OCA\ChoreStrike\Exception\ConflictException;
use OCA\ChoreStrike\Service\CompletionService;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * What the tests of the one form's save read and set up next to
 * {@see CycleFixtures} and {@see TaskEditing}: the rows a save wrote, a
 * second room that shares the definition, and stored states no command
 * produces directly (a private task of somebody, a departed member).
 */
trait EditFixtures {
	protected function owner(array $f): HouseUser {
		return $this->member($f['houseId'], static::OWNER);
	}

	protected function helper(array $f): HouseUser {
		return $this->member($f['houseId'], static::HELPER);
	}

	/** @return array<string, mixed> */
	protected function roomTaskRow(int $roomTaskId): array {
		return $this->rows('chorestrike_room_tasks', 'id', $roomTaskId)[0];
	}

	/** @return array<string, mixed> */
	protected function taskRow(int $taskId): array {
		return $this->rows('chorestrike_tasks', 'id', $taskId)[0];
	}

	/** The definition the room task uses now. */
	protected function taskIdOf(int $roomTaskId): int {
		return (int)$this->roomTaskRow($roomTaskId)['task_id'];
	}

	/** @return string[] */
	protected function assigneesOf(int $roomTaskId): array {
		return \OC::$server->get(TaskAssigneeMapper::class)->findUserIdsByRoomTask($roomTaskId);
	}

	/** @return list<string> The definition's sub-tasks in their order */
	protected function labels(int $taskId): array {
		return array_column($this->items($taskId), 'label');
	}

	/** @return list<array<string, mixed>> */
	protected function items(int $taskId): array {
		$qb = $this->db->getQueryBuilder();
		$result = $qb->select('id', 'label')->from('chorestrike_chk_items')
			->where($qb->expr()->eq('task_id', $qb->createNamedParameter($taskId, IQueryBuilder::PARAM_INT)))
			->orderBy('sort_order', 'ASC')->addOrderBy('id', 'ASC')
			->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();
		return $rows;
	}

	protected function complete(array $f, ?string $userId = null): int {
		return (int)$this->completeOccurrence(\OC::$server->get(CompletionService::class), $f['houseId'], $userId ?? static::OWNER, $f['roomTaskId'])['completionId'];
	}

	/** The reason of the conflict a save answers. */
	protected function conflictOf(callable $save): string {
		try {
			$save();
		} catch (ConflictException $e) {
			return $e->getReason();
		}
		self::fail('the save must be refused with a conflict');
	}

	/**
	 * A second room of the fixture house holding the same definition.
	 *
	 * @return int Its room task
	 */
	protected function shareWithASecondRoom(array $f, ?string $assignee = null): int {
		return $this->assignTaskToRoom($f['taskId'], $this->anotherRoom($f, 'Second kitchen'), $assignee ?? static::OWNER);
	}

	/** Another room on the floor of the fixture room. */
	protected function anotherRoom(array $f, string $name): int {
		$floorId = (int)$this->rows('chorestrike_rooms', 'id', $f['roomId'])[0]['floor_id'];
		return $this->createRoom($f['houseId'], $floorId, $name);
	}

	/** The fixture definition as a private task of the house owner. */
	protected function makePrivate(array $f, ?string $ownerUserId = null): void {
		$this->setColumn('chorestrike_tasks', $f['taskId'], 'visibility_type', 'private');
		$this->setColumn('chorestrike_tasks', $f['taskId'], 'owner_user_id', $ownerUserId ?? static::OWNER);
	}

	protected function setColumn(string $table, int $id, string $column, mixed $value): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($table)
			->set($column, $qb->createNamedParameter($value))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}
}
