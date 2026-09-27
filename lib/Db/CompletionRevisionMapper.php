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
 * @template-extends QBMapper<CompletionRevision>
 */
class CompletionRevisionMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'chorestrike_completion_revs', CompletionRevision::class);
	}

	/**
	 * The full correction history of one completion, oldest first.
	 *
	 * @return CompletionRevision[]
	 */
	public function findByCompletionId(int $completionId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('completion_id', $qb->createNamedParameter($completionId, IQueryBuilder::PARAM_INT)))
			->orderBy('revision', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * The highest audit revision recorded for a completion (the next
	 * revision is max + 1; the initial completion writes revision 0).
	 */
	public function maxRevisionOf(int $completionId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->createFunction('COALESCE(MAX(revision), -1)'))
			->from($this->getTableName())
			->where($qb->expr()->eq('completion_id', $qb->createNamedParameter($completionId, IQueryBuilder::PARAM_INT)));

		$result = $qb->executeQuery();
		$max = $result->fetchOne();
		$result->closeCursor();

		return (int)$max;
	}

	/**
	 * Erasure sweep (WP3 §3.6): anonymize the actor references of the
	 * given membership; the bounded audit facts stay.
	 */
	public function anonymizeActor(int $houseUserId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('actor_house_user_id', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->where($qb->expr()->eq('actor_house_user_id', $qb->createNamedParameter($houseUserId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	/**
	 * Test-mode full reset: clear the table in safe order.
	 */
	public function deleteAll(): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())->executeStatement();
	}
}
