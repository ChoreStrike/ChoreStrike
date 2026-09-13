<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Service;

use OCA\ChoreStrike\Db\CompletedTaskMapper;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\RoomMapper;
use OCA\ChoreStrike\Db\RoomTaskChecklistProgressMapper;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\TaskAssigneeMapper;
use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Db\TransactionExecutor;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\CalendarService;
use OCA\ChoreStrike\Service\CompletionService;
use OCA\ChoreStrike\Service\GameLogicService;
use OCA\ChoreStrike\Service\RewardConfigService;
use OCA\ChoreStrike\Service\TaskAccessPolicy;
use OCA\ChoreStrike\Service\TitleService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * WP5 exit gate (CS-PLAN-064 §4): a full completion → undo roundtrip must
 * restore EXACT persisted domain state across every persisted row the
 * completion touches — room_tasks, rooms, house_users and chk_progress —
 * for the recurrence permutations (once / recurring-dynamic / recurring-
 * static / recurring-flex), an overdue-takeover completion (claim fields),
 * and the D11 duplicate-guard roundtrip (complete → undo → same-day
 * re-complete → undo leaves two ledger rows, none revertible, final state
 * equal to the initial snapshot).
 *
 * The reward-side equality additionally proves the title LIFO interplay:
 * an undo removes only the title ids its OWN occurrence added — a title
 * unlocked before the undone occurrence (or by a later one) survives.
 */
class CompletionUndoStateEqualityTest extends IntegrationTestCase {
	/** @var int[] Room task ids whose ledger rows need explicit cleanup */
	private array $ledgerRoomTaskIds = [];
	/** @var int[] Room task ids whose checklist rows need explicit cleanup */
	private array $progressRoomTaskIds = [];

	protected function tearDown(): void {
		foreach (['chorestrike_completed_tasks' => $this->ledgerRoomTaskIds, 'chorestrike_chk_progress' => $this->progressRoomTaskIds] as $table => $ids) {
			if ($ids !== []) {
				$qb = $this->db->getQueryBuilder();
				$qb->delete($table)
					->where($qb->expr()->in('room_task_id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)))
					->executeStatement();
			}
		}

		parent::tearDown();
	}

	/**
	 * Recurrence permutations of the WP5 state-equality exit gate.
	 *
	 * @return array<string, array<int, mixed>>
	 */
	public static function recurrencePermutations(): array {
		return [
			'once deactivates and undo reactivates' => [
				['recurrence_mode' => 'once'],
			],
			'recurring dynamic advances from completion' => [
				[],
			],
			'recurring static follows the fixed schedule' => [
				[
					'scheduling_mode' => 'static',
					'fixed_schedule_time' => '1970-01-01 08:00:00',
					'fixed_schedule_days' => '["monday","tuesday","wednesday","thursday","friday","saturday","sunday"]',
				],
			],
			'recurring flex computes the ideal due window' => [
				['flex_days' => 3],
			],
		];
	}

	/**
	 * Complete_InPermutation_ThenUndo_RestoresExactPersistedState
	 *
	 * The WP5 exit gate: every persisted domain row is byte-identical after
	 * the undo (checked checklist rows reset by the completion are restored,
	 * and the ledger carries exactly the completed occurrence, marked undone).
	 *
	 * @dataProvider recurrencePermutations
	 * @param array<string, mixed> $taskColumns
	 */
	public function testCompleteThenUndo_RestoresExactPersistedState(array $taskColumns): void {
		$ids = $this->seedPermutationFixture($taskColumns);

		$completionService = \OC::$server->get(CompletionService::class);
		$actor = $this->houseUser('ueq_owner', $ids['houseId']);

		$before = $this->snapshotDomainState($ids['houseId'], $ids['roomId'], $ids['roomTaskId']);

		$completionService->complete($ids['houseId'], 'ueq_owner', $ids['roomTaskId']);
		$this->ledgerRoomTaskIds[] = $ids['roomTaskId'];

		$afterCompletion = $this->snapshotDomainState($ids['houseId'], $ids['roomId'], $ids['roomTaskId']);

		// Sanity: the completion actually mutated the domain rows (the
		// recurrence advance happened; for `once` the row deactivated) and
		// the occurrence-scoped checklist reset ran (decision D6).
		self::assertNotSame($before['roomTask'], $afterCompletion['roomTask'], 'completion must advance the recurrence state');
		if ($before['progress'] !== []) {
			foreach ($afterCompletion['progress'] as $row) {
				self::assertSame(0, (int)$row['is_checked'], 'completion must reset occurrence-scoped checklist progress');
				self::assertNull($row['checked_by_user_id']);
				self::assertNull($row['checked_at']);
			}
		}
		$ledger = $this->ledgerRows($ids['roomTaskId']);
		self::assertCount(1, $ledger);
		self::assertNull($ledger[0]['undone_at'], 'a fresh completion is revertible');
		self::assertNotNull($ledger[0]['occurrence_due'], 'the ledger row records occurrence_due');
		self::assertJson($ledger[0]['before_state']);
		self::assertJson($ledger[0]['deltas']);

		$completionService->undo($ids['houseId'], $actor, $ids['roomTaskId']);

		$afterUndo = $this->snapshotDomainState($ids['houseId'], $ids['roomId'], $ids['roomTaskId']);

		self::assertSame($before['roomTask'], $afterUndo['roomTask'], 'room task state must be exactly restored');
		self::assertSame($before['room'], $afterUndo['room'], 'room cleanliness/last_cleaned must be exactly restored');
		self::assertSame($before['users'], $afterUndo['users'], 'completer reward state must be exactly restored');
		self::assertSame($before['progress'], $afterUndo['progress'], 'checklist progress must be exactly restored');

		// The undone occurrence is not a completion anywhere (D11): the
		// history row stays, but nothing is revertible anymore.
		$ledger = $this->ledgerRows($ids['roomTaskId']);
		self::assertCount(1, $ledger);
		self::assertNotNull($ledger[0]['undone_at']);
	}

	/**
	 * TakeoverCompletion_UndoRestoresClaimFields_AndExactState
	 *
	 * A non-assignee completing an overdue task claims it (markClaimed);
	 * the undo restores was_claimed/original_assignee/claimed_by_user/
	 * claimed_at — proven by exact state equality including an owner actor
	 * undoing someone else's completion (D3 actor gate on the real DB).
	 */
	public function testTakeoverCompletion_UndoRestoresClaimFields_AndExactState(): void {
		$ids = $this->seedPermutationFixture([]);

		// Overdue: the recorded claim precondition for a non-assignee.
		$yesterday = (new \DateTime('-1 day'))->format('Y-m-d H:i:s');
		$qb = $this->db->getQueryBuilder();
		$qb->update('chorestrike_room_tasks')
			->set('next_due', $qb->createNamedParameter($yesterday))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($ids['roomTaskId'], IQueryBuilder::PARAM_INT)))
			->executeStatement();

		$completionService = \OC::$server->get(CompletionService::class);
		$owner = $this->houseUser('ueq_owner', $ids['houseId']);

		$before = $this->snapshotDomainState($ids['houseId'], $ids['roomId'], $ids['roomTaskId']);
		self::assertSame(0, (int)$before['roomTask']['was_claimed']);

		$completionService->complete($ids['houseId'], 'ueq_helper', $ids['roomTaskId']);
		$this->ledgerRoomTaskIds[] = $ids['roomTaskId'];

		$afterCompletion = $this->snapshotDomainState($ids['houseId'], $ids['roomId'], $ids['roomTaskId']);
		self::assertSame(1, (int)$afterCompletion['roomTask']['was_claimed'], 'the overdue takeover must be recorded');
		self::assertSame('ueq_owner', $afterCompletion['roomTask']['original_assignee']);
		self::assertSame('ueq_helper', $afterCompletion['roomTask']['claimed_by_user']);
		self::assertNotNull($afterCompletion['roomTask']['claimed_at']);

		// The OWNER undoes the helper's completion (completer-or-owner, D3).
		$completionService->undo($ids['houseId'], $owner, $ids['roomTaskId']);

		$afterUndo = $this->snapshotDomainState($ids['houseId'], $ids['roomId'], $ids['roomTaskId']);
		self::assertSame($before['roomTask'], $afterUndo['roomTask'], 'claim fields and recurrence must be exactly restored');
		self::assertSame($before['users'], $afterUndo['users'], 'the HELPER\'s rewards must be restored on the helper\'s row');
		self::assertSame($before['room'], $afterUndo['room']);
	}

	/**
	 * CompleteUndoRecompleteUndoRoundtrip_TwoLedgerRows_OneRevertibleAtATime
	 *
	 * The real-DB D11 proof (§4): undo → same-day re-complete is legal (the
	 * duplicate guard filters undone rows), producing a second ledger row
	 * while exactly one row stays revertible at any time; the final undo
	 * restores the initial snapshot exactly.
	 */
	public function testCompleteUndoRecompleteUndoRoundtrip_TwoLedgerRows_OneRevertibleAtATime(): void {
		$ids = $this->seedPermutationFixture([]);
		$this->ledgerRoomTaskIds[] = $ids['roomTaskId'];
		$this->progressRoomTaskIds[] = $ids['roomTaskId'];

		$completionService = \OC::$server->get(CompletionService::class);
		$actor = $this->houseUser('ueq_owner', $ids['houseId']);

		$before = $this->snapshotDomainState($ids['houseId'], $ids['roomId'], $ids['roomTaskId']);

		// 1st occurrence.
		$completionService->complete($ids['houseId'], 'ueq_owner', $ids['roomTaskId']);
		self::assertCount(1, $this->revertibleLedgerRows($ids['roomTaskId']));

		$completionService->undo($ids['houseId'], $actor, $ids['roomTaskId']);
		self::assertSame([], $this->revertibleLedgerRows($ids['roomTaskId']), 'after the undo nothing is revertible');
		self::assertSame($before, $this->snapshotDomainState($ids['houseId'], $ids['roomId'], $ids['roomTaskId']));

		// 2nd occurrence on the SAME day — the D11 duplicate-guard proof:
		// the undone 1st row must not trigger the day-duplicate 403.
		$completionService->complete($ids['houseId'], 'ueq_owner', $ids['roomTaskId']);

		$ledger = $this->ledgerRows($ids['roomTaskId']);
		self::assertCount(2, $ledger, 'the re-completion writes its own fresh ledger row');
		self::assertCount(1, $this->revertibleLedgerRows($ids['roomTaskId']), 'exactly the newest occurrence is revertible');
		self::assertSame(
			$ledger[0]['occurrence_due'],
			$ledger[1]['occurrence_due'],
			'both occurrences record the same house-local occurrence day (the undo restored nextDue)'
		);

		$completionService->undo($ids['houseId'], $actor, $ids['roomTaskId']);

		self::assertSame([], $this->revertibleLedgerRows($ids['roomTaskId']));
		self::assertSame($before, $this->snapshotDomainState($ids['houseId'], $ids['roomId'], $ids['roomTaskId']), 'the roundtrip must land on the exact initial state');
	}

	/**
	 * RewardSideEquality_TitleUnlocksRemovedByTheirOwnUndo_LaterStateSurvives
	 *
	 * Titles unlocked by occurrence 1 (contribution ≥ the home_helpers tier
	 * 1 threshold) are removed only by occurrence 1's undo; occurrence 2's
	 * undo (with no own unlock) must not touch them (LIFO/D8), and after
	 * both undos the completer's reward row equals the initial snapshot.
	 */
	public function testRewardSideEquality_TitleUnlocksRemovedByTheirOwnUndo_LaterStateSurvives(): void {
		$ids = $this->seedPermutationFixture([]);
		$this->ledgerRoomTaskIds[] = $ids['roomTaskId'];
		$this->progressRoomTaskIds[] = $ids['roomTaskId'];

		// Task B in the same room (energy 10 — below every title threshold).
		$taskB = $this->createTask($ids['houseId'], 'UEQ small', '', 5, 10);
		$roomTaskB = $this->assignTaskToRoom($taskB, $ids['roomId'], 'ueq_owner');
		$this->ledgerRoomTaskIds[] = $roomTaskB;
		$this->progressRoomTaskIds[] = $roomTaskB;

		$completionService = $this->titleWiredCompletionService();
		$actor = $this->houseUser('ueq_owner', $ids['houseId']);

		$usersBefore = $this->snapshotDomainState($ids['houseId'], $ids['roomId'], $ids['roomTaskId'])['users'];

		// Occurrence 1 (energy 60 → contribution 60 ≥ threshold 50): unlocks
		// the home_helpers.helper tier. The unlock is effect-record-visible
		// in the ledger deltas (it is not part of the API reward payload).
		$completionService->complete($ids['houseId'], 'ueq_owner', $ids['roomTaskId']);
		$firstDeltas = json_decode(
			(string)$this->ledgerRows($ids['roomTaskId'])[0]['deltas'],
			true,
			512,
			JSON_THROW_ON_ERROR
		);
		self::assertSame(
			['home_helpers.helper', 'cozy_home.cozy_buddy'],
			$firstDeltas['titlesAdded'],
			'the 60-energy completion must cross the contribution (≥50) and coins (≥500) tier-1 thresholds'
		);

		// Occurrence 2: no new unlock.
		$completionService->complete($ids['houseId'], 'ueq_owner', $roomTaskB);
		$secondDeltas = json_decode(
			(string)$this->ledgerRows($roomTaskB)[0]['deltas'],
			true,
			512,
			JSON_THROW_ON_ERROR
		);
		self::assertSame([], $secondDeltas['titlesAdded']);

		$afterBoth = $this->houseUser('ueq_owner', $ids['houseId']);
		self::assertContains('home_helpers.helper', $afterBoth->getUnlockedTitles());
		self::assertContains('cozy_home.cozy_buddy', $afterBoth->getUnlockedTitles());

		// Undo occurrence 2 (LIFO): the occurrence-1 titles must survive.
		$completionService->undo($ids['houseId'], $actor, $roomTaskB);

		$afterSecondUndo = $this->houseUser('ueq_owner', $ids['houseId']);
		self::assertContains('home_helpers.helper', $afterSecondUndo->getUnlockedTitles(), 'a title added by an EARLIER occurrence must survive a later undo');
		self::assertContains('cozy_home.cozy_buddy', $afterSecondUndo->getUnlockedTitles());

		// Undo occurrence 1: now its own title is removed.
		$completionService->undo($ids['houseId'], $actor, $ids['roomTaskId']);

		$usersAfter = $this->snapshotDomainState($ids['houseId'], $ids['roomId'], $ids['roomTaskId'])['users'];
		self::assertSame($usersBefore, $usersAfter, 'after both undos the reward rows equal the initial snapshot exactly');
		self::assertNotContains('home_helpers.helper', $this->houseUser('ueq_owner', $ids['houseId'])->getUnlockedTitles());
	}

	// ========== Helpers ==========

	/**
	 * Seed house/floor/room/task/room-task/checklist; the task definition is
	 * updated with the permutation columns. The owner owns an assigned,
	 * CHECKED checklist row; a second member exists for the takeover test.
	 *
	 * @param array<string, mixed> $taskColumns
	 * @return array{houseId: int, floorId: int, roomId: int, taskId: int, roomTaskId: int}
	 */
	private function seedPermutationFixture(array $taskColumns): array {
		$houseId = $this->createHouse('ueq_owner', 'UEQ House');
		$this->addHouseMember($houseId, 'ueq_helper', 'Helper', 'member');
		$floorId = $this->createFloor($houseId, 'Ground Floor', 1);
		$roomId = $this->createRoom($houseId, $floorId, 'Kitchen');
		$taskId = $this->createTask($houseId, 'UEQ permuted', '', 5, 60);

		if ($taskColumns !== []) {
			$qb = $this->db->getQueryBuilder();
			$update = $qb->update('chorestrike_tasks');
			foreach ($taskColumns as $column => $value) {
				$update->set($column, $qb->createNamedParameter($value));
			}
			$update->where($qb->expr()->eq('id', $qb->createNamedParameter($taskId, IQueryBuilder::PARAM_INT)))
				->executeStatement();
		}

		$roomTaskId = $this->assignTaskToRoom($taskId, $roomId, 'ueq_owner');
		$this->progressRoomTaskIds[] = $roomTaskId;

		// One checklist item with a CHECKED progress row owned by the owner —
		// the occurrence-scoped reset/restore subject (decision D6).
		$qb = $this->db->getQueryBuilder();
		$qb->insert('chorestrike_chk_items')
			->values([
				'task_id' => $qb->createNamedParameter($taskId, IQueryBuilder::PARAM_INT),
				'label' => $qb->createNamedParameter('Step 1'),
				'sort_order' => $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT),
				'created_at' => $qb->createNamedParameter(date('Y-m-d H:i:s')),
			])
			->executeStatement();
		$checklistItemId = (int)$this->db->lastInsertId('chorestrike_chk_items');

		$owner = $this->houseUser('ueq_owner', $houseId);
		$qb = $this->db->getQueryBuilder();
		$qb->insert('chorestrike_chk_progress')
			->values([
				'room_task_id' => $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT),
				'checklist_item_id' => $qb->createNamedParameter($checklistItemId, IQueryBuilder::PARAM_INT),
				'is_checked' => $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL),
				'checked_at' => $qb->createNamedParameter('2026-09-08 10:00:00'),
				'checked_by_user_id' => $qb->createNamedParameter($owner->getId(), IQueryBuilder::PARAM_INT),
			])
			->executeStatement();

		return [
			'houseId' => $houseId,
			'floorId' => $floorId,
			'roomId' => $roomId,
			'taskId' => $taskId,
			'roomTaskId' => $roomTaskId,
		];
	}

	/**
	 * A CompletionService whose GameLogicService definitely carries the
	 * TitleService (unlock detection is the subject of the LIFO test and
	 * must not depend on optional-injection resolution).
	 */
	private function titleWiredCompletionService(): CompletionService {
		$s = \OC::$server;

		return new CompletionService(
			$s->get(TransactionExecutor::class),
			$s->get(RoomTaskMapper::class),
			$s->get(TaskMapper::class),
			$s->get(RoomMapper::class),
			$s->get(HouseMapper::class),
			$s->get(HouseUserMapper::class),
			$s->get(TaskAssigneeMapper::class),
			$s->get(CompletedTaskMapper::class),
			$s->get(RoomTaskChecklistProgressMapper::class),
			$s->get(\OCA\ChoreStrike\Service\CompletionEffectService::class),
			$s->get(\OCA\ChoreStrike\Service\CompletionPostCommitService::class),
			new GameLogicService(
				$s->get(HouseUserMapper::class),
				$s->get(CompletedTaskMapper::class),
				$s->get(RewardConfigService::class),
				$s->get(TitleService::class),
			),
			$s->get(CalendarService::class),
			$s->get(TaskAccessPolicy::class),
			$s->get(\OCP\L10N\IFactory::class)->get('chorestrike'),
			$s->get(AppLogger::class),
		);
	}

	/**
	 * The house_user row of a Nextcloud user in the house.
	 */
	private function houseUser(string $userId, int $houseId): \OCA\ChoreStrike\Db\HouseUser {
		return \OC::$server->get(HouseUserMapper::class)->findByNextcloudUserId($houseId, $userId);
	}

	/**
	 * Raw-row snapshot of every persisted domain row the completion/undo
	 * aggregate touches. chk_progress rows are compared semantically (the
	 * undo re-inserts them, so the auto id differs by design).
	 *
	 * @return array{roomTask: array<string, mixed>, room: array<string, mixed>, users: array<int, array<string, mixed>>, progress: array<int, array<string, mixed>>}
	 */
	private function snapshotDomainState(int $houseId, int $roomId, int $roomTaskId): array {
		return [
			'roomTask' => $this->fetchRow('chorestrike_room_tasks', 'id', $roomTaskId),
			'room' => $this->fetchRow('chorestrike_rooms', 'id', $roomId),
			'users' => $this->fetchRowsBy('chorestrike_house_users', 'house_id', $houseId),
			'progress' => $this->normalizeProgressRows(
				$this->fetchRowsBy('chorestrike_chk_progress', 'room_task_id', $roomTaskId)
			),
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function fetchRow(string $table, string $column, int $id): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($table)
			->where($qb->expr()->eq($column, $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		self::assertIsArray($row, "$table row #$id must exist");
		return $row;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function fetchRowsBy(string $table, string $column, int $value): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($table)
			->where($qb->expr()->eq($column, $qb->createNamedParameter($value, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC');
		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();

		return $rows;
	}

	/**
	 * @param array<int, array<string, mixed>> $rows
	 * @return array<int, array<string, mixed>>
	 */
	private function normalizeProgressRows(array $rows): array {
		$normalized = array_map(
			static function (array $row): array {
				unset($row['id']);
				return $row;
			},
			$rows
		);
		usort($normalized, static fn (array $a, array $b) => $a['checklist_item_id'] <=> $b['checklist_item_id']);
		return $normalized;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function ledgerRows(int $roomTaskId): array {
		return $this->fetchRowsBy('chorestrike_completed_tasks', 'room_task_id', $roomTaskId);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function revertibleLedgerRows(int $roomTaskId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('chorestrike_completed_tasks')
			->where($qb->expr()->eq('room_task_id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('undone_at'));
		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();

		return $rows;
	}
}
