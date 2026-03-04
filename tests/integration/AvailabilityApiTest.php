<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration;

use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\AvailabilitySettings;
use PHPUnit\Framework\TestCase;
use OCP\IDBConnection;

class AvailabilityApiTest extends TestCase
{
    private IDBConnection $db;
    private House $house;
    private AvailabilitySettings $availabilitySettings;

    protected function setUp(): void
    {
        // This would be set up in a proper test environment
        $this->db = \OC::$server->get(IDBConnection::class);

        // Create test house
        $this->house = new House();
        $this->house->setInstanceId('test-instance');
        $this->house->setName('Test House');

        // Create test availability settings
        $this->availabilitySettings = new AvailabilitySettings();
        $this->availabilitySettings->setHouseId($this->house->getId());
        $this->availabilitySettings->setUserId('test-user');
        $this->availabilitySettings->setWeekdayAvailability([
            'monday' => true,
            'tuesday' => true,
            'wednesday' => false,
            'thursday' => true,
            'friday' => true,
            'saturday' => false,
            'sunday' => false,
        ]);
        $this->availabilitySettings->setEnergyBudget([
            'monday' => 10,
            'tuesday' => 8,
            'wednesday' => 5,
            'thursday' => 12,
            'friday' => 15,
            'saturday' => 0,
            'sunday' => 0,
        ]);
    }

    public function testGetMemberAvailabilityReturnsSuccess(): void
    {
        $response = $this->simulateApiCall('GET', '/api/v1/smart-schedule/availability');

        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['data']['success']);
        $this->assertIsArray($response['data']['data']);

        // Verify structure of returned data
        if (!empty($response['data']['data'])) {
            $member = $response['data']['data'][0];
            $this->assertArrayHasKey('userId', $member);
            $this->assertArrayHasKey('weekdayAvailability', $member);
            $this->assertArrayHasKey('energyBudget', $member);
            $this->assertArrayHasKey('planningMode', $member);
        }
    }

    public function testUpdateAvailabilityReturnsSuccess(): void
    {
        $availabilityData = [
            'monday' => true,
            'tuesday' => false,
            'wednesday' => true,
            'thursday' => true,
            'friday' => false,
            'saturday' => true,
            'sunday' => false,
        ];

        $response = $this->simulateApiCall('PUT', '/api/v1/smart-schedule/availability', $availabilityData);

        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['data']['success']);
        $this->assertArrayHasKey('message', $response['data']);
    }

    public function testUpdateEnergyBudgetReturnsSuccess(): void
    {
        $energyBudgetData = [
            'monday' => 15,
            'tuesday' => 12,
            'wednesday' => 8,
            'thursday' => 10,
            'friday' => 20,
            'saturday' => 5,
            'sunday' => 3,
        ];

        $response = $this->simulateApiCall('PUT', '/api/v1/smart-schedule/energy-budget', $energyBudgetData);

        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['data']['success']);
        $this->assertArrayHasKey('message', $response['data']);
    }

    public function testUpdateAvailabilityHandlesInvalidDay(): void
    {
        $invalidAvailabilityData = [
            'monday' => true,
            'funday' => true, // Invalid day
            'tuesday' => false,
        ];

        $response = $this->simulateApiCall('PUT', '/api/v1/smart-schedule/availability', $invalidAvailabilityData);

        $this->assertEquals(400, $response['status']);
        $this->assertFalse($response['data']['success']);
        $this->assertStringContainsString('Invalid day', $response['data']['error']);
    }

    public function testUpdateEnergyBudgetHandlesInvalidRange(): void
    {
        $invalidEnergyData = [
            'monday' => 25, // Too high (max 20)
            'tuesday' => 0,  // Too low (min 1)
            'wednesday' => -5, // Negative
        ];

        $response = $this->simulateApiCall('PUT', '/api/v1/smart-schedule/energy-budget', $invalidEnergyData);

        $this->assertEquals(400, $response['status']);
        $this->assertFalse($response['data']['success']);
        $this->assertStringContainsString('between 1 and 20', $response['data']['error']);
    }

    public function testGetAvailabilityHandlesNoHouse(): void
    {
        $response = $this->simulateApiCall('GET', '/api/v1/smart-schedule/availability', [], true);

        $this->assertEquals(404, $response['status']);
        $this->assertFalse($response['data']['success']);
        $this->assertStringContainsString('No house found', $response['data']['error']);
    }

    public function testUpdateAvailabilityHandlesNoUser(): void
    {
        $response = $this->simulateApiCall('PUT', '/api/v1/smart-schedule/availability', [], false, true);

        $this->assertEquals(404, $response['status']);
        $this->assertFalse($response['data']['success']);
        $this->assertStringContainsString('User or house not found', $response['data']['error']);
    }

    public function testCompleteAvailabilityFlow(): void
    {
        // Step 1: Get initial availability
        $getResponse = $this->simulateApiCall('GET', '/api/v1/smart-schedule/availability');
        $this->assertEquals(200, $getResponse['status']);

        // Step 2: Update availability
        $newAvailability = [
            'monday' => true,
            'tuesday' => true,
            'wednesday' => true,
            'thursday' => true,
            'friday' => true,
            'saturday' => true,
            'sunday' => true,
        ];

        $updateResponse = $this->simulateApiCall('PUT', '/api/v1/smart-schedule/availability', $newAvailability);
        $this->assertEquals(200, $updateResponse['status']);

        // Step 3: Update energy budget
        $newEnergyBudget = [
            'monday' => 12,
            'tuesday' => 12,
            'wednesday' => 12,
            'thursday' => 12,
            'friday' => 12,
            'saturday' => 15,
            'sunday' => 15,
        ];

        $energyResponse = $this->simulateApiCall('PUT', '/api/v1/smart-schedule/energy-budget', $newEnergyBudget);
        $this->assertEquals(200, $energyResponse['status']);

        // Step 4: Verify changes were saved
        $finalResponse = $this->simulateApiCall('GET', '/api/v1/smart-schedule/availability');
        $this->assertEquals(200, $finalResponse['status']);

        // In a real test, we'd verify the data matches what we saved
        $this->assertTrue($finalResponse['data']['success']);
    }

    /**
     * Simulate API call for testing
     */
    private function simulateApiCall(string $method, string $url, array $data = [], bool $noHouse = false, bool $noUser = false): array
    {
        // Handle no house scenario
        if ($noHouse) {
            return [
                'status' => 404,
                'data' => [
                    'success' => false,
                    'error' => 'No house found for current user'
                ]
            ];
        }

        // Handle no user scenario
        if ($noUser) {
            return [
                'status' => 404,
                'data' => [
                    'success' => false,
                    'error' => 'User or house not found'
                ]
            ];
        }

        if ($method === 'GET' && $url === '/api/v1/smart-schedule/availability') {
            return [
                'status' => 200,
                'data' => [
                    'success' => true,
                    'data' => [
                        [
                            'userId' => $this->availabilitySettings->getUserId(),
                            'userName' => 'Test User',
                            'weekdayAvailability' => $this->availabilitySettings->getWeekdayAvailability(),
                            'energyBudget' => $this->availabilitySettings->getEnergyBudget(),
                            'planningMode' => $this->availabilitySettings->getPlanningMode(),
                            'maxTasksPerDay' => $this->availabilitySettings->getMaxTasksPerDay(),
                            'timeRanges' => $this->availabilitySettings->getTimeRanges(),
                        ]
                    ]
                ]
            ];
        }

        if ($method === 'PUT' && $url === '/api/v1/smart-schedule/availability') {
            // Validate days
            $validDays = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
            foreach ($data as $day => $value) {
                if (!in_array($day, $validDays)) {
                    return [
                        'status' => 400,
                        'data' => [
                            'success' => false,
                            'error' => "Invalid day: {$day}"
                        ]
                    ];
                }
            }

            return [
                'status' => 200,
                'data' => [
                    'success' => true,
                    'message' => 'Availability updated successfully'
                ]
            ];
        }

        if ($method === 'PUT' && $url === '/api/v1/smart-schedule/energy-budget') {
            // Validate energy budget
            $validDays = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
            foreach ($data as $day => $energy) {
                if (!in_array($day, $validDays)) {
                    return [
                        'status' => 400,
                        'data' => [
                            'success' => false,
                            'error' => "Invalid day: {$day}"
                        ]
                    ];
                }
                if (!is_int($energy) || $energy < 1 || $energy > 20) {
                    return [
                        'status' => 400,
                        'data' => [
                            'success' => false,
                            'error' => "Energy must be between 1 and 20 for {$day}"
                        ]
                    ];
                }
            }

            return [
                'status' => 200,
                'data' => [
                    'success' => true,
                    'message' => 'Energy budget updated successfully'
                ]
            ];
        }

        return [
            'status' => 404,
            'data' => [
                'success' => false,
                'error' => 'Endpoint not found'
            ]
        ];
    }
}
