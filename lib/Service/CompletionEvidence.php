<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\CompletedTask;
use OCA\ChoreStrike\Db\CompletionRevision;
use OCA\ChoreStrike\Db\CompletionRevisionMapper;
use OCA\ChoreStrike\Db\Wallet;
use OCA\ChoreStrike\Db\WorkShare;
use OCA\ChoreStrike\Db\WorkShareMapper;

/**
 * The immutable evidence rows of a completion (ADR-014): one allocation
 * revision of work shares with their exact minor amounts, and the
 * append-only audit chain. The coins of a share use the multipliers
 * frozen on the completion (ADR-021 decision 11), so a later attribution
 * prices a new share as the work was paid, never as the factors stand
 * today.
 */
class CompletionEvidence {
	public function __construct(
		private WorkShareMapper $workShareMapper,
		private CompletionRevisionMapper $revisionMapper,
		private EffortAllocationService $allocationService,
	) {
	}

	/**
	 * Write one allocation revision. Old revisions stay as evidence and are
	 * never counted again: the completion's revision pointer selects one.
	 *
	 * @param array<string, int> $allocation userId => sharePct, summing to 100
	 * @param array<string, Wallet> $walletsByUserId The locked wallets of the people credited
	 * @return array<int, int> Wallet id to the coin minor its share pays
	 */
	public function writeShares(CompletedTask $completion, int $revision, array $allocation, array $walletsByUserId, \DateTime $now): array {
		$this->allocationService->validateShares($allocation);
		$coins = [];
		foreach ($allocation as $userId => $sharePct) {
			$walletId = (int)$walletsByUserId[$userId]->getId();
			$amounts = $this->allocationService->allocate(
				$completion->getTotalEnergy(), $sharePct, $completion->coinFactorOf($walletId), $completion->bonusPercentOrDefault()
			);
			$share = new WorkShare();
			$share->setCompletionId((int)$completion->getId());
			$share->setAllocationRevision($revision);
			$share->setWalletId($walletId);
			$share->setSharePct($sharePct);
			$share->setEnergyMinor($amounts['energyMinor']);
			$share->setCoinMinor($amounts['coinMinor']);
			$share->setCreatedAt($now);
			$this->workShareMapper->insert($share);
			$coins[$walletId] = $amounts['coinMinor'];
		}
		return $coins;
	}

	/** Append one row to the audit chain of a completion. */
	public function writeRevision(
		CompletedTask $completion,
		int $revision,
		string $action,
		?int $allocationRevision,
		?int $actorHouseUserId,
		?string $reasonCode,
		string $commandUuid,
	): void {
		$audit = new CompletionRevision();
		$audit->setCompletionId((int)$completion->getId());
		$audit->setRevision($revision);
		$audit->setAction($action);
		$audit->setAllocationRevision($allocationRevision);
		$audit->setActorHouseUserId($actorHouseUserId);
		$audit->setReasonCode($reasonCode);
		$audit->setCommandUuid($commandUuid);
		$audit->setCreatedAt(new \DateTime());
		$this->revisionMapper->insert($audit);
	}
}
