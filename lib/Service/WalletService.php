<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\WalletMapper;

/**
 * Persistent wallet identity helper (CS-PLAN-071 WP0, decision D1).
 *
 * The single implementation of the membership-to-wallet binding so every
 * membership-creation path (bootstrap, invite, setup) enforces the same
 * reattachment invariant: create the wallet when absent, bind its id on
 * the house_users row when it differs. Idempotent; runs inside the
 * caller's house-first transaction. Financial identity grants no access
 * (authorization stays solely the active house_users membership).
 */
class WalletService {
	public function __construct(
		private WalletMapper $walletMapper,
		private HouseUserMapper $houseUserMapper,
	) {
	}

	public function ensureBinding(int $houseId, HouseUser $member): HouseUser {
		$wallet = $this->walletMapper->createOrGet($houseId, $member->getNextcloudUserId());
		if ($member->getWalletId() !== $wallet->getId()) {
			$member->setWalletId($wallet->getId());
			return $this->houseUserMapper->update($member);
		}
		return $member;
	}
}
