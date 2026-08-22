<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Controller;

use OCA\ChoreStrike\Controller\RoomTaskController;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\RoomTaskService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IRequest;

/**
 * Real-DB integration tests for the bulk-assign endpoint (CS-PLAN-042,
 * CR-SVC-61): the controller delegates to
 * {@see RoomTaskService::bulkAssignTasks}, whose house-scoped pre-validation
 * skips foreign/missing ids per item while a non-member target user fails
 * the whole request up front.
 *
 * The controller resolves the shared instance house, so controller-level
 * tests run against it (HouseActivityIntegrationTest pattern); strict
 * cross-house isolation is proven with an isolated fixture house. The
 * request is a controlled IRequest mock carrying the JSON payload — the
 * real service/DB stack below it is unmocked.
 */
class BulkAssignIntegrationTest extends IntegrationTestCase {
	private const MEMBER = 'bulkassign_member';

	private function sharedHouseId(): int {
		$houseService = \OC::$server->get(HouseService::class);
		$house = $houseService->getOrCreateHouse($houseService->getInstanceId());

		return $house->getId();
	}

	private function bootstrapMembership(int $houseId, string $userId): void {
		$this->actingAs($userId);
		\OC::$server->get(AuthorizationService::class)->ensureHouseMember($houseId);
	}

	private function buildController(string $userId, array $params): RoomTaskController {
		$this->actingAs($userId);
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')
			->willReturnCallback(fn (string $key, mixed $default = null): mixed => $params[$key] ?? $default);

		return new RoomTaskController(
			$request,
			\OC::$server->get(RoomTaskService::class),
			\OC::$server->get(HouseService::class),
			\OC::$server->get(AuthorizationService::class),
		);
	}

	/**
	 * Seed room tasks of one house; returns their ids in input order.
	 *
	 * @return array{floorId: int, roomTaskIds: list<int>}
	 */
	private function seedRoomTasks(int $houseId, array $names): array {
		$floorId = $this->createFloor($houseId, 'Bulk Assign Floor');
		$roomId = $this->createRoom($houseId, $floorId, 'Bulk Assign Room');
		$roomTaskIds = [];
		foreach ($names as $name) {
			$taskId = $this->createTask($houseId, $name);
			$roomTaskIds[] = $this->assignTaskToRoom($taskId, $roomId);
		}

		return ['floorId' => $floorId, 'roomTaskIds' => $roomTaskIds];
	}

	private function persistedAssignee(int $roomTaskId): ?string {
		$qb = $this->db->getQueryBuilder();
		$qb->select('assigned_to_user_id')
			->from('chorestrike_room_tasks')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)));
		$row = $qb->executeQuery()->fetch();

		return $row === false ? null : $row['assigned_to_user_id'];
	}

	/**
	 * BulkAssign_OwnTasks_AssignedInInputOrder
	 *
	 * Happy path: every requested id belongs to the caller's house — both
	 * are assigned and returned in input order with persisted state.
	 */
	public function testBulkAssign_OwnTasks_AssignedInInputOrder(): void {
		$houseId = $this->sharedHouseId();
		$this->bootstrapMembership($houseId, self::MEMBER);
		['roomTaskIds' => $roomTaskIds] = $this->seedRoomTasks($houseId, ['Dishes', 'Laundry']);
		[$rtA, $rtB] = $roomTaskIds;

		$controller = $this->buildController(self::MEMBER, [
			'userId' => self::MEMBER,
			'roomTaskIds' => [$rtA, $rtB],
		]);
		$response = $controller->bulkAssign();

		self::assertSame(200, $response->getStatus());
		$data = $response->getData();
		self::assertTrue($data['success']);
		self::assertSame(
			[$rtA, $rtB],
			array_map(static fn (array $rt): int => $rt['id'], $data['data']),
			'both own tasks come back in input order'
		);
		foreach ($data['data'] as $rt) {
			self::assertSame(self::MEMBER, $rt['assignedToUserId']);
		}
		self::assertSame(self::MEMBER, $this->persistedAssignee($rtA));
		self::assertSame(self::MEMBER, $this->persistedAssignee($rtB));
	}

	/**
	 * BulkAssign_ForeignOrMissingIds_Skipped_OwnTasksStillAssigned
	 *
	 * Per-item isolation on a real cross-house id: the fixture house's task
	 * and a missing id are skipped without aborting the batch, and the
	 * foreign task stays untouched.
	 */
	public function testBulkAssign_ForeignOrMissingIds_Skipped_OwnTasksStillAssigned(): void {
		$houseId = $this->sharedHouseId();
		$this->bootstrapMembership($houseId, self::MEMBER);
		['roomTaskIds' => $ownIds] = $this->seedRoomTasks($houseId, ['Dishes', 'Laundry']);

		// Isolated fixture house whose task id must never be written.
		$otherHouseId = $this->createHouse('bulkassign_other_owner', 'Bulk Assign Other House');
		['roomTaskIds' => $foreignIds] = $this->seedRoomTasks($otherHouseId, ['Foreign Dishes']);
		$foreignId = $foreignIds[0];

		$controller = $this->buildController(self::MEMBER, [
			'userId' => self::MEMBER,
			'roomTaskIds' => [$ownIds[0], $foreignId, 999999999, $ownIds[1]],
		]);
		$response = $controller->bulkAssign();

		self::assertSame(200, $response->getStatus());
		$data = $response->getData();
		self::assertTrue($data['success']);
		self::assertSame(
			[$ownIds[0], $ownIds[1]],
			array_map(static fn (array $rt): int => $rt['id'], $data['data']),
			'only the caller\'s own tasks come back, in input order'
		);
		self::assertNull($this->persistedAssignee($foreignId), 'the foreign task must stay unassigned');
		self::assertSame(self::MEMBER, $this->persistedAssignee($ownIds[0]), 'own tasks are still assigned');
		self::assertSame(self::MEMBER, $this->persistedAssignee($ownIds[1]), 'own tasks are still assigned');
	}

	/**
	 * BulkAssign_NonMemberTarget_Returns400WithoutWrites
	 *
	 * The target user is a request-level parameter: a non-member fails the
	 * whole call up front (service-curated InvalidArgumentException → 400)
	 * before any write happens.
	 */
	public function testBulkAssign_NonMemberTarget_Returns400WithoutWrites(): void {
		$houseId = $this->sharedHouseId();
		$this->bootstrapMembership($houseId, self::MEMBER);
		['roomTaskIds' => $ownIds] = $this->seedRoomTasks($houseId, ['Dishes']);

		$controller = $this->buildController(self::MEMBER, [
			'userId' => 'bulkassign_stranger',
			'roomTaskIds' => [$ownIds[0]],
		]);
		$response = $controller->bulkAssign();

		self::assertSame(400, $response->getStatus());
		$data = $response->getData();
		self::assertFalse($data['success']);
		self::assertSame('VALIDATION_ERROR', $data['error']['code']);
		self::assertNull($this->persistedAssignee($ownIds[0]), 'no write may happen for a rejected request');
	}

	/**
	 * BulkAssign_InvalidPayloads_Returns400
	 *
	 * @dataProvider invalidPayloadProvider
	 */
	public function testBulkAssign_InvalidPayloads_Returns400(array $params): void {
		$houseId = $this->sharedHouseId();
		$this->bootstrapMembership($houseId, self::MEMBER);
		['roomTaskIds' => $ownIds] = $this->seedRoomTasks($houseId, ['Dishes']);

		$controller = $this->buildController(self::MEMBER, $params);
		$response = $controller->bulkAssign();

		self::assertSame(400, $response->getStatus());
		$data = $response->getData();
		self::assertFalse($data['success']);
		self::assertSame('VALIDATION_ERROR', $data['error']['code']);
		self::assertNull($this->persistedAssignee($ownIds[0]));
	}

	public static function invalidPayloadProvider(): array {
		return [
			'missing userId' => [['roomTaskIds' => [1]]],
			'blank userId' => [['userId' => '   ', 'roomTaskIds' => [1]]],
			'roomTaskIds not an array' => [['userId' => 'bulkassign_member', 'roomTaskIds' => 5]],
			'non-numeric element' => [['userId' => 'bulkassign_member', 'roomTaskIds' => ['abc']]],
			'non-positive element' => [['userId' => 'bulkassign_member', 'roomTaskIds' => [0]]],
		];
	}
}
