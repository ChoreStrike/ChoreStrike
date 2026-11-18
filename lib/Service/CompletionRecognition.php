<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;

/**
 * The tail every completion command ends with, inside its transaction:
 * the recognitions of the members it touched are derived again from valid
 * history — never incremented or restored (C3e/C3f) — and the house state
 * version moves on.
 */
class CompletionRecognition {
	public function __construct(
		private HouseUserMapper $houseUserMapper,
		private HouseMapper $houseMapper,
		private RhythmService $rhythmService,
		private ?TitleService $titleService,
		private RewardConfigService $configService,
	) {
	}

	/**
	 * Re-derive the streaks and title tiers of the given members and bump
	 * the house state version.
	 *
	 * The members are RE-LOADED first: the entities a command locked
	 * predate its wallet and contribution writes, so their cached stats
	 * would be stale for the tier comparison.
	 *
	 * @param HouseUser[] $lockedMembers The people credited and the responsible assignees
	 * @param string|null $actorUserId Whose newly crossed tier to answer (the celebration of a completion)
	 * @return array<string, mixed>|null The actor's new title tier
	 */
	public function recompute(int $houseId, array $lockedMembers, \DateTime $now, ?string $actorUserId = null): ?array {
		$ids = array_fill_keys(array_map(static fn (HouseUser $member): int => (int)$member->getId(), $lockedMembers), true);
		$members = array_values(array_filter(
			$this->houseUserMapper->findByHouseId($houseId),
			static fn (HouseUser $member): bool => isset($ids[(int)$member->getId()])
		));
		$this->rhythmService->recomputeForMembers($houseId, $members, $now);
		$newTitle = $this->reconcileTitles($members, $actorUserId);

		$house = $this->houseMapper->findForUpdate($houseId);
		$house->setStateVersion($house->getStateVersion() + 1);
		$house->touch();
		$this->houseMapper->update($house);
		return $newTitle;
	}

	/**
	 * Retained recognition (Q-074-01A): add newly crossed tiers, remove
	 * tiers the corrected valid history never reached (C3g).
	 *
	 * @param HouseUser[] $members
	 */
	private function reconcileTitles(array $members, ?string $actorUserId): ?array {
		if ($this->titleService === null || !$this->configService->getTitlesEnabled()) {
			return null;
		}
		$newTitle = null;
		foreach ($members as $member) {
			$unlocked = $this->titleService->detectUnlock($member);
			$removed = $this->titleService->reconcileUnlocked($member);
			// The marker change must survive the transaction.
			if ($unlocked !== [] || $removed !== []) {
				$this->houseUserMapper->update($member);
			}
			// One series, one tier per rung: the actor's unlock is the newly crossed tier.
			if ($actorUserId !== null && $member->getNextcloudUserId() === $actorUserId && $unlocked !== []) {
				$newTitle = $unlocked[0];
			}
		}
		return $newTitle;
	}
}
