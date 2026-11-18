<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;

/**
 * Game-state read service (CS-PLAN-070 WP3 cutover).
 *
 * The former completion-reward orchestration (coins, combo, streak,
 * freezes, teamwork, titles) is retired: money is the wallet ledger
 * (WalletService), streaks re-derive from valid history (RhythmService),
 * titles re-evaluate from valid stats (CompletionService + TitleService).
 * What remains here is the read side of `GET /game/state`: the
 * serialization of the member progression and the viewer's recognitions.
 */
class GameLogicService {
	private HouseUserMapper $houseUserMapper;
	private WeeklyGoalService $weeklyGoalService;
	private RhythmService $rhythmService;
	private ?TitleService $titleService;

	public function __construct(
		HouseUserMapper $houseUserMapper,
		WeeklyGoalService $weeklyGoalService,
		RhythmService $rhythmService,
		?TitleService $titleService = null,
	) {
		$this->houseUserMapper = $houseUserMapper;
		$this->weeklyGoalService = $weeklyGoalService;
		$this->rhythmService = $rhythmService;
		$this->titleService = $titleService;
	}

	/**
	 * The recognitions `GET /game/state` shows its viewer (parent §3.4 read
	 * contract): the house's weekly goal, the viewer's rhythm and the
	 * viewer's slice of the weekly goal. One pass feeds all three: the
	 * open work, the absences and the week's facts are read once. Pure
	 * reads.
	 *
	 * @return array{weeklyGoal: array<string, mixed>, rhythm: array{confirmed: int, current: int, best: int, pending: bool}, myWeeklyGoal: array<string, mixed>}
	 */
	public function recognitionsFor(House $house, HouseUser $viewer, \DateTime $now): array {
		$pass = $this->weeklyGoalService->beginPass($house);
		$weeklyGoal = $this->weeklyGoalService->progressForWeek($house, $now, $pass);
		$evaluation = $this->rhythmService->evaluateForMembers((int)$house->getId(), [$viewer], $now, $pass)[(int)$viewer->getId()]
			?? ['currentStreak' => 0, 'longestStreak' => 0, 'pending' => false];
		return [
			'weeklyGoal' => $weeklyGoal,
			'rhythm' => [
				// C1f: the evaluation confirms only closed successful days —
				// the confirmed and the displayed current streak are the
				// same valid-history value; `pending` marks the held day.
				'confirmed' => $evaluation['currentStreak'],
				'current' => $evaluation['currentStreak'],
				'best' => $evaluation['longestStreak'],
				'pending' => $evaluation['pending'],
			],
			'myWeeklyGoal' => $this->weeklyGoalService->progressForMember($house, $viewer, $now, $pass),
		];
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
