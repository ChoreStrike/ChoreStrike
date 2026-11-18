<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Abstract base class for ChoreStrike integration tests.
 *
 * Provides DB access via OC::$server, helper methods for creating
 * test fixtures, and tearDown cleanup to keep the shared Docker DB clean.
 *
 * Only usable inside the Docker test container (bootstrap-integration.php
 * exits with an error outside Docker).
 */
abstract class IntegrationTestCase extends TestCase {
	protected IDBConnection $db;
	protected IUserSession $userSession;
	protected IUserManager $userManager;

	/** @var array<int> */
	private array $createdHouseIds = [];
	/** @var array<int> */
	private array $createdFloorIds = [];
	/** @var array<int> */
	private array $createdRoomIds = [];
	/** @var array<int> */
	private array $createdTaskIds = [];
	/** @var array<int> */
	private array $createdRoomTaskIds = [];
	/** @var array<int> */
	private array $createdHouseUserIds = [];
	private array $createdCompletionIds = [];

	protected function setUp(): void {
		parent::setUp();

		$this->db = \OC::$server->get(IDBConnection::class);
		$this->userSession = \OC::$server->get(IUserSession::class);
		$this->userManager = \OC::$server->get(IUserManager::class);
	}

	protected function tearDown(): void {
		$this->cleanupCreatedRecords();
		parent::tearDown();
	}

	/**
	 * Simulate an authenticated user for the current test.
	 */
	protected function actingAs(string $userId): void {
		$user = $this->userManager->get($userId);
		if ($user === null) {
			$user = $this->userManager->createUser($userId, 'test-password-' . $userId);
			// TECH-TEST-003 (CS-PLAN-081 H2): first-time Nextcloud account
			// provisioning on a FRESH install fires listeners that leave an
			// open transaction on the shared connection. Fixture inserts
			// below must not ride inside it — a later rollback erased them
			// on the first run after a disposable-volume reset (deterministic
			// mid-suite failure, rerun-green). Close the dangling transaction
			// before any fixture write; the disposable env tolerates the
			// commit (the listeners' own work completes atomically).
			if ($this->db->inTransaction()) {
				$this->db->commit();
			}
		}
		$this->userSession->setUser($user);
	}

	/**
	 * Obtain a controller instance from the DI container with a simulated user.
	 *
	 * Accepts either a short class name (e.g. `RoomTaskController`) or a
	 * fully qualified class name. Short names are resolved against the
	 * ChoreStrike `Controller` namespace.
	 *
	 * @param string $controllerClass Controller class name (short or FQCN)
	 * @param string $userId The user to authenticate as
	 */
	protected function getController(string $controllerClass, string $userId): object {
		if (!str_contains($controllerClass, '\\')) {
			$controllerClass = '\\OCA\\ChoreStrike\\Controller\\' . $controllerClass;
		}
		$this->actingAs($userId);
		return \OC::$server->get($controllerClass);
	}

	/**
	 * Create a house and register the owner as a house user.
	 *
	 * @return int The house ID
	 */
	protected function createHouse(string $ownerUserId, string $name = 'Test House'): int {
		$this->actingAs($ownerUserId);

		$houseId = $this->createBareHouse($name);
		$this->addHouseMember($houseId, $ownerUserId, $ownerUserId, 'owner');

		return $houseId;
	}

	/**
	 * Create a house WITHOUT any members (fresh-install fixture, e.g. for
	 * first-user bootstrap tests).
	 *
	 * @return int The house ID
	 */
	protected function createBareHouse(string $name = 'Empty House'): int {
		$instanceId = 'test-' . uniqid();
		$now = date('Y-m-d H:i:s');

		$qb = $this->db->getQueryBuilder();
		$qb->insert('chorestrike_houses')
			->values([
				'nextcloud_instance_id' => $qb->createNamedParameter($instanceId),
				'name' => $qb->createNamedParameter($name),
				'house_health' => $qb->createNamedParameter(100),
				'created_at' => $qb->createNamedParameter($now),
				'updated_at' => $qb->createNamedParameter($now),
				// ADR-021 decision 12: the derived model owns everything a
				// fixture house does, also work dated in the past. A test of
				// the cutover sets its own day.
				'cycles_from' => $qb->createNamedParameter('2000-01-01'),
			]);
		$qb->executeStatement();

		$houseId = (int)$this->db->lastInsertId('chorestrike_houses');
		$this->createdHouseIds[] = $houseId;

		return $houseId;
	}

	/**
	 * Create a floor in a house.
	 *
	 * @return int The floor ID
	 */
	protected function createFloor(int $houseId, string $name = 'Ground Floor', int $sortOrder = 1): int {
		$qb = $this->db->getQueryBuilder();
		$qb->insert('chorestrike_floors')
			->values([
				'house_id' => $qb->createNamedParameter($houseId),
				'name' => $qb->createNamedParameter($name),
				'sort_order' => $qb->createNamedParameter($sortOrder),
				'created_at' => $qb->createNamedParameter(date('Y-m-d H:i:s')),
			]);
		$qb->executeStatement();

		$floorId = (int)$this->db->lastInsertId('chorestrike_floors');
		$this->createdFloorIds[] = $floorId;
		return $floorId;
	}

	/**
	 * Create a room on a floor.
	 *
	 * @param int $houseId Unused — retained for caller API consistency
	 * @return int The room ID
	 */
	protected function createRoom(int $houseId, int $floorId, string $name = 'Test Room'): int {
		$qb = $this->db->getQueryBuilder();
		$qb->insert('chorestrike_rooms')
			->values([
				'floor_id' => $qb->createNamedParameter($floorId),
				'name' => $qb->createNamedParameter($name),
				'cleanliness' => $qb->createNamedParameter(50),
				'monster_count' => $qb->createNamedParameter(0),
				'created_at' => $qb->createNamedParameter(date('Y-m-d H:i:s')),
				'icon' => $qb->createNamedParameter('Home'),
			]);
		$qb->executeStatement();

		$roomId = (int)$this->db->lastInsertId('chorestrike_rooms');
		$this->createdRoomIds[] = $roomId;
		return $roomId;
	}

	/**
	 * Create a task definition.
	 *
	 * @param int $houseId Unused — retained for caller API consistency
	 * @return int The task ID
	 */
	protected function createTask(int $houseId, string $title, string $description = '', int $sparks = 5, int $energyCost = 3): int {
		$qb = $this->db->getQueryBuilder();
		$qb->insert('chorestrike_tasks')
			->values([
				'name' => $qb->createNamedParameter($title),
				'description' => $qb->createNamedParameter($description),
				'energy_cost' => $qb->createNamedParameter($energyCost),
				'priority' => $qb->createNamedParameter(2),
				'frequency_type' => $qb->createNamedParameter('weekly'),
				'frequency_value' => $qb->createNamedParameter(1),
				'estimated_minutes' => $qb->createNamedParameter(15),
				'category' => $qb->createNamedParameter('cleaning'),
				'scope_type' => $qb->createNamedParameter('room'),
				'recurrence_mode' => $qb->createNamedParameter('recurring'),
				'scheduling_mode' => $qb->createNamedParameter('dynamic'),
				'created_at' => $qb->createNamedParameter(date('Y-m-d H:i:s')),
			]);
		$qb->executeStatement();

		$taskId = (int)$this->db->lastInsertId('chorestrike_tasks');
		$this->createdTaskIds[] = $taskId;
		return $taskId;
	}

	/**
	 * Assign a task to a room (creates a RoomTask).
	 *
	 * The optional assignee is written to the canonical task_assignees join
	 * table (CS-PLAN-060 WP1, D2) — the legacy assigned_to_user_id column no
	 * longer exists.
	 *
	 * ADR-021: the raw row insert is followed by the production start of
	 * the room task — its first date (the seeded next_due, house-local)
	 * anchors the derived window, so completions against fixture rows work.
	 * A room task due in the past was mounted no later than that day: no
	 * missed cycle predates its room task (CS-PLAN-091 WP-B1 review).
	 *
	 * @return int The room task ID
	 */
	protected function assignTaskToRoom(int $taskId, int $roomId, ?string $assignedToUserId = null, ?string $nextDue = null): int {
		$now = date('Y-m-d H:i:s');
		$qb = $this->db->getQueryBuilder();
		$qb->insert('chorestrike_room_tasks')
			->values([
				'room_id' => $qb->createNamedParameter($roomId),
				'task_id' => $qb->createNamedParameter($taskId),
				'next_due' => $qb->createNamedParameter($nextDue ?? $now),
				'is_active' => $qb->createNamedParameter(1),
				'was_claimed' => $qb->createNamedParameter(0),
				'created_at' => $qb->createNamedParameter($nextDue !== null && $nextDue < $now ? $nextDue : $now),
			]);
		$qb->executeStatement();

		$roomTaskId = (int)$this->db->lastInsertId('chorestrike_room_tasks');
		$this->createdRoomTaskIds[] = $roomTaskId;

		if ($assignedToUserId !== null) {
			$qb = $this->db->getQueryBuilder();
			$qb->insert('chorestrike_task_assignees')
				->values([
					'room_task_id' => $qb->createNamedParameter($roomTaskId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
					'user_id' => $qb->createNamedParameter($assignedToUserId),
					'assigned_at' => $qb->createNamedParameter(date('Y-m-d H:i:s')),
				]);
			$qb->executeStatement();
		}

		$this->initializeScheduleForFixture($roomId, $roomTaskId, $taskId, $assignedToUserId);

		return $roomTaskId;
	}

	/**
	 * Start a fixture room task as creation does (ADR-021): its seeded
	 * next_due day becomes the first date of the derived window.
	 */
	protected function initializeScheduleForFixture(int $roomId, int $roomTaskId, int $taskId, ?string $assignedToUserId): void {
		$houseId = $this->houseIdForRoomId($roomId);
		$timeZone = \OC::$server->get(\OCA\ChoreStrike\Service\RoomTaskReadService::class)->timeZoneForHouse($houseId);
		$roomTask = \OC::$server->get(\OCA\ChoreStrike\Db\RoomTaskMapper::class)->find($roomTaskId);
		\OC::$server->get(\OCA\ChoreStrike\Service\CycleService::class)->startUnderLock(
			$roomTask,
			\OC::$server->get(\OCA\ChoreStrike\Db\TaskMapper::class)->find($taskId),
			\OCA\ChoreStrike\Service\WindowService::todayKey($roomTask->getNextDue(), $timeZone),
			$timeZone,
			new \DateTime(),
		);
	}

	/**
	 * Resolve the owning house id of a room (room → floor → house).
	 */
	protected function houseIdForRoomId(int $roomId): int {
		return \OC::$server->get(\OCA\ChoreStrike\Service\RoomTaskReadService::class)->houseIdForRoom($roomId);
	}

	/**
	 * Build the receipt for completing a fixture room task's CURRENT cycle
	 * (ADR-021 decision 4): the cycle key and cycle version the row shows,
	 * resolved write-free, plus a fresh command UUID. The key is null for
	 * a bonus task.
	 *
	 * @return array{0: string, 1: ?string, 2: int} [commandId, cycleKey, expectedVersion]
	 */
	protected function completionReceiptFor(int $roomTaskId): array {
		$roomTask = \OC::$server->get(\OCA\ChoreStrike\Db\RoomTaskMapper::class)->find($roomTaskId);
		$readService = \OC::$server->get(\OCA\ChoreStrike\Service\RoomTaskReadService::class);
		$block = $readService->cycleFacts($readService->houseIdForRoom($roomTask->getRoomId()), [$roomTask])[$roomTaskId]['block'] ?? null;
		if ($block === null || !isset($block['expectedVersion'])) {
			throw new \RuntimeException('No completable cycle for room task ' . $roomTaskId);
		}
		return [$this->newCommandId(), $block['cycleKey'] ?? null, $block['expectedVersion']];
	}

	/** A fresh command UUID. */
	protected function newCommandId(): string {
		return sprintf('%08x-%04x-4%03x-8%03x-%012x', mt_rand(), mt_rand(0, 0xffff), mt_rand(0, 0xfff), mt_rand(0, 0xfff), mt_rand());
	}

	/**
	 * Complete a fixture room task's current cycle through the full
	 * receipt contract.
	 */
	protected function completeOccurrence(\OCA\ChoreStrike\Service\CompletionService $service, int $houseId, string $userId, int $roomTaskId): array {
		[$commandId, $cycleKey, $expectedVersion] = $this->completionReceiptFor($roomTaskId);
		return $service->complete($houseId, $userId, $roomTaskId, $commandId, $cycleKey, $expectedVersion);
	}

	/**
	 * Complete with an EXPLICIT actual-work split (WP3 §3.6): the
	 * participants carry the actor, `allocationReviewed` covers a
	 * deviation from the default split.
	 *
	 * @param array<int, array{userId: string, sharePct: int}> $participants
	 */
	protected function completeOccurrenceWithParticipants(
		\OCA\ChoreStrike\Service\CompletionService $service,
		int $houseId,
		string $userId,
		int $roomTaskId,
		array $participants,
	): array {
		[$commandId, $cycleKey, $expectedVersion] = $this->completionReceiptFor($roomTaskId);
		return $service->complete($houseId, $userId, $roomTaskId, $commandId, $cycleKey, $expectedVersion, $participants, true);
	}

	/**
	 * Record a closed cycle with explicit days (ADR-021 decision 5), as a
	 * completion or the timeline job leaves it: the `done` row projects the
	 * cycle facts frozen on its completion, a `missed` row stands alone.
	 *
	 * @param int[] $responsible Wallet ids the cycle counts for
	 */
	protected function insertClosedCycle(
		int $houseId,
		int $roomTaskId,
		string $startKey,
		string $endKey,
		array $responsible = [],
		?int $completionId = null,
		string $completionClass = \OCA\ChoreStrike\Db\CompletedTask::CLASS_ON_TIME,
		?string $cycleKey = null,
	): int {
		$cycleKey ??= 'd' . $endKey;
		$row = new \OCA\ChoreStrike\Db\Cycle();
		$row->setHouseId($houseId);
		$row->setRoomTaskId($roomTaskId);
		$row->setCycleKey($cycleKey);
		$row->setWindowStartOn(new \DateTime($startKey . ' 00:00:00 UTC'));
		$row->setWindowEndOn(new \DateTime($endKey . ' 00:00:00 UTC'));
		$row->setWeekStart(new \DateTime(\OCA\ChoreStrike\Service\RecurrenceCalculator::mondayOf($endKey) . ' 00:00:00 UTC'));
		$row->setTimezone('UTC');
		$row->setOutcome($completionId === null ? \OCA\ChoreStrike\Db\Cycle::OUTCOME_MISSED : \OCA\ChoreStrike\Db\Cycle::OUTCOME_DONE);
		$row->setCompletionId($completionId);
		$row->setCompletionClass($completionId === null ? null : $completionClass);
		$row->setEnergy(3);
		$row->setResponsible(array_values($responsible));
		$row->setCreatedAt(new \DateTime());
		$id = (int)\OC::$server->get(\OCA\ChoreStrike\Db\CycleMapper::class)->insert($row)->getId();
		if ($completionId !== null) {
			$qb = $this->db->getQueryBuilder();
			$qb->update('chorestrike_completed_tasks')
				->set('cycle_key', $qb->createNamedParameter($cycleKey))
				->set('completion_class', $qb->createNamedParameter($completionClass))
				->set('window_start_on', $qb->createNamedParameter($startKey))
				->set('window_end_on', $qb->createNamedParameter($endKey))
				->where($qb->expr()->eq('id', $qb->createNamedParameter($completionId, IQueryBuilder::PARAM_INT)))
				->executeStatement();
		}
		return $id;
	}

	/**
	 * Move the first date of a fixture room task that was never completed
	 * (house-local `Y-m-d`), as an edit of the first date does: the
	 * window, the cycle version and the projection follow.
	 */
	protected function setFirstDate(int $roomTaskId, string $dayKey): void {
		$roomTask = \OC::$server->get(\OCA\ChoreStrike\Db\RoomTaskMapper::class)->find($roomTaskId);
		$readService = \OC::$server->get(\OCA\ChoreStrike\Service\RoomTaskReadService::class);
		\OC::$server->get(\OCA\ChoreStrike\Service\CycleService::class)->startUnderLock(
			$roomTask,
			\OC::$server->get(\OCA\ChoreStrike\Db\TaskMapper::class)->find($roomTask->getTaskId()),
			$dayKey,
			$readService->timeZoneForHouse($readService->houseIdForRoom($roomTask->getRoomId())),
			new \DateTime(),
		);
	}

	/**
	 * Undo the LATEST completion of a room task through the targeted
	 * correction endpoint (WP3 §3.4): exact completion id + a fresh
	 * command receipt against the recorded version.
	 */
	protected function undoLatestForRoomTask(
		\OCA\ChoreStrike\Service\CompletionService $service,
		int $houseId,
		string $actorUserId,
		int $roomTaskId,
		int $expectedVersion = 0,
	): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from('chorestrike_completed_tasks')
			->where($qb->expr()->eq('room_task_id', $qb->createNamedParameter($roomTaskId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'DESC')
			->setMaxResults(1);
		$result = $qb->executeQuery();
		$id = $result->fetchOne();
		$result->closeCursor();
		if ($id === false || $id === null) {
			throw new \RuntimeException('no completion row for room task ' . $roomTaskId);
		}

		$actor = \OC::$server->get(\OCA\ChoreStrike\Db\HouseUserMapper::class)
			->findByNextcloudUserId($houseId, $actorUserId);
		return $service->undo(
			$houseId,
			$actor,
			(int)$id,
			$this->newCommandId(),
			$expectedVersion,
			null
		);
	}

	/**
	 * Complete through a REAL controller: injects the cycle receipt into
	 * the controller's request params and calls
	 * the endpoint action. `$participants` adds an explicit actual-work
	 * split (WP3 §3.6 — the actor takes part; Q-074-02).
	 *
	 * @param array<int, array{userId: string, sharePct: int}> $participants
	 */
	protected function completeViaController(object $controller, int $roomTaskId, ?array $participants = null): object {
		[$commandId, $cycleKey, $expectedVersion] = $this->completionReceiptFor($roomTaskId);
		$params = ['commandId' => $commandId, 'expectedVersion' => $expectedVersion];
		if ($cycleKey !== null) {
			$params['cycleKey'] = $cycleKey;
		}
		if ($participants !== null) {
			$params['participants'] = $participants;
			$params['allocationReviewed'] = true;
		}
		$this->injectRequestParams($controller, $params);
		return $controller->complete($roomTaskId);
	}

	/**
	 * Swap a controller's request for a param-carrying stub (reflection on
	 * the AppFramework base's protected $request).
	 */
	protected function injectRequestParams(object $controller, array $params): void {
		$mock = $this->getMockBuilder(\OCP\IRequest::class)->getMock();
		$mock->method('getParams')->willReturn($params);
		$mock->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => $params[$key] ?? $default
		);

		$ref = new \ReflectionClass(\OCP\AppFramework\Controller::class);
		$prop = $ref->getProperty('request');
		$prop->setAccessible(true);
		$prop->setValue($controller, $mock);
	}

	/**
	 * Add a member to a house through the PRODUCTION membership invariants
	 * (WP3): a raw row plus the wallet binding every real membership
	 * carries (the occurrence/rhythm participant chain is wallet-keyed).
	 */
	protected function addHouseMember(int $houseId, string $userId, string $displayName = '', string $role = 'member'): void {
		$this->actingAs($userId);

		$qb = $this->db->getQueryBuilder();
		$qb->insert('chorestrike_house_users')
			->values([
				'house_id' => $qb->createNamedParameter($houseId),
				'nextcloud_user_id' => $qb->createNamedParameter($userId),
				'display_name' => $qb->createNamedParameter($displayName ?: $userId),
				'role' => $qb->createNamedParameter($role),
				'contribution_minor' => $qb->createNamedParameter(0),
				'current_streak' => $qb->createNamedParameter(0),
				'longest_streak' => $qb->createNamedParameter(0),
				// Production memberships (HouseUserMapper::createOrGet) persist
				// the json columns from the entity defaults — a raw NULL row is
				// a state no real member ever has.
				'unlocked_titles' => $qb->createNamedParameter('[]'),
				'joined_at' => $qb->createNamedParameter(date('Y-m-d H:i:s')),
			]);
		$qb->executeStatement();

		$houseUserId = (int)$this->db->lastInsertId('chorestrike_house_users');
		$this->createdHouseUserIds[] = $houseUserId;

		\OC::$server->get(\OCA\ChoreStrike\Service\WalletService::class)
			->ensureBinding($houseId, \OC::$server->get(\OCA\ChoreStrike\Db\HouseUserMapper::class)->find($houseUserId));
	}

	/**
	 * A pending personal pause revision as it could only arise before
	 * ADR-020 (a child's request, or its pending shortening when
	 * `$pauseGroupId` names an existing group). Inserted directly: no
	 * command creates one any more, but stored rows must stay decidable.
	 *
	 * @return array{pauseGroupId: int, revision: int, state: string}
	 */
	protected function seedPendingPauseRequest(int $houseId, string $userId, string $startDate, string $endDate, ?int $pauseGroupId = null): array {
		$mapper = \OC::$server->get(\OCA\ChoreStrike\Db\PauseMapper::class);
		$member = \OC::$server->get(\OCA\ChoreStrike\Db\HouseUserMapper::class)->findByNextcloudUserId($houseId, $userId);
		$house = \OC::$server->get(\OCA\ChoreStrike\Db\HouseMapper::class)->find($houseId);
		$now = new \DateTime();

		$pause = new \OCA\ChoreStrike\Db\Pause();
		$pause->setHouseId($houseId);
		$pause->setPauseGroupId($pauseGroupId ?? $mapper->maxGroupId());
		$pause->setScope(\OCA\ChoreStrike\Db\Pause::SCOPE_PERSONAL);
		$pause->setSubjectWalletId((int)$member->getWalletId());
		$pause->setRequesterHouseUserId((int)$member->getId());
		$pause->setState(\OCA\ChoreStrike\Db\Pause::STATE_REQUESTED);
		$pause->setReasonCode(\OCA\ChoreStrike\Db\Pause::REASON_OTHER);
		$pause->setStartDate(new \DateTime($startDate));
		$pause->setEndDate(new \DateTime($endDate));
		$pause->setTimezone(\OC::$server->get(\OCA\ChoreStrike\Service\CalendarService::class)->getTimeZone($house)->getName());
		$pause->setCreatedAt($now);
		$pause->setUpdatedAt($now);
		$pause = $mapper->appendRevision($pause);

		return ['pauseGroupId' => $pause->getPauseGroupId(), 'revision' => $pause->getRevision(), 'state' => $pause->getState()];
	}

	/**
	 * Insert a raw work-evidence (completed task) row for read-path
	 * fixtures (WP3 §3.1 shape: frozen energy/rate + versioning pair, no
	 * monetary outputs). Writers that need the full aggregate should use
	 * {@see completeOccurrence()} instead.
	 */
	protected function insertCompletionRow(int $roomTaskId, ?int $houseUserId, string $completedAt, int $totalEnergy = 3, ?string $occurrenceDue = null): int {
		$qb = $this->db->getQueryBuilder();
		$qb->insert('chorestrike_completed_tasks')
			->values([
				'room_task_id' => $qb->createNamedParameter($roomTaskId),
				'user_id' => $qb->createNamedParameter($houseUserId),
				'completed_at' => $qb->createNamedParameter($completedAt),
				'total_energy' => $qb->createNamedParameter($totalEnergy),
				'rate_minor' => $qb->createNamedParameter(1000),
				'completion_version' => $qb->createNamedParameter(0),
				'allocation_revision' => $qb->createNamedParameter(1),
				'occurrence_due' => $qb->createNamedParameter($occurrenceDue ?? substr($completedAt, 0, 10)),
				'work_date' => $qb->createNamedParameter(substr($completedAt, 0, 10)),
				'work_zone' => $qb->createNamedParameter('UTC'),
				'completion_class' => $qb->createNamedParameter('agreed'),
			])
			->executeStatement();
		$id = (int)$this->db->lastInsertId('chorestrike_completed_tasks');
		$this->createdCompletionIds[] = $id;
		return $id;
	}

	/**
	 * Remove all records created during the test.
	 */
	private function cleanupCreatedRecords(): void {
		// Discover service/worker-created descendants before any parent is removed.
		$this->createdFloorIds = array_merge($this->createdFloorIds, $this->fixtureIds('chorestrike_floors', 'house_id', $this->createdHouseIds));
		$this->createdRoomIds = array_merge($this->createdRoomIds, $this->fixtureIds('chorestrike_rooms', 'floor_id', $this->createdFloorIds));
		$this->createdRoomTaskIds = array_merge($this->createdRoomTaskIds, $this->fixtureIds('chorestrike_room_tasks', 'room_id', $this->createdRoomIds));
		$this->createdCompletionIds = array_merge($this->createdCompletionIds, $this->fixtureIds('chorestrike_completed_tasks', 'room_task_id', $this->createdRoomTaskIds));
		$this->deleteFixtureRows('chorestrike_work_shares', 'completion_id', $this->createdCompletionIds);
		$this->deleteFixtureRows('chorestrike_completion_revs', 'completion_id', $this->createdCompletionIds);
		$this->deleteFixtureRows('chorestrike_room_events', 'source_completion_id', $this->createdCompletionIds);
		// Baselines/manual events have no completion reference.
		$this->deleteFixtureRows('chorestrike_room_events', 'room_id', $this->createdRoomIds);
		$this->deleteFixtureRows('chorestrike_chk_progress', 'room_task_id', $this->createdRoomTaskIds);
		// ADR-021: closed cycles and per-cycle ticks hang on the room task.
		$this->deleteFixtureRows('chorestrike_cycle_progress', 'room_task_id', $this->createdRoomTaskIds);
		$this->deleteFixtureRows('chorestrike_cycles', 'room_task_id', $this->createdRoomTaskIds);
		$this->deleteTimelineByRoomTaskIds($this->createdRoomTaskIds);
		$this->deleteAssigneesByRoomTaskIds($this->createdRoomTaskIds);
		// WP2 pause/receipt facts are house-scoped — remove them before the
		// house rows go so no test leaks pause groups into later suites.
		$this->deletePauseFactsByHouseIds($this->createdHouseIds);
		// CS-PLAN-070 WP5: the digest queue and slot history are
		// house-scoped — the dispatch pass is instance-global and would
		// otherwise see stale slots of already-deleted houses.
		$this->deleteDigestRowsByHouseIds($this->createdHouseIds);
		// CS-PLAN-070 WP7: the reward tables clear in dependency order —
		// request-events → requests → items — before the wallet/
		// membership/house rows go (no FK cascade exists).
		$this->deleteRewardRowsByHouseIds($this->createdHouseIds);
		foreach (['chorestrike_member_prefs', 'chorestrike_wgoal_receipts', 'chorestrike_minigame_scores'] as $table) {
			$this->deleteFixtureRows($table, 'house_id', $this->createdHouseIds);
		}
		$memberIds = array_merge($this->createdHouseUserIds, $this->fixtureIds('chorestrike_house_users', 'house_id', $this->createdHouseIds));
		$this->deleteFixtureRows('chorestrike_medals', 'user_id', $memberIds);
		$walletIds = $this->fixtureIds('chorestrike_wallets', 'house_id', $this->createdHouseIds);
		$this->deleteFixtureRows('chorestrike_wallet_entries', 'wallet_id', $walletIds);
		$this->deleteByIds('chorestrike_wallets', $walletIds);
		$this->deleteByIds('chorestrike_completed_tasks', $this->createdCompletionIds);
		$this->deleteByIds('chorestrike_room_tasks', $this->createdRoomTaskIds);
		$this->deleteFixtureRows('chorestrike_chk_items', 'task_id', $this->createdTaskIds);
		$this->deleteByIds('chorestrike_tasks', $this->createdTaskIds);
		$this->deleteByIds('chorestrike_rooms', $this->createdRoomIds);
		$this->deleteByIds('chorestrike_floors', $this->createdFloorIds);
		$this->deleteByIds('chorestrike_house_users', $this->createdHouseUserIds);
		$this->deleteHouseUsersByHouseIds($this->createdHouseIds);
		$this->deleteByIds('chorestrike_houses', $this->createdHouseIds);

		$this->createdRoomTaskIds = [];
		$this->createdCompletionIds = [];
		$this->createdTaskIds = [];
		$this->createdRoomIds = [];
		$this->createdFloorIds = [];
		$this->createdHouseUserIds = [];
		$this->createdHouseIds = [];
	}

	/** Register a definition created by a service, never infer ownership from a mount. */
	protected function registerCreatedTask(int $taskId): void {
		$this->createdTaskIds[] = $taskId;
	}

	/** @param int[] $ids @return int[] */
	private function fixtureIds(string $table, string $column, array $ids): array {
		if ($ids === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$result = $qb->select('id')->from($table)
			->where($qb->expr()->in($column, $qb->createNamedParameter(array_values(array_unique($ids)), \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)))
			->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();
		return array_map(static fn (array $row): int => (int)$row['id'], $rows);
	}

	/** @param int[] $ids */
	private function deleteFixtureRows(string $table, string $column, array $ids): void {
		if ($ids === []) {
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->delete($table)
			->where($qb->expr()->in($column, $qb->createNamedParameter(array_values(array_unique($ids)), \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)))
			->executeStatement();
	}

	/**
	 * Remove the WP2 house-scoped pause/command-receipt facts of fixture
	 * houses (CS-PLAN-073): pauses carry no room-task reference and the
	 * receipt log survives actors — both must not leak across suites
	 * (the schema suite pins the global (pause_group_id, revision) key).
	 *
	 * @param array<int> $houseIds
	 */
	private function deletePauseFactsByHouseIds(array $houseIds): void {
		if (empty($houseIds)) {
			return;
		}
		$intArray = \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY;
		$qb = $this->db->getQueryBuilder();
		$qb->delete('chorestrike_pauses')
			->where($qb->expr()->in('house_id', $qb->createNamedParameter($houseIds, $intArray)))
			->executeStatement();
		$qb = $this->db->getQueryBuilder();
		$qb->delete('chorestrike_commands')
			->where($qb->expr()->in('house_id', $qb->createNamedParameter($houseIds, $intArray)))
			->executeStatement();
	}

	/**
	 * Remove the WP5 house-scoped digest rows of fixture houses
	 * (CS-PLAN-070 WP5): the dispatch pass is instance-global — stale
	 * slots of already-deleted houses would poison later suites.
	 *
	 * @param array<int> $houseIds
	 */
	private function deleteDigestRowsByHouseIds(array $houseIds): void {
		if (empty($houseIds)) {
			return;
		}
		$intArray = \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY;
		$qb = $this->db->getQueryBuilder();
		$qb->delete('chorestrike_digest_changes')
			->where($qb->expr()->in('house_id', $qb->createNamedParameter($houseIds, $intArray)))
			->executeStatement();
		$qb = $this->db->getQueryBuilder();
		$qb->delete('chorestrike_digest_slots')
			->where($qb->expr()->in('house_id', $qb->createNamedParameter($houseIds, $intArray)))
			->executeStatement();
	}

	/**
	 * Credit a fixture wallet through the REAL ledger path so the
	 * integration fixture mirrors production earning (B equals the entry
	 * sum by construction; the unique source identity avoids collisions
	 * across tests).
	 */
	protected function creditTestWallet(int $houseId, int $walletId, int $amountMinor, ?int $actorHouseUserId): void {
		\OC::$server->get(\OCA\ChoreStrike\Service\WalletService::class)->postEntries(
			$houseId,
			'test_credit',
			random_int(1, 2147483000),
			0,
			[$walletId => $amountMinor],
			'test.credit',
			$actorHouseUserId,
		);
	}

	/**
	 * Remove the WP7 reward rows of fixture houses in dependency order
	 * (CS-PLAN-078 §3.1): events → requests → items. Events carry no
	 * house column, so they resolve through the request ids first.
	 *
	 * @param array<int> $houseIds
	 */
	private function deleteRewardRowsByHouseIds(array $houseIds): void {
		if (empty($houseIds)) {
			return;
		}
		$intArray = \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY;

		$qb = $this->db->getQueryBuilder();
		$requestIds = array_map(
			static fn (array $row): int => (int)$row['id'],
			$qb->select('id')->from('chorestrike_reward_requests')
				->where($qb->expr()->in('house_id', $qb->createNamedParameter($houseIds, $intArray)))
				->executeQuery()->fetchAll()
		);
		if ($requestIds !== []) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('chorestrike_request_events')
				->where($qb->expr()->in('request_id', $qb->createNamedParameter($requestIds, $intArray)))
				->executeStatement();
		}
		$qb = $this->db->getQueryBuilder();
		$qb->delete('chorestrike_reward_requests')
			->where($qb->expr()->in('house_id', $qb->createNamedParameter($houseIds, $intArray)))
			->executeStatement();
		$qb = $this->db->getQueryBuilder();
		$qb->delete('chorestrike_reward_items')
			->where($qb->expr()->in('house_id', $qb->createNamedParameter($houseIds, $intArray)))
			->executeStatement();
	}

	/**
	 * Remove the timeline rows of fixture room tasks (CS-PLAN-072 WP1):
	 * agreements → occ_members → occurrences → schedule_revs.
	 *
	 * @param array<int> $ids
	 */
	private function deleteTimelineByRoomTaskIds(array $ids): void {
		if (empty($ids)) {
			return;
		}
		$intArray = \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY;
		$qb = $this->db->getQueryBuilder();

		$occIds = array_map(
			static fn (array $row): int => (int)$row['id'],
			$qb->select('id')->from('chorestrike_occurrences')
				->where($qb->expr()->in('room_task_id', $qb->createNamedParameter($ids, $intArray)))
				->executeQuery()->fetchAll()
		);

		if ($occIds !== []) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('chorestrike_agreements')
				->where($qb->expr()->in('occurrence_id', $qb->createNamedParameter($occIds, $intArray)))
				->executeStatement();
			$qb = $this->db->getQueryBuilder();
			$qb->delete('chorestrike_occ_members')
				->where($qb->expr()->in('occurrence_id', $qb->createNamedParameter($occIds, $intArray)))
				->executeStatement();
			$this->deleteByIds('chorestrike_occurrences', $occIds);
		}

		$qb = $this->db->getQueryBuilder();
		$qb->delete('chorestrike_schedule_revs')
			->where($qb->expr()->in('room_task_id', $qb->createNamedParameter($ids, $intArray)))
			->executeStatement();
	}

	/**
	 * @param array<int> $ids
	 */
	private function deleteByIds(string $tableName, array $ids): void {
		if (empty($ids)) {
			return;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->delete($tableName)
			->where($qb->expr()->in('id', $qb->createNamedParameter($ids, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)));
		$qb->executeStatement();
	}

	/**
	 * Remove task_assignees rows that reference the given room tasks — the
	 * schema has no FK cascade, so a lingering join row would survive the
	 * room-task delete.
	 *
	 * @param array<int> $ids
	 */
	private function deleteAssigneesByRoomTaskIds(array $ids): void {
		if (empty($ids)) {
			return;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->delete('chorestrike_task_assignees')
			->where($qb->expr()->in('room_task_id', $qb->createNamedParameter($ids, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)));
		$qb->executeStatement();
	}

	/**
	 * Remove house_users rows that reference the given houses. Catches
	 * memberships created through services (e.g. ensureHouseMember bootstrap)
	 * rather than addHouseMember(), which are not in $createdHouseUserIds.
	 *
	 * @param array<int> $houseIds
	 */
	private function deleteHouseUsersByHouseIds(array $houseIds): void {
		if (empty($houseIds)) {
			return;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->delete('chorestrike_house_users')
			->where($qb->expr()->in('house_id', $qb->createNamedParameter($houseIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)));
		$qb->executeStatement();
	}
}
