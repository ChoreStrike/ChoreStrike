<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Controller;

use OCA\ChoreStrike\Controller\CompletionController;
use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Exception\ConflictException;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Exception\NotFoundException;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\CompletionService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\RoomTaskReadService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CompletionController (CS-PLAN-070 WP3 §3.6): targeted
 * completion correction — exact numeric completion id, closed receipt
 * payloads, shared envelope/error mapping. GET is write-free.
 */
class CompletionControllerTest extends TestCase {
	private CompletionController $controller;
	private CompletionService $completionService;
	private RoomTaskReadService $roomTaskReadService;
	private HouseService $houseService;
	private AuthorizationService $authService;
	private IRequest $request;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->completionService = $this->createMock(CompletionService::class);
		$this->roomTaskReadService = $this->createMock(RoomTaskReadService::class);
		$this->roomTaskReadService->method('timeZoneForHouse')->willReturn(new \DateTimeZone('UTC'));
		$this->houseService = $this->createMock(HouseService::class);
		$this->authService = $this->createMock(AuthorizationService::class);

		$house = new House();
		$house->setId(1);
		$this->houseService->method('getInstanceId')->willReturn('instance-123');
		$this->houseService->method('getOrCreateHouse')->willReturn($house);
		$this->authService->method('assertHouseMember')->willReturn($this->viewer());

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text) => $text);

		$this->controller = new CompletionController(
			$this->request,
			$this->houseService,
			$this->authService,
			$this->completionService,
			$this->roomTaskReadService,
			$l10n,
			$this->createMock(AppLogger::class)
		);
	}

	private function viewer(): HouseUser {
		$viewer = new HouseUser();
		$viewer->setId(11);
		$viewer->setHouseId(1);
		$viewer->setNextcloudUserId('viewer-nc');
		$viewer->setRole('owner');
		return $viewer;
	}

	private function stubParams(array $params): void {
		$this->request->method('getParams')->willReturn($params);
	}

	private const UUID = '11111111-2222-4333-8444-555555555555';

	/**
	 * Show_ReturnsViewerSafeCompletionPayload
	 */
	public function testShow_ReturnsViewerSafeCompletionPayload(): void {
		$payload = [
			'completion' => ['id' => 9, 'completionVersion' => 0, 'allocationRevision' => 1],
			'allocation' => [['userId' => 'mom', 'sharePct' => 50]],
			'canCorrect' => true,
		];

		$this->completionService->expects($this->once())
			->method('showCompletion')
			->with(1, $this->isInstanceOf(HouseUser::class), 9)
			->willReturn($payload);

		$response = $this->controller->show(9);

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(200, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertSame($payload, $data['data']);
	}

	/**
	 * Show_InvisibleTarget_Maps404AntiEnumeration
	 */
	public function testShow_InvisibleTarget_Maps404AntiEnumeration(): void {
		$this->completionService->method('showCompletion')
			->willThrowException(new NotFoundException('Completion not found'));

		$response = $this->controller->show(99);

		$this->assertEquals(404, $response->getStatus());
		$this->assertEquals('RESOURCE_NOT_FOUND', $response->getData()['error']['code']);
	}

	/**
	 * Undo_DelegatesToTargetedCorrectionAggregate
	 */
	public function testUndo_DelegatesToTargetedCorrectionAggregate(): void {
		$this->stubParams([
			'commandId' => self::UUID,
			'expectedVersion' => 0,
			'reasonCode' => 'wrong_person',
		]);

		$roomTask = new \OCA\ChoreStrike\Db\RoomTask();
		$result = ['completionId' => 9, 'completionVersion' => 1, 'undone' => true,
			'roomTask' => $roomTask, 'occurrence' => ['classification' => 'late']];
		$this->completionService->expects($this->once())
			->method('undo')
			->with(1, $this->isInstanceOf(HouseUser::class), 9, self::UUID, 0, 'wrong_person')
			->willReturn($result);
		// CR-084-03: the row status follows the fresh selection block.
		$this->roomTaskReadService->expects($this->once())->method('roomTaskPayload')
			->with($roomTask, $this->isInstanceOf(\DateTimeZone::class), 'late')
			->willReturn(['id' => 4, 'status' => 'overdue']);

		$response = $this->controller->undo(9);

		$this->assertEquals(200, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertSame(array_replace($result, ['roomTask' => ['id' => 4, 'status' => 'overdue']]), $data['data']);
	}

	/**
	 * Undo_UnknownField_Answers400ClosedSet
	 */
	public function testUndo_UnknownField_Answers400ClosedSet(): void {
		$this->stubParams([
			'commandId' => self::UUID,
			'expectedVersion' => 0,
			'completedAt' => '2026-09-21T10:00:00Z',
		]);

		$this->completionService->expects($this->never())->method('undo');

		$response = $this->controller->undo(9);

		$this->assertEquals(400, $response->getStatus());
		$this->assertStringContainsString('Unknown field', $response->getData()['error']['message']);
	}

	/**
	 * Undo_MalformedReceipt_Answers400
	 */
	public function testUndo_MalformedReceipt_Answers400(): void {
		$this->stubParams(['commandId' => 'not-a-uuid', 'expectedVersion' => 0]);

		$this->completionService->expects($this->never())->method('undo');

		$response = $this->controller->undo(9);

		$this->assertEquals(400, $response->getStatus());
		$this->assertStringContainsString('commandId', $response->getData()['error']['message']);
	}

	/**
	 * Undo_StaleVersion_Maps409
	 */
	public function testUndo_StaleVersion_Maps409(): void {
		$this->stubParams(['commandId' => self::UUID, 'expectedVersion' => 0]);

		$this->completionService->method('undo')
			->willThrowException(new ConflictException('The completion changed since it was displayed'));

		$response = $this->controller->undo(9);

		$this->assertEquals(409, $response->getStatus());
		$this->assertEquals('CONFLICT', $response->getData()['error']['code']);
	}

	/**
	 * Undo_NoCorrectionAuthority_Maps403
	 */
	public function testUndo_NoCorrectionAuthority_Maps403(): void {
		$this->stubParams(['commandId' => self::UUID, 'expectedVersion' => 0]);

		$this->completionService->method('undo')
			->willThrowException(new ForbiddenException('Only the completer or a house owner can undo a completion'));

		$response = $this->controller->undo(9);

		$this->assertEquals(403, $response->getStatus());
		$this->assertEquals('FORBIDDEN', $response->getData()['error']['code']);
	}

	/**
	 * Undo_InvisibleTarget_Maps404
	 */
	public function testUndo_InvisibleTarget_Maps404(): void {
		$this->stubParams(['commandId' => self::UUID, 'expectedVersion' => 0]);

		$this->completionService->method('undo')
			->willThrowException(new NotFoundException('Completion not found'));

		$response = $this->controller->undo(99);

		$this->assertEquals(404, $response->getStatus());
		$this->assertEquals('RESOURCE_NOT_FOUND', $response->getData()['error']['code']);
	}

	/**
	 * Attribute_DelegatesWithParticipantsAndReview
	 */
	public function testAttribute_DelegatesWithParticipantsAndReview(): void {
		$participants = [
			['userId' => 'mom', 'sharePct' => 50],
			['userId' => 'dad', 'sharePct' => 50],
		];
		$this->stubParams([
			'commandId' => self::UUID,
			'expectedVersion' => 1,
			'participants' => $participants,
			'allocationReviewed' => true,
			'reasonCode' => 'wrong_split',
		]);

		$roomTask = new \OCA\ChoreStrike\Db\RoomTask();
		$result = ['completionId' => 9, 'completionVersion' => 2, 'allocationRevision' => 2,
			'roomTask' => $roomTask, 'occurrence' => null];
		$this->completionService->expects($this->once())
			->method('attribute')
			->with(1, $this->isInstanceOf(HouseUser::class), 9, self::UUID, 1, $participants, true, 'wrong_split')
			->willReturn($result);
		// CR-084-03: no selection block rides along — resolve it best-effort.
		$this->roomTaskReadService->expects($this->once())->method('committedRoomTaskPayload')
			->with(1, $roomTask, $this->isInstanceOf(\DateTimeZone::class), 'attribute')
			->willReturn(['id' => 4, 'status' => 'due_today']);

		$response = $this->controller->attribute(9);

		$this->assertEquals(200, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertSame(array_replace($result, ['roomTask' => ['id' => 4, 'status' => 'due_today']]), $data['data']);
	}

	/**
	 * Attribute_RejectsTimeEnergyOccurrenceEdits_ClosedSet
	 *
	 * The closed payload never accepts time/energy/occurrence edits.
	 */
	public function testAttribute_RejectsTimeEnergyOccurrenceEdits_ClosedSet(): void {
		$this->stubParams([
			'commandId' => self::UUID,
			'expectedVersion' => 1,
			'participants' => [['userId' => 'mom', 'sharePct' => 100]],
			'totalEnergy' => 5,
		]);

		$this->completionService->expects($this->never())->method('attribute');

		$response = $this->controller->attribute(9);

		$this->assertEquals(400, $response->getStatus());
		$this->assertStringContainsString('Unknown field', $response->getData()['error']['message']);
	}

	/**
	 * Attribute_EmptyParticipants_Answer400
	 */
	public function testAttribute_EmptyParticipants_Answer400(): void {
		$this->stubParams([
			'commandId' => self::UUID,
			'expectedVersion' => 1,
			'participants' => [],
		]);

		$this->completionService->expects($this->never())->method('attribute');

		$response = $this->controller->attribute(9);

		$this->assertEquals(400, $response->getStatus());
		$this->assertStringContainsString('participants must be a non-empty array', $response->getData()['error']['message']);
	}

	/**
	 * Attribute_OverlongReasonCode_Answers400
	 */
	public function testAttribute_OverlongReasonCode_Answers400(): void {
		$this->stubParams([
			'commandId' => self::UUID,
			'expectedVersion' => 1,
			'participants' => [['userId' => 'mom', 'sharePct' => 100]],
			'reasonCode' => str_repeat('x', 65),
		]);

		$this->completionService->expects($this->never())->method('attribute');

		$response = $this->controller->attribute(9);

		$this->assertEquals(400, $response->getStatus());
		$this->assertStringContainsString('reasonCode', $response->getData()['error']['message']);
	}

	/**
	 * Attribute_StaleVersion_Maps409
	 */
	public function testAttribute_StaleVersion_Maps409(): void {
		$this->stubParams([
			'commandId' => self::UUID,
			'expectedVersion' => 0,
			'participants' => [['userId' => 'mom', 'sharePct' => 100]],
		]);

		$this->completionService->method('attribute')
			->willThrowException(new ConflictException('The completion changed since it was displayed'));

		$response = $this->controller->attribute(9);

		$this->assertEquals(409, $response->getStatus());
	}
}
