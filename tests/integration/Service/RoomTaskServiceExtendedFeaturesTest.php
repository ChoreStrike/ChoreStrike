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
use OCA\ChoreStrike\Db\Occurrence;
use OCA\ChoreStrike\Db\OccurrenceMapper;
use OCA\ChoreStrike\Db\RoomMapper;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\ScheduleRevision;
use OCA\ChoreStrike\Db\ScheduleRevisionMapper;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Db\TaskAssigneeMapper;
use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\OccurrenceService;
use OCA\ChoreStrike\Service\RecurrenceCalculator;
use OCA\ChoreStrike\Service\RoomTaskReadService;
use OCA\ChoreStrike\Service\RoomTaskService;
use OCA\ChoreStrike\Service\TaskAccessPolicy;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for RoomTaskService claiming surface
 * (CS-PLAN-060) — occurrence-authority edition (CS-PLAN-072 WP1):
 * the claimable set derives from the canonical selection (a missed,
 * uncovered occurrence = the catch-up card), not from a next_due SQL
 * comparison.
 *
 * @covers \OCA\ChoreStrike\Service\RoomTaskService
 * @covers \OCA\ChoreStrike\Service\RoomTaskReadService
 */
class RoomTaskServiceExtendedFeaturesTest extends TestCase {
	private RoomTaskService $service;
	private RoomTaskReadService $readService;
	private RoomTaskMapper $roomTaskMapper;
	private TaskMapper $taskMapper;
	private RoomMapper $roomMapper;
	private FloorMapper $floorMapper;
	private HouseMapper $houseMapper;
	private OccurrenceService $occurrenceService;
	private int $testHouseId;
	private int $testFloorId;
	private int $testRoomId;
	private int $testTaskId;

	private function buildServices(TaskAssigneeMapper $taskAssigneeMapper): void {
		$l10n = $this->createMock(IL10N::class);
		// Identity mock: message assertions match the wrapped source text.
		$l10n->method('t')->willReturnCallback(static fn (string $text) => $text);

		$transactions = new \OCA\ChoreStrike\Db\TransactionExecutor($this->createMock(\OCP\IDBConnection::class));
		$appLogger = $this->createMock(AppLogger::class);

		// RF-072-WP1-18: the OccurrenceService facade delegates to the
		// collaborator split — build the real collaborators from the same
		// mocked mappers so the service behaves exactly like before.
		$revisionMapper = $this->createMock(ScheduleRevisionMapper::class);
		$occurrenceMapper = $this->createMock(OccurrenceMapper::class);
		$occMemberMapper = $this->createMock(\OCA\ChoreStrike\Db\OccurrenceMemberMapper::class);
		$completedTaskMapper = $this->createMock(CompletedTaskMapper::class);
		$calculator = new RecurrenceCalculator();
		$calendar = new \OCA\ChoreStrike\Service\CalendarService();
		$rowFactory = new \OCA\ChoreStrike\Service\OccurrenceRowFactory($calendar);

		$reconcileService = new \OCA\ChoreStrike\Service\OccurrenceReconcileService(
			$transactions,
			$revisionMapper,
			$occurrenceMapper,
			$occMemberMapper,
			$this->roomTaskMapper,
			$this->houseMapper,
			$completedTaskMapper,
			$calculator,
			$calendar,
			$l10n,
			$appLogger,
			$rowFactory,
		);
		$selectionService = new \OCA\ChoreStrike\Service\OccurrenceSelectionService(
			$revisionMapper,
			$occurrenceMapper,
			$calculator,
			$calendar,
			$rowFactory,
			$reconcileService,
			new \OCA\ChoreStrike\Service\PauseProjectionService(
				\OC::$server->get(\OCA\ChoreStrike\Db\PauseMapper::class),
				\OC::$server->get(\OCA\ChoreStrike\Db\HouseUserMapper::class),
				$occMemberMapper,
				$calendar,
			),
		);
		$revisionService = new \OCA\ChoreStrike\Service\OccurrenceRevisionService(
			$transactions,
			$revisionMapper,
			$this->roomTaskMapper,
			$this->houseMapper,
			$this->taskMapper,
			$this->createMock(\OCA\ChoreStrike\Db\WalletMapper::class),
			$calculator,
			$calendar,
			$reconcileService,
		);
		$completionService = new \OCA\ChoreStrike\Service\OccurrenceCompletionService(
			$revisionMapper,
			$occurrenceMapper,
			$occMemberMapper,
			$completedTaskMapper,
			$this->createMock(\OCA\ChoreStrike\Db\AgreementMapper::class),
			$this->createMock(\OCA\ChoreStrike\Db\RoomTaskChecklistProgressMapper::class),
			$calculator,
			$calendar,
			$l10n,
			$reconcileService,
			$selectionService,
			$rowFactory,
		);
		$agreementService = new \OCA\ChoreStrike\Service\OccurrenceAgreementService(
			$revisionMapper,
			$occurrenceMapper,
			$occMemberMapper,
			$this->createMock(\OCA\ChoreStrike\Db\AgreementMapper::class),
			$this->roomTaskMapper,
			$this->createMock(HouseUserMapper::class),
			$l10n,
			$rowFactory,
			$reconcileService,
			$selectionService,
			$revisionService,
		);
		$this->occurrenceService = new OccurrenceService(
			$reconcileService,
			$selectionService,
			$revisionService,
			$completionService,
			$agreementService,
		);

		// CS-PLAN-067 §3.1: the claimable-overdue read lives in the
		// RoomTaskReadService read cluster.
		$this->readService = new RoomTaskReadService(
			$this->roomTaskMapper,
			$this->taskMapper,
			$this->roomMapper,
			$this->floorMapper,
			$this->houseMapper,
			$taskAssigneeMapper,
			new TaskAccessPolicy($taskAssigneeMapper, $l10n),
			new \OCA\ChoreStrike\Service\CalendarService(),
			$this->occurrenceService,
			new \OCA\ChoreStrike\Service\PauseProjectionService(
				\OC::$server->get(\OCA\ChoreStrike\Db\PauseMapper::class),
				\OC::$server->get(\OCA\ChoreStrike\Db\HouseUserMapper::class),
				$occMemberMapper,
				new \OCA\ChoreStrike\Service\CalendarService(),
			),
			\OC::$server->get(\OCA\ChoreStrike\Db\HouseUserMapper::class),
			\OC::$server->get(\OCA\ChoreStrike\Db\OccurrenceMemberMapper::class),
			new \OCA\ChoreStrike\Service\EffortAllocationService(),
			$l10n
		);

		$this->service = new RoomTaskService(
			$this->roomTaskMapper,
			$this->taskMapper,
			$this->roomMapper,
			$this->houseMapper,
			$this->readService,
			$this->getMockBuilder(\OCA\ChoreStrike\Service\RoomService::class)
				->disableOriginalConstructor()
				->getMock(),
			$taskAssigneeMapper,
			$this->createMock(HouseUserMapper::class),
			$this->createMock(CompletedTaskMapper::class),
			$transactions,
			$l10n,
			$appLogger,
			new TaskAccessPolicy($taskAssigneeMapper, $l10n),
			$this->createMock(AuthorizationService::class),
			new \OCA\ChoreStrike\Service\CalendarService(),
			$this->occurrenceService
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

		$this->buildServices($this->createMock(TaskAssigneeMapper::class));

		// Set up test IDs
		$this->testHouseId = 1;
		$this->testFloorId = 1;
		$this->testRoomId = 1;
		$this->testTaskId = 1;
	}

	/**
	 * Test getting claimable overdue tasks — occurrence authority edition
	 * (CS-PLAN-072 WP1): a task is claimable when its canonical selection
	 * is a MISSED occurrence and it has at least one assignee.
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

		// A task whose selection is OPEN (today) is NOT overdue.
		$todayTask = new RoomTask();
		$todayTask->setId(4);
		$todayTask->setTaskId(14);
		$todayTask->setNextDue(new \DateTime('today'));
		$todayTask->setIsActive(true);

		$allTasks = [$overdueTask1, $overdueTask2, $unassignedTask, $todayTask];

		$this->roomTaskMapper
			->method('findByHouse')
			->with($houseId, true)
			->willReturn($allTasks);

		// Assignees live in the canonical join table (CS-PLAN-060 WP1, D2):
		// tasks 1, 2 and 4 have assignees, task 3 has none.
		$taskAssigneeMapper = $this->createMock(TaskAssigneeMapper::class);
		$taskAssigneeMapper->method('findUserIdsByRoomTasks')->willReturn([
			1 => ['user-1'],
			2 => ['user-2'],
			3 => [],
			4 => ['user-4'],
		]);

		// The visibility pass resolves the definitions for the claimable
		// candidates; all are public → visible to every member.
		$this->taskMapper->method('findByIds')->willReturn([
			11 => $this->publicTask(11),
			12 => $this->publicTask(12),
			14 => $this->publicTask(14),
		]);

		$this->buildServices($taskAssigneeMapper);

		// Override the batched derivation with fixed per-task selections:
		// tasks 1-3 have a MISSED occurrence (overdue), task 4's window is
		// still open (today — not overdue).
		$this->occurrenceService = $this->getMockBuilder(OccurrenceService::class)
			->disableOriginalConstructor()
			->onlyMethods(['selectionsForHouseTasks'])
			->getMock();
		$this->occurrenceService->method('selectionsForHouseTasks')->willReturnCallback(
			static function (int $h, array $tasks, \DateTime $now): array {
				$selections = [];
				foreach ($tasks as $task) {
					if ($task->getId() === 4) {
						$miss = RoomTaskServiceExtendedFeaturesTest::missedOccurrenceStatic('today');
						$selections[4] = ['occurrence' => $miss['occurrence'], 'revision' => $miss['revision'], 'classification' => 'agreed'];
					} else {
						$miss = RoomTaskServiceExtendedFeaturesTest::missedOccurrenceStatic('-2 days');
						$selections[$task->getId()] = ['occurrence' => $miss['occurrence'], 'revision' => $miss['revision'], 'classification' => 'late'];
					}
				}
				return $selections;
			}
		);
		$this->readService = new RoomTaskReadService(
			$this->roomTaskMapper,
			$this->taskMapper,
			$this->roomMapper,
			$this->floorMapper,
			$this->houseMapper,
			$taskAssigneeMapper,
			new TaskAccessPolicy($taskAssigneeMapper, $this->identityL10n()),
			new \OCA\ChoreStrike\Service\CalendarService(),
			$this->occurrenceService,
			new \OCA\ChoreStrike\Service\PauseProjectionService(
				\OC::$server->get(\OCA\ChoreStrike\Db\PauseMapper::class),
				\OC::$server->get(\OCA\ChoreStrike\Db\HouseUserMapper::class),
				\OC::$server->get(\OCA\ChoreStrike\Db\OccurrenceMemberMapper::class),
				new \OCA\ChoreStrike\Service\CalendarService(),
			),
			\OC::$server->get(\OCA\ChoreStrike\Db\HouseUserMapper::class),
			\OC::$server->get(\OCA\ChoreStrike\Db\OccurrenceMemberMapper::class),
			new \OCA\ChoreStrike\Service\EffortAllocationService(),
			$this->identityL10n()
		);

		$result = $this->readService->getClaimableOverdueTasks($houseId, $this->ownerViewer());

		$this->assertCount(2, $result); // Unassigned + open-window tasks filtered out
		$this->assertEquals(1, $result[0]->getId());
		$this->assertEquals(2, $result[1]->getId());
	}

	private static function missedOccurrenceStatic(string $anchor): array {
		$revision = new ScheduleRevision();
		$revision->setId(99);
		$revision->setRoomTaskId(1);
		$revision->setRevision(1);
		$revision->setCadenceMode(ScheduleRevision::CADENCE_INTERVAL);
		$revision->setCadenceUnit(ScheduleRevision::UNIT_DAY);
		$revision->setAnchorDate(new \DateTime($anchor));

		$occurrence = new Occurrence();
		$occurrence->setRoomTaskId(1);
		$occurrence->setScheduleRevId(99);
		$occurrence->setOrdinal(1);
		$planned = new \DateTime($anchor);
		$planned->setTime(0, 0);
		$occurrence->setPlannedDate($planned);
		$occurrence->setWeekStart($planned);
		$occurrence->setTimezone('UTC');
		$occurrence->setDayStart(clone $planned);
		$occurrence->setWindowStart(clone $planned);
		$occurrence->setWindowEnd((clone $planned)->modify('+1 day'));
		$occurrence->setEnergy(1);

		return ['occurrence' => $occurrence, 'revision' => $revision];
	}

	private function identityL10n(): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text) => $text);
		return $l10n;
	}
}
