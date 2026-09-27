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
use OCA\ChoreStrike\Db\Medal;
use OCA\ChoreStrike\Db\MedalMapper;
use OCA\ChoreStrike\Db\TransactionExecutor;
use OCA\ChoreStrike\Db\WeeklyGoalReceiptMapper;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Owner of the RETAINED recognition awards (CS-PLAN-070 C3d / Q-074-01
 * Option A): the personal rhythm thresholds at 3/6/12 consecutive
 * successful planned task days and the household weekly milestone.
 *
 * The old activity/combo medal catalog (team_anchor, flow_keeper,
 * room_guardian, team_player) retired with the wallet cutover — no
 * wallet-qualified or raw-activity medals remain. Rhythm awards are
 * lifetime rows (`weekStart = self::LIFETIME_WEEK` sentinel, non-null per
 * the unique key) evaluated from the valid-history longest streak. Ordinary
 * spending or a later real break never revokes a legitimate award (D7c);
 * corrections revoke only unsupported recognition. A revoked row remains a
 * non-visible tombstone so a later valid run restores it without a second
 * celebration.
 *
 * Award timing stays read-triggered (GameController::checkMedals), never
 * from inside the completion transaction.
 */
class MedalService {
	public const RHYTHM_TIERS = [
		'rhythm_3' => 3,
		'rhythm_6' => 6,
		'rhythm_12' => 12,
	];

	/** The non-null lifetime sentinel for the unique (user, type, week) key. */
	public const LIFETIME_WEEK = 'lifetime';

	private HouseUserMapper $houseUserMapper;
	private MedalMapper $medalMapper;
	private NotificationService $notificationService;
	private AppLogger $appLogger;
	private WeeklyGoalService $weeklyGoalService;
	private RhythmService $rhythmService;
	private HouseMapper $houseMapper;
	private WeeklyGoalReceiptMapper $weeklyGoalReceiptMapper;
	private TransactionExecutor $transactions;

	public function __construct(
		HouseUserMapper $houseUserMapper,
		MedalMapper $medalMapper,
		NotificationService $notificationService,
		AppLogger $appLogger,
		WeeklyGoalService $weeklyGoalService,
		RhythmService $rhythmService,
		HouseMapper $houseMapper,
		WeeklyGoalReceiptMapper $weeklyGoalReceiptMapper,
		TransactionExecutor $transactions,
	) {
		$this->houseUserMapper = $houseUserMapper;
		$this->medalMapper = $medalMapper;
		$this->notificationService = $notificationService;
		$this->appLogger = $appLogger;
		$this->weeklyGoalService = $weeklyGoalService;
		$this->rhythmService = $rhythmService;
		$this->houseMapper = $houseMapper;
		$this->weeklyGoalReceiptMapper = $weeklyGoalReceiptMapper;
		$this->transactions = $transactions;
	}

	/**
	 * Check and award the retained recognition: the personal rhythm tiers
	 * off the valid-history streak plus the household weekly milestone
	 * (80% target) for the current house-local week.
	 *
	 * @param int $houseId The house ID
	 * @return Medal[]
	 */
	public function checkAndAwardMedals(int $houseId): array {
		$awardedMedals = [];
		$now = new \DateTime();
		$this->rhythmService->refreshForHouse($houseId, $now);
		$users = $this->houseUserMapper->findByHouseId($houseId);
		$existing = $this->medalMapper->findByUserIds(array_map(static fn (HouseUser $user): int => (int)$user->getId(), $users));

		// A corrected record may lose a tier, but another legitimate past run
		// preserves it. Revoked rows remain as tombstones: requalification
		// reactivates the same award without a second celebration.
		foreach ($users as $user) {
			foreach (self::RHYTHM_TIERS as $medalType => $threshold) {
				$this->reconcileExisting($existing[$user->getId()] ?? [], $medalType, self::LIFETIME_WEEK, $user->getLongestStreak() >= $threshold, $threshold);
				if ($user->getLongestStreak() >= $threshold && !$this->hasAward($existing[$user->getId()] ?? [], $medalType, self::LIFETIME_WEEK)) {
					$medal = $this->awardMedalIfNew($user, $medalType, $threshold, self::LIFETIME_WEEK);
					if ($medal !== null) {
						$awardedMedals[] = $medal;
					}
				}
			}
		}

		$progress = $this->weeklyGoalService->getWeeklyGoalProgress($houseId);
		$weekKey = $progress['weekKey'];
		$previousKey = (new \DateTime($weekKey))->modify('-7 days')->format('Y-m-d');
		$weeks = [
			$weekKey => $progress,
			$previousKey => $this->weeklyGoalService->getWeeklyGoalProgressForWeek($houseId, $previousKey, $now),
		];
		foreach ($existing as $awards) {
			foreach ($awards as $award) {
				if ($award->getMedalType() === 'weekly_milestone' && !isset($weeks[$award->getWeekStart()])) {
					$weeks[$award->getWeekStart()] = $this->weeklyGoalService->getWeeklyGoalProgressForWeek($houseId, $award->getWeekStart(), $now);
				}
			}
		}
		foreach ($weeks as $key => $week) {
			array_push($awardedMedals, ...$this->reconcileWeeklyMedals($users, $existing, $key, $week));
		}

		return $awardedMedals;
	}

	/**
	 * After timeline and pause convergence, scan saved planned-week keys in
	 * bounded pages. Old corrections and missed cron runs remain eligible.
	 */
	public function reconcileClosedWeeksForJobs(\DateTime $now): void {
		$house = $this->houseMapper->findOneForJobs();
		if ($house === null) {
			return;
		}
		$this->reconcileClosedWeeksForHouse((int)$house->getId(), $now);
	}

	public function reconcileClosedWeeksForHouse(int $houseId, \DateTime $now): void {
		$house = $this->houseMapper->find($houseId);
		$before = $this->weeklyGoalService->progressForWeek($house, $now)['weekKey'];
		$users = $this->houseUserMapper->findByHouseId($houseId);
		$existing = $this->medalMapper->findByUserIds(array_map(static fn (HouseUser $user): int => (int)$user->getId(), $users));
		$after = null;
		do {
			$weeks = $this->weeklyGoalReceiptMapper->plannedWeeksPage($houseId, $before, $after, 50);
			$claimed = $this->weeklyGoalReceiptMapper->claimedWeeks($houseId, $weeks);
			foreach ($weeks as $key) {
				$progress = $this->weeklyGoalService->progressForPlannedWeek($house, $key, $now);
				if ($progress['provisional']) {
					continue;
				}
				$needsClaim = $progress['achieved'] && !isset($claimed[$key]);
				if ($needsClaim) {
					$progress = $this->transactions->run(function () use ($houseId, $key, $now): array {
						$lockedHouse = $this->houseMapper->findForUpdate($houseId);
						return $this->weeklyGoalService->progressForPlannedWeek($lockedHouse, $key, $now);
					});
				}
				if ($progress['provisional']) {
					continue;
				}
				$this->reconcileWeeklyMedals($users, $existing, $key, $progress);
				$newClaim = $needsClaim && $progress['achieved'] ? $this->claimClosedWeek($houseId, $key, $now) : null;
				if ($newClaim !== null) {
					try {
						$this->notificationService->notifyWeeklyGoalReachedForHouse(
							$houseId, $newClaim['percent'], $newClaim['targetPercent'], $newClaim['weekStart']
						);
					} catch (\Throwable $e) {
						$this->appLogger->logException('Failed to dispatch weekly goal notification', $e, [
							'event' => 'weekly_goal_dispatch_failed',
							'houseId' => $houseId,
							'weekKey' => $key,
						]);
					}
				}
			}
			$after = $weeks === [] ? $after : end($weeks);
		} while (count($weeks) === 50);
	}

	/** @param HouseUser[] $users @param array<int, Medal[]> $existing @return Medal[] */
	private function reconcileWeeklyMedals(array $users, array $existing, string $weekKey, array $progress): array {
		$awarded = [];
		foreach ($users as $user) {
			$awards = $existing[$user->getId()] ?? [];
			$eligible = $this->wasMemberBeforeWeekEnd($user, $progress);
			$this->reconcileExisting($awards, 'weekly_milestone', $weekKey, $eligible, $progress['percent']);
			if (!$eligible || $this->hasAward($awards, 'weekly_milestone', $weekKey)) {
				continue;
			}
			$medal = $this->awardMedalIfNew($user, 'weekly_milestone', $progress['percent'], $weekKey);
			if ($medal !== null) {
				$awarded[] = $medal;
			}
		}
		return $awarded;
	}

	private function wasMemberBeforeWeekEnd(HouseUser $user, array $progress): bool {
		if (!$progress['achieved'] || $user->getJoinedAt() === null) {
			return false;
		}
		$weekEnd = new \DateTimeImmutable($progress['weekEnd']);
		return $user->getJoinedAt()->getTimestamp() < $weekEnd->getTimestamp();
	}

	private function claimClosedWeek(int $houseId, string $weekKey, \DateTime $now): ?array {
		return $this->transactions->run(function () use ($houseId, $weekKey, $now): ?array {
			$house = $this->houseMapper->findForUpdate($houseId);
			$fresh = $this->weeklyGoalService->progressForPlannedWeek($house, $weekKey, $now);
			if (!$fresh['achieved'] || $fresh['provisional'] || !$this->weeklyGoalReceiptMapper->claim($houseId, $weekKey, $now)) {
				return null;
			}
			return $fresh;
		});
	}

	/** @param Medal[] $awards */
	private function hasAward(array $awards, string $type, string $weekKey): bool {
		foreach ($awards as $award) {
			if ($award->getMedalType() === $type && $award->getWeekStart() === $weekKey) {
				return true;
			}
		}
		return false;
	}

	/** @param Medal[] $awards */
	private function reconcileExisting(array $awards, string $type, string $weekKey, bool $eligible, int $value): void {
		foreach ($awards as $award) {
			if ($award->getMedalType() === $type && $award->getWeekStart() === $weekKey) {
				$nextValue = $eligible ? $value : 0;
				if ($award->getValue() !== $nextValue) {
					$award->setValue($nextValue);
					$this->medalMapper->update($award);
				}
				return;
			}
		}
	}

	/**
	 * Get all medals for a house with user names.
	 *
	 * One findByUserIds batch fetch (CS-PLAN-036); the payload shape (house-user
	 * order, then each user's medals awarded_at DESC) is unchanged.
	 *
	 * @param int $houseId The house ID
	 * @return array Array of medals with user names
	 */
	public function getMedals(int $houseId): array {
		$users = $this->houseUserMapper->findByHouseId($houseId);

		$medalsByUserId = $this->medalMapper->findByUserIds(array_map(
			static fn (HouseUser $user) => $user->getId(),
			$users
		));

		$medals = [];
		$weeks = [];
		foreach ($users as $user) {
			foreach ($medalsByUserId[$user->getId()] ?? [] as $medal) {
				$type = $medal->getMedalType();
				$value = $medal->getValue();
				if (isset(self::RHYTHM_TIERS[$type])) {
					$value = $user->getLongestStreak() >= self::RHYTHM_TIERS[$type] ? self::RHYTHM_TIERS[$type] : 0;
				} elseif ($type === 'weekly_milestone') {
					$key = $medal->getWeekStart();
					$weeks[$key] ??= $this->weeklyGoalService->getWeeklyGoalProgressForWeek($houseId, $key, new \DateTime());
					$value = $this->wasMemberBeforeWeekEnd($user, $weeks[$key]) ? $weeks[$key]['percent'] : 0;
				}
				if ($value <= 0) {
					continue;
				}
				$medals[] = [
					'id' => $medal->getId(),
					'userId' => $medal->getUserId(),
					'userName' => $user->getDisplayName(),
					'medalType' => $medal->getMedalType(),
					'value' => $value,
					'weekStart' => $medal->getWeekStart(),
					'awardedAt' => $medal->getAwardedAt()?->format('c'),
				];
			}
		}

		return $medals;
	}

	/**
	 * Award a medal to a user if they don't already have one of this type
	 * for the given week key, and notify them on the new award.
	 *
	 * Returns null if the medal already existed (idempotent — re-checking
	 * medals doesn't re-notify the user). Race-safe: the read-then-insert
	 * window is closed by catching the unique-constraint violation on insert.
	 */
	private function awardMedalIfNew(HouseUser $user, string $medalType, int $value, string $weekKey): ?Medal {
		try {
			// Already exists — idempotent no-op.
			$this->medalMapper->findByUserTypeAndWeek($user->getId(), $medalType, $weekKey);
			return null;
		} catch (DoesNotExistException $e) {
			// Not found — create + notify.
		}

		try {
			$medal = $this->createMedal($user->getId(), $medalType, $value, $weekKey);
		} catch (\OCP\DB\Exception $e) {
			// Only a unique-constraint violation is the expected race-loser
			// case (two concurrent first-time awards for the same user+type+
			// week). Any other DB error must propagate so the medal isn't
			// silently lost.
			if ($e->getReason() !== \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $e;
			}
			$this->appLogger->warning('MedalService: concurrent medal award race lost', [
				'event' => 'medal_award_race_lost',
				'service' => 'MedalService',
				'houseUserId' => $user->getId(),
				'medalType' => $medalType,
				'weekStart' => $weekKey,
			]);
			return null;
		}

		$nextcloudUserId = $user->getNextcloudUserId();
		if ($nextcloudUserId !== null && $nextcloudUserId !== '') {
			$this->notificationService->notifyMedalAwarded(
				$nextcloudUserId,
				$medalType,
				$value,
				$weekKey
			);
		}

		return $medal;
	}

	/**
	 * Construct and persist a new Medal row.
	 */
	private function createMedal(int $userId, string $medalType, int $value, string $weekStart): Medal {
		$medal = new Medal();
		$medal->setUserId($userId);
		$medal->setMedalType($medalType);
		$medal->setValue($value);
		$medal->setWeekStart($weekStart);
		$medal->setAwardedAt(new \DateTime());
		return $this->medalMapper->insert($medal);
	}
}
