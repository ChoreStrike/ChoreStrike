<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Titles\TitleCatalog;

/**
 * Server-authoritative role-title logic (CS-PLAN-006 Phase 2; WP4
 * single-series cutover per CS-PLAN-070 C3d/D7c, Q-075-02 Option A).
 *
 * Reads existing HouseUser progression stats (the valid-history
 * longestStreak maintained by RhythmService) and resolves which title
 * tiers a user qualifies for. Unlocks are computed server-side only — the
 * client displays what the server returns. There is NO pack selection:
 * `currentTitle` is always the highest qualifying tier of the single
 * retained rhythm series (Q-075-02 Option A).
 *
 * Two notions of "unlocked":
 *  - {@see getCurrentTitle()} / {@see getUnlockedTitles()} read **live**
 *    stats against the fixed 3/6/12 thresholds, so the displayed title
 *    always reflects reality and is immune to cache staleness after a
 *    reset.
 *  - {@see detectUnlock()} compares live stats against the user's
 *    `unlocked_titles` **marker** (the first-earned celebration evidence,
 *    C3g) to find genuinely-new unlocks worth celebrating/notifying. It
 *    mutates the marker in-memory (the caller is responsible for
 *    persisting the HouseUser) and is idempotent — a second call with the
 *    same stats returns an empty list.
 */
class TitleService {

	/**
	 * The highest tier of the retained rhythm series the user currently
	 * qualifies for, or null when no tier is reached.
	 *
	 * @return array{pack: string, packLabelKey: string, tierIndex: int, tierKey: string, labelKey: string, threshold: int}|null
	 */
	public function getCurrentTitle(HouseUser $user): ?array {
		$qualifying = $this->qualifyingTiers($user);
		return $qualifying === [] ? null : $qualifying[count($qualifying) - 1];
	}

	/**
	 * Every tier the user currently qualifies for, as a list of tier
	 * descriptors. Recomputed live from stats on every call.
	 *
	 * @return array<int, array{pack: string, packLabelKey: string, tierIndex: int, tierKey: string, labelKey: string, threshold: int}>
	 */
	public function getUnlockedTitles(HouseUser $user): array {
		return $this->qualifyingTiers($user);
	}

	/**
	 * Enrich a serialized user payload with its server-computed current
	 * title. Shared by UserController and GameLogicService so the
	 * user-list and game-state views emit the same shape.
	 *
	 * @param array<string, mixed> $data Serialized user payload
	 * @return array<string, mixed>
	 */
	public function enrichWithCurrentTitle(array $data, HouseUser $user): array {
		$data['currentTitle'] = $this->getCurrentTitle($user);
		return $data;
	}

	/**
	 * Detect titles the user newly qualifies for since the last check.
	 *
	 * Compares the live-derived unlock set against the user's
	 * `unlocked_titles` marker. Newly-crossed tiers are appended to the
	 * marker (mutating the entity in-memory) and returned so the caller can
	 * fire a notification + celebration. The caller persists the HouseUser
	 * (e.g. the existing mapper update) — this method does NOT persist, to
	 * avoid a double write.
	 *
	 * Idempotent: a second call with unchanged stats returns an empty list
	 * (everything is already marked) — earned recognition never re-celebrates
	 * after spending, undo/redo or an ordinary later break (C3g).
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
	 * Correction reconciliation (CS-PLAN-070 WP3, Q-074-01 / C3g): revoke a
	 * marked tier ONLY when the corrected valid history no longer reaches
	 * its threshold — a tier supported by any surviving valid run (earlier
	 * or later) stays marked, ordinary spending or later real streak breaks
	 * never revoke earned recognition. The caller persists the HouseUser;
	 * returns the removed tier ids.
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
	 * The retained series/tier catalog with its fixed thresholds, shaped
	 * for the titles API so the frontend can render progress without a
	 * second call.
	 *
	 * @return array<string, array{labelKey: string, signal: string, tiers: array<int, array{key: string, labelKey: string, threshold: int}>}>
	 */
	public function getCatalogView(): array {
		$view = [];
		foreach (TitleCatalog::getPackKeys() as $packKey) {
			$pack = TitleCatalog::getPack($packKey);
			$tiers = [];
			foreach ($pack['tiers'] as $tier) {
				$tiers[] = [
					'key' => $tier['key'],
					'labelKey' => $tier['labelKey'],
					'threshold' => $tier['threshold'],
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
			'threshold' => $tier['threshold'],
		];
	}

	/**
	 * Single source of the `signalValue >= threshold` qualification check.
	 *
	 * Returns tier descriptors for every tier the user currently qualifies
	 * for. Tiers are returned in ascending tier order, so the last element
	 * is the highest tier. getCurrentTitle/getUnlockedTitles/detectUnlock
	 * all derive from this so the unlock semantics can never drift.
	 *
	 * @return array<int, array{pack: string, packLabelKey: string, tierIndex: int, tierKey: string, labelKey: string, threshold: int}>
	 */
	private function qualifyingTiers(HouseUser $user): array {
		$result = [];
		foreach (TitleCatalog::getPackKeys() as $key) {
			$pack = TitleCatalog::getPack($key);
			$signalValue = TitleCatalog::getSignalValue($user, $pack['signal']);
			foreach ($pack['tiers'] as $index => $tier) {
				if ($signalValue >= $tier['threshold']) {
					$result[] = $this->tierDescriptor($key, $index);
				}
			}
		}
		return $result;
	}
}
