<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\CompletedTaskMapper;
use OCA\ChoreStrike\Db\Room;
use OCA\ChoreStrike\Db\RoomEvent;
use OCA\ChoreStrike\Db\RoomEventMapper;
use OCA\ChoreStrike\Db\RoomMapper;

/**
 * The ONE room history authority (CS-PLAN-070 WP3 §3.5; CR-SVC-63).
 *
 * Every room mutation journals its REQUESTED effect — never an absolute
 * value, never an observed clamped delta:
 *
 *  - `baseline`: creation/setup seed (recorded BEFORE any room mutation);
 *  - `completion`: the frozen per-completion effect `totalEnergy * 10`,
 *    one event per completion regardless of the participant count — undo
 *    invalidates exactly this effect, attribution never touches it;
 *  - `manual_delta`: a requested cleanliness delta
 *    ({@see RoomService::updateRoomCleanliness});
 *  - `reset`: absolute cleanliness 100, monsters 0, `last_cleaned` NULL.
 *
 * The room cache is rebuilt by folding the VALID events in
 * `(effective_at, id)` order with the saved requested values and clamps:
 * baseline 90 → false +20 (clamps to 100) → undone → later valid +20
 * replays to 100 with the later valid timestamp. A correction's execution
 * time never becomes `last_cleaned` — only completion/manual events stamp
 * it, with their own work instant. A later reset remains authoritative
 * over earlier corrections. Runs inside the caller's transaction; lower
 * service, never calls the completion coordinator back.
 */
class RoomReplayService {
	private RoomEventMapper $eventMapper;
	private CompletedTaskMapper $completedTaskMapper;
	private RoomMapper $roomMapper;

	public function __construct(
		RoomEventMapper $eventMapper,
		CompletedTaskMapper $completedTaskMapper,
		RoomMapper $roomMapper,
	) {
		$this->eventMapper = $eventMapper;
		$this->completedTaskMapper = $completedTaskMapper;
		$this->roomMapper = $roomMapper;
	}

	/**
	 * Record the creation/setup baseline of a room (before any mutation).
	 */
	public function recordBaseline(Room $room): RoomEvent {
		$event = new RoomEvent();
		$event->setRoomId((int)$room->getId());
		$event->setKind(RoomEvent::KIND_BASELINE);
		$event->setEffectiveAt(\DateTime::createFromInterface($room->getCreatedAt()));
		$event->setStableId('baseline');
		$event->setBaselineCleanliness($room->getCleanliness());
		$event->setBaselineMonsters($room->getMonsterCount());
		$event->setCreatedAt(new \DateTime());
		return $this->eventMapper->insert($event);
	}

	/**
	 * Record the total-room effect of one completion (the undo seam
	 * resolves validity through the completion row).
	 */
	public function recordCompletion(int $roomId, int $completionId, int $deltaCleanliness, \DateTime $workInstant): RoomEvent {
		$event = new RoomEvent();
		$event->setRoomId($roomId);
		$event->setKind(RoomEvent::KIND_COMPLETION);
		$event->setEffectiveAt($workInstant);
		$event->setStableId('completion:' . $completionId);
		$event->setSourceCompletionId($completionId);
		$event->setDeltaCleanliness($deltaCleanliness);
		$event->setCreatedAt(new \DateTime());
		return $this->eventMapper->insert($event);
	}

	/**
	 * Record a requested manual cleanliness delta. The caller's house-scoped
	 * command receipt owns idempotency before this writer is reached.
	 */
	public function recordManualDelta(int $roomId, int $requestedDelta, string $commandUuid, \DateTime $at): RoomEvent {
		$event = new RoomEvent();
		$event->setRoomId($roomId);
		$event->setKind(RoomEvent::KIND_MANUAL_DELTA);
		$event->setEffectiveAt($at);
		$event->setStableId('manual:' . $commandUuid);
		$event->setDeltaCleanliness($requestedDelta);
		$event->setCommandUuid($commandUuid);
		$event->setCreatedAt(new \DateTime());
		return $this->eventMapper->insert($event);
	}

	/**
	 * Record an absolute house reset for one room. The caller's receipt
	 * protects the entire house reset, including rooms added later.
	 */
	public function recordReset(int $roomId, string $commandUuid, \DateTime $at): RoomEvent {
		$event = new RoomEvent();
		$event->setRoomId($roomId);
		$event->setKind(RoomEvent::KIND_RESET);
		$event->setEffectiveAt($at);
		$event->setStableId('reset:' . $commandUuid);
		$event->setBaselineCleanliness(100);
		$event->setBaselineMonsters(0);
		$event->setClearsLastCleaned(true);
		$event->setCommandUuid($commandUuid);
		$event->setCreatedAt(new \DateTime());
		return $this->eventMapper->insert($event);
	}

	/**
	 * Fold the valid events of one room into the current state (bounded
	 * cursor batches, replay order `(effective_at, id)`).
	 *
	 * @return array{cleanliness: int, monsterCount: int, lastCleaned: ?\DateTime}
	 */
	public function replay(int $roomId): array {
		$cleanliness = 50;
		$monsters = 0;
		$lastCleaned = null;
		$reset = $this->eventMapper->findLatestResetByRoom($roomId);
		$cursor = $reset === null ? null : ['at' => $reset->getEffectiveAt(), 'id' => (int)$reset->getId()];
		if ($reset !== null) {
			$cleanliness = (int)($reset->getBaselineCleanliness() ?? 100);
			$monsters = (int)($reset->getBaselineMonsters() ?? 0);
		}
		do {
			$batch = $this->eventMapper->findEventsByRoomAfter($roomId, $cursor);
			if ($batch === []) {
				break;
			}
			$completionIds = [];
			foreach ($batch as $event) {
				if ($event->getKind() === RoomEvent::KIND_COMPLETION && $event->getSourceCompletionId() !== null) {
					$completionIds[] = (int)$event->getSourceCompletionId();
				}
			}
			$validIds = $completionIds === [] ? [] : array_fill_keys($this->completedTaskMapper->findValidIds($completionIds), true);
			foreach ($batch as $event) {
				switch ($event->getKind()) {
					case RoomEvent::KIND_BASELINE:
						$cleanliness = (int)($event->getBaselineCleanliness() ?? $cleanliness);
						$monsters = (int)($event->getBaselineMonsters() ?? $monsters);
						break;
					case RoomEvent::KIND_COMPLETION:
						$sourceId = $event->getSourceCompletionId();
						if ($sourceId !== null && !isset($validIds[(int)$sourceId])) {
							break;
						}
						$cleanliness = $this->clamp($cleanliness + (int)$event->getDeltaCleanliness());
						$lastCleaned = \DateTime::createFromInterface($event->getEffectiveAt());
						break;
					case RoomEvent::KIND_MANUAL_DELTA:
						$cleanliness = $this->clamp($cleanliness + (int)$event->getDeltaCleanliness());
						$lastCleaned = \DateTime::createFromInterface($event->getEffectiveAt());
						break;
					case RoomEvent::KIND_RESET:
						$cleanliness = (int)($event->getBaselineCleanliness() ?? 100);
						$monsters = (int)($event->getBaselineMonsters() ?? 0);
						$lastCleaned = null;
						break;
				}
				$cursor = ['at' => $event->getEffectiveAt(), 'id' => (int)$event->getId()];
			}
		} while (count($batch) === RoomEventMapper::BATCH_SIZE);

		return [
			'cleanliness' => $cleanliness,
			'monsterCount' => $monsters,
			'lastCleaned' => $lastCleaned,
		];
	}

	/**
	 * Replay the room and write the derived cache onto the locked room row
	 * (the replay is the single source; the cache is rebuildable).
	 */
	public function resyncRoomCache(Room $room): Room {
		$state = $this->replay((int)$room->getId());
		$room->setCleanliness($state['cleanliness']);
		$room->setMonsterCount($state['monsterCount']);
		$room->setLastCleaned($state['lastCleaned']);
		return $this->roomMapper->update($room);
	}

	private function clamp(int $value): int {
		return max(0, min(100, $value));
	}
}
