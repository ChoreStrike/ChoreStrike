<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Exception\ConflictException;
use OCP\IL10N;

/**
 * The people a completion credits, as the client names them and as the
 * server defaults them (WP3 §3.2, ADR-021 decision 10): 1 to 100 unique
 * house members with positive whole percents that sum to exactly 100.
 * Pure: no reads, no writes.
 */
class ParticipantAllocation {
	public function __construct(
		private EffortAllocationService $allocationService,
		private IL10N $l10n,
	) {
	}

	/**
	 * Validate the client's participant list and bring it into its
	 * canonical order (by user id), so the command payload is the same
	 * whatever order the client sent.
	 *
	 * @param array<int, array{userId: string, sharePct: int}> $participants
	 * @return list<array{userId: string, sharePct: int}>
	 * @throws \InvalidArgumentException
	 */
	public function canonicalize(array $participants): array {
		$seen = [];
		foreach ($participants as $participant) {
			$uid = (string)($participant['userId'] ?? '');
			$pct = $participant['sharePct'] ?? null;
			if ($uid === '' || !is_numeric($pct) || (int)$pct != $pct || (int)$pct < 1 || (int)$pct > 100) {
				throw new \InvalidArgumentException($this->l10n->t('Every participant needs a user id and a whole percent share'));
			}
			if (isset($seen[$uid])) {
				throw new \InvalidArgumentException($this->l10n->t('Participants must be unique'));
			}
			$seen[$uid] = ['userId' => $uid, 'sharePct' => (int)$pct];
		}
		usort($seen, static fn (array $a, array $b): int => $a['userId'] <=> $b['userId']);
		return $seen;
	}

	/**
	 * What a completion without explicit participants credits: the
	 * assignees in equal shares (wallet order), else the recorder alone.
	 *
	 * @param string[] $assigneeUserIds
	 * @param HouseUser[] $members
	 * @return array<string, int> userId => sharePct
	 */
	public function proposal(array $assigneeUserIds, array $members, string $actorUserId): array {
		if ($assigneeUserIds === []) {
			return [$actorUserId => 100];
		}
		$walletByUserId = [];
		foreach ($members as $member) {
			$walletByUserId[$member->getNextcloudUserId()] = $member->getWalletId();
		}
		$subjects = [];
		foreach ($assigneeUserIds as $uid) {
			$subjects[$uid] = $walletByUserId[$uid] ?? null;
		}
		return $this->allocationService->equalShares($subjects);
	}

	/**
	 * Whether an explicit participant list equals a reference allocation,
	 * whatever its order.
	 *
	 * @param list<array{userId: string, sharePct: int}> $participants
	 * @param array<string, int> $reference userId => sharePct
	 */
	public function same(array $participants, array $reference): bool {
		if (count($participants) !== count($reference)) {
			return false;
		}
		foreach ($participants as $participant) {
			if (($reference[$participant['userId']] ?? null) !== $participant['sharePct']) {
				return false;
			}
		}
		return true;
	}

	/**
	 * The allocation of an explicit participant list: members only, summing
	 * to exactly 100.
	 *
	 * @param list<array{userId: string, sharePct: int}> $participants
	 * @param HouseUser[] $members
	 * @return array<string, int> userId => sharePct
	 * @throws ConflictException a participant who is no member
	 */
	public function fromParticipants(array $participants, array $members): array {
		$memberIds = [];
		foreach ($members as $member) {
			$memberIds[$member->getNextcloudUserId()] = true;
		}
		$allocation = [];
		foreach ($participants as $participant) {
			if (!isset($memberIds[$participant['userId']])) {
				throw new ConflictException($this->l10n->t('Participants must be house members'));
			}
			$allocation[$participant['userId']] = $participant['sharePct'];
		}
		$this->allocationService->validateShares($allocation);
		return $allocation;
	}
}
