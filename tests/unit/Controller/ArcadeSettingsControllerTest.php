<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Controller;

use OCA\ChoreStrike\Controller\ArcadeSettingsController;
use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Exception\ValidationException;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\ArcadeAccessService;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\HouseService;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * CS-PLAN-090 WP-J (D-090-63/66): the owner's arcade controls check
 * membership and the owner role before anything else, validate the
 * threshold envelope and map each service outcome.
 */
class ArcadeSettingsControllerTest extends TestCase {
	private AuthorizationService&MockObject $auth;
	private ArcadeAccessService&MockObject $arcade;

	protected function setUp(): void {
		parent::setUp();
		$this->auth = $this->createMock(AuthorizationService::class);
		$this->arcade = $this->createMock(ArcadeAccessService::class);
	}

	private function controller(array $params = []): ArcadeSettingsController {
		$house = new House();
		$house->setId(3);
		$houseService = $this->createMock(HouseService::class);
		$houseService->method('getInstanceId')->willReturn('instance');
		$houseService->method('getOrCreateHouse')->willReturn($house);
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, $default = null) => $params[$key] ?? $default);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		return new ArcadeSettingsController($request, $houseService, $this->auth, $this->arcade, $l10n, $this->createMock(AppLogger::class));
	}

	public function testUpdateThresholds_SavesForAnOwnerAndReturnsTheCatalog(): void {
		$thresholds = ['spots' => 0, 'socks' => 1, 'snake' => 10, 'tiles' => 25, 'dust' => 50];
		$catalog = ['gateEnabled' => true, 'finishedChores' => 0, 'games' => []];
		$this->auth->expects($this->once())->method('assertHouseMember')->with(3);
		$this->auth->expects($this->once())->method('requireHouseOwner')->with(3);
		$this->arcade->expects($this->once())->method('setThresholds')->with(3, $thresholds)->willReturn($catalog);

		$response = $this->controller(['thresholds' => $thresholds])->updateThresholds();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame($catalog, $response->getData()['data']);
	}

	/**
	 * @dataProvider malformedThresholdEnvelopes
	 */
	public function testUpdateThresholds_RefusesAMalformedEnvelopeWithoutAWrite(array $params): void {
		$this->arcade->expects($this->never())->method('setThresholds');

		$response = $this->controller($params)->updateThresholds();

		$this->assertSame(400, $response->getStatus());
		$this->assertSame('VALIDATION_ERROR', $response->getData()['error']['code']);
	}

	public static function malformedThresholdEnvelopes(): array {
		return [
			'no thresholds' => [[]],
			'a string' => [['thresholds' => 'socks=1']],
			'a list' => [['thresholds' => [0, 3, 10, 25, 50]]],
		];
	}

	public function testUpdateThresholds_MapsARefusedValueTo400WithItsMessage(): void {
		$this->arcade->method('setThresholds')
			->willThrowException(new ValidationException('Each game needs a threshold from 0 to %1$s finished chores.'));

		$response = $this->controller(['thresholds' => ['socks' => 900]])->updateThresholds();

		$this->assertSame(400, $response->getStatus());
		$this->assertSame('Each game needs a threshold from 0 to %1$s finished chores.', $response->getData()['error']['message']);
	}

	public function testANonOwnerGets403OnEveryRoute(): void {
		$this->auth->method('requireHouseOwner')->willThrowException(new ForbiddenException('Owner role required'));
		$this->arcade->expects($this->never())->method('setThresholds');
		$this->arcade->expects($this->never())->method('resetMember');
		$this->arcade->expects($this->never())->method('getProgress');

		$this->assertSame(403, $this->controller(['thresholds' => 'junk'])->updateThresholds()->getStatus());
		$this->assertSame(403, $this->controller()->resetMember('kid')->getStatus());
		$this->assertSame(403, $this->controller()->progress()->getStatus());
	}

	public function testANonMemberGets403BeforeTheOwnerCheck(): void {
		$this->auth->method('assertHouseMember')->willThrowException(new ForbiddenException('Not a member of this house'));
		$this->auth->expects($this->never())->method('requireHouseOwner');

		$this->assertSame(403, $this->controller()->progress()->getStatus());
	}

	public function testResetMember_ChecksTheTargetAndReturnsTheProgressList(): void {
		$progress = [['userId' => 'kid', 'displayName' => 'Kid', 'finishedChores' => 0, 'resetAt' => '2026-11-14T10:00:00+00:00']];
		$this->auth->expects($this->once())->method('assertUserInHouse')->with(3, 'kid');
		$this->arcade->expects($this->once())->method('resetMember')->with(3, 'kid')->willReturn($progress);

		$response = $this->controller()->resetMember('kid');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame($progress, $response->getData()['data']);
	}

	public function testResetMember_ATargetOutsideTheHouseGets403WithoutAReset(): void {
		$this->auth->method('assertUserInHouse')->willThrowException(new ForbiddenException('User is not a member of this house'));
		$this->arcade->expects($this->never())->method('resetMember');

		$this->assertSame(403, $this->controller()->resetMember('stranger')->getStatus());
	}

	public function testProgress_ReturnsTheList(): void {
		$progress = [['userId' => 'kid', 'displayName' => 'Kid', 'finishedChores' => 4, 'resetAt' => null]];
		$this->arcade->expects($this->once())->method('getProgress')->with(3)->willReturn($progress);

		$response = $this->controller()->progress();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame($progress, $response->getData()['data']);
	}

	public function testAnUnexpectedFailureIsALogged500WithoutDetails(): void {
		$this->arcade->method('getProgress')->willThrowException(new \RuntimeException('SQLSTATE secret detail'));

		$response = $this->controller()->progress();

		$this->assertSame(500, $response->getStatus());
		$this->assertSame('ARCADE_SETTINGS_ERROR', $response->getData()['error']['code']);
		$this->assertStringNotContainsString('SQLSTATE', $response->getData()['error']['message']);
	}
}
