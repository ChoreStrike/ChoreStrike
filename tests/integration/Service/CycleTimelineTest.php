<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Service;

use OCA\ChoreStrike\Db\CompletedTask;
use OCA\ChoreStrike\Db\Cycle;
use OCA\ChoreStrike\Db\CycleMapper;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Exception\ConflictException;
use OCA\ChoreStrike\Service\CalendarService;
use OCA\ChoreStrike\Service\ChecklistService;
use OCA\ChoreStrike\Service\CompletionService;
use OCA\ChoreStrike\Service\CycleAdvanceService;
use OCA\ChoreStrike\Service\CycleService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\RoomTaskService;
use OCA\ChoreStrike\Service\TaskCreationService;
use OCA\ChoreStrike\Service\TaskService;
use OCA\ChoreStrike\Service\WindowService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;
use OCA\ChoreStrike\Tests\Integration\Support\CycleFixtures;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * Derived due windows against the real database with the real services
 * (CS-PLAN-091 WP-B1, ADR-021): one current cycle per room task, its facts
 * frozen on the completion, closed cycles written exactly once, the
 * `next_due` projection following every command that changes a window,
 * sub-task ticks per cycle and bonus tasks without a cycle.
 */
class CycleTimelineTest extends IntegrationTestCase {
	use CycleFixtures;

	protected const OWNER = 'ct_owner';
	protected const HELPER = 'ct_helper';

	private CompletionService $completionService;
	private CycleService $cycleService;
	private TaskService $taskService;

	protected function setUp(): void {
		parent::setUp();
		$this->completionService = \OC::$server->get(CompletionService::class);
		$this->cycleService = \OC::$server->get(CycleService::class);
		$this->taskService = \OC::$server->get(TaskService::class);
		$this->initToday();
	}

	private function owner(int $houseId): HouseUser {
		return \OC::$server->get(HouseUserMapper::class)->findByNextcloudUserId($houseId, self::OWNER);
	}

	private function complete(array $f, string $userId = self::OWNER): int {
		return (int)$this->completeOccurrence($this->completionService, $f['houseId'], $userId, $f['roomTaskId'])['completionId'];
	}

	/**
	 * The timeline job's pass over the fixture house. The job itself takes
	 * the one household of the instance, which a test database does not
	 * guarantee to be this one.
	 *
	 * @return array{tasks: int, missed: int}
	 */
	private function jobPass(array $f, ?\DateTime $now = null): array {
		return \OC::$server->get(CycleAdvanceService::class)->advanceHouse(\OC::$server->get(HouseMapper::class)->find($f['houseId']), $now ?? new \DateTime());
	}

	private function edit(array $f, array $updates): void {
		$this->actingAs(self::OWNER);
		$this->taskService->updateTask($f['taskId'], $updates, $this->owner($f['houseId']));
	}

	/**
	 * The stored projection equals what {@see WindowService} derives right
	 * now: `next_due` is the start of the window's last day and
	 * `ideal_due_date` the start of its planned day, in the house zone.
	 */
	private function assertProjectionMatchesWindow(array $f, string $after, ?\DateTime $now = null): void {
		$roomTask = \OC::$server->get(RoomTaskMapper::class)->find($f['roomTaskId']);
		$house = \OC::$server->get(HouseMapper::class)->find($f['houseId']);
		$calendar = \OC::$server->get(CalendarService::class);
		$zone = $calendar->getTimeZone($house);
		$cycle = $this->cycleService->currentFor($roomTask, \OC::$server->get(TaskMapper::class)->find($f['taskId']), $zone, $now ?? new \DateTime());
		self::assertNotNull($cycle, $after . ': the room task has a current cycle');
		self::assertEquals($calendar->dayStartInstant($cycle->endOn, $zone), $roomTask->getNextDue(), $after . ': next_due is the last day of the window');
		self::assertEquals($calendar->dayStartInstant($cycle->plannedOn(), $zone), $roomTask->getIdealDueDate(), $after . ': ideal_due_date is the planned day');
	}

	// ------------------------------------------------------------------
	// The current cycle and its completion
	// ------------------------------------------------------------------

	public function testCreation_StartsOneCurrentCycle_AndCompletionFreezesItsFacts(): void {
		$f = $this->fixture();

		$block = $this->block($f);
		self::assertSame('d' . $this->today, $block['cycleKey'], 'the first cycle is keyed by the first date');
		self::assertSame([$this->today, $this->today, $this->today], [$block['windowStartOn'], $block['windowEndOn'], $block['centreOn']]);
		self::assertSame(WindowService::STATE_OPEN, $block['state']);
		self::assertSame([], $this->rows('chorestrike_cycles', 'room_task_id', $f['roomTaskId']), 'an open cycle has no row');

		$completionId = $this->complete($f);

		$ledger = $this->rows('chorestrike_completed_tasks', 'id', $completionId)[0];
		self::assertSame('d' . $this->today, $ledger['cycle_key']);
		self::assertSame([$this->today, $this->today], [substr((string)$ledger['window_start_on'], 0, 10), substr((string)$ledger['window_end_on'], 0, 10)]);
		self::assertSame(CompletedTask::CLASS_ON_TIME, $ledger['completion_class']);
		self::assertSame(2, (int)$ledger['total_energy']);

		$closed = \OC::$server->get(CycleMapper::class)->findByKey($f['roomTaskId'], 'd' . $this->today);
		self::assertNotNull($closed);
		self::assertSame(Cycle::OUTCOME_DONE, $closed->getOutcome());
		self::assertSame($completionId, $closed->getCompletionId());
		self::assertSame(CompletedTask::CLASS_ON_TIME, $closed->getCompletionClass());
		self::assertSame(2, $closed->getEnergy());
		self::assertSame([(int)$this->owner($f['houseId'])->getWalletId()], $closed->responsibleWalletIds(), 'the assignees it had when it closed');
		self::assertSame((new \DateTime($this->today))->modify('monday this week')->format('Y-m-d'), $closed->getWeekStart()->format('Y-m-d'));

		$next = $this->block($f);
		self::assertSame('c' . $completionId, $next['cycleKey'], 'the next cycle is keyed by the completion it follows');
		self::assertSame($this->day(1), $next['centreOn'], 'and counts from the day of the work');
		self::assertSame(WindowService::STATE_UPCOMING, $next['state']);
		self::assertGreaterThan($block['expectedVersion'], $next['expectedVersion']);
	}

	public function testFixedRule_KeepsItsDays(): void {
		$weekday = strtolower((new \DateTime($this->today))->format('l'));
		$f = $this->fixture(['frequency_type' => 'weekly', 'scheduling_mode' => 'static', 'fixed_schedule_days' => json_encode([$weekday])]);

		self::assertSame('d' . $this->today, $this->block($f)['cycleKey']);
		$this->complete($f);

		$next = $this->block($f);
		self::assertSame('d' . $this->day(7), $next['cycleKey'], 'a fixed rule is keyed by its day, not by the completion');
		self::assertSame($this->day(7), $next['centreOn']);
		self::assertSame(WindowService::STATE_UPCOMING, $next['state']);
	}

	public function testLateCompletion_IsClassifiedLate_AndTheNextCycleCountsFromTheWorkDay(): void {
		$f = $this->fixture(['frequency_type' => 'weekly'], $this->day(-3));
		$block = $this->block($f);
		self::assertSame(WindowService::STATE_OVERDUE, $block['state']);
		self::assertFalse($block['canPostpone'], 'an overdue cycle is done, not moved');

		$completionId = $this->complete($f);

		self::assertSame(CompletedTask::CLASS_LATE, $this->rows('chorestrike_completed_tasks', 'id', $completionId)[0]['completion_class']);
		$closed = \OC::$server->get(CycleMapper::class)->findByKey($f['roomTaskId'], 'd' . $this->day(-3));
		self::assertSame([Cycle::OUTCOME_DONE, CompletedTask::CLASS_LATE], [$closed->getOutcome(), $closed->getCompletionClass()]);
		self::assertSame($this->day(-3), $closed->getWindowEndOn()->format('Y-m-d'), 'the cycle counts in the week its window ended');
		self::assertSame($this->day(7), $this->block($f)['centreOn'], 'no backlog: the rhythm restarts from today');
	}

	public function testStaleOrRepeatedReceipt_ConflictsWithZeroWrites(): void {
		$f = $this->fixture();
		[, $cycleKey, $version] = $this->completionReceiptFor($f['roomTaskId']);
		$attempts = [
			'a stale cycle version' => [$cycleKey, $version + 1, ConflictException::REASON_STALE_TARGET],
			'a cycle that is not the current one' => ['d' . $this->day(-1), $version, ConflictException::REASON_STALE_TARGET],
			'no cycle for a task that has one' => [null, $version, ConflictException::REASON_STALE_TARGET],
		];
		foreach ($attempts as $label => [$key, $expectedVersion, $reason]) {
			try {
				$this->completionService->complete($f['houseId'], self::OWNER, $f['roomTaskId'], $this->newCommandId(), $key, $expectedVersion);
				self::fail($label . ' must conflict');
			} catch (ConflictException $e) {
				self::assertSame($reason, $e->getReason(), $label);
			}
		}
		self::assertSame([], $this->rows('chorestrike_completed_tasks', 'room_task_id', $f['roomTaskId']));
		self::assertSame([], $this->rows('chorestrike_cycles', 'room_task_id', $f['roomTaskId']));

		$this->complete($f);

		// The next cycle is there at once, but not on the day of the work.
		[, $nextKey, $nextVersion] = $this->completionReceiptFor($f['roomTaskId']);
		try {
			$this->completionService->complete($f['houseId'], self::OWNER, $f['roomTaskId'], $this->newCommandId(), $nextKey, $nextVersion);
			self::fail('a second completion on the same day must conflict');
		} catch (ConflictException $e) {
			self::assertSame(ConflictException::REASON_SAME_DAY, $e->getReason());
		}
		self::assertCount(1, $this->rows('chorestrike_completed_tasks', 'room_task_id', $f['roomTaskId']));
		self::assertCount(1, $this->rows('chorestrike_cycles', 'room_task_id', $f['roomTaskId']));
	}

	// ------------------------------------------------------------------
	// Edits show at once
	// ------------------------------------------------------------------

	public function testRuleEdit_MovesTheOpenCycleAtOnce(): void {
		$f = $this->fixture();
		$completionId = $this->complete($f);
		$before = $this->block($f);
		self::assertSame($this->day(1), $before['centreOn']);

		$this->edit($f, ['frequencyType' => 'weekly']);

		$after = $this->block($f);
		self::assertSame('c' . $completionId, $after['cycleKey'], 'the cycle keeps its identity');
		self::assertSame($this->day(7), $after['centreOn'], 'the new rhythm counts from the same completion');
		self::assertGreaterThan($before['expectedVersion'], $after['expectedVersion'], 'a row shown before the edit is stale');
		$this->assertProjectionMatchesWindow($f, 'rule edit');
	}

	/** D-091-28: a rule that becomes fixed counts its days from the edit day. */
	public function testRuleEdit_ToAFixedRule_AnchorsOnTheEditDay(): void {
		$f = $this->fixture(['frequency_type' => 'weekly'], $this->day(-10));
		self::assertSame(WindowService::STATE_OVERDUE, $this->block($f)['state']);
		$inThreeDays = strtolower((new \DateTime($this->day(3)))->format('l'));

		$this->edit($f, ['schedulingMode' => 'static', 'fixedScheduleDays' => [$inThreeDays]]);

		$block = $this->block($f);
		self::assertSame('d' . $this->day(3), $block['cycleKey'], 'the first fixed day on or after the edit');
		self::assertSame(WindowService::STATE_UPCOMING, $block['state']);
		self::assertSame($this->today, \OC::$server->get(RoomTaskMapper::class)->find($f['roomTaskId'])->getAnchorOn()->format('Y-m-d'));
		self::assertSame([], $this->rows('chorestrike_cycles', 'room_task_id', $f['roomTaskId']), 'no missed days are invented for the time before the rule');
		$this->assertProjectionMatchesWindow($f, 'edit to a fixed rule');
	}

	/**
	 * D-091-28 and the WP-B1 interim review: a fixed rule whose days change
	 * is anchored on a day of the rule it replaces, so a monthly rule keeps
	 * its day of the month; a change between recurring and bounded alone
	 * keeps the anchor.
	 */
	public function testRuleEdit_OfAFixedRule_KeepsItsDayOfTheMonth_AndBoundingKeepsTheAnchor(): void {
		$fifteenth = (new \DateTime($this->today))->modify('first day of -2 months')->format('Y-m-15');
		$f = $this->fixture(['frequency_type' => 'monthly', 'scheduling_mode' => 'static'], $fifteenth);
		$anchor = fn (): string => \OC::$server->get(RoomTaskMapper::class)->find($f['roomTaskId'])->getAnchorOn()->format('Y-m-d');

		$this->edit($f, ['recurrenceMode' => 'bounded', 'activeUntil' => $this->day(400)]);
		self::assertSame($fifteenth, $anchor(), 'bounding a rule does not move its days');

		$this->edit($f, ['frequencyValue' => 2]);
		self::assertSame('15', substr($anchor(), 8, 2), 'the new anchor is a rule day of the replaced rule');
		self::assertSame('15', substr($this->block($f)['centreOn'], 8, 2), 'the monthly day stays the 15th, not the day of the edit');
		$this->assertProjectionMatchesWindow($f, 'monthly interval edit');
	}

	public function testFlexEdit_WidensTheOpenWindowAtOnce(): void {
		$f = $this->fixture(['frequency_type' => 'weekly']);
		$before = $this->block($f);
		self::assertFalse($before['canPostpone'], 'no flex, nothing to postpone');

		$this->edit($f, ['flexDays' => 2]);

		$after = $this->block($f);
		self::assertSame($before['cycleKey'], $after['cycleKey']);
		self::assertSame([$this->day(-2), $this->day(2), $this->today], [$after['windowStartOn'], $after['windowEndOn'], $after['centreOn']]);
		self::assertTrue($after['canPostpone']);
		self::assertSame(0, $after['postponeCount']);
		$this->assertProjectionMatchesWindow($f, 'flex edit');
	}

	// ------------------------------------------------------------------
	// The next_due projection
	// ------------------------------------------------------------------

	public function testNextDueProjection_MatchesTheWindowAfterEveryWindowChangingCommand(): void {
		$houseId = $this->createHouse(self::OWNER, 'Projection House');
		$this->addHouseMember($houseId, 'ct_helper', 'Helper', 'member');
		$roomId = $this->createRoom($houseId, $this->createFloor($houseId, 'Ground', 1), 'Kitchen');
		$owner = $this->owner($houseId);

		$created = \OC::$server->get(TaskCreationService::class)->create($houseId, $owner, [
			'roomId' => $roomId,
			'task' => ['name' => 'Projection chore', 'frequencyType' => 'weekly', 'frequencyValue' => 1, 'flexDays' => 1],
			'assigneeUserIds' => [self::OWNER],
			'firstDate' => $this->day(1),
		]);
		$this->registerCreatedTask((int)$created['task']->getId());
		$f = ['houseId' => $houseId, 'roomId' => $roomId, 'taskId' => (int)$created['task']->getId(), 'roomTaskId' => (int)$created['roomTask']->getId()];
		$this->assertProjectionMatchesWindow($f, 'creation');
		self::assertSame($this->day(2), \OC::$server->get(RoomTaskMapper::class)->find($f['roomTaskId'])->getNextDue()->format('Y-m-d'));

		\OC::$server->get(RoomTaskService::class)->setAssignees($f['roomTaskId'], ['ct_helper'], $houseId, $owner);
		$this->assertProjectionMatchesWindow($f, 'assignee change');

		$this->edit($f, ['flexDays' => 3]);
		$this->assertProjectionMatchesWindow($f, 'flex edit');

		$this->edit($f, ['frequencyType' => 'daily', 'flexDays' => 0]);
		$this->assertProjectionMatchesWindow($f, 'rule edit');

		$this->setFirstDate($f['roomTaskId'], $this->today);
		$this->assertProjectionMatchesWindow($f, 'first date edit');

		$completionId = $this->complete($f, 'ct_helper');
		$this->assertProjectionMatchesWindow($f, 'completion');
		self::assertSame($this->day(1), \OC::$server->get(RoomTaskMapper::class)->find($f['roomTaskId'])->getNextDue()->format('Y-m-d'));

		$version = (int)$this->rows('chorestrike_completed_tasks', 'id', $completionId)[0]['completion_version'];
		$this->completionService->undo($houseId, $owner, $completionId, $this->newCommandId(), $version, 'not_done');
		$this->assertProjectionMatchesWindow($f, 'undo');
		self::assertSame($this->today, \OC::$server->get(RoomTaskMapper::class)->find($f['roomTaskId'])->getNextDue()->format('Y-m-d'));

		$this->actingAs(self::OWNER);
		\OC::$server->get(HouseService::class)->updateHouse($houseId, 'Projection House', 'Pacific/Auckland');
		$this->assertProjectionMatchesWindow($f, 'house zone change');
		self::assertNotSame('00:00:00', \OC::$server->get(RoomTaskMapper::class)->find($f['roomTaskId'])->getNextDue()->format('H:i:s'), 'the day starts in the house zone');
	}

	public function testJobPass_RepairsTheProjectionWhenTimeMovesAFixedRuleOn(): void {
		$f = $this->fixture(['scheduling_mode' => 'static']);
		$this->assertProjectionMatchesWindow($f, 'creation');

		$later = new \DateTime($this->day(2) . ' 09:00:00 UTC');
		$this->jobPass($f, $later);

		self::assertSame('d' . $this->day(2), $this->block($f, $later)['cycleKey'], 'the rule moved on with the days');
		$this->assertProjectionMatchesWindow($f, 'job pass', $later);
		self::assertSame($this->day(2), \OC::$server->get(RoomTaskMapper::class)->find($f['roomTaskId'])->getNextDue()->format('Y-m-d'));
	}

	// ------------------------------------------------------------------
	// Closed cycles are written exactly once
	// ------------------------------------------------------------------

	public function testJobPass_RecordsEachMissedFixedCycleOnce(): void {
		$f = $this->fixture(['scheduling_mode' => 'static'], $this->day(-3));

		$first = $this->jobPass($f);

		self::assertSame(3, $first['missed']);
		$rows = $this->rows('chorestrike_cycles', 'room_task_id', $f['roomTaskId']);
		self::assertSame(['d' . $this->day(-3), 'd' . $this->day(-2), 'd' . $this->day(-1)], array_column($rows, 'cycle_key'));
		$walletId = (int)$this->owner($f['houseId'])->getWalletId();
		foreach ($rows as $row) {
			self::assertSame(Cycle::OUTCOME_MISSED, $row['outcome']);
			self::assertNull($row['completion_id']);
			self::assertSame([$walletId], json_decode((string)$row['responsible'], true), 'a missed cycle counts for who was responsible');
			self::assertSame(2, (int)$row['energy']);
			self::assertSame(substr((string)$row['window_end_on'], 0, 10), substr((string)$row['cycle_key'], 1));
		}
		self::assertSame('d' . $this->today, $this->block($f)['cycleKey'], 'today is the one open entry');
		self::assertSame(WindowService::STATE_OPEN, $this->block($f)['state']);

		self::assertSame(['tasks' => 0, 'missed' => 0], $this->jobPass($f), 'a repeated pass writes nothing');
		self::assertCount(3, $this->rows('chorestrike_cycles', 'room_task_id', $f['roomTaskId']));

		// Completing today leaves the missed days as they are.
		$this->complete($f);
		$outcomes = array_column($this->rows('chorestrike_cycles', 'room_task_id', $f['roomTaskId']), 'outcome');
		self::assertSame([Cycle::OUTCOME_MISSED, Cycle::OUTCOME_MISSED, Cycle::OUTCOME_MISSED, Cycle::OUTCOME_DONE], $outcomes);
	}

	/** An after-completion task waits: one red entry, never a row per missed day. */
	public function testJobPass_AfterCompletionRule_StaysOneOverdueEntry(): void {
		$f = $this->fixture([], $this->day(-10));

		self::assertSame(0, $this->jobPass($f)['missed']);

		self::assertSame([], $this->rows('chorestrike_cycles', 'room_task_id', $f['roomTaskId']));
		$block = $this->block($f);
		self::assertSame(['d' . $this->day(-10), WindowService::STATE_OVERDUE], [$block['cycleKey'], $block['state']]);
	}

	/** ADR-021 decision 12: nothing before the cutover day is the derived model's to record. */
	public function testJobPass_RecordsNoMissedCycleFromBeforeTheCutover(): void {
		$f = $this->fixture(['scheduling_mode' => 'static'], $this->day(-3));
		$qb = $this->db->getQueryBuilder();
		$qb->update('chorestrike_houses')
			->set('cycles_from', $qb->createNamedParameter($this->day(-1)))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($f['houseId'], IQueryBuilder::PARAM_INT)))
			->executeStatement();

		$this->jobPass($f);

		self::assertSame(['d' . $this->day(-1)], array_column($this->rows('chorestrike_cycles', 'room_task_id', $f['roomTaskId']), 'cycle_key'));
	}

	/**
	 * WP-B1 interim review: a fixed cycle that stopped being current is
	 * recorded as missed when the next one is completed before the job
	 * reached it — afterwards the job would skip it.
	 */
	public function testCompletion_OfTheNextFixedCycle_RecordsTheMissTheJobHadNotReached(): void {
		$f = $this->fixture(['scheduling_mode' => 'static'], $this->day(-1));
		self::assertSame('d' . $this->today, $this->block($f)['cycleKey'], 'yesterday stopped being current at midnight');

		$this->complete($f);

		$rows = $this->rows('chorestrike_cycles', 'room_task_id', $f['roomTaskId']);
		self::assertSame([['d' . $this->day(-1), Cycle::OUTCOME_MISSED], ['d' . $this->today, Cycle::OUTCOME_DONE]], array_map(
			static fn (array $row): array => [$row['cycle_key'], $row['outcome']],
			$rows
		));
		self::assertSame(['tasks' => 0, 'missed' => 0], $this->jobPass($f), 'the job finds nothing left to record');
	}

	/**
	 * WP-B1 interim review (D-091-02): undoing an older fixed completion
	 * while a later one stays valid leaves a cycle that is no longer
	 * current and whose window ended — it is a miss, not a gap.
	 */
	public function testUndo_OfAnOlderFixedCompletion_RecordsItsCycleAsMissed(): void {
		$f = $this->fixture(['scheduling_mode' => 'static'], $this->day(-1));
		$owner = $this->owner($f['houseId']);
		$yesterday = $this->insertCompletionRow($f['roomTaskId'], (int)$owner->getId(), $this->day(-1) . ' 12:00:00');
		$this->insertClosedCycle($f['houseId'], $f['roomTaskId'], $this->day(-1), $this->day(-1), [(int)$owner->getWalletId()], $yesterday);
		$this->complete($f);

		$qb = $this->db->getQueryBuilder();
		$qb->update('chorestrike_completed_tasks')
			->set('undone_at', $qb->createNamedParameter(date('Y-m-d H:i:s')))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($yesterday, IQueryBuilder::PARAM_INT)))
			->executeStatement();
		$roomTask = \OC::$server->get(RoomTaskMapper::class)->find($f['roomTaskId']);
		$this->cycleService->reopenUnderLock(
			$f['houseId'],
			$roomTask,
			\OC::$server->get(TaskMapper::class)->find($f['taskId']),
			\OC::$server->get(\OCA\ChoreStrike\Db\CompletedTaskMapper::class)->find($yesterday),
			new \DateTimeZone('UTC'),
			new \DateTime()
		);

		$rows = $this->rows('chorestrike_cycles', 'room_task_id', $f['roomTaskId']);
		self::assertSame([['d' . $this->today, Cycle::OUTCOME_DONE, true], ['d' . $this->day(-1), Cycle::OUTCOME_MISSED, false]], array_map(
			static fn (array $row): array => [$row['cycle_key'], $row['outcome'], $row['completion_id'] !== null],
			$rows
		));
		self::assertSame('d' . $this->day(1), $this->block($f)['cycleKey'], 'the current cycle follows the valid work of today');
	}

	/** WP-B1 interim review: no miss predates its room task (a past first date). */
	public function testJobPass_RecordsNoMissForWindowsBeforeTheRoomTaskWasCreated(): void {
		$f = $this->fixture(['scheduling_mode' => 'static'], $this->day(-3));
		$qb = $this->db->getQueryBuilder();
		$qb->update('chorestrike_room_tasks')
			->set('created_at', $qb->createNamedParameter(date('Y-m-d H:i:s')))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($f['roomTaskId'], IQueryBuilder::PARAM_INT)))
			->executeStatement();

		self::assertSame(0, $this->jobPass($f)['missed']);

		self::assertSame([], $this->rows('chorestrike_cycles', 'room_task_id', $f['roomTaskId']), 'created today with a first date three days ago');
		self::assertSame(['d' . $this->today, WindowService::STATE_OPEN], [$this->block($f)['cycleKey'], $this->block($f)['state']]);
	}

	public function testConcurrentJobRuns_RecordEachMissedCycleOnce(): void {
		$f = $this->fixture(['scheduling_mode' => 'static'], $this->day(-4));
		$base = sys_get_temp_dir() . '/cs-cycle-job-' . bin2hex(random_bytes(6));
		$script = $base . '.php';
		file_put_contents($script, <<<'PHP'
<?php
require '/var/www/html/lib/base.php';

[, $houseId, $bootedFile, $goFile] = $argv;
$house = \OC::$server->get(\OCA\ChoreStrike\Db\HouseMapper::class)->find((int)$houseId);
file_put_contents($bootedFile, 'booted');
$deadline = microtime(true) + 30.0;
while (!file_exists($goFile)) {
    if (microtime(true) > $deadline) {
        fwrite(STDERR, 'go signal never arrived');
        exit(1);
    }
    usleep(2000);
}
echo json_encode(\OC::$server->get(\OCA\ChoreStrike\Service\CycleAdvanceService::class)->advanceHouse($house, new DateTime()));
PHP);

		$workers = [];
		try {
			foreach ([1, 2] as $n) {
				$process = proc_open([PHP_BINARY, $script, (string)$f['houseId'], $base . ".booted$n", $base . '.go'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
				self::assertIsResource($process, 'failed to start job worker ' . $n);
				$workers[$n] = [$process, $pipes];
			}
			$deadline = microtime(true) + 30.0;
			while (!file_exists($base . '.booted1') || !file_exists($base . '.booted2')) {
				self::assertLessThan($deadline, microtime(true), 'the job workers did not boot in time');
				usleep(20000);
			}
			file_put_contents($base . '.go', 'go');

			$written = 0;
			foreach ($workers as $n => [$process, $pipes]) {
				$stdout = stream_get_contents($pipes[1]);
				$stderr = stream_get_contents($pipes[2]);
				fclose($pipes[1]);
				fclose($pipes[2]);
				self::assertSame(0, proc_close($process), "job worker $n failed: $stderr");
				$written += (int)(json_decode((string)$stdout, true)['missed'] ?? -100);
			}
		} finally {
			foreach (glob($base . '*') ?: [] as $file) {
				@unlink($file);
			}
		}

		self::assertSame(4, $written, 'both passes together wrote each missed cycle once');
		self::assertSame(
			['d' . $this->day(-4), 'd' . $this->day(-3), 'd' . $this->day(-2), 'd' . $this->day(-1)],
			array_column($this->rows('chorestrike_cycles', 'room_task_id', $f['roomTaskId']), 'cycle_key')
		);
	}

	// ------------------------------------------------------------------
	// Sub-tasks and bonus tasks
	// ------------------------------------------------------------------

	/** D-089-02: partly ticked work stays saved; ticks never leak into the next cycle. */
	public function testChecklistProgress_BelongsToItsCycle_AndPartlyTickedWorkIsKept(): void {
		$f = $this->fixture();
		$rinse = $this->createChecklistItem($f['taskId'], 'Rinse', 0);
		$this->createChecklistItem($f['taskId'], 'Dry', 1);
		$checklist = \OC::$server->get(ChecklistService::class);
		$viewer = $this->owner($f['houseId']);
		$firstKey = 'd' . $this->today;

		$toggled = $checklist->toggleItem($f['roomTaskId'], $firstKey, $rinse, 0, $f['houseId'], $viewer);
		self::assertSame($firstKey, $toggled['cycle']['cycleKey']);
		$progress = $checklist->getRoomTaskProgress($f['roomTaskId'], null, $f['houseId'], $viewer);
		self::assertSame([2, 1], [$progress['stats']['total'], $progress['stats']['checked']], 'one of two is ticked and saved');
		self::assertSame(WindowService::STATE_OPEN, $this->block($f)['state'], 'the task stays open');

		// The work is completed with one sub-task open: the completion marks
		// it done (ADR-021 decision 4), and the next cycle starts empty.
		$completionId = $this->complete($f);
		$next = $checklist->getRoomTaskProgress($f['roomTaskId'], null, $f['houseId'], $viewer);
		self::assertSame('c' . $completionId, $next['cycle']['cycleKey']);
		self::assertSame([2, 0, []], [$next['stats']['total'], $next['stats']['checked'], $next['items']]);
		self::assertNull($checklist->getRoomTaskProgress($f['roomTaskId'], $firstKey, $f['houseId'], $viewer)['cycle'], 'a closed cycle is no longer shown');

		try {
			$checklist->toggleItem($f['roomTaskId'], $firstKey, $rinse, null, $f['houseId'], $viewer);
			self::fail('a tick aimed at the closed cycle must conflict');
		} catch (ConflictException $e) {
			self::assertSame(ConflictException::REASON_STALE_TARGET, $e->getReason());
		}
		$checklist->toggleItem($f['roomTaskId'], 'c' . $completionId, $rinse, 0, $f['houseId'], $viewer);

		$stored = $this->rows('chorestrike_cycle_progress', 'room_task_id', $f['roomTaskId']);
		self::assertSame([$firstKey, $firstKey, 'c' . $completionId], array_column($stored, 'cycle_key'), 'the ticks of the closed cycle stay as evidence');
		self::assertSame([1, 1], [(int)$stored[0]['is_checked'], (int)$stored[1]['is_checked']], 'the completion closed the cycle with every sub-task done');
		self::assertSame([1, 1], [(int)$stored[0]['revision'], (int)$stored[1]['revision']], 'a tick that was already set is left as it was');
		self::assertSame((int)$viewer->getId(), (int)$stored[1]['checked_by_user_id'], 'the rest is ticked by who recorded the work');
		self::assertSame([], $this->rows('chorestrike_chk_progress', 'room_task_id', $f['roomTaskId']), 'nothing is written to the occurrence-keyed table');
	}

	/** ADR-021 decision 8: a bonus task has no window, no cycle and no miss. */
	public function testBonusTask_CompletesWithoutACycle(): void {
		$f = $this->fixture(['is_voluntary' => 1], $this->day(-5));

		$block = $this->block($f);
		self::assertSame(WindowService::STATE_BONUS, $block['state']);
		self::assertArrayNotHasKey('cycleKey', $block);
		self::assertSame(0, $this->jobPass($f)['missed']);

		try {
			$this->completionService->complete($f['houseId'], self::OWNER, $f['roomTaskId'], $this->newCommandId(), 'd' . $this->day(-5), $block['expectedVersion']);
			self::fail('a bonus task has no cycle to aim at');
		} catch (ConflictException $e) {
			self::assertSame(ConflictException::REASON_STALE_TARGET, $e->getReason());
		}
		$completionId = $this->complete($f);

		$ledger = $this->rows('chorestrike_completed_tasks', 'id', $completionId)[0];
		self::assertSame(CompletedTask::CLASS_BONUS, $ledger['completion_class']);
		self::assertNull($ledger['cycle_key']);
		self::assertNull($ledger['window_end_on']);
		self::assertSame([], $this->rows('chorestrike_cycles', 'room_task_id', $f['roomTaskId']), 'bonus work closes no cycle');
		self::assertSame(WindowService::STATE_BONUS, $this->block($f)['state'], 'and stays available');
		self::assertTrue((bool)$this->rows('chorestrike_room_tasks', 'id', $f['roomTaskId'])[0]['is_active']);

		// Until WP-B4 brings the per-person rule, the day rule holds for
		// bonus work too (WP-B1 interim review: no unlimited payouts).
		try {
			$this->complete($f, 'ct_helper');
			self::fail('a second bonus completion on the same day must conflict');
		} catch (ConflictException $e) {
			self::assertSame(ConflictException::REASON_SAME_DAY, $e->getReason());
		}
		self::assertCount(1, $this->rows('chorestrike_completed_tasks', 'room_task_id', $f['roomTaskId']));
	}
}
