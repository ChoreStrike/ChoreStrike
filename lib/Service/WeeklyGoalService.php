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
use OCA\ChoreStrike\Db\Occurrence;
use OCA\ChoreStrike\Db\OccurrenceMapper;
use OCA\ChoreStrike\Db\OccurrenceMember;
use OCA\ChoreStrike\Db\OccurrenceMemberMapper;
use OCA\ChoreStrike\Db\WorkShareMapper;

/**
 * The weekly-goal evaluator (CS-PLAN-070 WP3, Q-074-01 Option A
 * correction-closure slice; parent D5b / C2 goal semantics).
 *
 * Planned-date weeks (Monday through Sunday) count scheduled due
 * occurrences rather than distinct task definitions or work timestamps.
 * Recognized suspensions are neutral, and late catch-up cannot move a
 * missed opportunity into its completion week. Open windows keep an old
 * week provisional; an empty week is neutral. Pure reads, no writes.
 *
 * CS-PLAN-070 WP4 (parent §3.4 read contract): the house progress carries
 * explicit eligible/onTime/neutral counts, and the personal slice
 * ({@see progressForMember()}) evaluates the SAME occurrence facts for
 * one member — never a second recurrence implementation. The 80% target
 * is fixed policy, not a setting (WP4 retirement of the config override).
 */
class WeeklyGoalService {
	/** Fixed first-slice weekly-goal target (10/80/3-6-12 policy). */
	public const TARGET_PERCENT = 80;

	private HouseMapper $houseMapper;
	private OccurrenceMapper $occurrenceMapper;
	private CompletedTaskMapper $completedTaskMapper;
	private OccurrenceMemberMapper $occMemberMapper;
	private WorkShareMapper $workShareMapper;
	private CalendarService $calendarService;

	public function __construct(
		HouseMapper $houseMapper,
		OccurrenceMapper $occurrenceMapper,
		CompletedTaskMapper $completedTaskMapper,
		OccurrenceMemberMapper $occMemberMapper,
		WorkShareMapper $workShareMapper,
		CalendarService $calendarService,
	) {
		$this->houseMapper = $houseMapper;
		$this->occurrenceMapper = $occurrenceMapper;
		$this->completedTaskMapper = $completedTaskMapper;
		$this->occMemberMapper = $occMemberMapper;
		$this->workShareMapper = $workShareMapper;
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
	 * Re-evaluate a saved planned-date week, including a prior week whose
	 * last agreed window closes after Monday. Only valid on-time work counts.
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
		$endKey = (clone $endLocal)->modify('-1 day')->format('Y-m-d');
		$rows = $this->occurrenceMapper->findByHousePlannedBetween((int)$house->getId(), $weekKey, $endKey);
		$ids = array_values(array_unique(array_filter(array_map(static fn (Occurrence $row): ?int => $row->getCompletionId(), $rows))));
		$completions = [];
		foreach (array_chunk($ids, RhythmService::HISTORY_PAGE_SIZE) as $chunk) {
			$completions += $this->completedTaskMapper->findValidByIds($chunk);
		}
		$dueCount = 0;
		$completedCount = 0;
		$neutralCount = 0;
		$provisional = $now < $endLocal;
		foreach ($rows as $row) {
			$completion = $completions[(int)($row->getCompletionId() ?? 0)] ?? null;
			$onTime = $completion !== null
				&& $completion->isAgreedOnTime($row->getWindowStart(), $row->getWindowEnd());
			if ($row->getSuspensionState() === Occurrence::SUSPENSION_SUSPENDED && !$onTime) {
				$neutralCount++;
				continue;
			}
			$dueCount++;
			$provisional = $provisional || ($row->getWindowEnd() !== null && $row->getWindowEnd() > $now);
			if ($onTime) {
				$completedCount++;
			}
		}
		$percent = $dueCount > 0 ? (int)round(($completedCount / $dueCount) * 100) : 0;
		$weekStart = (clone $startLocal)->setTimezone(new \DateTimeZone('UTC'));
		$weekEnd = (clone $endLocal)->setTimezone(new \DateTimeZone('UTC'));

		return [
			// `eligible`/`onTime` mirror dueCount/completedCount under the
			// §3.4 read-contract names; `neutral` is the recognized-
			// suspension remainder excluded from the denominator.
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
	 * caller's own eligible/onTime/neutral counts over the same occurrence
	 * facts as the house evaluation — one member's agreed obligations,
	 * never a second recurrence implementation.
	 *
	 * @return array{eligible: int, onTime: int, neutral: int, provisional: bool, weekKey: string, weekStart: string, weekEnd: string}
	 */
	public function progressForMember(House $house, HouseUser $member, \DateTime $now): array {
		$tz = $this->calendarService->getTimeZone($house);
		$weekStart = $this->calendarService->weekStart($now, $tz);
		$fromKey = $weekStart->format('Y-m-d');
		$endLocal = (clone $weekStart)->modify('+7 days');
		$toKey = (clone $endLocal)->modify('-1 day')->format('Y-m-d');

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

		$rows = $this->occurrenceMapper->findByHousePlannedBetween((int)$house->getId(), $fromKey, $toKey);
		if ($rows === []) {
			return $result;
		}
		$ids = array_map(static fn (Occurrence $row): int => (int)$row->getId(), $rows);
		$membersByOccurrence = $this->occMemberMapper->findByOccurrenceIds($ids);
		$completionIds = array_values(array_unique(array_filter(array_map(
			static fn (Occurrence $row): ?int => $row->getCompletionId(), $rows
		))));
		$completions = [];
		foreach (array_chunk($completionIds, RhythmService::HISTORY_PAGE_SIZE) as $chunk) {
			$completions += $this->completedTaskMapper->findValidByIds($chunk);
		}
		$shares = $this->workShareMapper->findSelectedByCompletionIds(array_keys($completions));

		foreach ($rows as $row) {
			foreach ($membersByOccurrence[(int)$row->getId()] ?? [] as $memberRow) {
				if ((int)$memberRow->getWalletId() !== $walletId
					|| !$memberRow->getObligated()
					|| $memberRow->getDisposition() !== OccurrenceMember::DISPOSITION_ACTIVE) {
					continue;
				}
				$completion = $completions[(int)($row->getCompletionId() ?? 0)] ?? null;
				$onTime = $completion !== null
					&& $completion->isAgreedOnTime($row->getWindowStart(), $row->getWindowEnd());
				if ($row->getSuspensionState() === Occurrence::SUSPENSION_SUSPENDED && !$onTime) {
					$result['neutral']++;
					continue;
				}
				$result['eligible']++;
				$result['provisional'] = $result['provisional']
					|| ($row->getWindowEnd() !== null && $row->getWindowEnd() > $now);
				if ($onTime && $this->coversMember($completions, $shares, $row, $walletId)) {
					$result['onTime']++;
				}
			}
		}
		return $result;
	}

	/**
	 * Whether a valid selected share of the completion covers the member's
	 * wallet (the same fulfilment facts the rhythm evaluator consumes).
	 */
	private function coversMember(array $completions, array $shares, Occurrence $row, int $walletId): bool {
		$completionId = (int)($row->getCompletionId() ?? 0);
		if (!isset($completions[$completionId])) {
			return false;
		}
		foreach ($shares[$completionId] ?? [] as $share) {
			if ((int)$share->getWalletId() === $walletId) {
				return true;
			}
		}
		return false;
	}
}
