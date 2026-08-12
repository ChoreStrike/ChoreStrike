<?php

namespace OCP\Notification;

/**
 * Mock interface for a notification value object.
 *
 * Mirrors the public surface of OCP\Notification\INotification as used by
 * ChoreStrike's NotificationService and ChoreStrikeNotifier. PHPUnit's
 * mock builder auto-implements these methods.
 */
interface INotification {
	/**
	 * @return $this
	 */
	public function setApp(string $app);
	public function getApp(): string;

	/**
	 * @return $this
	 */
	public function setUser(string $user);
	public function getUser(): string;

	/**
	 * @return $this
	 */
	public function setDateTime(\DateTime $dateTime);
	public function getDateTime(): \DateTime;

	/**
	 * @return $this
	 */
	public function setObject(string $type, string $id);
	public function getObjectType(): string;
	public function getObjectId(): string;

	/**
	 * @param array<string, mixed> $parameters
	 * @return $this
	 */
	public function setSubject(string $subject, array $parameters = []);
	public function getSubject(): string;

	/**
	 * @return array<string, mixed>
	 */
	public function getSubjectParameters(): array;

	/**
	 * @param array<string, mixed> $parameters
	 * @return $this
	 */
	public function setMessage(string $message, array $parameters = []);
	public function getMessage(): string;

	/**
	 * @return array<string, mixed>
	 */
	public function getMessageParameters(): array;

	/**
	 * @param array<string, mixed> $parameters
	 * @return $this
	 */
	public function setDescription(string $description, array $parameters = []);
	public function getDescription(): string;

	/**
	 * @return array<string, mixed>
	 */
	public function getDescriptionParameters(): array;
	public function getParsedDescription(): string;

	/**
	 * @return $this
	 */
	public function setLink(string $link);
	public function getLink(): string;

	/**
	 * @return $this
	 */
	public function setIcon(string $icon);
	public function getIcon(): string;

	/**
	 * @return $this
	 */
	public function setParsedSubject(string $subject);
	public function getParsedSubject(): string;

	/**
	 * @param array<string, mixed> $parameters
	 * @return $this
	 */
	public function setRichSubject(string $subject, array $parameters = []);
	public function getRichSubject(): string;

	/**
	 * @return array<string, mixed>
	 */
	public function getRichSubjectParameters(): array;

	/**
	 * @return $this
	 */
	public function setParsedMessage(string $message);
	public function getParsedMessage(): string;

	/**
	 * @return $this
	 */
	public function addAction(IAction $action);

	/**
	 * @return array<IAction>
	 */
	public function getActions(): array;

	/**
	 * @return array<IAction>
	 */
	public function getParsedActions(): array;

	public function isValid(): bool;
	public function isValidParsed(): bool;
}
