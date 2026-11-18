<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Controller;

use OCA\ChoreStrike\Controller\CoinMultiplierController;
use OCA\ChoreStrike\Db\BonusPeriod;
use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Exception\ConflictException;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Exception\NotFoundException;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\CoinMultiplierService;
use OCA\ChoreStrike\Service\HouseService;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * CS-PLAN-091 WP-B5 (ADR-021 decision 11): the owner's coin multipliers
 * check membership and the owner role before anything else, take a closed
 * body with its command id and map each service outcome.
 */
class CoinMultiplierControllerTest extends TestCase {
	private const UUID = '11111111-2222-4333-8444-555555555555';

	private AuthorizationService&MockObject $auth;
	private CoinMultiplierService&MockObject $multipliers;

	protected function setUp(): void {
		parent::setUp();
		$this->auth = $this->createMock(AuthorizationService::class);
		$this->multipliers = $this->createMock(CoinMultiplierService::class);
	}

	private function controller(array $params = []): CoinMultiplierController {
		$house = new House();
		$house->setId(3);
		$houseService = $this->createMock(HouseService::class);
		$houseService->method('getInstanceId')->willReturn('instance');
		$houseService->method('getOrCreateHouse')->willReturn($house);
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params + ['_route' => 'chorestrike.coin_multiplier']);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []) => vsprintf($text, $params));
		return new CoinMultiplierController($request, $houseService, $this->auth, $this->multipliers, $l10n, $this->createMock(AppLogger::class));
	}

	private function period(int $id = 5): BonusPeriod {
		$period = new BonusPeriod();
		$period->setId($id);
		$period->setStartsOn(new \DateTime('2026-11-20'));
		$period->setEndsOn(new \DateTime('2026-11-21'));
		return $period;
	}

	public function testSetFactor_SavesForAnOwnerAndAnswersTheMember(): void {
		$member = new HouseUser();
		$member->setId(8);
		$member->setNextcloudUserId('kid');
		$member->setCoinFactor(3);
		$this->auth->expects($this->once())->method('assertHouseMember')->with(3);
		$this->auth->expects($this->once())->method('requireHouseOwner')->with(3);
		$this->multipliers->expects($this->once())->method('setFactor')->with(3, 'kid', 3, self::UUID)->willReturn($member);

		// The route parameter is no field of the body; the command id is case-insensitive.
		$response = $this->controller(['userId' => 'kid', 'commandId' => strtoupper(self::UUID), 'factor' => 3])->setFactor('kid');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['kid', 3], [$response->getData()['data']['nextcloudUserId'], $response->getData()['data']['coinFactor']]);
	}

	/** @return array<string, array{array<string, mixed>}> */
	public static function malformedFactors(): array {
		return [
			'no command id' => [['factor' => 2]],
			'no factor' => [['commandId' => self::UUID]],
			'a factor as text' => [['commandId' => self::UUID, 'factor' => '2']],
			'an unknown field' => [['commandId' => self::UUID, 'factor' => 2, 'bonus' => true]],
		];
	}

	/** @dataProvider malformedFactors */
	public function testSetFactor_RefusesAMalformedBodyWithoutAWrite(array $params): void {
		$this->multipliers->expects($this->never())->method('setFactor');

		$response = $this->controller($params)->setFactor('kid');

		$this->assertSame(400, $response->getStatus());
		$this->assertSame('VALIDATION_ERROR', $response->getData()['error']['code']);
	}

	public function testStartPeriod_AnswersThePeriod(): void {
		$this->multipliers->expects($this->once())->method('startPeriod')->with(3, '2026-11-20', 2, self::UUID)->willReturn($this->period());

		$response = $this->controller(['commandId' => self::UUID, 'startsOn' => '2026-11-20', 'days' => 2])->startPeriod();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['bonusPeriod' => ['id' => 5, 'startsOn' => '2026-11-20', 'endsOn' => '2026-11-21']], $response->getData()['data']);
	}

	/** @return array<string, array{array<string, mixed>}> */
	public static function malformedPeriods(): array {
		return [
			'no first day' => [['commandId' => self::UUID, 'days' => 2]],
			'a first day that is no text' => [['commandId' => self::UUID, 'startsOn' => 20261120, 'days' => 2]],
			'days as text' => [['commandId' => self::UUID, 'startsOn' => '2026-11-20', 'days' => '2']],
			'no command id' => [['startsOn' => '2026-11-20', 'days' => 2]],
		];
	}

	/** @dataProvider malformedPeriods */
	public function testStartPeriod_RefusesAMalformedBodyWithoutAWrite(array $params): void {
		$this->multipliers->expects($this->never())->method('startPeriod');

		$response = $this->controller($params)->startPeriod();

		$this->assertSame(400, $response->getStatus());
		$this->assertSame('VALIDATION_ERROR', $response->getData()['error']['code']);
	}

	public function testCancelPeriod_AnswersWhatTheHouseShowsNow(): void {
		$this->multipliers->expects($this->once())->method('cancelPeriod')->with(3, 5, self::UUID)->willReturn($this->period());
		$this->multipliers->expects($this->once())->method('runningOrNext')->willReturn(null);

		// The command id of a DELETE arrives as a query parameter.
		$response = $this->controller(['id' => '5', 'commandId' => self::UUID])->cancelPeriod(5);

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['bonusPeriod' => null], $response->getData()['data']);
	}

	/** @return array<string, array{\Throwable, int, string}> */
	public static function serviceOutcomes(): array {
		return [
			'not the owner' => [new ForbiddenException('Owner role required'), 403, 'FORBIDDEN'],
			'an unknown period' => [new NotFoundException('Bonus days not found'), 404, 'RESOURCE_NOT_FOUND'],
			'an ended period' => [new ConflictException('These bonus days are already over'), 409, 'CONFLICT'],
			'a rule of the service' => [new \InvalidArgumentException('Bonus days last one to three days'), 400, 'VALIDATION_ERROR'],
			'anything unexpected' => [new \RuntimeException('connection lost'), 500, 'COIN_MULTIPLIER_ERROR'],
		];
	}

	/** @dataProvider serviceOutcomes */
	public function testCommands_MapEachServiceOutcome(\Throwable $outcome, int $status, string $code): void {
		$this->multipliers->method('cancelPeriod')->willThrowException($outcome);

		$response = $this->controller(['commandId' => self::UUID])->cancelPeriod(5);

		$this->assertSame($status, $response->getStatus());
		$this->assertSame($code, $response->getData()['error']['code']);
		$this->assertStringNotContainsString('connection lost', $response->getData()['error']['message'], 'no internal detail in an answer');
	}

	public function testCommands_ANonOwnerIsRefusedBeforeTheBodyIsRead(): void {
		$this->auth->method('requireHouseOwner')->willThrowException(new ForbiddenException('Owner role required'));
		$this->multipliers->expects($this->never())->method('startPeriod');

		// A malformed body would be a 400; the role decides first.
		$response = $this->controller(['nonsense' => true])->startPeriod();

		$this->assertSame(403, $response->getStatus());
	}
}
