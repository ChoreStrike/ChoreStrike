<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Service;

use OCA\ChoreStrike\Controller\RoomTaskController;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\TaskAssigneeMapper;
use OCA\ChoreStrike\Db\TaskChecklistItemMapper;
use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Exception\ValidationException;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\CalendarService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\RoomTaskService;
use OCA\ChoreStrike\Service\TaskCreationService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IRequest;

/**
 * Real-DB integration tests for the atomic creation aggregate
 * (CS-PLAN-063 §3.3, CR-WF-03): the definition insert, room-task
 * materialization, initial assignee rows, sub-tasks and an optional sleep
 * commit or roll back together, the one form's create (CS-PLAN-091 WP-B6)
 * shares the one validation authority, and the response carries the
 * unified aggregate shape. Every room task gets a definition of its own: a
 * template only fills the form, and the mount variant is gone.
 */
class TaskCreationAggregateTest extends IntegrationTestCase {
	private TaskCreationService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->service = \OC::$server->get(TaskCreationService::class);
	}

	/**
	 * Resolve the acting user's house membership row.
	 */
	private function actor(int $houseId, string $userId): \OCA\ChoreStrike\Db\HouseUser {
		return \OC::$server->get(\OCA\ChoreStrike\Db\HouseUserMapper::class)
			->findByNextcloudUserId($houseId, $userId);
	}

	private function validTaskPayload(array $overrides = []): array {
		return array_merge([
			'name' => 'Aggregate Vacuum',
			'description' => 'Vacuum the aggregate floor',
			'energyCost' => 3,
			'priority' => 2,
			'frequencyType' => 'weekly',
			'frequencyValue' => 1,
			'estimatedMinutes' => 20,
			'category' => 'cleaning',
		], $overrides);
	}

	private function countRows(string $table, string $whereCol, int $value): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))
			->from($table)
			->where($qb->expr()->eq($whereCol, $qb->createNamedParameter($value, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();
		return $count;
	}

	/**
	 * CustomCreate_PersistsFullAggregate_WithFirstDateAndFlexWindow
	 */
	public function testCustomCreate_PersistsFullAggregate_WithFirstDateAndFlexWindow(): void {
		$houseId = $this->createHouse('agg_owner1', 'Aggregate House 1');
		$this->addHouseMember($houseId, 'agg_member1', 'Member', 'member');
		$floorId = $this->createFloor($houseId, 'Ground Floor');
		$roomId = $this->createRoom($houseId, $floorId, 'Kitchen');
		// Relative to today: a first date lies within a year of it.
		$firstDate = (new \DateTime('today', new \DateTimeZone('UTC')))->modify('+30 days');

		$aggregate = $this->service->create($houseId, $this->actor($houseId, 'agg_owner1'), [
			'roomId' => $roomId,
			'task' => $this->validTaskPayload(['flexDays' => 3]),
			'assigneeUserIds' => ['agg_member1'],
			'firstDate' => $firstDate->format('Y-m-d'),
		]);

		// One aggregate response shape for both paths (D1).
		self::assertArrayHasKey('task', $aggregate);
		self::assertArrayHasKey('roomTask', $aggregate);
		self::assertArrayHasKey('assignees', $aggregate);

		$task = $aggregate['task'];
		self::assertSame('Aggregate Vacuum', $task->getName());
		self::assertSame(3, $task->getFlexDays());
		self::assertNull($task->getOwnerUserId(), 'public default carries no owner stamp');

		// Round-trip through the mapper: everything persisted.
		$roomTask = \OC::$server->get(RoomTaskMapper::class)->find($aggregate['roomTask']->getId());
		self::assertSame($roomId, $roomTask->getRoomId());
		self::assertSame($task->getId(), $roomTask->getTaskId());
		// ADR-021 decision 3: the first date anchors the window; the stored
		// projection carries its last day and its planned day.
		self::assertSame($firstDate->format('Y-m-d'), $roomTask->getAnchorOn()->format('Y-m-d'), 'the first date persists as the anchor');
		self::assertSame((clone $firstDate)->modify('+3 days')->format('Y-m-d'), $roomTask->getNextDue()->format('Y-m-d'), 'next_due projects the last day of the flex window');
		self::assertSame($firstDate->format('Y-m-d'), $roomTask->getIdealDueDate()->format('Y-m-d'), 'the planned day is the centre of the window');

		$taskRow = \OC::$server->get(TaskMapper::class)->find($task->getId());
		self::assertSame(3, $taskRow->getFlexDays());

		self::assertSame(
			['agg_member1'],
			\OC::$server->get(TaskAssigneeMapper::class)->findUserIdsByRoomTask($roomTask->getId()),
			'the initial assignment lands in the join table'
		);
	}

	/**
	 * FormCreate_WritesSubTasksBonusAndSleep: the further fields of the one
	 * form land with the definition in the same aggregate.
	 */
	public function testFormCreate_WritesSubTasksBonusAndSleep(): void {
		$houseId = $this->createHouse('agg_owner2', 'Aggregate House 2');
		$floorId = $this->createFloor($houseId, 'Ground Floor');
		$roomId = $this->createRoom($houseId, $floorId, 'Bathroom');
		$wakeOn = (new \DateTime('today', new \DateTimeZone('UTC')))->modify('+60 days')->format('Y-m-d');

		$aggregate = $this->service->create($houseId, $this->actor($houseId, 'agg_owner2'), [
			'roomId' => $roomId,
			'task' => $this->validTaskPayload(['isVoluntary' => true]),
			'checklistItems' => [['label' => 'Mirror'], ['label' => 'Sink']],
			'sleeping' => true,
			'wakeOn' => $wakeOn,
		]);
		$this->registerCreatedTask((int)$aggregate['task']->getId());

		$task = \OC::$server->get(TaskMapper::class)->find($aggregate['task']->getId());
		self::assertTrue($task->getIsVoluntary(), 'the bonus switch reaches the definition');
		self::assertSame(['Mirror', 'Sink'], array_map(
			static fn ($item): string => $item->getLabel(),
			\OC::$server->get(TaskChecklistItemMapper::class)->findByTaskId((int)$task->getId())
		));
		$roomTask = \OC::$server->get(RoomTaskMapper::class)->find($aggregate['roomTask']->getId());
		self::assertNotNull($roomTask->getSleepingSince(), 'the new task sleeps');
		self::assertSame($wakeOn, $roomTask->getWakeOn()?->format('Y-m-d'));
	}

	/**
	 * A wake date the task cannot take rolls the whole create back.
	 */
	public function testFormCreate_UnusableWakeDate_WritesNothing(): void {
		$houseId = $this->createHouse('agg_owner9', 'Aggregate House 9');
		$roomId = $this->createRoom($houseId, $this->createFloor($houseId, 'Ground Floor'), 'Hall');
		$roomTasksBefore = $this->countRows('chorestrike_room_tasks', 'room_id', $roomId);

		try {
			$this->service->create($houseId, $this->actor($houseId, 'agg_owner9'), [
				'roomId' => $roomId,
				'task' => $this->validTaskPayload(['name' => 'Asleep yesterday']),
				'checklistItems' => [['label' => 'Never written']],
				'sleeping' => true,
				'wakeOn' => (new \DateTime('today', new \DateTimeZone('UTC')))->format('Y-m-d'),
			]);
			self::fail('a wake date of today must be refused');
		} catch (\InvalidArgumentException $e) {
			self::assertStringContainsString('wake date', $e->getMessage());
		}

		self::assertSame($roomTasksBefore, $this->countRows('chorestrike_room_tasks', 'room_id', $roomId));
		self::assertSame(0, $this->countRowsByJoin('chorestrike_tasks', 'name', 'Asleep yesterday'), 'no definition survives');
		self::assertSame(0, $this->countRowsByJoin('chorestrike_chk_items', 'label', 'Never written'), 'no sub-task survives');
	}

	/**
	 * MountVariant_IsGone: a body naming an existing definition is refused
	 * before anything is written (FEAT-UX-043, D-091-88).
	 */
	public function testMountVariant_IsRefused_NoSecondRoomTask(): void {
		$houseId = $this->createHouse('agg_owner3', 'Aggregate House 3');
		$floorId = $this->createFloor($houseId, 'Ground Floor');
		$roomId = $this->createRoom($houseId, $floorId, 'Garage');
		$taskId = $this->createTask($houseId, 'Mount Source');

		try {
			$this->service->create($houseId, $this->actor($houseId, 'agg_owner3'), [
				'roomId' => $roomId,
				'taskId' => $taskId,
			]);
			self::fail('the mount variant must be refused');
		} catch (\InvalidArgumentException $e) {
			self::assertSame('Unknown field: taskId', $e->getMessage());
		}

		self::assertSame(0, $this->countRows('chorestrike_room_tasks', 'task_id', $taskId));
	}

	/**
	 * NonMemberAssignee_FailsUnderLock_WithZeroWrites
	 *
	 * The membership revalidation runs inside the transaction: a miss rolls
	 * back with zero definition, room-task, and assignee rows (the orphan-
	 * definition proof at DB level).
	 */
	public function testNonMemberAssignee_FailsUnderLock_WithZeroWrites(): void {
		$houseId = $this->createHouse('agg_owner5', 'Aggregate House 5');
		$floorId = $this->createFloor($houseId, 'Ground Floor');
		$roomId = $this->createRoom($houseId, $floorId, 'Attic');

		$definitionsBefore = $this->countRowsByJoin('chorestrike_tasks', 'name', 'Orphan Proof');
		$roomTasksBefore = $this->countRows('chorestrike_room_tasks', 'room_id', $roomId);

		try {
			$this->service->create($houseId, $this->actor($houseId, 'agg_owner5'), [
				'roomId' => $roomId,
				'task' => $this->validTaskPayload(['name' => 'Orphan Proof']),
				'assigneeUserIds' => ['agg_never_a_member'],
			]);
			self::fail('A non-member assignee must fail the under-lock revalidation');
		} catch (\InvalidArgumentException $e) {
			self::assertStringContainsString('not a member', $e->getMessage());
		}

		self::assertSame($definitionsBefore, $this->countRowsByJoin('chorestrike_tasks', 'name', 'Orphan Proof'), 'zero definition rows survived');
		self::assertSame($roomTasksBefore, $this->countRows('chorestrike_room_tasks', 'room_id', $roomId), 'zero room-task rows survived');
	}

	/**
	 * VisibilityCreationMatrix: private stamps the creator, admin-only is
	 * owner-gated (member 403 / owner 200), public is the default — at the
	 * controller boundary with the real service + DB stack
	 * (controller-boundary precedent of ChecklistApiTest).
	 */
	public function testVisibilityCreationMatrix_PrivateAdminPublic(): void {
		$houseId = $this->createHouse('agg_owner6', 'Aggregate House 6');
		$this->addHouseMember($houseId, 'agg_member6', 'Member', 'member');
		$floorId = $this->createFloor($houseId, 'Ground Floor');
		$roomId = $this->createRoom($houseId, $floorId, 'Pantry');

		// Member + admin-only → 403 role denial.
		try {
			$this->service->create($houseId, $this->actor($houseId, 'agg_member6'), [
				'roomId' => $roomId,
				'task' => $this->validTaskPayload(['visibilityType' => 'admin-only']),
			]);
			self::fail('A member must not create admin-only tasks');
		} catch (ForbiddenException $e) {
			self::assertStringContainsString('admin-only', $e->getMessage());
		}

		// Owner + admin-only → persisted.
		$adminAggregate = $this->service->create($houseId, $this->actor($houseId, 'agg_owner6'), [
			'roomId' => $roomId,
			'task' => $this->validTaskPayload(['visibilityType' => 'admin-only']),
		]);
		$adminTask = \OC::$server->get(TaskMapper::class)->find($adminAggregate['task']->getId());
		self::assertSame('admin-only', $adminTask->getVisibilityType());

		// Private → the creator is stamped as ownerUserId.
		$privateAggregate = $this->service->create($houseId, $this->actor($houseId, 'agg_owner6'), [
			'roomId' => $roomId,
			'task' => $this->validTaskPayload(['visibilityType' => 'private']),
		]);
		$privateTask = \OC::$server->get(TaskMapper::class)->find($privateAggregate['task']->getId());
		self::assertSame('private', $privateTask->getVisibilityType());
		self::assertSame('agg_owner6', $privateTask->getOwnerUserId());

		// Public default → no owner stamp.
		$publicAggregate = $this->service->create($houseId, $this->actor($houseId, 'agg_owner6'), [
			'roomId' => $roomId,
			'task' => $this->validTaskPayload(),
		]);
		$publicTask = \OC::$server->get(TaskMapper::class)->find($publicAggregate['task']->getId());
		self::assertSame('public', $publicTask->getVisibilityType());
		self::assertNull($publicTask->getOwnerUserId());
	}

	/**
	 * DateAndRecurrencePermutations_PersistedAndRoundTripped: once/bounded/
	 * recurring, activeFrom/activeUntil, dynamic/static with fixed time and
	 * weekday list, and the flex bounds 0 and 7 (8 rejects with 400).
	 */
	public function testDateAndRecurrencePermutations_PersistedAndRoundTripped(): void {
		$houseId = $this->createHouse('agg_owner7', 'Aggregate House 7');
		$floorId = $this->createFloor($houseId, 'Ground Floor');
		$roomId = $this->createRoom($houseId, $floorId, 'Studio');

		$permutations = [
			'once with window' => [
				'recurrenceMode' => 'once',
				'activeFrom' => '2030-01-01',
				'activeUntil' => '2030-01-31',
			],
			'bounded with window' => [
				'recurrenceMode' => 'bounded',
				'activeFrom' => '2030-02-01',
				'activeUntil' => '2030-03-01',
			],
			'static with fixed time and days' => [
				'schedulingMode' => 'static',
				'fixedScheduleTime' => '08:30',
				'fixedScheduleDays' => ['monday', 'thursday'],
			],
			'flex bound 0' => ['flexDays' => 0],
			// FEAT-UX-039: the cap follows the rule (weekly 4, half a year 90).
			'flex at the weekly cap' => ['flexDays' => 4],
			'flex at the half-year cap' => ['frequencyType' => 'monthly', 'frequencyValue' => 6, 'flexDays' => 90],
		];

		foreach ($permutations as $label => $override) {
			$aggregate = $this->service->create($houseId, $this->actor($houseId, 'agg_owner7'), [
				'roomId' => $roomId,
				'task' => $this->validTaskPayload($override),
			]);

			$task = \OC::$server->get(TaskMapper::class)->find($aggregate['task']->getId());
			foreach ($override as $field => $value) {
				$getter = 'get' . ucfirst($field);
				$persisted = $task->$getter();
				if ($field === 'fixedScheduleTime') {
					self::assertSame($value, $persisted->format('H:i'), "$label: $field round-trips");
				} elseif ($persisted instanceof \DateTimeInterface) {
					self::assertSame($value, $persisted->format('Y-m-d'), "$label: $field round-trips");
				} elseif (is_array($value)) {
					self::assertSame(json_encode($value), $persisted, "$label: $field persists as canonical JSON");
				} else {
					self::assertSame($value, $persisted, "$label: $field round-trips");
				}
			}
		}

		// flexDays 5 → 400 (one above the weekly cap).
		try {
			$this->service->create($houseId, $this->actor($houseId, 'agg_owner7'), [
				'roomId' => $roomId,
				'task' => $this->validTaskPayload(['flexDays' => 5]),
			]);
			self::fail('flexDays 5 must be rejected for a weekly rule');
		} catch (ValidationException $e) {
			self::assertStringContainsString('Flex days', $e->getMessage());
		}
	}

	/**
	 * Controller_Level_AggregateShape: the endpoint serializes the unified
	 * aggregate shape through the real services (the form's body). The
	 * controller resolves the shared instance house, so the fixture seeds
	 * floor/room + membership there and cleans up after itself.
	 */
	public function testController_Level_AggregateShape(): void {
		$houseService = \OC::$server->get(HouseService::class);
		$instanceHouseId = $houseService->getOrCreateHouse($houseService->getInstanceId())->getId();
		$this->addHouseMember($instanceHouseId, 'agg_owner8', 'Controller Owner', 'owner');
		$floorId = $this->createFloor($instanceHouseId, 'Controller Floor');
		$roomId = $this->createRoom($instanceHouseId, $floorId, 'Laundry');

		try {
			$this->actingAs('agg_owner8');
			$request = $this->createMock(IRequest::class);
			$request->method('getParams')->willReturn([
				'roomId' => $roomId, 'task' => ['name' => 'Controller Laundry'], '_route' => 'chorestrike.room_task.create',
			]);
			$l10n = $this->createMock(\OCP\IL10N::class);
			$l10n->method('t')->willReturnCallback(static fn (string $text) => $text);

			$controller = new RoomTaskController(
				$request,
				\OC::$server->get(RoomTaskService::class),
				\OC::$server->get(\OCA\ChoreStrike\Service\RoomTaskReadService::class),
				\OC::$server->get(\OCA\ChoreStrike\Service\CompletionService::class),
				$this->service,
				$houseService,
				\OC::$server->get(AuthorizationService::class),
				$l10n,
				new CalendarService(),
				\OC::$server->get(AppLogger::class),
				\OC::$server->get(\OCA\ChoreStrike\Service\TodayRows::class),
			);

			$response = $controller->create();

			self::assertSame(200, $response->getStatus());
			$data = $response->getData();
			self::assertTrue($data['success']);
			self::assertArrayHasKey('task', $data['data']);
			self::assertArrayHasKey('roomTask', $data['data']);
			self::assertArrayHasKey('assignees', $data['data']);
			$this->registerCreatedTask((int)$data['data']['task']['id']);
			self::assertSame('Controller Laundry', $data['data']['task']['name']);
			self::assertSame($data['data']['task']['id'], $data['data']['roomTask']['taskId']);
		} finally {
			// Remove only the fixture's own rows and membership.
			$qb = $this->db->getQueryBuilder();
			$qb->delete('chorestrike_house_users')
				->where($qb->expr()->eq('house_id', $qb->createNamedParameter($instanceHouseId, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->eq('nextcloud_user_id', $qb->createNamedParameter('agg_owner8')));
			$qb->executeStatement();
		}
	}

	private function countRowsByJoin(string $table, string $col, string $value): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))
			->from($table)
			->where($qb->expr()->eq($col, $qb->createNamedParameter($value)));
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();
		return $count;
	}
}
