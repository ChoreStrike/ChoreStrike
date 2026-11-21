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
}
