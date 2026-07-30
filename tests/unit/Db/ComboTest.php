<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Db;

use OCA\ChoreStrike\Db\Combo;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Combo entity logic
 */
class ComboTest extends TestCase
{
    public function testAddTask_IncrementsCountAndPoints(): void
    {
        $combo = new Combo();
        $combo->setTaskCount(0);
        $combo->setComboBonusPoints(0);

        $combo->addTask(10);

        $this->assertEquals(1, $combo->getTaskCount());
        $this->assertEquals(10, $combo->getComboBonusPoints());
        $this->assertInstanceOf(\DateTime::class, $combo->getLastTaskAt());
    }

    public function testAddTask_AccumulatesPoints(): void
    {
        $combo = new Combo();
        $combo->setTaskCount(2);
        $combo->setComboBonusPoints(20);

        $combo->addTask(15);

        $this->assertEquals(3, $combo->getTaskCount());
        $this->assertEquals(35, $combo->getComboBonusPoints());
    }

    public function testGetMultiplier_ScalesWithTaskCount(): void
    {
        $combo = new Combo();
        $combo->setTaskCount(0);
        $this->assertEquals(1.0, $combo->getMultiplier());

        $combo->setTaskCount(5);
        $this->assertEquals(1.5, $combo->getMultiplier());

        $combo->setTaskCount(10);
        $this->assertEquals(2.0, $combo->getMultiplier());
    }

    public function testHasTimedOut_NullLastTask_ReturnsFalse(): void
    {
        $combo = new Combo();

        $this->assertFalse($combo->hasTimedOut());
    }

    public function testHasTimedOut_RecentTask_ReturnsFalse(): void
    {
        $combo = new Combo();
        $combo->setLastTaskAt(new \DateTime());

        $this->assertFalse($combo->hasTimedOut());
    }

    public function testHasTimedOut_OldTask_ReturnsTrue(): void
    {
        $combo = new Combo();
        $combo->setLastTaskAt(new \DateTime('-2 days'));

        $this->assertTrue($combo->hasTimedOut());
    }

    public function testEnd_SetsInactive(): void
    {
        $combo = new Combo();
        $combo->setIsActive(true);

        $combo->end();

        $this->assertFalse($combo->getIsActive());
    }

    public function testJsonSerialize_IncludesAllFields(): void
    {
        $combo = new Combo();
        $combo->setId(1);
        $combo->setHouseId(2);
        $combo->setUserId('alice');
        $combo->setTaskCount(5);
        $combo->setStartedAt(new \DateTime('2026-01-01'));
        $combo->setLastTaskAt(new \DateTime('2026-01-02'));
        $combo->setComboBonusPoints(50);
        $combo->setIsActive(true);

        $json = $combo->jsonSerialize();

        $this->assertEquals(1, $json['id']);
        $this->assertEquals(2, $json['houseId']);
        $this->assertEquals('alice', $json['userId']);
        $this->assertEquals(5, $json['taskCount']);
        $this->assertEquals(50, $json['comboBonusPoints']);
        $this->assertTrue($json['isActive']);
        $this->assertArrayHasKey('startedAt', $json);
        $this->assertArrayHasKey('lastTaskAt', $json);
    }
}
