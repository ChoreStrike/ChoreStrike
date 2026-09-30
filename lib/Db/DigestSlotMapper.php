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
 * @template-extends QBMapper<DigestSlot>
 */
class DigestSlotMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'chorestrike_digest_slots', DigestSlot::class);
	}

	/**
	 * The member's slot for one house-local day (the daily budget
	 * identity), or null.
	 */
	public function findByHouseUserAndDay(int $houseId, int $houseUserId, string $localDay): ?DigestSlot {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('house_user_id', $qb->createNamedParameter($houseUserId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('local_day', $qb->createNamedParameter($localDay)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException $e) {
			return null;
		}
	}

	/**
	 * Every pending slot of a house (zone-change re-resolution input).
	 *
	 * @return DigestSlot[]
	 */
	public function findPendingByHouseId(int $houseId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(DigestSlot::STATUS_PENDING)))
			->orderBy('scheduled_utc', 'ASC');
		return $this->findEntities($qb);
	}

	/**
	 * The most recent dispatch attempt instant of a membership (sent OR
	 * uncertain — the 24-elapsed-hour zone-change spacing input, C5k).
	 * Null when the member was never dispatched.
	 */
	public function findLastDispatchAt(int $houseId, int $houseUserId, string $differentFromZone): ?\DateTime {
		$qb = $this->db->getQueryBuilder();
		$qb->select('dispatched_at')
			->from($this->getTableName())
			->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('house_user_id', $qb->createNamedParameter($houseUserId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->neq('zone', $qb->createNamedParameter($differentFromZone)))
			->andWhere($qb->expr()->nonEmptyString('dispatched_at'))
			->orderBy('dispatched_at', 'DESC')
			->setMaxResults(1);
		$result = $qb->executeQuery();
		$value = $result->fetchOne();
		$result->closeCursor();
		if (!is_string($value) || $value === '') {
			return null;
		}
		return new \DateTime($value);
	}

	/**
	 * Pending slots whose dispatch window has ended (C5c: a missed slot
	 * expires permanently — never compensated by next-day catch-up).
	 *
	 * @return DigestSlot[]
	 */
	public function findExpiredPending(\DateTimeInterface $now, int $limit = 200): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('status', $qb->createNamedParameter(DigestSlot::STATUS_PENDING)))
			->andWhere($qb->expr()->lt('window_end_utc', $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATE)))
			->orderBy('window_end_utc', 'ASC')
			->setMaxResults($limit);
		return $this->findEntities($qb);
	}

	/**
	 * Pending slots that are due now and still inside their window.
	 *
	 * @return DigestSlot[]
	 */
	public function findDuePending(\DateTimeInterface $now, int $limit = 200): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('status', $qb->createNamedParameter(DigestSlot::STATUS_PENDING)))
			->andWhere($qb->expr()->lte('scheduled_utc', $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATE)))
			->andWhere($qb->expr()->gte('window_end_utc', $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATE)))
			->orderBy('scheduled_utc', 'ASC')
			->setMaxResults($limit);
		return $this->findEntities($qb);
	}

	/**
	 * Insert-or-return: the unique (house, membership, local_day) identity
	 * makes materialization idempotent for concurrent workers — a lost
	 * race re-reads the winner's slot. Called inside the digest pass.
	 *
	 * @throws \OCP\DB\Exception when the insert fails for any reason other
	 *                           than the unique-constraint race
	 */
	public function insertOrGet(DigestSlot $slot): DigestSlot {
		try {
			return $this->insert($slot);
		} catch (\OCP\DB\Exception $e) {
			if ($e->getReason() !== \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $e;
			}
			$existing = $this->findByHouseUserAndDay(
				$slot->getHouseId(),
				$slot->getHouseUserId(),
				$slot->getLocalDay()
			);
			if ($existing === null) {
				throw $e;
			}
			return $existing;
		}
	}

	/**
	 * Atomic claim (parent §3.6): the conditional pending → dispatching
	 * write. Returns the claimed slot or null when another worker (or a
	 * lost race) took it. The caller holds the house lock.
	 */
	public function claimPending(int $slotId, string $token, \DateTimeInterface $now): ?DigestSlot {
		$qb = $this->db->getQueryBuilder();
		$affected = $qb->update($this->getTableName())
			->set('status', $qb->createNamedParameter(DigestSlot::STATUS_DISPATCHING))
			->set('dispatch_token', $qb->createNamedParameter($token))
			->set('dispatched_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATE))
			->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATE))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($slotId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(DigestSlot::STATUS_PENDING)))
			->executeStatement();
		if ($affected === 0) {
			return null;
		}
		return $this->findById($slotId);
	}

	/**
	 * The slot by id, or null.
	 */
	public function findById(int $slotId): ?DigestSlot {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($slotId, IQueryBuilder::PARAM_INT)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException $e) {
			return null;
		}
	}

	/**
	 * Conditional recheck-skip: marks a still-pending slot skipped
	 * (departed member, opt-out, empty content). A lost race against a
	 * concurrent claim is a no-op — a dispatching/sent slot is never
	 * clobbered.
	 */
	public function skipPending(int $slotId, \DateTimeInterface $now): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('status', $qb->createNamedParameter(DigestSlot::STATUS_SKIPPED))
			->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATE))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($slotId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(DigestSlot::STATUS_PENDING)))
			->executeStatement();
	}

	/**
	 * Finalize a claimed slot (sent / skipped / uncertain) by its claim
	 * token — a stale token never overwrites a newer state.
	 */
	public function finalizeClaim(int $slotId, string $token, string $status, \DateTimeInterface $now): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('status', $qb->createNamedParameter($status))
			->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATE))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($slotId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('dispatch_token', $qb->createNamedParameter($token)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(DigestSlot::STATUS_DISPATCHING)))
			->executeStatement();
	}

	/**
	 * Delete the membership's slot history (departure / erasure — the
	 * ONLY deletion paths; rows are otherwise retained for the C5k
	 * spacing rule).
	 */
	public function deleteByHouseAndUser(int $houseId, int $houseUserId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('house_user_id', $qb->createNamedParameter($houseUserId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}
}
