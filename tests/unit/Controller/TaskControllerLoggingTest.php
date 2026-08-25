<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Controller;

use OCA\ChoreStrike\Controller\TaskController;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\RoomTaskService;
use OCA\ChoreStrike\Service\TaskService;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Logging-focused tests for TaskController.
 */
class TaskControllerLoggingTest extends TestCase {
	/**
	 * Index_ServiceFailure_DelegatesToAppLogger.
	 */
	public function testIndex_ServiceFailure_DelegatesToAppLogger(): void {
		$request = $this->createMock(IRequest::class);
		$taskService = $this->createMock(TaskService::class);
		$roomTaskService = $this->createMock(RoomTaskService::class);
		$houseService = $this->createMock(HouseService::class);
		$authService = $this->createMock(AuthorizationService::class);
		$appLogger = $this->createMock(AppLogger::class);
		// Identity mock: message assertions match the wrapped source text.
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text) => $text);

		$exception = new \RuntimeException('Database failure');

		$houseService->expects($this->once())
			->method('getInstanceId')
			->willThrowException($exception);

		$appLogger->expects($this->once())
			->method('logException')
			->with(
				'Controller action failed',
				$exception,
				$this->callback(function (array $context): bool {
					return $context['controller'] === TaskController::class
						&& $context['action'] === 'index';
				}),
				$request
			);

		$controller = new TaskController(
			$request,
			$taskService,
			$roomTaskService,
			$houseService,
			$authService,
			$l10n,
			$appLogger
		);

		$response = $controller->index();

		$this->assertSame(500, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
	}
}
