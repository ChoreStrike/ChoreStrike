<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Controller;

use OCA\ChoreStrike\Controller\HouseController;
use OCA\ChoreStrike\Service\ActivityService;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\HouseService;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for HouseController API endpoints
 */
class HouseControllerIntegrationTest extends TestCase {
	private HouseController $controller;
	private HouseService $houseService;
	private AuthorizationService $authService;
	private IRequest $request;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->houseService = $this->createMock(HouseService::class);
		$this->authService = $this->createMock(AuthorizationService::class);
		// Identity mock: message assertions match the wrapped source text.
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text) => $text);

		$this->controller = new HouseController(
			$this->request,
			$this->houseService,
			$this->authService,
			$this->createMock(ActivityService::class),
			$l10n,
			$this->createMock(AppLogger::class)
		);
	}

	/**
	 * Get_ReturnsHouseData_SuccessResponse
	 */
	public function testGet_ReturnsHouseData_SuccessResponse(): void {
		$this->houseService->method('getInstanceId')->willReturn('test-instance');
		$this->houseService->method('getOrCreateHouse')->willReturn($this->createMockHouse());
		$this->authService->method('ensureHouseMember')->willReturn($this->createMockHouseUser());

		$response = $this->controller->get();

		$this->assertEquals(200, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
	}

	private function createMockHouse(): \OCA\ChoreStrike\Db\House {
		$house = new \OCA\ChoreStrike\Db\House();
		$house->setId(1);
		$house->setNextcloudInstanceId('test-instance');
		$house->setName('Test House');
		$house->setHouseHealth(100);
		return $house;
	}

	private function createMockHouseUser(): \OCA\ChoreStrike\Db\HouseUser {
		$user = new \OCA\ChoreStrike\Db\HouseUser();
		$user->setId(1);
		$user->setHouseId(1);
		$user->setNextcloudUserId('test-user');
		$user->setDisplayName('Test User');
		$user->setRole('member');
		return $user;
	}

	/**
	 * Get_ServiceThrowsException_ReturnsErrorResponse
	 */
	public function testGet_ServiceThrowsException_ReturnsErrorResponse(): void {
		$this->houseService->method('getInstanceId')
			->willThrowException(new \Exception('Database error'));

		$response = $this->controller->get();

		$this->assertEquals(500, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertEquals('HOUSE_ERROR', $data['error']['code']);
	}

	/**
	 * Update_ValidName_ReturnsUpdatedHouse
	 */
	public function testUpdate_ValidName_ReturnsUpdatedHouse(): void {
		$newName = 'Updated House Name';
		$updatedHouse = $this->createMockHouse();
		$updatedHouse->setName($newName);

		$this->houseService->method('getInstanceId')->willReturn('test-instance');
		$this->houseService->method('getOrCreateHouse')->willReturn($this->createMockHouse());
		$this->houseService->method('updateHouseName')
			->willReturn($updatedHouse);
		$this->authService->method('requireHouseOwner')->willReturn($this->createMockHouseUser());

		$response = $this->controller->update($newName);

		$this->assertEquals(200, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
	}

	/**
	 * Update_BootstrapsMembershipBeforeOwnerCheck
	 *
	 * CR-SVC-47: house.update runs getOrCreateHouse (no membership side
	 * effect) and must explicitly bootstrap membership BEFORE the owner check
	 * so the first user on a fresh instance becomes the owner instead of a
	 * 403. requireHouseOwner itself stays read-only (CR-SVC-06).
	 */
	public function testUpdate_BootstrapsMembershipBeforeOwnerCheck(): void {
		$this->houseService->method('getInstanceId')->willReturn('test-instance');
		$this->houseService->method('getOrCreateHouse')->willReturn($this->createMockHouse());
		$this->houseService->method('updateHouseName')->willReturn($this->createMockHouse());

		$callOrder = [];
		$this->authService->method('ensureHouseMember')
			->willReturnCallback(function () use (&$callOrder) {
				$callOrder[] = 'ensureHouseMember';
				return $this->createMockHouseUser();
			});
		$this->authService->method('requireHouseOwner')
			->willReturnCallback(function () use (&$callOrder) {
				$callOrder[] = 'requireHouseOwner';
				return $this->createMockHouseUser();
			});

		$response = $this->controller->update('New Name');

		$this->assertEquals(200, $response->getStatus());
		$this->assertSame(['ensureHouseMember', 'requireHouseOwner'], $callOrder);
	}

	/**
	 * Reset_BootstrapsMembershipBeforeOwnerCheck
	 *
	 * CR-SVC-47: same bootstrap-before-owner-check contract for house.reset.
	 */
	public function testReset_BootstrapsMembershipBeforeOwnerCheck(): void {
		$this->houseService->method('getInstanceId')->willReturn('test-instance');
		$this->houseService->method('getOrCreateHouse')->willReturn($this->createMockHouse());

		$callOrder = [];
		$this->authService->method('ensureHouseMember')
			->willReturnCallback(function () use (&$callOrder) {
				$callOrder[] = 'ensureHouseMember';
				return $this->createMockHouseUser();
			});
		$this->authService->method('requireHouseOwner')
			->willReturnCallback(function () use (&$callOrder) {
				$callOrder[] = 'requireHouseOwner';
				return $this->createMockHouseUser();
			});

		$response = $this->controller->reset();

		$this->assertEquals(200, $response->getStatus());
		$this->assertSame(['ensureHouseMember', 'requireHouseOwner'], $callOrder);
	}

	/**
	 * Update_EmptyName_ReturnsValidationError
	 */
	public function testUpdate_EmptyName_ReturnsValidationError(): void {
		$response = $this->controller->update('');

		$this->assertEquals(400, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertEquals('VALIDATION_ERROR', $data['error']['code']);
	}

	/**
	 * Update_WhitespaceName_ReturnsValidationError
	 */
	public function testUpdate_WhitespaceName_ReturnsValidationError(): void {
		$response = $this->controller->update('   ');

		$this->assertEquals(400, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertEquals('VALIDATION_ERROR', $data['error']['code']);
	}

	/**
	 * GetStats_ReturnsHouseStats_SuccessResponse
	 */
	public function testGetStats_ReturnsHouseStats_SuccessResponse(): void {
		$stats = [
			'totalRooms' => 5,
			'totalFloors' => 2,
			'averageCleanliness' => 78.5,
			'totalTasks' => 12,
			'completedTasksThisWeek' => 8,
		];

		$this->houseService->method('getInstanceId')->willReturn('test-instance');
		$this->houseService->method('getOrCreateHouse')->willReturn($this->createMockHouse());
		$this->houseService->method('getHouseStats')
			->willReturn(['house' => $this->createMockHouse(), 'stats' => $stats]);
		$this->authService->method('ensureHouseMember')->willReturn($this->createMockHouseUser());

		$response = $this->controller->stats();

		$this->assertEquals(200, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
	}

	/**
	 * GetHealth_ReturnsHouseHealth_SuccessResponse
	 */
	public function testGetHealth_ReturnsHouseHealth_SuccessResponse(): void {
		$this->houseService->method('getInstanceId')->willReturn('test-instance');
		$this->houseService->method('getOrCreateHouse')->willReturn($this->createMockHouse());
		$this->houseService->method('calculateHouseHealth')
			->willReturn(82);
		$this->authService->method('ensureHouseMember')->willReturn($this->createMockHouseUser());

		$response = $this->controller->health();

		$this->assertEquals(200, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
	}
}
