<?php

declare(strict_types=1);

namespace OCA\ChoreWars\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<RoomTask>
 */
class RoomTaskMapper extends QBMapper {

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'chorewars_room_tasks', RoomTask::class);
	}

	/**
	 * Find room task by ID
	 *
	 * @param int $id The room task ID
	 * @throws DoesNotExistException if not found
	 */
	public function find(int $id): RoomTask {
		return parent::find($id);
	}

	/**
	 * Get all room tasks for a room
	 *
	 * @param int $roomId The room ID
	 * @param bool $activeOnly Whether to return only active tasks
	 * @return RoomTask[]
	 */
	public function findByRoomId(int $roomId, bool $activeOnly = true): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('room_id', $qb->createNamedParameter($roomId, IQueryBuilder::PARAM_INT)));

		if ($activeOnly) {
			$qb->andWhere($qb->expr()->eq('is_active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));
		}

		$qb->orderBy('next_due', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * Get all room tasks for a user
	 *
	 * @param string $userId The user ID
	 * @param bool $activeOnly Whether to return only active tasks
	 * @return RoomTask[]
	 */
	public function findByUserId(string $userId, bool $activeOnly = true): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('assigned_to_user_id', $qb->createNamedParameter($userId)));

		if ($activeOnly) {
			$qb->andWhere($qb->expr()->eq('is_active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));
		}

		$qb->orderBy('next_due', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * Get overdue tasks
	 *
	 * @param int|null $roomId Optional room ID to filter by
	 * @return RoomTask[]
	 */
	public function findOverdue(?int $roomId = null): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->lt('next_due', $qb->createNamedParameter(new \DateTime(), IQueryBuilder::PARAM_DATE)))
			->andWhere($qb->expr()->eq('is_active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));

		if ($roomId !== null) {
			$qb->andWhere($qb->expr()->eq('room_id', $qb->createNamedParameter($roomId, IQueryBuilder::PARAM_INT)));
		}

		$qb->orderBy('next_due', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * Get tasks due today
	 *
	 * @param int|null $roomId Optional room ID to filter by
	 * @return RoomTask[]
	 */
	public function findDueToday(?int $roomId = null): array {
		$today = new \DateTime();
		$today->setTime(0, 0, 0);
		$tomorrow = clone $today;
		$tomorrow->add(new \DateInterval('P1D'));

		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->gte('next_due', $qb->createNamedParameter($today, IQueryBuilder::PARAM_DATE)))
			->andWhere($qb->expr()->lt('next_due', $qb->createNamedParameter($tomorrow, IQueryBuilder::PARAM_DATE)))
			->andWhere($qb->expr()->eq('is_active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));

		if ($roomId !== null) {
			$qb->andWhere($qb->expr()->eq('room_id', $qb->createNamedParameter($roomId, IQueryBuilder::PARAM_INT)));
		}

		$qb->orderBy('next_due', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * Get tasks due within the next N days
	 *
	 * @param int $days Number of days
	 * @param int|null $roomId Optional room ID to filter by
	 * @return RoomTask[]
	 */
	public function findDueWithinDays(int $days, ?int $roomId = null): array {
		$today = new \DateTime();
		$future = clone $today;
		$future->add(new \DateInterval("P{$days}D"));

		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->lte('next_due', $qb->createNamedParameter($future, IQueryBuilder::PARAM_DATE)))
			->andWhere($qb->expr()->gte('next_due', $qb->createNamedParameter($today, IQueryBuilder::PARAM_DATE)))
			->andWhere($qb->expr()->eq('is_active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));

		if ($roomId !== null) {
			$qb->andWhere($qb->expr()->eq('room_id', $qb->createNamedParameter($roomId, IQueryBuilder::PARAM_INT)));
		}

		$qb->orderBy('next_due', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * Create a new room task
	 *
	 * @param int $roomId The room ID
	 * @param int $taskId The task ID
	 * @param \DateTime $nextDue The next due date
	 * @param string|null $assignedToUserId Optional assigned user ID
	 */
	public function create(int $roomId, int $taskId, \DateTime $nextDue, ?string $assignedToUserId = null): RoomTask {
		$roomTask = new RoomTask();
		$roomTask->setRoomId($roomId);
		$roomTask->setTaskId($taskId);
		$roomTask->setNextDue($nextDue);
		$roomTask->setAssignedToUserId($assignedToUserId);
		$roomTask->setIsActive(true);
		$roomTask->setCreatedAt(new \DateTime());

		return $this->insert($roomTask);
	}

	/**
	 * Assign task to user
	 *
	 * @param int $roomTaskId The room task ID
	 * @param string $userId The user ID
	 */
	public function assignToUser(int $roomTaskId, string $userId): void {
		$qb = $this->db->getQueryBuilder();

		$qb->update($this->getTableName())
			->set('assigned_to_user_id', $qb->createNamedParameter($userId))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)));

		$qb->executeStatement();
	}

	/**
	 * Deactivate task
	 *
	 * @param int $roomTaskId The room task ID
	 */
	public function deactivate(int $roomTaskId): void {
		$qb = $this->db->getQueryBuilder();

		$qb->update($this->getTableName())
			->set('is_active', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)));

		$qb->executeStatement();
	}

	/**
	 * Reactivate task
	 *
	 * @param int $roomTaskId The room task ID
	 */
	public function reactivate(int $roomTaskId): void {
		$qb = $this->db->getQueryBuilder();

		$qb->update($this->getTableName())
			->set('is_active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)));

		$qb->executeStatement();
	}

	/**
	 * Update next due date
	 *
	 * @param int $roomTaskId The room task ID
	 * @param \DateTime $nextDue The new due date
	 */
	public function updateNextDue(int $roomTaskId, \DateTime $nextDue): void {
		$qb = $this->db->getQueryBuilder();

		$qb->update($this->getTableName())
			->set('next_due', $qb->createNamedParameter($nextDue, IQueryBuilder::PARAM_DATE))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)));

		$qb->executeStatement();
	}

	/**
	 * Check if room already has this task
	 *
	 * @param int $roomId The room ID
	 * @param int $taskId The task ID
	 * @return bool
	 */
	public function exists(int $roomId, int $taskId): bool {
		$qb = $this->db->getQueryBuilder();

		$qb->select('id')
			->from($this->getTableName())
			->where($qb->expr()->eq('room_id', $qb->createNamedParameter($roomId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('task_id', $qb->createNamedParameter($taskId, IQueryBuilder::PARAM_INT)));

		$result = $qb->executeQuery();
		return $result->fetchOne() !== false;
	}
}
