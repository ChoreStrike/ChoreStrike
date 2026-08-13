<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\CompletedTaskMapper;
use OCA\ChoreStrike\Db\FloorMapper;
use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\Room;
use OCA\ChoreStrike\Db\RoomMapper;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;

/**
 * Service for house-related business logic
 */
class HouseService {
	private HouseMapper $houseMapper;
	private FloorMapper $floorMapper;
	private RoomMapper $roomMapper;
	private RoomTaskMapper $roomTaskMapper;
	private CompletedTaskMapper $completedTaskMapper;
	private RewardConfigService $rewardConfigService;
	private IConfig $config;
	private ?AppLogger $appLogger;

	public function __construct(
		HouseMapper $houseMapper,
		FloorMapper $floorMapper,
		RoomMapper $roomMapper,
		RoomTaskMapper $roomTaskMapper,
		CompletedTaskMapper $completedTaskMapper,
		RewardConfigService $rewardConfigService,
		IConfig $config,
		?AppLogger $appLogger = null,
	) {
		$this->houseMapper = $houseMapper;
		$this->floorMapper = $floorMapper;
		$this->roomMapper = $roomMapper;
		$this->roomTaskMapper = $roomTaskMapper;
		$this->completedTaskMapper = $completedTaskMapper;
		$this->rewardConfigService = $rewardConfigService;
		$this->config = $config;
		$this->appLogger = $appLogger;
	}

	/**
	 * Get or create house for current Nextcloud instance
	 *
	 * @param string $instanceId The Nextcloud instance ID
	 * @param string $houseName Optional house name
	 */
	public function getOrCreateHouse(string $instanceId, string $houseName = 'My House'): House {
		try {
			return $this->houseMapper->findByInstanceId($instanceId);
		} catch (DoesNotExistException $e) {
			$this->appLogger?->info('Creating house for instance', [
				'service' => 'HouseService',
				'instanceId' => $instanceId,
				'houseName' => $houseName,
			]);
			return $this->houseMapper->createForInstance($instanceId, $houseName);
		}
	}

	/**
	 * Update house name
	 *
	 * @param int $houseId The house ID
	 * @param string $name The new name
	 */
	public function updateHouseName(int $houseId, string $name): House {
		$house = $this->houseMapper->find($houseId);
		$house->setName($name);
		$house->touch();
		return $this->houseMapper->update($house);
	}

	/**
	 * Calculate overall house health based on room cleanliness
	 *
	 * @param int $houseId The house ID
	 */
	public function calculateHouseHealth(int $houseId): int {
		$floors = $this->floorMapper->findByHouseId($houseId);
		$totalCleanliness = 0;
		$roomCount = 0;

		foreach ($floors as $floor) {
			$rooms = $this->roomMapper->findByFloorId($floor->getId());

			foreach ($rooms as $room) {
				$totalCleanliness += $room->getCleanliness();
				$roomCount++;
			}
		}

		if ($roomCount === 0) {
			return 100; // Empty house is perfectly clean
		}

		$averageCleanliness = (int)round($totalCleanliness / $roomCount);
		$this->houseMapper->updateHealth($houseId, $averageCleanliness);

		return $averageCleanliness;
	}

	/**
	 * Get house statistics
	 *
	 * @param int $houseId The house ID
	 */
	public function getHouseStats(int $houseId): array {
		$house = $this->houseMapper->find($houseId);
		$floors = $this->floorMapper->findByHouseId($houseId);

		$roomCount = 0;
		$cleanRooms = 0;
		$dirtyRooms = 0;
		$roomsNeedingCleaning = 0;

		foreach ($floors as $floor) {
			$rooms = $this->roomMapper->findByFloorId($floor->getId());
			$roomCount += count($rooms);

			foreach ($rooms as $room) {
				if ($room->getCleanliness() >= 60) {
					$cleanRooms++;
				} else {
					$dirtyRooms++;
				}

				if ($room->needsCleaning()) {
					$roomsNeedingCleaning++;
				}
			}
		}

		return [
			'house' => $house,
			'floors' => $floors,
			'stats' => [
				'totalRooms' => $roomCount,
				'cleanRooms' => $cleanRooms,
				'dirtyRooms' => $dirtyRooms,
				'roomsNeedingCleaning' => $roomsNeedingCleaning,
				'houseHealth' => $house->getHouseHealth(),
			]
		];
	}

	/**
	 * Reset house to initial state
	 *
	 * @param int $houseId The house ID
	 */
	public function resetHouse(int $houseId): void {
		$this->appLogger?->warning('Resetting house to initial state', [
			'service' => 'HouseService',
			'houseId' => $houseId,
		]);
		$house = $this->houseMapper->find($houseId);
		$house->setHouseHealth(100);
		$house->touch();
		$this->houseMapper->update($house);

		// Reset all rooms
		$floors = $this->floorMapper->findByHouseId($houseId);
		foreach ($floors as $floor) {
			$rooms = $this->roomMapper->findByFloorId($floor->getId());
			foreach ($rooms as $room) {
				$room->setCleanliness(100);
				$room->setMonsterCount(0);
				$room->setLastCleaned(null);
				$this->roomMapper->update($room);
			}
		}
	}

	/**
	 * Get instance ID from Nextcloud config
	 */
	public function getInstanceId(): string {
		return $this->config->getSystemValue('instanceid', 'default');
	}

	/**
	 * Whether the house has any floors yet.
	 *
	 * Used by the first-run setup gate (the wizard is only available while the
	 * house is structurally empty — decision D3).
	 *
	 * @param int $houseId The house ID
	 */
	public function hasFloors(int $houseId): bool {
		return $this->floorMapper->findByHouseId($houseId) !== [];
	}

	/**
	 * Compute the weekly household goal progress for a house.
	 *
	 * The household "reaches" its goal when the share of completed work meets
	 * the configured target percent (RewardConfigService key
	 * `weekly_goal_target_percent`, default 80).
	 *
	 * Metric (KISS, no extra schema):
	 *   - dueSet         = (active room_tasks with next_due in this week) ∪
	 *                      (distinct room_tasks completed this week)
	 *   - dueCount       = |dueSet|
	 *   - completedCount = distinct room_tasks completed this week (⊆ dueSet
	 *                      by construction, so completedCount ≤ dueCount always)
	 *   - percent        = dueCount > 0 ? round(completedCount / dueCount * 100) : …
	 *   - achieved       = percent >= targetPercent AND (dueCount > 0 OR completedCount > 0)
	 *
	 * Why the UNION: a recurring task that is completed shifts its next_due
	 * forward (often out of this week) and a one-time task deactivates on
	 * completion — both leave the "active-due-this-week" set even though they
	 * are part of this week's planned work. Counting only active-due as the
	 * denominator would shrink it as the week progresses and inflate the
	 * percent prematurely (e.g. 4 of 5 done → dueCount=1, completedCount=4 →
	 * bogus 400%). Unioning with completed-this-week keeps the denominator
	 * stable for the whole week.
	 *
	 * Distinct room_task_id on both sides: a daily task completed three times
	 * this week counts once (matches "this task was done this week", not "we
	 * did N chores"), and a multi-assign task completed by three members
	 * counts once for the household.
	 *
	 * The achieved-flag explicitly requires at least one due or completed task
	 * so a brand-new empty house doesn't trigger a spurious celebration.
	 *
	 * Week bounds are Monday 00:00:00 → Sunday 23:59:59 in the server timezone
	 * (consistent with GameLogicService::getWeekStart's Monday-start).
	 *
	 * @param int $houseId The house ID
	 * @return array{dueCount: int, completedCount: int, percent: int, targetPercent: int, achieved: bool, weekStart: string, weekEnd: string}
	 */
	public function getWeeklyGoalProgress(int $houseId): array {
		[$weekStart, $weekEnd] = $this->getWeekBounds();

		$activeDueIds = $this->roomTaskMapper->findActiveDueIdsBetweenByHouse($houseId, $weekStart, $weekEnd);
		$completedIds = $this->completedTaskMapper->findCompletedRoomTaskIdsBetweenByHouse($houseId, $weekStart, $weekEnd);
		$targetPercent = $this->rewardConfigService->getWeeklyGoalTargetPercent();

		// dueSet = active-due ∪ completed-this-week. array_flip+keys gives
		// O(n) dedup on integer IDs without string-coercion surprises.
		$dueSet = array_keys(array_flip(array_merge($activeDueIds, $completedIds)));
		$dueCount = count($dueSet);
		$completedCount = count($completedIds);

		if ($dueCount === 0) {
			// Cannot happen given the UNION (completedIds would be in dueSet),
			// but defensive: empty house reports 0%.
			$percent = 0;
		} else {
			// completedCount ≤ dueCount by construction, so no clamp needed.
			$percent = (int)round(($completedCount / $dueCount) * 100);
		}

		$hasActivity = $dueCount > 0 || $completedCount > 0;

		return [
			'dueCount' => $dueCount,
			'completedCount' => $completedCount,
			'percent' => $percent,
			'targetPercent' => $targetPercent,
			'achieved' => $hasActivity && $percent >= $targetPercent,
			'weekStart' => $weekStart->format('c'),
			'weekEnd' => $weekEnd->format('c'),
		];
	}

	/**
	 * Get the current week's Monday 00:00:00 → Sunday 23:59:59 bounds.
	 *
	 * @return array{\DateTimeImmutable, \DateTimeImmutable}
	 */
	private function getWeekBounds(): array {
		$today = new \DateTimeImmutable('today');
		$dayOfWeek = (int)$today->format('N'); // 1 (Mon) .. 7 (Sun)
		$monday = $today->modify('-' . ($dayOfWeek - 1) . ' days')->setTime(0, 0, 0);
		$sunday = $monday->modify('+6 days')->setTime(23, 59, 59);
		return [$monday, $sunday];
	}
}
