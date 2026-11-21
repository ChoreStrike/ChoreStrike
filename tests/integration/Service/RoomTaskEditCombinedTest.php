<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Service;

use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Service\ChecklistService;
use OCA\ChoreStrike\Service\TaskCreationService;
use OCA\ChoreStrike\Service\TaskEdit;
use OCA\ChoreStrike\Service\WindowService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;
use OCA\ChoreStrike\Tests\Integration\Support\CycleFixtures;
use OCA\ChoreStrike\Tests\Integration\Support\EditFixtures;
use OCA\ChoreStrike\Tests\Integration\Support\TaskEditing;

/**
 * Saves of the one form that change several things at once (CS-PLAN-091
 * WP-B6, D-091-92): the window changes once — a first date wins over a
 * rule, a rule over the assignees — waking comes before that change and
 * going to sleep after it; a split carries a move, new assignees, a
 * sleeping and a bonus task. And what a save sends again without choosing
 * it anew: the stored first date and the stored wake date.
 */
class RoomTaskEditCombinedTest extends IntegrationTestCase {
	use CycleFixtures;
	use EditFixtures;
	use TaskEditing;

	protected const OWNER = 'rtc_owner';
	protected const HELPER = 'rtc_helper';

	protected function setUp(): void {
		parent::setUp();
		$this->initToday();
	}

	private function version(int $roomTaskId): int {
		return (int)$this->roomTaskRow($roomTaskId)['cycle_version'];
	}

	private function anchor(int $roomTaskId): string {
		return substr((string)$this->roomTaskRow($roomTaskId)['anchor_on'], 0, 10);
	}

	// ------------------------------------------------------------------
	// One window change per save
	// ------------------------------------------------------------------

	public function testFirstDateWithARuleChange_TheWindowLiesAroundTheDate_WithTheNewFlex(): void {
		$f = $this->fixture(['frequency_type' => 'weekly']);
		$version = $this->version($f['roomTaskId']);

		$this->saveForm($f['houseId'], $this->owner($f), $f['roomTaskId'], ['firstDate' => $this->day(4), 'task' => ['flexDays' => 2]]);

		$block = $this->block($f);
		self::assertSame([$this->day(4), $this->day(2), $this->day(6)], [$block['centreOn'], $block['windowStartOn'], $block['windowEndOn']]);
		self::assertSame($version + 1, $this->version($f['roomTaskId']), 'one change of the window, one version');
	}

	public function testRuleChangeWithNewAssignees_BothHold_InOneVersion(): void {
		$f = $this->fixture(['frequency_type' => 'weekly']);
		$version = $this->version($f['roomTaskId']);

		$answer = $this->saveForm($f['houseId'], $this->owner($f), $f['roomTaskId'], [
			'task' => ['flexDays' => 1], 'assigneeUserIds' => [self::OWNER, self::HELPER],
		]);

		self::assertEqualsCanonicalizing([self::OWNER, self::HELPER], $this->assigneesOf($f['roomTaskId']));
		self::assertSame([$this->day(-1), $this->day(1)], [$answer['cycle']['windowStartOn'], $answer['cycle']['windowEndOn']]);
		self::assertSame($version + 1, $this->version($f['roomTaskId']));
	}

	public function testWakingWithAFirstDate_TheTaskWakes_AndTheDateHolds(): void {
		$f = $this->fixture();
		$this->saveForm($f['houseId'], $this->owner($f), $f['roomTaskId'], ['sleeping' => true]);

		$this->saveForm($f['houseId'], $this->owner($f), $f['roomTaskId'], ['sleeping' => false, 'firstDate' => $this->day(5)]);

		self::assertNull($this->roomTaskRow($f['roomTaskId'])['sleeping_since']);
		self::assertSame($this->day(5), $this->anchor($f['roomTaskId']), 'the wake did not overwrite the chosen date');
		self::assertSame(['d' . $this->day(5), $this->day(5)], [$this->block($f)['cycleKey'], $this->block($f)['centreOn']]);
	}

	public function testWakingWithTheStoredFirstDate_StartsOnTheWakeDay(): void {
		$f = $this->fixture([], $this->day(-20));
		$this->saveForm($f['houseId'], $this->owner($f), $f['roomTaskId'], ['sleeping' => true]);

		// The form sends the first date it showed; it is no new choice.
		$this->saveForm($f['houseId'], $this->owner($f), $f['roomTaskId'], ['sleeping' => false, 'firstDate' => $this->day(-20)]);

		self::assertSame($this->today, $this->anchor($f['roomTaskId']), 'a woken task starts on its wake day');
		self::assertSame($this->today, $this->block($f)['centreOn']);
	}

	// ------------------------------------------------------------------
	// What the form sends again is no new choice
	// ------------------------------------------------------------------

	public function testFirstDateOfLongAgo_StaysThroughOtherSaves_AndOnlyANewOneIsHeldToItsRange(): void {
		$f = $this->fixture();
		$this->setFirstDate($f['roomTaskId'], $this->day(-400));

		$this->saveForm($f['houseId'], $this->owner($f), $f['roomTaskId'], ['firstDate' => $this->day(-400), 'task' => ['name' => 'Renamed']]);
		self::assertSame(['Renamed', $this->day(-400)], [$this->taskRow($f['taskId'])['name'], $this->anchor($f['roomTaskId'])]);

		try {
			$this->saveForm($f['houseId'], $this->owner($f), $f['roomTaskId'], ['firstDate' => $this->day(-401), 'task' => ['name' => 'Never saved']]);
			self::fail('a new first date of more than a year ago is refused');
		} catch (\InvalidArgumentException $e) {
			self::assertSame(['Renamed', $this->day(-400)], [$this->taskRow($f['taskId'])['name'], $this->anchor($f['roomTaskId'])]);
		}

		$this->saveForm($f['houseId'], $this->owner($f), $f['roomTaskId'], ['firstDate' => $this->day(2)]);
		self::assertSame($this->day(2), $this->anchor($f['roomTaskId']));
	}

	public function testCreation_HoldsItsFirstDateToTheRange(): void {
		$f = $this->fixture();

		$this->expectException(\InvalidArgumentException::class);
		\OC::$server->get(TaskCreationService::class)->create($f['houseId'], $this->owner($f), [
			'roomId' => $f['roomId'], 'task' => ['name' => 'Windows'], 'firstDate' => $this->day(-400),
		]);
	}

	public function testStoredWakeDateOfToday_IsNoNewChoice_SoTheSaveGoesThrough(): void {
		$f = $this->fixture();
		$this->saveForm($f['houseId'], $this->owner($f), $f['roomTaskId'], ['sleeping' => true, 'wakeOn' => $this->day(10)]);
		// The wake day has come and the job has not run yet.
		$this->setColumn('chorestrike_room_tasks', $f['roomTaskId'], 'wake_on', $this->today);
		$since = $this->roomTaskRow($f['roomTaskId'])['sleeping_since'];

		$this->saveForm($f['houseId'], $this->owner($f), $f['roomTaskId'], [
			'sleeping' => true, 'wakeOn' => $this->today, 'task' => ['name' => 'Renamed'],
		]);

		self::assertSame('Renamed', $this->taskRow($f['taskId'])['name']);
		self::assertSame($since, $this->roomTaskRow($f['roomTaskId'])['sleeping_since'], 'still asleep, as it was');
	}

	// ------------------------------------------------------------------
	// A split that does more
	// ------------------------------------------------------------------

	public function testSplitWithAMove_TheCopyLivesInTheNewRoom(): void {
		$f = $this->fixture();
		$other = $this->shareWithASecondRoom($f);
		$third = $this->anotherRoom($f, 'Pantry');

		$this->saveForm($f['houseId'], $this->owner($f), $f['roomTaskId'], [
			'task' => ['name' => 'Wash up'], 'roomId' => $third, 'scope' => TaskEdit::SCOPE_THIS_ROOM,
		]);

		$row = $this->roomTaskRow($f['roomTaskId']);
		self::assertSame($third, (int)$row['room_id']);
		self::assertNotSame($f['taskId'], (int)$row['task_id']);
		self::assertSame(['Wash up', 'Dishes'], [$this->taskRow((int)$row['task_id'])['name'], $this->taskRow($f['taskId'])['name']]);
		self::assertSame($f['taskId'], $this->taskIdOf($other));
	}

	public function testSplitWithNewAssignees_TheVersionMoves_AndTheOtherRoomStays(): void {
		$f = $this->fixture();
		$other = $this->shareWithASecondRoom($f);
		$versions = [$this->version($f['roomTaskId']), $this->version($other)];

		$this->saveForm($f['houseId'], $this->owner($f), $f['roomTaskId'], [
			'task' => ['name' => 'Wash up'], 'assigneeUserIds' => [self::HELPER], 'scope' => TaskEdit::SCOPE_THIS_ROOM,
		]);

		self::assertNotSame($f['taskId'], $this->taskIdOf($f['roomTaskId']));
		self::assertSame([self::HELPER], $this->assigneesOf($f['roomTaskId']));
		self::assertSame([$versions[0] + 1, $versions[1]], [$this->version($f['roomTaskId']), $this->version($other)]);
		self::assertSame([self::OWNER], $this->assigneesOf($other));
	}

	public function testSplitOfASleepingTask_StaysAsleep_OnItsOwnDefinition(): void {
		$f = $this->fixture();
		$this->createChecklistItem($f['taskId'], 'Plates', 0);
		$other = $this->shareWithASecondRoom($f);
		$this->saveForm($f['houseId'], $this->owner($f), $f['roomTaskId'], ['sleeping' => true, 'wakeOn' => $this->day(10)]);

		$this->saveForm($f['houseId'], $this->owner($f), $f['roomTaskId'], [
			'task' => ['name' => 'Wash up'], 'sleeping' => true, 'wakeOn' => $this->day(10), 'scope' => TaskEdit::SCOPE_THIS_ROOM,
		]);

		$copyId = $this->taskIdOf($f['roomTaskId']);
		self::assertNotSame($f['taskId'], $copyId);
		self::assertSame(['Plates'], $this->labels($copyId), 'the copy has the sub-tasks of its own');
		self::assertSame(WindowService::STATE_ASLEEP, $this->block($f)['state']);
		self::assertNull($this->roomTaskRow($other)['sleeping_since'], 'the other room is awake as before');
	}

	public function testSplitOfABonusTask_StaysABonusTask_AndCarriesNoTicks(): void {
		$f = $this->fixture(['is_voluntary' => 1]);
		$item = $this->createChecklistItem($f['taskId'], 'Plates', 0);
		$other = $this->shareWithASecondRoom($f);

		$this->saveForm($f['houseId'], $this->owner($f), $f['roomTaskId'], [
			'task' => ['name' => 'Extra dishes'],
			'checklistItems' => [['id' => $item, 'label' => 'Plates'], ['label' => 'Pots']],
			'scope' => TaskEdit::SCOPE_THIS_ROOM,
		]);

		$copy = \OC::$server->get(TaskMapper::class)->find($this->taskIdOf($f['roomTaskId']));
		self::assertNotSame($f['taskId'], (int)$copy->getId());
		self::assertTrue((bool)$copy->getIsVoluntary());
		self::assertSame(['Plates', 'Pots'], $this->labels((int)$copy->getId()));
		self::assertSame(['Plates'], $this->labels($f['taskId']), 'the shared definition keeps its list');
		self::assertSame(WindowService::STATE_BONUS, $this->block($f)['state']);
		$progress = \OC::$server->get(ChecklistService::class)->getRoomTaskProgress($f['roomTaskId'], null, $f['houseId'], $this->owner($f));
		self::assertSame([2, 0], [$progress['stats']['total'], $progress['stats']['checked']]);
		self::assertSame($f['taskId'], $this->taskIdOf($other));
	}

	// ------------------------------------------------------------------
	// Creation and edit store the same (D-091-100)
	// ------------------------------------------------------------------

	public function testCreation_StoresAnEmptyTextAsNoText_LikeAnEdit(): void {
		$f = $this->fixture();

		$created = \OC::$server->get(TaskCreationService::class)->create($f['houseId'], $this->owner($f), [
			'roomId' => $f['roomId'],
			'task' => ['name' => 'Windows', 'description' => '', 'instructions' => '', 'tips' => ''],
		]);
		$this->registerCreatedTask((int)$created['task']->getId());

		$row = $this->taskRow((int)$created['task']->getId());
		self::assertSame([null, null, null], [$row['description'], $row['instructions'], $row['tips']]);
	}
}
