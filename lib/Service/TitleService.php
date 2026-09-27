<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Titles\TitleCatalog;
use OCP\IL10N;

/**
 * Server-authoritative role-title logic (CS-PLAN-006 Phase 2).
 *
 * Reads existing HouseUser progression stats (contributionScore,
 * longestStreak, coins) and resolves which title tiers a user
 * qualifies for. Unlocks are computed server-side only — the client displays
 * what the server returns.
 *
 * Two notions of "unlocked":
 *  - {@see getCurrentTitle()} / {@see getUnlockedTitles()} read **live**
 *    stats against the (config-overridden) thresholds, so the displayed
 *    title always reflects reality and is immune to cache staleness after a
 *    reset.
 *  - {@see detectUnlock()} compares live stats against the user's
 *    `unlocked_titles` **cache** to find genuinely-new unlocks worth
 *    celebrating/notifying. It mutates the cache in-memory (the caller is
 *    responsible for persisting the HouseUser) and is idempotent — a second
 *    call with the same stats returns an empty list.
 */
class TitleService {
	private HouseUserMapper $houseUserMapper;
	private RewardConfigService $rewardConfig;
	private IL10N $l10n;

	public function __construct(
		HouseUserMapper $houseUserMapper,
		RewardConfigService $rewardConfig,
		IL10N $l10n,
	) {
		$this->houseUserMapper = $houseUserMapper;
		$this->rewardConfig = $rewardConfig;
		$this->l10n = $l10n;
	}

	/**
	 * The highest tier in the user's selected pack that the user currently
	 * qualifies for, or null when no pack is selected or no tier is reached.
	 *
	 * @return array{pack: string, packLabelKey: string, tierIndex: int, tierKey: string, labelKey: string, threshold: int}|null
	 */
	public function getCurrentTitle(HouseUser $user): ?array {
		$packKey = $user->getSelectedTitlePack();
		if ($packKey === null || !TitleCatalog::isValidPack($packKey)) {
			return null;
		}

		$qualifying = $this->qualifyingTiers($user, $packKey);
		return $qualifying === [] ? null : $qualifying[count($qualifying) - 1];
	}

	/**
	 * Every tier the user currently qualifies for across all packs, as a
	 * list of tier descriptors. Recomputed live from stats on every call.
	 *
	 * @return array<int, array{pack: string, packLabelKey: string, tierIndex: int, tierKey: string, labelKey: string, threshold: int}>
	 */
	public function getUnlockedTitles(HouseUser $user): array {
		return $this->qualifyingTiers($user);
	}

	/**
	 * Enrich a serialized user payload with its server-computed current
	 * title. Shared by UserController and GameLogicService so the
	 * leaderboard, user-list and game-state views emit the same shape.
	 *
	 * @param array<string, mixed> $data Serialized user payload
	 * @return array<string, mixed>
	 */
	public function enrichWithCurrentTitle(array $data, HouseUser $user): array {
		$data['currentTitle'] = $this->getCurrentTitle($user);
		return $data;
	}

	/**
	 * Select the pack the user displays. Validates the pack key and persists.
	 *
	 * Titles are identity, not admin: the actor must be the target user
	 * or a house owner — enforced service-side (RF-058-07); the controller
	 * check stays as defense in depth.
	 *
	 * @param HouseUser $target The user whose pack changes
	 * @param HouseUser $actor The acting user's house membership
	 * @param string $packKey Pack key to display
	 * @throws \OCA\ChoreStrike\Exception\ForbiddenException if the actor is neither the target nor an owner
	 * @throws \InvalidArgumentException When the pack key is unknown.
	 */
	public function selectPack(HouseUser $target, HouseUser $actor, string $packKey): void {
		if (!$actor->isOwner() && $actor->getNextcloudUserId() !== $target->getNextcloudUserId()) {
			throw new ForbiddenException($this->l10n->t('You are not allowed to update this user'));
		}
		if (!TitleCatalog::isValidPack($packKey)) {
			throw new \InvalidArgumentException('Unknown title pack: ' . $packKey);
		}
		$target->setSelectedTitlePack($packKey);
		$this->houseUserMapper->update($target);
	}

	/**
	 * Detect titles the user newly qualifies for since the last check.
	 *
	 * Compares the live-derived unlock set against the user's
	 * `unlocked_titles` cache. Newly-crossed tiers are appended to the cache
	 * (mutating the entity in-memory) and returned so the caller can fire a
	 * notification + celebration. The caller persists the HouseUser (e.g.
	 * GameLogicService's existing mapper update) — this method does NOT
	 * persist, to avoid a double write.
	 *
	 * Idempotent: a second call with unchanged stats returns an empty list
	 * (everything is already cached).
	 *
	 * @return array<int, array{pack: string, packLabelKey: string, tierIndex: int, tierKey: string, labelKey: string, threshold: int}>
	 */
	public function detectUnlock(HouseUser $user): array {
		$newUnlocks = [];
		foreach ($this->qualifyingTiers($user) as $tier) {
			$tierId = TitleCatalog::tierId($tier['pack'], $tier['tierIndex']);
			if ($user->addUnlockedTitle($tierId)) {
				$newUnlocks[] = $tier;
			}
		}
		return $newUnlocks;
	}

	/**
	 * Correction reconciliation (CS-PLAN-070 WP3, Q-074-01 / C3g): revoke
	 * a cached tier ONLY when the corrected valid history no longer
	 * reaches its threshold — a tier supported by any surviving valid run
	 * (earlier or later) stays cached, ordinary spending or later real
	 * streak breaks never revoke earned recognition. The caller persists
	 * the HouseUser; returns the removed tier ids.
	 *
	 * @return list<string>
	 */
	public function reconcileUnlocked(HouseUser $user): array {
		$supported = [];
		foreach ($this->qualifyingTiers($user) as $tier) {
			$supported[TitleCatalog::tierId($tier['pack'], $tier['tierIndex'])] = true;
		}
		$removed = [];
		$kept = [];
		foreach ($user->getUnlockedTitles() ?? [] as $tierId) {
			if (isset($supported[$tierId])) {
				$kept[] = $tierId;
			} else {
				$removed[] = $tierId;
			}
		}
		if ($removed !== []) {
			$user->setUnlockedTitles(array_values($kept));
		}
		return $removed;
	}

	/**
	 * The pack/tier catalog with effective (config-overridden) thresholds,
	 * shaped for the title-picker API so the frontend can render progress
	 * bars without a second call.
	 *
	 * @return array<string, array{labelKey: string, signal: string, tiers: array<int, array{key: string, labelKey: string, threshold: int}>}>
	 */
	public function getCatalogView(): array {
		$view = [];
		foreach (TitleCatalog::getPackKeys() as $packKey) {
			$pack = TitleCatalog::getPack($packKey);
			$tiers = [];
			foreach ($pack['tiers'] as $index => $tier) {
				$tiers[] = [
					'key' => $tier['key'],
					'labelKey' => $tier['labelKey'],
					'threshold' => $this->rewardConfig->getTitleThreshold($packKey, $index),
				];
			}
			$view[$packKey] = [
				'labelKey' => $pack['labelKey'],
				'signal' => $pack['signal'],
				'tiers' => $tiers,
			];
		}
		return $view;
	}

	/**
	 * Build a serializable tier descriptor.
	 *
	 * @return array{pack: string, packLabelKey: string, tierIndex: int, tierKey: string, labelKey: string, threshold: int}
	 */
	private function tierDescriptor(string $packKey, int $tierIndex): array {
		$pack = TitleCatalog::getPack($packKey);
		$tier = $pack['tiers'][$tierIndex];
		return [
			'pack' => $packKey,
			'packLabelKey' => $pack['labelKey'],
			'tierIndex' => $tierIndex,
			'tierKey' => $tier['key'],
			'labelKey' => $tier['labelKey'],
			'threshold' => $this->rewardConfig->getTitleThreshold($packKey, $tierIndex),
		];
	}

	/**
	 * Single source of the `signalValue >= threshold` qualification check.
	 *
	 * Returns tier descriptors for every tier the user currently qualifies
	 * for, optionally limited to one pack. Tiers are returned in ascending
	 * tier order within each pack, so the last element is the highest tier.
	 * getCurrentTitle/getUnlockedTitles/detectUnlock all derive from this
	 * so the unlock semantics can never drift between them.
	 *
	 * @param string|null $packKey Limit to one pack, or null for all packs
	 * @return array<int, array{pack: string, packLabelKey: string, tierIndex: int, tierKey: string, labelKey: string, threshold: int}>
	 */
	private function qualifyingTiers(HouseUser $user, ?string $packKey = null): array {
		$packKeys = $packKey !== null && TitleCatalog::isValidPack($packKey)
			? [$packKey]
			: TitleCatalog::getPackKeys();

		$result = [];
		foreach ($packKeys as $key) {
			$pack = TitleCatalog::getPack($key);
			$signalValue = TitleCatalog::getSignalValue($user, $pack['signal']);
			foreach ($pack['tiers'] as $index => $tier) {
				$threshold = $this->rewardConfig->getTitleThreshold($key, $index);
				if ($signalValue >= $threshold) {
					$result[] = $this->tierDescriptor($key, $index);
				}
			}
		}
		return $result;
	}
}
