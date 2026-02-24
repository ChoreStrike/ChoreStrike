<?php

declare(strict_types=1);

namespace OCA\ChoreWars\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for Medal entity
 */
class MedalMapper extends QBMapper {

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'chorewars_medals', Medal::class);
	}

	/**
	 * Find medal by ID
	 *
	 * @param int $id The medal ID
	 * @throws DoesNotExistException
	 */
	public function find(int $id): Medal {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $this->findEntity($qb);
	}

	/**
	 * Find all medals for a user
	 *
	 * @param int $userId The house user ID
	 * @return Medal[]
	 */
	public function findByUserId(int $userId): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_INT)))
			->orderBy('awarded_at', 'DESC');

		return $this->findEntities($qb);
	}

	/**
	 * Find medals for a specific week
	 *
	 * @param string $weekStart Week start date (YYYY-MM-DD)
	 * @return Medal[]
	 */
	public function findByWeek(string $weekStart): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('week_start', $qb->createNamedParameter($weekStart, IQueryBuilder::PARAM_STR)))
			->orderBy('awarded_at', 'DESC');

		return $this->findEntities($qb);
	}

	/**
	 * Find medals for a user in a specific week
	 *
	 * @param int $userId The house user ID
	 * @param string $weekStart Week start date (YYYY-MM-DD)
	 * @return Medal[]
	 */
	public function findByUserAndWeek(int $userId, string $weekStart): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('week_start', $qb->createNamedParameter($weekStart, IQueryBuilder::PARAM_STR)))
			->orderBy('awarded_at', 'DESC');

		return $this->findEntities($qb);
	}

	/**
	 * Find specific medal type for user in week
	 *
	 * @param int $userId The house user ID
	 * @param string $medalType The medal type
	 * @param string $weekStart Week start date (YYYY-MM-DD)
	 * @throws DoesNotExistException
	 */
	public function findByUserTypeAndWeek(int $userId, string $medalType, string $weekStart): Medal {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('medal_type', $qb->createNamedParameter($medalType, IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->eq('week_start', $qb->createNamedParameter($weekStart, IQueryBuilder::PARAM_STR)));

		return $this->findEntity($qb);
	}

	/**
	 * Get medal count by type for a user
	 *
	 * @param int $userId The house user ID
	 */
	public function getMedalCountsByType(int $userId): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('medal_type')
			->addSelect('COUNT(*) as count')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_INT)))
			->groupBy('medal_type');

		$result = $qb->executeQuery();
		$counts = [];
		
		while ($row = $result->fetch()) {
			$counts[$row['medal_type']] = (int)$row['count'];
		}
		
		$result->closeCursor();
		return $counts;
	}
}
