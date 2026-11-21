<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Service;

use OCA\ChoreStrike\Exception\ConflictException;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Exception\NotFoundException;
use OCA\ChoreStrike\Service\ChecklistService;
use OCA\ChoreStrike\Service\RoomTaskEditService;
use OCA\ChoreStrike\Service\TaskEdit;
use OCA\ChoreStrike\Service\WindowService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;
use OCA\ChoreStrike\Tests\Integration\Support\CycleFixtures;
use OCA\ChoreStrike\Tests\Integration\Support\EditFixtures;
use OCA\ChoreStrike\Tests\Integration\Support\TaskEditing;

/**
 * The one form's save against the real database (CS-PLAN-091 WP-B6,
 * FEAT-UX-040, FEAT-UX-043): one atomic write of the definition, its
 * sub-tasks, the room, the assignees, the first date and the sleep switch;
 * a definition several rooms share changes only with a scope, and *this
 * room* splits off a copy; the rights and the receipt of the command.
 */
class RoomTaskEditTest extends IntegrationTestCase {
	use CycleFixtures;
	use EditFixtures;
	use TaskEditing;

	protected const OWNER = 'rte_owner';
	protected const HELPER = 'rte_helper';
	private const KID = 'rte_kid';

	protected function setUp(): void {
		parent::setUp();
		$this->initToday();
	}

	// ------------------------------------------------------------------
	// One save is one write
	// ------------------------------------------------------------------

	public function testSave_WritesEveryPartTogether(): void {
		$f = $this->fixture();

		$answer = $this->saveForm($f['houseId'], $this->owner($f), $f['roomTaskId'], [
			'task' => ['name' => 'Wash up', 'energyCost' => 3],
			'assigneeUserIds' => [self::OWNER, self::HELPER],
			'checklistItems' => [['label' => 'Plates'], ['label' => 'Pots']],
		]);

		self::assertSame('Wash up', $this->taskRow($f['taskId'])['name']);
		self::assertSame(3, (int)$this->taskRow($f['taskId'])['energy_cost']);
		self::assertEqualsCanonicalizing([self::OWNER, self::HELPER], $answer['assignees']);
		self::assertSame(['Plates', 'Pots'], $this->labels($f['taskId']));
		self::assertSame(['Plates', 'Pots'], array_map(static fn ($item): string => $item->getLabel(), $answer['checklistItems']));
		self::assertFalse($answer['replay']);
		self::assertSame($answer['cycle']['expectedVersion'], (int)$this->roomTaskRow($f['roomTaskId'])['cycle_version'], 'the answer carries the fresh version');
	}

	public function testSave_AMemberWhoLeftUnderTheLock_RollsEverythingBack(): void {
		$f = $this->fixture();
		$before = $this->roomTaskRow($f['roomTaskId']);

		try {
			$this->saveForm($f['houseId'], $this->owner($f), $f['roomTaskId'], [
				'task' => ['name' => 'Never saved', 'flexDays' => 1],
				'checklistItems' => [['label' => 'Never written']],
				'assigneeUserIds' => ['rte_nobody'],
			]);
			self::fail('a non-member assignee must refuse the save');
		} catch (\InvalidArgumentException $e) {
			self::assertStringContainsString('not a member', $e->getMessage());
		}

		self::assertSame('Dishes', $this->taskRow($f['taskId'])['name'], 'the definition stays');
		self::assertSame([], $this->labels($f['taskId']), 'no sub-task stays');
		self::assertSame($before, $this->roomTaskRow($f['roomTaskId']), 'the room task stays as it was');
		self::assertSame([self::OWNER], \OC::$server->get(\OCA\ChoreStrike\Db\TaskAssigneeMapper::class)->findUserIdsByRoomTask($f['roomTaskId']));
	}

	public function testSave_ARepeatedCommandReplays_AndAStaleVersionConflicts(): void {
		$f = $this->fixture();
		$edit = $this->formEdit($f['roomTaskId'], ['task' => ['flexDays' => 1]]);
		$service = \OC::$server->get(RoomTaskEditService::class);

		$first = $service->edit($f['houseId'], $this->owner($f), $f['roomTaskId'], $edit);
		$second = $service->edit($f['houseId'], $this->owner($f), $f['roomTaskId'], $edit);

		self::assertFalse($first['replay']);
		self::assertTrue($second['replay'], 'the same command id replays');
		self::assertSame($first['cycle']['expectedVersion'], $second['cycle']['expectedVersion'], 'the replay changed nothing');

		// The version the first form showed has moved on.
		self::assertSame(ConflictException::REASON_STALE_TARGET, $this->conflictOf(fn () => $service->edit(
			$f['houseId'], $this->owner($f), $f['roomTaskId'],
			TaskEdit::fromPayload(['commandId' => $this->newCommandId(), 'expectedVersion' => $edit->expectedVersion, 'task' => ['flexDays' => 0]],
				\OC::$server->get(\OCA\ChoreStrike\Service\CalendarService::class), \OC::$server->getL10N('chorestrike'))
		)));
		self::assertSame(1, (int)$this->taskRow($f['taskId'])['flex_days']);
	}

	public function testSave_OfAFinishedTask_Conflicts(): void {
		$f = $this->fixture();
		$this->setColumn('chorestrike_room_tasks', $f['roomTaskId'], 'is_active', 0);

		self::assertSame(ConflictException::REASON_CONFLICT, $this->conflictOf(
			fn () => $this->saveForm($f['houseId'], $this->owner($f), $f['roomTaskId'], ['task' => ['name' => 'Too late']])
		));
		self::assertSame('Dishes', $this->taskRow($f['taskId'])['name']);
	}

	// ------------------------------------------------------------------
	// A definition several rooms share (FEAT-UX-043)
	// ------------------------------------------------------------------

	public function testSharedDefinition_WithoutAScope_IsRefused(): void {
		$f = $this->fixture();
		$this->shareWithASecondRoom($f);

		self::assertSame(ConflictException::REASON_SCOPE_REQUIRED, $this->conflictOf(
			fn () => $this->saveForm($f['houseId'], $this->owner($f), $f['roomTaskId'], ['task' => ['name' => 'Wash up']])
		));
		self::assertSame('Dishes', $this->taskRow($f['taskId'])['name']);
	}

	public function testSharedDefinition_ASaveThatChangesNoField_NeedsNoScope(): void {
		$f = $this->fixture();
		$this->shareWithASecondRoom($f);

		$this->saveForm($f['houseId'], $this->owner($f), $f['roomTaskId'], [
			'task' => ['name' => 'Dishes', 'energyCost' => 2],
			'assigneeUserIds' => [self::HELPER],
		]);

		self::assertSame($f['taskId'], (int)$this->roomTaskRow($f['roomTaskId'])['task_id'], 'nothing splits off');
		self::assertSame([self::HELPER], \OC::$server->get(\OCA\ChoreStrike\Db\TaskAssigneeMapper::class)->findUserIdsByRoomTask($f['roomTaskId']));
	}

	/** The form sends empty text fields as ''; a definition without that text changes nothing (no scope asked). */
	public function testSharedDefinition_EmptyTextsOfAMembersOnlySave_NeedNoScope(): void {
		$f = $this->fixture();
		$this->shareWithASecondRoom($f);

		$this->saveForm($f['houseId'], $this->owner($f), $f['roomTaskId'], [
			'task' => ['name' => 'Dishes', 'instructions' => '', 'tips' => ''],
			'assigneeUserIds' => [self::HELPER],
		]);

		self::assertSame($f['taskId'], (int)$this->roomTaskRow($f['roomTaskId'])['task_id'], 'nothing splits off');
		self::assertNull($this->taskRow($f['taskId'])['instructions']);
	}

	public function testSharedDefinition_ThisRoom_SplitsOffACopy_AndKeepsTheHistory(): void {
		$f = $this->fixture();
		$other = $this->shareWithASecondRoom($f);
		$this->createChecklistItem($f['taskId'], 'Plates', 0);
		$completionId = $this->complete($f);
		$completion = $this->rows('chorestrike_completed_tasks', 'id', $completionId);
		$cycles = $this->rows('chorestrike_cycles', 'room_task_id', $f['roomTaskId']);
		$journal = $this->rows('chorestrike_room_events', 'source_completion_id', $completionId);

		$this->saveForm($f['houseId'], $this->owner($f), $f['roomTaskId'], [
			'task' => ['name' => 'Wash up'], 'scope' => TaskEdit::SCOPE_THIS_ROOM,
		]);

		$copyId = (int)$this->roomTaskRow($f['roomTaskId'])['task_id'];
		$this->registerCreatedTask($copyId);
		self::assertNotSame($f['taskId'], $copyId, 'this room task uses a copy now');
		self::assertSame('Wash up', $this->taskRow($copyId)['name']);
		self::assertSame(['Plates'], $this->labels($copyId), 'the copy takes the sub-tasks');
		self::assertSame('Dishes', $this->taskRow($f['taskId'])['name'], 'the other room keeps the definition');
		self::assertSame(['Plates'], $this->labels($f['taskId']));
		self::assertSame($f['taskId'], (int)$this->roomTaskRow($other)['task_id']);
		self::assertSame($completion, $this->rows('chorestrike_completed_tasks', 'id', $completionId), 'the completion stays as recorded');
		self::assertSame($cycles, $this->rows('chorestrike_cycles', 'room_task_id', $f['roomTaskId']), 'the closed cycles stay');
		self::assertSame($journal, $this->rows('chorestrike_room_events', 'source_completion_id', $completionId), 'the journal stays');
	}

	public function testSharedDefinition_ThisRoom_CarriesTheTicksOfTheCurrentCycle(): void {
		$f = $this->fixture();
		$this->shareWithASecondRoom($f);
		$plates = $this->createChecklistItem($f['taskId'], 'Plates', 0);
		$pots = $this->createChecklistItem($f['taskId'], 'Pots', 1);
		$checklists = \OC::$server->get(ChecklistService::class);
		$checklists->toggleItem($f['roomTaskId'], $this->block($f)['cycleKey'], $plates, null, $f['houseId'], $this->owner($f));

		$this->saveForm($f['houseId'], $this->owner($f), $f['roomTaskId'], [
			'checklistItems' => [['id' => $plates, 'label' => 'Plates'], ['id' => $pots, 'label' => 'Pans'], ['label' => 'Cutlery']],
			'scope' => TaskEdit::SCOPE_THIS_ROOM,
		]);

		$copyId = (int)$this->roomTaskRow($f['roomTaskId'])['task_id'];
		$this->registerCreatedTask($copyId);
		self::assertSame(['Plates', 'Pans', 'Cutlery'], $this->labels($copyId));
		self::assertSame(['Plates', 'Pots'], $this->labels($f['taskId']), 'the shared sub-tasks stay');
		$progress = $checklists->getRoomTaskProgress($f['roomTaskId'], null, $f['houseId'], $this->owner($f));
		self::assertSame(['total' => 3, 'checked' => 1], ['total' => $progress['stats']['total'], 'checked' => $progress['stats']['checked']]);
		$copyOfPlates = (int)$this->items($copyId)[0]['id'];
		self::assertSame([$copyOfPlates], array_map(static fn ($row): int => (int)$row->getChecklistItemId(), $progress['items']), 'the tick follows its sub-task');
	}

	public function testSharedDefinition_AllRooms_EditsItWhereItIs_AndMovesEveryRoomsCycle(): void {
		$f = $this->fixture();
		$other = $this->shareWithASecondRoom($f);
		$otherVersion = (int)$this->roomTaskRow($other)['cycle_version'];

		$this->saveForm($f['houseId'], $this->owner($f), $f['roomTaskId'], [
			'task' => ['name' => 'Wash up', 'flexDays' => 1], 'scope' => TaskEdit::SCOPE_ALL_ROOMS,
		]);

		self::assertSame($f['taskId'], (int)$this->roomTaskRow($f['roomTaskId'])['task_id'], 'nothing splits off');
		self::assertSame('Wash up', $this->taskRow($f['taskId'])['name']);
		self::assertGreaterThan($otherVersion, (int)$this->roomTaskRow($other)['cycle_version'], 'the other room follows the changed rule');
	}

	// ------------------------------------------------------------------
	// Moving to another room
	// ------------------------------------------------------------------

	public function testMove_ChangesTheRoom_AndTheHistoryBelongsToTheRoomTask(): void {
		$f = $this->fixture();
		$completionId = $this->complete($f);
		$journal = $this->rows('chorestrike_room_events', 'source_completion_id', $completionId);
		$floorId = (int)$this->rows('chorestrike_rooms', 'id', $f['roomId'])[0]['floor_id'];
		$bathroom = $this->createRoom($f['houseId'], $floorId, 'Bathroom');

		$this->saveForm($f['houseId'], $this->owner($f), $f['roomTaskId'], ['roomId' => $bathroom]);

		self::assertSame($bathroom, (int)$this->roomTaskRow($f['roomTaskId'])['room_id']);
		self::assertSame($f['roomTaskId'], (int)$this->rows('chorestrike_completed_tasks', 'id', $completionId)[0]['room_task_id']);
		self::assertSame($journal, $this->rows('chorestrike_room_events', 'source_completion_id', $completionId), 'the journal keeps the room where the work happened');
	}

	public function testMove_IntoARoomThatHoldsTheDefinition_Conflicts_AndIntoAForeignRoom_IsNotFound(): void {
		$f = $this->fixture();
		$other = $this->shareWithASecondRoom($f);
		$otherRoom = (int)$this->roomTaskRow($other)['room_id'];

		self::assertSame(ConflictException::REASON_CONFLICT, $this->conflictOf(
			fn () => $this->saveForm($f['houseId'], $this->owner($f), $f['roomTaskId'], ['roomId' => $otherRoom])
		));

		$foreignHouse = $this->createHouse('rte_foreign', 'Foreign House');
		$foreignRoom = $this->createRoom($foreignHouse, $this->createFloor($foreignHouse, 'Ground', 1), 'Foreign kitchen');
		try {
			$this->saveForm($f['houseId'], $this->owner($f), $f['roomTaskId'], ['roomId' => $foreignRoom]);
			self::fail('a room of another house is not found');
		} catch (NotFoundException $e) {
			self::assertSame($f['roomId'], (int)$this->roomTaskRow($f['roomTaskId'])['room_id']);
		}
	}

	// ------------------------------------------------------------------
	// First date and sleep
	// ------------------------------------------------------------------

	public function testFirstDate_MovesTheWindowUntilTheFirstCompletion_ThenConflicts(): void {
		$f = $this->fixture();

		$this->saveForm($f['houseId'], $this->owner($f), $f['roomTaskId'], ['firstDate' => $this->day(3)]);

		self::assertSame($this->day(3), substr((string)$this->roomTaskRow($f['roomTaskId'])['anchor_on'], 0, 10));
		self::assertSame(['d' . $this->day(3), $this->day(3)], [$this->block($f)['cycleKey'], $this->block($f)['centreOn']]);

		$this->complete($f);
		self::assertSame(ConflictException::REASON_CONFLICT, $this->conflictOf(
			fn () => $this->saveForm($f['houseId'], $this->owner($f), $f['roomTaskId'], ['firstDate' => $this->day(5)])
		));
	}

	public function testSleep_ThroughTheForm_AndAWakeDateThatCannotBe(): void {
		$f = $this->fixture();

		$this->saveForm($f['houseId'], $this->owner($f), $f['roomTaskId'], ['sleeping' => true, 'wakeOn' => $this->day(10)]);
		self::assertSame(['state' => WindowService::STATE_ASLEEP, 'wakeOn' => $this->day(10)], array_intersect_key($this->block($f), ['state' => 1, 'wakeOn' => 1]));

		$this->saveForm($f['houseId'], $this->owner($f), $f['roomTaskId'], ['sleeping' => false]);
		self::assertNotSame(WindowService::STATE_ASLEEP, $this->block($f)['state'], 'the task woke up');

		try {
			$this->saveForm($f['houseId'], $this->owner($f), $f['roomTaskId'], ['sleeping' => true, 'wakeOn' => $this->today]);
			self::fail('a wake date of today is refused');
		} catch (\InvalidArgumentException $e) {
			self::assertNull($this->roomTaskRow($f['roomTaskId'])['sleeping_since'], 'nothing went to sleep');
		}
	}

	// ------------------------------------------------------------------
	// Sub-tasks
	// ------------------------------------------------------------------

	public function testSubTasks_RelabelReorderRemoveAndAdd_AndARemovedTickNoLongerCounts(): void {
		$f = $this->fixture();
		$plates = $this->createChecklistItem($f['taskId'], 'Plates', 0);
		$pots = $this->createChecklistItem($f['taskId'], 'Pots', 1);
		$sink = $this->createChecklistItem($f['taskId'], 'Sink', 2);
		$checklists = \OC::$server->get(ChecklistService::class);
		$checklists->toggleItem($f['roomTaskId'], $this->block($f)['cycleKey'], $sink, null, $f['houseId'], $this->owner($f));

		$this->saveForm($f['houseId'], $this->owner($f), $f['roomTaskId'], ['checklistItems' => [
			['id' => $pots, 'label' => 'Pans'], ['id' => $plates, 'label' => 'Plates'], ['label' => 'Counter'],
		]]);

		self::assertSame(['Pans', 'Plates', 'Counter'], $this->labels($f['taskId']));
		self::assertSame([$pots, $plates], array_slice(array_map('intval', array_column($this->items($f['taskId']), 'id')), 0, 2), 'the kept sub-tasks keep their ids');
		$progress = $checklists->getRoomTaskProgress($f['roomTaskId'], null, $f['houseId'], $this->owner($f));
		self::assertSame([3, 0], [$progress['stats']['total'], $progress['stats']['checked']], 'the tick of the removed sub-task no longer counts');
	}

	public function testSubTasks_AnIdOfAnotherTask_IsRefused(): void {
		$f = $this->fixture();
		$foreignItem = $this->createChecklistItem($this->createTask($f['houseId'], 'Other'), 'Not yours', 0);

		try {
			$this->saveForm($f['houseId'], $this->owner($f), $f['roomTaskId'], ['checklistItems' => [['id' => $foreignItem, 'label' => 'Mine now']]]);
			self::fail('a sub-task of another task is refused');
		} catch (\InvalidArgumentException $e) {
			self::assertSame('Unknown sub-task', $e->getMessage());
		}
		self::assertSame(['Not yours'], array_column($this->rows('chorestrike_chk_items', 'id', $foreignItem), 'label'));
	}

	// ------------------------------------------------------------------
	// Rights (ADR-010, D-091-16)
	// ------------------------------------------------------------------

	public function testRights_AChildCannotSave(): void {
		$f = $this->fixture();
		$this->addHouseMember($f['houseId'], self::KID, 'Kid', 'child');

		$this->expectException(ForbiddenException::class);
		$this->saveForm($f['houseId'], $this->member($f['houseId'], self::KID), $f['roomTaskId'], ['task' => ['name' => 'Mine']]);
	}

	public function testRights_APrivateTaskIsNotFoundForWhoMayNotSeeIt(): void {
		$f = $this->fixture();
		$this->setColumn('chorestrike_tasks', $f['taskId'], 'visibility_type', 'private');
		$this->setColumn('chorestrike_tasks', $f['taskId'], 'owner_user_id', self::OWNER);

		$this->expectException(NotFoundException::class);
		$this->saveForm($f['houseId'], $this->member($f['houseId'], self::HELPER), $f['roomTaskId'], ['task' => ['name' => 'Peeked']]);
	}

	public function testRights_AMemberCannotMakeATaskAdminOnly(): void {
		$f = $this->fixture();

		try {
			$this->saveForm($f['houseId'], $this->member($f['houseId'], self::HELPER), $f['roomTaskId'], ['task' => ['visibilityType' => 'admin-only']]);
			self::fail('admin-only is the owner\'s');
		} catch (ForbiddenException $e) {
			self::assertSame('public', $this->taskRow($f['taskId'])['visibility_type']);
		}
	}

	public function testRights_AnAssigneeEditingAPrivateTask_KeepsItsOwner(): void {
		$f = $this->fixture();
		$this->setColumn('chorestrike_tasks', $f['taskId'], 'visibility_type', 'private');
		$this->setColumn('chorestrike_tasks', $f['taskId'], 'owner_user_id', self::OWNER);
		$this->assignTo($f['houseId'], $this->owner($f), $f['roomTaskId'], [self::OWNER, self::HELPER]);

		$this->saveForm($f['houseId'], $this->member($f['houseId'], self::HELPER), $f['roomTaskId'], [
			'task' => ['name' => 'Renamed', 'visibilityType' => 'private'],
		]);

		self::assertSame('Renamed', $this->taskRow($f['taskId'])['name']);
		self::assertSame(self::OWNER, $this->taskRow($f['taskId'])['owner_user_id'], 'the creator keeps the task');
	}
}
