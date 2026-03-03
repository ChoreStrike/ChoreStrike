<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\Medal;
use OCA\ChoreStrike\Db\MedalMapper;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\CompletedTaskMapper;

/**
 * Service for managing medals and achievements
 */
class MedalService {

	private const MEDAL_TYPES = [
		'team_anchor' => [
			'name' => 'Team Anchor',
			'description' => 'Complete 10+ tasks in a week',
			'points' => 50,
			'rarity' => 'common',
			'icon' => '🏆',
		],
		'streak_keeper' => [
			'name' => 'Streak Keeper',
			'description' => 'Maintain a 7+ day streak',
			'points' => 100,
			'rarity' => 'rare',
			'icon' => '🔥',
		],
		'combo_master' => [
			'name' => 'Combo Master',
			'description' => 'Achieve a 5+ task combo',
			'points' => 75,
			'rarity' => 'rare',
			'icon' => '⚡',
		],
		'perfect_week' => [
			'name' => 'Perfect Week',
			'description' => 'Complete all tasks assigned in a week',
			'points' => 200,
			'rarity' => 'epic',
			'icon' => '✨',
		],
		'legendary_helper' => [
			'name' => 'Legendary Helper',
			'description' => 'Complete 100+ total tasks',
			'points' => 500,
			'rarity' => 'legendary',
			'icon' => '👑',
		],
	];

	public function __construct(
		private MedalMapper $medalMapper,
		private HouseUserMapper $houseUserMapper,
		private CompletedTaskMapper $completedTaskMapper,
		private UserStatsService $userStatsService,
		private ComboService $comboService,
	) {
	}

	/**
	 * Check and award medals for all users in house
	 */
	public function checkAndAwardMedals(int $houseId): array {
		$users = $this->houseUserMapper->findByHouseId($houseId);
		$awardedMedals = [];

		foreach ($users as $user) {
			$userId = $user->getNextcloudUserId();
			$stats = $this->userStatsService->getOrCreateStats($houseId, $userId);

			// Check Team Anchor (10+ tasks this week)
			$weekTasks = $this->getWeekTaskCount($houseId, $userId);
			if ($weekTasks >= 10) {
				$medal = $this->awardMedal($houseId, $userId, 'team_anchor');
				if ($medal) {
					$awardedMedals[] = $medal;
				}
			}

			// Check Streak Keeper (7+ day streak)
			if ($stats->getCurrentStreak() >= 7) {
				$medal = $this->awardMedal($houseId, $userId, 'streak_keeper');
				if ($medal) {
					$awardedMedals[] = $medal;
				}
			}

			// Check Combo Master (5+ task combo)
			$comboStats = $this->comboService->getComboStats($houseId);
			foreach ($comboStats as $stat) {
				if ($stat['user_id'] === $userId && $stat['max_combo'] >= 5) {
					$medal = $this->awardMedal($houseId, $userId, 'combo_master');
					if ($medal) {
						$awardedMedals[] = $medal;
					}
					break;
				}
			}

			// Check Legendary Helper (100+ total tasks)
			if ($stats->getTasksCompleted() >= 100) {
				$medal = $this->awardMedal($houseId, $userId, 'legendary_helper');
				if ($medal) {
					$awardedMedals[] = $medal;
				}
			}
		}

		return $awardedMedals;
	}

	/**
	 * Award specific medal to user
	 */
	public function awardMedal(int $houseId, string $userId, string $medalType): ?Medal {
		// Check if medal already awarded this week
		if ($this->hasMedalThisWeek($houseId, $userId, $medalType)) {
			return null;
		}

		$medal = new Medal();
		$medal->setUserId($userId);
		$medal->setMedalType($medalType);
		$medal->setValue(self::MEDAL_TYPES[$medalType]['points'] ?? 0);
		$medal->setWeekStart($this->getWeekStart());
		$medal->setAwardedAt(new \DateTime());

		return $this->medalMapper->insert($medal);
	}

	/**
	 * Check if medal already awarded this week
	 */
	public function hasMedalThisWeek(int $houseId, string $userId, string $medalType): bool {
		try {
			// userId in Medal entity is int (house_user.id), convert from Nextcloud string userId
			$this->medalMapper->findByUserTypeAndWeek((int)$userId, $medalType, $this->getWeekStart());
			return true;
		} catch (\Exception $e) {
			return false;
		}
	}

	/**
	 * Get medal information
	 */
	public function getMedalInfo(string $medalType): array {
		return self::MEDAL_TYPES[$medalType] ?? [];
	}

	/**
	 * Get all medal types
	 */
	public function getAllMedals(): array {
		return self::MEDAL_TYPES;
	}

	/**
	 * Get user medals
	 *
	 * @return Medal[]
	 */
	public function getUserMedals(string $userId): array {
		// userId in Medal entity is int (house_user.id), convert from Nextcloud string userId
		return $this->medalMapper->findByUserId((int)$userId);
	}

	/**
	 * Get week task count for user
	 */
	private function getWeekTaskCount(int $houseId, string $userId): int {
		$weekStart = new \DateTime($this->getWeekStart());
		$weekEnd = new \DateTime($this->getWeekStart());
		$weekEnd->modify('+7 days');

		// userId in CompletedTask is int (house_user.id), convert from Nextcloud string userId
		$stats = $this->completedTaskMapper->getUserStats((int)$userId, $weekStart, $weekEnd);
		return $stats['taskCount'] ?? 0;
	}

	/**
	 * Get week start date (Monday)
	 */
	private function getWeekStart(): string {
		$date = new \DateTime();
		$day = (int)$date->format('N');
		$diff = $day - 1;
		$date->modify("-{$diff} days");
		return $date->format('Y-m-d');
	}
}
