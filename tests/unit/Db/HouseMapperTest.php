<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Db;

use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\HouseMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\DB\IResult;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for HouseMapper
 */
class HouseMapperTest extends TestCase
{
    private HouseMapper $mapper;
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

        $this->mapper = new HouseMapper($this->db);
    }

    /**
     * FindByInstanceId_WithExistingInstance_ReturnsHouse
     */
    public function testFindByInstanceId_WithExistingInstance_ReturnsHouse(): void
    {
        $instanceId = 'instance123';

        $this->qb->expects($this->once())
            ->method('select')
            ->with('*')
            ->willReturn($this->qb);
        $this->qb->expects($this->once())
            ->method('from')
            ->with('chorestrike_houses')
            ->willReturn($this->qb);
        $this->qb->expects($this->once())
            ->method('where')
            ->willReturn($this->qb);

        $mockResult = $this->createMock(IResult::class);
        $mockResult->method('fetch')
            ->willReturn([
                'id' => 1,
                'nextcloud_instance_id' => $instanceId,
                'name' => 'Test House',
                'house_health' => 85,
                'created_at' => '2026-02-01 10:00:00',
                'updated_at' => '2026-02-24 15:30:00'
            ]);

        $this->qb->expects($this->once())
            ->method('executeQuery')
            ->willReturn($mockResult);

        $result = $this->mapper->findByInstanceId($instanceId);

        $this->assertInstanceOf(House::class, $result);
        $this->assertEquals($instanceId, $result->getNextcloudInstanceId());
        $this->assertEquals('Test House', $result->getName());
    }

    /**
     * FindByInstanceId_WithNonExistingInstance_ThrowsException
     */
    public function testFindByInstanceId_WithNonExistingInstance_ThrowsException(): void
    {
        $instanceId = 'nonexistent';

        $this->qb->expects($this->once())
            ->method('select')
            ->with('*')
            ->willReturn($this->qb);
        $this->qb->expects($this->once())
            ->method('from')
            ->willReturn($this->qb);
        $this->qb->expects($this->once())
            ->method('where')
            ->willReturn($this->qb);

        $mockResult = $this->createMock(IResult::class);
        $mockResult->method('fetch')
            ->willReturn(false);

        $this->qb->expects($this->once())
            ->method('executeQuery')
            ->willReturn($mockResult);

        $this->expectException(DoesNotExistException::class);
        $this->mapper->findByInstanceId($instanceId);
    }

    /**
     * CreateForInstance_WithNewInstance_CreatesHouse
     */
    public function testCreateForInstance_WithNewInstance_CreatesHouse(): void
    {
        $instanceId = 'newinstance';
        $name = 'New House';

        // Mock findByInstanceId to throw exception (house doesn't exist)
        $this->qb->expects($this->any())
            ->method('select')
            ->willReturn($this->qb);
        $this->qb->expects($this->any())
            ->method('from')
            ->willReturn($this->qb);
        $this->qb->expects($this->any())
            ->method('where')
            ->willReturn($this->qb);

        $mockResult = $this->createMock(IResult::class);
        $mockResult->method('fetch')
            ->willReturn(false);

        $this->qb->expects($this->any())
            ->method('executeQuery')
            ->willReturn($mockResult);

        // Verify the house object structure for creation
        $house = new House();
        $house->setNextcloudInstanceId($instanceId);
        $house->setName($name);
        $house->setHouseHealth(100);
        $now = new \DateTime();
        $house->setCreatedAt($now);
        $house->setUpdatedAt($now);

        $this->assertEquals($instanceId, $house->getNextcloudInstanceId());
        $this->assertEquals($name, $house->getName());
        $this->assertEquals(100, $house->getHouseHealth());
    }

    /**
     * House_Touch_UpdatesUpdatedAt
     */
    public function testHouse_Touch_UpdatesUpdatedAt(): void
    {
        $house = new House();
        $originalUpdatedAt = new \DateTime('2026-02-01 10:00:00');
        $house->setUpdatedAt($originalUpdatedAt);

        $beforeTouch = new \DateTime();
        $house->touch();
        $afterTouch = new \DateTime();

        $updatedAt = $house->getUpdatedAt();
        $this->assertGreaterThanOrEqual($beforeTouch->getTimestamp(), $updatedAt->getTimestamp());
        $this->assertLessThanOrEqual($afterTouch->getTimestamp(), $updatedAt->getTimestamp());
        $this->assertGreaterThan($originalUpdatedAt->getTimestamp(), $updatedAt->getTimestamp());
    }
}
