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
 * @template-extends QBMapper<CommandReceipt>
 */
class CommandReceiptMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'chorestrike_commands', CommandReceipt::class);
	}

	/**
	 * Find the receipt for a (house, actor, command UUID) key, or null.
	 *
	 * The unique index `uniq_cmd_key` makes NULL actors distinct, so an
	 * erased receipt never shadows a live key — the lookup therefore pins
	 * the actor explicitly.
	 */
	public function findByHouseActorUuid(int $houseId, ?int $actorHouseUserId, string $commandUuid): ?CommandReceipt {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('command_uuid', $qb->createNamedParameter($commandUuid, IQueryBuilder::PARAM_STR)));

		if ($actorHouseUserId === null) {
			$qb->andWhere($qb->expr()->isNull('actor_house_user_id'));
		} else {
			$qb->andWhere($qb->expr()->eq('actor_house_user_id', $qb->createNamedParameter($actorHouseUserId, IQueryBuilder::PARAM_INT)));
		}

		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException $e) {
			return null;
		}
	}

	/**
	 * Null the actor reference of every receipt recorded by the given
	 * membership (account erasure, decision D3). The receipts survive as
	 * anonymous facts; their unique-index slot frees (NULLs are distinct).
	 *
	 * Scoped to the house so the `(house_id, actor_house_user_id)`
	 * prefix of `uniq_cmd_key` serves the lookup — an actor-only
	 * predicate would full-scan the table while the house lock is held.
	 */
	public function anonymizeActor(int $houseId, int $actorHouseUserId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('actor_house_user_id', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('actor_house_user_id', $qb->createNamedParameter($actorHouseUserId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}
}
