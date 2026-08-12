<?php

/**
 * Mock IRequest interface for testing
 */

namespace OCP;

interface IRequest {
	public function getParams(): array;

	public function getParam(string $key, $default = null);

	public function getMethod(): string;

	public function getId(): string;

	public function getHeader(string $name): string;

	public function getRemoteAddress(): string;

	public function isUserAgent(array $userAgents): bool;
}
