<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\HouseUser;

/**
 * The weekly-goal evaluator (CS-PLAN-070 WP3, Q-074-01 Option A
 * correction-closure slice; parent D5b / C2 goal semantics).
 *
 * House-local weeks (Monday through Sunday) count due work rather than
 * distinct task definitions or work timestamps: a cycle counts in the
 * week its window ends (ADR-021 decision 5), a stored occurrence from
 * before the derived model in the week it was planned. Absences are
 * neutral, and late work cannot move a missed opportunity into its
 * completion week. Open windows keep a week provisional; an empty week
 * is neutral. Pure reads, no writes.
 *
 * CS-PLAN-070 WP4 (parent §3.4 read contract): the house progress carries
 * explicit eligible/onTime/neutral counts, and the personal slice
 * ({@see progressForMember()}) evaluates the SAME facts
 * ({@see RecognitionFactsService}) for one member — never a second recurrence implementation. The 80% target
 * is fixed policy, not a setting (WP4 retirement of the config override).
 */
class WeeklyGoalService {
	/** Fixed first-slice weekly-goal target (10/80/3-6-12 policy). */
	public const TARGET_PERCENT = 80;

	private HouseMapper $houseMapper;
	private RecognitionFactsService $facts;
	private CalendarService $calendarService;

	public function __construct(
		HouseMapper $houseMapper,
		RecognitionFactsService $facts,
		CalendarService $calendarService,
	) {
		$this->houseMapper = $houseMapper;
		$this->facts = $facts;
		$this->calendarService = $calendarService;
	}

	/**
	 * The weekly-goal progress of one house for the current house-local week.
	 *
	 * @return array{dueCount: int, completedCount: int, eligible: int, onTime: int, neutral: int, percent: int, targetPercent: int, achieved: bool, provisional: bool, weekKey: string, weekStart: string, weekEnd: string}
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
	 * Re-evaluate one house-local week, including a prior week whose last
	 * window closes after Monday. Only valid on-time work counts.
	 *
	 * @return array{dueCount: int, completedCount: int, eligible: int, onTime: int, neutral: int, percent: int, targetPercent: int, achieved: bool, provisional: bool, weekStart: string, weekEnd: string}
	 */
	public function progressForPlannedWeek(House $house, string $weekKey, \DateTime $now): array {
		$tz = $this->calendarService->getTimeZone($house);
		$startLocal = \DateTime::createFromFormat('!Y-m-d', $weekKey, $tz);
		if ($startLocal === false || $startLocal->format('Y-m-d') !== $weekKey || $startLocal->format('N') !== '1') {
			throw new \InvalidArgumentException('Invalid planned-week key');
		}
		$endLocal = (clone $startLocal)->modify('+7 days');
		$dueCount = 0;
		$completedCount = 0;
		$neutralCount = 0;
		$provisional = $now < $endLocal;
		foreach ($this->facts->factsForWeek($house, $weekKey, $now) as $fact) {
			if ($fact->houseNeutral && !$fact->isKept()) {
				$neutralCount++;
				continue;
			}
			$dueCount++;
			$provisional = $provisional || $fact->provisional;
			if ($fact->isKept()) {
				$completedCount++;
			}
		}
		$percent = $dueCount > 0 ? (int)round(($completedCount / $dueCount) * 100) : 0;
		$weekStart = (clone $startLocal)->setTimezone(new \DateTimeZone('UTC'));
		$weekEnd = (clone $endLocal)->setTimezone(new \DateTimeZone('UTC'));

		return [
			// `eligible`/`onTime` mirror dueCount/completedCount under the
			// §3.4 read-contract names; `neutral` is the absence remainder
			// excluded from the denominator.
			'dueCount' => $dueCount,
			'completedCount' => $completedCount,
			'eligible' => $dueCount,
			'onTime' => $completedCount,
			'neutral' => $neutralCount,
			'percent' => $percent,
			'targetPercent' => self::TARGET_PERCENT,
			'achieved' => $dueCount > 0 && !$provisional && $percent >= self::TARGET_PERCENT,
			'provisional' => $provisional,
			'weekKey' => $weekKey,
			'weekStart' => $weekStart->format('c'),
			'weekEnd' => $weekEnd->format('c'),
		];
	}

	/**
	 * The PERSONAL weekly-goal slice (parent §3.4 read contract): the
	 * caller's own eligible/onTime/neutral counts over the same facts as
	 * the house evaluation — the work the member is responsible for, never
	 * a second recurrence implementation.
	 *
	 * @return array{eligible: int, onTime: int, neutral: int, provisional: bool, weekKey: string, weekStart: string, weekEnd: string}
	 */
	public function progressForMember(House $house, HouseUser $member, \DateTime $now): array {
		$tz = $this->calendarService->getTimeZone($house);
		$weekStart = $this->calendarService->weekStart($now, $tz);
		$fromKey = (clone $weekStart)->setTimezone($tz)->format('Y-m-d');
		$endLocal = (clone $weekStart)->modify('+7 days');

		$result = [
			'eligible' => 0,
			'onTime' => 0,
			'neutral' => 0,
			'provisional' => $now < $endLocal,
			'weekKey' => $fromKey,
			'weekStart' => $weekStart->format('c'),
			'weekEnd' => $endLocal->format('c'),
		];

		$walletId = $member->getWalletId();
		if ($walletId === null) {
			return $result;
		}

		foreach ($this->facts->factsForWeek($house, $fromKey, $now) as $fact) {
			if (!in_array($walletId, $fact->responsible, true)) {
				continue;
			}
			if (!$fact->countsFor($walletId)) {
				$result['neutral']++;
				continue;
			}
			$result['eligible']++;
			$result['provisional'] = $result['provisional'] || $fact->provisional;
			if ($fact->isKeptBy($walletId)) {
				$result['onTime']++;
			}
		}
		return $result;
	}
}
