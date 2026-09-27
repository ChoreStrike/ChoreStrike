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
use OCA\ChoreStrike\Service\RoomService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;
use OCA\ChoreStrike\Tests\Integration\Support\QueryCounter;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * Real-DB room journal/replay proofs (CS-PLAN-070 WP3 §3.5; CR-SVC-63):
 * completion effects, manual deltas and the house reset fold through the
 * journaled REQUESTED values with clamps; undo invalidates exactly its own
 * completion effect (the correction time never becomes last_cleaned); the
 * command-UUID identity of the manual/reset writers makes a replayed
 * command a stable no-op that never repeats a delta or re-resets over
 * later work.
 */
class RoomReplayConcurrencyTest extends IntegrationTestCase {
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
	 * ManualDelta_RequestedValueIsJournaledAndClamped
	 *
	 * CR-SVC-63: the journal records the REQUESTED +20 (never the observed
	 * clamped +10) and the replayed cache clamps at 100.
	 */
	public function testManualDelta_RequestedValueIsJournaledAndClamped(): void {
		$f = $this->seedRoomFixture(baselineCleanliness: 90);
		$this->actingAs('rr_owner');

		$roomService = \OC::$server->get(RoomService::class);
		$room = $roomService->updateRoomCleanliness($f['roomId'], $f['houseId'], 20, $this->uuid());
		self::assertSame(100, (int)$room->getCleanliness(), 'the replay clamps the requested +20 at 100');

		$events = $this->roomEvents($f['roomId'], 'manual_delta');
		self::assertCount(1, $events);
		self::assertSame(20, (int)$events[0]['delta_cleanliness'], 'the REQUESTED delta is the journaled fact');
	}

	/**
	 * ManualDelta_CommandReplayDoesNotRepeatTheDelta
	 *
	 * Same command UUID + same input replays as a stable no-op; a later
	 * distinct command folds on top.
	 */
	public function testManualDelta_CommandReplayDoesNotRepeatTheDelta(): void {
		$f = $this->seedRoomFixture();
		$this->actingAs('rr_owner');

		$commandId = $this->uuid();
		$roomService = \OC::$server->get(RoomService::class);
		$roomService->updateRoomCleanliness($f['roomId'], $f['houseId'], 15, $commandId);
		// Replay: same UUID (the request carries the same idempotency key).
		$roomService->updateRoomCleanliness($f['roomId'], $f['houseId'], 15, $commandId);
		// Later distinct work.
		$roomService->updateRoomCleanliness($f['roomId'], $f['houseId'], 10, $this->uuid());

		$room = $this->roomRow($f['roomId']);
		self::assertSame(75, (int)$room['cleanliness'], '50 +15 (once) +10 — the replayed delta never repeats');

		$events = $this->roomEvents($f['roomId'], 'manual_delta');
		self::assertCount(2, $events, 'the journal holds one event per distinct command');
	}

	public function testManualDelta_ReusedUuidWithChangedDeltaConflictsWithoutBumpingVersion(): void {
		$f = $this->seedRoomFixture();
		$this->actingAs('rr_owner');
		$service = \OC::$server->get(RoomService::class);
		$uuid = $this->uuid();
		$service->updateRoomCleanliness($f['roomId'], $f['houseId'], 10, $uuid);
		$version = \OC::$server->get(HouseMapper::class)->find($f['houseId'])->getStateVersion();

		try {
			$service->updateRoomCleanliness($f['roomId'], $f['houseId'], 20, $uuid);
			self::fail('A changed payload must conflict with the original receipt');
		} catch (ConflictException $e) {
			self::assertSame(60, (int)$this->roomRow($f['roomId'])['cleanliness']);
			self::assertSame($version, \OC::$server->get(HouseMapper::class)->find($f['houseId'])->getStateVersion());
			self::assertSame(1, $this->countRoomEvents($f['roomId'], 'manual_delta'));
		}
	}

	public function testManualDelta_ReceiptReplayPrecedesStaleExpectedVersion(): void {
		$f = $this->seedRoomFixture();
		$this->actingAs('rr_owner');
		$service = \OC::$server->get(RoomService::class);
		$houses = \OC::$server->get(HouseMapper::class);
		$original = $houses->find($f['houseId'])->getStateVersion();
		$uuid = $this->uuid();
		$service->updateRoomCleanliness($f['roomId'], $f['houseId'], 10, $uuid, $original);
		$service->updateRoomCleanliness($f['roomId'], $f['houseId'], 5, $this->uuid());
		$version = $houses->find($f['houseId'])->getStateVersion();

		$replayed = $service->updateRoomCleanliness($f['roomId'], $f['houseId'], 10, $uuid, $original);
		self::assertSame(65, $replayed->getCleanliness());
		self::assertSame($version, $houses->find($f['houseId'])->getStateVersion());
		self::assertSame(2, $this->countRoomEvents($f['roomId'], 'manual_delta'));
	}

	/**
	 * Reset_AbsoluteValuesAndCommandReplayPreservesLaterWork
	 *
	 * The production reset journals one absolute event per room
	 * (cleanliness 100, monsters 0, last_cleaned NULL); replaying the same
	 * reset command afterwards is a no-op — it never re-resets over the
	 * later manual work.
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

		// Later work lands after the reset.
		$roomService = \OC::$server->get(RoomService::class);
		$roomService->updateRoomCleanliness($f['roomId'], $f['houseId'], -30, $this->uuid());
		self::assertSame(70, (int)$this->roomRow($f['roomId'])['cleanliness']);

		// The SAME reset command replays (retried request): the idempotent
		// identity must not re-run the reset over the -30.
		$houseService->resetHouse($f['houseId'], $commandId);
		self::assertSame(70, (int)$this->roomRow($f['roomId'])['cleanliness'], 'the replayed reset never re-resets over later work');

		$events = $this->roomEvents($f['roomId'], 'reset');
		self::assertCount(1, $events, 'one reset event per command identity');
	}

	public function testReset_ReplayCannotResetRoomAddedAfterOriginalCommandOrRewriteHealth(): void {
		$f = $this->seedRoomFixture();
		$this->actingAs('rr_owner');
		$houses = \OC::$server->get(HouseMapper::class);
		$service = \OC::$server->get(HouseService::class);
		$rooms = \OC::$server->get(RoomService::class);
		$uuid = $this->uuid();
		$original = $houses->find($f['houseId'])->getStateVersion();
		$service->resetHouse($f['houseId'], $uuid, $original);

		$newRoomId = $this->createRoom($f['houseId'], $this->roomFloorId($f['roomId']), 'Later room');
		$this->cleanupRoomIds[] = $newRoomId;
		\OC::$server->get(RoomReplayService::class)->recordBaseline(\OC::$server->get(RoomMapper::class)->find($newRoomId));
		$rooms->updateRoomCleanliness($f['roomId'], $f['houseId'], -30, $this->uuid());
		$rooms->updateRoomCleanliness($newRoomId, $f['houseId'], -10, $this->uuid());
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
			$replay->recordManualDelta($f['roomId'], 1, 'old-' . $i, new \DateTime('2025-01-01 00:00:00'));
		}
		\OC::$server->get(HouseService::class)->resetHouse($f['houseId'], $this->uuid());
		\OC::$server->get(RoomService::class)->updateRoomCleanliness($f['roomId'], $f['houseId'], -10, $this->uuid());

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
		self::assertSame(2, $count, implode("\n", $sql));
		self::assertStringContainsString('effective_at', $sql[1], 'the event read starts after the absolute checkpoint');
	}

	/**
	 * ResetAfterUndo_StaysAuthoritativeOverTheCorrection
	 *
	 * A reset after an undone completion remains authoritative: the replay
	 * folds baseline → completion (undone, skipped) → reset → later manual.
	 */
	public function testResetAfterUndo_StaysAuthoritativeOverTheCorrection(): void {
		$f = $this->seedRoomFixture();
		$service = \OC::$server->get(CompletionService::class);

		$this->completeOccurrence($service, $f['houseId'], 'rr_owner', $f['roomTaskA']);
		$this->undoLatestForRoomTask($service, $f['houseId'], 'rr_owner', $f['roomTaskA']);

		$this->actingAs('rr_owner');
		\OC::$server->get(HouseService::class)->resetHouse($f['houseId'], $this->uuid());
		\OC::$server->get(RoomService::class)->updateRoomCleanliness($f['roomId'], $f['houseId'], -5, $this->uuid());

		$room = $this->roomRow($f['roomId']);
		self::assertSame(95, (int)$room['cleanliness'], 'reset 100, then the later manual -5');
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
