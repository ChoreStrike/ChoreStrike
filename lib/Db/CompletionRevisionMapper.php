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
	 * The corrections of the given kinds in a house since an instant,
	 * newest first, each with the room task of its completion (the recent
	 * activity shows date changes and deletions, ADR-021 decision 9). The
	 * house resolves through the room-task chain, as the activity feed does.
	 *
	 * @param string[] $actions CompletionRevision::ACTION_* values
	 * @param int|null $actorHouseUserId Only the corrections of this member, filtered before the limit
	 * @return list<array{completionId: int, roomTaskId: int, action: string, actorHouseUserId: ?int, createdAt: \DateTime}>
	 */
	public function findByHouseAndActionsSince(int $houseId, array $actions, \DateTimeInterface $since, int $limit, ?int $actorHouseUserId = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('cr.completion_id', 'cr.action', 'cr.actor_house_user_id', 'cr.created_at', 'ct.room_task_id')
			->from($this->getTableName(), 'cr')
			->innerJoin('cr', 'chorestrike_completed_tasks', 'ct', $qb->expr()->eq('cr.completion_id', 'ct.id'))
			->innerJoin('ct', 'chorestrike_room_tasks', 'rt', $qb->expr()->eq('ct.room_task_id', 'rt.id'))
			->innerJoin('rt', 'chorestrike_rooms', 'r', $qb->expr()->eq('rt.room_id', 'r.id'))
			->innerJoin('r', 'chorestrike_floors', 'f', $qb->expr()->eq('r.floor_id', 'f.id'))
			->where($qb->expr()->eq('f.house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->in('cr.action', $qb->createNamedParameter($actions, IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($qb->expr()->gte('cr.created_at', $qb->createNamedParameter($since, IQueryBuilder::PARAM_DATE)));
		if ($actorHouseUserId !== null) {
			$qb->andWhere($qb->expr()->eq('cr.actor_house_user_id', $qb->createNamedParameter($actorHouseUserId, IQueryBuilder::PARAM_INT)));
		}
		$qb->orderBy('cr.created_at', 'DESC')
			->addOrderBy('cr.id', 'DESC')
			->setMaxResults($limit);

		$result = $qb->executeQuery();
		$rows = [];
		foreach ($result->fetchAll() as $row) {
			$rows[] = [
				'completionId' => (int)$row['completion_id'],
				'roomTaskId' => (int)$row['room_task_id'],
				'action' => (string)$row['action'],
				'actorHouseUserId' => $row['actor_house_user_id'] !== null ? (int)$row['actor_house_user_id'] : null,
				'createdAt' => new \DateTime((string)$row['created_at'], new \DateTimeZone('UTC')),
			];
		}
		$result->closeCursor();
		return $rows;
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
