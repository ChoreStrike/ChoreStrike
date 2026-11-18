<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Db;

use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Service\ActivityService;
use OCA\ChoreStrike\Service\CoinMultiplierService;
use OCA\ChoreStrike\Service\CompletionDateCommand;
use OCA\ChoreStrike\Service\CompletionService;
use OCA\ChoreStrike\Service\PauseService;
use OCA\ChoreStrike\Service\PostponeService;
use OCA\ChoreStrike\Service\RoomTaskReadService;
use OCA\ChoreStrike\Service\SleepService;
use OCA\ChoreStrike\Service\WindowService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;
use OCA\ChoreStrike\Tests\Integration\Support\CycleFixtures;
use OCA\ChoreStrike\Tests\Integration\Support\QueryBudgets;
use OCA\ChoreStrike\Tests\Integration\Support\QueryCounter;

/**
 * Request budgets of what members do with a cycle besides completing it
 * (CS-PLAN-091 RF-091-08, D-091-09): the history of a task, the postpone
 * command, the date correction, the recent activity with its events, and
 * a completion that marks sub-tasks; and of WP-B4/WP-B5 (D-091-80): the
 * bonus rows of Today, the sleep command, the completion of a bonus and of
 * a sleeping task, and the bonus period of the house payload. Measured with the executed-query
 * counter against every supported database; the literals are frozen like
 * those of {@see WorkflowQueryBudgetTest}, so a regression cannot
 * re-baseline itself, and every budget also asserts the result.
 */
class CycleCommandQueryBudgetTest extends IntegrationTestCase {
	use CycleFixtures;
	use QueryBudgets;

	protected const OWNER = 'ccq_owner';
	protected const HELPER = 'ccq_helper';

	// The room task with its definition and assignees (the one visibility
	// decision), the entries, the members, the room, the selected shares
	// and the latest valid entry: constant in the number of entries.
	private const HISTORY_BUDGET = 8;
	// Guard and aggregate under the house lock, the receipt, the cycle
	// derivation (one completion read for both derivations) and the fresh
	// row of the answer.
	private const POSTPONE_BUDGET = 20;
	// A correction aggregate without a ledger effect: the entry and its
	// neighbours, the audit row, the room journal replay, the closed cycle
	// and the cycle that follows, the rhythm and the answer.
	private const DATE_BUDGET = 82;
	// Completions, their count, the task events (receipts and audit rows),
	// one membership read, one visibility decision for both lists (room
	// tasks, definitions, assignees), the rooms, the shares, the latest
	// valid entries and the absences of the period.
	private const ACTIVITY_BUDGET = 14;
	// What every sub-task adds to a completion: its tick row.
	private const WRITES_PER_SUB_TASK = 1;
	// CS-PLAN-091 WP-B4/WP-B5 (D-091-80). The bonus rows of Today: the
	// visible room tasks with their definitions, the assignees, the rooms,
	// the cycle inputs and whom each paid today with the members it maps
	// to; constant in the number of bonus tasks.
	private const BONUS_ROWS_BUDGET = 11;
	// The sleep command on a fixed rule: guard and aggregate under the
	// house lock, the receipt, the misses recorded first and the fresh row.
	private const SLEEP_BUDGET = 22;
	// Waking it again: the fresh anchor and the fresh row.
	private const WAKE_BUDGET = 20;
	// A bonus completion: the ledger aggregate without a cycle, plus the
	// per-person rule of the day and the bonus periods of the work day.
	private const BONUS_COMPLETE_BUDGET = 74;
	// A sleeping task's completion: the ledger aggregate with the wake
	// inside the target resolution.
	private const ASLEEP_COMPLETE_BUDGET = 88;

	protected function setUp(): void {
		parent::setUp();
		$this->initToday();
	}

	/** @return array{0: mixed, 1: int, 2: list<string>} The result, the statement count and the statements */
	private function measured(callable $read): array {
		$counter = QueryCounter::attach($this->db);
		try {
			$counter->mark();
			$result = $read();
			return [$result, $counter->count(), $counter->sqlStatements()];
		} finally {
			$counter->detach();
		}
	}

	public function testHistory_ConstantQueryCount_10vs50Entries(): void {
		$f = $this->fixture();
		$owner = $this->member($f['houseId'], self::OWNER);
		for ($day = 60; $day >= 1; $day--) {
			$this->insertCompletionRow($f['roomTaskId'], (int)$owner->getId(), $this->day(-$day) . ' 10:00:00');
		}
		$history = \OC::$server->get(ActivityService::class);

		[$ten, $tenCount] = $this->measured(fn (): array => $history->getRoomTaskHistory($f['houseId'], $f['roomTaskId'], 10, $owner));
		[$fifty, $fiftyCount, $statements] = $this->measured(fn (): array => $history->getRoomTaskHistory($f['houseId'], $f['roomTaskId'], 50, $owner));

		self::assertCount(10, $ten['completions']);
		self::assertCount(50, $fifty['completions']);
		self::assertSame($this->day(-1), $fifty['completions'][0]['workDate'], 'newest first');
		self::assertSame($tenCount, $fiftyCount, 'Q(50) must equal Q(10): the history is batched');
		self::assertLessThanOrEqual(self::HISTORY_BUDGET, $fiftyCount, implode("\n", $statements));
	}

	public function testPostpone_WithinBudget(): void {
		$f = $this->fixture(['frequency_type' => 'weekly', 'flex_days' => 2]);
		$block = $this->block($f);
		$helper = $this->member($f['houseId'], self::HELPER);
		$service = \OC::$server->get(PostponeService::class);

		$counter = QueryCounter::attach($this->db);
		try {
			$counter->mark();
			$result = $service->postpone($f['houseId'], $helper, $f['roomTaskId'], $this->newCommandId(), $block['cycleKey'], $block['expectedVersion']);
		} finally {
			$counter->detach();
		}

		self::assertSame(1, $result['cycle']['postponeCount']);
		// Locks: the house and the room task. Generated ids: the receipt.
		$this->assertPortableWriteBudget($counter, self::POSTPONE_BUDGET, 2, 1);
	}

	public function testDateCorrection_WithinBudget(): void {
		$f = $this->fixture(['frequency_type' => 'weekly', 'flex_days' => 1], $this->day(-3));
		$owner = $this->member($f['houseId'], self::OWNER);
		$completionId = (int)$this->completeOccurrence(\OC::$server->get(CompletionService::class), $f['houseId'], self::OWNER, $f['roomTaskId'])['completionId'];
		$command = \OC::$server->get(CompletionDateCommand::class);

		$counter = QueryCounter::attach($this->db);
		try {
			$counter->mark();
			$result = $command->changeDate($f['houseId'], $owner, $completionId, $this->newCommandId(), 0, $this->day(-2));
		} finally {
			$counter->detach();
		}

		self::assertSame($this->day(-2), substr((string)$this->rows('chorestrike_completed_tasks', 'id', $completionId)[0]['work_date'], 0, 10));
		self::assertSame($this->day(5), $result['cycle']['centreOn'], 'the next window moved with the day');
		// Locks: house, room task, room, the member credited, and the house
		// again for its state version. Generated ids: the audit row and the receipt.
		$this->assertPortableWriteBudget($counter, self::DATE_BUDGET, 5, 2);
	}

	public function testActivity_WithEvents_ConstantQueryCount_3vs30Rows(): void {
		$small = $this->houseWithActivity('Small', 3);
		$big = $this->houseWithActivity('Big', 30);
		$activity = \OC::$server->get(ActivityService::class);
		$read = fn (array $house): array => $activity->getActivity($house['houseId'], 30, 200, null, $this->member($house['houseId'], self::OWNER));

		[$smallFeed, $smallCount] = $this->measured(fn (): array => $read($small));
		[$bigFeed, $bigCount, $statements] = $this->measured(fn (): array => $read($big));

		// The events: one postponement and one absence per task.
		self::assertSame([3, 6], [count($smallFeed['completions']), count($smallFeed['events'])]);
		self::assertSame([30, 60], [count($bigFeed['completions']), count($bigFeed['events'])]);
		self::assertCount(30, array_filter($bigFeed['events'], static fn (array $event): bool => str_starts_with((string)$event['type'], 'absence')), 'the absences of the period are read and counted');
		self::assertNotNull($bigFeed['events'][0]['taskTitle'], 'the events show the titles the viewer may read');
		self::assertSame($smallCount, $bigCount, 'Q(30) must equal Q(3): completions and events are batched');
		self::assertLessThanOrEqual(self::ACTIVITY_BUDGET, $bigCount, implode("\n", $statements));
	}

	public function testCompletion_WithSubTasks_AddsOneTickPerSubTask(): void {
		$plain = $this->fixture();
		$withTwo = $this->fixtureWithSubTasks(2);
		$withSix = $this->fixtureWithSubTasks(6);
		$service = \OC::$server->get(CompletionService::class);
		$complete = fn (array $f): array => $this->measured(fn (): array => $this->completeOccurrence($service, $f['houseId'], self::OWNER, $f['roomTaskId']));

		[, $plainCount] = $complete($plain);
		[, $twoCount] = $complete($withTwo);
		[, $sixCount, $statements] = $complete($withSix);

		self::assertCount(6, $this->rows('chorestrike_cycle_progress', 'room_task_id', $withSix['roomTaskId']), 'every sub-task is marked with the completion');
		// A tick is one insert, plus its generated id on PostgreSQL.
		$perItem = self::WRITES_PER_SUB_TASK + ($this->db->getDatabaseProvider() === \OCP\IDBConnection::PLATFORM_POSTGRES ? 1 : 0);
		self::assertSame(4 * $perItem, $sixCount - $twoCount, implode("\n", $statements));
		// Sub-tasks at all add the one read of the cycle's ticks.
		self::assertSame(1 + 2 * $perItem, $twoCount - $plainCount);
	}

	public function testBonusRows_ConstantQueryCount_3vs30BonusTasks(): void {
		$small = $this->houseWithBonusTasks('Small', 3);
		$big = $this->houseWithBonusTasks('Big', 30);
		$read = \OC::$server->get(RoomTaskReadService::class);
		$rows = fn (array $house): array => $read->getBonusTaskRows($house['houseId'], $this->member($house['houseId'], self::OWNER));

		[$smallRows, $smallCount] = $this->measured(fn (): array => $rows($small));
		[$bigRows, $bigCount, $statements] = $this->measured(fn (): array => $rows($big));

		self::assertSame([3, 30], [count($smallRows), count($bigRows)]);
		self::assertSame([self::OWNER], $bigRows[29]['creditedToday'], 'each row says whom it paid today');
		self::assertSame($smallCount, $bigCount, 'Q(30) must equal Q(3): the bonus rows are batched');
		self::assertLessThanOrEqual(self::BONUS_ROWS_BUDGET, $bigCount, implode("\n", $statements));
	}

	public function testSleepAndWake_WithinBudget(): void {
		$f = $this->fixture(['scheduling_mode' => 'static', 'frequency_type' => 'daily']);
		$owner = $this->member($f['houseId'], self::OWNER);
		$sleep = \OC::$server->get(SleepService::class);
		$command = function (bool $sleeping) use ($f, $owner, $sleep): QueryCounter {
			$version = (int)$this->rows('chorestrike_room_tasks', 'id', $f['roomTaskId'])[0]['cycle_version'];
			$counter = QueryCounter::attach($this->db);
			try {
				$counter->mark();
				$sleep->setSleeping($f['houseId'], $owner, $f['roomTaskId'], $this->newCommandId(), $version, $sleeping, null);
				return $counter;
			} finally {
				$counter->detach();
			}
		};

		$asleep = $command(true);
		self::assertSame(WindowService::STATE_ASLEEP, $this->block($f)['state']);
		// Locks: the house and the room task. Generated ids: the receipt.
		$this->assertPortableWriteBudget($asleep, self::SLEEP_BUDGET, 2, 1);

		$awake = $command(false);
		self::assertSame('d' . $this->today, $this->block($f)['cycleKey']);
		$this->assertPortableWriteBudget($awake, self::WAKE_BUDGET, 2, 1);
	}

	public function testBonusCompletion_WithinBudget(): void {
		$f = $this->fixture(['is_voluntary' => 1]);
		$counter = $this->measuredCompletion($f);

		self::assertSame(\OCA\ChoreStrike\Db\CompletedTask::CLASS_BONUS, $this->rows('chorestrike_completed_tasks', 'room_task_id', $f['roomTaskId'])[0]['completion_class']);
		// The seven locks of every completion (WorkflowQueryBudgetTest).
		// Generated ids: the evidence, its share, its audit row, the ledger
		// entry, the room event and the receipt — no closed-cycle row.
		$this->assertPortableWriteBudget($counter, self::BONUS_COMPLETE_BUDGET, 7, 6);
	}

	public function testSleepingTaskCompletion_WithinBudget(): void {
		$f = $this->fixture(['frequency_type' => 'weekly', 'flex_days' => 1]);
		\OC::$server->get(SleepService::class)->setSleeping(
			$f['houseId'], $this->member($f['houseId'], self::OWNER), $f['roomTaskId'], $this->newCommandId(), $this->block($f)['expectedVersion'], true, null
		);
		$counter = $this->measuredCompletion($f);

		self::assertNull($this->rows('chorestrike_room_tasks', 'id', $f['roomTaskId'])[0]['sleeping_since'], 'the completion woke it');
		$this->assertPortableWriteBudget($counter, self::ASLEEP_COMPLETE_BUDGET, 7, 7);
	}

	public function testHousePayload_TheBonusPeriodIsOneRead(): void {
		$f = $this->fixture();
		$house = \OC::$server->get(HouseMapper::class)->find($f['houseId']);
		$this->actingAs(self::OWNER);
		$multipliers = \OC::$server->get(CoinMultiplierService::class);
		$multipliers->startPeriod($f['houseId'], $this->today, 1, $this->newCommandId());

		[$period, $count, $statements] = $this->measured(fn () => $multipliers->runningOrNext($house));

		self::assertSame($this->today, $period?->getStartsOn()->format('Y-m-d'));
		self::assertSame(1, $count, implode("\n", $statements));
	}

	private function measuredCompletion(array $f): QueryCounter {
		$service = \OC::$server->get(CompletionService::class);
		$counter = QueryCounter::attach($this->db);
		try {
			$counter->mark();
			$this->completeOccurrence($service, $f['houseId'], self::OWNER, $f['roomTaskId']);
			return $counter;
		} finally {
			$counter->detach();
		}
	}

	/** A house of bonus tasks the owner has each done once today. */
	private function houseWithBonusTasks(string $name, int $tasks): array {
		$this->actingAs(self::OWNER);
		$houseId = $this->createHouse(self::OWNER, 'CCQ Bonus ' . $name);
		$roomId = $this->createRoom($houseId, $this->createFloor($houseId, 'Ground', 1), 'Kitchen');
		$completions = \OC::$server->get(CompletionService::class);
		for ($i = 0; $i < $tasks; $i++) {
			$taskId = $this->createTask($houseId, 'Bonus ' . $i, '', 3, 1);
			$qb = $this->db->getQueryBuilder();
			$qb->update('chorestrike_tasks')
				->set('is_voluntary', $qb->createNamedParameter(true, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_BOOL))
				->where($qb->expr()->eq('id', $qb->createNamedParameter($taskId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
				->executeStatement();
			$roomTaskId = $this->assignTaskToRoom($taskId, $roomId, self::OWNER, $this->today . ' 00:00:00');
			$this->completeOccurrence($completions, $houseId, self::OWNER, $roomTaskId);
		}
		return ['houseId' => $houseId];
	}

	/**
	 * A house whose tasks were each postponed once and then completed, a
	 * minute ago, with as many absences of the owner entered in the period.
	 */
	private function houseWithActivity(string $name, int $tasks): array {
		$this->actingAs(self::OWNER);
		$houseId = $this->createHouse(self::OWNER, 'CCQ ' . $name);
		$roomId = $this->createRoom($houseId, $this->createFloor($houseId, 'Ground', 1), 'Kitchen');
		$owner = $this->member($houseId, self::OWNER);
		$postpones = \OC::$server->get(PostponeService::class);
		$completions = \OC::$server->get(CompletionService::class);
		$pauses = \OC::$server->get(PauseService::class);
		for ($i = 0; $i < $tasks; $i++) {
			// One absence group each, on days of their own (RF-091-08: the absence read is part of the budget).
			$pauses->requestForSelf($houseId, self::OWNER, ['commandId' => $this->newCommandId(), 'startDate' => $this->day(2 + 2 * $i), 'endDate' => $this->day(2 + 2 * $i)]);
			$taskId = $this->createTask($houseId, 'Chore ' . $i, '', 3, 1);
			$qb = $this->db->getQueryBuilder();
			$qb->update('chorestrike_tasks')
				->set('frequency_type', $qb->createNamedParameter('weekly'))
				->set('flex_days', $qb->createNamedParameter(2, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
				->where($qb->expr()->eq('id', $qb->createNamedParameter($taskId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
				->executeStatement();
			$roomTaskId = $this->assignTaskToRoom($taskId, $roomId, self::OWNER, $this->today . ' 00:00:00');
			[, $cycleKey, $version] = $this->completionReceiptFor($roomTaskId);
			$postpones->postpone($houseId, $owner, $roomTaskId, $this->newCommandId(), (string)$cycleKey, $version);
			$completionId = (int)$this->completeOccurrence($completions, $houseId, self::OWNER, $roomTaskId)['completionId'];
			// The feed reads up to the second it runs in, exclusively.
			$qb = $this->db->getQueryBuilder();
			$qb->update('chorestrike_completed_tasks')
				->set('completed_at', $qb->createNamedParameter(gmdate('Y-m-d H:i:s', time() - 60)))
				->where($qb->expr()->eq('id', $qb->createNamedParameter($completionId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
				->executeStatement();
		}
		return ['houseId' => $houseId];
	}

	private function fixtureWithSubTasks(int $count): array {
		$f = $this->fixture();
		for ($i = 1; $i <= $count; $i++) {
			$this->createChecklistItem($f['taskId'], 'Step ' . $i, $i);
		}
		return $f;
	}
}
