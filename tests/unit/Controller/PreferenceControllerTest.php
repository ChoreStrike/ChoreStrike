<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Controller;

use OCA\ChoreStrike\Controller\PreferenceController;
use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Exception\ConflictException;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\PreferenceService;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PreferenceController (CS-PLAN-070 WP5, C5h): the thin
 * SELF-ONLY endpoint — membership gates run here; validation, the
 * versioned update and the error mapping (400 validation / 409 conflict /
 * 403 forbidden) live in PreferenceService and the shared formatter.
 */
class PreferenceControllerTest extends TestCase {
	private PreferenceController $controller;
	private PreferenceService|MockObject $preferenceService;
	private HouseService|MockObject $houseService;
	private AuthorizationService|MockObject $authService;
	private IRequest|MockObject $request;

	protected function setUp(): void {
		parent::setUp();
		$this->preferenceService = $this->createMock(PreferenceService::class);
		$this->houseService = $this->createMock(HouseService::class);
		$this->authService = $this->createMock(AuthorizationService::class);
		$this->request = $this->createMock(IRequest::class);

		$this->controller = new PreferenceController(
			$this->request,
			$this->preferenceService,
			$this->houseService,
			$this->authService,
			$this->createIdentityL10n(),
			$this->createMock(\OCA\ChoreStrike\Service\AppLogger::class)
		);
	}

	private function createIdentityL10n(): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text) => $text);
		return $l10n;
	}

	private function member(int $id): HouseUser {
		$member = new HouseUser();
		$member->setId($id);
		$member->setNextcloudUserId('nc-' . $id);
		return $member;
	}

	private function house(): House {
		$house = new House();
		$house->setId(1);
		return $house;
	}

	private function wireHouseAccess(int $memberId = 11): HouseUser {
		$viewer = $this->member($memberId);
		$this->houseService->method('getInstanceId')->willReturn('instance-1');
		$this->houseService->method('getOrCreateHouse')->willReturn($this->house());
		$this->authService->method('assertHouseMember')->willReturn($viewer);
		return $viewer;
	}

	public function testShow_ReturnsTheOwnPayload(): void {
		$viewer = $this->wireHouseAccess();
		$payload = [
			'version' => 3,
			'taskDigestEnabled' => true,
			'orgDigestEnabled' => false,
			'digestTime' => '19:30',
			'quietStart' => null,
			'quietEnd' => null,
			'soundEnabled' => false,
			'animationEnabled' => false,
			'arcadeVisible' => true,
			'catalogVisible' => true,
			'ranking' => ['state' => 'pending', 'generation' => 2, 'effectiveMonday' => '2026-10-05'],
		];
		$this->preferenceService->expects($this->once())
			->method('getOwnPreferences')
			->with(1, $viewer)
			->willReturn($payload);

		$response = $this->controller->show();

		$this->assertEquals(200, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertSame($payload, $data['data']);
	}

	public function testUpdate_DelegatesTheSelfOnlyMutation(): void {
		$viewer = $this->wireHouseAccess();
		$this->request->method('getParams')->willReturn([
			'commandId' => '11111111-1111-4111-8111-111111111111',
			'expectedVersion' => 2,
			'taskDigestEnabled' => true,
			'digestTime' => '19:00',
		]);
		$payload = ['version' => 3, 'taskDigestEnabled' => true];
		$this->preferenceService->expects($this->once())
			->method('updateOwnPreferences')
			->with(1, $viewer, $this->callback(static function (array $input): bool {
				// The command identity and route internals never reach the
				// service as field keys.
				return !array_key_exists('commandId', $input)
					&& $input['expectedVersion'] === 2
					&& $input['taskDigestEnabled'] === true;
			}), '11111111-1111-4111-8111-111111111111')
			->willReturn($payload);

		$response = $this->controller->update();

		$this->assertEquals(200, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertSame($payload, $data['data']);
	}

	public function testUpdate_MissingCommandId_IsA400(): void {
		$this->wireHouseAccess();
		$this->request->method('getParams')->willReturn([
			'expectedVersion' => 2,
			'taskDigestEnabled' => true,
		]);
		$this->preferenceService->expects($this->never())->method('updateOwnPreferences');

		$response = $this->controller->update();

		$this->assertEquals(400, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertSame('PREFERENCES_VALIDATION_ERROR', $data['error']['code']);
	}

	public function testUpdate_UnknownField_IsA400BeforeAnySideEffect(): void {
		$this->wireHouseAccess();
		$this->request->method('getParams')->willReturn([
			'commandId' => '11111111-1111-4111-8111-111111111111',
			'expectedVersion' => 2,
			'sneakyField' => true,
		]);
		$this->preferenceService->expects($this->once())
			->method('updateOwnPreferences')
			->willThrowException(new \InvalidArgumentException('Unknown preference field'));

		$response = $this->controller->update();

		$this->assertEquals(400, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertSame('PREFERENCES_VALIDATION_ERROR', $data['error']['code']);
	}

	public function testUpdate_StaleVersion_IsA409(): void {
		$this->wireHouseAccess();
		$this->request->method('getParams')->willReturn([
			'commandId' => '11111111-1111-4111-8111-111111111111',
			'expectedVersion' => 1,
		]);
		$this->preferenceService->method('updateOwnPreferences')
			->willThrowException(new ConflictException('The preferences changed since they were displayed'));

		$response = $this->controller->update();

		$this->assertEquals(409, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertSame('CONFLICT', $data['error']['code']);
	}

	public function testShow_Forbidden_IsA403(): void {
		$this->wireHouseAccess();
		$this->preferenceService->method('getOwnPreferences')
			->willThrowException(new ForbiddenException('not a member'));

		$response = $this->controller->show();

		$this->assertEquals(403, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
	}
}
