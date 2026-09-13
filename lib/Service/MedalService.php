<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\CompletedTaskMapper;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\Medal;
use OCA\ChoreStrike\Db\MedalMapper;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Owner of the weekly medal domain (CS-PLAN-067 §3.2): the medal
 * evaluation rules, the race-safe award primitive and the house medal
 * listing — extracted from GameLogicService as one cohesive cluster.
 *
 * Award timing is UNCHANGED: medals stay read-triggered
 * (GameController::checkMedals), never awarded from inside the completion
 * transaction.
 */
class MedalService {
	public const MEDAL_TEAM_ANCHOR_MIN_TASKS = 10;
	public const MEDAL_STREAK_KEEPER_MIN_DAYS = 7;
	public const MEDAL_FLOW_KEEPER_MIN_ACTIVE_DAYS = 4;
	public const MEDAL_ROOM_GUARDIAN_MIN_PER_ROOM = 5;
	public const MEDAL_TEAM_PLAYER_MIN_COMBOS = 3;

	private HouseUserMapper $houseUserMapper;
	private CompletedTaskMapper $completedTaskMapper;
	private MedalMapper $medalMapper;
	private NotificationService $notificationService;
	private AppLogger $appLogger;
	private HouseMapper $houseMapper;
	private CalendarService $calendarService;

	public function __construct(
		HouseUserMapper $houseUserMapper,
		CompletedTaskMapper $completedTaskMapper,
		MedalMapper $medalMapper,
		NotificationService $notificationService,
		AppLogger $appLogger,
		HouseMapper $houseMapper,
		CalendarService $calendarService,
	) {
		$this->houseUserMapper = $houseUserMapper;
		$this->completedTaskMapper = $completedTaskMapper;
		$this->medalMapper = $medalMapper;
		$this->notificationService = $notificationService;
		$this->appLogger = $appLogger;
		$this->houseMapper = $houseMapper;
		$this->calendarService = $calendarService;
	}

	/**
	 * Check and award medals for weekly achievements.
	 *
	 * The completion stats and max-room-count aggregates are fetched once
	 * per house as grouped maps (CS-PLAN-036) instead of two queries per
	 * user; users absent from a map get zero defaults. Medal existence
	 * checks, inserts, and notifications stay deliberately per-row: the
	 * unique-constraint race handling in awardMedalIfNew and the
	 * per-award notification are inherently per-row concerns.
	 *
	 * The medal week runs Monday 00:00 → Monday 00:00 in the house
	 * timezone (CS-PLAN-057); the medal week key is the house-local
	 * Y-m-d of the week start.
	 *
	 * @param int $houseId The house ID
	 */
	public function checkAndAwardMedals(int $houseId): array {
		$house = $this->houseMapper->find($houseId);
		$tz = $this->calendarService->getTimeZone($house);
		$weekStart = $this->calendarService->weekStart(new \DateTime(), $tz);
		$weekEnd = (clone $weekStart)->modify('+7 days');
		$weekStartKey = $this->calendarService->dayKey($weekStart, $tz);

		$users = $this->houseUserMapper->findByHouseId($houseId);
		$zeroStats = ['taskCount' => 0, 'totalCoins' => 0, 'comboCount' => 0, 'activeDays' => 0];
		$statsByUser = $this->completedTaskMapper->getUserStatsByHouse($houseId, $weekStart, $weekEnd);
		$maxPerRoomByUser = $this->completedTaskMapper->getMaxRoomCompletionCountByHouse($houseId, $weekStart, $weekEnd);

		$awardedMedals = [];

		foreach ($users as $user) {
			$stats = $statsByUser[$user->getId()] ?? $zeroStats;

			if ($stats['taskCount'] >= self::MEDAL_TEAM_ANCHOR_MIN_TASKS) {
				$medal = $this->awardMedalIfNew($user, 'team_anchor', $stats['taskCount'], $weekStartKey);
				if ($medal !== null) {
					$awardedMedals[] = $medal;
				}
			}

			if ($user->getCurrentStreak() >= self::MEDAL_STREAK_KEEPER_MIN_DAYS) {
				$medal = $this->awardMedalIfNew($user, 'streak_keeper', $user->getCurrentStreak(), $weekStartKey);
				if ($medal !== null) {
					$awardedMedals[] = $medal;
				}
			}

			$activeDays = $stats['activeDays'] ?? 0;
			if ($activeDays >= self::MEDAL_FLOW_KEEPER_MIN_ACTIVE_DAYS) {
				$medal = $this->awardMedalIfNew($user, 'flow_keeper', $activeDays, $weekStartKey);
				if ($medal !== null) {
					$awardedMedals[] = $medal;
				}
			}

			$maxPerRoom = $maxPerRoomByUser[$user->getId()] ?? 0;
			if ($maxPerRoom >= self::MEDAL_ROOM_GUARDIAN_MIN_PER_ROOM) {
				$medal = $this->awardMedalIfNew($user, 'room_guardian', $maxPerRoom, $weekStartKey);
				if ($medal !== null) {
					$awardedMedals[] = $medal;
				}
			}

			// team_player is driven by comboCount (wasCombo flag), not the
			// dead isTeamCompletion/participantCount/teamSparkBonus residue.
			$combos = $stats['comboCount'] ?? 0;
			if ($combos >= self::MEDAL_TEAM_PLAYER_MIN_COMBOS) {
				$medal = $this->awardMedalIfNew($user, 'team_player', $combos, $weekStartKey);
				if ($medal !== null) {
					$awardedMedals[] = $medal;
				}
			}
		}

		return $awardedMedals;
	}

	/**
	 * Get all medals for a house with user names.
	 *
	 * One findByUserIds batch fetch (CS-PLAN-036) instead of a per-user
	 * lookup; the payload shape (house-user order, then each user's medals
	 * awarded_at DESC) is unchanged.
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
		foreach ($users as $user) {
			foreach ($medalsByUserId[$user->getId()] ?? [] as $medal) {
				$medals[] = [
					'id' => $medal->getId(),
					'userId' => $medal->getUserId(),
					'userName' => $user->getDisplayName(),
					'medalType' => $medal->getMedalType(),
					'value' => $medal->getValue(),
					'weekStart' => $medal->getWeekStart(),
					'awardedAt' => $medal->getAwardedAt()?->format('c'),
				];
			}
		}

		return $medals;
	}

	/**
	 * Award a medal to a user if they don't already have one of this type for
	 * the given week, and notify them on the new award.
	 *
	 * Returns null if the medal already existed (idempotent — re-checking
	 * medals in a week doesn't re-notify the user). Returns the new Medal
	 * entity otherwise, and dispatches a best-effort notification to the
	 * member's Nextcloud account so they see it in the bell + mobile feed.
	 *
	 * Race-safe: the read-then-insert window is closed by catching the
	 * unique-constraint violation on insert (the loser of two concurrent
	 * first-time awards returns null; the winner's medal exists).
	 */
	private function awardMedalIfNew(HouseUser $user, string $medalType, int $value, string $weekStart): ?Medal {
		try {
			// Already exists — idempotent no-op.
			$this->medalMapper->findByUserTypeAndWeek($user->getId(), $medalType, $weekStart);
			return null;
		} catch (DoesNotExistException $e) {
			// Not found — create + notify.
		}

		try {
			$medal = $this->createMedal($user->getId(), $medalType, $value, $weekStart);
		} catch (\OCP\DB\Exception $e) {
			// Only a unique-constraint violation is the expected race-loser
			// case (two concurrent first-time awards for the same user+type+
			// week). Any other DB error (FK violation, deadlock, connection
			// loss, NOT NULL) must propagate so the medal isn't silently
			// lost.
			if ($e->getReason() !== \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $e;
			}
			// Expected race-loser case (two concurrent first-time awards for
			// the same user+type+week): the medal exists, this invocation is
			// a no-op. Logged so the swallow is observable without changing
			// the idempotent outcome.
			$this->appLogger->warning('MedalService: concurrent medal award race lost', [
				'event' => 'medal_award_race_lost',
				'service' => 'MedalService',
				'houseUserId' => $user->getId(),
				'medalType' => $medalType,
				'weekStart' => $weekStart,
			]);
			return null;
		}

		$nextcloudUserId = $user->getNextcloudUserId();
		if ($nextcloudUserId !== null && $nextcloudUserId !== '') {
			$this->notificationService->notifyMedalAwarded(
				$nextcloudUserId,
				$medalType,
				$value,
				$weekStart
			);
		}

		return $medal;
	}

	/**
	 * Construct and persist a new Medal row.
	 *
	 * Shared by the (single) award path so there is exactly one definition of
	 * how a Medal row is built. Throws the underlying mapper exception on
	 * failure so the caller can react (e.g. awardMedalIfNew treats a unique-
	 * constraint violation as the medal already existing).
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
