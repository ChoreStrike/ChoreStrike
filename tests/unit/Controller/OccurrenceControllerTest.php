<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Controller;

use OCA\ChoreStrike\Controller\OccurrenceController;
use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Exception\ConflictException;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Exception\NotFoundException;
use OCA\ChoreStrike\Service\AgreementService;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\HouseService;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Occurrence agreement API mapping (CS-PLAN-073 §3.5): the route segment
 * is the canonical occurrence identity, and errors map as malformed 400,
 * visible role denial 403, foreign 404, stale 409.
 *
 * @covers \OCA\ChoreStrike\Controller\OccurrenceController
 */
class OccurrenceControllerTest extends TestCase {
	private OccurrenceController $controller;
	private AgreementService|MockObject $agreementService;
	private IRequest|MockObject $request;

	protected function setUp(): void {
		parent::setUp();
		$this->agreementService = $this->createMock(AgreementService::class);
		$houseService = $this->createMock(HouseService::class);
		$authService = $this->createMock(AuthorizationService::class);
		$this->request = $this->createMock(IRequest::class);

		$house = new House();
		$house->setId(7);
		$houseService->method('getInstanceId')->willReturn('instance');
		$houseService->method('getOrCreateHouse')->willReturn($house);
		$authService->method('assertHouseMember')->willReturn(new HouseUser());
		$authService->method('requireCurrentUserId')->willReturn('dad');

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text) => $text);

		$this->controller = new OccurrenceController(
			$this->request,
			$this->agreementService,
			$houseService,
			$authService,
			$l10n,
			$this->createMock(AppLogger::class),
		);
	}

	private function params(array $params): void {
		$this->request->method('getParams')->willReturn($params);
	}

	public function testAmend_RouteSegmentIsTheCanonicalOccurrenceIdentity(): void {
		$this->params(['roomTaskId' => 5]);
		$this->agreementService->expects($this->once())
			->method('amend')
			->with(7, 'dad', 5, $this->callback(static fn (array $payload): bool => $payload['occurrenceId'] === '3:1' && !isset($payload['roomTaskId'])))
			->willReturn(['occurrenceId' => '3:1', 'expectedVersion' => 1]);

		$response = $this->controller->amend('3:1');

		$this->assertEquals(200, $response->getStatus());
		$this->assertSame('3:1', $response->getData()['data']['occurrenceId']);
	}

	public function testAmend_MissingRoomTaskId_Rejected(): void {
		$this->params([]);

		$response = $this->controller->amend('3:1');

		$this->assertEquals(400, $response->getStatus());
		$this->assertSame('VALIDATION_ERROR', $response->getData()['error']['code']);
	}

	public function testCatchUp_MapsConflictTo409(): void {
		$this->params(['roomTaskId' => 5]);
		$this->agreementService->method('catchUp')->willThrowException(new ConflictException('The amendment deadline has passed'));

		$response = $this->controller->catchUp('3:2');

		$this->assertEquals(409, $response->getStatus());
		$this->assertSame('CONFLICT', $response->getData()['error']['code']);
	}

	public function testRestart_MapsForbiddenTo403(): void {
		$this->params(['roomTaskId' => 5]);
		$this->agreementService->method('restart')->willThrowException(new ForbiddenException('Owner role required'));

		$response = $this->controller->restart('3:2');

		$this->assertEquals(403, $response->getStatus());
	}

	public function testRestart_MapsForeignTaskTo404(): void {
		$this->params(['roomTaskId' => 5]);
		$this->agreementService->method('restart')->willThrowException(new NotFoundException('Room task not found'));

		$response = $this->controller->restart('3:2');

		$this->assertEquals(404, $response->getStatus());
	}
}
