<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Db;

use OCA\ChoreStrike\Db\TaskScope;
use OCA\ChoreStrike\Db\TaskScopeMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\DB\IResult;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for TaskScopeMapper
 */
class TaskScopeMapperTest extends TestCase
{
    private TaskScopeMapper $mapper;
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

        $this->mapper = new TaskScopeMapper($this->db);
    }

    /**
     * FindByHouseId_ReturnsScopesForHouse
     */
    public function testFindByHouseId_ReturnsScopesForHouse(): void
    {
        $houseId = 1;

        $this->qb->expects($this->once())
            ->method('select')
            ->with('*')
            ->willReturn($this->qb);
        $this->qb->expects($this->once())
            ->method('from')
            ->with('chorestrike_task_scopes')
            ->willReturn($this->qb);
        $this->qb->expects($this->once())
            ->method('where')
            ->willReturn($this->qb);
        $this->qb->expects($this->any())
            ->method('orderBy')
            ->willReturn($this->qb);
        $this->qb->expects($this->once())
            ->method('addOrderBy')
            ->willReturn($this->qb);

        $mockResult = $this->createMock(IResult::class);
        $mockResult->method('fetch')
            ->willReturnOnConsecutiveCalls([
                'id' => 1,
                'house_id' => $houseId,
                'type' => 'garden',
                'name' => 'Backyard',
                'room_id' => null
            ], [
                'id' => 2,
                'house_id' => $houseId,
                'type' => 'room',
                'name' => 'Kitchen Scope',
                'room_id' => 5
            ], false);

        $this->qb->expects($this->once())
            ->method('executeQuery')
            ->willReturn($mockResult);

        $results = $this->mapper->findByHouseId($houseId);

        $this->assertCount(2, $results);
        $this->assertInstanceOf(TaskScope::class, $results[0]);
        $this->assertEquals('garden', $results[0]->getType());
        $this->assertEquals('Backyard', $results[0]->getName());
        $this->assertEquals('room', $results[1]->getType());
        $this->assertEquals(5, $results[1]->getRoomId());
    }

    /**
     * FindByType_ReturnsScopesOfType
     */
    public function testFindByType_ReturnsScopesOfType(): void
    {
        $houseId = 1;
        $type = 'garden';

        $this->qb->expects($this->once())
            ->method('select')
            ->willReturn($this->qb);
        $this->qb->expects($this->once())
            ->method('from')
            ->willReturn($this->qb);
        $this->qb->expects($this->any())
            ->method('where')
            ->willReturn($this->qb);
        $this->qb->expects($this->any())
            ->method('andWhere')
            ->willReturn($this->qb);
        $this->qb->expects($this->once())
            ->method('orderBy')
            ->willReturn($this->qb);

        $mockResult = $this->createMock(IResult::class);
        $mockResult->method('fetch')
            ->willReturnOnConsecutiveCalls([
                'id' => 1,
                'house_id' => $houseId,
                'type' => $type,
                'name' => 'Garden 1'
            ], [
                'id' => 2,
                'house_id' => $houseId,
                'type' => $type,
                'name' => 'Garden 2'
            ], false);

        $this->qb->expects($this->once())
            ->method('executeQuery')
            ->willReturn($mockResult);

        $results = $this->mapper->findByType($houseId, $type);

        $this->assertCount(2, $results);
        $this->assertEquals($type, $results[0]->getType());
    }

    /**
     * Create_WithValidData_ReturnsScope
     */
    public function testCreate_WithValidData_ReturnsScope(): void
    {
        $houseId = 1;
        $type = 'home';
        $name = 'Whole House';
        $roomId = null;

        $scope = new TaskScope();
        $scope->setHouseId($houseId);
        $scope->setType($type);
        $scope->setName($name);
        $scope->setRoomId($roomId);
        $scope->setCreatedAt(new \DateTime());

        $this->assertEquals($houseId, $scope->getHouseId());
        $this->assertEquals($type, $scope->getType());
        $this->assertEquals($name, $scope->getName());
        $this->assertNull($scope->getRoomId());
    }

    /**
     * Find_WithExistingId_ReturnsScope
     */
    public function testFind_WithExistingId_ReturnsScope(): void
    {
        $scopeId = 1;

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
            ->willReturn([
                'id' => $scopeId,
                'house_id' => 1,
                'type' => 'garden',
                'name' => 'Test Scope',
                'room_id' => null
            ]);

        $this->qb->expects($this->once())
            ->method('executeQuery')
            ->willReturn($mockResult);

        $result = $this->mapper->find($scopeId);

        $this->assertInstanceOf(TaskScope::class, $result);
        $this->assertEquals($scopeId, $result->getId());
    }
}
