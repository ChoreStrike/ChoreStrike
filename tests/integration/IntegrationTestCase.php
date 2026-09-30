<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration;

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

	/** @var array<string, int> Tracked house IDs for cleanup (tableName => [ids]) */
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
	 * CS-PLAN-072 WP1: the raw row insert is followed by the immutable
	 * initial schedule (revision 1 + agreed occurrences) through the
	 * common OccurrenceService generator — the timeline is the only
	 * recurrence authority, so completions against fixture rows work.
	 *
	 * @return int The room task ID
	 */
	protected function assignTaskToRoom(int $taskId, int $roomId, ?string $assignedToUserId = null, ?string $nextDue = null): int {
		$qb = $this->db->getQueryBuilder();
		$qb->insert('chorestrike_room_tasks')
			->values([
				'room_id' => $qb->createNamedParameter($roomId),
				'task_id' => $qb->createNamedParameter($taskId),
				'next_due' => $qb->createNamedParameter($nextDue ?? date('Y-m-d H:i:s')),
				'is_active' => $qb->createNamedParameter(1),
				'was_claimed' => $qb->createNamedParameter(0),
				'created_at' => $qb->createNamedParameter(date('Y-m-d H:i:s')),
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
	 * Materialize the immutable initial schedule for a fixture room task
	 * (CS-PLAN-072 WP1) through the production generator. The schedule
	 * anchors on the seeded next_due (the fixture's chosen first date).
	 */
	protected function initializeScheduleForFixture(int $roomId, int $roomTaskId, int $taskId, ?string $assignedToUserId): void {
		$houseId = $this->houseIdForRoomId($roomId);
		$readService = \OC::$server->get(\OCA\ChoreStrike\Service\RoomTaskReadService::class);
		$occurrenceService = \OC::$server->get(\OCA\ChoreStrike\Service\OccurrenceService::class);
		$taskMapper = \OC::$server->get(\OCA\ChoreStrike\Db\TaskMapper::class);
		$roomTaskMapper = \OC::$server->get(\OCA\ChoreStrike\Db\RoomTaskMapper::class);

		$roomTask = $roomTaskMapper->find($roomTaskId);
		$occurrenceService->initializeSchedule(
			$houseId,
			$roomTask,
			$taskMapper->find($taskId),
			$readService->timeZoneForHouse($houseId)->getName(),
			$roomTask->getNextDue(),
			$assignedToUserId !== null ? [$assignedToUserId] : [],
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
	 * Build the occurrence receipt for completing a fixture room task's
	 * CURRENT canonical target (CS-PLAN-072 WP1): resolves the selection
	 * (public key, version, token) write-free and generates a fresh
	 * command UUID.
	 *
	 * @return array{0: string, 1: string, 2: int, 3: string} [commandId, occurrenceId, expectedVersion, selectionToken]
	 */
	protected function completionReceiptFor(int $roomTaskId): array {
		$occurrenceService = \OC::$server->get(\OCA\ChoreStrike\Service\OccurrenceService::class);
		$roomTaskMapper = \OC::$server->get(\OCA\ChoreStrike\Db\RoomTaskMapper::class);
		$roomTask = $roomTaskMapper->find($roomTaskId);
		$houseId = \OC::$server->get(\OCA\ChoreStrike\Service\RoomTaskReadService::class)
			->houseIdForRoom($roomTask->getRoomId());
		$selection = $occurrenceService->selectionFor($roomTask, new \DateTime(), $houseId);
		if ($selection === null) {
			throw new \RuntimeException('No completable occurrence target for room task ' . $roomTaskId);
		}
		return [
			sprintf('%08x-%04x-4%03x-8%03x-%012x', mt_rand(), mt_rand(0, 0xffff), mt_rand(0, 0xfff), mt_rand(0, 0xfff), mt_rand()),
			$selection['occurrence']->toPublicKey(),
			$selection['occurrence']->getVersion(),
			$occurrenceService->selectionToken($selection['occurrence'], $selection['revision'], $selection['classification'], $houseId, null, $selection['workAppointment'] ?? null),
		];
	}

	/**
	 * Complete a fixture room task's current canonical occurrence target
	 * through the full receipt contract (CS-PLAN-072 WP1).
	 */
	protected function completeOccurrence(\OCA\ChoreStrike\Service\CompletionService $service, int $houseId, string $userId, int $roomTaskId): array {
		[$commandId, $occurrenceId, $expectedVersion, $selectionToken] = $this->completionReceiptFor($roomTaskId);
		return $service->complete($houseId, $userId, $roomTaskId, $commandId, $occurrenceId, $expectedVersion, $selectionToken);
	}

	/**
	 * Complete with an EXPLICIT actual-work split (WP3 §3.6): the
	 * participants carry the actor, `allocationReviewed` covers a
	 * deviation from the agreed proposal.
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
		[$commandId, $occurrenceId, $expectedVersion, $selectionToken] = $this->completionReceiptFor($roomTaskId);
		return $service->complete($houseId, $userId, $roomTaskId, $commandId, $occurrenceId, $expectedVersion, $selectionToken, $participants, true);
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
			sprintf('%08x-%04x-4%03x-8%03x-%012x', mt_rand(), mt_rand(0, 0xffff), mt_rand(0, 0xfff), mt_rand(0, 0xfff), mt_rand()),
			$expectedVersion,
			null
		);
	}

	/**
	 * Complete through a REAL controller (CS-PLAN-072 WP1): injects the
	 * occurrence receipt into the controller's request params and calls
	 * the endpoint action. `$participants` adds an explicit actual-work
	 * split (WP3 §3.6 — the actor takes part; Q-074-02).
	 *
	 * @param array<int, array{userId: string, sharePct: int}> $participants
	 */
	protected function completeViaController(object $controller, int $roomTaskId, ?array $participants = null): object {
		[$commandId, $occurrenceId, $expectedVersion, $selectionToken] = $this->completionReceiptFor($roomTaskId);
		$params = [
			'commandId' => $commandId,
			'occurrenceId' => $occurrenceId,
			'expectedVersion' => $expectedVersion,
			'selectionToken' => $selectionToken,
		];
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
		$this->deleteTimelineByRoomTaskIds($this->createdRoomTaskIds);
		$this->deleteAssigneesByRoomTaskIds($this->createdRoomTaskIds);
		// WP2 pause/receipt facts are house-scoped — remove them before the
		// house rows go so no test leaks pause groups into later suites.
		$this->deletePauseFactsByHouseIds($this->createdHouseIds);
		// CS-PLAN-070 WP5: the digest queue and slot history are
		// house-scoped — the dispatch pass is instance-global and would
		// otherwise see stale slots of already-deleted houses.
		$this->deleteDigestRowsByHouseIds($this->createdHouseIds);
		$this->deleteByIds('chorestrike_room_tasks', $this->createdRoomTaskIds);
		$this->deleteByIds('chorestrike_completed_tasks', $this->createdCompletionIds);
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
