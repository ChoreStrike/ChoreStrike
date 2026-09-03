<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Db;

use OCA\ChoreStrike\Db\TaskAssigneeMapper;
use OCA\ChoreStrike\Service\RoomTaskService;
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
	private TaskAssigneeMapper $taskAssigneeMapper;

	protected function setUp(): void {
		parent::setUp();
		$this->roomTaskService = \OC::$server->get(RoomTaskService::class);
		$this->taskAssigneeMapper = \OC::$server->get(TaskAssigneeMapper::class);
	}

	/**
	 * CreateRoomTask_WithAssignee_WritesJoinTableRows
	 *
	 * Initial assignment writes join rows; the schema no longer carries the
	 * legacy column and the payload no longer exposes it.
	 */
	public function testCreateRoomTask_WithAssignee_WritesJoinTableRows(): void {
		$houseId = $this->createHouse('asg_owner', 'Assignee House');
		$this->addHouseMember($houseId, 'asg_member', 'Member', 'member');
		$floorId = $this->createFloor($houseId, 'Ground Floor', 1);
		$roomId = $this->createRoom($houseId, $floorId, 'Kitchen');
		$taskId = $this->createTask($houseId, 'Dishes');

		$roomTask = $this->roomTaskService->createRoomTask(
			$houseId,
			$roomId,
			$taskId,
			null,
			null,
			'asg_member'
		);

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

		$roomTask = $this->roomTaskService->createRoomTask($houseId, $roomId, $taskId);

		$this->actingAs('asg_owner2');
		$this->roomTaskService->assignTask($roomTask->getId(), 'asg_member2', $houseId);
		self::assertSame(['asg_member2'], $this->taskAssigneeMapper->findUserIdsByRoomTask($roomTask->getId()));

		$this->roomTaskService->unassignTask($roomTask->getId());
		self::assertSame([], $this->taskAssigneeMapper->findUserIdsByRoomTask($roomTask->getId()), 'unassign must clear the join rows');
	}

	/**
	 * Completion reads the join-table assignees: a private task is
	 * completable by a join-table assignee and rejected for anyone else.
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

		// Non-assignee member is rejected.
		try {
			\OC::$server->get(\OCA\ChoreStrike\Service\RoomService::class)->completeRoomTask($roomTaskId, 'asg_other');
			self::fail('a non-assignee must not complete a private task');
		} catch (\OCA\ChoreStrike\Exception\ForbiddenException $e) {
			self::assertStringContainsString('assigned users', $e->getMessage());
		}

		// The join-table assignee completes successfully.
		$result = \OC::$server->get(\OCA\ChoreStrike\Service\RoomService::class)->completeRoomTask($roomTaskId, 'asg_member3');
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
