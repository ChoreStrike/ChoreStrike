<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\CompletedTask;
use OCA\ChoreStrike\Db\CompletedTaskMapper;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\RoomMapper;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Exception\NotFoundException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;

/**
 * House activity feed ("who did what when") — CS-PLAN-040 / FEAT-UX-016.
 *
 * Single data source for the Insights history list AND per-member
 * completed-energy aggregation. Enrichment is batch-mapped via the
 * CS-PLAN-036 IN-map idioms (no N+1) and null-safe: room deletion cleans
 * completions but single-task deletion does not and there is no FK cascade,
 * so missing task/room references are returned as null and rendered as a
 * fallback label by the UI instead of erroring.
 */
class ActivityService {
	public const DEFAULT_DAYS = 30;
	public const MAX_DAYS = 90;
	public const DEFAULT_LIMIT = 50;
	public const MAX_LIMIT = 200;

	private CompletedTaskMapper $completedTaskMapper;
	private RoomTaskMapper $roomTaskMapper;
	private TaskMapper $taskMapper;
	private RoomMapper $roomMapper;
	private HouseUserMapper $houseUserMapper;
	private IL10N $l10n;

	public function __construct(
		CompletedTaskMapper $completedTaskMapper,
		RoomTaskMapper $roomTaskMapper,
		TaskMapper $taskMapper,
		RoomMapper $roomMapper,
		HouseUserMapper $houseUserMapper,
		IL10N $l10n,
	) {
		$this->completedTaskMapper = $completedTaskMapper;
		$this->roomTaskMapper = $roomTaskMapper;
		$this->taskMapper = $taskMapper;
		$this->roomMapper = $roomMapper;
		$this->houseUserMapper = $houseUserMapper;
		$this->l10n = $l10n;
	}

	/**
	 * Get the house's completion activity for a period.
	 *
	 * @param int $houseId The house ID
	 * @param int $days Period length in days, clamped to [1, 90] (default 30)
	 * @param int $limit Maximum completions, clamped to [1, 200] (default 50)
	 * @param string|null $targetUserId Optional Nextcloud-user filter; must be
	 *                                  a member of the house (validated here against the house scope)
	 * @return array{completions: array<int, array<string, mixed>>, total: int}
	 */
	public function getActivity(int $houseId, int $days = self::DEFAULT_DAYS, int $limit = self::DEFAULT_LIMIT, ?string $targetUserId = null): array {
		$days = max(1, min($days, self::MAX_DAYS));
		$limit = max(1, min($limit, self::MAX_LIMIT));

		$end = new \DateTimeImmutable();
		$start = $end->modify("-{$days} days");

		$houseUserId = null;
		if ($targetUserId !== null && trim($targetUserId) !== '') {
			try {
				$member = $this->houseUserMapper->findByNextcloudUserId($houseId, $targetUserId);
			} catch (DoesNotExistException $e) {
				throw new NotFoundException($this->l10n->t('User is not a member of this house'));
			}
			$houseUserId = $member->getId();
		}

		$completions = $this->completedTaskMapper->findActivityBetweenByHouse($houseId, $start, $end, $limit, $houseUserId);
		$total = $this->completedTaskMapper->countActivityByHouse($houseId, $start, $end, $houseUserId);

		return [
			'completions' => $this->enrich($houseId, $completions),
			'total' => $total,
		];
	}

	/**
	 * Batch-enrich completions with actor names and task/room details.
	 *
	 * One IN-map fetch per referenced table (CS-PLAN-036 idioms); dangling
	 * references simply miss the map and serialize as null.
	 *
	 * @param int $houseId The house ID
	 * @param CompletedTask[] $completions Completions of the house
	 * @return array<int, array<string, mixed>>
	 */
	private function enrich(int $houseId, array $completions): array {
		if ($completions === []) {
			return [];
		}

		$roomTaskIds = [];
		foreach ($completions as $completion) {
			$roomTaskIds[$completion->getRoomTaskId()] = true;
		}

		// findByIdsScopedToHouse is skip-on-missing — dangling room task ids
		// are absent from the map and their completions fall back to nulls.
		$roomTasksById = [];
		foreach ($this->roomTaskMapper->findByIdsScopedToHouse(array_keys($roomTaskIds), $houseId) as $roomTask) {
			$roomTasksById[$roomTask->getId()] = $roomTask;
		}

		$taskIds = [];
		$roomIds = [];
		foreach ($roomTasksById as $roomTask) {
			$taskIds[$roomTask->getTaskId()] = true;
			$roomIds[$roomTask->getRoomId()] = true;
		}
		$tasksById = $this->taskMapper->findByIds(array_keys($taskIds));
		$roomsById = $this->roomMapper->findByIds(array_keys($roomIds));

		// Houses are small — one membership fetch covers every completer.
		$membersById = [];
		foreach ($this->houseUserMapper->findByHouseId($houseId) as $member) {
			$membersById[$member->getId()] = $member;
		}

		$items = [];
		foreach ($completions as $completion) {
			$items[] = $this->serializeCompletion($completion, $roomTasksById, $tasksById, $roomsById, $membersById);
		}

		return $items;
	}

	/**
	 * Project one completion into its API item shape.
	 *
	 * @return array<string, mixed>
	 */
	private function serializeCompletion(
		CompletedTask $completion,
		array $roomTasksById,
		array $tasksById,
		array $roomsById,
		array $membersById,
	): array {
		$roomTask = $roomTasksById[$completion->getRoomTaskId()] ?? null;
		$task = $roomTask !== null ? ($tasksById[$roomTask->getTaskId()] ?? null) : null;
		$room = $roomTask !== null ? ($roomsById[$roomTask->getRoomId()] ?? null) : null;
		$member = $membersById[$completion->getUserId()] ?? null;

		return [
			'id' => $completion->getId(),
			'completedAt' => $completion->getCompletedAt()?->format('c'),
			'wasCombo' => $completion->getWasCombo(),
			// Reward fields from CompletedTask (sync bookkeeping excluded).
			'coinsEarned' => $completion->getCoinsEarned(),
			'comboWithUserId' => $completion->getComboWithUserId(),
			'isTeamCompletion' => $completion->getIsTeamCompletion(),
			'participantCount' => $completion->getParticipantCount(),
			'teamSparkBonus' => $completion->getTeamSparkBonus(),
			'wasClaimedCompletion' => $completion->getWasClaimedCompletion(),
			'claimBonusSparks' => $completion->getClaimBonusSparks(),
			'houseUserId' => $completion->getUserId(),
			'displayName' => $member?->getDisplayName(),
			'taskId' => $task?->getId(),
			'taskTitle' => $task?->getName(),
			'energyCost' => $task?->getEnergyCost(),
			'roomId' => $room?->getId(),
			'roomName' => $room?->getName(),
		];
	}
}
