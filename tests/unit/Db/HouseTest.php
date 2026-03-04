<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Db;

use OCA\ChoreStrike\Db\House;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for House entity
 */
class HouseTest extends TestCase
{
    /**
     * JsonSerialize_ReturnsCorrectArray
     */
    public function testJsonSerialize_ReturnsCorrectArray(): void
    {
        $house = new House();
        $house->setId(1);
        $house->setNextcloudInstanceId('instance123');
        $house->setName('My House');
        $house->setHouseHealth(85);
        $createdAt = new \DateTime('2026-02-01 10:00:00');
        $house->setCreatedAt($createdAt);
        $updatedAt = new \DateTime('2026-02-24 15:30:00');
        $house->setUpdatedAt($updatedAt);

        $result = $house->jsonSerialize();

        self::assertEquals(1, $result['id']);
        self::assertEquals('instance123', $result['nextcloudInstanceId']);
        self::assertEquals('My House', $result['name']);
        self::assertEquals(85, $result['houseHealth']);
        self::assertEquals($createdAt->format('c'), $result['createdAt']);
        self::assertEquals($updatedAt->format('c'), $result['updatedAt']);
    }

    /**
     * Touch_UpdatesUpdatedAt
     */
    public function testTouch_UpdatesUpdatedAt(): void
    {
        $house = new House();
        $house->setUpdatedAt(new \DateTime('2026-02-01 10:00:00'));

        $beforeTouch = new \DateTime();
        $house->touch();
        $afterTouch = new \DateTime();

        $updatedAt = $house->getUpdatedAt();
        self::assertGreaterThanOrEqual($beforeTouch->getTimestamp(), $updatedAt->getTimestamp());
        self::assertLessThanOrEqual($afterTouch->getTimestamp(), $updatedAt->getTimestamp());
    }

    /**
     * SetAndGetProperties_WorksCorrectly
     */
    public function testSetAndGetProperties_WorksCorrectly(): void
    {
        $house = new House();
        $house->setNextcloudInstanceId('instance456');
        $house->setName('Test House');
        $house->setHouseHealth(75);
        $createdAt = new \DateTime('2026-02-24 12:00:00');
        $house->setCreatedAt($createdAt);
        $updatedAt = new \DateTime('2026-02-24 15:00:00');
        $house->setUpdatedAt($updatedAt);

        self::assertEquals('instance456', $house->getNextcloudInstanceId());
        self::assertEquals('Test House', $house->getName());
        self::assertEquals(75, $house->getHouseHealth());
        self::assertSame($createdAt, $house->getCreatedAt());
        self::assertSame($updatedAt, $house->getUpdatedAt());
    }
}
