<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Controller;

use OCA\ChoreStrike\Controller\TemplateController;
use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\TemplateService;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for TemplateController
 */
class TemplateControllerTest extends TestCase {
	private TemplateController $controller;
	private TemplateService $templateService;
	private HouseService $houseService;
	private AuthorizationService $authService;
	private IRequest $request;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->templateService = $this->createMock(TemplateService::class);
		$this->houseService = $this->createMock(HouseService::class);
		$this->authService = $this->createMock(AuthorizationService::class);

		$this->controller = new TemplateController(
			$this->request,
			$this->templateService,
			$this->houseService,
			$this->authService
		);
	}

	private function expectHouseContext(int $houseId = 1): void {
		$house = $this->createMock(House::class);
		$house->method('getId')->willReturn($houseId);

		$houseUser = new HouseUser();
		$houseUser->setNextcloudUserId('caller');
		$houseUser->setRole('member');

		$this->houseService->method('getInstanceId')->willReturn('inst-1');
		$this->houseService->method('getOrCreateHouse')->willReturn($house);
		$this->authService->method('ensureHouseMember')->willReturn($houseUser);
	}

	public function testIndex_ReturnsTemplates(): void {
		$this->expectHouseContext();
		$templates = [['id' => 'tpl-1', 'name' => 'Test']];

		$this->templateService->expects($this->once())
			->method('getAllTemplates')
			->willReturn($templates);

		$response = $this->controller->index();

		$this->assertEquals(200, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertEquals($templates, $data['data']);
	}

	public function testIndex_ForbiddenException_Returns403(): void {
		$house = $this->createMock(House::class);
		$house->method('getId')->willReturn(1);
		$this->houseService->method('getInstanceId')->willReturn('inst-1');
		$this->houseService->method('getOrCreateHouse')->willReturn($house);
		$this->authService->method('ensureHouseMember')
			->willThrowException(new ForbiddenException('Not a member'));

		$response = $this->controller->index();

		$this->assertEquals(403, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertEquals('FORBIDDEN', $data['error']['code']);
	}

	public function testCategories_ReturnsCategoryList(): void {
		$this->expectHouseContext();
		$this->templateService->method('getCategories')->willReturn(['kitchen', 'bathroom']);

		$response = $this->controller->categories();

		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertEquals(['kitchen', 'bathroom'], $data['data']);
	}

	public function testByCategory_ReturnsFilteredTemplates(): void {
		$this->expectHouseContext();
		$this->templateService->method('getTemplatesByCategory')
			->with('kitchen')
			->willReturn(['tpl-1' => ['id' => 'tpl-1']]);

		$response = $this->controller->byCategory('kitchen');

		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertCount(1, $data['data']);
	}

	public function testByRoomType_ReturnsFilteredTemplates(): void {
		$this->expectHouseContext();
		$this->templateService->method('getTemplatesByRoomType')
			->with('Kitchen')
			->willReturn(['tpl-1' => ['id' => 'tpl-1']]);

		$response = $this->controller->byRoomType('Kitchen');

		$data = $response->getData();
		$this->assertTrue($data['success']);
	}

	public function testSearch_EmptyQuery_Returns400(): void {
		$response = $this->controller->search('  ');

		$this->assertEquals(400, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertEquals('VALIDATION_ERROR', $data['error']['code']);
	}

	public function testSearch_ValidQuery_ReturnsResults(): void {
		$this->expectHouseContext();
		$this->templateService->method('searchTemplates')
			->with('clean')
			->willReturn(['tpl-1' => ['id' => 'tpl-1']]);

		$response = $this->controller->search('clean');

		$data = $response->getData();
		$this->assertTrue($data['success']);
	}

	public function testSuggestions_EmptyRoomName_Returns400(): void {
		$response = $this->controller->suggestions('');

		$this->assertEquals(400, $response->getStatus());
	}

	public function testSuggestions_ValidRoom_ReturnsTemplates(): void {
		$this->expectHouseContext();
		$this->templateService->method('getSuggestionsForRoom')
			->with('Kitchen')
			->willReturn(['tpl-1' => ['id' => 'tpl-1']]);

		$response = $this->controller->suggestions('Kitchen');

		$data = $response->getData();
		$this->assertTrue($data['success']);
	}

	public function testImport_EmptyTemplateId_Returns400(): void {
		$response = $this->controller->import('  ');

		$this->assertEquals(400, $response->getStatus());
	}

	public function testImport_ValidTemplate_ReturnsTask(): void {
		$this->expectHouseContext();
		$task = new Task();
		$task->setId(10);
		$task->setName('Imported Task');

		$this->templateService->method('importTemplate')
			->with('tpl-1')
			->willReturn($task);

		$response = $this->controller->import('tpl-1');

		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertEquals(10, $data['data']['id']);
	}

	public function testImport_TemplateNotFound_Returns404(): void {
		$this->expectHouseContext();
		$this->templateService->method('importTemplate')
			->willThrowException(new \InvalidArgumentException('Template not found'));

		$response = $this->controller->import('bad-id');

		$this->assertEquals(404, $response->getStatus());
		$data = $response->getData();
		$this->assertEquals('TEMPLATE_NOT_FOUND', $data['error']['code']);
	}

	public function testBulkImport_EmptyIds_Returns400(): void {
		$this->request->method('getParam')->with('templateIds', [])->willReturn([]);

		$response = $this->controller->bulkImport();

		$this->assertEquals(400, $response->getStatus());
	}

	public function testBulkImport_ValidIds_ReturnsCreatedTasks(): void {
		$this->expectHouseContext();
		$this->request->method('getParam')->with('templateIds', [])->willReturn(['tpl-1']);

		$task = new Task();
		$task->setId(11);
		$this->templateService->method('bulkImport')->willReturn([$task]);

		$response = $this->controller->bulkImport();

		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertCount(1, $data['data']);
	}

	public function testStatistics_ReturnsStats(): void {
		$this->expectHouseContext();
		$stats = ['total' => 5, 'byCategory' => ['kitchen' => 3]];
		$this->templateService->method('getStatistics')->willReturn($stats);

		$response = $this->controller->statistics();

		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertEquals(5, $data['data']['total']);
	}

	public function testIndex_GenericException_Returns500WithSafeMessage(): void {
		$house = $this->createMock(House::class);
		$house->method('getId')->willReturn(1);
		$this->houseService->method('getInstanceId')->willReturn('inst-1');
		$this->houseService->method('getOrCreateHouse')->willReturn($house);
		$this->authService->method('ensureHouseMember')->willReturn(new HouseUser());
		$this->templateService->method('getAllTemplates')
			->willThrowException(new \RuntimeException('DB connection lost'));

		$response = $this->controller->index();

		$this->assertEquals(500, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertStringNotContainsString('DB connection', $data['error']['message']);
	}
}
