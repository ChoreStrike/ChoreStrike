<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Service;

use OCA\ChoreStrike\Db\CommandReceiptMapper;
use OCA\ChoreStrike\Db\CycleProgressMapper;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\TaskAssigneeMapper;
use OCA\ChoreStrike\Db\TaskChecklistItemMapper;
use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Db\TransactionExecutor;
use OCA\ChoreStrike\Exception\ConflictException;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Exception\NotFoundException;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\CalendarService;
use OCA\ChoreStrike\Service\CommandService;
use OCA\ChoreStrike\Service\CycleProjection;
use OCA\ChoreStrike\Service\CycleService;
use OCA\ChoreStrike\Service\RoomService;
use OCA\ChoreStrike\Service\RoomTaskAccess;
use OCA\ChoreStrike\Service\RoomTaskCommand;
use OCA\ChoreStrike\Service\RoomTaskEditService;
use OCA\ChoreStrike\Service\RoomTaskReadService;
use OCA\ChoreStrike\Service\SleepService;
use OCA\ChoreStrike\Service\SubTaskWriter;
use OCA\ChoreStrike\Service\TaskAccessPolicy;
use OCA\ChoreStrike\Service\TaskEdit;
use OCA\ChoreStrike\Service\TaskService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;
use OCA\ChoreStrike\Tests\Integration\Support\CycleFixtures;
use OCA\ChoreStrike\Tests\Integration\Support\EditFixtures;
use OCA\ChoreStrike\Tests\Integration\Support\TaskEditing;

/**
 * The guards of the one form's save against the real database
 * (CS-PLAN-091 WP-B6, its interim Full review): the guard that decides on
 * execution and on replay with the member as they are now; whose private
 * task it is (D-091-108, D-091-109); a shared definition with room tasks
 * the member may not see (D-091-110); the house scope; the audit event.
 */
class RoomTaskEditGuardsTest extends IntegrationTestCase {
	use CycleFixtures;
	use EditFixtures;
	use TaskEditing;

	protected const OWNER = 'rtg_owner';
	protected const HELPER = 'rtg_helper';

	protected function setUp(): void {
		parent::setUp();
		$this->initToday();
	}

	/** A private task of the house owner in one room, assigned to the owner and the helper. */
	private function privateFixture(): array {
		$f = $this->fixture();
		$this->assignTo($f['houseId'], $this->owner($f), $f['roomTaskId'], [self::OWNER, self::HELPER]);
		$this->makePrivate($f);
		return $f;
	}

	// ------------------------------------------------------------------
	// The guard decides on replay as on execution
	// ------------------------------------------------------------------

	/** @return array<string, array{string, class-string<\Throwable>}> */
	public static function membersWhoLostTheirRight(): array {
		return [
			'left the house' => ['left', ForbiddenException::class],
			'became a child' => ['child', ForbiddenException::class],
			'lost sight of the private task' => ['unassigned', NotFoundException::class],
		];
	}

	/** @dataProvider membersWhoLostTheirRight */
	public function testReplay_OfAMemberWhoLostTheRight_AnswersLikeAFirstAttempt(string $change, string $exception): void {
		$f = $this->privateFixture();
		$helper = $this->helper($f);
		$edit = $this->formEdit($f['roomTaskId'], ['task' => ['name' => 'Renamed']]);
		$service = \OC::$server->get(RoomTaskEditService::class);
		self::assertFalse($service->edit($f['houseId'], $helper, $f['roomTaskId'], $edit)['replay']);

		if ($change === 'left') {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('chorestrike_house_users')->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$helper->getId())))->executeStatement();
		} elseif ($change === 'child') {
			$this->setColumn('chorestrike_house_users', (int)$helper->getId(), 'role', 'child');
		} else {
			$this->assignTo($f['houseId'], $this->owner($f), $f['roomTaskId'], [self::OWNER]);
		}

		try {
			$service->edit($f['houseId'], $helper, $f['roomTaskId'], $edit);
			self::fail('the replay must be refused: ' . $change);
		} catch (\Throwable $e) {
			self::assertInstanceOf($exception, $e, 'no replay answer tells of the committed save');
		}
		self::assertSame('Renamed', $this->taskRow($f['taskId'])['name'], 'the committed save stands');
	}

	public function testSave_OfARoomTaskOfAnotherHouse_IsNotFound_AndWritesNothing(): void {
		$f = $this->fixture();
		$otherHouseId = $this->createHouse('rtg_other', 'Other House');
		$before = [$this->taskRow($f['taskId']), $this->roomTaskRow($f['roomTaskId'])];

		try {
			$this->saveForm($otherHouseId, $this->member($otherHouseId, 'rtg_other'), $f['roomTaskId'], [
				'task' => ['name' => 'Taken over'], 'assigneeUserIds' => ['rtg_other'],
			]);
			self::fail('a room task of another house is not found');
		} catch (NotFoundException $e) {
			self::assertSame($before, [$this->taskRow($f['taskId']), $this->roomTaskRow($f['roomTaskId'])]);
			self::assertSame([self::OWNER], $this->assigneesOf($f['roomTaskId']));
		}
	}

	// ------------------------------------------------------------------
	// A private task is its owner's (D-091-108, D-091-109)
	// ------------------------------------------------------------------

	public function testPrivateTask_AnAssigneeCannotSaveThemselvesOutOfIt(): void {
		$f = $this->privateFixture();
		$before = $this->roomTaskRow($f['roomTaskId']);

		try {
			$this->saveForm($f['houseId'], $this->helper($f), $f['roomTaskId'], [
				'task' => ['name' => 'Never saved'], 'assigneeUserIds' => [self::OWNER],
			]);
			self::fail('a save must not take the member\'s own sight of the task');
		} catch (ForbiddenException $e) {
			self::assertEqualsCanonicalizing([self::OWNER, self::HELPER], $this->assigneesOf($f['roomTaskId']));
			self::assertSame('Dishes', $this->taskRow($f['taskId'])['name'], 'nothing of the save is written');
			self::assertSame($before, $this->roomTaskRow($f['roomTaskId']));
		}
	}

	public function testPrivateTask_ItsOwnerRemovesAnAssignee(): void {
		$f = $this->privateFixture();

		$this->saveForm($f['houseId'], $this->owner($f), $f['roomTaskId'], ['assigneeUserIds' => []]);

		self::assertSame([], $this->assigneesOf($f['roomTaskId']), 'the owner sees the task without being assigned');
	}

	public function testPrivateTask_WhoMakesATaskPrivateOwnsIt_AlsoWithoutBeingAssigned(): void {
		$f = $this->fixture();

		$this->saveForm($f['houseId'], $this->helper($f), $f['roomTaskId'], [
			'task' => ['visibilityType' => 'private'], 'assigneeUserIds' => [self::OWNER],
		]);

		self::assertSame(['private', self::HELPER], [$this->taskRow($f['taskId'])['visibility_type'], $this->taskRow($f['taskId'])['owner_user_id']]);
		self::assertSame([self::OWNER], $this->assigneesOf($f['roomTaskId']));
	}

	public function testPrivateTask_OnlyItsOwnerChangesTheVisibility(): void {
		$f = $this->privateFixture();

		foreach (['public', 'admin-only'] as $visibility) {
			try {
				$this->saveForm($f['houseId'], $this->helper($f), $f['roomTaskId'], ['task' => ['visibilityType' => $visibility]]);
				self::fail('an assignee does not change the visibility of a private task');
			} catch (ForbiddenException $e) {
				self::assertSame(['private', self::OWNER], [$this->taskRow($f['taskId'])['visibility_type'], $this->taskRow($f['taskId'])['owner_user_id']]);
			}
		}

		$this->saveForm($f['houseId'], $this->owner($f), $f['roomTaskId'], ['task' => ['visibilityType' => 'public']]);
		self::assertSame('public', $this->taskRow($f['taskId'])['visibility_type']);
	}

	// ------------------------------------------------------------------
	// A shared definition with room tasks the member may not see (D-091-110)
	// ------------------------------------------------------------------

	/** @return array<string, array{?string}> */
	public static function scopesOfAMemberWhoSeesOneRoom(): array {
		return ['no scope' => [null], 'all rooms' => [TaskEdit::SCOPE_ALL_ROOMS], 'this room' => [TaskEdit::SCOPE_THIS_ROOM]];
	}

	/** @dataProvider scopesOfAMemberWhoSeesOneRoom */
	public function testSharedPrivateDefinition_AMemberWhoSeesOneRoom_ChangesThatRoomOnly(?string $scope): void {
		$f = $this->privateFixture();
		$hidden = $this->shareWithASecondRoom($f);
		$hiddenRow = $this->roomTaskRow($hidden);

		$this->saveForm($f['houseId'], $this->helper($f), $f['roomTaskId'], array_filter([
			'task' => ['name' => 'Renamed', 'flexDays' => 1], 'scope' => $scope,
		]));

		$copyId = $this->taskIdOf($f['roomTaskId']);
		self::assertNotSame($f['taskId'], $copyId, 'the room got a definition of its own, without a question');
		$copy = $this->taskRow($copyId);
		self::assertSame(['Renamed', 'private', self::OWNER], [$copy['name'], $copy['visibility_type'], $copy['owner_user_id']]);
		self::assertSame(['Dishes', 0], [$this->taskRow($f['taskId'])['name'], (int)$this->taskRow($f['taskId'])['flex_days']], 'the definition of the other room is untouched');
		self::assertSame($hiddenRow, $this->roomTaskRow($hidden), 'and so is its room task');
	}

	public function testSharedPrivateDefinition_ItsOwnerSeesEveryRoom_AndChooses(): void {
		$f = $this->privateFixture();
		$other = $this->shareWithASecondRoom($f);

		self::assertSame(ConflictException::REASON_SCOPE_REQUIRED, $this->conflictOf(
			fn () => $this->saveForm($f['houseId'], $this->owner($f), $f['roomTaskId'], ['task' => ['name' => 'Renamed']])
		));
		$this->saveForm($f['houseId'], $this->owner($f), $f['roomTaskId'], ['task' => ['name' => 'Renamed'], 'scope' => TaskEdit::SCOPE_ALL_ROOMS]);

		self::assertSame([$f['taskId'], $f['taskId']], [$this->taskIdOf($f['roomTaskId']), $this->taskIdOf($other)], 'nothing splits off');
		self::assertSame('Renamed', $this->taskRow($f['taskId'])['name']);
	}

	public function testSharedAdminOnlyDefinition_ASplit_KeepsTheVisibilityOnTheCopy(): void {
		$f = $this->fixture();
		$this->setColumn('chorestrike_tasks', $f['taskId'], 'visibility_type', 'admin-only');
		$other = $this->shareWithASecondRoom($f);

		$this->saveForm($f['houseId'], $this->owner($f), $f['roomTaskId'], ['task' => ['name' => 'Renamed'], 'scope' => TaskEdit::SCOPE_THIS_ROOM]);

		$copy = $this->taskRow($this->taskIdOf($f['roomTaskId']));
		self::assertSame(['Renamed', 'admin-only'], [$copy['name'], $copy['visibility_type']]);
		self::assertSame(['Dishes', 'admin-only'], [$this->taskRow($f['taskId'])['name'], $this->taskRow($f['taskId'])['visibility_type']]);
		self::assertSame($f['taskId'], $this->taskIdOf($other));
	}

	// ------------------------------------------------------------------
	// A committed save is audited (logging.md: definitions and their sub-tasks)
	// ------------------------------------------------------------------

	public function testSave_WritesTheAuditEventOnItsCommit_AndNoneOnAReplay(): void {
		$f = $this->fixture();
		$logger = $this->createMock(AppLogger::class);
		$logger->expects($this->once())->method('audit')->with(
			'House command committed',
			$this->callback(fn (array $context): bool => $context['event'] === 'house.command.committed'
				&& $context['action'] === RoomTaskEditService::ACTION && $context['houseId'] === $f['houseId']
				&& $context['actorId'] === (int)$this->owner($f)->getId() && !array_key_exists('payload', $context)),
		);
		$service = $this->editServiceLoggingTo($logger);
		$edit = $this->formEdit($f['roomTaskId'], ['task' => ['name' => 'Wash up'], 'checklistItems' => [['label' => 'Plates']]]);

		$first = $service->edit($f['houseId'], $this->owner($f), $f['roomTaskId'], $edit);
		$second = $service->edit($f['houseId'], $this->owner($f), $f['roomTaskId'], $edit);

		self::assertSame([false, true], [$first['replay'], $second['replay']]);
	}

	/** The edit service of the container, with its commands logging to a spy. */
	private function editServiceLoggingTo(AppLogger $logger): RoomTaskEditService {
		$get = static fn (string $class): object => \OC::$server->get($class);
		$l10n = \OC::$server->getL10N('chorestrike');
		$command = new RoomTaskCommand(
			new CommandService($get(TransactionExecutor::class), $get(HouseMapper::class), $get(CommandReceiptMapper::class), $logger),
			$get(RoomTaskAccess::class), $get(AuthorizationService::class), $get(RoomTaskReadService::class),
			$get(RoomTaskMapper::class), $get(CycleService::class), $l10n,
		);
		return new RoomTaskEditService(
			$command, $get(TaskService::class), $get(SubTaskWriter::class), $get(SleepService::class), $get(CycleService::class),
			$get(CycleProjection::class), $get(CycleProgressMapper::class), $get(RoomTaskMapper::class), $get(TaskMapper::class),
			$get(TaskAssigneeMapper::class), $get(TaskChecklistItemMapper::class), $get(HouseMapper::class), $get(RoomService::class),
			$get(AuthorizationService::class), $get(TaskAccessPolicy::class), $get(CalendarService::class), $l10n,
		);
	}
}
