<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Support;

use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Service\CalendarService;
use OCA\ChoreStrike\Service\RoomTaskEditService;
use OCA\ChoreStrike\Service\TaskEdit;

/**
 * Saves the one form on an existing task (CS-PLAN-091 WP-B6), the only
 * path that edits a definition, its sub-tasks or the assignees of a room
 * task: the tests send the fields they change, with the version the room
 * task has now and a fresh command id.
 */
trait TaskEditing {
	/**
	 * @param array<string, mixed> $fields Form fields: `task`, `roomId`, `assigneeUserIds`, `checklistItems`, `firstDate`, `sleeping`, `wakeOn`, `scope`
	 * @return array<string, mixed> The answer of {@see RoomTaskEditService::edit()}
	 */
	protected function saveForm(int $houseId, HouseUser $actor, int $roomTaskId, array $fields): array {
		return \OC::$server->get(RoomTaskEditService::class)->edit($houseId, $actor, $roomTaskId, $this->formEdit($roomTaskId, $fields));
	}

	/**
	 * The validated body of a save, with the room task's current version
	 * and a fresh command id unless the fields name them.
	 *
	 * @param array<string, mixed> $fields
	 */
	protected function formEdit(int $roomTaskId, array $fields): TaskEdit {
		return TaskEdit::fromPayload([
			'commandId' => $this->formCommandId(),
			'expectedVersion' => \OC::$server->get(RoomTaskMapper::class)->find($roomTaskId)->getCycleVersion(),
			...$fields,
		], \OC::$server->get(CalendarService::class), \OC::$server->getL10N('chorestrike'));
	}

	/**
	 * Edit a definition through one of its active room tasks, for every
	 * room that shares it.
	 *
	 * @param array<string, mixed> $definition
	 */
	protected function editDefinition(int $houseId, HouseUser $actor, int $taskId, array $definition): array {
		$active = array_values(array_filter(
			\OC::$server->get(RoomTaskMapper::class)->findByTaskIdScopedToHouse($taskId, $houseId),
			static fn ($roomTask): bool => (bool)$roomTask->getIsActive(),
		));
		if ($active === []) {
			throw new \LogicException('editDefinition() needs an active room task of the definition');
		}
		return $this->saveForm($houseId, $actor, (int)$active[0]->getId(), ['task' => $definition, 'scope' => TaskEdit::SCOPE_ALL_ROOMS]);
	}

	/** @param string[] $userIds */
	protected function assignTo(int $houseId, HouseUser $actor, int $roomTaskId, array $userIds): array {
		return $this->saveForm($houseId, $actor, $roomTaskId, ['assigneeUserIds' => $userIds]);
	}

	private function formCommandId(): string {
		$bytes = random_bytes(16);
		$bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
		$bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
	}
}
