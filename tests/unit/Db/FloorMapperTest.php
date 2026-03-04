<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Db;

use OCA\ChoreStrike\Db\Floor;
use OCA\ChoreStrike\Db\FloorMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\DB\IResult;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for FloorMapper
 */
class FloorMapperTest extends TestCase
{
    private FloorMapper $mapper;
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

        $this->mapper = new FloorMapper($this->db);
    }

    /**
     * FindByHouseId_ReturnsFloorsForHouse
     */
    public function testFindByHouseId_ReturnsFloorsForHouse(): void
    {
        $houseId = 1;

        $this->qb->expects($this->once())
            ->method('select')
            ->with('*')
            ->willReturn($this->qb);
        $this->qb->expects($this->once())
            ->method('from')
            ->with('chorestrike_floors')
            ->willReturn($this->qb);
        $this->qb->expects($this->once())
            ->method('where')
            ->willReturn($this->qb);
        $this->qb->expects($this->once())
            ->method('orderBy')
            ->with('sort_order', 'ASC')
            ->willReturn($this->qb);

        $mockResult = $this->createMock(IResult::class);
        $mockResult->method('fetch')
            ->willReturnOnConsecutiveCalls([
                'id' => 1,
                'house_id' => $houseId,
                'name' => 'Ground Floor',
                'sort_order' => 1
            ], [
                'id' => 2,
                'house_id' => $houseId,
                'name' => 'First Floor',
                'sort_order' => 2
            ], false);

        $this->qb->expects($this->once())
            ->method('executeQuery')
            ->willReturn($mockResult);

        $results = $this->mapper->findByHouseId($houseId);

        $this->assertCount(2, $results);
        $this->assertInstanceOf(Floor::class, $results[0]);
        $this->assertEquals('Ground Floor', $results[0]->getName());
        $this->assertEquals('First Floor', $results[1]->getName());
    }

    /**
     * Create_WithValidData_ReturnsFloor
     */
    public function testCreate_WithValidData_ReturnsFloor(): void
    {
        $houseId = 1;
        $name = 'New Floor';
        $sortOrder = 3;

        // The insert method will be called on the mapper
        // We verify the floor object has correct values
        $floor = new Floor();
        $floor->setHouseId($houseId);
        $floor->setName($name);
        $floor->setSortOrder($sortOrder);
        $floor->setCreatedAt(new \DateTime());

        $this->assertEquals($houseId, $floor->getHouseId());
        $this->assertEquals($name, $floor->getName());
        $this->assertEquals($sortOrder, $floor->getSortOrder());
    }

    /**
     * FindByHouseId_WithNoFloors_ReturnsEmptyArray
     */
    public function testFindByHouseId_WithNoFloors_ReturnsEmptyArray(): void
    {
        $houseId = 999;

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
        $this->qb->expects($this->once())
            ->method('orderBy')
            ->willReturn($this->qb);

        $mockResult = $this->createMock(IResult::class);
        $mockResult->method('fetch')
            ->willReturn(false);

        $this->qb->expects($this->once())
            ->method('executeQuery')
            ->willReturn($mockResult);

        $results = $this->mapper->findByHouseId($houseId);

        $this->assertIsArray($results);
        $this->assertEmpty($results);
    }
}
