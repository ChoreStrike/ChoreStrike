<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Controller;

use OCA\ChoreStrike\Controller\RewardController;
use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Exception\ConflictException;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Exception\NotFoundException;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\RewardService;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the catalog HTTP surface (CS-PLAN-070 WP7, CS-PLAN-078
 * §3.3/C4.10): strict money/text validation, scope/actor matrix mapping
 * and the 400/403/404/409 error shapes.
 */
class RewardControllerTest extends TestCase {
	private RewardController $controller;
	private $rewardService;
	private $authService;
	private $houseService;
	private HouseUser $viewer;

	protected function setUp(): void {
		$this->rewardService = $this->createMock(RewardService::class);
		$this->authService = $this->createMock(AuthorizationService::class);
		$this->houseService = $this->createMock(HouseService::class);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text) => $text);

		$this->controller = new RewardController(
			$this->createRequest([]),
			$this->rewardService,
			$this->authService,
			$this->houseService,
			$l10n,
			$this->createMock(AppLogger::class),
		);

		$house = new House();
		$house->setId(1);
		$this->houseService->method('getInstanceId')->willReturn('instance-123');
		$this->houseService->method('getOrCreateHouse')->willReturn($house);
		// One swappable viewer identity per test (identity assertions use
		// the same instance).
		$this->viewer = new HouseUser();
		$this->authService->method('assertHouseMember')->willReturnCallback(fn (): HouseUser => $this->viewer);
	}

	/**
	 * @param array<string, mixed> $params
	 */
	private function createRequest(array $params): IRequest {
		$mock = $this->getMockBuilder(IRequest::class)->getMock();
		$mock->method('getParams')->willReturn($params);
		$mock->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => array_key_exists($key, $params) ? $params[$key] : $default
		);
		return $mock;
	}

	/**
	 * @param array<string, mixed> $params
	 */
	private function useRequest(array $params): void {
		$ref = new \ReflectionClass(\OCP\AppFramework\Controller::class);
		$prop = $ref->getProperty('request');
		$prop->setAccessible(true);
		$prop->setValue($this->controller, $this->createRequest($params));
	}

	public function testIndex_DelegatesActiveScopeWithBounds(): void {
		$this->useRequest(['scope' => 'active', 'limit' => 25, 'cursor' => 7]);
		$this->rewardService->expects($this->once())
			->method('listItems')
			->with(1, $this->identicalTo($this->viewer), 'active', 7, 25)
			->willReturn(['catalogEnabled' => true, 'items' => []]);

		$response = $this->controller->index();

		$this->assertEquals(200, $response->getStatus());
		$this->assertTrue($response->getData()['success']);
	}

	public function testIndex_DefaultScopeIsActiveAndBounded(): void {
		$this->useRequest([]);
		$this->rewardService->expects($this->once())
			->method('listItems')
			->with(1, $this->identicalTo($this->viewer), 'active', null, RewardService::DEFAULT_LIMIT)
			->willReturn(['catalogEnabled' => false, 'items' => []]);

		$response = $this->controller->index();

		$this->assertEquals(200, $response->getStatus());
		$this->assertFalse($response->getData()['data']['catalogEnabled']);
	}

	public function testIndex_RejectsUnknownScope(): void {
		$this->useRequest(['scope' => 'everything']);

		$response = $this->controller->index();

		$this->assertEquals(400, $response->getStatus());
		$this->assertEquals('VALIDATION_ERROR', $response->getData()['error']['code']);
	}

	public function testIndex_AcceptsCanonicalDigitStringLimit(): void {
		// Real HTTP GET query params arrive as strings — paging must work.
		$this->useRequest(['limit' => '25']);
		$this->rewardService->expects($this->once())
			->method('listItems')
			->with(1, $this->identicalTo($this->viewer), 'active', null, 25)
			->willReturn(['catalogEnabled' => true, 'items' => []]);

		$response = $this->controller->index();

		$this->assertEquals(200, $response->getStatus());
	}

	public function testIndex_RejectsNonCanonicalLimitStrings(): void {
		foreach (['12.5', '1e3', '-5', 'abc'] as $bad) {
			$this->useRequest(['limit' => $bad]);
			$response = $this->controller->index();
			$this->assertEquals(400, $response->getStatus(), "limit '$bad' must 400");
		}
	}

	public function testIndex_MapsAdminDenialTo403(): void {
		$this->useRequest(['scope' => 'admin']);
		$this->rewardService->method('listItems')
			->willThrowException(new ForbiddenException('Owner role required'));

		$response = $this->controller->index();

		$this->assertEquals(403, $response->getStatus());
		$this->assertEquals('FORBIDDEN', $response->getData()['error']['code']);
	}

	public function testCreate_DelegatesWhitelistedPayload(): void {
		$this->useRequest(['title' => 'Cinema', 'priceMinor' => 1250, 'commandId' => '11111111-1111-4111-8111-111111111111']);
		$this->rewardService->expects($this->once())
			->method('createItem')
			->with(1, $this->callback(static fn (array $p): bool => $p['priceMinor'] === 1250))
			->willReturn(['id' => 9, 'title' => 'Cinema', 'priceMinor' => 1250, 'archived' => false, 'version' => 0]);

		$response = $this->controller->create();

		$this->assertEquals(200, $response->getStatus());
		$this->assertSame(1250, $response->getData()['data']['priceMinor']);
	}

	public function testCreate_MapsValidationTo400(): void {
		$this->rewardService->method('createItem')
			->willThrowException(new \InvalidArgumentException('priceMinor must be a positive whole number of hundredths'));

		$response = $this->controller->create();

		$this->assertEquals(400, $response->getStatus());
		$this->assertStringContainsString('priceMinor', $response->getData()['error']['message']);
	}

	public function testCreate_MapsConflictTo409(): void {
		$this->rewardService->method('createItem')
			->willThrowException(new ConflictException('The house state changed since it was displayed'));

		$response = $this->controller->create();

		$this->assertEquals(409, $response->getStatus());
		$this->assertEquals('CONFLICT', $response->getData()['error']['code']);
	}

	public function testUpdate_MapsForeignItemTo404(): void {
		$this->rewardService->method('updateItem')
			->willThrowException(new NotFoundException('Reward item not found'));

		$response = $this->controller->update(99);

		$this->assertEquals(404, $response->getStatus());
		$this->assertEquals('RESOURCE_NOT_FOUND', $response->getData()['error']['code']);
	}

	public function testUnexpectedError_StaysInternalAndSafe(): void {
		$this->rewardService->method('updateItem')
			->willThrowException(new \Exception('SQLSTATE[HY000]: internal detail'));

		$response = $this->controller->update(3);

		$this->assertEquals(500, $response->getStatus());
		$this->assertStringNotContainsString('SQLSTATE', $response->getData()['error']['message'], 'internal details never leak');
	}
}
