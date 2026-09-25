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
 * @template-extends QBMapper<Agreement>
 */
class AgreementMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'chorestrike_agreements', Agreement::class);
	}

	/**
	 * The next free revision number for an occurrence (max + 1, 1 when
	 * empty). Callers hold the house lock, so this is race-free inside the
	 * owning transaction.
	 */
	public function nextRevision(int $occurrenceId): int {
		$qb = $this->db->getQueryBuilder();
		$result = $qb->select($qb->func()->max('revision'))
			->from($this->getTableName())
			->where($qb->expr()->eq('occurrence_id', $qb->createNamedParameter($occurrenceId, IQueryBuilder::PARAM_INT)))
			->executeQuery();
		$max = (int)$result->fetchOne();
		return $max + 1;
	}

	/**
	 * Record one agreement revision. Called inside the owning house-first
	 * transaction. WP2: `$consent` stores the recorded consenting subject
	 * facts, `$pauseRef` links a pause-effect row to its pause group.
	 */
	public function record(
		int $occurrenceId,
		string $kind,
		?int $actorHouseUserId,
		?array $affectedWalletIds,
		?array $oldState,
		?array $newState,
		?array $consent = null,
		?int $pauseRef = null,
	): Agreement {
		$entity = new Agreement();
		$entity->setOccurrenceId($occurrenceId);
		$entity->setRevision($this->nextRevision($occurrenceId));
		$entity->setKind($kind);
		$entity->setActorHouseUserId($actorHouseUserId);
		$entity->setAffectedWalletIds($affectedWalletIds);
		$entity->setOldState($oldState);
		$entity->setNewState($newState);
		$entity->setConsent($consent);
		$entity->setPauseRef($pauseRef);
		$entity->setCreatedAt(new \DateTime());
		return $this->insert($entity);
	}

	/**
	 * Null the actor of a membership's agreement rows (account erasure);
	 * the traceable facts stay.
	 */
	public function anonymizeActor(int $houseUserId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('actor_house_user_id', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->where($qb->expr()->eq('actor_house_user_id', $qb->createNamedParameter($houseUserId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	/**
	 * Anonymize a wallet's user references inside every agreement state
	 * snapshot of a house (account erasure): the numeric wallet fact stays
	 * (non-reidentifiable), the account binding is stripped. Mirrors
	 * ScheduleRevisionMapper::anonymizeDefaultMembers.
	 */
	public function anonymizeMemberSnapshots(int $houseId, int $walletId): void {
		$occQb = $this->db->getQueryBuilder();
		$rows = $occQb->select('id')
			->from('chorestrike_occurrences')
			->where($occQb->expr()->eq('house_id', $occQb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
			->executeQuery()
			->fetchAll();
		$occurrenceIds = array_map(static fn (array $row): int => (int)$row['id'], $rows);
		if ($occurrenceIds === []) {
			return;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->in('occurrence_id', $qb->createNamedParameter($occurrenceIds, IQueryBuilder::PARAM_INT_ARRAY)));
		foreach ($this->findEntities($qb) as $agreement) {
			$oldState = self::scrubMemberUserIds($agreement->getOldState(), $walletId);
			$newState = self::scrubMemberUserIds($agreement->getNewState(), $walletId);
			$consent = self::scrubConsentUserIds($agreement->getConsent(), $walletId);
			if ($oldState !== $agreement->getOldState() || $newState !== $agreement->getNewState() || $consent !== $agreement->getConsent()) {
				$agreement->setOldState($oldState);
				$agreement->setNewState($newState);
				$agreement->setConsent($consent);
				$this->update($agreement);
			}
		}
	}

	/**
	 * Null the `userId` of every member snapshot entry belonging to the
	 * wallet; all other facts stay untouched.
	 *
	 * @param array<string, mixed>|null $state
	 * @return array<string, mixed>|null
	 */
	private static function scrubMemberUserIds(?array $state, int $walletId): ?array {
		if ($state === null || !isset($state['members']) || !is_array($state['members'])) {
			return $state;
		}
		foreach ($state['members'] as $index => $member) {
			if (is_array($member) && (int)($member['walletId'] ?? 0) === $walletId) {
				$state['members'][$index]['userId'] = null;
			}
		}
		return $state;
	}

	/**
	 * Null the `userId` of the erased wallet inside recorded consent
	 * snapshots (WP2 erasure); the numeric wallet fact stays.
	 *
	 * @param array<string, mixed>|null $consent
	 * @return array<string, mixed>|null
	 */
	private static function scrubConsentUserIds(?array $consent, int $walletId): ?array {
		if ($consent === null || !isset($consent['subjects']) || !is_array($consent['subjects'])) {
			return $consent;
		}
		foreach ($consent['subjects'] as $index => $subject) {
			if (is_array($subject) && (int)($subject['walletId'] ?? 0) === $walletId) {
				$consent['subjects'][$index]['userId'] = null;
			}
		}
		return $consent;
	}

	/**
	 * Delete agreements of the given occurrences (test-mode reset).
	 *
	 * @param int[] $occurrenceIds
	 */
	public function deleteByOccurrenceIds(array $occurrenceIds): void {
		$occurrenceIds = array_values(array_map('intval', $occurrenceIds));
		if ($occurrenceIds === []) {
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->in('occurrence_id', $qb->createNamedParameter($occurrenceIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->executeStatement();
	}

	/**
	 * Delete agreements of a house's occurrences (test-mode reset).
	 */
	public function deleteByHouseId(int $houseId): void {
		$qb = $this->db->getQueryBuilder();
		$occQb = $this->db->getQueryBuilder();
		$rows = $occQb->select('id')
			->from('chorestrike_occurrences')
			->where($occQb->expr()->eq('house_id', $occQb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
			->executeQuery()
			->fetchAll();
		$this->deleteByOccurrenceIds(array_map(static fn (array $row): int => (int)$row['id'], $rows));
	}
}
