<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Controller;

use OCA\ChoreStrike\Controller\RoomTaskController;
use OCA\ChoreStrike\Db\TaskAssigneeMapper;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * Real-DB authorization tests for GET /room-tasks house scoping
 * (CR-053-02): a caller-supplied roomId must belong to the caller's house
 * (404 otherwise), and a scopeId fetch must never leak cross-house entries
 * (skip-on-missing intersection), while a same-house read returns the
 * entries incl. assignee user ids.
 */
class RoomTaskIndexAuthorizationTest extends IntegrationTestCase {
	private const CALLER = 'rtidx_member';
	private const FOREIGN_OWNER = 'rtidx_foreign_owner';

	private int $instanceHouseId = 0;
	/** @var array<int> */
	private array $roomTaskIds = [];

	protected function tearDown(): void {
		// Assignee rows have no FK constraint and are not tracked by the
		// base cleanup — remove them before the room tasks go away.
		if ($this->roomTaskIds !== []) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('chorestrike_task_assignees')
				->where($qb->expr()->in(
					'room_task_id',
					$qb->createNamedParameter($this->roomTaskIds, IQueryBuilder::PARAM_INT_ARRAY)
				));
			$qb->executeStatement();
		}

		parent::tearDown();
	}

	/**
	 * Index_ForeignRoomId_Returns404
	 */
	public function testIndex_ForeignRoomId_Returns404(): void {
		$foreignHouseId = $this->createHouse(self::FOREIGN_OWNER, 'RT Idx Foreign House');
		$foreignFloorId = $this->createFloor($foreignHouseId, 'Foreign Floor');
		$foreignRoomId = $this->createRoom($foreignHouseId, $foreignFloorId, 'Foreign Kitchen');
		$this->seedCallerMembership();

		$controller = $this->getController(RoomTaskController::class, self::CALLER);
		$response = $controller->index($foreignRoomId);

		self::assertSame(404, $response->getStatus());
		$data = $response->getData();
		self::assertFalse($data['success']);
		self::assertSame('RESOURCE_NOT_FOUND', $data['error']['code']);
	}

	/**
	 * Index_SameHouseRoom_ReturnsRoomTasksWithAssignees
	 */
	public function testIndex_SameHouseRoom_ReturnsRoomTasksWithAssignees(): void {
		$houseId = $this->resolveInstanceHouseId();
		$this->seedCallerMembership();

		$floorId = $this->createFloor($houseId, 'RT Idx Floor');
		$roomId = $this->createRoom($houseId, $floorId, 'RT Idx Room');
		$taskId = $this->createTask($houseId, 'RT Idx Task');
		$roomTaskId = $this->trackRoomTask($this->assignTaskToRoom($taskId, $roomId));

		\OC::$server->get(TaskAssigneeMapper::class)->addAssignee($roomTaskId, self::CALLER);

		$controller = $this->getController(RoomTaskController::class, self::CALLER);
		$response = $controller->index($roomId);

		self::assertSame(200, $response->getStatus());
		$data = $response->getData();
		self::assertTrue($data['success']);
		self::assertCount(1, $data['data']);
		self::assertSame($roomTaskId, $data['data'][0]['id']);
		self::assertSame([self::CALLER], $data['data'][0]['assignees']);
	}

	/**
	 * Index_ForeignScopeId_ReturnsNoCrossHouseRows
	 *
	 * Two room tasks share one scope_id, one per house: the caller must
	 * only ever see their own house's entry.
	 */
	public function testIndex_ForeignScopeId_ReturnsNoCrossHouseRows(): void {
		$houseId = $this->resolveInstanceHouseId();
		$this->seedCallerMembership();

		$ownFloorId = $this->createFloor($houseId, 'RT Idx Scope Floor');
		$ownRoomId = $this->createRoom($houseId, $ownFloorId, 'RT Idx Scope Room');
		$ownTaskId = $this->createTask($houseId, 'RT Idx Own Scoped Task');
		$ownRoomTaskId = $this->trackRoomTask($this->assignTaskToRoom($ownTaskId, $ownRoomId));

		$foreignHouseId = $this->createHouse(self::FOREIGN_OWNER, 'RT Idx Scope Foreign House');
		$foreignFloorId = $this->createFloor($foreignHouseId, 'Foreign Scope Floor');
		$foreignRoomId = $this->createRoom($foreignHouseId, $foreignFloorId, 'Foreign Scope Room');
		$foreignTaskId = $this->createTask($foreignHouseId, 'RT Idx Foreign Scoped Task');
		$foreignRoomTaskId = $this->trackRoomTask($this->assignTaskToRoom($foreignTaskId, $foreignRoomId));

		$scopeId = 987654300 + random_int(0, 699);
		$this->setScopeId($ownRoomTaskId, $scopeId);
		$this->setScopeId($foreignRoomTaskId, $scopeId);

		$controller = $this->getController(RoomTaskController::class, self::CALLER);
		$response = $controller->index(null, $scopeId);

		self::assertSame(200, $response->getStatus());
		$data = $response->getData();
		self::assertTrue($data['success']);
		self::assertCount(1, $data['data'], 'the foreign house entry must be absent');
		self::assertSame($ownRoomTaskId, $data['data'][0]['id']);
	}

	private function seedCallerMembership(): void {
		$this->addHouseMember($this->resolveInstanceHouseId(), self::CALLER, 'RT Idx Member', 'member');
	}

	/**
	 * Resolve (and cache) the shared instance house the controller uses
	 * via getInstanceId() + getOrCreateHouse().
	 */
	private function resolveInstanceHouseId(): int {
		if ($this->instanceHouseId === 0) {
			$houseService = \OC::$server->get(HouseService::class);
			$this->instanceHouseId = $houseService
				->getOrCreateHouse($houseService->getInstanceId())
				->getId();
		}

		return $this->instanceHouseId;
	}

	private function trackRoomTask(int $roomTaskId): int {
		$this->roomTaskIds[] = $roomTaskId;
		return $roomTaskId;
	}

	private function setScopeId(int $roomTaskId, int $scopeId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('chorestrike_room_tasks')
			->set('scope_id', $qb->createNamedParameter($scopeId, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}
}
