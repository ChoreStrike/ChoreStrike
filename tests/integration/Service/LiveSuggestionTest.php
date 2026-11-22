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
use OCA\ChoreStrike\Db\Pause;
use OCA\ChoreStrike\Db\RoomMapper;
use OCA\ChoreStrike\Db\SuggestionDay;
use OCA\ChoreStrike\Db\SuggestionDayMapper;
use OCA\ChoreStrike\Db\TransactionExecutor;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\CalendarService;
use OCA\ChoreStrike\Service\CompletionService;
use OCA\ChoreStrike\Service\PauseService;
use OCA\ChoreStrike\Service\PostponeService;
use OCA\ChoreStrike\Service\RoomTaskReadService;
use OCA\ChoreStrike\Service\RoomTaskService;
use OCA\ChoreStrike\Service\SuggestionInputs;
use OCA\ChoreStrike\Service\SuggestionPlanner;
use OCA\ChoreStrike\Service\SuggestionService;
use OCA\ChoreStrike\Service\SuggestionView;
use OCA\ChoreStrike\Service\TaskAccessPolicy;
use OCA\ChoreStrike\Service\TaskCreationService;
use OCA\ChoreStrike\Service\TaskService;
use OCA\ChoreStrike\Service\TodayRows;
use OCA\ChoreStrike\Service\WindowService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;
use OCA\ChoreStrike\Tests\Integration\Support\TaskEditing;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * The live suggestion on a real database (CS-PLAN-091 WP-C, ADR-022): it
 * is computed on read from the windows, the members' preferences, the
 * absences and today's work; today's list is stored once per house and
 * day and then only left and joined; the read contract filters by task
 * visibility. The placement rules themselves are pinned in
 * {@see \OCA\ChoreStrike\Tests\Unit\Service\SuggestionPlannerTest}.
 */
class LiveSuggestionTest extends IntegrationTestCase {
	use TaskEditing;

	private const OWNER = 'lsg_owner';
	private const ADULT = 'lsg_adult';
	private const KID = 'lsg_kid';

	private SuggestionService $suggestions;
	private TaskCreationService $creation;
	private HouseUserMapper $houseUserMapper;
	private int $houseId;
	private int $roomId;
	private House $house;
	private string $today;
	/** One instant for every read of a test, so the house-local day cannot change between them. */
	private \DateTime $now;

	protected function setUp(): void {
		parent::setUp();
		$this->suggestions = \OC::$server->get(SuggestionService::class);
		$this->creation = \OC::$server->get(TaskCreationService::class);
		$this->houseUserMapper = \OC::$server->get(HouseUserMapper::class);
		$this->now = new \DateTime('now', new \DateTimeZone('UTC'));
		$this->today = $this->now->format('Y-m-d');

		$this->houseId = $this->createHouse(self::OWNER, 'Suggestion House');
		$this->addHouseMember($this->houseId, self::ADULT, 'Adult', 'member');
		$this->addHouseMember($this->houseId, self::KID, 'Kid', 'child');
		$this->roomId = $this->createRoom($this->houseId, $this->createFloor($this->houseId, 'Ground', 1), 'Kitchen');
		$this->house = \OC::$server->get(HouseMapper::class)->find($this->houseId);
		// The same energy on every day, so the expected days do not depend on the weekday the test runs on.
		foreach ([self::OWNER, self::ADULT, self::KID] as $userId) {
			$this->setPreferences($userId, []);
		}
	}

	// ------------------------------------------------------------------
	// Fixtures
	// ------------------------------------------------------------------

	private function day(int $offset): string {
		return (new \DateTime($this->today))->modify(sprintf('%+d days', $offset))->format('Y-m-d');
	}

	private function member(string $userId): HouseUser {
		return $this->houseUserMapper->findByNextcloudUserId($this->houseId, $userId);
	}

	/**
	 * A weekly task whose window is its first date plus and minus the
	 * flex days. It counts as created yesterday; a task the test marks as
	 * fresh counts as created just after the stored first read of
	 * {@see firstRead()}, so it is "created since the day was stored".
	 *
	 * @param list<string> $assignees
	 * @param array<string, mixed> $definition
	 */
	private function task(string $name, array $assignees, int $firstOffset, int $flex = 0, array $definition = [], bool $fresh = false, string $creator = self::OWNER): int {
		$result = $this->creation->create($this->houseId, $this->member($creator), [
			'roomId' => $this->roomId,
			'task' => $definition + ['name' => $name, 'frequencyType' => 'weekly', 'frequencyValue' => 1, 'energyCost' => 2, 'flexDays' => $flex],
			'assigneeUserIds' => $assignees,
			'firstDate' => $this->day($firstOffset),
		]);
		$this->registerCreatedTask((int)$result['task']->getId());
		$roomTaskId = (int)$result['roomTask']->getId();
		$this->setColumn('chorestrike_room_tasks', 'id', $roomTaskId, 'created_at', $fresh ? $this->today . ' 00:00:02' : $this->day(-1) . ' 12:00:00');
		return $roomTaskId;
	}

	private function setColumn(string $table, string $keyColumn, int $key, string $column, string $value): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($table)
			->set($column, $qb->createNamedParameter($value))
			->where($qb->expr()->eq($keyColumn, $qb->createNamedParameter($key, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	/** @param array<string, mixed> $preferences Merged over an even budget of six on every available day */
	private function setPreferences(string $userId, array $preferences): void {
		$week = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
		$qb = $this->db->getQueryBuilder();
		$qb->update('chorestrike_house_users')
			->set('scheduling_preferences', $qb->createNamedParameter(json_encode($preferences + [
				'energyBudget' => array_fill_keys($week, 6),
				'weekdayAvailability' => array_fill_keys($week, true),
			])))
			->where($qb->expr()->eq('house_id', $qb->createNamedParameter($this->houseId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('nextcloud_user_id', $qb->createNamedParameter($userId)))
			->executeStatement();
	}

	private function weekdayOf(int $offset): string {
		return strtolower((new \DateTime($this->day($offset)))->format('l'));
	}

	private function view(): SuggestionView {
		return $this->suggestions->view($this->house, clone $this->now);
	}

	/** @return list<int> Who carries the current cycle of a room task, and who sees it with them */
	private static function carriersOf(SuggestionView $view, int $roomTaskId): array {
		foreach ($view->plan->placements() as $placement) {
			if ($placement->current && $placement->roomTaskId === $roomTaskId) {
				return $placement->memberIds;
			}
		}
		return [];
	}

	/** @return array<string, mixed> The read contract as the member sees it, for days given as offsets from today */
	private function range(string $userId, int $from, int $to): array {
		return $this->suggestions->range($this->house, $this->member($userId), $this->day($from), $this->day($to), clone $this->now);
	}

	/**
	 * The day's first read, stored as if it had happened at midnight: a
	 * task the test creates afterwards is created since.
	 */
	private function firstRead(): SuggestionView {
		$view = $this->view();
		$this->setColumn('chorestrike_suggestion_days', 'house_id', $this->houseId, 'created_at', $this->today . ' 00:00:01');
		return $view;
	}

	/** @return array<int, string> Room task id to the day its current cycle is placed on */
	private function daysOf(?SuggestionView $view = null): array {
		$days = [];
		foreach (($view ?? $this->view())->plan->placements() as $placement) {
			if ($placement->current) {
				$days[$placement->roomTaskId] = $placement->day;
			}
		}
		ksort($days);
		return $days;
	}

	/** @return list<int> The room tasks on today's list */
	private function todayIds(): array {
		$ids = array_keys($this->view()->today());
		sort($ids);
		return $ids;
	}

	/** @return list<array<string, mixed>> */
	private function storedDays(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('chorestrike_suggestion_days')
			->where($qb->expr()->eq('house_id', $qb->createNamedParameter($this->houseId, IQueryBuilder::PARAM_INT)))
			->orderBy('day_key', 'ASC');
		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();
		return $rows;
	}

	private function complete(int $roomTaskId, string $userId = self::OWNER): void {
		$this->completeOccurrence(\OC::$server->get(CompletionService::class), $this->houseId, $userId, $roomTaskId);
	}

	// ------------------------------------------------------------------
	// Placement from real inputs
	// ------------------------------------------------------------------

	public function testView_PlacesWorkInsideItsWindowOnTheRoomiestDay(): void {
		$first = $this->task('First', [self::OWNER], 0, 1, ['energyCost' => 4]);
		$second = $this->task('Second', [self::OWNER], 0, 1, ['energyCost' => 4]);
		$later = $this->task('Later', [self::OWNER], 5);

		// Six a day: the second no longer fits today and takes tomorrow, the last day of its window.
		self::assertSame([$first => $this->day(0), $second => $this->day(1), $later => $this->day(5)], $this->daysOf());
	}

	public function testView_PreferredDay_IsFilledFirst(): void {
		$this->setPreferences(self::OWNER, ['preferredWeekdays' => [$this->weekdayOf(2)]]);
		$roomTaskId = $this->task('Windows', [self::OWNER], 1, 2);

		self::assertSame([$roomTaskId => $this->day(2)], $this->daysOf());
	}

	public function testView_UnavailableWeekdayAndAbsence_GetNothingSuggested(): void {
		$week = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
		$this->setPreferences(self::OWNER, ['weekdayAvailability' => [$this->weekdayOf(0) => false] + array_fill_keys($week, true)]);
		\OC::$server->get(PauseService::class)->requestForSelf($this->houseId, self::ADULT, [
			'commandId' => $this->newCommandId(), 'startDate' => $this->day(0), 'endDate' => $this->day(1), 'reasonCode' => Pause::REASON_TRAVEL,
		]);
		$own = $this->task('Own', [self::OWNER], 0, 1);
		$away = $this->task('Away', [self::ADULT], 0, 1);
		$back = $this->task('Back', [self::ADULT], 1, 1);

		// The owner is free today; the adult is away for the whole first window and back for the last day of the second.
		self::assertSame([$own => $this->day(1), $back => $this->day(2)], $this->daysOf());
		self::assertArrayNotHasKey($away, $this->daysOf());
	}

	public function testView_UnassignedAndTeamWork_FollowTheBudgets(): void {
		$this->setPreferences(self::ADULT, ['energyBudget' => array_fill_keys(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'], 9)]);
		$unassigned = $this->task('Unassigned', [], 0);
		$team = $this->task('Team', [self::OWNER, self::KID], 0);

		$view = $this->view();
		$today = $view->today();

		self::assertSame([self::ADULT], $today[$unassigned]['userIds'], 'the member with the most remaining energy, and nobody else');
		self::assertSame([self::OWNER, self::KID], $today[$team]['userIds'], 'every assignee sees a team task; the first carries it');
		self::assertSame(2, $view->plan->ledger->placed((int)$this->member(self::OWNER)->getId(), $this->today));
		self::assertSame(0, $view->plan->ledger->placed((int)$this->member(self::KID)->getId(), $this->today), 'the team task is on one budget');
	}

	public function testView_PrivateTask_IsNeverSuggestedToSomeoneWhoMayNotSeeIt(): void {
		$private = $this->task('Private', [], 0, 0, ['visibilityType' => 'private'], false, self::ADULT);

		self::assertSame([self::ADULT], $this->view()->today()[$private]['userIds'], 'an unassigned private task is its creator\'s');
	}

	public function testView_AdminOnlyTeamTask_IsNeverCarriedOrShownToAnAssigneeWhoMayNotSeeIt(): void {
		$roomTaskId = $this->task('Admin', [self::OWNER, self::ADULT], 0, 0, ['visibilityType' => 'admin-only']);

		$view = $this->view();

		self::assertSame([self::OWNER], $view->today()[$roomTaskId]['userIds'], 'the owner alone; no team with an assignee who may not see it');
		self::assertSame(0, $view->countFor($this->member(self::ADULT)));
		$forAdult = $this->range(self::ADULT, 0, 0);
		self::assertSame([], $forAdult['days'][0]['entries']);
		self::assertSame(0, $forAdult['members'][0]['placed']);
	}

	public function testView_OverdueAndDoneWork_TakeTodaysEnergy(): void {
		$overdue = $this->task('Overdue', [self::OWNER], -4, 0, ['energyCost' => 3]);
		$done = $this->task('Done', [self::OWNER], 0, 0, ['energyCost' => 2]);
		$open = $this->task('Open', [self::OWNER], 0, 1, ['energyCost' => 2]);
		$this->complete($done);

		$view = $this->view();
		$ownerId = (int)$this->member(self::OWNER)->getId();

		self::assertSame([$overdue], array_column($view->plan->overdue(), 'roomTaskId'));
		// Six a day, three overdue and two done: the open task takes tomorrow.
		self::assertSame($this->day(1), $this->daysOf($view)[$open]);
		self::assertSame(200, $view->plan->ledger->doneMinor($ownerId));
		self::assertSame(3, $view->plan->ledger->placed($ownerId, $this->today));
	}

	public function testCountFor_SuggestedTodayPlusOverdue(): void {
		$this->task('Overdue', [self::OWNER], -4);
		$this->task('Today', [self::OWNER], 0);
		$this->task('Tomorrow', [self::OWNER], 1);
		$this->task('Someone else', [self::ADULT], 0);

		$view = $this->view();

		self::assertSame(2, $view->countFor($this->member(self::OWNER)));
		self::assertSame(1, $view->countFor($this->member(self::ADULT)));
		self::assertSame(0, $view->countFor($this->member(self::KID)));
	}

	// ------------------------------------------------------------------
	// Today's stored list (decision 3)
	// ------------------------------------------------------------------

	public function testFirstRead_StoresTodaysListOncePerHouseAndDay(): void {
		$today = $this->task('Today', [self::OWNER], 0);
		$this->task('Tomorrow', [self::OWNER], 1);
		self::assertSame([], $this->storedDays(), 'nothing is stored before a read');

		self::assertSame([$today], $this->todayIds());
		self::assertSame([$today], $this->todayIds());

		$stored = $this->storedDays();
		self::assertCount(1, $stored);
		self::assertSame($this->today, $stored[0]['day_key']);
		$ownerId = (int)$this->member(self::OWNER)->getId();
		self::assertEquals(
			[['r' => $today, 'k' => 'd' . $this->today, 'u' => [$ownerId], 'p' => 0, 'o' => false]],
			json_decode((string)$stored[0]['placements'], true),
			'the cycle that was placed and who carries it; no name, no actor'
		);
	}

	public function testFirstRead_PlacesAsEveryLaterRead(): void {
		$week = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
		// The owner prefers today and has more energy tomorrow; the kid has the week off.
		$this->setPreferences(self::OWNER, ['energyBudget' => [$this->weekdayOf(1) => 9] + array_fill_keys($week, 6), 'preferredWeekdays' => [$this->weekdayOf(0)]]);
		$this->setPreferences(self::KID, ['weekdayAvailability' => array_fill_keys($week, false)]);
		$this->task('Own', [self::OWNER], 0, 0, ['energyCost' => 3]);
		$this->task('Adult\'s', [self::ADULT], 0, 0, ['energyCost' => 3]);
		$open = $this->task('Open', [], 0, 1);

		$first = $this->view();
		$later = $this->view();

		// With today open the owner's best day is the preferred today; once today is stored, tomorrow (9) is.
		self::assertSame(self::carriersOf($later, $open), self::carriersOf($first, $open));
		self::assertSame([(int)$this->member(self::OWNER)->getId()], self::carriersOf($first, $open));
		self::assertSame($this->daysOf($later), $this->daysOf($first));
	}

	public function testFirstRead_LostToAnotherRead_FollowsItsListAndSaysSo(): void {
		// On its own this read would place the first on today and the second tomorrow.
		$first = $this->task('First', [self::OWNER], 0, 1);
		$second = $this->task('Second', [self::OWNER], 0, 1);
		// The other read stored its list after this read looked and found nothing.
		\OC::$server->get(SuggestionDayMapper::class)->storeOnce($this->houseId, $this->today, [
			['r' => $second, 'k' => 'd' . $this->today, 'u' => [(int)$this->member(self::OWNER)->getId()], 'p' => 0, 'o' => false],
		], new \DateTime($this->today . ' 00:00:01'));
		$racing = new class(\OC::$server->get(IDBConnection::class)) extends SuggestionDayMapper {
			private bool $looked = false;

			public function findDay(int $houseId, string $dayKey): ?SuggestionDay {
				if (!$this->looked) {
					$this->looked = true;
					return null;
				}
				return parent::findDay($houseId, $dayKey);
			}
		};
		$logger = $this->createMock(AppLogger::class);
		$logger->expects(self::once())->method('warning')
			->with(self::anything(), self::callback(static fn (array $context): bool => $context['event'] === 'suggestion_day_race_lost'));
		$service = new SuggestionService(
			\OC::$server->get(SuggestionInputs::class), new SuggestionPlanner(), $racing, \OC::$server->get(WindowService::class),
			\OC::$server->get(TaskAccessPolicy::class), \OC::$server->get(RoomMapper::class), \OC::$server->get(HouseMapper::class),
			\OC::$server->get(CalendarService::class), \OC::$server->getL10N('chorestrike'), $logger,
		);

		self::assertSame([$first => $this->day(1), $second => $this->day(0)], $this->daysOf($service->view($this->house, clone $this->now)));
		self::assertCount(1, $this->storedDays());
	}

	public function testStoredDay_ThatIsNoList_IsReadAsEmpty(): void {
		$dueToday = $this->task('Due today', [self::OWNER], 0);
		$qb = $this->db->getQueryBuilder();
		$qb->insert('chorestrike_suggestion_days')->values([
			'house_id' => $qb->createNamedParameter($this->houseId, IQueryBuilder::PARAM_INT),
			'day_key' => $qb->createNamedParameter($this->today),
			'placements' => $qb->createNamedParameter('5'),
			'created_at' => $qb->createNamedParameter($this->today . ' 00:00:01'),
		])->executeStatement();

		// Work due only today still joins; nothing breaks for the rest of the day.
		self::assertSame([$dueToday], $this->todayIds());
	}

	public function testStoredDay_ListOfAnotherFirstRead_IsFollowed(): void {
		$kept = $this->task('Kept', [self::OWNER], 0, 1);
		$other = $this->task('Other', [self::OWNER], 0, 1);
		// Another read won the day and placed only one of the two on it.
		\OC::$server->get(SuggestionDayMapper::class)->storeOnce($this->houseId, $this->today, [
			['r' => $kept, 'k' => 'd' . $this->today, 'u' => [(int)$this->member(self::OWNER)->getId()], 'p' => 0, 'o' => false],
		], new \DateTime($this->today . ' 00:00:01'));

		self::assertSame([$kept => $this->day(0), $other => $this->day(1)], $this->daysOf());
	}

	public function testStoreOnce_SecondWriter_KeepsTheFirstListAndTheTransaction(): void {
		$mapper = \OC::$server->get(SuggestionDayMapper::class);
		$mapper->storeOnce($this->houseId, $this->today, [['r' => 1]], new \DateTime());

		// Inside a transaction the lost insert must not poison it (PostgreSQL).
		$stored = \OC::$server->get(TransactionExecutor::class)->run(function () use ($mapper): array {
			$row = $mapper->storeOnce($this->houseId, $this->today, [['r' => 2]], new \DateTime());
			return [$row->getPlacements(), count($this->houseUserMapper->findByHouseId($this->houseId))];
		});

		self::assertSame([[['r' => 1]], 3], $stored);
		self::assertCount(1, $this->storedDays());
	}

	public function testStoredDay_AChangedBudget_MovesWorkOnlyFromTomorrowOn(): void {
		$today = $this->task('Today', [self::OWNER], 0, 1, ['energyCost' => 4]);
		$tomorrow = $this->task('Tomorrow', [self::OWNER], 0, 1, ['energyCost' => 4]);
		self::assertSame([$today => $this->day(0), $tomorrow => $this->day(1)], $this->daysOf($this->firstRead()));

		// More room today would now take both; nothing left today would take neither.
		$this->setPreferences(self::OWNER, ['energyBudget' => array_fill_keys(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'], 20)]);
		self::assertSame([$today => $this->day(0), $tomorrow => $this->day(1)], $this->daysOf());
		$this->setPreferences(self::OWNER, ['energyBudget' => array_fill_keys(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'], 1)]);
		self::assertSame([$today], $this->todayIds());
	}

	public function testStoredDay_DoneWorkLeaves_AndAnUndoBringsItBack(): void {
		$roomTaskId = $this->task('Dishes', [self::OWNER], 0, 0, ['frequencyType' => 'daily']);
		$this->firstRead();

		$this->complete($roomTaskId);
		self::assertSame([], $this->todayIds());
		self::assertSame($this->day(1), $this->daysOf()[$roomTaskId], 'the next cycle is placed after today');

		$this->undoLatestForRoomTask(\OC::$server->get(CompletionService::class), $this->houseId, self::OWNER, $roomTaskId);
		self::assertSame([$roomTaskId], $this->todayIds());
	}

	public function testStoredDay_PostponedSleepingDeletedAndHandedOverWork_Leaves(): void {
		$postponed = $this->task('Postponed', [self::OWNER], 0, 2);
		$asleep = $this->task('Asleep', [self::OWNER], 0, 1);
		$deleted = $this->task('Deleted', [self::OWNER], 0, 1);
		$handedOver = $this->task('Handed over', [self::OWNER], 0, 1);
		$stays = $this->task('Stays', [self::ADULT], 0, 1);
		// Room for everything today and for nothing on the other days, so the first read places all of it on today.
		$this->setPreferences(self::OWNER, ['energyBudget' => [$this->weekdayOf(0) => 20] + array_fill_keys(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'], 1)]);
		self::assertCount(5, $this->firstRead()->today());
		$owner = $this->member(self::OWNER);

		$block = \OC::$server->get(RoomTaskReadService::class)->cycleBlockOf($this->houseId, \OC::$server->get(\OCA\ChoreStrike\Db\RoomTaskMapper::class)->find($postponed));
		\OC::$server->get(PostponeService::class)->postpone($this->houseId, $owner, $postponed, $this->newCommandId(), $block['cycleKey'], $block['expectedVersion']);
		$this->saveForm($this->houseId, $owner, $asleep, ['sleeping' => true]);
		$this->actingAs(self::OWNER);
		\OC::$server->get(RoomTaskService::class)->deleteRoomTask($deleted, $owner);
		$this->assignTo($this->houseId, $owner, $handedOver, [self::ADULT]);

		self::assertSame([$stays], $this->todayIds());
		$days = $this->daysOf();
		self::assertGreaterThan($this->today, $days[$postponed], 'postponed work is suggested again from tomorrow on');
		self::assertSame($this->day(1), $days[$handedOver], 'the new assignee gets it from tomorrow on');
		self::assertArrayNotHasKey($asleep, $days);
		self::assertArrayNotHasKey($deleted, $days);
	}

	public function testStoredDay_WorkCreatedSince_JoinsWhenItsWindowContainsToday(): void {
		$stored = $this->task('Stored', [self::OWNER], 0);
		$this->firstRead();

		$joins = $this->task('Joins', [self::OWNER], 1, 1, ['energyCost' => 5], true);
		$waits = $this->task('Waits', [self::OWNER], 2, 1, [], true);

		$view = $this->view();
		self::assertSame([$stored => $this->day(0), $joins => $this->day(0), $waits => $this->day(1)], $this->daysOf($view));
		self::assertTrue($view->today()[$joins]['overload'], 'it joins today also when today has no room for it');
		self::assertCount(1, json_decode((string)$this->storedDays()[0]['placements'], true), 'the stored row is not rewritten');
	}

	public function testStoredDay_WorkDueOnlyToday_JoinsWhoeverIsResponsibleNow(): void {
		$handedOver = $this->task('Due today, handed over', [self::OWNER], 0);
		$moved = $this->task('Moved onto today', [self::OWNER], 3);
		self::assertSame([$handedOver], array_keys($this->firstRead()->today()));
		$owner = $this->member(self::OWNER);

		// Neither has a later day in its window: a hand-over and an edit that makes work due today.
		$this->assignTo($this->houseId, $owner, $handedOver, [self::ADULT]);
		$this->saveForm($this->houseId, $owner, $moved, ['firstDate' => $this->day(0)]);

		$today = $this->view()->today();
		self::assertSame([self::ADULT], $today[$handedOver]['userIds']);
		self::assertSame([self::OWNER], $today[$moved]['userIds']);
		self::assertCount(1, json_decode((string)$this->storedDays()[0]['placements'], true), 'the stored row is not rewritten');
	}

	public function testStoredDay_WorkWhoseWindowAnEditMovesOffToday_Leaves(): void {
		$roomTaskId = $this->task('Windows', [self::OWNER], 2, 2);
		self::assertSame([$roomTaskId], array_keys($this->firstRead()->today()), 'the roomiest day of its window is today');

		// Same cycle key, a window that now starts the day after tomorrow.
		$taskId = \OC::$server->get(\OCA\ChoreStrike\Db\RoomTaskMapper::class)->find($roomTaskId)->getTaskId();
		$this->editDefinition($this->houseId, $this->member(self::OWNER), $taskId, ['flexDays' => 0]);

		self::assertSame([], $this->todayIds());
		self::assertSame([$roomTaskId => $this->day(2)], $this->daysOf());
		self::assertSame(0, $this->view()->countFor($this->member(self::OWNER)), 'the daily summary agrees with Today');
	}

	public function testStoredDay_JoinerNobodyCanTakeToday_TakesALaterDayOfItsWindow(): void {
		$this->firstRead();
		\OC::$server->get(PauseService::class)->requestForSelf($this->houseId, self::ADULT, [
			'commandId' => $this->newCommandId(), 'startDate' => $this->day(0), 'endDate' => $this->day(0), 'reasonCode' => Pause::REASON_TRAVEL,
		]);

		$joins = $this->task('Created for someone away today', [self::ADULT], 1, 1, [], true);

		self::assertSame([$joins => $this->day(1)], $this->daysOf());
	}

	public function testStoredDay_CarrierWhoLeftTheHouse_IsDroppedFromEveryPlaceTheyStood(): void {
		$roomTaskId = $this->task('Left behind', [self::ADULT], 0, 1);
		self::assertSame([$roomTaskId], array_keys($this->firstRead()->today()));
		$adult = $this->member(self::ADULT);

		$this->actingAs(self::OWNER);
		\OC::$server->get(\OCA\ChoreStrike\Service\HouseService::class)->removeHouseMember((int)$adult->getId());

		$view = $this->view();
		self::assertSame([], $view->today(), 'the stored entry left with its carrier');
		self::assertNotContains((int)$adult->getId(), self::carriersOf($view, $roomTaskId));
		self::assertNotContains(self::ADULT, array_column($this->range(self::OWNER, 0, 1)['members'], 'userId'));
	}

	public function testDeleteOldDays_KeepsAWeek(): void {
		$mapper = \OC::$server->get(SuggestionDayMapper::class);
		foreach ([-9, -8, -7, -1, 0] as $offset) {
			$mapper->storeOnce($this->houseId, $this->day($offset), [], new \DateTime());
		}

		self::assertSame(2, $this->suggestions->deleteOldDays($this->house, new \DateTime()));
		self::assertSame([$this->day(-7), $this->day(-1), $this->day(0)], array_column($this->storedDays(), 'day_key'));
	}

	// ------------------------------------------------------------------
	// The read contract (decision 6)
	// ------------------------------------------------------------------

	public function testRange_FiltersByTaskVisibility_AndShowsTheViewersLoad(): void {
		$public = $this->task('Public', [self::ADULT], 0, 0, ['energyCost' => 3]);
		$private = $this->task('Private', [self::ADULT], 0, 0, ['visibilityType' => 'private'], false, self::ADULT);
		$overduePrivate = $this->task('Overdue private', [self::ADULT], -3, 0, ['visibilityType' => 'private'], false, self::ADULT);

		$forAdult = $this->suggestions->range($this->house, $this->member(self::ADULT), $this->day(0), $this->day(1));
		$forKid = $this->suggestions->range($this->house, $this->member(self::KID), $this->day(0), $this->day(1));
		$forOwner = $this->suggestions->range($this->house, $this->member(self::OWNER), $this->day(0), $this->day(1));

		self::assertSame([$this->day(0), $this->day(1)], array_column($forAdult['days'], 'day'));
		self::assertEqualsCanonicalizing([$public, $private], array_column($forAdult['days'][0]['entries'], 'roomTaskId'));
		self::assertSame([$overduePrivate], array_column($forAdult['overdue'], 'roomTaskId'));
		foreach ([$forKid, $forOwner] as $foreign) {
			self::assertSame([$public], array_column($foreign['days'][0]['entries'], 'roomTaskId'), 'a private task reaches only who may see it');
			self::assertSame([], $foreign['overdue']);
		}
		$entry = $forKid['days'][0]['entries'][0];
		self::assertSame(
			['roomTaskId' => $public, 'cycleKey' => 'd' . $this->today, 'userIds' => [self::ADULT], 'energy' => 3, 'overload' => false,
				'windowStartOn' => $this->today, 'windowEndOn' => $this->today, 'taskName' => 'Public', 'roomId' => $this->roomId, 'roomName' => 'Kitchen'],
			$entry
		);

		// A member reads the own load; the owner, who manages the budgets, reads everyone's.
		self::assertSame([self::KID], array_values(array_unique(array_column($forKid['members'], 'userId'))));
		self::assertEqualsCanonicalizing([self::OWNER, self::ADULT, self::KID], array_values(array_unique(array_column($forOwner['members'], 'userId'))));
		$adultToday = array_values(array_filter($forAdult['members'], fn (array $row): bool => $row['day'] === $this->today))[0];
		self::assertSame(
			['userId' => self::ADULT, 'day' => $this->today, 'budget' => 6, 'placed' => 7, 'doneMinor' => 0, 'preferred' => false, 'absent' => false],
			$adultToday,
			'overdue work counts against today'
		);
	}

	/**
	 * *Today* reads its rows from the suggestion's inputs (D-091-130): the
	 * visibility filter of ADR-010 must hold on that path for every role,
	 * marks included.
	 */
	public function testFilteredDaily_ShowsOnlyRowsTheViewerMaySee(): void {
		$public = $this->task('Public', [self::ADULT], 0);
		$adultsOpen = $this->task('Adult private, unassigned', [], 0, 0, ['visibilityType' => 'private'], false, self::ADULT);
		$adultsOwn = $this->task('Adult private, own', [self::ADULT], 0, 0, ['visibilityType' => 'private'], false, self::ADULT);
		$forKid = $this->task('Owner private, for the kid', [self::KID], 0, 0, ['visibilityType' => 'private']);
		$adminOnly = $this->task('Admin only, assigned to the adult', [self::ADULT], 0, 0, ['visibilityType' => 'admin-only']);
		$today = \OC::$server->get(TodayRows::class);
		$rows = fn (string $userId): array => $today->forViewer($this->house, $this->member($userId), clone $this->now)['rows'];
		$ids = static fn (array $list): array => array_map(static fn (array $row): int => (int)$row['roomTask']->getId(), $list);

		$expected = [
			self::OWNER => [$public, $forKid, $adminOnly],
			self::ADULT => [$public, $adultsOpen, $adultsOwn],
			// A child is offered its own and unassigned work only, among what it may see.
			self::KID => [$forKid],
		];
		foreach ($expected as $userId => $visible) {
			$list = $rows($userId);
			self::assertEqualsCanonicalizing($visible, $ids($list), $userId);
			foreach ($list as $row) {
				self::assertTrue(isset($row['suggestedFor']) || ($row['openWindow'] ?? false), 'every row carries its mark');
			}
		}
	}

	/**
	 * *Today* takes the viewer's load from the same read as its rows
	 * (RF-091-14): a team task counts for the member who carries it, and
	 * unassigned overdue work for the one member the plan counts it
	 * against — here the kid, who has the most energy left once today's
	 * stored list took its share.
	 */
	public function testDailyLoad_IsTheViewersLoadOfTheRangeRead(): void {
		$this->setPreferences(self::ADULT, ['energyBudget' => array_fill_keys(TaskService::WEEKDAYS, 2)]);
		$team = $this->task('Team', [self::OWNER, self::ADULT], 0, 0, ['energyCost' => 4]);
		$this->task('Unassigned overdue', [], -3, 0, ['energyCost' => 2]);
		$today = \OC::$server->get(TodayRows::class);
		$loadOf = fn (string $userId): array => $today->forViewer($this->house, $this->member($userId), clone $this->now)['load'];

		$owner = $loadOf(self::OWNER);

		self::assertSame([(int)$this->member(self::OWNER)->getId(), (int)$this->member(self::ADULT)->getId()], self::carriersOf($this->view(), $team));
		self::assertSame(
			['userId' => self::OWNER, 'day' => $this->today, 'budget' => 6, 'placed' => 4, 'doneMinor' => 0, 'preferred' => false, 'absent' => false],
			$owner,
			'the team task, and nothing of the unassigned overdue work'
		);
		self::assertSame(0, $loadOf(self::ADULT)['placed'], 'a team task takes only its carrier\'s energy');
		self::assertSame(2, $loadOf(self::KID)['placed'], 'the overdue work counts once');
		$ownRow = static fn (array $rows): array => array_values(array_filter($rows, fn (array $row): bool => $row['day'] === $owner['day']))[0];
		self::assertSame($owner, $ownRow($this->range(self::OWNER, 0, 0)['members']), 'Today and the calendar read one load');
	}

	public function testRange_NamesTheHorizon(): void {
		$last = $this->day(SuggestionPlanner::HORIZON_DAYS - 1);

		foreach ([[0, 2], [SuggestionPlanner::HORIZON_DAYS, SuggestionPlanner::HORIZON_DAYS + 3]] as [$from, $to]) {
			$range = $this->range(self::KID, $from, $to);
			self::assertSame(SuggestionPlanner::HORIZON_DAYS, $range['horizonDays']);
			self::assertSame($last, $range['horizonEndOn'], 'also when the range lies outside it');
		}
	}

	public function testRange_ProjectsTheRecurringWorkOfTheHorizon(): void {
		$daily = $this->task('Daily', [self::OWNER], 0, 0, ['frequencyType' => 'daily']);
		$last = $this->day(SuggestionPlanner::HORIZON_DAYS - 1);

		$range = $this->suggestions->range($this->house, $this->member(self::OWNER), $this->day(0), $last);

		self::assertCount(SuggestionPlanner::HORIZON_DAYS, $range['days']);
		foreach ($range['days'] as $index => $day) {
			self::assertSame([$daily], array_column($day['entries'], 'roomTaskId'), $day['day']);
			// Only the current cycle is a completion target.
			self::assertSame($index === 0 ? 'd' . $this->today : null, $day['entries'][0]['cycleKey']);
		}
	}

	public function testRange_StartingAfterToday_HasNoOverdue(): void {
		$overdue = $this->task('Overdue', [self::OWNER], -4);

		self::assertSame([$overdue], array_column($this->range(self::OWNER, 0, 1)['overdue'], 'roomTaskId'));
		self::assertSame([], $this->range(self::OWNER, 1, 2)['overdue'], 'overdue work counts against today only');
	}

	/**
	 * A client whose day ran over midnight, or whose week reaches past the
	 * horizon, gets the days that remain.
	 *
	 * @dataProvider rangesReachingOutOfTheHorizon
	 */
	public function testRange_ReachingOutOfTheHorizon_IsCutToIt(int $from, int $to, int $first, int $last): void {
		$days = array_column($this->range(self::OWNER, $from, $to)['days'], 'day');

		self::assertSame($this->day($first), $days[0]);
		self::assertSame($this->day($last), $days[count($days) - 1]);
	}

	/** @return array<string, array{int, int, int, int}> */
	public static function rangesReachingOutOfTheHorizon(): array {
		return [
			'from yesterday' => [-1, 3, 0, 3],
			'beyond the horizon' => [0, SuggestionPlanner::HORIZON_DAYS + 2, 0, SuggestionPlanner::HORIZON_DAYS - 1],
		];
	}

	public function testRange_WhollyOutsideOrReversed_ReadsAndStoresNothing(): void {
		$this->task('Today', [self::OWNER], 0);

		$outside = $this->range(self::OWNER, SuggestionPlanner::HORIZON_DAYS, SuggestionPlanner::HORIZON_DAYS + 3);
		self::assertSame([[], [], []], [$outside['days'], $outside['overdue'], $outside['members']]);
		try {
			$this->range(self::OWNER, 3, 2);
			self::fail('a reversed range is refused');
		} catch (\InvalidArgumentException $e) {
			self::assertSame('The last day must not lie before the first day', $e->getMessage());
		}
		self::assertSame([], $this->storedDays(), 'nothing is stored before the range is known to hold a day');
	}
}
