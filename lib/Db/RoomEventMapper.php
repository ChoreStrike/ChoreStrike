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
 * @template-extends QBMapper<RoomEvent>
 */
class RoomEventMapper extends QBMapper {
	/** Replay/collection batch size (WP3 §3.5: indexed cursor batches). */
	public const BATCH_SIZE = 500;

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'chorestrike_room_events', RoomEvent::class);
	}

	/**
	 * All events of one room in replay order `(effective_at, id)`, bounded
	 * by the batch limit. The replay consumer pages with a cursor when a
	 * room carries more events than one batch.
	 *
	 * @return RoomEvent[]
	 */
	public function findEventsByRoom(int $roomId, int $limit = self::BATCH_SIZE): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('room_id', $qb->createNamedParameter($roomId, IQueryBuilder::PARAM_INT)))
			->orderBy('effective_at', 'ASC')
			->addOrderBy('id', 'ASC')
			->setMaxResults($limit);

		return $this->findEntities($qb);
	}

	/**
	 * The events of one room after a replay cursor (exclusive), in replay
	 * order `(effective_at, id)`, bounded by the batch limit — the paging
	 * seam of {@see \OCA\ChoreStrike\Service\RoomReplayService::replay()}.
	 *
	 * @param array{at: \DateTimeInterface, id: int}|null $cursor
	 * @return RoomEvent[]
	 */
	public function findEventsByRoomAfter(int $roomId, ?array $cursor, int $limit = self::BATCH_SIZE): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('room_id', $qb->createNamedParameter($roomId, IQueryBuilder::PARAM_INT)));

		if ($cursor !== null) {
			$qb->andWhere($qb->expr()->orX(
				$qb->expr()->gt('effective_at', $qb->createNamedParameter($cursor['at'], IQueryBuilder::PARAM_DATE)),
				$qb->expr()->andX(
					$qb->expr()->eq('effective_at', $qb->createNamedParameter($cursor['at'], IQueryBuilder::PARAM_DATE)),
					$qb->expr()->gt('id', $qb->createNamedParameter($cursor['id'], IQueryBuilder::PARAM_INT))
				)
			));
		}

		$qb->orderBy('effective_at', 'ASC')
			->addOrderBy('id', 'ASC')
			->setMaxResults($limit);

		return $this->findEntities($qb);
	}

	/**
	 * Latest absolute checkpoint; earlier events cannot influence room state.
	 */
	public function findLatestResetByRoom(int $roomId): ?RoomEvent {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('room_id', $qb->createNamedParameter($roomId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('kind', $qb->createNamedParameter(RoomEvent::KIND_RESET)))
			->orderBy('effective_at', 'DESC')
			->addOrderBy('id', 'DESC')
			->setMaxResults(1);

		try {
			return $this->findEntity($qb);
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			return null;
		}
	}

	/**
	 * Count the journaled events of one room (query-budget probe).
	 */
	public function countByRoom(int $roomId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))
			->from($this->getTableName())
			->where($qb->expr()->eq('room_id', $qb->createNamedParameter($roomId, IQueryBuilder::PARAM_INT)));

		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		return $count;
	}

	/**
	 * All events of a set of rooms in replay order (house reset/replay
	 * batch path).
	 *
	 * @param int[] $roomIds
	 * @return RoomEvent[]
	 */
	public function findByRoomIds(array $roomIds): array {
		$roomIds = array_values(array_map('intval', $roomIds));
		if ($roomIds === []) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->in('room_id', $qb->createNamedParameter($roomIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->orderBy('effective_at', 'ASC')
			->addOrderBy('id', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * The completion-source event of one completion (the effect an undo
	 * invalidates).
	 */
	public function findBySourceCompletion(int $completionId): ?RoomEvent {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('source_completion_id', $qb->createNamedParameter($completionId, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);

		try {
			return $this->findEntity($qb);
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			return null;
		}
	}

	/**
	 * Test-mode full reset: clear the table in safe order.
	 */
	public function deleteAll(): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())->executeStatement();
	}
}
