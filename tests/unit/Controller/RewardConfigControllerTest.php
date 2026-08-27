<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Controller;

use OCA\ChoreStrike\Controller\RewardConfigController;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\RewardConfigService;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for RewardConfigController
 *
 * The controller is a thin delegate to RewardConfigService. The security
 * boundary is the @AdminRequired annotation on update() (enforced by
 * Nextcloud middleware before the controller is reached). These tests
 * cover the delegation, response shaping, and exception propagation.
 */
class RewardConfigControllerTest extends TestCase {
	private RewardConfigController $controller;
	private RewardConfigService $configService;
	private IRequest $request;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->configService = $this->createMock(RewardConfigService::class);

		$this->controller = new RewardConfigController(
			'chorestrike',
			$this->request,
			$this->configService,
			$this->createMock(AppLogger::class)
		);
	}

	public function testIndex_ReturnsConfigFromService(): void {
		$config = [
			'tierBronzeMultiplier' => 1.0,
			'tierSilverMultiplier' => 1.5,
			'tierGoldMultiplier' => 2.0,
			'coinBasePerEnergy' => 10,
		];

		$this->configService->expects($this->once())
			->method('getAllConfig')
			->willReturn($config);

		$response = $this->controller->index();

		$this->assertInstanceOf(DataResponse::class, $response);
		$this->assertEquals($config, $response->getData());
	}

	public function testUpdate_CallsUpdateConfigAndReturnsUpdatedConfig(): void {
		$values = ['coin_base_per_energy' => 15];
		$updatedConfig = ['coinBasePerEnergy' => 15];

		$this->configService->expects($this->once())
			->method('updateConfig')
			->with($values);

		$this->configService->expects($this->once())
			->method('getAllConfig')
			->willReturn($updatedConfig);

		$response = $this->controller->update($values);

		$this->assertInstanceOf(DataResponse::class, $response);
		$this->assertEquals($updatedConfig, $response->getData());
	}

	public function testUpdate_EmptyValues_StillCallsService(): void {
		$this->configService->expects($this->once())
			->method('updateConfig')
			->with([]);

		$this->configService->expects($this->once())
			->method('getAllConfig')
			->willReturn([]);

		$response = $this->controller->update([]);

		$this->assertInstanceOf(DataResponse::class, $response);
	}

	public function testIndex_ServiceThrows_ExceptionPropagates(): void {
		$this->configService->expects($this->once())
			->method('getAllConfig')
			->willThrowException(new \RuntimeException('Config backend unavailable'));

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Config backend unavailable');

		$this->controller->index();
	}

	public function testUpdate_ServiceThrowsOnUpdate_ExceptionPropagates(): void {
		$this->configService->expects($this->once())
			->method('updateConfig')
			->willThrowException(new \InvalidArgumentException('Unknown config key'));

		$this->expectException(\InvalidArgumentException::class);

		$this->controller->update(['bad_key' => 1]);
	}
}
