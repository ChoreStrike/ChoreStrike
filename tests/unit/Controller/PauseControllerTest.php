<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Controller;

use OCA\ChoreStrike\Controller\PauseController;
use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Exception\ConflictException;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Exception\NotFoundException;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\PauseService;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Pause API error/shape mapping (CS-PLAN-073 §3.5): malformed 400,
 * visible role denial 403, foreign target 404, stale revision 409 —
 * service delegation carries the domain logic.
 *
 * @covers \OCA\ChoreStrike\Controller\PauseController
 */
class PauseControllerTest extends TestCase {
	private PauseController $controller;
	private PauseService|MockObject $pauseService;
	private HouseService|MockObject $houseService;
	private AuthorizationService|MockObject $authService;
	private IRequest|MockObject $request;

	protected function setUp(): void {
		parent::setUp();
		$this->pauseService = $this->createMock(PauseService::class);
		$this->houseService = $this->createMock(HouseService::class);
		$this->authService = $this->createMock(AuthorizationService::class);
		$this->request = $this->createMock(IRequest::class);

		$house = new House();
		$house->setId(7);
		$this->houseService->method('getInstanceId')->willReturn('instance');
		$this->houseService->method('getOrCreateHouse')->willReturn($house);
		$this->authService->method('assertHouseMember')->willReturn(new HouseUser());
		$this->authService->method('requireCurrentUserId')->willReturn('dad');

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text) => $text);
		$appLogger = $this->createMock(AppLogger::class);

		$this->controller = new PauseController(
			$this->request,
			$this->pauseService,
			$this->houseService,
			$this->authService,
			$l10n,
			$appLogger,
		);
	}

	private function params(array $params): void {
		$this->request->method('getParams')->willReturn($params);
	}

	public function testIndex_DelegatesToViewerSafeList(): void {
		$this->params([]);
		$this->pauseService->expects($this->once())
			->method('listForViewer')
			->with(7, 'dad')
			->willReturn([['pauseGroupId' => 1]]);

		$response = $this->controller->index();

		$this->assertEquals(200, $response->getStatus());
		$this->assertSame([['pauseGroupId' => 1]], $response->getData()['data']);
	}

	public function testCreate_PersonalScope_DelegatesToRequestForSelf(): void {
		$this->params(['scope' => 'personal', 'commandId' => 'x']);
		$this->pauseService->expects($this->once())
			->method('requestForSelf')
			->with(7, 'dad', ['commandId' => 'x'])
			->willReturn(['state' => 'requested']);

		$response = $this->controller->create();

		$this->assertEquals(200, $response->getStatus());
		$this->assertSame('requested', $response->getData()['data']['state']);
	}

	public function testCreate_HouseScope_DelegatesToCreateHousePause(): void {
		$this->params(['scope' => 'house', 'commandId' => 'x']);
		$this->pauseService->expects($this->once())
			->method('createHousePause')
			->willReturn(['state' => 'acknowledged']);

		$response = $this->controller->create();

		$this->assertEquals(200, $response->getStatus());
	}

	public function testCreate_UnknownScope_Rejected(): void {
		$this->params(['scope' => 'everyone']);

		$response = $this->controller->create();

		$this->assertEquals(400, $response->getStatus());
		$this->assertSame('VALIDATION_ERROR', $response->getData()['error']['code']);
	}

	public function testApprove_MapsServiceExceptions(): void {
		$this->params(['commandId' => 'x', 'expectedRevision' => 1]);
		$this->pauseService->method('approve')->willThrowException(new ConflictException('stale'));

		$response = $this->controller->approve(1);

		$this->assertEquals(409, $response->getStatus());
	}

	public function testReject_MapsForbiddenTo403(): void {
		$this->params([]);
		$this->pauseService->method('reject')->willThrowException(new ForbiddenException('Owner role required'));

		$response = $this->controller->reject(1);

		$this->assertEquals(403, $response->getStatus());
	}

	public function testUpdate_MapsNotFoundTo404(): void {
		$this->params([]);
		$this->pauseService->method('shorten')->willThrowException(new NotFoundException('Pause not found'));

		$response = $this->controller->update(99);

		$this->assertEquals(404, $response->getStatus());
	}

	public function testRecognizeIllness_Delegates(): void {
		$this->params(['commandId' => 'x', 'subjectUserId' => 'kid', 'startDate' => '2026-10-01', 'endDate' => '2026-10-02']);
		$this->pauseService->expects($this->once())
			->method('recognizeIllness')
			->willReturn(['state' => 'acknowledged']);

		$response = $this->controller->recognizeIllness();

		$this->assertEquals(200, $response->getStatus());
	}
}
