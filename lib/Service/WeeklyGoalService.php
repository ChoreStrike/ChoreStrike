<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\CompletedTask;
use OCA\ChoreStrike\Db\CompletedTaskMapper;
use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\Occurrence;
use OCA\ChoreStrike\Db\OccurrenceMapper;

/**
 * The weekly-goal evaluator (CS-PLAN-070 WP3, Q-074-01 Option A
 * correction-closure slice; parent D5b / C2 goal semantics).
 *
 * Planned-date weeks (Monday through Sunday) count scheduled due
 * occurrences rather than distinct task definitions or work timestamps.
 * Recognized suspensions are neutral, and late catch-up cannot move a
 * missed opportunity into its completion week. Open windows keep an old
 * week provisional; an empty week is neutral. Pure reads, no writes.
 */
class WeeklyGoalService {
	private HouseMapper $houseMapper;
	private OccurrenceMapper $occurrenceMapper;
	private CompletedTaskMapper $completedTaskMapper;
	private RewardConfigService $rewardConfigService;
	private CalendarService $calendarService;

	public function __construct(
		HouseMapper $houseMapper,
		OccurrenceMapper $occurrenceMapper,
		CompletedTaskMapper $completedTaskMapper,
		RewardConfigService $rewardConfigService,
		CalendarService $calendarService,
	) {
		$this->houseMapper = $houseMapper;
		$this->occurrenceMapper = $occurrenceMapper;
		$this->completedTaskMapper = $completedTaskMapper;
		$this->rewardConfigService = $rewardConfigService;
		$this->calendarService = $calendarService;
	}

	/**
	 * The weekly-goal progress of one house for the current house-local week.
	 *
	 * @return array{dueCount: int, completedCount: int, percent: int, targetPercent: int, achieved: bool, provisional: bool, weekKey: string, weekStart: string, weekEnd: string}
	 */
	public function getWeeklyGoalProgress(int $houseId): array {
		$house = $this->houseMapper->find($houseId);
		return $this->progressForWeek($house, new \DateTime());
	}

	public function getWeeklyGoalProgressForWeek(int $houseId, string $weekKey, \DateTime $now): array {
		return $this->progressForPlannedWeek($this->houseMapper->find($houseId), $weekKey, $now);
	}

	/**
	 * The evaluation body over one house and reference instant (injectable
	 * clock for tests).
	 */
	public function progressForWeek(House $house, \DateTime $now): array {
		$tz = $this->calendarService->getTimeZone($house);
		$key = $this->calendarService->weekStart($now, $tz)->setTimezone($tz)->format('Y-m-d');
		return $this->progressForPlannedWeek($house, $key, $now);
	}

	/**
	 * Re-evaluate a saved planned-date week, including a prior week whose
	 * last agreed window closes after Monday. Only valid on-time work counts.
	 *
	 * @return array{dueCount: int, completedCount: int, percent: int, targetPercent: int, achieved: bool, provisional: bool, weekStart: string, weekEnd: string}
	 */
	public function progressForPlannedWeek(House $house, string $weekKey, \DateTime $now): array {
		$tz = $this->calendarService->getTimeZone($house);
		$startLocal = \DateTime::createFromFormat('!Y-m-d', $weekKey, $tz);
		if ($startLocal === false || $startLocal->format('Y-m-d') !== $weekKey || $startLocal->format('N') !== '1') {
			throw new \InvalidArgumentException('Invalid planned-week key');
		}
		$endLocal = (clone $startLocal)->modify('+7 days');
		$endKey = (clone $endLocal)->modify('-1 day')->format('Y-m-d');
		$rows = $this->occurrenceMapper->findByHousePlannedBetween((int)$house->getId(), $weekKey, $endKey);
		$ids = array_values(array_unique(array_filter(array_map(static fn (Occurrence $row): ?int => $row->getCompletionId(), $rows))));
		$completions = [];
		foreach (array_chunk($ids, RhythmService::HISTORY_PAGE_SIZE) as $chunk) {
			$completions += $this->completedTaskMapper->findValidByIds($chunk);
		}
		$dueCount = 0;
		$completedCount = 0;
		$provisional = $now < $endLocal;
		foreach ($rows as $row) {
			$completion = $completions[(int)($row->getCompletionId() ?? 0)] ?? null;
			$onTime = $completion !== null && $completion->getCompletionClass() === CompletedTask::CLASS_AGREED
				&& ($row->getWindowStart() === null || $completion->getCompletedAt() >= $row->getWindowStart())
				&& ($row->getWindowEnd() === null || $completion->getCompletedAt() < $row->getWindowEnd());
			if ($row->getSuspensionState() === Occurrence::SUSPENSION_SUSPENDED && !$onTime) {
				continue;
			}
			$dueCount++;
			$provisional = $provisional || ($row->getWindowEnd() !== null && $row->getWindowEnd() > $now);
			if ($onTime) {
				$completedCount++;
			}
		}
		$targetPercent = $this->rewardConfigService->getWeeklyGoalTargetPercent();
		$percent = $dueCount > 0 ? (int)round(($completedCount / $dueCount) * 100) : 0;
		$weekStart = (clone $startLocal)->setTimezone(new \DateTimeZone('UTC'));
		$weekEnd = (clone $endLocal)->setTimezone(new \DateTimeZone('UTC'));

		return [
			'dueCount' => $dueCount,
			'completedCount' => $completedCount,
			'percent' => $percent,
			'targetPercent' => $targetPercent,
			'achieved' => $dueCount > 0 && !$provisional && $percent >= $targetPercent,
			'provisional' => $provisional,
			'weekKey' => $weekKey,
			'weekStart' => $weekStart->format('c'),
			'weekEnd' => $weekEnd->format('c'),
		];
	}
}
