<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\CompletedTask;
use OCA\ChoreStrike\Db\CompletedTaskMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCP\IL10N;

/**
 * Who may correct a history entry (ADR-021 decision 9, D-091-23).
 *
 * The owner corrects any entry: its date, its people, or deletes it. Every
 * other member changes the date of, or deletes, only the latest valid entry
 * of a task that they recorded themselves. Changing the people is the
 * owner's alone. The commands and every read model that shows the
 * correction actions decide through this one class.
 */
class CompletionAuthority {
	public function __construct(
		private CompletedTaskMapper $completedTaskMapper,
		private IL10N $l10n,
	) {
	}

	/**
	 * Whether the member may re-date or delete the entry.
	 *
	 * @param int|null $latestValidId The latest valid completion of the entry's room task
	 */
	public function canCorrect(HouseUser $member, CompletedTask $completion, ?int $latestValidId): bool {
		if ($completion->getUndoneAt() !== null) {
			return false;
		}
		return $member->isOwner()
			|| ($this->recorded($member, $completion) && (int)$completion->getId() === $latestValidId);
	}

	/** Whether the member may change who is credited for the entry. */
	public function canAttribute(HouseUser $member, CompletedTask $completion): bool {
		return $completion->getUndoneAt() === null && $member->isOwner();
	}

	/**
	 * Gate of the date correction and of the deletion. A deleted entry
	 * stays answerable for the member who recorded it, so a repeated delete
	 * command ends as the no-op it is instead of a refusal.
	 *
	 * @param HouseUser $member The acting membership, read fresh
	 * @throws ForbiddenException
	 */
	public function assertCanCorrect(HouseUser $member, CompletedTask $completion): void {
		if ($member->isOwner()) {
			return;
		}
		$own = $this->recorded($member, $completion);
		if ($own && $completion->getUndoneAt() !== null) {
			return;
		}
		$roomTaskId = (int)$completion->getRoomTaskId();
		$latest = $this->completedTaskMapper->findLatestValidIdsByRoomTasks([$roomTaskId])[$roomTaskId] ?? null;
		if (!$own || (int)$completion->getId() !== $latest) {
			throw new ForbiddenException($this->l10n->t('You can only change or delete your own latest entry of a task'));
		}
	}

	/**
	 * The correction right without its state part: the owner, or the member
	 * who recorded the entry. Whether the entry is still the task's latest
	 * is decided inside the command, so a replay of a committed command
	 * never re-tests it (CommandService guard contract).
	 *
	 * @param HouseUser $member The acting membership, read fresh
	 * @throws ForbiddenException
	 */
	public function assertMayCorrectEntry(HouseUser $member, CompletedTask $completion): void {
		if (!$member->isOwner() && !$this->recorded($member, $completion)) {
			throw new ForbiddenException($this->l10n->t('You can only change or delete your own latest entry of a task'));
		}
	}

	/**
	 * @param HouseUser $member The acting membership, read fresh
	 * @throws ForbiddenException
	 */
	public function assertCanAttribute(HouseUser $member): void {
		if (!$member->isOwner()) {
			throw new ForbiddenException($this->l10n->t('Only a house owner can change who did the work'));
		}
	}

	/** An entry one recorded: its audit actor is the member (an erased actor is nobody's). */
	private function recorded(HouseUser $member, CompletedTask $completion): bool {
		return $completion->getUserId() !== null && (int)$completion->getUserId() === (int)$member->getId();
	}
}
