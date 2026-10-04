<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Service\AppLogger;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Log\ILogFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for AppLogger.
 */
class AppLoggerTest extends TestCase {
	private AppLogger $service;
	private LoggerInterface $logger;
	private ILogFactory $logFactory;
	private IUserSession $userSession;
	private IUser $user;

	protected function setUp(): void {
		parent::setUp();

		$this->logger = $this->createMock(LoggerInterface::class);
		$this->logFactory = $this->createMock(ILogFactory::class);
		$this->logFactory->method('getCustomPsrLogger')->willReturn($this->logger);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->user = $this->createMock(IUser::class);

		$this->user->method('getUID')->willReturn('current-user');
		$this->userSession->method('getUser')->willReturn($this->user);

		$this->service = new AppLogger($this->logFactory, $this->userSession);
	}

	/**
	 * Warning_RedactsSensitiveContextAndAddsRequestMetadata.
	 */
	public function testWarning_RedactsSensitiveContextAndAddsRequestMetadata(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getId')->willReturn('req-123');
		$request->method('getMethod')->willReturn('POST');

		$this->logger->expects($this->once())
			->method('warning')
			->with(
				'Request failed',
				$this->callback(function (array $context): bool {
					return $context['taskId'] === 42
						&& $context['password'] === '[redacted]'
						&& $context['nested']['token'] === '[redacted]'
						&& $context['requestId'] === 'req-123'
						&& $context['requestMethod'] === 'POST'
						&& $context['currentUserId'] === 'current-user';
				})
			);

		$this->service->warning('Request failed', [
			'taskId' => 42,
			'password' => 'secret',
			'nested' => ['token' => 'abc'],
		], $request);
	}

	public function testAudit_AuthenticatedSession_DoesNotAddIdentitySnapshot(): void {
		$this->user->expects($this->never())->method('getUID');
		$context = ['event' => 'house.member.invited', 'houseId' => 3, 'actorId' => 7, 'memberId' => 21, 'outcome' => 'invited'];
		$this->logger->expects($this->once())->method('info')->with('House member invite completed', $context);
		$this->service->audit('House member invite completed', $context);
	}

	/**
	 * LogException_IncludesExceptionMetadata.
	 */
	public function testLogException_IncludesExceptionMetadata(): void {
		$exception = new \RuntimeException('Boom', 12);

		$this->logger->expects($this->once())
			->method('error')
			->with(
				'Controller action failed',
				$this->callback(function (array $context): bool {
					return $context['exception'] === \RuntimeException::class
						&& $context['exceptionMessage'] === 'Boom'
						&& $context['exceptionCode'] === 12
						&& $context['currentUserId'] === 'current-user';
				})
			);

		$this->service->logException('Controller action failed', $exception, ['action' => 'delete']);
	}
}
