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
 * @template-extends QBMapper<Wallet>
 */
class WalletMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'chorestrike_wallets', Wallet::class);
	}

	/**
	 * Find the wallet bound to a house + Nextcloud account, or null.
	 */
	public function findByHouseAndUser(int $houseId, string $nextcloudUserId): ?Wallet {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('nextcloud_user_id', $qb->createNamedParameter($nextcloudUserId, IQueryBuilder::PARAM_STR)));

		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException $e) {
			return null;
		}
	}

	/**
	 * Find a wallet by ID and acquire an exclusive row lock (ADR-007
	 * pattern; SQLite via the no-op self-assignment UPDATE). Must be called
	 * inside an open transaction.
	 *
	 * @throws DoesNotExistException if the row does not exist
	 */
	public function findForUpdate(int $id): Wallet {
		if ($this->db->getDatabaseProvider() === IDBConnection::PLATFORM_SQLITE) {
			$qb = $this->db->getQueryBuilder();
			$qb->update($this->getTableName())
				->set('version', $qb->createFunction('version'))
				->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
			$qb->executeStatement();

			return $this->find($id);
		}

		$sql = 'SELECT * FROM *PREFIX*' . $this->getTableName() . ' WHERE id = :id FOR UPDATE';
		$result = $this->db->executeQuery($sql, ['id' => $id], ['id' => IQueryBuilder::PARAM_INT]);
		$row = $result->fetch();
		$result->closeCursor();

		if ($row === false) {
			throw new DoesNotExistException('Wallet with id ' . $id . ' does not exist');
		}

		return $this->mapRowToEntity($row);
	}

	/**
	 * Create the wallet for a house + account binding, or return the
	 * existing one (identity creation is idempotent).
	 *
	 * Race-tolerant (CR-053-07 pattern): the `uniq_wallet_house_user`
	 * unique index rejects a concurrent duplicate insert; the loser
	 * re-fetches the winner's row. Callers run this inside the house-first
	 * transaction, so the house lock already serializes same-house
	 * creation — the constraint is the belt-and-suspenders guard.
	 */
	public function createOrGet(int $houseId, string $nextcloudUserId): Wallet {
		$existing = $this->findByHouseAndUser($houseId, $nextcloudUserId);
		if ($existing !== null) {
			return $existing;
		}

		$now = new \DateTime();
		$wallet = new Wallet();
		$wallet->setHouseId($houseId);
		$wallet->setNextcloudUserId($nextcloudUserId);
		$wallet->setBookedMinor(0);
		$wallet->setReservedMinor(0);
		$wallet->setVersion(0);
		$wallet->setCreatedAt($now);
		$wallet->setUpdatedAt($now);
		try {
			return $this->insert($wallet);
		} catch (\OCP\DB\Exception $insertException) {
			if ($insertException->getReason() !== \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $insertException;
			}
			$wallet = $this->findByHouseAndUser($houseId, $nextcloudUserId);
			if ($wallet === null) {
				throw $insertException;
			}
			return $wallet;
		}
	}

	/**
	 * Every wallet bound to a Nextcloud account across all houses
	 * (account-erasure sweep; covers already-departed memberships).
	 *
	 * @return Wallet[]
	 */
	public function findAllByUserId(string $nextcloudUserId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('nextcloud_user_id', $qb->createNamedParameter($nextcloudUserId, IQueryBuilder::PARAM_STR)));

		return $this->findEntities($qb);
	}
}
