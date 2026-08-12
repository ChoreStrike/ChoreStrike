<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for AvailabilitySettings entity
 */
class AvailabilitySettingsMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'chorestrike_availability', AvailabilitySettings::class);
	}

	/**
	 * Find settings by house and user
	 */
	public function findByHouseAndUser(int $houseId, string $userId): ?AvailabilitySettings {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)));

		try {
			return $this->findEntity($qb);
		} catch (\Exception $e) {
			return null;
		}
	}

	/**
	 * Get or create settings for user
	 */
	public function getOrCreate(int $houseId, string $userId): AvailabilitySettings {
		$settings = $this->findByHouseAndUser($houseId, $userId);

		if ($settings === null) {
			$settings = new AvailabilitySettings();
			$settings->setHouseId($houseId);
			$settings->setUserId($userId);
			$settings->setUpdatedAt(new \DateTime());
			return $this->insert($settings);
		}

		return $settings;
	}

	/**
	 * Find all settings for a house
	 *
	 * @return AvailabilitySettings[]
	 */
	public function findByHouse(int $houseId): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)));

		return $this->findEntities($qb);
	}

	/**
	 * Find users with specific planning mode
	 *
	 * @return AvailabilitySettings[]
	 */
	public function findByPlanningMode(int $houseId, string $mode): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('planning_mode', $qb->createNamedParameter($mode, IQueryBuilder::PARAM_STR)));

		return $this->findEntities($qb);
	}

	/**
	 * Get total available energy for house
	 */
	public function getTotalHouseEnergy(int $houseId): int {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)));

		$settings = $this->findEntities($qb);
		$total = 0;

		foreach ($settings as $setting) {
			$total += $setting->getTotalWeeklyEnergy();
		}

		return $total;
	}

	/**
	 * Delete settings for user
	 */
	public function deleteForUser(int $houseId, string $userId): void {
		$qb = $this->db->getQueryBuilder();

		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)));

		$qb->executeStatement();
	}
}
