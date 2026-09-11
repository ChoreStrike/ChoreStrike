<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Db;

use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Service\CalendarService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * Real-DB regression test for RoomTaskMapper::findDailyTasks (BUG-API-DAILY-001).
 *
 * The old implementation passed `DATE(rt.next_due)` as the column argument of
 * eq(), which the query builder escaped as a quoted column name →
 * `SQLSTATE[42S22] Unknown column 'DATE(rt.next_due)'` (silent 500 of
 * GET /api/v1/tasks/daily since CS-PLAN-023). A mock-based test cannot catch
 * SQL shape errors, so this spec runs against the real Docker DB and pins the
 * [start, end) range semantics including the cross-day boundaries.
 *
 * CS-PLAN-057: the day window is passed explicitly as `[start, end)` bounds;
 * these pins use CalendarService day bounds for a UTC house, which equal the
 * UTC calendar day used by the fixtures.
 */
class RoomTaskMapperDailyTest extends IntegrationTestCase {
	private RoomTaskMapper $mapper;
	private CalendarService $calendar;

	protected function setUp(): void {
		parent::setUp();
		$this->mapper = \OC::$server->get(RoomTaskMapper::class);
		$this->calendar = new CalendarService();
	}

	private function utcDayBounds(\DateTimeInterface $day): array {
		return $this->calendar->dayBounds($day, new \DateTimeZone('UTC'));
	}

	/**
	 * Set a room task's next_due explicitly (the fixture helper always
	 * inserts "now").
	 */
	private function setNextDue(int $roomTaskId, \DateTimeImmutable $nextDue): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('chorestrike_room_tasks')
			->set('next_due', $qb->createNamedParameter($nextDue, IQueryBuilder::PARAM_DATE))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	/**
	 * Only active tasks due within the requested calendar day are returned:
	 * yesterday, tomorrow, inactive-today, and other-house rows are excluded;
	 * the 00:00:00 and 23:59:59 boundary rows are included.
	 *
	 * Each row gets its own task definition: chorestrike_room_tasks has a
	 * unique (room_id, task_id) constraint, so one task cannot be assigned
	 * to the same room twice.
	 */
	public function testFindDailyTasks_ReturnsOnlySameDayActiveRows(): void {
		$houseId = $this->createHouse('daily_owner', 'Daily House');
		$floorId = $this->createFloor($houseId, 'Ground Floor', 1);
		$roomId = $this->createRoom($houseId, $floorId, 'Kitchen');

		$today = new \DateTimeImmutable('today');
		$todayNoon = $today->modify('12:00:00');
		$todayStart = $today->modify('00:00:00');
		$todayEnd = $today->modify('23:59:59');
		$tomorrow = $today->modify('+1 day');
		$yesterday = $today->modify('-1 day');

		$dueTodayNoon = $this->assignTaskToRoom($this->createTask($houseId, 'Dishes'), $roomId, 'daily_owner');
		$this->setNextDue($dueTodayNoon, $todayNoon);

		$dueTodayStart = $this->assignTaskToRoom($this->createTask($houseId, 'Laundry'), $roomId, 'daily_owner');
		$this->setNextDue($dueTodayStart, $todayStart);

		$dueTodayEnd = $this->assignTaskToRoom($this->createTask($houseId, 'Trash'), $roomId, 'daily_owner');
		$this->setNextDue($dueTodayEnd, $todayEnd);

		$dueTomorrow = $this->assignTaskToRoom($this->createTask($houseId, 'Vacuum'), $roomId, 'daily_owner');
		$this->setNextDue($dueTomorrow, $tomorrow);

		$dueYesterday = $this->assignTaskToRoom($this->createTask($houseId, 'Mop'), $roomId, 'daily_owner');
		$this->setNextDue($dueYesterday, $yesterday);

		// Active today, but belongs to another house → excluded by the
		// house-scope JOIN chain.
		$otherHouseId = $this->createHouse('daily_other', 'Other House');
		$otherFloorId = $this->createFloor($otherHouseId, 'Other Floor', 1);
		$otherRoomId = $this->createRoom($otherHouseId, $otherFloorId, 'Bathroom');
		$otherHouseDue = $this->assignTaskToRoom($this->createTask($otherHouseId, 'Other Task'), $otherRoomId, 'daily_other');
		$this->setNextDue($otherHouseDue, $todayNoon);

		// Inactive but due today → excluded.
		$inactiveToday = $this->assignTaskToRoom($this->createTask($houseId, 'Windows'), $roomId, 'daily_owner');
		$this->setNextDue($inactiveToday, $todayNoon);
		$qb = $this->db->getQueryBuilder();
		$qb->update('chorestrike_room_tasks')
			->set('is_active', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($inactiveToday, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();

		[$dayStart, $dayEnd] = $this->utcDayBounds(new \DateTime());
		$result = $this->mapper->findDailyTasks($houseId, $dayStart, $dayEnd);

		$ids = array_map(fn ($roomTask) => $roomTask->getId(), $result);
		sort($ids);
		$expected = [$dueTodayNoon, $dueTodayStart, $dueTodayEnd];
		sort($expected);

		self::assertSame(
			$expected,
			$ids,
			'findDailyTasks must return exactly the active rows due within [00:00:00, +1d) of the requested day, scoped to the house'
		);
	}

	/**
	 * Requesting a non-today date returns that calendar day's rows, not the
	 * current day's (the day comes from the caller, not from NOW()).
	 */
	public function testFindDailyTasks_RequestedDate_ReturnsThatDayOnly(): void {
		$houseId = $this->createHouse('daily_owner2', 'Daily House 2');
		$floorId = $this->createFloor($houseId, 'Ground Floor', 1);
		$roomId = $this->createRoom($houseId, $floorId, 'Kitchen');
		$taskId = $this->createTask($houseId, 'Trash', 'Take out the trash', 5, 3);

		$yesterday = new \DateTimeImmutable('yesterday');
		$dueYesterday = $this->assignTaskToRoom($taskId, $roomId, 'daily_owner2');
		$this->setNextDue($dueYesterday, $yesterday->modify('09:30:00'));

		[$yesterdayStart, $yesterdayEnd] = $this->utcDayBounds(new \DateTime('yesterday'));
		$result = $this->mapper->findDailyTasks($houseId, $yesterdayStart, $yesterdayEnd);

		self::assertCount(1, $result);
		self::assertSame($dueYesterday, $result[0]->getId());
	}

	/**
	 * findDueOnDate (job-only, NO house scope — D4 of CS-PLAN-030) returns
	 * the active rows of the requested calendar day across ALL houses and
	 * excludes inactive rows. A distinct future day avoids interference with
	 * other specs' "now"-dated fixtures.
	 */
	public function testFindDueOnDate_ReturnsActiveRowsAcrossHouses(): void {
		$day = (new \DateTimeImmutable('today'))->modify('+40 days');
		$dayNoon = $day->modify('12:00:00');

		$houseA = $this->createHouse('due_owner_a', 'House A');
		$floorA = $this->createFloor($houseA, 'Ground Floor', 1);
		$roomA = $this->createRoom($houseA, $floorA, 'Kitchen');

		$houseB = $this->createHouse('due_owner_b', 'House B');
		$floorB = $this->createFloor($houseB, 'Ground Floor', 1);
		$roomB = $this->createRoom($houseB, $floorB, 'Bathroom');

		$dueA = $this->assignTaskToRoom($this->createTask($houseA, 'Dishes'), $roomA, 'due_owner_a');
		$this->setNextDue($dueA, $dayNoon);

		// Same day, different house → still returned (no house scope).
		$dueB = $this->assignTaskToRoom($this->createTask($houseB, 'Toilet'), $roomB, 'due_owner_b');
		$this->setNextDue($dueB, $day->modify('23:59:59'));

		// Different day → excluded.
		$otherDay = $this->assignTaskToRoom($this->createTask($houseA, 'Vacuum'), $roomA, 'due_owner_a');
		$this->setNextDue($otherDay, $day->modify('+1 day'));

		// Inactive on the requested day → excluded.
		$inactive = $this->assignTaskToRoom($this->createTask($houseA, 'Windows'), $roomA, 'due_owner_a');
		$this->setNextDue($inactive, $dayNoon);
		$qb = $this->db->getQueryBuilder();
		$qb->update('chorestrike_room_tasks')
			->set('is_active', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($inactive, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();

		[$dayStart, $dayEnd] = $this->utcDayBounds($day);
		$result = $this->mapper->findDueOnDate($dayStart, $dayEnd);

		$ids = array_map(fn ($roomTask) => $roomTask->getId(), $result);
		sort($ids);
		$expected = [$dueA, $dueB];
		sort($expected);

		self::assertSame(
			$expected,
			$ids,
			'findDueOnDate must return the active rows of the requested day across all houses'
		);
	}

	/**
	 * CS-PLAN-065 §3.3: the daily read order is the deterministic
	 * `nextDue, createdAt, id` — two rows created in the same second with
	 * the same due day keep ascending-id order across every engine, so the
	 * child-limit slicing cannot flip depending on the database.
	 */
	public function testFindDailyTasks_SameDayAndCreatedAt_OrdersAscendingByRoomTaskId(): void {
		$houseId = $this->createHouse('tie_owner', 'Tie House');
		$floorId = $this->createFloor($houseId, 'Ground Floor', 1);
		$roomId = $this->createRoom($houseId, $floorId, 'Kitchen');

		$noon = (new \DateTimeImmutable('today'))->modify('12:00:00');
		$first = $this->assignTaskToRoom($this->createTask($houseId, 'First'), $roomId, 'tie_owner');
		$second = $this->assignTaskToRoom($this->createTask($houseId, 'Second'), $roomId, 'tie_owner');
		$this->setNextDue($first, $noon);
		$this->setNextDue($second, $noon);

		[$dayStart, $dayEnd] = $this->utcDayBounds(new \DateTime());
		$result = $this->mapper->findDailyTasks($houseId, $dayStart, $dayEnd);

		$ids = array_map(static fn ($roomTask) => $roomTask->getId(), $result);
		$expected = [$first, $second];
		sort($expected);

		self::assertSame(
			$expected,
			$ids,
			'findDailyTasks must break nextDue/createdAt ties by ascending room-task id'
		);
	}
}
