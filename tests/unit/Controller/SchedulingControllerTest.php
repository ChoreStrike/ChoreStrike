<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Controller;

use OCA\ChoreStrike\Controller\SchedulingController;
use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\SchedulingService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SchedulingController
 */
class SchedulingControllerTest extends TestCase {
	private SchedulingController $controller;
	private $schedulingService;
	private $houseService;
	private $authService;
	private $request;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->schedulingService = $this->createMock(SchedulingService::class);
		$this->houseService = $this->createMock(HouseService::class);
		$this->authService = $this->createMock(AuthorizationService::class);

		// CR-016-15: the controller delegates the energy bounds to the
		// service; mirror the real pure-function behavior on the mock so the
		// section-validation tests pin the same stable message end-to-end.
		$this->schedulingService->method('assertValidEnergyBudget')
			->willReturnCallback(static function ($energy): void {
				if (!is_int($energy) || $energy < SchedulingService::ENERGY_BUDGET_MIN || $energy > SchedulingService::ENERGY_BUDGET_MAX) {
					throw new \InvalidArgumentException('Energy budget must be between 1 and 20');
				}
			});

		$this->controller = new SchedulingController(
			$this->request,
			$this->schedulingService,
			$this->houseService,
			$this->authService
		);
	}

	private function mockHouse(int $id = 1): House {
		$house = new House();
		$house->setId($id);
		$this->houseService->method('getInstanceId')->willReturn('instance-123');
		$this->houseService->method('getOrCreateHouse')->willReturn($house);
		return $house;
	}

	public function testGetPreferencesReturnsPreferences(): void {
		$preferences = [
			'userId' => 'user1',
			'houseId' => 1,
			'weekdayAvailability' => ['monday' => true, 'tuesday' => false],
			'energyBudget' => ['monday' => 10, 'tuesday' => 15],
		];

		$this->mockHouse();
		$this->authService->method('getCurrentUserId')->willReturn('user1');

		$this->schedulingService->expects($this->once())
			->method('getUserPreferencesByNextcloudUserId')
			->with(1, 'user1')
			->willReturn($preferences);

		$response = $this->controller->getPreferences();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(200, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertEquals($preferences, $data['data']);
	}

	public function testGetPreferencesReturnsForbiddenForNonMember(): void {
		$this->mockHouse();
		$this->authService->method('getCurrentUserId')->willReturn('user1');
		$this->authService->method('ensureHouseMember')
			->willThrowException(new ForbiddenException('Not a house member'));

		$response = $this->controller->getPreferences();

		$this->assertEquals(403, $response->getStatus());
	}

	private function fullWeek(callable $valueFor): array {
		$week = [];
		foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'] as $day) {
			$week[$day] = $valueFor($day);
		}
		return $week;
	}

	public function testUpdatePreferences_PersistsFullWeek_SuccessResponse(): void {
		$preferences = [
			'userId' => 7,
			'weekdayAvailability' => $this->fullWeek(fn () => true),
			'energyBudget' => $this->fullWeek(fn () => 10),
		];
		$weekdayAvailability = $this->fullWeek(fn (string $day) => $day !== 'sunday');
		$energyBudget = $this->fullWeek(fn (string $day) => $day === 'saturday' ? 15 : 10);

		$this->mockHouse();
		$this->authService->method('getCurrentUserId')->willReturn('user1');

		$this->schedulingService->expects($this->once())
			->method('updatePreferencesByNextcloudUserId')
			->with(1, 'user1', $weekdayAvailability, $energyBudget)
			->willReturn($preferences);

		$response = $this->controller->updatePreferences($weekdayAvailability, $energyBudget);

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(200, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertEquals($preferences, $data['data']);
	}

	public function testUpdatePreferences_EnergyOnly_Success(): void {
		$energyBudget = $this->fullWeek(fn () => 12);

		$this->mockHouse();
		$this->authService->method('getCurrentUserId')->willReturn('user1');

		$this->schedulingService->expects($this->once())
			->method('updatePreferencesByNextcloudUserId')
			->with(1, 'user1', null, $energyBudget);

		$response = $this->controller->updatePreferences(null, $energyBudget);

		$this->assertEquals(200, $response->getStatus());
		$this->assertTrue($response->getData()['success']);
	}

	public function testUpdatePreferences_AvailabilityOnly_Success(): void {
		$weekdayAvailability = $this->fullWeek(fn () => false);

		$this->mockHouse();
		$this->authService->method('getCurrentUserId')->willReturn('user1');

		$this->schedulingService->expects($this->once())
			->method('updatePreferencesByNextcloudUserId')
			->with(1, 'user1', $weekdayAvailability, null);

		$response = $this->controller->updatePreferences($weekdayAvailability, null);

		$this->assertEquals(200, $response->getStatus());
		$this->assertTrue($response->getData()['success']);
	}

	public function testUpdatePreferences_ReturnsBadRequestWhenNeitherSectionProvided(): void {
		$this->mockHouse();
		$this->authService->method('getCurrentUserId')->willReturn('user1');

		$this->schedulingService->expects($this->never())
			->method('updatePreferencesByNextcloudUserId');

		$response = $this->controller->updatePreferences(null, null);

		$this->assertEquals(400, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
		$this->assertEquals('VALIDATION_ERROR', $response->getData()['error']['code']);
	}

	public function testUpdatePreferences_ReturnsBadRequestForPartialWeek(): void {
		$weekdayAvailability = $this->fullWeek(fn () => true);
		unset($weekdayAvailability['friday']);

		$this->mockHouse();
		$this->authService->method('getCurrentUserId')->willReturn('user1');

		$response = $this->controller->updatePreferences($weekdayAvailability, null);

		$this->assertEquals(400, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
		$this->assertStringContainsString('must contain all seven weekdays', $response->getData()['error']['message']);
	}

	public function testUpdatePreferences_ReturnsBadRequestForUnknownDayKey(): void {
		$energyBudget = $this->fullWeek(fn () => 10);
		$energyBudget['funday'] = 10;

		$this->mockHouse();
		$this->authService->method('getCurrentUserId')->willReturn('user1');

		$response = $this->controller->updatePreferences(null, $energyBudget);

		$this->assertEquals(400, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
		$this->assertEquals('Invalid weekday: funday', $response->getData()['error']['message']);
	}

	public function testUpdatePreferences_ReturnsBadRequestForNonIntegerEnergy(): void {
		$energyBudget = $this->fullWeek(fn () => 10);
		$energyBudget['monday'] = '15';

		$this->mockHouse();
		$this->authService->method('getCurrentUserId')->willReturn('user1');

		$response = $this->controller->updatePreferences(null, $energyBudget);

		$this->assertEquals(400, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
		// CR-SVC-49: the curated bounds message must surface verbatim.
		$this->assertEquals('Energy budget must be between 1 and 20', $response->getData()['error']['message']);
	}

	public function testUpdatePreferences_ReturnsBadRequestForOutOfRangeEnergy(): void {
		$energyBudget = $this->fullWeek(fn () => 25);

		$this->mockHouse();
		$this->authService->method('getCurrentUserId')->willReturn('user1');

		$response = $this->controller->updatePreferences(null, $energyBudget);

		$this->assertEquals(400, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
		$this->assertEquals('Energy budget must be between 1 and 20', $response->getData()['error']['message']);
	}

	public function testUpdatePreferences_ReturnsBadRequestForNonBoolAvailability(): void {
		$weekdayAvailability = $this->fullWeek(fn () => true);
		$weekdayAvailability['monday'] = 'yes';

		$this->mockHouse();
		$this->authService->method('getCurrentUserId')->willReturn('user1');

		$response = $this->controller->updatePreferences($weekdayAvailability, null);

		$this->assertEquals(400, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
		$this->assertEquals('Availability values must be booleans', $response->getData()['error']['message']);
	}

	public function testUpdatePreferences_ReturnsForbiddenForNonMember(): void {
		$this->mockHouse();
		$this->authService->method('getCurrentUserId')->willReturn('user1');
		$this->authService->method('ensureHouseMember')
			->willThrowException(new ForbiddenException('Not a house member'));

		$response = $this->controller->updatePreferences($this->fullWeek(fn () => true), null);

		$this->assertEquals(403, $response->getStatus());
	}

	public function testGetMemberAvailabilityReturnsListForOwner(): void {
		$members = [
			['userId' => 'user1', 'houseId' => 1],
			['userId' => 'user2', 'houseId' => 1],
		];

		$this->mockHouse();

		$this->schedulingService->expects($this->once())
			->method('getMemberAvailability')
			->with(1)
			->willReturn($members);

		$response = $this->controller->getMemberAvailability();

		$this->assertEquals(200, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertCount(2, $data['data']);
	}

	public function testGetMemberAvailabilityReturnsForbiddenForNonOwner(): void {
		$this->mockHouse();
		$this->authService->method('requireHouseOwner')
			->willThrowException(new ForbiddenException('Not the house owner'));

		$response = $this->controller->getMemberAvailability();

		$this->assertEquals(403, $response->getStatus());
	}

	public function testUpdateMemberAvailabilityUpdatesForOwner(): void {
		$this->mockHouse();
		$updates = ['weekdayAvailability' => ['monday' => false]];

		$this->schedulingService->expects($this->once())
			->method('updateMemberAvailabilityByNextcloudUserId')
			->with(1, 'alice', $updates);

		$response = $this->controller->updateMemberAvailability('alice', $updates);

		$this->assertEquals(200, $response->getStatus());
		$this->assertTrue($response->getData()['success']);
	}

	public function testUpdateMemberAvailabilityReturnsForbiddenForNonOwner(): void {
		$this->mockHouse();
		$this->authService->method('requireHouseOwner')
			->willThrowException(new ForbiddenException('Not the house owner'));

		$response = $this->controller->updateMemberAvailability('alice', []);

		$this->assertEquals(403, $response->getStatus());
	}

	public function testUpdateMemberAvailabilityReturnsBadRequestForInvalidWeekday(): void {
		$this->mockHouse();
		$updates = ['weekdayAvailability' => ['funday' => true]];

		$response = $this->controller->updateMemberAvailability('alice', $updates);

		$this->assertEquals(400, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
		// CR-SVC-49: the curated weekday message must surface verbatim.
		$this->assertEquals('Invalid weekday: funday', $response->getData()['error']['message']);
	}

	public function testGetSuggestionsReturnsSuggestions(): void {
		$taskCount = 3;
		$suggestions = [['day' => 'monday', 'tasks' => []]];

		$this->mockHouse();

		$this->schedulingService->expects($this->once())
			->method('getSchedulingSuggestions')
			->with($taskCount, 1)
			->willReturn($suggestions);

		$response = $this->controller->getSuggestions($taskCount);

		$this->assertEquals(200, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertEquals($suggestions, $data['data']);
	}

	public function testGetSuggestionsRejectsNegativeTaskCount(): void {
		$this->mockHouse();

		$response = $this->controller->getSuggestions(-1);

		$this->assertEquals(400, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
	}
}
