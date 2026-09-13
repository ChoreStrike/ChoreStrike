<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\Room;
use OCA\ChoreStrike\Db\RoomMapper;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\RoomTaskChecklistProgress;
use OCA\ChoreStrike\Db\RoomTaskChecklistProgressMapper;
use OCA\ChoreStrike\Db\RoomTaskMapper;

/**
 * Effect-record snapshot/restore owner (CS-PLAN-067 §3.2, decision D2).
 *
 * ADR-011 JSON contract: a completion captures the authoritative
 * before-state (room task recurrence/claim fields, room cleanliness,
 * completer reward fields, checklist progress) and every undo restores
 * exactly that record ("restore, never recalculate").
 *
 * Runs INSIDE the caller's transaction ({@see CompletionService} owns the
 * boundary and lock order); this service never begins, commits or rolls
 * back a transaction and shares the caller's connection.
 */
class CompletionEffectService {
	private RoomTaskMapper $roomTaskMapper;
	private RoomMapper $roomMapper;
	private HouseUserMapper $houseUserMapper;
	private RoomTaskChecklistProgressMapper $checklistProgressMapper;

	public function __construct(
		RoomTaskMapper $roomTaskMapper,
		RoomMapper $roomMapper,
		HouseUserMapper $houseUserMapper,
		RoomTaskChecklistProgressMapper $checklistProgressMapper,
	) {
		$this->roomTaskMapper = $roomTaskMapper;
		$this->roomMapper = $roomMapper;
		$this->houseUserMapper = $houseUserMapper;
		$this->checklistProgressMapper = $checklistProgressMapper;
	}

	/**
	 * Snapshot the authoritative before-state (decision D1 / ADR-011 JSON
	 * contract): room task fields, room cleanliness, completer reward
	 * fields, and the room task's checklist progress rows.
	 *
	 * @return array<string, mixed>
	 */
	public function snapshot(RoomTask $roomTask, Room $room, HouseUser $completer): array {
		$progress = [];
		foreach ($this->checklistProgressMapper->findByRoomTaskId($roomTask->getId()) as $row) {
			/** @var RoomTaskChecklistProgress $row */
			$progress[] = [
				'checklistItemId' => $row->getChecklistItemId(),
				'userId' => $row->getCheckedByUserId(),
				'checked' => $row->getIsChecked(),
				'checkedAt' => $row->getCheckedAt()?->format('c'),
			];
		}

		return [
			'roomTask' => [
				'nextDue' => $roomTask->getNextDue()?->format('c'),
				'idealDueDate' => $roomTask->getIdealDueDate()?->format('c'),
				'isActive' => $roomTask->getIsActive(),
				'lastCompleted' => $roomTask->getLastCompleted()?->format('c'),
				'wasClaimed' => $roomTask->getWasClaimed(),
				'originalAssignee' => $roomTask->getOriginalAssignee(),
				'claimedByUser' => $roomTask->getClaimedByUser(),
				'claimedAt' => $roomTask->getClaimedAt()?->format('c'),
			],
			'room' => [
				'cleanliness' => $room->getCleanliness(),
				'lastCleaned' => $room->getLastCleaned()?->format('c'),
			],
			'houseUser' => [
				'coins' => $completer->getCoins(),
				'contributionScore' => $completer->getContributionScore(),
				'currentStreak' => $completer->getCurrentStreak(),
				'longestStreak' => $completer->getLongestStreak(),
				'streakFreezes' => $completer->getStreakFreezes(),
				'lastFreezeDate' => $completer->getLastFreezeDate()?->format('c'),
				'unlockedTitles' => $completer->getUnlockedTitles(),
				'lastActivity' => $completer->getLastActivity()?->format('c'),
			],
			'checklist' => $progress,
		];
	}

	/**
	 * Restore the locked entities from a recorded effect (undo phase).
	 *
	 * Assigns every recorded before-state value, removes exactly the
	 * `deltas.titlesAdded` ids STILL present from the current title set
	 * (later unlocks survive, LIFO/D8), rewrites the checklist progress
	 * from the snapshot, and persists room task, room, completer.
	 *
	 * @param array<string, mixed> $beforeState
	 * @param array<string, mixed> $deltas
	 */
	public function restore(RoomTask $roomTask, Room $room, HouseUser $completer, array $beforeState, array $deltas): void {
		$beforeRoomTask = $beforeState['roomTask'];
		$roomTask->setNextDue($this->dateOrNull($beforeRoomTask['nextDue'] ?? null));
		$roomTask->setIdealDueDate($this->dateOrNull($beforeRoomTask['idealDueDate'] ?? null));
		$roomTask->setIsActive((bool)($beforeRoomTask['isActive'] ?? true));
		$roomTask->setLastCompleted($this->dateOrNull($beforeRoomTask['lastCompleted'] ?? null));
		$roomTask->setWasClaimed((bool)($beforeRoomTask['wasClaimed'] ?? false));
		$roomTask->setOriginalAssignee($beforeRoomTask['originalAssignee'] ?? null);
		$roomTask->setClaimedByUser($beforeRoomTask['claimedByUser'] ?? null);
		$roomTask->setClaimedAt($this->dateOrNull($beforeRoomTask['claimedAt'] ?? null));
		$this->roomTaskMapper->update($roomTask);

		$beforeRoom = $beforeState['room'];
		$room->setCleanliness((int)($beforeRoom['cleanliness'] ?? $room->getCleanliness()));
		$room->setLastCleaned($this->dateOrNull($beforeRoom['lastCleaned'] ?? null));
		$this->roomMapper->update($room);

		$beforeUser = $beforeState['houseUser'];
		$completer->setCoins((int)($beforeUser['coins'] ?? $completer->getCoins()));
		$completer->setContributionScore((int)($beforeUser['contributionScore'] ?? $completer->getContributionScore()));
		$completer->setCurrentStreak((int)($beforeUser['currentStreak'] ?? $completer->getCurrentStreak()));
		$completer->setLongestStreak((int)($beforeUser['longestStreak'] ?? $completer->getLongestStreak()));
		$completer->setStreakFreezes((int)($beforeUser['streakFreezes'] ?? $completer->getStreakFreezes()));
		$completer->setLastFreezeDate($this->dateOrNull($beforeUser['lastFreezeDate'] ?? null));
		// lastActivity drives the streak-absence calculation, so the
		// restore must rewind it too — a stale completion timestamp would
		// otherwise bridge the undone occurrence's gap (exact state
		// equality, WP5 exit gate).
		$completer->setLastActivity($this->dateOrNull($beforeUser['lastActivity'] ?? null));

		// Titles: remove exactly the deltas.titlesAdded ids STILL
		// present — titles unlocked by later completions survive (D8/LIFO).
		$tiersAdded = $deltas['titlesAdded'] ?? [];
		if (is_array($tiersAdded) && $tiersAdded !== []) {
			$completer->setUnlockedTitles(array_values(array_filter(
				$completer->getUnlockedTitles(),
				static fn (string $tierId): bool => !in_array($tierId, $tiersAdded, true)
			)));
		}
		$this->houseUserMapper->update($completer);

		// Checklist restore: clear whatever accumulated since, then
		// re-insert the snapshotted rows.
		$this->checklistProgressMapper->deleteByRoomTaskId($roomTask->getId());
		$this->checklistProgressMapper->restoreProgress(
			$roomTask->getId(),
			is_array($beforeState['checklist'] ?? null) ? $beforeState['checklist'] : []
		);
	}

	/**
	 * Parse a recorded ISO datetime string (nullable) back into a DateTime.
	 */
	private function dateOrNull(?string $value): ?\DateTime {
		if ($value === null || $value === '') {
			return null;
		}

		return new \DateTime($value);
	}
}
