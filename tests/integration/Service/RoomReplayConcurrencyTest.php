<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Service;

use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\RoomMapper;
use OCA\ChoreStrike\Exception\ConflictException;
use OCA\ChoreStrike\Service\CompletionService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\RoomReplayService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;
use OCA\ChoreStrike\Tests\Integration\Support\QueryCounter;
use OCA\ChoreStrike\Tests\Integration\Support\StoredTimelineFixtures;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * Real-DB room journal/replay proofs (CS-PLAN-070 WP3 §3.5; CR-SVC-63):
 * completion effects, retained manual deltas and the house reset fold
 * through the journaled REQUESTED values with clamps; undo invalidates
 * exactly its own completion effect (the correction time never becomes
 * last_cleaned); the command-UUID identity of the reset makes a replayed
 * command a stable no-op that never re-resets over later work.
 *
 * The quick clean that wrote manual deltas is retired (CS-PLAN-091 WP-B7):
 * its entries are seeded as stored rows and keep replaying.
 */
class RoomReplayConcurrencyTest extends IntegrationTestCase {
	use StoredTimelineFixtures;

	private array $cleanupRoomTaskIds = [];
	private array $cleanupRoomIds = [];
	private array $cleanupHouseIds = [];

	protected function tearDown(): void {
		foreach ($this->cleanupRoomTaskIds as $roomTaskId) {
			$completionIds = $this->completionIdsForRoomTask($roomTaskId);
			if ($completionIds !== []) {
				$intArray = IQueryBuilder::PARAM_INT_ARRAY;
				$qb = $this->db->getQueryBuilder();
				$qb->delete('chorestrike_work_shares')
					->where($qb->expr()->in('completion_id', $qb->createNamedParameter($completionIds, $intArray)))
					->executeStatement();
				$qb = $this->db->getQueryBuilder();
				$qb->delete('chorestrike_completion_revs')
					->where($qb->expr()->in('completion_id', $qb->createNamedParameter($completionIds, $intArray)))
					->executeStatement();
			}
			$qb = $this->db->getQueryBuilder();
			$qb->delete('chorestrike_completed_tasks')
				->where($qb->expr()->eq('room_task_id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)))
				->executeStatement();
			$qb = $this->db->getQueryBuilder();
			$qb->delete('chorestrike_chk_progress')
				->where($qb->expr()->eq('room_task_id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)))
				->executeStatement();
		}

		foreach ($this->cleanupRoomIds as $roomId) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('chorestrike_room_events')
				->where($qb->expr()->eq('room_id', $qb->createNamedParameter($roomId, IQueryBuilder::PARAM_INT)))
				->executeStatement();
		}

		foreach ($this->cleanupHouseIds as $houseId) {
			$walletIds = $this->walletIdsForHouse($houseId);
			if ($walletIds !== []) {
				$qb = $this->db->getQueryBuilder();
				$qb->delete('chorestrike_wallet_entries')
					->where($qb->expr()->in('wallet_id', $qb->createNamedParameter($walletIds, IQueryBuilder::PARAM_INT_ARRAY)))
					->executeStatement();
			}
			$qb = $this->db->getQueryBuilder();
			$qb->delete('chorestrike_wallets')
				->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
				->executeStatement();
		}

		parent::tearDown();
	}

	/**
	 * CompleteUndo_ReplayKeepsLaterValidWorkAndDropsTheUndoneEffect
	 *
	 * Two completions (+20 each on the baseline); undoing the FIRST folds
	 * to baseline+20 — the later valid completion survives with its own
	 * work instant as last_cleaned.
	 */
	public function testCompleteUndo_LaterValidCompletionSurvivesTheReplay(): void {
		$f = $this->seedRoomFixture();
		$service = \OC::$server->get(CompletionService::class);

		$this->completeOccurrence($service, $f['houseId'], 'rr_owner', $f['roomTaskA']);
		$this->completeOccurrence($service, $f['houseId'], 'rr_owner', $f['roomTaskB']);

		$room = $this->roomRow($f['roomId']);
		self::assertSame(90, (int)$room['cleanliness'], 'baseline 50 + two +20 completion effects');

		// Undo the FIRST completion (targeted by id, not "latest").
		$first = $this->completionRow($f['roomTaskA']);
		$owner = \OC::$server->get(\OCA\ChoreStrike\Db\HouseUserMapper::class)->findByNextcloudUserId($f['houseId'], 'rr_owner');
		$service->undo($f['houseId'], $owner, (int)$first['id'], $this->uuid(), (int)$first['completion_version'], 'not_done');

		$room = $this->roomRow($f['roomId']);
		self::assertSame(70, (int)$room['cleanliness'], 'the undone effect drops out; the later valid +20 survives');
		self::assertNotNull($room['last_cleaned'], 'the surviving completion keeps the room cleaned');

		// The journal still carries both completion events — the undo
		// invalidates through the completion row, never by deleting history.
		self::assertSame(2, $this->countRoomEvents($f['roomId'], 'completion'));
	}

	/**
	 * UndoAfterCompletion_LastCleanedReturnsToNull
	 *
	 * With only the undone completion in the journal, the replayed room has
	 * NO last_cleaned — the correction execution time never becomes it.
	 */
	public function testUndo_LastCleanedReturnsToNullWithoutSurvivingWork(): void {
		$f = $this->seedRoomFixture();
		$service = \OC::$server->get(CompletionService::class);

		$this->completeOccurrence($service, $f['houseId'], 'rr_owner', $f['roomTaskA']);
		self::assertNotNull($this->roomRow($f['roomId'])['last_cleaned']);

		$this->undoLatestForRoomTask($service, $f['houseId'], 'rr_owner', $f['roomTaskA']);

		$room = $this->roomRow($f['roomId']);
		self::assertSame(50, (int)$room['cleanliness']);
		self::assertNull($room['last_cleaned'], 'the baseline carries no last_cleaned');
	}

	/**
	 * RetainedManualDelta_KeepsReplayingWithItsRequestedValueAndClamp
	 *
	 * CR-SVC-63: the journal holds the REQUESTED +20 (never the observed
	 * clamped +10). Nothing writes such an entry any more; a retained one
	 * still folds into the room, clamped at 100, and stamps last_cleaned.
	 */
	public function testRetainedManualDelta_KeepsReplayingWithItsRequestedValueAndClamp(): void {
		$f = $this->seedRoomFixture(baselineCleanliness: 90);
		$this->insertStoredManualDelta($f['roomId'], 20);
		$this->insertStoredManualDelta($f['roomId'], -15);

		$room = $this->resyncRoom($f['roomId']);

		self::assertSame(85, (int)$room['cleanliness'], '90 +20 clamps at 100, then the requested -15');
		self::assertNotNull($room['last_cleaned']);
		self::assertSame(20, (int)$this->roomEvents($f['roomId'], 'manual_delta')[0]['delta_cleanliness'], 'the REQUESTED delta stays the journaled fact');

		// A later completion replays the retained entries with its own effect.
		$this->completeOccurrence(\OC::$server->get(CompletionService::class), $f['houseId'], 'rr_owner', $f['roomTaskA']);
		self::assertSame(100, (int)$this->roomRow($f['roomId'])['cleanliness'], '85 +20 clamps at 100');
	}

	/**
	 * Reset_AbsoluteValuesAndCommandReplayPreservesLaterWork
	 *
	 * The production reset journals one absolute event per room
	 * (cleanliness 100, monsters 0, last_cleaned NULL); replaying the same
	 * reset command afterwards is a no-op — it never re-resets over the
	 * later work.
	 */
	public function testReset_CommandReplayPreservesLaterWork(): void {
		$f = $this->seedRoomFixture();
		$this->actingAs('rr_owner');

		$houseService = \OC::$server->get(HouseService::class);
		$commandId = $this->uuid();
		$houseService->resetHouse($f['houseId'], $commandId);

		$room = $this->roomRow($f['roomId']);
		self::assertSame(100, (int)$room['cleanliness']);
		self::assertSame(0, (int)$room['monster_count']);
		self::assertNull($room['last_cleaned']);

		// Later work lands after the reset: a retained -30 and a completion.
		$this->insertStoredManualDelta($f['roomId'], -30);
		$this->completeOccurrence(\OC::$server->get(CompletionService::class), $f['houseId'], 'rr_owner', $f['roomTaskA']);
		self::assertSame(90, (int)$this->roomRow($f['roomId'])['cleanliness'], 'reset 100, -30, then the completion\'s +20');
		$this->actingAs('rr_owner');

		// The SAME reset command replays (retried request): the idempotent
		// identity must not re-run the reset over the later work.
		$houseService->resetHouse($f['houseId'], $commandId);
		$room = $this->roomRow($f['roomId']);
		self::assertSame(90, (int)$room['cleanliness'], 'the replayed reset never re-resets over later work');
		self::assertNotNull($room['last_cleaned'], 'the completion after the reset stays the room\'s last cleaning');

		$events = $this->roomEvents($f['roomId'], 'reset');
		self::assertCount(1, $events, 'one reset event per command identity');
	}

	public function testReset_ReplayCannotResetRoomAddedAfterOriginalCommandOrRewriteHealth(): void {
		$f = $this->seedRoomFixture();
		$this->actingAs('rr_owner');
		$houses = \OC::$server->get(HouseMapper::class);
		$service = \OC::$server->get(HouseService::class);
		$uuid = $this->uuid();
		$original = $houses->find($f['houseId'])->getStateVersion();
		$service->resetHouse($f['houseId'], $uuid, $original);

		$newRoomId = $this->createRoom($f['houseId'], $this->roomFloorId($f['roomId']), 'Later room');
		$this->cleanupRoomIds[] = $newRoomId;
		\OC::$server->get(RoomReplayService::class)->recordBaseline(\OC::$server->get(RoomMapper::class)->find($newRoomId));
		$this->insertStoredManualDelta($f['roomId'], -30);
		$this->insertStoredManualDelta($newRoomId, -10);
		$this->resyncRoom($f['roomId']);
		$this->resyncRoom($newRoomId);
		$health = $service->calculateHouseHealth($f['houseId']);
		$version = $houses->find($f['houseId'])->getStateVersion();

		$service->resetHouse($f['houseId'], $uuid, $original);
		self::assertSame(70, (int)$this->roomRow($f['roomId'])['cleanliness']);
		self::assertSame(40, (int)$this->roomRow($newRoomId)['cleanliness']);
		self::assertSame(0, $this->countRoomEvents($newRoomId, 'reset'));
		self::assertSame($health, $houses->find($f['houseId'])->getHouseHealth());
		self::assertSame($version, $houses->find($f['houseId'])->getStateVersion());
	}

	public function testReset_UuidReusedWithChangedVersionConflicts(): void {
		$f = $this->seedRoomFixture();
		$this->actingAs('rr_owner');
		$service = \OC::$server->get(HouseService::class);
		$uuid = $this->uuid();
		$version = \OC::$server->get(HouseMapper::class)->find($f['houseId'])->getStateVersion();
		$service->resetHouse($f['houseId'], $uuid, $version);

		$this->expectException(ConflictException::class);
		$service->resetHouse($f['houseId'], $uuid, $version + 1);
	}

	public function testReplay_LatestResetBoundsHistoryReadDespiteOldEvents(): void {
		$f = $this->seedRoomFixture();
		$this->actingAs('rr_owner');
		$replay = \OC::$server->get(RoomReplayService::class);
		for ($i = 0; $i < 80; $i++) {
			$this->insertStoredManualDelta($f['roomId'], 1, '2025-01-01 00:00:00');
		}
		\OC::$server->get(HouseService::class)->resetHouse($f['houseId'], $this->uuid());
		$this->insertStoredManualDelta($f['roomId'], -10);

		$counter = QueryCounter::attach($this->db);
		try {
			$counter->mark();
			$state = $replay->replay($f['roomId']);
			$count = $counter->count();
			$sql = $counter->sqlStatements();
		} finally {
			$counter->detach();
		}

		self::assertSame(90, $state['cleanliness']);
		self::assertSame(81, $this->countRoomEvents($f['roomId'], 'manual_delta'));
		// Reset lookup, the event read after the checkpoint and — for a room
		// with a reset only — the `last_done` entries recorded after it
		// (FEAT-UX-045 review fix: such an entry counts for an earlier day).
		self::assertSame(3, $count, implode("\n", $sql));
		self::assertStringContainsString('effective_at', $sql[1], 'the event read starts after the absolute checkpoint');
		self::assertStringNotContainsString('effective_at', $sql[2], 'the entry read is bounded by the reset id, not by time');
	}

	/**
	 * ResetAfterUndo_StaysAuthoritativeOverTheCorrection
	 *
	 * A reset after an undone completion remains authoritative: the replay
	 * folds baseline → completion (undone, skipped) → reset → a later
	 * retained delta.
	 */
	public function testResetAfterUndo_StaysAuthoritativeOverTheCorrection(): void {
		$f = $this->seedRoomFixture();
		$service = \OC::$server->get(CompletionService::class);

		$this->completeOccurrence($service, $f['houseId'], 'rr_owner', $f['roomTaskA']);
		$this->undoLatestForRoomTask($service, $f['houseId'], 'rr_owner', $f['roomTaskA']);

		$this->actingAs('rr_owner');
		\OC::$server->get(HouseService::class)->resetHouse($f['houseId'], $this->uuid());
		$this->insertStoredManualDelta($f['roomId'], -5);

		$room = $this->resyncRoom($f['roomId']);
		self::assertSame(95, (int)$room['cleanliness'], 'reset 100, then the later -5');
	}

	// ========== Helpers ==========

	/**
	 * @return array{houseId: int, roomId: int, roomTaskA: int, roomTaskB: int}
	 */
	private function seedRoomFixture(int $baselineCleanliness = 50): array {
		$houseId = $this->createHouse('rr_owner', 'Replay House');
		$floorId = $this->createFloor($houseId, 'Ground Floor', 1);
		$roomId = $this->createRoom($houseId, $floorId, 'Kitchen');

		if ($baselineCleanliness !== 50) {
			$qb = $this->db->getQueryBuilder();
			$qb->update('chorestrike_rooms')
				->set('cleanliness', $qb->createNamedParameter($baselineCleanliness, IQueryBuilder::PARAM_INT))
				->where($qb->expr()->eq('id', $qb->createNamedParameter($roomId, IQueryBuilder::PARAM_INT)))
				->executeStatement();
		}

		// WP3 §3.5: the creation baseline anchors the replay — raw fixture
		// rows get the same explicit baseline fact production writes.
		$room = \OC::$server->get(RoomMapper::class)->find($roomId);
		\OC::$server->get(RoomReplayService::class)->recordBaseline($room);

		$taskA = $this->createTask($houseId, 'Replay A', '', 5, 2);
		$taskB = $this->createTask($houseId, 'Replay B', '', 5, 2);
		$roomTaskA = $this->assignTaskToRoom($taskA, $roomId, 'rr_owner');
		$roomTaskB = $this->assignTaskToRoom($taskB, $roomId, 'rr_owner');

		$this->cleanupRoomTaskIds = [$roomTaskA, $roomTaskB];
		$this->cleanupRoomIds = [$roomId];
		$this->cleanupHouseIds = [$houseId];

		return ['houseId' => $houseId, 'roomId' => $roomId, 'roomTaskA' => $roomTaskA, 'roomTaskB' => $roomTaskB];
	}

	/**
	 * Rebuild the room cache from its journal, as every room writer does.
	 *
	 * @return array<string, mixed> The room row afterwards
	 */
	private function resyncRoom(int $roomId): array {
		\OC::$server->get(RoomReplayService::class)->resyncRoomCache(\OC::$server->get(RoomMapper::class)->find($roomId));
		return $this->roomRow($roomId);
	}

	private function uuid(): string {
		return sprintf('%08x-%04x-4%03x-8%03x-%012x', mt_rand(), mt_rand(0, 0xffff), mt_rand(0, 0xfff), mt_rand(0, 0xfff), mt_rand());
	}

	/**
	 * @return array<string, mixed>
	 */
	private function roomRow(int $roomId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('chorestrike_rooms')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($roomId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		self::assertIsArray($row);
		return $row;
	}

	private function roomFloorId(int $roomId): int {
		return (int)$this->roomRow($roomId)['floor_id'];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function completionRow(int $roomTaskId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('chorestrike_completed_tasks')
			->where($qb->expr()->eq('room_task_id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC')
			->setMaxResults(1);
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		self::assertIsArray($row);
		return $row;
	}

	/**
	 * @return array<int, int>
	 */
	private function completionIdsForRoomTask(int $roomTaskId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from('chorestrike_completed_tasks')
			->where($qb->expr()->eq('room_task_id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$ids = array_map('intval', $result->fetchAll(\PDO::FETCH_COLUMN));
		$result->closeCursor();
		return $ids;
	}

	private function countRoomEvents(int $roomId, string $kind): int {
		return count($this->roomEvents($roomId, $kind));
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function roomEvents(int $roomId, string $kind): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('chorestrike_room_events')
			->where($qb->expr()->eq('room_id', $qb->createNamedParameter($roomId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('kind', $qb->createNamedParameter($kind)))
			->orderBy('effective_at', 'ASC')
			->addOrderBy('id', 'ASC');
		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();
		return $rows;
	}

	/**
	 * @return int[]
	 */
	private function walletIdsForHouse(int $houseId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from('chorestrike_wallets')
			->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$ids = array_map('intval', $result->fetchAll(\PDO::FETCH_COLUMN));
		$result->closeCursor();
		return $ids;
	}
}
