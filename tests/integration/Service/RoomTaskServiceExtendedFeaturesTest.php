<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\tests\integration\Service;

use OCA\ChoreStrike\Db\CompletedTaskMapper;
use OCA\ChoreStrike\Db\FloorMapper;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\RoomMapper;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Db\TaskAssigneeMapper;
use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\RoomTaskService;
use OCA\ChoreStrike\Service\TaskAccessPolicy;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for RoomTaskService with Group Assignment, Teamwork, and Claiming
 *
 * @covers \OCA\ChoreStrike\Service\RoomTaskService
 */
class RoomTaskServiceExtendedFeaturesTest extends TestCase {
	private RoomTaskService $service;
	private RoomTaskMapper $roomTaskMapper;
	private TaskMapper $taskMapper;
	private RoomMapper $roomMapper;
	private FloorMapper $floorMapper;
	private HouseMapper $houseMapper;
	private int $testHouseId;
	private int $testFloorId;
	private int $testRoomId;
	private int $testTaskId;

	private function buildService(TaskAssigneeMapper $taskAssigneeMapper): RoomTaskService {
		$l10n = $this->createMock(IL10N::class);
		// Identity mock: message assertions match the wrapped source text.
		$l10n->method('t')->willReturnCallback(static fn (string $text) => $text);

		return new RoomTaskService(
			$this->roomTaskMapper,
			$this->taskMapper,
			$this->roomMapper,
			$this->floorMapper,
			$this->getMockBuilder(\OCA\ChoreStrike\Service\RoomService::class)
				->disableOriginalConstructor()
				->getMock(),
			$taskAssigneeMapper,
			$this->createMock(HouseUserMapper::class),
			$this->createMock(CompletedTaskMapper::class),
			new \OCA\ChoreStrike\Db\TransactionExecutor($this->createMock(\OCP\IDBConnection::class)),
			$l10n,
			$this->createMock(AppLogger::class),
			$this->houseMapper,
			new \OCA\ChoreStrike\Service\CalendarService(),
			new TaskAccessPolicy($taskAssigneeMapper, $l10n),
			$this->createMock(AuthorizationService::class)
		);
	}

	private function ownerViewer(): HouseUser {
		$viewer = new HouseUser();
		$viewer->setNextcloudUserId('viewer-1');
		$viewer->setRole('owner');

		return $viewer;
	}

	private function publicTask(int $id): Task {
		$task = new Task();
		$task->setId($id);
		$task->setName('Task ' . $id);
		$task->setVisibilityType(Task::VISIBILITY_PUBLIC);

		return $task;
	}

	protected function setUp(): void {
		parent::setUp();

		// Initialize mappers with mocked database connection
		// In a real test environment, these would use the actual database
		$this->roomTaskMapper = $this->createMock(RoomTaskMapper::class);
		$this->taskMapper = $this->createMock(TaskMapper::class);
		$this->roomMapper = $this->createMock(RoomMapper::class);
		$this->floorMapper = $this->createMock(FloorMapper::class);
		$this->houseMapper = $this->createMock(HouseMapper::class);

		$this->service = $this->buildService($this->createMock(TaskAssigneeMapper::class));

		// Set up test IDs
		$this->testHouseId = 1;
		$this->testFloorId = 1;
		$this->testRoomId = 1;
		$this->testTaskId = 1;
	}

	/**
	 * Test getting claimable overdue tasks
	 *
	 * @test
	 */
	public function testGetClaimableOverdueTasks(): void {
		$houseId = 1;

		$overdueTask1 = new RoomTask();
		$overdueTask1->setId(1);
		$overdueTask1->setTaskId(11);
		$overdueTask1->setNextDue(new \DateTime('-2 days'));
		$overdueTask1->setIsActive(true);

		$overdueTask2 = new RoomTask();
		$overdueTask2->setId(2);
		$overdueTask2->setTaskId(12);
		$overdueTask2->setNextDue(new \DateTime('-5 days'));
		$overdueTask2->setIsActive(true);

		// Unassigned task should be filtered out
		$unassignedTask = new RoomTask();
		$unassignedTask->setId(3);
		$unassignedTask->setTaskId(13);
		$unassignedTask->setNextDue(new \DateTime('-1 day'));
		$unassignedTask->setIsActive(true);

		$allOverdueTasks = [$overdueTask1, $overdueTask2, $unassignedTask];

		$this->roomTaskMapper
			->expects($this->once())
			->method('findOverdueByHouse')
			->with($houseId)
			->willReturn($allOverdueTasks);

		// Assignees live in the canonical join table (CS-PLAN-060 WP1, D2):
		// tasks 1 and 2 have assignees, task 3 has none.
		$taskAssigneeMapper = $this->createMock(TaskAssigneeMapper::class);
		$taskAssigneeMapper->method('findUserIdsByRoomTasks')->willReturn([
			1 => ['user-1'],
			2 => ['user-2'],
			3 => [],
		]);

		// The visibility pass resolves the definitions for the two still-
		// claimable candidates; both are public → visible to every member.
		$this->taskMapper->method('findByIds')->willReturn([
			11 => $this->publicTask(11),
			12 => $this->publicTask(12),
		]);

		$service = $this->buildService($taskAssigneeMapper);

		$result = $service->getClaimableOverdueTasks($houseId, $this->ownerViewer());

		$this->assertCount(2, $result); // Unassigned task filtered out
		$this->assertEquals(1, $result[0]->getId());
		$this->assertEquals(2, $result[1]->getId());
	}
}
