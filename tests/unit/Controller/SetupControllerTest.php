<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Controller;

use OCA\ChoreStrike\Controller\SetupController;
use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\HouseSetupService;
use OCA\ChoreStrike\Service\TemplateService;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SetupController.
 */
class SetupControllerTest extends TestCase {
	private SetupController $controller;
	private $templateService;
	private $houseSetupService;
	private $houseService;
	private $authService;
	private $request;

	protected function setUp(): void {
		parent::setUp();

		$this->templateService = $this->createMock(TemplateService::class);
		$this->houseSetupService = $this->createMock(HouseSetupService::class);
		$this->houseService = $this->createMock(HouseService::class);
		$this->authService = $this->createMock(AuthorizationService::class);
		$this->request = $this->createMock(IRequest::class);
		// Identity mock: message assertions match the wrapped source text.
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text) => $text);

		$this->controller = new SetupController(
			$this->request,
			$this->templateService,
			$this->houseSetupService,
			$this->houseService,
			$this->authService,
			$l10n,
			$this->createMock(AppLogger::class)
		);
	}

	private function stubHouse(int $houseId = 1): void {
		$house = new House();
		$ref = new \ReflectionProperty(House::class, 'id');
		$ref->setAccessible(true);
		$ref->setValue($house, $houseId);

		$this->houseService->method('getInstanceId')->willReturn('inst-1');
		$this->houseService->method('getOrCreateHouse')->willReturn($house);
		$this->authService->method('ensureHouseMember')->willReturn($this->createMock(HouseUser::class));
	}

	public function testCatalog_ReturnsLocalizedCatalog(): void {
		$this->stubHouse();
		$catalog = ['levels' => [], 'roomTypes' => [], 'templates' => []];
		$this->templateService->expects($this->once())
			->method('getCatalog')
			->willReturn($catalog);

		$response = $this->controller->catalog();

		$this->assertEquals(200, $response->getStatus());
		$body = $response->getData();
		$this->assertTrue($body['success']);
		$this->assertSame($catalog, $body['data']);
	}

	public function testCatalog_Forbidden_Returns403(): void {
		$this->houseService->method('getInstanceId')->willReturn('inst-1');
		$house = new House();
		$ref = new \ReflectionProperty(House::class, 'id');
		$ref->setAccessible(true);
		$ref->setValue($house, 1);
		$this->houseService->method('getOrCreateHouse')->willReturn($house);
		$this->authService->method('ensureHouseMember')
			->willThrowException(new ForbiddenException('nope'));

		$response = $this->controller->catalog();

		$this->assertEquals(403, $response->getStatus());
	}

	public function testApply_FirstRun_AppliesAndReturnsCounts(): void {
		$this->stubHouse();
		$this->houseService->expects($this->once())->method('hasFloors')->willReturn(false);

		$payload = ['intensityLevel' => 'putzteufel', 'floors' => [['name' => 'GF', 'rooms' => []]]];
		$this->request->method('getParams')->willReturn($payload);

		$result = ['floors' => 1, 'rooms' => 2, 'tasks' => 5, 'roomTasks' => 5, 'skipped' => []];
		$this->houseSetupService->expects($this->once())
			->method('applySetup')
			->with(1, $payload)
			->willReturn($result);

		$response = $this->controller->apply();

		$this->assertEquals(200, $response->getStatus());
		$body = $response->getData();
		$this->assertTrue($body['success']);
		$this->assertSame($result, $body['data']);
	}

	public function testApply_HouseAlreadySetUp_Returns409(): void {
		$this->stubHouse();
		$this->houseService->expects($this->once())->method('hasFloors')->willReturn(true);

		$response = $this->controller->apply();

		$this->assertEquals(409, $response->getStatus());
		$this->assertSame('HOUSE_ALREADY_SET_UP', $response->getData()['error']['code']);
	}

	public function testApply_InvalidPayload_Returns400(): void {
		$this->stubHouse();
		$this->houseService->method('hasFloors')->willReturn(false);

		$this->houseSetupService->method('applySetup')
			->willThrowException(new \InvalidArgumentException('Invalid intensity level'));

		// A payload carrying 'floors' passes readJsonBody so the service
		// (and its validation exception) is actually reached.
		$this->request->method('getParams')->willReturn(['intensityLevel' => 'bad', 'floors' => []]);

		$response = $this->controller->apply();

		$this->assertEquals(400, $response->getStatus());
		$this->assertSame('VALIDATION_ERROR', $response->getData()['error']['code']);
		// CR-SVC-49: the curated service message must surface verbatim.
		$this->assertSame('Invalid intensity level', $response->getData()['error']['message']);
	}

	public function testApply_NoJsonBody_Returns400(): void {
		$this->stubHouse();
		$this->houseService->method('hasFloors')->willReturn(false);
		$this->request->method('getParams')->willReturn([]);

		$response = $this->controller->apply();

		$this->assertEquals(400, $response->getStatus());
		$this->assertSame('VALIDATION_ERROR', $response->getData()['error']['code']);
	}
}
