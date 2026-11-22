<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Service;

use OCA\ChoreStrike\Db\CompletedTask;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\WalletMapper;
use OCA\ChoreStrike\Exception\ConflictException;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Exception\NotFoundException;
use OCA\ChoreStrike\Service\CompletionService;
use OCA\ChoreStrike\Service\CycleAdvanceService;
use OCA\ChoreStrike\Service\PostponeService;
use OCA\ChoreStrike\Service\RhythmService;
use OCA\ChoreStrike\Service\RoomTaskReadService;
use OCA\ChoreStrike\Service\SleepService;
use OCA\ChoreStrike\Service\WeeklyGoalService;
use OCA\ChoreStrike\Service\WindowService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;
use OCA\ChoreStrike\Tests\Integration\Support\CycleFixtures;
use OCA\ChoreStrike\Tests\Integration\Support\TaskEditing;

/**
 * Sleeping and bonus tasks against the real database (CS-PLAN-091 WP-B4,
 * ADR-021 decisions 7 and 8): a sleeping task has no cycle and wakes with
 * a fresh one, by command, by its wake date or by a completion; a bonus
 * task is never due, pays each person once per house-local day and counts
 * for no recognition.
 */
class SleepAndBonusTest extends IntegrationTestCase {
	use CycleFixtures;
	use TaskEditing;

	protected const OWNER = 'sab_owner';
	protected const HELPER = 'sab_helper';
	private const KID = 'sab_kid';

	private CompletionService $completionService;
	private SleepService $sleepService;

	protected function setUp(): void {
		parent::setUp();
		$this->completionService = \OC::$server->get(CompletionService::class);
		$this->sleepService = \OC::$server->get(SleepService::class);
		$this->initToday();
	}

	/** Put the task to sleep or wake it, with the version its row shows. */
	private function sleep(array $f, string $userId, bool $sleeping, ?string $wakeOn = null, ?string $commandId = null, ?int $version = null): array {
		return $this->sleepService->setSleeping(
			$f['houseId'], $this->member($f['houseId'], $userId), $f['roomTaskId'],
			$commandId ?? $this->newCommandId(), $version ?? (int)$this->roomTask($f)['cycle_version'], $sleeping, $wakeOn
		);
	}

	/** @return array<string, mixed> */
	private function roomTask(array $f): array {
		return $this->rows('chorestrike_room_tasks', 'id', $f['roomTaskId'])[0];
	}

	private function jobPass(array $f, ?\DateTime $now = null): array {
		return \OC::$server->get(CycleAdvanceService::class)->advanceHouse(\OC::$server->get(HouseMapper::class)->find($f['houseId']), $now ?? new \DateTime());
	}

	private function bookedMinor(int $houseId, string $userId): int {
		return (int)\OC::$server->get(WalletMapper::class)->findByHouseAndUser($houseId, $userId)?->getBookedMinor();
	}

	private function reasonOf(callable $command): string {
		try {
			$command();
		} catch (ConflictException $e) {
			return $e->getReason();
		}
		self::fail('the command must be refused');
	}

	/** @param list<string> $userIds The people to credit in equal shares */
	private function completeFor(array $f, string $recorder, array $userIds): array {
		$share = intdiv(100, count($userIds));
		$participants = [];
		foreach (array_values($userIds) as $index => $userId) {
			$participants[] = ['userId' => $userId, 'sharePct' => $index === 0 ? 100 - $share * (count($userIds) - 1) : $share];
		}
		return $this->completeOccurrenceWithParticipants($this->completionService, $f['houseId'], $recorder, $f['roomTaskId'], $participants);
	}

	// ------------------------------------------------------------------
	// Sleeping
	// ------------------------------------------------------------------

	public function testSleep_ARedTaskHasNoWindowAnyMore_AndWakingStartsAFreshCycleToday(): void {
		// Weekly after completion, first date long ago: the one red entry.
		$f = $this->fixture(['frequency_type' => 'weekly', 'flex_days' => 1], $this->day(-20));
		self::assertSame(WindowService::STATE_OVERDUE, $this->block($f)['state']);

		$result = $this->sleep($f, self::HELPER, true);

		self::assertFalse($result['replay']);
		self::assertSame(WindowService::STATE_ASLEEP, $result['cycle']['state']);
		self::assertNull($result['cycle']['wakeOn']);
		self::assertSame((int)$this->roomTask($f)['cycle_version'], $result['cycle']['expectedVersion'], 'the version is the receipt of waking it');
		$owner = $this->member($f['houseId'], self::OWNER);
		$read = \OC::$server->get(RoomTaskReadService::class);
		self::assertSame([], $read->getOverdueTaskRows($f['houseId'], $owner), 'never overdue while asleep');
		self::assertSame([], $read->getDueTodayTaskRows($f['houseId'], $owner));
		self::assertSame(['tasks' => 0, 'missed' => 0], $this->jobPass($f), 'the job leaves a sleeping task alone');

		$woken = $this->sleep($f, self::OWNER, false)['cycle'];

		self::assertSame(
			['d' . $this->today, $this->day(-1), $this->day(1), WindowService::STATE_OPEN],
			[$woken['cycleKey'], $woken['windowStartOn'], $woken['windowEndOn'], $woken['state']],
			'anchored on the wake day, whatever was due before'
		);
		self::assertSame($this->day(1), substr((string)$this->roomTask($f)['next_due'], 0, 10), 'the projection follows in the same transaction');
		self::assertSame([], $this->rows('chorestrike_cycles', 'room_task_id', $f['roomTaskId']), 'nothing was missed');
	}

	public function testSleep_OnlyAdultsWhoSeeTheTask_AndOnlyTheShownVersion(): void {
		$f = $this->fixture();
		$this->addHouseMember($f['houseId'], self::KID, 'Kid', 'child');
		$version = (int)$this->roomTask($f)['cycle_version'];

		try {
			$this->sleep($f, self::KID, true);
			self::fail('a child must not put a task to sleep');
		} catch (ForbiddenException $e) {
			self::assertNull($this->roomTask($f)['sleeping_since']);
		}
		self::assertSame(ConflictException::REASON_STALE_TARGET, $this->reasonOf(fn () => $this->sleep($f, self::OWNER, true, null, null, $version + 1)));
		self::assertNull($this->roomTask($f)['sleeping_since'], 'a refusal writes nothing');

		$private = $this->fixture(['visibility_type' => 'private', 'owner_user_id' => self::OWNER]);
		$this->expectException(NotFoundException::class);
		$this->sleep($private, self::HELPER, true);
	}

	public function testSleep_ARepeatedCommandReplays_AndAWakeDateMustLieAhead(): void {
		$f = $this->fixture();
		$commandId = $this->newCommandId();
		$version = (int)$this->roomTask($f)['cycle_version'];

		$this->sleep($f, self::OWNER, true, $this->day(10), $commandId, $version);
		$replay = $this->sleep($f, self::OWNER, true, $this->day(10), $commandId, $version);

		self::assertTrue($replay['replay']);
		self::assertSame($this->day(10), $replay['cycle']['wakeOn']);
		self::assertSame($version + 1, (int)$this->roomTask($f)['cycle_version'], 'the replay has no second effect');

		// Asleep already: the command only moves the wake date.
		$since = $this->roomTask($f)['sleeping_since'];
		self::assertSame($this->day(3), $this->sleep($f, self::HELPER, true, $this->day(3))['cycle']['wakeOn']);
		self::assertSame($since, $this->roomTask($f)['sleeping_since']);

		foreach ([$this->today, $this->day(-1), $this->day(400), 'tomorrow'] as $refused) {
			try {
				$this->sleep($f, self::OWNER, true, $refused);
				self::fail('refused wake date: ' . $refused);
			} catch (\InvalidArgumentException $e) {
				self::assertSame($this->day(3), substr((string)$this->roomTask($f)['wake_on'], 0, 10));
			}
		}
	}

	public function testWakeDate_TheJobWakesTheTaskOnItsDay_AlsoWhenItRunsLate(): void {
		$f = $this->fixture(['frequency_type' => 'weekly', 'flex_days' => 2]);
		$this->sleep($f, self::OWNER, true, $this->day(5));

		self::assertSame(['tasks' => 0, 'missed' => 0], $this->jobPass($f, new \DateTime($this->day(4) . ' 12:00:00')), 'not before its day');
		self::assertNotNull($this->roomTask($f)['sleeping_since']);

		// Two days late: the fresh cycle still hangs on the wake date.
		$late = new \DateTime($this->day(7) . ' 12:00:00');
		self::assertSame(['tasks' => 1, 'missed' => 0], $this->jobPass($f, $late));

		$row = $this->roomTask($f);
		self::assertSame([null, null], [$row['sleeping_since'], $row['wake_on']]);
		$block = $this->block($f, $late);
		self::assertSame(['d' . $this->day(5), $this->day(3), $this->day(7)], [$block['cycleKey'], $block['windowStartOn'], $block['windowEndOn']]);
		self::assertSame(['tasks' => 0, 'missed' => 0], $this->jobPass($f, $late), 'woken once');
	}

	public function testFixedRule_SleepsThroughItsRuleDaysWithoutAMiss_AndContinuesOnItsNextOne(): void {
		// Every day, fixed: the first cycle ended ten days ago and was missed.
		$f = $this->fixture(['scheduling_mode' => 'static', 'frequency_type' => 'daily'], $this->day(-10));
		// It went to sleep eight days ago: the rule at that instant, under the command's locks.
		$asleepAt = new \DateTime($this->day(-8) . ' 12:00:00');
		\OC::$server->get(\OCA\ChoreStrike\Db\TransactionExecutor::class)->run(function () use ($f, $asleepAt): void {
			$house = \OC::$server->get(HouseMapper::class)->findForUpdate($f['houseId']);
			$roomTask = \OC::$server->get(\OCA\ChoreStrike\Db\RoomTaskMapper::class)->lockForUpdate($f['roomTaskId']);
			$task = \OC::$server->get(\OCA\ChoreStrike\Db\TaskMapper::class)->find($f['taskId']);
			\OC::$server->get(\OCA\ChoreStrike\Service\CycleSleep::class)->sleepUnderLock($house, $roomTask, $task, null, new \DateTimeZone('UTC'), $asleepAt);
		});

		// What had stopped being current before the sleep was missed; the days it slept were not.
		$missed = array_column($this->rows('chorestrike_cycles', 'room_task_id', $f['roomTaskId']), 'cycle_key');
		self::assertSame(['d' . $this->day(-10), 'd' . $this->day(-9)], $missed);

		$woken = $this->sleep($f, self::OWNER, false)['cycle'];

		self::assertSame('d' . $this->today, $woken['cycleKey'], 'the next rule day from the wake day');
		$this->jobPass($f);
		self::assertSame($missed, array_column($this->rows('chorestrike_cycles', 'room_task_id', $f['roomTaskId']), 'cycle_key'), 'no miss for the days it slept');
	}

	public function testCompletingASleepingTask_WakesItAndFulfilsTheFreshCycle(): void {
		$f = $this->fixture(['frequency_type' => 'weekly', 'flex_days' => 1], $this->day(-20));
		$this->sleep($f, self::OWNER, true, $this->day(30));
		$asleep = $this->block($f);
		self::assertArrayNotHasKey('cycleKey', $asleep, 'a sleeping task has no cycle to name');

		// A cycle key is a stale target; the version alone is the receipt.
		self::assertSame(ConflictException::REASON_STALE_TARGET, $this->reasonOf(fn () => $this->completionService->complete(
			$f['houseId'], self::HELPER, $f['roomTaskId'], $this->newCommandId(), 'd' . $this->day(-20), $asleep['expectedVersion']
		)));
		self::assertNotNull($this->roomTask($f)['sleeping_since'], 'the refused completion did not wake it');

		$result = $this->completionService->complete($f['houseId'], self::HELPER, $f['roomTaskId'], $this->newCommandId(), null, $asleep['expectedVersion']);

		$ledger = $this->rows('chorestrike_completed_tasks', 'id', $result['completionId'])[0];
		self::assertSame(['d' . $this->today, CompletedTask::CLASS_ON_TIME], [$ledger['cycle_key'], $ledger['completion_class']], 'on time in the fresh cycle, not late in the old one');
		$row = $this->roomTask($f);
		self::assertSame([null, null], [$row['sleeping_since'], $row['wake_on']]);
		// The rhythm continues from that day (D-091-22).
		self::assertSame(['c' . $result['completionId'], $this->day(7)], [$result['cycle']['cycleKey'], $result['cycle']['centreOn']]);
	}

	/** D-091-82: going to sleep ends the postponement of the current cycle, also when the wake day gives the same key. */
	public function testSleep_EndsThePostponementOfTheCurrentCycle_SoTheWakeDayStartsUnmoved(): void {
		$f = $this->fixture(['frequency_type' => 'weekly', 'flex_days' => 2]);
		$shown = $this->block($f);
		\OC::$server->get(PostponeService::class)->postpone(
			$f['houseId'], $this->member($f['houseId'], self::OWNER), $f['roomTaskId'], $this->newCommandId(), $shown['cycleKey'], $shown['expectedVersion']
		);
		self::assertSame([1, $this->day(3)], [$this->block($f)['postponeCount'], $this->block($f)['windowEndOn']]);

		$this->sleep($f, self::OWNER, true);
		$woken = $this->sleep($f, self::OWNER, false)['cycle'];

		self::assertSame(
			['d' . $this->today, $this->day(-2), $this->day(2), 0, true],
			[$woken['cycleKey'], $woken['windowStartOn'], $woken['windowEndOn'], $woken['postponeCount'], $woken['canPostpone']],
			'the same key as before the sleep, but a fresh cycle'
		);
		self::assertSame([null, 0, 0], [$this->roomTask($f)['cycle_key'], (int)$this->roomTask($f)['postpone_count'], (int)$this->roomTask($f)['postpone_days']]);
	}

	/** A bounded rule that ended while its task slept: a completion cannot wake it into a cycle, and says so. */
	public function testCompletingASleepingTask_WhoseBoundedRuleEnded_IsRefusedAsEnded(): void {
		$f = $this->fixture(['scheduling_mode' => 'static', 'frequency_type' => 'daily', 'recurrence_mode' => 'bounded', 'active_until' => $this->day(5) . ' 00:00:00']);
		$this->sleep($f, self::OWNER, true);
		// The bound passes while it sleeps.
		$qb = $this->db->getQueryBuilder();
		$qb->update('chorestrike_tasks')->set('active_until', $qb->createNamedParameter($this->day(-1) . ' 00:00:00'))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($f['taskId'], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))->executeStatement();
		$asleep = $this->block($f);

		self::assertSame(ConflictException::REASON_TASK_ENDED, $this->reasonOf(fn () => $this->completionService->complete(
			$f['houseId'], self::HELPER, $f['roomTaskId'], $this->newCommandId(), null, $asleep['expectedVersion']
		)));
		self::assertNotNull($this->roomTask($f)['sleeping_since'], 'the refusal writes nothing');
		self::assertSame([], $this->rows('chorestrike_completed_tasks', 'room_task_id', $f['roomTaskId']));

		// Waking it closes it.
		self::assertNull($this->sleep($f, self::OWNER, false)['cycle']);
		self::assertSame(0, (int)$this->roomTask($f)['is_active']);
	}

	// ------------------------------------------------------------------
	// Bonus tasks
	// ------------------------------------------------------------------

	public function testBonusTask_PaysEachPersonOncePerDay_WhoeverRecordsIt(): void {
		$f = $this->fixture(['is_voluntary' => 1]);
		$this->addHouseMember($f['houseId'], self::KID, 'Kid', 'child');

		// The assignee holds: credited through the default split.
		$first = $this->completeOccurrence($this->completionService, $f['houseId'], self::OWNER, $f['roomTaskId']);
		self::assertSame(2000, $this->bookedMinor($f['houseId'], self::OWNER));
		self::assertSame(WindowService::STATE_BONUS, $first['cycle']['state'], 'still available: no day rule for the task itself');

		// Someone else did it too, the same day.
		$this->completeFor($f, self::KID, [self::KID]);
		self::assertSame(2000, $this->bookedMinor($f['houseId'], self::KID));

		// A second credit of either of them is refused, also inside a split with a third person.
		self::assertSame(ConflictException::REASON_BONUS_ALREADY_TODAY, $this->reasonOf(fn () => $this->completeFor($f, self::HELPER, [self::OWNER])));
		self::assertSame(ConflictException::REASON_BONUS_ALREADY_TODAY, $this->reasonOf(fn () => $this->completeFor($f, self::HELPER, [self::HELPER, self::KID])));
		self::assertCount(2, $this->rows('chorestrike_completed_tasks', 'room_task_id', $f['roomTaskId']), 'a refusal writes nothing');
		self::assertSame(0, $this->bookedMinor($f['houseId'], self::HELPER));

		// The rows of Today say whom it already paid.
		$rows = \OC::$server->get(RoomTaskReadService::class)->getBonusTaskRows($f['houseId'], $this->member($f['houseId'], self::HELPER));
		self::assertCount(1, $rows);
		self::assertSame([self::KID, self::OWNER], $rows[0]['creditedToday']);
		self::assertSame(WindowService::STATE_BONUS, $rows[0]['cycle']['state']);

		// A deleted entry frees its person for the day again.
		$this->completionService->undo($f['houseId'], $this->member($f['houseId'], self::OWNER), $first['completionId'], $this->newCommandId(), 0, null);
		$this->completeFor($f, self::HELPER, [self::OWNER]);
		self::assertSame(2000, $this->bookedMinor($f['houseId'], self::OWNER));
	}

	/** D-091-84: a bonus task has no cycle, so its completions and their undo never move the version the rows show. */
	public function testBonusTask_TwoPeopleCompleteItWithTheVersionTheirRowsShowed(): void {
		$f = $this->fixture(['is_voluntary' => 1]);
		$shown = $this->block($f)['expectedVersion'];

		// Both members loaded Today before either completed it.
		$first = $this->completionService->complete($f['houseId'], self::OWNER, $f['roomTaskId'], $this->newCommandId(), null, $shown, [['userId' => self::OWNER, 'sharePct' => 100]], true);
		$second = $this->completionService->complete($f['houseId'], self::HELPER, $f['roomTaskId'], $this->newCommandId(), null, $shown, [['userId' => self::HELPER, 'sharePct' => 100]], true);

		self::assertSame([$shown, $shown], [$first['cycle']['expectedVersion'], $second['cycle']['expectedVersion']]);
		$this->completionService->undo($f['houseId'], $this->member($f['houseId'], self::OWNER), $first['completionId'], $this->newCommandId(), 0, null);
		self::assertSame($shown, (int)$this->roomTask($f)['cycle_version'], 'an undo moves it neither');
		self::assertNotNull($this->roomTask($f)['last_completed'], 'the last-completed mark follows the valid entry');
	}

	/** D-091-81: a bonus task that becomes a regular fixed rule starts on the edit day; its bonus days count no miss. */
	public function testBonusTask_TurnedIntoAFixedRule_StartsOnTheEditDayWithoutAMiss(): void {
		// Every day, fixed, but a bonus task since ten days ago.
		$f = $this->fixture(['is_voluntary' => 1, 'scheduling_mode' => 'static', 'frequency_type' => 'daily'], $this->day(-10));
		$this->actingAs(self::OWNER);

		$this->editDefinition($f['houseId'], $this->member($f['houseId'], self::OWNER), $f['taskId'], ['isVoluntary' => false]);

		self::assertSame('d' . $this->today, $this->block($f)['cycleKey'], 'the first rule day from the edit day');
		self::assertSame(0, $this->jobPass($f)['missed']);
		self::assertSame([], $this->rows('chorestrike_cycles', 'room_task_id', $f['roomTaskId']), 'the days it was a bonus task count no miss');
	}

	public function testBonusTask_TheOwnerCannotMoveACreditOntoSomeoneItPaidToday(): void {
		$f = $this->fixture(['is_voluntary' => 1]);
		$owner = $this->member($f['houseId'], self::OWNER);
		$this->completeFor($f, self::OWNER, [self::OWNER]);
		$second = $this->completeFor($f, self::HELPER, [self::HELPER]);

		self::assertSame(ConflictException::REASON_BONUS_ALREADY_TODAY, $this->reasonOf(fn () => $this->completionService->attribute(
			$f['houseId'], $owner, $second['completionId'], $this->newCommandId(), 0, [['userId' => self::OWNER, 'sharePct' => 100]], true, null
		)));
		self::assertSame([2000, 2000], [$this->bookedMinor($f['houseId'], self::OWNER), $this->bookedMinor($f['houseId'], self::HELPER)]);

		// The same people in other shares is no second credit.
		$this->completionService->attribute(
			$f['houseId'], $owner, $second['completionId'], $this->newCommandId(), 0, [['userId' => self::HELPER, 'sharePct' => 100]], true, null
		);
		self::assertSame(2000, $this->bookedMinor($f['houseId'], self::HELPER));
	}

	public function testBonusTask_IsNeverDueAndCountsForNoRecognition(): void {
		$f = $this->fixture(['is_voluntary' => 1], $this->day(-30));
		$owner = $this->member($f['houseId'], self::OWNER);
		$house = \OC::$server->get(HouseMapper::class)->find($f['houseId']);
		$read = \OC::$server->get(RoomTaskReadService::class);
		$goal = static fn (): array => \OC::$server->get(WeeklyGoalService::class)->progressForWeek($house, new \DateTime());
		$streak = fn (): int => \OC::$server->get(RhythmService::class)->evaluateForMembers($f['houseId'], [$this->member($f['houseId'], self::OWNER)], new \DateTime())[(int)$owner->getId()]['currentStreak'];

		self::assertSame([], $read->getOverdueTaskRows($f['houseId'], $owner), 'never overdue, whatever its first date');
		self::assertSame([], $read->getDueTodayTaskRows($f['houseId'], $owner));
		self::assertSame([], $this->todayRowsOf($f['houseId'], $owner), 'never suggested');
		self::assertSame([0, 0], [$goal()['eligible'], $streak()]);

		$result = $this->completeOccurrence($this->completionService, $f['houseId'], self::OWNER, $f['roomTaskId']);

		self::assertSame(CompletedTask::CLASS_BONUS, $this->rows('chorestrike_completed_tasks', 'id', $result['completionId'])[0]['completion_class']);
		self::assertSame([0, 0, 0], [$goal()['eligible'], $goal()['onTime'], $streak()], 'paid, outside the weekly goal and the rhythm');
		self::assertSame(['tasks' => 0, 'missed' => 0], $this->jobPass($f));
	}

	public function testBonusRows_AChildSeesItsOwnAndTheUnassigned_ASleepingOneShowsNobody(): void {
		$f = $this->fixture(['is_voluntary' => 1]);
		$this->addHouseMember($f['houseId'], self::KID, 'Kid', 'child');
		// A second bonus task of the same room, unassigned.
		$openTaskId = $this->createTask($f['houseId'], 'Feed the cat', '', 3, 1);
		$qb = $this->db->getQueryBuilder();
		$qb->update('chorestrike_tasks')->set('is_voluntary', $qb->createNamedParameter(true, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_BOOL))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($openTaskId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))->executeStatement();
		$open = $this->assignTaskToRoom($openTaskId, $f['roomId'], null, $this->today . ' 00:00:00');
		$read = \OC::$server->get(RoomTaskReadService::class);
		$ids = static fn (array $rows): array => array_map(static fn (array $row): int => (int)$row['roomTask']->getId(), $rows);

		self::assertEqualsCanonicalizing([$f['roomTaskId'], $open], $ids($read->getBonusTaskRows($f['houseId'], $this->member($f['houseId'], self::HELPER))));
		self::assertSame([$open], $ids($read->getBonusTaskRows($f['houseId'], $this->member($f['houseId'], self::KID))), 'not the one assigned to someone else');

		$this->sleep(['roomTaskId' => $open] + $f, self::OWNER, true);
		self::assertSame([$f['roomTaskId']], $ids($read->getBonusTaskRows($f['houseId'], $this->member($f['houseId'], self::HELPER))));
	}
}
