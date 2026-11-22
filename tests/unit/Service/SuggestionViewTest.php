<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\Room;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Service\RecurrenceCalculator;
use OCA\ChoreStrike\Service\SuggestionContext;
use OCA\ChoreStrike\Service\SuggestionMember;
use OCA\ChoreStrike\Service\SuggestionPlacement;
use OCA\ChoreStrike\Service\SuggestionPlan;
use OCA\ChoreStrike\Service\SuggestionView;
use OCA\ChoreStrike\Service\TaskService;
use PHPUnit\Framework\TestCase;

/**
 * What the readers take from one suggestion read (ADR-022 decisions 6 and
 * 7, RF-091-14): the count of the daily summary with its free-day and
 * absence gate, a member's load, and the read contract of a range.
 *
 * @covers \OCA\ChoreStrike\Service\SuggestionView
 */
class SuggestionViewTest extends TestCase {
	private const TODAY = '2026-03-07'; // A Saturday
	private const MOM = 1;
	private const DAD = 2;

	/** @var array<int, array<string, mixed>> Preferences by member id */
	private array $preferences = [];
	/** @var array<int, array<string, true>> */
	private array $absentDays = [];
	/** @var array<int, int> */
	private array $doneMinor = [];
	private ?SuggestionPlan $plan = null;
	private ?SuggestionContext $context = null;

	protected function setUp(): void {
		$this->preferences = [self::MOM => [], self::DAD => []];
		$this->absentDays = $this->doneMinor = [];
		$this->plan = $this->context = null;
	}

	private static function day(int $offset): string {
		return RecurrenceCalculator::offsetDays(self::TODAY, $offset);
	}

	private static function member(int $id, string $userId, string $role): HouseUser {
		$member = new HouseUser();
		$member->setId($id);
		$member->setNextcloudUserId($userId);
		$member->setRole($role);
		return $member;
	}

	/** Two members with six energy a day; room tasks 1 to 3 in room 5. */
	private function context(): SuggestionContext {
		if ($this->context !== null) {
			return $this->context;
		}
		$members = [self::MOM => self::member(self::MOM, 'mom', 'owner'), self::DAD => self::member(self::DAD, 'dad', 'member')];
		$plannerMembers = [];
		foreach ($members as $id => $member) {
			$plannerMembers[] = new SuggestionMember(
				$id,
				(string)$member->getNextcloudUserId(),
				array_fill_keys(TaskService::WEEKDAYS, 6),
				$this->preferences[$id]['weekdayAvailability'] ?? [],
				$this->preferences[$id]['preferredWeekdays'] ?? [],
				null,
			);
		}
		$roomTasks = $tasks = [];
		foreach ([1, 2, 3] as $id) {
			$roomTasks[$id] = new RoomTask();
			$roomTasks[$id]->setId($id);
			$roomTasks[$id]->setTaskId(10 + $id);
			$roomTasks[$id]->setRoomId(5);
			$tasks[10 + $id] = new Task();
			$tasks[10 + $id]->setId(10 + $id);
			$tasks[10 + $id]->setName("Task $id");
		}
		return $this->context = new SuggestionContext(1, new \DateTimeZone('UTC'), self::TODAY, $members, $plannerMembers, $this->absentDays, $this->doneMinor, $roomTasks, $tasks, [], []);
	}

	private function plan(): SuggestionPlan {
		return $this->plan ??= new SuggestionPlan($this->context()->ledger());
	}

	/**
	 * @param list<int> $memberIds The carrier first
	 */
	private function placed(int $roomTaskId, int $dayOffset, array $memberIds, int $energy = 2, bool $current = true, bool $overload = false): void {
		$day = self::day($dayOffset);
		$this->plan()->ledger->book($memberIds[0], $day, $energy);
		$this->plan()->place(new SuggestionPlacement($roomTaskId, $current ? "d$day" : null, $day, $memberIds, $energy, $overload, $current, $day, $day, 0));
	}

	/**
	 * @param list<int> $memberIds
	 */
	private function overdue(int $roomTaskId, array $memberIds): void {
		$this->plan()->countOverdue(new SuggestionPlacement($roomTaskId, 'd' . self::day(-3), null, $memberIds, 2, false, true, self::day(-3), self::day(-3), 0));
	}

	private function view(): SuggestionView {
		return new SuggestionView($this->context(), $this->plan());
	}

	private function viewer(int $memberId): HouseUser {
		return $this->context()->members[$memberId];
	}

	// ------------------------------------------------------------------
	// The count of the daily summary (decision 7)
	// ------------------------------------------------------------------

	public function testCountFor_TodaysCurrentWorkOfTheMemberPlusTheirOverdueTasks(): void {
		$this->placed(1, 0, [self::MOM]);
		$this->placed(2, 0, [self::DAD, self::MOM]);
		$this->placed(3, 1, [self::MOM]);
		// A cycle the rule yields after today's is no work of today.
		$this->placed(1, 0, [self::MOM], 2, false);
		$this->overdue(3, [self::MOM]);

		self::assertSame(3, $this->view()->countFor($this->viewer(self::MOM)), 'own, a team task someone else carries, and overdue');
		self::assertSame(1, $this->view()->countFor($this->viewer(self::DAD)));
	}

	public function testCountFor_OnADayTheMemberIsNotAvailable_IsZero(): void {
		$this->preferences[self::MOM] = ['weekdayAvailability' => ['saturday' => false]];
		$this->placed(1, 0, [self::DAD, self::MOM]);
		$this->overdue(2, [self::MOM]);

		self::assertSame(0, $this->view()->countFor($this->viewer(self::MOM)), 'a free day has no task content');
		self::assertSame(1, $this->view()->countFor($this->viewer(self::DAD)));
	}

	public function testCountFor_WhileTheMemberIsAbsent_IsZero(): void {
		$this->absentDays = [self::MOM => [self::TODAY => true]];
		// An overdue task of an absent assignee keeps naming them.
		$this->overdue(1, [self::MOM]);

		self::assertSame(0, $this->view()->countFor($this->viewer(self::MOM)));
	}

	public function testCountFor_SomeoneThePlanDoesNotKnow_IsZero(): void {
		$this->overdue(1, [9]);

		self::assertSame(0, $this->view()->countFor(self::member(9, 'guest', 'member')));
	}

	// ------------------------------------------------------------------
	// A member's load
	// ------------------------------------------------------------------

	public function testLoadOf_PlacedEnergyAgainstTheDaysEnergy(): void {
		$this->preferences[self::MOM] = ['preferredWeekdays' => ['sunday']];
		$this->absentDays = [self::MOM => [self::day(2) => true]];
		$this->doneMinor = [self::MOM => 150];
		$this->placed(1, 0, [self::MOM], 4);
		// A team task takes the energy of the member who carries it only.
		$this->placed(2, 0, [self::DAD, self::MOM], 3);
		$view = $this->view();

		self::assertSame(
			['userId' => 'mom', 'day' => self::TODAY, 'budget' => 6, 'placed' => 4, 'doneMinor' => 150, 'preferred' => false, 'absent' => false],
			$view->loadOf(self::MOM, self::TODAY)
		);
		self::assertSame(
			['userId' => 'mom', 'day' => self::day(1), 'budget' => 6, 'placed' => 0, 'doneMinor' => 0, 'preferred' => true, 'absent' => false],
			$view->loadOf(self::MOM, self::day(1)),
			'done work counts on today only'
		);
		self::assertTrue($view->loadOf(self::MOM, self::day(2))['absent']);
		self::assertNull($view->loadOf(9, self::TODAY));
	}

	// ------------------------------------------------------------------
	// The read contract of a range (decision 6)
	// ------------------------------------------------------------------

	public function testRange_ShowsTheVisibleEntriesByDayInPlacementOrder(): void {
		$this->placed(2, 0, [self::DAD, self::MOM], 3, true, true);
		$this->placed(1, 0, [self::MOM]);
		$this->placed(3, 0, [self::MOM]);
		$this->placed(1, 1, [self::MOM], 2, false);
		$this->overdue(3, [self::MOM]);
		$this->overdue(2, []);
		$context = $this->context();
		$room = new Room();
		$room->setId(5);
		$room->setName('Kitchen');
		// Room task 3 is one the viewer may not see.
		$visible = [1 => $context->roomTasks[1], 2 => $context->roomTasks[2]];

		$range = $this->view()->range($this->viewer(self::DAD), $visible, [5 => $room], self::TODAY, self::day(1));

		self::assertSame([self::TODAY, self::day(1)], array_column($range['days'], 'day'));
		self::assertSame([2, 1], array_column($range['days'][0]['entries'], 'roomTaskId'));
		self::assertSame(
			['roomTaskId' => 2, 'cycleKey' => 'd' . self::TODAY, 'userIds' => ['dad', 'mom'], 'energy' => 3, 'overload' => true,
				'windowStartOn' => self::TODAY, 'windowEndOn' => self::TODAY, 'taskName' => 'Task 2', 'roomId' => 5, 'roomName' => 'Kitchen'],
			$range['days'][0]['entries'][0]
		);
		self::assertSame([null], array_column($range['days'][1]['entries'], 'cycleKey'), 'a projected cycle is no completion target');
		self::assertSame([[2, []]], array_map(static fn (array $entry): array => [$entry['roomTaskId'], $entry['userIds']], $range['overdue']));
	}

	public function testRange_ThatStartsAfterToday_HasNoOverdue(): void {
		$this->overdue(1, [self::MOM]);
		$visible = $this->context()->roomTasks;

		self::assertSame([], $this->view()->range($this->viewer(self::MOM), $visible, [], self::day(1), self::day(2))['overdue']);
		self::assertCount(1, $this->view()->range($this->viewer(self::MOM), $visible, [], self::TODAY, self::day(2))['overdue']);
	}

	public function testRange_AMemberReadsTheOwnLoad_TheOwnerEveryMembers(): void {
		$this->placed(1, 0, [self::DAD], 5);

		$forDad = $this->view()->range($this->viewer(self::DAD), [], [], self::TODAY, self::day(1))['members'];
		$forOwner = $this->view()->range($this->viewer(self::MOM), [], [], self::TODAY, self::day(1))['members'];

		self::assertSame([['dad', self::TODAY, 5], ['dad', self::day(1), 0]], array_map(static fn (array $row): array => [$row['userId'], $row['day'], $row['placed']], $forDad));
		self::assertSame(['mom', 'mom', 'dad', 'dad'], array_column($forOwner, 'userId'));
	}
}
