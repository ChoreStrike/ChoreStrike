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

	public function testUpdateEnergyUpdatesBudget(): void {
		$this->mockHouse();
		$this->authService->method('getCurrentUserId')->willReturn('user1');

		$this->schedulingService->expects($this->once())
			->method('updateEnergyBudgetByNextcloudUserId')
			->with(1, 'user1', 'monday', 15);

		$response = $this->controller->updateEnergy('monday', 15);

		$this->assertEquals(200, $response->getStatus());
		$this->assertTrue($response->getData()['success']);
	}

	public function testUpdateEnergyReturnsBadRequestForInvalidValue(): void {
		$this->mockHouse();
		$this->authService->method('getCurrentUserId')->willReturn('user1');

		$this->schedulingService->method('updateEnergyBudgetByNextcloudUserId')
			->willThrowException(new \InvalidArgumentException('Energy must be between 1 and 20'));

		$response = $this->controller->updateEnergy('monday', 25);

		$this->assertEquals(400, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
		// CR-SVC-49: the curated service message must surface verbatim.
		$this->assertEquals('Energy must be between 1 and 20', $response->getData()['error']['message']);
	}

	public function testUpdateAvailabilityUpdatesFlag(): void {
		$this->mockHouse();
		$this->authService->method('getCurrentUserId')->willReturn('user1');

		$this->schedulingService->expects($this->once())
			->method('updateAvailabilityByNextcloudUserId')
			->with(1, 'user1', 'tuesday', false);

		$response = $this->controller->updateAvailability('tuesday', false);

		$this->assertEquals(200, $response->getStatus());
		$this->assertTrue($response->getData()['success']);
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

	public function testUpdateEnergyReturnsBadRequestForInvalidWeekday(): void {
		$this->mockHouse();
		$this->authService->method('getCurrentUserId')->willReturn('user1');

		$response = $this->controller->updateEnergy('funday', 10);

		$this->assertEquals(400, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
		$this->assertEquals('Invalid weekday: funday', $response->getData()['error']['message']);
	}

	public function testUpdateAvailabilityReturnsBadRequestForInvalidWeekday(): void {
		$this->mockHouse();
		$this->authService->method('getCurrentUserId')->willReturn('user1');

		$response = $this->controller->updateAvailability('funday', true);

		$this->assertEquals(400, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
	}

	public function testGetSuggestionsReturnsSuggestions(): void {
		$tasks = [['id' => 1, 'title' => 'Clean kitchen']];
		$suggestions = [['day' => 'monday', 'tasks' => $tasks]];

		$this->mockHouse();

		$this->schedulingService->expects($this->once())
			->method('getSchedulingSuggestions')
			->with($tasks, 1)
			->willReturn($suggestions);

		$response = $this->controller->getSuggestions($tasks);

		$this->assertEquals(200, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertEquals($suggestions, $data['data']);
	}
}
