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
 * @template-extends QBMapper<RewardRequestEvent>
 */
class RewardRequestEventMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'chorestrike_request_events', RewardRequestEvent::class);
	}

	/**
	 * Append one audit event. The unique (request_id, revision) identity
	 * is the concurrency guard: a writer that lost the version check
	 * cannot insert a duplicate revision. Runs inside the caller's
	 * house-first transaction.
	 */
	public function append(RewardRequestEvent $event): RewardRequestEvent {
		return $this->insert($event);
	}

	/**
	 * The authorized audit history of one request (ascending revision =
	 * chronological order).
	 *
	 * @return RewardRequestEvent[]
	 */
	public function findByRequestId(int $requestId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('request_id', $qb->createNamedParameter($requestId, IQueryBuilder::PARAM_INT)))
			->orderBy('revision', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * Delete every event of the given requests (authorized privacy
	 * erasure is the ONLY deletion path).
	 *
	 * @param int[] $requestIds
	 */
	public function deleteByRequestIds(array $requestIds): void {
		if ($requestIds === []) {
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->in('request_id', $qb->createNamedParameter($requestIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->executeStatement();
	}

	/**
	 * Detach the erased actor from retained events of OTHER people's
	 * requests (the erased actor's own request events died with their
	 * requests).
	 */
	public function anonymizeActorByMembership(int $houseUserId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('actor_house_user_id', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->where($qb->expr()->eq('actor_house_user_id', $qb->createNamedParameter($houseUserId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	/**
	 * Detach the erased actor by wallet provenance (covers
	 * already-departed actors whose membership row is gone).
	 */
	public function anonymizeActorByWallet(int $walletId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('actor_house_user_id', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->set('actor_wallet_id', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->where($qb->expr()->eq('actor_wallet_id', $qb->createNamedParameter($walletId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}
}
