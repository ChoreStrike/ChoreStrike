<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCP\IL10N;

/**
 * One save of the one form on an existing task (`PUT /room-tasks/{id}`,
 * CS-PLAN-091 WP-B6): its receipt (command id and the cycle version the
 * form was opened with), the form's fields and, for a definition several
 * rooms share, the scope of the change.
 */
final class TaskEdit {
	/** The edit splits off a copy of the definition for this room task. */
	public const SCOPE_THIS_ROOM = 'thisRoom';
	/** The edit changes the definition every room shares. */
	public const SCOPE_ALL_ROOMS = 'allRooms';

	private function __construct(
		public readonly string $commandId,
		public readonly int $expectedVersion,
		public readonly TaskFormPayload $form,
		public readonly ?string $scope,
	) {
	}

	/**
	 * Validate the closed body of a save.
	 *
	 * @param array<string, mixed> $payload The request body without framework keys
	 * @throws \InvalidArgumentException with a translated message (400)
	 */
	public static function fromPayload(array $payload, CalendarService $calendar, IL10N $l10n): self {
		$receipt = CycleReceipt::fromPayload($payload, $l10n, CycleReceipt::KEY_NONE, [...TaskFormPayload::FIELDS, 'scope']);
		$scope = $payload['scope'] ?? null;
		if ($scope !== null && !in_array($scope, [self::SCOPE_THIS_ROOM, self::SCOPE_ALL_ROOMS], true)) {
			throw new \InvalidArgumentException($l10n->t('Invalid value type for field: %1$s', ['scope']));
		}
		$form = TaskFormPayload::fromPayload(array_intersect_key($payload, array_flip(TaskFormPayload::FIELDS)), $calendar, $l10n);
		return new self($receipt->commandId, $receipt->expectedVersion, $form, $scope);
	}

	/**
	 * What makes two saves the same command.
	 *
	 * @return array<string, mixed>
	 */
	public function identity(): array {
		return ['expectedVersion' => $this->expectedVersion, 'scope' => $this->scope, ...$this->form->identity()];
	}
}
