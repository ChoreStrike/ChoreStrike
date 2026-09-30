<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\CompletedTaskMapper;
use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\MemberPreference;
use OCA\ChoreStrike\Db\MemberPreferenceMapper;
use OCA\ChoreStrike\Db\Occurrence;
use OCA\ChoreStrike\Db\OccurrenceMapper;
use OCA\ChoreStrike\Db\OccurrenceMember;
use OCA\ChoreStrike\Db\OccurrenceMemberMapper;
use OCA\ChoreStrike\Db\TransactionExecutor;
use OCA\ChoreStrike\Db\WalletMapper;
use OCA\ChoreStrike\Db\WorkShareMapper;

/**
 * Consent-filtered chore ranking (CS-PLAN-070 WP4, C5i/C5l/D10b,
 * Q-075-01 Option A).
 *
 * The ranking shows, for the current house week, ONE visible entry per
 * EFFECTIVELY CONSENTED member: the on-time ratio of that member's
 * personal agreed occurrences on household-public tasks (the public
 * flag frozen at agreement time). Non-consenting members, foreign consent
 * states, private-task facts, Coins and raw contribution are never
 * exposed — the filter lives here, server-side, never client-side.
 *
 * Comparison is EXACT: ratios are compared by cross-multiplication of the
 * integer counts (no floats); equal ratios tie; zero-denominator members
 * (no eligible public occurrences this week) render neutral/unranked.
 * The week is provisional while it or any included window is open.
 * Consented-but-not-yet-effective members appear as pending, without a
 * rank (C5l): a new consent generation activates the NEXT Monday 00:00 in
 * the house timezone (saved with that zone), never retroactively.
 *
 * Consent is personal and self-only (children included — C5i). Withdrawal
 * and a house-flag disable remove visible participation immediately and
 * invalidate every generation; re-enabling starts everyone unconsented.
 * Consent belongs to the active membership: departure deletes the row,
 * erasure purges it, rejoining starts quiet.
 */
class RankingService {
	private HouseMapper $houseMapper;
	private HouseUserMapper $houseUserMapper;
	private MemberPreferenceMapper $prefMapper;
	private OccurrenceMapper $occurrenceMapper;
	private OccurrenceMemberMapper $occMemberMapper;
	private CompletedTaskMapper $completedTaskMapper;
	private WalletMapper $walletMapper;
	private WorkShareMapper $workShareMapper;
	private CalendarService $calendar;
	private TransactionExecutor $transactions;

	public function __construct(
		HouseMapper $houseMapper,
		HouseUserMapper $houseUserMapper,
		MemberPreferenceMapper $prefMapper,
		OccurrenceMapper $occurrenceMapper,
		OccurrenceMemberMapper $occMemberMapper,
		CompletedTaskMapper $completedTaskMapper,
		WalletMapper $walletMapper,
		WorkShareMapper $workShareMapper,
		CalendarService $calendar,
		TransactionExecutor $transactions,
	) {
		$this->houseMapper = $houseMapper;
		$this->houseUserMapper = $houseUserMapper;
		$this->prefMapper = $prefMapper;
		$this->occurrenceMapper = $occurrenceMapper;
		$this->occMemberMapper = $occMemberMapper;
		$this->completedTaskMapper = $completedTaskMapper;
		$this->walletMapper = $walletMapper;
		$this->workShareMapper = $workShareMapper;
		$this->calendar = $calendar;
		$this->transactions = $transactions;
	}

	/**
	 * The consent-filtered ranking view for the caller's house.
	 *
	 * @param int $houseId The house ID
	 * @param HouseUser $viewer The calling member (own consent state only)
	 * @param \DateTime|null $now Injectable clock (tests)
	 * @return array{rankingEnabled: bool, week: array{weekKey: string, weekStart: string, weekEnd: string, provisional: bool}, me: array{state: string, generation: int|null, effectiveMonday: string|null}, entries: list<array{houseUserId: int, displayName: string, eligible: int, onTime: int, neutral: bool, rank: int|null, tied: bool, isMe: bool}>, pending: list<array{houseUserId: int, displayName: string, effectiveMonday: string}>}
	 */
	public function getRankingView(int $houseId, HouseUser $viewer, ?\DateTime $now = null): array {
		$now ??= new \DateTime();
		$house = $this->houseMapper->find($houseId);
		$week = $this->weekFacts($house, $now);
		$me = ['state' => 'none', 'generation' => null, 'effectiveMonday' => null];
		$entries = [];
		$pending = [];

		if ($house->getRankingEnabled()) {
			// Key the consent rows by membership id (the mapper returns a
			// list). Ranking-inactive rows (generation 0 — delivery-only
			// preference rows, RF-076-01) never enter the ranking.
			$prefs = [];
			foreach ($this->prefMapper->findByHouseId($houseId) as $pref) {
				if ($pref->getRankingConsentGeneration() > 0) {
					$prefs[(int)$pref->getHouseUserId()] = $pref;
				}
			}
			$members = [];
			foreach ($this->houseUserMapper->findByHouseId($houseId) as $member) {
				$members[(int)$member->getId()] = $member;
			}

			[$ratioFacts, $weekOpen] = $this->ratioFacts($house, $prefs, $members, $now);
			$week['provisional'] = $week['provisional'] || $weekOpen;
			[$scored, $pending] = $this->splitEffective($prefs, $members, $viewer, $now, $ratioFacts);
			$entries = $this->rank($scored);
			$me = $this->ownState($prefs, (int)$viewer->getId(), $now);
		}

		return [
			'rankingEnabled' => $house->getRankingEnabled(),
			'week' => $week,
			'me' => $me,
			'entries' => $entries,
			'pending' => $pending,
		];
	}

	/**
	 * Give ranking consent for the calling membership (self-only, children
	 * included). Creates a fresh generation effective next Monday 00:00 in
	 * the house timezone, saved with that zone; never retroactive. An
	 * existing pending generation is replaced by the new one.
	 *
	 * Q-076-03 A: the consent updates the membership's preference row IN
	 * PLACE (or inserts it when absent) — delivery preferences and the
	 * shared `version` chain survive every consent transition.
	 *
	 * @return array{state: string, generation: int, effectiveMonday: string}
	 */
	public function consent(int $houseId, HouseUser $actor, ?\DateTime $now = null): array {
		$now ??= new \DateTime();
		return $this->transactions->run(function () use ($houseId, $actor, $now): array {
			// FIRST lock of the documented order: the house row, then the
			// membership row — consent belongs to the ACTIVE membership, so
			// the fresh row state decides.
			$house = $this->houseMapper->findForUpdate($houseId);
			$locked = $this->houseUserMapper->findForUpdate((int)$actor->getId());

			$tz = $this->calendar->getTimeZone($house);
			$monday = $this->nextMonday($now, $tz);
			// The generation watermark lives on the locked house row (consent
			// states are resettable — the max-row heuristic cannot survive a
			// withdrawal, the watermark can).
			$generation = $house->getRankingGenerationSeq() + 1;
			$house->setRankingGenerationSeq($generation);
			$this->houseMapper->update($house);

			// In-place consent (Q-076-03 A): the singular row carries both
			// the consent block and the delivery preferences.
			$pref = $this->prefMapper->findByHouseAndUser($houseId, (int)$locked->getId());
			if ($pref === null) {
				$pref = new MemberPreference();
				$pref->setHouseId($houseId);
				$pref->setHouseUserId((int)$locked->getId());
				$pref->setCreatedAt($now);
				$pref->setVersion(1);
				$created = true;
			} else {
				$pref->setVersion($pref->getVersion() + 1);
				$created = false;
			}
			$pref->setRankingConsentGeneration($generation);
			$pref->setRankingEffectiveMonday($monday);
			$pref->setRankingSavedZone($tz->getName());
			$pref->setUpdatedAt($now);
			$pref = $created ? $this->prefMapper->insertOrGet($pref) : $this->prefMapper->update($pref);

			return [
				'state' => 'pending',
				'generation' => (int)$pref->getRankingConsentGeneration(),
				'effectiveMonday' => $pref->getRankingEffectiveMonday()?->format('Y-m-d'),
			];
		});
	}

	/**
	 * Withdraw the calling membership's ranking consent. Removes the
	 * member from the visible ranking immediately and invalidates the
	 * generation (re-consent starts a fresh generation at the next full
	 * week). Q-076-03 A: ONLY the consent columns are reset — delivery
	 * preferences survive. Idempotent — withdrawing without consent is a
	 * no-op.
	 */
	public function withdraw(int $houseId, HouseUser $actor): void {
		$this->transactions->run(function () use ($houseId, $actor): void {
			$this->houseMapper->findForUpdate($houseId);
			$locked = $this->houseUserMapper->findForUpdate((int)$actor->getId());
			$this->prefMapper->clearRankingConsentByHouseAndUser($houseId, (int)$locked->getId(), new \DateTime());
		});
	}

	/**
	 * The house-week facts shared by every ranking response.
	 *
	 * @return array{weekKey: string, weekStart: string, weekEnd: string, provisional: bool}
	 */
	private function weekFacts(House $house, \DateTime $now): array {
		$tz = $this->calendar->getTimeZone($house);
		$start = $this->calendar->weekStart($now, $tz);
		$end = (clone $start)->modify('+7 days');
		return [
			'weekKey' => $start->format('Y-m-d'),
			'weekStart' => $start->format('c'),
			'weekEnd' => $end->format('c'),
			'provisional' => $now < $end,
		];
	}

	/**
	 * The next Monday 00:00 strictly after `$now`, in the house zone (the
	 * Monday of the FOLLOWING house-local week — a consent never activates
	 * mid-week).
	 */
	private function nextMonday(\DateTime $now, \DateTimeZone $tz): \DateTime {
		$monday = $this->calendar->weekStart($now, $tz);
		return (clone $monday)->modify('+7 days');
	}

	/**
	 * The caller's own consent state: none / pending / effective.
	 *
	 * @param array<int, MemberPreference> $prefs
	 * @return array{state: string, generation: int|null, effectiveMonday: string|null}
	 */
	private function ownState(array $prefs, int $viewerId, \DateTime $now): array {
		$pref = $prefs[$viewerId] ?? null;
		if ($pref === null) {
			return ['state' => 'none', 'generation' => null, 'effectiveMonday' => null];
		}
		if (!$pref->isEffectiveAt($now)) {
			return [
				'state' => 'pending',
				'generation' => (int)$pref->getRankingConsentGeneration(),
				'effectiveMonday' => $pref->getRankingEffectiveMonday()->format('Y-m-d'),
			];
		}
		return [
			'state' => 'effective',
			'generation' => (int)$pref->getRankingConsentGeneration(),
			'effectiveMonday' => $pref->getRankingEffectiveMonday()->format('Y-m-d'),
		];
	}

	/**
	 * Per-member current-week on-time facts over household-public agreed
	 * occurrences — the SAME occurrence/completion/selected-share reads
	 * the rhythm/goal evaluation uses (obligated, active disposition,
	 * non-suspended, planned in the current Monday-Sunday house week,
	 * valid CLASS_AGREED on-time completion covering the member).
	 *
	 * @param array<int, MemberPreference> $prefs
	 * @param array<int, HouseUser> $members
	 * @return array{0: array<int, array{eligible: int, onTime: int}>, 1: bool} membership id => facts, and whether any included window is still open
	 */
	private function ratioFacts(House $house, array $prefs, array $members, \DateTime $now): array {
		$facts = [];
		foreach (array_keys($prefs) as $houseUserId) {
			$facts[$houseUserId] = ['eligible' => 0, 'onTime' => 0];
		}
		$weekOpen = false;
		if ($facts === []) {
			return [$facts, $weekOpen];
		}

		$tz = $this->calendar->getTimeZone($house);
		$weekStart = $this->calendar->weekStart($now, $tz);
		$fromKey = $weekStart->format('Y-m-d');
		$toKey = (clone $weekStart)->modify('+6 days')->format('Y-m-d');
		$rows = $this->occurrenceMapper->findByHousePlannedBetween((int)$house->getId(), $fromKey, $toKey);
		if ($rows === []) {
			return [$facts, $weekOpen];
		}

		// Membership id by wallet id — the occ_members linkage is wallet-
		// keyed (persistent financial identity), the ranking is per
		// membership.
		$walletToMember = [];
		$walletIds = [];
		foreach ($members as $member) {
			if ($member->getWalletId() !== null) {
				$walletToMember[(int)$member->getWalletId()] = (int)$member->getId();
				$walletIds[] = (int)$member->getWalletId();
			}
		}
		if ($walletIds === []) {
			return [$facts, $weekOpen];
		}

		$ids = array_map(static fn (Occurrence $row): int => (int)$row->getId(), $rows);
		$membersByOccurrence = $this->occMemberMapper->findByOccurrenceIds($ids);
		$completionIds = array_values(array_unique(array_filter(array_map(
			static fn (Occurrence $row): ?int => $row->getCompletionId(), $rows
		))));
		$completions = $this->completedTaskMapper->findValidByIds($completionIds);
		$shares = $this->workShareMapper->findSelectedByCompletionIds(array_keys($completions));

		foreach ($rows as $occurrence) {
			// Public-at-agreement: the classification frozen on the row at
			// agreement time — later task reclassification never rewrites it.
			if ($occurrence->getIsPublic() !== true) {
				continue;
			}
			$completion = $completions[(int)($occurrence->getCompletionId() ?? 0)] ?? null;
			$onTime = $completion !== null
				&& $completion->isAgreedOnTime($occurrence->getWindowStart(), $occurrence->getWindowEnd());
			$suspended = $occurrence->getSuspensionState() === Occurrence::SUSPENSION_SUSPENDED;
			$windowEnd = $occurrence->getWindowEnd();
			if ($windowEnd !== null && $windowEnd > $now) {
				$weekOpen = true;
			}
			$wallets = [];
			foreach ($shares[(int)($occurrence->getCompletionId() ?? 0)] ?? [] as $share) {
				if ($share->getWalletId() !== null) {
					$wallets[(int)$share->getWalletId()] = true;
				}
			}
			foreach ($membersByOccurrence[(int)$occurrence->getId()] ?? [] as $memberRow) {
				$houseUserId = $walletToMember[(int)$memberRow->getWalletId()] ?? null;
				if ($houseUserId === null || !isset($facts[$houseUserId])) {
					continue;
				}
				if (!$memberRow->getObligated() || $memberRow->getDisposition() !== OccurrenceMember::DISPOSITION_ACTIVE) {
					continue;
				}
				$fulfilled = $onTime && isset($wallets[(int)$memberRow->getWalletId()]);
				if ($suspended && !$fulfilled) {
					// Recognized suspension: the unfulfilled occurrence is
					// neutral (consumes no live planned capacity); kept
					// work still counts.
					continue;
				}
				$facts[$houseUserId]['eligible']++;
				if ($fulfilled) {
					$facts[$houseUserId]['onTime']++;
				}
			}
		}
		return [$facts, $weekOpen];
	}

	/**
	 * Split consented members into ranked-effective and pending groups.
	 *
	 * @param array<int, MemberPreference> $prefs
	 * @param array<int, HouseUser> $members
	 * @param array<int, array{eligible: int, onTime: int}> $ratioFacts
	 * @return array{0: list<array{houseUserId: int, displayName: string, eligible: int, onTime: int, isMe: bool}>, 1: list<array{houseUserId: int, displayName: string, effectiveMonday: string}>}
	 */
	private function splitEffective(array $prefs, array $members, HouseUser $viewer, \DateTime $now, array $ratioFacts): array {
		$effective = [];
		$pending = [];
		foreach ($prefs as $houseUserId => $pref) {
			$member = $members[$houseUserId] ?? null;
			if ($member === null) {
				continue;
			}
			if (!$pref->isEffectiveAt($now)) {
				$pending[] = [
					'houseUserId' => $houseUserId,
					'displayName' => $member->getDisplayName(),
					'effectiveMonday' => $pref->getRankingEffectiveMonday()->format('Y-m-d'),
				];
				continue;
			}
			$fact = $ratioFacts[$houseUserId];
			$effective[] = [
				'houseUserId' => $houseUserId,
				'displayName' => $member->getDisplayName(),
				'eligible' => $fact['eligible'],
				'onTime' => $fact['onTime'],
				'isMe' => $houseUserId === (int)$viewer->getId(),
			];
		}
		return [$effective, $pending];
	}

	/**
	 * Exact-rational ranking: sort by ratio desc (cross-multiplied
	 * comparisons, no floats), standard competition ranks (ties share the
	 * better rank), zero-denominator members neutral and unranked.
	 *
	 * @param list<array{houseUserId: int, displayName: string, eligible: int, onTime: int, isMe: bool}> $effective
	 * @return list<array{houseUserId: int, displayName: string, eligible: int, onTime: int, neutral: bool, rank: int|null, tied: bool, isMe: bool}>
	 */
	private function rank(array $effective): array {
		$ranked = [];
		$neutral = [];
		foreach ($effective as $entry) {
			if ($entry['eligible'] <= 0) {
				$neutral[] = [
					'houseUserId' => $entry['houseUserId'],
					'displayName' => $entry['displayName'],
					'eligible' => 0,
					'onTime' => 0,
					'neutral' => true,
					'rank' => null,
					'tied' => false,
					'isMe' => $entry['isMe'],
				];
				continue;
			}
			$ranked[] = $entry;
		}

		// onTime_a / eligible_a > onTime_b / eligible_b  ⇔  onTime_a * eligible_b > onTime_b * eligible_a
		usort($ranked, static function (array $a, array $b): int {
			$left = $a['onTime'] * $b['eligible'];
			$right = $b['onTime'] * $a['eligible'];
			return $right <=> $left;
		});

		$entries = [];
		$previous = null;
		$rank = 0;
		foreach ($ranked as $index => $entry) {
			$tied = $previous !== null
				&& $previous['onTime'] * $entry['eligible'] === $entry['onTime'] * $previous['eligible'];
			if (!$tied) {
				$rank = $index + 1;
			} else {
				// A tie inherits the flagged rank marker of its first member.
				$entries[$index - 1]['tied'] = true;
			}
			$previous = $entry;
			$entries[] = [
				'houseUserId' => $entry['houseUserId'],
				'displayName' => $entry['displayName'],
				'eligible' => $entry['eligible'],
				'onTime' => $entry['onTime'],
				'neutral' => false,
				'rank' => $rank,
				'tied' => $tied,
				'isMe' => $entry['isMe'],
			];
		}

		return array_merge($entries, $neutral);
	}
}
