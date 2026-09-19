<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\CompletedTask;
use OCA\ChoreStrike\Db\CompletedTaskMapper;
use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\Room;
use OCA\ChoreStrike\Db\RoomMapper;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\RoomTaskChecklistProgress;
use OCA\ChoreStrike\Db\RoomTaskChecklistProgressMapper;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Db\TaskAssigneeMapper;
use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Exception\ConflictException;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Exception\NotFoundException;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\CalendarService;
use OCA\ChoreStrike\Service\CompletionService;
use OCA\ChoreStrike\Service\GameLogicService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\NotificationService;
use OCA\ChoreStrike\Service\TaskAccessPolicy;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CompletionService (CS-PLAN-064 WP5): occurrence gates,
 * before-state/deltas snapshot discipline, teamwork fold, post-commit
 * degradation, and the idempotent undo aggregate.
 */
class CompletionServiceTest extends TestCase {
	private CompletionService $service;
	private RoomTaskMapper $roomTaskMapper;
	private TaskMapper $taskMapper;
	private RoomMapper $roomMapper;
	private HouseMapper $houseMapper;
	private HouseUserMapper $houseUserMapper;
	private TaskAssigneeMapper $taskAssigneeMapper;
	private CompletedTaskMapper $completedTaskMapper;
	private RoomTaskChecklistProgressMapper $checklistProgressMapper;
	private GameLogicService $gameLogicService;
	private HouseService $houseService;
	private NotificationService $notificationService;
	private IDBConnection $db;
	private AppLogger $appLogger;
	private TaskAccessPolicy $taskAccessPolicy;

	protected function setUp(): void {
		$this->roomTaskMapper = $this->createMock(RoomTaskMapper::class);
		$this->taskMapper = $this->createMock(TaskMapper::class);
		$this->roomMapper = $this->createMock(RoomMapper::class);
		$this->houseMapper = $this->createMock(HouseMapper::class);
		$this->houseUserMapper = $this->createMock(HouseUserMapper::class);
		$this->taskAssigneeMapper = $this->createMock(TaskAssigneeMapper::class);
		$this->completedTaskMapper = $this->createMock(CompletedTaskMapper::class);
		$this->checklistProgressMapper = $this->createMock(RoomTaskChecklistProgressMapper::class);
		$this->gameLogicService = $this->createMock(GameLogicService::class);
		$this->houseService = $this->createMock(HouseService::class);
		$this->notificationService = $this->createMock(NotificationService::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->appLogger = $this->createMock(AppLogger::class);
		$this->taskAccessPolicy = $this->createMock(TaskAccessPolicy::class);

		$utcHouse = new House();
		$utcHouse->setTimezone('UTC');
		$this->houseMapper->method('find')->willReturn($utcHouse);
		// CS-PLAN-071 WP0: completion/undo additionally call
		// HouseMapper::findForUpdate as the FIRST in-transaction lock; the
		// return value is unused, so tests that do not track lock order need
		// no stub for it (PHPUnit keeps the FIRST stub per method, so the
		// call-order test registers its own tracking stub).

		$defaultSnapshot = [
			'dueCount' => 0,
			'completedCount' => 0,
			'percent' => 0,
			'targetPercent' => 80,
			'achieved' => false,
			'weekStart' => '2026-09-07T00:00:00+00:00',
			'weekEnd' => '2026-09-13T23:59:59+00:00',
		];
		$this->houseService->method('getWeeklyGoalProgress')->willReturn($defaultSnapshot);

		$this->service = new CompletionService(
			new \OCA\ChoreStrike\Db\TransactionExecutor($this->db),
			$this->roomTaskMapper,
			$this->taskMapper,
			$this->roomMapper,
			$this->houseMapper,
			$this->houseUserMapper,
			$this->taskAssigneeMapper,
			$this->completedTaskMapper,
			$this->checklistProgressMapper,
			// CS-PLAN-067 §3.2: the extracted participants run REAL against
			// the same mocks, so the degrade/snapshot contracts stay
			// observable end-to-end.
			new \OCA\ChoreStrike\Service\CompletionEffectService(
				$this->roomTaskMapper,
				$this->roomMapper,
				$this->houseUserMapper,
				$this->checklistProgressMapper
			),
			new \OCA\ChoreStrike\Service\CompletionPostCommitService(
				$this->houseService,
				$this->houseMapper,
				$this->houseUserMapper,
				$this->notificationService,
				$this->appLogger
			),
			$this->gameLogicService,
			new CalendarService(),
			$this->taskAccessPolicy,
			$this->createIdentityL10n(),
			$this->appLogger
		);
	}

	private function createIdentityL10n(): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text) => $text);
		return $l10n;
	}

	// ------------------------------------------------------------------
	// Fixture builders
	// ------------------------------------------------------------------

	private function buildTask(int $id = 1): Task {
		$task = new Task();
		$task->setId($id);
		$task->setName('Test Task');
		$task->setEnergyCost(3);
		$task->setPriority(2);
		$task->setFrequencyType('weekly');
		$task->setFrequencyValue(1);
		$task->setRecurrenceMode('recurring');
		$task->setSchedulingMode('dynamic');
		return $task;
	}

	private function buildRoom(int $id = 1, int $cleanliness = 50): Room {
		$room = new Room();
		$room->setId($id);
		$room->setCleanliness($cleanliness);
		return $room;
	}

	private function buildRoomTask(int $id = 1, bool $isActive = true): RoomTask {
		$roomTask = new RoomTask();
		$roomTask->setId($id);
		$roomTask->setTaskId(1);
		$roomTask->setRoomId(1);
		$roomTask->setIsActive($isActive);
		$roomTask->setNextDue(new \DateTime('today'));
		return $roomTask;
	}

	private function buildHouseUser(int $id = 1, string $uid = 'user123'): HouseUser {
		$houseUser = new HouseUser();
		$houseUser->setId($id);
		$houseUser->setNextcloudUserId($uid);
		return $houseUser;
	}

	/**
	 * Stub every pre-transaction and in-transaction lookup for the happy
	 * completion path. Overridable collaborators are passed in explicitly —
	 * a PHPUnit mock keeps its FIRST stub per method, so overrides must be
	 * parameters, not re-registrations.
	 */
	private function stubCompleteLookups(
		RoomTask $roomTask,
		Room $room,
		Task $task,
		HouseUser $houseUser,
		array $assigneeUserIds = [],
		int $todayCount = 0,
		array $progressRows = [],
		?\Closure $gameResult = null,
	): void {
		$this->roomTaskMapper->method('findByIdsScopedToHouse')->willReturn([$roomTask]);
		$this->taskMapper->method('find')->willReturn($task);
		$this->roomMapper->method('find')->willReturn($room);
		$this->houseUserMapper->method('findByHouseAndUser')->willReturn($houseUser);
		$this->roomTaskMapper->method('lockForUpdate')->willReturn($roomTask);
		$this->roomMapper->method('lockForUpdate')->willReturn($room);
		$this->houseUserMapper->method('findForUpdate')->willReturn($houseUser);
		$this->taskAssigneeMapper->method('findUserIdsByRoomTask')->willReturn($assigneeUserIds);
		$this->completedTaskMapper->method('countByRoomTaskAndUserOnDate')->willReturn($todayCount);
		$this->checklistProgressMapper->method('findByRoomTaskId')->willReturn($progressRows);
		$this->roomMapper->method('update')->willReturn($room);
		$this->gameLogicService->method('handleTaskCompletion')->willReturnCallback(
			$gameResult ?? function (RoomTask $rt, int $userId, Task $t, $tz, array $assignees, ?CompletedTask $row) {
				$row->setCoinsEarned(50);
				$row->setWasCombo(false);
				return [
					'contributionScore' => 3,
					'coins' => 50,
					'teamworkBonus' => 0,
					'isCombo' => false,
					'currentStreak' => 1,
					'longestStreak' => 1,
					'totalContribution' => 3,
					'totalCoins' => 50,
					'streakFreezeUsed' => false,
					'streakFreezesRemaining' => 1,
					'comboBonusFreeze' => false,
					'titlesAdded' => [],
					'newTitle' => null,
				];
			}
		);
	}

	// ------------------------------------------------------------------
	// complete(): occurrence gates (decision D4)
	// ------------------------------------------------------------------

	/**
	 * Complete_InactiveOccurrence_ConflictBeforeAnyMutation
	 *
	 * An inactive room task answers 409 BEFORE any mapper mutation call —
	 * no recurrence update, no checklist reset, no reward, no ledger row.
	 */
	public function testComplete_InactiveOccurrence_ConflictBeforeAnyMutation(): void {
		$roomTask = $this->buildRoomTask(1, false);
		$this->stubCompleteLookups($roomTask, $this->buildRoom(), $this->buildTask(), $this->buildHouseUser());

		$this->roomTaskMapper->expects($this->never())->method('update');
		$this->roomMapper->expects($this->never())->method('update');
		$this->houseUserMapper->expects($this->never())->method('update');
		$this->checklistProgressMapper->expects($this->never())->method('resetProgress');
		$this->completedTaskMapper->expects($this->never())->method('insert');
		$this->db->expects($this->once())->method('rollBack');
		$this->db->expects($this->never())->method('commit');

		$this->expectException(ConflictException::class);
		$this->expectExceptionMessage('Task is not active');

		$this->service->complete(1, 'user123', 1);
	}

	/**
	 * Complete_DuplicateOccurrenceToday_Forbidden
	 */
	public function testComplete_DuplicateOccurrenceToday_Forbidden(): void {
		$roomTask = $this->buildRoomTask();
		$this->stubCompleteLookups($roomTask, $this->buildRoom(), $this->buildTask(), $this->buildHouseUser(), [], 1);

		$this->roomTaskMapper->expects($this->never())->method('update');
		$this->completedTaskMapper->expects($this->never())->method('insert');

		$this->expectException(ForbiddenException::class);
		$this->expectExceptionMessage('You already completed this task today');

		$this->service->complete(1, 'user123', 1);
	}

	/**
	 * Complete_NonMember_ForbiddenBeforeAnyLock
	 */
	public function testComplete_NonMember_ForbiddenBeforeAnyLock(): void {
		$roomTask = $this->buildRoomTask();
		$this->roomTaskMapper->method('findByIdsScopedToHouse')->willReturn([$roomTask]);
		$this->taskMapper->method('find')->willReturn($this->buildTask());
		$this->roomMapper->method('find')->willReturn($this->buildRoom());
		$this->houseUserMapper->method('findByHouseAndUser')->willReturn(null);

		$this->roomTaskMapper->expects($this->never())->method('lockForUpdate');

		$this->expectException(ForbiddenException::class);
		$this->expectExceptionMessage('Only house members can complete tasks');

		$this->service->complete(1, 'stranger', 1);
	}

	/**
	 * Complete_ForeignOrUnknownId_NotFound (404 anti-enumeration)
	 */
	public function testComplete_ForeignOrUnknownId_NotFound(): void {
		$this->roomTaskMapper->method('findByIdsScopedToHouse')->willReturn([]);

		$this->expectException(NotFoundException::class);
		$this->service->complete(1, 'user123', 99);
	}

	/**
	 * Complete_InvisiblePrivateTask_NotFound
	 */
	public function testComplete_InvisiblePrivateTask_NotFound(): void {
		$roomTask = $this->buildRoomTask();
		$this->stubCompleteLookups($roomTask, $this->buildRoom(), $this->buildTask(), $this->buildHouseUser());
		$this->taskAccessPolicy->method('assertCanView')
			->willThrowException(new NotFoundException('Task not found'));

		$this->roomTaskMapper->expects($this->never())->method('update');

		$this->expectException(NotFoundException::class);
		$this->service->complete(1, 'user123', 1);
	}

	// ------------------------------------------------------------------
	// complete(): snapshot / ledger discipline (decision D1)
	// ------------------------------------------------------------------

	/**
	 * Complete_HappyPath_InsertsLedgerRowWithBeforeStateOccurrenceDueAndDeltas
	 *
	 * The before_state is captured from PRE-mutation values; the ledger
	 * insert carries occurrence_due (house-local day of the completed
	 * occurrence) and the full delta record; checklist resetProgress runs
	 * inside the transaction (first-caller pin, decision D6).
	 */
	public function testComplete_HappyPath_InsertsLedgerRowWithBeforeStateOccurrenceDueAndDeltas(): void {
		$roomTask = $this->buildRoomTask();
		$room = $this->buildRoom(1, 50);
		$task = $this->buildTask();
		$houseUser = $this->buildHouseUser();

		$progressRow = new RoomTaskChecklistProgress();
		$progressRow->setRoomTaskId(1);
		$progressRow->setChecklistItemId(7);
		$progressRow->setIsChecked(true);
		$progressRow->setCheckedAt(new \DateTime('2026-09-06T10:00:00'));
		$progressRow->setCheckedByUserId(3);

		$nextDueBefore = $roomTask->getNextDue()->format('c');

		/** @var CompletedTask|null $ledgerRow */
		$ledgerRow = null;
		$this->stubCompleteLookups(
			$roomTask,
			$room,
			$task,
			$houseUser,
			[],
			0,
			[$progressRow],
			function (RoomTask $rt, int $userId, Task $t, $tz, array $assignees, ?CompletedTask $row) {
				// The teamwork input threads the canonical assignee set (D9).
				self::assertSame([], $assignees);
				$row->setCoinsEarned(50);
				$row->setWasCombo(false);
				return [
					'contributionScore' => 3,
					'coins' => 50,
					'teamworkBonus' => 4,
					'isCombo' => false,
					'currentStreak' => 2,
					'longestStreak' => 5,
					'totalContribution' => 3,
					'totalCoins' => 54,
					'streakFreezeUsed' => false,
					'streakFreezesRemaining' => 1,
					'comboBonusFreeze' => false,
					'titlesAdded' => [],
					'newTitle' => null,
				];
			}
		);

		$inserted = null;
		$this->completedTaskMapper->expects($this->once())
			->method('insert')
			->willReturnCallback(function (CompletedTask $row) use (&$inserted) {
				$inserted = $row;
				return $row;
			});

		// First caller pin (decision D6): the reset runs inside the
		// completion transaction.
		$this->checklistProgressMapper->expects($this->once())->method('resetProgress')->with(1);

		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('commit');

		$result = $this->service->complete(1, 'user123', 1);

		self::assertNotNull($inserted, 'one ledger row must be inserted');
		$before = $inserted->getBeforeState();
		self::assertSame($nextDueBefore, $before['roomTask']['nextDue'], 'before_state.roomTask.nextDue is the pre-mutation value');
		self::assertSame(true, $before['roomTask']['isActive']);
		self::assertSame(null, $before['roomTask']['lastCompleted']);
		self::assertSame(50, $before['room']['cleanliness']);
		self::assertSame(0, $before['houseUser']['coins']);
		self::assertSame([], $before['houseUser']['unlockedTitles']);
		self::assertSame(
			[[
				'checklistItemId' => 7,
				'userId' => 3,
				'checked' => true,
				'checkedAt' => (new \DateTime('2026-09-06T10:00:00'))->format('c'),
			]],
			$before['checklist']
		);
		self::assertNotNull($inserted->getOccurrenceDue(), 'occurrence_due must be recorded');
		self::assertSame(
			(new \DateTime($nextDueBefore))->format('Y-m-d'),
			$inserted->getOccurrenceDue()->format('Y-m-d'),
			'occurrence_due is the house-local day of the completed occurrence (pre-advance nextDue)'
		);
		self::assertNull($inserted->getUndoneAt(), 'a fresh completion is revertible');

		$deltas = $inserted->getDeltas();
		self::assertSame(54, $deltas['coinsAwarded'], 'coinsAwarded = base + teamwork');
		self::assertSame(4, $deltas['teamworkBonus']);
		self::assertSame(3, $deltas['contributionAwarded']);
		self::assertSame(0, $deltas['streakBefore']);
		self::assertSame(2, $deltas['streakAfter']);
		self::assertFalse($deltas['freezeUsed']);
		self::assertFalse($deltas['freezeAwarded']);
		self::assertSame([], $deltas['titlesAdded']);
		self::assertSame(30, $deltas['cleanlinessChange']);
		self::assertFalse($deltas['takeoverClaimed']);

		// Response (decision D10): authoritative totals reconcile stores.
		self::assertSame(54, $result['rewards']['totalCoins']);
		self::assertSame(3, $result['rewards']['totalContribution']);
		self::assertSame(5, $result['rewards']['longestStreak']);
		self::assertArrayNotHasKey('degraded', $result['rewards']);
		// CS-PLAN-066 §3.4: newTitle is explicit and nullable — a null
		// unlock still rides the response shape.
		self::assertNull($result['rewards']['newTitle']);
	}

	/**
	 * Complete_ComputedNewTitle_TravelsInRewards
	 *
	 * CS-PLAN-066 §3.4: the completion aggregate carries the computed
	 * unlock to the client so the title event fires exactly once per
	 * successful completion response — independent of any post-commit
	 * notification failure (the notification runs AFTER the payload is
	 * built and cannot erase the unlock).
	 */
	public function testComplete_ComputedNewTitle_TravelsInRewards(): void {
		$roomTask = $this->buildRoomTask();
		$room = $this->buildRoom(1, 50);
		$task = $this->buildTask();
		$houseUser = $this->buildHouseUser();

		$this->stubCompleteLookups(
			$roomTask,
			$room,
			$task,
			$houseUser,
			[],
			0,
			[],
			function (RoomTask $rt, int $userId, Task $t, $tz, array $assignees, ?CompletedTask $row) {
				$row->setCoinsEarned(50);
				$row->setWasCombo(false);
				return [
					'contributionScore' => 3,
					'coins' => 50,
					'teamworkBonus' => 0,
					'isCombo' => false,
					'currentStreak' => 1,
					'longestStreak' => 1,
					'totalContribution' => 3,
					'totalCoins' => 50,
					'streakFreezeUsed' => false,
					'streakFreezesRemaining' => 1,
					'comboBonusFreeze' => false,
					'titlesAdded' => [],
					'newTitle' => [
						'pack' => 'sparkling',
						'labelKey' => 'titles.sparkling.bronze',
						'tierIndex' => 0,
					],
				];
			}
		);

		$this->completedTaskMapper->method('insert')->willReturnArgument(0);
		$this->db->method('beginTransaction');
		$this->db->method('commit');

		$result = $this->service->complete(1, 'user123', 1);

		self::assertSame(
			['pack' => 'sparkling', 'labelKey' => 'titles.sparkling.bronze', 'tierIndex' => 0],
			$result['rewards']['newTitle']
		);
	}

	/**
	 * The effect-record JSON contract (ADR-011): every recorded key of the
	 * before_state is present with the documented type — restore reads
	 * through typed accessors, so a shape drift must fail here, not at undo.
	 */
	public function testBeforeStateSerializationContract_IsPinned(): void {
		$roomTask = $this->buildRoomTask();
		$room = $this->buildRoom(1, 42);
		$task = $this->buildTask();
		$houseUser = $this->buildHouseUser();
		$houseUser->setCoins(11);
		$houseUser->setContributionScore(7);
		$houseUser->setCurrentStreak(2);
		$houseUser->setLongestStreak(9);
		$houseUser->setStreakFreezes(1);
		$houseUser->setUnlockedTitles(['pack.tier']);

		$roomTask->setWasClaimed(true);
		$roomTask->setOriginalAssignee('orig');
		$roomTask->setClaimedByUser('claimer');
		$roomTask->setClaimedAt(new \DateTime('2026-09-01T08:00:00'));
		$roomTask->setLastCompleted(new \DateTime('2026-08-30T08:00:00'));

		$beforeState = null;
		$this->stubCompleteLookups(
			$roomTask,
			$room,
			$task,
			$houseUser,
			[],
			0,
			[],
			function ($rt, $userId, $t, $tz, $assignees, ?CompletedTask $row) {
				return [
					'contributionScore' => 3, 'coins' => 1, 'teamworkBonus' => 0, 'isCombo' => false,
					'currentStreak' => 2, 'longestStreak' => 9, 'totalContribution' => 10, 'totalCoins' => 12,
					'streakFreezeUsed' => false, 'streakFreezesRemaining' => 1, 'comboBonusFreeze' => false,
					'titlesAdded' => [], 'newTitle' => null,
				];
			}
		);
		$this->completedTaskMapper->expects($this->once())
			->method('insert')
			->willReturnCallback(function (CompletedTask $row) use (&$beforeState) {
				$beforeState = $row->getBeforeState();
				return $row;
			});

		$this->service->complete(1, 'user123', 1);

		self::assertEqualsCanonicalizing(
			['roomTask', 'room', 'houseUser', 'checklist'],
			array_keys($beforeState)
		);
		self::assertEqualsCanonicalizing(
			['nextDue', 'idealDueDate', 'isActive', 'lastCompleted', 'wasClaimed', 'originalAssignee', 'claimedByUser', 'claimedAt'],
			array_keys($beforeState['roomTask'])
		);
		self::assertEqualsCanonicalizing(['cleanliness', 'lastCleaned'], array_keys($beforeState['room']));
		self::assertEqualsCanonicalizing(
			['coins', 'contributionScore', 'currentStreak', 'longestStreak', 'streakFreezes', 'lastFreezeDate', 'unlockedTitles', 'lastActivity'],
			array_keys($beforeState['houseUser'])
		);
		self::assertSame(42, $beforeState['room']['cleanliness']);
		self::assertSame(11, $beforeState['houseUser']['coins']);
		self::assertSame(['pack.tier'], $beforeState['houseUser']['unlockedTitles']);
		self::assertSame(true, $beforeState['roomTask']['wasClaimed']);
		self::assertSame('claimer', $beforeState['roomTask']['claimedByUser']);
	}

	// ------------------------------------------------------------------
	// complete(): rollback sabotage
	// ------------------------------------------------------------------

	/**
	 * Complete_MapperFailureAfterLedgerInsert_RollsBackZeroWrites
	 */
	public function testComplete_MapperFailureAfterLedgerInsert_RollsBackZeroWrites(): void {
		$roomTask = $this->buildRoomTask();
		$room = $this->buildRoom(1, 50);
		$task = $this->buildTask();
		$houseUser = $this->buildHouseUser();
		$this->stubCompleteLookups($roomTask, $room, $task, $houseUser);

		// A sabotage AFTER the reward phase already wrote its rows: the
		// rollback must discard them all (ledger insert is the last write).
		$this->completedTaskMapper->expects($this->once())
			->method('insert')
			->willThrowException(new \RuntimeException('db down'));

		$this->db->expects($this->once())->method('rollBack');
		$this->db->expects($this->never())->method('commit');

		$this->appLogger->expects($this->once())->method('logException');

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('db down');

		$this->service->complete(1, 'user123', 1);
	}

	// ------------------------------------------------------------------
	// complete(): post-commit degradation (decision D7)
	// ------------------------------------------------------------------

	/**
	 * Complete_WeeklyGoalReadFails_StillSuccessWithDegradedFlag
	 *
	 * A post-commit failure must never turn a committed completion into an
	 * ambiguous API failure: the response stays success with
	 * `rewards.weeklyGoal = null`, `rewards.degraded = true`, and a logged
	 * warning — no exception escapes.
	 */
	public function testComplete_WeeklyGoalReadFails_StillSuccessWithDegradedFlag(): void {
		$roomTask = $this->buildRoomTask();
		$room = $this->buildRoom(1, 50);
		$task = $this->buildTask();
		$houseUser = $this->buildHouseUser();
		$this->stubCompleteLookups($roomTask, $room, $task, $houseUser);
		$this->houseService->method('getWeeklyGoalProgress')
			->willThrowException(new \RuntimeException('goal read exploded'));

		$this->appLogger->expects($this->once())
			->method('warning')
			->with(
				'CompletionService: post-commit effects degraded',
				$this->callback(function (array $context): bool {
					return $context['event'] === 'completion_post_commit_degraded'
						&& $context['service'] === 'CompletionService';
				})
			);

		$result = $this->service->complete(1, 'user123', 1);

		self::assertTrue($result['rewards']['degraded']);
		self::assertNull($result['rewards']['weeklyGoal']);
	}

	/**
	 * Complete_TeamworkFold_GamePhasePerformsTheOnlyHouseUserWrite
	 *
	 * Decision D9: the bonus is folded into handleTaskCompletion — the
	 * completion service itself performs NO second house-user write (the
	 * old unlocked RMW is gone).
	 */
	public function testComplete_TeamworkFold_NoSecondHouseUserWrite(): void {
		$roomTask = $this->buildRoomTask();
		$room = $this->buildRoom(1, 50);
		$task = $this->buildTask();
		$houseUser = $this->buildHouseUser();

		$this->stubCompleteLookups(
			$roomTask,
			$room,
			$task,
			$houseUser,
			['alice', 'bob'],
			0,
			[],
			function (RoomTask $rt, int $userId, Task $t, $tz, array $assignees, ?CompletedTask $row) {
				self::assertSame(['alice', 'bob'], $assignees, 'the canonical assignee set threads the teamwork input');
				$row->setCoinsEarned(50);
				$row->setWasCombo(false);
				return [
					'contributionScore' => 3, 'coins' => 50, 'teamworkBonus' => 2, 'isCombo' => false,
					'currentStreak' => 1, 'longestStreak' => 1, 'totalContribution' => 3, 'totalCoins' => 52,
					'streakFreezeUsed' => false, 'streakFreezesRemaining' => 1, 'comboBonusFreeze' => false,
					'titlesAdded' => [], 'newTitle' => null,
				];
			}
		);

		$this->houseUserMapper->expects($this->never())->method('update');

		$result = $this->service->complete(1, 'user123', 1);
		self::assertSame(2, $result['rewards']['teamworkBonus']);
		self::assertSame(52, $result['rewards']['totalCoins']);
	}

	// ------------------------------------------------------------------
	// undo(): idempotency + actor gate (decision D3)
	// ------------------------------------------------------------------

	private function buildLedgerRow(array $beforeState, array $deltas): CompletedTask {
		$row = new CompletedTask();
		$row->setId(77);
		$row->setRoomTaskId(1);
		$row->setUserId(1);
		$row->setCompletedAt(new \DateTime('-5 minutes'));
		$row->setBeforeState($beforeState);
		$row->setDeltas($deltas);
		return $row;
	}

	private function buildBeforeState(): array {
		return [
			'roomTask' => [
				'nextDue' => '2026-09-06T00:00:00+00:00',
				'idealDueDate' => null,
				'isActive' => true,
				'lastCompleted' => null,
				'wasClaimed' => false,
				'originalAssignee' => null,
				'claimedByUser' => null,
				'claimedAt' => null,
			],
			'room' => [
				'cleanliness' => 40,
				'lastCleaned' => '2026-09-04T18:00:00+00:00',
			],
			'houseUser' => [
				'coins' => 10,
				'contributionScore' => 4,
				'currentStreak' => 3,
				'longestStreak' => 8,
				'streakFreezes' => 2,
				'lastFreezeDate' => null,
				'unlockedTitles' => ['pack.a'],
				'lastActivity' => '2026-09-05T07:30:00+00:00',
			],
			'checklist' => [
				['checklistItemId' => 5, 'userId' => 2, 'checked' => true, 'checkedAt' => '2026-09-05T09:00:00+00:00'],
			],
		];
	}

	private function stubUndoLookups(RoomTask $roomTask, Room $room, Task $task, HouseUser $actor): void {
		$this->roomTaskMapper->method('findByIdsScopedToHouse')->willReturn([$roomTask]);
		$this->taskMapper->method('find')->willReturn($task);
		$this->roomMapper->method('find')->willReturn($room);
		$this->taskAssigneeMapper->method('findUserIdsByRoomTask')->willReturn([]);
		$this->roomTaskMapper->method('lockForUpdate')->willReturn($roomTask);
		$this->roomMapper->method('lockForUpdate')->willReturn($room);
		$this->roomMapper->method('update')->willReturn($room);
	}

	/**
	 * Undo_NoRevertibleRow_ZeroWriteIdempotentSuccess
	 */
	public function testUndo_NoRevertibleRow_ZeroWriteIdempotentSuccess(): void {
		$roomTask = $this->buildRoomTask();
		$room = $this->buildRoom();
		$actor = $this->buildHouseUser(1, 'user123');
		$this->stubUndoLookups($roomTask, $room, $this->buildTask(), $actor);
		$this->completedTaskMapper->method('findNewestRevertibleByRoomTask')->willReturn(null);

		$this->db->expects($this->never())->method('beginTransaction');
		$this->roomTaskMapper->expects($this->never())->method('update');
		$this->completedTaskMapper->expects($this->never())->method('markUndone');

		$result = $this->service->undo(1, $actor, 1);

		self::assertFalse($result['undone']);
		self::assertNull($result['rewards']);
	}

	/**
	 * Undo_MemberWhoIsNotCompleter_Forbidden
	 */
	public function testUndo_MemberWhoIsNotCompleter_Forbidden(): void {
		$roomTask = $this->buildRoomTask();
		$room = $this->buildRoom();
		$actor = $this->buildHouseUser(2, 'other-member');
		$actor->setRole('member');
		$this->stubUndoLookups($roomTask, $room, $this->buildTask(), $actor);

		$completer = $this->buildHouseUser(1, 'alice');
		$this->houseUserMapper->method('find')->willReturn($completer);
		$this->completedTaskMapper->method('findNewestRevertibleByRoomTask')
			->willReturn($this->buildLedgerRow($this->buildBeforeState(), []));

		$this->db->expects($this->never())->method('beginTransaction');

		$this->expectException(ForbiddenException::class);
		$this->expectExceptionMessage('Only the completer or a house owner can undo a completion');

		$this->service->undo(1, $actor, 1);
	}

	/**
	 * Undo_OwnerMayUndoSomeoneElsesCompletion_Allowed
	 */
	public function testUndo_OwnerMayUndoSomeoneElsesCompletion_Allowed(): void {
		$roomTask = $this->buildRoomTask();
		$room = $this->buildRoom();
		$owner = $this->buildHouseUser(2, 'owner-user');
		$owner->setRole('owner');
		$this->stubUndoLookups($roomTask, $room, $this->buildTask(), $owner);

		$completer = $this->buildHouseUser(1, 'alice');
		$this->houseUserMapper->method('find')->willReturn($completer);
		$this->houseUserMapper->method('findForUpdate')->willReturn($completer);
		$ledger = $this->buildLedgerRow($this->buildBeforeState(), [
			'coinsAwarded' => 50,
			'titlesAdded' => [],
			'teamworkBonus' => 0,
			'contributionAwarded' => 3,
			'cleanlinessChange' => 30,
		]);
		// First probe finds the row; the under-lock re-check must find it again.
		$this->completedTaskMapper->method('findNewestRevertibleByRoomTask')->willReturn($ledger);

		$this->db->expects($this->once())->method('commit');
		$this->completedTaskMapper->expects($this->once())->method('markUndone')->with(77);

		$result = $this->service->undo(1, $owner, 1);
		self::assertTrue($result['undone']);
	}

	/**
	 * Undo_ChildCompleterUndoingOwnCompletion_Allowed
	 */
	public function testUndo_ChildCompleterUndoingOwnCompletion_Allowed(): void {
		$roomTask = $this->buildRoomTask();
		$room = $this->buildRoom();
		$child = $this->buildHouseUser(3, 'kid-user');
		$child->setRole('child');
		$this->stubUndoLookups($roomTask, $room, $this->buildTask(), $child);

		$this->houseUserMapper->method('find')->willReturn($child);
		$this->houseUserMapper->method('findForUpdate')->willReturn($child);
		$ledger = $this->buildLedgerRow($this->buildBeforeState(), ['titlesAdded' => []]);
		$this->completedTaskMapper->method('findNewestRevertibleByRoomTask')->willReturn($ledger);

		$this->db->expects($this->once())->method('commit');

		$result = $this->service->undo(1, $child, 1);
		self::assertTrue($result['undone']);
	}

	// ------------------------------------------------------------------
	// undo(): restore discipline (masterplan D2)
	// ------------------------------------------------------------------

	/**
	 * Undo_RestoresRecordedBeforeState_InDocumentedLockOrder
	 *
	 * Mock call order pin: lock room task → lock room → lock completer
	 * house_user → restores → markUndone. Every before-state value is
	 * assigned verbatim (restore, never recalculate).
	 */
	public function testUndo_RestoresRecordedBeforeState_InDocumentedLockOrder(): void {
		$roomTask = $this->buildRoomTask();
		$room = $this->buildRoom(1, 80);
		$actor = $this->buildHouseUser(1, 'alice');
		$this->stubUndoLookups($roomTask, $room, $this->buildTask(), $actor);

		// The locked completer starts from the post-completion state; the
		// restore must overwrite it with the recorded before-state values.
		$completer = $this->buildHouseUser(1, 'alice');
		$completer->setCoins(60);
		$completer->setContributionScore(7);
		$completer->setCurrentStreak(4);
		$completer->setLongestStreak(9);
		$completer->setStreakFreezes(1);
		$completer->setUnlockedTitles(['pack.a', 'pack.b']);

		$this->houseUserMapper->method('find')->willReturn($completer);
		$this->houseUserMapper->method('findForUpdate')->willReturn($completer);

		$before = $this->buildBeforeState();
		$before['houseUser']['unlockedTitles'] = ['pack.a'];
		$ledger = $this->buildLedgerRow($before, [
			'coinsAwarded' => 50,
			'coins' => 50,
			'teamworkBonus' => 0,
			'contributionAwarded' => 3,
			'streakBefore' => 3,
			'streakAfter' => 4,
			'freezeUsed' => false,
			'freezeAwarded' => false,
			'titlesAdded' => ['pack.b'],
			'cleanlinessChange' => 30,
			'takeoverClaimed' => false,
		]);
		$this->completedTaskMapper->method('findNewestRevertibleByRoomTask')->willReturn($ledger);

		$callOrder = [];
		$this->houseMapper->method('findForUpdate')->willReturnCallback(function () use (&$callOrder) {
			$callOrder[] = 'lock_house';
			return new House();
		});
		$this->roomTaskMapper->method('lockForUpdate')->willReturnCallback(function () use (&$callOrder, $roomTask) {
			$callOrder[] = 'lock_room_task';
			return $roomTask;
		});
		$this->roomMapper->method('lockForUpdate')->willReturnCallback(function () use (&$callOrder, $room) {
			$callOrder[] = 'lock_room';
			return $room;
		});
		$this->houseUserMapper->method('findForUpdate')->willReturnCallback(function () use (&$callOrder, $completer) {
			$callOrder[] = 'lock_house_user';
			return $completer;
		});

		$this->roomTaskMapper->expects($this->once())->method('update')->willReturnCallback(function (RoomTask $rt) use (&$callOrder) {
			$callOrder[] = 'restore_room_task';
			return $rt;
		});
		$this->roomMapper->expects($this->once())->method('update')->willReturnCallback(function (Room $r) use (&$callOrder) {
			$callOrder[] = 'restore_room';
			return $r;
		});
		$this->houseUserMapper->expects($this->once())->method('update')->willReturnCallback(function (HouseUser $u) use (&$callOrder) {
			$callOrder[] = 'restore_house_user';
			return $u;
		});
		$this->checklistProgressMapper->expects($this->once())->method('deleteByRoomTaskId')->with(1);
		$this->checklistProgressMapper->expects($this->once())->method('restoreProgress')
			->with(1, $this->callback(function (array $rows) {
				return $rows === [[
					'checklistItemId' => 5,
					'userId' => 2,
					'checked' => true,
					'checkedAt' => (new \DateTime('2026-09-05T09:00:00'))->format('c'),
				]];
			}));
		$this->completedTaskMapper->expects($this->once())->method('markUndone')->willReturnCallback(function () use (&$callOrder) {
			$callOrder[] = 'mark_undone';
		});

		$this->db->expects($this->once())->method('commit');

		$result = $this->service->undo(1, $actor, 1);

		self::assertSame([
			'lock_house', 'lock_room_task', 'lock_room', 'lock_house_user',
			'restore_room_task', 'restore_room', 'restore_house_user',
			'mark_undone',
		], $callOrder, 'restore must follow the documented house-first lock and write order');

		// Room task before-state restored verbatim.
		self::assertSame('2026-09-06T00:00:00+00:00', $roomTask->getNextDue()->format('c'));
		self::assertTrue($roomTask->getIsActive());
		self::assertNull($roomTask->getLastCompleted());
		self::assertFalse($roomTask->getWasClaimed());

		// Cleanliness restored to the pre-completion value.
		self::assertSame(40, $room->getCleanliness());
		self::assertEquals(
			new \DateTime('2026-09-04T18:00:00+00:00'),
			$room->getLastCleaned(),
			'lastCleaned (drives the room display) must be rewinded too'
		);

		// Reward fields restored to the recorded before-state.
		self::assertSame(10, $completer->getCoins());
		self::assertSame(4, $completer->getContributionScore());
		self::assertSame(3, $completer->getCurrentStreak());
		self::assertSame(8, $completer->getLongestStreak());
		self::assertSame(2, $completer->getStreakFreezes());
		self::assertEquals(
			new \DateTime('2026-09-05T07:30:00+00:00'),
			$completer->getLastActivity(),
			'lastActivity drives the streak-absence calculation and must be rewinded'
		);
		// titlesAdded removal only for keys still present: pack.b (unlocked
		// by a LATER completion) survives; pack.a (the before-state) stays.
		self::assertSame(['pack.a'], $completer->getUnlockedTitles());

		self::assertTrue($result['undone']);
		self::assertSame(-50, $result['rewards']['coins']);
		self::assertSame(-3, $result['rewards']['contributionScore']);
		self::assertSame(-30, $result['rewards']['cleanlinessChange']);
		self::assertSame(10, $result['rewards']['totalCoins']);
		// CS-PLAN-066 §3.4: undo rewards never carry a newTitle (ADR-011
		// title policy — undo emits no unlock event).
		self::assertArrayNotHasKey('newTitle', $result['rewards']);
	}

	/**
	 * Undo_ConcurrentWinnerMakesThisTheNoOp
	 *
	 * The under-lock revertible re-check: a concurrent undo winner marks the
	 * row undone first; this invocation writes nothing (the read-only
	 * transaction commits zero writes under the run() retry contract) and
	 * answers the idempotent `undone: false`.
	 */
	public function testUndo_ConcurrentWinner_MakesThisTheNoOp(): void {
		$roomTask = $this->buildRoomTask();
		$room = $this->buildRoom();
		$actor = $this->buildHouseUser(1, 'alice');
		$this->stubUndoLookups($roomTask, $room, $this->buildTask(), $actor);

		$completer = $this->buildHouseUser(1, 'alice');
		$this->houseUserMapper->method('find')->willReturn($completer);

		$ledger = $this->buildLedgerRow($this->buildBeforeState(), []);
		// First probe: revertible. Under-lock re-check: gone (winner won).
		$this->completedTaskMapper->method('findNewestRevertibleByRoomTask')
			->willReturnOnConsecutiveCalls($ledger, null);

		$this->roomTaskMapper->expects($this->never())->method('update');
		$this->completedTaskMapper->expects($this->never())->method('markUndone');
		$this->db->expects($this->once())->method('commit');
		$this->db->expects($this->never())->method('rollBack');

		$result = $this->service->undo(1, $actor, 1);
		self::assertFalse($result['undone']);
	}

	/**
	 * Undo_MapperFailure_RollsBackAndRethrows
	 */
	public function testUndo_MapperFailure_RollsBackAndRethrows(): void {
		$roomTask = $this->buildRoomTask();
		$room = $this->buildRoom();
		$actor = $this->buildHouseUser(1, 'alice');
		$this->stubUndoLookups($roomTask, $room, $this->buildTask(), $actor);

		$completer = $this->buildHouseUser(1, 'alice');
		$this->houseUserMapper->method('find')->willReturn($completer);
		$this->houseUserMapper->method('findForUpdate')->willReturn($completer);

		$ledger = $this->buildLedgerRow($this->buildBeforeState(), []);
		$this->completedTaskMapper->method('findNewestRevertibleByRoomTask')->willReturn($ledger);

		$this->completedTaskMapper->method('markUndone')
			->willThrowException(new \RuntimeException('db down'));

		$this->db->expects($this->once())->method('rollBack');
		$this->db->expects($this->never())->method('commit');
		$this->appLogger->expects($this->once())->method('logException');

		$this->expectException(\RuntimeException::class);
		$this->service->undo(1, $actor, 1);
	}

	/**
	 * Undo_CompleterMembershipDeleted_CleanForbidden
	 *
	 * RF-05: the ledger row records an internal house_user id; when that
	 * membership was deleted after the completion, the actor-gate resolution
	 * must answer a clean undo-worded 403 — never a DoesNotExistException 500.
	 */
	public function testUndo_CompleterMembershipDeleted_CleanForbidden(): void {
		$roomTask = $this->buildRoomTask();
		$room = $this->buildRoom();
		$actor = $this->buildHouseUser(2, 'owner-user');
		$actor->setRole('owner');
		$this->stubUndoLookups($roomTask, $room, $this->buildTask(), $actor);

		$this->houseUserMapper->method('find')
			->willThrowException(new DoesNotExistException('completer gone'));
		$this->completedTaskMapper->method('findNewestRevertibleByRoomTask')
			->willReturn($this->buildLedgerRow($this->buildBeforeState(), []));

		// No transaction may open, and nothing may be restored.
		$this->db->expects($this->never())->method('beginTransaction');
		$this->roomTaskMapper->expects($this->never())->method('update');
		$this->roomMapper->expects($this->never())->method('update');
		$this->houseUserMapper->expects($this->never())->method('update');
		$this->completedTaskMapper->expects($this->never())->method('markUndone');

		$this->expectException(ForbiddenException::class);
		$this->expectExceptionMessage('The completer is no longer a member of this house');

		$this->service->undo(1, $actor, 1);
	}

	/**
	 * Undo_ErasedCompleter_CleanForbidden_NeverATypeError
	 *
	 * Q-70-02 (CS-PLAN-071): erasure nulls the ledger row's user_id —
	 * the actor gate must answer the clean 403 instead of feeding null
	 * into the int-typed mapper (TypeError 500, review finding).
	 */
	public function testUndo_ErasedCompleter_CleanForbidden_NeverATypeError(): void {
		$roomTask = $this->buildRoomTask();
		$room = $this->buildRoom();
		$actor = $this->buildHouseUser(2, 'owner-user');
		$actor->setRole('owner');
		$this->stubUndoLookups($roomTask, $room, $this->buildTask(), $actor);

		$ledger = $this->buildLedgerRow($this->buildBeforeState(), []);
		$ledger->setUserId(null);
		$this->completedTaskMapper->method('findNewestRevertibleByRoomTask')->willReturn($ledger);

		// No transaction may open, and the mapper must never see a null id.
		$this->db->expects($this->never())->method('beginTransaction');
		$this->houseUserMapper->expects($this->never())->method('find');
		$this->houseUserMapper->expects($this->never())->method('findForUpdate');

		$this->expectException(ForbiddenException::class);
		$this->expectExceptionMessage('The completer is no longer a member of this house');

		$this->service->undo(1, $actor, 1);
	}

	/**
	 * Undo_CompleterRowVanishesUnderLock_AnswersUndoWording
	 *
	 * RF-05: when the completer's row vanishes between the actor gate and
	 * the third lock, the undo context must not reuse the completion-worded
	 * 403 ("Only house members can complete tasks").
	 */
	public function testUndo_CompleterRowVanishesUnderLock_AnswersUndoWording(): void {
		$roomTask = $this->buildRoomTask();
		$room = $this->buildRoom();
		$actor = $this->buildHouseUser(1, 'alice');
		$this->stubUndoLookups($roomTask, $room, $this->buildTask(), $actor);

		$completer = $this->buildHouseUser(1, 'alice');
		$this->houseUserMapper->method('find')->willReturn($completer);
		$this->houseUserMapper->method('findForUpdate')
			->willThrowException(new DoesNotExistException('completer gone mid-undo'));

		$ledger = $this->buildLedgerRow($this->buildBeforeState(), []);
		$this->completedTaskMapper->method('findNewestRevertibleByRoomTask')->willReturn($ledger);

		$this->db->expects($this->once())->method('rollBack');
		$this->db->expects($this->never())->method('commit');
		$this->completedTaskMapper->expects($this->never())->method('markUndone');

		$this->expectException(ForbiddenException::class);
		$this->expectExceptionMessage('The completer is no longer a member of this house');

		$this->service->undo(1, $actor, 1);
	}

	/**
	 * UndoThenSameDayRecomplete_Succeeds_WithFreshLedgerRow
	 *
	 * The service-level roundtrip (§4, D11): complete → undo → complete
	 * again on the same day must succeed and insert a SECOND, distinct
	 * ledger row — no service-side state (e.g. the reused entity) blocks
	 * the re-completion; the undone row stays out of the duplicate guard.
	 */
	public function testUndoThenSameDayRecomplete_Succeeds_WithFreshLedgerRow(): void {
		$roomTask = $this->buildRoomTask();
		$room = $this->buildRoom();
		$task = $this->buildTask();
		$houseUser = $this->buildHouseUser();
		$this->stubCompleteLookups($roomTask, $room, $task, $houseUser);

		$insertedRows = [];
		$this->completedTaskMapper->expects($this->exactly(2))
			->method('insert')
			->willReturnCallback(function (CompletedTask $row) use (&$insertedRows) {
				$row->setId(100 + count($insertedRows));
				$insertedRows[] = $row;
				return $row;
			});
		// The mocked reward phase must fill the ledger row's identity fields
		// exactly like the real GameLogicService contract does — the undo
		// actor gate resolves the completer through them.
		$this->houseUserMapper->method('find')->willReturn($houseUser);
		$this->gameLogicService->method('handleTaskCompletion')->willReturnCallback(
			function (RoomTask $rt, int $userId, Task $t, $tz, array $assignees, ?CompletedTask $row) {
				$row->setRoomTaskId($rt->getId());
				$row->setUserId($userId);
				$row->setCompletedAt(new \DateTime());
				$row->setCoinsEarned(50);
				$row->setWasCombo(false);
				return [
					'contributionScore' => 3, 'coins' => 50, 'teamworkBonus' => 0, 'isCombo' => false,
					'currentStreak' => 1, 'longestStreak' => 1, 'totalContribution' => 3, 'totalCoins' => 50,
					'streakFreezeUsed' => false, 'streakFreezesRemaining' => 1, 'comboBonusFreeze' => false,
					'titlesAdded' => [], 'newTitle' => null,
				];
			}
		);
		// The mapper's D11 filter is what keeps the undone row out of the
		// duplicate guard — the count reads 0 for the second completion.
		$this->completedTaskMapper->method('countByRoomTaskAndUserOnDate')->willReturn(0);

		// The revertible lookup: the first completion's row once inserted,
		// NULL after the undo marked it (the re-complete then starts clean).
		$undoneRowIds = [];
		$this->completedTaskMapper->method('findNewestRevertibleByRoomTask')
			->willReturnCallback(function (int $roomTaskId) use (&$insertedRows, &$undoneRowIds) {
				foreach ($insertedRows as $row) {
					if (!in_array($row->getId(), $undoneRowIds, true)) {
						return $row;
					}
				}
				return null;
			});
		$this->completedTaskMapper->method('markUndone')
			->willReturnCallback(function (int $id) use (&$undoneRowIds) {
				$undoneRowIds[] = $id;
			});

		$first = $this->service->complete(1, 'user123', 1);
		$undone = $this->service->undo(1, $houseUser, 1);
		$second = $this->service->complete(1, 'user123', 1);

		self::assertTrue($undone['undone']);
		self::assertCount(2, $insertedRows, 'the re-completion must write its own fresh ledger row');
		self::assertNotSame($insertedRows[0], $insertedRows[1]);
		self::assertSame($first['rewards']['totalCoins'], $second['rewards']['totalCoins']);
	}
}
