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
 * @template-extends QBMapper<House>
 */
class HouseMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'chorestrike_houses', House::class);
	}

	/**
	 * Find house by Nextcloud instance ID
	 *
	 * @param string $instanceId The Nextcloud instance ID
	 * @throws DoesNotExistException if not found
	 */
	public function findByInstanceId(string $instanceId): House {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('nextcloud_instance_id', $qb->createNamedParameter($instanceId)));

		return $this->findEntity($qb);
	}

	/**
	 * Find house by ID
	 *
	 * @param int $id The house ID
	 * @throws DoesNotExistException if not found
	 */
	public function find(int $id): House {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $this->findEntity($qb);
	}

	/**
	 * Create or update house for instance
	 *
	 * @param string $instanceId The Nextcloud instance ID
	 * @param string $name The house name
	 */
	public function createForInstance(string $instanceId, string $name): House {
		try {
			$house = $this->findByInstanceId($instanceId);
			$house->setName($name);
			$house->touch();
			return $this->update($house);
		} catch (DoesNotExistException $e) {
			$house = new House();
			$house->setNextcloudInstanceId($instanceId);
			$house->setName($name);
			$house->setHouseHealth(100);
			$now = new \DateTime();
			$house->setCreatedAt($now);
			$house->setUpdatedAt($now);
			return $this->insert($house);
		}
	}

	/**
	 * Update house health
	 *
	 * @param int $houseId The house ID
	 * @param int $health The new health value (0-100)
	 */
	public function updateHealth(int $houseId, int $health): void {
		$health = max(0, min(100, $health)); // Clamp between 0 and 100

		$qb = $this->db->getQueryBuilder();

		$qb->update($this->getTableName())
			->set('house_health', $qb->createNamedParameter($health, IQueryBuilder::PARAM_INT))
			->set('updated_at', $qb->createNamedParameter(new \DateTime(), IQueryBuilder::PARAM_DATE))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)));

		$qb->executeStatement();
	}

	/**
	 * Atomically claim the weekly-goal-reached notification for a house+week.
	 *
	 * Issues a conditional UPDATE that sets `weekly_goal_notified_week` only
	 * when the stored value differs from the supplied week. Returns true if
	 * the row was updated (i.e. this caller is the first to claim dispatch
	 * for the given week), false if another caller already claimed it.
	 *
	 * The single-statement conditional UPDATE is atomic on its own —
	 * InnoDB/PostgreSQL serialise concurrent UPDATEs to the same row via
	 * the storage-engine row lock, and SQLite serialises all writes at the
	 * DB level. No surrounding transaction or explicit row lock is needed.
	 * Two concurrent claimants for the same house+week can never both see
	 * `=== 1` because only the first UPDATE matches the WHERE clause; the
	 * second sees the column already set to the supplied week and matches
	 * neither `IS NULL` nor `!= weekStart`, so its UPDATE affects 0 rows.
	 *
	 * @param int $houseId The house ID
	 * @param string $weekStart ISO week-start (Y-m-d, Monday)
	 * @return bool True if this caller claimed dispatch, false if already claimed
	 */
	public function claimWeeklyGoalNotification(int $houseId, string $weekStart): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('weekly_goal_notified_week', $qb->createNamedParameter($weekStart, IQueryBuilder::PARAM_STR))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->orX(
				$qb->expr()->isNull('weekly_goal_notified_week'),
				$qb->expr()->neq('weekly_goal_notified_week', $qb->createNamedParameter($weekStart, IQueryBuilder::PARAM_STR))
			));

		return $qb->executeStatement() === 1;
	}
}
