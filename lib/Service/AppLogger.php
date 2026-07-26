<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCP\IRequest;
use OCP\IUserSession;
use OCP\Log\ILogFactory;
use Psr\Log\LoggerInterface;

/**
 * Application-level structured logger for privacy-safe diagnostics.
 */
class AppLogger
{
    /**
     * @var string[]
     */
    private const SENSITIVE_KEY_FRAGMENTS = [
        'password',
        'token',
        'secret',
        'authorization',
        'cookie',
        'session',
        'credential',
        'csrf',
    ];

    private LoggerInterface $logger;

    public function __construct(
        ILogFactory $logFactory,
        private IUserSession $userSession
    ) {
        $this->logger = $logFactory->getCustomPsrLogger('');
    }

    /**
     * Write a debug log entry.
     *
     * @param string $message Log message
     * @param array<string, mixed> $context Structured context
     */
    public function debug(string $message, array $context = [], ?IRequest $request = null): void
    {
        $this->logger->debug($message, $this->buildContext($context, $request));
    }

    /**
     * Write an info log entry.
     *
     * @param string $message Log message
     * @param array<string, mixed> $context Structured context
     */
    public function info(string $message, array $context = [], ?IRequest $request = null): void
    {
        $this->logger->info($message, $this->buildContext($context, $request));
    }

    /**
     * Write a warning log entry.
     *
     * @param string $message Log message
     * @param array<string, mixed> $context Structured context
     */
    public function warning(string $message, array $context = [], ?IRequest $request = null): void
    {
        $this->logger->warning($message, $this->buildContext($context, $request));
    }

    /**
     * Write an error log entry.
     *
     * @param string $message Log message
     * @param array<string, mixed> $context Structured context
     */
    public function error(string $message, array $context = [], ?IRequest $request = null): void
    {
        $this->logger->error($message, $this->buildContext($context, $request));
    }

    /**
     * Write a critical log entry.
     *
     * @param string $message Log message
     * @param array<string, mixed> $context Structured context
     */
    public function critical(string $message, array $context = [], ?IRequest $request = null): void
    {
        $this->logger->critical($message, $this->buildContext($context, $request));
    }

    /**
     * Log an exception with standardized exception metadata.
     *
     * @param string $message Log message
     * @param \Throwable $exception Exception to log
     * @param array<string, mixed> $context Structured context
     */
    public function logException(string $message, \Throwable $exception, array $context = [], ?IRequest $request = null): void
    {
        $exceptionContext = [
            'exception' => get_class($exception),
            'exceptionMessage' => $exception->getMessage(),
            'exceptionCode' => $exception->getCode(),
        ];

        $this->logger->error(
            $message,
            $this->buildContext(array_merge($context, $exceptionContext), $request)
        );
    }

    /**
     * Log a security-relevant event.
     *
     * @param string $message Log message
     * @param array<string, mixed> $context Structured context
     */
    public function securityWarning(string $message, array $context = [], ?IRequest $request = null): void
    {
        $securityContext = array_merge(['event' => 'security'], $context);
        $this->logger->warning($message, $this->buildContext($securityContext, $request));
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function buildContext(array $context, ?IRequest $request): array
    {
        $requestContext = $request === null ? [] : [
            'requestId' => $request->getId(),
            'requestMethod' => $request->getMethod(),
        ];

        $userContext = [
            'currentUserId' => $this->userSession->getUser()?->getUID(),
        ];

        return $this->sanitizeContext(array_merge($context, $requestContext, $userContext));
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function sanitizeContext(array $context): array
    {
        $sanitized = [];

        foreach ($context as $key => $value) {
            if ($this->isSensitiveKey((string)$key)) {
                $sanitized[$key] = '[redacted]';
                continue;
            }

            if (is_array($value)) {
                $sanitized[$key] = $this->sanitizeContext($value);
                continue;
            }

            if (is_object($value)) {
                $sanitized[$key] = sprintf('[object:%s]', get_class($value));
                continue;
            }

            $sanitized[$key] = $value;
        }

        return $sanitized;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalizedKey = strtolower($key);

        foreach (self::SENSITIVE_KEY_FRAGMENTS as $fragment) {
            if (str_contains($normalizedKey, $fragment)) {
                return true;
            }
        }

        return false;
    }
}
