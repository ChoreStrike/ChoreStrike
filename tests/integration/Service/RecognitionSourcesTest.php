<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Service;

use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Service\RecognitionFact;
use OCA\ChoreStrike\Service\RecognitionFactsService;
use OCA\ChoreStrike\Service\RhythmService;
use OCA\ChoreStrike\Service\WeeklyGoalService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;
use OCA\ChoreStrike\Tests\Integration\Support\StoredTimelineFixtures;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * The recognitions across the cutover (CS-PLAN-091 WP-B1, ADR-021
 * decisions 5 and 12, D-091-04): weeks before the day the derived windows
 * start are read from the stored occurrences exactly as they were, weeks
 * after it from the cycles, and the week of the cutover from both —
 * without counting an appointment twice. The rhythm continues from the
 * streak a member held at the cutover.
 *
 * The household switched on Wednesday, 18 November 2026:
 *
 * | Day       | What                                           | Source |
 * |-----------|------------------------------------------------|--------|
 * | Tue 10th  | appointment, done on time                      | stored |
 * | Thu 12th  | appointment, missed                            | stored |
 * | Mon 16th  | appointment, missed                            | stored |
 * | Wed 18th  | appointment of the update day, done on time by its cycle (D-091-41) | cycle  |
 * | Fri 20th  | appointment that reached past the cutover, open | cycle  |
 */
class RecognitionSourcesTest extends IntegrationTestCase {
	use StoredTimelineFixtures;

	private const OWNER = 'rs_owner';
	private const CUTOVER = '2026-11-18';

	private int $houseId;
	private int $roomId;
	private int $walletId;
	private House $house;
	private HouseUser $owner;

	protected function setUp(): void {
		parent::setUp();
		$this->houseId = $this->createHouse(self::OWNER, 'Cutover Week House');
		$this->roomId = $this->createRoom($this->houseId, $this->createFloor($this->houseId, 'Ground', 1), 'Kitchen');
		$qb = $this->db->getQueryBuilder();
		$qb->update('chorestrike_houses')
			->set('cycles_from', $qb->createNamedParameter(self::CUTOVER))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($this->houseId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
		// The streak the member held when the household switched.
		$qb = $this->db->getQueryBuilder();
		$qb->update('chorestrike_house_users')
			->set('current_streak', $qb->createNamedParameter(5, IQueryBuilder::PARAM_INT))
			->set('longest_streak', $qb->createNamedParameter(9, IQueryBuilder::PARAM_INT))
			->set('streak_base', $qb->createNamedParameter(5, IQueryBuilder::PARAM_INT))
			->set('longest_streak_base', $qb->createNamedParameter(9, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('house_id', $qb->createNamedParameter($this->houseId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
		$this->house = \OC::$server->get(HouseMapper::class)->find($this->houseId);
		$this->owner = \OC::$server->get(HouseUserMapper::class)->findByNextcloudUserId($this->houseId, self::OWNER);
		$this->walletId = (int)$this->owner->getWalletId();
		$this->seedWeeks();
	}

	/** A weekly task of the owner whose window hangs on the given day. */
	private function roomTask(string $name, string $firstDay): int {
		return $this->assignTaskToRoom($this->createTask($this->houseId, $name), $this->roomId, self::OWNER, $firstDay . ' 00:00:00');
	}

	/** A stored appointment the owner was responsible for. */
	private function storedAppointment(int $roomTaskId, string $day, ?int $completionId = null): void {
		$occurrenceId = $this->insertStoredOccurrence($this->houseId, $roomTaskId, $day, $completionId === null ? [] : ['completion_id' => $completionId]);
		$this->insertStoredMember($occurrenceId, $this->walletId);
	}

	/** Valid work of the owner at noon of the day, with its selected share. */
	private function work(int $roomTaskId, string $day): int {
		$completionId = $this->insertCompletionRow($roomTaskId, (int)$this->owner->getId(), $day . ' 12:00:00');
		$qb = $this->db->getQueryBuilder();
		$qb->insert('chorestrike_work_shares')
			->values([
				'completion_id' => $qb->createNamedParameter($completionId, IQueryBuilder::PARAM_INT),
				'allocation_revision' => $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT),
				'wallet_id' => $qb->createNamedParameter($this->walletId, IQueryBuilder::PARAM_INT),
				'share_pct' => $qb->createNamedParameter(100, IQueryBuilder::PARAM_INT),
				'energy_minor' => $qb->createNamedParameter(300, IQueryBuilder::PARAM_INT),
				'coin_minor' => $qb->createNamedParameter(3000, IQueryBuilder::PARAM_INT),
				'created_at' => $qb->createNamedParameter($day . ' 12:00:00'),
			])
			->executeStatement();
		return $completionId;
	}

	private function seedWeeks(): void {
		// Before the cutover: three appointments of the occurrence timeline.
		$kept = $this->roomTask('Kept before', '2026-11-10');
		$this->storedAppointment($kept, '2026-11-10', $this->work($kept, '2026-11-10'));
		$this->storedAppointment($this->roomTask('Missed before', '2026-11-12'), '2026-11-12');
		$this->storedAppointment($this->roomTask('Missed in the cutover week', '2026-11-16'), '2026-11-16');

		// An appointment that was still ahead at the cutover: the stored row
		// stays, the derived cycle of the same room task owns it from now on.
		$ahead = $this->roomTask('Ahead at the cutover', '2026-11-20');
		$this->storedAppointment($ahead, '2026-11-20');

		// The day of the update (D-091-41): its stored appointment can no
		// longer be fulfilled, the derived cycle closed on time owns it.
		$after = $this->roomTask('Kept after', self::CUTOVER);
		$this->storedAppointment($after, self::CUTOVER);
		$this->insertClosedCycle($this->houseId, $after, self::CUTOVER, self::CUTOVER, [$this->walletId], $this->work($after, self::CUTOVER));
	}

	private function instant(string $instant): \DateTime {
		return new \DateTime($instant, new \DateTimeZone('UTC'));
	}

	/** @return list<array{0: string, 1: string, 2: string}> [day, state, source] ascending by day */
	private function week(string $weekKey, string $now): array {
		$facts = \OC::$server->get(RecognitionFactsService::class)->factsForWeek($this->house, $weekKey, $this->instant($now));
		usort($facts, static fn (RecognitionFact $a, RecognitionFact $b): int => $a->day <=> $b->day);
		return array_map(
			static fn (RecognitionFact $fact): array => [$fact->day, $fact->state, $fact->roomTaskId === null ? 'stored' : 'cycle'],
			$facts
		);
	}

	public function testWeekBeforeTheCutover_IsReadFromTheStoredOccurrences(): void {
		self::assertSame([
			['2026-11-10', RecognitionFact::KEPT, 'stored'],
			['2026-11-12', RecognitionFact::MISSED, 'stored'],
		], $this->week('2026-11-09', '2026-11-21 12:00:00'));

		$goal = \OC::$server->get(WeeklyGoalService::class)->progressForPlannedWeek($this->house, '2026-11-09', $this->instant('2026-11-21 12:00:00'));
		self::assertSame([2, 1, false], [$goal['dueCount'], $goal['completedCount'], $goal['provisional']], 'a past week is evaluated as it was');
	}

	public function testWeekOfTheCutover_IsReadFromBothSources_AndNothingCountsTwice(): void {
		self::assertSame([
			['2026-11-16', RecognitionFact::MISSED, 'stored'],
			['2026-11-18', RecognitionFact::KEPT, 'cycle'],
			['2026-11-20', RecognitionFact::UPCOMING, 'cycle'],
		], $this->week('2026-11-16', '2026-11-19 12:00:00'), 'the appointment that reached past the cutover counts once, as the derived cycle');

		self::assertSame([
			['2026-11-16', RecognitionFact::MISSED, 'stored'],
			['2026-11-18', RecognitionFact::KEPT, 'cycle'],
			['2026-11-20', RecognitionFact::MISSED, 'cycle'],
		], $this->week('2026-11-16', '2026-11-21 12:00:00'), 'an open red cycle counts as missed while it is open');

		$goal = \OC::$server->get(WeeklyGoalService::class)->progressForPlannedWeek($this->house, '2026-11-16', $this->instant('2026-11-21 12:00:00'));
		self::assertSame([3, 1], [$goal['dueCount'], $goal['completedCount']]);
	}

	public function testWeekAfterTheCutover_IsReadFromTheCyclesOnly(): void {
		// The kept task's next cycle: one week after the work of the 18th.
		self::assertSame([
			['2026-11-25', RecognitionFact::UPCOMING, 'cycle'],
		], $this->week('2026-11-23', '2026-11-23 12:00:00'));
	}

	/** D-091-04: the rhythm continues from the streak held at the cutover. */
	public function testRhythm_ContinuesFromTheStreakBase(): void {
		$rhythm = \OC::$server->get(RhythmService::class);
		$memberId = (int)$this->owner->getId();

		$kept = $rhythm->evaluateForMembers($this->houseId, [$this->owner], $this->instant('2026-11-19 12:00:00'))[$memberId];
		self::assertSame([6, 9], [$kept['currentStreak'], $kept['longestStreak']], 'the day kept after the cutover adds to the five held before; the misses before it were already in that five');

		$broken = $rhythm->evaluateForMembers($this->houseId, [$this->owner], $this->instant('2026-11-21 12:00:00'))[$memberId];
		self::assertSame([0, 9], [$broken['currentStreak'], $broken['longestStreak']], 'a miss after the cutover breaks it; the longest streak stays');
	}
}
