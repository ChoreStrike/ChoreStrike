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
 * Mapper for HouseUser entity
 */
class HouseUserMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'chorestrike_house_users', HouseUser::class);
	}

	/**
	 * Find house user by ID
	 *
	 * @param int $id The house user ID
	 * @throws DoesNotExistException
	 */
	public function find(int $id): HouseUser {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $this->findEntity($qb);
	}

	/**
	 * Find a house user by ID and acquire an exclusive row lock.
	 *
	 * Closes the read-modify-write race where two concurrent same-user
	 * completions each read the coins/streak row and the second update
	 * clobbers the first (CR-SVC-04). The lock must never write pre-read
	 * data back: the previous implementation UPDATE-d contribution_score to
	 * the value read BEFORE the lock was held, which clobbered a concurrent
	 * winner's committed increment — re-introducing the very race.
	 *
	 * - MySQL/MariaDB/PostgreSQL: a true locking read (SELECT … FOR UPDATE)
	 *   acquires the exclusive row lock AND returns the latest committed
	 *   state in one statement. A read-then-lock UPDATE is not enough:
	 *   Nextcloud sessions run at READ COMMITTED, so a concurrent winner
	 *   committing between the pre-read and the lock write is clobbered by
	 *   the stale write (and under REPEATABLE READ a plain re-read after a
	 *   no-op UPDATE can additionally be snapshot-stale).
	 * - SQLite: no FOR UPDATE support. A no-op self-assignment UPDATE
	 *   (writes the column's own current value — never pre-read data)
	 *   acquires the database-wide write lock and serializes writers; the
	 *   re-read afterwards sees the latest committed state.
	 *
	 * Must be called inside an open transaction.
	 *
	 * @param int $id The house user ID
	 * @throws DoesNotExistException
	 */
	public function findForUpdate(int $id): HouseUser {
		if ($this->db->getDatabaseProvider() === IDBConnection::PLATFORM_SQLITE) {
			$qb = $this->db->getQueryBuilder();
			$qb->update($this->getTableName())
				->set('contribution_minor', $qb->createFunction('contribution_minor'))
				->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
			$qb->executeStatement();

			return $this->find($id);
		}

		$sql = 'SELECT * FROM *PREFIX*' . $this->getTableName() . ' WHERE id = :id FOR UPDATE';
		$result = $this->db->executeQuery($sql, ['id' => $id], ['id' => IQueryBuilder::PARAM_INT]);
		$row = $result->fetch();
		$result->closeCursor();

		if ($row === false) {
			throw new DoesNotExistException('HouseUser with id ' . $id . ' does not exist');
		}

		return $this->mapRowToEntity($row);
	}

	/**
	 * Find all users in a house
	 *
	 * @param int $houseId The house ID
	 * @return HouseUser[]
	 */
	public function findByHouseId(int $houseId): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
			->orderBy('contribution_minor', 'DESC');

		return $this->findEntities($qb);
	}

	/**
	 * Find house user by Nextcloud user ID and house ID
	 *
	 * @param int $houseId The house ID
	 * @param string $nextcloudUserId The Nextcloud user ID
	 * @throws DoesNotExistException
	 */
	public function findByNextcloudUserId(int $houseId, string $nextcloudUserId): HouseUser {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('nextcloud_user_id', $qb->createNamedParameter($nextcloudUserId, IQueryBuilder::PARAM_STR)));

		return $this->findEntity($qb);
	}

	/**
	 * Create or get house user
	 *
	 * Race-tolerant (CR-053-07): the uniq_house_user constraint rejects a
	 * concurrent duplicate insert; the loser re-fetches and receives the
	 * existing row instead of an unhandled 500. No duplicate rows, first
	 * insert wins.
	 *
	 * @param int $houseId The house ID
	 * @param string $nextcloudUserId The Nextcloud user ID
	 * @param string $displayName The display name
	 * @param string $role The user role
	 */
	public function createOrGet(int $houseId, string $nextcloudUserId, string $displayName, string $role = 'member'): HouseUser {
		try {
			return $this->findByNextcloudUserId($houseId, $nextcloudUserId);
		} catch (DoesNotExistException $e) {
			$houseUser = new HouseUser();
			$houseUser->setHouseId($houseId);
			$houseUser->setNextcloudUserId($nextcloudUserId);
			$houseUser->setDisplayName($displayName);
			$houseUser->setRole($role);
			$houseUser->setJoinedAt(new \DateTime());
			try {
				return $this->insert($houseUser);
			} catch (\OCP\DB\Exception $insertException) {
				// Expected race-loser case (two concurrent createOrGet calls
				// for the same house+user): re-fetch the winner's row. Any
				// other DB error must propagate.
				if ($insertException->getReason() !== \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
					throw $insertException;
				}
				return $this->findByNextcloudUserId($houseId, $nextcloudUserId);
			}
		}
	}

	/**
	 * Find house user by house ID and Nextcloud user ID
	 * Returns null if not found (does not throw exception)
	 *
	 * @param int $houseId The house ID
	 * @param string $nextcloudUserId The Nextcloud user ID
	 * @return HouseUser|null
	 */
	public function findByHouseAndUser(int $houseId, string $nextcloudUserId): ?HouseUser {
		try {
			return $this->findByNextcloudUserId($houseId, $nextcloudUserId);
		} catch (DoesNotExistException $e) {
			return null;
		}
	}

	/**
	 * Find house user by house ID and Nextcloud user ID (alias for findByHouseAndUser)
	 * Returns null if not found
	 *
	 * @param int $houseId The house ID
	 * @param string $nextcloudUserId The Nextcloud user ID
	 * @return HouseUser|null
	 */
	public function findByHouseIdAndNextcloudUserId(int $houseId, string $nextcloudUserId): ?HouseUser {
		return $this->findByHouseAndUser($houseId, $nextcloudUserId);
	}

	/**
	 * Find house users by house ID and multiple Nextcloud user IDs in one query.
	 *
	 * Batch sibling of findByHouseIdAndNextcloudUserId (CS-PLAN-036): an
	 * IN-clause lookup returning a map nextcloud_user_id → HouseUser.
	 * Users without a membership in this house are simply absent from the
	 * map — callers treat a missing key as "not found" instead of running
	 * N per-row lookups (leaderboard enrichment).
	 *
	 * @param int $houseId The house ID
	 * @param string[] $ncUserIds The Nextcloud user IDs
	 * @return array<string, HouseUser> Map of Nextcloud user ID → HouseUser
	 */
	/**
	 * The live membership bound to a wallet id (wallet → member reverse
	 * lookup; WP3 contribution-cache re-derivation). Departed memberships
	 * have no bound row for new lookups? They keep the binding — this
	 * read is also the rejoin path's cache source.
	 */
	public function findByWalletId(int $walletId): ?HouseUser {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('wallet_id', $qb->createNamedParameter($walletId, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);

		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException $e) {
			return null;
		}
	}

	public function findByHouseIdAndNextcloudUserIds(int $houseId, array $ncUserIds): array {
		if (empty($ncUserIds)) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->in('nextcloud_user_id', $qb->createNamedParameter($ncUserIds, IQueryBuilder::PARAM_STR_ARRAY)));

		$result = $qb->executeQuery();
		$map = [];
		while ($row = $result->fetch()) {
			$entity = $this->mapRowToEntity($row);
			$map[$entity->getNextcloudUserId()] = $entity;
		}
		$result->closeCursor();

		return $map;
	}

	/**
	 * Count the owner-role members of a house.
	 *
	 * Backs the last-owner role guard (BUG-SEC-002): demoting an owner is only
	 * allowed while at least one other owner remains.
	 *
	 * @param int $houseId The house ID
	 */
	public function countOwnersForHouse(int $houseId): int {
		$qb = $this->db->getQueryBuilder();

		$qb->select($qb->func()->count('id'))
			->from($this->getTableName())
			->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('role', $qb->createNamedParameter('owner', IQueryBuilder::PARAM_STR)));

		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		return $count;
	}

	/**
	 * Find all house users across all houses for background-job iteration.
	 *
	 * Centralising this lookup in the mapper keeps StreakFreezeJob,
	 * StreakRestockJob, and DailyNotificationJob in sync on the canonical
	 * "all house users" set; future filtering (active flag, soft-delete,
	 * tenant scoping) only needs to change here.
	 *
	 * @return HouseUser[]
	 */
	public function findAllForJobs(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName());

		return $this->findEntities($qb);
	}

	/**
	 * Find every membership of one Nextcloud account across all houses
	 * (account erasure; CS-PLAN-071 WP0).
	 *
	 * An indexed equality lookup on `nextcloud_user_id` — erasure must
	 * not iterate the whole `house_users` table to resolve one account.
	 *
	 * @return HouseUser[]
	 */
	public function findAllByNextcloudUserId(string $nextcloudUserId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('nextcloud_user_id', $qb->createNamedParameter($nextcloudUserId, IQueryBuilder::PARAM_STR)));

		return $this->findEntities($qb);
	}
}
