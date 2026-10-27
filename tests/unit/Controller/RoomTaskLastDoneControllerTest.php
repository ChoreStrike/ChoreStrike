<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Controller;

use OCA\ChoreStrike\Controller\RoomTaskLastDoneController;
use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Exception\ConflictException;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\RoomTaskLastDoneService;
use OCA\ChoreStrike\Service\RoomTaskReadService;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * FEAT-UX-045 (CS-PLAN-088 D-088-20): the closed input of the "last done"
 * endpoint and its error mapping.
 */
class RoomTaskLastDoneControllerTest extends TestCase {
	private const UUID = '0f8fad5b-d9cb-469f-a165-70867728950e';

	private RoomTaskLastDoneService|MockObject $service;
	private IRequest|MockObject $request;
	private RoomTaskLastDoneController $controller;

	protected function setUp(): void {
		parent::setUp();
		$this->service = $this->createMock(RoomTaskLastDoneService::class);
		$this->request = $this->createMock(IRequest::class);
		$house = new House();
		$house->setId(3);
		$houseService = $this->createMock(HouseService::class);
		$houseService->method('getOrCreateHouse')->willReturn($house);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);
		$this->controller = new RoomTaskLastDoneController(
			$this->request,
			$this->service,
			$this->createMock(RoomTaskReadService::class),
			$houseService,
			$l10n,
			$this->createMock(\OCA\ChoreStrike\Service\AppLogger::class)
		);
	}

	private function params(array $params): void {
		$this->request->method('getParam')->willReturnCallback(static fn (string $key) => $params[$key] ?? null);
	}

	public function testUpdate_WithoutAValidCommandId_Is400WithoutAServiceCall(): void {
		$this->params(['commandId' => 'not-a-uuid', 'date' => '2026-10-20']);
		$this->service->expects($this->never())->method('recordLastDone');

		$response = $this->controller->update(5);

		$this->assertSame(400, $response->getStatus());
		$this->assertSame('VALIDATION_ERROR', $response->getData()['error']['code']);
	}

	public function testUpdate_ValidInput_RecordsWithTheLowercasedCommandId(): void {
		$this->params(['commandId' => strtoupper(self::UUID), 'date' => '2026-10-20']);
		$this->service->expects($this->once())->method('recordLastDone')
			->with(5, 3, '2026-10-20', self::UUID)->willReturn(new RoomTask());

		$response = $this->controller->update(5);

		$this->assertSame(200, $response->getStatus());
		$this->assertTrue($response->getData()['success']);
	}

	public function testUpdate_OverdueRefusal_Is409Conflict(): void {
		$this->params(['commandId' => self::UUID, 'date' => '2026-10-20']);
		$this->service->method('recordLastDone')->willThrowException(new ConflictException('This task is overdue.'));

		$response = $this->controller->update(5);

		$this->assertSame(409, $response->getStatus());
		$this->assertSame('CONFLICT', $response->getData()['error']['code']);
	}
}
