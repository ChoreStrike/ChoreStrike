<?php

namespace OCP\Notification;

/**
 * Mock interface for a notification action button.
 */
interface IAction {
	public function setLabel(string $label);
	public function getLabel(): string;
	public function setLink(string $link, bool $primary = false);
	public function getLink(): string;
	public function isPrimary(): bool;
	public function setParsedLabel(string $label);
	public function getParsedLabel(): string;
	public function getRequestType(): string;
	public function setRequestType(string $requestType);
}
