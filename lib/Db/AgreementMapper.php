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
	 * Anonymize an erased account's references inside every agreement
	 * snapshot of a house (account erasure): the numeric wallet fact stays
	 * (non-reidentifiable), the account binding is stripped, and so is the
	 * plain user id the weekly planner's apply recorded as a consent subject.
	 * Mirrors ScheduleRevisionMapper::anonymizeDefaultMembers. The agreements
	 * are read through their occurrences in pages of 500, so no list grows
	 * with the retained history.
	 */
	public function anonymizeMemberSnapshots(int $houseId, int $walletId, string $userId): void {
		$afterId = 0;
		do {
			$qb = $this->db->getQueryBuilder();
			$qb->select('a.*')
				->from($this->getTableName(), 'a')
				->innerJoin('a', 'chorestrike_occurrences', 'o', $qb->expr()->eq('o.id', 'a.occurrence_id'))
				->where($qb->expr()->eq('o.house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->gt('a.id', $qb->createNamedParameter($afterId, IQueryBuilder::PARAM_INT)))
				->orderBy('a.id', 'ASC')
				->setMaxResults(500);
			$page = $this->findEntities($qb);
			foreach ($page as $agreement) {
				$afterId = (int)$agreement->getId();
				$this->scrubAgreement($agreement, $walletId, $userId);
			}
		} while (count($page) === 500);
	}

	private function scrubAgreement(Agreement $agreement, int $walletId, string $userId): void {
		$oldState = self::scrubMemberUserIds($agreement->getOldState(), $walletId);
		$newState = self::scrubMemberUserIds($agreement->getNewState(), $walletId);
		$consent = self::scrubConsentUserIds($agreement->getConsent(), $walletId, $userId);
		if ($oldState !== $agreement->getOldState() || $newState !== $agreement->getNewState() || $consent !== $agreement->getConsent()) {
			$agreement->setOldState($oldState);
			$agreement->setNewState($newState);
			$agreement->setConsent($consent);
			$this->update($agreement);
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
	 * snapshots (WP2 erasure); the numeric wallet fact stays. A subject the
	 * planner recorded as a plain user id is nulled in place.
	 *
	 * @param array<string, mixed>|null $consent
	 * @return array<string, mixed>|null
	 */
	private static function scrubConsentUserIds(?array $consent, int $walletId, string $userId): ?array {
		if ($consent === null || !isset($consent['subjects']) || !is_array($consent['subjects'])) {
			return $consent;
		}
		foreach ($consent['subjects'] as $index => $subject) {
			if (is_array($subject) && (int)($subject['walletId'] ?? 0) === $walletId) {
				$consent['subjects'][$index]['userId'] = null;
			} elseif (is_string($subject) && $subject === $userId) {
				$consent['subjects'][$index] = null;
			}
		}
		return $consent;
	}
}
