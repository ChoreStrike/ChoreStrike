<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Db;

use OCA\ChoreStrike\Db\Room;
use OCA\ChoreStrike\Db\RoomMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\DB\IResult;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for RoomMapper
 */
class RoomMapperTest extends TestCase
{
    private RoomMapper $mapper;
    private IDBConnection $db;
    private IQueryBuilder $qb;
    private IExpressionBuilder $expr;

    protected function setUp(): void
    {
        $this->db = $this->createMock(IDBConnection::class);
        $this->qb = $this->createMock(IQueryBuilder::class);
        $this->expr = $this->createMock(IExpressionBuilder::class);

        $this->db->method('getQueryBuilder')
            ->willReturn($this->qb);
        $this->qb->method('expr')
            ->willReturn($this->expr);

        $this->mapper = new RoomMapper($this->db);
    }

    /**
     * FindByFloorId_ReturnsRoomsForFloor
     */
    public function testFindByFloorId_ReturnsRoomsForFloor(): void
    {
        $floorId = 1;

        $this->qb->expects($this->once())
            ->method('select')
            ->with('*')
            ->willReturn($this->qb);
        $this->qb->expects($this->once())
            ->method('from')
            ->with('chorestrike_rooms')
            ->willReturn($this->qb);
        $this->qb->expects($this->once())
            ->method('where')
            ->willReturn($this->qb);

        $mockResult = $this->createMock(IResult::class);
        $mockResult->method('fetch')
            ->willReturnOnConsecutiveCalls([
                'id' => 1,
                'floor_id' => $floorId,
                'name' => 'Kitchen',
                'cleanliness' => 80,
                'monster_count' => 0
            ], [
                'id' => 2,
                'floor_id' => $floorId,
                'name' => 'Living Room',
                'cleanliness' => 60,
                'monster_count' => 1
            ], false);

        $this->qb->expects($this->once())
            ->method('executeQuery')
            ->willReturn($mockResult);

        $results = $this->mapper->findByFloorId($floorId);

        $this->assertCount(2, $results);
        $this->assertInstanceOf(Room::class, $results[0]);
        $this->assertEquals('Kitchen', $results[0]->getName());
        $this->assertEquals('Living Room', $results[1]->getName());
    }

    /**
     * FindNeedingCleaning_WithDirtyRooms_ReturnsDirtyRooms
     */
    public function testFindNeedingCleaning_WithDirtyRooms_ReturnsDirtyRooms(): void
    {
        $this->qb->expects($this->once())
            ->method('select')
            ->with('*')
            ->willReturn($this->qb);
        $this->qb->expects($this->once())
            ->method('from')
            ->with('chorestrike_rooms')
            ->willReturn($this->qb);
        $this->qb->expects($this->once())
            ->method('where')
            ->willReturn($this->qb);

        $mockResult = $this->createMock(IResult::class);
        $mockResult->method('fetch')
            ->willReturnOnConsecutiveCalls([
                'id' => 1,
                'floor_id' => 1,
                'name' => 'Dirty Room',
                'cleanliness' => 40,
                'monster_count' => 2
            ], false);

        $this->qb->expects($this->once())
            ->method('executeQuery')
            ->willReturn($mockResult);

        $results = $this->mapper->findNeedingCleaning();

        $this->assertCount(1, $results);
        $this->assertLessThan(60, $results[0]->getCleanliness());
    }

    /**
     * Room_GetCleanlinessLabel_ReturnsCorrectLabel
     */
    public function testRoom_GetCleanlinessLabel_ReturnsCorrectLabel(): void
    {
        $cleanRoom = new Room();
        $cleanRoom->setCleanliness(80);
        $this->assertEquals('clean', $cleanRoom->getCleanlinessLabel());

        $moderateRoom = new Room();
        $moderateRoom->setCleanliness(50);
        $this->assertEquals('moderate', $moderateRoom->getCleanlinessLabel());

        $dirtyRoom = new Room();
        $dirtyRoom->setCleanliness(30);
        $this->assertEquals('dirty', $dirtyRoom->getCleanlinessLabel());

        $filthyRoom = new Room();
        $filthyRoom->setCleanliness(10);
        $this->assertEquals('filthy', $filthyRoom->getCleanlinessLabel());
    }

    /**
     * Room_AddMonster_IncreasesCount
     */
    public function testRoom_AddMonster_IncreasesCount(): void
    {
        $room = new Room();
        $room->setMonsterCount(2);

        $room->addMonster();

        $this->assertEquals(3, $room->getMonsterCount());
    }

    /**
     * Room_RemoveMonster_DecreasesCount
     */
    public function testRoom_RemoveMonster_DecreasesCount(): void
    {
        $room = new Room();
        $room->setMonsterCount(3);

        $room->removeMonster();

        $this->assertEquals(2, $room->getMonsterCount());
    }

    /**
     * Room_RemoveMonster_DoesNotGoBelowZero
     */
    public function testRoom_RemoveMonster_DoesNotGoBelowZero(): void
    {
        $room = new Room();
        $room->setMonsterCount(0);

        $room->removeMonster();

        $this->assertEquals(0, $room->getMonsterCount());
    }

    /**
     * Room_NeedsCleaning_ReturnsCorrectBoolean
     */
    public function testRoom_NeedsCleaning_ReturnsCorrectBoolean(): void
    {
        $cleanRoom = new Room();
        $cleanRoom->setCleanliness(80);
        $this->assertFalse($cleanRoom->needsCleaning());

        $dirtyRoom = new Room();
        $dirtyRoom->setCleanliness(40);
        $this->assertTrue($dirtyRoom->needsCleaning());
    }
}
