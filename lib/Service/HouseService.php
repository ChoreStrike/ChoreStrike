<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\Floor;
use OCA\ChoreStrike\Db\FloorMapper;
use OCA\ChoreStrike\Db\Room;
use OCA\ChoreStrike\Db\RoomMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;

/**
 * Service for house-related business logic
 */
class HouseService
{
    private HouseMapper $houseMapper;
    private FloorMapper $floorMapper;
    private RoomMapper $roomMapper;
    private IConfig $config;
    private ?AppLogger $appLogger;

    public function __construct(
        HouseMapper $houseMapper,
        FloorMapper $floorMapper,
        RoomMapper $roomMapper,
        IConfig $config,
        ?AppLogger $appLogger = null
    ) {
        $this->houseMapper = $houseMapper;
        $this->floorMapper = $floorMapper;
        $this->roomMapper = $roomMapper;
        $this->config = $config;
        $this->appLogger = $appLogger;
    }

    /**
     * Get or create house for current Nextcloud instance
     *
     * @param string $instanceId The Nextcloud instance ID
     * @param string $houseName Optional house name
     */
    public function getOrCreateHouse(string $instanceId, string $houseName = 'My House'): House
    {
        try {
            return $this->houseMapper->findByInstanceId($instanceId);
        } catch (DoesNotExistException $e) {
            $this->appLogger?->info('Creating house for instance', [
                'service' => 'HouseService',
                'instanceId' => $instanceId,
                'houseName' => $houseName,
            ]);
            return $this->houseMapper->createForInstance($instanceId, $houseName);
        }
    }

    /**
     * Update house name
     *
     * @param int $houseId The house ID
     * @param string $name The new name
     */
    public function updateHouseName(int $houseId, string $name): House
    {
        $house = $this->houseMapper->find($houseId);
        $house->setName($name);
        $house->touch();
        return $this->houseMapper->update($house);
    }

    /**
     * Calculate overall house health based on room cleanliness
     *
     * @param int $houseId The house ID
     */
    public function calculateHouseHealth(int $houseId): int
    {
        $floors = $this->floorMapper->findByHouseId($houseId);
        $totalCleanliness = 0;
        $roomCount = 0;

        foreach ($floors as $floor) {
            $rooms = $this->roomMapper->findByFloorId($floor->getId());

            foreach ($rooms as $room) {
                $totalCleanliness += $room->getCleanliness();
                $roomCount++;
            }
        }

        if ($roomCount === 0) {
            return 100; // Empty house is perfectly clean
        }

        $averageCleanliness = (int)round($totalCleanliness / $roomCount);
        $this->houseMapper->updateHealth($houseId, $averageCleanliness);

        return $averageCleanliness;
    }

    /**
     * Get house statistics
     *
     * @param int $houseId The house ID
     */
    public function getHouseStats(int $houseId): array
    {
        $house = $this->houseMapper->find($houseId);
        $floors = $this->floorMapper->findByHouseId($houseId);

        $roomCount = 0;
        $cleanRooms = 0;
        $dirtyRooms = 0;
        $roomsNeedingCleaning = 0;

        foreach ($floors as $floor) {
            $rooms = $this->roomMapper->findByFloorId($floor->getId());
            $roomCount += count($rooms);

            foreach ($rooms as $room) {
                if ($room->getCleanliness() >= 60) {
                    $cleanRooms++;
                } else {
                    $dirtyRooms++;
                }

                if ($room->needsCleaning()) {
                    $roomsNeedingCleaning++;
                }
            }
        }

        return [
            'house' => $house,
            'floors' => $floors,
            'stats' => [
                'totalRooms' => $roomCount,
                'cleanRooms' => $cleanRooms,
                'dirtyRooms' => $dirtyRooms,
                'roomsNeedingCleaning' => $roomsNeedingCleaning,
                'houseHealth' => $house->getHouseHealth(),
            ]
        ];
    }

    /**
     * Reset house to initial state
     *
     * @param int $houseId The house ID
     */
    public function resetHouse(int $houseId): void
    {
        $this->appLogger?->warning('Resetting house to initial state', [
            'service' => 'HouseService',
            'houseId' => $houseId,
        ]);
        $house = $this->houseMapper->find($houseId);
        $house->setHouseHealth(100);
        $house->touch();
        $this->houseMapper->update($house);

        // Reset all rooms
        $floors = $this->floorMapper->findByHouseId($houseId);
        foreach ($floors as $floor) {
            $rooms = $this->roomMapper->findByFloorId($floor->getId());
            foreach ($rooms as $room) {
                $room->setCleanliness(100);
                $room->setMonsterCount(0);
                $room->setLastCleaned(null);
                $this->roomMapper->update($room);
            }
        }
    }

    /**
     * Get instance ID from Nextcloud config
     */
    public function getInstanceId(): string
    {
        return $this->config->getSystemValue('instanceid', 'default');
    }
}
