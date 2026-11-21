<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Db\TaskAssigneeMapper;
use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Db\TransactionExecutor;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Exception\NotFoundException;
use OCA\ChoreStrike\Exception\ValidationException;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\CalendarService;
use OCA\ChoreStrike\Service\RoomService;
use OCA\ChoreStrike\Service\TaskAccessPolicy;
use OCA\ChoreStrike\Service\TaskCreationService;
use OCA\ChoreStrike\Service\TaskService;
use OCA\ChoreStrike\Service\TemplateService;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the atomic creation aggregate (CS-PLAN-063 §3.2, CR-WF-03)
 */
class TaskCreationServiceTest extends TestCase {
	private IDBConnection $db;
	private TaskMapper $taskMapper;
	private RoomTaskMapper $roomTaskMapper;
	private TaskAssigneeMapper $taskAssigneeMapper;
	private HouseMapper $houseMapper;
	private RoomService $roomService;
	private TaskService $taskService;
	private TemplateService $templateService;
	private TaskAccessPolicy $taskAccessPolicy;
	private AuthorizationService $authorization;
	private TaskCreationService $service;

	/** Recorded aggregate writes (assertion targets). */
	private array $definitionInserts = [];
	private array $roomTaskInserts = [];
	private array $assigneeInserts = [];
	private bool $assigneesRead = false;

	/** The task returned by taskMapper->create / taskMapper->find. */
	private ?Task $persistedTask = null;

	/** The member the under-lock check finds (null = a miss). */
	private ?HouseUser $memberUnderLock = null;

	/** @var \OCA\ChoreStrike\Service\CycleService&\PHPUnit\Framework\MockObject\MockObject */
	private $cycleService;

	/** @var \OCA\ChoreStrike\Service\SleepService&\PHPUnit\Framework\MockObject\MockObject */
	private $sleepService;

	/** @var \OCA\ChoreStrike\Db\TaskChecklistItemMapper&\PHPUnit\Framework\MockObject\MockObject */
	private $checklistItemMapper;

	protected function setUp(): void {
		$this->definitionInserts = [];
		$this->roomTaskInserts = [];
		$this->assigneeInserts = [];
		$this->assigneesRead = false;
		$this->persistedTask = null;
		$this->memberUnderLock = null;

		$this->db = $this->createMock(IDBConnection::class);
		$this->taskMapper = $this->createMock(TaskMapper::class);
		$this->roomTaskMapper = $this->createMock(RoomTaskMapper::class);
		$this->taskAssigneeMapper = $this->createMock(TaskAssigneeMapper::class);
		$this->houseMapper = $this->createMock(HouseMapper::class);
		$this->roomService = $this->createMock(RoomService::class);
		$this->taskAccessPolicy = $this->createMock(TaskAccessPolicy::class);
		$this->authorization = $this->createMock(AuthorizationService::class);
		$this->cycleService = $this->createMock(\OCA\ChoreStrike\Service\CycleService::class);
		$this->sleepService = $this->createMock(\OCA\ChoreStrike\Service\SleepService::class);
		$this->checklistItemMapper = $this->createMock(\OCA\ChoreStrike\Db\TaskChecklistItemMapper::class);

		$l10n = $this->createIdentityL10n();

		// The REAL TaskService validates through the closed constraint set —
		// mocked validators would prove nothing about §3.1.
		$taskCategories = $this->createMock(\OCA\ChoreStrike\Service\TaskCategoryService::class);
		$taskCategories->method('isKnown')->willReturnCallback(static fn (string $key): bool => in_array($key, \OCA\ChoreStrike\Service\TaskCategoryService::DEFAULT_KEYS, true));
		$taskCategories->method('keyOrOther')->willReturnCallback(static fn (string $key): string => in_array($key, \OCA\ChoreStrike\Service\TaskCategoryService::DEFAULT_KEYS, true) ? $key : 'other');
		$taskCategories->method('getList')->willReturn(['revision' => 0, 'items' => array_map(static fn (string $key): array => ['key' => $key, 'label' => null], \OCA\ChoreStrike\Service\TaskCategoryService::DEFAULT_KEYS)]);
		$this->taskService = new TaskService(
			$this->taskMapper,
			$this->createMock(RoomTaskMapper::class),
			$l10n,
			$this->authorization,
			$this->taskAccessPolicy,
			$this->createMock(\OCA\ChoreStrike\Service\CycleService::class),
			$taskCategories,
		);

		// The REAL TemplateService loads the file-based catalog (the fixture
		// test below); a template only fills the form (CS-PLAN-091 WP-B6).
		$this->templateService = new TemplateService($l10n, $taskCategories);

		$this->service = new TaskCreationService(
			new TransactionExecutor($this->db),
			$this->taskMapper,
			$this->roomTaskMapper,
			$this->taskAssigneeMapper,
			$this->houseMapper,
			$this->roomService,
			$this->taskService,
			new \OCA\ChoreStrike\Service\SubTaskWriter($this->checklistItemMapper, $l10n),
			$this->sleepService,
			$this->authorization,
			new CalendarService(),
			$this->cycleService,
			$l10n,
			$this->createMock(AppLogger::class),
		);
	}

	private function createIdentityL10n(): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static function (string $text, array $parameters = []) {
			$pattern = preg_replace('/%[0-9]+\$[ds]/', '%s', $text);
			return $parameters === [] ? $pattern : vsprintf($pattern, array_map('strval', array_values($parameters)));
		});
		return $l10n;
	}

	private function createHouseUser(string $userId = 'creator', string $role = 'owner'): HouseUser {
		$houseUser = new HouseUser();
		$houseUser->setNextcloudUserId($userId);
		$houseUser->setRole($role);
		return $houseUser;
	}

	/**
	 * A valid custom definition payload.
	 */
	private function validTaskPayload(array $overrides = []): array {
		return array_merge([
			'name' => 'Vacuum the floor',
			'energyCost' => 3,
			'priority' => 2,
			'frequencyType' => 'weekly',
			'frequencyValue' => 1,
			'estimatedMinutes' => 20,
			'category' => 'cleaning',
		], $overrides);
	}

	/**
	 * Wire the default aggregate happy path: house lock succeeds, members
	 * resolve, definition insert records and returns {@see $persistedTask},
	 * room-task materialization records, assignee rows record.
	 */
	private function stubAggregateHappyPath(): void {
		$house = new House();
		$this->houseMapper->method('findForUpdate')->willReturn($house);
		// Creation resolves the house timezone for the first date;
		// default to a UTC house.
		$this->houseMapper->method('find')->willReturn($house);

		$member = new HouseUser();
		$member->setNextcloudUserId('member-user');
		$this->memberUnderLock = $member;

		// The under-lock membership check is the one of AuthorizationService.
		$this->authorization->method('assertMembersUnderLock')->willReturnCallback(function (int $houseId, array $userIds): void {
			if ($this->memberUnderLock === null && $userIds !== []) {
				throw new \InvalidArgumentException('User is not a member of this house: ' . $userIds[0]);
			}
		});

		$this->taskMapper->method('create')->willReturnCallback(function (...$args) {
			$this->definitionInserts[] = $args;
			if ($this->persistedTask === null) {
				$task = new Task();
				$task->setId(42);
				$task->setFlexDays(0);
				$this->persistedTask = $task;
			}
			return $this->persistedTask;
		});

		$this->taskMapper->method('find')->willReturnCallback(function (int $id) {
			if ($this->persistedTask !== null && $this->persistedTask->getId() === $id) {
				return $this->persistedTask;
			}
			throw new \OCP\AppFramework\Db\DoesNotExistException('Task not found');
		});

		$this->roomTaskMapper->method('create')->willReturnCallback(function (...$args) {
			$this->roomTaskInserts[] = $args;
			$roomTask = new RoomTask();
			$roomTask->setId(7);
			$roomTask->setRoomId($args[0]);
			$roomTask->setTaskId($args[1]);
			return $roomTask;
		});

		$this->taskAssigneeMapper->method('addAssignee')->willReturnCallback(function (...$args) {
			$this->assigneeInserts[] = $args;
			$assignee = new \OCA\ChoreStrike\Db\TaskAssignee();
			$assignee->setRoomTaskId($args[0]);
			$assignee->setUserId($args[1]);
			return $assignee;
		});

		$this->taskAssigneeMapper->method('findByRoomTask')->willReturnCallback(function () {
			$this->assigneesRead = true;
			return [];
		});
	}

	/**
	 * A fresh persisted task entity for the definition insert to return.
	 */
	private function willPersistTask(Task $task): void {
		$this->persistedTask = $task;
	}

	/**
	 * TaskMapper::create parameter order (positional capture map — PHPUnit
	 * forwards bound parameters positionally).
	 */
	private const CREATE_ARG = [
		'name' => 0,
		'description' => 1,
		'energyCost' => 2,
		'priority' => 3,
		'frequencyType' => 4,
		'frequencyValue' => 5,
		'estimatedMinutes' => 6,
		'category' => 7,
		'scopeType' => 8,
		'scopeLabel' => 9,
		'recurrenceMode' => 10,
		'activeFrom' => 11,
		'activeUntil' => 12,
		'isVoluntary' => 13,
		'schedulingMode' => 14,
		'fixedScheduleTime' => 15,
		'fixedScheduleDays' => 16,
		'instructions' => 17,
		'tips' => 18,
		'estimatedMinutesMin' => 19,
		'estimatedMinutesMax' => 20,
		'visibilityType' => 21,
		'ownerUserId' => 22,
	];

	private function definitionArg(array $insert, string $name): mixed {
		return $insert[self::CREATE_ARG[$name]];
	}

	/**
	 * Create_CustomPath_RunsAggregateAndReturnsUnifiedShape
	 *
	 * The custom variant persists definition, room task, and assignee rows
	 * inside one transaction and returns the one aggregate response shape
	 * (D1): {task, roomTask, assignees}.
	 */
	public function testCreate_CustomPath_RunsAggregateAndReturnsUnifiedShape(): void {
		// House lock first (documented lock order, WP3 precedent).
		$this->houseMapper->expects($this->once())->method('findForUpdate')->with(10);
		$this->roomService->expects($this->once())->method('validateRoomOwnership')->with(5, 10);
		$this->stubAggregateHappyPath();

		$result = $this->service->create(10, $this->createHouseUser('creator', 'owner'), [
			'roomId' => 5,
			'task' => $this->validTaskPayload(),
			'assigneeUserIds' => ['member-user'],
		]);

		$this->assertCount(1, $this->definitionInserts, 'exactly one definition insert');
		$this->assertCount(1, $this->roomTaskInserts, 'exactly one room-task materialization');
		$this->assertSame([7, 'member-user', 'creator'], [
			$this->assigneeInserts[0][0],
			$this->assigneeInserts[0][1],
			$this->assigneeInserts[0][2],
		], 'the initial assignee row is attributed to the actor');

		$this->assertSame(42, $result['task']->getId());
		$this->assertSame(7, $result['roomTask']->getId());
		$this->assertTrue($this->assigneesRead, 'assignees are read back into the response');
	}

	/**
	 * CS-PLAN-090 WP-I (D-090-65): a definition without a category is
	 * created as *Other*; an unlisted key is refused before any write.
	 */
	public function testCreate_CategoryDefaultsToOtherAndRefusesUnlistedKeys(): void {
		$this->stubAggregateHappyPath();
		$payload = $this->validTaskPayload();
		unset($payload['category']);

		$this->service->create(10, $this->createHouseUser('creator', 'owner'), ['roomId' => 5, 'task' => $payload]);
		$this->assertSame('other', $this->definitionInserts[0][self::CREATE_ARG['category']]);

		try {
			$this->service->create(10, $this->createHouseUser('creator', 'owner'), [
				'roomId' => 5, 'task' => $this->validTaskPayload(['category' => 'garden']),
			]);
			$this->fail('an unlisted category must be refused');
		} catch (\OCA\ChoreStrike\Exception\ValidationException $e) {
			$this->assertContains('Unknown task category', $e->getErrors());
		}
		$this->assertCount(1, $this->definitionInserts, 'the refused create wrote nothing');
	}

	/**
	 * Create_CustomPath_ExplicitFirstDate_AnchorsTheWindow
	 *
	 * ADR-021: the explicit first date is the centre of the first window.
	 * It anchors the room task through the cycle service in the same
	 * aggregate; no future cycle and no ideal date is stored at creation.
	 */
	public function testCreate_CustomPath_ExplicitFirstDate_AnchorsTheWindow(): void {
		$this->stubAggregateHappyPath();
		$flexTask = new Task();
		$flexTask->setId(42);
		$flexTask->setFlexDays(3);
		$this->willPersistTask($flexTask);
		// Relative to today: a first date must lie within a year of it.
		$firstDate = (new \DateTime('today', new \DateTimeZone('UTC')))->modify('-5 days')->format('Y-m-d');
		$this->cycleService->expects($this->once())->method('startUnderLock')
			->with(
				$this->isInstanceOf(RoomTask::class),
				$this->identicalTo($flexTask),
				$firstDate,
				$this->callback(static fn (\DateTimeZone $zone): bool => $zone->getName() === 'UTC'),
				$this->isInstanceOf(\DateTime::class)
			);

		$this->service->create(10, $this->createHouseUser(), [
			'roomId' => 5,
			'task' => $this->validTaskPayload(['flexDays' => 3]),
			'firstDate' => $firstDate,
		]);

		$this->assertCount(1, $this->roomTaskInserts);
		[$roomId, $taskId, $nextDue] = $this->roomTaskInserts[0];
		$this->assertSame(5, $roomId);
		$this->assertSame(42, $taskId);
		$this->assertSame($firstDate, $nextDue->format('Y-m-d'), 'the row starts on its first date until the cycle projects it');
	}

	/**
	 * Create_DefaultsToTomorrowWithoutFirstDate
	 *
	 * No firstDate payload falls back to the established tomorrow default
	 * (house-local).
	 */
	public function testCreate_DefaultsToTomorrowWithoutFirstDate(): void {
		$this->stubAggregateHappyPath();

		$tomorrow = (new \DateTime('now', new \DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d');
		$this->cycleService->expects($this->once())->method('startUnderLock')
			->with($this->anything(), $this->anything(), $tomorrow, $this->anything(), $this->anything());

		$this->service->create(10, $this->createHouseUser(), [
			'roomId' => 5,
			'task' => $this->validTaskPayload(),
		]);

		$this->assertSame($tomorrow, $this->roomTaskInserts[0][2]->format('Y-m-d'));
	}

	/**
	 * Create_InvalidFirstDate_Throws: only a house-local `Y-m-d` day is a first date.
	 */
	public function testCreate_InvalidFirstDate_Throws(): void {
		$this->roomTaskMapper->expects($this->never())->method('create');

		$this->expectException(\InvalidArgumentException::class);

		$this->service->create(10, $this->createHouseUser(), [
			'roomId' => 5,
			'task' => $this->validTaskPayload(),
			'firstDate' => '2026-09-10T08:00:00Z',
		]);
	}

	/**
	 * Review regression (CS-PLAN-091 WP-B1 interim Full review): a
	 * day-shaped value that is no calendar day, or one more than a year
	 * away, is a translated 400 before anything is written.
	 *
	 * @dataProvider unusableFirstDates
	 */
	public function testCreate_UnusableFirstDate_ThrowsTranslatedMessage(string $firstDate, string $message): void {
		$this->roomTaskMapper->expects($this->never())->method('create');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage($message);

		$this->service->create(10, $this->createHouseUser(), [
			'roomId' => 5,
			'task' => $this->validTaskPayload(),
			'firstDate' => $firstDate,
		]);
	}

	/** @return array<string, array{0: string, 1: string}> */
	public static function unusableFirstDates(): array {
		$today = new \DateTime('today', new \DateTimeZone('UTC'));
		return [
			'no calendar day' => ['2026-02-31', 'Invalid firstDate date format'],
			'far in the past' => [(clone $today)->modify('-400 days')->format('Y-m-d'), 'The first date must lie within one year of today'],
			'far in the future' => ['9999-12-31', 'The first date must lie within one year of today'],
		];
	}

	/**
	 * Create_ChildActor_ThrowsForbiddenBeforeAnyMapperCall
	 *
	 * Gate order (CS-PLAN-061 D5): the actor policy fires before any
	 * persistence — children execute tasks, they do not create them.
	 */
	public function testCreate_ChildActor_ThrowsForbiddenBeforeAnyMapperCall(): void {
		$this->authorization->method('assertCanManageTasks')
			->willThrowException(new ForbiddenException('Children may view and complete tasks, but not manage them'));

		$this->roomService->expects($this->never())->method('validateRoomOwnership');
		$this->roomTaskMapper->expects($this->never())->method('create');

		$this->expectException(ForbiddenException::class);

		$this->service->create(10, $this->createHouseUser('kid', 'child'), [
			'roomId' => 5,
			'task' => $this->validTaskPayload(),
		]);
	}

	/**
	 * Create_CrossHouseRoom_ThrowsNotFound
	 *
	 * A room of another house is the 404 anti-enumeration denial before any
	 * persistence (ADR-010 standard).
	 */
	public function testCreate_CrossHouseRoom_ThrowsNotFound(): void {
		$this->roomService->method('validateRoomOwnership')
			->willThrowException(new NotFoundException('Room not found'));

		$this->roomTaskMapper->expects($this->never())->method('create');

		$this->expectException(NotFoundException::class);

		$this->service->create(10, $this->createHouseUser(), [
			'roomId' => 5,
			'task' => $this->validTaskPayload(),
		]);
	}

	/**
	 * Create_PrivateStampsCreatorAsOwner
	 *
	 * Private creation tracks the creator as ownerUserId on the fresh
	 * definition.
	 */
	public function testCreate_PrivateStampsCreatorAsOwner(): void {
		$this->stubAggregateHappyPath();

		$this->service->create(10, $this->createHouseUser('creator', 'owner'), [
			'roomId' => 5,
			'task' => $this->validTaskPayload(['visibilityType' => 'private']),
		]);

		$this->assertSame('creator', $this->definitionArg($this->definitionInserts[0], 'ownerUserId'));
	}

	/**
	 * Create_AdminOnlyByMember_ThrowsForbidden
	 *
	 * Admin-only creation is owner-gated (403 role denial, CS-PLAN-061).
	 */
	public function testCreate_AdminOnlyByMember_ThrowsForbidden(): void {
		$this->roomTaskMapper->expects($this->never())->method('create');

		$this->expectException(ForbiddenException::class);

		$this->service->create(10, $this->createHouseUser('member', 'member'), [
			'roomId' => 5,
			'task' => $this->validTaskPayload(['visibilityType' => 'admin-only']),
		]);
	}

	/**
	 * The mount variant is gone (CS-PLAN-091 WP-B6, FEAT-UX-043): every room
	 * task gets a definition of its own, so a body naming an existing one
	 * is refused before anything is written.
	 */
	public function testCreate_MountVariantIsGone(): void {
		$this->roomTaskMapper->expects($this->never())->method('create');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Unknown field: taskId');

		$this->service->create(10, $this->createHouseUser(), ['roomId' => 5, 'taskId' => 42]);
	}

	/** The one form's create writes the sub-tasks with the definition, in their order. */
	public function testCreate_WithSubTasks_WritesThemInOrder(): void {
		$this->stubAggregateHappyPath();
		$created = [];
		$this->checklistItemMapper->method('create')->willReturnCallback(function (int $taskId, string $label, int $order) use (&$created) {
			$created[] = [$taskId, $label, $order];
			return new \OCA\ChoreStrike\Db\TaskChecklistItem();
		});

		$this->service->create(10, $this->createHouseUser(), [
			'roomId' => 5,
			'task' => $this->validTaskPayload(),
			'checklistItems' => [['label' => ' Carpet '], ['label' => 'Under the sofa']],
		]);

		$this->assertSame([[42, 'Carpet', 0], [42, 'Under the sofa', 1]], $created);
	}

	/** A new task has no sub-task to keep: an entry with an id is refused and nothing stays. */
	public function testCreate_SubTaskWithAnId_IsRefused(): void {
		$this->stubAggregateHappyPath();
		$this->checklistItemMapper->expects($this->never())->method('create');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('A new task has no sub-tasks to keep');

		$this->service->create(10, $this->createHouseUser(), [
			'roomId' => 5,
			'task' => $this->validTaskPayload(),
			'checklistItems' => [['id' => 3, 'label' => 'Carpet']],
		]);
	}

	/** The bonus switch and the sleep switch of the form reach the new task. */
	public function testCreate_BonusAndAsleep(): void {
		$this->stubAggregateHappyPath();
		$this->sleepService->expects($this->once())->method('applyUnderLock')
			->with($this->isInstanceOf(House::class), $this->isInstanceOf(RoomTask::class), $this->isInstanceOf(Task::class), true, '2099-04-01');

		$this->service->create(10, $this->createHouseUser(), [
			'roomId' => 5,
			'task' => $this->validTaskPayload(['isVoluntary' => true]),
			'sleeping' => true,
			'wakeOn' => '2099-04-01',
		]);

		$this->assertTrue($this->definitionArg($this->definitionInserts[0], 'isVoluntary'));
	}

	/**
	 * Create_UnderLockMembershipMiss_RollsBackWithZeroWrites
	 *
	 * A membership removed between the pre-validation and the transaction
	 * revalidates under the house lock — the aggregate rolls back with zero
	 * assignee rows and throws (→ 400).
	 */
	public function testCreate_UnderLockMembershipMiss_RollsBackWithZeroWrites(): void {
		$this->stubAggregateHappyPath();
		$this->memberUnderLock = null;

		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('rollBack');
		$this->db->expects($this->never())->method('commit');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('User is not a member of this house: gone-user');

		try {
			$this->service->create(10, $this->createHouseUser(), [
				'roomId' => 5,
				'task' => $this->validTaskPayload(),
				'assigneeUserIds' => ['gone-user'],
			]);
		} finally {
			$this->assertSame([], $this->definitionInserts, 'zero definition writes');
			$this->assertSame([], $this->roomTaskInserts, 'zero room-task writes');
			$this->assertSame([], $this->assigneeInserts, 'zero assignee writes');
		}
	}

	/**
	 * Create_MapperFailureAfterDefinitionInsert_RollsBackEverything
	 *
	 * Rollback sabotage (§4): a mapper throwing after the definition insert
	 * must roll back the transaction — commit never runs and no assignee
	 * row is written. No orphan task definitions.
	 */
	public function testCreate_MapperFailureAfterDefinitionInsert_RollsBackEverything(): void {
		$this->stubAggregateHappyPath();

		$boom = new \RuntimeException('disk full');
		$this->roomTaskMapper->method('create')->willReturnCallback(function () use ($boom) {
			throw $boom;
		});

		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('rollBack');
		$this->db->expects($this->never())->method('commit');

		$this->expectException(\RuntimeException::class);

		try {
			$this->service->create(10, $this->createHouseUser(), [
				'roomId' => 5,
				'task' => $this->validTaskPayload(),
				'assigneeUserIds' => ['member-user'],
			]);
		} finally {
			$this->assertSame([], $this->assigneeInserts, 'no assignee row survives a failed aggregate');
		}
	}

	/**
	 * Create_ValidationFailures_RejectWith400AndZeroWrites
	 *
	 * The closed constraint set (§3.1): out-of-set scheduling modes, out-of
	 * -bound flexDays, malformed weekday lists, non-string names, and
	 * oversized instructions are rejected before any persistence.
	 *
	 * @dataProvider validationFailureProvider
	 */
	public function testCreate_ValidationFailures_RejectWith400AndZeroWrites(array $payload, string $expectedMessage): void {
		$this->stubAggregateHappyPath();

		try {
			$this->service->create(10, $this->createHouseUser(), [
				'roomId' => 5,
				'task' => $this->validTaskPayload($payload),
			]);
			$this->fail('Expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertStringContainsString($expectedMessage, $e->getMessage());
			$this->assertContains($expectedMessage, $e->getErrors());
		}

		$this->assertSame([], $this->definitionInserts, 'no definition write on validation failure');
		$this->assertSame([], $this->roomTaskInserts, 'no room-task write on validation failure');
	}

	public static function validationFailureProvider(): array {
		return [
			'invalid scheduling mode' => [
				['schedulingMode' => 'sometimes'],
				'Invalid scheduling mode',
			],
			// FEAT-UX-039: the weekly payload's cap is ceil(7 / 2) = 4.
			'flexDays one above the weekly cap' => [
				['flexDays' => 5],
				'Flex days must be between 0 and 4',
			],
			'flexDays negative' => [
				['flexDays' => -1],
				'Flex days must be between 0 and 4',
			],
			'flexDays above the cap of a weekday set' => [
				['schedulingMode' => 'static', 'fixedScheduleTime' => '08:00', 'fixedScheduleDays' => ['monday', 'thursday'], 'flexDays' => 3],
				'Flex days must be between 0 and 2',
			],
			'flexDays non-integer is never coerced' => [
				['flexDays' => '3'],
				'Invalid value type for field: flexDays',
			],
			'weekday list with unknown day' => [
				['fixedScheduleDays' => ['funday']],
				'Invalid fixed schedule days',
			],
			'weekday list as malformed JSON string' => [
				['fixedScheduleDays' => 'not-json'],
				'Invalid fixed schedule days',
			],
			'non-string name is never coerced' => [
				['name' => ['Vacuum']],
				'Invalid value type for field: name',
			],
			'null name is rejected, never passed through' => [
				['name' => null],
				'Task name is required',
			],
			'instructions beyond the length cap' => [
				['instructions' => str_repeat('x', 2001)],
				'Instructions must not exceed 2000 characters',
			],
			'a name beyond its column (D-091-111)' => [
				['name' => str_repeat('x', 256)],
				'Task name must be at most 255 characters',
			],
			'a description beyond the length cap (D-091-111)' => [
				['description' => str_repeat('x', 2001)],
				'Description must not exceed 2000 characters',
			],
			'a description that is no text is never coerced' => [
				['description' => ['Wipe']],
				'Invalid value type for field: description',
			],
			'energyCost above the limit' => [
				['energyCost' => 21],
				'Energy cost must be between 1 and 20',
			],
			'energyCost below the limit' => [
				['energyCost' => 0],
				'Energy cost must be between 1 and 20',
			],
			'inverted active window' => [
				['activeFrom' => '2026-09-10', 'activeUntil' => '2026-09-01'],
				'Active until must be equal or after active from',
			],
		];
	}

	/**
	 * Create_EnergyAtTheBounds_Accepted (D-089-07)
	 */
	public function testCreate_EnergyAtTheBounds_Accepted(): void {
		$this->stubAggregateHappyPath();

		foreach ([1, 20] as $energyCost) {
			$this->service->create(10, $this->createHouseUser(), [
				'roomId' => 5,
				'task' => $this->validTaskPayload(['energyCost' => $energyCost]),
			]);
		}

		$this->assertSame(1, $this->definitionArg($this->definitionInserts[0], 'energyCost'));
		$this->assertSame(20, $this->definitionArg($this->definitionInserts[1], 'energyCost'));
	}

	/**
	 * Create_ValidSchedulingPayloads_Accepted
	 *
	 * Positive half of the closed set: static scheduling with canonical
	 * weekday lists and a flex value at the rule's cap (FEAT-UX-039: weekly
	 * 4, every six months 90) pass validation.
	 */
	public function testCreate_ValidSchedulingPayloads_Accepted(): void {
		$this->stubAggregateHappyPath();

		$this->service->create(10, $this->createHouseUser(), [
			'roomId' => 5,
			'task' => $this->validTaskPayload([
				'schedulingMode' => 'static',
				'fixedScheduleTime' => '08:00',
				'fixedScheduleDays' => ['monday', 'friday'],
			]),
		]);

		$this->service->create(10, $this->createHouseUser(), [
			'roomId' => 5,
			'task' => $this->validTaskPayload(['flexDays' => 4]),
		]);

		$this->service->create(10, $this->createHouseUser(), [
			'roomId' => 5,
			'task' => $this->validTaskPayload(['frequencyType' => 'monthly', 'frequencyValue' => 6, 'flexDays' => 90]),
		]);

		$this->assertCount(3, $this->definitionInserts);
		$this->assertSame(
			json_encode(['monday', 'friday']),
			$this->definitionArg($this->definitionInserts[0], 'fixedScheduleDays'),
			'weekday lists persist in their canonical JSON encoding'
		);
	}

	/**
	 * Catalog_Fixture_EveryTemplateTimesLevelPassesClosedSet
	 *
	 * Risk mitigation (§7): the validation tightening must not reject
	 * catalog payloads — every catalog template materialized at every level
	 * must pass the closed constraint set before the check goes live.
	 */
	public function testCatalog_Fixture_EveryTemplateTimesLevelPassesClosedSet(): void {
		$templates = $this->templateService->getCatalog()['templates'];
		$this->assertNotEmpty($templates, 'The catalog fixture must not be empty');

		$checked = 0;
		foreach ($templates as $template) {
			foreach (TemplateService::LEVELS as $level) {
				// Level-exclusive templates are omitted from a level by the
				// catalog itself (same skip rule as the setup wizard).
				if (($this->templateService->getTemplate($template['id'])['levels'][$level] ?? null) === null) {
					continue;
				}

				$materialized = $this->templateService->materializeTemplate($template['id'], $level);
				$errors = $this->taskService->validateTaskData($materialized);
				$this->assertSame([], $errors, sprintf(
					'Catalog template %s at level %s must pass the closed set: %s',
					$template['id'],
					$level,
					implode(' | ', $errors)
				));
				$checked++;
			}
		}

		$this->assertGreaterThan(0, $checked);
	}
}
