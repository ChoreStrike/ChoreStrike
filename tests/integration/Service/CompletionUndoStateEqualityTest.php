<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Service;

use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Service\CompletionService;
use OCA\ChoreStrike\Service\TitleService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * WP5 exit gate (CS-PLAN-064 Â§4): a full completion â†’ undo roundtrip must
 * restore EXACT persisted domain state across every persisted row the
 * completion touches ” room_tasks, rooms, house_users and chk_progress ”
 * for the recurrence permutations (once / recurring-dynamic / recurring-
 * static / recurring-flex), an overdue-takeover completion (claim fields),
 * and the D11 duplicate-guard roundtrip (complete â†’ undo â†’ same-day
 * re-complete â†’ undo leaves two ledger rows, none revertible, final state
 * equal to the initial snapshot).
 *
 * The reward-side equality additionally proves the title LIFO interplay:
 * an undo removes only the title ids its OWN occurrence added ” a title
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
	 * the undo (the occurrence-scoped checklist evidence survives both legs
	 * untouched, and the ledger carries exactly the completed occurrence,
	 * marked undone).
	 *
	 * @dataProvider recurrencePermutations
	 * @param array<string, mixed> $taskColumns
	 */
	public function testCompleteThenUndo_RestoresExactPersistedState(array $taskColumns): void {
		$ids = $this->seedPermutationFixture($taskColumns);

		$completionService = \OC::$server->get(CompletionService::class);
		$actor = $this->houseUser('ueq_owner', $ids['houseId']);

		$before = $this->snapshotDomainState($ids['houseId'], $ids['roomId'], $ids['roomTaskId']);

		$this->completeOccurrence($completionService, $ids['houseId'], 'ueq_owner', $ids['roomTaskId']);
		$this->ledgerRoomTaskIds[] = $ids['roomTaskId'];

		$afterCompletion = $this->snapshotDomainState($ids['houseId'], $ids['roomId'], $ids['roomTaskId']);

		// Sanity: the completion actually mutated the domain rows (the
		// recurrence advance happened; for `once` the row deactivated).
		// CS-PLAN-072 WP1 occurrence-scoped checklist: the fulfilled
		// occurrence KEEPS its checked evidence ” completion no longer
		// resets progress; the next occurrence derives fresh state.
		self::assertNotSame($before['roomTask'], $afterCompletion['roomTask'], 'completion must advance the recurrence state');
		self::assertSame($before['progress'], $afterCompletion['progress'], 'completion must not touch the fulfilled occurrence\'s checklist evidence');
		$ledger = $this->ledgerRows($ids['roomTaskId']);
		self::assertCount(1, $ledger);
		self::assertNull($ledger[0]['undone_at'], 'a fresh completion is revertible');
		self::assertNotNull($ledger[0]['occurrence_due'], 'the ledger row records occurrence_due');
		// WP3 §3.1: the evidence row carries frozen work facts, not a
		// serialized effect blob.
		self::assertGreaterThan(0, (int)$ledger[0]['total_energy']);
		self::assertSame(1000, (int)$ledger[0]['rate_minor']);
		self::assertArrayNotHasKey('before_state', $ledger[0]);
		self::assertArrayNotHasKey('deltas', $ledger[0]);

		$this->undoLatestForRoomTask($completionService, $ids['houseId'], 'ueq_owner', $ids['roomTaskId']);

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
	 * TakeoverCompletion_WithExplicitSplit_UndoRestoresClaimFieldsAndState
	 *
	 * A non-assignee completing an overdue task claims it (markClaimed,
	 * retained through the WP3 cutover); under Q-074-02 the non-assignee
	 * must take part explicitly — the split names the helper. The owner's
	 * targeted undo restores the claim fields and the exact state (D3
	 * actor gate on the real DB).
	 */
	public function testTakeoverCompletion_UndoRestoresClaimFields_AndExactState(): void {
		// Overdue: the recorded claim precondition for a non-assignee.
		// CS-PLAN-072 WP1: the anchor is seeded BEFORE the schedule exists —
		// a raw next_due move afterwards would diverge from the timeline
		// (the undo re-derives the projection from the occurrences).
		$yesterday = (new \DateTime('-1 day'))->format('Y-m-d 00:00:00');
		$ids = $this->seedPermutationFixture([], $yesterday);

		$completionService = \OC::$server->get(CompletionService::class);

		$before = $this->snapshotDomainState($ids['houseId'], $ids['roomId'], $ids['roomTaskId']);
		self::assertSame(0, (int)$before['roomTask']['was_claimed']);

		// Participant-less: forbidden (the completer must take part).
		try {
			$this->completeOccurrence($completionService, $ids['houseId'], 'ueq_helper', $ids['roomTaskId']);
			self::fail('a non-participant must not complete participant-less');
		} catch (\OCA\ChoreStrike\Exception\ForbiddenException $e) {
			self::assertSame('The completer must take part in the work', $e->getMessage());
		}

		// Explicit split (the helper takes the work): claims and completes.
		$this->completeOccurrenceWithParticipants(
			$completionService,
			$ids['houseId'],
			'ueq_helper',
			$ids['roomTaskId'],
			[['userId' => 'ueq_helper', 'sharePct' => 100]]
		);
		$this->ledgerRoomTaskIds[] = $ids['roomTaskId'];

		$afterCompletion = $this->snapshotDomainState($ids['houseId'], $ids['roomId'], $ids['roomTaskId']);
		self::assertSame(1, (int)$afterCompletion['roomTask']['was_claimed'], 'the overdue takeover must be recorded');
		self::assertSame('ueq_owner', $afterCompletion['roomTask']['original_assignee']);
		self::assertSame('ueq_helper', $afterCompletion['roomTask']['claimed_by_user']);
		self::assertNotNull($afterCompletion['roomTask']['claimed_at']);
		self::assertSame((int)$this->ledgerRows($ids['roomTaskId'])[0]['id'], (int)$afterCompletion['roomTask']['claimed_source_completion_id']);

		// The OWNER undoes the helper's completion (completer-or-owner, D3).
		$this->undoLatestForRoomTask($completionService, $ids['houseId'], 'ueq_owner', $ids['roomTaskId']);

		$afterUndo = $this->snapshotDomainState($ids['houseId'], $ids['roomId'], $ids['roomTaskId']);
		self::assertSame($before['roomTask'], $afterUndo['roomTask'], 'claim fields and recurrence must be exactly restored');
		self::assertSame($before['users'], $afterUndo['users'], 'the HELPER\'s rewards must be restored on the helper\'s row');
		self::assertSame($before['room'], $afterUndo['room']);
	}

	/**
	 * A later claim by the SAME NC account, even in the same stored second,
	 * belongs to its own completion and must survive an older targeted undo.
	 */
	public function testUndo_OlderSameUserClaim_PreservesLaterCompletionClaim(): void {
		$ids = $this->seedPermutationFixture([], (new \DateTime('-1 day'))->format('Y-m-d 00:00:00'));
		$this->ledgerRoomTaskIds[] = $ids['roomTaskId'];
		$service = \OC::$server->get(CompletionService::class);
		$this->completeOccurrenceWithParticipants(
			$service, $ids['houseId'], 'ueq_helper', $ids['roomTaskId'],
			[['userId' => 'ueq_helper', 'sharePct' => 100]]
		);
		$first = $this->ledgerRows($ids['roomTaskId'])[0];
		$laterId = $this->insertCompletionRow(
			$ids['roomTaskId'], (int)$this->houseUser('ueq_helper', $ids['houseId'])->getId(), $first['completed_at']
		);
		$roomTaskMapper = \OC::$server->get(RoomTaskMapper::class);
		$roomTask = $roomTaskMapper->find($ids['roomTaskId']);
		$roomTask->markClaimed('ueq_owner', 'ueq_helper', new \DateTime($first['completed_at']));
		$roomTask->setClaimedSourceCompletionId($laterId);
		$roomTaskMapper->update($roomTask);

		$service->undo($ids['houseId'], $this->houseUser('ueq_owner', $ids['houseId']), (int)$first['id'],
			sprintf('c7c8d9e0-f1a2-4b3c-9d8e-%012d', random_int(0, 999999999999)), 0, null);

		$after = $roomTaskMapper->find($ids['roomTaskId']);
		self::assertTrue($after->getWasClaimed());
		self::assertSame('ueq_helper', $after->getClaimedByUser());
		self::assertSame($laterId, $after->getClaimedSourceCompletionId());
	}

	/**
	 * CompleteUndoRecompleteUndoRoundtrip_TwoLedgerRows_OneRevertibleAtATime
	 *
	 * The real-DB D11 proof (Â§4): undo â†’ same-day re-complete is legal (the
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
		$this->completeOccurrence($completionService, $ids['houseId'], 'ueq_owner', $ids['roomTaskId']);
		self::assertCount(1, $this->revertibleLedgerRows($ids['roomTaskId']));

		$this->undoLatestForRoomTask($completionService, $ids['houseId'], 'ueq_owner', $ids['roomTaskId']);
		self::assertSame([], $this->revertibleLedgerRows($ids['roomTaskId']), 'after the undo nothing is revertible');
		self::assertSame($before, $this->snapshotDomainState($ids['houseId'], $ids['roomId'], $ids['roomTaskId']));

		// 2nd occurrence on the SAME day ” the D11 duplicate-guard proof:
		// the undone 1st row must not trigger the day-duplicate 403.
		$this->completeOccurrence($completionService, $ids['houseId'], 'ueq_owner', $ids['roomTaskId']);

		$ledger = $this->ledgerRows($ids['roomTaskId']);
		self::assertCount(2, $ledger, 'the re-completion writes its own fresh ledger row');
		self::assertCount(1, $this->revertibleLedgerRows($ids['roomTaskId']), 'exactly the newest occurrence is revertible');
		self::assertSame(
			$ledger[0]['occurrence_due'],
			$ledger[1]['occurrence_due'],
			'both occurrences record the same house-local occurrence day (the undo restored nextDue)'
		);

		$this->undoLatestForRoomTask($completionService, $ids['houseId'], 'ueq_owner', $ids['roomTaskId']);

		self::assertSame([], $this->revertibleLedgerRows($ids['roomTaskId']));
		self::assertSame($before, $this->snapshotDomainState($ids['houseId'], $ids['roomId'], $ids['roomTaskId']), 'the roundtrip must land on the exact initial state');
	}

	/**
	 * RewardSideEquality_TitleUnlockReconciledAwayByItsOwnUndo
	 *
	 * WP4 (C3g): titles derive from the member's valid-history rhythm
	 * (fixed 3/6/12 rungs — no config override exists). The unlocked
	 * marker is recomputable cache: the completion's re-evaluation
	 * reconciles a stale cached tier the real history no longer supports,
	 * and the undo restores every persisted column except the re-derived
	 * rhythm stats and the reconciled marker.
	 */
	public function testRewardSideEquality_TitleUnlockReconciledAwayByItsOwnUndo(): void {
		$ids = $this->seedPermutationFixture([]);
		$this->ledgerRoomTaskIds[] = $ids['roomTaskId'];
		$this->progressRoomTaskIds[] = $ids['roomTaskId'];

		// Seed a STALE marker + stats cache the real history cannot support
		// (no valid occurrences exist yet): the completion aggregate must
		// recompute both from real facts.
		$memberId = $this->houseUser('ueq_owner', $ids['houseId'])->getId();
		$qb = $this->db->getQueryBuilder();
		$qb->update('chorestrike_house_users')
			->set('longest_streak', $qb->createNamedParameter(3, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
			->set('unlocked_titles', $qb->createNamedParameter('["family_rhythm.rhythm_3"]'))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($memberId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
			->executeStatement();

		$completionService = $this->titleWiredCompletionService();

		$before = $this->snapshotDomainState($ids['houseId'], $ids['roomId'], $ids['roomTaskId'])['users'];
		self::assertContains('family_rhythm.rhythm_3', $this->houseUser('ueq_owner', $ids['houseId'])->getUnlockedTitles());

		// The completion re-evaluates the valid history (one success →
		// maximum 1 < 3): the stale cached tier is reconciled away (C3g)
		// and never re-celebrates.
		$this->completeOccurrence($completionService, $ids['houseId'], 'ueq_owner', $ids['roomTaskId']);
		$afterCompletion = $this->houseUser('ueq_owner', $ids['houseId']);
		self::assertSame([], $afterCompletion->getUnlockedTitles(), 'the tier the real valid history never reached is reconciled away');
		self::assertSame(1, $afterCompletion->getCurrentStreak());
		self::assertSame(1, $afterCompletion->getLongestStreak());

		// Undo: the re-derived stats fall back to zero; every other
		// persisted column equals the pre-completion snapshot exactly —
		// only the re-derived rhythm facts (current/longest streak) and the
		// reconciled marker differ by design.
		$this->undoLatestForRoomTask($completionService, $ids['houseId'], 'ueq_owner', $ids['roomTaskId']);

		$after = $this->houseUser('ueq_owner', $ids['houseId']);
		self::assertSame([], $after->getUnlockedTitles());
		self::assertSame(0, $after->getCurrentStreak());
		self::assertSame(0, $after->getLongestStreak());

		$stripDerived = static function (array $users): array {
			return array_map(static function (array $user): array {
				unset($user['unlocked_titles'], $user['current_streak'], $user['longest_streak']);
				return $user;
			}, $users);
		};
		self::assertSame($stripDerived($before), $stripDerived($this->snapshotDomainState($ids['houseId'], $ids['roomId'], $ids['roomTaskId'])['users']), 'after the undo every persisted reward column except the re-derived rhythm facts equals the pre-completion state');
	}

	// ========== Helpers ==========

	/**
	 * Seed house/floor/room/task/room-task/checklist; the task definition is
	 * updated with the permutation columns BEFORE the assignment so the
	 * initial schedule revision freezes the permuted inputs. The owner owns
	 * an assigned, CHECKED checklist row keyed to the first occurrence of
	 * the initial revision (CS-PLAN-072 WP1 occurrence scoping); a second
	 * member exists for the takeover test. `$nextDue` seeds the schedule
	 * anchor (the timeline normalizes the row projection to it).
	 *
	 * @param array<string, mixed> $taskColumns
	 * @return array{houseId: int, floorId: int, roomId: int, taskId: int, roomTaskId: int}
	 */
	private function seedPermutationFixture(array $taskColumns, ?string $nextDue = null): array {
		$houseId = $this->createHouse('ueq_owner', 'UEQ House');
		$this->addHouseMember($houseId, 'ueq_helper', 'Helper', 'member');
		$floorId = $this->createFloor($houseId, 'Ground Floor', 1);
		$roomId = $this->createRoom($houseId, $floorId, 'Kitchen');
		$taskId = $this->createTask($houseId, 'UEQ permuted', '', 5, 5);

		if ($taskColumns !== []) {
			$qb = $this->db->getQueryBuilder();
			$update = $qb->update('chorestrike_tasks');
			foreach ($taskColumns as $column => $value) {
				$update->set($column, $qb->createNamedParameter($value));
			}
			$update->where($qb->expr()->eq('id', $qb->createNamedParameter($taskId, IQueryBuilder::PARAM_INT)))
				->executeStatement();
		}

		$roomTaskId = $this->assignTaskToRoom($taskId, $roomId, 'ueq_owner', $nextDue);
		$this->progressRoomTaskIds[] = $roomTaskId;

		// One checklist item with a CHECKED progress row owned by the owner,
		// keyed to the FIRST occurrence of the initial revision ” the
		// occurrence-scoped evidence subject (CS-PLAN-072 WP1: a fulfilled
		// occurrence keeps its checklist state; nothing is reset/restored).
		$scheduleRevId = \OC::$server->get(\OCA\ChoreStrike\Db\ScheduleRevisionMapper::class)
			->findCurrentByRoomTask($roomTaskId)
			->getId();
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
				'schedule_rev_id' => $qb->createNamedParameter($scheduleRevId, IQueryBuilder::PARAM_INT),
				'ordinal' => $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT),
				'revision' => $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT),
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
	 * A CompletionService whose TitleService is definitely wired (unlock
	 * reconciliation is the subject of the LIFO test and must not depend
	 * on optional-injection resolution). WP3: TitleService is a first-class
	 * collaborator — swap it on the DI instance instead of hand-maintaining
	 * the constructor list.
	 */
	private function titleWiredCompletionService(): CompletionService {
		$service = \OC::$server->get(CompletionService::class);
		$prop = (new \ReflectionClass(CompletionService::class))->getProperty('titleService');
		$prop->setAccessible(true);
		$prop->setValue($service, \OC::$server->get(TitleService::class));
		return $service;
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
			// WP3: the wallet binding is durable account identity — the
			// completion lazily ensures it and the undo must NOT unbind.
			// Work state excludes `wallet_id`.
			'users' => array_map(
				static function (array $user): array {
					unset($user['wallet_id']);
					return $user;
				},
				$this->fetchRowsBy('chorestrike_house_users', 'house_id', $houseId)
			),
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
