<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\CompletedTask;
use OCA\ChoreStrike\Db\CompletedTaskMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\Medal;
use OCA\ChoreStrike\Db\MedalMapper;
use OCA\ChoreStrike\Db\RoomMapper;
use OCA\ChoreStrike\Db\Task;

/**
 * Service for game logic and mechanics
 */
class GameLogicService {

	private HouseUserMapper $houseUserMapper;
	private CompletedTaskMapper $completedTaskMapper;
	private MedalMapper $medalMapper;
	private RoomMapper $roomMapper;

	public function __construct(
		HouseUserMapper $houseUserMapper,
		CompletedTaskMapper $completedTaskMapper,
		MedalMapper $medalMapper,
		RoomMapper $roomMapper
	) {
		$this->houseUserMapper = $houseUserMapper;
		$this->completedTaskMapper = $completedTaskMapper;
		$this->medalMapper = $medalMapper;
		$this->roomMapper = $roomMapper;
	}

	/**
	 * Handle task completion with game logic
	 *
	 * @param int $roomTaskId The room task ID
	 * @param int $userId The house user ID
	 * @param Task $task The task entity
	 * @param int $roomCleanliness Current room cleanliness
	 */
	public function handleTaskCompletion(int $roomTaskId, int $userId, Task $task, int $roomCleanliness): array {
		$user = $this->houseUserMapper->find($userId);

		$contributionScore = $this->calculateContributionScore($task, $roomCleanliness);
		$recentCompletions = $this->completedTaskMapper->findRecentByUserId($userId, 5);
		$isCombo = count($recentCompletions) >= 2;
		$teamSparks = $this->calculateTeamSparks($task, $isCombo);

		$completedTask = new CompletedTask();
		$completedTask->setRoomTaskId($roomTaskId);
		$completedTask->setUserId($userId);
		$completedTask->setCompletedAt(new \DateTime());
		$completedTask->setSparksEarned($teamSparks);
		$completedTask->setWasCombo($isCombo);
		$this->completedTaskMapper->insert($completedTask);

		$user->addContributionScore($contributionScore);
		$user->addTeamSparks($teamSparks);
		
		// Update streak respecting absence days
		$preferences = $user->getSchedulingPreferences();
		$weekdayAvailability = $preferences['weekdayAvailability'] ?? [];
		$user->updateStreakWithAbsence($weekdayAvailability);
		
		$user->touch();
		$this->houseUserMapper->update($user);

		return [
			'contributionScore' => $contributionScore,
			'teamSparks' => $teamSparks,
			'isCombo' => $isCombo,
			'currentStreak' => $user->getCurrentStreak(),
			'totalContribution' => $user->getContributionScore(),
			'totalSparks' => $user->getTeamSparks(),
		];
	}

	/**
	 * Calculate contribution score for task completion
	 *
	 * @param Task $task The task
	 * @param int $roomCleanliness Current room cleanliness (0-100)
	 */
	public function calculateContributionScore(Task $task, int $roomCleanliness): int {
		$baseScore = $task->getEnergyCost() * 10;

		if ($task->getPriority() === 3) {
			$baseScore = (int)($baseScore * 1.5);
		} elseif ($task->getPriority() === 2) {
			$baseScore = (int)($baseScore * 1.2);
		}

		if ($roomCleanliness < 20) {
			$baseScore = (int)($baseScore * 1.5);
		} elseif ($roomCleanliness < 40) {
			$baseScore = (int)($baseScore * 1.3);
		}

		return $baseScore;
	}

	/**
	 * Calculate Team Sparks for task completion
	 *
	 * @param Task $task The task
	 * @param bool $isCombo Whether this is part of a combo
	 */
	public function calculateTeamSparks(Task $task, bool $isCombo = false): int {
		$sparks = $task->getEnergyCost() * 5;

		if ($isCombo) {
			$sparks *= 2;
		}

		if ($task->getPriority() === 3) {
			$sparks += 10;
		} elseif ($task->getPriority() === 2) {
			$sparks += 5;
		}

		return $sparks;
	}

	/**
	 * Calculate monster count based on room cleanliness
	 *
	 * @param int $cleanliness Room cleanliness (0-100)
	 */
	public function calculateMonsterCount(int $cleanliness): int {
		if ($cleanliness >= 80) {
			return 0;
		} elseif ($cleanliness >= 60) {
			return 1;
		} elseif ($cleanliness >= 40) {
			return 2;
		} elseif ($cleanliness >= 20) {
			return 3;
		} else {
			return 4;
		}
	}

	/**
	 * Get monster type based on cleanliness
	 *
	 * @param int $cleanliness Room cleanliness (0-100)
	 */
	public function getMonsterType(int $cleanliness): string {
		if ($cleanliness >= 60) {
			return 'dust-bunny';
		} elseif ($cleanliness >= 40) {
			return 'slime-blob';
		} elseif ($cleanliness >= 20) {
			return 'pixel-spider';
		} else {
			return 'dust-demon';
		}
	}

	/**
	 * Get game state for a house
	 *
	 * @param int $houseId The house ID
	 */
	public function getGameState(int $houseId): array {
		$users = $this->houseUserMapper->findByHouseId($houseId);
		$rooms = $this->roomMapper->findByHouseId($houseId);

		$monsters = [];
		foreach ($rooms as $room) {
			$monsterCount = $this->calculateMonsterCount($room->getCleanliness());
			$monsterType = $this->getMonsterType($room->getCleanliness());

			$roomMonsters = [];
			for ($i = 0; $i < $monsterCount; $i++) {
				$roomMonsters[] = [
					'id' => $room->getId() . '-' . $i,
					'type' => $monsterType,
					'position' => [
						'x' => rand(0, 100),
						'y' => rand(0, 100),
					],
				];
			}
			$monsters[$room->getId()] = $roomMonsters;
		}

		return [
			'monsters' => $monsters,
			'totalMonsters' => array_sum(array_map('count', $monsters)),
			'users' => array_map(fn($user) => $user->jsonSerialize(), $users),
		];
	}

	/**
	 * Get leaderboard for a house
	 *
	 * @param int $houseId The house ID
	 * @param int $limit Maximum number of users
	 */
	public function getLeaderboard(int $houseId, int $limit = 10): array {
		return array_map(
			fn($user) => $user->jsonSerialize(),
			$this->houseUserMapper->getLeaderboard($houseId, $limit)
		);
	}

	/**
	 * Check and award medals for weekly achievements
	 *
	 * @param int $houseId The house ID
	 */
	public function checkAndAwardMedals(int $houseId): array {
		$weekStart = $this->getWeekStart();
		$weekEnd = new \DateTime($weekStart);
		$weekEnd->modify('+7 days');

		$users = $this->houseUserMapper->findByHouseId($houseId);
		$awardedMedals = [];

		foreach ($users as $user) {
			$stats = $this->completedTaskMapper->getUserStats(
				$user->getId(),
				new \DateTime($weekStart),
				$weekEnd
			);

			if ($stats['taskCount'] >= 10) {
				$medal = $this->awardMedal($user->getId(), 'team_anchor', $stats['taskCount'], $weekStart);
				$awardedMedals[] = $medal;
			}

			if ($user->getCurrentStreak() >= 7) {
				$medal = $this->awardMedal($user->getId(), 'streak_keeper', $user->getCurrentStreak(), $weekStart);
				$awardedMedals[] = $medal;
			}
		}

		return $awardedMedals;
	}

	/**
	 * Award a medal to a user
	 *
	 * @param int $userId The house user ID
	 * @param string $medalType Type of medal
	 * @param int $value Medal value
	 * @param string $weekStart Week start date
	 */
	private function awardMedal(int $userId, string $medalType, int $value, string $weekStart): Medal {
		try {
			return $this->medalMapper->findByUserTypeAndWeek($userId, $medalType, $weekStart);
		} catch (\Exception $e) {
			$medal = new Medal();
			$medal->setUserId($userId);
			$medal->setMedalType($medalType);
			$medal->setValue($value);
			$medal->setWeekStart($weekStart);
			$medal->setAwardedAt(new \DateTime());
			return $this->medalMapper->insert($medal);
		}
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

	/**
	 * Get all medals for a house
	 *
	 * @param int $houseId The house ID
	 */
	public function getMedals(int $houseId): array {
		$users = $this->houseUserMapper->findByHouseId($houseId);
		$allMedals = [];

		foreach ($users as $user) {
			$medals = $this->medalMapper->findByUserId($user->getId());
			foreach ($medals as $medal) {
				$medalData = $medal->jsonSerialize();
				$medalData['userName'] = $user->getDisplayName();
				$allMedals[] = $medalData;
			}
		}

		usort($allMedals, fn($a, $b) => strtotime($b['awardedAt']) - strtotime($a['awardedAt']));

		return $allMedals;
	}
}
