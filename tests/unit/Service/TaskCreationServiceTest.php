<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
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
	private HouseUserMapper $houseUserMapper;
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

	/** Value returned by houseUserMapper->findByHouseAndUser (null = miss). */
	private ?HouseUser $memberUnderLock = null;

	/** Value returned by roomTaskMapper->exists for the mount guard. */
	private bool $mountExists = false;

	protected function setUp(): void {
		$this->definitionInserts = [];
		$this->roomTaskInserts = [];
		$this->assigneeInserts = [];
		$this->assigneesRead = false;
		$this->persistedTask = null;
		$this->memberUnderLock = null;
		$this->mountExists = false;

		$this->db = $this->createMock(IDBConnection::class);
		$this->taskMapper = $this->createMock(TaskMapper::class);
		$this->roomTaskMapper = $this->createMock(RoomTaskMapper::class);
		$this->taskAssigneeMapper = $this->createMock(TaskAssigneeMapper::class);
		$this->houseMapper = $this->createMock(HouseMapper::class);
		$this->houseUserMapper = $this->createMock(HouseUserMapper::class);
		$this->roomService = $this->createMock(RoomService::class);
		$this->taskAccessPolicy = $this->createMock(TaskAccessPolicy::class);
		$this->authorization = $this->createMock(AuthorizationService::class);

		$l10n = $this->createIdentityL10n();

		// The REAL TaskService validates through the closed constraint set —
		// mocked validators would prove nothing about §3.1.
		$taskServiceHouseMapper = $this->createMock(HouseMapper::class);
		$taskServiceHouseMapper->method('findForUpdate')->willReturn(new \OCA\ChoreStrike\Db\House());
		$this->taskService = new TaskService(
			$this->taskMapper,
			$taskServiceHouseMapper,
			$l10n,
			$this->authorization,
			$this->taskAccessPolicy,
			new TransactionExecutor($this->createMock(IDBConnection::class)),
		);

		// The REAL TemplateService loads the file-based catalog.
		$this->templateService = new TemplateService($l10n);

		$this->service = new TaskCreationService(
			new TransactionExecutor($this->db),
			$this->taskMapper,
			$this->roomTaskMapper,
			$this->taskAssigneeMapper,
			$this->houseMapper,
			$this->houseUserMapper,
			$this->roomService,
			$this->taskService,
			$this->templateService,
			$this->taskAccessPolicy,
			$this->authorization,
			new CalendarService(),
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
		// RF-058-01: creation resolves the house timezone for `Y-m-d`
		// nextDue labels; default to a UTC house.
		$this->houseMapper->method('find')->willReturn($house);

		$member = new HouseUser();
		$member->setNextcloudUserId('member-user');
		$this->memberUnderLock = $member;

		$this->houseUserMapper->method('findByHouseAndUser')->willReturnCallback(function () {
			return $this->memberUnderLock;
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

		$this->roomTaskMapper->method('exists')->willReturnCallback(function () {
			return $this->mountExists;
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
	 * Create_CustomPath_ExplicitNextDueAndFlexWindow_Persisted
	 *
	 * The explicit creation due date round-trips and a flexDays > 0 window
	 * materializes an idealDueDate (previously dead at creation, §1.4).
	 */
	public function testCreate_CustomPath_ExplicitNextDueAndFlexWindow_Persisted(): void {
		$this->stubAggregateHappyPath();
		$flexTask = new Task();
		$flexTask->setId(42);
		$flexTask->setFlexDays(3);
		$this->willPersistTask($flexTask);

		$this->service->create(10, $this->createHouseUser(), [
			'roomId' => 5,
			'task' => $this->validTaskPayload(['flexDays' => 3]),
			'nextDue' => '2026-09-10',
		]);

		$this->assertCount(1, $this->roomTaskInserts);
		[$roomId, $taskId, $nextDue, $scopeId, $idealDueDate] = $this->roomTaskInserts[0];
		$this->assertSame(5, $roomId);
		$this->assertSame(42, $taskId);
		$this->assertSame('2026-09-10', $nextDue->format('Y-m-d'), 'the explicit creation due date persists');
		$this->assertNull($scopeId);
		$this->assertInstanceOf(\DateTime::class, $idealDueDate, 'a flex window materializes an idealDueDate');
	}

	/**
	 * Create_DefaultsToTomorrowWithoutNextDue
	 *
	 * No nextDue payload falls back to the established tomorrow default.
	 */
	public function testCreate_DefaultsToTomorrowWithoutNextDue(): void {
		$this->stubAggregateHappyPath();

		$this->service->create(10, $this->createHouseUser(), [
			'roomId' => 5,
			'task' => $this->validTaskPayload(),
		]);

		$tomorrow = (new \DateTime())->add(new \DateInterval('P1D'))->format('Y-m-d');
		$this->assertSame($tomorrow, $this->roomTaskInserts[0][2]->format('Y-m-d'));
	}

	/**
	 * Create_InvalidNextDue_Throws
	 */
	public function testCreate_InvalidNextDue_Throws(): void {
		$this->roomTaskMapper->expects($this->never())->method('create');

		$this->expectException(\InvalidArgumentException::class);

		$this->service->create(10, $this->createHouseUser(), [
			'roomId' => 5,
			'task' => $this->validTaskPayload(),
			'nextDue' => 'not-a-date',
		]);
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
	 * Create_MountExisting_ReusesDefinitionWithoutInsert
	 *
	 * The mount variant re-reads the visible definition and materializes
	 * only the room task — the definition insert never runs.
	 */
	public function testCreate_MountExisting_ReusesDefinitionWithoutInsert(): void {
		$actor = $this->createHouseUser('creator', 'owner');
		$existing = new Task();
		$existing->setId(42);
		$existing->setName('Vacuum the floor');
		$existing->setFlexDays(0);
		$existing->setVisibilityType(Task::VISIBILITY_PUBLIC);

		$this->stubAggregateHappyPath();
		$this->willPersistTask($existing);
		$this->taskAccessPolicy->method('assertCanViewTask')->with($existing, $actor);

		$result = $this->service->create(10, $actor, [
			'roomId' => 5,
			'taskId' => 42,
		]);

		$this->assertSame([], $this->definitionInserts, 'the definition is reused, never re-inserted');
		$this->assertCount(1, $this->roomTaskInserts);
		$this->assertSame(42, $result['task']->getId());
		$this->assertSame(7, $result['roomTask']->getId());
	}

	/**
	 * Create_MountDuplicateUnderLock_ThrowsWithNoInsert
	 *
	 * The duplicate guard is rechecked under the house lock: two concurrent
	 * mounts of the same definition cannot both pass — the loser gets 400
	 * and no second row.
	 */
	public function testCreate_MountDuplicateUnderLock_ThrowsWithNoInsert(): void {
		$existing = new Task();
		$existing->setId(42);
		$existing->setFlexDays(0);
		$existing->setVisibilityType(Task::VISIBILITY_PUBLIC);

		$this->stubAggregateHappyPath();
		$this->willPersistTask($existing);
		$this->mountExists = true;

		$this->roomTaskMapper->expects($this->never())->method('create');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Task 42 already exists in room 5');

		$this->service->create(10, $this->createHouseUser(), [
			'roomId' => 5,
			'taskId' => 42,
		]);
	}

	/**
	 * Create_MountInvisibleDefinition_ThrowsNotFound
	 *
	 * An invisible (private/admin-only) definition id is the 404
	 * anti-enumeration denial (ADR-010).
	 */
	public function testCreate_MountInvisibleDefinition_ThrowsNotFound(): void {
		$existing = new Task();
		$existing->setId(42);

		$this->taskAccessPolicy->method('assertCanViewTask')
			->willThrowException(new NotFoundException('Task not found'));
		$this->taskMapper->method('find')->willReturn($existing);

		$this->roomTaskMapper->expects($this->never())->method('create');

		$this->expectException(NotFoundException::class);

		$this->service->create(10, $this->createHouseUser(), [
			'roomId' => 5,
			'taskId' => 42,
		]);
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
			'flexDays above the ratified bound' => [
				['flexDays' => 8],
				'Flex days must be between 0 and 7',
			],
			'flexDays negative' => [
				['flexDays' => -1],
				'Flex days must be between 0 and 7',
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
			'energyCost out of range' => [
				['energyCost' => 9],
				'Energy cost must be between 1 and 5',
			],
			'inverted active window' => [
				['activeFrom' => '2026-09-10', 'activeUntil' => '2026-09-01'],
				'Active until must be equal or after active from',
			],
		];
	}

	/**
	 * Create_ValidSchedulingPayloads_Accepted
	 *
	 * Positive half of the closed set: static scheduling with canonical
	 * weekday lists and the flex bounds 0 and 7 pass validation.
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
			'task' => $this->validTaskPayload(['flexDays' => 7]),
		]);

		$this->assertCount(2, $this->definitionInserts);
		$this->assertSame(
			json_encode(['monday', 'friday']),
			$this->definitionArg($this->definitionInserts[0], 'fixedScheduleDays'),
			'weekday lists persist in their canonical JSON encoding'
		);
	}

	/**
	 * CreateFromTemplate_OutOfRangeOverride_FailsValidation
	 *
	 * The template path no longer bypasses validation (§1.3): a whitelisted
	 * override outside the closed constraint set rejects the whole aggregate
	 * with 400 before any persistence.
	 */
	public function testCreateFromTemplate_OutOfRangeOverride_FailsValidation(): void {
		$this->stubAggregateHappyPath();

		try {
			$this->service->createFromTemplate(
				10,
				$this->createHouseUser(),
				5,
				$this->firstCatalogTemplateId(),
				TemplateService::LEVEL_PUTZTEUFEL,
				['energyCost' => 9],
				'member-user',
			);
			$this->fail('Expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertSame(['Energy cost must be between 1 and 5'], $e->getErrors());
		}

		$this->assertSame([], $this->definitionInserts, 'no definition write on template validation failure');
		$this->assertSame([], $this->roomTaskInserts);
	}

	/**
	 * CreateFromTemplate_MaterializesAndReturnsAggregateShape
	 *
	 * The template path runs the same atomic body and returns the unified
	 * response shape (D1); the scalar assignee normalizes to a one-element
	 * set.
	 */
	public function testCreateFromTemplate_MaterializesAndReturnsAggregateShape(): void {
		$this->stubAggregateHappyPath();

		$templateId = $this->firstCatalogTemplateId();
		$result = $this->service->createFromTemplate(
			10,
			$this->createHouseUser('creator', 'owner'),
			5,
			$templateId,
			TemplateService::LEVEL_PUTZTEUFEL,
			[],
			'member-user',
		);

		$this->assertArrayHasKey('task', $result);
		$this->assertArrayHasKey('roomTask', $result);
		$this->assertArrayHasKey('assignees', $result);
		$this->assertCount(1, $this->definitionInserts, 'the template materializes through the definition insert');
		$this->assertSame('member-user', $this->assigneeInserts[0][1], 'the scalar assignee becomes the one-element set');
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

	/**
	 * First template id from the real catalog (fixture-independent tests).
	 */
	private function firstCatalogTemplateId(): string {
		$templates = $this->templateService->getCatalog()['templates'];
		$this->assertNotEmpty($templates, 'The catalog fixture must not be empty');
		return (string)$templates[0]['id'];
	}
}
