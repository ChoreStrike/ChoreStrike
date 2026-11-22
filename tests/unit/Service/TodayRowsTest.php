<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\Room;
use OCA\ChoreStrike\Db\RoomMapper;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Service\CalendarService;
use OCA\ChoreStrike\Service\CurrentCycle;
use OCA\ChoreStrike\Service\RecurrenceCalculator;
use OCA\ChoreStrike\Service\RoomTaskReadService;
use OCA\ChoreStrike\Service\SuggestionContext;
use OCA\ChoreStrike\Service\SuggestionJob;
use OCA\ChoreStrike\Service\SuggestionMember;
use OCA\ChoreStrike\Service\SuggestionPlan;
use OCA\ChoreStrike\Service\SuggestionService;
use OCA\ChoreStrike\Service\SuggestionView;
use OCA\ChoreStrike\Service\TaskAccessPolicy;
use OCA\ChoreStrike\Service\TaskService;
use OCA\ChoreStrike\Service\TodayRows;
use OCA\ChoreStrike\Service\WindowService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The daily read of *Today* (CS-PLAN-091 WP-C, RF-091-14): the live
 * suggestion decides which open rows are today's work (ADR-022), every
 * other open row is also possible, and a child is offered its own and the
 * unassigned work up to the daily limit. The rows are built from the
 * inputs the suggestion read, so the read issues no second query for room
 * tasks, definitions, assignees or completions, and a child's
 * availability and limit are the ones the plan was placed with.
 */
class TodayRowsTest extends TestCase {
	private const DATE = '2026-03-07'; // A Saturday
	private const VIEWER_ID = 1;

	private TodayRows $service;
	private RoomMapper|MockObject $roomMapper;
	private \OCA\ChoreStrike\Db\TaskChecklistItemMapper|MockObject $checklistItemMapper;
	/** @var array<int, RoomTask> The active room tasks of the house, as the suggestion read them. */
	private array $roomTasks = [];
	/** @var array<int, Task> */
	private array $tasks = [];
	/** @var array<int, list<string>> */
	private array $assignees = [];
	/** @var array<int, string> Room tasks without an open window today: `late` or `none`. */
	private array $closed = [];
	/** @var array<int, array{cycleKey: string, userIds: list<string>, overload: bool}> Today's suggestion by room task. */
	private array $suggested = [];
	/** @var array<int, HouseUser> The members the suggestion read. */
	private array $members = [];
	/**
	 * @var array<int, array{available?: bool, limit?: int}> The budgets the plan was placed with, by member:
	 *                                                       available today unless set, without a limit unless set,
	 *                                                       whatever the member's stored preferences say
	 */
	private array $planned = [];

	protected function setUp(): void {
		$this->roomTasks = $this->tasks = $this->assignees = $this->closed = $this->suggested = $this->members = $this->planned = [];
		$this->roomMapper = $this->createMock(RoomMapper::class);
		$this->checklistItemMapper = $this->createMock(\OCA\ChoreStrike\Db\TaskChecklistItemMapper::class);

		// The suggestion is stubbed: its rules are pinned in SuggestionPlannerTest.
		// Its inputs are the room tasks of the house with their current cycle.
		// "Everything visible": the matrix is covered in TaskAccessPolicyTest.
		$suggestions = $this->createMock(SuggestionService::class);
		$suggestions->method('view')->willReturnCallback(function (): SuggestionView {
			$context = $this->context();
			$view = $this->getMockBuilder(SuggestionView::class)
				->setConstructorArgs([$context, new SuggestionPlan($context->ledger())])
				->onlyMethods(['today'])
				->getMock();
			$view->method('today')->willReturn($this->suggested);
			return $view;
		});
		$suggestions->method('visibleRoomTasks')->willReturnCallback(fn (SuggestionContext $context): array => $context->roomTasks);

		// No mapper but the rooms' and the sub-task counts' may be asked: the rest is the suggestion's.
		$roomTaskMapper = $this->createMock(\OCA\ChoreStrike\Db\RoomTaskMapper::class);
		$roomTaskMapper->expects($this->never())->method($this->anything());
		$taskMapper = $this->createMock(\OCA\ChoreStrike\Db\TaskMapper::class);
		$taskMapper->expects($this->never())->method($this->anything());
		$assigneeMapper = $this->createMock(\OCA\ChoreStrike\Db\TaskAssigneeMapper::class);
		$assigneeMapper->expects($this->never())->method($this->anything());
		$completions = $this->createMock(\OCA\ChoreStrike\Db\CompletedTaskMapper::class);
		$completions->expects($this->never())->method($this->anything());
		// The House comes with the call: it is not read again.
		$houseMapper = $this->createMock(\OCA\ChoreStrike\Db\HouseMapper::class);
		$houseMapper->expects($this->never())->method($this->anything());

		$windows = new WindowService(new RecurrenceCalculator(), $completions);
		$this->service = new TodayRows($suggestions, new RoomTaskReadService(
			$roomTaskMapper,
			$taskMapper,
			$this->roomMapper,
			$this->createMock(\OCA\ChoreStrike\Db\FloorMapper::class),
			$houseMapper,
			$assigneeMapper,
			$this->createMock(TaskAccessPolicy::class),
			new CalendarService(),
			$windows,
			$this->createMock(\OCA\ChoreStrike\Service\AppLogger::class),
			$this->checklistItemMapper,
			$this->createMock(\OCA\ChoreStrike\Service\CreditPolicy::class),
		), $windows);
	}

	private static function key(): string {
		return 'd' . self::DATE;
	}

	/**
	 * What the suggestion read: every room task has an open window around
	 * today unless the test closes it, and the members are the viewers the
	 * test made.
	 */
	private function context(): SuggestionContext {
		$jobs = [];
		foreach ($this->roomTasks as $id => $roomTask) {
			$cycle = match ($this->closed[$id] ?? null) {
				'late' => new CurrentCycle('d2026-03-04', '2026-03-04', '2026-03-04', '2026-03-04'),
				'none' => null,
				default => new CurrentCycle(self::key(), self::DATE, '2026-03-06', '2026-03-08'),
			};
			if ($cycle !== null) {
				$jobs[$id] = new SuggestionJob($id, 1, [], false, false, $cycle);
			}
		}
		// The plan's budgets come from the test, never from the stored preferences: *Today* must follow the plan.
		$plannerMembers = array_values(array_map(function (HouseUser $member): SuggestionMember {
			$plan = $this->planned[(int)$member->getId()] ?? [];
			return new SuggestionMember(
				(int)$member->getId(),
				(string)$member->getNextcloudUserId(),
				array_fill_keys(TaskService::WEEKDAYS, 6),
				['saturday' => $plan['available'] ?? true],
				[],
				$plan['limit'] ?? null,
			);
		}, $this->members));
		return new SuggestionContext(1, new \DateTimeZone('UTC'), self::DATE, $this->members, $plannerMembers, [], [self::VIEWER_ID => 250], $this->roomTasks, $this->tasks, $this->assignees, $jobs);
	}

	/**
	 * Room tasks 1..$count of one room, each with its own definition.
	 *
	 * @param array<int, list<string>> $assignees
	 */
	private function wireHouse(int $count, array $assignees = []): void {
		for ($id = 1; $id <= $count; $id++) {
			$roomTask = new RoomTask();
			$roomTask->setId($id);
			$roomTask->setTaskId(10 + $id);
			$roomTask->setRoomId(1);
			$roomTask->setIsActive(true);
			$this->roomTasks[$id] = $roomTask;
			$task = new Task();
			$task->setId(10 + $id);
			$this->tasks[10 + $id] = $task;
		}
		$this->assignees = $assignees + array_fill_keys(array_keys($this->roomTasks), []);
		$room = new Room();
		$room->setId(1);
		$this->roomMapper->method('findByIds')->willReturn([1 => $room]);
	}

	/**
	 * Today's suggestion: room task id to the members it is suggested to.
	 *
	 * @param array<int, list<string>> $entries
	 * @param list<int> $overload Room tasks placed as overload
	 */
	private function suggest(array $entries, array $overload = []): void {
		foreach ($entries as $roomTaskId => $userIds) {
			$this->suggested[$roomTaskId] = ['cycleKey' => self::key(), 'userIds' => $userIds, 'overload' => in_array($roomTaskId, $overload, true)];
		}
	}

	/** A member of the house, as the suggestion read them. */
	private function viewer(string $userId, string $role, array $preferences = []): HouseUser {
		$viewer = new HouseUser();
		$viewer->setId(self::VIEWER_ID);
		$viewer->setNextcloudUserId($userId);
		$viewer->setRole($role);
		$viewer->setSchedulingPreferences($preferences);
		$this->members[self::VIEWER_ID] = $viewer;
		return $viewer;
	}

	/** @return list<array<string, mixed>> */
	private function rows(HouseUser $viewer): array {
		return $this->service->forViewer(new House(), $viewer, new \DateTime(self::DATE))['rows'];
	}

	/** @return list<int> */
	private static function ids(array $rows): array {
		return array_values(array_map(static fn (array $item): int => $item['roomTask']->getId(), $rows));
	}

	public function testDaily_Adult_SuggestedRowsFirstThenEveryOtherOpenRowAsAlsoPossible(): void {
		$this->wireHouse(5);
		$this->closed = [4 => 'late', 5 => 'none'];
		$this->suggest([2 => ['mom'], 3 => ['dad', 'mom']], [3]);

		$result = $this->rows($this->viewer('mom', 'owner'));

		// 4 is overdue and 5 has no cycle: neither is a daily row.
		$this->assertSame([2, 3, 1], self::ids($result));
		$this->assertSame(['mom'], $result[0]['suggestedFor']);
		$this->assertFalse($result[0]['overload']);
		$this->assertArrayNotHasKey('openWindow', $result[0], 'a suggested row is a plain due row');
		$this->assertSame(['dad', 'mom'], $result[1]['suggestedFor'], 'the viewer sees whom the day suggests it to');
		$this->assertTrue($result[1]['overload']);
		$this->assertTrue($result[2]['openWindow']);
		$this->assertArrayNotHasKey('suggestedFor', $result[2]);
	}

	public function testDaily_EntryOfAnotherCycle_IsNoSuggestionForTheRow(): void {
		$this->wireHouse(1);
		$this->suggested[1] = ['cycleKey' => 'c99', 'userIds' => ['mom'], 'overload' => false];

		$this->assertTrue($this->rows($this->viewer('mom', 'owner'))[0]['openWindow']);
	}

	/**
	 * BUG-UX-003 (CS-PLAN-088 D-088-19): every daily row carries its
	 * definition's checklist item count from ONE grouped query, 0 without
	 * items.
	 */
	public function testDaily_RowsCarryTheChecklistItemCount(): void {
		$this->wireHouse(2);
		$this->checklistItemMapper->expects($this->once())->method('countByTaskIds')->with([11, 12])->willReturn([11 => 3]);

		$this->assertSame([3, 0], array_column($this->rows($this->viewer('mom', 'owner')), 'checklistItemCount'));
	}

	/**
	 * RF-091-14: *Today* compares the viewer's work with the load the plan
	 * booked, not with a budget the client resolved on its own.
	 */
	public function testDaily_CarriesTheViewersLoadOfToday(): void {
		$this->wireHouse(1);
		$viewer = $this->viewer('mom', 'owner');

		$load = $this->service->forViewer(new House(), $viewer, new \DateTime(self::DATE))['load'];

		$this->assertSame(
			['userId' => 'mom', 'day' => self::DATE, 'budget' => 6, 'placed' => 0, 'doneMinor' => 250, 'preferred' => false, 'absent' => false],
			$load
		);
	}

	/** D-091-148: the availability is the plan's, not a second reading of the stored preferences. */
	public function testDaily_ChildOnADayThePlanHasItUnavailable_SeesNothing(): void {
		$this->wireHouse(2);
		$this->suggest([1 => ['teen']]);
		$viewer = $this->viewer('teen', 'child', ['weekdayAvailability' => ['saturday' => true]]);
		$this->planned[self::VIEWER_ID] = ['available' => false];

		$this->assertSame([], $this->rows($viewer));
	}

	public function testDaily_ChildThePlanHasAvailable_SeesItsRowsWhateverItsStoredWeekSays(): void {
		$this->wireHouse(2);
		$this->suggest([1 => ['teen']]);

		$this->assertSame([1, 2], self::ids($this->rows($this->viewer('teen', 'child', ['weekdayAvailability' => ['saturday' => false]]))));
	}

	/**
	 * CS-PLAN-065 (decision D4): a child is offered its own and the
	 * unassigned work only, and what today suggests to someone else is
	 * not offered to the child at all.
	 */
	public function testDaily_Child_SuggestedWorkThenOwnAndUnassignedOpenRows(): void {
		$this->wireHouse(6, [1 => ['teen'], 2 => ['sibling'], 4 => ['sibling'], 5 => ['teen']]);
		$this->suggest([1 => ['teen'], 2 => ['sibling'], 3 => ['mom']]);

		$result = $this->rows($this->viewer('teen', 'child'));

		// 2 and 4 are the sibling's; 3 is unassigned work that today suggests to mom;
		// 5 is the child's own open row and 6 an unassigned one.
		$this->assertSame([1, 5, 6], self::ids($result));
		$this->assertSame(['teen'], $result[0]['suggestedFor']);
		$this->assertTrue($result[1]['openWindow']);
		$this->assertArrayNotHasKey('suggestedFor', $result[1], 'a row that is only possible names no suggestion');
		$this->assertTrue($result[2]['openWindow']);
	}

	/**
	 * ADR-022 decision 2 rule 5 with BUG-UX-013: *Today* suggests a child
	 * at most the daily limit, work that fits the day first; what is above
	 * the limit stays visible as also possible.
	 *
	 * @dataProvider limits
	 * @param list<int> $suggested
	 * @param list<int> $possible
	 */
	public function testDaily_ChildLimit_CapsTheSuggestionAndHidesNothing(int $limit, array $suggested, array $possible): void {
		$this->wireHouse(4, [1 => ['teen'], 2 => ['teen'], 3 => ['teen']]);
		$this->suggest([1 => ['teen'], 2 => ['teen'], 3 => ['teen']], [1]);
		// The stored limit would cut nothing: the plan's limit is the one that holds (D-091-148).
		$viewer = $this->viewer('teen', 'child', ['maxDailyTasks' => 99]);
		$this->planned[self::VIEWER_ID] = ['limit' => $limit];

		$result = $this->rows($viewer);

		$this->assertSame($suggested, self::ids(array_filter($result, static fn (array $item): bool => isset($item['suggestedFor']))));
		$this->assertSame($possible, self::ids(array_filter($result, static fn (array $item): bool => $item['openWindow'] ?? false)));
	}

	/** @return array<string, array{int, list<int>, list<int>}> */
	public function limits(): array {
		return [
			'one slot: fitting work before overload' => [1, [2], [3, 1, 4]],
			'two slots' => [2, [2, 3], [1, 4]],
			'as many slots as suggestions' => [3, [1, 2, 3], [4]],
			// CS-PLAN-065 (decision D5): a stale PHP_INT_MAX from before D5 means unlimited.
			'a non-finite limit' => [PHP_INT_MAX, [1, 2, 3], [4]],
		];
	}

	/**
	 * The limit belongs to a child: *Today* never cuts an adult's list, not
	 * even with a limit in the plan's budgets (the plan gives adults none).
	 */
	public function testDaily_AdultWithALimit_IsNotLimited(): void {
		$this->wireHouse(2);
		$this->suggest([1 => ['mom'], 2 => ['mom']]);
		$viewer = $this->viewer('mom', 'member', ['maxDailyTasks' => 1]);
		$this->planned[self::VIEWER_ID] = ['limit' => 1, 'available' => false];

		$result = $this->rows($viewer);

		$this->assertCount(2, array_filter($result, static fn (array $item): bool => isset($item['suggestedFor'])));
	}
}
