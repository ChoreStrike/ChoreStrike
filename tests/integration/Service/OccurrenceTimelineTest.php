<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Service;

use OCA\ChoreStrike\Db\Occurrence;
use OCA\ChoreStrike\Db\OccurrenceMapper;
use OCA\ChoreStrike\Db\ScheduleRevisionMapper;
use OCA\ChoreStrike\Service\CompletionService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\OccurrenceService;
use OCA\ChoreStrike\Service\TaskService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * Occurrence timeline integration suite (CS-PLAN-072 WP1; parent
 * CS-PLAN-070 T1-B/T1-C/T1-D/T1-E): durable identity, virtual agreed
 * resolution, deterministic selection, replay authorization, catch-up
 * coverage, revision closure on definition edits and zone changes —
 * all against the real Docker database with the real services.
 */
class OccurrenceTimelineTest extends IntegrationTestCase {
	private OccurrenceService $occurrenceService;
	private OccurrenceMapper $occurrenceMapper;
	private ScheduleRevisionMapper $revisionMapper;
	private CompletionService $completionService;
	private TaskService $taskService;

	protected function setUp(): void {
		parent::setUp();
		$this->occurrenceService = \OC::$server->get(OccurrenceService::class);
		$this->occurrenceMapper = \OC::$server->get(OccurrenceMapper::class);
		$this->revisionMapper = \OC::$server->get(ScheduleRevisionMapper::class);
		$this->completionService = \OC::$server->get(CompletionService::class);
		$this->taskService = \OC::$server->get(TaskService::class);
	}

	/**
	 * Full fixture: house + owner + room + (optional recurrence) task with
	 * an initialized schedule. The optional anchor seeds the room task's
	 * next_due (the schedule's first date).
	 */
	private function fixture(array $taskOverrides = [], ?string $anchor = null): array {
		$houseId = $this->createHouse('ot_owner', 'Occurrence House');
		$this->addHouseMember($houseId, 'ot_helper', 'Helper', 'member');
		$floorId = $this->createFloor($houseId, 'Ground', 1);
		$roomId = $this->createRoom($houseId, $floorId, 'Kitchen');

		$taskId = $this->createTask($houseId, 'Dishes', 'Do the dishes', 3, 2);
		$qb = $this->db->getQueryBuilder();
		$taskOverrides += [
			'frequency_type' => 'daily',
			'frequency_value' => 1,
			'scheduling_mode' => 'dynamic',
			'recurrence_mode' => 'recurring',
		];
		foreach ($taskOverrides as $column => $value) {
			$qb->update('chorestrike_tasks')
				->set($column, $qb->createNamedParameter($value))
				->where($qb->expr()->eq('id', $qb->createNamedParameter($taskId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
				->executeStatement();
		}

		$roomTaskId = $this->assignTaskToRoom($taskId, $roomId, 'ot_owner', $anchor);
		return [
			'houseId' => $houseId,
			'roomId' => $roomId,
			'taskId' => $taskId,
			'roomTaskId' => $roomTaskId,
		];
	}

	/**
	 * The next Monday on/after the given date (Y-m-d).
	 */
	private function nextMonday(string $fromDate): string {
		$date = new \DateTime($fromDate);
		while ((int)$date->format('N') !== 1) {
			$date->modify('+1 day');
		}
		return $date->format('Y-m-d');
	}

	/**
	 * T1-B: creation materializes revision 1 + the interval pending at the
	 * anchor; the ledger row carries the frozen occurrence evidence.
	 */
	public function testIntervalCreation_MaterializesOnePending_WithFrozenEvidence(): void {
		$f = $this->fixture();
		$revisions = $this->revisionMapper->findByRoomTask($f['roomTaskId']);
		self::assertCount(1, $revisions);
		self::assertSame(1, $revisions[0]->getRevision());
		self::assertNull($revisions[0]->getClosedAt());
		self::assertSame('interval', $revisions[0]->getCadenceMode());

		$occurrences = $this->occurrenceMapper->findByRoomTask($f['roomTaskId']);
		self::assertCount(1, $occurrences, 'an interval rule owns exactly one pending occurrence at creation');
		self::assertSame(1, $occurrences[0]->getOrdinal());
		self::assertSame(0, $occurrences[0]->getVersion(), 'a merely materialized occurrence keeps version 0');

		// Complete through the receipt contract; the ledger must freeze the
		// public key parts, work date/zone and the 'agreed' classification.
		$result = $this->completeOccurrence($this->completionService, $f['houseId'], 'ot_owner', $f['roomTaskId']);
		$ledgerRows = $this->fetchRows('chorestrike_completed_tasks', ['room_task_id' => $f['roomTaskId']]);
		self::assertCount(1, $ledgerRows);
		self::assertNotNull($ledgerRows[0]['schedule_rev_id']);
		self::assertNotNull($ledgerRows[0]['occurrence_ordinal']);
		self::assertNotNull($ledgerRows[0]['work_date']);
		self::assertSame('UTC', $ledgerRows[0]['work_zone']);
		self::assertSame('agreed', $ledgerRows[0]['completion_class']);
		self::assertGreaterThan(0, $result['completionId']);
	}

	/**
	 * T1-B regression (CS-PLAN-072 WP1): a participant amendment on an
	 * interval task appends a new revision while the OLD revision's
	 * unfulfilled pending survives as the task's sole open obligation. The
	 * batched agreed derivation must NOT fabricate a second virtual pending
	 * for the row-less new revision — that double-counted the survivor in the
	 * calendar/range/selection reads (the scheduling-apply-flow exit journey
	 * saw the task twice on the applied day).
	 */
	public function testParticipantAmendment_DoesNotDuplicateIntervalPending(): void {
		$f = $this->fixture();
		$roomTaskMapper = \OC::$server->get(\OCA\ChoreStrike\Db\RoomTaskMapper::class);

		$before = $this->occurrenceService->agreedOccurrencesForTask($roomTaskMapper->find($f['roomTaskId']), null, null);
		self::assertCount(1, $before, 'an interval rule owns exactly one pending occurrence');

		// Amend the participants (owner -> helper) through the public
		// agreement writer; this closes+appends a revision.
		$actor = \OC::$server->get(\OCA\ChoreStrike\Db\HouseUserMapper::class)
			->findByHouseAndUser($f['houseId'], 'ot_owner');
		\OC::$server->get(\OCA\ChoreStrike\Service\RoomTaskService::class)
			->setAssignees($f['roomTaskId'], ['ot_helper'], $f['houseId'], $actor);

		$after = $this->occurrenceService->agreedOccurrencesForTask($roomTaskMapper->find($f['roomTaskId']), null, null);
		$open = array_values(array_filter(
			$after,
			static fn (array $entry): bool => $entry['occurrence']->getCompletionId() === null
				&& $entry['occurrence']->getCoveredByCompletionId() === null
		));
		self::assertCount(1, $open, 'the participant amendment keeps exactly one open interval pending (no virtual duplicate)');
	}

	/**
	 * T1-B: a fixed rule materializes the full horizon in the saved zone,
	 * and a completion fulfills exactly one occurrence while the rest stay
	 * agreed.
	 */
	public function testFixedRule_MaterializesHorizon_AndCompletionFulfillsOne(): void {
		$today = (new \DateTime())->format('Y-m-d');
		$f = $this->fixture([
			'frequency_type' => 'weekly',
			'scheduling_mode' => 'static',
			'fixed_schedule_days' => json_encode([strtolower((new \DateTime())->format('l'))]),
		], $today . ' 00:00:00');
		$occurrences = $this->occurrenceMapper->findByRoomTask($f['roomTaskId']);
		self::assertGreaterThanOrEqual(1, count($occurrences), 'fixed rules materialize through the horizon');
		foreach ($occurrences as $occurrence) {
			self::assertSame(
				(strtolower((new \DateTime())->format('l'))),
				strtolower($occurrence->getPlannedDate()->format('l')),
				'every occurrence lands on the configured weekday'
			);
		}

		$this->completeOccurrence($this->completionService, $f['houseId'], 'ot_owner', $f['roomTaskId']);

		$after = $this->occurrenceMapper->findByRoomTask($f['roomTaskId']);
		$fulfilled = array_values(array_filter($after, static fn (Occurrence $o): bool => $o->getCompletionId() !== null));
		self::assertCount(1, $fulfilled, 'exactly one occurrence is fulfilled');
	}

	/**
	 * T1-B replay semantics: the same (commandId, payload) returns the
	 * ORIGINAL completion identity with fresh authorized projections and a
	 * replay marker — no second ledger row, no reward repeat. A NEW
	 * command on the fulfilled target conflicts.
	 */
	public function testCommandReplay_ReturnsOriginalIdentity_WithoutRepeatingEffects(): void {
		$f = $this->fixture();
		[$commandId, $occurrenceId, $expectedVersion, $selectionToken] = $this->completionReceiptFor($f['roomTaskId']);

		$first = $this->completionService->complete($f['houseId'], 'ot_owner', $f['roomTaskId'], $commandId, $occurrenceId, $expectedVersion, $selectionToken);
		$replay = $this->completionService->complete($f['houseId'], 'ot_owner', $f['roomTaskId'], $commandId, $occurrenceId, $expectedVersion, $selectionToken);

		self::assertFalse($first['replay']);
		self::assertTrue($replay['replay']);
		self::assertSame($first['completionId'], $replay['completionId'], 'the receipt returns the original completion identity');
		self::assertCount(1, $this->fetchRows('chorestrike_completed_tasks', ['room_task_id' => $f['roomTaskId']]));

		// A new command on the fulfilled target conflicts.
		try {
			$this->completionService->complete($f['houseId'], 'ot_owner', $f['roomTaskId'], self::uuid(), $occurrenceId, $expectedVersion, $selectionToken);
			self::fail('a new command on a fulfilled target must conflict');
		} catch (\OCA\ChoreStrike\Exception\ConflictException $e) {
			self::assertStringContainsString('already completed', $e->getMessage());
		}
	}

	/**
	 * T1-B: stale selection inputs (wrong version, wrong token) conflict
	 * with zero writes.
	 */
	public function testStaleSelectionInputs_ConflictWithZeroWrites(): void {
		$f = $this->fixture();
		[$commandId, $occurrenceId, $expectedVersion, $selectionToken] = $this->completionReceiptFor($f['roomTaskId']);

		try {
			$this->completionService->complete($f['houseId'], 'ot_owner', $f['roomTaskId'], $commandId, $occurrenceId, $expectedVersion + 5, $selectionToken);
			self::fail('a wrong expected version must conflict');
		} catch (\OCA\ChoreStrike\Exception\ConflictException $e) {
			self::assertStringContainsString('changed since', $e->getMessage());
		}
		try {
			$this->completionService->complete($f['houseId'], 'ot_owner', $f['roomTaskId'], self::uuid(), $occurrenceId, $expectedVersion, 'bogus');
			self::fail('a wrong selection token must conflict');
		} catch (\OCA\ChoreStrike\Exception\ConflictException $e) {
			self::assertStringContainsString('changed since', $e->getMessage());
		}

		self::assertSame([], $this->fetchRows('chorestrike_completed_tasks', ['room_task_id' => $f['roomTaskId']]));
	}

	/**
	 * T1-C: undo re-opens the recorded occurrence, deletes the open
	 * interval successor (later valid work survives), and re-completing
	 * the SAME day succeeds with a fresh ledger row.
	 */
	public function testUndo_ReleasesOccurrence_ReDerivesIntervalSuccessor(): void {
		$f = $this->fixture();

		$this->completeOccurrence($this->completionService, $f['houseId'], 'ot_owner', $f['roomTaskId']);
		$afterComplete = $this->occurrenceMapper->findByRoomTask($f['roomTaskId']);
		self::assertCount(2, $afterComplete, 'completion anchored one successor');

		$this->undoLatestForRoomTask($this->completionService, $f['houseId'], 'ot_owner', $f['roomTaskId']);
		$afterUndo = $this->occurrenceMapper->findByRoomTask($f['roomTaskId']);
		self::assertCount(1, $afterUndo, 'the open successor anchored on the undone completion is re-derived away');
		self::assertNull($afterUndo[0]->getCompletionId(), 'the completed occurrence is open again');
		self::assertGreaterThan(0, $afterUndo[0]->getVersion(), 're-opening advances the occurrence version');

		// Re-complete the same day: occurrence validity replaces the
		// per-person/day duplicate guard.
		$this->completeOccurrence($this->completionService, $f['houseId'], 'ot_owner', $f['roomTaskId']);
		self::assertCount(2, $this->fetchRows('chorestrike_completed_tasks', ['room_task_id' => $f['roomTaskId']]));
	}

	/**
	 * T1-C catch-up coverage: completing current work covers the OLDER
	 * missed occurrence (covered_by), never fulfills it; a late completion
	 * of the miss itself is classified 'late'.
	 */
	public function testCatchUpCompletion_CoversOlderMiss_WithoutFulfillingIt(): void {
		$f = $this->fixture();

		// Force a miss: move the pending occurrence's window into the past.
		$occurrences = $this->occurrenceMapper->findByRoomTask($f['roomTaskId']);
		$missed = $occurrences[0];
		$qb = $this->db->getQueryBuilder();
		$qb->update('chorestrike_occurrences')
			->set('planned_date', $qb->createNamedParameter((new \DateTime('-3 days'))->format('Y-m-d')))
			->set('day_start', $qb->createNamedParameter((new \DateTime('-3 days'))->setTime(0, 0)->format('Y-m-d H:i:s')))
			->set('window_start', $qb->createNamedParameter((new \DateTime('-3 days'))->setTime(0, 0)->format('Y-m-d H:i:s')))
			->set('window_end', $qb->createNamedParameter((new \DateTime('-2 days'))->setTime(0, 0)->format('Y-m-d H:i:s')))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($missed->getId(), \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
			->executeStatement();

		// Completing the catch-up card fulfills the miss late.
		$result = $this->completeOccurrence($this->completionService, $f['houseId'], 'ot_owner', $f['roomTaskId']);
		self::assertSame('late', $this->classificationOfCompletion($result['completionId']));

		$after = $this->occurrenceMapper->findByRoomTask($f['roomTaskId']);
		$fulfilled = array_values(array_filter($after, static fn (Occurrence $o): bool => $o->getCompletionId() !== null));
		self::assertCount(1, $fulfilled);
		self::assertSame($missed->getId(), $fulfilled[0]->getId(), 'the missed occurrence itself is fulfilled late');
	}

	/**
	 * T1-D: a cadence edit closes the old revision and appends a new one;
	 * already agreed occurrences keep identity/energy; the new revision
	 * re-anchors beyond the old frontier.
	 */
	public function testDefinitionEdit_ClosesOldRevision_AppendsNew_BeyondOldFrontier(): void {
		$f = $this->fixture();
		$oldRevisions = $this->revisionMapper->findByRoomTask($f['roomTaskId']);
		$oldFrontier = $oldRevisions[0]->getAgreedThrough()->format('Y-m-d');
		$oldOccurrences = $this->occurrenceMapper->findByRoomTask($f['roomTaskId']);
		self::assertNotSame([], $oldOccurrences);

		// Edit a rule-relevant input (cadence change).
		$this->actingAs('ot_owner');
		$this->taskService->updateTask($f['taskId'], ['frequencyType' => 'weekly'], $this->houseUser($f['houseId'], 'ot_owner'));

		$revisions = $this->revisionMapper->findByRoomTask($f['roomTaskId']);
		self::assertCount(2, $revisions);
		self::assertNotNull($revisions[0]->getClosedAt(), 'the old revision is closed');
		self::assertNull($revisions[1]->getClosedAt(), 'the new revision is current');
		self::assertSame(2, $revisions[1]->getRevision());
		self::assertSame(
			$oldFrontier,
			$revisions[1]->getAgreedThrough()->format('Y-m-d'),
			'the new frontier starts at the old one — new agreements are prospective only'
		);

		// Already agreed occurrences retain identity and state.
		foreach ($oldOccurrences as $oldOccurrence) {
			$still = $this->occurrenceMapper->find((int)$oldOccurrence->getId());
			self::assertSame($oldOccurrence->toPublicKey(), $still->toPublicKey());
		}
	}

	/**
	 * T1-D regression (RF-072-WP1-02): a keep-anchor edit (energy) on a
	 * fixed rule closes the old revision and appends a new one with the
	 * ORIGINAL anchor. The new revision must not re-derive the predecessor's
	 * agreed history: a fulfilled planned day would reappear as an
	 * unfulfilled completable twin and reads would show the task twice.
	 */
	public function testKeepAnchorEdit_DoesNotDuplicateAgreedOccurrences(): void {
		$today = (new \DateTime())->format('Y-m-d');
		$f = $this->fixture([
			'frequency_type' => 'weekly',
			'scheduling_mode' => 'static',
			'fixed_schedule_days' => json_encode([strtolower((new \DateTime())->format('l'))]),
		], $today . ' 00:00:00');
		$roomTaskMapper = \OC::$server->get(\OCA\ChoreStrike\Db\RoomTaskMapper::class);

		$this->completeOccurrence($this->completionService, $f['houseId'], 'ot_owner', $f['roomTaskId']);
		$fulfilled = null;
		foreach ($this->occurrenceMapper->findByRoomTask($f['roomTaskId']) as $occurrence) {
			if ($occurrence->getCompletionId() !== null) {
				$fulfilled = $occurrence;
			}
		}
		self::assertNotNull($fulfilled, 'the open occurrence was fulfilled');
		$agreedBefore = $this->occurrenceService->agreedOccurrencesForTask($roomTaskMapper->find($f['roomTaskId']), null, null);

		// Energy is rule-relevant but leaves the cadence shape unchanged —
		// a keep-anchor revision.
		$this->actingAs('ot_owner');
		$this->taskService->updateTask($f['taskId'], ['energyCost' => 5], $this->houseUser($f['houseId'], 'ot_owner'));

		$revisions = $this->revisionMapper->findByRoomTask($f['roomTaskId']);
		self::assertCount(2, $revisions, 'the energy edit closed the old and appended a new revision');
		self::assertSame(
			$revisions[0]->getAnchorDate()->format('Y-m-d'),
			$revisions[1]->getAnchorDate()->format('Y-m-d'),
			'a keep-anchor edit preserves the original anchor'
		);

		$agreedAfter = $this->occurrenceService->agreedOccurrencesForTask($roomTaskMapper->find($f['roomTaskId']), null, null);
		self::assertCount(
			count($agreedBefore),
			$agreedAfter,
			'the keep-anchor revision must not duplicate the predecessor\'s agreed entries'
		);
		$fulfilledDateKey = $fulfilled->getPlannedDate()->format('Y-m-d');
		foreach ($agreedAfter as $entry) {
			$occurrence = $entry['occurrence'];
			if ($occurrence->getPlannedDate()->format('Y-m-d') === $fulfilledDateKey
				&& $occurrence->getCompletionId() === null
				&& $occurrence->getCoveredByCompletionId() === null) {
				self::fail('the fulfilled planned day reappeared as an unfulfilled twin under the new revision');
			}
		}

		// A stale twin key under the new revision must conflict, never
		// materialize a second completable occurrence for the owned date.
		$twinKey = $revisions[1]->getId() . ':' . $fulfilled->getOrdinal();
		try {
			$this->completionService->complete($f['houseId'], 'ot_owner', $f['roomTaskId'], self::uuid(), $twinKey, 0, 'bogus');
			self::fail('a twin key of a predecessor-owned date must conflict');
		} catch (\OCA\ChoreStrike\Exception\ConflictException $e) {
			self::assertStringContainsString('no longer valid', $e->getMessage());
		}
	}

	/**
	 * T1-C regression (RF-072-WP1-03): with default (flex=0) windows the
	 * yesterday miss and today's open occurrence share the boundary instant
	 * (miss.windowEnd == target.windowStart). Completing the current open
	 * target must COVER the adjacent older miss — a window-instant
	 * comparison never covers it, leaving a stale catch-up card that stays
	 * completable for extra coins.
	 */
	public function testCurrentCompletion_CoversAdjacentMiss_ForDefaultFlexWindows(): void {
		$yesterday = (new \DateTime('-1 days'))->format('Y-m-d');
		$f = $this->fixture([
			'frequency_type' => 'daily',
			'scheduling_mode' => 'static',
		], $yesterday . ' 00:00:00');

		$result = $this->completeOccurrence($this->completionService, $f['houseId'], 'ot_owner', $f['roomTaskId']);
		self::assertSame('agreed', $this->classificationOfCompletion($result['completionId']));

		$missed = null;
		$fulfilled = null;
		foreach ($this->occurrenceMapper->findByRoomTask($f['roomTaskId']) as $occurrence) {
			if ($occurrence->getPlannedDate()->format('Y-m-d') === $yesterday) {
				$missed = $occurrence;
			} elseif ($occurrence->getCompletionId() !== null) {
				$fulfilled = $occurrence;
			}
		}
		self::assertNotNull($missed, 'the yesterday occurrence materialized as the miss');
		self::assertNotNull($fulfilled, "today's open occurrence was fulfilled");
		self::assertNull($missed->getCompletionId(), 'the miss is covered, never fulfilled');
		self::assertSame(
			$result['completionId'],
			$missed->getCoveredByCompletionId(),
			'the current work covers the adjacent older miss'
		);

		// With the miss covered and today fulfilled, no late catch-up card
		// remains selectable.
		$roomTaskMapper = \OC::$server->get(\OCA\ChoreStrike\Db\RoomTaskMapper::class);
		$selection = $this->occurrenceService->selectionFor($roomTaskMapper->find($f['roomTaskId']), new \DateTime());
		self::assertTrue(
			$selection === null || $selection['classification'] !== 'late',
			'no stale catch-up card survives the coverage'
		);
	}

	/**
	 * T1-C zone changes: a house-zone edit appends zone-carrying
	 * revisions atomically; existing occurrences keep their saved zone.
	 */
	public function testHouseZoneChange_PropagatesToSchedules_SavedZoneSurvives(): void {
		$f = $this->fixture();
		$occurrences = $this->occurrenceMapper->findByRoomTask($f['roomTaskId']);
		self::assertSame('UTC', $occurrences[0]->getTimezone());

		$houseService = \OC::$server->get(HouseService::class);
		$this->actingAs('ot_owner');
		$houseService->updateHouse($f['houseId'], 'Occurrence House', 'Europe/Berlin');

		$revisions = $this->revisionMapper->findByRoomTask($f['roomTaskId']);
		self::assertCount(2, $revisions, 'the zone edit appended a new revision');
		self::assertSame('Europe/Berlin', $revisions[1]->getTimezone());

		// The already agreed occurrence keeps its saved zone.
		$saved = $this->occurrenceMapper->find((int)$occurrences[0]->getId());
		self::assertSame('UTC', $saved->getTimezone(), 'saved occurrences keep their zone across house-zone edits');
	}

	/**
	 * T1-E: checklist progress is occurrence-scoped — toggling under one
	 * occurrence never leaks into another.
	 */
	public function testChecklistProgress_IsOccurrenceScoped(): void {
		$f = $this->fixture();
		$itemId = $this->createChecklistItem($f['taskId'], 'Rinse');

		$checklistService = \OC::$server->get(\OCA\ChoreStrike\Service\ChecklistService::class);
		$viewer = $this->houseUser($f['houseId'], 'ot_owner');

		$progress = $checklistService->getRoomTaskProgress($f['roomTaskId'], null, $f['houseId'], $viewer);
		$occurrenceId = $progress['occurrence']['occurrenceId'];
		self::assertNotSame('', $occurrenceId);

		$checklistService->toggleItem($f['roomTaskId'], $occurrenceId, $itemId, 0, $f['houseId'], $viewer);
		$rows = $this->fetchRows('chorestrike_chk_progress', ['room_task_id' => $f['roomTaskId']]);
		self::assertCount(1, $rows);
		self::assertSame(1, (int)$rows[0]['is_checked']);

		// Complete + toggle under the NEXT occurrence: a separate row keyed
		// by the new occurrence, the old row untouched. The successor is a
		// FUTURE agreed occurrence (its window has not opened yet) — the
		// canonical selection is null then, so the toggle addresses it by
		// its explicit public key.
		$this->completeOccurrence($this->completionService, $f['houseId'], 'ot_owner', $f['roomTaskId']);
		$successor = null;
		foreach ($this->occurrenceMapper->findByRoomTask($f['roomTaskId']) as $occurrence) {
			if ($occurrence->getCompletionId() === null) {
				$successor = $occurrence;
				break;
			}
		}
		self::assertNotNull($successor, 'completion anchored an open successor');
		$nextOccurrenceId = $successor->toPublicKey();
		self::assertNotSame($occurrenceId, $nextOccurrenceId);
		$checklistService->toggleItem($f['roomTaskId'], $nextOccurrenceId, $itemId, 0, $f['houseId'], $viewer);

		$rows = $this->fetchRows('chorestrike_chk_progress', ['room_task_id' => $f['roomTaskId']]);
		self::assertCount(2, $rows, 'progress rows are scoped per occurrence');
	}

	/**
	 * The timeline job pass: the frontier advances and missing occurrences
	 * materialize idempotently (a repeated run writes nothing new).
	 */
	public function testTimelineJobPass_MaterializesIdempotently(): void {
		$today = (new \DateTime())->format('Y-m-d');
		$f = $this->fixture([
			'frequency_type' => 'weekly',
			'scheduling_mode' => 'static',
			'fixed_schedule_days' => json_encode([strtolower((new \DateTime())->format('l'))]),
		], $today . ' 00:00:00');

		// Regress the frontier to force materialization on the next pass
		// (the per-task reconcile is the same generator the house pass of
		// the timeline job runs per room task).
		$revision = $this->revisionMapper->findCurrentByRoomTask($f['roomTaskId']);
		$regressed = (new \DateTime('-30 days'))->format('Y-m-d');
		$qb = $this->db->getQueryBuilder();
		$qb->update('chorestrike_schedule_revs')
			->set('agreed_through', $qb->createNamedParameter($regressed))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($revision->getId(), \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
			->executeStatement();

		$materialized = $this->occurrenceService->reconcileRoomTask($f['houseId'], $f['roomTaskId'], new \DateTime());
		self::assertGreaterThan(0, $materialized);

		$afterFirst = count($this->occurrenceMapper->findByRoomTask($f['roomTaskId']));
		$second = $this->occurrenceService->reconcileRoomTask($f['houseId'], $f['roomTaskId'], new \DateTime());
		self::assertSame(0, $second, 'a repeated pass writes nothing new');
		self::assertSame($afterFirst, count($this->occurrenceMapper->findByRoomTask($f['roomTaskId'])));
	}

	/**
	 * RF-072-WP1-06 regression, WP3 §3.4 semantics: undo AFTER a
	 * participant amendment releases the completion's occurrence, while
	 * the successor the amendment explicitly agreed on stays PINNED (the
	 * correction never deletes explicit agreement evidence). The released
	 * obligation and the agreed successor are DISTINCT planned days —
	 * no same-day duplicate may remain.
	 */
	public function testUndoAfterParticipantAmendment_ReleasesCrossRevisionSuccessor(): void {
		$f = $this->fixture();

		// Complete: pending fulfilled + open successor anchored on the
		// completion, both under revision 1.
		$this->completeOccurrence($this->completionService, $f['houseId'], 'ot_owner', $f['roomTaskId']);
		self::assertCount(2, $this->occurrenceMapper->findByRoomTask($f['roomTaskId']));

		// Participant amendment: closes revision 1 and appends revision 2
		// while the revision-1 successor stays the open obligation (and
		// carries the recorded agreement → explicit provenance).
		$actor = $this->houseUser($f['houseId'], 'ot_owner');
		\OC::$server->get(\OCA\ChoreStrike\Service\RoomTaskService::class)
			->setAssignees($f['roomTaskId'], ['ot_helper'], $f['houseId'], $actor);
		$revisions = $this->revisionMapper->findByRoomTask($f['roomTaskId']);
		self::assertCount(2, $revisions);
		self::assertNotNull($revisions[0]->getClosedAt());

		// Undo: the fulfilled occurrence is released again; the explicitly
		// agreed successor survives the re-derivation (provenance guard).
		$this->undoLatestForRoomTask($this->completionService, $f['houseId'], 'ot_owner', $f['roomTaskId']);

		$after = $this->occurrenceMapper->findByRoomTask($f['roomTaskId']);
		$open = array_values(array_filter(
			$after,
			static fn (Occurrence $o): bool => $o->getCompletionId() === null && $o->getCoveredByCompletionId() === null
		));
		self::assertCount(2, $open, 'the released obligation reopens while the explicitly agreed successor stays pinned');
		$plannedKeys = array_map(
			static fn (Occurrence $o): string => $o->getPlannedDate()->format('Y-m-d'),
			$open
		);
		self::assertCount(count(array_unique($plannedKeys)), $plannedKeys, 'no same-day duplicate pendings across revision boundaries');
	}

	/**
	 * RF-072-WP1-08 regression: a job pass materializes a large historical
	 * gap only within its per-pass budget — the frontier advances through
	 * what the pass actually reconciled and later passes converge without
	 * re-materializing.
	 */
	public function testReconciliationBudget_ConvergesOverPasses(): void {
		// Static daily rule anchored 400 days ago: creation materializes the
		// full anchorâ†’horizon range (8-day creation horizon semantics kept
		// simple here by the fixture's 7-day window), so simulate a dead
		// cron by keeping only the creation week's rows and freezing the
		// frontier there — exactly the state a stopped job leaves behind.
		$todayKey = (new \DateTime())->format('Y-m-d');
		$anchorKey = (new \DateTime('-400 days'))->format('Y-m-d');
		$f = $this->fixture([
			'frequency_type' => 'daily',
			'scheduling_mode' => 'static',
		], $anchorKey . ' 00:00:00');

		// Shrink to the creation-week state: keep rows of the first 8 days,
		// freeze the frontier at the last kept day.
		$keepThrough = (new \DateTime($anchorKey))->modify('+7 days')->format('Y-m-d');
		$deleteFrom = (new \DateTimeImmutable($anchorKey))->modify('+8 days')->format('Y-m-d');
		$qb = $this->db->getQueryBuilder();
		$qb->delete('chorestrike_occurrences')
			->where($qb->expr()->eq('room_task_id', $qb->createNamedParameter($f['roomTaskId'], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->gte('planned_date', $qb->createNamedParameter($deleteFrom, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR)))
			->executeStatement();
		$revision = $this->revisionMapper->findCurrentByRoomTask($f['roomTaskId']);
		$qb = $this->db->getQueryBuilder();
		$qb->update('chorestrike_schedule_revs')
			->set('agreed_through', $qb->createNamedParameter($keepThrough))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($revision->getId(), \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
			->executeStatement();

		$rowsBefore = count($this->occurrenceMapper->findByRoomTask($f['roomTaskId']));
		self::assertSame(8, $rowsBefore);

		// Pass 1: bounded by the per-pass budget — far fewer than the ~400
		// missing days are materialized, the frontier advances partway.
		$this->occurrenceService->reconcileRoomTask($f['houseId'], $f['roomTaskId'], new \DateTime());
		$afterFirst = $this->occurrenceMapper->findByRoomTask($f['roomTaskId']);
		self::assertLessThan(
			400,
			count($afterFirst) - $rowsBefore,
			'a single pass must not materialize the whole historical gap (budget)'
		);
		self::assertCount(OccurrenceService::JOB_MATERIALIZE_BUDGET + $rowsBefore, $afterFirst);
		$frontierAfterFirst = $this->revisionMapper->findCurrentByRoomTask($f['roomTaskId'])
			->getAgreedThrough()->format('Y-m-d');
		self::assertGreaterThan($keepThrough, $frontierAfterFirst);
		self::assertLessThan($todayKey, $frontierAfterFirst, 'the truncated pass stops before the horizon');

		// Pass 2 converges to the full horizon; pass 3 writes nothing.
		$this->occurrenceService->reconcileRoomTask($f['houseId'], $f['roomTaskId'], new \DateTime());
		$afterSecond = count($this->occurrenceMapper->findByRoomTask($f['roomTaskId']));
		$frontierAfterSecond = $this->revisionMapper->findCurrentByRoomTask($f['roomTaskId'])
			->getAgreedThrough()->format('Y-m-d');
		self::assertGreaterThanOrEqual($todayKey, $frontierAfterSecond);

		$third = $this->occurrenceService->reconcileRoomTask($f['houseId'], $f['roomTaskId'], new \DateTime());
		self::assertSame(0, $third, 'a converged pass writes nothing new');
		self::assertSame($afterSecond, count($this->occurrenceMapper->findByRoomTask($f['roomTaskId'])));
	}

	/**
	 * RF-072-WP1-09: the derived next_due projection follows the earliest
	 * OPEN scheduled occurrence — completion moves it to the successor,
	 * undo restores it.
	 */
	public function testNextDueProjection_TracksEarliestOpenOccurrence(): void {
		$f = $this->fixture();
		$roomTaskMapper = \OC::$server->get(\OCA\ChoreStrike\Db\RoomTaskMapper::class);

		$before = $roomTaskMapper->find($f['roomTaskId'])->getNextDue();
		self::assertNotNull($before);

		$this->completeOccurrence($this->completionService, $f['houseId'], 'ot_owner', $f['roomTaskId']);
		$open = array_values(array_filter(
			$this->occurrenceMapper->findByRoomTask($f['roomTaskId']),
			static fn (Occurrence $o): bool => $o->getCompletionId() === null
		));
		self::assertCount(1, $open);
		self::assertSame(
			$open[0]->getDayStart()->getTimestamp(),
			$roomTaskMapper->find($f['roomTaskId'])->getNextDue()->getTimestamp(),
			'the projection follows the successor occurrence'
		);

		$this->undoLatestForRoomTask($this->completionService, $f['houseId'], 'ot_owner', $f['roomTaskId']);
		self::assertSame(
			$before->getTimestamp(),
			$roomTaskMapper->find($f['roomTaskId'])->getNextDue()->getTimestamp(),
			'the undo restores the projection to the re-opened occurrence'
		);
	}

	/**
	 * RF-072-WP1-14: voluntary rows are neither catch-up misses nor
	 * coverage targets — the catch-up lookup filters by kind and recorded
	 * voluntary work never carries catch-up coverage marks.
	 */
	public function testVoluntaryOccurrence_IsNoCatchUpMiss_AndNeverCovered(): void {
		$f = $this->fixture();

		// Force the pending into an expired window and mark it voluntary
		// (the kind a voluntary task's rows carry).
		$pending = $this->occurrenceMapper->findByRoomTask($f['roomTaskId'])[0];
		$qb = $this->db->getQueryBuilder();
		$qb->update('chorestrike_occurrences')
			->set('kind', $qb->createNamedParameter('voluntary'))
			->set('planned_date', $qb->createNamedParameter((new \DateTime('-3 days'))->format('Y-m-d')))
			->set('day_start', $qb->createNamedParameter((new \DateTime('-3 days'))->setTime(0, 0)->format('Y-m-d H:i:s')))
			->set('window_start', $qb->createNamedParameter((new \DateTime('-3 days'))->setTime(0, 0)->format('Y-m-d H:i:s')))
			->set('window_end', $qb->createNamedParameter((new \DateTime('-2 days'))->setTime(0, 0)->format('Y-m-d H:i:s')))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($pending->getId(), \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
			->executeStatement();

		// (a) The catch-up lookup ignores voluntary rows entirely.
		self::assertNull(
			$this->occurrenceMapper->findLatestMissedByRoomTask($f['roomTaskId'], new \DateTime()),
			'an expired voluntary row is never the catch-up target'
		);

		// (b) The selection classifies it as recordable voluntary work, not
		// as a late catch-up obligation.
		$roomTaskMapper = \OC::$server->get(\OCA\ChoreStrike\Db\RoomTaskMapper::class);
		$selection = $this->occurrenceService->selectionFor($roomTaskMapper->find($f['roomTaskId']), new \DateTime());
		self::assertNotNull($selection);
		self::assertSame('voluntary', $selection['classification']);

		// (c) Recording it fulfills the voluntary occurrence itself and
		// never writes catch-up coverage provenance.
		$result = $this->completeOccurrence($this->completionService, $f['houseId'], 'ot_owner', $f['roomTaskId']);
		self::assertSame('voluntary', $this->classificationOfCompletion($result['completionId']));
		foreach ($this->occurrenceMapper->findByRoomTask($f['roomTaskId']) as $occurrence) {
			self::assertNull($occurrence->getCoveredByCompletionId(), 'no occurrence is covered by voluntary work');
		}
	}

	/**
	 * RF-072-WP1-11 regression: the C2h date-amendment gate counts OPEN
	 * occurrences on the target day — including agreed occurrences whose
	 * task's next_due still points at an earlier day (the next_due-equality
	 * count missed those) and never fulfilled rows.
	 */
	public function testDateAmendment_ChildCapacity_CountsOpenOccurrencesOnTargetDay(): void {
		$houseId = $this->createHouse('ot_cap_owner', 'Capacity House');
		$this->addHouseMember($houseId, 'ot_kid', 'Kid', 'child');

		// Child membership: wallet identity + a maxDailyTasks=2 limit.
		$walletMapper = \OC::$server->get(\OCA\ChoreStrike\Db\WalletMapper::class);
		$wallet = $walletMapper->createOrGet($houseId, 'ot_kid');
		$qb = $this->db->getQueryBuilder();
		$qb->update('chorestrike_house_users')
			->set('wallet_id', $qb->createNamedParameter($wallet->getId(), \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
			->set('scheduling_preferences', $qb->createNamedParameter(json_encode(['maxDailyTasks' => 2])))
			->where($qb->expr()->eq('nextcloud_user_id', $qb->createNamedParameter('ot_kid')))
			->executeStatement();

		$floorId = $this->createFloor($houseId, 'Ground', 1);
		$roomId = $this->createRoom($houseId, $floorId, 'Kitchen');
		$todayKey = (new \DateTime())->format('Y-m-d');
		$tomorrowKey = (new \DateTime('+1 day'))->format('Y-m-d');

		// A1: static daily — open occurrences on EVERY horizon day, with
		// next_due pinned to TODAY (its earliest open occurrence).
		$a1Task = $this->createTask($houseId, 'A1', '', 1, 1);
		$qb = $this->db->getQueryBuilder();
		$qb->update('chorestrike_tasks')
			->set('frequency_type', $qb->createNamedParameter('daily'))
			->set('frequency_value', $qb->createNamedParameter(1))
			->set('scheduling_mode', $qb->createNamedParameter('static'))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($a1Task, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
			->executeStatement();
		$a1 = $this->assignTaskToRoom($a1Task, $roomId, 'ot_cap_owner', $todayKey . ' 00:00:00');

		// A2: interval — its single pending is moved to an open
		// tomorrow window.
		$a2Task = $this->createTask($houseId, 'A2', '', 1, 1);
		$a2 = $this->assignTaskToRoom($a2Task, $roomId, 'ot_cap_owner');
		$qb = $this->db->getQueryBuilder();
		$qb->update('chorestrike_occurrences')
			->set('planned_date', $qb->createNamedParameter($tomorrowKey))
			->set('day_start', $qb->createNamedParameter((new \DateTime($tomorrowKey))->setTime(0, 0)->format('Y-m-d H:i:s')))
			->set('window_start', $qb->createNamedParameter((new \DateTime($tomorrowKey))->setTime(0, 0)->format('Y-m-d H:i:s')))
			->set('window_end', $qb->createNamedParameter((new \DateTime('+2 days'))->setTime(0, 0)->format('Y-m-d H:i:s')))
			->where($qb->expr()->eq('room_task_id', $qb->createNamedParameter($a2, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
			->executeStatement();

		// Assign kid to both — A1's open tomorrow occurrence and A2's
		// open tomorrow occurrence put the child's day load at 2.
		$actor = $this->houseUser($houseId, 'ot_cap_owner');
		$roomTaskService = \OC::$server->get(\OCA\ChoreStrike\Service\RoomTaskService::class);
		$roomTaskService->setAssignees($a1, ['ot_kid'], $houseId, $actor);
		$roomTaskService->setAssignees($a2, ['ot_kid'], $houseId, $actor);

		// B: a third task for the owner — moving it to tomorrow would push
		// the child to load 2 + 1 > 2. Both open occurrences of A1/A2 count
		// against tomorrow even though A1's next_due says today.
		$bTask = $this->createTask($houseId, 'B', '', 1, 1);
		$b = $this->assignTaskToRoom($bTask, $roomId, 'ot_cap_owner');
		$roomTaskService->setAssignees($b, ['ot_kid'], $houseId, $actor);

		try {
			$roomTaskService->updateDueDate($b, $tomorrowKey, $actor);
			self::fail('moving B onto the full day must conflict');
		} catch (\OCA\ChoreStrike\Exception\ConflictException $e) {
			self::assertStringContainsString('daily task limit', $e->getMessage());
		}

		// The same move succeeds once the child carries fewer open
		// occurrences on that day (A2's pending fulfilled â†’ open load 1).
		$openA2 = array_values(array_filter(
			$this->occurrenceMapper->findByRoomTask($a2),
			static fn (Occurrence $o): bool => $o->getCompletionId() === null && $o->getCoveredByCompletionId() === null
		));
		$qb = $this->db->getQueryBuilder();
		$qb->update('chorestrike_occurrences')
			->set('completion_id', $qb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($openA2[0]->getId(), \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
			->executeStatement();
		$roomTaskService->updateDueDate($b, $tomorrowKey, $actor);
		self::assertSame(
			$tomorrowKey,
			$this->occurrenceMapper->findByRoomTask($b)[0]->getPlannedDate()->format('Y-m-d'),
			'the amendment lands once the open occurrence load fits the limit'
		);
	}

	// ------------------------------------------------------------------
	// CS-PLAN-073 WP2 fix round: agreement command regressions
	// (RF-073-WP2-01/02/04/05/07/19/20/22)
	// ------------------------------------------------------------------

	private function agreementService(): \OCA\ChoreStrike\Service\AgreementService {
		return \OC::$server->get(\OCA\ChoreStrike\Service\AgreementService::class);
	}

	/**
	 * Low-energy agreement fixture: owner + adult helper with bound
	 * wallets, one interval task (energy 2 — handover/share amendments fit
	 * the default daily budget of 10).
	 *
	 * @return array{houseId: int, roomTaskId: int, taskId: int, owner: string, helper: string}
	 */
	private function agreementFixture(array $taskOverrides = [], ?string $anchor = null, string $suffix = ''): array {
		$owner = 'agr_owner' . $suffix;
		$helper = 'agr_helper' . $suffix;
		$houseId = $this->createHouse($owner, 'Agreement House');
		$this->addHouseMember($houseId, $helper, 'Helper', 'member');
		$houseUserMapper = \OC::$server->get(\OCA\ChoreStrike\Db\HouseUserMapper::class);
		$walletService = \OC::$server->get(\OCA\ChoreStrike\Service\WalletService::class);
		$walletService->ensureBinding($houseId, $houseUserMapper->findByNextcloudUserId($houseId, $owner));
		$walletService->ensureBinding($houseId, $houseUserMapper->findByNextcloudUserId($houseId, $helper));

		$floorId = $this->createFloor($houseId, 'Ground', 1);
		$roomId = $this->createRoom($houseId, $floorId, 'Kitchen');
		$taskId = $this->createTask($houseId, 'Dishes', '', 5, 2);
		$qb = $this->db->getQueryBuilder();
		$taskOverrides += [
			'frequency_type' => 'daily',
			'frequency_value' => 1,
			'scheduling_mode' => 'dynamic',
			'recurrence_mode' => 'recurring',
		];
		foreach ($taskOverrides as $column => $value) {
			$qb->update('chorestrike_tasks')
				->set($column, $qb->createNamedParameter($value))
				->where($qb->expr()->eq('id', $qb->createNamedParameter($taskId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
				->executeStatement();
		}
		$roomTaskId = $this->assignTaskToRoom($taskId, $roomId, $owner, $anchor);
		return ['houseId' => $houseId, 'roomTaskId' => $roomTaskId, 'taskId' => $taskId, 'owner' => $owner, 'helper' => $helper];
	}

	/**
	 * The canonical selection receipt of a room task (the same material a
	 * rendered occurrence block hands the client).
	 *
	 * @return array{occurrenceId: string, expectedVersion: int, selectionToken: string, classification: string}
	 */
	private function agreementReceipt(int $houseId, int $roomTaskId): array {
		$roomTask = \OC::$server->get(\OCA\ChoreStrike\Db\RoomTaskMapper::class)->find($roomTaskId);
		$selection = $this->occurrenceService->selectionFor($roomTask, new \DateTime(), $houseId);
		self::assertNotNull($selection, 'the fixture must expose a canonical selection target');
		return [
			'occurrenceId' => $selection['occurrence']->toPublicKey(),
			'expectedVersion' => $selection['occurrence']->getVersion(),
			'selectionToken' => $this->occurrenceService->selectionToken(
				$selection['occurrence'],
				$selection['revision'],
				$selection['classification'],
				$houseId,
				null,
				$selection['workAppointment']
			),
			'classification' => $selection['classification'],
		];
	}

	private function amendPayload(array $receipt, array $extra = []): array {
		return array_merge([
			'commandId' => self::uuid(),
			'occurrenceId' => $receipt['occurrenceId'],
			'expectedVersion' => $receipt['expectedVersion'],
			'selectionToken' => $receipt['selectionToken'],
			'consent' => true,
		], $extra);
	}

	/**
	 * RF-073-WP2-01: the agreement date command moves EXACTLY the
	 * occurrence resolved from the client's key — never a re-selected
	 * other one (the legacy self-selecting writer would have grabbed the
	 * earliest expired miss and died on its deadline) — and the receipt
	 * refs report the RESULTING version of the moved row.
	 */
	public function testAmendDate_ExplicitTarget_MovesResolvedOccurrenceOnly(): void {
		$todayKey = (new \DateTime())->format('Y-m-d');
		$tomorrowKey = (new \DateTime('+1 day'))->format('Y-m-d');
		$f = $this->agreementFixture([
			'frequency_type' => 'daily',
			'scheduling_mode' => 'static',
		], (new \DateTime('-2 days'))->format('Y-m-d') . ' 00:00:00');

		$receipt = $this->agreementReceipt($f['houseId'], $f['roomTaskId']);
		self::assertSame('agreed', $receipt['classification'], 'the open today occurrence is the canonical target');

		$beforeMisses = array_map(
			static fn (Occurrence $o): array => [$o->getOrdinal(), $o->getPlannedDate()->format('Y-m-d'), $o->getVersion()],
			array_values(array_filter(
				$this->occurrenceMapper->findByRoomTask($f['roomTaskId']),
				static fn (Occurrence $o): bool => $o->getPlannedDate()->format('Y-m-d') < $todayKey
			))
		);
		self::assertNotEmpty($beforeMisses, 'the fixture owns expired misses a self-selecting writer would grab');

		$refs = $this->agreementService()->amend($f['houseId'], $f['owner'], $f['roomTaskId'], $this->amendPayload($receipt, ['date' => $tomorrowKey]));

		self::assertSame($receipt['occurrenceId'], $refs['occurrenceId'], 'the receipt refs report the RESOLVED target, not another occurrence');
		self::assertGreaterThan($receipt['expectedVersion'], $refs['expectedVersion'], 'the refs carry the resulting version after the write');

		$moved = null;
		foreach ($this->occurrenceMapper->findByRoomTask($f['roomTaskId']) as $row) {
			if ($row->toPublicKey() === $receipt['occurrenceId']) {
				$moved = $row;
			}
		}
		self::assertNotNull($moved);
		self::assertSame($tomorrowKey, $moved->getPlannedDate()->format('Y-m-d'), 'exactly the requested occurrence moved');
		self::assertSame($refs['expectedVersion'], $moved->getVersion());

		$afterMisses = array_map(
			static fn (Occurrence $o): array => [$o->getOrdinal(), $o->getPlannedDate()->format('Y-m-d'), $o->getVersion()],
			array_values(array_filter(
				$this->occurrenceMapper->findByRoomTask($f['roomTaskId']),
				static fn (Occurrence $o): bool => $o->getPlannedDate()->format('Y-m-d') < $todayKey
			))
		);
		self::assertSame($beforeMisses, $afterMisses, 'the expired misses are untouched — no other occurrence was amended');
	}

	/**
	 * RF-073-WP2-02/18: a handover PERSISTS the neutral release of the
	 * former responsibility (disposition `released`, obligation dropped,
	 * former role/share snapshot retained) beside full consent evidence —
	 * and a later share-only amendment of the same responsibility records
	 * kind `share`, never `handover`.
	 */
	public function testHandover_PersistsNeutralReleaseAndConsentEvidence(): void {
		$f = $this->agreementFixture();
		$receipt = $this->agreementReceipt($f['houseId'], $f['roomTaskId']);

		$refs = $this->agreementService()->amend($f['houseId'], $f['owner'], $f['roomTaskId'], $this->amendPayload($receipt, [
			'participants' => [
				['userId' => $f['helper'], 'role' => 'responsible', 'sharePct' => 100],
			],
		]));
		self::assertSame($receipt['occurrenceId'], $refs['occurrenceId']);

		$occurrenceId = $this->occurrenceRowIdByKey($f['roomTaskId'], $receipt['occurrenceId']);
		$members = $this->fetchRows('chorestrike_occ_members', ['occurrence_id' => $occurrenceId]);
		self::assertCount(2, $members, 'the former responsible stays visible as a released row');
		$byDisposition = [];
		foreach ($members as $row) {
			$byDisposition[$row['disposition']] = $row;
		}
		self::assertSame('responsible', $byDisposition['active']['role']);
		self::assertSame(100, (int)$byDisposition['active']['share_pct']);
		self::assertSame(1, (int)$byDisposition['active']['obligated']);
		self::assertSame('released', $byDisposition['released']['disposition']);
		self::assertSame(0, (int)$byDisposition['released']['obligated'], 'the release is neutral — no obligation remains');
		self::assertSame('responsible', $byDisposition['released']['role'], 'the former role snapshot is retained');

		$agreements = $this->fetchRows('chorestrike_agreements', ['occurrence_id' => $occurrenceId]);
		$handover = null;
		foreach ($agreements as $row) {
			if ($row['kind'] === 'handover') {
				$handover = $row;
			}
		}
		self::assertNotNull($handover, 'the responsibility transfer records a handover evidence row');
		$consent = json_decode((string)$handover['consent'], true);
		self::assertIsArray($consent, 'the consent evidence is persisted, not dropped');
		self::assertArrayHasKey('attestedBy', $consent);
		self::assertNotEmpty($consent['subjects']);
		$newState = json_decode((string)$handover['new_state'], true);
		self::assertContains((int)$byDisposition['released']['wallet_id'], $newState['released'], 'the released wallet is part of the evidence');
		$oldState = json_decode((string)$handover['old_state'], true);
		self::assertNotEmpty($oldState['members'], 'the former arrangement is preserved in the evidence');

		// A share-only amendment of the SAME responsibility records kind
		// `share` (RF-073-WP2-18: `handover` labels only a real transfer).
		$receipt2 = $this->agreementReceipt($f['houseId'], $f['roomTaskId']);
		$this->agreementService()->amend($f['houseId'], $f['owner'], $f['roomTaskId'], $this->amendPayload($receipt2, [
			'participants' => [
				['userId' => $f['helper'], 'role' => 'responsible', 'sharePct' => 60],
				['userId' => $f['owner'], 'role' => 'joint', 'sharePct' => 40],
			],
		]));
		$agreements = $this->fetchRows('chorestrike_agreements', ['occurrence_id' => $occurrenceId]);
		$kinds = array_map(static fn (array $row): string => (string)$row['kind'], $agreements);
		self::assertSame(1, count(array_filter($kinds, static fn (string $k): bool => $k === 'handover')), 'no second handover for a share change');
		self::assertContains('share', $kinds);
		$members = $this->fetchRows('chorestrike_occ_members', ['occurrence_id' => $occurrenceId]);
		$active = array_values(array_filter($members, static fn (array $row): bool => $row['disposition'] === 'active'));
		self::assertCount(2, $active, 'the re-joining former responsible is active again — one row per wallet');
	}

	public function testHandover_DailyEligibilityUsesConcreteObligationsNotDefaultsOrVoluntaryShares(): void {
		$f = $this->agreementFixture([], (new \DateTime())->format('Y-m-d') . ' 00:00:00');
		$members = \OC::$server->get(\OCA\ChoreStrike\Db\HouseUserMapper::class);
		$child = $members->findByNextcloudUserId($f['houseId'], $f['helper']);
		$child->setRole('child');
		$members->update($child);
		$read = \OC::$server->get(\OCA\ChoreStrike\Service\RoomTaskReadService::class);
		self::assertSame([], $read->getFilteredDailyTasksForUser($f['houseId'], $child, new \DateTime()));

		$receipt = $this->agreementReceipt($f['houseId'], $f['roomTaskId']);
		$this->agreementService()->amend($f['houseId'], $f['owner'], $f['roomTaskId'], $this->amendPayload($receipt, [
			'participants' => [['userId' => $f['helper'], 'role' => 'responsible', 'sharePct' => 100]],
		]));
		$rows = $read->getFilteredDailyTasksForUser($f['houseId'], $child, new \DateTime());
		self::assertCount(1, $rows);
		self::assertSame($f['roomTaskId'], (int)$rows[0]['roomTask']->getId());
		self::assertSame([$f['owner']], $rows[0]['assignees'], 'future defaults stay unchanged');
		self::assertSame([$f['helper']], $rows[0]['proposal']['obligatedUserIds']);
		self::assertSame($receipt['occurrenceId'], $rows[0]['proposal']['occurrenceId']);

		$receipt = $this->agreementReceipt($f['houseId'], $f['roomTaskId']);
		$this->agreementService()->amend($f['houseId'], $f['owner'], $f['roomTaskId'], $this->amendPayload($receipt, [
			'participants' => [
				['userId' => $f['owner'], 'role' => 'responsible', 'sharePct' => 100],
				['userId' => $f['helper'], 'role' => 'helper', 'sharePct' => 50],
			],
		]));
		self::assertSame([], $read->getFilteredDailyTasksForUser($f['houseId'], $child, new \DateTime()), 'voluntary help is not a new obligation');
		$owner = $members->findByNextcloudUserId($f['houseId'], $f['owner']);
		$rows = $read->getFilteredDailyTasksForUser($f['houseId'], $owner, new \DateTime());
		self::assertSame([$f['owner']], $rows[0]['proposal']['obligatedUserIds']);
		self::assertCount(2, $rows[0]['proposal']['participants'], 'actual-work proposal still includes the helper');
	}

	/**
	 * RF-073-WP2-04/20: a catch-up with participants keeps the agreed work
	 * appointment INCLUDING its zone on the replaced rows, retains the
	 * original planned date/window/miss, and the appointment projects into
	 * the selection DTOs even though the house has NO pause at all
	 * (appointment derivation is pause-independent).
	 */
	public function testCatchUp_WithParticipants_KeepsAppointmentIncludingZone(): void {
		$f = $this->agreementFixture();
		$tomorrowKey = (new \DateTime('+1 day'))->format('Y-m-d');

		// Force the pending into an expired window (the catch-up target).
		$pending = $this->occurrenceMapper->findByRoomTask($f['roomTaskId'])[0];
		$qb = $this->db->getQueryBuilder();
		$qb->update('chorestrike_occurrences')
			->set('planned_date', $qb->createNamedParameter((new \DateTime('-2 days'))->format('Y-m-d')))
			->set('day_start', $qb->createNamedParameter((new \DateTime('-2 days'))->setTime(0, 0)->format('Y-m-d H:i:s')))
			->set('window_start', $qb->createNamedParameter((new \DateTime('-2 days'))->setTime(0, 0)->format('Y-m-d H:i:s')))
			->set('window_end', $qb->createNamedParameter((new \DateTime('-1 days'))->setTime(0, 0)->format('Y-m-d H:i:s')))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($pending->getId(), \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
			->executeStatement();

		$receipt = $this->agreementReceipt($f['houseId'], $f['roomTaskId']);
		self::assertSame('late', $receipt['classification'], 'the expired pending is the catch-up target');

		$this->agreementService()->catchUp($f['houseId'], $f['owner'], $f['roomTaskId'], $this->amendPayload($receipt, [
			'workDate' => $tomorrowKey,
			'workZone' => 'Europe/Berlin',
			'participants' => [
				['userId' => $f['helper'], 'role' => 'responsible', 'sharePct' => 100],
			],
		]));

		$members = $this->fetchRows('chorestrike_occ_members', ['occurrence_id' => (int)$pending->getId()]);
		$active = array_values(array_filter($members, static fn (array $row): bool => $row['disposition'] === 'active'));
		self::assertCount(1, $active);
		self::assertSame($tomorrowKey, substr((string)$active[0]['work_date'], 0, 10), 'the appointment rides the replaced participant row');
		self::assertSame('Europe/Berlin', $active[0]['work_zone'], 'including its zone');

		$row = $this->occurrenceMapper->find((int)$pending->getId());
		self::assertSame((new \DateTime('-2 days'))->format('Y-m-d'), $row->getPlannedDate()->format('Y-m-d'), 'the miss keeps its planned date');
		self::assertNull($row->getCompletionId());
		self::assertNull($row->getCoveredByCompletionId());

		// RF-073-WP2-20: the house has NO pause — the appointment still
		// projects into the canonical selection (before the fix the
		// stamping pass early-returned the planned-date default).
		$roomTask = \OC::$server->get(\OCA\ChoreStrike\Db\RoomTaskMapper::class)->find($f['roomTaskId']);
		$selection = $this->occurrenceService->selectionFor($roomTask, new \DateTime(), $f['houseId']);
		self::assertNotNull($selection);
		self::assertSame($tomorrowKey, $selection['workAppointment']['date']);
		self::assertSame('Europe/Berlin', $selection['workAppointment']['zone']);
	}

	/**
	 * RF-073-WP2-19: the receipt identity covers the ACTION and every
	 * normalized semantic input — an identical repeat replays the original
	 * result without a second effect, while the same UUID with a changed
	 * date (or a different action) conflicts.
	 */
	public function testAgreementReceipt_SemanticInputsAndActionIdentity(): void {
		$f = $this->agreementFixture([
			'frequency_type' => 'daily',
			'scheduling_mode' => 'static',
		], (new \DateTime())->format('Y-m-d') . ' 00:00:00');
		$receipt = $this->agreementReceipt($f['houseId'], $f['roomTaskId']);
		$tomorrowKey = (new \DateTime('+1 day'))->format('Y-m-d');
		$commandId = self::uuid();

		$payload = $this->amendPayload($receipt, ['date' => $tomorrowKey]);
		$payload['commandId'] = $commandId;
		$first = $this->agreementService()->amend($f['houseId'], $f['owner'], $f['roomTaskId'], $payload);

		$occurrenceId = $this->occurrenceRowIdByKey($f['roomTaskId'], $receipt['occurrenceId']);
		$agreementsAfterFirst = count($this->fetchRows('chorestrike_agreements', ['occurrence_id' => $occurrenceId]));

		// Identical repeat â†’ replay of the ORIGINAL identity, no second effect.
		$replay = $this->agreementService()->amend($f['houseId'], $f['owner'], $f['roomTaskId'], $payload);
		self::assertSame($first, $replay, 'the replay returns the original result refs');
		self::assertCount($agreementsAfterFirst, $this->fetchRows('chorestrike_agreements', ['occurrence_id' => $occurrenceId]));

		// Same UUID, changed semantic input â†’ conflict, no writes.
		try {
			$this->agreementService()->amend($f['houseId'], $f['owner'], $f['roomTaskId'], array_merge($payload, [
				'date' => (new \DateTime('+2 days'))->format('Y-m-d'),
			]));
			self::fail('the same UUID with a changed date must conflict');
		} catch (\OCA\ChoreStrike\Exception\ConflictException $e) {
			self::assertStringContainsString('different', $e->getMessage());
		}

		// Same UUID under a DIFFERENT action â†’ conflict (the action
		// identity is part of the receipt hash).
		$catchUpPayload = $this->amendPayload($receipt, ['workDate' => $tomorrowKey]);
		$catchUpPayload['commandId'] = $commandId;
		try {
			$this->agreementService()->catchUp($f['houseId'], $f['owner'], $f['roomTaskId'], $catchUpPayload);
			self::fail('reusing the receipt UUID for another command must conflict');
		} catch (\OCA\ChoreStrike\Exception\ConflictException $e) {
			self::assertStringContainsString('different', $e->getMessage());
		}
		self::assertCount($agreementsAfterFirst, $this->fetchRows('chorestrike_agreements', ['occurrence_id' => $occurrenceId]));
	}

	/**
	 * RF-073-WP2-22: an effective-state change invisible to the row
	 * version invalidates the shown selection token — the command
	 * conflicts with ZERO persisted writes. Part (b) is the core proof: a
	 * READ-ONLY VIRTUAL target carries no row version to bump, so only the
	 * token recomputation over the stamped pause facts can see the drift.
	 */
	public function testStaleSelectionToken_PauseDrift_ConflictsWithZeroWrites(): void {
		$f = $this->agreementFixture();
		$receipt = $this->agreementReceipt($f['houseId'], $f['roomTaskId']);
		$occurrenceId = $this->occurrenceRowIdByKey($f['roomTaskId'], $receipt['occurrenceId']);

		// (a) A bogus token conflicts immediately.
		try {
			$this->agreementService()->amend($f['houseId'], $f['owner'], $f['roomTaskId'], $this->amendPayload($receipt, [
				'selectionToken' => 'bogus-token',
				'date' => (new \DateTime('+1 day'))->format('Y-m-d'),
			]));
			self::fail('a non-matching token must conflict');
		} catch (\OCA\ChoreStrike\Exception\ConflictException $e) {
			self::assertStringContainsString('changed since', $e->getMessage());
		}
		$row = $this->occurrenceMapper->find($occurrenceId);
		self::assertSame($receipt['expectedVersion'], $row->getVersion(), 'the conflicting command wrote nothing');
		self::assertCount(0, $this->fetchRows('chorestrike_agreements', ['occurrence_id' => $occurrenceId, 'kind' => 'date']));

		// (b) Virtual target: delete the materialized pending row so the
		// read path derives the VIRTUAL twin (version 0, no row to bump),
		// render its token, then acknowledge a pause over it — the pause
		// effects find no persisted row, so ONLY the token recomputation
		// can detect the drift.
		$staticFixture = $this->agreementFixture([
			'frequency_type' => 'daily',
			'scheduling_mode' => 'static',
		], (new \DateTime())->format('Y-m-d') . ' 00:00:00', 'V');
		$virtualReceipt = $this->agreementReceipt($staticFixture['houseId'], $staticFixture['roomTaskId']);
		$pending = null;
		foreach ($this->occurrenceMapper->findByRoomTask($staticFixture['roomTaskId']) as $candidate) {
			if ($candidate->toPublicKey() === $virtualReceipt['occurrenceId']) {
				$pending = $candidate;
			}
		}
		self::assertNotNull($pending);
		$qb = $this->db->getQueryBuilder();
		$qb->delete('chorestrike_occurrences')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($pending->getId(), \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
			->executeStatement();
		self::assertSame(0, $virtualReceipt['expectedVersion'], 'the virtual twin always shows version 0');

		$pauseService = \OC::$server->get(\OCA\ChoreStrike\Service\PauseService::class);
		$pauseService->requestForSelf($staticFixture['houseId'], $staticFixture['owner'], [
			'commandId' => self::uuid(),
			'startDate' => (new \DateTime())->format('Y-m-d'),
			'endDate' => (new \DateTime('+4 days'))->format('Y-m-d'),
			'reasonCode' => \OCA\ChoreStrike\Db\Pause::REASON_TRAVEL,
		]);

		try {
			$this->agreementService()->amend($staticFixture['houseId'], $staticFixture['owner'], $staticFixture['roomTaskId'], $this->amendPayload($virtualReceipt, [
				'date' => (new \DateTime('+1 day'))->format('Y-m-d'),
			]));
			self::fail('a pause-invalidated token must conflict even with a matching version');
		} catch (\OCA\ChoreStrike\Exception\ConflictException $e) {
			self::assertStringContainsString('changed since', $e->getMessage());
		}

		// Zero persisted writes: the command materialized the virtual twin
		// inside its transaction and the conflict rolled everything back.
		$keys = array_map(static fn (Occurrence $o): string => $o->toPublicKey(), $this->occurrenceMapper->findByRoomTask($staticFixture['roomTaskId']));
		self::assertNotContains($virtualReceipt['occurrenceId'], $keys, 'the rolled-back command left no materialized row');
	}

	/**
	 * RF-073-WP2-05: the restart command derives the EFFECTIVE suspension
	 * state through the projection — a legitimately suspended interval
	 * predecessor whose persisted row still carries the materialization
	 * default restarts (and the derived facts are persisted), while an
	 * amendment of the same effectively-suspended target is rejected with
	 * zero writes.
	 */
	public function testRestart_EffectiveSuspension_DerivedAtCommandBoundary(): void {
		$f = $this->agreementFixture();
		$pauseService = \OC::$server->get(\OCA\ChoreStrike\Service\PauseService::class);
		$pauseService->requestForSelf($f['houseId'], $f['owner'], [
			'commandId' => self::uuid(),
			'startDate' => (new \DateTime())->format('Y-m-d'),
			'endDate' => (new \DateTime('+4 days'))->format('Y-m-d'),
			'reasonCode' => \OCA\ChoreStrike\Db\Pause::REASON_TRAVEL,
		]);

		$pending = $this->occurrenceMapper->findByRoomTask($f['roomTaskId'])[0];
		self::assertSame(Occurrence::SUSPENSION_SUSPENDED, $pending->getSuspensionState(), 'the acknowledged pause suspended the pending row');
		$suspendedVersion = $pending->getVersion();

		// Simulate the virtualâ†’materialized drift: the row carries the
		// materialization default while the projection still derives
		// suspended from the recognized pause.
		$qb = $this->db->getQueryBuilder();
		$qb->update('chorestrike_occurrences')
			->set('suspension_state', $qb->createNamedParameter('none'))
			->set('suspension_evidence', $qb->createNamedParameter(null, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_NULL))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($pending->getId(), \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
			->executeStatement();

		// The amendment of the effectively-suspended target is rejected.
		try {
			$this->agreementService()->amend($f['houseId'], $f['owner'], $f['roomTaskId'], [
				'commandId' => self::uuid(),
				'occurrenceId' => $pending->toPublicKey(),
				'expectedVersion' => $suspendedVersion,
				'selectionToken' => 'any',
				'consent' => true,
				'date' => (new \DateTime('+1 day'))->format('Y-m-d'),
			]);
			self::fail('an effectively-suspended occurrence is no date-amendment target');
		} catch (\OCA\ChoreStrike\Exception\ConflictException $e) {
			// Expected — token/version/suspension gates all conflict here.
		}

		// The restart succeeds through the derived state and persists it.
		// The agreed restart day lies AFTER the pause range — future
		// reactivation must fit capacity (plan Â§3.4), and the owner is
		// paused through +4 days.
		$refs = $this->agreementService()->restart($f['houseId'], $f['owner'], $f['roomTaskId'], [
			'commandId' => self::uuid(),
			'occurrenceId' => $pending->toPublicKey(),
			'expectedVersion' => $suspendedVersion,
			'consent' => true,
			'workDate' => (new \DateTime('+5 days'))->format('Y-m-d'),
		]);
		self::assertArrayHasKey('occurrenceId', $refs);
		self::assertNotSame($pending->toPublicKey(), $refs['occurrenceId'], 'the receipt reports the restart successor');

		$predecessor = $this->occurrenceMapper->find((int)$pending->getId());
		self::assertSame(Occurrence::SUSPENSION_SUSPENDED, $predecessor->getSuspensionState(), 'the derived suspension facts are persisted, not lost');
		$rows = $this->occurrenceMapper->findByRoomTask($f['roomTaskId']);
		$restarts = array_values(array_filter($rows, static fn (Occurrence $o): bool => $o->getRestartOfId() !== null));
		self::assertCount(1, $restarts, 'exactly one linked restart successor exists');
		self::assertSame((int)$pending->getId(), $restarts[0]->getRestartOfId());
	}

	/**
	 * RF-073-WP2-07: a concretely agreed HELPER commitment is capacity-
	 * checked with its exact share — an over-budget helper share conflicts
	 * (the pre-fix code skipped non-obligated rows entirely), a fitting
	 * helper share is installed.
	 */
	public function testHandover_HelperCommitment_CapacityCheckedWithExactShare(): void {
		$f = $this->agreementFixture();
		$receipt = $this->agreementReceipt($f['houseId'], $f['roomTaskId']);

		// Shrink the helper's daily energy budget to 1 (100 minor): a
		// helper share of 100% of the energy-2 revision (200 minor) no
		// longer fits, while 50% (100 minor) exactly fits the budget.
		$qb = $this->db->getQueryBuilder();
		$qb->update('chorestrike_house_users')
			->set('scheduling_preferences', $qb->createNamedParameter(json_encode([
				'energyBudget' => array_fill_keys(
					['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'],
					1
				),
			])))
			->where($qb->expr()->eq('nextcloud_user_id', $qb->createNamedParameter($f['helper'])))
			->executeStatement();

		try {
			$this->agreementService()->amend($f['houseId'], $f['owner'], $f['roomTaskId'], $this->amendPayload($receipt, [
				'participants' => [
					['userId' => $f['owner'], 'role' => 'responsible', 'sharePct' => 100],
					['userId' => $f['helper'], 'role' => 'helper', 'sharePct' => 100],
				],
			]));
			self::fail('an over-budget helper commitment must conflict');
		} catch (\OCA\ChoreStrike\Exception\ConflictException $e) {
			self::assertStringContainsString('energy budget', $e->getMessage());
		}

		// A fitting helper share (50% of 2 = 100 minor â‰¤ 100 budget) installs.
		$receipt2 = $this->agreementReceipt($f['houseId'], $f['roomTaskId']);
		$this->agreementService()->amend($f['houseId'], $f['owner'], $f['roomTaskId'], $this->amendPayload($receipt2, [
			'participants' => [
				['userId' => $f['owner'], 'role' => 'responsible', 'sharePct' => 100],
				['userId' => $f['helper'], 'role' => 'helper', 'sharePct' => 50],
			],
		]));
		$occurrenceId = $this->occurrenceRowIdByKey($f['roomTaskId'], $receipt['occurrenceId']);
		$members = $this->fetchRows('chorestrike_occ_members', ['occurrence_id' => $occurrenceId]);
		$helpers = array_values(array_filter($members, static fn (array $row): bool => $row['role'] === 'helper' && $row['disposition'] === 'active'));
		self::assertCount(1, $helpers, 'the fitting helper commitment is installed');
		self::assertSame(0, (int)$helpers[0]['obligated']);
	}

	// ------------------------------------------------------------------
	// CS-PLAN-073 fix round R4/R5: concrete-arrangement validation and
	// virtual/persisted projection parity (RF-073-WP2-26/27)
	// ------------------------------------------------------------------

	private function walletIdOf(int $houseId, string $userId): int {
		$member = \OC::$server->get(\OCA\ChoreStrike\Db\HouseUserMapper::class)->findByHouseAndUser($houseId, $userId);
		self::assertNotNull($member);
		self::assertNotNull($member->getWalletId());
		return (int)$member->getWalletId();
	}

	private function acknowledgePause(int $houseId, string $userId, string $startKey, string $endKey): array {
		return \OC::$server->get(\OCA\ChoreStrike\Service\PauseService::class)->requestForSelf($houseId, $userId, [
			'commandId' => self::uuid(),
			'startDate' => $startKey,
			'endDate' => $endKey,
			'reasonCode' => \OCA\ChoreStrike\Db\Pause::REASON_TRAVEL,
		]);
	}

	/**
	 * R5 / RF-073-WP2-27: after a concrete A-to-B handover, a follow-up
	 * date-only amendment validates and records consent against the ACTUAL
	 * occurrence participants (B) — the released former responsible (A,
	 * still the revision default) is neither capacity-checked nor a consent
	 * subject, so A's pause no longer blocks B's fitting arrangement.
	 */
	public function testAmend_DateOnly_AfterHandover_FollowsConcreteArrangement(): void {
		$f = $this->agreementFixture([
			'frequency_type' => 'daily',
			'scheduling_mode' => 'static',
		], (new \DateTime())->format('Y-m-d') . ' 00:00:00', 'R5a');
		$tomorrowKey = (new \DateTime('+1 day'))->format('Y-m-d');

		// Concrete handover owner -> helper on today's target.
		$receipt = $this->agreementReceipt($f['houseId'], $f['roomTaskId']);
		$this->agreementService()->amend($f['houseId'], $f['owner'], $f['roomTaskId'], $this->amendPayload($receipt, [
			'participants' => [
				['userId' => $f['helper'], 'role' => 'responsible', 'sharePct' => 100],
			],
		]));

		// The OWNER (released, but still the revision default) pauses over
		// the target day; the concrete responsible (helper) does not.
		$this->acknowledgePause($f['houseId'], $f['owner'], (new \DateTime())->format('Y-m-d'), (new \DateTime('+4 days'))->format('Y-m-d'));

		$receipt2 = $this->agreementReceipt($f['houseId'], $f['roomTaskId']);
		$refs = $this->agreementService()->amend($f['houseId'], $f['owner'], $f['roomTaskId'], $this->amendPayload($receipt2, [
			'date' => $tomorrowKey,
		]));

		self::assertSame($receipt2['occurrenceId'], $refs['occurrenceId']);
		$moved = null;
		foreach ($this->occurrenceMapper->findByRoomTask($f['roomTaskId']) as $row) {
			if ($row->toPublicKey() === $refs['occurrenceId']) {
				$moved = $row;
			}
		}
		self::assertNotNull($moved);
		self::assertSame($tomorrowKey, $moved->getPlannedDate()->format('Y-m-d'), 'the concrete arrangement moves even though the released default member is paused');

		// The consent evidence names the ACTUAL responsible, not the default.
		$agreements = $this->fetchRows('chorestrike_agreements', ['occurrence_id' => (int)$moved->getId(), 'kind' => 'date']);
		self::assertCount(1, $agreements);
		$affected = json_decode((string)$agreements[0]['affected_wallet_ids'], true);
		self::assertSame([$this->walletIdOf($f['houseId'], $f['helper'])], array_map('intval', $affected));
		$consent = json_decode((string)$agreements[0]['consent'], true);
		self::assertIsArray($consent);
		self::assertSame(
			[$this->walletIdOf($f['houseId'], $f['helper'])],
			array_map(static fn (array $subject): int => (int)$subject['walletId'], $consent['subjects'])
		);
	}

	/**
	 * R5 / RF-073-WP2-27 (denial direction): the concrete participant's
	 * pause blocks the follow-up date amendment even though the released
	 * revision-default member has room — validation follows the people the
	 * writer will actually keep.
	 */
	public function testAmend_DateOnly_ConcreteParticipantPaused_ConflictsDespiteFreeDefault(): void {
		$f = $this->agreementFixture([
			'frequency_type' => 'daily',
			'scheduling_mode' => 'static',
		], (new \DateTime())->format('Y-m-d') . ' 00:00:00', 'R5b');
		$tomorrowKey = (new \DateTime('+1 day'))->format('Y-m-d');

		$receipt = $this->agreementReceipt($f['houseId'], $f['roomTaskId']);
		$this->agreementService()->amend($f['houseId'], $f['owner'], $f['roomTaskId'], $this->amendPayload($receipt, [
			'participants' => [
				['userId' => $f['helper'], 'role' => 'responsible', 'sharePct' => 100],
			],
		]));
		// The CONCRETE responsible pauses on the target day only.
		$this->acknowledgePause($f['houseId'], $f['helper'], $tomorrowKey, $tomorrowKey);

		$receipt2 = $this->agreementReceipt($f['houseId'], $f['roomTaskId']);
		try {
			$this->agreementService()->amend($f['houseId'], $f['owner'], $f['roomTaskId'], $this->amendPayload($receipt2, [
				'date' => $tomorrowKey,
			]));
			self::fail('the paused concrete participant must block the move');
		} catch (\OCA\ChoreStrike\Exception\ConflictException $e) {
			self::assertStringContainsString('paused', $e->getMessage());
		}

		// Zero writes: the occurrence kept its planned date and version.
		$unchanged = null;
		foreach ($this->occurrenceMapper->findByRoomTask($f['roomTaskId']) as $row) {
			if ($row->toPublicKey() === $receipt2['occurrenceId']) {
				$unchanged = $row;
			}
		}
		self::assertNotNull($unchanged);
		self::assertSame($receipt2['expectedVersion'], $unchanged->getVersion());
		self::assertNotSame($tomorrowKey, $unchanged->getPlannedDate()->format('Y-m-d'));
	}

	/**
	 * R5: a catch-up WITHOUT a participant payload binds the concrete
	 * current participants — the released revision default is irrelevant,
	 * the concrete member's pause on the work day conflicts, and a fitting
	 * day installs the appointment on the actual rows.
	 */
	public function testCatchUp_WithoutParticipants_FollowsConcreteArrangement(): void {
		// A bounded lifetime ending yesterday leaves ONLY expired misses —
		// the canonical selection classifies the latest one as `late`.
		$f = $this->agreementFixture([
			'frequency_type' => 'daily',
			'scheduling_mode' => 'static',
			'active_until' => (new \DateTime('-1 day'))->format('Y-m-d') . ' 00:00:00',
		], (new \DateTime('-2 days'))->format('Y-m-d') . ' 00:00:00', 'R5c');
		$tomorrowKey = (new \DateTime('+1 day'))->format('Y-m-d');
		$afterKey = (new \DateTime('+2 days'))->format('Y-m-d');

		$receipt = $this->agreementReceipt($f['houseId'], $f['roomTaskId']);
		self::assertSame('late', $receipt['classification'], 'the expired miss is the catch-up target');
		// The handover of a miss rides a catch-up WITH participants (an
		// ordinary amendment cannot touch an expired window): the concrete
		// arrangement becomes the helper on an appointed day that fits.
		$this->agreementService()->catchUp($f['houseId'], $f['owner'], $f['roomTaskId'], $this->amendPayload($receipt, [
			'workDate' => $afterKey,
			'participants' => [
				['userId' => $f['helper'], 'role' => 'responsible', 'sharePct' => 100],
			],
		]));
		$this->acknowledgePause($f['houseId'], $f['helper'], $tomorrowKey, $tomorrowKey);

		// The follow-up catch-up WITHOUT participants binds the concrete
		// helper — their pause on the proposed work day conflicts even
		// though the released revision default (owner) has room.
		$receipt2 = $this->agreementReceipt($f['houseId'], $f['roomTaskId']);
		try {
			$this->agreementService()->catchUp($f['houseId'], $f['owner'], $f['roomTaskId'], $this->amendPayload($receipt2, [
				'workDate' => $tomorrowKey,
			]));
			self::fail('the concrete participant is paused on the proposed work day');
		} catch (\OCA\ChoreStrike\Exception\ConflictException $e) {
			self::assertStringContainsString('paused', $e->getMessage());
		}

		// A day the concrete participant fits installs the appointment on
		// the ACTUAL active rows.
		$receipt3 = $this->agreementReceipt($f['houseId'], $f['roomTaskId']);
		$this->agreementService()->catchUp($f['houseId'], $f['owner'], $f['roomTaskId'], $this->amendPayload($receipt3, [
			'workDate' => $afterKey,
		]));
		$occurrenceId = $this->occurrenceRowIdByKey($f['roomTaskId'], $receipt3['occurrenceId']);
		$active = array_values(array_filter(
			$this->fetchRows('chorestrike_occ_members', ['occurrence_id' => $occurrenceId]),
			static fn (array $row): bool => $row['disposition'] === 'active'
		));
		self::assertCount(1, $active);
		self::assertSame($this->walletIdOf($f['houseId'], $f['helper']), (int)$active[0]['wallet_id']);
		self::assertSame($afterKey, substr((string)$active[0]['work_date'], 0, 10), 'the appointment rides the concrete participant row');
	}

	/**
	 * R4 / RF-073-WP2-26: with a stopped cron (the materialized row of an
	 * agreed key is absent), the read path derives a VIRTUAL twin whose
	 * suspension comes from the associated saved revision — identical to
	 * the materialized twin the job pass later persists. The GET writes
	 * nothing, the completion of the suspended virtual target conflicts
	 * with zero committed writes, and the job replay converges to the same
	 * public key, state and appointment.
	 */
	public function testStoppedCron_VirtualProjection_MatchesLaterMaterializedRow(): void {
		$todayKey = (new \DateTime())->format('Y-m-d');
		$f = $this->agreementFixture([
			'frequency_type' => 'daily',
			'scheduling_mode' => 'static',
		], $todayKey . ' 00:00:00', 'R4');
		$roomTask = \OC::$server->get(\OCA\ChoreStrike\Db\RoomTaskMapper::class)->find($f['roomTaskId']);

		// Simulate the stopped cron: today's agreed row never materialized.
		$pending = null;
		foreach ($this->occurrenceMapper->findByRoomTask($f['roomTaskId']) as $row) {
			if ($row->getPlannedDate()->format('Y-m-d') === $todayKey) {
				$pending = $row;
			}
		}
		self::assertNotNull($pending);
		$virtualKey = $pending->toPublicKey();
		$qb = $this->db->getQueryBuilder();
		$qb->delete('chorestrike_occ_members')
			->where($qb->expr()->eq('occurrence_id', $qb->createNamedParameter((int)$pending->getId(), IQueryBuilder::PARAM_INT)))
			->executeStatement();
		$qb = $this->db->getQueryBuilder();
		$qb->delete('chorestrike_occurrences')
			->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$pending->getId(), IQueryBuilder::PARAM_INT)))
			->executeStatement();

		// The owner's acknowledged pause covers today.
		$pause = $this->acknowledgePause($f['houseId'], $f['owner'], $todayKey, (new \DateTime('+4 days'))->format('Y-m-d'));

		$countsBefore = [
			'occurrences' => count($this->occurrenceMapper->findByRoomTask($f['roomTaskId'])),
			'commands' => $this->countTable('chorestrike_commands', 'house_id', $f['houseId']),
		];

		// The read path derives the virtual twin FROM THE SAVED REVISION.
		$read = $this->occurrenceService->agreedOccurrencesForHouseTasks($f['houseId'], [$roomTask], $todayKey, $todayKey);
		$entry = null;
		foreach ($read[$f['roomTaskId']] as $candidate) {
			if ($candidate['occurrence']->toPublicKey() === $virtualKey) {
				$entry = $candidate;
			}
		}
		self::assertNotNull($entry, 'the agreed key is rendered even without a materialized row');
		self::assertNull($entry['occurrence']->getId(), 'the twin is genuinely virtual');
		self::assertSame(Occurrence::SUSPENSION_SUSPENDED, $entry['occurrence']->getSuspensionState(), 'the virtual twin derives its suspension from the saved revision participants');
		$virtualEvidence = $entry['occurrence']->getSuspensionEvidence();
		self::assertIsArray($virtualEvidence);
		self::assertSame($pause['pauseGroupId'], (int)$virtualEvidence[0]['pauseGroupId']);

		// The GET created no occurrence, evidence or receipt.
		self::assertSame($countsBefore['occurrences'], count($this->occurrenceMapper->findByRoomTask($f['roomTaskId'])));
		self::assertSame($countsBefore['commands'], $this->countTable('chorestrike_commands', 'house_id', $f['houseId']));

		// Completing the suspended virtual target conflicts with zero
		// committed writes (the materialization inside the command rolls
		// back with the conflict).
		$token = $this->occurrenceService->selectionToken($entry['occurrence'], $entry['revision'], 'agreed', $f['houseId'], null, $entry['workAppointment']);
		try {
			$this->completionService->complete($f['houseId'], $f['owner'], $f['roomTaskId'], self::uuid(), $virtualKey, 0, $token);
			self::fail('a suspended virtual target is no completion target');
		} catch (\OCA\ChoreStrike\Exception\ConflictException $e) {
			// Expected.
		}
		self::assertSame($countsBefore['occurrences'], count($this->occurrenceMapper->findByRoomTask($f['roomTaskId'])), 'the rolled-back command left no materialized row');
		self::assertSame($countsBefore['commands'], $this->countTable('chorestrike_commands', 'house_id', $f['houseId']));

		// Job replay: the late run materializes the missing agreed row and
		// converges the persisted suspension facts (frontier pulled back to
		// simulate the stopped cron's materialization gap).
		$qb = $this->db->getQueryBuilder();
		$qb->update('chorestrike_schedule_revs')
			->set('agreed_through', $qb->createNamedParameter((new \DateTime('-1 day'))->format('Y-m-d')))
			->where($qb->expr()->eq('room_task_id', $qb->createNamedParameter($f['roomTaskId'], IQueryBuilder::PARAM_INT)))
			->executeStatement();
		$this->occurrenceService->reconcileRoomTask($f['houseId'], $f['roomTaskId']);
		$pauseService = \OC::$server->get(\OCA\ChoreStrike\Service\PauseService::class);
		$pauseService->applyPauseEffectsUnderLock($f['houseId']);

		$materialized = null;
		foreach ($this->occurrenceMapper->findByRoomTask($f['roomTaskId']) as $row) {
			if ($row->toPublicKey() === $virtualKey) {
				$materialized = $row;
			}
		}
		self::assertNotNull($materialized, 'the job pass rematerialized the agreed key');
		self::assertSame(Occurrence::SUSPENSION_SUSPENDED, $materialized->getSuspensionState(), 'the persisted twin carries the same effective suspension');
		self::assertSame($virtualEvidence, $materialized->getSuspensionEvidence(), 'identical provenance before/after materialization');

		// The read path after materialization agrees with the virtual read.
		$readAfter = $this->occurrenceService->agreedOccurrencesForHouseTasks($f['houseId'], [$roomTask], $todayKey, $todayKey);
		$entryAfter = null;
		foreach ($readAfter[$f['roomTaskId']] as $candidate) {
			if ($candidate['occurrence']->toPublicKey() === $virtualKey) {
				$entryAfter = $candidate;
			}
		}
		self::assertNotNull($entryAfter);
		self::assertSame($entry['occurrence']->getSuspensionState(), $entryAfter['occurrence']->getSuspensionState());
		self::assertSame($entry['workAppointment']['date'] ?? null, $entryAfter['workAppointment']['date'] ?? null);
		self::assertSame($entry['workAppointment']['zone'] ?? null, $entryAfter['workAppointment']['zone'] ?? null);
	}

	/**
	 * R4 boundary pin: the two virtual seams stay disjoint by the frozen
	 * WP1 contract — virtual materialization exists for FIXED cadences
	 * only, while restart requires an INTERVAL predecessor. A restart of a
	 * suspended FIXED virtual twin is therefore an explicit conflict with
	 * zero writes (the interval predecessor of a restart is always a
	 * materialized row; `testRestart_EffectiveSuspension_*` covers its
	 * derived-suspension path).
	 */
	public function testStoppedCron_RestartOfFixedVirtualTwin_ConflictsWithZeroWrites(): void {
		$todayKey = (new \DateTime())->format('Y-m-d');
		$f = $this->agreementFixture([
			'frequency_type' => 'daily',
			'scheduling_mode' => 'static',
		], $todayKey . ' 00:00:00', 'R4restartfixed');
		$roomTask = \OC::$server->get(\OCA\ChoreStrike\Db\RoomTaskMapper::class)->find($f['roomTaskId']);

		$pending = $this->occurrenceMapper->findByRoomTask($f['roomTaskId'])[0];
		$virtualKey = $pending->toPublicKey();
		$qb = $this->db->getQueryBuilder();
		$qb->delete('chorestrike_occ_members')
			->where($qb->expr()->eq('occurrence_id', $qb->createNamedParameter((int)$pending->getId(), IQueryBuilder::PARAM_INT)))
			->executeStatement();
		$qb = $this->db->getQueryBuilder();
		$qb->delete('chorestrike_occurrences')
			->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$pending->getId(), IQueryBuilder::PARAM_INT)))
			->executeStatement();

		$this->acknowledgePause($f['houseId'], $f['owner'], $todayKey, (new \DateTime('+4 days'))->format('Y-m-d'));

		// The read path shows the suspended virtual twin (RF-073-WP2-26).
		$read = $this->occurrenceService->agreedOccurrencesForHouseTasks($f['houseId'], [$roomTask], $todayKey, $todayKey);
		$entry = null;
		foreach ($read[$f['roomTaskId']] as $candidate) {
			if ($candidate['occurrence']->toPublicKey() === $virtualKey) {
				$entry = $candidate;
			}
		}
		self::assertNotNull($entry);
		self::assertSame(Occurrence::SUSPENSION_SUSPENDED, $entry['occurrence']->getSuspensionState());

		$commandsBefore = $this->countTable('chorestrike_commands', 'house_id', $f['houseId']);
		try {
			$this->agreementService()->restart($f['houseId'], $f['owner'], $f['roomTaskId'], [
				'commandId' => self::uuid(),
				'occurrenceId' => $virtualKey,
				'expectedVersion' => 0,
				'consent' => true,
				'workDate' => (new \DateTime('+5 days'))->format('Y-m-d'),
			]);
			self::fail('a fixed-cadence occurrence is no restart predecessor');
		} catch (\OCA\ChoreStrike\Exception\ConflictException $e) {
			// Expected — the interval-predecessor gate rejects explicitly.
		}

		// Zero writes: no row materialized, no successor, no new receipt.
		self::assertSame([], array_values(array_filter(
			$this->occurrenceMapper->findByRoomTask($f['roomTaskId']),
			static fn (Occurrence $o): bool => $o->toPublicKey() === $virtualKey || $o->getRestartOfId() !== null
		)));
		self::assertSame($commandsBefore, $this->countTable('chorestrike_commands', 'house_id', $f['houseId']), 'the rejected restart wrote no receipt');
	}

	private function countTable(string $table, string $column, int $value): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))->from($table)
			->where($qb->expr()->eq($column, $qb->createNamedParameter($value, IQueryBuilder::PARAM_INT)));
		$count = (int)$qb->executeQuery()->fetchOne();
		return $count;
	}

	private function occurrenceRowIdByKey(int $roomTaskId, string $publicKey): int {
		foreach ($this->occurrenceMapper->findByRoomTask($roomTaskId) as $row) {
			if ($row->toPublicKey() === $publicKey) {
				return (int)$row->getId();
			}
		}
		self::fail('occurrence row for key ' . $publicKey . ' must exist');
	}

	private function classificationOfCompletion(int $completionId): string {
		$rows = $this->fetchRows('chorestrike_completed_tasks', ['id' => $completionId]);
		return (string)$rows[0]['completion_class'];
	}

	private function houseUser(int $houseId, string $userId): \OCA\ChoreStrike\Db\HouseUser {
		$houseUser = \OC::$server->get(\OCA\ChoreStrike\Db\HouseUserMapper::class)->findByHouseAndUser($houseId, $userId);
		self::assertNotNull($houseUser);
		return $houseUser;
	}

	private function createChecklistItem(int $taskId, string $label): int {
		$qb = $this->db->getQueryBuilder();
		$qb->insert('chorestrike_chk_items')
			->values([
				'task_id' => $qb->createNamedParameter($taskId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
				'label' => $qb->createNamedParameter($label),
				'sort_order' => $qb->createNamedParameter(0, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
				'created_at' => $qb->createNamedParameter(date('Y-m-d H:i:s')),
			]);
		$qb->executeStatement();
		return (int)$this->db->lastInsertId('chorestrike_chk_items');
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function fetchRows(string $table, array $criteria): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($table);
		foreach ($criteria as $column => $value) {
			$qb->andWhere($qb->expr()->eq($column, $qb->createNamedParameter($value, is_int($value) ? \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT : \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR)));
		}
		return $qb->executeQuery()->fetchAll();
	}

	private static function uuid(): string {
		return sprintf('%08x-%04x-4%03x-8%03x-%012x', mt_rand(), mt_rand(0, 0xffff), mt_rand(0, 0xfff), mt_rand(0, 0xfff), mt_rand());
	}
}
