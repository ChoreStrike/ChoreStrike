<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\CompletedTaskMapper;
use OCA\ChoreStrike\Db\Room;
use OCA\ChoreStrike\Db\RoomEvent;
use OCA\ChoreStrike\Db\RoomEventMapper;
use OCA\ChoreStrike\Db\RoomMapper;
use OCA\ChoreStrike\Service\RoomReplayService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the room journal replay (CS-PLAN-070 WP3 §3.5; CR-SVC-63):
 * the fold of VALID events in (effective_at, id) order with the saved
 * REQUESTED effects and clamps — never today's task energy, never observed
 * clamped deltas. Covers the documented clamp example, undo invalidation,
 * reset semantics (incl. last_cleaned), receipt-owned manual/reset
 * writers and batched cursor paging. Real races and
 * writer integration live in the Docker suites (RoomReplayConcurrencyTest).
 */
class RoomReplayServiceTest extends TestCase {
	private RoomEventMapper $eventMapper;
	private CompletedTaskMapper $completedTaskMapper;
	private RoomMapper $roomMapper;
	private RoomReplayService $service;

	protected function setUp(): void {
		$this->eventMapper = $this->createMock(RoomEventMapper::class);
		$this->completedTaskMapper = $this->createMock(CompletedTaskMapper::class);
		$this->roomMapper = $this->createMock(RoomMapper::class);
		$this->service = new RoomReplayService(
			$this->eventMapper,
			$this->completedTaskMapper,
			$this->roomMapper,
		);
	}

	private function event(string $kind, string $at, int $id, ?int $delta = null, ?int $completionId = null, ?int $baselineClean = null, ?int $baselineMonsters = null): RoomEvent {
		$event = new RoomEvent();
		$event->setId($id);
		$event->setRoomId(7);
		$event->setKind($kind);
		$event->setEffectiveAt(new \DateTime($at));
		$event->setStableId($kind . ':' . $id);
		if ($delta !== null) {
			$event->setDeltaCleanliness($delta);
		}
		if ($completionId !== null) {
			$event->setSourceCompletionId($completionId);
		}
		if ($baselineClean !== null) {
			$event->setBaselineCleanliness($baselineClean);
		}
		if ($baselineMonsters !== null) {
			$event->setBaselineMonsters($baselineMonsters);
		}
		return $event;
	}

	/**
	 * Replay_BaselinePlusCompletion_FoldsRequestedEffectAndStampsLastCleaned
	 */
	public function testReplay_BaselinePlusCompletion_FoldsEffectAndStampsLastCleaned(): void {
		$baseline = $this->event(RoomEvent::KIND_BASELINE, '2026-09-20 08:00:00', 1, baselineClean: 50, baselineMonsters: 0);
		$completion = $this->event(RoomEvent::KIND_COMPLETION, '2026-09-21 09:30:00', 2, delta: 30, completionId: 55);

		$this->eventMapper->method('findEventsByRoomAfter')->willReturn([$baseline, $completion]);
		$this->completedTaskMapper->expects($this->once())->method('findValidIds')->with([55])->willReturn([55]);

		$state = $this->service->replay(7);

		self::assertSame(80, $state['cleanliness']);
		self::assertSame(0, $state['monsterCount']);
		self::assertSame('2026-09-21 09:30:00', $state['lastCleaned']->format('Y-m-d H:i:s'));
	}

	/**
	 * Replay_SourceLessConcurrentWinner_PreservesItsEffectWithEarlierWorkInstant
	 *
	 * A room-lock winner may journal a completion effect without a source
	 * ledger ID. The old validity check treated this as valid; batching must
	 * not coerce NULL to ID 0 and silently drop the committed winner.
	 */
	public function testReplay_SourceLessConcurrentWinner_PreservesItsEffectWithEarlierWorkInstant(): void {
		$baseline = $this->event(RoomEvent::KIND_BASELINE, '2026-09-20 08:00:00', 1, baselineClean: 50);
		$winner = $this->event(RoomEvent::KIND_COMPLETION, '2026-09-21 10:00:00', 2, delta: 20);
		$blocked = $this->event(RoomEvent::KIND_COMPLETION, '2026-09-21 09:00:00', 3, delta: 20, completionId: 55);
		// The blocked completion captured work time before acquiring the
		// room lock, then inserted after the concurrent winner committed.
		$this->eventMapper->method('findEventsByRoomAfter')->willReturn([$baseline, $blocked, $winner]);
		$this->completedTaskMapper->expects($this->once())->method('findValidIds')->with([55])->willReturn([55]);

		$state = $this->service->replay(7);
		self::assertSame(90, $state['cleanliness']);
		self::assertSame('2026-09-21 10:00:00', $state['lastCleaned']->format('Y-m-d H:i:s'));
	}

	/**
	 * Replay_FalseRequestedDeltaClamps_ThenUndo_LaterValidDeltaReplaysToClamp
	 *
	 * The documented WP3 §3.5 example: baseline 90, false requested +20
	 * (clamps to 100), later valid +20 replays to 100 with the later valid
	 * timestamp after the false completion is undone.
	 */
	public function testReplay_ClampExample_UndoThenLaterValidReplay(): void {
		$baseline = $this->event(RoomEvent::KIND_BASELINE, '2026-09-20 08:00:00', 1, baselineClean: 90, baselineMonsters: 0);
		$falseCompletion = $this->event(RoomEvent::KIND_COMPLETION, '2026-09-21 10:00:00', 2, delta: 20, completionId: 60);
		$validCompletion = $this->event(RoomEvent::KIND_COMPLETION, '2026-09-22 11:00:00', 3, delta: 20, completionId: 61);

		$this->eventMapper->method('findEventsByRoomAfter')->willReturn([$baseline, $falseCompletion, $validCompletion]);
		$this->completedTaskMapper->expects($this->once())->method('findValidIds')->with([60, 61])->willReturn([61]);

		$state = $this->service->replay(7);

		// The false +20 never applied (its completion is undone); the valid
		// +20 folds 90 → 110 → clamped 100 with the VALID work instant.
		self::assertSame(100, $state['cleanliness']);
		self::assertSame('2026-09-22 11:00:00', $state['lastCleaned']->format('Y-m-d H:i:s'));
	}

	/**
	 * Replay_UndoInvalidatesExactlyTheCompletionEffect
	 */
	public function testReplay_UndoInvalidatesExactlyTheCompletionEffect(): void {
		$baseline = $this->event(RoomEvent::KIND_BASELINE, '2026-09-20 08:00:00', 1, baselineClean: 50, baselineMonsters: 0);
		$undone = $this->event(RoomEvent::KIND_COMPLETION, '2026-09-21 10:00:00', 2, delta: 30, completionId: 70);
		$manual = $this->event(RoomEvent::KIND_MANUAL_DELTA, '2026-09-21 12:00:00', 3, delta: 10);

		$this->eventMapper->method('findEventsByRoomAfter')->willReturn([$baseline, $undone, $manual]);
		$this->completedTaskMapper->expects($this->once())->method('findValidIds')->with([70])->willReturn([]);

		$state = $this->service->replay(7);

		// Only the manual delta folds: 50 + 10 = 60; the correction execution
		// time never becomes last_cleaned.
		self::assertSame(60, $state['cleanliness']);
		self::assertSame('2026-09-21 12:00:00', $state['lastCleaned']->format('Y-m-d H:i:s'));
	}

	/**
	 * Replay_EqualEffectiveTime_OrdersByEventId
	 */
	public function testReplay_EqualEffectiveTime_OrdersByEventId(): void {
		$baseline = $this->event(RoomEvent::KIND_BASELINE, '2026-09-20 08:00:00', 1, baselineClean: 95, baselineMonsters: 2);
		// Two resets at the SAME instant: the later id wins (replay order).
		$resetFirst = $this->event(RoomEvent::KIND_RESET, '2026-09-21 09:00:00', 2, baselineClean: 100, baselineMonsters: 0);
		$resetFirst->setClearsLastCleaned(true);
		$resetSecond = $this->event(RoomEvent::KIND_RESET, '2026-09-21 09:00:00', 3, baselineClean: 40, baselineMonsters: 1);
		$resetSecond->setClearsLastCleaned(true);

		$this->eventMapper->method('findLatestResetByRoom')->willReturn($resetSecond);
		$this->eventMapper->expects($this->once())->method('findEventsByRoomAfter')
			->with(7, ['at' => $resetSecond->getEffectiveAt(), 'id' => 3])->willReturn([]);

		$state = $this->service->replay(7);

		self::assertSame(40, $state['cleanliness'], 'the later-id reset is authoritative at equal time');
		self::assertSame(1, $state['monsterCount']);
		self::assertNull($state['lastCleaned'], 'a reset clears last_cleaned');
	}

	/**
	 * Replay_LaterResetStaysAuthoritativeOverEarlierCorrections
	 */
	public function testReplay_LaterResetStaysAuthoritativeOverEarlierCorrections(): void {
		$baseline = $this->event(RoomEvent::KIND_BASELINE, '2026-09-20 08:00:00', 1, baselineClean: 50, baselineMonsters: 0);
		$completion = $this->event(RoomEvent::KIND_COMPLETION, '2026-09-21 10:00:00', 2, delta: 25, completionId: 80);
		$reset = $this->event(RoomEvent::KIND_RESET, '2026-09-22 09:00:00', 3, baselineClean: 100, baselineMonsters: 0);
		$reset->setClearsLastCleaned(true);
		$manual = $this->event(RoomEvent::KIND_MANUAL_DELTA, '2026-09-23 09:00:00', 4, delta: -10);

		$this->eventMapper->method('findLatestResetByRoom')->willReturn($reset);
		$this->eventMapper->expects($this->once())->method('findEventsByRoomAfter')
			->with(7, ['at' => $reset->getEffectiveAt(), 'id' => 3])->willReturn([$manual]);
		$this->completedTaskMapper->expects($this->never())->method('findValidIds');

		$state = $this->service->replay(7);

		// 50 +25 → 75, reset → 100/NULL, manual -10 → 90 with the manual stamp.
		self::assertSame(90, $state['cleanliness']);
		self::assertSame('2026-09-23 09:00:00', $state['lastCleaned']->format('Y-m-d H:i:s'));
	}

	/**
	 * Replay_NoBaseline_UsesSchemaDefaults
	 */
	public function testReplay_NoBaseline_UsesSchemaDefaults(): void {
		$manual = $this->event(RoomEvent::KIND_MANUAL_DELTA, '2026-09-21 10:00:00', 5, delta: 5);

		$this->eventMapper->method('findEventsByRoomAfter')->willReturn([$manual]);

		$state = $this->service->replay(7);

		self::assertSame(55, $state['cleanliness']);
	}

	/**
	 * Replay_PagesBatchesWithTheCursorUntilAShortBatch
	 */
	public function testReplay_PagesBatchesWithTheCursorUntilAShortBatch(): void {
		// The paging loop continues ONLY while a full BATCH_SIZE (500)
		// batch returns — replay exactly one full batch plus a short tail.
		$batch1 = [$this->event(RoomEvent::KIND_BASELINE, '2026-09-20 08:00:00', 1, baselineClean: 80, baselineMonsters: 0)];
		for ($i = 2; $i <= RoomEventMapper::BATCH_SIZE; $i++) {
			$batch1[] = $this->event(RoomEvent::KIND_MANUAL_DELTA, '2026-09-21 08:00:00', $i, delta: 0);
		}
		$batch2 = [$this->event(RoomEvent::KIND_MANUAL_DELTA, '2026-09-22 08:00:00', RoomEventMapper::BATCH_SIZE + 1, delta: 10)];

		$cursors = [];
		$this->eventMapper->method('findEventsByRoomAfter')->willReturnCallback(
			static function (int $roomId, ?array $cursor) use (&$cursors, $batch1, $batch2): array {
				$cursors[] = $cursor;
				return $cursor === null ? $batch1 : $batch2;
			}
		);

		$state = $this->service->replay(7);

		self::assertCount(2, $cursors);
		self::assertNull($cursors[0]);
		self::assertSame(RoomEventMapper::BATCH_SIZE, $cursors[1]['id'], 'the second read resumes after the last folded event id');
		self::assertSame(90, $state['cleanliness']);
		self::assertSame('2026-09-22 08:00:00', $state['lastCleaned']->format('Y-m-d H:i:s'));
	}

	/**
	 * RecordManualDelta_ReceiptOwnedWriter_InsertsRequestedEffect
	 */
	public function testRecordManualDelta_ReceiptOwnedWriter_InsertsRequestedEffect(): void {
		$this->eventMapper->expects($this->once())->method('insert')->with($this->callback(
			static fn (RoomEvent $e): bool => $e->getStableId() === 'manual:uuid-1' && $e->getDeltaCleanliness() === 15
		))->willReturnArgument(0);
		$this->service->recordManualDelta(7, 15, 'uuid-1', new \DateTime());
	}

	/**
	 * RecordReset_ReceiptOwnedWriter_InsertsAbsoluteCheckpoint
	 */
	public function testRecordReset_ReceiptOwnedWriter_InsertsAbsoluteCheckpoint(): void {
		$this->eventMapper->expects($this->once())->method('insert')->with($this->callback(
			static fn (RoomEvent $e): bool => $e->getStableId() === 'reset:uuid-2'
				&& $e->getBaselineCleanliness() === 100 && $e->getBaselineMonsters() === 0
		))->willReturnArgument(0);
		$this->service->recordReset(7, 'uuid-2', new \DateTime());
	}

	/**
	 * Replay_LargeBatch_QueriesCompletionValidityOnce
	 */
	public function testReplay_LargeBatch_QueriesCompletionValidityOnce(): void {
		$events = [$this->event(RoomEvent::KIND_BASELINE, '2026-09-20 08:00:00', 1, baselineClean: 50)];
		for ($i = 2; $i <= 80; $i++) {
			$events[] = $this->event(RoomEvent::KIND_COMPLETION, '2026-09-21 08:00:00', $i, delta: 1, completionId: $i);
		}
		$this->eventMapper->method('findEventsByRoomAfter')->willReturn($events);
		$this->completedTaskMapper->expects($this->once())->method('findValidIds')
			->with(range(2, 80))->willReturn(range(2, 80));
		self::assertSame(100, $this->service->replay(7)['cleanliness']);
	}

	/**
	 * ResyncRoomCache_WritesTheReplayedStateOntoTheRoomRow
	 */
	public function testResyncRoomCache_WritesReplayedStateOntoTheRoomRow(): void {
		$baseline = $this->event(RoomEvent::KIND_BASELINE, '2026-09-20 08:00:00', 1, baselineClean: 60, baselineMonsters: 1);
		$completion = $this->event(RoomEvent::KIND_COMPLETION, '2026-09-21 10:00:00', 2, delta: 30, completionId: 90);

		$this->eventMapper->method('findEventsByRoomAfter')->willReturn([$baseline, $completion]);
		$this->completedTaskMapper->method('findValidIds')->with([90])->willReturn([90]);

		$room = new Room();
		$room->setId(7);
		$room->setCleanliness(50);
		$room->setMonsterCount(0);

		$this->roomMapper->expects($this->once())->method('update')->with($this->callback(
			static fn (Room $r): bool => $r->getCleanliness() === 90
				&& $r->getMonsterCount() === 1
				&& $r->getLastCleaned()?->format('Y-m-d H:i:s') === '2026-09-21 10:00:00'
		))->willReturnArgument(0);

		$this->service->resyncRoomCache($room);
	}
}
