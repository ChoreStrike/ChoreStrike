<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\FloorMapper;
use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\RoomMapper;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\HouseService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

/**
 * Logging-focused tests for HouseService.
 */
class HouseServiceLoggingTest extends TestCase
{
    /**
     * GetOrCreateHouse_NewHouse_LogsCreation.
     */
    public function testGetOrCreateHouse_NewHouse_LogsCreation(): void
    {
        $houseMapper = $this->createMock(HouseMapper::class);
        $floorMapper = $this->createMock(FloorMapper::class);
        $roomMapper = $this->createMock(RoomMapper::class);
        $config = $this->createMock(IConfig::class);
        $appLogger = $this->createMock(AppLogger::class);

        $house = new House();
        $house->setId(5);
        $house->setName('Family Home');

        $houseMapper->expects($this->once())
            ->method('findByInstanceId')
            ->with('instance-1')
            ->willThrowException(new DoesNotExistException('Not found'));

        $appLogger->expects($this->once())
            ->method('info')
            ->with(
                'Creating house for instance',
                $this->callback(function (array $context): bool {
                    return $context['service'] === 'HouseService'
                        && $context['instanceId'] === 'instance-1'
                        && $context['houseName'] === 'Family Home';
                })
            );

        $houseMapper->expects($this->once())
            ->method('createForInstance')
            ->with('instance-1', 'Family Home')
            ->willReturn($house);

        $service = new HouseService($houseMapper, $floorMapper, $roomMapper, $config, $appLogger);

        $result = $service->getOrCreateHouse('instance-1', 'Family Home');

        $this->assertSame($house, $result);
    }
}
