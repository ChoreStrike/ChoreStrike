<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Db;

use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\TaskAssigneeMapper;
use OCA\ChoreStrike\Exception\NotFoundException;
use OCA\ChoreStrike\Service\RoomTaskService;
use OCA\ChoreStrike\Service\TaskCreationService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * Assignee unification (CS-PLAN-060 WP1 §3.3, D2): the
 * `chorestrike_task_assignees` join table is the only persisted assignee
 * representation — every write lands there, every read comes from there,
 * and the legacy `room_tasks.assigned_to_user_id` column no longer exists.
 */
class AssigneeRepresentationTest extends IntegrationTestCase {
	private RoomTaskService $roomTaskService;
	private TaskCreationService $taskCreationService;
	private TaskAssigneeMapper $taskAssigneeMapper;

	protected function setUp(): void {
		parent::setUp();
		$this->roomTaskService = \OC::$server->get(RoomTaskService::class);
		$this->taskCreationService = \OC::$server->get(TaskCreationService::class);
		$this->taskAssigneeMapper = \OC::$server->get(TaskAssigneeMapper::class);
	}

	/**
	 * Resolve the acting user's house membership row (CS-PLAN-061: services
	 * take an explicit HouseUser actor).
	 */
	private function actor(int $houseId, string $userId): \OCA\ChoreStrike\Db\HouseUser {
		return \OC::$server->get(HouseUserMapper::class)->findByNextcloudUserId($houseId, $userId);
	}

	/**
	 * CreateRoomTask_WithAssignee_WritesJoinTableRows
	 *
	 * Initial assignment writes join rows through the creation aggregate
	 * (CS-PLAN-063); the schema no longer carries the legacy column and the
	 * payload no longer exposes it.
	 */
	public function testCreateRoomTask_WithAssignee_WritesJoinTableRows(): void {
		$houseId = $this->createHouse('asg_owner', 'Assignee House');
		$this->addHouseMember($houseId, 'asg_member', 'Member', 'member');
		$floorId = $this->createFloor($houseId, 'Ground Floor', 1);
		$roomId = $this->createRoom($houseId, $floorId, 'Kitchen');
		$taskId = $this->createTask($houseId, 'Dishes');

		$aggregate = $this->taskCreationService->create($houseId, $this->actor($houseId, 'asg_owner'), [
			'roomId' => $roomId,
			'taskId' => $taskId,
			'assigneeUserIds' => ['asg_member'],
		]);
		$roomTask = $aggregate['roomTask'];

		self::assertSame(
			['asg_member'],
			$this->taskAssigneeMapper->findUserIdsByRoomTask($roomTask->getId()),
			'the initial assignment must land in the join table'
		);
		self::assertArrayNotHasKey(
			'assignedToUserId',
			$roomTask->jsonSerialize(),
			'the legacy payload key must be gone'
		);
	}

	/**
	 * AssignAndUnassign_LeaveCanonicalJoinTableStateOnly
	 */
	public function testAssignAndUnassign_LeaveCanonicalJoinTableStateOnly(): void {
		$houseId = $this->createHouse('asg_owner2', 'Assignee House 2');
		$this->addHouseMember($houseId, 'asg_member2', 'Member 2', 'member');
		$floorId = $this->createFloor($houseId, 'Ground Floor', 1);
		$roomId = $this->createRoom($houseId, $floorId, 'Kitchen');
		$taskId = $this->createTask($houseId, 'Trash');

		$this->actingAs('asg_owner2');
		$aggregate = $this->taskCreationService->create($houseId, $this->actor($houseId, 'asg_owner2'), [
			'roomId' => $roomId,
			'taskId' => $taskId,
		]);
		$roomTask = $aggregate['roomTask'];
		$owner = $this->actor($houseId, 'asg_owner2');

		$this->roomTaskService->setAssignees($roomTask->getId(), ['asg_member2'], $houseId, $owner);
		self::assertSame(['asg_member2'], $this->taskAssigneeMapper->findUserIdsByRoomTask($roomTask->getId()));

		$this->roomTaskService->setAssignees($roomTask->getId(), [], $houseId, $owner);
		self::assertSame([], $this->taskAssigneeMapper->findUserIdsByRoomTask($roomTask->getId()), 'unassign must clear the join rows');
	}

	/**
	 * Completion reads the join-table assignees: a private task is
	 * completable by a join-table assignee and a 404 anti-enumeration
	 * denial (NotFoundException, CS-PLAN-061 D3) for anyone else.
	 */
	public function testPrivateTaskCompletion_ReadsJoinTableAssignees(): void {
		$houseId = $this->createHouse('asg_owner3', 'Assignee House 3');
		$this->addHouseMember($houseId, 'asg_member3', 'Member 3', 'member');
		$this->addHouseMember($houseId, 'asg_other', 'Other', 'member');
		$floorId = $this->createFloor($houseId, 'Ground Floor', 1);
		$roomId = $this->createRoom($houseId, $floorId, 'Kitchen');
		$taskId = $this->createTask($houseId, 'Private chore');

		$this->setTaskVisibility($taskId, 'private', 'asg_owner3');
		$roomTaskId = $this->assignTaskToRoom($taskId, $roomId, 'asg_member3');

		// Non-assignee member is rejected with the 404 contract.
		try {
			$this->completeOccurrence(\OC::$server->get(\OCA\ChoreStrike\Service\CompletionService::class), $houseId, 'asg_other', $roomTaskId);
			self::fail('a non-assignee must not complete a private task');
		} catch (NotFoundException $e) {
			self::assertSame('Task not found', $e->getMessage());
		}

		// The join-table assignee completes successfully.
		$result = $this->completeOccurrence(\OC::$server->get(\OCA\ChoreStrike\Service\CompletionService::class), $houseId, 'asg_member3', $roomTaskId);
		self::assertGreaterThan(0, $result['rewards']['coins']);
	}

	/**
	 * The folded schema carries no legacy assignee column.
	 */
	public function testSchemaHasNoLegacyAssigneeColumn(): void {
		$result = $this->db->executeQuery(
			'SELECT COUNT(*) FROM information_schema.COLUMNS'
			. ' WHERE TABLE_SCHEMA = DATABASE()'
			. " AND TABLE_NAME = '*PREFIX*chorestrike_room_tasks'"
			. " AND COLUMN_NAME = 'assigned_to_user_id'"
		);
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		self::assertSame(0, $count, 'room_tasks.assigned_to_user_id must be folded out of the schema');
	}

	private function setTaskVisibility(int $taskId, string $visibilityType, ?string $ownerUserId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('chorestrike_tasks')
			->set('visibility_type', $qb->createNamedParameter($visibilityType))
			->set('owner_user_id', $qb->createNamedParameter($ownerUserId))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($taskId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}
}
