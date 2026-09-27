<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;

/**
 * Game-state read service (CS-PLAN-070 WP3 cutover).
 *
 * The former completion-reward orchestration (coins, combo, streak,
 * freezes, teamwork, titles) is retired: money is the wallet ledger
 * (WalletService), streaks re-derive from valid history (RhythmService),
 * titles re-evaluate from valid stats (CompletionService + TitleService).
 * What remains here is the read-side serialization of the member
 * progression for `GET /game/state`.
 */
class GameLogicService {
	private HouseUserMapper $houseUserMapper;
	private ?TitleService $titleService;

	public function __construct(
		HouseUserMapper $houseUserMapper,
		?TitleService $titleService = null,
	) {
		$this->houseUserMapper = $houseUserMapper;
		$this->titleService = $titleService;
	}

	/**
	 * Get game state for a house (viewer-safe member progression; no Coin
	 * balances — the wallet is exposed only as the caller's OWN state).
	 *
	 * @param int $houseId The house ID
	 */
	public function getGameState(int $houseId): array {
		$users = $this->houseUserMapper->findByHouseId($houseId);

		return [
			'users' => array_map(fn ($user) => $this->serializeUser($user), $users),
		];
	}

	/**
	 * Serialize a HouseUser to its JSON shape, enriching it with the
	 * server-computed `currentTitle` when the title system is available.
	 * Delegates to TitleService so the shape stays in sync with UserController.
	 */
	private function serializeUser(HouseUser $user): array {
		$data = $user->jsonSerialize();
		if ($this->titleService !== null) {
			return $this->titleService->enrichWithCurrentTitle($data, $user);
		}
		return $data;
	}
}
