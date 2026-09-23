<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<ScheduleRevision>
 */
class ScheduleRevisionMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'chorestrike_schedule_revs', ScheduleRevision::class);
	}

	/**
	 * All revisions of a room task in revision order (oldest first).
	 *
	 * @return ScheduleRevision[]
	 */
	public function findByRoomTask(int $roomTaskId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('room_task_id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)))
			->orderBy('revision', 'ASC');
		return $this->findEntities($qb);
	}

	/**
	 * All revisions of a set of room tasks (one batched query).
	 *
	 * @param int[] $roomTaskIds
	 * @return ScheduleRevision[]
	 */
	public function findByRoomTasks(array $roomTaskIds): array {
		$roomTaskIds = array_values(array_map('intval', $roomTaskIds));
		if ($roomTaskIds === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->in('room_task_id', $qb->createNamedParameter($roomTaskIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->orderBy('room_task_id', 'ASC')
			->addOrderBy('revision', 'ASC');
		return $this->findEntities($qb);
	}

	/**
	 * The current (not superseded) revision of a room task.
	 *
	 * @throws DoesNotExistException when the room task has no live revision
	 */
	public function findCurrentByRoomTask(int $roomTaskId): ScheduleRevision {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('room_task_id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('closed_at'))
			->orderBy('revision', 'DESC')
			->setMaxResults(1);
		return $this->findEntity($qb);
	}

	/**
	 * The latest revision BEFORE the given revision number of a room task
	 * (its frozen predecessor), or null for revision 1.
	 */
	public function findPredecessor(int $roomTaskId, int $revisionNumber): ?ScheduleRevision {
		if ($revisionNumber <= 1) {
			return null;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('room_task_id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->lt('revision', $qb->createNamedParameter($revisionNumber, IQueryBuilder::PARAM_INT)))
			->orderBy('revision', 'DESC')
			->setMaxResults(1);
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException $e) {
			return null;
		}
	}

	/**
	 * Create the first revision of a room task from already-validated rule
	 * inputs. Called inside the owning creation transaction.
	 */
	public function createRevision(
		int $roomTaskId,
		int $revision,
		\DateTime $effectiveFrom,
		\DateTime $agreedThrough,
		string $cadenceMode,
		string $cadenceUnit,
		int $cadenceInterval,
		\DateTime $anchorDate,
		?string $weekdays,
		string $lifetime,
		?\DateTime $activeFrom,
		?\DateTime $activeUntil,
		int $earlyWindowDays,
		int $lateWindowDays,
		string $timezone,
		int $energy,
		bool $isPublic,
		bool $isVoluntary,
		?array $defaultMembers,
	): ScheduleRevision {
		$entity = new ScheduleRevision();
		$entity->setRoomTaskId($roomTaskId);
		$entity->setRevision($revision);
		$entity->setEffectiveFrom($effectiveFrom);
		$entity->setAgreedThrough($agreedThrough);
		$entity->setCadenceMode($cadenceMode);
		$entity->setCadenceUnit($cadenceUnit);
		$entity->setCadenceInterval($cadenceInterval);
		$entity->setAnchorDate($anchorDate);
		$entity->setWeekdays($weekdays);
		$entity->setLifetime($lifetime);
		$entity->setActiveFrom($activeFrom);
		$entity->setActiveUntil($activeUntil);
		$entity->setEarlyWindowDays($earlyWindowDays);
		$entity->setLateWindowDays($lateWindowDays);
		$entity->setTimezone($timezone);
		$entity->setEnergy($energy);
		$entity->setIsPublic($isPublic);
		$entity->setIsVoluntary($isVoluntary);
		$entity->setDefaultMembers($defaultMembers);
		$entity->setCreatedAt(new \DateTime());
		return $this->insert($entity);
	}

	/**
	 * Advance the revision's agreement frontier (monotonic: never backwards).
	 * Called inside the owning house-first transaction.
	 */
	public function advanceFrontier(ScheduleRevision $revision, \DateTime $agreedThrough): void {
		if ($revision->getAgreedThrough() !== null && $agreedThrough <= $revision->getAgreedThrough()) {
			return;
		}
		$revision->setAgreedThrough($agreedThrough);
		$this->update($revision);
	}

	/**
	 * Close a superseded revision. Called inside the owning transaction.
	 */
	public function close(ScheduleRevision $revision, \DateTime $closedAt): void {
		$revision->setClosedAt($closedAt);
		$this->update($revision);
	}

	/**
	 * Anonymize a wallet's user references inside every default-membership
	 * snapshot of this house (account erasure): the numeric wallet fact
	 * stays (non-reidentifiable), the account binding is stripped.
	 */
	public function anonymizeDefaultMembers(int $houseId, int $walletId): void {
		$roomTaskIds = $this->roomTaskIdsOfHouse($houseId);
		if ($roomTaskIds === []) {
			return;
		}
		foreach ($this->findByRoomTasks($roomTaskIds) as $revision) {
			$members = $revision->getDefaultMembers();
			if (!is_array($members)) {
				continue;
			}
			$changed = false;
			foreach ($members as $index => $member) {
				if (is_array($member) && (int)($member['walletId'] ?? 0) === $walletId) {
					$members[$index]['userId'] = null;
					$changed = true;
				}
			}
			if ($changed) {
				$revision->setDefaultMembers($members);
				$this->update($revision);
			}
		}
	}

	/**
	 * All revisions of a house's room tasks in one query (batched read
	 * model for the occurrence derivation; ordered by room task,
	 * revision).
	 *
	 * @return ScheduleRevision[]
	 */
	public function findByHouseId(int $houseId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('s.*')
			->from($this->getTableName(), 's')
			->innerJoin('s', 'chorestrike_room_tasks', 'rt', 's.room_task_id = rt.id')
			->innerJoin('rt', 'chorestrike_rooms', 'r', 'rt.room_id = r.id')
			->innerJoin('r', 'chorestrike_floors', 'f', 'r.floor_id = f.id')
			->where($qb->expr()->eq('f.house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
			->orderBy('s.room_task_id', 'ASC')
			->addOrderBy('s.revision', 'ASC');
		return $this->findEntities($qb);
	}

	/**
	 * @return int[]
	 */
	private function roomTaskIdsOfHouse(int $houseId): array {
		$qb = $this->db->getQueryBuilder();
		$rows = $qb->select('rt.id')
			->from('chorestrike_room_tasks', 'rt')
			->innerJoin('rt', 'chorestrike_rooms', 'r', 'rt.room_id = r.id')
			->innerJoin('r', 'chorestrike_floors', 'f', 'r.floor_id = f.id')
			->where($qb->expr()->eq('f.house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
			->executeQuery()
			->fetchAll();
		return array_map(static fn (array $row): int => (int)$row['id'], $rows);
	}
}
