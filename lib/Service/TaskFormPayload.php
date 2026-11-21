<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCP\IL10N;

/**
 * What the one form sends besides its receipt (CS-PLAN-091 WP-B6): the
 * definition, the room, the assignees, the sub-tasks, the first date and
 * the sleep switch ({@see SleepSwitch}). One boundary validation for the
 * creation aggregate (`POST /room-tasks`) and the edit aggregate
 * (`PUT /room-tasks/{id}`); the definition's values are checked by
 * {@see TaskService}.
 */
final class TaskFormPayload {
	/** The fields of the form, receipt and scope excluded. */
	public const FIELDS = ['task', 'roomId', 'assigneeUserIds', 'checklistItems', 'firstDate', 'sleeping', 'wakeOn'];

	/** How far the first date may lie from today, either way (D-091-45). */
	private const FIRST_DATE_RANGE_DAYS = 366;

	/**
	 * @param array<string, mixed>|null $definition Null: the definition stays as it is
	 * @param string[]|null $assigneeUserIds Null: the assignees stay as they are
	 * @param bool|null $sleeping Null: the sleep state stays as it is
	 */
	private function __construct(
		public readonly ?array $definition,
		public readonly ?int $roomId,
		public readonly ?array $assigneeUserIds,
		public readonly ?SubTaskList $subTasks,
		public readonly ?string $firstDate,
		public readonly ?bool $sleeping,
		public readonly ?string $wakeOn,
	) {
	}

	/**
	 * @param array<string, mixed> $payload The body without its receipt fields
	 * @throws \InvalidArgumentException with a translated message (400)
	 */
	public static function fromPayload(array $payload, CalendarService $calendar, IL10N $l10n): self {
		$definition = $payload['task'] ?? null;
		if ($definition !== null && (!is_array($definition) || ($definition !== [] && array_is_list($definition)))) {
			throw new \InvalidArgumentException($l10n->t('task must be an object'));
		}
		$roomId = $payload['roomId'] ?? null;
		if ($roomId !== null && (!is_int($roomId) || $roomId < 1)) {
			throw new \InvalidArgumentException($l10n->t('Invalid value type for field: %1$s', ['roomId']));
		}
		$switch = SleepSwitch::fromPayload($payload, $calendar, $l10n, false);
		return new self(
			$definition,
			$roomId,
			self::assignees($payload['assigneeUserIds'] ?? null, $l10n),
			array_key_exists('checklistItems', $payload) && $payload['checklistItems'] !== null
				? SubTaskList::fromPayload($payload['checklistItems'], $l10n)
				: null,
			self::firstDate($payload['firstDate'] ?? null, $calendar, $l10n),
			$switch->sleeping,
			$switch->wakeOn,
		);
	}

	/**
	 * A chosen first date lies within a year of today (D-091-45). Checked
	 * where the date is a new choice: at creation, and on an edit only
	 * when it differs from the stored one — a task that was never done
	 * keeps a first date of long ago through every other save.
	 *
	 * @throws \InvalidArgumentException
	 */
	public function assertFirstDateInRange(IL10N $l10n): void {
		if ($this->firstDate === null) {
			return;
		}
		$today = new \DateTime('today', new \DateTimeZone('UTC'));
		$day = \DateTime::createFromFormat('!Y-m-d', $this->firstDate, new \DateTimeZone('UTC'));
		if ((int)$today->diff($day)->days > self::FIRST_DATE_RANGE_DAYS) {
			throw new \InvalidArgumentException($l10n->t('The first date must lie within one year of today'));
		}
	}

	/**
	 * The receipt identity of the payload: what makes two saves the same.
	 *
	 * @return array<string, mixed>
	 */
	public function identity(): array {
		return [
			'task' => $this->definition, 'roomId' => $this->roomId, 'assigneeUserIds' => $this->assigneeUserIds,
			'checklistItems' => $this->subTasks?->entries, 'firstDate' => $this->firstDate,
			'sleeping' => $this->sleeping, 'wakeOn' => $this->wakeOn,
		];
	}

	/**
	 * @return string[]|null Unique, non-empty user ids in the order sent
	 * @throws \InvalidArgumentException
	 */
	private static function assignees(mixed $raw, IL10N $l10n): ?array {
		if ($raw === null) {
			return null;
		}
		if (!is_array($raw) || !array_is_list($raw)) {
			throw new \InvalidArgumentException($l10n->t('assigneeUserIds must be an array of user IDs'));
		}
		foreach ($raw as $userId) {
			if (!is_string($userId) || trim($userId) === '') {
				throw new \InvalidArgumentException($l10n->t('assigneeUserIds must be an array of user IDs'));
			}
		}
		return array_values(array_unique($raw));
	}

	/**
	 * The optional first date (ADR-021 decision 1): a real `Y-m-d` calendar
	 * day, never coerced. It is the centre of the first window; its range
	 * is {@see assertFirstDateInRange()}.
	 *
	 * @throws \InvalidArgumentException
	 */
	private static function firstDate(mixed $value, CalendarService $calendar, IL10N $l10n): ?string {
		if ($value === null || $value === '') {
			return null;
		}
		if (!$calendar->isRealDay($value)) {
			throw new \InvalidArgumentException($l10n->t('Invalid firstDate date format'));
		}
		return $value;
	}
}
