<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\Floor;
use OCA\ChoreStrike\Db\FloorMapper;
use OCA\ChoreStrike\Db\Room;
use OCA\ChoreStrike\Db\RoomMapper;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\RoomService;
use OCA\ChoreStrike\Service\RoomTaskService;
use OCP\AppFramework\Http\NotFoundException;
use PHPUnit\Framework\TestCase;

/**
 * Logging-focused tests for RoomTaskService.
 */
class RoomTaskServiceLoggingTest extends TestCase
{
    /**
     * ValidateRoomTaskOwnership_ForeignHouse_LogsSecurityWarning.
     */
    public function testValidateRoomTaskOwnership_ForeignHouse_LogsSecurityWarning(): void
    {
        $roomTaskMapper = $this->createMock(RoomTaskMapper::class);
        $taskMapper = $this->createMock(TaskMapper::class);
        $roomMapper = $this->createMock(RoomMapper::class);
        $floorMapper = $this->createMock(FloorMapper::class);
        $roomService = $this->createMock(RoomService::class);
        $appLogger = $this->createMock(AppLogger::class);

        $roomTask = new RoomTask();
        $roomTask->setId(10);
        $roomTask->setRoomId(5);

        $room = new Room();
        $room->setId(5);
        $room->setFloorId(7);

        $floor = new Floor();
        $floor->setId(7);
        $floor->setHouseId(99);

        $roomTaskMapper->method('find')->with(10)->willReturn($roomTask);
        $roomMapper->method('find')->with(5)->willReturn($room);
        $floorMapper->method('find')->with(7)->willReturn($floor);

        $appLogger->expects($this->once())
            ->method('securityWarning')
            ->with(
                'Room task ownership validation failed',
                $this->callback(function (array $context): bool {
                    return $context['service'] === 'RoomTaskService'
                        && $context['roomTaskId'] === 10
                        && $context['houseId'] === 1
                        && $context['actualHouseId'] === 99;
                })
            );

        $service = new RoomTaskService(
            $roomTaskMapper,
            $taskMapper,
            $roomMapper,
            $floorMapper,
            $roomService,
            $appLogger
        );

        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('Room task not found');

        $service->validateRoomTaskOwnership(10, 1);
    }
}
