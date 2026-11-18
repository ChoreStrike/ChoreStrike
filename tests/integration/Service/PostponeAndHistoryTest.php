<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Service;

use OCA\ChoreStrike\Db\CompletedTask;
use OCA\ChoreStrike\Db\CycleMapper;
use OCA\ChoreStrike\Exception\ConflictException;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Exception\NotFoundException;
use OCA\ChoreStrike\Service\ActivityEventService;
use OCA\ChoreStrike\Service\ActivityService;
use OCA\ChoreStrike\Service\ChecklistService;
use OCA\ChoreStrike\Service\CompletionService;
use OCA\ChoreStrike\Service\PauseService;
use OCA\ChoreStrike\Service\PostponeService;
use OCA\ChoreStrike\Service\RhythmService;
use OCA\ChoreStrike\Service\WeeklyGoalService;
use OCA\ChoreStrike\Service\WindowService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;
use OCA\ChoreStrike\Tests\Integration\Support\CycleFixtures;

/**
 * Postponing and correcting the history against the real database
 * (CS-PLAN-091 WP-B3, ADR-021 decisions 6 and 9): the limits and rights of
 * the postpone command, a date correction with everything that hangs on
 * the corrected day, the narrowed rights of members, and the recent
 * activity that shows all of it.
 */
class PostponeAndHistoryTest extends IntegrationTestCase {
	use CycleFixtures;

	protected const OWNER = 'pah_owner';
	protected const HELPER = 'pah_helper';
	private const KID = 'pah_kid';

	private CompletionService $completionService;
	private PostponeService $postponeService;

	protected function setUp(): void {
		parent::setUp();
		$this->completionService = \OC::$server->get(CompletionService::class);
		$this->postponeService = \OC::$server->get(PostponeService::class);
		$this->initToday();
	}

	/** Postpone the cycle the row shows, as the given member. */
	private function postpone(array $f, string $userId, ?string $commandId = null, ?int $version = null): array {
		$block = $this->block($f);
		return $this->postponeService->postpone(
			$f['houseId'], $this->member($f['houseId'], $userId), $f['roomTaskId'],
			$commandId ?? $this->newCommandId(), $block['cycleKey'], $version ?? $block['expectedVersion']
		);
	}

	private function redate(array $f, string $userId, int $completionId, string $day): array {
		$version = (int)$this->rows('chorestrike_completed_tasks', 'id', $completionId)[0]['completion_version'];
		return \OC::$server->get(\OCA\ChoreStrike\Service\CompletionDateCommand::class)->changeDate($f['houseId'], $this->member($f['houseId'], $userId), $completionId, $this->newCommandId(), $version, $day);
	}

	private function complete(array $f, string $userId): int {
		return (int)$this->completeOccurrence($this->completionService, $f['houseId'], $userId, $f['roomTaskId'])['completionId'];
	}

	/** @return array<string, mixed> */
	private function entry(int $completionId): array {
		return $this->rows('chorestrike_completed_tasks', 'id', $completionId)[0];
	}

	private function reasonOf(callable $command): string {
		try {
			$command();
		} catch (ConflictException $e) {
			return $e->getReason();
		}
		self::fail('the command must be refused');
	}

	/** @return list<array<string, mixed>> */
	private function events(array $f, string $viewer): array {
		return \OC::$server->get(ActivityService::class)->getActivity($f['houseId'], 30, 50, null, $this->member($f['houseId'], $viewer))['events'];
	}

	// ------------------------------------------------------------------
	// Postpone
	// ------------------------------------------------------------------

	public function testPostpone_MovesTheWindowByHalfTheFlexDays_UpToThreeTimes(): void {
		$f = $this->fixture(['frequency_type' => 'weekly', 'flex_days' => 3]);
		$this->addHouseMember($f['houseId'], self::KID, 'Kid', 'child');
		$first = $this->block($f);
		self::assertSame([$this->day(-3), $this->day(3), true], [$first['windowStartOn'], $first['windowEndOn'], $first['canPostpone']]);

		// Half of three flex days, rounded up: two days per press. A child may press too.
		$result = $this->postpone($f, self::KID);
		$block = $result['cycle'];
		self::assertFalse($result['replay']);
		self::assertSame($first['cycleKey'], $block['cycleKey'], 'the cycle stays the same one');
		self::assertSame([$this->day(-1), $this->day(5), $this->day(2), 1], [$block['windowStartOn'], $block['windowEndOn'], $block['centreOn'], $block['postponeCount']]);
		self::assertGreaterThan($first['expectedVersion'], $block['expectedVersion']);
		self::assertSame($this->day(5), substr((string)$this->rows('chorestrike_room_tasks', 'id', $f['roomTaskId'])[0]['next_due'], 0, 10), 'the projection follows in the same transaction');

		$this->postpone($f, self::HELPER);
		$third = $this->postpone($f, self::OWNER)['cycle'];
		self::assertSame([$this->day(3), $this->day(9), 3, false], [$third['windowStartOn'], $third['windowEndOn'], $third['postponeCount'], $third['canPostpone']]);
		self::assertSame(WindowService::STATE_UPCOMING, $third['state']);

		self::assertSame(ConflictException::REASON_NOT_POSTPONABLE, $this->reasonOf(fn () => $this->postpone($f, self::OWNER)));
		self::assertSame(3, (int)$this->rows('chorestrike_room_tasks', 'id', $f['roomTaskId'])[0]['postpone_count'], 'the refusal writes nothing');
	}

	public function testPostpone_WithoutFlexDays_OrOnARedTask_IsRefused(): void {
		$rigid = $this->fixture();
		self::assertFalse($this->block($rigid)['canPostpone']);
		self::assertSame(ConflictException::REASON_NOT_POSTPONABLE, $this->reasonOf(fn () => $this->postpone($rigid, self::OWNER)));

		// D-091-21: a miss stays a miss.
		$red = $this->fixture(['frequency_type' => 'weekly', 'flex_days' => 1], $this->day(-5));
		self::assertSame(WindowService::STATE_OVERDUE, $this->block($red)['state']);
		self::assertSame(ConflictException::REASON_WINDOW_ENDED, $this->reasonOf(fn () => $this->postpone($red, self::OWNER)));
	}

	public function testPostpone_StaleTargetConflicts_AndARepeatedCommandReplays(): void {
		$f = $this->fixture(['frequency_type' => 'weekly', 'flex_days' => 2]);
		$shown = $this->block($f);
		$commandId = $this->newCommandId();

		$this->postpone($f, self::HELPER, $commandId);
		// The same command again, as an uncertain client repeats it.
		$replay = $this->postponeService->postpone(
			$f['houseId'], $this->member($f['houseId'], self::HELPER), $f['roomTaskId'], $commandId, $shown['cycleKey'], $shown['expectedVersion']
		);
		self::assertTrue($replay['replay']);
		self::assertSame(1, $replay['cycle']['postponeCount'], 'the replay has no second effect');

		// A second press with the version the row showed before the first one.
		self::assertSame(
			ConflictException::REASON_STALE_TARGET,
			$this->reasonOf(fn () => $this->postpone($f, self::OWNER, null, $shown['expectedVersion']))
		);
	}

	public function testPostponedWindow_IsFrozenOnTheCompletion_AndTheNextCycleStartsFresh(): void {
		$weekday = strtolower((new \DateTime($this->today))->format('l'));
		$f = $this->fixture(['frequency_type' => 'weekly', 'scheduling_mode' => 'static', 'fixed_schedule_days' => json_encode([$weekday]), 'flex_days' => 2]);
		$this->postpone($f, self::HELPER);

		$completionId = $this->complete($f, self::OWNER);

		$entry = $this->entry($completionId);
		self::assertSame([$this->day(-1), $this->day(3), CompletedTask::CLASS_ON_TIME], [substr((string)$entry['window_start_on'], 0, 10), substr((string)$entry['window_end_on'], 0, 10), $entry['completion_class']]);
		$next = $this->block($f);
		// A fixed rule keeps its days: the next window is not moved.
		self::assertSame([$this->day(5), $this->day(9), $this->day(7), 0], [$next['windowStartOn'], $next['windowEndOn'], $next['centreOn'], $next['postponeCount']]);
		// The postponement stays stored with the key of the closed cycle.
		$stored = $this->rows('chorestrike_room_tasks', 'id', $f['roomTaskId'])[0];
		self::assertSame([$entry['cycle_key'], 1], [$stored['cycle_key'], (int)$stored['postpone_count']]);
	}

	public function testAfterCompletion_PostponedThenDone_TheNextWindowHangsOnTheCompletion(): void {
		$f = $this->fixture(['frequency_type' => 'weekly', 'flex_days' => 2]);
		$this->postpone($f, self::HELPER);

		$completionId = $this->complete($f, self::OWNER);

		$next = $this->block($f);
		self::assertSame(
			['c' . $completionId, $this->day(5), $this->day(9), 0, true],
			[$next['cycleKey'], $next['windowStartOn'], $next['windowEndOn'], $next['postponeCount'], $next['canPostpone']],
			'counted from the day of the work, not from the postponed window'
		);
	}

	/** D-091-57: the count belongs to the cycle key, also across a completion and its undo. */
	public function testUndo_ReopensThePostponedCycleAsItWas(): void {
		$f = $this->fixture(['frequency_type' => 'weekly', 'flex_days' => 2], $this->day(-1));
		$shown = $this->block($f);
		$this->postpone($f, self::HELPER);
		$postponed = $this->postpone($f, self::HELPER)['cycle'];
		self::assertSame([$this->day(-1), $this->day(3), 2], [$postponed['windowStartOn'], $postponed['windowEndOn'], $postponed['postponeCount']]);

		$completionId = $this->complete($f, self::OWNER);
		$this->completionService->undo($f['houseId'], $this->member($f['houseId'], self::OWNER), $completionId, $this->newCommandId(), 0, null);

		$reopened = $this->block($f);
		self::assertSame(
			[$shown['cycleKey'], $this->day(-1), $this->day(3), 2, true],
			[$reopened['cycleKey'], $reopened['windowStartOn'], $reopened['windowEndOn'], $reopened['postponeCount'], $reopened['canPostpone']]
		);
		self::assertNotSame(WindowService::STATE_OVERDUE, $reopened['state']);
		self::assertSame(3, $this->postpone($f, self::HELPER)['cycle']['postponeCount'], 'the limit counts the presses before the undo');
		self::assertSame(ConflictException::REASON_NOT_POSTPONABLE, $this->reasonOf(fn () => $this->postpone($f, self::HELPER)));
	}

	/** D-091-60: an undo keeps the sub-task ticks; the next completion marks every sub-task anyway. */
	public function testUndo_KeepsTheSubTaskTicksOfTheCycle(): void {
		$f = $this->fixture(['frequency_type' => 'weekly']);
		$this->createChecklistItem($f['taskId'], 'Plates', 1);
		$this->createChecklistItem($f['taskId'], 'Pots', 2);
		$owner = $this->member($f['houseId'], self::OWNER);
		$progress = fn (): array => \OC::$server->get(ChecklistService::class)->getRoomTaskProgress($f['roomTaskId'], null, $f['houseId'], $owner)['stats'];

		$completionId = $this->complete($f, self::OWNER);
		$this->completionService->undo($f['houseId'], $owner, $completionId, $this->newCommandId(), 0, null);

		self::assertSame([2, 2], [$progress()['total'], $progress()['checked']]);
	}

	public function testPostpone_OfATaskTheMemberMayNotSee_IsNotFound(): void {
		$f = $this->fixture(['frequency_type' => 'weekly', 'flex_days' => 2, 'visibility_type' => 'private', 'owner_user_id' => self::OWNER]);

		$this->expectException(NotFoundException::class);
		$this->postpone($f, self::HELPER);
	}

	// ------------------------------------------------------------------
	// Date correction
	// ------------------------------------------------------------------

	public function testDateCorrection_MovesTheNextWindow_AndDecidesOnTimeAgain(): void {
		$f = $this->fixture(['frequency_type' => 'weekly'], $this->day(-10));
		// The helper records the late work for the assignee.
		$completionId = $this->complete($f, self::HELPER);
		self::assertSame(CompletedTask::CLASS_LATE, $this->entry($completionId)['completion_class']);
		self::assertSame($this->day(7), $this->block($f)['centreOn']);

		// It was done on the planned day; the recorder corrects their own latest entry.
		$result = $this->redate($f, self::HELPER, $completionId, $this->day(-10));

		$entry = $this->entry($completionId);
		self::assertSame(
			[$this->day(-10), $this->day(-10), CompletedTask::CLASS_ON_TIME, 1],
			[substr((string)$entry['work_date'], 0, 10), substr((string)$entry['completed_at'], 0, 10), $entry['completion_class'], (int)$entry['completion_version']]
		);
		self::assertSame(CompletedTask::CLASS_ON_TIME, \OC::$server->get(CycleMapper::class)->findByCompletion($f['roomTaskId'], $completionId)->getCompletionClass());
		self::assertSame(['complete', 'date'], array_column($this->rows('chorestrike_completion_revs', 'completion_id', $completionId), 'action'));
		self::assertSame($this->day(-10), substr((string)$this->rows('chorestrike_room_events', 'source_completion_id', $completionId)[0]['effective_at'], 0, 10), 'the room journal folds the work on the corrected day');

		// The next window hangs on the corrected day: it is red now.
		$next = $this->block($f);
		self::assertSame($next, $result['cycle']);
		self::assertSame(['c' . $completionId, $this->day(-3), WindowService::STATE_OVERDUE], [$next['cycleKey'], $next['centreOn'], $next['state']]);
		self::assertSame($this->day(-3), substr((string)$this->rows('chorestrike_room_tasks', 'id', $f['roomTaskId'])[0]['next_due'], 0, 10));
		// What was paid stays as recorded.
		self::assertSame(2, (int)$entry['total_energy']);
		self::assertCount(1, $this->rows('chorestrike_work_shares', 'completion_id', $completionId));

		try {
			$this->redate($f, self::HELPER, $completionId, $this->day(1));
			self::fail('a day in the future must be refused');
		} catch (\InvalidArgumentException $e) {
			self::assertSame($this->day(-10), substr((string)$this->entry($completionId)['work_date'], 0, 10));
		}
	}

	/** The recognitions read the corrected entry: weekly goal and rhythm follow the new day. */
	public function testDateCorrection_ReDerivesTheWeeklyGoalAndTheRhythm(): void {
		$f = $this->fixture(['frequency_type' => 'weekly'], $this->day(-10));
		$owner = $this->member($f['houseId'], self::OWNER);
		$weekKey = (new \DateTime($this->day(-10)))->modify('monday this week')->format('Y-m-d');
		$house = \OC::$server->get(\OCA\ChoreStrike\Db\HouseMapper::class)->find($f['houseId']);
		$goal = fn (): array => \OC::$server->get(WeeklyGoalService::class)->progressForPlannedWeek($house, $weekKey, new \DateTime());
		$longest = fn (): int => \OC::$server->get(RhythmService::class)->evaluateForMembers($f['houseId'], [$owner], new \DateTime())[(int)$owner->getId()]['longestStreak'];

		$completionId = $this->complete($f, self::OWNER);
		self::assertSame(0, $goal()['completedCount'], 'late work does not count for the week its window ended in');
		self::assertSame(0, $longest());

		$this->redate($f, self::OWNER, $completionId, $this->day(-10));

		self::assertSame(1, $goal()['completedCount'], 'the week of the corrected day counts the work as done on time');
		self::assertSame(1, $longest(), 'the rhythm keeps the day it had broken on');
	}

	public function testDateCorrection_OfAnOlderEntry_MovesTheCycleThatFollowedIt(): void {
		$f = $this->fixture([], $this->day(-6));
		$first = $this->complete($f, self::HELPER);
		$this->redate($f, self::HELPER, $first, $this->day(-4));
		// The next cycle counted from the corrected day and ended three days ago.
		$second = $this->complete($f, self::OWNER);
		$follower = $this->entry($second);
		self::assertSame(['c' . $first, $this->day(-3), CompletedTask::CLASS_LATE], [$follower['cycle_key'], substr((string)$follower['window_end_on'], 0, 10), $follower['completion_class']]);

		// D-091-23: the helper's entry is no longer the latest one of the task.
		foreach ([
			fn () => $this->redate($f, self::HELPER, $first, $this->day(-5)),
			fn () => $this->completionService->undo($f['houseId'], $this->member($f['houseId'], self::HELPER), $first, $this->newCommandId(), 1, null),
		] as $command) {
			try {
				$command();
				self::fail('only the owner corrects an entry that is not the latest');
			} catch (ForbiddenException $e) {
				self::assertSame($this->day(-4), substr((string)$this->entry($first)['work_date'], 0, 10));
			}
		}

		// The owner moves the first entry to yesterday: the cycle that followed ends today.
		$this->redate($f, self::OWNER, $first, $this->day(-1));

		$follower = $this->entry($second);
		self::assertSame(
			[$this->today, $this->today, CompletedTask::CLASS_ON_TIME],
			[substr((string)$follower['window_start_on'], 0, 10), substr((string)$follower['window_end_on'], 0, 10), $follower['completion_class']]
		);
		$closed = \OC::$server->get(CycleMapper::class)->findByCompletion($f['roomTaskId'], $second);
		self::assertSame(
			[$this->today, CompletedTask::CLASS_ON_TIME, (new \DateTime($this->today))->modify('monday this week')->format('Y-m-d')],
			[$closed->getWindowEndOn()->format('Y-m-d'), $closed->getCompletionClass(), $closed->getWeekStart()->format('Y-m-d')]
		);
		self::assertSame($this->day(1), $this->block($f)['centreOn'], 'the current cycle still follows the latest entry');

		// D-091-26: no entry moves past its neighbours.
		foreach ([[$first, $this->today], [$second, $this->day(-1)]] as [$completionId, $day]) {
			try {
				$this->redate($f, self::OWNER, $completionId, $day);
				self::fail('an entry may not take the day of its neighbour');
			} catch (\InvalidArgumentException $e) {
				self::assertNotSame($day, substr((string)$this->entry($completionId)['work_date'], 0, 10));
			}
		}
	}

	/** A bounded rule ends and comes back with the day its last entry is corrected to. */
	public function testDateCorrection_OfABoundedTask_EndsAndReopensIt(): void {
		$f = $this->fixture(['frequency_type' => 'weekly', 'recurrence_mode' => 'bounded', 'active_until' => $this->day(3) . ' 00:00:00'], $this->day(-10));
		$isActive = fn (): int => (int)$this->rows('chorestrike_room_tasks', 'id', $f['roomTaskId'])[0]['is_active'];
		$completionId = $this->complete($f, self::OWNER);
		self::assertSame(0, $isActive(), 'the next cycle would lie after the bound');

		$this->redate($f, self::OWNER, $completionId, $this->day(-5));
		self::assertSame([1, $this->day(2)], [$isActive(), $this->block($f)['centreOn']], 'the cycle after the corrected day lies inside the bound');

		$this->redate($f, self::OWNER, $completionId, $this->day(-1));
		self::assertSame([0, null], [$isActive(), $this->block($f)]);

		$this->redate($f, self::OWNER, $completionId, $this->day(-4));
		self::assertSame([1, $this->day(3)], [$isActive(), $this->block($f)['centreOn']]);
	}

	public function testChild_CorrectsOnlyItsOwnLatestEntry_AndNeverThePeople(): void {
		$f = $this->fixture([], $this->day(-2));
		$this->addHouseMember($f['houseId'], self::KID, 'Kid', 'child');
		$kid = $this->member($f['houseId'], self::KID);
		$completionId = (int)$this->completeOccurrenceWithParticipants(
			$this->completionService, $f['houseId'], self::KID, $f['roomTaskId'], [['userId' => self::KID, 'sharePct' => 100]]
		)['completionId'];

		try {
			$this->completionService->attribute($f['houseId'], $kid, $completionId, $this->newCommandId(), 0, [['userId' => self::KID, 'sharePct' => 100]], true, null);
			self::fail('changing the people is the owner\'s alone');
		} catch (ForbiddenException $e) {
			self::assertSame(0, (int)$this->entry($completionId)['completion_version']);
		}
		try {
			$this->redate($f, self::HELPER, $completionId, $this->day(-1));
			self::fail('nobody but the recorder and the owner re-dates an entry');
		} catch (ForbiddenException $e) {
			self::assertSame(0, (int)$this->entry($completionId)['completion_version']);
		}

		$this->redate($f, self::KID, $completionId, $this->day(-1));
		$undone = $this->completionService->undo($f['houseId'], $kid, $completionId, $this->newCommandId(), 1, null);

		self::assertTrue($undone['undone']);
		self::assertNotNull($this->entry($completionId)['undone_at']);
	}

	// ------------------------------------------------------------------
	// Recent activity
	// ------------------------------------------------------------------

	public function testRecentActivity_ShowsPostponementsDateChangesDeletionsAndAbsences(): void {
		$f = $this->fixture(['frequency_type' => 'weekly', 'flex_days' => 2], $this->day(-1));
		$pauses = \OC::$server->get(PauseService::class);

		$this->postpone($f, self::HELPER);
		$completionId = $this->complete($f, self::HELPER);
		$this->redate($f, self::HELPER, $completionId, $this->day(-1));
		$this->completionService->undo($f['houseId'], $this->member($f['houseId'], self::OWNER), $completionId, $this->newCommandId(), 1, null);
		$absence = $pauses->requestForSelf($f['houseId'], self::HELPER, ['commandId' => $this->newCommandId(), 'startDate' => $this->day(2), 'endDate' => $this->day(4)]);
		$pauses->delete($f['houseId'], self::OWNER, $absence['pauseGroupId'], ['commandId' => $this->newCommandId(), 'expectedRevision' => $absence['revision']]);

		$events = [];
		foreach ($this->events($f, self::OWNER) as $event) {
			$events[$event['type']] = $event;
		}
		self::assertEqualsCanonicalizing(
			[ActivityEventService::TYPE_POSTPONED, ActivityEventService::TYPE_DATE_CHANGED, ActivityEventService::TYPE_DELETED, ActivityEventService::TYPE_ABSENCE, ActivityEventService::TYPE_ABSENCE_REMOVED],
			array_keys($events)
		);
		self::assertSame(['Helper', 'Dishes', 1, $f['roomTaskId']], [$events['postponed']['displayName'], $events['postponed']['taskTitle'], $events['postponed']['postponeCount'], $events['postponed']['roomTaskId']]);
		self::assertSame(['Helper', 'Dishes', $completionId], [$events['date_changed']['displayName'], $events['date_changed']['taskTitle'], $events['date_changed']['completionId']]);
		self::assertSame($completionId, $events['deleted']['completionId']);
		self::assertNotSame('Helper', $events['deleted']['displayName'], 'the owner deleted the entry');
		self::assertSame([self::HELPER, $this->day(2), $this->day(4)], [$events['absence']['subjectUserId'], $events['absence']['from'], $events['absence']['to']]);
		self::assertSame('Helper', $events['absence']['displayName']);
		self::assertSame([self::HELPER, $events['deleted']['displayName']], [$events['absence_removed']['subjectUserId'], $events['absence_removed']['displayName']], 'removed by the owner');

		// The per-member filter keeps the events that member caused.
		$mine = \OC::$server->get(ActivityService::class)->getActivity($f['houseId'], 30, 50, self::HELPER, $this->member($f['houseId'], self::OWNER))['events'];
		self::assertEqualsCanonicalizing(['postponed', 'date_changed', 'absence'], array_column($mine, 'type'));
	}

	public function testRecentActivity_WithholdsTheTitleOfATaskTheViewerMayNotSee(): void {
		$f = $this->fixture(['frequency_type' => 'weekly', 'flex_days' => 2, 'visibility_type' => 'private', 'owner_user_id' => self::OWNER]);
		$this->postpone($f, self::OWNER);

		self::assertSame('Dishes', $this->events($f, self::OWNER)[0]['taskTitle']);
		$seenByHelper = $this->events($f, self::HELPER)[0];
		self::assertSame([ActivityEventService::TYPE_POSTPONED, null], [$seenByHelper['type'], $seenByHelper['taskTitle']], 'the event stays, its task content does not leak');
	}
}
