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
class HouseUserMapper extends QBMapper
{
    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, 'chorestrike_house_users', HouseUser::class);
    }

    /**
     * Find house user by ID
     *
     * @param int $id The house user ID
     * @throws DoesNotExistException
     */
    public function find(int $id): HouseUser
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        return $this->findEntity($qb);
    }

    /**
     * Find a house user by ID and acquire an exclusive row lock.
     *
     * Mirrors {@see RoomTaskMapper::lockForUpdate()}: a no-op UPDATE acquires
     * an exclusive row lock on locking engines (InnoDB/PostgreSQL) and
     * serializes via the write lock on SQLite. This closes the read-modify-
     * write race where two concurrent same-user completions each read the
     * coins/streak row and the second update clobbers the first (CR-SVC-04).
     *
     * Must be called inside an open transaction. Re-reads the row after the
     * lock is granted so the caller builds on the freshest committed state.
     *
     * @param int $id The house user ID
     * @throws DoesNotExistException
     */
    public function findForUpdate(int $id): HouseUser
    {
        $houseUser = $this->find($id);

        $qb = $this->db->getQueryBuilder();
        $qb->update($this->getTableName())
            ->set('contribution_score', $qb->createNamedParameter($houseUser->getContributionScore(), IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();

        return $this->find($id);
    }

    /**
     * Find all users in a house
     *
     * @param int $houseId The house ID
     * @return HouseUser[]
     */
    public function findByHouseId(int $houseId): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
            ->orderBy('contribution_score', 'DESC');

        return $this->findEntities($qb);
    }

    /**
     * Find house user by Nextcloud user ID and house ID
     *
     * @param int $houseId The house ID
     * @param string $nextcloudUserId The Nextcloud user ID
     * @throws DoesNotExistException
     */
    public function findByNextcloudUserId(int $houseId, string $nextcloudUserId): HouseUser
    {
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
     * @param int $houseId The house ID
     * @param string $nextcloudUserId The Nextcloud user ID
     * @param string $displayName The display name
     * @param string $role The user role
     */
    public function createOrGet(int $houseId, string $nextcloudUserId, string $displayName, string $role = 'member'): HouseUser
    {
        try {
            return $this->findByNextcloudUserId($houseId, $nextcloudUserId);
        } catch (DoesNotExistException $e) {
            $houseUser = new HouseUser();
            $houseUser->setHouseId($houseId);
            $houseUser->setNextcloudUserId($nextcloudUserId);
            $houseUser->setDisplayName($displayName);
            $houseUser->setRole($role);
            $houseUser->setJoinedAt(new \DateTime());
            return $this->insert($houseUser);
        }
    }

    /**
     * Get leaderboard for a house
     *
     * @param int $houseId The house ID
     * @param int $limit Maximum number of users to return
     * @return HouseUser[]
     */
    public function getLeaderboard(int $houseId, int $limit = 10): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
            ->orderBy('contribution_score', 'DESC')
            ->setMaxResults($limit);

        return $this->findEntities($qb);
    }

    /**
     * Find house user by house ID and Nextcloud user ID
     * Returns null if not found (does not throw exception)
     *
     * @param int $houseId The house ID
     * @param string $nextcloudUserId The Nextcloud user ID
     * @return HouseUser|null
     */
    public function findByHouseAndUser(int $houseId, string $nextcloudUserId): ?HouseUser
    {
        try {
            return $this->findByNextcloudUserId($houseId, $nextcloudUserId);
        } catch (DoesNotExistException $e) {
            return null;
        }
    }

    /**
     * Get active users (with activity in last N days)
     *
     * @param int $houseId The house ID
     * @param int $days Number of days to look back
     * @return HouseUser[]
     */
    public function getActiveUsers(int $houseId, int $days = 7): array
    {
        $qb = $this->db->getQueryBuilder();
        $cutoffDate = new \DateTime("-{$days} days");

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->gte('last_activity', $qb->createNamedParameter($cutoffDate, IQueryBuilder::PARAM_DATE)))
            ->orderBy('last_activity', 'DESC');

        return $this->findEntities($qb);
    }

    /**
     * Find all users in house
     *
     * @param int $houseId The house ID
     * @return HouseUser[]
     */
    public function findByHouse(int $houseId): array
    {
        return $this->findByHouseId($houseId);
    }

    /**
     * Find active users in house
     *
     * @param int $houseId The house ID
     * @return HouseUser[]
     */
    public function findActiveByHouse(int $houseId): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('is_active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
            ->orderBy('contribution_score', 'DESC');

        return $this->findEntities($qb);
    }

    /**
     * Find users by house and role
     *
     * @param int $houseId The house ID
     * @param string $role The user role
     * @return HouseUser[]
     */
    public function findByHouseAndRole(int $houseId, string $role): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('role', $qb->createNamedParameter($role, IQueryBuilder::PARAM_STR)))
            ->orderBy('contribution_score', 'DESC');

        return $this->findEntities($qb);
    }

    /**
     * Find house user by house ID and Nextcloud user ID (alias for findByHouseAndUser)
     * Returns null if not found
     *
     * @param int $houseId The house ID
     * @param string $nextcloudUserId The Nextcloud user ID
     * @return HouseUser|null
     */
    public function findByHouseIdAndNextcloudUserId(int $houseId, string $nextcloudUserId): ?HouseUser
    {
        return $this->findByHouseAndUser($houseId, $nextcloudUserId);
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
    public function findAllForJobs(): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName());

        return $this->findEntities($qb);
    }
}
