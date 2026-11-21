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

	public function testDestroy_DelegatesToDeleteWithoutRoutingParams(): void {
		$this->params(['commandId' => 'x', 'expectedRevision' => 2, 'id' => 7, '_route' => 'chorestrike.pause.destroy']);
		$this->pauseService->expects($this->once())
			->method('delete')
			->with($this->anything(), $this->anything(), 7, ['commandId' => 'x', 'expectedRevision' => 2])
			->willReturn(['pauseGroupId' => 7, 'revision' => 3, 'state' => 'cancelled']);

		$response = $this->controller->destroy(7);

		$this->assertEquals(200, $response->getStatus());
		$this->assertSame('cancelled', $response->getData()['data']['state']);
	}

	/** @dataProvider destroyErrorProvider */
	public function testDestroy_MapsServiceExceptions(\Exception $exception, int $status): void {
		$this->params([]);
		$this->pauseService->method('delete')->willThrowException($exception);

		$this->assertEquals($status, $this->controller->destroy(7)->getStatus());
	}

	public static function destroyErrorProvider(): array {
		return [
			'malformed' => [new \InvalidArgumentException('A valid expectedRevision is required'), 400],
			'not the subject or the owner' => [new ForbiddenException('Owner role required'), 403],
			'foreign target' => [new NotFoundException('Pause not found'), 404],
			'stale or ended' => [new ConflictException('An absence that has ended cannot be deleted'), 409],
			'unexpected' => [new \RuntimeException('boom'), 500],
		];
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
