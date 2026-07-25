<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\tests\integration\Service;

use OCA\ChoreStrike\Db\Floor;
use OCA\ChoreStrike\Db\FloorMapper;
use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\Room;
use OCA\ChoreStrike\Db\RoomMapper;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Db\TaskAssigneeMapper;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Service\RoomTaskService;
use OCA\ChoreStrike\Exception\NotFoundException;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for RoomTaskService with Group Assignment, Teamwork, and Claiming
 *
 * @covers \OCA\ChoreStrike\Service\RoomTaskService
 */
class RoomTaskServiceExtendedFeaturesTest extends TestCase
{
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

    protected function setUp(): void
    {
        parent::setUp();

        // Initialize mappers with mocked database connection
        // In a real test environment, these would use the actual database
        $this->roomTaskMapper = $this->createMock(RoomTaskMapper::class);
        $this->taskMapper = $this->createMock(TaskMapper::class);
        $this->roomMapper = $this->createMock(RoomMapper::class);
        $this->floorMapper = $this->createMock(FloorMapper::class);
        $this->houseMapper = $this->createMock(HouseMapper::class);

        $this->service = new RoomTaskService(
            $this->roomTaskMapper,
            $this->taskMapper,
            $this->roomMapper,
            $this->floorMapper,
            $this->getMockBuilder(\OCA\ChoreStrike\Service\RoomService::class)
                ->disableOriginalConstructor()
                ->getMock(),
            $this->createMock(TaskAssigneeMapper::class),
            $this->createMock(HouseUserMapper::class)
        );

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
    public function testGetClaimableOverdueTasks(): void
    {
        $houseId = 1;

        $overdueTask1 = new RoomTask();
        $overdueTask1->setId(1);
        $overdueTask1->setAssignedToUserId('user-1');
        $overdueTask1->setNextDue(new \DateTime('-2 days'));
        $overdueTask1->setIsActive(true);

        $overdueTask2 = new RoomTask();
        $overdueTask2->setId(2);
        $overdueTask2->setAssignedToUserId('user-2');
        $overdueTask2->setNextDue(new \DateTime('-5 days'));
        $overdueTask2->setIsActive(true);

        // Unassigned task should be filtered out
        $unassignedTask = new RoomTask();
        $unassignedTask->setId(3);
        $unassignedTask->setAssignedToUserId(null);
        $unassignedTask->setNextDue(new \DateTime('-1 day'));
        $unassignedTask->setIsActive(true);

        $allOverdueTasks = [$overdueTask1, $overdueTask2, $unassignedTask];

        $this->roomTaskMapper
            ->expects($this->once())
            ->method('findOverdueByHouse')
            ->with($houseId)
            ->willReturn($allOverdueTasks);

        $result = $this->service->getClaimableOverdueTasks($houseId);

        $this->assertCount(2, $result); // Unassigned task filtered out
        $this->assertEquals(1, $result[0]->getId());
        $this->assertEquals(2, $result[1]->getId());
    }
}
