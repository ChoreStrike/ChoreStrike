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
	protected function createTask(int $houseId, string $title, string $description = '', int $sparks = 5, int $energyCost = 10): int {
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
	 * @return int The room task ID
	 */
	protected function assignTaskToRoom(int $taskId, int $roomId, ?string $assignedToUserId = null): int {
		$qb = $this->db->getQueryBuilder();
		$qb->insert('chorestrike_room_tasks')
			->values([
				'room_id' => $qb->createNamedParameter($roomId),
				'task_id' => $qb->createNamedParameter($taskId),
				'next_due' => $qb->createNamedParameter(date('Y-m-d H:i:s')),
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

		return $roomTaskId;
	}

	/**
	 * Add a member to a house.
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
				'contribution_score' => $qb->createNamedParameter(0),
				'coins' => $qb->createNamedParameter(0),
				'current_streak' => $qb->createNamedParameter(0),
				'longest_streak' => $qb->createNamedParameter(0),
				'streak_freezes' => $qb->createNamedParameter(1),
				'joined_at' => $qb->createNamedParameter(date('Y-m-d H:i:s')),
			]);
		$qb->executeStatement();

		$houseUserId = (int)$this->db->lastInsertId('chorestrike_house_users');
		$this->createdHouseUserIds[] = $houseUserId;
	}

	/**
	 * Remove all records created during the test.
	 */
	private function cleanupCreatedRecords(): void {
		$this->deleteAssigneesByRoomTaskIds($this->createdRoomTaskIds);
		$this->deleteByIds('chorestrike_room_tasks', $this->createdRoomTaskIds);
		$this->deleteByIds('chorestrike_tasks', $this->createdTaskIds);
		$this->deleteByIds('chorestrike_rooms', $this->createdRoomIds);
		$this->deleteByIds('chorestrike_floors', $this->createdFloorIds);
		$this->deleteByIds('chorestrike_house_users', $this->createdHouseUserIds);
		$this->deleteHouseUsersByHouseIds($this->createdHouseIds);
		$this->deleteByIds('chorestrike_houses', $this->createdHouseIds);

		$this->createdRoomTaskIds = [];
		$this->createdTaskIds = [];
		$this->createdRoomIds = [];
		$this->createdFloorIds = [];
		$this->createdHouseUserIds = [];
		$this->createdHouseIds = [];
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
