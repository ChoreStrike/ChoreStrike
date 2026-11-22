<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\HouseMapper;
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
use OCA\ChoreStrike\Service\SuggestionPlan;
use OCA\ChoreStrike\Service\SuggestionService;
use OCA\ChoreStrike\Service\SuggestionView;
use OCA\ChoreStrike\Service\TaskAccessPolicy;
use OCA\ChoreStrike\Service\WindowService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The daily read of RoomTaskReadService (CS-PLAN-067 §3.1; CS-PLAN-091
 * WP-C): the live suggestion decides which open rows are today's work
 * (ADR-022), every other open row is also possible, and a child is offered
 * its own and the unassigned work up to the daily limit. The rows are built
 * from the inputs the suggestion read, so the read issues no second query
 * for room tasks, definitions, assignees or completions.
 */
class RoomTaskServiceDailyTest extends TestCase {
	private const DATE = '2026-03-07'; // A Saturday

	private RoomTaskReadService $service;
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

	protected function setUp(): void {
		$this->roomTasks = $this->tasks = $this->assignees = $this->closed = $this->suggested = [];
		$this->roomMapper = $this->createMock(RoomMapper::class);
		$this->checklistItemMapper = $this->createMock(\OCA\ChoreStrike\Db\TaskChecklistItemMapper::class);
		// CS-PLAN-057: day windows resolve the house timezone (UTC here).
		$utcHouse = new House();
		$utcHouse->setTimezone('UTC');
		$houseMapper = $this->createMock(HouseMapper::class);
		$houseMapper->method('find')->willReturn($utcHouse);

		// "Everything visible": the matrix is covered in TaskAccessPolicyTest.
		$taskAccessPolicy = $this->createMock(TaskAccessPolicy::class);
		$taskAccessPolicy->method('canView')->willReturn(true);

		// The suggestion is stubbed: its rules are pinned in SuggestionPlannerTest.
		// Its inputs are the room tasks of the house with their current cycle.
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

		// No mapper but the rooms' and the sub-task counts' may be asked: the rest is the suggestion's.
		$roomTaskMapper = $this->createMock(\OCA\ChoreStrike\Db\RoomTaskMapper::class);
		$roomTaskMapper->expects($this->never())->method($this->anything());
		$taskMapper = $this->createMock(\OCA\ChoreStrike\Db\TaskMapper::class);
		$taskMapper->expects($this->never())->method($this->anything());
		$assigneeMapper = $this->createMock(\OCA\ChoreStrike\Db\TaskAssigneeMapper::class);
		$assigneeMapper->expects($this->never())->method($this->anything());
		$completions = $this->createMock(\OCA\ChoreStrike\Db\CompletedTaskMapper::class);
		$completions->expects($this->never())->method($this->anything());

		$this->service = new RoomTaskReadService(
			$roomTaskMapper,
			$taskMapper,
			$this->roomMapper,
			$this->createMock(\OCA\ChoreStrike\Db\FloorMapper::class),
			$houseMapper,
			$assigneeMapper,
			$taskAccessPolicy,
			new CalendarService(),
			new WindowService(new RecurrenceCalculator(), $completions),
			$this->createMock(\OCA\ChoreStrike\Service\AppLogger::class),
			$this->checklistItemMapper,
			$this->createMock(\OCA\ChoreStrike\Service\CreditPolicy::class),
			$suggestions,
		);
	}

	private static function key(): string {
		return 'd' . self::DATE;
	}

	/**
	 * What the suggestion read: every room task has an open window around
	 * today unless the test closes it.
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
		return new SuggestionContext(1, new \DateTimeZone('UTC'), self::DATE, [], [], [], [], $this->roomTasks, $this->tasks, $this->assignees, $jobs);
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

	private function viewer(string $userId, string $role, array $preferences = []): HouseUser {
		$viewer = new HouseUser();
		$viewer->setId(1);
		$viewer->setNextcloudUserId($userId);
		$viewer->setRole($role);
		$viewer->setSchedulingPreferences($preferences);
		return $viewer;
	}

	/** @return list<int> */
	private function daily(HouseUser $viewer): array {
		return array_map(
			static fn (array $item): int => $item['roomTask']->getId(),
			$this->service->getFilteredDailyTasksForUser(1, $viewer, new \DateTime(self::DATE))
		);
	}

	public function testDaily_Adult_SuggestedRowsFirstThenEveryOtherOpenRowAsAlsoPossible(): void {
		$this->wireHouse(5);
		$this->closed = [4 => 'late', 5 => 'none'];
		$this->suggest([2 => ['mom'], 3 => ['dad', 'mom']], [3]);

		$result = $this->service->getFilteredDailyTasksForUser(1, $this->viewer('mom', 'owner'), new \DateTime(self::DATE));

		// 4 is overdue and 5 has no cycle: neither is a daily row.
		$this->assertSame([2, 3, 1], array_map(static fn (array $item): int => $item['roomTask']->getId(), $result));
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

		$result = $this->service->getFilteredDailyTasksForUser(1, $this->viewer('mom', 'owner'), new \DateTime(self::DATE));

		$this->assertTrue($result[0]['openWindow']);
	}

	/**
	 * BUG-UX-003 (CS-PLAN-088 D-088-19): every daily row carries its
	 * definition's checklist item count from ONE grouped query, 0 without
	 * items.
	 */
	public function testDaily_RowsCarryTheChecklistItemCount(): void {
		$this->wireHouse(2);
		$this->checklistItemMapper->expects($this->once())->method('countByTaskIds')->with([11, 12])->willReturn([11 => 3]);

		$result = $this->service->getFilteredDailyTasksForUser(1, $this->viewer('mom', 'owner'), new \DateTime(self::DATE));

		$this->assertSame([3, 0], array_column($result, 'checklistItemCount'));
	}

	public function testDaily_ChildOnADayItIsNotAvailable_SeesNothing(): void {
		$this->wireHouse(2);
		$this->suggest([1 => ['teen']]);

		$this->assertSame([], $this->daily($this->viewer('teen', 'child', ['weekdayAvailability' => ['saturday' => false]])));
	}

	/**
	 * CS-PLAN-065 (decision D4): a child is offered its own and the
	 * unassigned work only, and what today suggests to someone else is
	 * not offered to the child at all.
	 */
	public function testDaily_Child_SuggestedWorkThenOwnAndUnassignedOpenRows(): void {
		$this->wireHouse(6, [1 => ['teen'], 2 => ['sibling'], 4 => ['sibling'], 5 => ['teen']]);
		$this->suggest([1 => ['teen'], 2 => ['sibling'], 3 => ['mom']]);

		$result = $this->service->getFilteredDailyTasksForUser(1, $this->viewer('teen', 'child'), new \DateTime(self::DATE));

		// 2 and 4 are the sibling's; 3 is unassigned work that today suggests to mom;
		// 5 is the child's own open row and 6 an unassigned one.
		$this->assertSame([1, 5, 6], array_map(static fn (array $item): int => $item['roomTask']->getId(), $result));
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

		$result = $this->service->getFilteredDailyTasksForUser(1, $this->viewer('teen', 'child', ['maxDailyTasks' => $limit]), new \DateTime(self::DATE));

		$ids = static fn (array $rows): array => array_values(array_map(static fn (array $item): int => $item['roomTask']->getId(), $rows));
		$this->assertSame($suggested, $ids(array_filter($result, static fn (array $item): bool => isset($item['suggestedFor']))));
		$this->assertSame($possible, $ids(array_filter($result, static fn (array $item): bool => $item['openWindow'] ?? false)));
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
}
