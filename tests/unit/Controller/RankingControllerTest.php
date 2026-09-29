<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Controller;

use OCA\ChoreStrike\Controller\RankingController;
use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\RankingService;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for RankingController (CS-PLAN-070 WP4): thin endpoint —
 * membership gates run here, the ranking authority is RankingService.
 */
class RankingControllerTest extends TestCase {
	private RankingController $controller;
	private $rankingService;
	private $houseService;
	private $authService;

	protected function setUp(): void {
		parent::setUp();
		$this->rankingService = $this->createMock(RankingService::class);
		$this->houseService = $this->createMock(HouseService::class);
		$this->authService = $this->createMock(AuthorizationService::class);

		$this->controller = new RankingController(
			$this->createMock(IRequest::class),
			$this->rankingService,
			$this->houseService,
			$this->authService,
			$this->createIdentityL10n(),
			$this->createMock(\OCA\ChoreStrike\Service\AppLogger::class)
		);
	}

	private function createIdentityL10n(): \OCP\IL10N {
		$l10n = $this->createMock(\OCP\IL10N::class);
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

	public function testIndex_ReturnsTheConsentFilteredView(): void {
		$viewer = $this->member(11);
		$view = [
			'rankingEnabled' => true,
			'week' => ['weekKey' => '2026-09-21', 'weekStart' => '', 'weekEnd' => '', 'provisional' => true],
			'me' => ['state' => 'effective', 'generation' => 2, 'effectiveMonday' => '2026-09-14'],
			'entries' => [['houseUserId' => 11, 'displayName' => 'Alice', 'eligible' => 3, 'onTime' => 2, 'neutral' => false, 'rank' => 1, 'tied' => false, 'isMe' => true]],
			'pending' => [],
		];

		$this->houseService->method('getInstanceId')->willReturn('instance-1');
		$this->houseService->method('getOrCreateHouse')->willReturn($this->house());
		$this->authService->method('assertHouseMember')->willReturn($viewer);
		$this->rankingService->expects($this->once())
			->method('getRankingView')
			->with(1, $viewer)
			->willReturn($view);

		$response = $this->controller->index();

		$this->assertEquals(200, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertSame($view, $data['data']);
	}

	public function testConsent_DelegatesTheSelfOnlyMutation(): void {
		$viewer = $this->member(11);
		$this->houseService->method('getInstanceId')->willReturn('instance-1');
		$this->houseService->method('getOrCreateHouse')->willReturn($this->house());
		$this->authService->method('assertHouseMember')->willReturn($viewer);
		$this->rankingService->expects($this->once())
			->method('consent')
			->with(1, $viewer)
			->willReturn(['state' => 'pending', 'generation' => 3, 'effectiveMonday' => '2026-09-28']);

		$response = $this->controller->consent();

		$this->assertEquals(200, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertSame('pending', $data['data']['state']);
	}

	public function testWithdraw_DelegatesTheSelfOnlyMutation(): void {
		$viewer = $this->member(11);
		$this->houseService->method('getInstanceId')->willReturn('instance-1');
		$this->houseService->method('getOrCreateHouse')->willReturn($this->house());
		$this->authService->method('assertHouseMember')->willReturn($viewer);
		$this->rankingService->expects($this->once())
			->method('withdraw')
			->with(1, $viewer);

		$response = $this->controller->withdraw();

		$this->assertEquals(200, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertSame('none', $data['data']['state']);
	}

	public function testIndex_NonMember_IsForbidden(): void {
		$this->houseService->method('getInstanceId')->willReturn('instance-1');
		$this->houseService->method('getOrCreateHouse')->willReturn($this->house());
		$this->authService->method('assertHouseMember')
			->willThrowException(new \OCA\ChoreStrike\Exception\ForbiddenException('Not a member'));

		$response = $this->controller->index();

		$this->assertEquals(403, $response->getStatus());
	}
}
